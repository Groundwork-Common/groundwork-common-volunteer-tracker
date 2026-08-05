<?php
/**
 * Retention arithmetic, and the log that makes a sweep visible.
 *
 * The purge itself needs a real database and is covered by
 * tests/integration/privacy.php. What belongs here is the part with a decision
 * in it: when a record becomes due, and when it emphatically does not.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/**
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWCVT_SETTINGS_OPTION, $settings );
		gwcvt_settings_cache( null, true );
	}

	/* ── The cutoff ──────────────────────────────────────────────────────── */

	/**
	 * @param int    $months   Retention period.
	 * @param string $today    Today's date.
	 * @param string $expected The cutoff.
	 */
	#[DataProvider( 'cutoffs' )]
	public function test_the_cutoff_is_calendar_months( int $months, string $today, string $expected ): void {
		$this->assertSame( $expected, gwcvt_retention_cutoff( $months, $today ) );
	}

	public static function cutoffs(): array {
		return array(
			'a year'              => array( 12, '2026-08-05', '2025-08-05' ),
			'eighteen months'     => array( 18, '2026-08-05', '2025-02-05' ),
			'two years'           => array( 24, '2026-08-05', '2024-08-05' ),

			/* Calendar months, not 30-day blocks. "Six months" means six months
			 * to everybody who sets this, and a multiplication by thirty days
			 * drifts nearly a week a year away from that — on a setting whose
			 * whole job is to be defensible when somebody asks. */
			'across a leap day'   => array( 12, '2028-02-29', '2027-03-01' ),
			'month-end shortfall' => array( 1, '2026-03-31', '2026-03-03' ),
		);
	}

	public function test_retention_off_has_no_cutoff(): void {
		$this->assertSame( '', gwcvt_retention_cutoff( 0, '2026-08-05' ) );
		$this->assertSame( '', gwcvt_retention_cutoff( -12, '2026-08-05' ) );
	}

	public function test_a_nonsense_date_has_no_cutoff(): void {
		$this->assertSame( '', gwcvt_retention_cutoff( 12, 'not a date' ) );
		$this->assertSame( '', gwcvt_retention_cutoff( 12, '' ) );
	}

	/* ── Due, and not due ────────────────────────────────────────────────── */

	/**
	 * A volunteer whose most recent shift was on a given date.
	 *
	 * @param string $last_shift Y-m-d.
	 * @return int
	 */
	private function volunteer_last_active( string $last_shift ): int {
		$id = 500;

		gwcvt_test_add_post( $id, GWCVT_VOLUNTEER_TYPE, 'publish', 'Jane Quimby' );

		update_post_meta(
			$id,
			GWCVT_VOLUNTEER_TOTALS,
			( new GWCVT_Totals( 390, 0, 2, '2020-01-01', $last_shift, time() ) )->to_array()
		);

		return $id;
	}

	public function test_nothing_is_ever_due_while_retention_is_off(): void {
		$this->settings( array( 'retention_months' => 0 ) );

		$volunteer = $this->volunteer_last_active( '2001-01-01' );

		/* The default, and the one that must never purge. A record twenty-five
		 * years old is still kept while nobody has chosen a policy. */
		$this->assertFalse( gwcvt_retention_due( $volunteer, '2026-08-05' ) );
	}

	public function test_a_record_older_than_the_cutoff_is_due(): void {
		$this->settings( array( 'retention_months' => 24, 'retention_anchor' => 'last_entry' ) );

		$this->assertTrue( gwcvt_retention_due( $this->volunteer_last_active( '2024-08-04' ), '2026-08-05' ) );
	}

	public function test_a_record_inside_the_cutoff_is_not_due(): void {
		$this->settings( array( 'retention_months' => 24, 'retention_anchor' => 'last_entry' ) );

		$this->assertFalse( gwcvt_retention_due( $this->volunteer_last_active( '2024-08-06' ), '2026-08-05' ) );
	}

	public function test_the_boundary_day_itself_is_not_due(): void {
		$this->settings( array( 'retention_months' => 24, 'retention_anchor' => 'last_entry' ) );

		/* Exactly on the cutoff is inside it. Erring the other way means a record
		 * is destroyed one day before the policy said it could be. */
		$this->assertFalse( gwcvt_retention_due( $this->volunteer_last_active( '2024-08-05' ), '2026-08-05' ) );
	}

	public function test_a_hold_exempts_a_record_however_old(): void {
		$this->settings( array( 'retention_months' => 12, 'retention_anchor' => 'last_entry' ) );

		$volunteer = $this->volunteer_last_active( '2001-01-01' );
		update_post_meta( $volunteer, GWCVT_VOLUNTEER_HOLD, 1 );

		$this->assertTrue( gwcvt_retention_held( $volunteer ) );
		$this->assertFalse(
			gwcvt_retention_due( $volunteer, '2026-08-05' ),
			'A court can require an organisation to keep a record longer than its own policy.'
		);
	}

	public function test_a_site_can_override_the_decision(): void {
		$this->settings( array( 'retention_months' => 12, 'retention_anchor' => 'last_entry' ) );

		$volunteer = $this->volunteer_last_active( '2001-01-01' );
		$never     = static fn(): bool => false;

		add_filter( 'gwcvt_retention_due', $never );
		$this->assertFalse( gwcvt_retention_due( $volunteer, '2026-08-05' ) );
		remove_filter( 'gwcvt_retention_due', $never );

		$this->assertTrue( gwcvt_retention_due( $volunteer, '2026-08-05' ) );
	}

	/* ── The run log ─────────────────────────────────────────────────────── */

	public function test_a_run_is_recorded(): void {
		gwcvt_log_retention_run( 'anonymize', 4, 1 );

		$log = gwcvt_retention_log();

		$this->assertCount( 1, $log );
		$this->assertSame( 'anonymize', $log[0]['action'] );
		$this->assertSame( 4, $log[0]['purged'] );
		$this->assertSame( 1, $log[0]['held'] );
	}

	public function test_the_newest_run_is_first(): void {
		gwcvt_log_retention_run( 'anonymize', 1, 0 );
		gwcvt_log_retention_run( 'delete', 2, 0 );

		$this->assertSame( 'delete', gwcvt_retention_log()[0]['action'] );
	}

	public function test_the_log_is_bounded(): void {
		for ( $i = 0; $i < GWCVT_RETENTION_LOG_SIZE + 10; $i++ ) {
			gwcvt_log_retention_run( 'anonymize', $i, 0 );
		}

		/* Written daily forever. Unbounded, this is an option that grows by a row
		 * a day for the life of the site. */
		$this->assertCount( GWCVT_RETENTION_LOG_SIZE, gwcvt_retention_log() );
		$this->assertSame( GWCVT_RETENTION_LOG_SIZE + 9, gwcvt_retention_log()[0]['purged'] );
	}

	/* ── Scheduling ──────────────────────────────────────────────────────── */

	public function test_the_sweep_is_scheduled_once(): void {
		gwcvt_schedule_retention();
		$first = wp_next_scheduled( GWCVT_RETENTION_EVENT );

		gwcvt_schedule_retention();

		$this->assertSame(
			$first,
			wp_next_scheduled( GWCVT_RETENTION_EVENT ),
			'Running on every init must not re-schedule, or the sweep never actually fires.'
		);
	}

	/* ── The retention period options ────────────────────────────────────── */

	public function test_keeping_indefinitely_is_an_option(): void {
		$options = gwcvt_retention_period_options();

		$this->assertArrayHasKey( '0', $options );
		$this->assertSame( 'Keep indefinitely', $options['0'] );
	}
}

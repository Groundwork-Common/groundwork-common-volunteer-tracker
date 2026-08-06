<?php
/**
 * What somebody still has to complete.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequiredTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/**
	 * A volunteer with a requirement and a cached rollup.
	 *
	 * The rollup rather than real entries, because gwcvt_volunteer_totals()
	 * reads the cache and there is no get_posts() in this bootstrap — the
	 * arithmetic is what is under test here, and tests/integration/required.php
	 * covers it against a real database.
	 *
	 * @param int    $required_hours What they must complete.
	 * @param int    $verified       Verified minutes.
	 * @param int    $pending        Unverified minutes.
	 * @param string $deadline       Y-m-d, or ''.
	 */
	private function volunteer( int $required_hours, int $verified, int $pending = 0, string $deadline = '' ): void {
		gwcvt_test_add_post( 7, GWCVT_VOLUNTEER_TYPE, 'publish', 'Jane Quimby' );

		if ( $required_hours > 0 ) {
			update_post_meta( 7, GWCVT_VOLUNTEER_REQUIRED, $required_hours * 60 );
		}

		if ( '' !== $deadline ) {
			update_post_meta( 7, GWCVT_VOLUNTEER_REQUIRED_BY, $deadline );
		}

		$totals                   = new GWCVT_Totals();
		$totals->verified_minutes = $verified;
		$totals->pending_minutes  = $pending;
		$totals->entries          = 1;
		$totals->computed_at      = time();

		update_post_meta( 7, GWCVT_VOLUNTEER_TOTALS, $totals->to_array() );
	}

	/* ── Most volunteers have none of this ───────────────────────────────── */

	public function test_a_volunteer_has_no_requirement_by_default(): void {
		gwcvt_test_add_post( 7, GWCVT_VOLUNTEER_TYPE );

		$this->assertFalse( gwcvt_has_requirement( 7 ) );
		$this->assertSame( 0, gwcvt_required_minutes( 7 ) );
		$this->assertSame( '', gwcvt_requirement_label( 7 ) );
	}

	/* ── Verified hours count, and only those ────────────────────────────────
	 * Counting unverified hours towards a requirement would tell somebody they
	 * were finished on the strength of a row nobody had looked at.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_only_verified_hours_count_towards_it(): void {
		$this->volunteer( 40, 20 * 60, 15 * 60 );

		$progress = gwcvt_requirement_progress( 7 );

		$this->assertSame( 20 * 60, $progress['verified'] );
		$this->assertSame( 20 * 60, $progress['remaining'] );
		$this->assertFalse( $progress['met'] );
	}

	/**
	 * Reported alongside rather than folded in. Four hours short with six
	 * unverified is a ten-second problem, and only visible if the two numbers
	 * stay apart.
	 */
	public function test_unverified_hours_are_reported_separately(): void {
		$this->volunteer( 40, 36 * 60, 6 * 60 );

		$progress = gwcvt_requirement_progress( 7 );

		$this->assertSame( 6 * 60, $progress['pending'] );
		$this->assertSame( 4 * 60, $progress['remaining'] );
		$this->assertFalse( $progress['met'], 'unverified hours must not complete a requirement' );
	}

	public function test_a_requirement_is_met_exactly_on_the_number(): void {
		$this->volunteer( 40, 40 * 60 );

		$this->assertTrue( gwcvt_requirement_progress( 7 )['met'] );
	}

	public function test_doing_more_than_required_still_reads_as_met(): void {
		$this->volunteer( 40, 55 * 60 );

		$progress = gwcvt_requirement_progress( 7 );

		$this->assertTrue( $progress['met'] );
		$this->assertSame( 0, $progress['remaining'], 'remaining must never go negative' );
		$this->assertSame( 100, $progress['percent'], 'a bar reading 137% reads as a bug' );
	}

	public function test_progress_is_a_whole_percentage(): void {
		$this->volunteer( 40, 10 * 60 );

		$this->assertSame( 25, gwcvt_requirement_progress( 7 )['percent'] );
	}

	/* ── The deadline is a fact, never a prediction ──────────────────────── */

	public function test_a_deadline_in_the_future_is_not_overdue(): void {
		$this->volunteer( 40, 10 * 60, 0, gmdate( 'Y-m-d', time() + ( 10 * DAY_IN_SECONDS ) ) );

		$progress = gwcvt_requirement_progress( 7 );

		$this->assertFalse( $progress['overdue'] );
		$this->assertSame( 10, $progress['days_left'] );
	}

	public function test_a_deadline_that_has_passed_unmet_is_overdue(): void {
		$this->volunteer( 40, 10 * 60, 0, gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ) );

		$progress = gwcvt_requirement_progress( 7 );

		$this->assertTrue( $progress['overdue'] );
		$this->assertSame( -3, $progress['days_left'] );
	}

	/**
	 * Somebody who finished is not overdue, whatever the date says. Telling a
	 * person who completed their hours that they are late is the kind of thing
	 * that gets a coordinator a phone call from a probation officer.
	 */
	public function test_a_met_requirement_is_never_overdue(): void {
		$this->volunteer( 40, 40 * 60, 0, gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );

		$this->assertFalse( gwcvt_requirement_progress( 7 )['overdue'] );
		$this->assertSame( '', gwcvt_requirement_deadline_label( 7 ) );
	}

	public function test_no_deadline_means_no_countdown(): void {
		$this->volunteer( 40, 10 * 60 );

		$this->assertNull( gwcvt_requirement_progress( 7 )['days_left'] );
		$this->assertSame( '', gwcvt_requirement_deadline_label( 7 ) );
	}

	/* ── Reading what somebody typed ─────────────────────────────────────── */

	/**
	 * @param string $raw      What was typed.
	 * @param int    $expected Minutes.
	 */
	#[DataProvider( 'requirements' )]
	public function test_it_reads_a_requirement_the_way_people_state_one( string $raw, int $expected ): void {
		$this->assertSame( $expected, gwcvt_parse_required( $raw ) );
	}

	public static function requirements(): array {
		return array(
			'a plain number'        => array( '40', 2400 ),
			'with a unit'           => array( '40h', 2400 ),
			'spelled out'           => array( '40 hours', 2400 ),
			'a half'                => array( '7.5', 450 ),
			'surrounding space'     => array( '  120  ', 7200 ),

			/* A shift is capped at a day; a requirement is not. Court-ordered
			 * service routinely runs to hundreds of hours. */
			'hundreds of hours'     => array( '500', 30000 ),

			'nothing'               => array( '', 0 ),
			'not a number'          => array( 'forty', 0 ),
			'zero'                  => array( '0', 0 ),
			'negative'              => array( '-40', 0 ),

			/* A typo here is silent, and a stray zero is likelier than five
			 * years of full-time work. */
			'absurd'                => array( '999999', 0 ),
		);
	}

	/* ── It never reaches the letter ─────────────────────────────────────────
	 * The assertion this whole feature is arranged around. How many hours a
	 * court ordered is a fact about the court's document, not about anything
	 * the organisation observed — and an organisation certifying the terms of
	 * an order back to the court that issued it is the seal problem wearing a
	 * different hat.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Reflection rather than an instance: the letter model takes ten arguments
	 * to build and none of them are the point. What is asserted is the shape of
	 * the class itself, which is what would have to change for a requirement to
	 * reach a document.
	 */
	public function test_the_letter_model_carries_nothing_about_a_requirement(): void {
		foreach ( array( 'GWCVT_Letter', 'GWCVT_Letter_Entry' ) as $class ) {
			$reflection = new ReflectionClass( $class );

			foreach ( $reflection->getProperties() as $property ) {
				$this->assertStringNotContainsStringIgnoringCase(
					'requir',
					$property->getName(),
					$class . ' must not carry what somebody was required to do'
				);
			}
		}
	}

	/**
	 * And nor does the file that builds it, nor the one that renders it. Read
	 * as source rather than exercised, because the point is that no code path
	 * exists — a test that called the builder would only prove the paths it
	 * happened to take.
	 */
	public function test_no_letter_code_reads_the_requirement(): void {
		foreach ( array( 'inc/letter.php', 'inc/render.php', 'inc/emails.php' ) as $file ) {
			$source = (string) file_get_contents( GWCVT_DIR . $file );

			$this->assertStringNotContainsString(
				'GWCVT_VOLUNTEER_REQUIRED',
				$source,
				$file . ' must not read what somebody was required to do'
			);

			$this->assertStringNotContainsString(
				'gwcvt_requirement_progress',
				$source,
				$file . ' must not read what somebody was required to do'
			);
		}
	}
}

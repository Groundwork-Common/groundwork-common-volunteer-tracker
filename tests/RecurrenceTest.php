<?php
/**
 * Turning a recurrence into a list of dates.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/* ── The patterns a coordinator picks ────────────────────────────────── */

	/**
	 * @param string   $start    First occurrence.
	 * @param string   $pattern  Pattern key.
	 * @param string   $until    Last date to consider.
	 * @param string[] $expected The dates it should land on.
	 */
	#[DataProvider( 'patterns' )]
	public function test_it_lands_on_the_dates_somebody_would_write_on_a_calendar( string $start, string $pattern, string $until, array $expected ): void {
		$result = gwcvt_recurrence_dates( $start, $pattern, $until );

		$this->assertSame( $expected, $result['dates'] );
		$this->assertSame( '', $result['capped'] );
	}

	public static function patterns(): array {
		return array(
			'weekly for a month'      => array(
				'2026-03-07',
				'weekly',
				'2026-03-28',
				array( '2026-03-07', '2026-03-14', '2026-03-21', '2026-03-28' ),
			),
			'every other week'        => array(
				'2026-03-07',
				'fortnightly',
				'2026-04-04',
				array( '2026-03-07', '2026-03-21', '2026-04-04' ),
			),
			'daily'                   => array(
				'2026-03-07',
				'daily',
				'2026-03-10',
				array( '2026-03-07', '2026-03-08', '2026-03-09', '2026-03-10' ),
			),
			'weekdays skip the weekend' => array(
				'2026-03-02',
				'weekdays',
				'2026-03-08',
				array( '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06' ),
			),
			'the second Saturday'     => array(
				'2026-03-14',
				'monthly',
				'2026-06-30',
				array( '2026-03-14', '2026-04-11', '2026-05-09', '2026-06-13' ),
			),
		);
	}

	/**
	 * The end of a weekly series lands on the last matching date, not past it.
	 */
	public function test_it_stops_on_the_end_date_rather_than_after_it(): void {
		$result = gwcvt_recurrence_dates( '2026-03-07', 'weekly', '2026-03-27' );

		$this->assertSame( array( '2026-03-07', '2026-03-14', '2026-03-21' ), $result['dates'] );
	}

	/* ── The awkward calendar ────────────────────────────────────────────── */

	/**
	 * A month with no fifth Saturday is skipped, never nudged onto a different
	 * one. Somebody turning up to a locked door is the failure this avoids.
	 */
	public function test_a_month_without_a_fifth_saturday_is_skipped_not_moved(): void {
		// 30 May 2026 is the fifth Saturday. June and July 2026 have only four.
		$result = gwcvt_recurrence_dates( '2026-05-30', 'monthly', '2026-08-31' );

		$this->assertSame( array( '2026-05-30', '2026-08-29' ), $result['dates'] );
	}

	/**
	 * Weekly stepping across a daylight-saving boundary keeps the dates exactly
	 * seven days apart. This is calendar arithmetic and never touches a clock —
	 * see the box comment on why a shift stores wall time. ShiftTest covers the
	 * other half, that the wall time survives the conversion to an instant.
	 */
	public function test_it_steps_across_a_clock_change_without_drifting(): void {
		// US daylight saving begins on 8 March 2026.
		$result = gwcvt_recurrence_dates( '2026-03-01', 'weekly', '2026-03-22' );

		$this->assertSame(
			array( '2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22' ),
			$result['dates']
		);
	}

	/**
	 * Starting on the 31st does not roll a short month into the next one.
	 */
	public function test_a_month_end_start_does_not_roll_over(): void {
		// 31 January 2026 is the fifth Saturday of January.
		$result = gwcvt_recurrence_dates( '2026-01-31', 'monthly', '2026-06-30' );

		foreach ( $result['dates'] as $date ) {
			$this->assertSame( '6', gmdate( 'N', strtotime( $date ) ), $date . ' is not a Saturday' );
		}

		$this->assertNotContains( '2026-03-07', $result['dates'] );
	}

	/* ── The caps ────────────────────────────────────────────────────────── */

	/**
	 * Two hundred is the ceiling, and hitting it is reported rather than silent.
	 */
	public function test_it_reports_the_occurrence_cap_rather_than_quietly_applying_it(): void {
		$result = gwcvt_recurrence_dates( '2026-01-01', 'daily', '2026-12-31' );

		$this->assertCount( GWCVT_RECURRENCE_MAX, $result['dates'] );
		$this->assertSame( 'count', $result['capped'] );
	}

	/**
	 * And so is the twelve-month horizon.
	 */
	public function test_it_reports_the_horizon_cap(): void {
		$result = gwcvt_recurrence_dates( '2026-01-03', 'weekly', '2030-01-01' );

		$this->assertSame( 'horizon', $result['capped'] );
		$this->assertSame( '2026-01-03', $result['dates'][0] );

		// Nothing past a year out.
		$this->assertLessThanOrEqual( '2027-01-03', end( $result['dates'] ) );
	}

	/**
	 * A run that finishes on its own before either cap says so.
	 */
	public function test_a_series_that_ends_on_its_own_is_not_reported_as_capped(): void {
		$result = gwcvt_recurrence_dates( '2026-03-07', 'weekly', '2026-05-30' );

		$this->assertSame( '', $result['capped'] );
	}

	/* ── Refusals ────────────────────────────────────────────────────────── */

	/**
	 * 'once' is one date, whatever end date came along with it.
	 */
	public function test_once_is_one_date_and_ignores_the_end_date(): void {
		$result = gwcvt_recurrence_dates( '2026-03-07', 'once', '2026-12-31' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * No end date is a question nobody answered, and the honest answer is one
	 * shift rather than a year of them.
	 */
	public function test_a_repeating_pattern_with_no_end_date_makes_one_shift(): void {
		$result = gwcvt_recurrence_dates( '2026-03-07', 'weekly', '' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * An end date before the start is the same case.
	 */
	public function test_an_end_date_before_the_start_makes_one_shift(): void {
		$result = gwcvt_recurrence_dates( '2026-03-07', 'weekly', '2026-01-01' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * @param string $start   The start date under test.
	 * @param string $pattern Pattern key.
	 */
	#[DataProvider( 'refusals' )]
	public function test_it_refuses_what_it_cannot_read( string $start, string $pattern ): void {
		$result = gwcvt_recurrence_dates( $start, $pattern, '2026-12-31' );

		$this->assertSame( array(), $result['dates'] );
	}

	public static function refusals(): array {
		return array(
			'a day that does not exist' => array( '2026-02-31', 'weekly' ),
			'a month that does not'     => array( '2026-13-01', 'weekly' ),
			'not a date at all'         => array( 'saturday', 'weekly' ),
			'an empty date'             => array( '', 'weekly' ),
			'a pattern we do not have'  => array( '2026-03-07', 'every-third-tuesday' ),
			'an empty pattern'          => array( '2026-03-07', '' ),
		);
	}

	/**
	 * The patterns are a translated lookup table, so it has to be a function with
	 * a memo rather than a const — a const is evaluated before the request's
	 * translations load. Asserted here so the shape cannot quietly change.
	 */
	public function test_the_patterns_are_keyed_by_what_the_form_stores(): void {
		$patterns = gwcvt_recurrence_patterns();

		$this->assertSame(
			array( 'once', 'daily', 'weekdays', 'weekly', 'fortnightly', 'monthly' ),
			array_keys( $patterns )
		);
	}
}

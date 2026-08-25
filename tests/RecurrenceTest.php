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
		gwc_vt_test_reset();
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
		$result = gwc_vt_recurrence_dates( $start, $pattern, $until );

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
		$result = gwc_vt_recurrence_dates( '2026-03-07', 'weekly', '2026-03-27' );

		$this->assertSame( array( '2026-03-07', '2026-03-14', '2026-03-21' ), $result['dates'] );
	}

	/* ── The awkward calendar ────────────────────────────────────────────── */

	/**
	 * A month with no fifth Saturday is skipped, never nudged onto a different
	 * one. Somebody turning up to a locked door is the failure this avoids.
	 */
	public function test_a_month_without_a_fifth_saturday_is_skipped_not_moved(): void {
		// 30 May 2026 is the fifth Saturday. June and July 2026 have only four.
		$result = gwc_vt_recurrence_dates( '2026-05-30', 'monthly', '2026-08-31' );

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
		$result = gwc_vt_recurrence_dates( '2026-03-01', 'weekly', '2026-03-22' );

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
		$result = gwc_vt_recurrence_dates( '2026-01-31', 'monthly', '2026-06-30' );

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
		$result = gwc_vt_recurrence_dates( '2026-01-01', 'daily', '2026-12-31' );

		$this->assertCount( GWC_VT_RECURRENCE_MAX, $result['dates'] );
		$this->assertSame( 'count', $result['capped'] );
	}

	/**
	 * And so is the twelve-month horizon.
	 */
	public function test_it_reports_the_horizon_cap(): void {
		$result = gwc_vt_recurrence_dates( '2026-01-03', 'weekly', '2030-01-01' );

		$this->assertSame( 'horizon', $result['capped'] );
		$this->assertSame( '2026-01-03', $result['dates'][0] );

		// Nothing past a year out.
		$this->assertLessThanOrEqual( '2027-01-03', end( $result['dates'] ) );
	}

	/**
	 * A run that finishes on its own before either cap says so.
	 */
	public function test_a_series_that_ends_on_its_own_is_not_reported_as_capped(): void {
		$result = gwc_vt_recurrence_dates( '2026-03-07', 'weekly', '2026-05-30' );

		$this->assertSame( '', $result['capped'] );
	}

	/* ── Refusals ────────────────────────────────────────────────────────── */

	/**
	 * 'once' is one date, whatever end date came along with it.
	 */
	public function test_once_is_one_date_and_ignores_the_end_date(): void {
		$result = gwc_vt_recurrence_dates( '2026-03-07', 'once', '2026-12-31' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * No end date is a question nobody answered, and the honest answer is one
	 * shift rather than a year of them.
	 */
	public function test_a_repeating_pattern_with_no_end_date_makes_one_shift(): void {
		$result = gwc_vt_recurrence_dates( '2026-03-07', 'weekly', '' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * An end date before the start is the same case.
	 */
	public function test_an_end_date_before_the_start_makes_one_shift(): void {
		$result = gwc_vt_recurrence_dates( '2026-03-07', 'weekly', '2026-01-01' );

		$this->assertSame( array( '2026-03-07' ), $result['dates'] );
	}

	/**
	 * @param string $start   The start date under test.
	 * @param string $pattern Pattern key.
	 */
	#[DataProvider( 'refusals' )]
	public function test_it_refuses_what_it_cannot_read( string $start, string $pattern ): void {
		$result = gwc_vt_recurrence_dates( $start, $pattern, '2026-12-31' );

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
		$patterns = gwc_vt_recurrence_patterns();

		$this->assertSame(
			array( 'once', 'daily', 'weekdays', 'weekly', 'fortnightly', 'monthly' ),
			array_keys( $patterns )
		);
	}

	/* ── Saying it before the save ───────────────────────────────────────────
	 * The preview exists so a coordinator finds out what a repeat will create
	 * before they commit to it rather than after. What makes it worth having is
	 * that it is the same arithmetic — so the thing most worth asserting is not
	 * the wording but that the two cannot disagree.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * The number the box promises is the number the save will make.
	 *
	 * Asserted against gwc_vt_recurrence_dates() rather than against a literal,
	 * because a literal would go on passing if the preview grew its own copy of
	 * the rule and both were changed together. This fails the moment they are
	 * two implementations.
	 *
	 * @param string $start   First occurrence.
	 * @param string $pattern Pattern key.
	 * @param string $until   Last date to consider.
	 */
	#[DataProvider( 'provide_runs_worth_previewing' )]
	public function test_the_preview_promises_what_the_save_would_make( string $start, string $pattern, string $until ): void {
		$run     = gwc_vt_recurrence_dates( $start, $pattern, $until );
		$preview = gwc_vt_recurrence_preview( $start, $pattern, $until );

		$this->assertSame( count( $run['dates'] ), $preview['count'] );
		$this->assertSame( $run['capped'], $preview['capped'] );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function provide_runs_worth_previewing(): array {
		return array(
			'a term of Saturdays'      => array( '2026-08-08', 'weekly', '2026-12-19' ),
			'every other week'         => array( '2026-08-08', 'fortnightly', '2026-12-19' ),
			'weekdays, which skip'     => array( '2026-08-03', 'weekdays', '2026-08-31' ),
			'monthly across a gap'     => array( '2026-01-31', 'monthly', '2026-12-31' ),
			'past the count cap'       => array( '2026-01-01', 'daily', '2026-12-31' ),
			'past the horizon'         => array( '2026-01-03', 'weekly', '2030-01-01' ),
			'no end date at all'       => array( '2026-08-08', 'weekly', '' ),
			'an end before the start'  => array( '2026-08-08', 'weekly', '2026-08-01' ),
		);
	}

	public function test_a_run_that_does_not_repeat_has_nothing_to_preview(): void {
		$preview = gwc_vt_recurrence_preview( '2026-08-08', 'once', '2026-12-19' );

		$this->assertFalse( $preview['repeats'] );
		$this->assertSame( '', $preview['headline'] );
	}

	public function test_a_pattern_it_has_never_heard_of_has_nothing_to_preview(): void {
		$preview = gwc_vt_recurrence_preview( '2026-08-08', 'every-third-tuesday', '2026-12-19' );

		$this->assertFalse( $preview['repeats'] );
	}

	/**
	 * The count reaches the words, both of them.
	 *
	 * The button and the box say the same number, because they are the same
	 * sentence said in two places — a box promising twenty over a button
	 * offering to add one is the confusion this whole change is about.
	 */
	public function test_the_count_reaches_the_headline_and_the_button(): void {
		$preview = gwc_vt_recurrence_preview( '2026-08-08', 'weekly', '2026-12-19' );

		$this->assertSame( 20, $preview['count'] );
		$this->assertStringContainsString( '20', $preview['headline'] );
		$this->assertStringContainsString( '20', $preview['submit'] );
	}

	/**
	 * A repeat with no end date makes one shift, and the preview says so.
	 *
	 * gwc_vt_recurrence_dates() has always answered this way — "no end date is
	 * not forever, it is a question nobody answered" — but until now the answer
	 * arrived after the save, as a schedule with one Saturday on it. Somebody
	 * who meant to book a term and got a single shift found out by looking.
	 */
	public function test_a_repeat_with_no_end_date_says_it_will_make_one(): void {
		$preview = gwc_vt_recurrence_preview( '2026-08-08', 'weekly', '' );

		$this->assertTrue( $preview['repeats'], 'the box has to appear to say this' );
		$this->assertSame( 1, $preview['count'] );
		$this->assertNotSame( '', $preview['headline'] );
		$this->assertSame( '', $preview['note'], 'nothing was truncated; this is not a cap' );
	}

	/* ── The capped sentences, said once ─────────────────────────────────── */

	public function test_a_capped_run_carries_the_note_the_save_would_print(): void {
		$horizon = gwc_vt_recurrence_preview( '2026-01-03', 'weekly', '2030-01-01' );

		$this->assertSame( 'horizon', $horizon['capped'] );
		$this->assertSame( gwc_vt_recurrence_capped_note( 'horizon' ), $horizon['note'] );
		$this->assertNotSame( '', $horizon['note'] );
	}

	public function test_a_run_that_was_not_capped_carries_no_note(): void {
		$preview = gwc_vt_recurrence_preview( '2026-08-08', 'weekly', '2026-12-19' );

		$this->assertSame( '', $preview['capped'] );
		$this->assertSame( '', $preview['note'] );
	}

	public function test_the_two_caps_do_not_say_the_same_thing(): void {
		$this->assertNotSame(
			gwc_vt_recurrence_capped_note( 'count' ),
			gwc_vt_recurrence_capped_note( 'horizon' ),
			'they are different news and a coordinator does different things about them'
		);
	}

	public function test_no_cap_is_no_sentence(): void {
		$this->assertSame( '', gwc_vt_recurrence_capped_note( '' ) );
	}

	/**
	 * The caps quote their own constants rather than a copy of the number.
	 */
	public function test_the_cap_sentences_quote_the_constants(): void {
		$this->assertStringContainsString(
			(string) GWC_VT_RECURRENCE_MAX,
			gwc_vt_recurrence_capped_note( 'count' )
		);

		$this->assertStringContainsString(
			(string) GWC_VT_RECURRENCE_HORIZON_MONTHS,
			gwc_vt_recurrence_capped_note( 'horizon' )
		);
	}
}

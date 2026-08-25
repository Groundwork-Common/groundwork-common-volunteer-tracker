<?php
/**
 * Narrowing the schedule: which rows a filter keeps, and how many it counts.
 *
 * The rows arrive carrying their state — gwc_vt_schedule_rows() works that out
 * once, against the database — so everything here is arithmetic over an array
 * and belongs in the unit suite. What needs a database is the search, which
 * reaches the roster, and that is covered by
 * tests/integration/schedule-filters.php.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleFilterTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * A schedule of rows in known states.
	 *
	 * @return array[]
	 */
	private function rows(): array {
		return array(
			array( 'type' => 'shift', 'id' => 1, 'date' => '2031-03-01', 'state' => 'short' ),
			array( 'type' => 'shift', 'id' => 2, 'date' => '2031-03-02', 'state' => 'ok' ),
			array( 'type' => 'event', 'id' => 3, 'date' => '2031-03-03', 'state' => 'short' ),
			array( 'type' => 'shift', 'id' => 4, 'date' => '2031-03-04', 'state' => 'full' ),
			array( 'type' => 'shift', 'id' => 5, 'date' => '2031-03-05', 'state' => 'cancelled' ),
			array( 'type' => 'shift', 'id' => 6, 'date' => '2031-03-06', 'state' => 'awaiting' ),
			array( 'type' => 'shift', 'id' => 7, 'date' => '2031-03-07', 'state' => 'logged' ),
		);
	}

	/* ── The count and the list are the same array ───────────────────────────
	 * A chip reading "Short of people · 7" over six rows is the failure this
	 * plugin already has a rule about, said inside one screen. The only way it
	 * cannot happen is for the counting and the drawing to run over the same
	 * rows, so that is what is asserted: for every filter, the number on the
	 * chip is the number of rows the filter leaves.
	 * ─────────────────────────────────────────────────────────────────────── */

	#[DataProvider( 'provide_filters' )]
	public function test_the_chip_count_is_the_number_of_rows_it_leaves( string $state ): void {
		$rows   = $this->rows();
		$counts = gwc_vt_schedule_state_counts( $rows );
		$kept   = gwc_vt_filter_schedule_rows( $rows, $state, '' );

		$this->assertSame( $counts[ $state ], count( $kept ), $state );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function provide_filters(): array {
		return array(
			'short'     => array( 'short' ),
			'awaiting'  => array( 'awaiting' ),
			'full'      => array( 'full' ),
			'cancelled' => array( 'cancelled' ),
		);
	}

	public function test_every_offered_filter_is_counted(): void {
		$counts = gwc_vt_schedule_state_counts( $this->rows() );

		$this->assertSame( GWC_VT_SCHEDULE_FILTERS, array_keys( $counts ) );
	}

	/**
	 * A state with nothing in it counts zero rather than being absent.
	 *
	 * The renderer decides not to offer an empty chip; the counting does not
	 * get to make that decision for it by leaving the key out.
	 */
	public function test_a_state_with_nothing_in_it_counts_zero(): void {
		$counts = gwc_vt_schedule_state_counts(
			array(
				array( 'type' => 'shift', 'id' => 1, 'date' => '2031-03-01', 'state' => 'ok' ),
			)
		);

		$this->assertSame( 0, $counts['short'] );
		$this->assertSame( 0, $counts['cancelled'] );
	}

	/**
	 * 'ok' and 'logged' are real states with no chip.
	 *
	 * Neither is something a coordinator hunts for — "show me the shifts that
	 * are fine" is not a question — so they are deliberately absent from the
	 * chips while still being what most rows are.
	 */
	public function test_the_states_nobody_hunts_for_have_no_chip(): void {
		$this->assertNotContains( 'ok', GWC_VT_SCHEDULE_FILTERS );
		$this->assertNotContains( 'logged', GWC_VT_SCHEDULE_FILTERS );
	}

	public function test_no_filter_keeps_everything(): void {
		$rows = $this->rows();

		$this->assertSame( $rows, gwc_vt_filter_schedule_rows( $rows, '', '' ) );
	}

	/**
	 * Events answer the chips too.
	 *
	 * An event is a container over shifts, so it has no roster of its own — but
	 * "show me what is short" means the festival with three empty times as much
	 * as it means Saturday. gwc_vt_event_state() puts an event into the same
	 * six words, and this asserts the filter does not quietly drop it.
	 */
	public function test_an_event_is_kept_by_the_filter_that_matches_it(): void {
		$kept = gwc_vt_filter_schedule_rows( $this->rows(), 'short', '' );

		$types = array_column( $kept, 'type' );

		$this->assertContains( 'event', $types );
		$this->assertContains( 'shift', $types );
	}

	/**
	 * Filtering keeps the order it was given.
	 *
	 * The rows arrive sorted by date and the screen prints them in that order.
	 * A filter that re-keyed or reordered would put next Saturday above today.
	 */
	public function test_filtering_keeps_the_order(): void {
		$kept = gwc_vt_filter_schedule_rows( $this->rows(), 'short', '' );

		$this->assertSame( array( 1, 3 ), array_column( $kept, 'id' ) );
		$this->assertSame( array( 0, 1 ), array_keys( $kept ) );
	}

	/**
	 * A state nobody offers keeps everything rather than emptying the screen.
	 *
	 * gwc_vt_schedule_filter() already refuses anything not in the list, so
	 * this is the second half of the same guard: a URL carrying
	 * ?gwc_vt_state=nonsense shows the schedule, not an empty table.
	 */
	public function test_a_state_it_does_not_offer_is_not_a_filter(): void {
		$this->assertSame( '', gwc_vt_schedule_filter() );
	}

	/* ── Which heading a date sits under ─────────────────────────────────── */

	public function test_the_past_view_is_grouped_by_month(): void {
		$this->assertSame( 'March 2031', gwc_vt_schedule_group_label( '2031-03-04', 'past' ) );
	}

	/**
	 * Reading backwards, "This week" would be a heading over Tuesday and then
	 * Monday, which is a frame that makes the list harder to read rather than
	 * easier. The past view gets month names throughout.
	 */
	public function test_the_past_view_does_not_say_this_week(): void {
		$label = gwc_vt_schedule_group_label( gwc_vt_today(), 'past' );

		$this->assertNotSame( 'This week', $label );
	}

	public function test_a_date_it_cannot_read_still_gets_a_heading(): void {
		$this->assertSame( 'Undated', gwc_vt_schedule_group_label( 'not-a-date', 'upcoming' ) );
		$this->assertSame( 'Undated', gwc_vt_schedule_group_label( '', 'upcoming' ) );
	}

	/**
	 * Today is in this week, and a date past the fortnight is in its month.
	 *
	 * Anchored to gwc_vt_today() rather than to a literal, because the boundary
	 * moves: a fixture dated next March would be "this week" for seven days a
	 * year and a month name for the rest.
	 */
	public function test_the_fortnight_reads_forwards(): void {
		$this->assertSame(
			'This week',
			gwc_vt_schedule_group_label( gwc_vt_today(), 'upcoming' )
		);

		$far = gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +90 days' ) );

		$this->assertNotSame( 'This week', gwc_vt_schedule_group_label( $far, 'upcoming' ) );
		$this->assertNotSame( 'Next week', gwc_vt_schedule_group_label( $far, 'upcoming' ) );
	}
}

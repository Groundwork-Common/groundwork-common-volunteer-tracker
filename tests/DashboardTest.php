<?php
/**
 * The worklist: what appears, and in what order.
 *
 * The counts themselves come from the database and are covered by
 * tests/integration/dashboard.php. What is here is the part that decides what a
 * coordinator sees first, which is the whole argument of the screen.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Every queue with something in it.
	 *
	 * @return array<string, int>
	 */
	private function everything(): array {
		return array(
			'unverified'   => 8,
			'unmatched'    => 2,
			'unreconciled' => 1,
			'understaffed' => 2,
			'overdue'      => 1,
		);
	}

	/**
	 * The keys of the worklist, in the order they render.
	 *
	 * @param array $counts What is waiting.
	 * @return string[]
	 */
	private function keys( array $counts ): array {
		return array_column( gwc_vt_dashboard_items( $counts ), 'key' );
	}

	/* ── Ordered by what is lost if it waits ─────────────────────────────────
	 * Not by size, and not by how loud it feels. Unlogged hours are hours on
	 * nobody's record and every week takes them further from anybody's memory.
	 * Short shifts have a deadline. A missed requirement matters enormously to
	 * one person and cannot be fixed today. Verifying and matching both keep.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_it_leads_with_what_is_lost_if_it_waits(): void {
		$this->assertSame(
			array( 'unreconciled', 'understaffed', 'overdue', 'unverified', 'unmatched' ),
			$this->keys( $this->everything() )
		);
	}

	/**
	 * The order is fixed, not derived from the numbers. Eight shifts to verify
	 * is a bigger number than one unlogged shift and a smaller problem.
	 */
	public function test_a_big_count_does_not_jump_the_queue(): void {
		$keys = $this->keys(
			array(
				'unverified'   => 400,
				'unreconciled' => 1,
			)
		);

		$this->assertSame( array( 'unreconciled', 'unverified' ), $keys );
	}

	/* ── Nothing empty appears ───────────────────────────────────────────────
	 * A screen that reports "0 waiting" five times over is one people stop
	 * reading — and then the line that says Saturday is short gets skimmed
	 * along with it.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_a_queue_at_zero_is_absent(): void {
		$keys = $this->keys(
			array(
				'unverified'   => 0,
				'unmatched'    => 3,
				'unreconciled' => 0,
			)
		);

		$this->assertSame( array( 'unmatched' ), $keys );
	}

	public function test_nothing_waiting_is_an_empty_list(): void {
		$this->assertSame( array(), gwc_vt_dashboard_items( array() ) );

		$this->assertSame(
			array(),
			gwc_vt_dashboard_items(
				array(
					'unverified'   => 0,
					'unmatched'    => 0,
					'unreconciled' => 0,
					'understaffed' => 0,
					'overdue'      => 0,
				)
			)
		);
	}

	/**
	 * A count that arrives negative — nothing should produce one, but a filter
	 * or a miscount could — is treated as nothing rather than rendered.
	 */
	public function test_a_negative_count_is_treated_as_nothing(): void {
		$this->assertSame( array(), $this->keys( array( 'unverified' => -3 ) ) );
	}

	public function test_a_key_it_does_not_know_is_ignored(): void {
		$this->assertSame( array(), $this->keys( array( 'something_else' => 9 ) ) );
	}

	/* ── What each line says ─────────────────────────────────────────────── */

	public function test_every_line_carries_a_count_a_sentence_and_an_action(): void {
		foreach ( gwc_vt_dashboard_items( $this->everything() ) as $item ) {
			$this->assertGreaterThan( 0, $item['count'] );
			$this->assertNotSame( '', $item['what'], $item['key'] . ' has nothing to say' );
			$this->assertNotSame( '', $item['why'], $item['key'] . ' does not say why it matters' );
			$this->assertNotSame( '', $item['action'], $item['key'] . ' offers nothing to do' );
			$this->assertContains( $item['severity'], array( 'critical', 'waiting' ) );
		}
	}

	/**
	 * The sentence never states the count.
	 *
	 * The count is rendered beside it as its own element, so a sentence holding
	 * it too reads "2  2 shifts this week are short of people". Both nooped
	 * forms are therefore free of placeholders — and both, not just the
	 * singular, because a placeholder in only one of them is a genuine i18n bug:
	 * Russian, Polish and Arabic use what gettext calls the singular for 21, 31
	 * and 101 as well.
	 *
	 * @param string $key   Which queue.
	 * @param int    $count How many.
	 */
	#[DataProvider( 'singulars' )]
	public function test_the_sentence_leaves_the_number_to_the_column( string $key, int $count ): void {
		$items = gwc_vt_dashboard_items( array( $key => $count ) );

		$this->assertCount( 1, $items );
		$this->assertSame( $count, $items[0]['count'] );
		$this->assertDoesNotMatchRegularExpression( '/\d/', $items[0]['what'], 'the sentence restates a count the column already shows' );
		$this->assertStringNotContainsString( '%', $items[0]['what'], 'a placeholder survived into the rendered sentence' );
	}

	public static function singulars(): array {
		return array(
			'one unlogged shift'   => array( 'unreconciled', 1 ),
			'four unlogged shifts' => array( 'unreconciled', 4 ),
			'one short shift'      => array( 'understaffed', 1 ),
			'three short shifts'   => array( 'understaffed', 3 ),
			'one person overdue'   => array( 'overdue', 1 ),
			'two people overdue'   => array( 'overdue', 2 ),
			'one to verify'        => array( 'unverified', 1 ),
			'nine to verify'       => array( 'unverified', 9 ),
			'one to match'         => array( 'unmatched', 1 ),
			'five to match'        => array( 'unmatched', 5 ),
		);
	}

	/**
	 * The two that cannot wait are the two that carry the loud stripe. Color
	 * is reinforcement — every line says its own count and its own sentence —
	 * but the reinforcement should still be pointing at the right lines.
	 */
	public function test_only_the_time_critical_lines_are_loud(): void {
		$severity = array_column( gwc_vt_dashboard_items( $this->everything() ), 'severity', 'key' );

		$this->assertSame( 'critical', $severity['unreconciled'] );
		$this->assertSame( 'critical', $severity['understaffed'] );
		$this->assertSame( 'waiting', $severity['overdue'] );
		$this->assertSame( 'waiting', $severity['unverified'] );
		$this->assertSame( 'waiting', $severity['unmatched'] );
	}

	/* ── The screen holds no names ───────────────────────────────────────────
	 * The rule the whole worklist is arranged around: every line is a count and
	 * a link, and the names live on the screen somebody goes to deliberately.
	 * The overdue line is the one that would be tempting to expand, and it is
	 * the one that must not be.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_a_line_carries_no_room_for_a_name(): void {
		foreach ( gwc_vt_dashboard_items( $this->everything() ) as $item ) {
			$this->assertSame(
				array( 'key', 'count', 'severity', 'what', 'why', 'action' ),
				array_keys( $item ),
				'a worklist line gained a field, and the only thing it could carry is somebody'
			);
		}
	}

	public function test_the_overdue_line_points_at_the_list_rather_than_naming_anybody(): void {
		$items = gwc_vt_dashboard_items( array( 'overdue' => 2 ) );

		$this->assertStringNotContainsStringIgnoringCase( 'court', $items[0]['what'] );
		$this->assertStringNotContainsStringIgnoringCase( 'court', $items[0]['why'] );
		$this->assertStringContainsString( 'volunteer list', $items[0]['why'] );
	}

	/**
	 * Every line names a job rather than reporting a state.
	 *
	 * The screen exists to be acted on. "A shift has happened and its hours are
	 * not logged" makes the reader translate a description into a task before
	 * they can decide anything; "Write up a shift that has already happened"
	 * does not. The state belongs on the second line, which answers "and if I
	 * leave it?".
	 */
	public function test_every_line_opens_with_something_to_do(): void {
		foreach ( gwc_vt_dashboard_items( $this->everything() ) as $item ) {
			$this->assertMatchesRegularExpression(
				'/^(Write up|Find|Check on|Verify|Match)\b/',
				$item['what'],
				$item['key'] . ' describes a state instead of naming the job'
			);
		}
	}

	/* ── Where this week stops ───────────────────────────────────────────────
	 * Coming up runs a fortnight split in two. The split is calendar
	 * arithmetic, and the day it breaks on belongs to the site — WordPress
	 * already asks, and a great many places answer Saturday or Sunday.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $today   Y-m-d.
	 * @param int    $start   start_of_week, as WordPress stores it.
	 * @param string $ends    Expected last day of this week.
	 * @param string $further Expected last day of next week.
	 */
	#[DataProvider( 'fortnights' )]
	public function test_the_week_ends_where_the_site_says_it_does( string $today, int $start, string $ends, string $further ): void {
		$bounds = gwc_vt_fortnight_bounds( $today, $start );

		$this->assertSame( $ends, $bounds['this_week'] );
		$this->assertSame( $further, $bounds['fortnight'] );
	}

	public static function fortnights(): array {
		/* 2026-08-06 is a Thursday. */
		return array(
			'Thursday, weeks starting Monday' => array( '2026-08-06', 1, '2026-08-09', '2026-08-16' ),
			'Thursday, weeks starting Sunday' => array( '2026-08-06', 0, '2026-08-08', '2026-08-15' ),

			/* On the first day of the week, this week is a full seven days. */
			'Monday, weeks starting Monday'   => array( '2026-08-03', 1, '2026-08-09', '2026-08-16' ),

			/* On the last, it ends tonight — and the fortnight is really eight
			 * days, which is the honest answer: "next week" has not moved. */
			'Sunday, weeks starting Monday'   => array( '2026-08-09', 1, '2026-08-09', '2026-08-16' ),

			/* Across a month, and across a year. */
			'end of the month'                => array( '2026-08-30', 1, '2026-08-30', '2026-09-06' ),
			'end of the year'                 => array( '2026-12-31', 1, '2027-01-03', '2027-01-10' ),

			/* A leap day is a day like any other to date arithmetic that never
			 * touches a timezone, and this is the assertion that says so. */
			'a leap year'                     => array( '2028-02-28', 1, '2028-03-05', '2028-03-12' ),
		);
	}

	/**
	 * A start_of_week outside 0–6 is wrapped rather than producing a bound that
	 * is days adrift. Nothing in WordPress writes one, and an option is an
	 * option.
	 */
	public function test_a_nonsense_week_start_still_produces_a_week(): void {
		$sane = gwc_vt_fortnight_bounds( '2026-08-06', 1 );

		$this->assertSame( $sane, gwc_vt_fortnight_bounds( '2026-08-06', 8 ) );
		$this->assertSame( $sane, gwc_vt_fortnight_bounds( '2026-08-06', -6 ) );
	}

	public function test_an_unreadable_date_falls_back_to_today(): void {
		$bounds = gwc_vt_fortnight_bounds( 'the day after tomorrow', 1 );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $bounds['this_week'] );
		$this->assertGreaterThan( $bounds['this_week'], $bounds['fortnight'] );
	}

	/* ── Filterable ──────────────────────────────────────────────────────── */

	public function test_a_site_can_add_a_line_of_its_own(): void {
		add_filter(
			'gwc_vt_dashboard_items',
			static function ( array $items ): array {
				$items[] = array(
					'key'      => 'acme',
					'count'    => 3,
					'severity' => 'waiting',
					'what'     => 'Three grant reports are due',
					'why'      => 'Because the funder asked.',
					'action'   => 'Open them',
				);

				return $items;
			}
		);

		$this->assertContains( 'acme', $this->keys( $this->everything() ) );

		gwc_vt_test_reset_filters();
	}
}

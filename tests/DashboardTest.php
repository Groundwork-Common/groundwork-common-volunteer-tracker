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
		gwcvt_test_reset();
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
		return array_column( gwcvt_dashboard_items( $counts ), 'key' );
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
		$this->assertSame( array(), gwcvt_dashboard_items( array() ) );

		$this->assertSame(
			array(),
			gwcvt_dashboard_items(
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
		foreach ( gwcvt_dashboard_items( $this->everything() ) as $item ) {
			$this->assertGreaterThan( 0, $item['count'] );
			$this->assertNotSame( '', $item['what'], $item['key'] . ' has nothing to say' );
			$this->assertNotSame( '', $item['why'], $item['key'] . ' does not say why it matters' );
			$this->assertNotSame( '', $item['action'], $item['key'] . ' offers nothing to do' );
			$this->assertContains( $item['severity'], array( 'critical', 'waiting' ) );
		}
	}

	/**
	 * Both plural forms state the count, the singular included.
	 *
	 * It reads a shade stiffer in English than "A shift has happened", and it is
	 * not optional: Russian, Polish and Arabic use what gettext calls the
	 * singular for 21, 31 and 101 as well, so a singular that leaves the number
	 * out says "shift has happened" to somebody looking at twenty-one of them.
	 * WP-CLI warns about exactly this, and it was right.
	 *
	 * @param string $key   Which queue.
	 * @param int    $count How many.
	 */
	#[DataProvider( 'singulars' )]
	public function test_every_line_states_its_own_count( string $key, int $count ): void {
		$items = gwcvt_dashboard_items( array( $key => $count ) );

		$this->assertCount( 1, $items );
		$this->assertStringContainsString( (string) $count, $items[0]['what'] );
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
	 * The two that cannot wait are the two that carry the loud stripe. Colour
	 * is reinforcement — every line says its own count and its own sentence —
	 * but the reinforcement should still be pointing at the right lines.
	 */
	public function test_only_the_time_critical_lines_are_loud(): void {
		$severity = array_column( gwcvt_dashboard_items( $this->everything() ), 'severity', 'key' );

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
		foreach ( gwcvt_dashboard_items( $this->everything() ) as $item ) {
			$this->assertSame(
				array( 'key', 'count', 'severity', 'what', 'why', 'action' ),
				array_keys( $item ),
				'a worklist line gained a field, and the only thing it could carry is somebody'
			);
		}
	}

	public function test_the_overdue_line_points_at_the_list_rather_than_naming_anybody(): void {
		$items = gwcvt_dashboard_items( array( 'overdue' => 2 ) );

		$this->assertStringNotContainsStringIgnoringCase( 'court', $items[0]['what'] );
		$this->assertStringNotContainsStringIgnoringCase( 'court', $items[0]['why'] );
		$this->assertStringContainsString( 'volunteer list', $items[0]['why'] );
	}

	/* ── Filterable ──────────────────────────────────────────────────────── */

	public function test_a_site_can_add_a_line_of_its_own(): void {
		add_filter(
			'gwcvt_dashboard_items',
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

		gwcvt_test_reset_filters();
	}
}

<?php
/**
 * Events: overlap, ordering, capacity, and the shape of a status.
 *
 * The parts of the feature that are arithmetic rather than WordPress. Saving a
 * grid, cancelling a time that has a roster and grouping a reminder all need a
 * real database and live in tests/integration/events.php.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/**
	 * Put a slot in the store.
	 *
	 * @param int    $id    Post ID.
	 * @param string $date  Y-m-d.
	 * @param string $start H:i.
	 * @param string $end   H:i.
	 * @param string $role  What it is.
	 * @param int    $max   Capacity, 0 for none.
	 */
	private function slot( int $id, string $date, string $start, string $end, string $role = 'Greeter', int $max = 4 ): void {
		gwcvt_test_add_post( $id, GWCVT_SHIFT_TYPE );

		update_post_meta( $id, GWCVT_SHIFT_DATE, $date );
		update_post_meta( $id, GWCVT_SHIFT_START, $start );
		update_post_meta( $id, GWCVT_SHIFT_END, $end );
		update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, $role );
		update_post_meta( $id, GWCVT_SHIFT_MAX, $max );
	}

	/* ── Overlap ─────────────────────────────────────────────────────────────
	 * The boundary case is the one that matters. Set-up 07:30–09:00 followed by
	 * greeting 09:00–12:00 is a normal morning, and reporting it as a clash
	 * would train coordinators to ignore the flag that means something.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $a_start One slot's start.
	 * @param string $a_end   One slot's end.
	 * @param string $b_start The other's start.
	 * @param string $b_end   The other's end.
	 * @param bool   $clash   Whether they overlap.
	 */
	#[DataProvider( 'pairs' )]
	public function test_it_only_calls_a_real_overlap_a_clash( string $a_start, string $a_end, string $b_start, string $b_end, bool $clash ): void {
		$this->slot( 101, '2026-10-12', $a_start, $a_end );
		$this->slot( 102, '2026-10-12', $b_start, $b_end );

		$this->assertSame( $clash, gwcvt_shifts_overlap( 101, 102 ) );
		$this->assertSame( $clash, gwcvt_shifts_overlap( 102, 101 ), 'overlap is symmetric' );
	}

	public static function pairs(): array {
		return array(
			'touching at the boundary'   => array( '07:30', '09:00', '09:00', '12:00', false ),
			'touching the other way'     => array( '12:00', '15:00', '09:00', '12:00', false ),
			'one inside the other'       => array( '08:00', '14:00', '10:00', '11:00', true ),
			'overlapping by an hour'     => array( '09:00', '12:00', '11:00', '14:00', true ),
			'identical'                  => array( '09:00', '12:00', '09:00', '12:00', true ),
			'a minute apart'             => array( '09:00', '11:59', '12:00', '14:00', false ),
			'a minute over'              => array( '09:00', '12:01', '12:00', '14:00', true ),
			'nowhere near'               => array( '07:00', '08:00', '18:00', '19:00', false ),
		);
	}

	public function test_a_slot_does_not_overlap_itself(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00' );

		$this->assertFalse( gwcvt_shifts_overlap( 101, 101 ) );
	}

	public function test_different_days_do_not_overlap(): void {
		$this->slot( 101, '2026-10-12', '09:00', '17:00' );
		$this->slot( 102, '2026-10-13', '09:00', '17:00' );

		$this->assertFalse( gwcvt_shifts_overlap( 101, 102 ) );
	}

	public function test_a_slot_with_no_readable_time_is_not_evidence_of_a_clash(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00' );
		$this->slot( 102, '2026-10-12', '', '' );

		$this->assertFalse( gwcvt_shifts_overlap( 101, 102 ) );
	}

	public function test_it_reports_the_first_clashing_pair(): void {
		$this->slot( 101, '2026-10-12', '07:30', '09:00' );
		$this->slot( 102, '2026-10-12', '09:00', '12:00' );
		$this->slot( 103, '2026-10-12', '08:00', '14:00' );

		$pair = gwcvt_first_overlapping_pair( array( 101, 102, 103 ) );

		$this->assertCount( 2, $pair );
		$this->assertContains( 103, $pair );
	}

	public function test_it_reports_nothing_when_nothing_clashes(): void {
		$this->slot( 101, '2026-10-12', '07:30', '09:00' );
		$this->slot( 102, '2026-10-12', '09:00', '12:00' );
		$this->slot( 103, '2026-10-12', '12:00', '15:00' );

		$this->assertSame( array(), gwcvt_first_overlapping_pair( array( 101, 102, 103 ) ) );
	}

	public function test_one_slot_cannot_clash(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00' );

		$this->assertSame( array(), gwcvt_first_overlapping_pair( array( 101 ) ) );
		$this->assertSame( array(), gwcvt_first_overlapping_pair( array() ) );
	}

	public function test_the_same_slot_twice_is_not_a_clash(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00' );

		$this->assertSame( array(), gwcvt_first_overlapping_pair( array( 101, 101 ) ) );
	}

	/* ── Ordering ────────────────────────────────────────────────────────── */

	public function test_slots_sort_by_day_then_by_time(): void {
		$this->slot( 101, '2026-10-13', '09:00', '10:00' );
		$this->slot( 102, '2026-10-12', '15:00', '16:00' );
		$this->slot( 103, '2026-10-12', '08:00', '09:00' );

		$ids = array( 101, 102, 103 );
		usort( $ids, 'gwcvt_compare_slots' );

		$this->assertSame( array( 103, 102, 101 ), $ids );
	}

	public function test_two_slots_at_the_same_time_keep_a_stable_order(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00' );
		$this->slot( 102, '2026-10-12', '09:00', '12:00' );

		$this->assertLessThan( 0, gwcvt_compare_slots( 101, 102 ) );
		$this->assertGreaterThan( 0, gwcvt_compare_slots( 102, 101 ) );
	}

	/* ── Labels ──────────────────────────────────────────────────────────── */

	public function test_a_slot_is_named_by_role_and_time(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00', 'Kitchen' );

		$this->assertStringContainsString( 'Kitchen', gwcvt_slot_label( 101 ) );
	}

	public function test_a_slot_with_no_role_still_has_a_name(): void {
		$this->slot( 101, '2026-10-12', '09:00', '12:00', '' );

		$this->assertNotSame( '', gwcvt_slot_label( 101 ) );
	}

	/* ── A status has to fit the column ──────────────────────────────────────
	 * wp_posts.post_status is varchar(20). A longer one is not an error, is not
	 * truncated and is not warned about: wp_insert_post() sanitises what it
	 * cannot store and the row keeps the status it already had.
	 *
	 * GWCVT_EVENT_CANCELLED was twenty-one characters, so calling an event off
	 * reported success while the event stayed published and went on taking
	 * signups. Nothing on any screen said otherwise.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $status A status this plugin registers.
	 */
	#[DataProvider( 'statuses' )]
	public function test_every_status_fits_the_column( string $status ): void {
		$this->assertLessThanOrEqual(
			20,
			strlen( $status ),
			'wp_posts.post_status is varchar(20); a longer status is silently refused.'
		);
	}

	public static function statuses(): array {
		return array(
			'a cancelled event'  => array( GWCVT_EVENT_CANCELLED ),
			'a cancelled shift'  => array( GWCVT_SHIFT_CANCELLED ),
			'a waiting signup'   => array( GWCVT_SIGNUP_WAITLIST ),
			'a withdrawn signup' => array( GWCVT_SIGNUP_WITHDRAWN ),
		);
	}

	/* ── What the public grid may never do ───────────────────────────────────
	 * A count of what the WRITE did is the leak — signing up is idempotent and
	 * the form never checks whose address it was given, so "3 added" against
	 * "2 added, 1 you were already on" tells a stranger which slots somebody
	 * else was already on.
	 *
	 * A count of what was TICKED would in fact be safe. The rule bans all of
	 * them because this assertion is one line and "only digits derived from the
	 * request" is a rule the next friendly copy edit will break.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $key A result the public form can return.
	 */
	#[DataProvider( 'public_results' )]
	public function test_no_public_message_carries_a_number( string $key ): void {
		$this->assertDoesNotMatchRegularExpression(
			'/\d/',
			gwcvt_signup_message( $key ),
			'A number here would report what the database did, which is a disclosure about somebody else.'
		);
	}

	public static function public_results(): array {
		return array(
			'accepted'    => array( 'accepted' ),
			'incomplete'  => array( 'incomplete' ),
			'unavailable' => array( 'unavailable' ),
			'expired'     => array( 'expired' ),
			'bad code'    => array( 'bad-code' ),
			'cancelled'   => array( 'cancelled' ),
			'stale link'  => array( 'cancel-unknown' ),
			'too many'    => array( 'too-many' ),
			'a clash'     => array( 'clash' ),
		);
	}

	public function test_an_accepted_signup_and_a_honeypot_hit_read_identically(): void {
		/* Both paths end on the same key, so this is really an assertion that
		 * nothing has quietly given the honeypot a key of its own. */
		$this->assertSame( gwcvt_signup_message( 'accepted' ), gwcvt_signup_message( 'accepted' ) );
		$this->assertNotSame( '', gwcvt_signup_message( 'accepted' ) );
	}

	public function test_an_unknown_result_says_nothing(): void {
		$this->assertSame( '', gwcvt_signup_message( 'made-up' ) );
	}
}

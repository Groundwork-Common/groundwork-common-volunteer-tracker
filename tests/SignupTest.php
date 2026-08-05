<?php
/**
 * The cancellation token, and the lock that settles a shift's capacity.
 *
 * The rest of the signup model — creating one, promoting from the waiting list,
 * withdrawing — queries the database and is covered by
 * tests/integration/signups.php. What is unit-testable here is the arithmetic
 * and the two things that have to hold whatever the database says.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class SignupTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/**
	 * A signup with a created time, ready to be tokenised.
	 *
	 * @param int    $id      Post ID.
	 * @param string $created GMT datetime.
	 */
	private function signup( int $id, string $created = '2026-03-01 10:00:00' ): void {
		gwcvt_test_add_post( $id, GWCVT_SIGNUP_TYPE );
		update_post_meta( $id, GWCVT_SIGNUP_CREATED, $created );
	}

	/* ── The cancellation token ──────────────────────────────────────────── */

	public function test_a_signup_answers_to_its_own_token(): void {
		$this->signup( 10 );

		$this->assertTrue( gwcvt_signup_token_valid( 10, gwcvt_signup_token( 10 ) ) );
	}

	/**
	 * The signup ID is inside the digest, so a link that cancels one booking
	 * cannot be pointed at somebody else's.
	 */
	public function test_one_signups_token_does_not_open_another(): void {
		$this->signup( 10 );
		$this->signup( 11 );

		$this->assertFalse( gwcvt_signup_token_valid( 11, gwcvt_signup_token( 10 ) ) );
	}

	/**
	 * The revision is in the digest, so the link in the first email does not
	 * un-book a booking made after a withdrawal.
	 */
	public function test_a_token_dies_when_the_signup_is_made_again(): void {
		$this->signup( 10 );
		$old = gwcvt_signup_token( 10 );

		update_post_meta( 10, GWCVT_SIGNUP_REVISION, 2 );

		$this->assertFalse( gwcvt_signup_token_valid( 10, $old ) );
		$this->assertTrue( gwcvt_signup_token_valid( 10, gwcvt_signup_token( 10 ) ) );
	}

	/**
	 * And the revision alone is enough, without the created time moving.
	 *
	 * This is the regression: the digest was originally the signup ID and its
	 * created time, which is a mysql datetime with one-second resolution — so a
	 * withdrawal and a re-booking inside the same second produced an identical
	 * token and left the old cancellation link live. A double-submit is enough to
	 * hit that. How long a link stays valid must not depend on how fast somebody
	 * clicks.
	 */
	public function test_a_token_dies_even_when_the_clock_has_not_moved(): void {
		$this->signup( 10 );
		update_post_meta( 10, GWCVT_SIGNUP_REVISION, 1 );

		$old = gwcvt_signup_token( 10 );

		// Same second, same created value — only the revision moves.
		update_post_meta( 10, GWCVT_SIGNUP_REVISION, 2 );

		$this->assertFalse( gwcvt_signup_token_valid( 10, $old ) );
	}

	public function test_a_forged_token_is_refused(): void {
		$this->signup( 10 );

		$this->assertFalse( gwcvt_signup_token_valid( 10, str_repeat( 'a', 64 ) ) );
		$this->assertFalse( gwcvt_signup_token_valid( 10, '' ) );
	}

	/**
	 * A post ID that is not a signup is refused before the digest is computed,
	 * so a token cannot be aimed at an hour entry or a volunteer record.
	 */
	public function test_a_token_for_something_that_is_not_a_signup_is_refused(): void {
		gwcvt_test_add_post( 20, GWCVT_ENTRY_TYPE );
		update_post_meta( 20, GWCVT_SIGNUP_CREATED, '2026-03-01 10:00:00' );

		$this->assertFalse( gwcvt_signup_token_valid( 20, gwcvt_signup_token( 20 ) ) );
		$this->assertFalse( gwcvt_signup_token_valid( 999, gwcvt_signup_token( 999 ) ) );
	}

	/**
	 * Derived rather than stored, so the same facts give the same token and there
	 * is nothing sitting in a table to leak.
	 */
	public function test_the_token_is_stable(): void {
		$this->signup( 10 );

		$this->assertSame( gwcvt_signup_token( 10 ), gwcvt_signup_token( 10 ) );
	}

	/* ── The settling lock ───────────────────────────────────────────────── */

	public function test_only_one_caller_holds_the_lock(): void {
		$this->assertTrue( gwcvt_take_signup_lock( 5 ) );
		$this->assertFalse( gwcvt_take_signup_lock( 5 ) );
	}

	public function test_the_lock_is_per_shift(): void {
		$this->assertTrue( gwcvt_take_signup_lock( 5 ) );
		$this->assertTrue( gwcvt_take_signup_lock( 6 ) );
	}

	public function test_releasing_the_lock_lets_the_next_caller_in(): void {
		gwcvt_take_signup_lock( 5 );
		gwcvt_release_signup_lock( 5 );

		$this->assertTrue( gwcvt_take_signup_lock( 5 ) );
	}

	/**
	 * A lock left behind by a request that died mid-settle is stolen rather than
	 * waited on. Nothing is holding it and nothing is coming back to release it,
	 * so waiting would wedge the shift's capacity permanently.
	 */
	public function test_an_abandoned_lock_is_stolen(): void {
		update_option( 'gwcvt_signup_lock_5', time() - ( GWCVT_SIGNUP_LOCK_TTL + 5 ), false );

		$this->assertTrue( gwcvt_take_signup_lock( 5 ) );
	}

	public function test_a_lock_that_is_merely_recent_is_not_stolen(): void {
		update_option( 'gwcvt_signup_lock_5', time() - 1, false );

		$this->assertFalse( gwcvt_take_signup_lock( 5 ) );
	}

	/**
	 * Stealing a stale lock resets its clock, so two requests arriving together
	 * after an abandonment do not both decide it is theirs.
	 */
	public function test_stealing_the_lock_resets_its_clock(): void {
		update_option( 'gwcvt_signup_lock_5', time() - ( GWCVT_SIGNUP_LOCK_TTL + 5 ), false );

		$this->assertTrue( gwcvt_take_signup_lock( 5 ) );
		$this->assertFalse( gwcvt_take_signup_lock( 5 ) );
	}
}

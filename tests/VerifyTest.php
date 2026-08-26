<?php
/**
 * Attestation: the state machine, and the promise that it stays readable.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class VerifyTest extends TestCase {

	private const ENTRY     = 100;
	private const VERIFIER  = 7;
	private const OUTSIDER  = 8;
	private const VOLUNTEER = 300;

	protected function setUp(): void {
		gwc_vt_test_reset();

		gwc_vt_test_add_post( self::ENTRY, GWC_VT_ENTRY_TYPE, 'publish' );

		/* The fixture names somebody, because a real entry that reaches
		 * verification does. gwc_vt_verify_entry() refuses one that does not —
		 * attesting is one named person saying another named person did this
		 * work, and with no volunteer the sentence has no subject. Every test
		 * below is about some OTHER rule, so the fixture has to satisfy this
		 * one or they all fail for a reason none of them is checking. The rule
		 * itself is tested on its own, further down. */
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VOLUNTEER, (string) self::VOLUNTEER );

		gwc_vt_test_add_user( self::VERIFIER, array( 'gwc_vt_verify_hours' => true, 'edit_post' => true ), '', 'Dana Reyes' );
		gwc_vt_test_add_user( self::OUTSIDER, array( 'edit_posts' => true, 'edit_post' => true ), '', 'Sam Idris' );
	}

	/* ── Verifying ───────────────────────────────────────────────────────── */

	public function test_verifying_records_when_who_and_how(): void {
		$this->assertTrue( gwc_vt_verify_entry( self::ENTRY, self::VERIFIER ) );

		$this->assertTrue( gwc_vt_entry_is_verified( self::ENTRY ) );
		$this->assertSame( self::VERIFIER, (int) get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, true ) );
		$this->assertSame( 'staff', get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_METHOD, true ) );
		$this->assertNotSame( '', get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, true ) );
	}

	public function test_somebody_without_the_capability_cannot_verify(): void {
		$this->assertFalse( gwc_vt_verify_entry( self::ENTRY, self::OUTSIDER ) );
		$this->assertFalse( gwc_vt_entry_is_verified( self::ENTRY ) );
	}

	public function test_verifying_a_pending_entry_publishes_it(): void {
		gwc_vt_test_add_post( 101, GWC_VT_ENTRY_TYPE, 'pending' );
		update_post_meta( 101, GWC_VT_ENTRY_VOLUNTEER, (string) self::VOLUNTEER );

		gwc_vt_verify_entry( 101, self::VERIFIER );

		/* Attesting to a self-logged shift IS accepting the record. Making staff
		 * do both would be a two-step triage, and the second step is the one
		 * nobody finishes. */
		$this->assertSame( 'publish', get_post_status( 101 ) );
	}

	public function test_verifying_twice_does_not_move_the_timestamp(): void {
		gwc_vt_verify_entry( self::ENTRY, self::VERIFIER );

		$first = get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, true );

		gwc_vt_test_add_user( 9, array( 'gwc_vt_verify_hours' => true, 'edit_post' => true ), '', 'Someone Else' );
		gwc_vt_verify_entry( self::ENTRY, 9 );

		$this->assertSame( $first, get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, true ) );
		$this->assertSame(
			self::VERIFIER,
			(int) get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, true ),
			'The timestamp records when somebody FIRST said this happened; a second click must not rewrite it.'
		);
	}

	public function test_only_an_hour_entry_can_be_verified(): void {
		gwc_vt_test_add_post( 200, GWC_VT_VOLUNTEER_TYPE );
		gwc_vt_test_add_post( 201, 'page' );

		$this->assertFalse( gwc_vt_verify_entry( 200, self::VERIFIER ) );
		$this->assertFalse( gwc_vt_verify_entry( 201, self::VERIFIER ) );
	}

	/* ── An entry nobody has matched ─────────────────────────────────────── */

	public function test_an_entry_with_no_volunteer_cannot_be_verified(): void {
		gwc_vt_test_add_post( 210, GWC_VT_ENTRY_TYPE, 'publish' );
		update_post_meta( 210, GWC_VT_ENTRY_VOLUNTEER, '0' );

		$this->assertFalse( gwc_vt_verify_entry( 210, self::VERIFIER ) );
		$this->assertFalse( gwc_vt_entry_is_verified( 210 ) );
	}

	public function test_a_missing_volunteer_key_reads_the_same_as_a_stored_zero(): void {
		/* A self-logged entry arrives with the key set to '0'; one built by a
		 * route that never wrote it has no key at all. Both mean nobody, and a
		 * guard testing only one of them lets the other past. */
		gwc_vt_test_add_post( 211, GWC_VT_ENTRY_TYPE, 'publish' );

		$this->assertSame( 0, gwc_vt_entry_volunteer_id( 211 ) );
		$this->assertFalse( gwc_vt_verify_entry( 211, self::VERIFIER ) );
	}

	public function test_matching_an_entry_makes_it_verifiable(): void {
		gwc_vt_test_add_post( 212, GWC_VT_ENTRY_TYPE, 'publish' );
		update_post_meta( 212, GWC_VT_ENTRY_VOLUNTEER, '0' );

		$this->assertFalse( gwc_vt_verify_entry( 212, self::VERIFIER ) );

		update_post_meta( 212, GWC_VT_ENTRY_VOLUNTEER, (string) self::VOLUNTEER );

		$this->assertTrue(
			gwc_vt_verify_entry( 212, self::VERIFIER ),
			'The refusal must be about the missing volunteer and nothing else.'
		);
	}

	public function test_an_entry_verified_before_the_guard_still_reports_verified(): void {
		/* Installs that ran before this rule have rows like this. The function
		 * answers "is it verified after this call", and retroactively answering
		 * no about a timestamp that is really there would make the badge and the
		 * count disagree — this bug pointed the other way. That is why the
		 * idempotent check runs before the volunteer check, not after. */
		gwc_vt_test_add_post( 213, GWC_VT_ENTRY_TYPE, 'publish' );
		update_post_meta( 213, GWC_VT_ENTRY_VOLUNTEER, '0' );
		update_post_meta( 213, GWC_VT_ENTRY_VERIFIED_AT, '2026-01-01 09:00:00' );

		$this->assertTrue( gwc_vt_verify_entry( 213, self::VERIFIER ) );
	}

	public function test_an_unregistered_method_is_refused(): void {
		$this->assertFalse( gwc_vt_verify_entry( self::ENTRY, self::VERIFIER, 'supervisor_token' ) );
		$this->assertFalse( gwc_vt_entry_is_verified( self::ENTRY ) );
	}

	/* ── Withdrawing ─────────────────────────────────────────────────────── */

	public function test_withdrawing_clears_all_three_keys(): void {
		gwc_vt_verify_entry( self::ENTRY, self::VERIFIER );

		$this->assertTrue( gwc_vt_unverify_entry( self::ENTRY ) );

		$this->assertFalse( gwc_vt_entry_is_verified( self::ENTRY ) );
		$this->assertSame( '', get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, true ) );
		$this->assertSame(
			'',
			get_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_METHOD, true ),
			'Leaving the method behind would report how an entry was verified that is not verified.'
		);
	}

	public function test_withdrawing_an_unverified_entry_is_harmless(): void {
		$this->assertTrue( gwc_vt_unverify_entry( self::ENTRY ) );
		$this->assertFalse( gwc_vt_entry_is_verified( self::ENTRY ) );
	}

	/* ── Forward compatibility ───────────────────────────────────────────────
	 * The two properties that let emailed supervisor confirmation ship without
	 * a migration. If either of these breaks, that feature needs one.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_a_missing_method_reads_as_staff(): void {
		/* Exactly what an entry verified before _gwc_vt_verified_method existed
		 * looks like on disk. It must keep reading correctly forever, because
		 * nothing will ever go back and fill it in. */
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, '2026-03-04 10:00:00' );
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, self::VERIFIER );

		$this->assertSame( 'staff', gwc_vt_entry_method( self::ENTRY ) );
		$this->assertStringContainsString( 'Dana Reyes', gwc_vt_attestation_line( self::ENTRY ) );
	}

	public function test_a_site_can_add_an_attestation_method(): void {
		$add = static function ( array $methods ): array {
			$methods['supervisor_token'] = array(
				'label'       => static fn(): string => 'Supervisor confirmation',
				'letter_line' => static fn( array $c ): string => 'Confirmed by the shift supervisor',
				'admin_badge' => static fn( array $c ): string => 'Supervisor',
				'can_apply'   => static fn( int $user_id, int $entry_id ): bool => $user_id > 0 && $entry_id > 0,
			);
			return $methods;
		};

		add_filter( 'gwc_vt_attestation_methods', $add );

		$this->assertTrue( gwc_vt_verify_entry( self::ENTRY, self::VERIFIER, 'supervisor_token' ) );
		$this->assertSame( 'supervisor_token', gwc_vt_entry_method( self::ENTRY ) );
		$this->assertSame( 'Confirmed by the shift supervisor', gwc_vt_attestation_line( self::ENTRY ) );
		$this->assertSame( 'Supervisor', gwc_vt_attestation_badge( self::ENTRY ) );

		remove_filter( 'gwc_vt_attestation_methods', $add );
	}

	public function test_a_method_that_has_gone_away_still_renders(): void {
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, '2026-03-04 10:00:00' );
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, self::VERIFIER );
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_METHOD, 'some_deactivated_plugin' );

		/* An entry attested by a plugin that has since been deactivated must
		 * still produce a letter. Falling back to the staff wording is the
		 * plainest true statement available. */
		$this->assertStringContainsString( 'Dana Reyes', gwc_vt_attestation_line( self::ENTRY ) );
	}

	/* ── What the letter says ────────────────────────────────────────────── */

	public function test_an_unverified_entry_says_so(): void {
		$this->assertSame( 'Not verified', gwc_vt_attestation_line( self::ENTRY ) );
		$this->assertSame( gwc_vt_verification_label( 'unverified' ), gwc_vt_attestation_badge( self::ENTRY ) );
	}

	public function test_a_deleted_attester_is_named_as_such(): void {
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_AT, '2026-03-04 10:00:00' );
		update_post_meta( self::ENTRY, GWC_VT_ENTRY_VERIFIED_BY, 4242 );

		$line = gwc_vt_attestation_line( self::ENTRY );

		/* Saying the account is gone beats naming nobody and beats inventing a
		 * name. Somebody checking this letter needs to know the record is
		 * thinner than it looks. */
		$this->assertStringContainsString( 'has since been removed', $line );
	}

	public function test_the_attester_name_is_resolved_at_read_time(): void {
		gwc_vt_verify_entry( self::ENTRY, self::VERIFIER );

		$this->assertStringContainsString( 'Dana Reyes', gwc_vt_attestation_line( self::ENTRY ) );

		gwc_vt_test_add_user( self::VERIFIER, array( 'gwc_vt_verify_hours' => true, 'edit_post' => true ), '', 'Dana Reyes-Okafor' );

		/* A display name that changes should change everywhere. Copying it onto
		 * the entry at verification time would make two letters for the same
		 * hours disagree about who signed them. */
		$this->assertStringContainsString( 'Dana Reyes-Okafor', gwc_vt_attestation_line( self::ENTRY ) );
	}

	/* ── The count ───────────────────────────────────────────────────────── */

	public function test_verifying_drops_the_cached_count(): void {
		set_transient( GWC_VT_UNVERIFIED_COUNT_KEY, 12, 300 );

		gwc_vt_verify_entry( self::ENTRY, self::VERIFIER );

		$this->assertFalse(
			get_transient( GWC_VT_UNVERIFIED_COUNT_KEY ),
			'A stale bubble is worse than no bubble — it tells somebody they are caught up when they are not.'
		);
	}

	public function test_withdrawing_drops_the_cached_count(): void {
		gwc_vt_verify_entry( self::ENTRY, self::VERIFIER );
		set_transient( GWC_VT_UNVERIFIED_COUNT_KEY, 12, 300 );

		gwc_vt_unverify_entry( self::ENTRY );

		$this->assertFalse( get_transient( GWC_VT_UNVERIFIED_COUNT_KEY ) );
	}
}

<?php
/**
 * Who may attest, who may issue, and the fact that those are different people.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class CapsTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	public function test_the_three_gates_are_distinct(): void {
		$this->assertSame( 'gwc_vt_verify_hours', gwc_vt_cap( 'verify' ) );
		$this->assertSame( 'gwc_vt_issue_letters', gwc_vt_cap( 'issue' ) );
		$this->assertSame( 'manage_options', gwc_vt_cap( 'manage' ) );

		$this->assertNotSame(
			gwc_vt_cap( 'verify' ),
			gwc_vt_cap( 'issue' ),
			'Confirming a shift happened and signing the organization’s name to a letter are separate decisions.'
		);
	}

	public function test_a_site_can_remap_the_capabilities(): void {
		$remap = static function ( array $caps ): array {
			$caps['verify'] = 'edit_shifts';
			return $caps;
		};

		add_filter( 'gwc_vt_capabilities', $remap );

		$this->assertSame( 'edit_shifts', gwc_vt_cap( 'verify' ) );
		$this->assertSame( 'gwc_vt_issue_letters', gwc_vt_cap( 'issue' ), 'Remapping one must not disturb the others.' );

		remove_filter( 'gwc_vt_capabilities', $remap );
	}

	public function test_a_filter_that_drops_a_key_fails_closed(): void {
		$broken = static fn(): array => array();

		add_filter( 'gwc_vt_capabilities', $broken );

		/* Not the requested key back, and not an empty string. A capability
		 * plugin that answers true for unrecognised strings would turn either of
		 * those into "everybody may verify hours". */
		$this->assertSame( 'manage_options', gwc_vt_cap( 'verify' ) );

		remove_filter( 'gwc_vt_capabilities', $broken );
	}

	public function test_a_filter_that_returns_rubbish_fails_closed(): void {
		$broken = static fn(): array => array( 'verify' => '' );

		add_filter( 'gwc_vt_capabilities', $broken );

		$this->assertSame( 'manage_options', gwc_vt_cap( 'verify' ) );

		remove_filter( 'gwc_vt_capabilities', $broken );
	}

	/* ── Granting ────────────────────────────────────────────────────────── */

	public function test_it_grants_both_capabilities_to_the_default_roles(): void {
		$admin  = gwc_vt_test_add_role( 'administrator', array( 'manage_options' => true ) );
		$editor = gwc_vt_test_add_role( 'editor', array( 'edit_posts' => true ) );

		gwc_vt_grant_capabilities();

		foreach ( array( $admin, $editor ) as $role ) {
			$this->assertTrue( $role->has_cap( 'gwc_vt_verify_hours' ), $role->name );
			$this->assertTrue( $role->has_cap( 'gwc_vt_issue_letters' ), $role->name );
		}
	}

	public function test_granting_twice_does_not_rewrite_the_role(): void {
		$admin = gwc_vt_test_add_role( 'administrator' );

		gwc_vt_grant_capabilities();
		$after_first = $admin->writes;

		gwc_vt_grant_capabilities();

		/* add_cap() writes the whole role back to the options table. Running on
		 * every init means this happens on every admin page load, so the guard
		 * that stops it is not a micro-optimization. */
		$this->assertSame( $after_first, $admin->writes, 'The second run wrote to the role again.' );
	}

	public function test_a_capability_explicitly_set_to_false_stays_revoked(): void {
		$editor = gwc_vt_test_add_role( 'editor' );

		gwc_vt_grant_capabilities();

		/* What a capability-manager plugin writes when an administrator clears
		 * the box: the key stays, holding false. That is a recorded decision,
		 * and the isset() guard in gwc_vt_grant_capabilities() leaves it alone. */
		$editor->capabilities['gwc_vt_issue_letters'] = false;

		gwc_vt_grant_capabilities();

		$this->assertFalse(
			$editor->has_cap( 'gwc_vt_issue_letters' ),
			'An administrator who cleared this must not have it handed back on the next page load.'
		);
	}

	public function test_a_capability_removed_entirely_is_treated_as_lost_and_restored(): void {
		$editor = gwc_vt_test_add_role( 'editor' );

		gwc_vt_grant_capabilities();
		$editor->remove_cap( 'gwc_vt_issue_letters' );

		gwc_vt_grant_capabilities();

		/* The deliberate other half of the test above. remove_cap() unsets the
		 * key, which is byte-for-byte what a role rebuilt by a security plugin
		 * or restored from an older backup looks like — so this case cannot be
		 * told apart from a loss and is restored. Documented in
		 * gwc_vt_grant_capabilities(); asserted here so that changing it is a
		 * decision rather than an accident. */
		$this->assertTrue( $editor->has_cap( 'gwc_vt_issue_letters' ) );
	}

	public function test_a_missing_role_is_not_an_error(): void {
		// No roles registered at all — a site mid-restore, or a unit test harness.
		gwc_vt_grant_capabilities();

		$this->assertTrue( true, 'gwc_vt_grant_capabilities() must survive a site with no roles.' );
	}

	public function test_the_granted_roles_are_filterable(): void {
		$volunteers = gwc_vt_test_add_role( 'volunteer_coordinator' );
		$only_ours  = static fn(): array => array( 'volunteer_coordinator' );

		add_filter( 'gwc_vt_default_cap_roles', $only_ours );
		gwc_vt_grant_capabilities();
		remove_filter( 'gwc_vt_default_cap_roles', $only_ours );

		$this->assertTrue( $volunteers->has_cap( 'gwc_vt_verify_hours' ) );
	}

	/* ── The verification check ──────────────────────────────────────────── */

	public function test_verifying_needs_both_the_capability_and_access_to_the_record(): void {
		gwc_vt_test_add_user( 7, array( 'gwc_vt_verify_hours' => true, 'edit_post' => true ) );

		$this->assertTrue( gwc_vt_user_can_verify( 7, 42 ) );
	}

	public function test_the_capability_alone_is_not_enough(): void {
		gwc_vt_test_add_user( 8, array( 'gwc_vt_verify_hours' => true ) );

		$this->assertFalse(
			gwc_vt_user_can_verify( 8, 42 ),
			'Trusted to attest in general is not the same as allowed near this record.'
		);
	}

	public function test_editing_posts_alone_is_not_enough(): void {
		gwc_vt_test_add_user( 9, array( 'edit_posts' => true, 'edit_post' => true ) );

		$this->assertFalse(
			gwc_vt_user_can_verify( 9, 42 ),
			'Being able to edit an entry must not imply being able to attest to it.'
		);
	}

	public function test_a_logged_out_visitor_can_verify_nothing(): void {
		$this->assertFalse( gwc_vt_user_can_verify( 0, 42 ) );
		$this->assertFalse( gwc_vt_user_can_verify( 7, 0 ) );
	}

	public function test_issuing_a_letter_is_gated_separately(): void {
		gwc_vt_test_add_user( 10, array( 'gwc_vt_verify_hours' => true, 'edit_post' => true ) );

		$this->assertTrue( gwc_vt_user_can_verify( 10, 42 ) );
		$this->assertFalse(
			gwc_vt_user_can_issue( 10 ),
			'A shift coordinator who can attest must not thereby be able to send letters.'
		);
	}
}

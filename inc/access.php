<?php
/**
 * Who may do what.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Two capabilities, and no new role ───────────────────────────────────────
 * The post portal in this family invents a role, because it provisions accounts
 * for people who have to sign in. This plugin provisions nobody. The volunteer
 * never logs in — that is the entire point of the anonymous self-log form — so
 * a role here would be a role with no members, copied because the sibling had
 * one.
 *
 * What it needs instead is two capabilities on the accounts that already exist:
 *
 *   gwcvt_verify_hours    attest that a shift happened
 *   gwcvt_issue_letters   put the organisation's name on a document
 *
 * They are deliberately separate. At a small nonprofit the coordinator who
 * knows Jane swept the warehouse on Saturday and the director who signs a
 * letter to a probation officer are frequently different people, and the letter
 * is the higher-trust action. Collapsing them into one capability would mean
 * every shift coordinator can issue letters, which is not what anybody would
 * choose if asked.
 *
 * Creating or editing an entry needs no custom capability at all: the post types
 * use capability_type 'post' with map_meta_cap, so edit_posts and edit_post do
 * the work, and a site's existing editorial roles already mean something
 * sensible. Only the two genuinely new decisions get new capabilities.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCVT_CAP_VERIFY = 'gwcvt_verify_hours';
const GWCVT_CAP_ISSUE  = 'gwcvt_issue_letters';

/* The roles that get both capabilities when the plugin cannot ask. Editor is
 * included because at the scale this plugin is built for — a food bank with one
 * administrator and three staff logins — an editor IS the volunteer
 * coordinator, and a plugin that granted nothing to anybody but the site owner
 * would be configured by handing out administrator accounts, which is worse
 * than anything this list could get wrong. */
const GWCVT_DEFAULT_CAP_ROLES = array( 'administrator', 'editor' );

add_action( 'init', 'gwcvt_grant_capabilities', 5 );

/**
 * The capability that gates each kind of action.
 *
 * Every call site reads through here rather than naming a capability inline, so
 * a site running a membership or user-role plugin can remap all three in one
 * filter and cannot end up with the screen that renders a button disagreeing
 * with the handler that acts on it.
 *
 * @param string $which One of 'verify', 'issue', 'manage'.
 * @return string A capability name.
 */
function gwcvt_cap( string $which ): string {
	$caps = array(
		'verify' => GWCVT_CAP_VERIFY,
		'issue'  => GWCVT_CAP_ISSUE,
		'manage' => 'manage_options',
	);

	/**
	 * The capabilities gating verification, letter issuance and settings.
	 *
	 * @param array<string, string> $caps Keyed by 'verify', 'issue', 'manage'.
	 */
	$caps = (array) apply_filters( 'gwcvt_capabilities', $caps );

	/* Falling back to manage_options rather than to the requested key means a
	 * filter that drops a key fails closed. A missing capability name would
	 * otherwise reach current_user_can() as '' and, since no role holds '',
	 * would also deny — but only by accident, and only until somebody's
	 * capability plugin started returning true for unknown strings. */
	return isset( $caps[ $which ] ) && is_string( $caps[ $which ] ) && '' !== $caps[ $which ]
		? $caps[ $which ]
		: 'manage_options';
}

/**
 * Give the two capabilities to the roles that should have them.
 *
 * On `init` rather than on activation, and idempotent, for the reason set out
 * in the main plugin file: an activation hook runs once, and a site that loses
 * these to a security plugin rebuilding roles, a migration, or a restore from a
 * backup taken before install has no way to get them back short of
 * deactivate/reactivate. Two get_role() calls per request is a fair price for
 * never having to explain that to somebody.
 *
 * Nothing here ever removes a capability, and the check is isset() rather than
 * has_cap() — which is the whole of how a deliberate revocation is told apart
 * from a loss.
 *
 * A role's capabilities array holds `'cap' => false` for a capability that was
 * explicitly taken away, and holds no key at all for one that was never
 * granted. Capability-manager plugins — Members, User Role Editor — write the
 * false. So isset() is true for "an administrator decided no", and this leaves
 * it alone; isset() is false for "this role has never heard of it", and this
 * grants.
 *
 * The case that cannot be distinguished is WP_Role::remove_cap(), which unsets
 * the key entirely and is therefore indistinguishable from never having had it.
 * That one is restored on the next init. It is the right way round: the failure
 * of restoring is that somebody re-revokes through their capability plugin,
 * where the false sticks; the failure of not restoring is a site whose staff
 * silently cannot verify hours after a migration, with nothing in the interface
 * to explain why.
 */
function gwcvt_grant_capabilities(): void {
	/**
	 * The roles granted the plugin's capabilities when they are first seen.
	 *
	 * @param string[] $roles Role slugs.
	 */
	$roles = (array) apply_filters( 'gwcvt_default_cap_roles', GWCVT_DEFAULT_CAP_ROLES );

	foreach ( $roles as $role_name ) {
		$role = get_role( (string) $role_name );
		if ( ! $role ) {
			continue;
		}

		foreach ( array( GWCVT_CAP_VERIFY, GWCVT_CAP_ISSUE ) as $cap ) {
			/* has_cap() is checked rather than blindly re-adding, because
			 * add_cap() writes the whole role back to the options table every
			 * time it is called. Doing that twice per request on every page load
			 * of every admin screen is a write nobody asked for. */
			if ( ! isset( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}

/**
 * May this user attest to this entry?
 *
 * The single place that answers the question. Every handler, every button, and
 * every list-table row action routes through here — the same rule the post
 * portal states for its own authorization check, and for the same reason: an
 * authorization answer that lives in more than one place is one that will
 * eventually disagree with itself.
 *
 * Two conditions, both required. The capability says this person is trusted to
 * attest at all; edit_post says they are allowed near this particular record,
 * which is what makes the plugin behave sensibly on a site that has partitioned
 * its content with a roles plugin.
 *
 * @param int $user_id  User ID.
 * @param int $entry_id Hour entry post ID.
 * @return bool
 */
function gwcvt_user_can_verify( int $user_id, int $entry_id ): bool {
	if ( $user_id < 1 || $entry_id < 1 ) {
		return false;
	}

	if ( ! user_can( $user_id, gwcvt_cap( 'verify' ) ) ) {
		return false;
	}

	return user_can( $user_id, 'edit_post', $entry_id );
}

/**
 * May this user put the organisation's name on a letter?
 *
 * @param int $user_id User ID.
 * @return bool
 */
function gwcvt_user_can_issue( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, gwcvt_cap( 'issue' ) );
}

/**
 * Stop the request unless the current user holds a capability.
 *
 * wp_die() rather than a return value, deliberately. A handler that returns
 * early on a failed check relies on every caller remembering to check what it
 * returned, and the failure mode of forgetting is that the action goes ahead.
 * Dying here means the only way to reach the code below the call is to have
 * passed it.
 *
 * @param string $which One of 'verify', 'issue', 'manage'.
 */
function gwcvt_require_cap( string $which ): void {
	if ( current_user_can( gwcvt_cap( $which ) ) ) {
		return;
	}

	wp_die(
		esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
		array( 'response' => 403 )
	);
}

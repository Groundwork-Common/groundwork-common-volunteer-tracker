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
 *   gwc_vt_verify_hours    attest that a shift happened
 *   gwc_vt_issue_letters   put the organization's name on a document
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

const GWC_VT_CAP_VERIFY = 'gwc_vt_verify_hours';
const GWC_VT_CAP_ISSUE  = 'gwc_vt_issue_letters';

/* ── Seeing records about other people ───────────────────────────────────────
 * A third gate, and NOT a third capability — it maps onto one WordPress already
 * has, for a reason worth writing down.
 *
 * The comment above says the post types use capability_type 'post' so that "a
 * site's existing editorial roles already mean something sensible", and below
 * the plural that is exactly true — edit_post, delete_post and the ownership
 * rules map as they do for a post.
 *
 * It has never been true of who reaches a SCREEN. Every custom screen here
 * queries for itself — the schedule drawer, the offers queue, the verify queue,
 * the REST routes behind them — and each was gated on edit_posts, which a
 * CONTRIBUTOR has. So a role WordPress designed for "may draft a post, may not
 * see anybody else's" could read volunteer names off a roster, and read a
 * stranger's name, email, phone, photograph and the court that ordered their
 * service off the offers queue.
 *
 * And it was not true of the two LIST TABLES either, which this plugin believed
 * in writing in four places for months. See gwc_vt_records_post_type_caps().
 *
 * edit_others_posts is the line WordPress already draws for "may see other
 * people's records", and it is held by editor and administrator — which is
 * exactly GWC_VT_DEFAULT_CAP_ROLES below. The plugin's own idea of who runs it
 * and WordPress's own idea of who may see other people's work turn out to be
 * the same two roles, so no new capability is needed and no site has to be
 * migrated onto one.
 *
 * Author is excluded deliberately. An author may publish their own posts and
 * still has no business reading a list of people working off a court order.
 *
 * A site whose roles genuinely differ can remap the whole gate through the
 * gwc_vt_records_cap filter, which the menu and the screens both read.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The capability that means "may see records about other people".
 *
 * The string, not the answer, because add_submenu_page() takes a capability
 * name and current_user_can() takes one too — deriving the boolean from this
 * rather than filtering both separately is what stops a site's override
 * applying to the screens and not the menu that reaches them.
 *
 * @return string
 */
function gwc_vt_records_cap(): string {
	/**
	 * The capability gating every screen that shows somebody else's record.
	 *
	 * For a site whose roles do not follow WordPress's own shape — a custom
	 * "coordinator" role, say, built without edit_others_posts.
	 *
	 * @param string $capability Defaults to edit_others_posts.
	 */
	$capability = (string) apply_filters( 'gwc_vt_records_cap', 'edit_others_posts' );

	/* Falling back rather than trusting an empty string, for the reason
	 * gwc_vt_cap() gives: a filter that returns nothing must not open a door. */
	return '' !== trim( $capability ) ? $capability : 'edit_others_posts';
}

/**
 * Whether this user may see the plugin's records at all.
 *
 * One definition, used by every screen and every REST route that shows or acts
 * on somebody else's record — so raising or lowering the bar is one edit rather
 * than a sweep somebody has to get complete.
 *
 * @param int $user_id User to test, or 0 for the current one.
 * @return bool
 */
function gwc_vt_can_see_records( int $user_id = 0 ): bool {
	return $user_id > 0
		? user_can( $user_id, gwc_vt_records_cap() )
		: current_user_can( gwc_vt_records_cap() );
}

/**
 * The capability overrides that put a list table behind the same gate.
 *
 * ── What WordPress does not do ───────────────────────────────────────────────
 * This plugin said, in inc/access.php, in CLAUDE.md, in README.md and in
 * tests/integration/caps.php, that WordPress adds an author restriction to a
 * list table for anybody without edit_others_posts — so that a contributor
 * opening Volunteers saw zero rows and the two screens with a list table needed
 * nothing from us.
 *
 * It does not. wp_edit_posts_query() narrows by permission in exactly one
 * place: it sets $perm = 'readable', and only when a post_status is present in
 * the query string. The default view passes none, so there is no restriction at
 * all — and 'readable' would not restrict a published post even if there were.
 * The same site shows a contributor the administrator's own posts in the
 * ordinary Posts list, which is the quickest way to see that this is core's
 * behaviour rather than anything here.
 *
 * What that cost: a contributor could read six volunteers' names, their verified
 * hours and their court-ordered totals off one screen, and twenty hour entries —
 * names, dates, activities, attestations — off the other, while every custom
 * screen in the plugin correctly refused them. Issue #213.
 *
 * ── Why an override and not a pre_get_posts ──────────────────────────────────
 * Because hiding the rows is not the same as closing the screen, and this is a
 * screen that should be closed. A query filter would leave the views strip
 * counting what it will not show — "All (6)" over an empty table — and would be
 * a second mechanism beside gwc_vt_records_cap(), which is the one thing this
 * file exists to prevent.
 *
 * ── Why create_posts is here too ─────────────────────────────────────────────
 * get_post_type_capabilities() derives create_posts from the DEFAULT plural
 * rather than from an override, so setting edit_posts alone leaves post-new.php
 * open. Both, or neither.
 *
 * Read at registration, which is `init` — so a site using the gwc_vt_records_cap
 * filter has to add it before then, rather than on admin_menu as the screens
 * would allow.
 *
 * @return array<string, string> register_post_type() capability overrides.
 */
function gwc_vt_records_post_type_caps(): array {
	$capability = gwc_vt_records_cap();

	return array(
		'edit_posts'   => $capability,
		'create_posts' => $capability,
	);
}

/* The roles that get both capabilities when the plugin cannot ask. Editor is
 * included because at the scale this plugin is built for — a food bank with one
 * administrator and three staff logins — an editor IS the volunteer
 * coordinator, and a plugin that granted nothing to anybody but the site owner
 * would be configured by handing out administrator accounts, which is worse
 * than anything this list could get wrong. */
const GWC_VT_DEFAULT_CAP_ROLES = array( 'administrator', 'editor' );

add_action( 'init', 'gwc_vt_grant_capabilities', 5 );
add_filter( 'map_meta_cap', 'gwc_vt_map_open_letters', 10, 3 );

/* ── Reaching the Letters screen ─────────────────────────────────────────────
 * The screen does two things, and they are not the same size. Producing a letter
 * puts the organization's name on a document a court reads, and stays behind
 * gwc_vt_issue_letters. Checking a reference somebody has phoned in about is a
 * ten-second lookup that answers "does this still match our records" and
 * discloses nothing the caller is not holding already.
 *
 * Both used to sit behind the higher one, which meant the front-desk answer to a
 * probation officer's phone call required the right to sign letters — so either
 * nobody could answer the phone, or everybody could sign.
 *
 * A meta capability rather than a second menu item: add_submenu_page() takes one
 * capability, and the screen has to appear for somebody who holds EITHER. This
 * is the mechanism WordPress provides for exactly that, and it keeps one screen
 * with two halves rather than two screens that would drift.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWC_VT_CAP_OPEN_LETTERS = 'gwc_vt_open_letters';

/**
 * Map the Letters screen's meta capability.
 *
 * @param string[] $caps    Primitive capabilities required.
 * @param string   $cap     The capability being checked.
 * @param int      $user_id Who is being checked.
 * @return string[]
 */
function gwc_vt_map_open_letters( $caps, $cap, $user_id ): array {
	if ( GWC_VT_CAP_OPEN_LETTERS !== $cap ) {
		return (array) $caps;
	}

	/* Both are primitive capabilities, so neither re-enters this filter. */
	$allowed = user_can( (int) $user_id, gwc_vt_cap( 'issue' ) )
		|| user_can( (int) $user_id, gwc_vt_cap( 'verify' ) );

	return array( $allowed ? 'exist' : 'do_not_allow' );
}

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
function gwc_vt_cap( string $which ): string {
	$caps = array(
		'verify' => GWC_VT_CAP_VERIFY,
		'issue'  => GWC_VT_CAP_ISSUE,
		'manage' => 'manage_options',
	);

	/**
	 * The capabilities gating verification, letter issuance and settings.
	 *
	 * @param array<string, string> $caps Keyed by 'verify', 'issue', 'manage'.
	 */
	$caps = (array) apply_filters( 'gwc_vt_capabilities', $caps );

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
function gwc_vt_grant_capabilities(): void {
	/**
	 * The roles granted the plugin's capabilities when they are first seen.
	 *
	 * @param string[] $roles Role slugs.
	 */
	$roles = (array) apply_filters( 'gwc_vt_default_cap_roles', GWC_VT_DEFAULT_CAP_ROLES );

	foreach ( $roles as $role_name ) {
		$role = get_role( (string) $role_name );
		if ( ! $role ) {
			continue;
		}

		foreach ( array( GWC_VT_CAP_VERIFY, GWC_VT_CAP_ISSUE ) as $cap ) {
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
function gwc_vt_user_can_verify( int $user_id, int $entry_id ): bool {
	if ( $user_id < 1 || $entry_id < 1 ) {
		return false;
	}

	if ( ! user_can( $user_id, gwc_vt_cap( 'verify' ) ) ) {
		return false;
	}

	return user_can( $user_id, 'edit_post', $entry_id );
}

/**
 * May this user put the organization's name on a letter?
 *
 * @param int $user_id User ID.
 * @return bool
 */
function gwc_vt_user_can_issue( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, gwc_vt_cap( 'issue' ) );
}

/**
 * The headers a response carrying somebody's personal data goes out with.
 *
 * Four responses need these: the shift roster sheet, the event roster sheet,
 * the printed letter and a signup's calendar file. All four set them by hand,
 * and they had drifted — the letter's copy gained max-age=0 and a replace
 * argument on every header() call, and the two roster sheets did not. So the
 * hardening reached the response whose own comment calls it "the response in
 * this plugin with the most personal data in it" and left behind the two
 * carrying names, email addresses and phone numbers. The calendar file had no
 * explicit Cache-Control at all.
 *
 * The replace argument matters more than it looks. Without it a header() call
 * ADDS a field beside any already sent, so a theme, a caching plugin or a
 * security plugin that has emitted its own Cache-Control leaves two on the
 * response and lets the intermediary choose. With it, this one wins.
 *
 * Here rather than in the admin bundle because inc/ics.php answers a front-end
 * request. Nothing in this plugin is wrapped in is_admin(), so the admin files
 * do load — but a front-end response depending on a file named admin-*.php is
 * the kind of thing that is true until somebody makes it false.
 *
 * @param string $content_type The response's own Content-Type.
 */
function gwc_vt_private_document_headers( string $content_type = 'text/html; charset=utf-8' ): void {
	nocache_headers();
	header( 'Content-Type: ' . $content_type, true );
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	header( 'Referrer-Policy: no-referrer', true );
}

/**
 * Stop the request unless the current user holds a capability.
 *
 * Deliberately wp_die() rather than a return value. A handler that returns
 * early on a failed check relies on every caller remembering to check what it
 * returned, and the failure mode of forgetting is that the action goes ahead.
 * Dying here means the only way to reach the code below the call is to have
 * passed it.
 *
 * @param string $which One of 'verify', 'issue', 'manage'.
 */
function gwc_vt_require_cap( string $which ): void {
	if ( current_user_can( gwc_vt_cap( $which ) ) ) {
		return;
	}

	wp_die(
		esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
		array( 'response' => 403 )
	);
}

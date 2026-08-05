<?php
/**
 * Plugin Name:       Groundwork Common Volunteer Tracker
 * Plugin URI:        https://github.com/Groundwork-Common/groundwork-common-volunteer-tracker
 * Description:       Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept from the person who earned it. Built for the nonprofits who host mandated service and currently do this on paper.
 * Version:           0.7.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Groundwork Common LLC
 * Author URI:        https://groundworkcommon.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       groundwork-common-volunteer-tracker
 * Domain Path:       /languages
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── What this plugin will not claim ─────────────────────────────────────────
 * Every other plugin in this family is careful about what it knows. This one
 * has to be careful about what it SAYS, because its output is a document
 * somebody hands to a probation officer or a school registrar, and the whole
 * value of that document is that it is believed.
 *
 * The temptation is obvious and must be refused at every turn. A seal. The word
 * "certified". A rendered signature. Language borrowed from an affidavit. All
 * of it is a few minutes of work, all of it makes the letter look more
 * official, and all of it is the plugin asserting an authority it does not have
 * and cannot have — nobody at Groundwork Common watched anybody sweep a
 * warehouse floor.
 *
 * So the rule the whole letter subsystem is built around: the plugin reports
 * what the organisation recorded, says plainly that the ORGANISATION is the
 * authoritative record-keeper, timestamps it, and stops. What makes the letter
 * credible is not decoration — it is that the hours are itemised, that each one
 * names the staff member who attested to it and when, and that the reference
 * code on it can be read back to a caller who phones the org to check. Those
 * are checkable facts. A seal is a picture of one.
 *
 * The corollary, and the reason it is worth writing down here rather than in
 * one file's header: the disclaimer is not a setting that can be emptied, and
 * the signature block is a ruled line for a human to sign rather than an image
 * of a signature. Both have been asked for. Both are no.
 *
 * The second constraint is the data. Names, dates, and — for the mandated case —
 * the fact that a named person is working off a court order. That is the most
 * sensitive thing any plugin in this family stores. Nothing here is exposed on
 * the REST API, nothing is public, nothing is searchable, and the one lookup
 * endpoint that exists returns display names to staff who already hold
 * edit_posts and nothing else. Retention is a decision the site makes, not a
 * default we pick for them.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCVT_VERSION = '0.7.0';

/* Deliberately not derived from GWCVT_VERSION, and SchemaTest asserts they can
 * move independently. The stored field schema changes when the shape of a field
 * definition changes, which is rarely, and coupling it to the release number
 * would mean either a pointless migration on every patch or a version number
 * that lies about what it describes. */
const GWCVT_SCHEMA_VERSION = 1;

/*
 * Where "Support this work" points. Every reference is guarded, so setting this
 * to '' removes the link and the paragraph asking for one together — a support
 * ask with nowhere to go is worse than none.
 */
const GWCVT_SPONSOR_URL = 'https://www.groundworkcommon.com/support/';

/* The company site. Named once because the colophon links it from three
 * places — the wordmark, the company name in the opening line, and the
 * "See what we do" link — and two of those agreeing while the third drifts
 * is the kind of thing nobody notices for a year. */
const GWCVT_GWC_URL = 'https://www.groundworkcommon.com/';

define( 'GWCVT_FILE', __FILE__ );
define( 'GWCVT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWCVT_URL', plugin_dir_url( __FILE__ ) );

/* ── Guarded requires ────────────────────────────────────────────────────────
 * Each guard names a function the file declares. This costs one function_exists
 * per file and buys immunity to the double-load that happens when a plugin is
 * activated while an older copy is still on the include path — during the
 * activation request WordPress has already loaded the old file, and a bare
 * require then fatals on redeclaration before the admin can do anything about
 * it.
 *
 * The order is not alphabetical and is not free to change. settings.php holds
 * the hour parser everything else measures with; access.php declares the
 * capabilities every handler gates on.
 *
 * Nothing here is wrapped in is_admin(). It is tempting — most of this plugin
 * is admin-only — but is_admin() answers "is this a wp-admin request", and
 * WP-CLI, cron and the REST API are none of those. The retention sweep is cron,
 * the volunteer lookup is REST, and the self-log form is a front-end POST. Each
 * of these files only *registers* hooks that fire in their own contexts anyway,
 * so the saving is a few microseconds of include, and the cost is a class of bug
 * where a function exists on the screen you tested and is fatally undefined
 * under `wp eval`. That trade is not close.
 * ─────────────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'gwcvt_verification_labels' ) ) {
	require GWCVT_DIR . 'inc/i18n.php';
}
if ( ! function_exists( 'gwcvt_setting' ) ) {
	require GWCVT_DIR . 'inc/settings.php';
}
if ( ! function_exists( 'gwcvt_cap' ) ) {
	require GWCVT_DIR . 'inc/access.php';
}

/* The value objects, guarded on the class rather than a function. They depend
 * on nothing and are constructed by the query layer below, so they come first.
 *
 * These are the family's one architectural departure and the rule is narrow:
 * objects for values that are COMPUTED, arrays for anything PERSISTED. The
 * field schema and the settings stay arrays, exactly as in the sibling plugins,
 * because they are serialised into an option and wrapping them would mean
 * hydrating and dehydrating on every read for no safety the defaults-merge does
 * not already provide. Totals and the letter model are computed, flow through
 * four call sites each, and are the two structures whose correctness is the
 * product. See the box comment in inc/class-gwcvt-totals.php. */
if ( ! class_exists( 'GWCVT_Totals' ) ) {
	require GWCVT_DIR . 'inc/class-gwcvt-totals.php';
}
if ( ! class_exists( 'GWCVT_Letter_Entry' ) ) {
	require GWCVT_DIR . 'inc/class-gwcvt-letter-entry.php';
}
if ( ! class_exists( 'GWCVT_Letter' ) ) {
	require GWCVT_DIR . 'inc/class-gwcvt-letter.php';
}

/* The post types, then the query layer that reads them. cpt.php declares the
 * meta key constants entries.php totals with, so it cannot move after it. */
if ( ! function_exists( 'gwcvt_register_post_type' ) ) {
	require GWCVT_DIR . 'inc/cpt.php';
}
if ( ! function_exists( 'gwcvt_register_volunteer_type' ) ) {
	require GWCVT_DIR . 'inc/volunteer-cpt.php';
}
if ( ! function_exists( 'gwcvt_entry_ids_for_volunteer' ) ) {
	require GWCVT_DIR . 'inc/entries.php';
}
if ( ! function_exists( 'gwcvt_verify_entry' ) ) {
	require GWCVT_DIR . 'inc/verify.php';
}
if ( ! function_exists( 'gwcvt_register_letter_type' ) ) {
	require GWCVT_DIR . 'inc/letter-cpt.php';
}

/* The letter. letter.php assembles the model and mints the reference,
 * render.php turns one into a document, emails.php sends it. None of them
 * knows about the admin screen that calls them. */
if ( ! function_exists( 'gwcvt_build_letter' ) ) {
	require GWCVT_DIR . 'inc/letter.php';
}
if ( ! function_exists( 'gwcvt_render_letter' ) ) {
	require GWCVT_DIR . 'inc/render.php';
}
if ( ! function_exists( 'gwcvt_send_email' ) ) {
	require GWCVT_DIR . 'inc/emails.php';
}
if ( ! function_exists( 'gwcvt_retention_due' ) ) {
	require GWCVT_DIR . 'inc/privacy.php';
}
if ( ! function_exists( 'gwcvt_register_rest_routes' ) ) {
	require GWCVT_DIR . 'inc/rest.php';
}
/* The public form. form.php renders it, self-log.php accepts it, block.php
 * places it. self-log.php declares gwcvt_self_log_enabled(), which form.php
 * calls, so it cannot move after it. */
if ( ! function_exists( 'gwcvt_dispatch' ) ) {
	require GWCVT_DIR . 'inc/self-log.php';
}
if ( ! function_exists( 'gwcvt_render_self_log_form' ) ) {
	require GWCVT_DIR . 'inc/form.php';
}
if ( ! function_exists( 'gwcvt_render_entry_meta_box' ) ) {
	require GWCVT_DIR . 'inc/meta-box.php';
}
if ( ! function_exists( 'gwcvt_register_front_assets' ) ) {
	require GWCVT_DIR . 'inc/enqueue.php';
}
if ( ! function_exists( 'gwcvt_register_block' ) ) {
	require GWCVT_DIR . 'inc/block.php';
}

/* The settings screen. Last, as in both sibling plugins, because it describes
 * the others.
 *
 * enqueue.php above calls gwcvt_is_plugin_screen(), which this file declares —
 * which looks like the wrong order and is not. Every one of these files only
 * REGISTERS hooks at include time; the call happens on admin_enqueue_scripts,
 * by which point the whole plugin is loaded. Moving this earlier to make the
 * reading order match the call order would put the admin screen ahead of the
 * post types it hangs its menu off, which is a dependency that IS load-time. */
if ( ! function_exists( 'gwcvt_colophon_snoozed' ) ) {
	require GWCVT_DIR . 'inc/admin-screen.php';

	// Verification where staff look for it: the hours list, and the entry itself.
	require GWCVT_DIR . 'inc/admin-verify.php';

	// The settings tabs' forms, and the one handler that saves them.
	require GWCVT_DIR . 'inc/admin-settings.php';

	// Producing, sending and checking letters.
	require GWCVT_DIR . 'inc/admin-letters.php';

	// A volunteer's own history: their shifts, and the letters issued for them.
	require GWCVT_DIR . 'inc/admin-volunteer.php';

	// Turning a public submission into a shift on somebody's record.
	require GWCVT_DIR . 'inc/admin-triage.php';

	// Typing up a sign-in sheet in one pass.
	require GWCVT_DIR . 'inc/admin-quick-add.php';
}

/* ── Activation ──────────────────────────────────────────────────────────────
 * Deliberately not flush_rewrite_rules(). On the activating request the post
 * types have not been registered yet — `init` already fired — so a flush here
 * writes rules that do not include ours. Leave a flag, consume it on the next
 * `init` after registration.
 *
 * The capabilities are granted on `init` rather than here, on purpose. An
 * activation hook runs once, and a site that loses them — a migration, a
 * security plugin that rebuilds roles, a restore from a backup taken before
 * install — would have no way to get them back short of deactivate/reactivate.
 * Granting them idempotently on every init costs two get_role() calls and
 * cannot drift.
 *
 * The option is explicitly non-autoloaded: it is read once, ever.
 * ─────────────────────────────────────────────────────────────────────────── */
register_activation_hook(
	__FILE__,
	static function (): void {
		update_option( 'gwcvt_needs_rewrite_flush', 1, false );
	}
);

add_action(
	'init',
	static function (): void {
		if ( get_option( 'gwcvt_needs_rewrite_flush' ) ) {
			delete_option( 'gwcvt_needs_rewrite_flush' );
			flush_rewrite_rules( false );
		}
	},
	99
);

/* Deactivation drops the rewrite rules and unschedules our cron, and does
 * nothing else. It does not remove the capabilities and it does not touch a
 * single hour record — deactivating is not uninstalling, and a plugin that
 * loses a volunteer's court-ordered service history when you toggle it off to
 * test something is a plugin nobody dares toggle. */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules( false );

		/* The retention sweep, which inc/privacy.php puts back on the next init
		 * if the plugin is reactivated. Left scheduled, it is a cron event
		 * pointing at a function that no longer exists — harmless in WordPress,
		 * which skips unknown hooks, and a permanent entry in every cron listing
		 * a site owner ever looks at. */
		foreach ( array( 'gwcvt_daily_retention' ) as $event ) {
			$next = wp_next_scheduled( $event );
			if ( $next ) {
				wp_unschedule_event( $next, $event );
			}
		}
	}
);

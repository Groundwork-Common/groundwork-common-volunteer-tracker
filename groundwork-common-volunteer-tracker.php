<?php
/**
 * Plugin Name:       Groundwork Common Volunteer Tracker
 * Plugin URI:        https://www.groundworkcommon.com/
 * Description:       Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept from the person who earned it. Built for the nonprofits who host mandated service and currently do this on paper.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Groundwork Common LLC
 * Author URI:        https://www.groundworkcommon.com/
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

const GWC_VT_VERSION = '1.0.0';

/* Deliberately not derived from GWC_VT_VERSION, and VersionTest asserts they can
 * move independently. The stored field schema changes when the shape of a field
 * definition changes, which is rarely, and coupling it to the release number
 * would mean either a pointless migration on every patch or a version number
 * that lies about what it describes.
 *
 * Nothing reads it yet. The stored field schema from the original plan was never
 * built, so this is a number with no migration runner behind it — see "Still to
 * come" in README.md, where whether that feature is still wanted is an open
 * question rather than a backlog item. */
const GWC_VT_SCHEMA_VERSION = 1;

/*
 * Where "Support this work" points. Every reference is guarded, so setting this
 * to '' removes the link and the paragraph asking for one together — a support
 * ask with nowhere to go is worse than none.
 */
const GWC_VT_SPONSOR_URL = 'https://www.groundworkcommon.com/support/';

/* Where "Report a problem" points, from the colophon and from every help tab.
 *
 * The support forum rather than the issue tracker, and not only because the
 * repository is private. A plugin hosted in the directory gets a forum whether
 * it wants one or not; it is linked from the plugin's own page, it is where
 * somebody who has never seen this repository will look first, and an answer
 * left there is read by the next person with the same problem. An issue tracker
 * is where the work is planned, which is a different audience — and pointing
 * installers at one they cannot open an account against is how a "Report a
 * problem" link quietly becomes a 404 nobody on this side ever clicks. */
const GWC_VT_SUPPORT_URL = 'https://wordpress.org/support/plugin/groundwork-common-volunteer-tracker/';

/* The company site. Named once because the colophon links it from three
 * places — the wordmark, the company name in the opening line, and the
 * "See what we do" link — and two of those agreeing while the third drifts
 * is the kind of thing nobody notices for a year. */
const GWC_VT_GWC_URL = 'https://www.groundworkcommon.com/';

define( 'GWC_VT_FILE', __FILE__ );
define( 'GWC_VT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWC_VT_URL', plugin_dir_url( __FILE__ ) );

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
if ( ! function_exists( 'gwc_vt_verification_labels' ) ) {
	require GWC_VT_DIR . 'inc/i18n.php';
}
if ( ! function_exists( 'gwc_vt_setting' ) ) {
	require GWC_VT_DIR . 'inc/settings.php';
}
if ( ! function_exists( 'gwc_vt_cap' ) ) {
	require GWC_VT_DIR . 'inc/access.php';
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
 * product. See the box comment in inc/class-gwc-vt-totals.php. */
if ( ! class_exists( 'GWC_VT_Totals' ) ) {
	require GWC_VT_DIR . 'inc/class-gwc-vt-totals.php';
}
if ( ! class_exists( 'GWC_VT_Letter_Entry' ) ) {
	require GWC_VT_DIR . 'inc/class-gwc-vt-letter-entry.php';
}
if ( ! class_exists( 'GWC_VT_Letter' ) ) {
	require GWC_VT_DIR . 'inc/class-gwc-vt-letter.php';
}

/* The post types, then the query layer that reads them. cpt.php declares the
 * meta key constants entries.php totals with, so it cannot move after it. */
if ( ! function_exists( 'gwc_vt_register_post_type' ) ) {
	require GWC_VT_DIR . 'inc/cpt.php';
}
if ( ! function_exists( 'gwc_vt_register_volunteer_type' ) ) {
	require GWC_VT_DIR . 'inc/volunteer-cpt.php';
}
if ( ! function_exists( 'gwc_vt_entry_ids_for_volunteer' ) ) {
	require GWC_VT_DIR . 'inc/entries.php';
}

/* What a mandated volunteer still has to complete. After entries.php, because
 * progress is measured against the cached rollup that file maintains. */
if ( ! function_exists( 'gwc_vt_requirement_progress' ) ) {
	require GWC_VT_DIR . 'inc/required.php';
}

/* The schedule. recurrence.php is pure calendar arithmetic and depends on
 * nothing, and shifts.php calls it to validate a date, so it goes first. The two
 * post types declare the meta key constants the query layer reads, so they
 * cannot move after it — the same ordering rule as cpt.php and entries.php
 * above. signups.php reads a shift's capacity, so it comes last of the four.
 *
 * All four load whether or not scheduling is switched on. The setting governs
 * whether the SCREENS appear, not whether the functions exist: a site that turns
 * scheduling off still has shifts in the database, and the privacy exporter, the
 * retention sweep and WP-CLI all have to be able to read them. */
if ( ! function_exists( 'gwc_vt_recurrence_dates' ) ) {
	require GWC_VT_DIR . 'inc/recurrence.php';
}
if ( ! function_exists( 'gwc_vt_register_shift_type' ) ) {
	require GWC_VT_DIR . 'inc/shift-cpt.php';
}
/* The event type, beside the shift type it contains. Its meta key constants are
 * read by events.php below, so it cannot move after it — the same ordering rule
 * as shift-cpt.php and shifts.php. */
if ( ! function_exists( 'gwc_vt_register_event_type' ) ) {
	require GWC_VT_DIR . 'inc/event-cpt.php';
}
if ( ! function_exists( 'gwc_vt_register_signup_type' ) ) {
	require GWC_VT_DIR . 'inc/signup-cpt.php';
}
if ( ! function_exists( 'gwc_vt_shift_duration' ) ) {
	require GWC_VT_DIR . 'inc/shifts.php';
}
/* Reading events. After shifts.php, because every question about an event is a
 * question about its slots and this file answers them by calling that one. */
if ( ! function_exists( 'gwc_vt_event_slot_ids' ) ) {
	require GWC_VT_DIR . 'inc/events.php';
}
if ( ! function_exists( 'gwc_vt_add_signup' ) ) {
	require GWC_VT_DIR . 'inc/signups.php';
}

/* The public signup surface, in the same order as the hours form's below:
 * handler first, because it declares gwc_vt_signups_open() and
 * gwc_vt_public_shift_ids(), which the form and the calendar file both call.
 * schedule-emails.php is last of the three — the handler queues a confirmation
 * through it, but only at request time. */
if ( ! function_exists( 'gwc_vt_signup_dispatch' ) ) {
	require GWC_VT_DIR . 'inc/signup-handler.php';
}
if ( ! function_exists( 'gwc_vt_render_shift_list' ) ) {
	require GWC_VT_DIR . 'inc/signup-form.php';
}
/* The event grid, beside the shift list it shares its rules with. After
 * signup-form.php, because it reuses gwc_vt_render_signup_manage() to answer a
 * cancellation link that happens to land on an event's page. */
if ( ! function_exists( 'gwc_vt_render_event_grid' ) ) {
	require GWC_VT_DIR . 'inc/event-form.php';
}
if ( ! function_exists( 'gwc_vt_signup_ics' ) ) {
	require GWC_VT_DIR . 'inc/ics.php';
}
if ( ! function_exists( 'gwc_vt_send_signup_confirmation' ) ) {
	require GWC_VT_DIR . 'inc/schedule-emails.php';
}
/* The two scheduled passes. After schedule-emails.php, which holds every message
 * they send, and after admin-schedule.php would be too late — this registers
 * cron hooks that fire on requests with no admin loaded at all. */
if ( ! function_exists( 'gwc_vt_run_reminders' ) ) {
	require GWC_VT_DIR . 'inc/schedule-cron.php';
}

/* What the dashboard reads. After the schedule and the requirements, because it
 * counts both; not in the admin bundle, because the year figures are cleared
 * from entry hooks that fire under WP-CLI and cron as well. */
if ( ! function_exists( 'gwc_vt_dashboard_items' ) ) {
	require GWC_VT_DIR . 'inc/dashboard.php';
}
if ( ! function_exists( 'gwc_vt_verify_entry' ) ) {
	require GWC_VT_DIR . 'inc/verify.php';
}
if ( ! function_exists( 'gwc_vt_register_letter_type' ) ) {
	require GWC_VT_DIR . 'inc/letter-cpt.php';
}

/* The letter. letter.php assembles the model and mints the reference,
 * render.php turns one into a document, emails.php sends it. None of them
 * knows about the admin screen that calls them. */
if ( ! function_exists( 'gwc_vt_build_letter' ) ) {
	require GWC_VT_DIR . 'inc/letter.php';
}
if ( ! function_exists( 'gwc_vt_render_letter' ) ) {
	require GWC_VT_DIR . 'inc/render.php';
}
if ( ! function_exists( 'gwc_vt_send_email' ) ) {
	require GWC_VT_DIR . 'inc/emails.php';
}
if ( ! function_exists( 'gwc_vt_retention_due' ) ) {
	require GWC_VT_DIR . 'inc/privacy.php';
}
if ( ! function_exists( 'gwc_vt_register_rest_routes' ) ) {
	require GWC_VT_DIR . 'inc/rest.php';
}
/* The public form. form.php renders it, self-log.php accepts it, block.php
 * places it. self-log.php declares gwc_vt_self_log_enabled(), which form.php
 * calls, so it cannot move after it. */
if ( ! function_exists( 'gwc_vt_dispatch' ) ) {
	require GWC_VT_DIR . 'inc/self-log.php';
}
if ( ! function_exists( 'gwc_vt_render_self_log_form' ) ) {
	require GWC_VT_DIR . 'inc/form.php';
}
if ( ! function_exists( 'gwc_vt_render_entry_meta_box' ) ) {
	require GWC_VT_DIR . 'inc/meta-box.php';
}
if ( ! function_exists( 'gwc_vt_register_front_assets' ) ) {
	require GWC_VT_DIR . 'inc/enqueue.php';
}
if ( ! function_exists( 'gwc_vt_register_block' ) ) {
	require GWC_VT_DIR . 'inc/block.php';
}

/* The settings screen. Last, as in both sibling plugins, because it describes
 * the others.
 *
 * enqueue.php above calls gwc_vt_is_plugin_screen(), which this file declares —
 * which looks like the wrong order and is not. Every one of these files only
 * REGISTERS hooks at include time; the call happens on admin_enqueue_scripts,
 * by which point the whole plugin is loaded. Moving this earlier to make the
 * reading order match the call order would put the admin screen ahead of the
 * post types it hangs its menu off, which is a dependency that IS load-time. */
if ( ! function_exists( 'gwc_vt_colophon_snoozed' ) ) {
	require GWC_VT_DIR . 'inc/admin-screen.php';

	// Verification where staff look for it: the hours list, and the entry itself.
	require GWC_VT_DIR . 'inc/admin-verify.php';

	// The settings tabs' forms, and the one handler that saves them.
	require GWC_VT_DIR . 'inc/admin-settings.php';

	// Producing, sending and checking letters.
	require GWC_VT_DIR . 'inc/admin-letters.php';

	// A volunteer's own history: their shifts, and the letters issued for them.
	require GWC_VT_DIR . 'inc/admin-volunteer.php';

	// Turning a public submission into a shift on somebody's record.
	require GWC_VT_DIR . 'inc/admin-triage.php';

	// Typing up a sign-in sheet in one pass.
	require GWC_VT_DIR . 'inc/admin-quick-add.php';

	/* Planning shifts, and who is coming to them. admin-schedule.php owns the
	 * menu and routes between the screen's views; admin-shift.php renders one
	 * shift and holds every handler that writes one.
	 *
	 * The two event files follow the same split — admin-event.php is the editor
	 * and the handlers that write an event's grid, admin-event-roster.php is who
	 * is coming and the sheet that goes on the clipboard. They load after
	 * admin-shift.php because the roster's promote action falls back to
	 * gwc_vt_shift_redirect() for a standalone shift. */
	require GWC_VT_DIR . 'inc/admin-schedule.php';
	require GWC_VT_DIR . 'inc/admin-shift.php';
	require GWC_VT_DIR . 'inc/admin-event.php';
	require GWC_VT_DIR . 'inc/admin-event-actions.php';
	require GWC_VT_DIR . 'inc/admin-event-roster.php';

	// The screen somebody lands on.
	require GWC_VT_DIR . 'inc/admin-dashboard.php';

	/* Contextual help. Last, because it describes what all of the above do —
	 * which now includes the schedule and its rosters. */
	require GWC_VT_DIR . 'inc/admin-help.php';
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
		update_option( 'gwc_vt_needs_rewrite_flush', 1, false );
	}
);

add_action(
	'init',
	static function (): void {
		if ( get_option( 'gwc_vt_needs_rewrite_flush' ) ) {
			delete_option( 'gwc_vt_needs_rewrite_flush' );
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
		foreach ( array( 'gwc_vt_daily_retention', 'gwc_vt_shift_reminders', 'gwc_vt_coordinator_digest' ) as $event ) {
			$next = wp_next_scheduled( $event );
			if ( $next ) {
				wp_unschedule_event( $next, $event );
			}
		}
	}
);

<?php
/**
 * What deleting this plugin removes, and what it deliberately does not.
 *
 * @package VolunteerTracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/* ── Non-destructive by default ──────────────────────────────────────────────
 * Deleting a plugin from the Plugins screen is two clicks, and one of them is
 * often a mistake. This plugin's data is a nonprofit's record of who worked
 * which shift — for some of those people, the only proof that they completed
 * court-ordered service. Destroying that because somebody deactivated and
 * deleted while tidying up would be unrecoverable and entirely our fault.
 *
 * So uninstall removes NOTHING unless the site has explicitly armed it:
 *
 *   update_option( 'gwc_vt_allow_destructive_uninstall', true );
 *
 * And even armed, it deletes only this plugin's OPTIONS. Never posts, never
 * post meta. The hour entries, the volunteer records and the issued-letter log
 * all survive, because a plugin's settings are the plugin's and the records are
 * the organization's.
 *
 * The capabilities survive too, armed or not. An orphaned custom capability is
 * one row in one option; a capability yanked out from under six staff accounts
 * is a support call nobody can diagnose.
 *
 * The cron event is unscheduled either way, because a scheduled event pointing
 * at a function that no longer exists is a permanent entry in every cron
 * listing the site owner ever looks at. WordPress skips unknown hooks, so it is
 * harmless — just untidy in a way that outlives us.
 * ─────────────────────────────────────────────────────────────────────────── */

/* One family of options is deliberately absent from the list below: the
 * per-shift signup locks, 'gwc_vt_signup_lock_<id>'. They are named after a post
 * ID, so they cannot be enumerated without a query, and they do not need to be —
 * each is released by the request that took it, stolen and reused if that
 * request died, and deleted outright when its shift is. A stray one is a single
 * non-autoloaded row that any later signup on that shift reclaims. Adding a
 * $wpdb LIKE sweep to catch it would be the plugin's only raw query, run at the
 * least testable moment in its life, to tidy something already self-tidying.
 *
 * 'gwc_vt_schema' used to be listed and is not any more. Nothing has ever written
 * it: the stored field schema described in the original plan was never built, so
 * the entry deleted an option no site has. It read as evidence that the feature
 * exists. If it is ever built, this is where its option goes back. */

/** Every option this plugin writes. */
const GWC_VT_UNINSTALL_OPTIONS = array(
	'gwc_vt_settings',
	'gwc_vt_retention_log',
	'gwc_vt_needs_rewrite_flush',
	'gwc_vt_event_page_gen',
	'gwc_vt_allow_destructive_uninstall',
);

/**
 * Clean up one site.
 */
function gwc_vt_uninstall_site(): void {
	foreach ( array( 'gwc_vt_daily_retention', 'gwc_vt_shift_reminders', 'gwc_vt_coordinator_digest' ) as $gwc_vt_event ) {
		$gwc_vt_next = wp_next_scheduled( $gwc_vt_event );

		if ( $gwc_vt_next ) {
			wp_unschedule_event( $gwc_vt_next, $gwc_vt_event );
		}
	}

	delete_transient( 'gwc_vt_unverified_count' );

	if ( ! get_option( 'gwc_vt_allow_destructive_uninstall' ) ) {
		return;
	}

	foreach ( GWC_VT_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	/* The one thing this plugin writes that is not an option and is not a
	 * record: whether each person dismissed the notice pointing at the guide.
	 * It is a preference, it goes with the settings, and it is deleted only
	 * under the same arming — a reinstall then offers the guide again, which is
	 * the right answer for what is, by then, a new install. */
	delete_metadata( 'user', 0, 'gwc_vt_welcome_dismissed', '', true );
}

if ( is_multisite() ) {
	/* Bounded rather than unbounded. A network with more sites than this has an
	 * administrator who deletes plugins with WP-CLI, and a query that tries to
	 * load every site on a large network is one that dies partway through and
	 * leaves half the job done with no record of which half. */
	$gwc_vt_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $gwc_vt_sites as $gwc_vt_site_id ) {
		switch_to_blog( (int) $gwc_vt_site_id );
		gwc_vt_uninstall_site();
		restore_current_blog();
	}
} else {
	gwc_vt_uninstall_site();
}

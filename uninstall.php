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
 *   update_option( 'gwcvt_allow_destructive_uninstall', true );
 *
 * And even armed, it deletes only this plugin's OPTIONS. Never posts, never
 * post meta. The hour entries, the volunteer records and the issued-letter log
 * all survive, because a plugin's settings are the plugin's and the records are
 * the organisation's.
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
 * per-shift signup locks, 'gwcvt_signup_lock_<id>'. They are named after a post
 * ID, so they cannot be enumerated without a query, and they do not need to be —
 * each is released by the request that took it, stolen and reused if that
 * request died, and deleted outright when its shift is. A stray one is a single
 * non-autoloaded row that any later signup on that shift reclaims. Adding a
 * $wpdb LIKE sweep to catch it would be the plugin's only raw query, run at the
 * least testable moment in its life, to tidy something already self-tidying. */

/** Every option this plugin writes. */
const GWCVT_UNINSTALL_OPTIONS = array(
	'gwcvt_settings',
	'gwcvt_schema',
	'gwcvt_retention_log',
	'gwcvt_needs_rewrite_flush',
	'gwcvt_allow_destructive_uninstall',
);

/**
 * Clean up one site.
 */
function gwcvt_uninstall_site(): void {
	foreach ( array( 'gwcvt_daily_retention', 'gwcvt_shift_reminders', 'gwcvt_coordinator_digest' ) as $gwcvt_event ) {
		$gwcvt_next = wp_next_scheduled( $gwcvt_event );

		if ( $gwcvt_next ) {
			wp_unschedule_event( $gwcvt_next, $gwcvt_event );
		}
	}

	delete_transient( 'gwcvt_unverified_count' );

	if ( ! get_option( 'gwcvt_allow_destructive_uninstall' ) ) {
		return;
	}

	foreach ( GWCVT_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	/* Bounded rather than unbounded. A network with more sites than this has an
	 * administrator who deletes plugins with WP-CLI, and a query that tries to
	 * load every site on a large network is one that dies partway through and
	 * leaves half the job done with no record of which half. */
	$gwcvt_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $gwcvt_sites as $gwcvt_site_id ) {
		switch_to_blog( (int) $gwcvt_site_id );
		gwcvt_uninstall_site();
		restore_current_blog();
	}
} else {
	gwcvt_uninstall_site();
}

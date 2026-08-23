<?php
/**
 * The capabilities, against real roles.
 *
 * The unit suite can only prove that gwc_vt_grant_capabilities() writes what it
 * says it writes into a role double. What it cannot prove — because the stub
 * does not implement map_meta_cap — is that the capabilities actually land on
 * real WordPress roles and survive being taken away and re-granted. That is
 * this script's job.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/caps.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly, everywhere, and not `global $x` alongside a bare
 * top-level `$x = 0`. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL — while `global` in the helper below reaches
 * the real global. The two are different variables, the counter increments one
 * and the summary reads the other, and the script cheerfully prints ALL PASS
 * underneath a list of failures. That happened. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 */
function gwc_vt_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n";
}

/* ── They are granted ────────────────────────────────────────────────────── */

foreach ( array( 'administrator', 'editor' ) as $gwc_vt_role_name ) {
	$gwc_vt_role = get_role( $gwc_vt_role_name );

	gwc_vt_check( $gwc_vt_role_name . ' exists', (bool) $gwc_vt_role );

	if ( ! $gwc_vt_role ) {
		continue;
	}

	gwc_vt_check( $gwc_vt_role_name . ' can verify hours', $gwc_vt_role->has_cap( 'gwc_vt_verify_hours' ) );
	gwc_vt_check( $gwc_vt_role_name . ' can issue letters', $gwc_vt_role->has_cap( 'gwc_vt_issue_letters' ) );
}

/* ── And not to everybody ────────────────────────────────────────────────── */

$gwc_vt_subscriber = get_role( 'subscriber' );
gwc_vt_check(
	'subscriber cannot verify hours',
	$gwc_vt_subscriber && ! $gwc_vt_subscriber->has_cap( 'gwc_vt_verify_hours' )
);
gwc_vt_check(
	'subscriber cannot issue letters',
	$gwc_vt_subscriber && ! $gwc_vt_subscriber->has_cap( 'gwc_vt_issue_letters' )
);

/* ── A role that lost them gets them back ────────────────────────────────────
 * The reason the grant runs on init rather than on activation. remove_cap()
 * leaves exactly what a security plugin rebuilding roles, or a restore from a
 * backup taken before install, leaves behind.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_editor = get_role( 'editor' );
$gwc_vt_editor->remove_cap( 'gwc_vt_verify_hours' );

gwc_vt_check(
	'the capability really was removed',
	! get_role( 'editor' )->has_cap( 'gwc_vt_verify_hours' )
);

gwc_vt_grant_capabilities();

gwc_vt_check(
	'a lost capability is restored by the next init',
	get_role( 'editor' )->has_cap( 'gwc_vt_verify_hours' )
);

/* ── One set to false is a decision, and is respected ─────────────────────── */

$gwc_vt_editor = get_role( 'editor' );
$gwc_vt_editor->add_cap( 'gwc_vt_issue_letters', false );

gwc_vt_grant_capabilities();

gwc_vt_check(
	'a capability cleared in a role manager stays cleared',
	! get_role( 'editor' )->has_cap( 'gwc_vt_issue_letters' )
);

// Put it back, so re-running this script starts from where it started.
get_role( 'editor' )->add_cap( 'gwc_vt_issue_letters', true );

/* ── The helpers agree with the constants ────────────────────────────────── */

gwc_vt_check( 'gwc_vt_cap(verify)', 'gwc_vt_verify_hours' === gwc_vt_cap( 'verify' ) );
gwc_vt_check( 'gwc_vt_cap(issue)', 'gwc_vt_issue_letters' === gwc_vt_cap( 'issue' ) );
gwc_vt_check( 'gwc_vt_cap(manage)', 'manage_options' === gwc_vt_cap( 'manage' ) );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

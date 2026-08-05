<?php
/**
 * The capabilities, against real roles.
 *
 * The unit suite can only prove that gwcvt_grant_capabilities() writes what it
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
$GLOBALS['gwcvt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 */
function gwcvt_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n";
}

/* ── They are granted ────────────────────────────────────────────────────── */

foreach ( array( 'administrator', 'editor' ) as $gwcvt_role_name ) {
	$gwcvt_role = get_role( $gwcvt_role_name );

	gwcvt_check( $gwcvt_role_name . ' exists', (bool) $gwcvt_role );

	if ( ! $gwcvt_role ) {
		continue;
	}

	gwcvt_check( $gwcvt_role_name . ' can verify hours', $gwcvt_role->has_cap( 'gwcvt_verify_hours' ) );
	gwcvt_check( $gwcvt_role_name . ' can issue letters', $gwcvt_role->has_cap( 'gwcvt_issue_letters' ) );
}

/* ── And not to everybody ────────────────────────────────────────────────── */

$gwcvt_subscriber = get_role( 'subscriber' );
gwcvt_check(
	'subscriber cannot verify hours',
	$gwcvt_subscriber && ! $gwcvt_subscriber->has_cap( 'gwcvt_verify_hours' )
);
gwcvt_check(
	'subscriber cannot issue letters',
	$gwcvt_subscriber && ! $gwcvt_subscriber->has_cap( 'gwcvt_issue_letters' )
);

/* ── A role that lost them gets them back ────────────────────────────────────
 * The reason the grant runs on init rather than on activation. remove_cap()
 * leaves exactly what a security plugin rebuilding roles, or a restore from a
 * backup taken before install, leaves behind.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_editor = get_role( 'editor' );
$gwcvt_editor->remove_cap( 'gwcvt_verify_hours' );

gwcvt_check(
	'the capability really was removed',
	! get_role( 'editor' )->has_cap( 'gwcvt_verify_hours' )
);

gwcvt_grant_capabilities();

gwcvt_check(
	'a lost capability is restored by the next init',
	get_role( 'editor' )->has_cap( 'gwcvt_verify_hours' )
);

/* ── One set to false is a decision, and is respected ─────────────────────── */

$gwcvt_editor = get_role( 'editor' );
$gwcvt_editor->add_cap( 'gwcvt_issue_letters', false );

gwcvt_grant_capabilities();

gwcvt_check(
	'a capability unticked in a role manager stays unticked',
	! get_role( 'editor' )->has_cap( 'gwcvt_issue_letters' )
);

// Put it back, so re-running this script starts from where it started.
get_role( 'editor' )->add_cap( 'gwcvt_issue_letters', true );

/* ── The helpers agree with the constants ────────────────────────────────── */

gwcvt_check( 'gwcvt_cap(verify)', 'gwcvt_verify_hours' === gwcvt_cap( 'verify' ) );
gwcvt_check( 'gwcvt_cap(issue)', 'gwcvt_issue_letters' === gwcvt_cap( 'issue' ) );
gwcvt_check( 'gwcvt_cap(manage)', 'manage_options' === gwcvt_cap( 'manage' ) );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

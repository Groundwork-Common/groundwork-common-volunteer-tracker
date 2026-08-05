<?php
/**
 * Attestation against real WordPress.
 *
 * What this proves that VerifyTest cannot: capabilities go through real
 * map_meta_cap rather than a stub that answers from an explicit grant, the
 * pending-to-publish transition is a real post status change, and the
 * unverified count is a real WP_Query over a real NOT EXISTS join.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/verify.php
 *
 * It creates its own fixtures and deletes them again, so it is safe to re-run.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS, not a top-level local — see the note in tests/integration/entries.php. */
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_made']     = array();
$GLOBALS['gwcvt_users']    = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwcvt_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [got: ' . $got . ']' : '' ), "\n";
}

/**
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param string $status       Post status.
 * @return int
 */
function gwcvt_make_entry( int $volunteer_id, string $date, int $minutes, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_ENTRY_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $id, GWCVT_ENTRY_MINUTES, $minutes );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Create a user in a role.
 *
 * @param string $login Login name.
 * @param string $role  Role slug.
 * @return int
 */
function gwcvt_make_user( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );

	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);

	$GLOBALS['gwcvt_users'][] = $id;

	return (int) $id;
}

$gwcvt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Ada Nwosu',
	)
);
$GLOBALS['gwcvt_made'][] = $gwcvt_volunteer;

$gwcvt_editor     = gwcvt_make_user( 'zzytest_editor', 'editor' );
$gwcvt_subscriber = gwcvt_make_user( 'zzytest_subscriber', 'subscriber' );

/* ── Capabilities, through real map_meta_cap ─────────────────────────────── */

$gwcvt_entry = gwcvt_make_entry( $gwcvt_volunteer, '2026-05-02', 180 );

gwcvt_check(
	'an editor may verify',
	gwcvt_user_can_verify( $gwcvt_editor, $gwcvt_entry )
);

gwcvt_check(
	'a subscriber may not verify',
	! gwcvt_user_can_verify( $gwcvt_subscriber, $gwcvt_entry )
);

gwcvt_check(
	'a subscriber’s attempt does nothing',
	! gwcvt_verify_entry( $gwcvt_entry, $gwcvt_subscriber ) && ! gwcvt_entry_is_verified( $gwcvt_entry )
);

/* ── Verifying ───────────────────────────────────────────────────────────── */

gwcvt_check( 'an editor’s attempt succeeds', gwcvt_verify_entry( $gwcvt_entry, $gwcvt_editor ) );
gwcvt_check( 'the entry is verified', gwcvt_entry_is_verified( $gwcvt_entry ) );
gwcvt_check( 'the attester is recorded', $gwcvt_editor === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_VERIFIED_BY, true ) );
gwcvt_check( 'the method is recorded', 'staff' === gwcvt_entry_method( $gwcvt_entry ) );
gwcvt_check(
	'the letter line names the attester',
	false !== strpos( gwcvt_attestation_line( $gwcvt_entry ), 'zzytest_editor' ),
	gwcvt_attestation_line( $gwcvt_entry )
);

/* ── Pending becomes published ───────────────────────────────────────────── */

$gwcvt_pending = gwcvt_make_entry( $gwcvt_volunteer, '2026-05-09', 120, 'pending' );

gwcvt_check( 'a self-logged entry starts pending', 'pending' === get_post_status( $gwcvt_pending ) );

gwcvt_verify_entry( $gwcvt_pending, $gwcvt_editor );

gwcvt_check(
	'verifying a pending entry publishes it',
	'publish' === get_post_status( $gwcvt_pending ),
	(string) get_post_status( $gwcvt_pending )
);

/* ── Totals move from pending to verified ────────────────────────────────── */

$gwcvt_unverified = gwcvt_make_entry( $gwcvt_volunteer, '2026-05-16', 60 );
$gwcvt_totals     = gwcvt_compute_totals( $gwcvt_volunteer );

gwcvt_check( 'verified minutes', 300 === $gwcvt_totals->verified_minutes, (string) $gwcvt_totals->verified_minutes );
gwcvt_check( 'pending minutes', 60 === $gwcvt_totals->pending_minutes, (string) $gwcvt_totals->pending_minutes );

gwcvt_verify_entry( $gwcvt_unverified, $gwcvt_editor );
$gwcvt_totals = gwcvt_compute_totals( $gwcvt_volunteer );

gwcvt_check( 'verifying moves hours across', 360 === $gwcvt_totals->verified_minutes, (string) $gwcvt_totals->verified_minutes );
gwcvt_check( 'nothing is left pending', 0 === $gwcvt_totals->pending_minutes, (string) $gwcvt_totals->pending_minutes );

/* ── Withdrawing ─────────────────────────────────────────────────────────── */

gwcvt_unverify_entry( $gwcvt_unverified );
$gwcvt_totals = gwcvt_compute_totals( $gwcvt_volunteer );

gwcvt_check( 'withdrawing moves them back', 300 === $gwcvt_totals->verified_minutes, (string) $gwcvt_totals->verified_minutes );
gwcvt_check(
	'withdrawing does not unpublish the entry',
	'publish' === get_post_status( $gwcvt_unverified ),
	(string) get_post_status( $gwcvt_unverified )
);

/* ── The count ───────────────────────────────────────────────────────────────
 * A real WP_Query with a NOT EXISTS join, and the transient that caches it.
 * ─────────────────────────────────────────────────────────────────────────── */

gwcvt_forget_unverified_count();
$gwcvt_count = gwcvt_unverified_count();

gwcvt_check( 'the unverified count is exact', 1 === $gwcvt_count, (string) $gwcvt_count );

gwcvt_check( 'the count is cached', false !== get_transient( GWCVT_UNVERIFIED_COUNT_KEY ) );

gwcvt_verify_entry( $gwcvt_unverified, $gwcvt_editor );

gwcvt_check(
	'verifying drops the cache',
	false === get_transient( GWCVT_UNVERIFIED_COUNT_KEY )
);

gwcvt_check( 'the count is now zero', 0 === gwcvt_unverified_count(), (string) gwcvt_unverified_count() );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $GLOBALS['gwcvt_users'] as $gwcvt_user_id ) {
	wp_delete_user( $gwcvt_user_id );
}

gwcvt_forget_unverified_count();

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

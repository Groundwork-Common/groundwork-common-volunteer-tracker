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
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();
$GLOBALS['gwc_vt_users']    = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
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
function gwc_vt_make_entry( int $volunteer_id, string $date, int $minutes, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Create a user in a role.
 *
 * @param string $login Login name.
 * @param string $role  Role slug.
 * @return int
 */
function gwc_vt_make_user( string $login, string $role ): int {
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

	$GLOBALS['gwc_vt_users'][] = $id;

	return (int) $id;
}

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Ada Nwosu',
	)
);
$GLOBALS['gwc_vt_made'][] = $gwc_vt_volunteer;

$gwc_vt_editor     = gwc_vt_make_user( 'zzytest_editor', 'editor' );
$gwc_vt_subscriber = gwc_vt_make_user( 'zzytest_subscriber', 'subscriber' );

/* ── Capabilities, through real map_meta_cap ─────────────────────────────── */

$gwc_vt_entry = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-05-02', 180 );

gwc_vt_check(
	'an editor may verify',
	gwc_vt_user_can_verify( $gwc_vt_editor, $gwc_vt_entry )
);

gwc_vt_check(
	'a subscriber may not verify',
	! gwc_vt_user_can_verify( $gwc_vt_subscriber, $gwc_vt_entry )
);

gwc_vt_check(
	'a subscriber’s attempt does nothing',
	! gwc_vt_verify_entry( $gwc_vt_entry, $gwc_vt_subscriber ) && ! gwc_vt_entry_is_verified( $gwc_vt_entry )
);

/* ── Verifying ───────────────────────────────────────────────────────────── */

gwc_vt_check( 'an editor’s attempt succeeds', gwc_vt_verify_entry( $gwc_vt_entry, $gwc_vt_editor ) );
gwc_vt_check( 'the entry is verified', gwc_vt_entry_is_verified( $gwc_vt_entry ) );
gwc_vt_check( 'the attester is recorded', $gwc_vt_editor === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_VERIFIED_BY, true ) );
gwc_vt_check( 'the method is recorded', 'staff' === gwc_vt_entry_method( $gwc_vt_entry ) );
gwc_vt_check(
	'the letter line names the attester',
	false !== strpos( gwc_vt_attestation_line( $gwc_vt_entry ), 'zzytest_editor' ),
	gwc_vt_attestation_line( $gwc_vt_entry )
);

/* ── Pending becomes published ───────────────────────────────────────────── */

$gwc_vt_pending = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-05-09', 120, 'pending' );

gwc_vt_check( 'a self-logged entry starts pending', 'pending' === get_post_status( $gwc_vt_pending ) );

gwc_vt_verify_entry( $gwc_vt_pending, $gwc_vt_editor );

gwc_vt_check(
	'verifying a pending entry publishes it',
	'publish' === get_post_status( $gwc_vt_pending ),
	(string) get_post_status( $gwc_vt_pending )
);

/* ── Totals move from pending to verified ────────────────────────────────── */

$gwc_vt_unverified = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-05-16', 60 );
$gwc_vt_totals     = gwc_vt_compute_totals( $gwc_vt_volunteer );

gwc_vt_check( 'verified minutes', 300 === $gwc_vt_totals->verified_minutes, (string) $gwc_vt_totals->verified_minutes );
gwc_vt_check( 'pending minutes', 60 === $gwc_vt_totals->pending_minutes, (string) $gwc_vt_totals->pending_minutes );

gwc_vt_verify_entry( $gwc_vt_unverified, $gwc_vt_editor );
$gwc_vt_totals = gwc_vt_compute_totals( $gwc_vt_volunteer );

gwc_vt_check( 'verifying moves hours across', 360 === $gwc_vt_totals->verified_minutes, (string) $gwc_vt_totals->verified_minutes );
gwc_vt_check( 'nothing is left pending', 0 === $gwc_vt_totals->pending_minutes, (string) $gwc_vt_totals->pending_minutes );

/* ── Withdrawing ─────────────────────────────────────────────────────────── */

gwc_vt_unverify_entry( $gwc_vt_unverified );
$gwc_vt_totals = gwc_vt_compute_totals( $gwc_vt_volunteer );

gwc_vt_check( 'withdrawing moves them back', 300 === $gwc_vt_totals->verified_minutes, (string) $gwc_vt_totals->verified_minutes );
gwc_vt_check(
	'withdrawing does not unpublish the entry',
	'publish' === get_post_status( $gwc_vt_unverified ),
	(string) get_post_status( $gwc_vt_unverified )
);

/* ── The count ───────────────────────────────────────────────────────────────
 * A real WP_Query with a NOT EXISTS join, and the transient that caches it.
 * ─────────────────────────────────────────────────────────────────────────── */

/* Measured as a delta, not against a fixed number. gwc_vt_unverified_count() is
 * a site-wide figure and this script does not own the site — a demo fixture or
 * a record somebody left behind would break an absolute assertion while the
 * plugin was working perfectly. That happened: these read `1` and `0` until the
 * seeded demo organisation existed. */
gwc_vt_forget_unverified_count();
$gwc_vt_baseline = gwc_vt_unverified_count();

$gwc_vt_extra = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-05-23', 45 );
gwc_vt_forget_unverified_count();

gwc_vt_check(
	'an unverified entry raises the count by one',
	gwc_vt_unverified_count() === $gwc_vt_baseline + 1,
	gwc_vt_unverified_count() . ' vs baseline ' . $gwc_vt_baseline
);

gwc_vt_check( 'the count is cached', false !== get_transient( GWC_VT_UNVERIFIED_COUNT_KEY ) );

gwc_vt_verify_entry( $gwc_vt_extra, $gwc_vt_editor );

gwc_vt_check(
	'verifying drops the cache',
	false === get_transient( GWC_VT_UNVERIFIED_COUNT_KEY )
);

/* Back to the baseline exactly, because only the entry added AFTER the baseline
 * was taken has been verified. $gwc_vt_unverified is deliberately left alone
 * here: it was already unverified when the baseline was measured, so verifying
 * it too would land one BELOW the baseline — which is what the first version of
 * this assertion got wrong by calling the expected figure "the baseline". */
gwc_vt_check(
	'verifying it again returns the count to the baseline',
	gwc_vt_unverified_count() === $gwc_vt_baseline,
	gwc_vt_unverified_count() . ' vs baseline ' . $gwc_vt_baseline
);

/* ── Withdrawing in bulk stops to ask ────────────────────────────────────────
 * Verifying in bulk is additive and undoable, so it acts at once. Withdrawing
 * removes an attestation naming a person and a date, and verifying again does
 * not restore it — it records a new one. It used to be two clicks with no
 * confirmation, no undo, and a green notice afterwards.
 *
 * The assertion that matters is the second one: that asking has not quietly
 * become doing.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_set_current_user( $gwc_vt_editor );

$gwc_vt_bulk = array();

foreach ( range( 1, 2 ) as $gwc_vt_n ) {
	$gwc_vt_id = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-0' . $gwc_vt_n, 120 );
	gwc_vt_verify_entry( $gwc_vt_id, $gwc_vt_editor );
	$gwc_vt_bulk[] = $gwc_vt_id;
}

/* One that was never verified, which has nothing to withdraw. */
$gwc_vt_never = gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-09', 60 );

$gwc_vt_redirect = gwc_vt_handle_bulk_actions(
	admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
	'gwc_vt_unverify',
	array_merge( $gwc_vt_bulk, array( $gwc_vt_never ) )
);

gwc_vt_check(
	'bulk withdrawal asks first',
	false !== strpos( $gwc_vt_redirect, 'gwc_vt_confirm=unverify' )
);

gwc_vt_check(
	'AND HAS WITHDRAWN NOTHING YET',
	gwc_vt_entry_is_verified( $gwc_vt_bulk[0] ) && gwc_vt_entry_is_verified( $gwc_vt_bulk[1] )
);

parse_str( (string) wp_parse_url( $gwc_vt_redirect, PHP_URL_QUERY ), $gwc_vt_args );

gwc_vt_check(
	'an entry with nothing to withdraw is left out',
	false === strpos( (string) ( $gwc_vt_args['gwc_vt_ids'] ?? '' ), (string) $gwc_vt_never ),
	(string) ( $gwc_vt_args['gwc_vt_ids'] ?? '' )
);

gwc_vt_check(
	'and is counted as skipped',
	1 === (int) ( $gwc_vt_args['gwc_vt_skipped'] ?? 0 ),
	(string) ( $gwc_vt_args['gwc_vt_skipped'] ?? 0 )
);

/* Verifying, by contrast, still acts immediately. */
foreach ( $gwc_vt_bulk as $gwc_vt_id ) {
	gwc_vt_unverify_entry( $gwc_vt_id );
}

$gwc_vt_redirect = gwc_vt_handle_bulk_actions(
	admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
	'gwc_vt_verify',
	$gwc_vt_bulk
);

gwc_vt_check(
	'bulk verification still acts at once',
	false !== strpos( $gwc_vt_redirect, 'gwc_vt_result=verified' ) && gwc_vt_entry_is_verified( $gwc_vt_bulk[0] )
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $GLOBALS['gwc_vt_users'] as $gwc_vt_user_id ) {
	wp_delete_user( $gwc_vt_user_id );
}

gwc_vt_forget_unverified_count();

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

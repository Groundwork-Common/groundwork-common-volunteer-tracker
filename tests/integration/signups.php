<?php
/**
 * Signups against real WordPress: capacity, the waiting list, and matching.
 *
 * Everything here needs a database. The unit suite covers the cancellation token
 * and the settling lock; this covers what those protect.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/signups.php
 *
 * It creates its own fixtures and deletes them again, so it is safe to re-run.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/entries.php about what
 * `wp eval-file` does to a top-level assignment. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();

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
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwc_vt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Create a shift.
 *
 * @param string $date Y-m-d.
 * @param int    $max  How many people it takes; 0 for no limit.
 * @param int    $min  How many it needs.
 * @return int
 */
function gwc_vt_make_shift( string $date, int $max = 0, int $min = 0 ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWC_VT_SHIFT_MAX, $max );
	update_post_meta( $id, GWC_VT_SHIFT_MIN, $min );

	gwc_vt_retitle_shift( (int) $id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Remember a signup so the cleanup can remove it.
 *
 * @param int $signup_id Signup post ID.
 * @return int
 */
function gwc_vt_track( int $signup_id ): int {
	if ( $signup_id > 0 ) {
		$GLOBALS['gwc_vt_made'][] = $signup_id;
	}

	return $signup_id;
}

$gwc_vt_jane  = gwc_vt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwc_vt_omar  = gwc_vt_make_volunteer( 'Zzytest Omar Delacroix', 'omar@example.test' );
$gwc_vt_priya = gwc_vt_make_volunteer( 'Zzytest Priya Nandakumar' );

/* ── Putting somebody on a shift ─────────────────────────────────────────── */

$gwc_vt_shift  = gwc_vt_make_shift( '2030-06-01', 2, 2 );
$gwc_vt_first  = gwc_vt_track( gwc_vt_add_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_jane ) ) );

gwc_vt_check( 'a signup is created', $gwc_vt_first > 0, (string) $gwc_vt_first );
gwc_vt_check( 'and lands on the roster', 'publish' === get_post_status( $gwc_vt_first ), (string) get_post_status( $gwc_vt_first ) );
gwc_vt_check( 'it is a child of the shift', $gwc_vt_shift === (int) get_post_field( 'post_parent', $gwc_vt_first ) );
gwc_vt_check( 'the shift is one full', 1 === gwc_vt_shift_filled( $gwc_vt_shift ), (string) gwc_vt_shift_filled( $gwc_vt_shift ) );
gwc_vt_check( 'and still short of its minimum', gwc_vt_shift_is_understaffed( $gwc_vt_shift ) );
gwc_vt_check( 'with one place left', 1 === gwc_vt_shift_spots_left( $gwc_vt_shift ), (string) gwc_vt_shift_spots_left( $gwc_vt_shift ) );

gwc_vt_check(
	'the roster reads its name off the volunteer record',
	'Zzytest Jane Quimby' === gwc_vt_signup_name( $gwc_vt_first ),
	gwc_vt_signup_name( $gwc_vt_first )
);
gwc_vt_check(
	'and its address off the volunteer record',
	'jane@example.test' === gwc_vt_signup_email( $gwc_vt_first ),
	gwc_vt_signup_email( $gwc_vt_first )
);

/* ── Signing up twice is signing up once ─────────────────────────────────────
 * The public form gives an identical answer either way, so a second submission
 * must not become a second place on the roster.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_again = gwc_vt_add_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_jane ) );

gwc_vt_check( 'signing up again returns the same signup', $gwc_vt_first === $gwc_vt_again, (string) $gwc_vt_again );
gwc_vt_check( 'and does not take a second place', 1 === gwc_vt_shift_filled( $gwc_vt_shift ), (string) gwc_vt_shift_filled( $gwc_vt_shift ) );

/* ── Capacity ────────────────────────────────────────────────────────────── */

$gwc_vt_second = gwc_vt_track( gwc_vt_add_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_omar ) ) );

gwc_vt_check( 'the second person fills the shift', 2 === gwc_vt_shift_filled( $gwc_vt_shift ), (string) gwc_vt_shift_filled( $gwc_vt_shift ) );
gwc_vt_check( 'which is no longer understaffed', ! gwc_vt_shift_is_understaffed( $gwc_vt_shift ) );
gwc_vt_check( 'with no places left', 0 === gwc_vt_shift_spots_left( $gwc_vt_shift ), (string) gwc_vt_shift_spots_left( $gwc_vt_shift ) );

/* Over the maximum is recorded, never refused. Somebody working off a court
 * order should not be bounced by a number typed in March. */
$gwc_vt_third = gwc_vt_track( gwc_vt_add_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_priya ) ) );

gwc_vt_check( 'a third person is still recorded', $gwc_vt_third > 0, (string) $gwc_vt_third );
gwc_vt_check( 'on the waiting list', GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_third ), (string) get_post_status( $gwc_vt_third ) );
gwc_vt_check( 'and not on the roster', 2 === gwc_vt_shift_filled( $gwc_vt_shift ), (string) gwc_vt_shift_filled( $gwc_vt_shift ) );

/* ── Withdrawing ─────────────────────────────────────────────────────────── */

gwc_vt_withdraw_signup( $gwc_vt_second );

gwc_vt_check( 'a withdrawal is kept, not deleted', GWC_VT_SIGNUP_WITHDRAWN === get_post_status( $gwc_vt_second ), (string) get_post_status( $gwc_vt_second ) );
gwc_vt_check(
	'the waiting list moves up immediately',
	'publish' === get_post_status( $gwc_vt_third ),
	(string) get_post_status( $gwc_vt_third )
);
gwc_vt_check( 'and the shift is full again', 2 === gwc_vt_shift_filled( $gwc_vt_shift ), (string) gwc_vt_shift_filled( $gwc_vt_shift ) );

gwc_vt_check(
	'a withdrawn signup is off the roster query',
	! in_array( $gwc_vt_second, gwc_vt_shift_signup_ids( $gwc_vt_shift ), true )
);
gwc_vt_check(
	'but findable when asked for',
	in_array( $gwc_vt_second, gwc_vt_shift_signup_ids( $gwc_vt_shift, array( GWC_VT_SIGNUP_WITHDRAWN ) ), true )
);

/* Coming back after withdrawing is a normal thing to do, and it retires the
 * cancellation link in the first email. */
$gwc_vt_before_token = gwc_vt_signup_token( $gwc_vt_second );
$gwc_vt_returned     = gwc_vt_add_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_omar ) );

gwc_vt_check( 'signing up again reuses the same record', $gwc_vt_second === $gwc_vt_returned, (string) $gwc_vt_returned );
gwc_vt_check( 'the shift being full puts them on the waiting list', GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_second ), (string) get_post_status( $gwc_vt_second ) );
gwc_vt_check( 'and the old cancellation link no longer works', ! gwc_vt_signup_token_valid( $gwc_vt_second, $gwc_vt_before_token ) );
gwc_vt_check( 'while the new one does', gwc_vt_signup_token_valid( $gwc_vt_second, gwc_vt_signup_token( $gwc_vt_second ) ) );

/* ── A signup from somebody who is not on file ───────────────────────────────
 * The public form's shape: a name and an address stored as claims, volunteer
 * left at 0, matched by a human afterwards.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_open  = gwc_vt_make_shift( '2030-06-08' );
$gwc_vt_claim = gwc_vt_track(
	gwc_vt_add_signup(
		$gwc_vt_open,
		array(
			'claim_name'  => 'Zzytest Marcus Bell',
			'claim_email' => 'marcus@example.test',
			'source'      => 'self',
		)
	)
);

gwc_vt_check( 'a stranger can be recorded', $gwc_vt_claim > 0, (string) $gwc_vt_claim );
gwc_vt_check( 'attached to nobody', '0' === (string) get_post_meta( $gwc_vt_claim, GWC_VT_SIGNUP_VOLUNTEER, true ) );
gwc_vt_check(
	'and shown as unmatched',
	false !== strpos( gwc_vt_signup_name( $gwc_vt_claim ), 'unmatched' ),
	gwc_vt_signup_name( $gwc_vt_claim )
);
gwc_vt_check(
	'their claimed address is where a note would go',
	'marcus@example.test' === gwc_vt_signup_email( $gwc_vt_claim ),
	gwc_vt_signup_email( $gwc_vt_claim )
);

$gwc_vt_claim_again = gwc_vt_add_signup(
	$gwc_vt_open,
	array(
		'claim_name'  => 'Zzytest Marcus Bell',
		'claim_email' => 'marcus@example.test',
		'source'      => 'self',
	)
);
gwc_vt_check( 'the same address does not book twice', $gwc_vt_claim === $gwc_vt_claim_again, (string) $gwc_vt_claim_again );
gwc_vt_check( 'so the shift has one person on it', 1 === gwc_vt_shift_filled( $gwc_vt_open ), (string) gwc_vt_shift_filled( $gwc_vt_open ) );

/* Matching answers who this is, and nothing more. */
$gwc_vt_marcus = gwc_vt_make_volunteer( 'Zzytest Marcus Bell', 'marcus@example.test' );
gwc_vt_attach_signup( $gwc_vt_claim, $gwc_vt_marcus );

gwc_vt_check( 'attaching sets the volunteer', $gwc_vt_marcus === (int) get_post_meta( $gwc_vt_claim, GWC_VT_SIGNUP_VOLUNTEER, true ) );
gwc_vt_check(
	'and the roster stops saying unmatched',
	'Zzytest Marcus Bell' === gwc_vt_signup_name( $gwc_vt_claim ),
	gwc_vt_signup_name( $gwc_vt_claim )
);
gwc_vt_check( 'attaching does not change the roster count', 1 === gwc_vt_shift_filled( $gwc_vt_open ), (string) gwc_vt_shift_filled( $gwc_vt_open ) );

/* ── A shift with no maximum ─────────────────────────────────────────────── */

gwc_vt_track( gwc_vt_add_signup( $gwc_vt_open, array( 'volunteer_id' => $gwc_vt_jane ) ) );
gwc_vt_track( gwc_vt_add_signup( $gwc_vt_open, array( 'volunteer_id' => $gwc_vt_omar ) ) );

gwc_vt_check( 'everybody gets a place when there is no maximum', 3 === gwc_vt_shift_filled( $gwc_vt_open ), (string) gwc_vt_shift_filled( $gwc_vt_open ) );
gwc_vt_check( 'and places left is unanswerable rather than zero', null === gwc_vt_shift_spots_left( $gwc_vt_open ) );
gwc_vt_check( 'a shift with no minimum is never understaffed', ! gwc_vt_shift_is_understaffed( $gwc_vt_open ) );

/* ── Lowering the maximum does not un-invite anybody ─────────────────────── */

update_post_meta( $gwc_vt_open, GWC_VT_SHIFT_MAX, 1 );
gwc_vt_settle_signups( $gwc_vt_open );

gwc_vt_check( 'lowering the maximum leaves the roster alone', 3 === gwc_vt_shift_filled( $gwc_vt_open ), (string) gwc_vt_shift_filled( $gwc_vt_open ) );
gwc_vt_check( 'and places left reads as none rather than negative', 0 === gwc_vt_shift_spots_left( $gwc_vt_open ), (string) gwc_vt_shift_spots_left( $gwc_vt_open ) );

/* ── Refusals ────────────────────────────────────────────────────────────── */

gwc_vt_check( 'a signup needs a real shift', 0 === gwc_vt_add_signup( $gwc_vt_jane, array( 'volunteer_id' => $gwc_vt_omar ) ) );
gwc_vt_check( 'a signup needs somebody', 0 === gwc_vt_add_signup( $gwc_vt_open, array() ) );
gwc_vt_check( 'a signup needs a real volunteer', 0 === gwc_vt_add_signup( $gwc_vt_open, array( 'volunteer_id' => $gwc_vt_shift ) ) );
gwc_vt_check( 'attaching needs a real volunteer', ! gwc_vt_attach_signup( $gwc_vt_claim, $gwc_vt_shift ) );

/* Signups must never dirty a volunteer's hour totals — the meta keys are
 * deliberately not the entry's, and this is the assertion that keeps them apart. */
$gwc_vt_totals = gwc_vt_volunteer_totals( $gwc_vt_jane );
gwc_vt_check(
	'signing up for a shift records no hours',
	0 === $gwc_vt_totals->total_minutes() && 0 === $gwc_vt_totals->entries,
	(string) $gwc_vt_totals->total_minutes()
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

foreach ( array( $gwc_vt_shift, $gwc_vt_open ) as $gwc_vt_id ) {
	delete_option( 'gwc_vt_signup_lock_' . $gwc_vt_id );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

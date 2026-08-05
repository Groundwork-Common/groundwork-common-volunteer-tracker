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
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_made']     = array();

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
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwcvt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWCVT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwcvt_made'][] = $id;

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
function gwcvt_make_shift( string $date, int $max = 0, int $min = 0 ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_SHIFT_DATE, $date );
	update_post_meta( $id, GWCVT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWCVT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWCVT_SHIFT_MAX, $max );
	update_post_meta( $id, GWCVT_SHIFT_MIN, $min );

	gwcvt_retitle_shift( (int) $id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Remember a signup so the cleanup can remove it.
 *
 * @param int $signup_id Signup post ID.
 * @return int
 */
function gwcvt_track( int $signup_id ): int {
	if ( $signup_id > 0 ) {
		$GLOBALS['gwcvt_made'][] = $signup_id;
	}

	return $signup_id;
}

$gwcvt_jane  = gwcvt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwcvt_omar  = gwcvt_make_volunteer( 'Zzytest Omar Delacroix', 'omar@example.test' );
$gwcvt_priya = gwcvt_make_volunteer( 'Zzytest Priya Nandakumar' );

/* ── Putting somebody on a shift ─────────────────────────────────────────── */

$gwcvt_shift  = gwcvt_make_shift( '2030-06-01', 2, 2 );
$gwcvt_first  = gwcvt_track( gwcvt_add_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_jane ) ) );

gwcvt_check( 'a signup is created', $gwcvt_first > 0, (string) $gwcvt_first );
gwcvt_check( 'and lands on the roster', 'publish' === get_post_status( $gwcvt_first ), (string) get_post_status( $gwcvt_first ) );
gwcvt_check( 'it is a child of the shift', $gwcvt_shift === (int) get_post_field( 'post_parent', $gwcvt_first ) );
gwcvt_check( 'the shift is one full', 1 === gwcvt_shift_filled( $gwcvt_shift ), (string) gwcvt_shift_filled( $gwcvt_shift ) );
gwcvt_check( 'and still short of its minimum', gwcvt_shift_is_understaffed( $gwcvt_shift ) );
gwcvt_check( 'with one place left', 1 === gwcvt_shift_spots_left( $gwcvt_shift ), (string) gwcvt_shift_spots_left( $gwcvt_shift ) );

gwcvt_check(
	'the roster reads its name off the volunteer record',
	'Zzytest Jane Quimby' === gwcvt_signup_name( $gwcvt_first ),
	gwcvt_signup_name( $gwcvt_first )
);
gwcvt_check(
	'and its address off the volunteer record',
	'jane@example.test' === gwcvt_signup_email( $gwcvt_first ),
	gwcvt_signup_email( $gwcvt_first )
);

/* ── Signing up twice is signing up once ─────────────────────────────────────
 * The public form gives an identical answer either way, so a second submission
 * must not become a second place on the roster.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_again = gwcvt_add_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_jane ) );

gwcvt_check( 'signing up again returns the same signup', $gwcvt_first === $gwcvt_again, (string) $gwcvt_again );
gwcvt_check( 'and does not take a second place', 1 === gwcvt_shift_filled( $gwcvt_shift ), (string) gwcvt_shift_filled( $gwcvt_shift ) );

/* ── Capacity ────────────────────────────────────────────────────────────── */

$gwcvt_second = gwcvt_track( gwcvt_add_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_omar ) ) );

gwcvt_check( 'the second person fills the shift', 2 === gwcvt_shift_filled( $gwcvt_shift ), (string) gwcvt_shift_filled( $gwcvt_shift ) );
gwcvt_check( 'which is no longer understaffed', ! gwcvt_shift_is_understaffed( $gwcvt_shift ) );
gwcvt_check( 'with no places left', 0 === gwcvt_shift_spots_left( $gwcvt_shift ), (string) gwcvt_shift_spots_left( $gwcvt_shift ) );

/* Over the maximum is recorded, never refused. Somebody working off a court
 * order should not be bounced by a number typed in March. */
$gwcvt_third = gwcvt_track( gwcvt_add_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_priya ) ) );

gwcvt_check( 'a third person is still recorded', $gwcvt_third > 0, (string) $gwcvt_third );
gwcvt_check( 'on the waiting list', GWCVT_SIGNUP_WAITLIST === get_post_status( $gwcvt_third ), (string) get_post_status( $gwcvt_third ) );
gwcvt_check( 'and not on the roster', 2 === gwcvt_shift_filled( $gwcvt_shift ), (string) gwcvt_shift_filled( $gwcvt_shift ) );

/* ── Withdrawing ─────────────────────────────────────────────────────────── */

gwcvt_withdraw_signup( $gwcvt_second );

gwcvt_check( 'a withdrawal is kept, not deleted', GWCVT_SIGNUP_WITHDRAWN === get_post_status( $gwcvt_second ), (string) get_post_status( $gwcvt_second ) );
gwcvt_check(
	'the waiting list moves up immediately',
	'publish' === get_post_status( $gwcvt_third ),
	(string) get_post_status( $gwcvt_third )
);
gwcvt_check( 'and the shift is full again', 2 === gwcvt_shift_filled( $gwcvt_shift ), (string) gwcvt_shift_filled( $gwcvt_shift ) );

gwcvt_check(
	'a withdrawn signup is off the roster query',
	! in_array( $gwcvt_second, gwcvt_shift_signup_ids( $gwcvt_shift ), true )
);
gwcvt_check(
	'but findable when asked for',
	in_array( $gwcvt_second, gwcvt_shift_signup_ids( $gwcvt_shift, array( GWCVT_SIGNUP_WITHDRAWN ) ), true )
);

/* Coming back after withdrawing is a normal thing to do, and it retires the
 * cancellation link in the first email. */
$gwcvt_before_token = gwcvt_signup_token( $gwcvt_second );
$gwcvt_returned     = gwcvt_add_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_omar ) );

gwcvt_check( 'signing up again reuses the same record', $gwcvt_second === $gwcvt_returned, (string) $gwcvt_returned );
gwcvt_check( 'the shift being full puts them on the waiting list', GWCVT_SIGNUP_WAITLIST === get_post_status( $gwcvt_second ), (string) get_post_status( $gwcvt_second ) );
gwcvt_check( 'and the old cancellation link no longer works', ! gwcvt_signup_token_valid( $gwcvt_second, $gwcvt_before_token ) );
gwcvt_check( 'while the new one does', gwcvt_signup_token_valid( $gwcvt_second, gwcvt_signup_token( $gwcvt_second ) ) );

/* ── A signup from somebody who is not on file ───────────────────────────────
 * The public form's shape: a name and an address stored as claims, volunteer
 * left at 0, matched by a human afterwards.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_open  = gwcvt_make_shift( '2030-06-08' );
$gwcvt_claim = gwcvt_track(
	gwcvt_add_signup(
		$gwcvt_open,
		array(
			'claim_name'  => 'Zzytest Marcus Bell',
			'claim_email' => 'marcus@example.test',
			'source'      => 'self',
		)
	)
);

gwcvt_check( 'a stranger can be recorded', $gwcvt_claim > 0, (string) $gwcvt_claim );
gwcvt_check( 'attached to nobody', '0' === (string) get_post_meta( $gwcvt_claim, GWCVT_SIGNUP_VOLUNTEER, true ) );
gwcvt_check(
	'and shown as unmatched',
	false !== strpos( gwcvt_signup_name( $gwcvt_claim ), 'unmatched' ),
	gwcvt_signup_name( $gwcvt_claim )
);
gwcvt_check(
	'their claimed address is where a note would go',
	'marcus@example.test' === gwcvt_signup_email( $gwcvt_claim ),
	gwcvt_signup_email( $gwcvt_claim )
);

$gwcvt_claim_again = gwcvt_add_signup(
	$gwcvt_open,
	array(
		'claim_name'  => 'Zzytest Marcus Bell',
		'claim_email' => 'marcus@example.test',
		'source'      => 'self',
	)
);
gwcvt_check( 'the same address does not book twice', $gwcvt_claim === $gwcvt_claim_again, (string) $gwcvt_claim_again );
gwcvt_check( 'so the shift has one person on it', 1 === gwcvt_shift_filled( $gwcvt_open ), (string) gwcvt_shift_filled( $gwcvt_open ) );

/* Matching answers who this is, and nothing more. */
$gwcvt_marcus = gwcvt_make_volunteer( 'Zzytest Marcus Bell', 'marcus@example.test' );
gwcvt_attach_signup( $gwcvt_claim, $gwcvt_marcus );

gwcvt_check( 'attaching sets the volunteer', $gwcvt_marcus === (int) get_post_meta( $gwcvt_claim, GWCVT_SIGNUP_VOLUNTEER, true ) );
gwcvt_check(
	'and the roster stops saying unmatched',
	'Zzytest Marcus Bell' === gwcvt_signup_name( $gwcvt_claim ),
	gwcvt_signup_name( $gwcvt_claim )
);
gwcvt_check( 'attaching does not change the roster count', 1 === gwcvt_shift_filled( $gwcvt_open ), (string) gwcvt_shift_filled( $gwcvt_open ) );

/* ── A shift with no maximum ─────────────────────────────────────────────── */

gwcvt_track( gwcvt_add_signup( $gwcvt_open, array( 'volunteer_id' => $gwcvt_jane ) ) );
gwcvt_track( gwcvt_add_signup( $gwcvt_open, array( 'volunteer_id' => $gwcvt_omar ) ) );

gwcvt_check( 'everybody gets a place when there is no maximum', 3 === gwcvt_shift_filled( $gwcvt_open ), (string) gwcvt_shift_filled( $gwcvt_open ) );
gwcvt_check( 'and places left is unanswerable rather than zero', null === gwcvt_shift_spots_left( $gwcvt_open ) );
gwcvt_check( 'a shift with no minimum is never understaffed', ! gwcvt_shift_is_understaffed( $gwcvt_open ) );

/* ── Lowering the maximum does not un-invite anybody ─────────────────────── */

update_post_meta( $gwcvt_open, GWCVT_SHIFT_MAX, 1 );
gwcvt_settle_signups( $gwcvt_open );

gwcvt_check( 'lowering the maximum leaves the roster alone', 3 === gwcvt_shift_filled( $gwcvt_open ), (string) gwcvt_shift_filled( $gwcvt_open ) );
gwcvt_check( 'and places left reads as none rather than negative', 0 === gwcvt_shift_spots_left( $gwcvt_open ), (string) gwcvt_shift_spots_left( $gwcvt_open ) );

/* ── Refusals ────────────────────────────────────────────────────────────── */

gwcvt_check( 'a signup needs a real shift', 0 === gwcvt_add_signup( $gwcvt_jane, array( 'volunteer_id' => $gwcvt_omar ) ) );
gwcvt_check( 'a signup needs somebody', 0 === gwcvt_add_signup( $gwcvt_open, array() ) );
gwcvt_check( 'a signup needs a real volunteer', 0 === gwcvt_add_signup( $gwcvt_open, array( 'volunteer_id' => $gwcvt_shift ) ) );
gwcvt_check( 'attaching needs a real volunteer', ! gwcvt_attach_signup( $gwcvt_claim, $gwcvt_shift ) );

/* Signups must never dirty a volunteer's hour totals — the meta keys are
 * deliberately not the entry's, and this is the assertion that keeps them apart. */
$gwcvt_totals = gwcvt_volunteer_totals( $gwcvt_jane );
gwcvt_check(
	'signing up for a shift records no hours',
	0 === $gwcvt_totals->total_minutes() && 0 === $gwcvt_totals->entries,
	(string) $gwcvt_totals->total_minutes()
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

foreach ( array( $gwcvt_shift, $gwcvt_open ) as $gwcvt_id ) {
	delete_option( 'gwcvt_signup_lock_' . $gwcvt_id );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

<?php
/**
 * Signups against real WordPress: capacity, the waiting list, and matching.
 *
 * Everything here needs a database. The unit suite covers the cancellation token
 * and the settling lock; this covers what those protect.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
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

echo "\n── Holding seats for a group ────────────────────────────────────\n";

/* ── The thing #210 exists for ───────────────────────────────────────────────
 * "Acme Corp is bringing twelve on Saturday": one booking, twelve places, no
 * names. Everything below turns on gwc_vt_shift_filled() summing seats rather
 * than counting rows, because every count on every screen reads through it.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwc_vt_hold_shift = gwc_vt_make_shift( '2030-09-14', 20, 0 );

$gwc_vt_group_hold = gwc_vt_track(
	gwc_vt_add_signup(
		$gwc_vt_hold_shift,
		array(
			'claim_name'  => 'Acme Corp',
			'claim_email' => 'rota@acme.example',
			'seats'       => 12,
		)
	)
);

gwc_vt_check( 'a hold is created without a volunteer', $gwc_vt_group_hold > 0, (string) $gwc_vt_group_hold );

gwc_vt_check(
	'twelve seats fill twelve places, not one',
	12 === gwc_vt_shift_filled( $gwc_vt_hold_shift ),
	(string) gwc_vt_shift_filled( $gwc_vt_hold_shift )
);

gwc_vt_check(
	'and spots-left agrees with it',
	8 === gwc_vt_shift_spots_left( $gwc_vt_hold_shift ),
	(string) gwc_vt_shift_spots_left( $gwc_vt_hold_shift )
);

gwc_vt_check( 'it reads as a hold', gwc_vt_signup_is_group_hold( $gwc_vt_group_hold ) );

/* Not "(unmatched)". A hold has no volunteer on purpose, and calling it
 * unmatched sends a coordinator looking for a person who does not exist. */
gwc_vt_check(
	'and is named plainly rather than reported as unmatched',
	'Acme Corp' === gwc_vt_signup_name( $gwc_vt_group_hold ),
	gwc_vt_signup_name( $gwc_vt_group_hold )
);

/* ── The seat sum must not move anything on a site with no holds ───────────── */
$gwc_vt_plain_shift = gwc_vt_make_shift( '2030-09-15', 3, 0 );
$gwc_vt_plain       = gwc_vt_track(
	gwc_vt_add_signup( $gwc_vt_plain_shift, array( 'claim_name' => 'Solo Person' ) )
);

gwc_vt_check(
	'an ordinary signup still fills exactly one place',
	1 === gwc_vt_shift_filled( $gwc_vt_plain_shift ),
	(string) gwc_vt_shift_filled( $gwc_vt_plain_shift )
);

gwc_vt_check(
	'and stores no seats row at all, so nothing needed migrating',
	'' === (string) get_post_meta( $gwc_vt_plain, GWC_VT_SIGNUP_SEATS, true )
);

echo "\n── The queue stops rather than stepping over a hold ─────────────\n";

/* Three places, then a twelve-seat hold at the front of the queue and a
 * one-seat signup behind it. Promoting the hold over-books by nine; skipping it
 * gives a group that has waited a fortnight its place to a walk-in. So the
 * queue stops, and a person decides. */
$gwc_vt_queue_shift = gwc_vt_make_shift( '2030-09-16', 4, 0 );

gwc_vt_track( gwc_vt_add_signup( $gwc_vt_queue_shift, array( 'claim_name' => 'First In' ) ) );

$gwc_vt_big_hold = gwc_vt_track(
	gwc_vt_add_signup( $gwc_vt_queue_shift, array( 'claim_name' => 'Big Group', 'seats' => 12 ) )
);

$gwc_vt_behind = gwc_vt_track(
	gwc_vt_add_signup( $gwc_vt_queue_shift, array( 'claim_name' => 'Behind Them' ) )
);

gwc_vt_check(
	'a hold that does not fit is waitlisted rather than over-booking the shift',
	GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_big_hold ),
	(string) get_post_status( $gwc_vt_big_hold )
);

gwc_vt_check(
	'and the one-seat signup behind it does not leapfrog it',
	GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_behind ),
	(string) get_post_status( $gwc_vt_behind )
);

gwc_vt_check(
	'so the shift stays as full as it honestly is',
	1 === gwc_vt_shift_filled( $gwc_vt_queue_shift ),
	(string) gwc_vt_shift_filled( $gwc_vt_queue_shift )
);

echo "\n── Reducing a hold, without re-making it ────────────────────────\n";

/* Backdated deliberately. Comparing the value before and after would pass a
 * delete-and-re-make that happened inside the same second, and
 * current_time( 'mysql' ) has one-second resolution — which is the trap
 * GWC_VT_SIGNUP_REVISION exists because of, recorded in inc/signup-cpt.php. A
 * fixed old timestamp cannot be arrived at by accident. */
$gwc_vt_was_created = '2029-01-02 03:04:05';

update_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_CREATED, $gwc_vt_was_created );

$gwc_vt_was_revision = (int) get_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_REVISION, true );

update_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_REMINDED, current_time( 'mysql', true ) );

gwc_vt_check( 'twelve becomes nine', gwc_vt_set_signup_seats( $gwc_vt_group_hold, 9 ) );

gwc_vt_check(
	'the places come back',
	11 === gwc_vt_shift_spots_left( $gwc_vt_hold_shift ),
	(string) gwc_vt_shift_spots_left( $gwc_vt_hold_shift )
);

/* The three things deleting and re-making would have destroyed. */
gwc_vt_check(
	'the booking still says when it was made',
	$gwc_vt_was_created === (string) get_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_CREATED, true )
);

gwc_vt_check(
	'the cancellation link already in their inbox still works',
	$gwc_vt_was_revision === (int) get_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_REVISION, true )
);

gwc_vt_check(
	'and a reminder that has gone is not owed again',
	'' !== (string) get_post_meta( $gwc_vt_group_hold, GWC_VT_SIGNUP_REMINDED, true )
);

/* Reducing frees places, so the queue behind should move at once. */
$gwc_vt_freed = gwc_vt_make_shift( '2030-09-17', 12, 0 );
$gwc_vt_squat = gwc_vt_track( gwc_vt_add_signup( $gwc_vt_freed, array( 'claim_name' => 'Squatter', 'seats' => 12 ) ) );
$gwc_vt_next  = gwc_vt_track( gwc_vt_add_signup( $gwc_vt_freed, array( 'claim_name' => 'Next Up' ) ) );

gwc_vt_check(
	'somebody behind a full hold waits',
	GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_next )
);

gwc_vt_set_signup_seats( $gwc_vt_squat, 5 );

gwc_vt_check(
	'and is promoted the moment the hold shrinks',
	'publish' === get_post_status( $gwc_vt_next ),
	(string) get_post_status( $gwc_vt_next )
);

/* A hold cannot be reduced to nothing. Nought places is not a thing anybody
 * means, and it would free the seats while leaving the booking on the roster. */
gwc_vt_set_signup_seats( $gwc_vt_squat, 0 );

gwc_vt_check(
	'a hold cannot be shrunk to nought places',
	1 === gwc_vt_signup_seats( $gwc_vt_squat ),
	(string) gwc_vt_signup_seats( $gwc_vt_squat )
);

echo "\n── A hold names a partner, and the name is not copied ───────────\n";

$gwc_vt_partner_term = wp_insert_term( 'Zzytest Hold Partner', GWC_VT_PARTNER_TAXONOMY );
$gwc_vt_partner_id   = is_wp_error( $gwc_vt_partner_term ) ? 0 : (int) $gwc_vt_partner_term['term_id'];

$gwc_vt_named_shift = gwc_vt_make_shift( '2030-09-18', 20, 0 );
$gwc_vt_named       = gwc_vt_track(
	gwc_vt_add_signup(
		$gwc_vt_named_shift,
		array( 'claim_name' => 'ignored', 'seats' => 6, 'partner' => $gwc_vt_partner_id )
	)
);

gwc_vt_check(
	'the hold is shown under the partner it names',
	'Zzytest Hold Partner' === gwc_vt_signup_name( $gwc_vt_named ),
	gwc_vt_signup_name( $gwc_vt_named )
);

/* The property a copied string would not have. */
wp_update_term( $gwc_vt_partner_id, GWC_VT_PARTNER_TAXONOMY, array( 'name' => 'Zzytest Renamed Partner' ) );
clean_term_cache( array( $gwc_vt_partner_id ), GWC_VT_PARTNER_TAXONOMY );

gwc_vt_check(
	'and renaming the partner renames the booking',
	'Zzytest Renamed Partner' === gwc_vt_signup_name( $gwc_vt_named ),
	gwc_vt_signup_name( $gwc_vt_named )
);

wp_delete_term( $gwc_vt_partner_id, GWC_VT_PARTNER_TAXONOMY );

echo "\n── A hold produces no hours, and nags for none ─────────────────\n";

/* A shift booked entirely by a group's hold has no names on it, so there are no
 * hours to type up — and a worklist line that can never be cleared is the one
 * everybody learns to read past. */
$gwc_vt_past = gwc_vt_make_shift( '2020-05-05', 20, 0 );

gwc_vt_track( gwc_vt_add_signup( $gwc_vt_past, array( 'claim_name' => 'Held Only', 'seats' => 12 ) ) );

gwc_vt_check(
	'a shift held only by a group does not nag to have its hours logged',
	! gwc_vt_shift_is_unlogged( $gwc_vt_past ),
	gwc_vt_shift_is_unlogged( $gwc_vt_past ) ? 'nagging' : 'quiet'
);

/* One named person on the same shift and it nags again, because now there is
 * somebody whose hours could be written up. */
gwc_vt_track( gwc_vt_add_signup( $gwc_vt_past, array( 'claim_name' => 'A Real Person' ) ) );

gwc_vt_check(
	'and nags again as soon as somebody nameable is on it',
	gwc_vt_shift_is_unlogged( $gwc_vt_past )
);

echo "\n── The public list says places, never who ──────────────────────\n";

/* Hard rule territory: a place count says nothing about anybody, and an
 * employer's name is somebody else's business. The public renderer is asked for
 * its actual output rather than reasoned about. */
$gwc_vt_public_shift = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 20, 0 );

update_post_meta( $gwc_vt_public_shift, GWC_VT_SHIFT_ACTIVITY, 'Zzytest public activity' );

gwc_vt_track(
	gwc_vt_add_signup(
		$gwc_vt_public_shift,
		array( 'claim_name' => 'Zzytest Secret Employer', 'claim_email' => 'x@example.org', 'seats' => 12 )
	)
);

$GLOBALS['gwc_vt_was_open'] = get_option( GWC_VT_SETTINGS_OPTION, array() );

update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge(
		(array) $GLOBALS['gwc_vt_was_open'],
		array( 'shifts_enabled' => 1, 'signup_enabled' => 1, 'schedule_page' => 1 )
	)
);
gwc_vt_settings_cache( null, true );

$gwc_vt_public_html = gwc_vt_render_shift_list();

gwc_vt_check(
	'the public list draws something',
	'' !== $gwc_vt_public_html,
	(string) strlen( $gwc_vt_public_html ) . ' bytes'
);

gwc_vt_check(
	'and never names the group holding the places',
	false === strpos( $gwc_vt_public_html, 'Zzytest Secret Employer' )
);

gwc_vt_check(
	'while the places it holds are subtracted from what is offered',
	8 === gwc_vt_shift_spots_left( $gwc_vt_public_shift ),
	(string) gwc_vt_shift_spots_left( $gwc_vt_public_shift )
);

update_option( GWC_VT_SETTINGS_OPTION, (array) $GLOBALS['gwc_vt_was_open'] );
gwc_vt_settings_cache( null, true );

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

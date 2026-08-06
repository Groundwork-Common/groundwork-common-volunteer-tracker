<?php
/**
 * An event's hours can be reached from the screens that nag about them.
 *
 * ── The bug this pins ────────────────────────────────────────────────────────
 * gwcvt_unreconciled_shift_ids() asks gwcvt_shifts_between() for everything,
 * with no parent argument — so an event's times were counted in "N shifts have
 * happened and their hours have not been logged yet". The flat schedule passes
 * parent => 0, so those times were not in the list that nag sent you to, the
 * event's own row offered only Roster and Edit, and gwcvt_shift_log_url()
 * appeared nowhere in either event screen.
 *
 * The count said five and every list showed none. The printed roster's advice
 * was to retype the whole thing into Log a day by hand.
 *
 * So the invariant is not "a badge renders" — it is that the number being
 * nagged about and the screens offering to act on it agree. Both halves are
 * asserted here, against a real database, because both halves are queries.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/event-hours-path.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwcvt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwcvt_hp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── An event with one time that has happened and somebody on it ─────────── */

$gwcvt_event = wp_insert_post(
	array(
		'post_type'   => GWCVT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Collection Drive',
	)
);

$gwcvt_slot = wp_insert_post(
	array(
		'post_type'   => GWCVT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_parent' => (int) $gwcvt_event,
		'post_title'  => 'Zzytest slot',
	)
);

update_post_meta( $gwcvt_slot, GWCVT_SHIFT_DATE, gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ) );
update_post_meta( $gwcvt_slot, GWCVT_SHIFT_START, '09:00' );
update_post_meta( $gwcvt_slot, GWCVT_SHIFT_END, '12:00' );
update_post_meta( $gwcvt_slot, GWCVT_SHIFT_ACTIVITY, 'Sorting donations' );

$gwcvt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Rowan Ashgrove',
	)
);

gwcvt_add_signup(
	(int) $gwcvt_slot,
	array(
		'volunteer_id' => (int) $gwcvt_volunteer,
		'name'         => 'Zzytest Rowan Ashgrove',
	)
);

/* ── The two halves have to agree ────────────────────────────────────────── */

$gwcvt_nagged = in_array( (int) $gwcvt_slot, gwcvt_unreconciled_shift_ids( 200 ), true );
$gwcvt_listed = in_array( (int) $gwcvt_slot, gwcvt_event_unlogged_slot_ids( (int) $gwcvt_event ), true );

gwcvt_hp_check( 'the nag counts an event time', $gwcvt_nagged );
gwcvt_hp_check( 'and the event agrees it is waiting', $gwcvt_listed );
gwcvt_hp_check( 'THE COUNT AND THE SCREEN AGREE', $gwcvt_nagged === $gwcvt_listed );

/* ── And the screens offer somewhere to go ───────────────────────────────── */

ob_start();
gwcvt_render_event_summary_row( (int) $gwcvt_event );
$gwcvt_row = (string) ob_get_clean();

gwcvt_hp_check(
	'the event row says its hours are waiting',
	false !== strpos( $gwcvt_row, 'needs its hours' ) || false !== strpos( $gwcvt_row, 'need their hours' )
);

ob_start();
gwcvt_render_event_roster( (int) $gwcvt_event );
$gwcvt_roster = (string) ob_get_clean();

gwcvt_hp_check( 'the roster offers to log that time', false !== strpos( $gwcvt_roster, 'Log the hours' ) );

gwcvt_hp_check(
	'and the link opens the reconciliation screen for that slot',
	false !== strpos( $gwcvt_roster, 'gwcvt_shift=' . (int) $gwcvt_slot )
);

/* The screen the link lands on has to accept it — an event slot is an ordinary
 * shift, and this is the assertion that keeps it one. */
gwcvt_hp_check(
	'and that screen accepts an event slot',
	GWCVT_SHIFT_TYPE === get_post_type( (int) $gwcvt_slot )
);

/* ── Once logged, it stops being counted ─────────────────────────────────── */

update_post_meta( $gwcvt_slot, GWCVT_SHIFT_RECONCILED, 1 );

gwcvt_hp_check(
	'a logged time leaves the queue',
	! in_array( (int) $gwcvt_slot, gwcvt_event_unlogged_slot_ids( (int) $gwcvt_event ), true )
);

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( gwcvt_shift_signup_ids( (int) $gwcvt_slot, array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ) ) as $gwcvt_signup ) {
	wp_delete_post( (int) $gwcvt_signup, true );
}

foreach ( array( $gwcvt_slot, $gwcvt_volunteer, $gwcvt_event ) as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? 'ALL PASS' : $GLOBALS['gwcvt_failures'] . ' FAILED' ), "\n";

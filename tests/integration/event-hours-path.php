<?php
/**
 * An event's hours can be reached from the screens that nag about them.
 *
 * ── The bug this pins ────────────────────────────────────────────────────────
 * gwc_vt_unreconciled_shift_ids() asks gwc_vt_shifts_between() for everything,
 * with no parent argument — so an event's times were counted in "N shifts have
 * happened and their hours have not been logged yet". The flat schedule passes
 * parent => 0, so those times were not in the list that nag sent you to, the
 * event's own row offered only Roster and Edit, and gwc_vt_shift_log_url()
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
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_hp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── An event with one time that has happened and somebody on it ─────────── */

$gwc_vt_event = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Collection Drive',
	)
);

$gwc_vt_slot = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_parent' => (int) $gwc_vt_event,
		'post_title'  => 'Zzytest slot',
	)
);

update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ) );
update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_START, '09:00' );
update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_END, '12:00' );
update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_ACTIVITY, 'Sorting donations' );

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Rowan Ashgrove',
	)
);

gwc_vt_add_signup(
	(int) $gwc_vt_slot,
	array(
		'volunteer_id' => (int) $gwc_vt_volunteer,
		'name'         => 'Zzytest Rowan Ashgrove',
	)
);

/* ── The two halves have to agree ────────────────────────────────────────── */

$gwc_vt_nagged = in_array( (int) $gwc_vt_slot, gwc_vt_unreconciled_shift_ids( 200 ), true );
$gwc_vt_listed = in_array( (int) $gwc_vt_slot, gwc_vt_event_unlogged_slot_ids( (int) $gwc_vt_event ), true );

gwc_vt_hp_check( 'the nag counts an event time', $gwc_vt_nagged );
gwc_vt_hp_check( 'and the event agrees it is waiting', $gwc_vt_listed );
gwc_vt_hp_check( 'THE COUNT AND THE SCREEN AGREE', $gwc_vt_nagged === $gwc_vt_listed );

/* ── And the screens offer somewhere to go ───────────────────────────────── */

ob_start();
gwc_vt_render_event_summary_row( (int) $gwc_vt_event );
$gwc_vt_row = (string) ob_get_clean();

gwc_vt_hp_check(
	'the event row says its hours are waiting',
	false !== strpos( $gwc_vt_row, 'needs its hours' ) || false !== strpos( $gwc_vt_row, 'need their hours' )
);

ob_start();
gwc_vt_render_event_roster( (int) $gwc_vt_event );
$gwc_vt_roster = (string) ob_get_clean();

gwc_vt_hp_check( 'the roster offers to log that time', false !== strpos( $gwc_vt_roster, 'Log the hours' ) );

gwc_vt_hp_check(
	'and the link opens the reconciliation screen for that slot',
	false !== strpos( $gwc_vt_roster, 'gwc_vt_shift=' . (int) $gwc_vt_slot )
);

/* The screen the link lands on has to accept it — an event slot is an ordinary
 * shift, and this is the assertion that keeps it one. */
gwc_vt_hp_check(
	'and that screen accepts an event slot',
	GWC_VT_SHIFT_TYPE === get_post_type( (int) $gwc_vt_slot )
);

/* ── Once logged, it stops being counted ─────────────────────────────────── */

update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_RECONCILED, 1 );

gwc_vt_hp_check(
	'a logged time leaves the queue',
	! in_array( (int) $gwc_vt_slot, gwc_vt_event_unlogged_slot_ids( (int) $gwc_vt_event ), true )
);

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) as $gwc_vt_signup ) {
	wp_delete_post( (int) $gwc_vt_signup, true );
}

foreach ( array( $gwc_vt_slot, $gwc_vt_volunteer, $gwc_vt_event ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

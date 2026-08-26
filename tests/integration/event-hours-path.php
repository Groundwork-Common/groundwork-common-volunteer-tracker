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

/* The link opens that time's form ON THIS SCREEN now. It used to go to the
 * standalone reconciliation page — this assertion read gwc_vt_shift=<slot> —
 * and the day of a four-time event was eight round trips as a result. What has
 * to stay true is that the roster offers a way to log THAT time, so the
 * assertion follows the link rather than being deleted with it. */
gwc_vt_hp_check(
	'and the link opens that time’s form on this screen',
	false !== strpos( $gwc_vt_roster, 'gwc_vt_log=' . (int) $gwc_vt_slot )
);

/* Following it renders the form, with the roster already in it. */
ob_start();
$_GET['gwc_vt_log'] = (int) $gwc_vt_slot;
gwc_vt_render_event_roster( (int) $gwc_vt_event );
$gwc_vt_open = (string) ob_get_clean();
unset( $_GET['gwc_vt_log'] );

gwc_vt_hp_check(
	'the open time carries the log form',
	false !== strpos( $gwc_vt_open, 'value="gwc_vt_quick_add"' )
);

gwc_vt_hp_check(
	'and it posts back to this event, not to the standalone screen',
	false !== strpos( $gwc_vt_open, 'name="gwc_vt_back_event" value="' . (int) $gwc_vt_event . '"' )
);

gwc_vt_hp_check(
	'with the person who signed up already on it',
	false !== strpos( $gwc_vt_open, 'Zzytest Rowan Ashgrove' ),
	'the roster did not reach the form'
);

/* A time that is not this event's must not open. Without the parentage check
 * the roster would draw a log form for any shift whose ID somebody typed. */
$_GET['gwc_vt_log'] = 99999999;
gwc_vt_hp_check(
	'a shift that is not this event’s does not open',
	0 === gwc_vt_event_open_slot( (int) $gwc_vt_event ),
	(string) gwc_vt_event_open_slot( (int) $gwc_vt_event )
);
unset( $_GET['gwc_vt_log'] );

/* The standalone screen still accepts an event slot — an event slot is an
 * ordinary shift, and this is the assertion that keeps it one. Every other
 * screen still links there. */
gwc_vt_hp_check(
	'and the standalone screen still accepts an event slot',
	GWC_VT_SHIFT_TYPE === get_post_type( (int) $gwc_vt_slot )
);

/* ── The header and the badges read one source ───────────────────────────────
 * "1 of 4 times have their hours logged" over four sections showing three green
 * badges is the count-and-screen-disagree bug this whole script exists for,
 * said inside one screen. Both read GWC_VT_SHIFT_RECONCILED.
 * ─────────────────────────────────────────────────────────────────────────── */

/* A second time, still to come. Without one the "only the times that have
 * finished" rule is not exercised at all: every slot has ended, so counting all
 * of them and counting the finished ones give the same answer, and an assertion
 * that cannot tell them apart is an assertion that passes when the rule is
 * removed. Checked by removing it. */
$gwc_vt_hp_later = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_parent' => (int) $gwc_vt_event,
		'post_title'  => 'tmp',
	)
);

update_post_meta( $gwc_vt_hp_later, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ) );
update_post_meta( $gwc_vt_hp_later, GWC_VT_SHIFT_START, '09:00' );
update_post_meta( $gwc_vt_hp_later, GWC_VT_SHIFT_END, '12:00' );
update_post_meta( $gwc_vt_hp_later, GWC_VT_SHIFT_ACTIVITY, 'Zzytest later role' );

$gwc_vt_hp_slots = gwc_vt_event_slot_ids( (int) $gwc_vt_event, array( 'publish', 'draft' ) );
$gwc_vt_hp_due   = 0;

foreach ( $gwc_vt_hp_slots as $gwc_vt_hp_id ) {
	if ( ! gwc_vt_shift_is_cancelled( (int) $gwc_vt_hp_id ) && gwc_vt_shift_has_ended( (int) $gwc_vt_hp_id ) ) {
		++$gwc_vt_hp_due;
	}
}

ob_start();
gwc_vt_render_event_progress( (int) $gwc_vt_event, gwc_vt_event_capacity( (int) $gwc_vt_event ), $gwc_vt_hp_slots );
$gwc_vt_hp_card = (string) ob_get_clean();

/* "0 of 1" — none written up, one finished. Both numbers asserted, because the
 * failure this guards against is the card and the badges disagreeing, and a
 * check on only one of them would pass while they did. */
gwc_vt_hp_check(
	'the progress card counts the times that have finished',
	false !== strpos(
		$gwc_vt_hp_card,
		sprintf( '%1$s of %2$s', number_format_i18n( 0 ), number_format_i18n( $gwc_vt_hp_due ) )
	),
	$gwc_vt_hp_due . ' finished, none written up'
);

gwc_vt_hp_check(
	'and only the ones that have finished',
	$gwc_vt_hp_due < count( $gwc_vt_hp_slots ) && $gwc_vt_hp_due > 0,
	$gwc_vt_hp_due . ' of ' . count( $gwc_vt_hp_slots ) . ' times have ended'
);

/* An event whose afternoon is still to come has no backlog yet. "0 of 4 logged"
 * at ten in the morning would be reporting one. */
gwc_vt_hp_check(
	'a time still to come is not counted as unwritten',
	false === strpos(
		$gwc_vt_hp_card,
		sprintf( '%1$s of %2$s', number_format_i18n( 0 ), number_format_i18n( count( $gwc_vt_hp_slots ) ) )
	),
	'it counted the time that has not happened'
);

gwc_vt_hp_check(
	'and reads as waiting while one is unwritten',
	false !== strpos( $gwc_vt_hp_card, 'gwcvt-event-progress--waiting' ),
	'it read as clear'
);

/* ── Once logged, it stops being counted ─────────────────────────────────── */

update_post_meta( $gwc_vt_slot, GWC_VT_SHIFT_RECONCILED, 1 );

gwc_vt_hp_check(
	'a logged time leaves the queue',
	! in_array( (int) $gwc_vt_slot, gwc_vt_event_unlogged_slot_ids( (int) $gwc_vt_event ), true )
);

ob_start();
gwc_vt_render_event_progress( (int) $gwc_vt_event, gwc_vt_event_capacity( (int) $gwc_vt_event ), gwc_vt_event_slot_ids( (int) $gwc_vt_event, array( 'publish', 'draft' ) ) );
$gwc_vt_hp_card = (string) ob_get_clean();

gwc_vt_hp_check(
	'and the progress card goes green with it',
	false !== strpos( $gwc_vt_hp_card, 'gwcvt-event-progress--clear' ),
	'it still read as waiting'
);

ob_start();
gwc_vt_render_event_roster( (int) $gwc_vt_event );
$gwc_vt_roster = (string) ob_get_clean();

gwc_vt_hp_check(
	'and the time itself says its hours are logged',
	false !== strpos( $gwc_vt_roster, 'Hours logged' ),
	'the badge still offered to log them'
);

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) as $gwc_vt_signup ) {
	wp_delete_post( (int) $gwc_vt_signup, true );
}

foreach ( array( $gwc_vt_slot, $gwc_vt_hp_later, $gwc_vt_volunteer, $gwc_vt_event ) as $gwc_vt_id ) {
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

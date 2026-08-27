<?php
/**
 * Finding a shift by what it is, where it is, or who is on it.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * tests/ScheduleFilterTest.php covers the half that is arithmetic over rows.
 * Three things here are not:
 *
 *   The person search. gwc_vt_schedule_shift_ids_for_person() is two queries —
 *   volunteers whose name matches, then the signups pointing at them — plus a
 *   third for the people nobody has matched to a record yet. The whole reason
 *   it is written that way is to avoid a query per row, and the only way to see
 *   that it returns the right shifts is to run it.
 *
 *   gwc_vt_event_state(). An event has no roster of its own; its state is the
 *   worst news among its slots, which are real posts with real parents.
 *
 *   The invariant. gwc_vt_schedule_rows() reads state off the database, and the
 *   chip that says "Short of people · 2" has to be the two rows underneath it.
 *   The unit test proves the counting and the filtering agree about an array;
 *   this proves the array is right.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/schedule-filters.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_sfl_made'] = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sfl_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Delete everything this script created.
 *
 * On shutdown so it runs whether this finishes, fails or fatals; PHP runs
 * shutdown functions on exit(), so the exit( 1 ) at the foot is safe beside it.
 */
function gwc_vt_sfl_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_sfl_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

register_shutdown_function( 'gwc_vt_sfl_cleanup' );

/**
 * A shift, with a roster.
 *
 * @param string $date     Y-m-d.
 * @param string $activity What the work is.
 * @param string $where    Where it is.
 * @param array  $extra    Extra meta, keyed.
 * @param int[]  $people   Volunteer post IDs to put on it.
 * @param string $claimed  A name typed into the public form, or ''.
 * @return int
 */
function gwc_vt_sfl_shift( string $date, string $activity, string $where, array $extra = array(), array $people = array(), string $claimed = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	$id = (int) $id;

	$GLOBALS['gwc_vt_sfl_made'][] = $id;

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, $activity );
	update_post_meta( $id, GWC_VT_SHIFT_LOCATION, $where );

	foreach ( $extra as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	foreach ( $people as $volunteer_id ) {
		$signup = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SIGNUP_TYPE,
				'post_status' => 'publish',
				'post_parent' => $id,
				'post_title'  => 'zzytest signup',
			)
		);

		$GLOBALS['gwc_vt_sfl_made'][] = (int) $signup;
		update_post_meta( (int) $signup, GWC_VT_SIGNUP_VOLUNTEER, (int) $volunteer_id );
	}

	if ( '' !== $claimed ) {
		$signup = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SIGNUP_TYPE,
				'post_status' => 'publish',
				'post_parent' => $id,
				'post_title'  => 'zzytest claim',
			)
		);

		$GLOBALS['gwc_vt_sfl_made'][] = (int) $signup;
		update_post_meta( (int) $signup, GWC_VT_SIGNUP_CLAIM_NAME, $claimed );
	}

	gwc_vt_retitle_shift( $id );

	return $id;
}

/* ── Fixtures ────────────────────────────────────────────────────────────── */

$gwc_vt_sfl_priya = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Priya Ramanathan',
	)
);

$GLOBALS['gwc_vt_sfl_made'][] = (int) $gwc_vt_sfl_priya;

$gwc_vt_sfl_soon = gmdate( 'Y-m-d', time() + ( 5 * DAY_IN_SECONDS ) );

$gwc_vt_sfl_hers = gwc_vt_sfl_shift(
	$gwc_vt_sfl_soon,
	'Zzytest produce sorting',
	'Zzytest riverside depot',
	array(
		GWC_VT_SHIFT_MIN => 6,
		GWC_VT_SHIFT_MAX => 8,
	),
	array( (int) $gwc_vt_sfl_priya )
);

$gwc_vt_sfl_theirs = gwc_vt_sfl_shift(
	$gwc_vt_sfl_soon,
	'Zzytest front desk',
	'Zzytest riverside depot',
	array( GWC_VT_SHIFT_MAX => 4 )
);

$gwc_vt_sfl_claimed = gwc_vt_sfl_shift(
	$gwc_vt_sfl_soon,
	'Zzytest evening cover',
	'Zzytest north hall',
	array( GWC_VT_SHIFT_MAX => 4 ),
	array(),
	'Zzytest Marcus Delacroix'
);

/* ── The person search ───────────────────────────────────────────────────── */

$gwc_vt_sfl_found = gwc_vt_schedule_shift_ids_for_person( 'Zzytest Priya' );

gwc_vt_sfl_check(
	'a volunteer’s name finds the shift they are on',
	in_array( $gwc_vt_sfl_hers, $gwc_vt_sfl_found, true ),
	implode( ', ', $gwc_vt_sfl_found )
);

gwc_vt_sfl_check(
	'and does not find a shift they are not on',
	! in_array( $gwc_vt_sfl_theirs, $gwc_vt_sfl_found, true )
);

/* Somebody who typed their own name into the public form and has not been
 * matched to a record yet is still on that shift, and "which shift is that
 * person on" is the same question either way. */
$gwc_vt_sfl_unmatched = gwc_vt_schedule_shift_ids_for_person( 'Marcus' );

gwc_vt_sfl_check(
	'an unmatched signup is found by the name they typed',
	in_array( $gwc_vt_sfl_claimed, $gwc_vt_sfl_unmatched, true ),
	implode( ', ', $gwc_vt_sfl_unmatched )
);

gwc_vt_sfl_check(
	'a name nobody has finds nothing',
	array() === gwc_vt_schedule_shift_ids_for_person( 'Zzytest Nobody At All' )
);

gwc_vt_sfl_check(
	'an empty search is not a search',
	array() === gwc_vt_schedule_shift_ids_for_person( '' )
);

/* ── The search over a row's own words ───────────────────────────────────── */

$gwc_vt_sfl_rows = gwc_vt_schedule_rows(
	array( $gwc_vt_sfl_hers, $gwc_vt_sfl_theirs, $gwc_vt_sfl_claimed ),
	array()
);

$gwc_vt_sfl_by_place = gwc_vt_filter_schedule_rows( $gwc_vt_sfl_rows, '', 'riverside' );

gwc_vt_sfl_check(
	'a place finds the shifts there, case-insensitively',
	2 === count( $gwc_vt_sfl_by_place ),
	(string) count( $gwc_vt_sfl_by_place )
);

$gwc_vt_sfl_by_activity = gwc_vt_filter_schedule_rows( $gwc_vt_sfl_rows, '', 'front desk' );

gwc_vt_sfl_check(
	'an activity finds its shift',
	1 === count( $gwc_vt_sfl_by_activity )
		&& $gwc_vt_sfl_theirs === (int) $gwc_vt_sfl_by_activity[0]['id'],
	(string) count( $gwc_vt_sfl_by_activity )
);

$gwc_vt_sfl_by_person = gwc_vt_filter_schedule_rows( $gwc_vt_sfl_rows, '', 'Zzytest Priya' );

gwc_vt_sfl_check(
	'and a person finds theirs, through the roster',
	1 === count( $gwc_vt_sfl_by_person )
		&& $gwc_vt_sfl_hers === (int) $gwc_vt_sfl_by_person[0]['id'],
	(string) count( $gwc_vt_sfl_by_person )
);

/* ── The invariant: the chip and the rows ────────────────────────────────────
 * For every filter offered, the number on the chip is the number of rows the
 * screen draws under it. This is the rule stated in CLAUDE.md — a count and the
 * screen it links to come from one function — applied inside one screen.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sfl_counts = gwc_vt_schedule_state_counts( $gwc_vt_sfl_rows );

foreach ( GWC_VT_SCHEDULE_FILTERS as $gwc_vt_sfl_state ) {
	$gwc_vt_sfl_kept = gwc_vt_filter_schedule_rows( $gwc_vt_sfl_rows, $gwc_vt_sfl_state, '' );

	gwc_vt_sfl_check(
		'the ' . $gwc_vt_sfl_state . ' chip counts the rows it leaves',
		$gwc_vt_sfl_counts[ $gwc_vt_sfl_state ] === count( $gwc_vt_sfl_kept ),
		$gwc_vt_sfl_counts[ $gwc_vt_sfl_state ] . ' vs ' . count( $gwc_vt_sfl_kept )
	);
}

gwc_vt_sfl_check(
	'and the short chip found the short shift',
	1 === $gwc_vt_sfl_counts['short'],
	(string) $gwc_vt_sfl_counts['short']
);

/* ── An event answers the chips too ──────────────────────────────────────────
 * An event is a container over shifts by post_parent, so "show me what is
 * short" has to mean the festival with an empty time as well as Saturday.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sfl_event = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest meal service',
	)
);

$GLOBALS['gwc_vt_sfl_made'][] = (int) $gwc_vt_sfl_event;

update_post_meta( (int) $gwc_vt_sfl_event, GWC_VT_EVENT_DATE, $gwc_vt_sfl_soon );

gwc_vt_sfl_check(
	'an event with no times yet is neutral, not logged',
	'ok' === gwc_vt_event_state( (int) $gwc_vt_sfl_event ),
	gwc_vt_event_state( (int) $gwc_vt_sfl_event )
);

$gwc_vt_sfl_slot = gwc_vt_sfl_shift(
	$gwc_vt_sfl_soon,
	'Zzytest serving',
	'Zzytest north hall',
	array(
		GWC_VT_SHIFT_MIN => 4,
		GWC_VT_SHIFT_MAX => 8,
	)
);

wp_update_post(
	array(
		'ID'          => $gwc_vt_sfl_slot,
		'post_parent' => (int) $gwc_vt_sfl_event,
	)
);

gwc_vt_sfl_check(
	'an event with a short time is short',
	'short' === gwc_vt_event_state( (int) $gwc_vt_sfl_event ),
	gwc_vt_event_state( (int) $gwc_vt_sfl_event )
);

wp_update_post(
	array(
		'ID'          => (int) $gwc_vt_sfl_event,
		'post_status' => GWC_VT_EVENT_CANCELLED,
	)
);

gwc_vt_sfl_check(
	'and a called-off event is called off, whatever its times say',
	'cancelled' === gwc_vt_event_state( (int) $gwc_vt_sfl_event ),
	gwc_vt_event_state( (int) $gwc_vt_sfl_event )
);

/* Every state an event can be in has words, the same as a shift's. */
gwc_vt_sfl_check(
	'an event’s state has words',
	'' !== gwc_vt_shift_state_label( gwc_vt_event_state( (int) $gwc_vt_sfl_event ) )
);

/* ── And the events list offers them, like the other two lists ───────────────
 * It was the one list on this screen with no chips and no find box: a site with
 * forty events in a year had a screen with no way to ask which of them is short
 * of people, while the two lists beside it both did.
 *
 * The rows come from gwc_vt_schedule_rows() and go through
 * gwc_vt_filter_schedule_rows() — the same two functions, which have always
 * handled events — so what is asserted here is that the SCREEN uses them, and
 * that its links stay on the events list rather than answering with shifts.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sfl_parade = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest lantern parade',
	)
);

$GLOBALS['gwc_vt_sfl_made'][] = (int) $gwc_vt_sfl_parade;

update_post_meta( (int) $gwc_vt_sfl_parade, GWC_VT_EVENT_DATE, $gwc_vt_sfl_soon );
update_post_meta( (int) $gwc_vt_sfl_parade, GWC_VT_EVENT_LOCATION, 'Zzytest north hall' );

/* One time on it, short of people, with Priya on it — so the person search has
 * something to find through an event, which is the branch that exists because
 * an event has no roster of its own. */
$gwc_vt_sfl_parade_slot = gwc_vt_sfl_shift(
	$gwc_vt_sfl_soon,
	'Zzytest lantern carrying',
	'Zzytest north hall',
	array(
		GWC_VT_SHIFT_MIN => 4,
		GWC_VT_SHIFT_MAX => 8,
	),
	array( (int) $gwc_vt_sfl_priya )
);

wp_update_post(
	array(
		'ID'          => $gwc_vt_sfl_parade_slot,
		'post_parent' => (int) $gwc_vt_sfl_parade,
	)
);

/* Both events, because gwc_vt_events_between() reads the pair of dates an event
 * derives from its times — the last day as well as the first, so a festival is
 * still "coming up" on its second morning. Setting only GWC_VT_EVENT_DATE by
 * hand leaves an event every query on this screen steps over. */
gwc_vt_event_refresh_dates( (int) $gwc_vt_sfl_parade );
gwc_vt_event_refresh_dates( (int) $gwc_vt_sfl_event );

/* Everything above this point works on data and needed nobody; these render the
 * real screen, which is behind gwc_vt_can_see_records(). */
$gwc_vt_sfl_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $gwc_vt_sfl_admins ? (int) $gwc_vt_sfl_admins[0]->ID : 1 );

/**
 * The events list as somebody arrives at it, with whatever is in the URL.
 *
 * @param string $term  What is in the find box.
 * @param string $state Which chip is on.
 * @return string
 */
function gwc_vt_sfl_events_screen( string $term = '', string $state = '' ): string {
	unset( $_GET['s'], $_GET['gwc_vt_state'] );

	if ( '' !== $term ) {
		$_GET['s'] = $term;
	}

	if ( '' !== $state ) {
		$_GET['gwc_vt_state'] = $state;
	}

	$_GET['gwc_vt_only'] = 'events';

	ob_start();
	gwc_vt_render_schedule_screen();
	$html = (string) ob_get_clean();

	unset( $_GET['gwc_vt_only'] );

	unset( $_GET['s'], $_GET['gwc_vt_state'] );

	return $html;
}

$GLOBALS['gwc_vt_sfl_ev_all'] = gwc_vt_sfl_events_screen();

gwc_vt_sfl_check(
	'the events list has a find box and chips at all',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'class="search-box"' )
		&& false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwcvt-chip-filter' )
);

/* The box says what it searches. "Find a shift" over a list of events is the
 * kind of wrong nobody reports and everybody notices. */
gwc_vt_sfl_check(
	'and the box says it searches events',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'Find an event' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'Find a shift' )
);

/* Every way out of the chips and the form keeps view=events. Without it a chip
 * on this screen answers with the shifts, which is the failure that looks like
 * the filter working. */
gwc_vt_sfl_check(
	'the find box posts back to this list',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], '<input type="hidden" name="gwc_vt_only" value="events" />' )
);

/* Every STATE chip keeps the narrowing; the Events chip is the one that lets go
 * of it, because it is the way back to the shifts. Counted as "all but one",
 * which is what that sentence looks like in markup. */
$GLOBALS['gwc_vt_sfl_chips'] = substr_count( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwcvt-chip-filter"' )
	+ substr_count( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwcvt-chip-filter ' );

gwc_vt_sfl_check(
	'and every chip but the one that undoes it stays on this list',
	substr_count( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwc_vt_only=events' ) >= $GLOBALS['gwc_vt_sfl_chips'] - 1,
	substr_count( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwc_vt_only=events' ) . ' of ' . $GLOBALS['gwc_vt_sfl_chips'] . ' chip(s)'
);

/* And that one is offered, marked as on, and points at the whole schedule. */
gwc_vt_sfl_check(
	'the Events chip is the narrowing, and is marked on',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'gwcvt-chip-filter--events' )
		&& false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'show the shifts as well' )
);

gwc_vt_sfl_check(
	'both events are there before anything is asked',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'Zzytest lantern parade' )
		&& false !== strpos( $GLOBALS['gwc_vt_sfl_ev_all'], 'Zzytest meal service' )
);

$GLOBALS['gwc_vt_sfl_ev_named'] = gwc_vt_sfl_events_screen( 'lantern' );

gwc_vt_sfl_check(
	'a name leaves the event it names and drops the other',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_named'], 'Zzytest lantern parade' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_named'], 'Zzytest meal service' )
);

/* An event has no roster of its own — somebody is on one of its TIMES. */
$GLOBALS['gwc_vt_sfl_ev_person'] = gwc_vt_sfl_events_screen( 'Zzytest Priya' );

gwc_vt_sfl_check(
	'a person’s name finds the event they are on a time of',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_person'], 'Zzytest lantern parade' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_person'], 'Zzytest meal service' )
);

/* The parade is short of people; the meal service was called off above. */
$GLOBALS['gwc_vt_sfl_ev_short'] = gwc_vt_sfl_events_screen( '', 'short' );
$GLOBALS['gwc_vt_sfl_ev_off']   = gwc_vt_sfl_events_screen( '', 'cancelled' );

gwc_vt_sfl_check(
	'the short chip answers with the short event',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_short'], 'Zzytest lantern parade' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_short'], 'Zzytest meal service' )
);

gwc_vt_sfl_check(
	'and the called-off chip with the called-off one',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_off'], 'Zzytest meal service' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_off'], 'Zzytest lantern parade' )
);

/* Two different facts, and the wrong one tells somebody with a full calendar
 * that they have no events. */
$GLOBALS['gwc_vt_sfl_ev_none'] = gwc_vt_sfl_events_screen( 'Zzytest nothing is called this' );

gwc_vt_sfl_check(
	'nothing matching says so, and does not say the schedule is empty',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_none'], 'Nothing on the schedule matches that' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_none'], 'Nothing scheduled yet' )
);

/* ── Changing HOW a screen is drawn must not change WHAT is on it ────────────
 * Pressing Month on the events list gave a calendar of everything: the control
 * that changes the drawing silently widened the subject, and a coordinator who
 * asked for their events got their shifts as well.
 *
 * gwc_vt_only=events is the narrowing that fixes it, and it is a parameter
 * rather than a property of one screen — the calendar reads it, the flat list
 * reads it (which is where a day clicked out of that calendar lands), and every
 * link on both carries it, because a chip that widens the screen it is narrowing
 * is the same bug wearing a different hat.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * One view of the schedule, as somebody arrives at it.
 *
 * @param array $query What is in the URL.
 * @return string
 */
function gwc_vt_sfl_screen( array $query ): string {
	$was = $_GET;

	foreach ( array( 'view', 'gwc_vt_only', 'gwc_vt_state', 's', 'gwc_vt_month' ) as $key ) {
		unset( $_GET[ $key ] );
	}

	foreach ( $query as $key => $value ) {
		$_GET[ $key ] = $value;
	}

	ob_start();
	gwc_vt_render_schedule_screen();
	$html = (string) ob_get_clean();

	$_GET = $was;

	return $html;
}

$GLOBALS['gwc_vt_sfl_ev_list']  = gwc_vt_sfl_screen( array( 'view' => 'events' ) );
$GLOBALS['gwc_vt_sfl_ev_month'] = gwc_vt_sfl_screen(
	array(
		'view'        => 'month',
		'gwc_vt_only' => 'events',
		'gwc_vt_month' => substr( $gwc_vt_sfl_soon, 0, 7 ),
	)
);
$GLOBALS['gwc_vt_sfl_all_month'] = gwc_vt_sfl_screen(
	array(
		'view'         => 'month',
		'gwc_vt_month' => substr( $gwc_vt_sfl_soon, 0, 7 ),
	)
);

/* The trip somebody takes: Events, then Month. */
gwc_vt_sfl_check(
	'Month on the events list asks for a calendar of the events',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_list'], 'view=month&#038;gwc_vt_only=events' )
);

gwc_vt_sfl_check(
	'and that calendar draws the event',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'Zzytest lantern parade' )
);

/* The bug itself: the shifts around it are not on that calendar. The control
 * changes how, never what. */
gwc_vt_sfl_check(
	'and not the shifts around it',
	false === strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'Zzytest produce sorting' )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'Zzytest front desk' )
);

/* The control, not the calendar: the same month without the narrowing still
 * draws the shifts, or this would be a calendar that lost them for everybody.
 *
 * Counted rather than named, because a day cell caps at GWC_VT_MONTH_CHIPS and
 * says "+2 more" — on a fixture day holding four things, asserting one title is
 * asserting which three the cap happened to keep. */
$GLOBALS['gwc_vt_sfl_chips_all'] = substr_count( $GLOBALS['gwc_vt_sfl_all_month'], 'gwcvt-chip__what' );
$GLOBALS['gwc_vt_sfl_chips_ev']  = substr_count( $GLOBALS['gwc_vt_sfl_ev_month'], 'gwcvt-chip__what' );

gwc_vt_sfl_check(
	'the calendar itself still draws the shifts when nothing narrowed it',
	$GLOBALS['gwc_vt_sfl_chips_all'] > $GLOBALS['gwc_vt_sfl_chips_ev']
		&& $GLOBALS['gwc_vt_sfl_chips_ev'] > 0,
	$GLOBALS['gwc_vt_sfl_chips_all'] . ' chip(s) against ' . $GLOBALS['gwc_vt_sfl_chips_ev'] . ' narrowed'
);

/* And every one the narrowed calendar draws is an event. */
gwc_vt_sfl_check(
	'nothing on the narrowed calendar links to a shift',
	! preg_match( '~gwcvt-month__chip[^>]*shift=[0-9]~', $GLOBALS['gwc_vt_sfl_ev_month'] )
		&& false === strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'shift=' . $gwc_vt_sfl_hers )
);

/* And back again, so the trip is a round one rather than a one-way door. */
gwc_vt_sfl_check(
	'List on that calendar goes back to those events as a list',
	1 === preg_match(
		'~gwcvt-segmented__on[^<]*<[^>]*>\s*Month|gwcvt-schedule__views.*?href="[^"]*gwc_vt_only=events~s',
		$GLOBALS['gwc_vt_sfl_ev_month']
	)
);

gwc_vt_sfl_check(
	'the calendar says it is the events it is showing',
	false !== strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'Events' )
		&& false !== strpos( $GLOBALS['gwc_vt_sfl_ev_month'], 'Find an event' )
);

/* Every link on it keeps the narrowing. A chip or a month step that dropped it
 * would put the shifts back one click later, which is the reported bug with an
 * extra step in front of it. */
foreach ( array( 'gwcvt-chip-filter', 'gwcvt-month__step' ) as $gwc_vt_sfl_kind ) {
	preg_match_all(
		'~class="[^"]*' . $gwc_vt_sfl_kind . '[^"]*"[^>]*~',
		$GLOBALS['gwc_vt_sfl_ev_month'],
		$gwc_vt_sfl_tags
	);

	/* The chips carry the class after the href, the steps before it, so the
	 * whole tag is re-read rather than trusting one order. */
	preg_match_all(
		'~<a[^>]*' . $gwc_vt_sfl_kind . '[^>]*>|<a[^>]*class="[^"]*' . $gwc_vt_sfl_kind . '~',
		$GLOBALS['gwc_vt_sfl_ev_month'],
		$gwc_vt_sfl_links
	);

	$gwc_vt_sfl_all  = implode( ' ', $gwc_vt_sfl_links[0] );
	$gwc_vt_sfl_have = substr_count( $gwc_vt_sfl_all, 'gwc_vt_only=events' );

	/* All of them, less the Events chip itself, which exists to let go of it. */
	$gwc_vt_sfl_want = count( $gwc_vt_sfl_links[0] )
		- ( 'gwcvt-chip-filter' === $gwc_vt_sfl_kind ? 1 : 0 );

	gwc_vt_sfl_check(
		'every ' . $gwc_vt_sfl_kind . ' on it keeps the narrowing',
		count( $gwc_vt_sfl_links[0] ) > 0 && $gwc_vt_sfl_have === $gwc_vt_sfl_want,
		$gwc_vt_sfl_have . ' of ' . $gwc_vt_sfl_want . ' link(s)'
	);
}

/* A day clicked out of that calendar lands on the flat list, which reads the
 * same narrowing rather than answering with the shifts. */
$GLOBALS['gwc_vt_sfl_day'] = gwc_vt_sfl_screen(
	array(
		'gwc_vt_only' => 'events',
		'gwc_vt_on'   => $gwc_vt_sfl_soon,
	)
);

gwc_vt_sfl_check(
	'the flat list reads the narrowing too',
	false === strpos( $GLOBALS['gwc_vt_sfl_day'], 'Zzytest produce sorting' )
);

/* And says so. A screen that has quietly stopped showing everything is how
 * somebody concludes there is nothing there. */
gwc_vt_sfl_check(
	'and says it has been narrowed, with the way back',
	false !== strpos( $GLOBALS['gwc_vt_sfl_day'], 'only events are listed' )
		&& false !== strpos( $GLOBALS['gwc_vt_sfl_day'], 'gwcvt-schedule__narrowed' )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

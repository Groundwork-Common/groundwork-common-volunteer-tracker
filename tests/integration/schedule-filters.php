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

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

<?php
/**
 * What the month calendar puts in its cells.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * tests/ScheduleMonthTest.php covers which cells a month has. What goes IN them
 * is a query, and three things about that are worth pinning:
 *
 *   An overnight shift belongs on the day it starts, once. It runs 22:00 to
 *   06:00 and touches two dates; drawn on both, every count on the screen would
 *   be one too many for the month it ran into.
 *
 *   An event is one chip, not one per time. It is a container over shifts by
 *   post_parent, and a festival with six times must not fill a Saturday with
 *   six chips and push everything else out of the cell.
 *
 *   And an event's chip has to carry its state, so that an event whose times
 *   are short reads short — the same red the filter chip counted it under.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/schedule-month.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_sm_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sm_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Delete everything this script created.
 */
function gwc_vt_sm_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_sm_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

register_shutdown_function( 'gwc_vt_sm_cleanup' );

/**
 * A shift, optionally overnight, optionally inside an event.
 *
 * @param string $date      Y-m-d.
 * @param array  $extra     Extra meta, keyed.
 * @param int    $parent    Event post ID, or 0.
 * @param int    $signups   How many people to put on it.
 * @param bool   $overnight Whether it runs past midnight.
 * @return int
 */
function gwc_vt_sm_shift( string $date, array $extra = array(), int $parent = 0, int $signups = 0, bool $overnight = false ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
			'post_parent' => $parent,
		)
	);

	$id = (int) $id;

	$GLOBALS['gwc_vt_sm_made'][] = $id;

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, $overnight ? '22:00' : '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, $overnight ? '06:00' : '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest month fixture' );

	if ( $overnight ) {
		update_post_meta( $id, GWC_VT_SHIFT_OVERNIGHT, 1 );
	}

	foreach ( $extra as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	for ( $i = 0; $i < $signups; $i++ ) {
		$signup = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SIGNUP_TYPE,
				'post_status' => 'publish',
				'post_parent' => $id,
				'post_title'  => 'zzytest signup',
			)
		);

		$GLOBALS['gwc_vt_sm_made'][] = (int) $signup;
	}

	gwc_vt_retitle_shift( $id );

	return $id;
}

/* A month far enough out that nothing else on the site is in it.
 *
 * $GLOBALS explicitly, and not because it looks tidier: `wp eval-file` runs
 * this file inside a function, so a top-level assignment is a LOCAL and the
 * helper below reading $GLOBALS would find nothing. Written the obvious way
 * first, this script queried an empty date range, found no rows, and printed
 * ALL PASS over three checks that had proved nothing. See the note in
 * tests/integration/entries.php. */
$GLOBALS['gwc_vt_sm_month'] = '2032-05';
$GLOBALS['gwc_vt_sm_from']  = $GLOBALS['gwc_vt_sm_month'] . '-01';
$GLOBALS['gwc_vt_sm_to']    = $GLOBALS['gwc_vt_sm_month'] . '-31';

/**
 * The rows the month view would build for this fixture month.
 *
 * @return array[]
 */
function gwc_vt_sm_rows(): array {
	return gwc_vt_schedule_rows(
		gwc_vt_shifts_between(
			array(
				'from'     => $GLOBALS['gwc_vt_sm_from'],
				'to'       => $GLOBALS['gwc_vt_sm_to'],
				'statuses' => array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ),
				'limit'    => 200,
				'parent'   => 0,
			)
		),
		gwc_vt_events_between(
			array(
				'from'     => $GLOBALS['gwc_vt_sm_from'],
				'to'       => $GLOBALS['gwc_vt_sm_to'],
				'statuses' => array( 'publish', 'draft', GWC_VT_EVENT_CANCELLED ),
				'limit'    => 100,
			)
		)
	);
}

/* ── An overnight shift is on the day it starts, once ────────────────────── */

$gwc_vt_sm_overnight = gwc_vt_sm_shift(
	$GLOBALS['gwc_vt_sm_month'] . '-15',
	array( GWC_VT_SHIFT_MAX => 2 ),
	0,
	1,
	true
);

$gwc_vt_sm_rows = gwc_vt_sm_rows();

$gwc_vt_sm_mine = array_values(
	array_filter(
		$gwc_vt_sm_rows,
		static function ( array $row ) use ( $gwc_vt_sm_overnight ): bool {
			return (int) $row['id'] === $gwc_vt_sm_overnight;
		}
	)
);

gwc_vt_sm_check(
	'an overnight shift is one row, not two',
	1 === count( $gwc_vt_sm_mine ),
	(string) count( $gwc_vt_sm_mine )
);

gwc_vt_sm_check(
	'and it sits on the day it starts',
	$GLOBALS['gwc_vt_sm_month'] . '-15' === (string) ( $gwc_vt_sm_mine[0]['date'] ?? '' ),
	(string) ( $gwc_vt_sm_mine[0]['date'] ?? 'nothing' )
);

/* It really does run into the next day — otherwise the check above is passing
 * for the wrong reason. */
$gwc_vt_sm_ends = gwc_vt_shift_ends( $gwc_vt_sm_overnight );

gwc_vt_sm_check(
	'the fixture really is overnight',
	null !== $gwc_vt_sm_ends && $GLOBALS['gwc_vt_sm_month'] . '-16' === $gwc_vt_sm_ends->format( 'Y-m-d' ),
	null !== $gwc_vt_sm_ends ? $gwc_vt_sm_ends->format( 'Y-m-d H:i' ) : 'null'
);

/* ── An event is one chip, whatever it holds ─────────────────────────────── */

$gwc_vt_sm_event = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest month event',
	)
);

$GLOBALS['gwc_vt_sm_made'][] = (int) $gwc_vt_sm_event;

update_post_meta( (int) $gwc_vt_sm_event, GWC_VT_EVENT_DATE, $GLOBALS['gwc_vt_sm_month'] . '-20' );
update_post_meta( (int) $gwc_vt_sm_event, GWC_VT_EVENT_END_DATE, $GLOBALS['gwc_vt_sm_month'] . '-20' );

/* Three times, one of them short of people. */
gwc_vt_sm_shift( $GLOBALS['gwc_vt_sm_month'] . '-20', array( GWC_VT_SHIFT_MAX => 4 ), (int) $gwc_vt_sm_event, 4 );
gwc_vt_sm_shift( $GLOBALS['gwc_vt_sm_month'] . '-20', array( GWC_VT_SHIFT_MAX => 4 ), (int) $gwc_vt_sm_event, 2 );
gwc_vt_sm_shift(
	$GLOBALS['gwc_vt_sm_month'] . '-20',
	array(
		GWC_VT_SHIFT_MIN => 6,
		GWC_VT_SHIFT_MAX => 8,
	),
	(int) $gwc_vt_sm_event,
	1
);

$gwc_vt_sm_rows = gwc_vt_sm_rows();

$gwc_vt_sm_the_day = $GLOBALS['gwc_vt_sm_month'] . '-20';

$gwc_vt_sm_on_the_day = array_values(
	array_filter(
		$gwc_vt_sm_rows,
		static function ( array $row ) use ( $gwc_vt_sm_the_day ): bool {
			return $gwc_vt_sm_the_day === (string) $row['date'];
		}
	)
);

gwc_vt_sm_check(
	'a three-time event is one chip on its day',
	1 === count( $gwc_vt_sm_on_the_day ),
	count( $gwc_vt_sm_on_the_day ) . ' rows on the 20th'
);

gwc_vt_sm_check(
	'and it is the event, not one of its times',
	'event' === (string) ( $gwc_vt_sm_on_the_day[0]['type'] ?? '' ),
	(string) ( $gwc_vt_sm_on_the_day[0]['type'] ?? 'nothing' )
);

/* ── And the chip carries the event's state ──────────────────────────────── */

gwc_vt_sm_check(
	'an event with a short time reads short',
	'short' === (string) ( $gwc_vt_sm_on_the_day[0]['state'] ?? '' ),
	(string) ( $gwc_vt_sm_on_the_day[0]['state'] ?? 'nothing' )
);

$gwc_vt_sm_summary = gwc_vt_event_fill_summary( (int) $gwc_vt_sm_event );

gwc_vt_sm_check(
	'its chip says it is an event',
	false !== strpos( $gwc_vt_sm_summary, 'Event' ),
	$gwc_vt_sm_summary
);

gwc_vt_sm_check(
	'and says how many of its times are short',
	false !== strpos( $gwc_vt_sm_summary, '1' ),
	$gwc_vt_sm_summary
);

/* A called-off event says that instead of counting places — the same rule the
 * shift summary follows, and for the same reason. */
wp_update_post(
	array(
		'ID'          => (int) $gwc_vt_sm_event,
		'post_status' => GWC_VT_EVENT_CANCELLED,
	)
);

gwc_vt_sm_check(
	'a called-off event reports being called off',
	false !== strpos( gwc_vt_event_fill_summary( (int) $gwc_vt_sm_event ), gwc_vt_shift_state_label( 'cancelled' ) ),
	gwc_vt_event_fill_summary( (int) $gwc_vt_sm_event )
);

/* ── The grid's window covers every cell it draws ────────────────────────────
 * The leading and trailing cells belong to the neighbouring months, and a shift
 * on one of them is still on the screen. A window of the month alone would draw
 * those cells empty and lie about a Saturday that is right there.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sm_weeks = gwc_vt_month_grid( $GLOBALS['gwc_vt_sm_month'], (int) get_option( 'start_of_week' ), gwc_vt_today() );
$gwc_vt_sm_first = (string) $gwc_vt_sm_weeks[0][0]['date'];
$gwc_vt_sm_last  = $gwc_vt_sm_weeks[ count( $gwc_vt_sm_weeks ) - 1 ];
$gwc_vt_sm_last  = (string) $gwc_vt_sm_last[ count( $gwc_vt_sm_last ) - 1 ]['date'];

gwc_vt_sm_check(
	'the grid opens on or before the first of the month',
	$gwc_vt_sm_first <= $GLOBALS['gwc_vt_sm_month'] . '-01',
	$gwc_vt_sm_first
);

gwc_vt_sm_check(
	'and closes on or after the last of it',
	$gwc_vt_sm_last >= $GLOBALS['gwc_vt_sm_month'] . '-31',
	$gwc_vt_sm_last
);

/* A shift in a leading cell is inside the window the month view queries. */
$gwc_vt_sm_leading = gwc_vt_sm_shift( $gwc_vt_sm_first, array( GWC_VT_SHIFT_MAX => 4 ), 0, 1 );

$gwc_vt_sm_found = gwc_vt_shifts_between(
	array(
		'from'     => $gwc_vt_sm_first,
		'to'       => $gwc_vt_sm_last,
		'statuses' => array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ),
		'limit'    => 200,
		'parent'   => 0,
	)
);

gwc_vt_sm_check(
	'a shift in a leading cell is fetched, not drawn as an empty day',
	in_array( $gwc_vt_sm_leading, $gwc_vt_sm_found, true )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

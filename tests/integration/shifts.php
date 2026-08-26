<?php
/**
 * Shifts against real WordPress: ordering, ranges, instants and statuses.
 *
 * The unit suite has no get_posts(), so everything it proves about the schedule
 * is the pure arithmetic. This runs the query layer against a real database, a
 * real meta_query ordered by the shift's own date, and a real registered post
 * type.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/shifts.php
 *
 * It creates its own fixtures and deletes them again, and puts back any setting
 * it changes, so it is safe to re-run against a site that belongs to somebody
 * else.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL while `global` in a helper reaches the real
 * global — two different variables, one incremented and the other read, and the
 * script prints ALL PASS over a list of failures. See tests/integration/entries.php. */
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

/* The settings this script writes, put back at the end. These scripts run
 * against a database that belongs to somebody else. */
$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

/**
 * Create a shift.
 *
 * @param string $date     Y-m-d.
 * @param string $start    H:i.
 * @param string $end      H:i.
 * @param array  $extra    Further meta, keyed by meta key.
 * @param string $status   Post status.
 * @return int
 */
function gwc_vt_make_shift( string $date, string $start = '09:00', string $end = '12:00', array $extra = array(), string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, $start );
	update_post_meta( $id, GWC_VT_SHIFT_END, $end );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWC_VT_SHIFT_SUPERVISOR, 'Dana Reyes' );

	foreach ( $extra as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	gwc_vt_retitle_shift( (int) $id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/* ── The post type is registered, and invisible ──────────────────────────── */

gwc_vt_check( 'the shift type is registered', post_type_exists( GWC_VT_SHIFT_TYPE ) );
gwc_vt_check( 'the signup type is registered', post_type_exists( GWC_VT_SIGNUP_TYPE ) );

$gwc_vt_object = get_post_type_object( GWC_VT_SHIFT_TYPE );
gwc_vt_check( 'shifts are not public', $gwc_vt_object && false === $gwc_vt_object->public );
gwc_vt_check( 'shifts are not in REST', $gwc_vt_object && false === $gwc_vt_object->show_in_rest );
gwc_vt_check( 'shifts are excluded from search', $gwc_vt_object && true === $gwc_vt_object->exclude_from_search );

gwc_vt_check( 'the cancelled status is registered', null !== get_post_status_object( GWC_VT_SHIFT_CANCELLED ) );
gwc_vt_check( 'the waiting list status is registered', null !== get_post_status_object( GWC_VT_SIGNUP_WAITLIST ) );
gwc_vt_check( 'the withdrawn status is registered', null !== get_post_status_object( GWC_VT_SIGNUP_WITHDRAWN ) );

/* The assertion that matters most here. A shift carries a location and a
 * supervisor's name, and its children carry the names and email addresses of
 * everybody who signed up. Neither may be served by the auto-generated route. */
$gwc_vt_routes = rest_get_server()->get_routes();

foreach ( array( GWC_VT_SHIFT_TYPE, GWC_VT_SIGNUP_TYPE ) as $gwc_vt_type ) {
	gwc_vt_check( '/wp/v2/' . $gwc_vt_type . ' is not registered', ! isset( $gwc_vt_routes[ '/wp/v2/' . $gwc_vt_type ] ) );

	$gwc_vt_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwc_vt_type ) );
	gwc_vt_check( '/wp/v2/' . $gwc_vt_type . ' 404s', 404 === $gwc_vt_response->get_status(), (string) $gwc_vt_response->get_status() );
}

/* ── Ordering is by when the shift is, not when it was typed ─────────────── */

$gwc_vt_third  = gwc_vt_make_shift( '2030-04-20' );
$gwc_vt_first  = gwc_vt_make_shift( '2030-04-06' );
$gwc_vt_second = gwc_vt_make_shift( '2030-04-13' );

$gwc_vt_found = gwc_vt_shifts_between(
	array(
		'from' => '2030-04-01',
		'to'   => '2030-04-30',
	)
);

gwc_vt_check( 'a month of shifts is found', 3 === count( $gwc_vt_found ), (string) count( $gwc_vt_found ) );
gwc_vt_check(
	'they come back in date order, not the order they were created',
	array( $gwc_vt_first, $gwc_vt_second, $gwc_vt_third ) === $gwc_vt_found,
	implode( ',', $gwc_vt_found )
);

$gwc_vt_narrow = gwc_vt_shifts_between(
	array(
		'from' => '2030-04-13',
		'to'   => '2030-04-13',
	)
);
gwc_vt_check( 'a range is inclusive at both ends', array( $gwc_vt_second ) === $gwc_vt_narrow, implode( ',', $gwc_vt_narrow ) );

$gwc_vt_open = gwc_vt_shifts_between( array( 'from' => '2030-04-14' ) );
gwc_vt_check( 'an open-ended range works', in_array( $gwc_vt_third, $gwc_vt_open, true ) && ! in_array( $gwc_vt_first, $gwc_vt_open, true ) );

/* A draft is not on the schedule unless it is asked for. */
$gwc_vt_draft = gwc_vt_make_shift( '2030-04-08', '09:00', '12:00', array(), 'draft' );

$gwc_vt_published_only = gwc_vt_shifts_between(
	array(
		'from' => '2030-04-01',
		'to'   => '2030-04-30',
	)
);
gwc_vt_check( 'a draft shift is left out by default', ! in_array( $gwc_vt_draft, $gwc_vt_published_only, true ) );

$gwc_vt_with_drafts = gwc_vt_shifts_between(
	array(
		'from'     => '2030-04-01',
		'to'       => '2030-04-30',
		'statuses' => array( 'publish', 'draft' ),
	)
);
gwc_vt_check( 'a draft shift is found when asked for', in_array( $gwc_vt_draft, $gwc_vt_with_drafts, true ) );

/* ── Durations and instants ──────────────────────────────────────────────── */

gwc_vt_check( 'a morning shift is three hours', 180 === gwc_vt_shift_minutes( $gwc_vt_first ), (string) gwc_vt_shift_minutes( $gwc_vt_first ) );

$gwc_vt_overnight = gwc_vt_make_shift(
	'2030-05-01',
	'22:00',
	'06:00',
	array( GWC_VT_SHIFT_OVERNIGHT => 1 )
);

gwc_vt_check( 'an overnight shift is eight hours', 480 === gwc_vt_shift_minutes( $gwc_vt_overnight ), (string) gwc_vt_shift_minutes( $gwc_vt_overnight ) );

$gwc_vt_ends = gwc_vt_shift_ends( $gwc_vt_overnight );
gwc_vt_check(
	'an overnight shift ends the following morning',
	null !== $gwc_vt_ends && '2030-05-02 06:00' === $gwc_vt_ends->format( 'Y-m-d H:i' ),
	null !== $gwc_vt_ends ? $gwc_vt_ends->format( 'Y-m-d H:i' ) : 'null'
);

$gwc_vt_starts = gwc_vt_shift_starts( $gwc_vt_overnight );
gwc_vt_check(
	'and starts the evening before',
	null !== $gwc_vt_starts && '2030-05-01 22:00' === $gwc_vt_starts->format( 'Y-m-d H:i' ),
	null !== $gwc_vt_starts ? $gwc_vt_starts->format( 'Y-m-d H:i' ) : 'null'
);

/* ── Has it happened, and is it open ─────────────────────────────────────────
 * Yesterday and tomorrow rather than hours either side of now, so that whatever
 * timezone the site is in cannot flip the answer.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_yesterday = gwc_vt_make_shift( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
$gwc_vt_tomorrow  = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );

gwc_vt_check( 'yesterday’s shift has ended', gwc_vt_shift_has_ended( $gwc_vt_yesterday ) );
gwc_vt_check( 'tomorrow’s has not', ! gwc_vt_shift_has_ended( $gwc_vt_tomorrow ) );

gwc_vt_check( 'tomorrow’s shift is open for signups', gwc_vt_shift_is_open( $gwc_vt_tomorrow ) );
gwc_vt_check( 'yesterday’s is not', ! gwc_vt_shift_is_open( $gwc_vt_yesterday ) );
gwc_vt_check( 'a draft is never open', ! gwc_vt_shift_is_open( $gwc_vt_draft ) );

/* The cutoff closes signups early. Two days is enough to cover tomorrow whatever
 * the site's offset from UTC. */
$gwc_vt_settings                        = (array) get_option( GWC_VT_SETTINGS_OPTION );
$gwc_vt_settings['signup_cutoff_hours'] = 48;
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_settings );

gwc_vt_check( 'a cutoff closes a shift that is too soon', ! gwc_vt_shift_is_open( $gwc_vt_tomorrow ) );
gwc_vt_check( 'and leaves a distant one open', gwc_vt_shift_is_open( $gwc_vt_first ) );

$gwc_vt_settings['signup_cutoff_hours'] = 0;
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_settings );

/* ── Cancelling ──────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $gwc_vt_third,
		'post_status' => GWC_VT_SHIFT_CANCELLED,
	)
);

gwc_vt_check( 'a cancelled shift reads as cancelled', gwc_vt_shift_is_cancelled( $gwc_vt_third ) );
gwc_vt_check( 'a cancelled shift is not open', ! gwc_vt_shift_is_open( $gwc_vt_third ) );

/* It is still on the schedule. A shift people committed to that vanished would
 * leave them with no way to find out it was called off. */
$gwc_vt_including_cancelled = gwc_vt_shifts_between(
	array(
		'from'     => '2030-04-01',
		'to'       => '2030-04-30',
		'statuses' => array( 'publish', GWC_VT_SHIFT_CANCELLED ),
	)
);
gwc_vt_check( 'a cancelled shift stays on the schedule', in_array( $gwc_vt_third, $gwc_vt_including_cancelled, true ) );

/* ── Reconciliation state ────────────────────────────────────────────────── */

gwc_vt_check( 'a new shift is not reconciled', ! gwc_vt_shift_is_reconciled( $gwc_vt_yesterday ) );

update_post_meta( $gwc_vt_yesterday, GWC_VT_SHIFT_RECONCILED, gmdate( 'Y-m-d H:i:s' ) );
gwc_vt_check( 'a stamped shift is reconciled', gwc_vt_shift_is_reconciled( $gwc_vt_yesterday ) );

/* ── The derived title ───────────────────────────────────────────────────── */

gwc_vt_check(
	'the title says what and when',
	false !== strpos( get_the_title( $gwc_vt_first ), 'Zzytest sorting donations' ),
	get_the_title( $gwc_vt_first )
);

/* ── What state a shift is in ────────────────────────────────────────────────
 * tests/ShiftTest.php asserts the precedence in gwc_vt_shift_state_from(),
 * which is pure. What it cannot reach is gwc_vt_shift_state() — the half that
 * decides WHICH facts to gather, off real post meta, a real post status and a
 * real roster. A gatherer that read the wrong meta key would hand the pure
 * function a perfectly consistent set of wrong facts, and every unit test would
 * still pass.
 *
 * Five screens draw this as a colour, so it is worth one fixture per state.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_soon = gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) );
$gwc_vt_gone = gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) );

/**
 * Put somebody on a shift.
 *
 * @param int $shift_id Shift post ID.
 * @param int $how_many How many signups to make.
 */
function gwc_vt_fill_shift( int $shift_id, int $how_many ): void {
	for ( $i = 0; $i < $how_many; $i++ ) {
		$id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SIGNUP_TYPE,
				'post_status' => 'publish',
				'post_parent' => $shift_id,
				'post_title'  => 'zzytest signup ' . $i,
			)
		);

		$GLOBALS['gwc_vt_made'][] = (int) $id;
	}
}

$gwc_vt_state_ok = gwc_vt_make_shift( $gwc_vt_soon, '09:00', '12:00', array( GWC_VT_SHIFT_MAX => 8 ) );
gwc_vt_fill_shift( $gwc_vt_state_ok, 3 );
gwc_vt_check( 'a shift filling normally is ok', 'ok' === gwc_vt_shift_state( $gwc_vt_state_ok ), gwc_vt_shift_state( $gwc_vt_state_ok ) );

$gwc_vt_state_short = gwc_vt_make_shift(
	$gwc_vt_soon,
	'09:00',
	'12:00',
	array(
		GWC_VT_SHIFT_MIN => 4,
		GWC_VT_SHIFT_MAX => 8,
	)
);
gwc_vt_fill_shift( $gwc_vt_state_short, 1 );
gwc_vt_check( 'below its minimum is short', 'short' === gwc_vt_shift_state( $gwc_vt_state_short ), gwc_vt_shift_state( $gwc_vt_state_short ) );

$gwc_vt_state_full = gwc_vt_make_shift( $gwc_vt_soon, '09:00', '12:00', array( GWC_VT_SHIFT_MAX => 2 ) );
gwc_vt_fill_shift( $gwc_vt_state_full, 2 );
gwc_vt_check( 'at its maximum is full', 'full' === gwc_vt_shift_state( $gwc_vt_state_full ), gwc_vt_shift_state( $gwc_vt_state_full ) );

$gwc_vt_state_awaiting = gwc_vt_make_shift( $gwc_vt_gone, '09:00', '12:00', array( GWC_VT_SHIFT_MAX => 8 ) );
gwc_vt_fill_shift( $gwc_vt_state_awaiting, 2 );
gwc_vt_check( 'past with people and no hours is awaiting', 'awaiting' === gwc_vt_shift_state( $gwc_vt_state_awaiting ), gwc_vt_shift_state( $gwc_vt_state_awaiting ) );

update_post_meta( $gwc_vt_state_awaiting, GWC_VT_SHIFT_RECONCILED, gmdate( 'Y-m-d H:i:s' ) );
gwc_vt_check( 'and written up, it is logged', 'logged' === gwc_vt_shift_state( $gwc_vt_state_awaiting ), gwc_vt_shift_state( $gwc_vt_state_awaiting ) );

$gwc_vt_state_empty = gwc_vt_make_shift( $gwc_vt_gone, '09:00', '12:00', array( GWC_VT_SHIFT_MAX => 8 ) );
gwc_vt_check( 'past with nobody on it is not awaiting', 'ok' === gwc_vt_shift_state( $gwc_vt_state_empty ), gwc_vt_shift_state( $gwc_vt_state_empty ) );

$gwc_vt_state_off = gwc_vt_make_shift(
	$gwc_vt_soon,
	'09:00',
	'12:00',
	array(
		GWC_VT_SHIFT_MIN => 4,
		GWC_VT_SHIFT_MAX => 8,
	),
	GWC_VT_SHIFT_CANCELLED
);
gwc_vt_check( 'called off beats being short', 'cancelled' === gwc_vt_shift_state( $gwc_vt_state_off ), gwc_vt_shift_state( $gwc_vt_state_off ) );

/* Every state a real shift can be in has words to print beside its colour. */
foreach ( array( $gwc_vt_state_ok, $gwc_vt_state_short, $gwc_vt_state_full, $gwc_vt_state_awaiting, $gwc_vt_state_empty, $gwc_vt_state_off ) as $gwc_vt_state_id ) {
	gwc_vt_check(
		'state ' . gwc_vt_shift_state( $gwc_vt_state_id ) . ' has words',
		'' !== gwc_vt_shift_state_label( gwc_vt_shift_state( $gwc_vt_state_id ) )
	);
}

/* ── The sentence beside the colour ──────────────────────────────────────────
 * Every chip and every line prints gwc_vt_shift_fill_summary(), because the
 * numbers are not enough on their own — "3 of 8" does not say whether three is
 * a problem — and the colour that would say so is the one thing some readers
 * cannot see. The dashboard's strip and its list are the same fortnight drawn
 * twice, so they read it from here rather than each building it.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_check(
	'a short shift says how many it needs',
	false !== strpos( gwc_vt_shift_fill_summary( $gwc_vt_state_short ), '4' ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_short )
);

gwc_vt_check(
	'and still says how many it has',
	false !== strpos( gwc_vt_shift_fill_summary( $gwc_vt_state_short ), '1' ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_short )
);

gwc_vt_check(
	'a full shift says so',
	gwc_vt_shift_fill_summary( $gwc_vt_state_full ) !== gwc_vt_shift_fill_label( $gwc_vt_state_full ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_full )
);

gwc_vt_check(
	'a shift filling normally says only the numbers',
	gwc_vt_shift_fill_summary( $gwc_vt_state_ok ) === gwc_vt_shift_fill_label( $gwc_vt_state_ok ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_ok )
);

/* A shift that has happened, or been called off, reports what happened to it
 * rather than how full it was. "2 of 8" on a cancelled Saturday is answering a
 * question nobody is asking any more. */
gwc_vt_check(
	'a called-off shift reports being called off',
	gwc_vt_shift_state_label( 'cancelled' ) === gwc_vt_shift_fill_summary( $gwc_vt_state_off ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_off )
);

gwc_vt_check(
	'a written-up shift reports being written up',
	gwc_vt_shift_state_label( 'logged' ) === gwc_vt_shift_fill_summary( $gwc_vt_state_awaiting ),
	gwc_vt_shift_fill_summary( $gwc_vt_state_awaiting )
);

/* Passing the state in must not change the answer — it is an optimisation for
 * callers that already have it, not a second way of deciding. */
foreach ( array( $gwc_vt_state_ok, $gwc_vt_state_short, $gwc_vt_state_full, $gwc_vt_state_off ) as $gwc_vt_fs_id ) {
	gwc_vt_check(
		'the summary is the same whether the state is handed in or looked up',
		gwc_vt_shift_fill_summary( $gwc_vt_fs_id ) === gwc_vt_shift_fill_summary( $gwc_vt_fs_id, gwc_vt_shift_state( $gwc_vt_fs_id ) ),
		gwc_vt_shift_fill_summary( $gwc_vt_fs_id )
	);
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
	delete_option( GWC_VT_SETTINGS_OPTION );
} else {
	update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

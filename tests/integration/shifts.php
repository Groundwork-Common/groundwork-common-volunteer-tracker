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

/* The settings this script writes, put back at the end. These scripts run
 * against a database that belongs to somebody else. */
$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );

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
function gwcvt_make_shift( string $date, string $start = '09:00', string $end = '12:00', array $extra = array(), string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_SHIFT_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_SHIFT_DATE, $date );
	update_post_meta( $id, GWCVT_SHIFT_START, $start );
	update_post_meta( $id, GWCVT_SHIFT_END, $end );
	update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWCVT_SHIFT_SUPERVISOR, 'Dana Reyes' );

	foreach ( $extra as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	gwcvt_retitle_shift( (int) $id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/* ── The post type is registered, and invisible ──────────────────────────── */

gwcvt_check( 'the shift type is registered', post_type_exists( GWCVT_SHIFT_TYPE ) );
gwcvt_check( 'the signup type is registered', post_type_exists( GWCVT_SIGNUP_TYPE ) );

$gwcvt_object = get_post_type_object( GWCVT_SHIFT_TYPE );
gwcvt_check( 'shifts are not public', $gwcvt_object && false === $gwcvt_object->public );
gwcvt_check( 'shifts are not in REST', $gwcvt_object && false === $gwcvt_object->show_in_rest );
gwcvt_check( 'shifts are excluded from search', $gwcvt_object && true === $gwcvt_object->exclude_from_search );

gwcvt_check( 'the cancelled status is registered', null !== get_post_status_object( GWCVT_SHIFT_CANCELLED ) );
gwcvt_check( 'the waiting list status is registered', null !== get_post_status_object( GWCVT_SIGNUP_WAITLIST ) );
gwcvt_check( 'the withdrawn status is registered', null !== get_post_status_object( GWCVT_SIGNUP_WITHDRAWN ) );

/* The assertion that matters most here. A shift carries a location and a
 * supervisor's name, and its children carry the names and email addresses of
 * everybody who signed up. Neither may be served by the auto-generated route. */
$gwcvt_routes = rest_get_server()->get_routes();

foreach ( array( GWCVT_SHIFT_TYPE, GWCVT_SIGNUP_TYPE ) as $gwcvt_type ) {
	gwcvt_check( '/wp/v2/' . $gwcvt_type . ' is not registered', ! isset( $gwcvt_routes[ '/wp/v2/' . $gwcvt_type ] ) );

	$gwcvt_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwcvt_type ) );
	gwcvt_check( '/wp/v2/' . $gwcvt_type . ' 404s', 404 === $gwcvt_response->get_status(), (string) $gwcvt_response->get_status() );
}

/* ── Ordering is by when the shift is, not when it was typed ─────────────── */

$gwcvt_third  = gwcvt_make_shift( '2030-04-20' );
$gwcvt_first  = gwcvt_make_shift( '2030-04-06' );
$gwcvt_second = gwcvt_make_shift( '2030-04-13' );

$gwcvt_found = gwcvt_shifts_between(
	array(
		'from' => '2030-04-01',
		'to'   => '2030-04-30',
	)
);

gwcvt_check( 'a month of shifts is found', 3 === count( $gwcvt_found ), (string) count( $gwcvt_found ) );
gwcvt_check(
	'they come back in date order, not the order they were created',
	array( $gwcvt_first, $gwcvt_second, $gwcvt_third ) === $gwcvt_found,
	implode( ',', $gwcvt_found )
);

$gwcvt_narrow = gwcvt_shifts_between(
	array(
		'from' => '2030-04-13',
		'to'   => '2030-04-13',
	)
);
gwcvt_check( 'a range is inclusive at both ends', array( $gwcvt_second ) === $gwcvt_narrow, implode( ',', $gwcvt_narrow ) );

$gwcvt_open = gwcvt_shifts_between( array( 'from' => '2030-04-14' ) );
gwcvt_check( 'an open-ended range works', in_array( $gwcvt_third, $gwcvt_open, true ) && ! in_array( $gwcvt_first, $gwcvt_open, true ) );

/* A draft is not on the schedule unless it is asked for. */
$gwcvt_draft = gwcvt_make_shift( '2030-04-08', '09:00', '12:00', array(), 'draft' );

$gwcvt_published_only = gwcvt_shifts_between(
	array(
		'from' => '2030-04-01',
		'to'   => '2030-04-30',
	)
);
gwcvt_check( 'a draft shift is left out by default', ! in_array( $gwcvt_draft, $gwcvt_published_only, true ) );

$gwcvt_with_drafts = gwcvt_shifts_between(
	array(
		'from'     => '2030-04-01',
		'to'       => '2030-04-30',
		'statuses' => array( 'publish', 'draft' ),
	)
);
gwcvt_check( 'a draft shift is found when asked for', in_array( $gwcvt_draft, $gwcvt_with_drafts, true ) );

/* ── Durations and instants ──────────────────────────────────────────────── */

gwcvt_check( 'a morning shift is three hours', 180 === gwcvt_shift_minutes( $gwcvt_first ), (string) gwcvt_shift_minutes( $gwcvt_first ) );

$gwcvt_overnight = gwcvt_make_shift(
	'2030-05-01',
	'22:00',
	'06:00',
	array( GWCVT_SHIFT_OVERNIGHT => 1 )
);

gwcvt_check( 'an overnight shift is eight hours', 480 === gwcvt_shift_minutes( $gwcvt_overnight ), (string) gwcvt_shift_minutes( $gwcvt_overnight ) );

$gwcvt_ends = gwcvt_shift_ends( $gwcvt_overnight );
gwcvt_check(
	'an overnight shift ends the following morning',
	null !== $gwcvt_ends && '2030-05-02 06:00' === $gwcvt_ends->format( 'Y-m-d H:i' ),
	null !== $gwcvt_ends ? $gwcvt_ends->format( 'Y-m-d H:i' ) : 'null'
);

$gwcvt_starts = gwcvt_shift_starts( $gwcvt_overnight );
gwcvt_check(
	'and starts the evening before',
	null !== $gwcvt_starts && '2030-05-01 22:00' === $gwcvt_starts->format( 'Y-m-d H:i' ),
	null !== $gwcvt_starts ? $gwcvt_starts->format( 'Y-m-d H:i' ) : 'null'
);

/* ── Has it happened, and is it open ─────────────────────────────────────────
 * Yesterday and tomorrow rather than hours either side of now, so that whatever
 * timezone the site is in cannot flip the answer.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_yesterday = gwcvt_make_shift( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
$gwcvt_tomorrow  = gwcvt_make_shift( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );

gwcvt_check( 'yesterday’s shift has ended', gwcvt_shift_has_ended( $gwcvt_yesterday ) );
gwcvt_check( 'tomorrow’s has not', ! gwcvt_shift_has_ended( $gwcvt_tomorrow ) );

gwcvt_check( 'tomorrow’s shift is open for signups', gwcvt_shift_is_open( $gwcvt_tomorrow ) );
gwcvt_check( 'yesterday’s is not', ! gwcvt_shift_is_open( $gwcvt_yesterday ) );
gwcvt_check( 'a draft is never open', ! gwcvt_shift_is_open( $gwcvt_draft ) );

/* The cutoff closes signups early. Two days is enough to cover tomorrow whatever
 * the site's offset from UTC. */
$gwcvt_settings                        = (array) get_option( GWCVT_SETTINGS_OPTION );
$gwcvt_settings['signup_cutoff_hours'] = 48;
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_settings );

gwcvt_check( 'a cutoff closes a shift that is too soon', ! gwcvt_shift_is_open( $gwcvt_tomorrow ) );
gwcvt_check( 'and leaves a distant one open', gwcvt_shift_is_open( $gwcvt_first ) );

$gwcvt_settings['signup_cutoff_hours'] = 0;
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_settings );

/* ── Cancelling ──────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $gwcvt_third,
		'post_status' => GWCVT_SHIFT_CANCELLED,
	)
);

gwcvt_check( 'a cancelled shift reads as cancelled', gwcvt_shift_is_cancelled( $gwcvt_third ) );
gwcvt_check( 'a cancelled shift is not open', ! gwcvt_shift_is_open( $gwcvt_third ) );

/* It is still on the schedule. A shift people committed to that vanished would
 * leave them with no way to find out it was called off. */
$gwcvt_including_cancelled = gwcvt_shifts_between(
	array(
		'from'     => '2030-04-01',
		'to'       => '2030-04-30',
		'statuses' => array( 'publish', GWCVT_SHIFT_CANCELLED ),
	)
);
gwcvt_check( 'a cancelled shift stays on the schedule', in_array( $gwcvt_third, $gwcvt_including_cancelled, true ) );

/* ── Reconciliation state ────────────────────────────────────────────────── */

gwcvt_check( 'a new shift is not reconciled', ! gwcvt_shift_is_reconciled( $gwcvt_yesterday ) );

update_post_meta( $gwcvt_yesterday, GWCVT_SHIFT_RECONCILED, gmdate( 'Y-m-d H:i:s' ) );
gwcvt_check( 'a stamped shift is reconciled', gwcvt_shift_is_reconciled( $gwcvt_yesterday ) );

/* ── The derived title ───────────────────────────────────────────────────── */

gwcvt_check(
	'the title says what and when',
	false !== strpos( get_the_title( $gwcvt_first ), 'Zzytest sorting donations' ),
	get_the_title( $gwcvt_first )
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

if ( false === $GLOBALS['gwcvt_settings_before'] ) {
	delete_option( GWCVT_SETTINGS_OPTION );
} else {
	update_option( GWCVT_SETTINGS_OPTION, $GLOBALS['gwcvt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

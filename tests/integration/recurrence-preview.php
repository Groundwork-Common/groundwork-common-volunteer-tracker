<?php
/**
 * The repeat preview: one rule, one route, and dates that do not move.
 *
 * ── Why this needs more than a unit test ─────────────────────────────────────
 * tests/RecurrenceTest.php already asserts the important thing — that the count
 * the preview promises is the count gwc_vt_recurrence_dates() would produce, so
 * the two cannot be separate implementations. Two things it cannot reach:
 *
 *   The route. gwc-vt/v1/recurrence-preview only exists inside a REST request,
 *   and the reason it exists at all is that the browser must not do this
 *   arithmetic itself. If the route stops answering, the box silently stops
 *   appearing and the form is back to reporting the count after the save.
 *
 *   The timezone. gwc_vt_recurrence_date_label() hands wp_date() a UTC
 *   timestamp AND a UTC timezone, because everything in inc/recurrence.php is a
 *   bare calendar date built in UTC and wp_date() would otherwise convert it
 *   into the site's zone. On a site behind UTC that lands the previous evening,
 *   and the box would name August 7 for a run that starts on the 8th. The unit
 *   suite cannot see this: its wp_date() stub is gmdate() and ignores the
 *   timezone argument entirely, so the bug and the fix look identical there.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/recurrence-preview.php
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
function gwc_vt_rp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── Borrow the site's date settings, and give them back ─────────────────────
 * Read what is there and restore exactly that, rather than writing a known set:
 * the same reasoning as tests/integration/letters-switch.php. Registered on
 * shutdown so it runs whether this finishes, fails or fatals; PHP runs shutdown
 * functions on exit(), so the exit( 1 ) at the foot is safe beside it.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_rp_before'] = array(
	'date_format'      => get_option( 'date_format' ),
	'timezone_string'  => get_option( 'timezone_string' ),
	'gmt_offset'       => get_option( 'gmt_offset' ),
	'user'             => get_current_user_id(),
);

/**
 * Put the site's date settings and the current user back.
 */
function gwc_vt_rp_restore(): void {
	if ( ! array_key_exists( 'gwc_vt_rp_before', $GLOBALS ) ) {
		return;
	}

	$before = $GLOBALS['gwc_vt_rp_before'];

	update_option( 'date_format', $before['date_format'] );
	update_option( 'timezone_string', $before['timezone_string'] );
	update_option( 'gmt_offset', $before['gmt_offset'] );
	wp_set_current_user( (int) $before['user'] );
}

register_shutdown_function( 'gwc_vt_rp_restore' );

/* Y-m-d while this runs, so a date can be asserted exactly rather than through
 * whatever the site happens to display. */
update_option( 'date_format', 'Y-m-d' );

/* ── The dates the box names are the dates the run creates ───────────────────
 * Checked in three zones: UTC, one well behind it and one well ahead. Behind is
 * where the bug was — a UTC midnight converted to Los Angeles is 5pm the day
 * before — but ahead is worth the two lines, because the same mistake made the
 * other way rounds a date forward.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_rp_start = '2026-08-08';
$gwc_vt_rp_until = '2026-12-19';

foreach ( array( 'UTC', 'America/Los_Angeles', 'Pacific/Auckland' ) as $gwc_vt_rp_zone ) {
	update_option( 'timezone_string', $gwc_vt_rp_zone );
	update_option( 'gmt_offset', '' );

	$gwc_vt_rp_run     = gwc_vt_recurrence_dates( $gwc_vt_rp_start, 'weekly', $gwc_vt_rp_until );
	$gwc_vt_rp_preview = gwc_vt_recurrence_preview( $gwc_vt_rp_start, 'weekly', $gwc_vt_rp_until );

	$gwc_vt_rp_first = (string) reset( $gwc_vt_rp_run['dates'] );
	$gwc_vt_rp_last  = (string) end( $gwc_vt_rp_run['dates'] );

	gwc_vt_rp_check(
		'in ' . $gwc_vt_rp_zone . ', the box names the first date the run creates',
		false !== strpos( $gwc_vt_rp_preview['detail'], $gwc_vt_rp_first ),
		$gwc_vt_rp_first . ' in: ' . $gwc_vt_rp_preview['detail']
	);

	gwc_vt_rp_check(
		'in ' . $gwc_vt_rp_zone . ', the box names the last date the run creates',
		false !== strpos( $gwc_vt_rp_preview['detail'], $gwc_vt_rp_last ),
		$gwc_vt_rp_last
	);

	gwc_vt_rp_check(
		'in ' . $gwc_vt_rp_zone . ', the count is the run’s own count',
		count( $gwc_vt_rp_run['dates'] ) === $gwc_vt_rp_preview['count'],
		count( $gwc_vt_rp_run['dates'] ) . ' vs ' . $gwc_vt_rp_preview['count']
	);
}

update_option( 'timezone_string', 'UTC' );

/* ── The route ───────────────────────────────────────────────────────────── */

$gwc_vt_rp_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( ! $gwc_vt_rp_admins ) {
	echo "FAIL  no administrator to run as\n\n1 FAILED\n";
	exit( 1 );
}

/* Anonymous first, while nobody is logged in. */
wp_set_current_user( 0 );

$gwc_vt_rp_request = new WP_REST_Request( 'GET', '/' . GWC_VT_REST_NAMESPACE . '/recurrence-preview' );
$gwc_vt_rp_request->set_param( 'start', $gwc_vt_rp_start );
$gwc_vt_rp_request->set_param( 'pattern', 'weekly' );
$gwc_vt_rp_request->set_param( 'until', $gwc_vt_rp_until );

$gwc_vt_rp_denied = rest_do_request( $gwc_vt_rp_request );

gwc_vt_rp_check(
	'a logged-out request is refused',
	$gwc_vt_rp_denied->is_error(),
	(string) $gwc_vt_rp_denied->get_status()
);

wp_set_current_user( (int) $gwc_vt_rp_admins[0] );

$gwc_vt_rp_response = rest_do_request( $gwc_vt_rp_request );
$gwc_vt_rp_data     = $gwc_vt_rp_response->get_data();

gwc_vt_rp_check(
	'the route answers somebody who can add a shift',
	200 === $gwc_vt_rp_response->get_status(),
	(string) $gwc_vt_rp_response->get_status()
);

gwc_vt_rp_check(
	'and answers with the same count the rule produces',
	is_array( $gwc_vt_rp_data )
		&& isset( $gwc_vt_rp_data['count'] )
		&& count( gwc_vt_recurrence_dates( $gwc_vt_rp_start, 'weekly', $gwc_vt_rp_until )['dates'] ) === $gwc_vt_rp_data['count'],
	isset( $gwc_vt_rp_data['count'] ) ? (string) $gwc_vt_rp_data['count'] : 'no count'
);

/* The keys the script reads. Asserted as a set rather than one at a time: the
 * script paints whatever it is given, so a key that quietly stops being sent is
 * a box that quietly loses a sentence. */
gwc_vt_rp_check(
	'the response carries every key the script paints',
	is_array( $gwc_vt_rp_data )
		&& array( 'capped', 'count', 'detail', 'headline', 'note', 'repeats', 'submit' )
			=== ( static function ( array $d ): array {
				$keys = array_keys( $d );
				sort( $keys );
				return $keys;
			} )( $gwc_vt_rp_data ),
	is_array( $gwc_vt_rp_data ) ? implode( ', ', array_keys( $gwc_vt_rp_data ) ) : 'not an array'
);

/* ── A bad pattern is an empty answer, not an error ──────────────────────────
 * The select cannot offer one, but a URL can. Nothing here should 500, and the
 * box should simply not appear.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_rp_junk = new WP_REST_Request( 'GET', '/' . GWC_VT_REST_NAMESPACE . '/recurrence-preview' );
$gwc_vt_rp_junk->set_param( 'start', 'not-a-date' );
$gwc_vt_rp_junk->set_param( 'pattern', 'every-third-tuesday' );
$gwc_vt_rp_junk->set_param( 'until', 'also-not-a-date' );

$gwc_vt_rp_junk_response = rest_do_request( $gwc_vt_rp_junk );
$gwc_vt_rp_junk_data     = $gwc_vt_rp_junk_response->get_data();

gwc_vt_rp_check(
	'nonsense is answered with nothing to show, not an error',
	200 === $gwc_vt_rp_junk_response->get_status()
		&& isset( $gwc_vt_rp_junk_data['repeats'] )
		&& false === $gwc_vt_rp_junk_data['repeats'],
	(string) $gwc_vt_rp_junk_response->get_status()
);

/* ── The capped sentence is the one the save would print ─────────────────── */

$gwc_vt_rp_capped = gwc_vt_recurrence_preview( '2026-08-08', 'weekly', '2030-01-01' );

gwc_vt_rp_check(
	'a horizon-capped run previews the sentence the notice uses',
	'horizon' === $gwc_vt_rp_capped['capped']
		&& gwc_vt_recurrence_capped_note( 'horizon' ) === $gwc_vt_rp_capped['note']
		&& '' !== $gwc_vt_rp_capped['note'],
	$gwc_vt_rp_capped['note']
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

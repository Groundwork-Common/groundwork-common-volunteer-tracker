<?php
/**
 * Dates that render as the dates they were stored as.
 *
 * ── Why this needs a database, and a timezone ────────────────────────────────
 * Two defects, one shape: a guarded helper existed and a caller formatted the
 * value itself instead.
 *
 * gwc_vt_event_date_label() built its instant at midnight UTC — that is what
 * gwc_vt_recurrence_date() returns — and then rendered it with wp_date(), which
 * moves it into the site's zone. On America/New_York, which is to say on every
 * site in the Americas, an event stored as 2026-10-12 printed as 11 October: on
 * the public grid, in the confirmation mail and in the reminder mail.
 *
 * The event roster's "signed up" cell reimplemented gwc_vt_local_date() without
 * its guard, so an unparseable GWC_VT_SIGNUP_CREATED became (int) false, became
 * a timestamp of 0, and printed 1 January 1970 — or, west of UTC and so under
 * this script, 31 December 1969. The shift roster called the helper but tested
 * the raw meta for emptiness, so the same value rendered a blank cell there.
 *
 * Neither could be caught by the unit suite: tests/bootstrap.php stubs
 * wp_timezone() to UTC and wp_date() to gmdate(), so both spellings agree there
 * and always will. The bug only exists where a real site's timezone does.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/event-dates.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a top-level
 * assignment is a local while `global` in a helper reaches the real one. The
 * counter increments one and the summary reads the other, and the script prints
 * ALL PASS under a list of failures. See the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_dt_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── Borrow the site's clock, and give it back ───────────────────────────────
 * Read the three options this script moves and restore exactly those, rather
 * than writing a known set: the same reasoning as the settings restore in
 * tests/integration/event-screens.php. The restore is registered on shutdown so
 * it runs whether this finishes, fails an assertion or fatals — and PHP runs
 * shutdown functions on exit(), so the exit( 1 ) at the foot is safe beside it.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_clock_before'] = array(
	'timezone_string' => get_option( 'timezone_string' ),
	'gmt_offset'      => get_option( 'gmt_offset' ),
	'date_format'     => get_option( 'date_format' ),
);

/**
 * Put the site's timezone and date format back exactly as they were found.
 */
function gwc_vt_restore_clock(): void {
	if ( ! array_key_exists( 'gwc_vt_clock_before', $GLOBALS ) ) {
		return;
	}

	foreach ( $GLOBALS['gwc_vt_clock_before'] as $gwc_vt_name => $gwc_vt_value ) {
		if ( false === $gwc_vt_value ) {
			delete_option( $gwc_vt_name );
		} else {
			update_option( $gwc_vt_name, $gwc_vt_value );
		}
	}

	unset( $GLOBALS['gwc_vt_clock_before'] );
}

register_shutdown_function( 'gwc_vt_restore_clock' );

/* West of UTC, which is where the bug lives. gmt_offset is cleared alongside it
 * because wp_timezone_string() prefers timezone_string and only falls back to
 * the offset — leaving a stale offset behind would not change this run, but it
 * would leave the site in a state neither option agrees about. Y-m-d as the
 * format so an assertion can name the day rather than describe it. */
update_option( 'timezone_string', 'America/New_York' );
update_option( 'gmt_offset', '' );
update_option( 'date_format', 'Y-m-d' );

gwc_vt_dt_check(
	'the site really is on America/New_York',
	'America/New_York' === (string) wp_timezone_string(),
	(string) wp_timezone_string()
);

/* ── An event's label names the day it was stored as ─────────────────────── */

$gwc_vt_event = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Harvest Day',
	)
);

update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_DATE, '2026-10-12' );

gwc_vt_dt_check(
	'a one-day event names its own day, not the day before',
	'2026-10-12' === gwc_vt_event_date_label( (int) $gwc_vt_event ),
	gwc_vt_event_date_label( (int) $gwc_vt_event )
);

/* A New Year's Day event is the case that crosses a month and a year at once:
 * the old spelling printed 2026-12-31 for it. */
update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_DATE, '2027-01-01' );

gwc_vt_dt_check(
	'and does not slip back over a month and a year boundary',
	'2027-01-01' === gwc_vt_event_date_label( (int) $gwc_vt_event ),
	gwc_vt_event_date_label( (int) $gwc_vt_event )
);

/* ── A span names both ends ──────────────────────────────────────────────── */

update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_DATE, '2026-10-12' );
update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_END_DATE, '2026-10-14' );

gwc_vt_dt_check(
	'a multi-day event names both of its own days',
	'2026-10-12 – 2026-10-14' === gwc_vt_event_date_label( (int) $gwc_vt_event ),
	gwc_vt_event_date_label( (int) $gwc_vt_event )
);

/* ── The validity check still refuses what it always refused ─────────────── */

update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_DATE, '2026-02-30' );
delete_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_END_DATE );

gwc_vt_dt_check(
	'a date that is not a date is still refused, not printed',
	'' === gwc_vt_event_date_label( (int) $gwc_vt_event ),
	gwc_vt_event_date_label( (int) $gwc_vt_event )
);

/* An unreadable end date falls back to the start rather than losing both — the
 * behavior the null check has always had, asserted so the fallback is not
 * quietly dropped by a later tidy-up. */
update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_DATE, '2026-10-12' );
update_post_meta( (int) $gwc_vt_event, GWC_VT_EVENT_END_DATE, 'not a date' );

gwc_vt_dt_check(
	'an unreadable end date leaves the start standing alone',
	'2026-10-12' === gwc_vt_event_date_label( (int) $gwc_vt_event ),
	gwc_vt_event_date_label( (int) $gwc_vt_event )
);

/* ── Which clock a time is on ────────────────────────────────────────────────
 * Every public time used to print a bare wall-clock reading with no zone, while
 * the calendar file beside it carried a UTC instant that a calendar app will
 * convert. Two numbers for one shift and nothing saying which was which.
 *
 * The interesting case is a list that spans a daylight-saving change: it has two
 * honest answers, so a single "all times are EDT" line at the top would be
 * stating something false over the December rows. This site is on
 * America/New_York for the whole script, so both sides of the change are
 * reachable.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_summer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest summer shift',
	)
);

update_post_meta( (int) $gwc_vt_summer, GWC_VT_SHIFT_DATE, '2026-08-26' );
update_post_meta( (int) $gwc_vt_summer, GWC_VT_SHIFT_START, '13:00' );
update_post_meta( (int) $gwc_vt_summer, GWC_VT_SHIFT_END, '16:00' );

$gwc_vt_winter = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest winter shift',
	)
);

update_post_meta( (int) $gwc_vt_winter, GWC_VT_SHIFT_DATE, '2026-12-05' );
update_post_meta( (int) $gwc_vt_winter, GWC_VT_SHIFT_START, '09:00' );
update_post_meta( (int) $gwc_vt_winter, GWC_VT_SHIFT_END, '12:00' );

gwc_vt_dt_check(
	'a summer shift is on daylight time',
	'EDT' === gwc_vt_shift_timezone_label( (int) $gwc_vt_summer ),
	gwc_vt_shift_timezone_label( (int) $gwc_vt_summer )
);

gwc_vt_dt_check(
	'and a winter one is not',
	'EST' === gwc_vt_shift_timezone_label( (int) $gwc_vt_winter ),
	gwc_vt_shift_timezone_label( (int) $gwc_vt_winter )
);

gwc_vt_dt_check(
	'a list on one side of the change shares a label, so it is said once',
	'EDT' === gwc_vt_shared_timezone_label( array( (int) $gwc_vt_summer ) ),
	gwc_vt_shared_timezone_label( array( (int) $gwc_vt_summer ) )
);

gwc_vt_dt_check(
	'a list spanning it shares none, so every row must say its own',
	'' === gwc_vt_shared_timezone_label( array( (int) $gwc_vt_summer, (int) $gwc_vt_winter ) ),
	gwc_vt_shared_timezone_label( array( (int) $gwc_vt_summer, (int) $gwc_vt_winter ) )
);

/* The message a volunteer actually receives, which is where the ambiguity was. */
gwc_vt_dt_check(
	'the line quoted back in mail names the clock',
	false !== strpos( gwc_vt_shift_one_line( (int) $gwc_vt_winter ), 'EST' ),
	gwc_vt_shift_one_line( (int) $gwc_vt_winter )
);

/* A shift with no readable start has no clock to name, and says nothing rather
 * than guessing at the site's current one. */
$gwc_vt_undated = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest undated shift',
	)
);

gwc_vt_dt_check(
	'a shift with no readable start names no zone at all',
	'' === gwc_vt_shift_timezone_label( (int) $gwc_vt_undated ),
	gwc_vt_shift_timezone_label( (int) $gwc_vt_undated )
);

$GLOBALS['gwc_vt_dt_shifts'] = array( (int) $gwc_vt_summer, (int) $gwc_vt_winter, (int) $gwc_vt_undated );

/* ── The rosters, and the epoch ──────────────────────────────────────────────
 * Both row renderers echo, so the assertion is on captured output. What is being
 * checked is narrow on purpose: that an unparseable stored date produces neither
 * an epoch nor a blank, on both screens. The rest of the markup is not this
 * script's business — the two renderers have forked in ways that are somebody
 * else's issue, and asserting on the rest of the row here would fail on the fix
 * for that rather than on a reintroduction of this.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_signup = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SIGNUP_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest signup',
	)
);

update_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_NAME, 'Zzytest Dana' );
update_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_EMAIL, 'zzytest-dana@example.test' );

/**
 * One roster row's markup, whichever screen draws it.
 *
 * @param string $screen    'event' or 'shift'.
 * @param int    $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_dt_row( string $screen, int $signup_id ): string {
	ob_start();

	if ( 'event' === $screen ) {
		gwc_vt_render_event_roster_row( $signup_id, 'Signed up', false );
	} else {
		gwc_vt_render_roster_row( $signup_id, 'Signed up', false );
	}

	return (string) ob_get_clean();
}

foreach ( array( 'event', 'shift' ) as $gwc_vt_screen ) {

	/* A readable date renders as itself on both screens. */
	update_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CREATED, '2026-08-14 15:04:00' );

	$gwc_vt_row = gwc_vt_dt_row( $gwc_vt_screen, (int) $gwc_vt_signup );

	gwc_vt_dt_check(
		$gwc_vt_screen . ' roster: a readable signup date renders as itself',
		false !== strpos( $gwc_vt_row, '2026-08-14' ),
		$gwc_vt_screen
	);

	/* Non-empty and unparseable: the value that reached the epoch. */
	update_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CREATED, 'redacted' );

	$gwc_vt_row = gwc_vt_dt_row( $gwc_vt_screen, (int) $gwc_vt_signup );

	/* Both years, because the epoch is subject to the same day-boundary hazard as
	 * the labels above: timestamp 0 is 1 January 1970 in UTC and 31 December
	 * 1969 in New York, and this script deliberately runs west of UTC. Checking
	 * '1970' alone passed against the unfixed code — the cell was rendering
	 * 1969-12-31. */
	gwc_vt_dt_check(
		$gwc_vt_screen . ' roster: an unreadable signup date is not the epoch',
		false === strpos( $gwc_vt_row, '1970' ) && false === strpos( $gwc_vt_row, '1969' ),
		$gwc_vt_screen
	);

	gwc_vt_dt_check(
		$gwc_vt_screen . ' roster: an unreadable signup date gets the em-dash',
		false !== strpos( $gwc_vt_row, '—' ),
		$gwc_vt_screen
	);

	/* Empty, which is the branch that always worked, asserted so the rewrite of
	 * the test it hangs off did not move it. */
	delete_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CREATED );

	$gwc_vt_row = gwc_vt_dt_row( $gwc_vt_screen, (int) $gwc_vt_signup );

	gwc_vt_dt_check(
		$gwc_vt_screen . ' roster: no signup date at all still gets the em-dash',
		false !== strpos( $gwc_vt_row, '—' ),
		$gwc_vt_screen
	);
}

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( array_merge( array( $gwc_vt_event, $gwc_vt_signup ), (array) ( $GLOBALS['gwc_vt_dt_shifts'] ?? array() ) ) as $gwc_vt_id ) {
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

<?php
/**
 * Every worklist line shows what it counted.
 *
 * ── The rule ────────────────────────────────────────────────────────────────
 * CLAUDE.md: where a screen acts on a count, it filters by the same function
 * that produced it. Two lines could not, and #90 left them alone rather than
 * shipping a link that lies.
 *
 * `gwc_vt_unreconciled_shift_ids()` and `gwc_vt_understaffed_shift_ids()` both
 * count an event's times INDIVIDUALLY, because the daily digest is built on
 * them and an event's Saturday morning being short of people is exactly what
 * that email exists to mention. The schedule list collapsed events to one row
 * and looked over a different stretch of calendar — 120 days back against the
 * counter's 180, 400 days ahead against its 7.
 *
 * So the number said slots over a screen drawing events, across a window that
 * did not match. "The count said five and every list showed none."
 *
 * ── Why this needs a database ───────────────────────────────────────────────
 * Both halves are queries. The count is a meta query over post dates; the rows
 * are the same query with different arguments, plus the event merge, plus the
 * state derivation that reads each shift's roster. Nothing about the mismatch
 * is visible without real posts.
 *
 * It follows the URL rather than re-deriving it: gwc_vt_dashboard_item_url()
 * builds the link, this parses it into $_GET and calls
 * gwc_vt_schedule_list_state(), which is the function the renderer calls. A
 * test that rebuilt the query here would prove only that it agrees with itself.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/worklist-links.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a
 * top-level assignment is a local while `global` in a helper reaches the real
 * one. See the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_wl_made']  = array();
$GLOBALS['gwc_vt_wl_get']   = $_GET;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_wl_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Make the request look like somebody clicked a worklist line.
 *
 * @param string $url The URL gwc_vt_dashboard_item_url() built.
 */
function gwc_vt_wl_visit( string $url ): void {
	$query = array();
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

	$_GET = $query;
}

/**
 * A shift, with a roster if asked for.
 *
 * @param string $date   Y-m-d.
 * @param int    $parent Event post ID, or 0.
 * @param int    $people How many to sign up.
 * @param int    $min    Minimum wanted.
 * @return int
 */
function gwc_vt_wl_shift( string $date, int $parent = 0, int $people = 0, int $min = 0 ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_parent' => $parent,
			/* Titled with the marker rather than 'tmp'. A run that dies before
			 * its cleanup leaves orphans, and orphans whose title says nothing
			 * cannot be found afterwards — the first run of this script fataled
			 * on a misremembered function name and left six shifts that took a
			 * meta query to identify. */
			'post_title'  => 'Zzwl work',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzwl work' );

	if ( $min > 0 ) {
		update_post_meta( $id, GWC_VT_SHIFT_MIN, $min );
		update_post_meta( $id, GWC_VT_SHIFT_MAX, $min + 4 );
	}

	for ( $n = 0; $n < $people; $n++ ) {
		gwc_vt_add_signup(
			$id,
			array(
				'claim_name'  => 'Zzwl Person ' . $n,
				'claim_email' => 'zzwl' . $n . '@example.com',
				'source'      => 'staff',
			)
		);
	}

	$GLOBALS['gwc_vt_wl_made'][] = $id;

	return (int) $id;
}

/* ── The fixture ─────────────────────────────────────────────────────────────
 * An event with two times, both in the past with people on them and neither
 * written up, and both far enough back that the schedule's own 120-day default
 * would have missed them. Plus one standalone shift of each kind, so a passing
 * run is not one where the event slots simply happen to fall outside.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_wl_event'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzwl Harvest weekend',
	)
);

$GLOBALS['gwc_vt_wl_made'][] = $GLOBALS['gwc_vt_wl_event'];

/* 150 days back: inside the counter's 180 and outside the list's old 120. */
$GLOBALS['gwc_vt_wl_old'] = gmdate( 'Y-m-d', time() - ( 150 * DAY_IN_SECONDS ) );

gwc_vt_wl_shift( $GLOBALS['gwc_vt_wl_old'], (int) $GLOBALS['gwc_vt_wl_event'], 2 );
gwc_vt_wl_shift( $GLOBALS['gwc_vt_wl_old'], (int) $GLOBALS['gwc_vt_wl_event'], 3 );
gwc_vt_wl_shift( gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ), 0, 2 );

/* Short of people: one event time and one standalone, both this week. */
gwc_vt_wl_shift( gmdate( 'Y-m-d', time() + ( 2 * DAY_IN_SECONDS ) ), (int) $GLOBALS['gwc_vt_wl_event'], 1, 6 );
gwc_vt_wl_shift( gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ), 0, 1, 6 );

/* Short, but months out — inside the schedule's 400-day reach and outside the
 * seven days the line counts. The window half of the bug lives here. */
gwc_vt_wl_shift( gmdate( 'Y-m-d', time() + ( 200 * DAY_IN_SECONDS ) ), 0, 1, 6 );

gwc_vt_event_refresh_dates( (int) $GLOBALS['gwc_vt_wl_event'] );

/* ── The two lines that could not land pre-filtered ──────────────────────── */

foreach ( array( 'unreconciled' => 'awaiting', 'understaffed' => 'short' ) as $gwc_vt_wl_key => $gwc_vt_wl_state ) {
	$gwc_vt_wl_counted = 'unreconciled' === $gwc_vt_wl_key
		? count( gwc_vt_unreconciled_shift_ids( 200 ) )
		: count( gwc_vt_understaffed_shift_ids() );

	gwc_vt_wl_visit( gwc_vt_dashboard_item_url( $gwc_vt_wl_key ) );

	$gwc_vt_wl_drawn = gwc_vt_schedule_list_state();
	$gwc_vt_wl_rows  = count( $gwc_vt_wl_drawn['rows'] );

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': the screen shows exactly what the line counted',
		$gwc_vt_wl_counted === $gwc_vt_wl_rows,
		'counted ' . $gwc_vt_wl_counted . ', drew ' . $gwc_vt_wl_rows
	);

	/* And it counted something. Zero equals zero is the way this assertion
	 * passes on a fixture that never built anything. */
	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': and there was something to count',
		$gwc_vt_wl_counted > 1,
		'the line counted ' . $gwc_vt_wl_counted
	);

	/* Every row is the state the line is about — not merely the right number of
	 * rows, which two unrelated mistakes could produce between them. */
	$gwc_vt_wl_wrong = 0;

	foreach ( $gwc_vt_wl_drawn['rows'] as $gwc_vt_wl_row ) {
		if ( ( $gwc_vt_wl_row['state'] ?? '' ) !== $gwc_vt_wl_state ) {
			++$gwc_vt_wl_wrong;
		}
	}

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': and every row drawn is in that state',
		0 === $gwc_vt_wl_wrong,
		$gwc_vt_wl_wrong . ' row(s) were some other state'
	);

	/* The screen itself renders. gwc_vt_schedule_list_state() is what the two
	 * assertions above call, and calling only that is how the first version of
	 * this test passed while the actual page was a fatal error: splitting the
	 * row selection out of the renderer left $term behind in the markup, and
	 * nothing here went near the markup. Warnings are promoted so an undefined
	 * variable fails rather than printing into the buffer. */
	set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a test asserting the screen renders clean.
		static function ( int $no, string $message ): bool {
			throw new RuntimeException( $message );
		},
		E_ALL
	);

	$gwc_vt_wl_html  = '';
	$gwc_vt_wl_threw = '';

	try {
		ob_start();
		gwc_vt_render_schedule_list();
		$gwc_vt_wl_html = (string) ob_get_clean();
	} catch ( Throwable $gwc_vt_wl_e ) {
		ob_end_clean();
		$gwc_vt_wl_threw = $gwc_vt_wl_e->getMessage();
	}

	restore_error_handler();

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': and the screen renders without a warning',
		'' === $gwc_vt_wl_threw,
		$gwc_vt_wl_threw
	);

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': and says it has been narrowed, with a way back',
		false !== strpos( $gwc_vt_wl_html, 'Narrowed to match the number you came from' )
			&& false !== strpos( $gwc_vt_wl_html, 'Show the whole schedule' ),
		'the narrowing notice was not drawn'
	);

	/* An event's times, one by one. The whole mismatch was that the counter
	 * counts these and the screen used to draw their parent instead. */
	$gwc_vt_wl_slots = 0;

	foreach ( $gwc_vt_wl_drawn['rows'] as $gwc_vt_wl_row ) {
		if ( (int) wp_get_post_parent_id( (int) ( $gwc_vt_wl_row['id'] ?? 0 ) ) === (int) $GLOBALS['gwc_vt_wl_event'] ) {
			++$gwc_vt_wl_slots;
		}
	}

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': and an event\'s times are rows of their own',
		$gwc_vt_wl_slots > 0,
		'no event slot was drawn as its own row'
	);
}

/* ── The three that already agreed, so they keep agreeing ─────────────────── */

foreach ( array( 'overdue', 'offers', 'lapsed', 'unverified', 'unmatched' ) as $gwc_vt_wl_key ) {
	$gwc_vt_wl_url = gwc_vt_dashboard_item_url( $gwc_vt_wl_key );

	$gwc_vt_wl_expected = array(
		'overdue'    => 'gwc_vt_requirement=overdue',
		/* The offers screen IS the pending list, so there is no filter to
		 * check — landing on it is the whole claim. */
		'offers'     => 'page=gwc-vt-offers',
		'lapsed'     => 'gwc_vt_credential=lapsed',
		'unverified' => 'page=gwc-vt-verify',
		'unmatched'  => 'page=gwc-vt-verify',
	);

	gwc_vt_wl_check(
		$gwc_vt_wl_key . ': still lands somewhere that filters',
		false !== strpos( $gwc_vt_wl_url, $gwc_vt_wl_expected[ $gwc_vt_wl_key ] ),
		$gwc_vt_wl_url
	);
}

/* ── The default is untouched ────────────────────────────────────────────────
 * The collapsing is an opt-OUT. If arriving without the argument started
 * showing slots, the fix would have replaced one wrong screen with another.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_wl_visit( gwc_vt_schedule_url() );

$gwc_vt_wl_plain = gwc_vt_schedule_list_state();
$gwc_vt_wl_bare  = 0;

foreach ( $gwc_vt_wl_plain['rows'] as $gwc_vt_wl_row ) {
	if ( (int) wp_get_post_parent_id( (int) ( $gwc_vt_wl_row['id'] ?? 0 ) ) === (int) $GLOBALS['gwc_vt_wl_event'] ) {
		++$gwc_vt_wl_bare;
	}
}

gwc_vt_wl_check(
	'the plain schedule still collapses an event to one row',
	0 === $gwc_vt_wl_bare && ! $gwc_vt_wl_plain['slots'],
	$gwc_vt_wl_bare . ' event slot(s) leaked into the default view'
);

/* And it still reaches past the seven days the short-of-people line asks for,
 * so the window narrowing is the link's doing and not the screen's. */
gwc_vt_wl_check(
	'and still reaches beyond a narrowed window',
	0 === $gwc_vt_wl_plain['within'],
	'the default view came back narrowed to ' . $gwc_vt_wl_plain['within'] . ' days'
);

/* A hostile number in the URL is capped rather than trusted. */
gwc_vt_wl_visit( gwc_vt_schedule_url( array( 'gwc_vt_within' => 99999 ) ) );

gwc_vt_wl_check(
	'an absurd window is capped',
	400 === gwc_vt_schedule_within(),
	'it accepted ' . gwc_vt_schedule_within()
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_wl_made'] as $gwc_vt_wl_id ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_wl_id, array( 'publish', 'draft', GWC_VT_SIGNUP_WAITLIST ) ) as $gwc_vt_wl_signup ) {
		wp_delete_post( (int) $gwc_vt_wl_signup, true );
	}

	wp_delete_post( (int) $gwc_vt_wl_id, true );
}

$_GET = $GLOBALS['gwc_vt_wl_get'];

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

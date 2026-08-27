<?php
/**
 * The drawer's panel, and the route that serves it.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * The panel is a roster, which is names, and the route that returns it is the
 * only one in this plugin that discloses anything. Four things are worth
 * pinning, and none of them is reachable without real posts:
 *
 *   The gate. edit_posts, checked by running the route rather than by reading
 *   the callback.
 *
 *   The type check. Any post ID would otherwise reach a renderer that reads
 *   shift meta off it and prints whatever it finds — an entry, a volunteer, a
 *   page somebody wrote.
 *
 *   The escaping. assets/js/admin-shift-drawer.js puts this response through
 *   innerHTML. That is safe exactly as long as the renderer escapes, so this
 *   asserts it does rather than trusting that it always will: a shift whose
 *   activity is a <script> tag has to come back inert.
 *
 *   The nonce. The panel carries a roster-add form, and a form with no nonce is
 *   a form the handler will refuse — silently, from the user's point of view,
 *   because they will just be told to try again.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/shift-panel.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL — see the note in tests/integration/entries.php,
 * and the one in tests/integration/schedule-month.php, which was written by
 * somebody who had just read this one and walked into it anyway. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_sp_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Delete everything this script created, and put the user back.
 */
function gwc_vt_sp_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_sp_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}

	wp_set_current_user( (int) ( $GLOBALS['gwc_vt_sp_user'] ?? 0 ) );
}

register_shutdown_function( 'gwc_vt_sp_cleanup' );

$GLOBALS['gwc_vt_sp_user'] = get_current_user_id();

/* ── Fixtures ────────────────────────────────────────────────────────────── */

$gwc_vt_sp_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( ! $gwc_vt_sp_admins ) {
	echo "FAIL  no administrator to run as\n\n1 FAILED\n";
	exit( 1 );
}

/* A name with a tag in it, because that is the value this whole arrangement
 * rests on being escaped. Staff type volunteer names, and — for a self-logged
 * entry — so does the public. */
$GLOBALS['gwc_vt_sp_volunteer'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest <script>alert(1)</script> Ramanathan',
	)
);

$GLOBALS['gwc_vt_sp_made'][] = (int) $GLOBALS['gwc_vt_sp_volunteer'];

$GLOBALS['gwc_vt_sp_shift'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'tmp',
	)
);

$GLOBALS['gwc_vt_sp_made'][] = (int) $GLOBALS['gwc_vt_sp_shift'];

update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', time() + ( 6 * DAY_IN_SECONDS ) ) );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_START, '09:00' );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_END, '12:00' );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_ACTIVITY, 'Zzytest <b>sorting</b> donations' );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_LOCATION, 'Zzytest depot' );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_MIN, 6 );
update_post_meta( (int) $GLOBALS['gwc_vt_sp_shift'], GWC_VT_SHIFT_MAX, 8 );
gwc_vt_retitle_shift( (int) $GLOBALS['gwc_vt_sp_shift'] );

$gwc_vt_sp_signup = wp_insert_post(
	array(
		'post_type'   => GWC_VT_SIGNUP_TYPE,
		'post_status' => 'publish',
		'post_parent' => (int) $GLOBALS['gwc_vt_sp_shift'],
		'post_title'  => 'zzytest signup',
	)
);

$GLOBALS['gwc_vt_sp_made'][] = (int) $gwc_vt_sp_signup;
update_post_meta( (int) $gwc_vt_sp_signup, GWC_VT_SIGNUP_VOLUNTEER, (int) $GLOBALS['gwc_vt_sp_volunteer'] );

/**
 * Ask the route for a panel.
 *
 * @param int    $shift_id What to ask about.
 * @param string $back     Which view a roster add should return to.
 * @param string $month    The month the calendar was on.
 * @return WP_REST_Response
 */
function gwc_vt_sp_ask( int $shift_id, string $back = '', string $month = '' ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/' . GWC_VT_REST_NAMESPACE . '/shift-panel' );
	$request->set_param( 'shift', $shift_id );
	$request->set_param( 'back', $back );
	$request->set_param( 'month', $month );

	return rest_ensure_response( rest_do_request( $request ) );
}

/* ── The gate ────────────────────────────────────────────────────────────── */

wp_set_current_user( 0 );

$gwc_vt_sp_denied = gwc_vt_sp_ask( (int) $GLOBALS['gwc_vt_sp_shift'] );

gwc_vt_sp_check(
	'a logged-out request is refused',
	$gwc_vt_sp_denied->is_error(),
	(string) $gwc_vt_sp_denied->get_status()
);

wp_set_current_user( (int) $gwc_vt_sp_admins[0] );

$gwc_vt_sp_response = gwc_vt_sp_ask( (int) $GLOBALS['gwc_vt_sp_shift'], 'month', '2026-08' );
$gwc_vt_sp_data     = $gwc_vt_sp_response->get_data();
$gwc_vt_sp_html     = (string) ( $gwc_vt_sp_data['html'] ?? '' );

gwc_vt_sp_check(
	'somebody who can open the schedule gets a panel',
	200 === $gwc_vt_sp_response->get_status() && '' !== $gwc_vt_sp_html,
	(string) $gwc_vt_sp_response->get_status()
);

/* ── It is a shift, or it is nothing ────────────────────────────────────────
 * Without the type check, any post ID reaches a renderer that reads shift meta
 * off it and prints what it finds.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sp_not_a_shift = gwc_vt_sp_ask( (int) $GLOBALS['gwc_vt_sp_volunteer'] );

gwc_vt_sp_check(
	'a volunteer’s ID is not a shift',
	404 === $gwc_vt_sp_not_a_shift->get_status(),
	(string) $gwc_vt_sp_not_a_shift->get_status()
);

gwc_vt_sp_check(
	'and neither is an ID that is nothing at all',
	404 === gwc_vt_sp_ask( 99999999 )->get_status()
);

/* ── The escaping the drawer's innerHTML rests on ──────────────────────────── */

gwc_vt_sp_check(
	'the activity is escaped, not rendered',
	false === strpos( $gwc_vt_sp_html, '<b>sorting</b>' )
		&& false !== strpos( $gwc_vt_sp_html, '&lt;b&gt;sorting&lt;/b&gt;' ),
	false !== strpos( $gwc_vt_sp_html, '<b>sorting</b>' ) ? 'raw markup came back' : 'escaped'
);

gwc_vt_sp_check(
	'a volunteer’s name cannot carry a script tag through',
	false === strpos( $gwc_vt_sp_html, '<script>' ),
	false !== strpos( $gwc_vt_sp_html, '<script>' ) ? 'a script tag came back' : 'no script tag'
);

/* ── What the panel is for ───────────────────────────────────────────────── */

gwc_vt_sp_check(
	'the roster is in it',
	false !== strpos( $gwc_vt_sp_html, 'Ramanathan' )
);

gwc_vt_sp_check(
	'so is where the shift is',
	false !== strpos( $gwc_vt_sp_html, 'Zzytest depot' )
);

gwc_vt_sp_check(
	'and how full it is, in words',
	false !== strpos( $gwc_vt_sp_html, gwc_vt_shift_fill_summary( (int) $GLOBALS['gwc_vt_sp_shift'] ) ),
	gwc_vt_shift_fill_summary( (int) $GLOBALS['gwc_vt_sp_shift'] )
);

/* ── The form works when it gets there ───────────────────────────────────── */

gwc_vt_sp_check(
	'the roster-add form carries a nonce',
	false !== strpos( $gwc_vt_sp_html, 'name="_wpnonce"' )
);

gwc_vt_sp_check(
	'and posts to the handler the shift editor posts to',
	false !== strpos( $gwc_vt_sp_html, 'value="gwc_vt_roster_add"' )
);

gwc_vt_sp_check(
	'it carries where to come back to',
	false !== strpos( $gwc_vt_sp_html, 'name="gwc_vt_back" value="month"' )
		&& false !== strpos( $gwc_vt_sp_html, 'name="gwc_vt_back_month" value="2026-08"' )
);

/* A return value the route does not recognise is dropped rather than passed
 * through to a redirect. */
$gwc_vt_sp_junk = (string) ( gwc_vt_sp_ask( (int) $GLOBALS['gwc_vt_sp_shift'], 'https://example.test/evil', 'not-a-month' )->get_data()['html'] ?? '' );

gwc_vt_sp_check(
	'a return value it does not recognise is dropped',
	false === strpos( $gwc_vt_sp_junk, 'gwc_vt_back' ),
	false !== strpos( $gwc_vt_sp_junk, 'gwc_vt_back' ) ? 'it came through' : 'dropped'
);

gwc_vt_sp_check(
	'and so is a month it cannot read',
	false === strpos( $gwc_vt_sp_junk, 'not-a-month' )
);

/* ── A called-off shift is not offered the call-off link ─────────────────── */

wp_update_post(
	array(
		'ID'          => (int) $GLOBALS['gwc_vt_sp_shift'],
		'post_status' => GWC_VT_SHIFT_CANCELLED,
	)
);

$gwc_vt_sp_off = (string) ( gwc_vt_sp_ask( (int) $GLOBALS['gwc_vt_sp_shift'] )->get_data()['html'] ?? '' );

gwc_vt_sp_check(
	'a called-off shift is not offered calling it off again',
	false === strpos( $gwc_vt_sp_off, 'gwcvt-drawer__danger' )
);

gwc_vt_sp_check(
	'nor a box for putting somebody on it',
	false === strpos( $gwc_vt_sp_off, 'gwcvt-drawer__add' )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

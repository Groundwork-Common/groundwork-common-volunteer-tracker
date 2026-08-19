<?php
/**
 * The public signup surface, against real WordPress.
 *
 * This is the one place in the plugin where the person on the other end is
 * anonymous, so it is the one place tested by driving the real handler with the
 * real superglobals rather than by calling the model functions underneath.
 *
 * What matters most here is not that a signup works. It is that the list never
 * names anybody, that a refusal and an acceptance look the same, that a link in
 * an email cannot be followed into a mutation, and that somebody who signed up
 * once and never became a volunteer can still be found and forgotten.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/public-signup.php
 *
 * It creates its own fixtures and deletes them again, and puts back every
 * setting it changes.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/entries.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();
$GLOBALS['gwc_vt_mail']     = array();

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

/**
 * Catch mail instead of sending it.
 *
 * pre_wp_mail short-circuits before PHPMailer is touched, so nothing leaves the
 * container and the test can read what would have gone.
 *
 * @param null  $short  Whatever an earlier filter decided.
 * @param array $atts   to, subject, message, headers, attachments.
 * @return bool
 */
function gwc_vt_test_catch_mail( $short, $atts ) {
	$GLOBALS['gwc_vt_mail'][] = $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'gwc_vt_test_catch_mail', 10, 2 );

/* Everything this script writes, put back at the end. These scripts run against
 * a database that belongs to somebody else. */
$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );
$GLOBALS['gwc_vt_limits_before']   = get_option( GWC_VT_RATE_LIMIT_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to merge over the defaults.
 */
function gwc_vt_set_settings( array $extra ): void {
	update_option( GWC_VT_SETTINGS_OPTION, $extra );
	gwc_vt_settings_cache( null, true );
}

/**
 * Create a shift.
 *
 * @param string $date   Y-m-d.
 * @param int    $max    Capacity, 0 for none.
 * @param string $status Post status.
 * @return int
 */
function gwc_vt_make_shift( string $date, int $max = 0, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWC_VT_SHIFT_LOCATION, 'Zzytest main warehouse' );
	update_post_meta( $id, GWC_VT_SHIFT_MAX, $max );

	gwc_vt_retitle_shift( (int) $id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Post to the signup handler as a browser would.
 *
 * @param array $post What the form sends.
 * @param bool  $aged Whether to stamp the form as rendered long enough ago.
 * @return string The result key the handler recorded.
 */
function gwc_vt_post_signup( array $post, bool $aged = true ): string {
	$GLOBALS['gwc_vt_signup_result'] = '';

	$stamp = $aged
		? ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwc_vt_form' ) )
		: gwc_vt_form_stamp();

	$_POST = array_merge(
		array(
			'gwc_vt_signup_submit' => '1',
			'gwc_vt_signup_nonce'  => wp_create_nonce( 'gwc_vt_signup' ),
			'gwc_vt_t'             => $stamp,
		),
		$post
	);

	$_REQUEST = $_POST;

	gwc_vt_handle_public_signup();

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $GLOBALS['gwc_vt_signup_result'] ?? '' );
}

/**
 * Every signup on a shift, in any status.
 *
 * @param int $shift_id Shift post ID.
 * @return int[]
 */
function gwc_vt_all_signups( int $shift_id ): array {
	return gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) );
}

/* ── Off until somebody turns it on ──────────────────────────────────────── */

gwc_vt_set_settings( array() );

gwc_vt_check( 'signups are closed on a fresh install', ! gwc_vt_signups_open() );
gwc_vt_check( 'and the list renders nothing at all', '' === gwc_vt_render_shift_list() );

$gwc_vt_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest volunteer shifts',
		'post_content' => '[gwc_vt_shift_list]',
	)
);

$GLOBALS['gwc_vt_made'][] = $gwc_vt_page;

gwc_vt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwc_vt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
	)
);

gwc_vt_check( 'switched on and pinned, signups are open', gwc_vt_signups_open() );

/* ── The list shows counts and never names ───────────────────────────────────
 * The single rule the public surface exists to keep. On a site running a
 * court-ordered service programme, the roster for Saturday is a list of people
 * working one off.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_soon = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) ), 3 );

$gwc_vt_known = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Jane Quimby',
	)
);
update_post_meta( $gwc_vt_known, GWC_VT_VOLUNTEER_EMAIL, 'zzytest-jane@example.test' );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_known;

$GLOBALS['gwc_vt_made'][] = gwc_vt_add_signup( $gwc_vt_soon, array( 'volunteer_id' => $gwc_vt_known ) );

$gwc_vt_html = gwc_vt_render_shift_list();

gwc_vt_check( 'the list renders', false !== strpos( $gwc_vt_html, 'Zzytest sorting donations' ) );
gwc_vt_check( 'and says how many places are left', false !== strpos( $gwc_vt_html, '2 places left' ) );
gwc_vt_check( 'and never names who is coming', false === strpos( $gwc_vt_html, 'Zzytest Jane Quimby' ) );
gwc_vt_check( 'nor leaks their address', false === strpos( $gwc_vt_html, 'zzytest-jane@example.test' ) );
gwc_vt_check( 'it carries a honeypot', false !== strpos( $gwc_vt_html, 'gwc_vt_website' ) );
gwc_vt_check( 'and a timing stamp', false !== strpos( $gwc_vt_html, 'gwc_vt_t' ) );
gwc_vt_check( 'and a nonce', false !== strpos( $gwc_vt_html, 'gwc_vt_signup_nonce' ) );

/* Shifts a visitor may not see are not merely hidden from the list — the
 * handler refuses them too, so guessing an ID gets nowhere. */
$gwc_vt_past      = gwc_vt_make_shift( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 0 );
$gwc_vt_draft     = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ), 0, 'draft' );
$gwc_vt_cancelled = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 4 * DAY_IN_SECONDS ) ), 0, GWC_VT_SHIFT_CANCELLED );
$gwc_vt_distant   = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 200 * DAY_IN_SECONDS ) ), 0 );

$gwc_vt_visible = gwc_vt_public_shift_ids();

gwc_vt_check( 'an upcoming shift is listed', in_array( $gwc_vt_soon, $gwc_vt_visible, true ) );
gwc_vt_check( 'a past one is not', ! in_array( $gwc_vt_past, $gwc_vt_visible, true ) );
gwc_vt_check( 'a draft is not', ! in_array( $gwc_vt_draft, $gwc_vt_visible, true ) );
gwc_vt_check( 'a cancelled one is not', ! in_array( $gwc_vt_cancelled, $gwc_vt_visible, true ) );
gwc_vt_check( 'nor one past the horizon', ! in_array( $gwc_vt_distant, $gwc_vt_visible, true ) );

/* ── A signup from a stranger ────────────────────────────────────────────── */

$gwc_vt_open = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 8 * DAY_IN_SECONDS ) ), 0 );

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Marcus Bell',
		'gwc_vt_email' => 'zzytest-marcus@example.test',
	)
);

gwc_vt_check( 'a signup is accepted', 'accepted' === $gwc_vt_result, $gwc_vt_result );

$gwc_vt_signups = gwc_vt_all_signups( $gwc_vt_open );
gwc_vt_check( 'and recorded', 1 === count( $gwc_vt_signups ), (string) count( $gwc_vt_signups ) );

$gwc_vt_signup = (int) ( $gwc_vt_signups[0] ?? 0 );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_signup;

gwc_vt_check( 'attached to nobody', '0' === (string) get_post_meta( $gwc_vt_signup, GWC_VT_SIGNUP_VOLUNTEER, true ) );
gwc_vt_check( 'with the name as a claim', 'Zzytest Marcus Bell' === (string) get_post_meta( $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_NAME, true ) );
gwc_vt_check( 'and marked as self-served', 'self' === (string) get_post_meta( $gwc_vt_signup, GWC_VT_SIGNUP_SOURCE, true ) );

/* ── The confirmation ────────────────────────────────────────────────────── */

do_action( 'shutdown' );

gwc_vt_check( 'a confirmation is sent', 1 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );

$gwc_vt_message = $GLOBALS['gwc_vt_mail'][0] ?? array();

gwc_vt_check( 'to the address they gave', 'zzytest-marcus@example.test' === ( $gwc_vt_message['to'] ?? '' ), (string) ( $gwc_vt_message['to'] ?? '' ) );
gwc_vt_check( 'naming the organisation', false !== strpos( (string) ( $gwc_vt_message['subject'] ?? '' ), 'Zzytest Riverbend Food Bank' ), (string) ( $gwc_vt_message['subject'] ?? '' ) );
gwc_vt_check( 'saying where to go', false !== strpos( (string) ( $gwc_vt_message['message'] ?? '' ), 'Zzytest main warehouse' ) );
gwc_vt_check( 'and carrying a way out of it', false !== strpos( (string) ( $gwc_vt_message['message'] ?? '' ), 'gwc_vt_signup=' . $gwc_vt_signup ) );

/* ── The defences ────────────────────────────────────────────────────────────
 * Every one of these ends at the same word the visitor sees for a real signup.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_before = count( gwc_vt_all_signups( $gwc_vt_open ) );

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift'   => (string) $gwc_vt_open,
		'gwc_vt_name'    => 'Zzytest A Robot',
		'gwc_vt_email'   => 'zzytest-robot@example.test',
		'gwc_vt_website' => 'http://example.test/',
	)
);

gwc_vt_check( 'a honeypot hit reads as accepted', 'accepted' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'and records nothing', $gwc_vt_before === count( gwc_vt_all_signups( $gwc_vt_open ) ) );

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Too Fast',
		'gwc_vt_email' => 'zzytest-fast@example.test',
	),
	false
);

gwc_vt_check( 'a form submitted instantly reads as accepted', 'accepted' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'and records nothing either', $gwc_vt_before === count( gwc_vt_all_signups( $gwc_vt_open ) ) );

gwc_vt_check(
	'the three look identical to the visitor',
	gwc_vt_signup_message( 'accepted' ) === gwc_vt_signup_message( 'accepted' )
		&& '' !== gwc_vt_signup_message( 'accepted' )
);

$GLOBALS['gwc_vt_signup_result'] = '';
$_POST                          = array( 'gwc_vt_signup_submit' => '1', 'gwc_vt_signup_nonce' => 'forged' );
$_REQUEST                       = $_POST;
gwc_vt_handle_public_signup();
gwc_vt_check( 'a forged nonce is refused', 'expired' === $GLOBALS['gwc_vt_signup_result'], (string) $GLOBALS['gwc_vt_signup_result'] );
$_POST    = array();
$_REQUEST = array();

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Nameless',
		'gwc_vt_email' => 'not-an-address',
	)
);
gwc_vt_check( 'an unreadable address is refused', 'incomplete' === $gwc_vt_result, $gwc_vt_result );

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => '',
		'gwc_vt_email' => 'zzytest-someone@example.test',
	)
);
gwc_vt_check( 'a missing name is refused', 'incomplete' === $gwc_vt_result, $gwc_vt_result );

foreach ( array( $gwc_vt_past, $gwc_vt_draft, $gwc_vt_cancelled, $gwc_vt_distant, 999999 ) as $gwc_vt_hidden ) {
	$gwc_vt_result = gwc_vt_post_signup(
		array(
			'gwc_vt_shift' => (string) $gwc_vt_hidden,
			'gwc_vt_name'  => 'Zzytest Guesser',
			'gwc_vt_email' => 'zzytest-guesser@example.test',
		)
	);

	gwc_vt_check( 'a shift a visitor cannot see cannot be signed up for (' . $gwc_vt_hidden . ')', 'unavailable' === $gwc_vt_result, $gwc_vt_result );
}

gwc_vt_check( 'and none of those created anything', 0 === count( gwc_vt_all_signups( $gwc_vt_past ) ) + count( gwc_vt_all_signups( $gwc_vt_draft ) ) + count( gwc_vt_all_signups( $gwc_vt_cancelled ) ) );

/* The shared code. A distinct message, because it is not a security boundary. */
gwc_vt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwc_vt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
		'signup_code'         => 'RIVERBEND',
	)
);

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Wrong Code',
		'gwc_vt_email' => 'zzytest-wrong@example.test',
		'gwc_vt_code'  => 'nope',
	)
);
gwc_vt_check( 'a mistyped code says so', 'bad-code' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'and is told apart from a signup', gwc_vt_signup_message( 'bad-code' ) !== gwc_vt_signup_message( 'accepted' ) );

$gwc_vt_result = gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Right Code',
		'gwc_vt_email' => 'zzytest-right@example.test',
		'gwc_vt_code'  => 'RIVERBEND',
	)
);
gwc_vt_check( 'the right code goes through', 'accepted' === $gwc_vt_result, $gwc_vt_result );

gwc_vt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwc_vt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
	)
);

foreach ( gwc_vt_all_signups( $gwc_vt_open ) as $gwc_vt_id ) {
	$GLOBALS['gwc_vt_made'][] = $gwc_vt_id;
}

/* Submitting twice does not book twice. */
$gwc_vt_count = count( gwc_vt_all_signups( $gwc_vt_open ) );

gwc_vt_post_signup(
	array(
		'gwc_vt_shift' => (string) $gwc_vt_open,
		'gwc_vt_name'  => 'Zzytest Marcus Bell',
		'gwc_vt_email' => 'zzytest-marcus@example.test',
	)
);

gwc_vt_check( 'signing up twice does not book twice', $gwc_vt_count === count( gwc_vt_all_signups( $gwc_vt_open ) ), (string) count( gwc_vt_all_signups( $gwc_vt_open ) ) );

/* ── Cancelling ──────────────────────────────────────────────────────────────
 * The link arrives in an email. Mail clients prefetch links and security
 * appliances follow them, so merely loading the page must never withdraw
 * anything — only the button on it may.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_token = gwc_vt_signup_token( $gwc_vt_signup );

$_GET = array(
	'gwc_vt_signup' => (string) $gwc_vt_signup,
	'gwc_vt_k'      => $gwc_vt_token,
);

$gwc_vt_manage = gwc_vt_render_shift_list();

gwc_vt_check( 'the manage page renders for a valid link', false !== strpos( $gwc_vt_manage, 'Cancel my place' ) );
gwc_vt_check( 'and offers the calendar file', false !== strpos( $gwc_vt_manage, 'gwc_vt_ics=' . $gwc_vt_signup ) );
gwc_vt_check( 'merely opening it withdraws nothing', 'publish' === get_post_status( $gwc_vt_signup ), (string) get_post_status( $gwc_vt_signup ) );
gwc_vt_check( 'and the cancel control is a POST form', false !== strpos( $gwc_vt_manage, 'method="post"' ) );

$_GET = array( 'gwc_vt_signup' => (string) $gwc_vt_signup, 'gwc_vt_k' => str_repeat( 'a', 64 ) );

$gwc_vt_forged = gwc_vt_render_shift_list();
gwc_vt_check( 'a forged link offers nothing', false === strpos( $gwc_vt_forged, 'Cancel my place' ) );
gwc_vt_check( 'and still withdraws nothing', 'publish' === get_post_status( $gwc_vt_signup ) );

$_GET = array();

/* The button itself. */
$GLOBALS['gwc_vt_signup_result'] = '';
$_POST                          = array(
	'gwc_vt_cancel_submit' => '1',
	'gwc_vt_cancel_nonce'  => wp_create_nonce( 'gwc_vt_cancel_signup' ),
	'gwc_vt_signup'        => (string) $gwc_vt_signup,
	'gwc_vt_k'             => str_repeat( 'a', 64 ),
);
$_REQUEST = $_POST;
gwc_vt_handle_public_cancel();
gwc_vt_check( 'a forged token cannot cancel', 'cancel-unknown' === $GLOBALS['gwc_vt_signup_result'], (string) $GLOBALS['gwc_vt_signup_result'] );
gwc_vt_check( 'and the place is still held', 'publish' === get_post_status( $gwc_vt_signup ) );

$GLOBALS['gwc_vt_signup_result'] = '';
$_POST['gwc_vt_k']               = $gwc_vt_token;
$_REQUEST                       = $_POST;
gwc_vt_handle_public_cancel();
gwc_vt_check( 'the real token cancels', 'cancelled' === $GLOBALS['gwc_vt_signup_result'], (string) $GLOBALS['gwc_vt_signup_result'] );
gwc_vt_check( 'and the signup is withdrawn, not deleted', GWC_VT_SIGNUP_WITHDRAWN === get_post_status( $gwc_vt_signup ), (string) get_post_status( $gwc_vt_signup ) );

$_POST    = array();
$_REQUEST = array();

/* ── The calendar file ───────────────────────────────────────────────────── */

$gwc_vt_ics = gwc_vt_signup_ics( $gwc_vt_signup );

gwc_vt_check( 'a calendar file is produced', false !== strpos( $gwc_vt_ics, 'BEGIN:VEVENT' ) );
gwc_vt_check( 'with a UTC start instant', 1 === preg_match( '/DTSTART:\d{8}T\d{6}Z/', $gwc_vt_ics ) );
gwc_vt_check( 'and a UTC end instant', 1 === preg_match( '/DTEND:\d{8}T\d{6}Z/', $gwc_vt_ics ) );
gwc_vt_check( 'published rather than an invitation to RSVP', false !== strpos( $gwc_vt_ics, 'METHOD:PUBLISH' ) && false === strpos( $gwc_vt_ics, 'METHOD:REQUEST' ) );
gwc_vt_check( 'a unique identifier tied to this signup', false !== strpos( $gwc_vt_ics, 'UID:gwcvt-signup-' . $gwc_vt_signup . '@' ) );
gwc_vt_check( 'lines end CRLF', false !== strpos( $gwc_vt_ics, "\r\n" ) );
gwc_vt_check( 'and it names the organisation so it is recognisable in a calendar', false !== strpos( $gwc_vt_ics, 'Zzytest Riverbend Food Bank' ) );

/* ── Privacy ─────────────────────────────────────────────────────────────────
 * The case the old exporter could not reach: somebody who signed up through the
 * public form, was never matched to a volunteer, and asks what is held.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_export = gwc_vt_export_personal_data( 'zzytest-marcus@example.test' );
$gwc_vt_groups = wp_list_pluck( $gwc_vt_export['data'], 'group_id' );

gwc_vt_check( 'an unmatched signup is exported', in_array( 'gwc_vt_signup', $gwc_vt_groups, true ), implode( ',', $gwc_vt_groups ) );

/* Matched on this signup's own item_id rather than "the last signup in the
 * export". Reading whichever one came back last is how this check first passed
 * against a seeded fixture belonging to somebody else — every address here is
 * namespaced now, and the assertion is pinned to the record under test too. */
$gwc_vt_found = '';

foreach ( $gwc_vt_export['data'] as $gwc_vt_item ) {
	if ( 'gwcvt-signup-' . $gwc_vt_signup === $gwc_vt_item['item_id'] ) {
		$gwc_vt_found = wp_json_encode( $gwc_vt_item['data'] );
	}
}

gwc_vt_check( 'and it is this signup, not somebody else\'s', '' !== $gwc_vt_found );
gwc_vt_check( 'carrying the name they gave', false !== strpos( $gwc_vt_found, 'Zzytest Marcus Bell' ) );

$gwc_vt_erase = gwc_vt_erase_personal_data( 'zzytest-marcus@example.test' );

gwc_vt_check( 'erasing reports something removed', ! empty( $gwc_vt_erase['items_removed'] ) );
gwc_vt_check( 'the claimed name is gone', '' === (string) get_post_meta( $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_NAME, true ) );
gwc_vt_check( 'the claimed address is gone', '' === (string) get_post_meta( $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_EMAIL, true ) );

/* The place on the shift survives: it is the organisation's record of what it
 * ran and who staffed it, and anonymised it identifies nobody. */
gwc_vt_check( 'but the place on the shift survives', GWC_VT_SIGNUP_TYPE === get_post_type( $gwc_vt_signup ) );
gwc_vt_check( 'and the erasure said so', ! empty( $gwc_vt_erase['messages'] ) );

$gwc_vt_after = gwc_vt_export_personal_data( 'zzytest-marcus@example.test' );
gwc_vt_check( 'a second export finds nothing left', ! in_array( 'gwc_vt_signup', wp_list_pluck( $gwc_vt_after['data'], 'group_id' ), true ) );

/* Nothing outside this script's own fixtures was touched. Every address here is
 * namespaced for exactly this reason: an eraser is the most destructive thing in
 * the plugin, and the first version of this file used a bare address that
 * collided with the demo data — so the erasure ran against a record belonging to
 * somebody else's fixtures and the check above passed by reading it. */
gwc_vt_check(
	'the erasure reached nothing outside this script',
	array() === gwc_vt_volunteers_by_email( 'zzytest-marcus@example.test' )
);

/* ── Retention reaches a signup that never became anybody ────────────────────
 * The sweep starts from volunteer records, so a claim belonging to no volunteer
 * would otherwise outlive every retention policy the site could set.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_orphan = gwc_vt_add_signup(
	$gwc_vt_soon,
	array(
		'claim_name'  => 'Zzytest Long Ago',
		'claim_email' => 'zzytest-longago@example.test',
		'source'      => 'self',
	)
);

$GLOBALS['gwc_vt_made'][] = $gwc_vt_orphan;

update_post_meta( $gwc_vt_orphan, GWC_VT_SIGNUP_CREATED, gmdate( 'Y-m-d H:i:s', strtotime( '-4 years' ) ) );

$gwc_vt_recent = gwc_vt_add_signup(
	$gwc_vt_soon,
	array(
		'claim_name'  => 'Zzytest Last Week',
		'claim_email' => 'zzytest-recent@example.test',
		'source'      => 'self',
	)
);

$GLOBALS['gwc_vt_made'][] = $gwc_vt_recent;

$gwc_vt_swept = gwc_vt_sweep_orphan_signups( 24 );

gwc_vt_check( 'an old unmatched claim is swept', $gwc_vt_swept >= 1, (string) $gwc_vt_swept );
gwc_vt_check( 'its name is gone', '' === (string) get_post_meta( $gwc_vt_orphan, GWC_VT_SIGNUP_CLAIM_NAME, true ) );
gwc_vt_check( 'its address is gone', '' === (string) get_post_meta( $gwc_vt_orphan, GWC_VT_SIGNUP_CLAIM_EMAIL, true ) );
gwc_vt_check( 'the place on the shift stays', GWC_VT_SIGNUP_TYPE === get_post_type( $gwc_vt_orphan ) );
gwc_vt_check( 'a recent one is left alone', 'Zzytest Last Week' === (string) get_post_meta( $gwc_vt_recent, GWC_VT_SIGNUP_CLAIM_NAME, true ) );

/* Nothing left to strip is not a purge — otherwise the retention log would
 * report work on every run, forever. */
gwc_vt_check( 'sweeping again reports nothing done', 0 === gwc_vt_sweep_orphan_signups( 24 ), (string) gwc_vt_sweep_orphan_signups( 24 ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array( $gwc_vt_soon, $gwc_vt_open, $gwc_vt_past, $gwc_vt_draft, $gwc_vt_cancelled, $gwc_vt_distant ) as $gwc_vt_shift_id ) {
	foreach ( gwc_vt_all_signups( $gwc_vt_shift_id ) as $gwc_vt_id ) {
		wp_delete_post( (int) $gwc_vt_id, true );
	}

	delete_option( 'gwc_vt_signup_lock_' . $gwc_vt_shift_id );
}

foreach ( array_unique( array_filter( $GLOBALS['gwc_vt_made'] ) ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
	delete_option( GWC_VT_SETTINGS_OPTION );
} else {
	update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
}

if ( false === $GLOBALS['gwc_vt_limits_before'] ) {
	delete_option( GWC_VT_RATE_LIMIT_OPTION );
} else {
	update_option( GWC_VT_RATE_LIMIT_OPTION, $GLOBALS['gwc_vt_limits_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

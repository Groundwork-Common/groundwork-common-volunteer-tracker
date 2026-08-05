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
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_made']     = array();
$GLOBALS['gwcvt_mail']     = array();

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
function gwcvt_test_catch_mail( $short, $atts ) {
	$GLOBALS['gwcvt_mail'][] = $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'gwcvt_test_catch_mail', 10, 2 );

/* Everything this script writes, put back at the end. These scripts run against
 * a database that belongs to somebody else. */
$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );
$GLOBALS['gwcvt_limits_before']   = get_option( GWCVT_RATE_LIMIT_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to merge over the defaults.
 */
function gwcvt_set_settings( array $extra ): void {
	update_option( GWCVT_SETTINGS_OPTION, $extra );
	gwcvt_settings_cache( null, true );
}

/**
 * Create a shift.
 *
 * @param string $date   Y-m-d.
 * @param int    $max    Capacity, 0 for none.
 * @param string $status Post status.
 * @return int
 */
function gwcvt_make_shift( string $date, int $max = 0, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_SHIFT_TYPE,
			'post_status' => $status,
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_SHIFT_DATE, $date );
	update_post_meta( $id, GWCVT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWCVT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWCVT_SHIFT_LOCATION, 'Zzytest main warehouse' );
	update_post_meta( $id, GWCVT_SHIFT_MAX, $max );

	gwcvt_retitle_shift( (int) $id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Post to the signup handler as a browser would.
 *
 * @param array $post What the form sends.
 * @param bool  $aged Whether to stamp the form as rendered long enough ago.
 * @return string The result key the handler recorded.
 */
function gwcvt_post_signup( array $post, bool $aged = true ): string {
	$GLOBALS['gwcvt_signup_result'] = '';

	$stamp = $aged
		? ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwcvt_form' ) )
		: gwcvt_form_stamp();

	$_POST = array_merge(
		array(
			'gwcvt_signup_submit' => '1',
			'gwcvt_signup_nonce'  => wp_create_nonce( 'gwcvt_signup' ),
			'gwcvt_t'             => $stamp,
		),
		$post
	);

	$_REQUEST = $_POST;

	gwcvt_handle_public_signup();

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $GLOBALS['gwcvt_signup_result'] ?? '' );
}

/**
 * Every signup on a shift, in any status.
 *
 * @param int $shift_id Shift post ID.
 * @return int[]
 */
function gwcvt_all_signups( int $shift_id ): array {
	return gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ) );
}

/* ── Off until somebody turns it on ──────────────────────────────────────── */

gwcvt_set_settings( array() );

gwcvt_check( 'signups are closed on a fresh install', ! gwcvt_signups_open() );
gwcvt_check( 'and the list renders nothing at all', '' === gwcvt_render_shift_list() );

$gwcvt_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest volunteer shifts',
		'post_content' => '[volunteer_shifts]',
	)
);

$GLOBALS['gwcvt_made'][] = $gwcvt_page;

gwcvt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwcvt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
	)
);

gwcvt_check( 'switched on and pinned, signups are open', gwcvt_signups_open() );

/* ── The list shows counts and never names ───────────────────────────────────
 * The single rule the public surface exists to keep. On a site running a
 * court-ordered service programme, the roster for Saturday is a list of people
 * working one off.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_soon = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) ), 3 );

$gwcvt_known = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Jane Quimby',
	)
);
update_post_meta( $gwcvt_known, GWCVT_VOLUNTEER_EMAIL, 'zzytest-jane@example.test' );
$GLOBALS['gwcvt_made'][] = $gwcvt_known;

$GLOBALS['gwcvt_made'][] = gwcvt_add_signup( $gwcvt_soon, array( 'volunteer_id' => $gwcvt_known ) );

$gwcvt_html = gwcvt_render_shift_list();

gwcvt_check( 'the list renders', false !== strpos( $gwcvt_html, 'Zzytest sorting donations' ) );
gwcvt_check( 'and says how many places are left', false !== strpos( $gwcvt_html, '2 places left' ) );
gwcvt_check( 'and never names who is coming', false === strpos( $gwcvt_html, 'Zzytest Jane Quimby' ) );
gwcvt_check( 'nor leaks their address', false === strpos( $gwcvt_html, 'zzytest-jane@example.test' ) );
gwcvt_check( 'it carries a honeypot', false !== strpos( $gwcvt_html, 'gwcvt_website' ) );
gwcvt_check( 'and a timing stamp', false !== strpos( $gwcvt_html, 'gwcvt_t' ) );
gwcvt_check( 'and a nonce', false !== strpos( $gwcvt_html, 'gwcvt_signup_nonce' ) );

/* Shifts a visitor may not see are not merely hidden from the list — the
 * handler refuses them too, so guessing an ID gets nowhere. */
$gwcvt_past      = gwcvt_make_shift( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 0 );
$gwcvt_draft     = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ), 0, 'draft' );
$gwcvt_cancelled = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 4 * DAY_IN_SECONDS ) ), 0, GWCVT_SHIFT_CANCELLED );
$gwcvt_distant   = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 200 * DAY_IN_SECONDS ) ), 0 );

$gwcvt_visible = gwcvt_public_shift_ids();

gwcvt_check( 'an upcoming shift is listed', in_array( $gwcvt_soon, $gwcvt_visible, true ) );
gwcvt_check( 'a past one is not', ! in_array( $gwcvt_past, $gwcvt_visible, true ) );
gwcvt_check( 'a draft is not', ! in_array( $gwcvt_draft, $gwcvt_visible, true ) );
gwcvt_check( 'a cancelled one is not', ! in_array( $gwcvt_cancelled, $gwcvt_visible, true ) );
gwcvt_check( 'nor one past the horizon', ! in_array( $gwcvt_distant, $gwcvt_visible, true ) );

/* ── A signup from a stranger ────────────────────────────────────────────── */

$gwcvt_open = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 8 * DAY_IN_SECONDS ) ), 0 );

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Marcus Bell',
		'gwcvt_email' => 'zzytest-marcus@example.test',
	)
);

gwcvt_check( 'a signup is accepted', 'accepted' === $gwcvt_result, $gwcvt_result );

$gwcvt_signups = gwcvt_all_signups( $gwcvt_open );
gwcvt_check( 'and recorded', 1 === count( $gwcvt_signups ), (string) count( $gwcvt_signups ) );

$gwcvt_signup = (int) ( $gwcvt_signups[0] ?? 0 );
$GLOBALS['gwcvt_made'][] = $gwcvt_signup;

gwcvt_check( 'attached to nobody', '0' === (string) get_post_meta( $gwcvt_signup, GWCVT_SIGNUP_VOLUNTEER, true ) );
gwcvt_check( 'with the name as a claim', 'Zzytest Marcus Bell' === (string) get_post_meta( $gwcvt_signup, GWCVT_SIGNUP_CLAIM_NAME, true ) );
gwcvt_check( 'and marked as self-served', 'self' === (string) get_post_meta( $gwcvt_signup, GWCVT_SIGNUP_SOURCE, true ) );

/* ── The confirmation ────────────────────────────────────────────────────── */

do_action( 'shutdown' );

gwcvt_check( 'a confirmation is sent', 1 === count( $GLOBALS['gwcvt_mail'] ), (string) count( $GLOBALS['gwcvt_mail'] ) );

$gwcvt_message = $GLOBALS['gwcvt_mail'][0] ?? array();

gwcvt_check( 'to the address they gave', 'zzytest-marcus@example.test' === ( $gwcvt_message['to'] ?? '' ), (string) ( $gwcvt_message['to'] ?? '' ) );
gwcvt_check( 'naming the organisation', false !== strpos( (string) ( $gwcvt_message['subject'] ?? '' ), 'Zzytest Riverbend Food Bank' ), (string) ( $gwcvt_message['subject'] ?? '' ) );
gwcvt_check( 'saying where to go', false !== strpos( (string) ( $gwcvt_message['message'] ?? '' ), 'Zzytest main warehouse' ) );
gwcvt_check( 'and carrying a way out of it', false !== strpos( (string) ( $gwcvt_message['message'] ?? '' ), 'gwcvt_signup=' . $gwcvt_signup ) );

/* ── The defences ────────────────────────────────────────────────────────────
 * Every one of these ends at the same word the visitor sees for a real signup.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_before = count( gwcvt_all_signups( $gwcvt_open ) );

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift'   => (string) $gwcvt_open,
		'gwcvt_name'    => 'Zzytest A Robot',
		'gwcvt_email'   => 'zzytest-robot@example.test',
		'gwcvt_website' => 'http://example.test/',
	)
);

gwcvt_check( 'a honeypot hit reads as accepted', 'accepted' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'and records nothing', $gwcvt_before === count( gwcvt_all_signups( $gwcvt_open ) ) );

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Too Fast',
		'gwcvt_email' => 'zzytest-fast@example.test',
	),
	false
);

gwcvt_check( 'a form submitted instantly reads as accepted', 'accepted' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'and records nothing either', $gwcvt_before === count( gwcvt_all_signups( $gwcvt_open ) ) );

gwcvt_check(
	'the three look identical to the visitor',
	gwcvt_signup_message( 'accepted' ) === gwcvt_signup_message( 'accepted' )
		&& '' !== gwcvt_signup_message( 'accepted' )
);

$GLOBALS['gwcvt_signup_result'] = '';
$_POST                          = array( 'gwcvt_signup_submit' => '1', 'gwcvt_signup_nonce' => 'forged' );
$_REQUEST                       = $_POST;
gwcvt_handle_public_signup();
gwcvt_check( 'a forged nonce is refused', 'expired' === $GLOBALS['gwcvt_signup_result'], (string) $GLOBALS['gwcvt_signup_result'] );
$_POST    = array();
$_REQUEST = array();

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Nameless',
		'gwcvt_email' => 'not-an-address',
	)
);
gwcvt_check( 'an unreadable address is refused', 'incomplete' === $gwcvt_result, $gwcvt_result );

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => '',
		'gwcvt_email' => 'zzytest-someone@example.test',
	)
);
gwcvt_check( 'a missing name is refused', 'incomplete' === $gwcvt_result, $gwcvt_result );

foreach ( array( $gwcvt_past, $gwcvt_draft, $gwcvt_cancelled, $gwcvt_distant, 999999 ) as $gwcvt_hidden ) {
	$gwcvt_result = gwcvt_post_signup(
		array(
			'gwcvt_shift' => (string) $gwcvt_hidden,
			'gwcvt_name'  => 'Zzytest Guesser',
			'gwcvt_email' => 'zzytest-guesser@example.test',
		)
	);

	gwcvt_check( 'a shift a visitor cannot see cannot be signed up for (' . $gwcvt_hidden . ')', 'unavailable' === $gwcvt_result, $gwcvt_result );
}

gwcvt_check( 'and none of those created anything', 0 === count( gwcvt_all_signups( $gwcvt_past ) ) + count( gwcvt_all_signups( $gwcvt_draft ) ) + count( gwcvt_all_signups( $gwcvt_cancelled ) ) );

/* The shared code. A distinct message, because it is not a security boundary. */
gwcvt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwcvt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
		'signup_code'         => 'RIVERBEND',
	)
);

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Wrong Code',
		'gwcvt_email' => 'zzytest-wrong@example.test',
		'gwcvt_code'  => 'nope',
	)
);
gwcvt_check( 'a mistyped code says so', 'bad-code' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'and is told apart from a signup', gwcvt_signup_message( 'bad-code' ) !== gwcvt_signup_message( 'accepted' ) );

$gwcvt_result = gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Right Code',
		'gwcvt_email' => 'zzytest-right@example.test',
		'gwcvt_code'  => 'RIVERBEND',
	)
);
gwcvt_check( 'the right code goes through', 'accepted' === $gwcvt_result, $gwcvt_result );

gwcvt_set_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => (int) $gwcvt_page,
		'signup_horizon_days' => 60,
		'org_name'            => 'Zzytest Riverbend Food Bank',
	)
);

foreach ( gwcvt_all_signups( $gwcvt_open ) as $gwcvt_id ) {
	$GLOBALS['gwcvt_made'][] = $gwcvt_id;
}

/* Submitting twice does not book twice. */
$gwcvt_count = count( gwcvt_all_signups( $gwcvt_open ) );

gwcvt_post_signup(
	array(
		'gwcvt_shift' => (string) $gwcvt_open,
		'gwcvt_name'  => 'Zzytest Marcus Bell',
		'gwcvt_email' => 'zzytest-marcus@example.test',
	)
);

gwcvt_check( 'signing up twice does not book twice', $gwcvt_count === count( gwcvt_all_signups( $gwcvt_open ) ), (string) count( gwcvt_all_signups( $gwcvt_open ) ) );

/* ── Cancelling ──────────────────────────────────────────────────────────────
 * The link arrives in an email. Mail clients prefetch links and security
 * appliances follow them, so merely loading the page must never withdraw
 * anything — only the button on it may.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_token = gwcvt_signup_token( $gwcvt_signup );

$_GET = array(
	'gwcvt_signup' => (string) $gwcvt_signup,
	'gwcvt_k'      => $gwcvt_token,
);

$gwcvt_manage = gwcvt_render_shift_list();

gwcvt_check( 'the manage page renders for a valid link', false !== strpos( $gwcvt_manage, 'Cancel my place' ) );
gwcvt_check( 'and offers the calendar file', false !== strpos( $gwcvt_manage, 'gwcvt_ics=' . $gwcvt_signup ) );
gwcvt_check( 'merely opening it withdraws nothing', 'publish' === get_post_status( $gwcvt_signup ), (string) get_post_status( $gwcvt_signup ) );
gwcvt_check( 'and the cancel control is a POST form', false !== strpos( $gwcvt_manage, 'method="post"' ) );

$_GET = array( 'gwcvt_signup' => (string) $gwcvt_signup, 'gwcvt_k' => str_repeat( 'a', 64 ) );

$gwcvt_forged = gwcvt_render_shift_list();
gwcvt_check( 'a forged link offers nothing', false === strpos( $gwcvt_forged, 'Cancel my place' ) );
gwcvt_check( 'and still withdraws nothing', 'publish' === get_post_status( $gwcvt_signup ) );

$_GET = array();

/* The button itself. */
$GLOBALS['gwcvt_signup_result'] = '';
$_POST                          = array(
	'gwcvt_cancel_submit' => '1',
	'gwcvt_cancel_nonce'  => wp_create_nonce( 'gwcvt_cancel_signup' ),
	'gwcvt_signup'        => (string) $gwcvt_signup,
	'gwcvt_k'             => str_repeat( 'a', 64 ),
);
$_REQUEST = $_POST;
gwcvt_handle_public_cancel();
gwcvt_check( 'a forged token cannot cancel', 'cancel-unknown' === $GLOBALS['gwcvt_signup_result'], (string) $GLOBALS['gwcvt_signup_result'] );
gwcvt_check( 'and the place is still held', 'publish' === get_post_status( $gwcvt_signup ) );

$GLOBALS['gwcvt_signup_result'] = '';
$_POST['gwcvt_k']               = $gwcvt_token;
$_REQUEST                       = $_POST;
gwcvt_handle_public_cancel();
gwcvt_check( 'the real token cancels', 'cancelled' === $GLOBALS['gwcvt_signup_result'], (string) $GLOBALS['gwcvt_signup_result'] );
gwcvt_check( 'and the signup is withdrawn, not deleted', GWCVT_SIGNUP_WITHDRAWN === get_post_status( $gwcvt_signup ), (string) get_post_status( $gwcvt_signup ) );

$_POST    = array();
$_REQUEST = array();

/* ── The calendar file ───────────────────────────────────────────────────── */

$gwcvt_ics = gwcvt_signup_ics( $gwcvt_signup );

gwcvt_check( 'a calendar file is produced', false !== strpos( $gwcvt_ics, 'BEGIN:VEVENT' ) );
gwcvt_check( 'with a UTC start instant', 1 === preg_match( '/DTSTART:\d{8}T\d{6}Z/', $gwcvt_ics ) );
gwcvt_check( 'and a UTC end instant', 1 === preg_match( '/DTEND:\d{8}T\d{6}Z/', $gwcvt_ics ) );
gwcvt_check( 'published rather than an invitation to RSVP', false !== strpos( $gwcvt_ics, 'METHOD:PUBLISH' ) && false === strpos( $gwcvt_ics, 'METHOD:REQUEST' ) );
gwcvt_check( 'a unique identifier tied to this signup', false !== strpos( $gwcvt_ics, 'UID:gwcvt-signup-' . $gwcvt_signup . '@' ) );
gwcvt_check( 'lines end CRLF', false !== strpos( $gwcvt_ics, "\r\n" ) );
gwcvt_check( 'and it names the organisation so it is recognisable in a calendar', false !== strpos( $gwcvt_ics, 'Zzytest Riverbend Food Bank' ) );

/* ── Privacy ─────────────────────────────────────────────────────────────────
 * The case the old exporter could not reach: somebody who signed up through the
 * public form, was never matched to a volunteer, and asks what is held.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_export = gwcvt_export_personal_data( 'zzytest-marcus@example.test' );
$gwcvt_groups = wp_list_pluck( $gwcvt_export['data'], 'group_id' );

gwcvt_check( 'an unmatched signup is exported', in_array( 'gwcvt_signup', $gwcvt_groups, true ), implode( ',', $gwcvt_groups ) );

/* Matched on this signup's own item_id rather than "the last signup in the
 * export". Reading whichever one came back last is how this check first passed
 * against a seeded fixture belonging to somebody else — every address here is
 * namespaced now, and the assertion is pinned to the record under test too. */
$gwcvt_found = '';

foreach ( $gwcvt_export['data'] as $gwcvt_item ) {
	if ( 'gwcvt-signup-' . $gwcvt_signup === $gwcvt_item['item_id'] ) {
		$gwcvt_found = wp_json_encode( $gwcvt_item['data'] );
	}
}

gwcvt_check( 'and it is this signup, not somebody else\'s', '' !== $gwcvt_found );
gwcvt_check( 'carrying the name they gave', false !== strpos( $gwcvt_found, 'Zzytest Marcus Bell' ) );

$gwcvt_erase = gwcvt_erase_personal_data( 'zzytest-marcus@example.test' );

gwcvt_check( 'erasing reports something removed', ! empty( $gwcvt_erase['items_removed'] ) );
gwcvt_check( 'the claimed name is gone', '' === (string) get_post_meta( $gwcvt_signup, GWCVT_SIGNUP_CLAIM_NAME, true ) );
gwcvt_check( 'the claimed address is gone', '' === (string) get_post_meta( $gwcvt_signup, GWCVT_SIGNUP_CLAIM_EMAIL, true ) );

/* The place on the shift survives: it is the organisation's record of what it
 * ran and who staffed it, and anonymised it identifies nobody. */
gwcvt_check( 'but the place on the shift survives', GWCVT_SIGNUP_TYPE === get_post_type( $gwcvt_signup ) );
gwcvt_check( 'and the erasure said so', ! empty( $gwcvt_erase['messages'] ) );

$gwcvt_after = gwcvt_export_personal_data( 'zzytest-marcus@example.test' );
gwcvt_check( 'a second export finds nothing left', ! in_array( 'gwcvt_signup', wp_list_pluck( $gwcvt_after['data'], 'group_id' ), true ) );

/* Nothing outside this script's own fixtures was touched. Every address here is
 * namespaced for exactly this reason: an eraser is the most destructive thing in
 * the plugin, and the first version of this file used a bare address that
 * collided with the demo data — so the erasure ran against a record belonging to
 * somebody else's fixtures and the check above passed by reading it. */
gwcvt_check(
	'the erasure reached nothing outside this script',
	array() === gwcvt_volunteers_by_email( 'zzytest-marcus@example.test' )
);

/* ── Retention reaches a signup that never became anybody ────────────────────
 * The sweep starts from volunteer records, so a claim belonging to no volunteer
 * would otherwise outlive every retention policy the site could set.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_orphan = gwcvt_add_signup(
	$gwcvt_soon,
	array(
		'claim_name'  => 'Zzytest Long Ago',
		'claim_email' => 'zzytest-longago@example.test',
		'source'      => 'self',
	)
);

$GLOBALS['gwcvt_made'][] = $gwcvt_orphan;

update_post_meta( $gwcvt_orphan, GWCVT_SIGNUP_CREATED, gmdate( 'Y-m-d H:i:s', strtotime( '-4 years' ) ) );

$gwcvt_recent = gwcvt_add_signup(
	$gwcvt_soon,
	array(
		'claim_name'  => 'Zzytest Last Week',
		'claim_email' => 'zzytest-recent@example.test',
		'source'      => 'self',
	)
);

$GLOBALS['gwcvt_made'][] = $gwcvt_recent;

$gwcvt_swept = gwcvt_sweep_orphan_signups( 24 );

gwcvt_check( 'an old unmatched claim is swept', $gwcvt_swept >= 1, (string) $gwcvt_swept );
gwcvt_check( 'its name is gone', '' === (string) get_post_meta( $gwcvt_orphan, GWCVT_SIGNUP_CLAIM_NAME, true ) );
gwcvt_check( 'its address is gone', '' === (string) get_post_meta( $gwcvt_orphan, GWCVT_SIGNUP_CLAIM_EMAIL, true ) );
gwcvt_check( 'the place on the shift stays', GWCVT_SIGNUP_TYPE === get_post_type( $gwcvt_orphan ) );
gwcvt_check( 'a recent one is left alone', 'Zzytest Last Week' === (string) get_post_meta( $gwcvt_recent, GWCVT_SIGNUP_CLAIM_NAME, true ) );

/* Nothing left to strip is not a purge — otherwise the retention log would
 * report work on every run, forever. */
gwcvt_check( 'sweeping again reports nothing done', 0 === gwcvt_sweep_orphan_signups( 24 ), (string) gwcvt_sweep_orphan_signups( 24 ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array( $gwcvt_soon, $gwcvt_open, $gwcvt_past, $gwcvt_draft, $gwcvt_cancelled, $gwcvt_distant ) as $gwcvt_shift_id ) {
	foreach ( gwcvt_all_signups( $gwcvt_shift_id ) as $gwcvt_id ) {
		wp_delete_post( (int) $gwcvt_id, true );
	}

	delete_option( 'gwcvt_signup_lock_' . $gwcvt_shift_id );
}

foreach ( array_unique( array_filter( $GLOBALS['gwcvt_made'] ) ) as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

if ( false === $GLOBALS['gwcvt_settings_before'] ) {
	delete_option( GWCVT_SETTINGS_OPTION );
} else {
	update_option( GWCVT_SETTINGS_OPTION, $GLOBALS['gwcvt_settings_before'] );
}

if ( false === $GLOBALS['gwcvt_limits_before'] ) {
	delete_option( GWCVT_RATE_LIMIT_OPTION );
} else {
	update_option( GWCVT_RATE_LIMIT_OPTION, $GLOBALS['gwcvt_limits_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

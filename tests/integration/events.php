<?php
/**
 * Events, against real WordPress.
 *
 * An event is a container over shifts, so most of what could break here is not
 * the event — it is whether everything that already worked on a shift still
 * works when that shift has a parent. So this file spends most of its assertions
 * on the seams: the reconciliation queries, the reminder pass, the privacy
 * tools, and the one message the public form is allowed to say back.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/events.php
 *
 * It creates its own fixtures and deletes them again, and puts back every
 * setting it changes.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly. wp eval-file runs this inside a function, so a top-level
 * assignment is a LOCAL while `global` in a helper reaches the real one — the
 * counter increments one and the summary reads the other, and the script prints
 * ALL PASS under a list of failures. That has happened. */
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
function gwcvt_ev_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [got: ' . $got . ']' : '' ), "\n";
}

/**
 * Catch mail instead of sending it.
 *
 * @param null  $short Whatever an earlier filter decided.
 * @param array $atts  to, subject, message, headers, attachments.
 * @return bool
 */
function gwcvt_ev_catch_mail( $short, $atts ) {
	$GLOBALS['gwcvt_mail'][] = $atts;

	return true;
}

/**
 * Remember a post so the teardown can remove it.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function gwcvt_ev_track( int $post_id ): int {
	$GLOBALS['gwcvt_made'][] = $post_id;

	return $post_id;
}

/**
 * Post to the event handler as a browser would.
 *
 * @param int   $event_id Event post ID.
 * @param int[] $slots    Shift IDs to tick.
 * @param array $extra    Anything else the form sends.
 * @param bool  $aged     Whether to stamp the form as rendered long enough ago.
 * @return string The result key the handler recorded.
 */
function gwcvt_ev_post( int $event_id, array $slots, array $extra = array(), bool $aged = true ): string {
	$GLOBALS['gwcvt_signup_result'] = '';
	$GLOBALS['gwcvt_signup_clash']  = array();

	$stamp = $aged
		? ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwcvt_form' ) )
		: gwcvt_form_stamp();

	$ticked = array();

	foreach ( $slots as $shift_id ) {
		$ticked[ (string) $shift_id ] = '1';
	}

	$_POST = array_merge(
		array(
			'gwcvt_event_submit' => '1',
			'gwcvt_signup_nonce' => wp_create_nonce( 'gwcvt_signup' ),
			'gwcvt_t'            => $stamp,
			'gwcvt_event'        => (string) $event_id,
			'gwcvt_slots'        => $ticked,
			'gwcvt_name'         => 'Dana Whitfield',
			'gwcvt_email'        => 'dana@example.org',
		),
		$extra
	);

	$_REQUEST = $_POST;

	gwcvt_handle_public_event_signup();

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $GLOBALS['gwcvt_signup_result'] ?? '' );
}

add_filter( 'pre_wp_mail', 'gwcvt_ev_catch_mail', 10, 2 );

/* ── Settings, remembered so they can be put back ────────────────────────── */

$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );
$GLOBALS['gwcvt_limits_before']   = get_option( GWCVT_RATE_LIMIT_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to merge over the defaults.
 */
function gwcvt_ev_settings( array $extra ): void {
	update_option( GWCVT_SETTINGS_OPTION, $extra );
	gwcvt_settings_cache( null, true );
}

$gwcvt_page = gwcvt_ev_track( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Volunteer', 'post_content' => '[volunteer_event id="0"]' ) ) );

gwcvt_ev_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => $gwcvt_page,
		'reminder_enabled'    => true,
		'reminder_lead_hours' => 96,
		'signup_horizon_days' => 60,
	)
);

/* ── A festival, two days, three roles ───────────────────────────────────── */

$gwcvt_soon = gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) );
$gwcvt_late = gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) );

$gwcvt_event = gwcvt_ev_track(
	wp_insert_post( array( 'post_type' => GWCVT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Fall Festival' ) )
);

update_post_meta( $gwcvt_event, GWCVT_EVENT_LOCATION, 'Riverside Park' );
update_post_meta( $gwcvt_event, GWCVT_EVENT_SUPERVISOR, 'Marcus Webb' );

$gwcvt_ctx = array( 'status' => 'publish', 'notify' => false, 'reason' => '', 'location' => 'Riverside Park', 'super' => 'Marcus Webb' );

gwcvt_save_event_grid(
	$gwcvt_event,
	array(
		array(
			'name'  => 'Greeter',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwcvt_soon, 'start' => '09:00', 'end' => '12:00', 'min' => 2, 'max' => 3 ),
				array( 'id' => 0, 'date' => $gwcvt_soon, 'start' => '13:00', 'end' => '15:00', 'min' => 2, 'max' => 3 ),
			),
		),
		array(
			'name'  => 'Kitchen',
			'notes' => 'Hot work and closed shoes, please.',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwcvt_soon, 'start' => '10:00', 'end' => '14:00', 'min' => 2, 'max' => 4 ),
			),
		),
		array(
			'name'  => 'Clear-down',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwcvt_late, 'start' => '15:00', 'end' => '17:00', 'min' => 2, 'max' => 4 ),
			),
		),
	),
	$gwcvt_ctx
);

gwcvt_event_refresh_dates( $gwcvt_event );

$gwcvt_slots = gwcvt_event_slot_ids( $gwcvt_event );

foreach ( $gwcvt_slots as $gwcvt_slot ) {
	gwcvt_ev_track( (int) $gwcvt_slot );
}

$gwcvt_greet_am = $gwcvt_slots[0];
$gwcvt_kitchen  = $gwcvt_slots[1];
$gwcvt_greet_pm = $gwcvt_slots[2];
$gwcvt_clear    = $gwcvt_slots[3];

gwcvt_ev_check( 'four slots on the event', 4 === count( $gwcvt_slots ), (string) count( $gwcvt_slots ) );
gwcvt_ev_check( 'the event spans both days', gwcvt_event_is_multi_day( $gwcvt_event ) );

/* ── THE TRAP: an event's slots must still reach the shift queries ───────── */

$gwcvt_all = gwcvt_shifts_between( array( 'from' => gwcvt_today(), 'to' => $gwcvt_late ) );
gwcvt_ev_check( 'event slots appear in gwcvt_shifts_between by default', in_array( $gwcvt_greet_am, $gwcvt_all, true ) );

$gwcvt_flat = gwcvt_shifts_between( array( 'from' => gwcvt_today(), 'to' => $gwcvt_late, 'parent' => 0 ) );
gwcvt_ev_check( 'parent => 0 leaves them out', ! in_array( $gwcvt_greet_am, $gwcvt_flat, true ) );

gwcvt_ev_check( 'an event slot is not on the flat public list', ! in_array( $gwcvt_greet_am, gwcvt_public_shift_ids(), true ) );
gwcvt_ev_check( 'an event slot is still signup-visible', gwcvt_shift_is_signup_visible( $gwcvt_greet_am ) );

$gwcvt_short = gwcvt_understaffed_shift_ids( 40 );
gwcvt_ev_check( 'an understaffed event slot reaches the daily summary', in_array( $gwcvt_greet_am, $gwcvt_short, true ) );

/* A slot on a DRAFT event cannot be booked by guessing its ID. */
wp_update_post( array( 'ID' => $gwcvt_event, 'post_status' => 'draft' ) );
gwcvt_ev_check( 'a slot on a draft event is refused', ! gwcvt_shift_is_signup_visible( $gwcvt_greet_am ) );
wp_update_post( array( 'ID' => $gwcvt_event, 'post_status' => 'publish' ) );

/* ── The public form: one submission, several slots ──────────────────────── */

$GLOBALS['gwcvt_mail'] = array();

$gwcvt_result = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_am, $gwcvt_greet_pm ) );
gwcvt_ev_check( 'two non-clashing slots are accepted', 'accepted' === $gwcvt_result, $gwcvt_result );
gwcvt_ev_check( 'both slots were taken', 1 === gwcvt_shift_filled( $gwcvt_greet_am ) && 1 === gwcvt_shift_filled( $gwcvt_greet_pm ) );

do_action( 'shutdown' );

gwcvt_ev_check( 'one confirmation, not two', 1 === count( $GLOBALS['gwcvt_mail'] ), (string) count( $GLOBALS['gwcvt_mail'] ) );

$gwcvt_body = (string) ( $GLOBALS['gwcvt_mail'][0]['message'] ?? '' );
gwcvt_ev_check( 'the confirmation names both roles', false !== strpos( $gwcvt_body, 'Greeter' ) );
gwcvt_ev_check( 'the confirmation carries a link per slot', 2 === substr_count( $gwcvt_body, 'gwcvt_signup=' ), (string) substr_count( $gwcvt_body, 'gwcvt_signup=' ) );

/* ── The message table is closed, and carries no count ───────────────────── */

$gwcvt_accepted = gwcvt_signup_message( 'accepted' );

$GLOBALS['gwcvt_mail'] = array();
$gwcvt_honeypot = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_kitchen ), array( 'gwcvt_website' => 'http://spam.example' ) );
gwcvt_ev_check( 'a honeypot hit answers "accepted"', 'accepted' === $gwcvt_honeypot, $gwcvt_honeypot );
gwcvt_ev_check( 'and wrote nothing', 0 === gwcvt_shift_filled( $gwcvt_kitchen ) );

gwcvt_ev_check(
	'accepted and honeypotted are byte-identical',
	$gwcvt_accepted === gwcvt_signup_message( $gwcvt_honeypot )
);

gwcvt_ev_check(
	'no message the public form can return carries a digit',
	1 !== preg_match( '/\d/', implode( ' ', array_map( 'gwcvt_signup_message', array( 'accepted', 'incomplete', 'unavailable', 'expired', 'clash', 'too-many' ) ) ) )
);

/* ── The clash warning ───────────────────────────────────────────────────── */

$gwcvt_result = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_am, $gwcvt_kitchen ) );
gwcvt_ev_check( 'overlapping slots in one submission warn', 'clash' === $gwcvt_result, $gwcvt_result );
gwcvt_ev_check( 'the clash names both slots', 2 === count( (array) $GLOBALS['gwcvt_signup_clash'] ) );
gwcvt_ev_check( 'and nothing was written', 0 === gwcvt_shift_filled( $gwcvt_kitchen ) );

$gwcvt_result = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_am, $gwcvt_kitchen ), array( 'gwcvt_clash_ok' => '1' ) );
gwcvt_ev_check( 'confirming the clash lets it through', 'accepted' === $gwcvt_result, $gwcvt_result );
gwcvt_ev_check( 'and the second slot was taken', 1 === gwcvt_shift_filled( $gwcvt_kitchen ) );

/* Touching is not overlapping: 13:00-15:00 after 10:00-14:00 does clash, but
 * 09:00-12:00 and 13:00-15:00 do not. */
gwcvt_ev_check( 'touching slots do not clash', ! gwcvt_shifts_overlap( $gwcvt_greet_am, $gwcvt_greet_pm ) );

/* The clash check runs before the honeypot, so the same POST gets the same
 * answer whether or not the honeypot is filled — otherwise the difference is a
 * honeypot detector. */
$gwcvt_a = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_pm, $gwcvt_kitchen ) );
$gwcvt_b = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_pm, $gwcvt_kitchen ), array( 'gwcvt_website' => 'http://spam.example' ) );
gwcvt_ev_check( 'a clashing POST answers the same with and without the honeypot', $gwcvt_a === $gwcvt_b, $gwcvt_a . ' / ' . $gwcvt_b );

/* ── The cap ─────────────────────────────────────────────────────────────── */

add_filter( 'gwcvt_event_signup_limit', 'gwcvt_ev_tiny_limit' );

/**
 * A cap of one, to exercise the refusal.
 *
 * @return int
 */
function gwcvt_ev_tiny_limit(): int {
	return 1;
}

$gwcvt_result = gwcvt_ev_post( $gwcvt_event, array( $gwcvt_greet_am, $gwcvt_greet_pm ) );
gwcvt_ev_check( 'more slots than the cap is refused', 'too-many' === $gwcvt_result, $gwcvt_result );
remove_filter( 'gwcvt_event_signup_limit', 'gwcvt_ev_tiny_limit' );

/* ── A slot from another event cannot be reached ─────────────────────────── */

$gwcvt_other = gwcvt_ev_track( wp_insert_post( array( 'post_type' => GWCVT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Other' ) ) );
gwcvt_save_event_grid( $gwcvt_other, array( array( 'name' => 'Sorting', 'slots' => array( array( 'id' => 0, 'date' => $gwcvt_soon, 'start' => '09:00', 'end' => '10:00', 'min' => 1, 'max' => 2 ) ) ) ), $gwcvt_ctx );
$gwcvt_foreign = gwcvt_ev_track( gwcvt_event_slot_ids( $gwcvt_other )[0] );

gwcvt_ev_post( $gwcvt_event, array( $gwcvt_foreign ) );
gwcvt_ev_check( 'a slot on another event is not booked through this one', 0 === gwcvt_shift_filled( $gwcvt_foreign ) );

/* ── Reminders: one message, and the flag on every slot it named ─────────── */

/* Flush anything the signups above queued, THEN start counting. A confirmation
 * still sitting in the queue would be sent by the shutdown below and read as a
 * second reminder. */
do_action( 'shutdown' );
$GLOBALS['gwcvt_mail'] = array();

/* ── Make this event the only thing a reminder pass can find ─────────────────
 * gwcvt_run_reminders() sweeps the whole site and returns a site-wide count, so
 * this assertion — three slots on one day produce ONE message — is only true of
 * a database holding nothing else due a reminder.
 *
 * That was an accident of running against an empty install. Seed the demo
 * fixture first, as CLAUDE.md tells you to, and its upcoming Saturdays are due
 * a reminder too: the count came back 4 and four assertions failed, on the first
 * run only, because a reminder is sent once and the flag then hides the problem
 * from every run after it. A test that passes the second time is worse than one
 * that fails.
 *
 * So every signup that is not on this event is marked as already reminded. It
 * says what the assertion always meant.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwcvt_mine = array();

foreach ( gwcvt_event_slot_ids( $gwcvt_event ) as $gwcvt_slot ) {
	foreach ( gwcvt_shift_signup_ids( (int) $gwcvt_slot ) as $gwcvt_signup ) {
		$gwcvt_mine[] = (int) $gwcvt_signup;
		delete_post_meta( (int) $gwcvt_signup, GWCVT_SIGNUP_REMINDED );
	}
}

$gwcvt_everyone = get_posts(
	array(
		'post_type'      => GWCVT_SIGNUP_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( (array) $gwcvt_everyone as $gwcvt_signup ) {
	if ( ! in_array( (int) $gwcvt_signup, $gwcvt_mine, true ) ) {
		update_post_meta( (int) $gwcvt_signup, GWCVT_SIGNUP_REMINDED, gmdate( 'Y-m-d H:i:s' ) );
	}
}

$gwcvt_sent = gwcvt_run_reminders();
do_action( 'shutdown' );

gwcvt_ev_check( 'one reminder for three slots on one day', 1 === $gwcvt_sent, (string) $gwcvt_sent );
gwcvt_ev_check( 'one message went out', 1 === count( $GLOBALS['gwcvt_mail'] ), (string) count( $GLOBALS['gwcvt_mail'] ) );

$gwcvt_reminder = (string) ( $GLOBALS['gwcvt_mail'][0]['message'] ?? '' );
gwcvt_ev_check( 'the reminder names Kitchen', false !== strpos( $gwcvt_reminder, 'Kitchen' ) );
gwcvt_ev_check( 'the reminder names Greeter', false !== strpos( $gwcvt_reminder, 'Greeter' ) );

/* THE INVARIANT: a slot's flag is set if and only if that slot was named. */
$gwcvt_named   = 0;
$gwcvt_flagged = 0;

foreach ( array( $gwcvt_greet_am, $gwcvt_greet_pm, $gwcvt_kitchen ) as $gwcvt_slot ) {
	foreach ( gwcvt_shift_signup_ids( (int) $gwcvt_slot ) as $gwcvt_signup ) {
		if ( '' !== (string) get_post_meta( (int) $gwcvt_signup, GWCVT_SIGNUP_REMINDED, true ) ) {
			++$gwcvt_flagged;
		}
	}

	++$gwcvt_named;
}

gwcvt_ev_check( 'every slot named in the message is flagged', 3 === $gwcvt_flagged, (string) $gwcvt_flagged );

/* And the far slot, outside the window, keeps its flag clear — marking one
 * without naming it means it is NEVER reminded about, silently. */
$gwcvt_clear_flagged = false;

foreach ( gwcvt_shift_signup_ids( $gwcvt_clear ) as $gwcvt_signup ) {
	if ( '' !== (string) get_post_meta( (int) $gwcvt_signup, GWCVT_SIGNUP_REMINDED, true ) ) {
		$gwcvt_clear_flagged = true;
	}
}

gwcvt_ev_check( 'a slot outside the window was not flagged', ! $gwcvt_clear_flagged );

/* Running again sends nothing. */
$GLOBALS['gwcvt_mail'] = array();
$gwcvt_again = gwcvt_run_reminders();
do_action( 'shutdown' );
gwcvt_ev_check( 'a second pass sends nothing', 0 === $gwcvt_again, (string) $gwcvt_again );

/* ── Privacy reaches every slot ──────────────────────────────────────────── */

$gwcvt_found = gwcvt_signups_by_claim_email( 'dana@example.org' );
gwcvt_ev_check( 'the exporter finds every event signup', count( $gwcvt_found ) >= 3, (string) count( $gwcvt_found ) );

foreach ( $gwcvt_found as $gwcvt_signup ) {
	gwcvt_clear_signup_claims( (int) $gwcvt_signup );
}

$gwcvt_left = 0;

foreach ( $gwcvt_found as $gwcvt_signup ) {
	if ( '' !== (string) get_post_meta( (int) $gwcvt_signup, GWCVT_SIGNUP_CLAIM_EMAIL, true ) ) {
		++$gwcvt_left;
	}
}

gwcvt_ev_check( 'the eraser clears every claim', 0 === $gwcvt_left, (string) $gwcvt_left );
gwcvt_ev_check( 'and the places on the roster survive', 1 === gwcvt_shift_filled( $gwcvt_greet_am ) );

/* ── Cancelling an event cancels its slots and keeps their rosters ───────── */

$gwcvt_before = gwcvt_shift_filled( $gwcvt_greet_am );

foreach ( gwcvt_event_slot_ids( $gwcvt_event ) as $gwcvt_slot ) {
	wp_update_post( array( 'ID' => (int) $gwcvt_slot, 'post_status' => GWCVT_SHIFT_CANCELLED ) );
}

wp_update_post( array( 'ID' => $gwcvt_event, 'post_status' => GWCVT_EVENT_CANCELLED ) );

gwcvt_ev_check( 'a cancelled event reads as cancelled', gwcvt_event_is_cancelled( $gwcvt_event ), (string) get_post_status( $gwcvt_event ) );

/* wp_posts.post_status is varchar(20). A longer status is silently refused and
 * the row keeps the one it had, so "call it off" reports success and the event
 * goes on taking signups. See the note on GWCVT_EVENT_CANCELLED. */
gwcvt_ev_check( 'every status this plugin registers fits the column', 20 >= max( array_map( 'strlen', array( GWCVT_EVENT_CANCELLED, GWCVT_SHIFT_CANCELLED, GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ) ) ) );
gwcvt_ev_check( 'its slots keep their rosters', $gwcvt_before === count( gwcvt_shift_signup_ids( $gwcvt_greet_am, array( 'publish' ) ) ) );

/* ── Teardown ────────────────────────────────────────────────────────────── */

remove_filter( 'pre_wp_mail', 'gwcvt_ev_catch_mail', 10 );

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_post ) {
	foreach ( get_posts( array( 'post_type' => GWCVT_SIGNUP_TYPE, 'post_parent' => (int) $gwcvt_post, 'post_status' => 'any', 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $gwcvt_signup ) {
		wp_delete_post( (int) $gwcvt_signup, true );
	}

	wp_delete_post( (int) $gwcvt_post, true );
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

gwcvt_settings_cache( null, true );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? 'ALL PASS' : $GLOBALS['gwcvt_failures'] . ' FAILED' ), "\n";

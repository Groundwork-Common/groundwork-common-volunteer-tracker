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
 *   bin/wpenv run cli -- wp eval-file \
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
function gwc_vt_ev_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
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
function gwc_vt_ev_catch_mail( $short, $atts ) {
	$GLOBALS['gwc_vt_mail'][] = $atts;

	return true;
}

/**
 * Remember a post so the teardown can remove it.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function gwc_vt_ev_track( int $post_id ): int {
	$GLOBALS['gwc_vt_made'][] = $post_id;

	return $post_id;
}

/**
 * Post to the event handler as a browser would.
 *
 * @param int   $event_id Event post ID.
 * @param int[] $slots    Shift IDs to select.
 * @param array $extra    Anything else the form sends.
 * @param bool  $aged     Whether to stamp the form as rendered long enough ago.
 * @return string The result key the handler recorded.
 */
function gwc_vt_ev_post( int $event_id, array $slots, array $extra = array(), bool $aged = true ): string {
	$GLOBALS['gwc_vt_signup_result'] = '';
	$GLOBALS['gwc_vt_signup_clash']  = array();

	$stamp = $aged
		? ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwc_vt_form' ) )
		: gwc_vt_form_stamp();

	$selected = array();

	foreach ( $slots as $shift_id ) {
		$selected[ (string) $shift_id ] = '1';
	}

	$_POST = array_merge(
		array(
			'gwc_vt_event_submit' => '1',
			'gwc_vt_signup_nonce' => wp_create_nonce( 'gwc_vt_signup' ),
			'gwc_vt_t'            => $stamp,
			'gwc_vt_event'        => (string) $event_id,
			'gwc_vt_slots'        => $selected,
			'gwc_vt_name'         => 'Dana Whitfield',
			'gwc_vt_email'        => 'dana@example.org',
		),
		$extra
	);

	$_REQUEST = $_POST;

	gwc_vt_handle_public_event_signup();

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $GLOBALS['gwc_vt_signup_result'] ?? '' );
}

add_filter( 'pre_wp_mail', 'gwc_vt_ev_catch_mail', 10, 2 );

/* ── Settings, remembered so they can be put back ────────────────────────── */

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );
$GLOBALS['gwc_vt_limits_before']   = get_option( GWC_VT_RATE_LIMIT_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to merge over the defaults.
 */
function gwc_vt_ev_settings( array $extra ): void {
	update_option( GWC_VT_SETTINGS_OPTION, $extra );
	gwc_vt_settings_cache( null, true );
}

$gwc_vt_page = gwc_vt_ev_track( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Volunteer', 'post_content' => '[gwc_vt_event_grid id="0"]' ) ) );

gwc_vt_ev_settings(
	array(
		'shifts_enabled'      => true,
		'signup_enabled'      => true,
		'schedule_page'       => $gwc_vt_page,
		'reminder_enabled'    => true,
		'reminder_lead_hours' => 96,
		'signup_horizon_days' => 60,
	)
);

/* ── A festival, two days, three roles ───────────────────────────────────── */

$gwc_vt_soon = gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) );
$gwc_vt_late = gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) );

$gwc_vt_event = gwc_vt_ev_track(
	wp_insert_post( array( 'post_type' => GWC_VT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Fall Festival' ) )
);

update_post_meta( $gwc_vt_event, GWC_VT_EVENT_LOCATION, 'Riverside Park' );
update_post_meta( $gwc_vt_event, GWC_VT_EVENT_SUPERVISOR, 'Marcus Webb' );

$gwc_vt_ctx = array( 'status' => 'publish', 'notify' => false, 'reason' => '', 'location' => 'Riverside Park', 'super' => 'Marcus Webb' );

gwc_vt_save_event_grid(
	$gwc_vt_event,
	array(
		array(
			'name'  => 'Greeter',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwc_vt_soon, 'start' => '09:00', 'end' => '12:00', 'min' => 2, 'max' => 3 ),
				array( 'id' => 0, 'date' => $gwc_vt_soon, 'start' => '13:00', 'end' => '15:00', 'min' => 2, 'max' => 3 ),
			),
		),
		array(
			'name'  => 'Kitchen',
			'notes' => 'Hot work and closed shoes, please.',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwc_vt_soon, 'start' => '10:00', 'end' => '14:00', 'min' => 2, 'max' => 4 ),
			),
		),
		array(
			'name'  => 'Clear-down',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwc_vt_late, 'start' => '15:00', 'end' => '17:00', 'min' => 2, 'max' => 4 ),
			),
		),
	),
	$gwc_vt_ctx
);

gwc_vt_event_refresh_dates( $gwc_vt_event );

$gwc_vt_slots = gwc_vt_event_slot_ids( $gwc_vt_event );

foreach ( $gwc_vt_slots as $gwc_vt_slot ) {
	gwc_vt_ev_track( (int) $gwc_vt_slot );
}

$gwc_vt_greet_am = $gwc_vt_slots[0];
$gwc_vt_kitchen  = $gwc_vt_slots[1];
$gwc_vt_greet_pm = $gwc_vt_slots[2];
$gwc_vt_clear    = $gwc_vt_slots[3];

gwc_vt_ev_check( 'four slots on the event', 4 === count( $gwc_vt_slots ), (string) count( $gwc_vt_slots ) );
gwc_vt_ev_check( 'the event spans both days', gwc_vt_event_is_multi_day( $gwc_vt_event ) );

/* ── THE TRAP: an event's slots must still reach the shift queries ───────── */

$gwc_vt_all = gwc_vt_shifts_between( array( 'from' => gwc_vt_today(), 'to' => $gwc_vt_late ) );
gwc_vt_ev_check( 'event slots appear in gwc_vt_shifts_between by default', in_array( $gwc_vt_greet_am, $gwc_vt_all, true ) );

$gwc_vt_flat = gwc_vt_shifts_between( array( 'from' => gwc_vt_today(), 'to' => $gwc_vt_late, 'parent' => 0 ) );
gwc_vt_ev_check( 'parent => 0 leaves them out', ! in_array( $gwc_vt_greet_am, $gwc_vt_flat, true ) );

gwc_vt_ev_check( 'an event slot is not on the flat public list', ! in_array( $gwc_vt_greet_am, gwc_vt_public_shift_ids(), true ) );
gwc_vt_ev_check( 'an event slot is still signup-visible', gwc_vt_shift_is_signup_visible( $gwc_vt_greet_am ) );

$gwc_vt_short = gwc_vt_understaffed_shift_ids( 40 );
gwc_vt_ev_check( 'an understaffed event slot reaches the daily summary', in_array( $gwc_vt_greet_am, $gwc_vt_short, true ) );

/* A slot on a DRAFT event cannot be booked by guessing its ID. */
wp_update_post( array( 'ID' => $gwc_vt_event, 'post_status' => 'draft' ) );
gwc_vt_ev_check( 'a slot on a draft event is refused', ! gwc_vt_shift_is_signup_visible( $gwc_vt_greet_am ) );
wp_update_post( array( 'ID' => $gwc_vt_event, 'post_status' => 'publish' ) );

/* ── The public form: one submission, several slots ──────────────────────── */

$GLOBALS['gwc_vt_mail'] = array();

$gwc_vt_result = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_am, $gwc_vt_greet_pm ) );
gwc_vt_ev_check( 'two non-clashing slots are accepted', 'accepted' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_ev_check( 'both slots were taken', 1 === gwc_vt_shift_filled( $gwc_vt_greet_am ) && 1 === gwc_vt_shift_filled( $gwc_vt_greet_pm ) );

do_action( 'shutdown' );

gwc_vt_ev_check( 'one confirmation, not two', 1 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );

$gwc_vt_body = (string) ( $GLOBALS['gwc_vt_mail'][0]['message'] ?? '' );
gwc_vt_ev_check( 'the confirmation names both roles', false !== strpos( $gwc_vt_body, 'Greeter' ) );
gwc_vt_ev_check( 'the confirmation carries a link per slot', 2 === substr_count( $gwc_vt_body, 'gwc_vt_signup=' ), (string) substr_count( $gwc_vt_body, 'gwc_vt_signup=' ) );

/* ── The message table is closed, and carries no count ───────────────────── */

$gwc_vt_accepted = gwc_vt_signup_message( 'accepted' );

$GLOBALS['gwc_vt_mail'] = array();
$gwc_vt_honeypot = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_kitchen ), array( 'gwc_vt_website' => 'http://spam.example' ) );
gwc_vt_ev_check( 'a honeypot hit answers "accepted"', 'accepted' === $gwc_vt_honeypot, $gwc_vt_honeypot );
gwc_vt_ev_check( 'and wrote nothing', 0 === gwc_vt_shift_filled( $gwc_vt_kitchen ) );

gwc_vt_ev_check(
	'accepted and honeypotted are byte-identical',
	$gwc_vt_accepted === gwc_vt_signup_message( $gwc_vt_honeypot )
);

gwc_vt_ev_check(
	'no message the public form can return carries a digit',
	1 !== preg_match( '/\d/', implode( ' ', array_map( 'gwc_vt_signup_message', array( 'accepted', 'incomplete', 'unavailable', 'expired', 'clash', 'too-many' ) ) ) )
);

/* ── The clash warning ───────────────────────────────────────────────────── */

$gwc_vt_result = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_am, $gwc_vt_kitchen ) );
gwc_vt_ev_check( 'overlapping slots in one submission warn', 'clash' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_ev_check( 'the clash names both slots', 2 === count( (array) $GLOBALS['gwc_vt_signup_clash'] ) );
gwc_vt_ev_check( 'and nothing was written', 0 === gwc_vt_shift_filled( $gwc_vt_kitchen ) );

$gwc_vt_result = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_am, $gwc_vt_kitchen ), array( 'gwc_vt_clash_ok' => '1' ) );
gwc_vt_ev_check( 'confirming the clash lets it through', 'accepted' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_ev_check( 'and the second slot was taken', 1 === gwc_vt_shift_filled( $gwc_vt_kitchen ) );

/* Touching is not overlapping: 13:00-15:00 after 10:00-14:00 does clash, but
 * 09:00-12:00 and 13:00-15:00 do not. */
gwc_vt_ev_check( 'touching slots do not clash', ! gwc_vt_shifts_overlap( $gwc_vt_greet_am, $gwc_vt_greet_pm ) );

/* The clash check runs before the honeypot, so the same POST gets the same
 * answer whether or not the honeypot is filled — otherwise the difference is a
 * honeypot detector. */
$gwc_vt_a = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_pm, $gwc_vt_kitchen ) );
$gwc_vt_b = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_pm, $gwc_vt_kitchen ), array( 'gwc_vt_website' => 'http://spam.example' ) );
gwc_vt_ev_check( 'a clashing POST answers the same with and without the honeypot', $gwc_vt_a === $gwc_vt_b, $gwc_vt_a . ' / ' . $gwc_vt_b );

/* ── The cap ─────────────────────────────────────────────────────────────── */

add_filter( 'gwc_vt_event_signup_limit', 'gwc_vt_ev_tiny_limit' );

/**
 * A cap of one, to exercise the refusal.
 *
 * @return int
 */
function gwc_vt_ev_tiny_limit(): int {
	return 1;
}

$gwc_vt_result = gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_greet_am, $gwc_vt_greet_pm ) );
gwc_vt_ev_check( 'more slots than the cap is refused', 'too-many' === $gwc_vt_result, $gwc_vt_result );
remove_filter( 'gwc_vt_event_signup_limit', 'gwc_vt_ev_tiny_limit' );

/* ── A slot from another event cannot be reached ─────────────────────────── */

$gwc_vt_other = gwc_vt_ev_track( wp_insert_post( array( 'post_type' => GWC_VT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Other' ) ) );
gwc_vt_save_event_grid( $gwc_vt_other, array( array( 'name' => 'Sorting', 'slots' => array( array( 'id' => 0, 'date' => $gwc_vt_soon, 'start' => '09:00', 'end' => '10:00', 'min' => 1, 'max' => 2 ) ) ) ), $gwc_vt_ctx );
$gwc_vt_foreign = gwc_vt_ev_track( gwc_vt_event_slot_ids( $gwc_vt_other )[0] );

gwc_vt_ev_post( $gwc_vt_event, array( $gwc_vt_foreign ) );
gwc_vt_ev_check( 'a slot on another event is not booked through this one', 0 === gwc_vt_shift_filled( $gwc_vt_foreign ) );

/* ── Reminders: one message, and the flag on every slot it named ─────────── */

/* Flush anything the signups above queued, THEN start counting. A confirmation
 * still sitting in the queue would be sent by the shutdown below and read as a
 * second reminder. */
do_action( 'shutdown' );
$GLOBALS['gwc_vt_mail'] = array();

/* ── Make this event the only thing a reminder pass can find ─────────────────
 * gwc_vt_run_reminders() sweeps the whole site and returns a site-wide count, so
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
$gwc_vt_mine = array();

foreach ( gwc_vt_event_slot_ids( $gwc_vt_event ) as $gwc_vt_slot ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot ) as $gwc_vt_signup ) {
		$gwc_vt_mine[] = (int) $gwc_vt_signup;
		delete_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_REMINDED );
	}
}

$gwc_vt_everyone = get_posts(
	array(
		'post_type'      => GWC_VT_SIGNUP_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( (array) $gwc_vt_everyone as $gwc_vt_signup ) {
	if ( ! in_array( (int) $gwc_vt_signup, $gwc_vt_mine, true ) ) {
		update_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_REMINDED, gmdate( 'Y-m-d H:i:s' ) );
	}
}

$gwc_vt_sent = gwc_vt_run_reminders();
do_action( 'shutdown' );

gwc_vt_ev_check( 'one reminder for three slots on one day', 1 === $gwc_vt_sent, (string) $gwc_vt_sent );
gwc_vt_ev_check( 'one message went out', 1 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );

$gwc_vt_reminder = (string) ( $GLOBALS['gwc_vt_mail'][0]['message'] ?? '' );
gwc_vt_ev_check( 'the reminder names Kitchen', false !== strpos( $gwc_vt_reminder, 'Kitchen' ) );
gwc_vt_ev_check( 'the reminder names Greeter', false !== strpos( $gwc_vt_reminder, 'Greeter' ) );

/* THE INVARIANT: a slot's flag is set if and only if that slot was named. */
$gwc_vt_named   = 0;
$gwc_vt_flagged = 0;

foreach ( array( $gwc_vt_greet_am, $gwc_vt_greet_pm, $gwc_vt_kitchen ) as $gwc_vt_slot ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot ) as $gwc_vt_signup ) {
		if ( '' !== (string) get_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_REMINDED, true ) ) {
			++$gwc_vt_flagged;
		}
	}

	++$gwc_vt_named;
}

gwc_vt_ev_check( 'every slot named in the message is flagged', 3 === $gwc_vt_flagged, (string) $gwc_vt_flagged );

/* And the far slot, outside the window, keeps its flag clear — marking one
 * without naming it means it is NEVER reminded about, silently. */
$gwc_vt_clear_flagged = false;

foreach ( gwc_vt_shift_signup_ids( $gwc_vt_clear ) as $gwc_vt_signup ) {
	if ( '' !== (string) get_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_REMINDED, true ) ) {
		$gwc_vt_clear_flagged = true;
	}
}

gwc_vt_ev_check( 'a slot outside the window was not flagged', ! $gwc_vt_clear_flagged );

/* Running again sends nothing. */
$GLOBALS['gwc_vt_mail'] = array();
$gwc_vt_again = gwc_vt_run_reminders();
do_action( 'shutdown' );
gwc_vt_ev_check( 'a second pass sends nothing', 0 === $gwc_vt_again, (string) $gwc_vt_again );

/* ── Privacy reaches every slot ──────────────────────────────────────────── */

$gwc_vt_found = gwc_vt_signups_by_claim_email( 'dana@example.org' );
gwc_vt_ev_check( 'the exporter finds every event signup', count( $gwc_vt_found ) >= 3, (string) count( $gwc_vt_found ) );

foreach ( $gwc_vt_found as $gwc_vt_signup ) {
	gwc_vt_clear_signup_claims( (int) $gwc_vt_signup );
}

$gwc_vt_left = 0;

foreach ( $gwc_vt_found as $gwc_vt_signup ) {
	if ( '' !== (string) get_post_meta( (int) $gwc_vt_signup, GWC_VT_SIGNUP_CLAIM_EMAIL, true ) ) {
		++$gwc_vt_left;
	}
}

gwc_vt_ev_check( 'the eraser clears every claim', 0 === $gwc_vt_left, (string) $gwc_vt_left );
gwc_vt_ev_check( 'and the places on the roster survive', 1 === gwc_vt_shift_filled( $gwc_vt_greet_am ) );

/* ── Cancelling an event cancels its slots and keeps their rosters ───────── */

$gwc_vt_before = gwc_vt_shift_filled( $gwc_vt_greet_am );

foreach ( gwc_vt_event_slot_ids( $gwc_vt_event ) as $gwc_vt_slot ) {
	wp_update_post( array( 'ID' => (int) $gwc_vt_slot, 'post_status' => GWC_VT_SHIFT_CANCELLED ) );
}

wp_update_post( array( 'ID' => $gwc_vt_event, 'post_status' => GWC_VT_EVENT_CANCELLED ) );

gwc_vt_ev_check( 'a cancelled event reads as cancelled', gwc_vt_event_is_cancelled( $gwc_vt_event ), (string) get_post_status( $gwc_vt_event ) );

/* wp_posts.post_status is varchar(20). A longer status is silently refused and
 * the row keeps the one it had, so "call it off" reports success and the event
 * goes on taking signups. See the note on GWC_VT_EVENT_CANCELLED. */
gwc_vt_ev_check( 'every status this plugin registers fits the column', 20 >= max( array_map( 'strlen', array( GWC_VT_EVENT_CANCELLED, GWC_VT_SHIFT_CANCELLED, GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) ) );
gwc_vt_ev_check( 'its slots keep their rosters', $gwc_vt_before === count( gwc_vt_shift_signup_ids( $gwc_vt_greet_am, array( 'publish' ) ) ) );

/* ── Teardown ────────────────────────────────────────────────────────────── */

remove_filter( 'pre_wp_mail', 'gwc_vt_ev_catch_mail', 10 );

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_post ) {
	/* Every registered status, not 'any' — which skips the waiting-list and
	 * withdrawn signups this is here to collect. See tests/seed.php. */
	foreach ( get_posts( array( 'post_type' => GWC_VT_SIGNUP_TYPE, 'post_parent' => (int) $gwc_vt_post, 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $gwc_vt_signup ) {
		wp_delete_post( (int) $gwc_vt_signup, true );
	}

	wp_delete_post( (int) $gwc_vt_post, true );
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

gwc_vt_settings_cache( null, true );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

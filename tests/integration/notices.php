<?php
/**
 * Reminders, notices and the daily summary, against real WordPress.
 *
 * These run on a schedule with nobody watching, which is what makes them worth
 * testing carefully: a reminder pass that double-sends does so every hour, and a
 * digest that fatals at three in the morning simply stops existing.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/notices.php
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
 * @param null  $short Whatever an earlier filter decided.
 * @param array $atts  to, subject, message, headers, attachments.
 * @return bool
 */
function gwcvt_test_catch_mail( $short, $atts ) {
	$GLOBALS['gwcvt_mail'][] = $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'gwcvt_test_catch_mail', 10, 2 );

/**
 * Everything caught since the last time this was called.
 *
 * @return array
 */
function gwcvt_drain_mail(): array {
	$mail                  = $GLOBALS['gwcvt_mail'];
	$GLOBALS['gwcvt_mail'] = array();

	return $mail;
}

$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to store.
 */
function gwcvt_set_settings( array $extra ): void {
	update_option( GWCVT_SETTINGS_OPTION, $extra );
	gwcvt_settings_cache( null, true );
}

/**
 * The settings this script runs with, plus whatever is being varied.
 *
 * @param array $extra Overrides.
 * @return array
 */
function gwcvt_base_settings( array $extra = array() ): array {
	return array_merge(
		array(
			'shifts_enabled'   => true,
			'org_name'         => 'Zzytest Riverbend Food Bank',
			'org_contact'      => 'volunteers@zzytest.example',
			'retention_months' => 0,

			/* Pinned, because every message a volunteer gets carries a link to
			 * manage their own booking and that link needs somewhere to go. A
			 * site with shifts on and no public page gets no link at all rather
			 * than one pointing at the front page — see gwcvt_signup_manage_url(),
			 * which this file caught sending exactly that. */
			'signup_enabled'   => true,
			'schedule_page'    => (int) ( $GLOBALS['gwcvt_schedule_page'] ?? 0 ),
		),
		$extra
	);
}

/**
 * Create a shift starting a given number of hours from now.
 *
 * @param float $hours_away How far ahead it starts.
 * @param int   $min        How many people it needs.
 * @return int
 */
function gwcvt_make_shift_in( float $hours_away, int $min = 0 ): int {
	$starts = time() + (int) round( $hours_away * HOUR_IN_SECONDS );
	$local  = new DateTimeImmutable( '@' . $starts );
	$local  = $local->setTimezone( gwcvt_timezone() );

	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_SHIFT_DATE, $local->format( 'Y-m-d' ) );
	update_post_meta( $id, GWCVT_SHIFT_START, $local->format( 'H:i' ) );
	update_post_meta( $id, GWCVT_SHIFT_END, $local->modify( '+3 hours' )->format( 'H:i' ) );
	update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWCVT_SHIFT_LOCATION, 'Zzytest main warehouse' );
	update_post_meta( $id, GWCVT_SHIFT_SUPERVISOR, 'Dana Reyes' );
	update_post_meta( $id, GWCVT_SHIFT_MIN, $min );

	gwcvt_retitle_shift( (int) $id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Put a stranger on a shift and remember the signup.
 *
 * @param int    $shift_id Shift post ID.
 * @param string $email    Their address.
 * @param string $name     Their name.
 * @return int
 */
function gwcvt_make_signup( int $shift_id, string $email, string $name = 'Zzytest Somebody' ): int {
	$id = gwcvt_add_signup(
		$shift_id,
		array(
			'claim_name'  => $name,
			'claim_email' => $email,
			'source'      => 'self',
		)
	);

	$GLOBALS['gwcvt_made'][] = $id;

	/* The confirmation the signup queued is not what this file is about. */
	$GLOBALS['gwcvt_pending_mail'] = array();
	gwcvt_drain_mail();

	return (int) $id;
}

$GLOBALS['gwcvt_schedule_page'] = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest shifts',
		'post_content' => '[volunteer_shifts]',
	)
);

$GLOBALS['gwcvt_made'][] = $GLOBALS['gwcvt_schedule_page'];

/* ── The events exist only while the feature does ────────────────────────── */

gwcvt_set_settings( array( 'shifts_enabled' => false ) );
gwcvt_schedule_shift_events();

gwcvt_check( 'no reminder event while shifts are off', false === wp_next_scheduled( GWCVT_REMINDER_EVENT ) );
gwcvt_check( 'no digest event while shifts are off', false === wp_next_scheduled( GWCVT_DIGEST_EVENT ) );

gwcvt_set_settings( gwcvt_base_settings() );
gwcvt_schedule_shift_events();

gwcvt_check( 'turning shifts on schedules the reminder pass', false !== wp_next_scheduled( GWCVT_REMINDER_EVENT ) );
gwcvt_check( 'and the daily summary', false !== wp_next_scheduled( GWCVT_DIGEST_EVENT ) );

/* ── The reminder pass ───────────────────────────────────────────────────── */

$gwcvt_soon    = gwcvt_make_shift_in( 30 );   // inside a 48-hour lead.
$gwcvt_distant = gwcvt_make_shift_in( 200 );  // well outside it.
$gwcvt_gone    = gwcvt_make_shift_in( -5 );   // already started.

$gwcvt_a = gwcvt_make_signup( $gwcvt_soon, 'zzytest-a@example.test', 'Zzytest Ana Ferreira' );
$gwcvt_b = gwcvt_make_signup( $gwcvt_distant, 'zzytest-b@example.test' );
$gwcvt_c = gwcvt_make_signup( $gwcvt_gone, 'zzytest-c@example.test' );

gwcvt_set_settings( gwcvt_base_settings( array( 'reminder_enabled' => false ) ) );

gwcvt_check( 'nothing is sent while reminders are off', 0 === gwcvt_run_reminders() );
gwcvt_check( 'and no timestamp was written', '' === (string) get_post_meta( $gwcvt_a, GWCVT_SIGNUP_REMINDED, true ) );

gwcvt_set_settings(
	gwcvt_base_settings(
		array(
			'reminder_enabled'    => true,
			'reminder_lead_hours' => 48,
		)
	)
);

$gwcvt_sent = gwcvt_run_reminders();
$gwcvt_mail = gwcvt_drain_mail();

gwcvt_check( 'exactly the shift inside the window is reminded', 1 === $gwcvt_sent, (string) $gwcvt_sent );
gwcvt_check( 'and one message went out', 1 === count( $gwcvt_mail ), (string) count( $gwcvt_mail ) );
gwcvt_check( 'to the right person', 'zzytest-a@example.test' === ( $gwcvt_mail[0]['to'] ?? '' ), (string) ( $gwcvt_mail[0]['to'] ?? '' ) );
gwcvt_check( 'saying where to go', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'Zzytest main warehouse' ) );
gwcvt_check( 'and carrying a way to cancel', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'gwcvt_signup=' . $gwcvt_a ) );

gwcvt_check( 'a shift too far out is left alone', '' === (string) get_post_meta( $gwcvt_b, GWCVT_SIGNUP_REMINDED, true ) );

/* A reminder about a shift that started an hour ago is worse than none: it
 * tells somebody who forgot that they have already let people down. */
gwcvt_check( 'a shift that already started is left alone', '' === (string) get_post_meta( $gwcvt_c, GWCVT_SIGNUP_REMINDED, true ) );

/* ── Idempotence, which is what makes an hourly pass safe ────────────────── */

gwcvt_check( 'the timestamp was written', '' !== (string) get_post_meta( $gwcvt_a, GWCVT_SIGNUP_REMINDED, true ) );

$gwcvt_again = gwcvt_run_reminders();
gwcvt_drain_mail();

gwcvt_check( 'running again sends nothing', 0 === $gwcvt_again, (string) $gwcvt_again );

/* ── Somebody on the waiting list has no place to be reminded of ─────────── */

update_post_meta( $gwcvt_soon, GWCVT_SHIFT_MAX, 1 );

$gwcvt_waiting = gwcvt_make_signup( $gwcvt_soon, 'zzytest-w@example.test' );

gwcvt_check( 'the extra person is on the waiting list', GWCVT_SIGNUP_WAITLIST === get_post_status( $gwcvt_waiting ), (string) get_post_status( $gwcvt_waiting ) );

gwcvt_run_reminders();
gwcvt_drain_mail();

gwcvt_check( 'and is not told to turn up', '' === (string) get_post_meta( $gwcvt_waiting, GWCVT_SIGNUP_REMINDED, true ) );

/* Promoted off the waiting list, they are owed one. */
gwcvt_withdraw_signup( $gwcvt_a );
$GLOBALS['gwcvt_pending_mail'] = array();

gwcvt_check( 'withdrawing promotes them', 'publish' === get_post_status( $gwcvt_waiting ), (string) get_post_status( $gwcvt_waiting ) );

$gwcvt_promoted = gwcvt_run_reminders();
$gwcvt_mail     = gwcvt_drain_mail();

gwcvt_check( 'and now they are reminded', 1 === $gwcvt_promoted, (string) $gwcvt_promoted );
gwcvt_check( 'at their own address', 'zzytest-w@example.test' === ( $gwcvt_mail[0]['to'] ?? '' ), (string) ( $gwcvt_mail[0]['to'] ?? '' ) );

/* A withdrawn signup is nobody's problem. */
gwcvt_run_reminders();
gwcvt_drain_mail();

/* ── Cancelling tells the roster ─────────────────────────────────────────── */

$gwcvt_off  = gwcvt_make_shift_in( 100 );
$gwcvt_one  = gwcvt_make_signup( $gwcvt_off, 'zzytest-one@example.test' );
$gwcvt_two  = gwcvt_make_signup( $gwcvt_off, 'zzytest-two@example.test' );

$GLOBALS['gwcvt_pending_mail'] = array();

foreach ( gwcvt_shift_signup_ids( $gwcvt_off, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) as $gwcvt_id ) {
	gwcvt_queue_signup_mail( 'cancelled', (int) $gwcvt_id, array( 'reason' => 'Zzytest van is in for repairs' ) );
}

gwcvt_send_queued_confirmations();
$gwcvt_mail = gwcvt_drain_mail();

gwcvt_check( 'everybody on the roster is told', 2 === count( $gwcvt_mail ), (string) count( $gwcvt_mail ) );
gwcvt_check( 'the subject says it is cancelled', false !== strpos( (string) ( $gwcvt_mail[0]['subject'] ?? '' ), 'cancelled' ), (string) ( $gwcvt_mail[0]['subject'] ?? '' ) );
gwcvt_check( 'the message says not to come', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'do not come' ) );
gwcvt_check( 'and gives the reason', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'Zzytest van is in for repairs' ) );

/* ── A shift that moves ──────────────────────────────────────────────────── */

$gwcvt_was = array(
	'date'     => (string) get_post_meta( $gwcvt_off, GWCVT_SHIFT_DATE, true ),
	'start'    => (string) get_post_meta( $gwcvt_off, GWCVT_SHIFT_START, true ),
	'end'      => (string) get_post_meta( $gwcvt_off, GWCVT_SHIFT_END, true ),
	'next_day' => '',
	'location' => 'Zzytest main warehouse',
	'label'    => gwcvt_shift_one_line( $gwcvt_off ),
);

update_post_meta( $gwcvt_off, GWCVT_SHIFT_LOCATION, 'Zzytest community centre' );

gwcvt_check( 'moving the place counts as a change', gwcvt_shift_moved( $gwcvt_off, $gwcvt_was ) );

$GLOBALS['gwcvt_pending_mail'] = array();
gwcvt_queue_signup_mail( 'changed', $gwcvt_one, array( 'was' => $gwcvt_was ) );
gwcvt_send_queued_confirmations();

$gwcvt_mail = gwcvt_drain_mail();

gwcvt_check( 'the change notice goes out', 1 === count( $gwcvt_mail ), (string) count( $gwcvt_mail ) );
gwcvt_check( 'carrying the new details', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'Zzytest community centre' ) );

/* Quoting what it used to be, because "the details have changed" makes the
 * reader go and find the original to work out what. */
gwcvt_check( 'and what it used to be', false !== strpos( (string) ( $gwcvt_mail[0]['message'] ?? '' ), 'Zzytest main warehouse' ) );

/* Nothing anybody needs to know about. */
update_post_meta( $gwcvt_off, GWCVT_SHIFT_SUPERVISOR, 'Somebody Else' );
update_post_meta( $gwcvt_off, GWCVT_SHIFT_ACTIVITY, 'Zzytest reworded' );

$gwcvt_after_cosmetic = array_merge( $gwcvt_was, array( 'location' => 'Zzytest community centre' ) );

gwcvt_check( 'renaming the activity and the supervisor does not', ! gwcvt_shift_moved( $gwcvt_off, $gwcvt_after_cosmetic ) );

/* ── The daily summary ───────────────────────────────────────────────────── */

gwcvt_set_settings( gwcvt_base_settings( array( 'digest_enabled' => false ) ) );

gwcvt_check( 'nothing is sent while the summary is off', ! gwcvt_run_digest() );
gwcvt_drain_mail();

gwcvt_set_settings(
	gwcvt_base_settings(
		array(
			'digest_enabled'   => true,
			'digest_recipient' => 'zzytest-coordinator@example.test',
		)
	)
);

$gwcvt_short = gwcvt_make_shift_in( 72, 6 );
update_post_meta( $gwcvt_short, GWCVT_SHIFT_MAX, 6 );
gwcvt_make_signup( $gwcvt_short, 'zzytest-lonely@example.test' );

$gwcvt_ran  = gwcvt_run_digest();
$gwcvt_mail = gwcvt_drain_mail();
$gwcvt_body = (string) ( $gwcvt_mail[0]['message'] ?? '' );

gwcvt_check( 'the summary goes out when there is something to say', $gwcvt_ran );
gwcvt_check( 'to the address configured', 'zzytest-coordinator@example.test' === ( $gwcvt_mail[0]['to'] ?? '' ), (string) ( $gwcvt_mail[0]['to'] ?? '' ) );
gwcvt_check( 'listing what is short of people', false !== strpos( $gwcvt_body, 'Short of people' ) );
gwcvt_check( 'naming this shift', false !== strpos( $gwcvt_body, 'Zzytest main warehouse' ) );
gwcvt_check( 'and how short it is', false !== strpos( $gwcvt_body, '1 of 6' ) );

/* The links in it are admin URLs, and they are built by functions that must be
 * loadable on cron — where the admin bundle is not. A digest that fatals at
 * three in the morning simply stops existing, and nobody finds out. */
gwcvt_check( 'with a link to the shift', false !== strpos( $gwcvt_body, 'page=' . GWCVT_SCHEDULE_PAGE ) );
gwcvt_check( 'and a link straight to logging the hours', false !== strpos( $gwcvt_body, 'page=' . GWCVT_QUICK_ADD_PAGE ) );

/* Scoped to this script's own shift rather than asserting the whole digest goes
 * silent. These scripts run against a database that belongs to somebody else,
 * and the demo data has under-staffed Saturdays of its own — a check that
 * demanded global silence would pass or fail depending on what else is seeded.
 * The unit suite covers "nothing to say, so nothing is sent" directly. */
gwcvt_check( 'this shift is in the short list', in_array( $gwcvt_short, gwcvt_understaffed_shift_ids( 7 ), true ) );

update_post_meta( $gwcvt_short, GWCVT_SHIFT_MIN, 0 );

gwcvt_check( 'and drops out once it is no longer short', ! in_array( $gwcvt_short, gwcvt_understaffed_shift_ids( 7 ), true ) );

$gwcvt_mail = gwcvt_drain_mail();

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array( GWCVT_REMINDER_EVENT, GWCVT_DIGEST_EVENT ) as $gwcvt_event ) {
	$gwcvt_next = wp_next_scheduled( $gwcvt_event );

	if ( $gwcvt_next ) {
		wp_unschedule_event( $gwcvt_next, $gwcvt_event );
	}
}

foreach ( array( $gwcvt_soon, $gwcvt_distant, $gwcvt_gone, $gwcvt_off, $gwcvt_short ) as $gwcvt_shift_id ) {
	foreach ( gwcvt_shift_signup_ids( $gwcvt_shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ) ) as $gwcvt_id ) {
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

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

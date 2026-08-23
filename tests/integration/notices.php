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
 * @param null  $short Whatever an earlier filter decided.
 * @param array $atts  to, subject, message, headers, attachments.
 * @return bool
 */
function gwc_vt_test_catch_mail( $short, $atts ) {
	$GLOBALS['gwc_vt_mail'][] = $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'gwc_vt_test_catch_mail', 10, 2 );

/**
 * Everything caught since the last time this was called.
 *
 * @return array
 */
function gwc_vt_drain_mail(): array {
	$mail                  = $GLOBALS['gwc_vt_mail'];
	$GLOBALS['gwc_vt_mail'] = array();

	return $mail;
}

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

/**
 * Store settings and clear the memo.
 *
 * @param array $extra Settings to store.
 */
function gwc_vt_set_settings( array $extra ): void {
	update_option( GWC_VT_SETTINGS_OPTION, $extra );
	gwc_vt_settings_cache( null, true );
}

/**
 * The settings this script runs with, plus whatever is being varied.
 *
 * @param array $extra Overrides.
 * @return array
 */
function gwc_vt_base_settings( array $extra = array() ): array {
	return array_merge(
		array(
			'shifts_enabled'   => true,
			'org_name'         => 'Zzytest Riverbend Food Bank',
			'org_contact'      => 'volunteers@zzytest.example',
			'retention_months' => 0,

			/* Pinned, because every message a volunteer gets carries a link to
			 * manage their own booking and that link needs somewhere to go. A
			 * site with shifts on and no public page gets no link at all rather
			 * than one pointing at the front page — see gwc_vt_signup_manage_url(),
			 * which this file caught sending exactly that. */
			'signup_enabled'   => true,
			'schedule_page'    => (int) ( $GLOBALS['gwc_vt_schedule_page'] ?? 0 ),
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
function gwc_vt_make_shift_in( float $hours_away, int $min = 0 ): int {
	$starts = time() + (int) round( $hours_away * HOUR_IN_SECONDS );
	$local  = new DateTimeImmutable( '@' . $starts );
	$local  = $local->setTimezone( gwc_vt_timezone() );

	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $local->format( 'Y-m-d' ) );
	update_post_meta( $id, GWC_VT_SHIFT_START, $local->format( 'H:i' ) );
	update_post_meta( $id, GWC_VT_SHIFT_END, $local->modify( '+3 hours' )->format( 'H:i' ) );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWC_VT_SHIFT_LOCATION, 'Zzytest main warehouse' );
	update_post_meta( $id, GWC_VT_SHIFT_SUPERVISOR, 'Dana Reyes' );
	update_post_meta( $id, GWC_VT_SHIFT_MIN, $min );

	gwc_vt_retitle_shift( (int) $id );

	$GLOBALS['gwc_vt_made'][] = $id;

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
function gwc_vt_make_signup( int $shift_id, string $email, string $name = 'Zzytest Somebody' ): int {
	$id = gwc_vt_add_signup(
		$shift_id,
		array(
			'claim_name'  => $name,
			'claim_email' => $email,
			'source'      => 'self',
		)
	);

	$GLOBALS['gwc_vt_made'][] = $id;

	/* The confirmation the signup queued is not what this file is about. */
	$GLOBALS['gwc_vt_pending_mail'] = array();
	gwc_vt_drain_mail();

	return (int) $id;
}

$GLOBALS['gwc_vt_schedule_page'] = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest shifts',
		'post_content' => '[gwc_vt_shift_list]',
	)
);

$GLOBALS['gwc_vt_made'][] = $GLOBALS['gwc_vt_schedule_page'];

/* ── The events exist only while the feature does ────────────────────────── */

gwc_vt_set_settings( array( 'shifts_enabled' => false ) );
gwc_vt_schedule_shift_events();

gwc_vt_check( 'no reminder event while shifts are off', false === wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
gwc_vt_check( 'no digest event while shifts are off', false === wp_next_scheduled( GWC_VT_DIGEST_EVENT ) );

gwc_vt_set_settings( gwc_vt_base_settings() );
gwc_vt_schedule_shift_events();

gwc_vt_check( 'turning shifts on schedules the reminder pass', false !== wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
gwc_vt_check( 'and the daily summary', false !== wp_next_scheduled( GWC_VT_DIGEST_EVENT ) );

/* ── The reminder pass ───────────────────────────────────────────────────── */

$gwc_vt_soon    = gwc_vt_make_shift_in( 30 );   // inside a 48-hour lead.
$gwc_vt_distant = gwc_vt_make_shift_in( 200 );  // well outside it.
$gwc_vt_gone    = gwc_vt_make_shift_in( -5 );   // already started.

$gwc_vt_a = gwc_vt_make_signup( $gwc_vt_soon, 'zzytest-a@example.test', 'Zzytest Ana Ferreira' );
$gwc_vt_b = gwc_vt_make_signup( $gwc_vt_distant, 'zzytest-b@example.test' );
$gwc_vt_c = gwc_vt_make_signup( $gwc_vt_gone, 'zzytest-c@example.test' );

gwc_vt_set_settings( gwc_vt_base_settings( array( 'reminder_enabled' => false ) ) );

gwc_vt_check( 'nothing is sent while reminders are off', 0 === gwc_vt_run_reminders() );
gwc_vt_check( 'and no timestamp was written', '' === (string) get_post_meta( $gwc_vt_a, GWC_VT_SIGNUP_REMINDED, true ) );

gwc_vt_set_settings(
	gwc_vt_base_settings(
		array(
			'reminder_enabled'    => true,
			'reminder_lead_hours' => 48,
		)
	)
);

$gwc_vt_sent = gwc_vt_run_reminders();

/* Only this script's own mail. The reminder pass is site-wide by design, so on
 * a site with seed data — or with any real shift two days out — the total is
 * whatever else happened to be due, and asserting on it makes the test pass or
 * fail depending on what day it is run. It did: this read 1 for weeks and then
 * read 4 the morning a seeded Saturday drifted inside the 48-hour lead. */
$gwc_vt_mail = array_values(
	array_filter(
		gwc_vt_drain_mail(),
		static function ( array $gwc_vt_message ): bool {
			return 0 === strpos( (string) ( $gwc_vt_message['to'] ?? '' ), 'zzytest-' );
		}
	)
);

gwc_vt_check( 'exactly the shift inside the window is reminded', 1 === count( $gwc_vt_mail ), (string) count( $gwc_vt_mail ) );
gwc_vt_check( 'and the pass counted it', $gwc_vt_sent >= 1, (string) $gwc_vt_sent );
gwc_vt_check( 'to the right person', 'zzytest-a@example.test' === ( $gwc_vt_mail[0]['to'] ?? '' ), (string) ( $gwc_vt_mail[0]['to'] ?? '' ) );
gwc_vt_check( 'saying where to go', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'Zzytest main warehouse' ) );
gwc_vt_check( 'and carrying a way to cancel', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'gwc_vt_signup=' . $gwc_vt_a ) );

gwc_vt_check( 'a shift too far out is left alone', '' === (string) get_post_meta( $gwc_vt_b, GWC_VT_SIGNUP_REMINDED, true ) );

/* A reminder about a shift that started an hour ago is worse than none: it
 * tells somebody who forgot that they have already let people down. */
gwc_vt_check( 'a shift that already started is left alone', '' === (string) get_post_meta( $gwc_vt_c, GWC_VT_SIGNUP_REMINDED, true ) );

/* ── Idempotence, which is what makes an hourly pass safe ────────────────── */

gwc_vt_check( 'the timestamp was written', '' !== (string) get_post_meta( $gwc_vt_a, GWC_VT_SIGNUP_REMINDED, true ) );

$gwc_vt_again = gwc_vt_run_reminders();
gwc_vt_drain_mail();

gwc_vt_check( 'running again sends nothing', 0 === $gwc_vt_again, (string) $gwc_vt_again );

/* ── Somebody on the waiting list has no place to be reminded of ─────────── */

update_post_meta( $gwc_vt_soon, GWC_VT_SHIFT_MAX, 1 );

$gwc_vt_waiting = gwc_vt_make_signup( $gwc_vt_soon, 'zzytest-w@example.test' );

gwc_vt_check( 'the extra person is on the waiting list', GWC_VT_SIGNUP_WAITLIST === get_post_status( $gwc_vt_waiting ), (string) get_post_status( $gwc_vt_waiting ) );

gwc_vt_run_reminders();
gwc_vt_drain_mail();

gwc_vt_check( 'and is not told to turn up', '' === (string) get_post_meta( $gwc_vt_waiting, GWC_VT_SIGNUP_REMINDED, true ) );

/* Promoted off the waiting list, they are owed one. */
gwc_vt_withdraw_signup( $gwc_vt_a );
$GLOBALS['gwc_vt_pending_mail'] = array();

gwc_vt_check( 'withdrawing promotes them', 'publish' === get_post_status( $gwc_vt_waiting ), (string) get_post_status( $gwc_vt_waiting ) );

$gwc_vt_promoted = gwc_vt_run_reminders();
$gwc_vt_mail     = gwc_vt_drain_mail();

gwc_vt_check( 'and now they are reminded', 1 === $gwc_vt_promoted, (string) $gwc_vt_promoted );
gwc_vt_check( 'at their own address', 'zzytest-w@example.test' === ( $gwc_vt_mail[0]['to'] ?? '' ), (string) ( $gwc_vt_mail[0]['to'] ?? '' ) );

/* A withdrawn signup is nobody's problem. */
gwc_vt_run_reminders();
gwc_vt_drain_mail();

/* ── Cancelling tells the roster ─────────────────────────────────────────── */

$gwc_vt_off  = gwc_vt_make_shift_in( 100 );
$gwc_vt_one  = gwc_vt_make_signup( $gwc_vt_off, 'zzytest-one@example.test' );
$gwc_vt_two  = gwc_vt_make_signup( $gwc_vt_off, 'zzytest-two@example.test' );

$GLOBALS['gwc_vt_pending_mail'] = array();

foreach ( gwc_vt_shift_signup_ids( $gwc_vt_off, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $gwc_vt_id ) {
	gwc_vt_queue_signup_mail( 'cancelled', (int) $gwc_vt_id, array( 'reason' => 'Zzytest van is in for repairs' ) );
}

gwc_vt_send_queued_confirmations();
$gwc_vt_mail = gwc_vt_drain_mail();

gwc_vt_check( 'everybody on the roster is told', 2 === count( $gwc_vt_mail ), (string) count( $gwc_vt_mail ) );
gwc_vt_check( 'the subject says it is canceled', false !== strpos( (string) ( $gwc_vt_mail[0]['subject'] ?? '' ), 'canceled' ), (string) ( $gwc_vt_mail[0]['subject'] ?? '' ) );
gwc_vt_check( 'the message says not to come', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'do not come' ) );
gwc_vt_check( 'and gives the reason', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'Zzytest van is in for repairs' ) );

/* ── A shift that moves ──────────────────────────────────────────────────── */

$gwc_vt_was = array(
	'date'     => (string) get_post_meta( $gwc_vt_off, GWC_VT_SHIFT_DATE, true ),
	'start'    => (string) get_post_meta( $gwc_vt_off, GWC_VT_SHIFT_START, true ),
	'end'      => (string) get_post_meta( $gwc_vt_off, GWC_VT_SHIFT_END, true ),
	'next_day' => '',
	'location' => 'Zzytest main warehouse',
	'label'    => gwc_vt_shift_one_line( $gwc_vt_off ),
);

update_post_meta( $gwc_vt_off, GWC_VT_SHIFT_LOCATION, 'Zzytest community center' );

gwc_vt_check( 'moving the place counts as a change', gwc_vt_shift_moved( $gwc_vt_off, $gwc_vt_was ) );

$GLOBALS['gwc_vt_pending_mail'] = array();
gwc_vt_queue_signup_mail( 'changed', $gwc_vt_one, array( 'was' => $gwc_vt_was ) );
gwc_vt_send_queued_confirmations();

$gwc_vt_mail = gwc_vt_drain_mail();

gwc_vt_check( 'the change notice goes out', 1 === count( $gwc_vt_mail ), (string) count( $gwc_vt_mail ) );
gwc_vt_check( 'carrying the new details', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'Zzytest community center' ) );

/* Quoting what it used to be, because "the details have changed" makes the
 * reader go and find the original to work out what. */
gwc_vt_check( 'and what it used to be', false !== strpos( (string) ( $gwc_vt_mail[0]['message'] ?? '' ), 'Zzytest main warehouse' ) );

/* Nothing anybody needs to know about. */
update_post_meta( $gwc_vt_off, GWC_VT_SHIFT_SUPERVISOR, 'Somebody Else' );
update_post_meta( $gwc_vt_off, GWC_VT_SHIFT_ACTIVITY, 'Zzytest reworded' );

$gwc_vt_after_cosmetic = array_merge( $gwc_vt_was, array( 'location' => 'Zzytest community center' ) );

gwc_vt_check( 'renaming the activity and the supervisor does not', ! gwc_vt_shift_moved( $gwc_vt_off, $gwc_vt_after_cosmetic ) );

/* ── The daily summary ───────────────────────────────────────────────────── */

gwc_vt_set_settings( gwc_vt_base_settings( array( 'digest_enabled' => false ) ) );

gwc_vt_check( 'nothing is sent while the summary is off', ! gwc_vt_run_digest() );
gwc_vt_drain_mail();

gwc_vt_set_settings(
	gwc_vt_base_settings(
		array(
			'digest_enabled'   => true,
			'digest_recipient' => 'zzytest-coordinator@example.test',
		)
	)
);

$gwc_vt_short = gwc_vt_make_shift_in( 72, 6 );
update_post_meta( $gwc_vt_short, GWC_VT_SHIFT_MAX, 6 );
gwc_vt_make_signup( $gwc_vt_short, 'zzytest-lonely@example.test' );

$gwc_vt_ran  = gwc_vt_run_digest();
$gwc_vt_mail = gwc_vt_drain_mail();
$gwc_vt_body = (string) ( $gwc_vt_mail[0]['message'] ?? '' );

gwc_vt_check( 'the summary goes out when there is something to say', $gwc_vt_ran );
gwc_vt_check( 'to the address configured', 'zzytest-coordinator@example.test' === ( $gwc_vt_mail[0]['to'] ?? '' ), (string) ( $gwc_vt_mail[0]['to'] ?? '' ) );
gwc_vt_check( 'listing what is short of people', false !== strpos( $gwc_vt_body, 'Short of people' ) );
gwc_vt_check( 'naming this shift', false !== strpos( $gwc_vt_body, 'Zzytest main warehouse' ) );
gwc_vt_check( 'and how short it is', false !== strpos( $gwc_vt_body, '1 of 6' ) );

/* The links in it are admin URLs, and they are built by functions that must be
 * loadable on cron — where the admin bundle is not. A digest that fatals at
 * three in the morning simply stops existing, and nobody finds out. */
gwc_vt_check( 'with a link to the shift', false !== strpos( $gwc_vt_body, 'page=' . GWC_VT_SCHEDULE_PAGE ) );
gwc_vt_check( 'and a link straight to logging the hours', false !== strpos( $gwc_vt_body, 'page=' . GWC_VT_QUICK_ADD_PAGE ) );

/* Scoped to this script's own shift rather than asserting the whole digest goes
 * silent. These scripts run against a database that belongs to somebody else,
 * and the demo data has under-staffed Saturdays of its own — a check that
 * demanded global silence would pass or fail depending on what else is seeded.
 * The unit suite covers "nothing to say, so nothing is sent" directly. */
gwc_vt_check( 'this shift is in the short list', in_array( $gwc_vt_short, gwc_vt_understaffed_shift_ids( 7 ), true ) );

update_post_meta( $gwc_vt_short, GWC_VT_SHIFT_MIN, 0 );

gwc_vt_check( 'and drops out once it is no longer short', ! in_array( $gwc_vt_short, gwc_vt_understaffed_shift_ids( 7 ), true ) );

$gwc_vt_mail = gwc_vt_drain_mail();

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array( GWC_VT_REMINDER_EVENT, GWC_VT_DIGEST_EVENT ) as $gwc_vt_event ) {
	$gwc_vt_next = wp_next_scheduled( $gwc_vt_event );

	if ( $gwc_vt_next ) {
		wp_unschedule_event( $gwc_vt_next, $gwc_vt_event );
	}
}

foreach ( array( $gwc_vt_soon, $gwc_vt_distant, $gwc_vt_gone, $gwc_vt_off, $gwc_vt_short ) as $gwc_vt_shift_id ) {
	foreach ( gwc_vt_shift_signup_ids( $gwc_vt_shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) as $gwc_vt_id ) {
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

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

<?php
/**
 * Turning a roster into hours — the step where a plan becomes a record.
 *
 * This is the milestone's whole point and the place it can do the most damage,
 * so it is tested against a real database and through the real handler rather
 * than against the model functions it calls. What is asserted is not only that
 * the entries appear, but that a no-show produces nothing, that a shift cannot
 * be logged before it has ended, that logging twice does not double anybody's
 * hours, and that the volunteer's totals move by exactly the minutes recorded.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/reconcile.php
 *
 * It creates its own fixtures and deletes them again, so it is safe to re-run.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/entries.php about what
 * `wp eval-file` does to a top-level assignment. */
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_made']     = array();

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
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwcvt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWCVT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Create a shift, in the past by default so it can be logged.
 *
 * @param string $date  Y-m-d.
 * @param string $start H:i.
 * @param string $end   H:i.
 * @return int
 */
function gwcvt_make_shift( string $date, string $start = '09:00', string $end = '12:00' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_SHIFT_DATE, $date );
	update_post_meta( $id, GWCVT_SHIFT_START, $start );
	update_post_meta( $id, GWCVT_SHIFT_END, $end );
	update_post_meta( $id, GWCVT_SHIFT_ACTIVITY, 'Zzytest warehouse inventory' );
	update_post_meta( $id, GWCVT_SHIFT_SUPERVISOR, 'Dana Reyes' );

	gwcvt_retitle_shift( (int) $id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/**
 * Put somebody on a shift and remember the signup.
 *
 * @param int   $shift_id Shift post ID.
 * @param array $args     Passed to gwcvt_add_signup().
 * @return int
 */
function gwcvt_track_signup( int $shift_id, array $args ): int {
	$id = gwcvt_add_signup( $shift_id, $args );

	if ( $id > 0 ) {
		$GLOBALS['gwcvt_made'][] = $id;
	}

	return (int) $id;
}

/* ── Running the real handler ────────────────────────────────────────────────
 * The handler is what a browser posts to, and it is where the row-index
 * arithmetic lives — the part that has to keep an unticked checkbox from being
 * read as the next row's answer. Testing the model functions it calls would
 * leave exactly that uncovered, so this drives the handler itself.
 *
 * The handler ends in exit(), as every admin_post_ handler in this plugin does.
 * exit is not catchable, so the redirect filter THROWS instead of returning:
 * wp_redirect() never gets to return, the exit is never reached, and the
 * exception carries the location the handler wanted to send us to.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Turn a redirect into something a test can catch.
 *
 * @param string $location Where the handler wanted to go.
 * @throws Exception Always, carrying the location.
 */
function gwcvt_test_catch_redirect( $location ): void {
	throw new Exception( (string) $location );
}

/**
 * Run the real handler and report the result key it redirected with.
 *
 * @param array $post What the form would have posted.
 * @return string
 */
function gwcvt_run_quick_add( array $post ): string {
	$_POST                = $post;
	$_REQUEST             = $post;
	$_POST['_wpnonce']    = wp_create_nonce( 'gwcvt_quick_add' );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

	add_filter( 'wp_redirect', 'gwcvt_test_catch_redirect', 999 );

	$location = '';

	try {
		gwcvt_handle_quick_add();
	} catch ( Exception $e ) {
		$location = $e->getMessage();
	}

	remove_filter( 'wp_redirect', 'gwcvt_test_catch_redirect', 999 );

	$args = array();
	parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $args );

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $args['gwcvt_qa'] ?? '' );
}

/**
 * Every hour entry currently attached to a volunteer.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int[]
 */
function gwcvt_entries_for( int $volunteer_id ): array {
	return array_map( 'intval', gwcvt_entry_ids_for_volunteer( $volunteer_id ) );
}

/* WP-CLI hooks wp_redirect at priority 10 to warn that something tried to
 * redirect. Here that is not a surprise, it is the assertion — and its backtrace
 * is forty lines between every check, which makes the output unreadable and a
 * failure easy to miss. Removed rather than tolerated. */
remove_filter( 'wp_redirect', 'WP_CLI\Utils\wp_redirect_handler' );

wp_set_current_user( 1 );

$gwcvt_jane  = gwcvt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwcvt_omar  = gwcvt_make_volunteer( 'Zzytest Omar Delacroix', 'omar@example.test' );
$gwcvt_priya = gwcvt_make_volunteer( 'Zzytest Priya Nandakumar', 'priya@example.test' );

/* ── A shift that has not finished cannot be logged ──────────────────────────
 * The assertion this milestone most needs. gwcvt_save_entry() silently clamps a
 * future date to today, so logging early would date somebody's hours the day
 * they were typed — on a document a court reads, with nothing on screen to say
 * it happened.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_future = gwcvt_make_shift( gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ) );
$gwcvt_early  = gwcvt_track_signup( $gwcvt_future, array( 'volunteer_id' => $gwcvt_jane ) );

$gwcvt_result = gwcvt_run_quick_add(
	array(
		'gwcvt_shift'      => (string) $gwcvt_future,
		'gwcvt_date'       => (string) get_post_meta( $gwcvt_future, GWCVT_SHIFT_DATE, true ),
		'gwcvt_activity'   => 'Zzytest warehouse inventory',
		'gwcvt_supervisor' => 'Dana Reyes',
		'gwcvt_volunteer'  => array( (string) $gwcvt_jane ),
		'gwcvt_hours'      => array( '3' ),
		'gwcvt_signup'     => array( 0 => (string) $gwcvt_early ),
		'gwcvt_attended'   => array( 0 => '1' ),
	)
);

gwcvt_check( 'a shift that has not ended is refused', 'not-ended' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'and nothing was recorded', 0 === count( gwcvt_entries_for( $gwcvt_jane ) ) );
gwcvt_check( 'nor was it marked as dealt with', ! gwcvt_shift_is_reconciled( $gwcvt_future ) );

/* ── The ordinary case ───────────────────────────────────────────────────────
 * Three people signed up. Two came, one of them left early, one did not come at
 * all, and somebody walked in.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$gwcvt_shift     = gwcvt_make_shift( $gwcvt_yesterday );

$gwcvt_s_jane  = gwcvt_track_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_jane ) );
$gwcvt_s_omar  = gwcvt_track_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_omar ) );
$gwcvt_s_priya = gwcvt_track_signup( $gwcvt_shift, array( 'volunteer_id' => $gwcvt_priya ) );

gwcvt_check( 'the scheduled duration is three hours', 180 === gwcvt_shift_minutes( $gwcvt_shift ), (string) gwcvt_shift_minutes( $gwcvt_shift ) );

$gwcvt_walk_in = gwcvt_make_volunteer( 'Zzytest Marcus Bell' );

$gwcvt_result = gwcvt_run_quick_add(
	array(
		'gwcvt_shift'      => (string) $gwcvt_shift,
		'gwcvt_date'       => $gwcvt_yesterday,
		'gwcvt_activity'   => 'Zzytest warehouse inventory',
		'gwcvt_supervisor' => 'Dana Reyes',
		'gwcvt_volunteer'  => array( (string) $gwcvt_jane, (string) $gwcvt_omar, (string) $gwcvt_priya, (string) $gwcvt_walk_in ),
		'gwcvt_hours'      => array( '3', '1:30', '3', '2' ),
		'gwcvt_signup'     => array(
			0 => (string) $gwcvt_s_jane,
			1 => (string) $gwcvt_s_omar,
			2 => (string) $gwcvt_s_priya,
		),
		/* Priya's box is missing, exactly as a browser omits an unticked one.
		 * The indexes are what keep row 3's absence from being read as row 4's. */
		'gwcvt_attended'   => array(
			0 => '1',
			1 => '1',
		),
	)
);

gwcvt_check( 'the shift is logged', 'logged' === $gwcvt_result, $gwcvt_result );

$gwcvt_jane_entries = gwcvt_entries_for( $gwcvt_jane );
gwcvt_check( 'somebody who came has one entry', 1 === count( $gwcvt_jane_entries ), (string) count( $gwcvt_jane_entries ) );

$gwcvt_entry = (int) ( $gwcvt_jane_entries[0] ?? 0 );

gwcvt_check( 'dated the day of the shift, not today', $gwcvt_yesterday === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_DATE, true ), (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_DATE, true ) );
gwcvt_check( 'for the scheduled duration', 180 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true ), (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true ) );
gwcvt_check( 'with the shift’s activity', 'Zzytest warehouse inventory' === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_ACTIVITY, true ) );
gwcvt_check( 'and the shift’s supervisor', 'Dana Reyes' === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_SUPERVISOR, true ) );
gwcvt_check( 'recorded as staff-entered', 'staff' === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_SOURCE, true ) );
gwcvt_check( 'linked back to the shift', $gwcvt_shift === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_SHIFT, true ) );
gwcvt_check( 'and the signup linked forward to it', $gwcvt_entry === (int) get_post_meta( $gwcvt_s_jane, GWCVT_SIGNUP_ENTRY, true ) );

/* Reconciling is not verifying. Matching answers whose hours these are, logging
 * answers whether the shift is written up, and verifying answers whether it
 * happened — three different questions and three different people's jobs. */
gwcvt_check( 'the entry arrives unverified', '' === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_VERIFIED_AT, true ) );

$gwcvt_omar_entries = gwcvt_entries_for( $gwcvt_omar );
gwcvt_check(
	'somebody who left early gets the hours that were typed',
	1 === count( $gwcvt_omar_entries ) && 90 === (int) get_post_meta( (int) $gwcvt_omar_entries[0], GWCVT_ENTRY_MINUTES, true ),
	(string) ( $gwcvt_omar_entries ? get_post_meta( (int) $gwcvt_omar_entries[0], GWCVT_ENTRY_MINUTES, true ) : 'none' )
);

/* The assertion the whole checkbox-index note in the handler exists for. */
gwcvt_check( 'a no-show gets no entry at all', 0 === count( gwcvt_entries_for( $gwcvt_priya ) ), (string) count( gwcvt_entries_for( $gwcvt_priya ) ) );
gwcvt_check( 'and no signup-to-entry link', '' === (string) get_post_meta( $gwcvt_s_priya, GWCVT_SIGNUP_ENTRY, true ) );

/* Nothing anywhere records that they were absent. */
gwcvt_check( 'and is still on the roster rather than marked absent', 'publish' === get_post_status( $gwcvt_s_priya ), (string) get_post_status( $gwcvt_s_priya ) );

gwcvt_check( 'a walk-in with no signup is recorded', 1 === count( gwcvt_entries_for( $gwcvt_walk_in ) ), (string) count( gwcvt_entries_for( $gwcvt_walk_in ) ) );

gwcvt_check( 'the shift is marked as dealt with', gwcvt_shift_is_reconciled( $gwcvt_shift ) );

/* ── Totals move by exactly what was logged ──────────────────────────────── */

$gwcvt_totals = gwcvt_volunteer_totals( $gwcvt_jane );
gwcvt_check( 'the volunteer’s pending total is the logged minutes', 180 === $gwcvt_totals->pending_minutes, (string) $gwcvt_totals->pending_minutes );
gwcvt_check( 'and nothing is verified yet', 0 === $gwcvt_totals->verified_minutes, (string) $gwcvt_totals->verified_minutes );

$gwcvt_priya_totals = gwcvt_volunteer_totals( $gwcvt_priya );
gwcvt_check( 'a no-show’s totals did not move', 0 === $gwcvt_priya_totals->total_minutes(), (string) $gwcvt_priya_totals->total_minutes() );

/* ── Logging twice does not double anybody ───────────────────────────────────
 * The screen renders an already-logged row with no tick box and posts nobody
 * for it. This asserts the handler agrees, because a coordinator who reopens
 * the screen to add a missed walk-in must not silently double everybody else.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_missed = gwcvt_make_volunteer( 'Zzytest Wendell Achebe' );

$gwcvt_result = gwcvt_run_quick_add(
	array(
		'gwcvt_shift'      => (string) $gwcvt_shift,
		'gwcvt_date'       => $gwcvt_yesterday,
		'gwcvt_activity'   => 'Zzytest warehouse inventory',
		'gwcvt_supervisor' => 'Dana Reyes',
		// Row 0 is Jane, already logged: posts nobody and no hours, as the screen does.
		'gwcvt_volunteer'  => array( '0', (string) $gwcvt_missed ),
		'gwcvt_hours'      => array( '', '4' ),
		'gwcvt_signup'     => array(),
		'gwcvt_attended'   => array(),
	)
);

gwcvt_check( 'a second pass logs the person who was missed', 'logged' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'they have an entry', 1 === count( gwcvt_entries_for( $gwcvt_missed ) ), (string) count( gwcvt_entries_for( $gwcvt_missed ) ) );
gwcvt_check( 'and nobody was logged twice', 1 === count( gwcvt_entries_for( $gwcvt_jane ) ), (string) count( gwcvt_entries_for( $gwcvt_jane ) ) );
gwcvt_check( 'so the totals did not move either', 180 === gwcvt_volunteer_totals( $gwcvt_jane )->pending_minutes, (string) gwcvt_volunteer_totals( $gwcvt_jane )->pending_minutes );

/* ── Reopening does not re-tick a no-show ────────────────────────────────────
 * The screen is what defends this, so the screen is what is asserted. Somebody
 * on the roster of a logged shift with no entry has already been recorded as
 * not having come; if reopening the page brought them back ticked with the
 * scheduled hours filled in, one press of Save would credit them a shift they
 * did not work — onto a record a letter is built from, with nothing to show it.
 * ─────────────────────────────────────────────────────────────────────────── */

ob_start();
gwcvt_render_roster_log_row( 0, $gwcvt_s_priya, $gwcvt_shift, true );
$gwcvt_reopened = (string) ob_get_clean();

gwcvt_check( 'a no-show comes back unticked when the shift was already logged', false === strpos( $gwcvt_reopened, 'checked=' ), 'the box was pre-ticked' );
gwcvt_check( 'and says why', false !== strpos( $gwcvt_reopened, 'Recorded as not having come' ) );

ob_start();
gwcvt_render_roster_log_row( 0, $gwcvt_s_priya, $gwcvt_shift, false );
$gwcvt_first_pass = (string) ob_get_clean();

gwcvt_check( 'but is ticked on a shift nobody has logged yet', false !== strpos( $gwcvt_first_pass, 'checked=' ) );

ob_start();
gwcvt_render_roster_log_row( 0, $gwcvt_s_jane, $gwcvt_shift, true );
$gwcvt_logged_row = (string) ob_get_clean();

gwcvt_check( 'and somebody already logged gets no box at all', false === strpos( $gwcvt_logged_row, 'gwcvt_attended' ) );
gwcvt_check( 'nor a hours field that could add more', false === strpos( $gwcvt_logged_row, 'inputmode' ) );

/* ── A shift where nobody came ───────────────────────────────────────────────
 * "Nobody turned up" is an answer, so the shift stops asking.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_empty  = gwcvt_make_shift( $gwcvt_yesterday );
$gwcvt_s_none = gwcvt_track_signup( $gwcvt_empty, array( 'volunteer_id' => $gwcvt_omar ) );

$gwcvt_before = count( gwcvt_entries_for( $gwcvt_omar ) );

$gwcvt_result = gwcvt_run_quick_add(
	array(
		'gwcvt_shift'      => (string) $gwcvt_empty,
		'gwcvt_date'       => $gwcvt_yesterday,
		'gwcvt_activity'   => 'Zzytest warehouse inventory',
		'gwcvt_supervisor' => 'Dana Reyes',
		'gwcvt_volunteer'  => array( (string) $gwcvt_omar ),
		'gwcvt_hours'      => array( '3' ),
		'gwcvt_signup'     => array( 0 => (string) $gwcvt_s_none ),
		'gwcvt_attended'   => array(),
	)
);

gwcvt_check( 'nothing is logged when nobody came', 'nothing' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'no entry was created', $gwcvt_before === count( gwcvt_entries_for( $gwcvt_omar ) ) );
gwcvt_check( 'but the shift is still marked as dealt with', gwcvt_shift_is_reconciled( $gwcvt_empty ) );

/* ── The unreconciled queue ──────────────────────────────────────────────── */

$gwcvt_waiting = gwcvt_make_shift( $gwcvt_yesterday );
gwcvt_track_signup( $gwcvt_waiting, array( 'volunteer_id' => $gwcvt_jane ) );

$gwcvt_queue = gwcvt_unreconciled_shift_ids();

gwcvt_check( 'a shift with a roster and no hours is in the queue', in_array( $gwcvt_waiting, $gwcvt_queue, true ) );
gwcvt_check( 'one that has been logged is not', ! in_array( $gwcvt_shift, $gwcvt_queue, true ) );
gwcvt_check( 'nor is one nobody signed up for', ! in_array( $gwcvt_future, $gwcvt_queue, true ) );

/* ── Logging also resolves an unmatched signup ───────────────────────────────
 * Picking who somebody is IS the match, so the roster stops saying "unmatched"
 * rather than carrying a claim forever. Matching, still not attesting.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_claim_shift = gwcvt_make_shift( $gwcvt_yesterday );
$gwcvt_claim       = gwcvt_track_signup(
	$gwcvt_claim_shift,
	array(
		'claim_name'  => 'Zzytest Joachim Whitfeather',
		'claim_email' => 'joachim@example.test',
		'source'      => 'self',
	)
);

gwcvt_check( 'the signup starts unmatched', 0 === (int) get_post_meta( $gwcvt_claim, GWCVT_SIGNUP_VOLUNTEER, true ) );

$gwcvt_joachim = gwcvt_make_volunteer( 'Zzytest Joachim Whitfeather', 'joachim@example.test' );

$gwcvt_suggestion = gwcvt_suggest_volunteer_for_signup( $gwcvt_claim );
gwcvt_check( 'and the screen suggests who they are, by email', $gwcvt_joachim === (int) $gwcvt_suggestion['volunteer_id'], (string) $gwcvt_suggestion['volunteer_id'] );
gwcvt_check( 'saying what it matched on', 'email' === $gwcvt_suggestion['matched_on'], $gwcvt_suggestion['matched_on'] );

gwcvt_run_quick_add(
	array(
		'gwcvt_shift'      => (string) $gwcvt_claim_shift,
		'gwcvt_date'       => $gwcvt_yesterday,
		'gwcvt_activity'   => 'Zzytest warehouse inventory',
		'gwcvt_supervisor' => 'Dana Reyes',
		'gwcvt_volunteer'  => array( (string) $gwcvt_joachim ),
		'gwcvt_hours'      => array( '3' ),
		'gwcvt_signup'     => array( 0 => (string) $gwcvt_claim ),
		'gwcvt_attended'   => array( 0 => '1' ),
	)
);

gwcvt_check( 'logging their hours attaches the signup', $gwcvt_joachim === (int) get_post_meta( $gwcvt_claim, GWCVT_SIGNUP_VOLUNTEER, true ), (string) get_post_meta( $gwcvt_claim, GWCVT_SIGNUP_VOLUNTEER, true ) );
gwcvt_check( 'and the roster stops saying unmatched', false === strpos( gwcvt_signup_name( $gwcvt_claim ), 'unmatched' ), gwcvt_signup_name( $gwcvt_claim ) );
gwcvt_check( 'and they have their hours', 1 === count( gwcvt_entries_for( $gwcvt_joachim ) ), (string) count( gwcvt_entries_for( $gwcvt_joachim ) ) );

/* ── The letter is untouched by any of this ──────────────────────────────────
 * The shift link is metadata. A letter built from hours that came off a roster
 * has to be the same document as one built from hours typed by hand, or every
 * reference code ever issued is worthless.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_letter = gwcvt_build_letter( $gwcvt_jane, array( 'include_unverified' => true ) );

gwcvt_check( 'a letter can still be built', $gwcvt_letter instanceof GWCVT_Letter );

if ( $gwcvt_letter instanceof GWCVT_Letter ) {
	$gwcvt_row = $gwcvt_letter->entries[0] ?? null;

	gwcvt_check( 'and itemises the shift', null !== $gwcvt_row && $gwcvt_yesterday === $gwcvt_row->date, null !== $gwcvt_row ? $gwcvt_row->date : 'none' );
	gwcvt_check( 'with the activity and nothing about a schedule', null !== $gwcvt_row && 'Zzytest warehouse inventory' === $gwcvt_row->activity );
	gwcvt_check( 'and carries a reference', '' !== $gwcvt_letter->reference, $gwcvt_letter->reference );

	/* The row objects carry exactly what the letter prints. If a shift ever
	 * leaked into this shape, every reference code already issued would stop
	 * matching — so the key set is asserted rather than spot-checked. */
	$gwcvt_props = array_keys( get_object_vars( $gwcvt_row ) );
	sort( $gwcvt_props );

	gwcvt_check(
		'a letter row still carries only what it always did',
		array( 'activity', 'attestation', 'date', 'minutes', 'supervisor', 'verified' ) === $gwcvt_props,
		implode( ',', $gwcvt_props )
	);
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

/* The entries created by the handler are not in the fixture list, because the
 * handler made them rather than this script. Found by their link back to a
 * shift, which is exactly what that link is for. */
foreach ( array( $gwcvt_shift, $gwcvt_empty, $gwcvt_waiting, $gwcvt_claim_shift, $gwcvt_future ) as $gwcvt_id ) {
	$gwcvt_entries = get_posts(
		array(
			'post_type'      => GWCVT_ENTRY_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_meta_query -- fixture cleanup.
				array(
					'key'   => GWCVT_ENTRY_SHIFT,
					'value' => $gwcvt_id,
				),
			),
		)
	);

	foreach ( $gwcvt_entries as $gwcvt_entry_id ) {
		wp_delete_post( (int) $gwcvt_entry_id, true );
	}

	delete_option( 'gwcvt_signup_lock_' . $gwcvt_id );
}

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

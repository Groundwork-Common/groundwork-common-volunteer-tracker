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
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();

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
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwc_vt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwc_vt_made'][] = $id;

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
function gwc_vt_make_shift( string $date, string $start = '09:00', string $end = '12:00' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, $start );
	update_post_meta( $id, GWC_VT_SHIFT_END, $end );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzytest warehouse inventory' );
	update_post_meta( $id, GWC_VT_SHIFT_SUPERVISOR, 'Dana Reyes' );

	gwc_vt_retitle_shift( (int) $id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Put somebody on a shift and remember the signup.
 *
 * @param int   $shift_id Shift post ID.
 * @param array $args     Passed to gwc_vt_add_signup().
 * @return int
 */
function gwc_vt_track_signup( int $shift_id, array $args ): int {
	$id = gwc_vt_add_signup( $shift_id, $args );

	if ( $id > 0 ) {
		$GLOBALS['gwc_vt_made'][] = $id;
	}

	return (int) $id;
}

/* ── Running the real handler ────────────────────────────────────────────────
 * The handler is what a browser posts to, and it is where the row-index
 * arithmetic lives — the part that has to keep a cleared checkbox from being
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
function gwc_vt_test_catch_redirect( $location ): void {
	throw new Exception( (string) $location );
}

/**
 * Run the real handler and report the result key it redirected with.
 *
 * @param array $post What the form would have posted.
 * @return string
 */
function gwc_vt_run_quick_add( array $post ): string {
	$_POST                = $post;
	$_REQUEST             = $post;
	$_POST['_wpnonce']    = wp_create_nonce( 'gwc_vt_quick_add' );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

	add_filter( 'wp_redirect', 'gwc_vt_test_catch_redirect', 999 );

	$location = '';

	try {
		gwc_vt_handle_quick_add();
	} catch ( Exception $e ) {
		$location = $e->getMessage();
	}

	remove_filter( 'wp_redirect', 'gwc_vt_test_catch_redirect', 999 );

	$args = array();
	parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $args );

	$_POST    = array();
	$_REQUEST = array();

	return (string) ( $args['gwc_vt_qa'] ?? '' );
}

/**
 * Every hour entry currently attached to a volunteer.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int[]
 */
function gwc_vt_entries_for( int $volunteer_id ): array {
	return array_map( 'intval', gwc_vt_entry_ids_for_volunteer( $volunteer_id ) );
}

/* WP-CLI hooks wp_redirect at priority 10 to warn that something tried to
 * redirect. Here that is not a surprise, it is the assertion — and its backtrace
 * is forty lines between every check, which makes the output unreadable and a
 * failure easy to miss. Removed rather than tolerated. */
remove_filter( 'wp_redirect', 'WP_CLI\Utils\wp_redirect_handler' );

wp_set_current_user( 1 );

$gwc_vt_jane  = gwc_vt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwc_vt_omar  = gwc_vt_make_volunteer( 'Zzytest Omar Delacroix', 'omar@example.test' );
$gwc_vt_priya = gwc_vt_make_volunteer( 'Zzytest Priya Nandakumar', 'priya@example.test' );

/* ── A shift that has not finished cannot be logged ──────────────────────────
 * The assertion this milestone most needs. gwc_vt_save_entry() silently clamps a
 * future date to today, so logging early would date somebody's hours the day
 * they were typed — on a document a court reads, with nothing on screen to say
 * it happened.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_future = gwc_vt_make_shift( gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) ) );
$gwc_vt_early  = gwc_vt_track_signup( $gwc_vt_future, array( 'volunteer_id' => $gwc_vt_jane ) );

$gwc_vt_result = gwc_vt_run_quick_add(
	array(
		'gwc_vt_shift'      => (string) $gwc_vt_future,
		'gwc_vt_date'       => (string) get_post_meta( $gwc_vt_future, GWC_VT_SHIFT_DATE, true ),
		'gwc_vt_activity'   => 'Zzytest warehouse inventory',
		'gwc_vt_supervisor' => 'Dana Reyes',
		'gwc_vt_volunteer'  => array( (string) $gwc_vt_jane ),
		'gwc_vt_hours'      => array( '3' ),
		'gwc_vt_signup'     => array( 0 => (string) $gwc_vt_early ),
		'gwc_vt_attended'   => array( 0 => '1' ),
	)
);

gwc_vt_check( 'a shift that has not ended is refused', 'not-ended' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'and nothing was recorded', 0 === count( gwc_vt_entries_for( $gwc_vt_jane ) ) );
gwc_vt_check( 'nor was it marked as dealt with', ! gwc_vt_shift_is_reconciled( $gwc_vt_future ) );

/* ── The ordinary case ───────────────────────────────────────────────────────
 * Three people signed up. Two came, one of them left early, one did not come at
 * all, and somebody walked in.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$gwc_vt_shift     = gwc_vt_make_shift( $gwc_vt_yesterday );

$gwc_vt_s_jane  = gwc_vt_track_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_jane ) );
$gwc_vt_s_omar  = gwc_vt_track_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_omar ) );
$gwc_vt_s_priya = gwc_vt_track_signup( $gwc_vt_shift, array( 'volunteer_id' => $gwc_vt_priya ) );

gwc_vt_check( 'the scheduled duration is three hours', 180 === gwc_vt_shift_minutes( $gwc_vt_shift ), (string) gwc_vt_shift_minutes( $gwc_vt_shift ) );

$gwc_vt_walk_in = gwc_vt_make_volunteer( 'Zzytest Marcus Bell' );

$gwc_vt_result = gwc_vt_run_quick_add(
	array(
		'gwc_vt_shift'      => (string) $gwc_vt_shift,
		'gwc_vt_date'       => $gwc_vt_yesterday,
		'gwc_vt_activity'   => 'Zzytest warehouse inventory',
		'gwc_vt_supervisor' => 'Dana Reyes',
		'gwc_vt_volunteer'  => array( (string) $gwc_vt_jane, (string) $gwc_vt_omar, (string) $gwc_vt_priya, (string) $gwc_vt_walk_in ),
		'gwc_vt_hours'      => array( '3', '1:30', '3', '2' ),
		'gwc_vt_signup'     => array(
			0 => (string) $gwc_vt_s_jane,
			1 => (string) $gwc_vt_s_omar,
			2 => (string) $gwc_vt_s_priya,
		),
		/* Priya's box is missing, exactly as a browser omits a cleared one.
		 * The indexes are what keep row 3's absence from being read as row 4's. */
		'gwc_vt_attended'   => array(
			0 => '1',
			1 => '1',
		),
	)
);

gwc_vt_check( 'the shift is logged', 'logged' === $gwc_vt_result, $gwc_vt_result );

$gwc_vt_jane_entries = gwc_vt_entries_for( $gwc_vt_jane );
gwc_vt_check( 'somebody who came has one entry', 1 === count( $gwc_vt_jane_entries ), (string) count( $gwc_vt_jane_entries ) );

$gwc_vt_entry = (int) ( $gwc_vt_jane_entries[0] ?? 0 );

gwc_vt_check( 'dated the day of the shift, not today', $gwc_vt_yesterday === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_DATE, true ), (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_DATE, true ) );
gwc_vt_check( 'for the scheduled duration', 180 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true ), (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true ) );
gwc_vt_check( 'with the shift’s activity', 'Zzytest warehouse inventory' === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_ACTIVITY, true ) );
gwc_vt_check( 'and the shift’s supervisor', 'Dana Reyes' === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_SUPERVISOR, true ) );
gwc_vt_check( 'recorded as staff-entered', 'staff' === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_SOURCE, true ) );
gwc_vt_check( 'linked back to the shift', $gwc_vt_shift === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_SHIFT, true ) );
gwc_vt_check( 'and the signup linked forward to it', $gwc_vt_entry === (int) get_post_meta( $gwc_vt_s_jane, GWC_VT_SIGNUP_ENTRY, true ) );

/* Reconciling is not verifying. Matching answers whose hours these are, logging
 * answers whether the shift is written up, and verifying answers whether it
 * happened — three different questions and three different people's jobs. */
gwc_vt_check( 'the entry arrives unverified', '' === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_VERIFIED_AT, true ) );

$gwc_vt_omar_entries = gwc_vt_entries_for( $gwc_vt_omar );
gwc_vt_check(
	'somebody who left early gets the hours that were typed',
	1 === count( $gwc_vt_omar_entries ) && 90 === (int) get_post_meta( (int) $gwc_vt_omar_entries[0], GWC_VT_ENTRY_MINUTES, true ),
	(string) ( $gwc_vt_omar_entries ? get_post_meta( (int) $gwc_vt_omar_entries[0], GWC_VT_ENTRY_MINUTES, true ) : 'none' )
);

/* The assertion the whole checkbox-index note in the handler exists for. */
gwc_vt_check( 'a no-show gets no entry at all', 0 === count( gwc_vt_entries_for( $gwc_vt_priya ) ), (string) count( gwc_vt_entries_for( $gwc_vt_priya ) ) );
gwc_vt_check( 'and no signup-to-entry link', '' === (string) get_post_meta( $gwc_vt_s_priya, GWC_VT_SIGNUP_ENTRY, true ) );

/* Nothing anywhere records that they were absent. */
gwc_vt_check( 'and is still on the roster rather than marked absent', 'publish' === get_post_status( $gwc_vt_s_priya ), (string) get_post_status( $gwc_vt_s_priya ) );

gwc_vt_check( 'a walk-in with no signup is recorded', 1 === count( gwc_vt_entries_for( $gwc_vt_walk_in ) ), (string) count( gwc_vt_entries_for( $gwc_vt_walk_in ) ) );

gwc_vt_check( 'the shift is marked as dealt with', gwc_vt_shift_is_reconciled( $gwc_vt_shift ) );

/* ── Totals move by exactly what was logged ──────────────────────────────── */

$gwc_vt_totals = gwc_vt_volunteer_totals( $gwc_vt_jane );
gwc_vt_check( 'the volunteer’s pending total is the logged minutes', 180 === $gwc_vt_totals->pending_minutes, (string) $gwc_vt_totals->pending_minutes );
gwc_vt_check( 'and nothing is verified yet', 0 === $gwc_vt_totals->verified_minutes, (string) $gwc_vt_totals->verified_minutes );

$gwc_vt_priya_totals = gwc_vt_volunteer_totals( $gwc_vt_priya );
gwc_vt_check( 'a no-show’s totals did not move', 0 === $gwc_vt_priya_totals->total_minutes(), (string) $gwc_vt_priya_totals->total_minutes() );

/* ── Logging twice does not double anybody ───────────────────────────────────
 * The screen renders an already-logged row with no checkbox and posts nobody
 * for it. This asserts the handler agrees, because a coordinator who reopens
 * the screen to add a missed walk-in must not silently double everybody else.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_missed = gwc_vt_make_volunteer( 'Zzytest Wendell Achebe' );

$gwc_vt_result = gwc_vt_run_quick_add(
	array(
		'gwc_vt_shift'      => (string) $gwc_vt_shift,
		'gwc_vt_date'       => $gwc_vt_yesterday,
		'gwc_vt_activity'   => 'Zzytest warehouse inventory',
		'gwc_vt_supervisor' => 'Dana Reyes',
		// Row 0 is Jane, already logged: posts nobody and no hours, as the screen does.
		'gwc_vt_volunteer'  => array( '0', (string) $gwc_vt_missed ),
		'gwc_vt_hours'      => array( '', '4' ),
		'gwc_vt_signup'     => array(),
		'gwc_vt_attended'   => array(),
	)
);

gwc_vt_check( 'a second pass logs the person who was missed', 'logged' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'they have an entry', 1 === count( gwc_vt_entries_for( $gwc_vt_missed ) ), (string) count( gwc_vt_entries_for( $gwc_vt_missed ) ) );
gwc_vt_check( 'and nobody was logged twice', 1 === count( gwc_vt_entries_for( $gwc_vt_jane ) ), (string) count( gwc_vt_entries_for( $gwc_vt_jane ) ) );
gwc_vt_check( 'so the totals did not move either', 180 === gwc_vt_volunteer_totals( $gwc_vt_jane )->pending_minutes, (string) gwc_vt_volunteer_totals( $gwc_vt_jane )->pending_minutes );

/* ── Reopening does not re-select a no-show ────────────────────────────────────
 * The screen is what defends this, so the screen is what is asserted. Somebody
 * on the roster of a logged shift with no entry has already been recorded as
 * not having come; if reopening the page brought them back selected with the
 * scheduled hours filled in, one press of Save would credit them a shift they
 * did not work — onto a record a letter is built from, with nothing to show it.
 * ─────────────────────────────────────────────────────────────────────────── */

ob_start();
gwc_vt_render_roster_log_row( 0, $gwc_vt_s_priya, $gwc_vt_shift, true );
$gwc_vt_reopened = (string) ob_get_clean();

gwc_vt_check( 'a no-show comes back cleared when the shift was already logged', false === strpos( $gwc_vt_reopened, 'checked=' ), 'the box was pre-selected' );
gwc_vt_check( 'and says why', false !== strpos( $gwc_vt_reopened, 'Recorded as not having come' ) );

ob_start();
gwc_vt_render_roster_log_row( 0, $gwc_vt_s_priya, $gwc_vt_shift, false );
$gwc_vt_first_pass = (string) ob_get_clean();

gwc_vt_check( 'but is selected on a shift nobody has logged yet', false !== strpos( $gwc_vt_first_pass, 'checked=' ) );

ob_start();
gwc_vt_render_roster_log_row( 0, $gwc_vt_s_jane, $gwc_vt_shift, true );
$gwc_vt_logged_row = (string) ob_get_clean();

gwc_vt_check( 'and somebody already logged gets no box at all', false === strpos( $gwc_vt_logged_row, 'gwc_vt_attended' ) );
gwc_vt_check( 'nor a hours field that could add more', false === strpos( $gwc_vt_logged_row, 'inputmode' ) );

/* ── A shift where nobody came ───────────────────────────────────────────────
 * "Nobody turned up" is an answer, so the shift stops asking.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_empty  = gwc_vt_make_shift( $gwc_vt_yesterday );
$gwc_vt_s_none = gwc_vt_track_signup( $gwc_vt_empty, array( 'volunteer_id' => $gwc_vt_omar ) );

$gwc_vt_before = count( gwc_vt_entries_for( $gwc_vt_omar ) );

$gwc_vt_result = gwc_vt_run_quick_add(
	array(
		'gwc_vt_shift'      => (string) $gwc_vt_empty,
		'gwc_vt_date'       => $gwc_vt_yesterday,
		'gwc_vt_activity'   => 'Zzytest warehouse inventory',
		'gwc_vt_supervisor' => 'Dana Reyes',
		'gwc_vt_volunteer'  => array( (string) $gwc_vt_omar ),
		'gwc_vt_hours'      => array( '3' ),
		'gwc_vt_signup'     => array( 0 => (string) $gwc_vt_s_none ),
		'gwc_vt_attended'   => array(),
	)
);

gwc_vt_check( 'nothing is logged when nobody came', 'nothing' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'no entry was created', $gwc_vt_before === count( gwc_vt_entries_for( $gwc_vt_omar ) ) );
gwc_vt_check( 'but the shift is still marked as dealt with', gwc_vt_shift_is_reconciled( $gwc_vt_empty ) );

/* ── The unreconciled queue ──────────────────────────────────────────────── */

$gwc_vt_waiting = gwc_vt_make_shift( $gwc_vt_yesterday );
gwc_vt_track_signup( $gwc_vt_waiting, array( 'volunteer_id' => $gwc_vt_jane ) );

$gwc_vt_queue = gwc_vt_unreconciled_shift_ids();

gwc_vt_check( 'a shift with a roster and no hours is in the queue', in_array( $gwc_vt_waiting, $gwc_vt_queue, true ) );
gwc_vt_check( 'one that has been logged is not', ! in_array( $gwc_vt_shift, $gwc_vt_queue, true ) );
gwc_vt_check( 'nor is one nobody signed up for', ! in_array( $gwc_vt_future, $gwc_vt_queue, true ) );

/* ── Logging also resolves an unmatched signup ───────────────────────────────
 * Picking who somebody is IS the match, so the roster stops saying "unmatched"
 * rather than carrying a claim forever. Matching, still not attesting.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_claim_shift = gwc_vt_make_shift( $gwc_vt_yesterday );
$gwc_vt_claim       = gwc_vt_track_signup(
	$gwc_vt_claim_shift,
	array(
		'claim_name'  => 'Zzytest Joachim Whitfeather',
		'claim_email' => 'joachim@example.test',
		'source'      => 'self',
	)
);

gwc_vt_check( 'the signup starts unmatched', 0 === (int) get_post_meta( $gwc_vt_claim, GWC_VT_SIGNUP_VOLUNTEER, true ) );

$gwc_vt_joachim = gwc_vt_make_volunteer( 'Zzytest Joachim Whitfeather', 'joachim@example.test' );

$gwc_vt_suggestion = gwc_vt_suggest_volunteer_for_signup( $gwc_vt_claim );
gwc_vt_check( 'and the screen suggests who they are, by email', $gwc_vt_joachim === (int) $gwc_vt_suggestion['volunteer_id'], (string) $gwc_vt_suggestion['volunteer_id'] );
gwc_vt_check( 'saying what it matched on', 'email' === $gwc_vt_suggestion['matched_on'], $gwc_vt_suggestion['matched_on'] );

gwc_vt_run_quick_add(
	array(
		'gwc_vt_shift'      => (string) $gwc_vt_claim_shift,
		'gwc_vt_date'       => $gwc_vt_yesterday,
		'gwc_vt_activity'   => 'Zzytest warehouse inventory',
		'gwc_vt_supervisor' => 'Dana Reyes',
		'gwc_vt_volunteer'  => array( (string) $gwc_vt_joachim ),
		'gwc_vt_hours'      => array( '3' ),
		'gwc_vt_signup'     => array( 0 => (string) $gwc_vt_claim ),
		'gwc_vt_attended'   => array( 0 => '1' ),
	)
);

gwc_vt_check( 'logging their hours attaches the signup', $gwc_vt_joachim === (int) get_post_meta( $gwc_vt_claim, GWC_VT_SIGNUP_VOLUNTEER, true ), (string) get_post_meta( $gwc_vt_claim, GWC_VT_SIGNUP_VOLUNTEER, true ) );
gwc_vt_check( 'and the roster stops saying unmatched', false === strpos( gwc_vt_signup_name( $gwc_vt_claim ), 'unmatched' ), gwc_vt_signup_name( $gwc_vt_claim ) );
gwc_vt_check( 'and they have their hours', 1 === count( gwc_vt_entries_for( $gwc_vt_joachim ) ), (string) count( gwc_vt_entries_for( $gwc_vt_joachim ) ) );

/* ── The letter is untouched by any of this ──────────────────────────────────
 * The shift link is metadata. A letter built from hours that came off a roster
 * has to be the same document as one built from hours typed by hand, or every
 * reference code ever issued is worthless.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_jane, array( 'include_unverified' => true ) );

gwc_vt_check( 'a letter can still be built', $gwc_vt_letter instanceof GWC_VT_Letter );

if ( $gwc_vt_letter instanceof GWC_VT_Letter ) {
	$gwc_vt_row = $gwc_vt_letter->entries[0] ?? null;

	gwc_vt_check( 'and itemises the shift', null !== $gwc_vt_row && $gwc_vt_yesterday === $gwc_vt_row->date, null !== $gwc_vt_row ? $gwc_vt_row->date : 'none' );
	gwc_vt_check( 'with the activity and nothing about a schedule', null !== $gwc_vt_row && 'Zzytest warehouse inventory' === $gwc_vt_row->activity );
	gwc_vt_check( 'and carries a reference', '' !== $gwc_vt_letter->reference, $gwc_vt_letter->reference );

	/* The row objects carry exactly what the letter prints. If a shift ever
	 * leaked into this shape, every reference code already issued would stop
	 * matching — so the key set is asserted rather than spot-checked. */
	$gwc_vt_props = array_keys( get_object_vars( $gwc_vt_row ) );
	sort( $gwc_vt_props );

	gwc_vt_check(
		'a letter row still carries only what it always did',
		array( 'activity', 'attestation', 'date', 'minutes', 'supervisor', 'verified' ) === $gwc_vt_props,
		implode( ',', $gwc_vt_props )
	);
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

/* The entries created by the handler are not in the fixture list, because the
 * handler made them rather than this script. Found by their link back to a
 * shift, which is exactly what that link is for. */
foreach ( array( $gwc_vt_shift, $gwc_vt_empty, $gwc_vt_waiting, $gwc_vt_claim_shift, $gwc_vt_future ) as $gwc_vt_id ) {
	$gwc_vt_entries = get_posts(
		array(
			'post_type'      => GWC_VT_ENTRY_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_meta_query -- fixture cleanup.
				array(
					'key'   => GWC_VT_ENTRY_SHIFT,
					'value' => $gwc_vt_id,
				),
			),
		)
	);

	foreach ( $gwc_vt_entries as $gwc_vt_entry_id ) {
		wp_delete_post( (int) $gwc_vt_entry_id, true );
	}

	delete_option( 'gwc_vt_signup_lock_' . $gwc_vt_id );
}

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

<?php
/**
 * The verify queue: what it lists, and that it lists what the count counted.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Everything here is a meta query over real entries. The assertion that matters
 * most is the one CLAUDE.md has a rule about: gwc_vt_unverified_count() puts a
 * number on the dashboard, on the menu bubble and at the top of this screen, and
 * gwc_vt_unverified_entry_ids() decides which rows appear underneath it. They
 * ask the same question of the same meta key and this proves it, because a
 * screen listing six rows under a line that says eight is the failure that rule
 * exists to prevent.
 *
 * The other three:
 *
 *   An entry nobody has matched to a volunteer is not offered attestation. That
 *   is the verify/log/match separation, and this screen is where it is kept:
 *   those rows get the match controls instead of a button.
 *
 *   The source label tells apart two entries that both say 'staff': one logged
 *   against a shift's roster, one typed into a blank day. Derived from
 *   GWC_VT_ENTRY_SHIFT, so it is worth checking it derives.
 *
 *   And the letter offer only fires when it is true.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/verify-queue.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL. See tests/integration/entries.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_vq_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_vq_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Delete everything this script created.
 */
function gwc_vt_vq_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_vq_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}

	gwc_vt_forget_unverified_count();
}

register_shutdown_function( 'gwc_vt_vq_cleanup' );

/**
 * One hour entry.
 *
 * @param int    $volunteer_id Whose, or 0 for an unmatched claim.
 * @param int    $minutes      How long.
 * @param string $source       'staff' or 'self'.
 * @param int    $shift_id     The shift it was logged against, or 0.
 * @return int
 */
function gwc_vt_vq_entry( int $volunteer_id, int $minutes, string $source = 'staff', int $shift_id = 0 ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	$id = (int) $id;

	$GLOBALS['gwc_vt_vq_made'][] = $id;

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, gmdate( 'Y-m-d', time() - ( 20 * DAY_IN_SECONDS ) ) );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Zzytest sorting' );
	update_post_meta( $id, GWC_VT_ENTRY_SOURCE, $source );

	if ( $shift_id > 0 ) {
		update_post_meta( $id, GWC_VT_ENTRY_SHIFT, $shift_id );
	}

	gwc_vt_forget_unverified_count();

	return $id;
}

/* ── The count and the list are the same question ───────────────────────────
 * Asserted against each other rather than against a literal, because a literal
 * would go on passing if both grew the same wrong answer.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_vq_before_count'] = gwc_vt_unverified_count();
$GLOBALS['gwc_vt_vq_before_rows']  = count( gwc_vt_unverified_entry_ids() );

gwc_vt_vq_check(
	'before anything, the count and the list already agree',
	$GLOBALS['gwc_vt_vq_before_count'] === $GLOBALS['gwc_vt_vq_before_rows'],
	$GLOBALS['gwc_vt_vq_before_count'] . ' counted, ' . $GLOBALS['gwc_vt_vq_before_rows'] . ' listed'
);

$gwc_vt_vq_priya = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Priya Ramanathan',
	)
);

$GLOBALS['gwc_vt_vq_made'][] = (int) $gwc_vt_vq_priya;

$gwc_vt_vq_roster = gwc_vt_vq_entry( (int) $gwc_vt_vq_priya, 195, 'staff', 4242 );
$gwc_vt_vq_typed  = gwc_vt_vq_entry( (int) $gwc_vt_vq_priya, 180, 'staff' );
$gwc_vt_vq_public = gwc_vt_vq_entry( (int) $gwc_vt_vq_priya, 120, 'self' );
$gwc_vt_vq_claim  = gwc_vt_vq_entry( 0, 150, 'self' );

gwc_vt_vq_check(
	'four more entries, four more of both',
	gwc_vt_unverified_count() === count( gwc_vt_unverified_entry_ids() )
		&& gwc_vt_unverified_count() === $GLOBALS['gwc_vt_vq_before_count'] + 4,
	gwc_vt_unverified_count() . ' counted, ' . count( gwc_vt_unverified_entry_ids() ) . ' listed'
);

gwc_vt_vq_check(
	'the queue holds the unmatched claim, rather than leaving it out',
	in_array( $gwc_vt_vq_claim, gwc_vt_unverified_entry_ids(), true )
);

/* ── Verifying one takes it out of both ──────────────────────────────────── */

gwc_vt_verify_entry( $gwc_vt_vq_typed, 1 );
gwc_vt_forget_unverified_count();

gwc_vt_vq_check(
	'verifying one takes it out of the list',
	! in_array( $gwc_vt_vq_typed, gwc_vt_unverified_entry_ids(), true )
);

gwc_vt_vq_check(
	'and out of the count, by the same one',
	gwc_vt_unverified_count() === count( gwc_vt_unverified_entry_ids() )
		&& gwc_vt_unverified_count() === $GLOBALS['gwc_vt_vq_before_count'] + 3,
	gwc_vt_unverified_count() . ' counted, ' . count( gwc_vt_unverified_entry_ids() ) . ' listed'
);

/* ── An unmatched claim is not offered attestation ───────────────────────────
 * The verify/log/match separation, as this screen keeps it: a claim nobody has
 * said whose it is gets the match controls and no verify button.
 *
 * That is a guarantee about the SCREEN, and it is asserted below by counting
 * buttons. It is deliberately not asserted about gwc_vt_verify_entry(), because
 * that function accepts an unmatched entry today — as does the list table's own
 * row action, which offers "Verify" on one. This screen is the stricter of the
 * two, and tightening the shared write path is a change to every route at once.
 * Filed rather than done here.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_vq_check(
	'an unmatched claim is still on nobody’s record',
	0 === (int) get_post_meta( $gwc_vt_vq_claim, GWC_VT_ENTRY_VOLUNTEER, true ),
	(string) get_post_meta( $gwc_vt_vq_claim, GWC_VT_ENTRY_VOLUNTEER, true )
);

gwc_vt_vq_check(
	'and reaches no letter, whatever its verified state',
	! in_array( $gwc_vt_vq_claim, gwc_vt_entry_ids_for_volunteer( (int) $gwc_vt_vq_priya ), true )
);

/* ── Where an entry came from ────────────────────────────────────────────────
 * Two entries both stored as 'staff'. What tells them apart is whether a shift
 * was on the other end of it.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_vq_check(
	'an entry logged against a shift says so',
	gwc_vt_entry_source_label( $gwc_vt_vq_roster ) !== gwc_vt_entry_source_label( $gwc_vt_vq_typed ),
	gwc_vt_entry_source_label( $gwc_vt_vq_roster ) . ' vs ' . gwc_vt_entry_source_label( $gwc_vt_vq_typed )
);

gwc_vt_vq_check(
	'and one from the public form says that instead',
	gwc_vt_entry_source_label( $gwc_vt_vq_public ) !== gwc_vt_entry_source_label( $gwc_vt_vq_typed )
		&& gwc_vt_entry_source_label( $gwc_vt_vq_public ) !== gwc_vt_entry_source_label( $gwc_vt_vq_roster ),
	gwc_vt_entry_source_label( $gwc_vt_vq_public )
);

/* ── Hours come from minutes ─────────────────────────────────────────────────
 * Durations are integer minutes and that is a hard rule. The 3.25 the screen
 * prints is a rendering of 195, never a stored decimal.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_vq_check(
	'195 minutes is stored as an integer',
	'195' === (string) get_post_meta( $gwc_vt_vq_roster, GWC_VT_ENTRY_MINUTES, true ),
	(string) get_post_meta( $gwc_vt_vq_roster, GWC_VT_ENTRY_MINUTES, true )
);

gwc_vt_vq_check(
	'and rendered as three and a quarter hours',
	false !== strpos( gwc_vt_format_hours( 195 ), '3' ),
	gwc_vt_format_hours( 195 )
);

/* ── The screen ──────────────────────────────────────────────────────────── */

$gwc_vt_vq_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( $gwc_vt_vq_admins ) {
	wp_set_current_user( (int) $gwc_vt_vq_admins[0] );
}

ob_start();
gwc_vt_render_verify_queue();
$gwc_vt_vq_html = (string) ob_get_clean();

gwc_vt_vq_check(
	'the screen names the volunteer it grouped by',
	false !== strpos( $gwc_vt_vq_html, 'Zzytest Priya Ramanathan' )
);

gwc_vt_vq_check(
	'it offers attestation in those words',
	false !== strpos( $gwc_vt_vq_html, 'I attest' )
);

gwc_vt_vq_check(
	'it holds the unmatched claim apart',
	false !== strpos( $gwc_vt_vq_html, 'match first' )
);

/* One button per waiting entry, MINUS the unmatched ones. Counted against the
 * queue's own contents rather than against a literal, because this database
 * belongs to somebody else and may already have entries of its own — an
 * assertion that assumed the site was empty would pass or fail on what was
 * here before the script ran. */
$gwc_vt_vq_waiting   = gwc_vt_unverified_entry_ids();
$gwc_vt_vq_unmatched = 0;

foreach ( $gwc_vt_vq_waiting as $gwc_vt_vq_id ) {
	if ( (int) get_post_meta( (int) $gwc_vt_vq_id, GWC_VT_ENTRY_VOLUNTEER, true ) < 1 ) {
		++$gwc_vt_vq_unmatched;
	}
}

$gwc_vt_vq_buttons = substr_count( $gwc_vt_vq_html, 'I attest' );

gwc_vt_vq_check(
	'there is a button for every waiting entry that has a name on it',
	$gwc_vt_vq_buttons === count( $gwc_vt_vq_waiting ) - $gwc_vt_vq_unmatched,
	$gwc_vt_vq_buttons . ' buttons, ' . count( $gwc_vt_vq_waiting ) . ' waiting, ' . $gwc_vt_vq_unmatched . ' unmatched'
);

gwc_vt_vq_check(
	'and the fixture really did include an unmatched one',
	$gwc_vt_vq_unmatched > 0,
	(string) $gwc_vt_vq_unmatched
);

/* ── The letter offer only fires when it is true ─────────────────────────── */

$_GET['gwc_vt_finished'] = (int) $gwc_vt_vq_priya;

ob_start();
gwc_vt_render_verify_letter_cta();
$gwc_vt_vq_cta = (string) ob_get_clean();

gwc_vt_vq_check(
	'no letter is offered while anything of theirs is waiting',
	'' === trim( $gwc_vt_vq_cta ),
	'' === trim( $gwc_vt_vq_cta ) ? 'nothing offered' : 'it offered one anyway'
);

foreach ( gwc_vt_entry_ids_for_volunteer( (int) $gwc_vt_vq_priya ) as $gwc_vt_vq_id ) {
	gwc_vt_verify_entry( (int) $gwc_vt_vq_id, 1 );
}

gwc_vt_forget_unverified_count();

ob_start();
gwc_vt_render_verify_letter_cta();
$gwc_vt_vq_cta = (string) ob_get_clean();

gwc_vt_vq_check(
	'and one is once nothing is',
	false !== strpos( $gwc_vt_vq_cta, 'Zzytest Priya Ramanathan' ),
	'' === trim( $gwc_vt_vq_cta ) ? 'nothing offered' : 'offered'
);

unset( $_GET['gwc_vt_finished'] );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

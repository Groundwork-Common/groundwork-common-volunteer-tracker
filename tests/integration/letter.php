<?php
/**
 * The letter, assembled from real records and rendered.
 *
 * LetterTest builds models by hand because the unit bootstrap has no
 * get_posts(). This runs the whole path — records to model to document — and
 * checks the things that can only be checked on the finished output.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter.php
 *
 * @package VolunteerTracker
 */

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
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether to attest to it.
 * @return int
 */
function gwc_vt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		gwc_vt_verify_entry( (int) $id, 1 );
	}

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

wp_set_current_user( 1 );

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => "Zzytest Ada O'Brien",
	)
);
update_post_meta( $gwc_vt_volunteer, GWC_VT_VOLUNTEER_EMAIL, 'ada@example.test' );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_volunteer;

gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-09', 180, true );
gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-02', 210, true );   // out of order on purpose
gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-16', 90, false );   // unverified
gwc_vt_make_entry( $gwc_vt_volunteer, '2026-09-01', 600, true );   // outside the range

/* ── The model ───────────────────────────────────────────────────────────── */

$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_volunteer, array( 'from' => '2026-03-01', 'to' => '2026-03-31' ) );

gwc_vt_check( 'a letter is built', $gwc_vt_letter instanceof GWC_VT_Letter );
gwc_vt_check( 'verified minutes', 390 === $gwc_vt_letter->verified_minutes, (string) $gwc_vt_letter->verified_minutes );
gwc_vt_check( 'unverified rows are excluded by default', 2 === $gwc_vt_letter->entry_count(), (string) $gwc_vt_letter->entry_count() );
gwc_vt_check( 'hours outside the range are excluded', 390 === $gwc_vt_letter->verified_minutes );

gwc_vt_check(
	'entries are ordered oldest first',
	'2026-03-02' === $gwc_vt_letter->entries[0]->date,
	$gwc_vt_letter->entries[0]->date
);

gwc_vt_check(
	'each row carries its attestation',
	false !== strpos( $gwc_vt_letter->entries[0]->attestation, 'Verified' ),
	$gwc_vt_letter->entries[0]->attestation
);

/* ── Unverified, when asked for ──────────────────────────────────────────── */

$gwc_vt_with_unverified = gwc_vt_build_letter(
	$gwc_vt_volunteer,
	array( 'from' => '2026-03-01', 'to' => '2026-03-31', 'include_unverified' => true )
);

gwc_vt_check( 'unverified rows appear when enabled', 3 === $gwc_vt_with_unverified->entry_count(), (string) $gwc_vt_with_unverified->entry_count() );
gwc_vt_check(
	'and are never added to the verified total',
	390 === $gwc_vt_with_unverified->verified_minutes,
	(string) $gwc_vt_with_unverified->verified_minutes
);
gwc_vt_check( 'but are reported separately', 90 === $gwc_vt_with_unverified->unverified_minutes, (string) $gwc_vt_with_unverified->unverified_minutes );

/* ── A pending entry never reaches a letter ──────────────────────────────── */

$gwc_vt_pending = wp_insert_post(
	array( 'post_type' => GWC_VT_ENTRY_TYPE, 'post_status' => 'pending', 'post_title' => 'tmp' )
);
update_post_meta( $gwc_vt_pending, GWC_VT_ENTRY_VOLUNTEER, (string) $gwc_vt_volunteer );
update_post_meta( $gwc_vt_pending, GWC_VT_ENTRY_DATE, '2026-03-20' );
update_post_meta( $gwc_vt_pending, GWC_VT_ENTRY_MINUTES, 999 );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_pending;

$gwc_vt_recheck = gwc_vt_build_letter(
	$gwc_vt_volunteer,
	array( 'from' => '2026-03-01', 'to' => '2026-03-31', 'include_unverified' => true )
);
gwc_vt_check(
	'an untriaged self-logged entry never reaches a letter',
	3 === $gwc_vt_recheck->entry_count(),
	(string) $gwc_vt_recheck->entry_count()
);

/* ── The printed document ────────────────────────────────────────────────── */

$gwc_vt_print = gwc_vt_render_letter( $gwc_vt_letter, 'print' );

gwc_vt_check( 'the print view is a whole document', 0 === strpos( ltrim( $gwc_vt_print ), '<!doctype html>' ) );
gwc_vt_check( 'it names the organization', false !== strpos( $gwc_vt_print, get_bloginfo( 'name' ) ) );
/* Compared against exactly what the template escapes, rather than against a
 * literal typed here. get_the_title() runs the `the_title` filter, which
 * texturizes — so the apostrophe in O'Brien reaches the letter as a curly one.
 * That is correct and desirable typographically, and a hand-typed expectation
 * just makes the test wrong about its own subject. */
gwc_vt_check(
	'it names the volunteer',
	false !== strpos( $gwc_vt_print, esc_html( $gwc_vt_letter->volunteer_name ) ),
	$gwc_vt_letter->volunteer_name
);
gwc_vt_check( 'it states the verified total', false !== strpos( $gwc_vt_print, '6.5' ), 'looking for 6.5' );
gwc_vt_check( 'it carries the reference code', false !== strpos( $gwc_vt_print, $gwc_vt_letter->reference ) );
gwc_vt_check( 'it carries the disclaimer', false !== strpos( $gwc_vt_print, 'authoritative record-keeper' ) );
gwc_vt_check( 'it links the print stylesheet', false !== strpos( $gwc_vt_print, 'assets/css/letter.css' ) );
gwc_vt_check( 'it asks robots not to index it', false !== strpos( $gwc_vt_print, 'noindex' ) );
gwc_vt_check( 'it contains no script tag', false === stripos( $gwc_vt_print, '<script' ) );

/* The claims this document must never make about itself. */
foreach ( array( 'certified', 'certifies', 'penalty of perjury', 'sworn', 'notari' ) as $gwc_vt_word ) {
	gwc_vt_check(
		'it never says "' . $gwc_vt_word . '"',
		false === stripos( $gwc_vt_print, $gwc_vt_word )
	);
}

/* ── The emailed document ────────────────────────────────────────────────── */

$gwc_vt_email = gwc_vt_render_letter( $gwc_vt_letter, 'email' );

gwc_vt_check( 'the email carries inline styles', false !== strpos( $gwc_vt_email, 'style="font-family:' ) );
gwc_vt_check( 'the email links no stylesheet', false === strpos( $gwc_vt_email, '<link' ) );
gwc_vt_check( 'the email has no print toolbar', false === strpos( $gwc_vt_email, 'gwcvt-print-button' ) );
gwc_vt_check( 'the email carries the same reference', false !== strpos( $gwc_vt_email, $gwc_vt_letter->reference ) );
gwc_vt_check( 'the email carries the disclaimer', false !== strpos( $gwc_vt_email, 'authoritative record-keeper' ) );

/* Both media must state the same total. This is the assertion behind the
 * one-template rule: if they ever diverge, a court has a letter that differs
 * from the organization's copy. */
gwc_vt_check(
	'print and email state the same hours',
	( false !== strpos( $gwc_vt_print, '6.5' ) ) === ( false !== strpos( $gwc_vt_email, '6.5' ) )
);

/* ── The log and the reference checker ───────────────────────────────────── */

$gwc_vt_record_id = gwc_vt_log_letter( $gwc_vt_letter, 'print' );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_record_id;

gwc_vt_check( 'issuing writes a log record', $gwc_vt_record_id > 0 );

$gwc_vt_result = gwc_vt_verify_reference( $gwc_vt_letter->reference );
gwc_vt_check( 'a fresh reference matches', 'match' === $gwc_vt_result['status'], $gwc_vt_result['status'] );

$gwc_vt_unknown = gwc_vt_verify_reference( 'REF-99-20260101-DEADBEEF' );
gwc_vt_check( 'an unknown reference is reported as unknown', 'unknown' === $gwc_vt_unknown['status'], $gwc_vt_unknown['status'] );

/* Change the records, then re-check the same code. */
gwc_vt_make_entry( $gwc_vt_volunteer, '2026-03-23', 60, true );

$gwc_vt_changed = gwc_vt_verify_reference( $gwc_vt_letter->reference );
gwc_vt_check( 'editing the records makes the code stop matching', 'changed' === $gwc_vt_changed['status'], $gwc_vt_changed['status'] );
gwc_vt_check(
	'and the current figure is reported alongside',
	450 === (int) ( $gwc_vt_changed['current']['minutes'] ?? 0 ),
	(string) ( $gwc_vt_changed['current']['minutes'] ?? 0 )
);

/* ── Changes the old digest could not see ────────────────────────────────────
 * The first version of the reference hashed only the volunteer, the range, the
 * total and the count. Each case below leaves all four identical, and each is a
 * change to what the document says — so each used to come back "matches".
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Issue a letter, alter one entry, and report whether the code notices.
 *
 * @param int      $volunteer_id Volunteer post ID.
 * @param callable $alter        Given an entry ID, changes something on it.
 * @return string The status the checker reports afterwards.
 */
function gwc_vt_tamper( int $volunteer_id, callable $alter ): string {
	$letter = gwc_vt_build_letter( $volunteer_id );
	$record = gwc_vt_log_letter( $letter, 'print' );
	$GLOBALS['gwc_vt_made'][] = $record;

	$ids = gwc_vt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish' ) ) );
	$alter( (int) $ids[0] );

	return gwc_vt_verify_reference( $letter->reference )['status'];
}

$gwc_vt_tamper_volunteer = wp_insert_post(
	array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzytest Tamper Subject' )
);
$GLOBALS['gwc_vt_made'][] = $gwc_vt_tamper_volunteer;

gwc_vt_make_entry( $gwc_vt_tamper_volunteer, '2026-04-06', 210, true );
gwc_vt_make_entry( $gwc_vt_tamper_volunteer, '2026-04-13', 180, true );

gwc_vt_check(
	'rewriting an activity is detected',
	'changed' === gwc_vt_tamper(
		$gwc_vt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Something entirely different' );
		}
	)
);

gwc_vt_check(
	'changing a supervisor is detected',
	'changed' === gwc_vt_tamper(
		$gwc_vt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Someone Who Was Not There' );
		}
	)
);

gwc_vt_check(
	'moving a date within the range is detected',
	'changed' === gwc_vt_tamper(
		$gwc_vt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWC_VT_ENTRY_DATE, '2026-04-07' );
		}
	)
);

/* The one the old digest was most obviously blind to: the total and the count
 * are untouched, only the split between two shifts moves. */
gwc_vt_check(
	'swapping hours between two shifts is detected',
	'changed' === gwc_vt_tamper(
		$gwc_vt_tamper_volunteer,
		static function ( int $id ) use ( $gwc_vt_tamper_volunteer ): void {
			$ids = gwc_vt_entry_ids_for_volunteer( $gwc_vt_tamper_volunteer, array( 'statuses' => array( 'publish' ) ) );
			$a   = (int) get_post_meta( (int) $ids[0], GWC_VT_ENTRY_MINUTES, true );
			$b   = (int) get_post_meta( (int) $ids[1], GWC_VT_ENTRY_MINUTES, true );
			update_post_meta( (int) $ids[0], GWC_VT_ENTRY_MINUTES, $b );
			update_post_meta( (int) $ids[1], GWC_VT_ENTRY_MINUTES, $a );
		}
	)
);

/* And an untouched letter still matches, so the above are detections rather
 * than a verifier that has started refusing everything. */
$gwc_vt_clean = gwc_vt_build_letter( $gwc_vt_tamper_volunteer );
$gwc_vt_clean_record = gwc_vt_log_letter( $gwc_vt_clean, 'print' );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_clean_record;

gwc_vt_check(
	'an untouched letter still matches',
	'match' === gwc_vt_verify_reference( $gwc_vt_clean->reference )['status']
);

/* The checker hands back the rebuilt letter so the screen can show it in full. */
$gwc_vt_rebuilt = gwc_vt_verify_reference( $gwc_vt_clean->reference )['rebuilt'];

gwc_vt_check( 'the checker returns a rebuilt letter', $gwc_vt_rebuilt instanceof GWC_VT_Letter );
gwc_vt_check(
	'and it renders as a full document',
	$gwc_vt_rebuilt instanceof GWC_VT_Letter
		&& false !== strpos( gwc_vt_letter_body( $gwc_vt_rebuilt, 'print' ), 'authoritative record-keeper' )
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

/* ── The produce screen, which no longer exists ──────────────────────────────
 * Six checks lived here about a screen you went to, searched for a volunteer
 * on, and produced a letter from: that a name typed but not chosen was answered
 * rather than ignored, that the typed name survived the round trip, that
 * arriving fresh was not an error, and that choosing somebody drew their
 * letter.
 *
 * All of it was about the screen's first question — WHO — and that question is
 * the reason the screen is gone. Letters are written in a box on the
 * volunteer's own record, where the answer is the page you are already on and
 * cannot be typed wrongly. The bug those checks were written for could not
 * occur now if somebody tried.
 *
 * What was worth keeping is below and has nothing to do with the screen: the
 * draft medium, and the one line that separates reading a letter from issuing
 * one.
 * ─────────────────────────────────────────────────────────────────────────── */

echo "\n── Reading a letter is not issuing it ───────────────────────────\n";

$GLOBALS['gwc_vt_shown'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Rosalind Okonkwo',
	)
);

$GLOBALS['gwc_vt_made'][] = $GLOBALS['gwc_vt_shown'];

gwc_vt_make_entry( (int) $GLOBALS['gwc_vt_shown'], '2026-03-09', 180, true );

/* ── The preview is the letter, and it is not an issue ───────────────────────
 * What the screen called a preview was four figures in a table. The document —
 * the letterhead, the shift table, the wording, the disclaimer, the signature
 * line — could not be read by anybody until the letter had been issued, because
 * opening one to print IS issuing it: gwc_vt_handle_letter_print() logs before
 * it renders.
 *
 * So there is a draft medium. The whole of its difference from printing is the
 * line that is not in the handler, and these checks are about that line.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_draft'] = gwc_vt_render_letter(
	gwc_vt_build_letter( (int) $GLOBALS['gwc_vt_shown'] ),
	'draft'
);

gwc_vt_check(
	'a draft is the whole document, not a summary of it',
	false !== strpos( $GLOBALS['gwc_vt_draft'], 'gwcvt-disclaimer' )
		&& false !== strpos( $GLOBALS['gwc_vt_draft'], 'gwcvt-signature' )
);

gwc_vt_check(
	'and says it is a draft, in words that survive printing',
	false !== strpos( $GLOBALS['gwc_vt_draft'], 'gwcvt-letter-draft' )
		&& false !== strpos( $GLOBALS['gwc_vt_draft'], 'Draft' )
);

/* The reference is the digest somebody checks by telephone against the log, and
 * a draft is in no log. Not on the footer, and not through the {reference}
 * token an administrator can put in the intro or the disclaimer either. */
$GLOBALS['gwc_vt_draft_letter'] = gwc_vt_build_letter( (int) $GLOBALS['gwc_vt_shown'] );

gwc_vt_check(
	'and carries no reference anywhere on it',
	false === strpos( $GLOBALS['gwc_vt_draft'], $GLOBALS['gwc_vt_draft_letter']->reference ),
	$GLOBALS['gwc_vt_draft_letter']->reference
);

gwc_vt_check(
	'while the issued letter still carries one',
	false !== strpos(
		gwc_vt_render_letter( $GLOBALS['gwc_vt_draft_letter'], 'print' ),
		$GLOBALS['gwc_vt_draft_letter']->reference
	)
);

/* The one that matters: looking is not issuing. */
$GLOBALS['gwc_vt_logged_before'] = count(
	get_posts(
		array(
			'post_type'      => GWC_VT_LETTER_TYPE,
			'post_status'    => array_values( get_post_stati() ),
			'numberposts'    => -1,
			'fields'         => 'ids',
		)
	)
);

gwc_vt_render_letter( $GLOBALS['gwc_vt_draft_letter'], 'draft' );

$GLOBALS['gwc_vt_logged_after'] = count(
	get_posts(
		array(
			'post_type'      => GWC_VT_LETTER_TYPE,
			'post_status'    => array_values( get_post_stati() ),
			'numberposts'    => -1,
			'fields'         => 'ids',
		)
	)
);

gwc_vt_check(
	'rendering a draft writes nothing to the issued-letter log',
	$GLOBALS['gwc_vt_logged_before'] === $GLOBALS['gwc_vt_logged_after'],
	$GLOBALS['gwc_vt_logged_before'] . ' → ' . $GLOBALS['gwc_vt_logged_after']
);

/* This section runs after the file's own clean-up loop, so it clears up after
 * itself. Left behind, one extra "Zzytest" volunteer is a fixture in somebody
 * else's database: tests/integration/entries.php searches that prefix and
 * counted three volunteers where it had made two. */
foreach ( gwc_vt_entry_ids_for_volunteer( (int) $GLOBALS['gwc_vt_shown'], array( 'statuses' => array( 'publish', 'draft' ) ) ) as $gwc_vt_left ) {
	wp_delete_post( (int) $gwc_vt_left, true );
}

wp_delete_post( (int) $GLOBALS['gwc_vt_shown'], true );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

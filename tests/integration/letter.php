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
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter.php
 *
 * @package VolunteerTracker
 */

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
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether to attest to it.
 * @return int
 */
function gwcvt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $id, GWCVT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $id, GWCVT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		gwcvt_verify_entry( (int) $id, 1 );
	}

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

wp_set_current_user( 1 );

$gwcvt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => "Zzytest Ada O'Brien",
	)
);
update_post_meta( $gwcvt_volunteer, GWCVT_VOLUNTEER_EMAIL, 'ada@example.test' );
$GLOBALS['gwcvt_made'][] = $gwcvt_volunteer;

gwcvt_make_entry( $gwcvt_volunteer, '2026-03-09', 180, true );
gwcvt_make_entry( $gwcvt_volunteer, '2026-03-02', 210, true );   // out of order on purpose
gwcvt_make_entry( $gwcvt_volunteer, '2026-03-16', 90, false );   // unverified
gwcvt_make_entry( $gwcvt_volunteer, '2026-09-01', 600, true );   // outside the range

/* ── The model ───────────────────────────────────────────────────────────── */

$gwcvt_letter = gwcvt_build_letter( $gwcvt_volunteer, array( 'from' => '2026-03-01', 'to' => '2026-03-31' ) );

gwcvt_check( 'a letter is built', $gwcvt_letter instanceof GWCVT_Letter );
gwcvt_check( 'verified minutes', 390 === $gwcvt_letter->verified_minutes, (string) $gwcvt_letter->verified_minutes );
gwcvt_check( 'unverified rows are excluded by default', 2 === $gwcvt_letter->entry_count(), (string) $gwcvt_letter->entry_count() );
gwcvt_check( 'hours outside the range are excluded', 390 === $gwcvt_letter->verified_minutes );

gwcvt_check(
	'entries are ordered oldest first',
	'2026-03-02' === $gwcvt_letter->entries[0]->date,
	$gwcvt_letter->entries[0]->date
);

gwcvt_check(
	'each row carries its attestation',
	false !== strpos( $gwcvt_letter->entries[0]->attestation, 'Verified' ),
	$gwcvt_letter->entries[0]->attestation
);

/* ── Unverified, when asked for ──────────────────────────────────────────── */

$gwcvt_with_unverified = gwcvt_build_letter(
	$gwcvt_volunteer,
	array( 'from' => '2026-03-01', 'to' => '2026-03-31', 'include_unverified' => true )
);

gwcvt_check( 'unverified rows appear when enabled', 3 === $gwcvt_with_unverified->entry_count(), (string) $gwcvt_with_unverified->entry_count() );
gwcvt_check(
	'and are never added to the verified total',
	390 === $gwcvt_with_unverified->verified_minutes,
	(string) $gwcvt_with_unverified->verified_minutes
);
gwcvt_check( 'but are reported separately', 90 === $gwcvt_with_unverified->unverified_minutes, (string) $gwcvt_with_unverified->unverified_minutes );

/* ── A pending entry never reaches a letter ──────────────────────────────── */

$gwcvt_pending = wp_insert_post(
	array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'pending', 'post_title' => 'tmp' )
);
update_post_meta( $gwcvt_pending, GWCVT_ENTRY_VOLUNTEER, (string) $gwcvt_volunteer );
update_post_meta( $gwcvt_pending, GWCVT_ENTRY_DATE, '2026-03-20' );
update_post_meta( $gwcvt_pending, GWCVT_ENTRY_MINUTES, 999 );
$GLOBALS['gwcvt_made'][] = $gwcvt_pending;

$gwcvt_recheck = gwcvt_build_letter(
	$gwcvt_volunteer,
	array( 'from' => '2026-03-01', 'to' => '2026-03-31', 'include_unverified' => true )
);
gwcvt_check(
	'an untriaged self-logged entry never reaches a letter',
	3 === $gwcvt_recheck->entry_count(),
	(string) $gwcvt_recheck->entry_count()
);

/* ── The printed document ────────────────────────────────────────────────── */

$gwcvt_print = gwcvt_render_letter( $gwcvt_letter, 'print' );

gwcvt_check( 'the print view is a whole document', 0 === strpos( ltrim( $gwcvt_print ), '<!doctype html>' ) );
gwcvt_check( 'it names the organisation', false !== strpos( $gwcvt_print, get_bloginfo( 'name' ) ) );
/* Compared against exactly what the template escapes, rather than against a
 * literal typed here. get_the_title() runs the `the_title` filter, which
 * texturizes — so the apostrophe in O'Brien reaches the letter as a curly one.
 * That is correct and desirable typographically, and a hand-typed expectation
 * just makes the test wrong about its own subject. */
gwcvt_check(
	'it names the volunteer',
	false !== strpos( $gwcvt_print, esc_html( $gwcvt_letter->volunteer_name ) ),
	$gwcvt_letter->volunteer_name
);
gwcvt_check( 'it states the verified total', false !== strpos( $gwcvt_print, '6.5' ), 'looking for 6.5' );
gwcvt_check( 'it carries the reference code', false !== strpos( $gwcvt_print, $gwcvt_letter->reference ) );
gwcvt_check( 'it carries the disclaimer', false !== strpos( $gwcvt_print, 'authoritative record-keeper' ) );
gwcvt_check( 'it links the print stylesheet', false !== strpos( $gwcvt_print, 'assets/css/letter.css' ) );
gwcvt_check( 'it asks robots not to index it', false !== strpos( $gwcvt_print, 'noindex' ) );
gwcvt_check( 'it contains no script tag', false === stripos( $gwcvt_print, '<script' ) );

/* The claims this document must never make about itself. */
foreach ( array( 'certified', 'certifies', 'penalty of perjury', 'sworn', 'notari' ) as $gwcvt_word ) {
	gwcvt_check(
		'it never says "' . $gwcvt_word . '"',
		false === stripos( $gwcvt_print, $gwcvt_word )
	);
}

/* ── The emailed document ────────────────────────────────────────────────── */

$gwcvt_email = gwcvt_render_letter( $gwcvt_letter, 'email' );

gwcvt_check( 'the email carries inline styles', false !== strpos( $gwcvt_email, 'style="font-family:' ) );
gwcvt_check( 'the email links no stylesheet', false === strpos( $gwcvt_email, '<link' ) );
gwcvt_check( 'the email has no print toolbar', false === strpos( $gwcvt_email, 'gwcvt-print-button' ) );
gwcvt_check( 'the email carries the same reference', false !== strpos( $gwcvt_email, $gwcvt_letter->reference ) );
gwcvt_check( 'the email carries the disclaimer', false !== strpos( $gwcvt_email, 'authoritative record-keeper' ) );

/* Both media must state the same total. This is the assertion behind the
 * one-template rule: if they ever diverge, a court has a letter that differs
 * from the organisation's copy. */
gwcvt_check(
	'print and email state the same hours',
	( false !== strpos( $gwcvt_print, '6.5' ) ) === ( false !== strpos( $gwcvt_email, '6.5' ) )
);

/* ── The log and the reference checker ───────────────────────────────────── */

$gwcvt_record_id = gwcvt_log_letter( $gwcvt_letter, 'print' );
$GLOBALS['gwcvt_made'][] = $gwcvt_record_id;

gwcvt_check( 'issuing writes a log record', $gwcvt_record_id > 0 );

$gwcvt_result = gwcvt_verify_reference( $gwcvt_letter->reference );
gwcvt_check( 'a fresh reference matches', 'match' === $gwcvt_result['status'], $gwcvt_result['status'] );

$gwcvt_unknown = gwcvt_verify_reference( 'REF-99-20260101-DEADBEEF' );
gwcvt_check( 'an unknown reference is reported as unknown', 'unknown' === $gwcvt_unknown['status'], $gwcvt_unknown['status'] );

/* Change the records, then re-check the same code. */
gwcvt_make_entry( $gwcvt_volunteer, '2026-03-23', 60, true );

$gwcvt_changed = gwcvt_verify_reference( $gwcvt_letter->reference );
gwcvt_check( 'editing the records makes the code stop matching', 'changed' === $gwcvt_changed['status'], $gwcvt_changed['status'] );
gwcvt_check(
	'and the current figure is reported alongside',
	450 === (int) ( $gwcvt_changed['current']['minutes'] ?? 0 ),
	(string) ( $gwcvt_changed['current']['minutes'] ?? 0 )
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
function gwcvt_tamper( int $volunteer_id, callable $alter ): string {
	$letter = gwcvt_build_letter( $volunteer_id );
	$record = gwcvt_log_letter( $letter, 'print' );
	$GLOBALS['gwcvt_made'][] = $record;

	$ids = gwcvt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish' ) ) );
	$alter( (int) $ids[0] );

	return gwcvt_verify_reference( $letter->reference )['status'];
}

$gwcvt_tamper_volunteer = wp_insert_post(
	array( 'post_type' => GWCVT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzytest Tamper Subject' )
);
$GLOBALS['gwcvt_made'][] = $gwcvt_tamper_volunteer;

gwcvt_make_entry( $gwcvt_tamper_volunteer, '2026-04-06', 210, true );
gwcvt_make_entry( $gwcvt_tamper_volunteer, '2026-04-13', 180, true );

gwcvt_check(
	'rewriting an activity is detected',
	'changed' === gwcvt_tamper(
		$gwcvt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, 'Something entirely different' );
		}
	)
);

gwcvt_check(
	'changing a supervisor is detected',
	'changed' === gwcvt_tamper(
		$gwcvt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWCVT_ENTRY_SUPERVISOR, 'Someone Who Was Not There' );
		}
	)
);

gwcvt_check(
	'moving a date within the range is detected',
	'changed' === gwcvt_tamper(
		$gwcvt_tamper_volunteer,
		static function ( int $id ): void {
			update_post_meta( $id, GWCVT_ENTRY_DATE, '2026-04-07' );
		}
	)
);

/* The one the old digest was most obviously blind to: the total and the count
 * are untouched, only the split between two shifts moves. */
gwcvt_check(
	'swapping hours between two shifts is detected',
	'changed' === gwcvt_tamper(
		$gwcvt_tamper_volunteer,
		static function ( int $id ) use ( $gwcvt_tamper_volunteer ): void {
			$ids = gwcvt_entry_ids_for_volunteer( $gwcvt_tamper_volunteer, array( 'statuses' => array( 'publish' ) ) );
			$a   = (int) get_post_meta( (int) $ids[0], GWCVT_ENTRY_MINUTES, true );
			$b   = (int) get_post_meta( (int) $ids[1], GWCVT_ENTRY_MINUTES, true );
			update_post_meta( (int) $ids[0], GWCVT_ENTRY_MINUTES, $b );
			update_post_meta( (int) $ids[1], GWCVT_ENTRY_MINUTES, $a );
		}
	)
);

/* And an untouched letter still matches, so the above are detections rather
 * than a verifier that has started refusing everything. */
$gwcvt_clean = gwcvt_build_letter( $gwcvt_tamper_volunteer );
$gwcvt_clean_record = gwcvt_log_letter( $gwcvt_clean, 'print' );
$GLOBALS['gwcvt_made'][] = $gwcvt_clean_record;

gwcvt_check(
	'an untouched letter still matches',
	'match' === gwcvt_verify_reference( $gwcvt_clean->reference )['status']
);

/* The checker hands back the rebuilt letter so the screen can show it in full. */
$gwcvt_rebuilt = gwcvt_verify_reference( $gwcvt_clean->reference )['rebuilt'];

gwcvt_check( 'the checker returns a rebuilt letter', $gwcvt_rebuilt instanceof GWCVT_Letter );
gwcvt_check(
	'and it renders as a full document',
	$gwcvt_rebuilt instanceof GWCVT_Letter
		&& false !== strpos( gwcvt_letter_body( $gwcvt_rebuilt, 'print' ), 'authoritative record-keeper' )
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

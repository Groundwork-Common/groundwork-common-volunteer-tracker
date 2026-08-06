<?php
/**
 * What somebody has to complete — against real hours, and a real letter.
 *
 * The unit suite fakes the rollup, because there is no get_posts() there. This
 * measures progress against entries that actually exist, and then asserts the
 * thing the whole feature is arranged around: that none of it reaches the
 * document.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/required.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/entries.php. */
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

$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );

update_option(
	GWCVT_SETTINGS_OPTION,
	array(
		'org_name'         => 'Zzytest Riverbend Food Bank',
		'hour_format'      => 'decimal',
		'hour_increment'   => 15,
		'letter_itemize'   => true,
		'retention_months' => 0,
	)
);
gwcvt_settings_cache( null, true );

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
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether it is attested.
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
	update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWCVT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_BY, 1 );
	}

	gwcvt_refresh_totals( $volunteer_id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

/* ── Progress against hours that actually exist ──────────────────────────── */

$gwcvt_marcus = gwcvt_make_volunteer( 'Zzytest Marcus Delacroix', 'zzytest-marcus-req@example.test' );

update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED, 40 * 60 );
update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() + ( 21 * DAY_IN_SECONDS ) ) );
update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED_FOR, 'Zzytest Franklin County Municipal Court' );

gwcvt_check( 'a requirement is recorded', gwcvt_has_requirement( $gwcvt_marcus ) );
gwcvt_check( 'and reads back in minutes', 2400 === gwcvt_required_minutes( $gwcvt_marcus ), (string) gwcvt_required_minutes( $gwcvt_marcus ) );

gwcvt_make_entry( $gwcvt_marcus, '2026-03-07', 210, true );
gwcvt_make_entry( $gwcvt_marcus, '2026-03-14', 210, true );
gwcvt_make_entry( $gwcvt_marcus, '2026-03-21', 180, false );

$gwcvt_progress = gwcvt_requirement_progress( $gwcvt_marcus );

gwcvt_check( 'verified hours count', 420 === $gwcvt_progress['verified'], (string) $gwcvt_progress['verified'] );
gwcvt_check( 'unverified ones do not', 180 === $gwcvt_progress['pending'], (string) $gwcvt_progress['pending'] );
gwcvt_check( 'so the remainder is measured on verified only', 1980 === $gwcvt_progress['remaining'], (string) $gwcvt_progress['remaining'] );
gwcvt_check( 'and it is not met', ! $gwcvt_progress['met'] );
gwcvt_check( 'the deadline counts down', 21 === $gwcvt_progress['days_left'], (string) $gwcvt_progress['days_left'] );
gwcvt_check( 'and is not overdue', ! $gwcvt_progress['overdue'] );

/* Verifying the outstanding entry moves the figure, through the rollup. */
$gwcvt_last = gwcvt_make_entry( $gwcvt_marcus, '2026-03-28', 1980, true );

gwcvt_check( 'finishing the hours meets it', gwcvt_requirement_progress( $gwcvt_marcus )['met'] );
gwcvt_check( 'and nothing remains', 0 === gwcvt_requirement_progress( $gwcvt_marcus )['remaining'] );
gwcvt_check(
	'and it reads as completed',
	false !== strpos( gwcvt_requirement_label( $gwcvt_marcus ), 'Completed' ),
	gwcvt_requirement_label( $gwcvt_marcus )
);

/* ── The letter is untouched by any of it ────────────────────────────────────
 * The assertion this feature exists to make safe. How many hours a court
 * ordered is a fact about the court's document, and an organisation certifying
 * the terms of an order back to the court that issued it is the seal problem
 * wearing a different hat.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_letter = gwcvt_build_letter( $gwcvt_marcus );

gwcvt_check( 'a letter can be built', $gwcvt_letter instanceof GWCVT_Letter );

if ( $gwcvt_letter instanceof GWCVT_Letter ) {
	$gwcvt_props = array_keys( get_object_vars( $gwcvt_letter ) );
	sort( $gwcvt_props );

	gwcvt_check(
		'the letter model still carries only what it always did',
		array( 'entries', 'from', 'includes_unverified', 'issued_at', 'reference', 'to', 'unverified_minutes', 'verified_minutes', 'volunteer_id', 'volunteer_name' ) === $gwcvt_props,
		implode( ',', $gwcvt_props )
	);

	update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED, 60 * 60 );

	$gwcvt_letter = gwcvt_build_letter( $gwcvt_marcus );
	$gwcvt_html   = gwcvt_render_letter( $gwcvt_letter );

	/* ── Words, never numbers ────────────────────────────────────────────────
	 * The obvious check is "the document must not contain 60". It is worthless:
	 * a rendered letter is full of dates, a timestamp, a reference code and
	 * inline styles, so a bare two-digit string is always in there somewhere.
	 * The first version of this file asserted exactly that and failed on a
	 * reference code that happened to contain the digits.
	 *
	 * What can be asserted textually is the distinctive vocabulary a leak would
	 * bring with it — you cannot state a requirement without naming it, and you
	 * cannot name who set it without printing them. The structural half of this
	 * claim is stronger anyway and lives in RequiredTest: no letter file reads
	 * the meta key, and the letter model has no property for it. */
	$gwcvt_leaks = array(
		'the word itself'          => 'requir',
		'who requires it'          => 'Franklin County',
		'anything about a court'   => 'court',
		'a deadline'               => 'due',
		'any talk of what is left' => 'to go',
		'or of a total to reach'   => 'remaining',
	);

	foreach ( $gwcvt_leaks as $gwcvt_what => $gwcvt_needle ) {
		gwcvt_check(
			'the rendered letter does not carry ' . $gwcvt_what,
			false === stripos( $gwcvt_html, (string) $gwcvt_needle ),
			'found "' . $gwcvt_needle . '"'
		);
	}

	/* And still says the one number it is for. */
	gwcvt_check( 'but it does state the verified total', false !== strpos( $gwcvt_html, gwcvt_format_hours( 2400 ) ), gwcvt_format_hours( 2400 ) );

	gwcvt_check( 'and the reference is unaffected', '' !== $gwcvt_letter->reference, $gwcvt_letter->reference );

	/* The strongest form of the claim: raising the requirement changes nothing
	 * about the document at all. If it ever reached the letter, the two would
	 * differ — and every reference code ever issued would break with it. */
	update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED, 500 * 60 );

	$gwcvt_after = gwcvt_render_letter( gwcvt_build_letter( $gwcvt_marcus ) );

	gwcvt_check( 'and changing the requirement changes nothing in the letter', $gwcvt_html === $gwcvt_after );
	gwcvt_check(
		'nor the reference code it carries',
		gwcvt_build_letter( $gwcvt_marcus )->reference === $gwcvt_letter->reference,
		gwcvt_build_letter( $gwcvt_marcus )->reference
	);

	update_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED, 40 * 60 );
}

/* ── Privacy ─────────────────────────────────────────────────────────────────
 * The most sensitive thing this plugin holds: it says a named person was under
 * a court order, names the court, and dates it.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_export = gwcvt_export_personal_data( 'zzytest-marcus-req@example.test' );
$gwcvt_dump   = wp_json_encode( $gwcvt_export['data'] );

gwcvt_check( 'the export includes what they must complete', false !== strpos( $gwcvt_dump, 'Hours they are required to complete' ) );
gwcvt_check( 'and who requires it', false !== strpos( $gwcvt_dump, 'Zzytest Franklin County Municipal Court' ) );

gwcvt_anonymize_volunteer( $gwcvt_marcus );

gwcvt_check( 'anonymising clears the requirement', '' === (string) get_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED, true ) );
gwcvt_check( 'and the deadline', '' === (string) get_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED_BY, true ) );

/* The court's name is the disclosure that survives an anonymised name: it says
 * a real person was under a real order, and dates it. */
gwcvt_check( 'and who required it', '' === (string) get_post_meta( $gwcvt_marcus, GWCVT_VOLUNTEER_REQUIRED_FOR, true ) );

/* The hours stay. They are the organisation's own service record and identify
 * nobody once the name and the order are gone. */
gwcvt_check( 'but the hours survive', 2400 === gwcvt_compute_totals( $gwcvt_marcus )->verified_minutes, (string) gwcvt_compute_totals( $gwcvt_marcus )->verified_minutes );
gwcvt_check( 'and the record no longer claims a requirement', ! gwcvt_has_requirement( $gwcvt_marcus ) );

/* ── A volunteer with nothing required of them ───────────────────────────── */

$gwcvt_ordinary = gwcvt_make_volunteer( 'Zzytest Priya Nandakumar' );
gwcvt_make_entry( $gwcvt_ordinary, '2026-03-07', 210, true );

gwcvt_check( 'an ordinary volunteer has no requirement', ! gwcvt_has_requirement( $gwcvt_ordinary ) );
gwcvt_check( 'and no label is drawn for them', '' === gwcvt_requirement_label( $gwcvt_ordinary ) );
gwcvt_check( 'and no countdown', '' === gwcvt_requirement_deadline_label( $gwcvt_ordinary ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array_unique( array_filter( $GLOBALS['gwcvt_made'] ) ) as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

unset( $gwcvt_last );

if ( false === $GLOBALS['gwcvt_settings_before'] ) {
	delete_option( GWCVT_SETTINGS_OPTION );
} else {
	update_option( GWCVT_SETTINGS_OPTION, $GLOBALS['gwcvt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

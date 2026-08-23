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

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

update_option(
	GWC_VT_SETTINGS_OPTION,
	array(
		'org_name'         => 'Zzytest Riverbend Food Bank',
		'hour_format'      => 'decimal',
		'hour_increment'   => 15,
		'letter_itemize'   => true,
		'retention_months' => 0,
	)
);
gwc_vt_settings_cache( null, true );

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
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether it is attested.
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
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Zzytest sorting donations' );
	update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_BY, 1 );
	}

	gwc_vt_refresh_totals( $volunteer_id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/* ── Progress against hours that actually exist ──────────────────────────── */

$gwc_vt_marcus = gwc_vt_make_volunteer( 'Zzytest Marcus Delacroix', 'zzytest-marcus-req@example.test' );

update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, 40 * 60 );
update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() + ( 21 * DAY_IN_SECONDS ) ) );
update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_FOR, 'Zzytest Franklin County Municipal Court' );

gwc_vt_check( 'a requirement is recorded', gwc_vt_has_requirement( $gwc_vt_marcus ) );
gwc_vt_check( 'and reads back in minutes', 2400 === gwc_vt_required_minutes( $gwc_vt_marcus ), (string) gwc_vt_required_minutes( $gwc_vt_marcus ) );

gwc_vt_make_entry( $gwc_vt_marcus, '2026-03-07', 210, true );
gwc_vt_make_entry( $gwc_vt_marcus, '2026-03-14', 210, true );
gwc_vt_make_entry( $gwc_vt_marcus, '2026-03-21', 180, false );

$gwc_vt_progress = gwc_vt_requirement_progress( $gwc_vt_marcus );

gwc_vt_check( 'verified hours count', 420 === $gwc_vt_progress['verified'], (string) $gwc_vt_progress['verified'] );
gwc_vt_check( 'unverified ones do not', 180 === $gwc_vt_progress['pending'], (string) $gwc_vt_progress['pending'] );
gwc_vt_check( 'so the remainder is measured on verified only', 1980 === $gwc_vt_progress['remaining'], (string) $gwc_vt_progress['remaining'] );
gwc_vt_check( 'and it is not met', ! $gwc_vt_progress['met'] );
gwc_vt_check( 'the deadline counts down', 21 === $gwc_vt_progress['days_left'], (string) $gwc_vt_progress['days_left'] );
gwc_vt_check( 'and is not overdue', ! $gwc_vt_progress['overdue'] );

/* Verifying the outstanding entry moves the figure, through the rollup. */
$gwc_vt_last = gwc_vt_make_entry( $gwc_vt_marcus, '2026-03-28', 1980, true );

gwc_vt_check( 'finishing the hours meets it', gwc_vt_requirement_progress( $gwc_vt_marcus )['met'] );
gwc_vt_check( 'and nothing remains', 0 === gwc_vt_requirement_progress( $gwc_vt_marcus )['remaining'] );
gwc_vt_check(
	'and it reads as completed',
	false !== strpos( gwc_vt_requirement_label( $gwc_vt_marcus ), 'Completed' ),
	gwc_vt_requirement_label( $gwc_vt_marcus )
);

/* ── The letter is untouched by any of it ────────────────────────────────────
 * The assertion this feature exists to make safe. How many hours a court
 * ordered is a fact about the court's document, and an organization certifying
 * the terms of an order back to the court that issued it is the seal problem
 * wearing a different hat.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_marcus );

gwc_vt_check( 'a letter can be built', $gwc_vt_letter instanceof GWC_VT_Letter );

if ( $gwc_vt_letter instanceof GWC_VT_Letter ) {
	$gwc_vt_props = array_keys( get_object_vars( $gwc_vt_letter ) );
	sort( $gwc_vt_props );

	gwc_vt_check(
		'the letter model still carries only what it always did',
		array( 'entries', 'from', 'includes_unverified', 'issued_at', 'reference', 'to', 'unverified_minutes', 'verified_minutes', 'volunteer_id', 'volunteer_name' ) === $gwc_vt_props,
		implode( ',', $gwc_vt_props )
	);

	update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, 60 * 60 );

	$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_marcus );
	$gwc_vt_html   = gwc_vt_render_letter( $gwc_vt_letter );

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
	$gwc_vt_leaks = array(
		'the word itself'          => 'requir',
		'who requires it'          => 'Franklin County',
		'anything about a court'   => 'court',
		'a deadline'               => 'due',
		'any talk of what is left' => 'to go',
		'or of a total to reach'   => 'remaining',
	);

	foreach ( $gwc_vt_leaks as $gwc_vt_what => $gwc_vt_needle ) {
		gwc_vt_check(
			'the rendered letter does not carry ' . $gwc_vt_what,
			false === stripos( $gwc_vt_html, (string) $gwc_vt_needle ),
			'found "' . $gwc_vt_needle . '"'
		);
	}

	/* And still says the one number it is for. */
	gwc_vt_check( 'but it does state the verified total', false !== strpos( $gwc_vt_html, gwc_vt_format_hours( 2400 ) ), gwc_vt_format_hours( 2400 ) );

	gwc_vt_check( 'and the reference is unaffected', '' !== $gwc_vt_letter->reference, $gwc_vt_letter->reference );

	/* The strongest form of the claim: raising the requirement changes nothing
	 * about the document at all. If it ever reached the letter, the two would
	 * differ — and every reference code ever issued would break with it. */
	update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, 500 * 60 );

	$gwc_vt_after = gwc_vt_render_letter( gwc_vt_build_letter( $gwc_vt_marcus ) );

	gwc_vt_check( 'and changing the requirement changes nothing in the letter', $gwc_vt_html === $gwc_vt_after );
	gwc_vt_check(
		'nor the reference code it carries',
		gwc_vt_build_letter( $gwc_vt_marcus )->reference === $gwc_vt_letter->reference,
		gwc_vt_build_letter( $gwc_vt_marcus )->reference
	);

	update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, 40 * 60 );
}

/* ── Privacy ─────────────────────────────────────────────────────────────────
 * The most sensitive thing this plugin holds: it says a named person was under
 * a court order, names the court, and dates it.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_export = gwc_vt_export_personal_data( 'zzytest-marcus-req@example.test' );
$gwc_vt_dump   = wp_json_encode( $gwc_vt_export['data'] );

gwc_vt_check( 'the export includes what they must complete', false !== strpos( $gwc_vt_dump, 'Hours they are required to complete' ) );
gwc_vt_check( 'and who requires it', false !== strpos( $gwc_vt_dump, 'Zzytest Franklin County Municipal Court' ) );

gwc_vt_anonymize_volunteer( $gwc_vt_marcus );

gwc_vt_check( 'anonymizing clears the requirement', '' === (string) get_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, true ) );
gwc_vt_check( 'and the deadline', '' === (string) get_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_BY, true ) );

/* The court's name is the disclosure that survives an anonymized name: it says
 * a real person was under a real order, and dates it. */
gwc_vt_check( 'and who required it', '' === (string) get_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_FOR, true ) );

/* The hours stay. They are the organization's own service record and identify
 * nobody once the name and the order are gone. */
gwc_vt_check( 'but the hours survive', 2400 === gwc_vt_compute_totals( $gwc_vt_marcus )->verified_minutes, (string) gwc_vt_compute_totals( $gwc_vt_marcus )->verified_minutes );
gwc_vt_check( 'and the record no longer claims a requirement', ! gwc_vt_has_requirement( $gwc_vt_marcus ) );

/* ── A volunteer with nothing required of them ───────────────────────────── */

$gwc_vt_ordinary = gwc_vt_make_volunteer( 'Zzytest Priya Nandakumar' );
gwc_vt_make_entry( $gwc_vt_ordinary, '2026-03-07', 210, true );

gwc_vt_check( 'an ordinary volunteer has no requirement', ! gwc_vt_has_requirement( $gwc_vt_ordinary ) );
gwc_vt_check( 'and no label is drawn for them', '' === gwc_vt_requirement_label( $gwc_vt_ordinary ) );
gwc_vt_check( 'and no countdown', '' === gwc_vt_requirement_deadline_label( $gwc_vt_ordinary ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array_unique( array_filter( $GLOBALS['gwc_vt_made'] ) ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

unset( $gwc_vt_last );

if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
	delete_option( GWC_VT_SETTINGS_OPTION );
} else {
	update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

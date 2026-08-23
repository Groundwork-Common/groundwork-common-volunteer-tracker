<?php
/**
 * Retention and the privacy tools, against real WordPress.
 *
 * RetentionTest covers the arithmetic. This covers what it cannot: the purge
 * actually removing things, and the exporter and eraser running through
 * WordPress's own filters rather than being called directly.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/privacy.php
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
 * A volunteer with one shift on a given date.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @param string $date  Y-m-d of their only shift.
 * @return array{volunteer:int, entry:int}
 */
function gwc_vt_make_person( string $name, string $email, string $date ): array {
	$volunteer = wp_insert_post(
		array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => $name )
	);
	update_post_meta( $volunteer, GWC_VT_VOLUNTEER_EMAIL, $email );
	update_post_meta( $volunteer, GWC_VT_VOLUNTEER_PHONE, '555-0100' );

	$entry = wp_insert_post(
		array( 'post_type' => GWC_VT_ENTRY_TYPE, 'post_status' => 'publish', 'post_title' => 'tmp' )
	);
	update_post_meta( $entry, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer );
	update_post_meta( $entry, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $entry, GWC_VT_ENTRY_MINUTES, 210 );
	update_post_meta( $entry, GWC_VT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $entry, '_gwc_vt_claim_name', $name );
	update_post_meta( $entry, '_gwc_vt_claim_email', $email );
	gwc_vt_verify_entry( (int) $entry, 1 );

	$GLOBALS['gwc_vt_made'][] = $volunteer;
	$GLOBALS['gwc_vt_made'][] = $entry;

	return array( 'volunteer' => (int) $volunteer, 'entry' => (int) $entry );
}


/* ── Put the site's own settings back afterwards ─────────────────────────────
 * This script rewrites gwc_vt_settings to exercise the plugin under particular
 * configurations. The first version then DELETED the option at cleanup, which
 * does not restore anything — it wipes the site's entire plugin configuration:
 * letterhead, signatory, disclaimer wording, retention policy, and whether the
 * public form is switched on.
 *
 * On this machine that silently turned the demo organization's form off. On a
 * real site it would erase a retention policy somebody had deliberately chosen
 * for records about court-ordered service, with nothing to say it had happened.
 *
 * So: snapshot first, restore last, including the "there was nothing here"
 * case. A test that changes global configuration has to put it back.
 * ─────────────────────────────────────────────────────────────────────────── */
$GLOBALS['gwc_vt_saved_options'] = array();

/**
 * Remember an option's current value so it can be restored.
 *
 * @param string $name Option name.
 */
function gwc_vt_borrow_option( string $name ): void {
	$GLOBALS['gwc_vt_saved_options'][ $name ] = get_option( $name );
}

/**
 * Put every borrowed option back exactly as it was.
 */
function gwc_vt_return_options(): void {
	foreach ( $GLOBALS['gwc_vt_saved_options'] as $name => $value ) {
		if ( false === $value ) {
			delete_option( $name );
			continue;
		}

		update_option( $name, $value );
	}

	gwc_vt_settings_cache( null, true );
}

wp_set_current_user( 1 );

gwc_vt_borrow_option( 'gwc_vt_settings' );
gwc_vt_borrow_option( GWC_VT_RETENTION_LOG );

update_option(
	'gwc_vt_settings',
	array( 'retention_months' => 24, 'retention_action' => 'anonymize', 'retention_anchor' => 'last_entry', 'retention_decided' => true )
);
gwc_vt_settings_cache( null, true );

$gwc_vt_old    = gwc_vt_make_person( 'Zzytest Old Record', 'old@example.test', '2019-05-01' );
$gwc_vt_recent = gwc_vt_make_person( 'Zzytest Recent Record', 'recent@example.test', gwc_vt_today() );
$gwc_vt_held   = gwc_vt_make_person( 'Zzytest Held Record', 'held@example.test', '2019-05-01' );

update_post_meta( $gwc_vt_held['volunteer'], GWC_VT_VOLUNTEER_HOLD, 1 );
update_post_meta( $gwc_vt_held['volunteer'], GWC_VT_VOLUNTEER_HOLD_REASON, 'Open probation case' );

/* ── Who is due ──────────────────────────────────────────────────────────── */

gwc_vt_check( 'an old record is due', gwc_vt_retention_due( $gwc_vt_old['volunteer'] ) );
gwc_vt_check( 'a recent record is not', ! gwc_vt_retention_due( $gwc_vt_recent['volunteer'] ) );
gwc_vt_check( 'a held record is not, however old', ! gwc_vt_retention_due( $gwc_vt_held['volunteer'] ) );

/* ── The sweep ───────────────────────────────────────────────────────────── */

/* Counted before the sweep, because gwc_vt_run_retention() walks every volunteer
 * on the site and this script does not own the site. Asserting "1 purged, 1
 * held" only holds on a pristine install — it broke the moment the seeded demo
 * organization existed, while the sweep was behaving exactly right. */
$gwc_vt_expect_purged = 0;
$gwc_vt_expect_held   = 0;

foreach (
	get_posts(
		array(
			'post_type'      => GWC_VT_VOLUNTEER_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => GWC_VT_RETENTION_BATCH,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	) as $gwc_vt_candidate
) {
	if ( gwc_vt_retention_held( (int) $gwc_vt_candidate ) ) {
		++$gwc_vt_expect_held;
	} elseif ( gwc_vt_retention_due( (int) $gwc_vt_candidate ) ) {
		++$gwc_vt_expect_purged;
	}
}

gwc_vt_run_retention();

$gwc_vt_title = get_the_title( $gwc_vt_old['volunteer'] );

gwc_vt_check(
	'the old record was anonymized',
	false !== strpos( $gwc_vt_title, 'Former volunteer' ),
	$gwc_vt_title
);
gwc_vt_check( 'its email is gone', '' === get_post_meta( $gwc_vt_old['volunteer'], GWC_VT_VOLUNTEER_EMAIL, true ) );
gwc_vt_check( 'its phone is gone', '' === get_post_meta( $gwc_vt_old['volunteer'], GWC_VT_VOLUNTEER_PHONE, true ) );

/* The claimed name and email live on the ENTRY, not the volunteer. Anonymizing
 * only the volunteer record would leave the name behind on every shift somebody
 * submitted through the public form. */
/* ── Written out, on purpose ─────────────────────────────────────────────────
 * These two keys are constants now — GWC_VT_ENTRY_CLAIM_NAME and
 * GWC_VT_ENTRY_CLAIM_EMAIL — and these assertions deliberately do not use them.
 * A test that reads the same constant the code writes passes whatever the
 * constant says, including after somebody renames its value; the literal is
 * what pins it to the string that is already in every installed database.
 *
 * Which matters here more than anywhere: a delete_post_meta() against a key
 * nothing writes is a silent no-op that returns false and is checked by nobody,
 * and this is the path whose entire job is that the name is gone.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_check( 'the claimed name on its shift is gone', '' === get_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_name', true ) );
gwc_vt_check( 'the claimed email on its shift is gone', '' === get_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_email', true ) );

gwc_vt_check( 'and the constants still name those exact keys', '_gwc_vt_claim_name' === GWC_VT_ENTRY_CLAIM_NAME && '_gwc_vt_claim_email' === GWC_VT_ENTRY_CLAIM_EMAIL, GWC_VT_ENTRY_CLAIM_NAME . ' / ' . GWC_VT_ENTRY_CLAIM_EMAIL );

/* The helper the three delete sites now share, exercised on its own so a fourth
 * caller inherits something that is known to clear both keys rather than one. */
update_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_name', 'Zzytest Left Behind' );
update_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_email', 'zzytest-left-behind@example.test' );

gwc_vt_check( 'a claim can be put back for the next check', 'Zzytest Left Behind' === get_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_name', true ) );

gwc_vt_clear_entry_claims( (int) $gwc_vt_old['entry'] );

gwc_vt_check( 'gwc_vt_clear_entry_claims() clears the name', '' === get_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_name', true ) );
gwc_vt_check( 'and the address beside it', '' === get_post_meta( $gwc_vt_old['entry'], '_gwc_vt_claim_email', true ) );

/* ── Who counts as being on file ─────────────────────────────────────────────
 * The exporter and the eraser find a person's signups by claim email, over a
 * status list that was written out verbatim in four places. Register a fifth
 * status and add it to the roster queries but not to that list, and somebody
 * asking to be forgotten is told nothing is on file while their name sits on a
 * roster. One function now, asserted here so the set is visible rather than
 * scattered.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_check(
	'a signup on file means published, waitlisted or withdrawn',
	array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) === gwc_vt_signup_statuses(),
	implode( ',', gwc_vt_signup_statuses() )
);

gwc_vt_check(
	'and a withdrawn signup is still somebody the eraser must find',
	in_array( GWC_VT_SIGNUP_WITHDRAWN, gwc_vt_signup_statuses(), true )
);

/* The hours survive, which is the whole point of anonymizing rather than
 * deleting: the organization's grant reporting needs them and they identify
 * nobody. */
gwc_vt_check( 'the hours survive', 210 === (int) get_post_meta( $gwc_vt_old['entry'], GWC_VT_ENTRY_MINUTES, true ) );
gwc_vt_check( 'the verification survives', gwc_vt_entry_is_verified( $gwc_vt_old['entry'] ) );

gwc_vt_check( 'the recent record was left alone', 'Zzytest Recent Record' === get_the_title( $gwc_vt_recent['volunteer'] ) );
gwc_vt_check( 'the held record was left alone', 'Zzytest Held Record' === get_the_title( $gwc_vt_held['volunteer'] ) );

$gwc_vt_log = gwc_vt_retention_log();
gwc_vt_check(
	'the sweep logged what it purged',
	! empty( $gwc_vt_log ) && $gwc_vt_expect_purged === (int) $gwc_vt_log[0]['purged'],
	( $gwc_vt_log[0]['purged'] ?? -1 ) . ' of an expected ' . $gwc_vt_expect_purged
);
gwc_vt_check(
	'and counted what it held back',
	$gwc_vt_expect_held === (int) ( $gwc_vt_log[0]['held'] ?? -1 ),
	( $gwc_vt_log[0]['held'] ?? -1 ) . ' of an expected ' . $gwc_vt_expect_held
);
gwc_vt_check( 'this script’s own old record was among them', $gwc_vt_expect_purged >= 1 );
gwc_vt_check( 'and its own held record among those', $gwc_vt_expect_held >= 1 );

/* ── Retention off purges nothing ────────────────────────────────────────── */

$gwc_vt_ancient = gwc_vt_make_person( 'Zzytest Ancient', 'ancient@example.test', '2001-01-01' );

update_option( 'gwc_vt_settings', array( 'retention_months' => 0, 'retention_decided' => true ) );
gwc_vt_settings_cache( null, true );

gwc_vt_run_retention();

gwc_vt_check(
	'retention off purges nothing, however old',
	'Zzytest Ancient' === get_the_title( $gwc_vt_ancient['volunteer'] ),
	get_the_title( $gwc_vt_ancient['volunteer'] )
);

/* ── The exporter, through WordPress's own filter ────────────────────────── */

$gwc_vt_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
gwc_vt_check( 'the exporter is registered', isset( $gwc_vt_exporters['groundwork-common-volunteer-tracker'] ) );

$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_recent['volunteer'] );
$gwc_vt_record = gwc_vt_log_letter( $gwc_vt_letter, 'print' );
$GLOBALS['gwc_vt_made'][] = $gwc_vt_record;

$gwc_vt_export = call_user_func( $gwc_vt_exporters['groundwork-common-volunteer-tracker']['callback'], 'recent@example.test', 1 );
$gwc_vt_groups = array_unique( wp_list_pluck( $gwc_vt_export['data'], 'group_id' ) );
sort( $gwc_vt_groups );

gwc_vt_check( 'the export is done in one page', true === $gwc_vt_export['done'] );
gwc_vt_check(
	'it covers the record, the shifts and the letters',
	array( 'gwc_vt_entry', 'gwc_vt_letter', 'gwc_vt_volunteer' ) === $gwc_vt_groups,
	implode( ',', $gwc_vt_groups )
);

$gwc_vt_empty = call_user_func( $gwc_vt_exporters['groundwork-common-volunteer-tracker']['callback'], 'nobody@example.test', 1 );
gwc_vt_check( 'an unknown address exports nothing', array() === $gwc_vt_empty['data'] );

/* ── The eraser ──────────────────────────────────────────────────────────── */

$gwc_vt_erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
gwc_vt_check( 'the eraser is registered', isset( $gwc_vt_erasers['groundwork-common-volunteer-tracker'] ) );

$gwc_vt_result = call_user_func( $gwc_vt_erasers['groundwork-common-volunteer-tracker']['callback'], 'held@example.test', 1 );

gwc_vt_check( 'a held record is not erased', false === $gwc_vt_result['items_removed'] );
gwc_vt_check( 'and it is reported as retained', true === $gwc_vt_result['items_retained'] );
gwc_vt_check(
	'and the reason for the hold is given',
	false !== strpos( implode( ' ', $gwc_vt_result['messages'] ), 'Open probation case' ),
	implode( ' | ', $gwc_vt_result['messages'] )
);
gwc_vt_check( 'the held record still has its name', 'Zzytest Held Record' === get_the_title( $gwc_vt_held['volunteer'] ) );

$gwc_vt_result = call_user_func( $gwc_vt_erasers['groundwork-common-volunteer-tracker']['callback'], 'recent@example.test', 1 );

gwc_vt_check( 'an ordinary record is erased', true === $gwc_vt_result['items_removed'] );
gwc_vt_check( 'the identity is gone', false !== strpos( get_the_title( $gwc_vt_recent['volunteer'] ), 'Former volunteer' ) );
gwc_vt_check( 'the hours are reported as retained', true === $gwc_vt_result['items_retained'] );

$gwc_vt_messages = implode( ' ', $gwc_vt_result['messages'] );

gwc_vt_check(
	'it explains what was kept and why',
	false !== strpos( $gwc_vt_messages, 'service reporting' ),
	$gwc_vt_messages
);

/* The assertion that matters most in this file. Silently destroying the record
 * behind a document a court is holding is precisely the failure this plugin
 * cannot have — the administrator handling the request has to be told a
 * reference may now be un-checkable. */
gwc_vt_check(
	'it names the letter references affected',
	false !== strpos( $gwc_vt_messages, $gwc_vt_letter->reference ),
	$gwc_vt_messages
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

gwc_vt_return_options();

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

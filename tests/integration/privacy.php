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
 * A volunteer with one shift on a given date.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @param string $date  Y-m-d of their only shift.
 * @return array{volunteer:int, entry:int}
 */
function gwcvt_make_person( string $name, string $email, string $date ): array {
	$volunteer = wp_insert_post(
		array( 'post_type' => GWCVT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => $name )
	);
	update_post_meta( $volunteer, GWCVT_VOLUNTEER_EMAIL, $email );
	update_post_meta( $volunteer, GWCVT_VOLUNTEER_PHONE, '555-0100' );

	$entry = wp_insert_post(
		array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'publish', 'post_title' => 'tmp' )
	);
	update_post_meta( $entry, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer );
	update_post_meta( $entry, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $entry, GWCVT_ENTRY_MINUTES, 210 );
	update_post_meta( $entry, GWCVT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $entry, '_gwcvt_claim_name', $name );
	update_post_meta( $entry, '_gwcvt_claim_email', $email );
	gwcvt_verify_entry( (int) $entry, 1 );

	$GLOBALS['gwcvt_made'][] = $volunteer;
	$GLOBALS['gwcvt_made'][] = $entry;

	return array( 'volunteer' => (int) $volunteer, 'entry' => (int) $entry );
}

wp_set_current_user( 1 );

update_option(
	'gwcvt_settings',
	array( 'retention_months' => 24, 'retention_action' => 'anonymize', 'retention_anchor' => 'last_entry', 'retention_decided' => true )
);
gwcvt_settings_cache( null, true );

$gwcvt_old    = gwcvt_make_person( 'Zzytest Old Record', 'old@example.test', '2019-05-01' );
$gwcvt_recent = gwcvt_make_person( 'Zzytest Recent Record', 'recent@example.test', gwcvt_today() );
$gwcvt_held   = gwcvt_make_person( 'Zzytest Held Record', 'held@example.test', '2019-05-01' );

update_post_meta( $gwcvt_held['volunteer'], GWCVT_VOLUNTEER_HOLD, 1 );
update_post_meta( $gwcvt_held['volunteer'], GWCVT_VOLUNTEER_HOLD_REASON, 'Open probation case' );

/* ── Who is due ──────────────────────────────────────────────────────────── */

gwcvt_check( 'an old record is due', gwcvt_retention_due( $gwcvt_old['volunteer'] ) );
gwcvt_check( 'a recent record is not', ! gwcvt_retention_due( $gwcvt_recent['volunteer'] ) );
gwcvt_check( 'a held record is not, however old', ! gwcvt_retention_due( $gwcvt_held['volunteer'] ) );

/* ── The sweep ───────────────────────────────────────────────────────────── */

/* Counted before the sweep, because gwcvt_run_retention() walks every volunteer
 * on the site and this script does not own the site. Asserting "1 purged, 1
 * held" only holds on a pristine install — it broke the moment the seeded demo
 * organisation existed, while the sweep was behaving exactly right. */
$gwcvt_expect_purged = 0;
$gwcvt_expect_held   = 0;

foreach (
	get_posts(
		array(
			'post_type'      => GWCVT_VOLUNTEER_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => GWCVT_RETENTION_BATCH,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	) as $gwcvt_candidate
) {
	if ( gwcvt_retention_held( (int) $gwcvt_candidate ) ) {
		++$gwcvt_expect_held;
	} elseif ( gwcvt_retention_due( (int) $gwcvt_candidate ) ) {
		++$gwcvt_expect_purged;
	}
}

gwcvt_run_retention();

$gwcvt_title = get_the_title( $gwcvt_old['volunteer'] );

gwcvt_check(
	'the old record was anonymised',
	false !== strpos( $gwcvt_title, 'Former volunteer' ),
	$gwcvt_title
);
gwcvt_check( 'its email is gone', '' === get_post_meta( $gwcvt_old['volunteer'], GWCVT_VOLUNTEER_EMAIL, true ) );
gwcvt_check( 'its phone is gone', '' === get_post_meta( $gwcvt_old['volunteer'], GWCVT_VOLUNTEER_PHONE, true ) );

/* The claimed name and email live on the ENTRY, not the volunteer. Anonymising
 * only the volunteer record would leave the name behind on every shift somebody
 * submitted through the public form. */
gwcvt_check( 'the claimed name on its shift is gone', '' === get_post_meta( $gwcvt_old['entry'], '_gwcvt_claim_name', true ) );
gwcvt_check( 'the claimed email on its shift is gone', '' === get_post_meta( $gwcvt_old['entry'], '_gwcvt_claim_email', true ) );

/* The hours survive, which is the whole point of anonymising rather than
 * deleting: the organisation's grant reporting needs them and they identify
 * nobody. */
gwcvt_check( 'the hours survive', 210 === (int) get_post_meta( $gwcvt_old['entry'], GWCVT_ENTRY_MINUTES, true ) );
gwcvt_check( 'the verification survives', gwcvt_entry_is_verified( $gwcvt_old['entry'] ) );

gwcvt_check( 'the recent record was left alone', 'Zzytest Recent Record' === get_the_title( $gwcvt_recent['volunteer'] ) );
gwcvt_check( 'the held record was left alone', 'Zzytest Held Record' === get_the_title( $gwcvt_held['volunteer'] ) );

$gwcvt_log = gwcvt_retention_log();
gwcvt_check(
	'the sweep logged what it purged',
	! empty( $gwcvt_log ) && $gwcvt_expect_purged === (int) $gwcvt_log[0]['purged'],
	( $gwcvt_log[0]['purged'] ?? -1 ) . ' of an expected ' . $gwcvt_expect_purged
);
gwcvt_check(
	'and counted what it held back',
	$gwcvt_expect_held === (int) ( $gwcvt_log[0]['held'] ?? -1 ),
	( $gwcvt_log[0]['held'] ?? -1 ) . ' of an expected ' . $gwcvt_expect_held
);
gwcvt_check( 'this script’s own old record was among them', $gwcvt_expect_purged >= 1 );
gwcvt_check( 'and its own held record among those', $gwcvt_expect_held >= 1 );

/* ── Retention off purges nothing ────────────────────────────────────────── */

$gwcvt_ancient = gwcvt_make_person( 'Zzytest Ancient', 'ancient@example.test', '2001-01-01' );

update_option( 'gwcvt_settings', array( 'retention_months' => 0, 'retention_decided' => true ) );
gwcvt_settings_cache( null, true );

gwcvt_run_retention();

gwcvt_check(
	'retention off purges nothing, however old',
	'Zzytest Ancient' === get_the_title( $gwcvt_ancient['volunteer'] ),
	get_the_title( $gwcvt_ancient['volunteer'] )
);

/* ── The exporter, through WordPress's own filter ────────────────────────── */

$gwcvt_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
gwcvt_check( 'the exporter is registered', isset( $gwcvt_exporters['groundwork-common-volunteer-tracker'] ) );

$gwcvt_letter = gwcvt_build_letter( $gwcvt_recent['volunteer'] );
$gwcvt_record = gwcvt_log_letter( $gwcvt_letter, 'print' );
$GLOBALS['gwcvt_made'][] = $gwcvt_record;

$gwcvt_export = call_user_func( $gwcvt_exporters['groundwork-common-volunteer-tracker']['callback'], 'recent@example.test', 1 );
$gwcvt_groups = array_unique( wp_list_pluck( $gwcvt_export['data'], 'group_id' ) );
sort( $gwcvt_groups );

gwcvt_check( 'the export is done in one page', true === $gwcvt_export['done'] );
gwcvt_check(
	'it covers the record, the shifts and the letters',
	array( 'gwcvt_entry', 'gwcvt_letter', 'gwcvt_volunteer' ) === $gwcvt_groups,
	implode( ',', $gwcvt_groups )
);

$gwcvt_empty = call_user_func( $gwcvt_exporters['groundwork-common-volunteer-tracker']['callback'], 'nobody@example.test', 1 );
gwcvt_check( 'an unknown address exports nothing', array() === $gwcvt_empty['data'] );

/* ── The eraser ──────────────────────────────────────────────────────────── */

$gwcvt_erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
gwcvt_check( 'the eraser is registered', isset( $gwcvt_erasers['groundwork-common-volunteer-tracker'] ) );

$gwcvt_result = call_user_func( $gwcvt_erasers['groundwork-common-volunteer-tracker']['callback'], 'held@example.test', 1 );

gwcvt_check( 'a held record is not erased', false === $gwcvt_result['items_removed'] );
gwcvt_check( 'and it is reported as retained', true === $gwcvt_result['items_retained'] );
gwcvt_check(
	'and the reason for the hold is given',
	false !== strpos( implode( ' ', $gwcvt_result['messages'] ), 'Open probation case' ),
	implode( ' | ', $gwcvt_result['messages'] )
);
gwcvt_check( 'the held record still has its name', 'Zzytest Held Record' === get_the_title( $gwcvt_held['volunteer'] ) );

$gwcvt_result = call_user_func( $gwcvt_erasers['groundwork-common-volunteer-tracker']['callback'], 'recent@example.test', 1 );

gwcvt_check( 'an ordinary record is erased', true === $gwcvt_result['items_removed'] );
gwcvt_check( 'the identity is gone', false !== strpos( get_the_title( $gwcvt_recent['volunteer'] ), 'Former volunteer' ) );
gwcvt_check( 'the hours are reported as retained', true === $gwcvt_result['items_retained'] );

$gwcvt_messages = implode( ' ', $gwcvt_result['messages'] );

gwcvt_check(
	'it explains what was kept and why',
	false !== strpos( $gwcvt_messages, 'service reporting' ),
	$gwcvt_messages
);

/* The assertion that matters most in this file. Silently destroying the record
 * behind a document a court is holding is precisely the failure this plugin
 * cannot have — the administrator handling the request has to be told a
 * reference may now be un-checkable. */
gwcvt_check(
	'it names the letter references affected',
	false !== strpos( $gwcvt_messages, $gwcvt_letter->reference ),
	$gwcvt_messages
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

delete_option( 'gwcvt_settings' );
delete_option( GWCVT_RETENTION_LOG );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

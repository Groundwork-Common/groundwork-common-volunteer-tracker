<?php
/**
 * The public form, against real WordPress.
 *
 * SelfLogTest covers the pure checks. This drives the handler itself with a
 * populated $_POST, which is the only way to prove the gate actually gates and
 * that a submission lands as an unverified, unattached, pending record.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/self-log.php
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
 * Post a submission through the real handler.
 *
 * @param array $overrides Fields to change or remove.
 * @return string The result key the handler recorded.
 */
function gwcvt_submit( array $overrides = array() ): string {
	$stamp = time() - 30;

	$_POST = array_merge(
		array(
			'gwcvt_log_hours'      => '1',
			'gwcvt_self_log_nonce' => wp_create_nonce( 'gwcvt_self_log' ),
			'gwcvt_t'              => $stamp . '.' . hash_hmac( 'sha256', (string) $stamp, wp_salt( 'gwcvt_form' ) ),
			'gwcvt_website'        => '',
			'gwcvt_name'           => 'Zzytest Rosa Iqbal',
			'gwcvt_email'          => 'rosa@example.test',
			'gwcvt_date'           => '2026-07-11',
			'gwcvt_hours'          => '3:30',
			'gwcvt_activity'       => 'Sorting donations',
			'gwcvt_supervisor'     => 'Dana Reyes',
		),
		$overrides
	);

	foreach ( $overrides as $key => $value ) {
		if ( null === $value ) {
			unset( $_POST[ $key ] );
		}
	}

	unset( $GLOBALS['gwcvt_self_log_result'] );

	gwcvt_handle_self_log();

	return (string) ( $GLOBALS['gwcvt_self_log_result'] ?? '' );
}

/** How many pending self-logged entries exist. */
function gwcvt_pending_count(): int {
	return count(
		get_posts(
			array(
				'post_type'      => GWCVT_ENTRY_TYPE,
				'post_status'    => 'pending',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		)
	);
}

wp_set_current_user( 0 );

$gwcvt_page = wp_insert_post(
	array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zzytest Log your hours' )
);
$GLOBALS['gwcvt_made'][] = $gwcvt_page;

/* ── Off by default ──────────────────────────────────────────────────────── */

update_option( 'gwcvt_settings', array( 'self_log_enabled' => false, 'self_log_page' => $gwcvt_page ) );
gwcvt_settings_cache( null, true );
delete_option( GWCVT_RATE_LIMIT_OPTION );

gwcvt_check( 'the form is off', ! gwcvt_self_log_enabled() );
gwcvt_check( 'and renders nothing', '' === gwcvt_render_self_log_form() );

$gwcvt_before = gwcvt_pending_count();
gwcvt_dispatch();
gwcvt_check( 'dispatch while off stores nothing', $gwcvt_before === gwcvt_pending_count() );

/* ── Switched on ─────────────────────────────────────────────────────────── */

update_option( 'gwcvt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwcvt_page ) );
gwcvt_settings_cache( null, true );

gwcvt_check( 'the form is on', gwcvt_self_log_enabled() );

$gwcvt_markup = gwcvt_render_self_log_form();
gwcvt_check( 'it renders a form', false !== strpos( $gwcvt_markup, 'gwcvt_log_hours' ) );
gwcvt_check( 'it carries a honeypot', false !== strpos( $gwcvt_markup, 'gwcvt_website' ) );
gwcvt_check( 'the honeypot is not type=hidden', false === strpos( $gwcvt_markup, 'type="hidden" id="gwcvt-website"' ) );
gwcvt_check( 'it carries a timing stamp', false !== strpos( $gwcvt_markup, 'gwcvt_t' ) );
gwcvt_check( 'it carries a nonce', false !== strpos( $gwcvt_markup, 'gwcvt_self_log_nonce' ) );

/* ── A real submission ───────────────────────────────────────────────────── */

$gwcvt_before = gwcvt_pending_count();
$gwcvt_result = gwcvt_submit();

gwcvt_check( 'a good submission is accepted', 'accepted' === $gwcvt_result, $gwcvt_result );
gwcvt_check( 'and stores one entry', gwcvt_pending_count() === $gwcvt_before + 1 );

$gwcvt_ids = get_posts(
	array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true )
);
$gwcvt_entry = (int) $gwcvt_ids[0];
$GLOBALS['gwcvt_made'][] = $gwcvt_entry;

gwcvt_check( 'it is pending', 'pending' === get_post_status( $gwcvt_entry ), (string) get_post_status( $gwcvt_entry ) );
gwcvt_check( 'it is marked as self-logged', 'self' === get_post_meta( $gwcvt_entry, GWCVT_ENTRY_SOURCE, true ) );
gwcvt_check( 'it is attached to nobody', 0 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_VOLUNTEER, true ) );
gwcvt_check( 'it is not verified', ! gwcvt_entry_is_verified( $gwcvt_entry ) );
gwcvt_check( 'the name is stored as a claim', 'Zzytest Rosa Iqbal' === get_post_meta( $gwcvt_entry, '_gwcvt_claim_name', true ) );
gwcvt_check( 'the email is stored as a claim', 'rosa@example.test' === get_post_meta( $gwcvt_entry, '_gwcvt_claim_email', true ) );
gwcvt_check( 'the duration was parsed', 210 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true ), (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true ) );
gwcvt_check( 'the title says it is unmatched', false !== strpos( get_the_title( $gwcvt_entry ), 'unmatched' ), get_the_title( $gwcvt_entry ) );

/* A submission must never reach a letter, whatever else happens to it. */
$gwcvt_volunteer = wp_insert_post(
	array( 'post_type' => GWCVT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzytest Rosa Iqbal' )
);
$GLOBALS['gwcvt_made'][] = $gwcvt_volunteer;

gwcvt_check(
	'an untriaged submission is not counted for anybody',
	0 === gwcvt_compute_totals( (int) $gwcvt_volunteer )->entries
);

/* ── The checks ──────────────────────────────────────────────────────────── */

delete_option( GWCVT_RATE_LIMIT_OPTION );
$gwcvt_before = gwcvt_pending_count();

gwcvt_check( 'a filled honeypot is silently dropped', 'accepted' === gwcvt_submit( array( 'gwcvt_website' => 'http://spam.test' ) ) );
gwcvt_check( 'and stores nothing', $gwcvt_before === gwcvt_pending_count() );

$gwcvt_instant = time();
gwcvt_check(
	'a submission three seconds after render is dropped',
	'accepted' === gwcvt_submit( array( 'gwcvt_t' => $gwcvt_instant . '.' . hash_hmac( 'sha256', (string) $gwcvt_instant, wp_salt( 'gwcvt_form' ) ) ) )
);
gwcvt_check( 'and stores nothing', $gwcvt_before === gwcvt_pending_count() );

gwcvt_check( 'a forged timing stamp is refused', 'expired' === gwcvt_submit( array( 'gwcvt_t' => time() . '.deadbeef' ) ) );
gwcvt_check( 'a bad nonce is refused', 'expired' === gwcvt_submit( array( 'gwcvt_self_log_nonce' => 'nope' ) ) );
gwcvt_check( 'a missing name is refused', 'incomplete' === gwcvt_submit( array( 'gwcvt_name' => '' ) ) );
gwcvt_check( 'a missing duration is refused', 'incomplete' === gwcvt_submit( array( 'gwcvt_hours' => '' ) ) );
gwcvt_check( 'an unreadable duration is refused', 'incomplete' === gwcvt_submit( array( 'gwcvt_hours' => 'ages' ) ) );
gwcvt_check( 'a future date is refused', 'incomplete' === gwcvt_submit( array( 'gwcvt_date' => '2099-01-01' ) ) );
gwcvt_check( 'nothing above stored anything', $gwcvt_before === gwcvt_pending_count(), (string) gwcvt_pending_count() );

/* ── Fields it must refuse to write ──────────────────────────────────────── */

delete_option( GWCVT_RATE_LIMIT_OPTION );

gwcvt_submit(
	array(
		'gwcvt_name'      => 'Zzytest Crafted Post',
		'gwcvt_volunteer' => '999',
		'_gwcvt_verified_at' => '2026-01-01 00:00:00',
		'gwcvt_verified_at'  => '2026-01-01 00:00:00',
	)
);

$gwcvt_ids = get_posts(
	array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC' )
);
$gwcvt_crafted = (int) $gwcvt_ids[0];
$GLOBALS['gwcvt_made'][] = $gwcvt_crafted;

gwcvt_check( 'a crafted volunteer id is ignored', 0 === (int) get_post_meta( $gwcvt_crafted, GWCVT_ENTRY_VOLUNTEER, true ) );
gwcvt_check( 'a crafted verification is ignored', ! gwcvt_entry_is_verified( $gwcvt_crafted ) );

/* ── The shared code ─────────────────────────────────────────────────────── */

update_option( 'gwcvt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwcvt_page, 'self_log_code' => 'RIVERBEND' ) );
gwcvt_settings_cache( null, true );
delete_option( GWCVT_RATE_LIMIT_OPTION );

gwcvt_check( 'a missing code is refused', 'bad-code' === gwcvt_submit() );
gwcvt_check( 'a wrong code is refused', 'bad-code' === gwcvt_submit( array( 'gwcvt_code' => 'nope' ) ) );
gwcvt_check( 'the right code is accepted', 'accepted' === gwcvt_submit( array( 'gwcvt_code' => 'RIVERBEND' ) ) );

$gwcvt_ids = get_posts(
	array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC' )
);
$GLOBALS['gwcvt_made'][] = (int) $gwcvt_ids[0];

/* ── Rate limiting, end to end ───────────────────────────────────────────── */

update_option( 'gwcvt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwcvt_page ) );
gwcvt_settings_cache( null, true );
delete_option( GWCVT_RATE_LIMIT_OPTION );

$gwcvt_before = gwcvt_pending_count();

for ( $gwcvt_i = 0; $gwcvt_i < 6; $gwcvt_i++ ) {
	gwcvt_submit( array( 'gwcvt_email' => 'flood@example.test' ) );
}

$gwcvt_after_six = gwcvt_pending_count();
$gwcvt_result    = gwcvt_submit( array( 'gwcvt_email' => 'flood@example.test' ) );

gwcvt_check( 'six submissions land', $gwcvt_after_six === $gwcvt_before + 6, (string) ( $gwcvt_after_six - $gwcvt_before ) );
gwcvt_check( 'the seventh is refused', $gwcvt_after_six === gwcvt_pending_count() );
gwcvt_check(
	'and looks exactly like an acceptance',
	'accepted' === $gwcvt_result && gwcvt_self_log_message( $gwcvt_result ) === gwcvt_self_log_message( 'accepted' ),
	$gwcvt_result
);

foreach ( get_posts( array( 'post_type' => GWCVT_ENTRY_TYPE, 'post_status' => 'pending', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) ) as $gwcvt_id ) {
	$GLOBALS['gwcvt_made'][] = (int) $gwcvt_id;
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

$_POST = array();

foreach ( array_unique( $GLOBALS['gwcvt_made'] ) as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

delete_option( 'gwcvt_settings' );
delete_option( GWCVT_RATE_LIMIT_OPTION );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

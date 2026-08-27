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
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/self-log.php
 *
 * @package VolunteerTracker
 */

$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();

/* Every entry this script's own submissions create, and nothing else.
 *
 * The first version swept up every pending entry on the site at cleanup,
 * because gwc_vt_submit() posts through the handler and does not hand an ID
 * back. That deleted records this script never created — it quietly destroyed
 * the seeded demo organization's two self-logged submissions every time it ran,
 * and on a site with real pending entries it would have destroyed those.
 *
 * gwc_vt_self_log_received fires with the ID of each entry the handler stores,
 * which is exactly the hook needed and was already there. */
$GLOBALS['gwc_vt_last_entry'] = 0;

add_action(
	'gwc_vt_self_log_received',
	static function ( $entry_id ): void {
		$GLOBALS['gwc_vt_made'][]     = (int) $entry_id;
		$GLOBALS['gwc_vt_last_entry'] = (int) $entry_id;
	}
);

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
 * Post a submission through the real handler.
 *
 * @param array $overrides Fields to change or remove.
 * @return string The result key the handler recorded.
 */
function gwc_vt_submit( array $overrides = array() ): string {
	$stamp = time() - 30;

	$_POST = array_merge(
		array(
			'gwc_vt_log_hours'      => '1',
			'gwc_vt_self_log_nonce' => wp_create_nonce( 'gwc_vt_self_log' ),
			'gwc_vt_t'              => $stamp . '.' . hash_hmac( 'sha256', (string) $stamp, wp_salt( 'gwc_vt_form' ) ),
			'gwc_vt_website'        => '',
			'gwc_vt_name'           => 'Zzytest Rosa Iqbal',
			'gwc_vt_email'          => 'rosa@example.test',
			'gwc_vt_date'           => '2026-07-11',
			'gwc_vt_hours'          => '3:30',
			'gwc_vt_activity'       => 'Sorting donations',
			'gwc_vt_supervisor'     => 'Dana Reyes',
		),
		$overrides
	);

	foreach ( $overrides as $key => $value ) {
		if ( null === $value ) {
			unset( $_POST[ $key ] );
		}
	}

	unset( $GLOBALS['gwc_vt_self_log_result'] );

	gwc_vt_handle_self_log();

	return (string) ( $GLOBALS['gwc_vt_self_log_result'] ?? '' );
}

/** How many pending self-logged entries exist. */
function gwc_vt_pending_count(): int {
	return count(
		get_posts(
			array(
				'post_type'      => GWC_VT_ENTRY_TYPE,
				'post_status'    => 'pending',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		)
	);
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

wp_set_current_user( 0 );

gwc_vt_borrow_option( 'gwc_vt_settings' );
gwc_vt_borrow_option( GWC_VT_RATE_LIMIT_OPTION );

$gwc_vt_page = wp_insert_post(
	array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zzytest Log your hours' )
);
$GLOBALS['gwc_vt_made'][] = $gwc_vt_page;

/* ── Off by default ──────────────────────────────────────────────────────── */

update_option( 'gwc_vt_settings', array( 'self_log_enabled' => false, 'self_log_page' => $gwc_vt_page ) );
gwc_vt_settings_cache( null, true );
delete_option( GWC_VT_RATE_LIMIT_OPTION );

gwc_vt_check( 'the form is off', ! gwc_vt_self_log_enabled() );
gwc_vt_check( 'and renders nothing', '' === gwc_vt_render_self_log_form() );

$gwc_vt_before = gwc_vt_pending_count();
gwc_vt_dispatch();
gwc_vt_check( 'dispatch while off stores nothing', $gwc_vt_before === gwc_vt_pending_count() );

/* ── Switched on ─────────────────────────────────────────────────────────── */

update_option( 'gwc_vt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwc_vt_page ) );
gwc_vt_settings_cache( null, true );

gwc_vt_check( 'the form is on', gwc_vt_self_log_enabled() );

$gwc_vt_markup = gwc_vt_render_self_log_form();
gwc_vt_check( 'it renders a form', false !== strpos( $gwc_vt_markup, 'gwc_vt_log_hours' ) );
gwc_vt_check( 'it carries a honeypot', false !== strpos( $gwc_vt_markup, 'gwc_vt_website' ) );
gwc_vt_check( 'the honeypot is not type=hidden', false === strpos( $gwc_vt_markup, 'type="hidden" id="gwcvt-website"' ) );
gwc_vt_check( 'it carries a timing stamp', false !== strpos( $gwc_vt_markup, 'gwc_vt_t' ) );
gwc_vt_check( 'it carries a nonce', false !== strpos( $gwc_vt_markup, 'gwc_vt_self_log_nonce' ) );

/* ── A real submission ───────────────────────────────────────────────────── */

$gwc_vt_before = gwc_vt_pending_count();
$gwc_vt_result = gwc_vt_submit();

gwc_vt_check( 'a good submission is accepted', 'accepted' === $gwc_vt_result, $gwc_vt_result );
gwc_vt_check( 'and stores one entry', gwc_vt_pending_count() === $gwc_vt_before + 1 );

/* The ID the handler reported, not "whichever pending entry is newest" —
 * that guess would pick up a seeded record the moment anything else on the
 * site was pending, and this script then deletes what it captures. */
$gwc_vt_entry = (int) $GLOBALS['gwc_vt_last_entry'];

gwc_vt_check( 'it is pending', 'pending' === get_post_status( $gwc_vt_entry ), (string) get_post_status( $gwc_vt_entry ) );
gwc_vt_check( 'it is marked as self-logged', 'self' === get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_SOURCE, true ) );
gwc_vt_check( 'it is attached to nobody', 0 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_VOLUNTEER, true ) );
gwc_vt_check( 'it is not verified', ! gwc_vt_entry_is_verified( $gwc_vt_entry ) );
gwc_vt_check( 'the name is stored as a claim', 'Zzytest Rosa Iqbal' === get_post_meta( $gwc_vt_entry, '_gwc_vt_claim_name', true ) );
gwc_vt_check( 'the email is stored as a claim', 'rosa@example.test' === get_post_meta( $gwc_vt_entry, '_gwc_vt_claim_email', true ) );
gwc_vt_check( 'the duration was parsed', 210 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true ), (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true ) );
gwc_vt_check( 'the title says it is unmatched', false !== strpos( get_the_title( $gwc_vt_entry ), 'unmatched' ), get_the_title( $gwc_vt_entry ) );

/* A submission must never reach a letter, whatever else happens to it. */
$gwc_vt_volunteer = wp_insert_post(
	array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzytest Rosa Iqbal' )
);
$GLOBALS['gwc_vt_made'][] = $gwc_vt_volunteer;

gwc_vt_check(
	'an untriaged submission is not counted for anybody',
	0 === gwc_vt_compute_totals( (int) $gwc_vt_volunteer )->entries
);

/* ── The checks ──────────────────────────────────────────────────────────── */

delete_option( GWC_VT_RATE_LIMIT_OPTION );
$gwc_vt_before = gwc_vt_pending_count();

gwc_vt_check( 'a filled honeypot is silently dropped', 'accepted' === gwc_vt_submit( array( 'gwc_vt_website' => 'http://spam.test' ) ) );
gwc_vt_check( 'and stores nothing', $gwc_vt_before === gwc_vt_pending_count() );

$gwc_vt_instant = time();
gwc_vt_check(
	'a submission three seconds after render is dropped',
	'accepted' === gwc_vt_submit( array( 'gwc_vt_t' => $gwc_vt_instant . '.' . hash_hmac( 'sha256', (string) $gwc_vt_instant, wp_salt( 'gwc_vt_form' ) ) ) )
);
gwc_vt_check( 'and stores nothing', $gwc_vt_before === gwc_vt_pending_count() );

gwc_vt_check( 'a forged timing stamp is refused', 'expired' === gwc_vt_submit( array( 'gwc_vt_t' => time() . '.deadbeef' ) ) );
gwc_vt_check( 'a bad nonce is refused', 'expired' === gwc_vt_submit( array( 'gwc_vt_self_log_nonce' => 'nope' ) ) );
gwc_vt_check( 'a missing name is refused', 'incomplete' === gwc_vt_submit( array( 'gwc_vt_name' => '' ) ) );
gwc_vt_check( 'a missing duration is refused', 'incomplete' === gwc_vt_submit( array( 'gwc_vt_hours' => '' ) ) );
gwc_vt_check( 'an unreadable duration is refused', 'incomplete' === gwc_vt_submit( array( 'gwc_vt_hours' => 'ages' ) ) );
gwc_vt_check( 'a future date is refused', 'incomplete' === gwc_vt_submit( array( 'gwc_vt_date' => '2099-01-01' ) ) );
gwc_vt_check( 'nothing above stored anything', $gwc_vt_before === gwc_vt_pending_count(), (string) gwc_vt_pending_count() );

/* ── Fields it must refuse to write ──────────────────────────────────────── */

delete_option( GWC_VT_RATE_LIMIT_OPTION );

gwc_vt_submit(
	array(
		'gwc_vt_name'      => 'Zzytest Crafted Post',
		'gwc_vt_volunteer' => '999',
		'_gwc_vt_verified_at' => '2026-01-01 00:00:00',
		'gwc_vt_verified_at'  => '2026-01-01 00:00:00',
	)
);

$gwc_vt_crafted = (int) $GLOBALS['gwc_vt_last_entry'];

gwc_vt_check( 'a crafted volunteer id is ignored', 0 === (int) get_post_meta( $gwc_vt_crafted, GWC_VT_ENTRY_VOLUNTEER, true ) );
gwc_vt_check( 'a crafted verification is ignored', ! gwc_vt_entry_is_verified( $gwc_vt_crafted ) );

/* ── The shared code ─────────────────────────────────────────────────────── */

update_option( 'gwc_vt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwc_vt_page, 'self_log_code' => 'RIVERBEND' ) );
gwc_vt_settings_cache( null, true );
delete_option( GWC_VT_RATE_LIMIT_OPTION );

gwc_vt_check( 'a missing code is refused', 'bad-code' === gwc_vt_submit() );
gwc_vt_check( 'a wrong code is refused', 'bad-code' === gwc_vt_submit( array( 'gwc_vt_code' => 'nope' ) ) );
gwc_vt_check( 'the right code is accepted', 'accepted' === gwc_vt_submit( array( 'gwc_vt_code' => 'RIVERBEND' ) ) );



/* ── Rate limiting, end to end ───────────────────────────────────────────── */

update_option( 'gwc_vt_settings', array( 'self_log_enabled' => true, 'self_log_page' => $gwc_vt_page ) );
gwc_vt_settings_cache( null, true );
delete_option( GWC_VT_RATE_LIMIT_OPTION );

$gwc_vt_before = gwc_vt_pending_count();

for ( $gwc_vt_i = 0; $gwc_vt_i < 6; $gwc_vt_i++ ) {
	gwc_vt_submit( array( 'gwc_vt_email' => 'flood@example.test' ) );
}

$gwc_vt_after_six = gwc_vt_pending_count();
$gwc_vt_result    = gwc_vt_submit( array( 'gwc_vt_email' => 'flood@example.test' ) );

gwc_vt_check( 'six submissions land', $gwc_vt_after_six === $gwc_vt_before + 6, (string) ( $gwc_vt_after_six - $gwc_vt_before ) );
gwc_vt_check( 'the seventh is refused', $gwc_vt_after_six === gwc_vt_pending_count() );
gwc_vt_check(
	'and looks exactly like an acceptance',
	'accepted' === $gwc_vt_result && gwc_vt_self_log_message( $gwc_vt_result ) === gwc_vt_self_log_message( 'accepted' ),
	$gwc_vt_result
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

$_POST = array();

foreach ( array_unique( $GLOBALS['gwc_vt_made'] ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

gwc_vt_return_options();

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

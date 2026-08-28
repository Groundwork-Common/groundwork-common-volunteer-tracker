<?php
/**
 * Turning the letter half off, and finding everything where it was left.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * The switch is only half a setting. The other half is a promise about what
 * turning it off does NOT do, and that promise is about stored records: the
 * issued-letter log, every gwc_vt_letter post, all seventeen letter settings and
 * both capabilities. None of that can be asserted without somewhere to store it.
 *
 * The acceptance criteria on the issue ask for the refusals to be checked by a
 * test rather than by inspection, and this is why: a hidden screen whose handler
 * still answers is not hidden, and nothing about the menu being gone tells you
 * whether admin-post.php still produces a letter for anybody who asks.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letters-switch.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_ls_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── Borrow the settings, and give them back ────────────────────────────────
 * Read what is there and restore exactly that, rather than writing a known set:
 * the same reasoning as tests/integration/event-screens.php, and for the same
 * reason — a script that wiped a site's whole configuration on every run was
 * invisible while the site was empty. Registered on shutdown, so it runs whether
 * this finishes, fails an assertion or fatals; PHP runs shutdown functions on
 * exit(), so the exit( 1 ) at the foot is safe beside it.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

/**
 * Put the site's settings back exactly as they were found.
 */
function gwc_vt_ls_restore(): void {
	if ( ! array_key_exists( 'gwc_vt_settings_before', $GLOBALS ) ) {
		return;
	}

	if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
		delete_option( GWC_VT_SETTINGS_OPTION );
	} else {
		update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
	}

	unset( $GLOBALS['gwc_vt_settings_before'] );
	gwc_vt_settings_cache( null, true );

	/* The fixtures go the same way, and for a sharper reason than tidiness. A
	 * volunteer left behind by a run that fatalled before its teardown is a
	 * volunteer tests/integration/entries.php then counts, and it fails there
	 * rather than here — a script breaking a different script is the hardest
	 * kind of red to read. That happened while this file was being written. */
	foreach ( (array) ( $GLOBALS['gwc_vt_ls_bin'] ?? array() ) as $gwc_vt_id ) {
		wp_delete_post( (int) $gwc_vt_id, true );
	}

	$GLOBALS['gwc_vt_ls_bin'] = array();
}

$GLOBALS['gwc_vt_ls_bin'] = array();

register_shutdown_function( 'gwc_vt_ls_restore' );

/* Everything this script creates, tracked so it can be taken away again. These
 * scripts run against a database that belongs to somebody else, and a leaked
 * "Zzytest" volunteer is not merely untidy: tests/integration/entries.php
 * asserts that a search for that word finds exactly its own two, so one left
 * behind here fails a script over there. */
$GLOBALS['gwc_vt_made'] = array();

/**
 * Delete what this script made.
 */
function gwc_vt_ls_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

register_shutdown_function( 'gwc_vt_ls_cleanup' );

/**
 * Set letters on or off, leaving every other setting alone.
 *
 * @param bool $on Whether letters are issued here.
 */
function gwc_vt_ls_letters( bool $on ): void {
	$stored = get_option( GWC_VT_SETTINGS_OPTION );
	$stored = is_array( $stored ) ? $stored : array();

	$stored['letters_enabled'] = $on;

	update_option( GWC_VT_SETTINGS_OPTION, $stored );
	gwc_vt_settings_cache( null, true );
}

/* ── The default, which is the part every existing install depends on ────── */

gwc_vt_ls_check(
	'letters are on by default, so an update takes nothing away',
	true === ( gwc_vt_setting_defaults()['letters_enabled'] ?? null ),
	var_export( gwc_vt_setting_defaults()['letters_enabled'] ?? null, true )
);

/* An install that has never seen this setting reads the default rather than
 * false — which is the difference between "not decided" and "decided no", and
 * is the same isset()-versus-truthiness trap the capabilities have. */
$gwc_vt_stored = get_option( GWC_VT_SETTINGS_OPTION );
$gwc_vt_stored = is_array( $gwc_vt_stored ) ? $gwc_vt_stored : array();
unset( $gwc_vt_stored['letters_enabled'] );
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_stored );
gwc_vt_settings_cache( null, true );

gwc_vt_ls_check(
	'and a site upgrading from a version without the setting still has them',
	gwc_vt_letters_enabled()
);

/* ── What is on file before the switch is touched ────────────────────────── */

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Letters Switch',
	)
);

$GLOBALS['gwc_vt_ls_bin'][] = (int) $gwc_vt_volunteer;

$gwc_vt_letter_post = wp_insert_post(
	array(
		'post_type'   => GWC_VT_LETTER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest issued letter',
	)
);

$GLOBALS['gwc_vt_ls_bin'][] = (int) $gwc_vt_letter_post;

/* ── Asked once, and only where it has not been answered ─────────────────────
 * letters_enabled defaults ON, which is right and is still a decision nobody
 * made on the sites it is wrong for. The prompt asks; saving the Logging tab
 * settles it either way. Two things have to hold or it becomes noise: it stops
 * for good once answered, and it never starts on a site that has already
 * answered by issuing a letter.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_ls_check(
	'nobody has been asked yet on a fresh install',
	false === ( gwc_vt_setting_defaults()['letters_decided'] ?? null ),
	var_export( gwc_vt_setting_defaults()['letters_decided'] ?? null, true )
);

/**
 * Set the decided flag directly, leaving every other setting alone.
 *
 * @param bool $decided Whether the question has been answered.
 */
function gwc_vt_ls_decided( bool $decided ): void {
	$stored = get_option( GWC_VT_SETTINGS_OPTION );
	$stored = is_array( $stored ) ? $stored : array();

	$stored['letters_decided'] = $decided;

	update_option( GWC_VT_SETTINGS_OPTION, $stored );
	gwc_vt_settings_cache( null, true );
}

/* Deliberately after the letter fixture above, and this ordering is load-bearing.
 * gwc_vt_any_letter_issued() memoizes for the request, so whichever assertion
 * calls it first fixes the answer for the rest of the run — and whether a bare
 * site has letters differs between the seeded development database and the
 * clean 7.4 one. Asking only once the fixture exists makes both of these mean
 * the same thing on either. An earlier draft asked before, and passed on the
 * seeded site while failing on the clean one. */
gwc_vt_ls_decided( false );

gwc_vt_ls_check(
	'a site that has issued a letter is never asked',
	gwc_vt_any_letter_issued() && gwc_vt_letters_decided(),
	'issued ' . var_export( gwc_vt_any_letter_issued(), true ) . ', decided ' . var_export( gwc_vt_letters_decided(), true )
);

gwc_vt_ls_decided( true );

gwc_vt_ls_check( 'and saying so settles it too, whatever the log holds', gwc_vt_letters_decided() );

gwc_vt_ls_decided( false );


gwc_vt_ls_letters( true );

$gwc_vt_settings_on = get_option( GWC_VT_SETTINGS_OPTION );

gwc_vt_ls_check( 'with letters on, the Letter tab is there', isset( gwc_vt_admin_tabs()['letter'] ), implode( ',', array_keys( gwc_vt_admin_tabs() ) ) );
gwc_vt_ls_check( 'and the Letters screen is in the menu order', in_array( GWC_VT_LETTERS_PAGE, gwc_vt_menu_order(), true ) );

/* ── Off ─────────────────────────────────────────────────────────────────── */

gwc_vt_ls_letters( false );

gwc_vt_ls_check( 'with letters off, the Letter tab is gone', ! isset( gwc_vt_admin_tabs()['letter'] ), implode( ',', array_keys( gwc_vt_admin_tabs() ) ) );
gwc_vt_ls_check( 'and the Letters screen is out of the menu order', ! in_array( GWC_VT_LETTERS_PAGE, gwc_vt_menu_order(), true ) );

/* A bookmark pointing at the tab that is gone lands somewhere real rather than
 * on an empty screen. */
$GLOBALS['gwc_vt_get_before'] = $_GET;
$_GET                         = array( 'tab' => 'letter' );

gwc_vt_ls_check(
	'a bookmarked ?tab=letter falls back to a tab that exists',
	isset( gwc_vt_admin_tabs()[ gwc_vt_current_tab() ] ),
	gwc_vt_current_tab()
);

$_GET = $GLOBALS['gwc_vt_get_before'];

/* The switch itself has to stay reachable, or turning it off is one-way. */
$gwc_vt_switch = gwc_vt_settings_fields()['letters_enabled'] ?? array();

gwc_vt_ls_check(
	'the switch lives on a tab that is still there when it is off',
	isset( $gwc_vt_switch['tab'] ) && isset( gwc_vt_admin_tabs()[ $gwc_vt_switch['tab'] ] ),
	(string) ( $gwc_vt_switch['tab'] ?? '(none)' )
);

/* ── Nothing was deleted, which is the promise ───────────────────────────── */

gwc_vt_ls_check( 'the issued letter is still there', GWC_VT_LETTER_TYPE === get_post_type( $gwc_vt_letter_post ) );
gwc_vt_ls_check( 'and the letter post type is still registered', post_type_exists( GWC_VT_LETTER_TYPE ) );

$gwc_vt_kept = 0;

foreach ( gwc_vt_settings_fields() as $gwc_vt_key => $gwc_vt_field ) {
	if ( 'letter' !== ( $gwc_vt_field['tab'] ?? '' ) ) {
		continue;
	}

	++$gwc_vt_kept;

	if ( ( $gwc_vt_settings_on[ $gwc_vt_key ] ?? null ) !== ( get_option( GWC_VT_SETTINGS_OPTION )[ $gwc_vt_key ] ?? null ) ) {
		gwc_vt_ls_check( 'letter setting ' . $gwc_vt_key . ' was left alone', false );
	}
}

gwc_vt_ls_check( 'every letter setting was left alone', $gwc_vt_kept > 0, $gwc_vt_kept . ' checked' );

/* Deactivation does not remove capabilities and neither does this switch — it is
 * not a back door around hard rules 5 and 10. Only the two PRIMITIVE
 * capabilities are role state; GWC_VT_CAP_OPEN_LETTERS is a meta capability
 * mapped by gwc_vt_map_open_letters(), so it is never written to a role and
 * has_cap() would answer false for it whatever this switch did. */
$gwc_vt_admin_role = get_role( 'administrator' );

gwc_vt_ls_check(
	'and both granted capabilities are still on the administrator role',
	$gwc_vt_admin_role instanceof WP_Role
		&& $gwc_vt_admin_role->has_cap( GWC_VT_CAP_ISSUE )
		&& $gwc_vt_admin_role->has_cap( GWC_VT_CAP_VERIFY )
);

gwc_vt_ls_check(
	'and the meta capability the Letters screen hangs off still maps',
	GWC_VT_CAP_OPEN_LETTERS === 'gwc_vt_open_letters'
		&& has_filter( 'map_meta_cap', 'gwc_vt_map_open_letters' ) !== false
);

/* ── Everything else keeps working ───────────────────────────────────────── */

gwc_vt_ls_check( 'the hours post type is untouched', post_type_exists( GWC_VT_ENTRY_TYPE ) );
gwc_vt_ls_check( 'so is the volunteer post type', post_type_exists( GWC_VT_VOLUNTEER_TYPE ) );
gwc_vt_ls_check( 'and the shift post type', post_type_exists( GWC_VT_SHIFT_TYPE ) );
gwc_vt_ls_check( 'verification still answers', is_bool( gwc_vt_entry_is_verified( (int) $gwc_vt_letter_post ) ) );
gwc_vt_ls_check( 'the retention policy is unaffected', is_array( gwc_vt_settings_fields() ) && isset( gwc_vt_settings_fields()['retention_months'] ) );
gwc_vt_ls_check( 'the privacy exporter is still registered', is_array( apply_filters( 'wp_privacy_personal_data_exporters', array() ) ) );

$gwc_vt_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$gwc_vt_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

gwc_vt_ls_check( 'and it is still ours, with letters off', isset( $gwc_vt_exporters['groundwork-common-volunteer-tracker'] ) );
gwc_vt_ls_check( 'as is the eraser', isset( $gwc_vt_erasers['groundwork-common-volunteer-tracker'] ) );

/* The Logging, Shifts and Privacy tabs are all still there — turning the letter
 * off must not take a neighbor with it. */
foreach ( array( 'logging', 'shifts', 'privacy' ) as $gwc_vt_tab ) {
	gwc_vt_ls_check( 'the ' . $gwc_vt_tab . ' tab survives', isset( gwc_vt_admin_tabs()[ $gwc_vt_tab ] ) );
}

/* ── And back on ─────────────────────────────────────────────────────────── */

gwc_vt_ls_letters( true );

gwc_vt_ls_check( 'turning it back on returns the Letter tab', isset( gwc_vt_admin_tabs()['letter'] ) );
gwc_vt_ls_check( 'and the Letters screen', in_array( GWC_VT_LETTERS_PAGE, gwc_vt_menu_order(), true ) );

$gwc_vt_settings_again = get_option( GWC_VT_SETTINGS_OPTION );
$gwc_vt_drifted        = array();

foreach ( gwc_vt_settings_fields() as $gwc_vt_key => $gwc_vt_field ) {
	if ( 'letter' !== ( $gwc_vt_field['tab'] ?? '' ) ) {
		continue;
	}

	if ( ( $gwc_vt_settings_on[ $gwc_vt_key ] ?? null ) !== ( $gwc_vt_settings_again[ $gwc_vt_key ] ?? null ) ) {
		$gwc_vt_drifted[] = $gwc_vt_key;
	}
}

gwc_vt_ls_check(
	'off and on again leaves every letter setting exactly as it was',
	array() === $gwc_vt_drifted,
	implode( ',', $gwc_vt_drifted )
);

gwc_vt_ls_check( 'and the issued letter is still there', GWC_VT_LETTER_TYPE === get_post_type( $gwc_vt_letter_post ) );

/* ── Teardown ────────────────────────────────────────────────────────────────
 * Deliberately not here. gwc_vt_ls_restore() takes the fixtures with the
 * settings, on shutdown, so a run that stops early still leaves the site as it
 * found it.
 * ─────────────────────────────────────────────────────────────────────────── */

/* ── The readiness line says what the letter will say ────────────────────────
 * It is built by gwc_vt_build_letter() with include_unverified forced ON, which
 * is not the obvious thing to do: the screen has already built a letter, and
 * reading unverified_minutes off THAT would be one lookup cheaper and wrong on
 * half the sites in the world. That field is only populated when the letter was
 * asked to LIST unattested shifts, and whether it was is the
 * letter_include_unverified setting — so the shortcut gives the right answer
 * with the setting on and a confident zero with it off.
 *
 * "Nothing of theirs is waiting to be verified" is exactly the sentence that
 * must not be wrong, so it is checked under both settings.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_ls_person'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Readiness Person',
	)
);

$GLOBALS['gwc_vt_made'][] = (int) $GLOBALS['gwc_vt_ls_person'];

/**
 * One entry for the readiness fixture.
 *
 * @param int  $minutes  How long.
 * @param bool $verified Whether to attest to it.
 * @return int
 */
function gwc_vt_ls_hours( int $minutes, bool $verified ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	$id = (int) $id;

	$GLOBALS['gwc_vt_made'][] = $id;

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (int) $GLOBALS['gwc_vt_ls_person'] );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Zzytest sorting' );

	if ( $verified ) {
		gwc_vt_verify_entry( $id, 1 );
	}

	return $id;
}

gwc_vt_ls_hours( 240, true );
gwc_vt_ls_hours( 180, false );

/* ── Told about waiting hours whichever way the switch is set ────────────────
 * letter_include_unverified decides whether unverified time is PRINTED on a
 * letter. Whether the coordinator is TOLD it exists is a different question,
 * and the answer has to be yes either way — a total about to change is worth
 * knowing before a letter goes out.
 *
 * This used to be the readiness line on the produce screen. That screen is
 * gone, and the sentence matters more than it did: a draft fixes the moment its
 * attestations are counted as of, so hours verified later will not join THIS
 * draft at all. gwc_vt_draft_unverified_minutes() asks with include_unverified
 * forced on, precisely so the answer does not depend on the setting. */
$GLOBALS['gwc_vt_ls_draft'] = gwc_vt_letter_draft( gwc_vt_add_letter_draft( (int) $GLOBALS['gwc_vt_ls_person'] ) );

foreach ( array( true, false ) as $gwc_vt_ls_listing ) {
	$gwc_vt_ls_settings                              = (array) get_option( GWC_VT_SETTINGS_OPTION );
	$gwc_vt_ls_settings['letter_include_unverified'] = $gwc_vt_ls_listing;
	update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_ls_settings );
	gwc_vt_settings_cache( null, true );

	gwc_vt_ls_check(
		'with the unverified listing ' . ( $gwc_vt_ls_listing ? 'on' : 'off' ) . ', the waiting hours are still reported',
		180 === gwc_vt_draft_unverified_minutes( $GLOBALS['gwc_vt_ls_draft'] ),
		(string) gwc_vt_draft_unverified_minutes( $GLOBALS['gwc_vt_ls_draft'] )
	);

	gwc_vt_ls_check(
		'and the verified total is the same either way',
		240 === gwc_vt_build_letter(
			(int) $GLOBALS['gwc_vt_ls_person'],
			gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ls_draft'] )
		)->verified_minutes,
		(string) gwc_vt_build_letter(
			(int) $GLOBALS['gwc_vt_ls_person'],
			gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ls_draft'] )
		)->verified_minutes
	);
}

/* Verify the last one. The draft is fixed to a moment BEFORE that, so it does
 * not move — which is the lock working, and is why the row tells somebody to
 * start another draft rather than implying this one will catch up.
 *
 * A second first, because the cutoff is an instant and the comparison is
 * strictly later. An attestation written in the same second as the draft counts
 * as part of it: the alternative rounds the other way and silently takes real
 * hours off a letter, which is the direction this plugin refuses to fail in. */
sleep( 1 );

foreach ( gwc_vt_entry_ids_for_volunteer( (int) $GLOBALS['gwc_vt_ls_person'] ) as $gwc_vt_ls_id ) {
	gwc_vt_verify_entry( (int) $gwc_vt_ls_id, 1 );
}

gwc_vt_ls_check(
	'and verifying them afterwards does not quietly join them to the draft',
	240 === gwc_vt_build_letter(
		(int) $GLOBALS['gwc_vt_ls_person'],
		gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ls_draft'] )
	)->verified_minutes,
	(string) gwc_vt_build_letter(
		(int) $GLOBALS['gwc_vt_ls_person'],
		gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ls_draft'] )
	)->verified_minutes
);

/* And the record itself does move, which is what makes the check above a lock
 * rather than an absence: a NEW draft started now would state everything. */
gwc_vt_ls_check(
	'while the record itself is now everything',
	420 === gwc_vt_build_letter( (int) $GLOBALS['gwc_vt_ls_person'] )->verified_minutes,
	(string) gwc_vt_build_letter( (int) $GLOBALS['gwc_vt_ls_person'] )->verified_minutes
);

/* ── The split ──────────────────────────────────────────────────────────────
 * The records screen is the log; producing starts from the volunteer. Asserted
 * by rendering both, because "it is on the other screen now" is the sort of
 * claim that is true on the day it is written.
 * ─────────────────────────────────────────────────────────────────────────── */

/* Both renderers refuse somebody without the capability, which is the point of
 * them — so this has to be somebody. */
$gwc_vt_ls_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( $gwc_vt_ls_admins ) {
	wp_set_current_user( (int) $gwc_vt_ls_admins[0] );
}

ob_start();
gwc_vt_render_letters_screen();
$gwc_vt_ls_records = (string) ob_get_clean();

gwc_vt_ls_check(
	'the records screen has no produce form on it',
	false === strpos( $gwc_vt_ls_records, 'gwcvt-letter-volunteer' )
);

/* The checker's own input, which is what renders only where the form does. It
 * is in this screen's rail now: whoever is reading the log is the person a
 * caller reaches, and sending them to the dashboard for a box that fits beside
 * the log was a page load to answer a ten-second question. */
gwc_vt_ls_check(
	'but the reference checker is on it',
	false !== strpos( $gwc_vt_ls_records, 'name="reference"' )
);

/* ── And it is not a cul-de-sac ──────────────────────────────────────────────
 * Everything on this screen has already happened, so the thing somebody arrives
 * wanting is elsewhere. Saying so in prose was not enough; this is the link
 * that says it. Asserted by destination rather than by wording, so a copy edit
 * does not fail the build and a broken link does.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_ls_check(
	'it offers a way to produce one, and it goes to the volunteer list',
	false !== strpos( $gwc_vt_ls_records, 'post_type=' . GWC_VT_VOLUNTEER_TYPE )
);

/* And no longer a link to the dashboard's copy of the checker, which is what
 * the second link here used to be: another page for a box six inches away. */
gwc_vt_ls_check(
	'and does not send anybody to the dashboard to check one',
	false === strpos( $gwc_vt_ls_records, 'page=' . GWC_VT_DASHBOARD_PAGE . '#gwcvt-reference' )
);

ob_start();
gwc_vt_render_dashboard_reference();
$gwc_vt_ls_dash = (string) ob_get_clean();

gwc_vt_ls_check(
	'the checker is on the dashboard',
	false !== strpos( $gwc_vt_ls_dash, 'gwcvt-reference' )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

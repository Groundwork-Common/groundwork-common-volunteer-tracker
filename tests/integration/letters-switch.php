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
 *   npx @wordpress/env run cli -- wp eval-file \
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

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

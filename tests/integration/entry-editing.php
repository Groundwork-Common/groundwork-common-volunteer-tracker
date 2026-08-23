<?php
/**
 * Saving one shift from the entry editor, and what it says about what it fixed.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * gwc_vt_save_entry() corrects three things it cannot store as typed: hours it
 * cannot read, a date in the future while those are switched off, and an ID that
 * does not name a volunteer. All three used to happen in silence. The only trace
 * of the first was a derived title reading "… — 0", and the plugin's own note
 * beside the parser says a long value is refused "so the form can say so" — which
 * the entry editor could not do, because nothing carried the reason out of the
 * save and into the redirect that follows it.
 *
 * The save itself needs $_POST, a nonce and a real post, so this belongs here
 * rather than in the unit suite.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/entry-editing.php
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
function gwc_vt_ee_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Save one entry the way the editor does, and report what it stashed.
 *
 * @param int   $entry_id Entry post ID.
 * @param array $fields   Posted fields.
 * @return string[] Problem keys.
 */
function gwc_vt_ee_save( int $entry_id, array $fields ): array {
	$_POST = array_merge(
		array( 'gwc_vt_entry_nonce' => wp_create_nonce( 'gwc_vt_save_entry' ) ),
		$fields
	);

	gwc_vt_save_entry( $entry_id, get_post( $entry_id ) );

	$stashed = get_transient( 'gwc_vt_entry_saved_' . $entry_id . '_' . get_current_user_id() );
	$_POST   = array();

	return is_array( $stashed ) ? array_map( 'strval', $stashed ) : array();
}

wp_set_current_user( 1 );

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Imogen Vasquez-Hale',
	)
);

$gwc_vt_entry = wp_insert_post(
	array(
		'post_type'   => GWC_VT_ENTRY_TYPE,
		'post_status' => 'publish',
	)
);

/* ── A good save says nothing ────────────────────────────────────────────── */

$gwc_vt_said = gwc_vt_ee_save(
	(int) $gwc_vt_entry,
	array(
		'gwc_vt_volunteer'  => (string) $gwc_vt_volunteer,
		'gwc_vt_date'       => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwc_vt_hours'      => '3:30',
		'gwc_vt_activity'   => 'Sorting donations',
		'gwc_vt_supervisor' => 'Marisol Okonkwo',
	)
);

gwc_vt_ee_check( 'a clean save reports nothing', array() === $gwc_vt_said, implode( ',', $gwc_vt_said ) );
gwc_vt_ee_check(
	'and records the hours',
	210 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true ),
	(string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true )
);

/* ── The bare 210 ────────────────────────────────────────────────────────────
 * Somebody typing minutes into a field they expect to take minutes. It is
 * refused rather than recorded as two hundred and ten hours, and the point of
 * this whole change is that the refusal is now visible.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_said = gwc_vt_ee_save(
	(int) $gwc_vt_entry,
	array(
		'gwc_vt_volunteer' => (string) $gwc_vt_volunteer,
		'gwc_vt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwc_vt_hours'     => '210',
	)
);

gwc_vt_ee_check( 'an unreadable duration is reported', in_array( 'hours', $gwc_vt_said, true ), implode( ',', $gwc_vt_said ) );
gwc_vt_ee_check(
	'and it really did store none',
	0 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, true )
);

/* And the same value written as minutes goes straight in. */
$gwc_vt_said = gwc_vt_ee_save(
	(int) $gwc_vt_entry,
	array(
		'gwc_vt_volunteer' => (string) $gwc_vt_volunteer,
		'gwc_vt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwc_vt_hours'     => '210m',
	)
);

gwc_vt_ee_check( '210m is accepted without comment', array() === $gwc_vt_said, implode( ',', $gwc_vt_said ) );

/* ── A future date, while those are switched off ─────────────────────────── */

$gwc_vt_settings = get_option( GWC_VT_SETTINGS_OPTION, array() );
$gwc_vt_restore  = $gwc_vt_settings;

$gwc_vt_settings['allow_future_dates'] = false;
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_settings );
gwc_vt_settings_cache( null, true );

$gwc_vt_ahead = gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) );

$gwc_vt_said = gwc_vt_ee_save(
	(int) $gwc_vt_entry,
	array(
		'gwc_vt_volunteer' => (string) $gwc_vt_volunteer,
		'gwc_vt_date'      => $gwc_vt_ahead,
		'gwc_vt_hours'     => '2',
	)
);

gwc_vt_ee_check( 'a clamped date is reported', in_array( 'future-date', $gwc_vt_said, true ), implode( ',', $gwc_vt_said ) );
gwc_vt_ee_check(
	'and the stored date is today',
	gwc_vt_today() === (string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_DATE, true ),
	(string) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_DATE, true )
);

/* ── An ID that is not a volunteer ───────────────────────────────────────── */

$gwc_vt_said = gwc_vt_ee_save(
	(int) $gwc_vt_entry,
	array(
		'gwc_vt_volunteer' => (string) $gwc_vt_entry, // An entry, not a volunteer.
		'gwc_vt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwc_vt_hours'     => '2',
	)
);

gwc_vt_ee_check( 'a dropped volunteer is reported', in_array( 'volunteer', $gwc_vt_said, true ), implode( ',', $gwc_vt_said ) );
gwc_vt_ee_check(
	'and the entry is attached to nobody',
	0 === (int) get_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_VOLUNTEER, true )
);

/* ── Every reported key has something to say ────────────────────────────────
 * A key with no message prints an empty warning box, which reads as a bug in
 * the plugin rather than a note about the shift.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_messages = gwc_vt_entry_problem_messages();

foreach ( array( 'hours', 'future-date', 'volunteer' ) as $gwc_vt_key ) {
	gwc_vt_ee_check(
		'"' . $gwc_vt_key . '" has wording',
		isset( $gwc_vt_messages[ $gwc_vt_key ] ) && '' !== trim( $gwc_vt_messages[ $gwc_vt_key ] )
	);
}

/* ── Teardown ────────────────────────────────────────────────────────────── */

delete_transient( 'gwc_vt_entry_saved_' . (int) $gwc_vt_entry . '_' . get_current_user_id() );
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_restore );
gwc_vt_settings_cache( null, true );

wp_delete_post( (int) $gwc_vt_entry, true );
wp_delete_post( (int) $gwc_vt_volunteer, true );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

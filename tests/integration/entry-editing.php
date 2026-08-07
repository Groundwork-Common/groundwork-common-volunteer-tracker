<?php
/**
 * Saving one shift from the entry editor, and what it says about what it fixed.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * gwcvt_save_entry() corrects three things it cannot store as typed: hours it
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
$GLOBALS['gwcvt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwcvt_ee_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
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
function gwcvt_ee_save( int $entry_id, array $fields ): array {
	$_POST = array_merge(
		array( 'gwcvt_entry_nonce' => wp_create_nonce( 'gwcvt_save_entry' ) ),
		$fields
	);

	gwcvt_save_entry( $entry_id, get_post( $entry_id ) );

	$stashed = get_transient( 'gwcvt_entry_saved_' . $entry_id . '_' . get_current_user_id() );
	$_POST   = array();

	return is_array( $stashed ) ? array_map( 'strval', $stashed ) : array();
}

wp_set_current_user( 1 );

$gwcvt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Imogen Vasquez-Hale',
	)
);

$gwcvt_entry = wp_insert_post(
	array(
		'post_type'   => GWCVT_ENTRY_TYPE,
		'post_status' => 'publish',
	)
);

/* ── A good save says nothing ────────────────────────────────────────────── */

$gwcvt_said = gwcvt_ee_save(
	(int) $gwcvt_entry,
	array(
		'gwcvt_volunteer'  => (string) $gwcvt_volunteer,
		'gwcvt_date'       => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwcvt_hours'      => '3:30',
		'gwcvt_activity'   => 'Sorting donations',
		'gwcvt_supervisor' => 'Marisol Okonkwo',
	)
);

gwcvt_ee_check( 'a clean save reports nothing', array() === $gwcvt_said, implode( ',', $gwcvt_said ) );
gwcvt_ee_check(
	'and records the hours',
	210 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true ),
	(string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true )
);

/* ── The bare 210 ────────────────────────────────────────────────────────────
 * Somebody typing minutes into a field they expect to take minutes. It is
 * refused rather than recorded as two hundred and ten hours, and the point of
 * this whole change is that the refusal is now visible.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_said = gwcvt_ee_save(
	(int) $gwcvt_entry,
	array(
		'gwcvt_volunteer' => (string) $gwcvt_volunteer,
		'gwcvt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwcvt_hours'     => '210',
	)
);

gwcvt_ee_check( 'an unreadable duration is reported', in_array( 'hours', $gwcvt_said, true ), implode( ',', $gwcvt_said ) );
gwcvt_ee_check(
	'and it really did store none',
	0 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, true )
);

/* And the same value written as minutes goes straight in. */
$gwcvt_said = gwcvt_ee_save(
	(int) $gwcvt_entry,
	array(
		'gwcvt_volunteer' => (string) $gwcvt_volunteer,
		'gwcvt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwcvt_hours'     => '210m',
	)
);

gwcvt_ee_check( '210m is accepted without comment', array() === $gwcvt_said, implode( ',', $gwcvt_said ) );

/* ── A future date, while those are switched off ─────────────────────────── */

$gwcvt_settings = get_option( GWCVT_SETTINGS_OPTION, array() );
$gwcvt_restore  = $gwcvt_settings;

$gwcvt_settings['allow_future_dates'] = false;
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_settings );
gwcvt_settings_cache( null, true );

$gwcvt_ahead = gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) );

$gwcvt_said = gwcvt_ee_save(
	(int) $gwcvt_entry,
	array(
		'gwcvt_volunteer' => (string) $gwcvt_volunteer,
		'gwcvt_date'      => $gwcvt_ahead,
		'gwcvt_hours'     => '2',
	)
);

gwcvt_ee_check( 'a clamped date is reported', in_array( 'future-date', $gwcvt_said, true ), implode( ',', $gwcvt_said ) );
gwcvt_ee_check(
	'and the stored date is today',
	gwcvt_today() === (string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_DATE, true ),
	(string) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_DATE, true )
);

/* ── An ID that is not a volunteer ───────────────────────────────────────── */

$gwcvt_said = gwcvt_ee_save(
	(int) $gwcvt_entry,
	array(
		'gwcvt_volunteer' => (string) $gwcvt_entry, // An entry, not a volunteer.
		'gwcvt_date'      => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
		'gwcvt_hours'     => '2',
	)
);

gwcvt_ee_check( 'a dropped volunteer is reported', in_array( 'volunteer', $gwcvt_said, true ), implode( ',', $gwcvt_said ) );
gwcvt_ee_check(
	'and the entry is attached to nobody',
	0 === (int) get_post_meta( $gwcvt_entry, GWCVT_ENTRY_VOLUNTEER, true )
);

/* ── Every reported key has something to say ────────────────────────────────
 * A key with no message prints an empty warning box, which reads as a bug in
 * the plugin rather than a note about the shift.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_messages = gwcvt_entry_problem_messages();

foreach ( array( 'hours', 'future-date', 'volunteer' ) as $gwcvt_key ) {
	gwcvt_ee_check(
		'"' . $gwcvt_key . '" has wording',
		isset( $gwcvt_messages[ $gwcvt_key ] ) && '' !== trim( $gwcvt_messages[ $gwcvt_key ] )
	);
}

/* ── Teardown ────────────────────────────────────────────────────────────── */

delete_transient( 'gwcvt_entry_saved_' . (int) $gwcvt_entry . '_' . get_current_user_id() );
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_restore );
gwcvt_settings_cache( null, true );

wp_delete_post( (int) $gwcvt_entry, true );
wp_delete_post( (int) $gwcvt_volunteer, true );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? 'ALL PASS' : $GLOBALS['gwcvt_failures'] . ' FAILED' ), "\n";

<?php
/**
 * Every event screen, rendered.
 *
 * ── Why this exists as well as tests/integration/events.php ──────────────────
 * That file asserts behaviour. This one asserts that the screens come up at all,
 * and it is here because of what the plugin's own notes say: almost every bug
 * found late in this build was found by a person looking at a screen, not by the
 * suite. A renderer that calls a function which only exists on admin requests,
 * or emits a deprecation on a newer PHP, is invisible to a test that never draws
 * anything.
 *
 * So each screen is rendered with an error handler in front of it, and ANY
 * notice, warning or deprecation is a failure. CI runs this on 7.4 and on 8.3,
 * which is what makes it worth the seconds it costs.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/event-screens.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_noise']    = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwcvt_sc_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Collect anything PHP wants to say, instead of printing it.
 *
 * @param int    $no   Error level.
 * @param string $str  Message.
 * @param string $file File.
 * @param int    $line Line.
 * @return bool
 */
function gwcvt_sc_note( $no, $str, $file = '', $line = 0 ) {
	$GLOBALS['gwcvt_noise'][] = $str . ' @ ' . basename( (string) $file ) . ':' . (int) $line;

	return true;
}

/**
 * Render one screen and fail on any noise it makes.
 *
 * @param string   $label What it is.
 * @param callable $draw  The renderer.
 * @return string The markup.
 */
function gwcvt_sc_render( string $label, callable $draw ): string {
	$before = count( $GLOBALS['gwcvt_noise'] );

	ob_start();

	try {
		$draw();
	} catch ( Throwable $e ) {
		$GLOBALS['gwcvt_noise'][] = 'threw: ' . $e->getMessage();
	}

	$html  = (string) ob_get_clean();
	$noise = array_slice( $GLOBALS['gwcvt_noise'], $before );

	gwcvt_sc_check(
		$label . ' renders without complaint',
		! $noise && '' !== $html,
		$noise ? implode( ' | ', array_slice( $noise, 0, 2 ) ) : strlen( $html ) . ' bytes'
	);

	return $html;
}

set_error_handler( 'gwcvt_sc_note', E_ALL );


/* ── The site's own settings are borrowed, never replaced ────────────────────
 * This script needs scheduling switched on. It used to get there by writing a
 * three-key settings array over whatever the site had, and putting the original
 * back at the end — which is fine until the script fails in the middle, and
 * then the site is left with three keys and a coordinator wondering why
 * scheduling turned itself off.
 *
 * Two changes. The overrides are MERGED over what is already stored, so even a
 * restore that never happens leaves every other setting intact. And the restore
 * is registered on shutdown, so it runs whether this script finishes, fails an
 * assertion, or fatals.
 *
 * The plugin's own notes name this: an integration script that wiped the site's
 * entire configuration on every run, invisible while the site was empty.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );

/**
 * Put the site's settings back exactly as they were found.
 */
function gwcvt_restore_settings(): void {
	if ( ! array_key_exists( 'gwcvt_settings_before', $GLOBALS ) ) {
		return;
	}

	if ( false === $GLOBALS['gwcvt_settings_before'] ) {
		delete_option( GWCVT_SETTINGS_OPTION );
	} else {
		update_option( GWCVT_SETTINGS_OPTION, $GLOBALS['gwcvt_settings_before'] );
	}

	unset( $GLOBALS['gwcvt_settings_before'] );

	if ( function_exists( 'gwcvt_settings_cache' ) ) {
		gwcvt_settings_cache( null, true );
	}
}

register_shutdown_function( 'gwcvt_restore_settings' );

/**
 * Switch on what this script needs, keeping everything else.
 *
 * @param array $overrides Settings to lay over the site's own.
 */
function gwcvt_borrow_settings( array $overrides ): void {
	update_option(
		GWCVT_SETTINGS_OPTION,
		array_merge( (array) get_option( GWCVT_SETTINGS_OPTION, array() ), $overrides )
	);

	gwcvt_settings_cache( null, true );
}

gwcvt_borrow_settings( array( 'shifts_enabled' => true, 'signup_enabled' => true, 'schedule_page' => 1 ) );

$gwcvt_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $gwcvt_admins ? (int) $gwcvt_admins[0]->ID : 1 );

/* ── A festival with somebody on it ──────────────────────────────────────── */

$gwcvt_when = gmdate( 'Y-m-d', time() + ( 5 * DAY_IN_SECONDS ) );

$gwcvt_event = wp_insert_post( array( 'post_type' => GWCVT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Fall Festival' ) );

update_post_meta( $gwcvt_event, GWCVT_EVENT_LOCATION, 'Riverside Park' );
update_post_meta( $gwcvt_event, GWCVT_EVENT_DESCRIPTION, "Our biggest day of the year.\n\nBring water." );

gwcvt_save_event_grid(
	$gwcvt_event,
	array(
		array(
			'name'  => 'Greeter',
			'notes' => 'Closed shoes, please.',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwcvt_when, 'start' => '09:00', 'end' => '12:00', 'min' => 2, 'max' => 3 ),
				array( 'id' => 0, 'date' => $gwcvt_when, 'start' => '13:00', 'end' => '15:00', 'min' => 2, 'max' => 3 ),
			),
		),
		array(
			'name'  => 'Kitchen',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwcvt_when, 'start' => '10:00', 'end' => '14:00', 'min' => 2, 'max' => 4 ),
			),
		),
	),
	array( 'status' => 'publish', 'notify' => false, 'reason' => '', 'location' => 'Riverside Park', 'super' => 'Marcus Webb' )
);

gwcvt_event_refresh_dates( $gwcvt_event );

$gwcvt_slots     = gwcvt_event_slot_ids( $gwcvt_event );
$gwcvt_volunteer = wp_insert_post( array( 'post_type' => GWCVT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Dana Whitfield' ) );

/* Two overlapping slots, so the roster has a clash to draw. */
gwcvt_add_signup( (int) $gwcvt_slots[0], array( 'volunteer_id' => $gwcvt_volunteer, 'source' => 'staff' ) );
gwcvt_add_signup( (int) $gwcvt_slots[1], array( 'volunteer_id' => $gwcvt_volunteer, 'source' => 'staff' ) );

/* ── The screens ─────────────────────────────────────────────────────────── */

gwcvt_sc_render( 'the events list', function () {
	gwcvt_render_events_list();
} );

gwcvt_sc_render( 'the flat schedule', function () {
	gwcvt_render_schedule_list();
} );

$gwcvt_editor = gwcvt_sc_render( 'the event editor', function () use ( $gwcvt_event ) {
	gwcvt_render_event_editor( $gwcvt_event );
} );

gwcvt_sc_render( 'a blank event editor', function () {
	gwcvt_render_event_editor( 0 );
} );

$gwcvt_roster = gwcvt_sc_render( 'the roster', function () use ( $gwcvt_event ) {
	gwcvt_render_event_roster( $gwcvt_event );
} );

gwcvt_sc_render( 'the printed roster', function () use ( $gwcvt_event ) {
	gwcvt_render_event_roster_document( $gwcvt_event );
} );

$gwcvt_grid = gwcvt_sc_render( 'the public grid', function () use ( $gwcvt_event ) {
	echo gwcvt_render_event_grid( $gwcvt_event );
} );

/* ── What the screens have to contain, and what they must not ────────────── */

gwcvt_sc_check(
	'the editor names the role once per role',
	1 === substr_count( $gwcvt_editor, 'value="Greeter"' ),
	(string) substr_count( $gwcvt_editor, 'value="Greeter"' )
);

gwcvt_sc_check(
	'the editor says what removing an occupied time will do',
	false !== strpos( $gwcvt_editor, 'Cancels it' )
);

gwcvt_sc_check(
	'the editor says what removing an empty time will do',
	false !== strpos( $gwcvt_editor, 'Deletes it' )
);

gwcvt_sc_check(
	'every grid field carries an explicit index',
	false === strpos( $gwcvt_editor, 'gwcvt_roles[]' )
);

gwcvt_sc_check( 'the roster names the volunteer', false !== strpos( $gwcvt_roster, 'Dana Whitfield' ) );
gwcvt_sc_check( 'the roster flags the double-booking', false !== strpos( $gwcvt_roster, 'overlap' ) );

gwcvt_sc_check(
	'the public grid ticks by shift ID',
	false !== strpos( $gwcvt_grid, 'gwcvt_slots[' . $gwcvt_slots[0] . ']' )
);

gwcvt_sc_check( 'the public grid groups by role', false !== strpos( $gwcvt_grid, '<legend>Greeter' ) );
gwcvt_sc_check( 'the public grid shows a count', false !== strpos( $gwcvt_grid, 'place' ) );

/* The one that matters most. Somebody is on two of these slots. */
gwcvt_sc_check( 'THE PUBLIC GRID NAMES NOBODY', false === strpos( $gwcvt_grid, 'Dana' ) );

/* ── Teardown ────────────────────────────────────────────────────────────── */

restore_error_handler();

foreach ( $gwcvt_slots as $gwcvt_slot ) {
	foreach ( gwcvt_shift_signup_ids( (int) $gwcvt_slot, array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ) ) as $gwcvt_signup ) {
		wp_delete_post( (int) $gwcvt_signup, true );
	}

	wp_delete_post( (int) $gwcvt_slot, true );
}

wp_delete_post( (int) $gwcvt_volunteer, true );
wp_delete_post( (int) $gwcvt_event, true );

/* Settings are put back by the shutdown handler registered above. */

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? 'ALL PASS' : $GLOBALS['gwcvt_failures'] . ' FAILED' ), "\n";

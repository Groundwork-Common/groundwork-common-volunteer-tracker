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
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_noise']    = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sc_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
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
function gwc_vt_sc_note( $no, $str, $file = '', $line = 0 ) {
	$GLOBALS['gwc_vt_noise'][] = $str . ' @ ' . basename( (string) $file ) . ':' . (int) $line;

	return true;
}

/**
 * Render one screen and fail on any noise it makes.
 *
 * @param string   $label What it is.
 * @param callable $draw  The renderer.
 * @return string The markup.
 */
function gwc_vt_sc_render( string $label, callable $draw ): string {
	$before = count( $GLOBALS['gwc_vt_noise'] );

	ob_start();

	try {
		$draw();
	} catch ( Throwable $e ) {
		$GLOBALS['gwc_vt_noise'][] = 'threw: ' . $e->getMessage();
	}

	$html  = (string) ob_get_clean();
	$noise = array_slice( $GLOBALS['gwc_vt_noise'], $before );

	gwc_vt_sc_check(
		$label . ' renders without complaint',
		! $noise && '' !== $html,
		$noise ? implode( ' | ', array_slice( $noise, 0, 2 ) ) : strlen( $html ) . ' bytes'
	);

	return $html;
}

set_error_handler( 'gwc_vt_sc_note', E_ALL );


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

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

/**
 * Put the site's settings back exactly as they were found.
 */
function gwc_vt_restore_settings(): void {
	if ( ! array_key_exists( 'gwc_vt_settings_before', $GLOBALS ) ) {
		return;
	}

	if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
		delete_option( GWC_VT_SETTINGS_OPTION );
	} else {
		update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
	}

	unset( $GLOBALS['gwc_vt_settings_before'] );

	if ( function_exists( 'gwc_vt_settings_cache' ) ) {
		gwc_vt_settings_cache( null, true );
	}
}

register_shutdown_function( 'gwc_vt_restore_settings' );

/**
 * Switch on what this script needs, keeping everything else.
 *
 * @param array $overrides Settings to lay over the site's own.
 */
function gwc_vt_borrow_settings( array $overrides ): void {
	update_option(
		GWC_VT_SETTINGS_OPTION,
		array_merge( (array) get_option( GWC_VT_SETTINGS_OPTION, array() ), $overrides )
	);

	gwc_vt_settings_cache( null, true );
}

gwc_vt_borrow_settings( array( 'shifts_enabled' => true, 'signup_enabled' => true, 'schedule_page' => 1 ) );

$gwc_vt_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $gwc_vt_admins ? (int) $gwc_vt_admins[0]->ID : 1 );

/* ── A festival with somebody on it ──────────────────────────────────────── */

$gwc_vt_when = gmdate( 'Y-m-d', time() + ( 5 * DAY_IN_SECONDS ) );

$gwc_vt_event = wp_insert_post( array( 'post_type' => GWC_VT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Fall Festival' ) );

update_post_meta( $gwc_vt_event, GWC_VT_EVENT_LOCATION, 'Riverside Park' );
update_post_meta( $gwc_vt_event, GWC_VT_EVENT_DESCRIPTION, "Our biggest day of the year.\n\nBring water." );

gwc_vt_save_event_grid(
	$gwc_vt_event,
	array(
		array(
			'name'  => 'Greeter',
			'notes' => 'Closed shoes, please.',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwc_vt_when, 'start' => '09:00', 'end' => '12:00', 'min' => 2, 'max' => 3 ),
				array( 'id' => 0, 'date' => $gwc_vt_when, 'start' => '13:00', 'end' => '15:00', 'min' => 2, 'max' => 3 ),
			),
		),
		array(
			'name'  => 'Kitchen',
			'slots' => array(
				array( 'id' => 0, 'date' => $gwc_vt_when, 'start' => '10:00', 'end' => '14:00', 'min' => 2, 'max' => 4 ),
			),
		),
	),
	array( 'status' => 'publish', 'notify' => false, 'reason' => '', 'location' => 'Riverside Park', 'super' => 'Marcus Webb' )
);

gwc_vt_event_refresh_dates( $gwc_vt_event );

$gwc_vt_slots     = gwc_vt_event_slot_ids( $gwc_vt_event );
$gwc_vt_volunteer = wp_insert_post( array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Dana Whitfield' ) );

/* Two overlapping slots, so the roster has a clash to draw. */
gwc_vt_add_signup( (int) $gwc_vt_slots[0], array( 'volunteer_id' => $gwc_vt_volunteer, 'source' => 'staff' ) );
gwc_vt_add_signup( (int) $gwc_vt_slots[1], array( 'volunteer_id' => $gwc_vt_volunteer, 'source' => 'staff' ) );

/* ── The screens ─────────────────────────────────────────────────────────── */

gwc_vt_sc_render( 'the events list', function () {
	gwc_vt_render_events_list();
} );

gwc_vt_sc_render( 'the flat schedule', function () {
	gwc_vt_render_schedule_list();
} );

$gwc_vt_editor = gwc_vt_sc_render( 'the event editor', function () use ( $gwc_vt_event ) {
	gwc_vt_render_event_editor( $gwc_vt_event );
} );

gwc_vt_sc_render( 'a blank event editor', function () {
	gwc_vt_render_event_editor( 0 );
} );

$gwc_vt_roster = gwc_vt_sc_render( 'the roster', function () use ( $gwc_vt_event ) {
	gwc_vt_render_event_roster( $gwc_vt_event );
} );

gwc_vt_sc_render( 'the printed roster', function () use ( $gwc_vt_event ) {
	gwc_vt_render_event_roster_document( $gwc_vt_event );
} );

$gwc_vt_grid = gwc_vt_sc_render( 'the public grid', function () use ( $gwc_vt_event ) {
	echo gwc_vt_render_event_grid( $gwc_vt_event );
} );

/* The two screens that stop to ask. */
$gwc_vt_ask_slot = gwc_vt_sc_render( 'the call-off confirmation', function () use ( $gwc_vt_slots ) {
	gwc_vt_render_call_off_slot( (int) $gwc_vt_slots[0] );
} );

$gwc_vt_ask_role = gwc_vt_sc_render( 'the drop-role confirmation', function () use ( $gwc_vt_event ) {
	gwc_vt_render_drop_role( $gwc_vt_event, 'Greeter' );
} );

gwc_vt_sc_check( 'calling off asks for a reason', false !== strpos( $gwc_vt_ask_slot, 'gwc_vt_reason' ) );
gwc_vt_sc_check( 'and offers a way out', false !== strpos( $gwc_vt_ask_slot, 'Leave it alone' ) );
gwc_vt_sc_check( 'and says the time is kept rather than deleted', false !== strpos( $gwc_vt_ask_slot, 'rather than being deleted' ) );
gwc_vt_sc_check( 'dropping a role lists what is kept and what goes', false !== strpos( $gwc_vt_ask_role, 'Called off, and kept' ) );

/* ── What the screens have to contain, and what they must not ────────────── */

gwc_vt_sc_check(
	'the editor names the role once per role',
	1 === substr_count( $gwc_vt_editor, 'value="Greeter"' ),
	(string) substr_count( $gwc_vt_editor, 'value="Greeter"' )
);

/* Lifecycle is an action per row, not a field on the form. An occupied time
 * offers to be called off — a screen that asks first, because it needs a reason
 * and decides whether people get an email. An empty one offers a plain delete. */
gwc_vt_sc_check(
	'an occupied time offers to be called off',
	false !== strpos( $gwc_vt_editor, 'Call it off' )
);

gwc_vt_sc_check(
	'an empty time offers a plain delete',
	false !== strpos( $gwc_vt_editor, 'gwc_vt_delete_slot' )
);

gwc_vt_sc_check(
	'the form itself carries no lifecycle fields',
	false === strpos( $gwc_vt_editor, '[remove]' ) && false === strpos( $gwc_vt_editor, '[restore]' )
);

gwc_vt_sc_check(
	'every grid field carries an explicit index',
	false === strpos( $gwc_vt_editor, 'gwc_vt_roles[]' )
);

gwc_vt_sc_check( 'the roster names the volunteer', false !== strpos( $gwc_vt_roster, 'Dana Whitfield' ) );
gwc_vt_sc_check( 'the roster flags the double-booking', false !== strpos( $gwc_vt_roster, 'overlap' ) );

gwc_vt_sc_check(
	'the public grid ticks by shift ID',
	false !== strpos( $gwc_vt_grid, 'gwc_vt_slots[' . $gwc_vt_slots[0] . ']' )
);

gwc_vt_sc_check( 'the public grid groups by role', false !== strpos( $gwc_vt_grid, '<legend>Greeter' ) );
gwc_vt_sc_check( 'the public grid shows a count', false !== strpos( $gwc_vt_grid, 'place' ) );

/* The one that matters most. Somebody is on two of these slots. */
gwc_vt_sc_check( 'THE PUBLIC GRID NAMES NOBODY', false === strpos( $gwc_vt_grid, 'Dana' ) );

/* ── Teardown ────────────────────────────────────────────────────────────── */

restore_error_handler();

foreach ( $gwc_vt_slots as $gwc_vt_slot ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) as $gwc_vt_signup ) {
		wp_delete_post( (int) $gwc_vt_signup, true );
	}

	wp_delete_post( (int) $gwc_vt_slot, true );
}

wp_delete_post( (int) $gwc_vt_volunteer, true );
wp_delete_post( (int) $gwc_vt_event, true );

/* Settings are put back by the shutdown handler registered above. */

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

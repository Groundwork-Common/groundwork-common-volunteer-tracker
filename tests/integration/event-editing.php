<?php
/**
 * What a coordinator actually does to an event, done the way they do it.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * tests/integration/events.php calls gwc_vt_save_event_grid() directly. That
 * proved the model was right and missed the bug that mattered: cancelling a time
 * worked perfectly and the screen came back looking untouched, so a coordinator
 * selected the checkbox, pressed Save, saw the same editable row with the same Remove
 * box still on it, and reasonably concluded the feature was broken.
 *
 * A test that calls the function under the form can never catch that. So this
 * file drives the real handlers through $_POST and $_GET exactly as the browser
 * fills them, and after each one asserts BOTH what is in the database AND what
 * the next screen says — because a state nothing on the screen agrees with is
 * worse than a state that never changed.
 *
 * The four lifecycle operations have since moved off the form into actions of
 * their own, which is what that bug was really telling us. The assertions came
 * with them: what used to be "select a checkbox and save" is now "follow the link and
 * confirm", and the things worth pinning are unchanged.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/event-editing.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_mail']     = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_ed_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Catch mail instead of sending it.
 *
 * @param null  $short Whatever an earlier filter decided.
 * @param array $atts  to, subject, message, headers, attachments.
 * @return bool
 */
function gwc_vt_ed_catch_mail( $short, $atts ) {
	$GLOBALS['gwc_vt_mail'][] = $atts;

	return true;
}

/**
 * Turn a redirect into something a script can read.
 *
 * The handler ends every path in wp_safe_redirect() and exit. Throwing from the
 * filter is what lets the test see where it was sending the coordinator, which
 * is half of what the coordinator experiences.
 *
 * @param string $location Where it wanted to go.
 * @throws Exception Always.
 */
function gwc_vt_ed_catch_redirect( $location ) {
	throw new Exception( (string) $location );
}

/**
 * Press Save on the event editor.
 *
 * @param int   $event_id The event.
 * @param array $roles    The gwc_vt_roles structure the form would post.
 * @param array $extra    Anything else on the form.
 * @return array The query arguments the coordinator lands on.
 */
function gwc_vt_ed_save( int $event_id, array $roles, array $extra = array() ): array {
	$_POST = array_merge(
		array(
			'action'          => 'gwc_vt_save_event',
			'gwc_vt_event'     => (string) $event_id,
			'_wpnonce'        => wp_create_nonce( 'gwc_vt_save_event_' . $event_id ),
			'gwc_vt_title'     => 'Fall Festival',
			'gwc_vt_published' => '1',
			'gwc_vt_roles'     => $roles,
		),
		$extra
	);

	$_REQUEST = $_POST;
	$landed   = '';

	try {
		gwc_vt_handle_save_event();
	} catch ( Throwable $e ) {
		$landed = $e->getMessage();
	}

	$_POST    = array();
	$_REQUEST = array();

	$query = (string) wp_parse_url( $landed, PHP_URL_QUERY );
	$args  = array();

	parse_str( $query, $args );

	return $args;
}

/**
 * The editor, as the coordinator sees it after a save.
 *
 * @param int $event_id The event.
 * @return string
 */
function gwc_vt_ed_screen( int $event_id ): string {
	ob_start();
	gwc_vt_render_event_editor( $event_id );

	return (string) ob_get_clean();
}

/**
 * One row of the grid, as text, for the time given.
 *
 * @param string $html     The editor.
 * @param int    $shift_id Which time.
 * @return string
 */
function gwc_vt_ed_row( string $html, int $shift_id ): string {
	preg_match_all( '#<tr[^>]*>.*?</tr>#s', $html, $rows );

	foreach ( $rows[0] as $row ) {
		if ( false !== strpos( $row, 'value="' . $shift_id . '"' ) ) {
			return $row;
		}
	}

	return '';
}

/**
 * A slot description for the posted grid.
 *
 * @param int    $id    Existing shift, or 0.
 * @param string $date  Y-m-d.
 * @param string $start H:i.
 * @param string $end   H:i.
 * @param array  $extra remove, restore.
 * @return array
 */
function gwc_vt_ed_slot( int $id, string $date, string $start, string $end, array $extra = array() ): array {
	return array_merge(
		array(
			'id'    => (string) $id,
			'date'  => $date,
			'start' => $start,
			'end'   => $end,
			'min'   => '2',
			'max'   => '4',
		),
		$extra
	);
}

/**
 * Follow a nonced action link, the way clicking it does.
 *
 * @param string $action   Which admin_post action.
 * @param int    $shift_id Shift post ID.
 * @param string $handler  The function the action is wired to.
 * @return array The query arguments the coordinator lands on.
 */
function gwc_vt_ed_click( string $action, int $shift_id, string $handler ): array {
	$_GET = array(
		'action'     => $action,
		'gwc_vt_slot' => (string) $shift_id,
		'_wpnonce'   => wp_create_nonce( $action . '_' . $shift_id ),
	);

	$_REQUEST = $_GET;
	$landed   = '';

	try {
		$handler();
	} catch ( Throwable $e ) {
		$landed = $e->getMessage();
	}

	$_GET     = array();
	$_REQUEST = array();

	$args = array();
	parse_str( (string) wp_parse_url( $landed, PHP_URL_QUERY ), $args );

	return $args;
}

/**
 * Confirm on one of the two screens that stop to ask.
 *
 * @param string $action Which admin_post action.
 * @param array  $post   What the confirmation form sends.
 * @param string $nonce  The nonce action.
 * @return array The query arguments the coordinator lands on.
 */
function gwc_vt_ed_confirm( string $action, array $post, string $nonce ): array {
	$_POST = array_merge( array( 'action' => $action, '_wpnonce' => wp_create_nonce( $nonce ) ), $post );

	$_REQUEST = $_POST;
	$landed   = '';

	try {
		call_user_func( 'gwc_vt_handle_' . substr( $action, strlen( 'gwc_vt_' ) ) );
	} catch ( Throwable $e ) {
		$landed = $e->getMessage();
	}

	$_POST    = array();
	$_REQUEST = array();

	$args = array();
	parse_str( (string) wp_parse_url( $landed, PHP_URL_QUERY ), $args );

	return $args;
}

add_filter( 'pre_wp_mail', 'gwc_vt_ed_catch_mail', 10, 2 );
add_filter( 'wp_redirect', 'gwc_vt_ed_catch_redirect', 1 );


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

$gwc_vt_when  = gmdate( 'Y-m-d', time() + ( 6 * DAY_IN_SECONDS ) );
$gwc_vt_event = wp_insert_post( array( 'post_type' => GWC_VT_EVENT_TYPE, 'post_status' => 'publish', 'post_title' => 'Fall Festival' ) );

/* ── Building the day ────────────────────────────────────────────────────── */

gwc_vt_ed_save(
	$gwc_vt_event,
	array(
		'0' => array(
			'name'  => 'Greeter',
			'slots' => array(
				'0' => gwc_vt_ed_slot( 0, $gwc_vt_when, '09:00', '12:00' ),
				'1' => gwc_vt_ed_slot( 0, $gwc_vt_when, '13:00', '15:00' ),
			),
		),
		'1' => array(
			'name'  => 'Kitchen',
			'slots' => array( '0' => gwc_vt_ed_slot( 0, $gwc_vt_when, '10:00', '14:00' ) ),
		),
		'2' => array(
			'name'  => 'Set-up',
			'slots' => array( '0' => gwc_vt_ed_slot( 0, $gwc_vt_when, '07:30', '09:00' ) ),
		),
		'3' => array( 'name' => '', 'slots' => array( '0' => gwc_vt_ed_slot( 0, '', '', '' ) ) ),
	)
);

$gwc_vt_slots = gwc_vt_event_slot_ids( $gwc_vt_event );

gwc_vt_ed_check( 'building a day from the blank grid makes four times', 4 === count( $gwc_vt_slots ), (string) count( $gwc_vt_slots ) );
gwc_vt_ed_check( 'the spare blank role made nothing', 3 === count( gwc_vt_event_roles( $gwc_vt_event ) ), (string) count( gwc_vt_event_roles( $gwc_vt_event ) ) );

/* Slots come back in time order: set-up 07:30, greeter 09:00, kitchen 10:00,
 * greeter 13:00. */
$gwc_vt_roles_now = gwc_vt_event_roles( $gwc_vt_event );
$gwc_vt_busy      = (int) $gwc_vt_roles_now['Greeter'][0];
$gwc_vt_empty     = (int) $gwc_vt_roles_now['Set-up'][0];

$gwc_vt_vol = wp_insert_post( array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Dana Whitfield' ) );

/* With an address. A volunteer record without one cannot be told anything, and
 * a test that forgets it asserts that mail was not sent for the wrong reason. */
update_post_meta( $gwc_vt_vol, GWC_VT_VOLUNTEER_EMAIL, 'dana@example.org' );

gwc_vt_add_signup( $gwc_vt_busy, array( 'volunteer_id' => $gwc_vt_vol, 'source' => 'staff' ) );

/* ── Calling off a time that people are on ───────────────────────────────── */

$GLOBALS['gwc_vt_mail'] = array();

$gwc_vt_landed = gwc_vt_ed_confirm(
	'gwc_vt_call_off_slot',
	array(
		'gwc_vt_slot'   => (string) $gwc_vt_busy,
		'gwc_vt_reason' => 'We have enough greeters',
		'gwc_vt_notify' => '1',
	),
	'gwc_vt_call_off_slot_' . $gwc_vt_busy
);

do_action( 'shutdown' );

gwc_vt_ed_check( 'the time is cancelled', GWC_VT_SHIFT_CANCELLED === get_post_status( $gwc_vt_busy ), (string) get_post_status( $gwc_vt_busy ) );
gwc_vt_ed_check( 'not deleted — the row survives', null !== get_post( $gwc_vt_busy ) );
gwc_vt_ed_check( 'its roster survives', 1 === count( gwc_vt_shift_signup_ids( $gwc_vt_busy, array( 'publish' ) ) ) );
gwc_vt_ed_check( 'the person on it was told', 1 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );
gwc_vt_ed_check( 'the notice names what it did', 'called-off-slot' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );
gwc_vt_ed_check( 'and names which time', (string) $gwc_vt_busy === (string) ( $gwc_vt_landed['gwc_vt_slot'] ?? '' ) );

/* THE BUG. Everything above passed before this file existed. */

$gwc_vt_screen = gwc_vt_ed_screen( $gwc_vt_event );

/* ── And the control that removes the whole role ─────────────────────────────
 * It renders only when the role still has a live time, which is right — a role
 * whose times have all been called off has nothing that can be removed, because
 * a cancelled time is kept on purpose.
 *
 * What was wrong was saying nothing in that case. An absent control and a
 * control that has not loaded look identical, so somebody who had just
 * cancelled a role's only time went hunting for a feature that was working
 * exactly as intended. */

gwc_vt_ed_check(
	'a role with a live time offers to remove the whole role',
	false !== strpos( $gwc_vt_screen, 'Remove this whole role' )
);

gwc_vt_ed_check(
	'the removal control sits with the role name, not four fields down',
	strpos( $gwc_vt_screen, 'view=drop-role' ) < strpos( $gwc_vt_screen, 'gwc_vt_roles[0][supervisor]' )
);

/* Lifecycle is gone from the form entirely. A save can no longer cancel, delete
 * or restore anything, whatever is posted to it. */
gwc_vt_ed_check( 'the form carries no removal field', false === strpos( $gwc_vt_screen, '[remove]' ) );
gwc_vt_ed_check( 'the form carries no restore field', false === strpos( $gwc_vt_screen, '[restore]' ) );

gwc_vt_ed_check(
	'it no longer has to predict a save',
	false === strpos( $gwc_vt_screen, 'when you save' )
);
$gwc_vt_row    = gwc_vt_ed_row( $gwc_vt_screen, $gwc_vt_busy );

gwc_vt_ed_check( 'the cancelled row is still on the screen', '' !== $gwc_vt_row );
gwc_vt_ed_check( 'it says it was called off', false !== strpos( $gwc_vt_row, 'Called off' ) );
gwc_vt_ed_check( 'it is struck through', false !== strpos( $gwc_vt_row, '<s>' ) );
gwc_vt_ed_check( 'it shows why', false !== strpos( $gwc_vt_row, 'We have enough greeters' ) );
gwc_vt_ed_check( 'it no longer offers to cancel it again', false === strpos( $gwc_vt_row, 'view=call-off' ) );
gwc_vt_ed_check( 'it offers to put it back on', false !== strpos( $gwc_vt_row, 'gwc_vt_restore_slot' ) );
gwc_vt_ed_check( 'its times are no longer editable', false === strpos( $gwc_vt_row, 'type="time"' ) );
gwc_vt_ed_check( 'it says how many people were on it', false !== strpos( $gwc_vt_row, 'was on it' ) );

/* ── Saving again must not quietly un-cancel it ──────────────────────────── */

gwc_vt_ed_save(
	$gwc_vt_event,
	array(
		'0' => array(
			'name'  => 'Greeter',
			'slots' => array(
				'0' => array( 'id' => (string) $gwc_vt_busy, 'date' => $gwc_vt_when, 'start' => '09:00', 'end' => '12:00', 'min' => '2', 'max' => '4' ),
				'1' => gwc_vt_ed_slot( (int) $gwc_vt_slots[1], $gwc_vt_when, '13:00', '15:00' ),
			),
		),
	)
);

gwc_vt_ed_check( 'a later save leaves it cancelled', GWC_VT_SHIFT_CANCELLED === get_post_status( $gwc_vt_busy ), (string) get_post_status( $gwc_vt_busy ) );

/* ── Putting it back on ──────────────────────────────────────────────────── */

$gwc_vt_landed = gwc_vt_ed_click( 'gwc_vt_restore_slot', $gwc_vt_busy, 'gwc_vt_handle_restore_slot' );

gwc_vt_ed_check( 'putting it back on republishes it', 'publish' === get_post_status( $gwc_vt_busy ), (string) get_post_status( $gwc_vt_busy ) );
gwc_vt_ed_check( 'the notice names it', 'restored-slot' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );
gwc_vt_ed_check( 'and its times are editable again', false !== strpos( gwc_vt_ed_row( gwc_vt_ed_screen( $gwc_vt_event ), $gwc_vt_busy ), 'type="time"' ) );

/* ── Deleting a time nobody is on ────────────────────────────────────────── */

$gwc_vt_landed = gwc_vt_ed_click( 'gwc_vt_delete_slot', $gwc_vt_empty, 'gwc_vt_handle_delete_slot' );

gwc_vt_ed_check( 'an empty time is deleted outright', null === get_post( $gwc_vt_empty ) );
gwc_vt_ed_check( 'the notice says deleted', 'deleted-slot' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );

/* And a time somebody IS on cannot be deleted by reaching the link directly. */
$gwc_vt_landed = gwc_vt_ed_click( 'gwc_vt_delete_slot', $gwc_vt_busy, 'gwc_vt_handle_delete_slot' );

gwc_vt_ed_check( 'a time with people on it refuses to be deleted', null !== get_post( $gwc_vt_busy ) );
gwc_vt_ed_check( 'and says why', 'has-roster' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );

/* ── Removing a whole role ───────────────────────────────────────────────── */

$gwc_vt_role = gwc_vt_event_roles( $gwc_vt_event );
$gwc_vt_kept = isset( $gwc_vt_role['Greeter'] ) ? $gwc_vt_role['Greeter'] : array();

gwc_vt_ed_check( 'the greeter role has two times before removing it', 2 === count( $gwc_vt_kept ), (string) count( $gwc_vt_kept ) );

$GLOBALS['gwc_vt_mail'] = array();

/* The screen that asks first has to list what goes and what merely stops. */
ob_start();
gwc_vt_render_drop_role( $gwc_vt_event, 'Greeter' );
$gwc_vt_asks = (string) ob_get_clean();

gwc_vt_ed_check( 'the confirm screen separates what is kept from what is deleted', false !== strpos( $gwc_vt_asks, 'Called off, and kept' ) && false !== strpos( $gwc_vt_asks, 'Deleted' ) );

$gwc_vt_landed = gwc_vt_ed_confirm(
	'gwc_vt_drop_role',
	array(
		'gwc_vt_event'  => (string) $gwc_vt_event,
		'gwc_vt_role'   => 'Greeter',
		'gwc_vt_reason' => 'Not greeting this year',
		'gwc_vt_notify' => '1',
	),
	'gwc_vt_drop_role_' . $gwc_vt_event
);

do_action( 'shutdown' );

gwc_vt_ed_check(
	'removing the role takes every one of its times',
	1 === (int) ( $gwc_vt_landed['gwc_vt_cancelled'] ?? 0 ) && 1 === (int) ( $gwc_vt_landed['gwc_vt_deleted'] ?? 0 ),
	'cancelled=' . (string) ( $gwc_vt_landed['gwc_vt_cancelled'] ?? '0' ) . ' deleted=' . (string) ( $gwc_vt_landed['gwc_vt_deleted'] ?? '0' )
);

gwc_vt_ed_check( 'the busy one is cancelled rather than deleted', GWC_VT_SHIFT_CANCELLED === get_post_status( $gwc_vt_kept[0] ) );
gwc_vt_ed_check( 'the empty one is gone', null === get_post( $gwc_vt_kept[1] ) );
gwc_vt_ed_check( 'the person on it was told once', 1 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );

$gwc_vt_screen = gwc_vt_ed_screen( $gwc_vt_event );
gwc_vt_ed_check( 'the role is no longer offered for removal', 1 === substr_count( $gwc_vt_screen, 'Remove this whole role' ), (string) substr_count( $gwc_vt_screen, 'Remove this whole role' ) );
gwc_vt_ed_check( 'the notice says the role is gone', 'dropped-role' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );

/* ── A role whose times have all been called off ─────────────────────────── */

$gwc_vt_all_off = gwc_vt_ed_screen( $gwc_vt_event );
$gwc_vt_greeter = strpos( $gwc_vt_all_off, 'value="Greeter"' );

gwc_vt_ed_check( 'the all-cancelled role is still on the screen', false !== $gwc_vt_greeter );

gwc_vt_ed_check(
	'it explains why it offers nothing to remove',
	false !== strpos( $gwc_vt_all_off, 'Every time in this role has been called off' )
);

/* ── Things that must not mail anybody ───────────────────────────────────── */

$gwc_vt_left = gwc_vt_event_roles( $gwc_vt_event );

gwc_vt_ed_check( 'kitchen is what is left', isset( $gwc_vt_left['Kitchen'] ), implode( ', ', array_keys( $gwc_vt_left ) ) );

$gwc_vt_one = (int) $gwc_vt_left['Kitchen'][0];

$GLOBALS['gwc_vt_mail'] = array();
gwc_vt_add_signup( $gwc_vt_one, array( 'volunteer_id' => $gwc_vt_vol, 'source' => 'staff' ) );

gwc_vt_ed_save(
	$gwc_vt_event,
	array(
		'0' => array(
			'name'  => 'Kitchen crew',
			'notes' => 'Closed shoes.',
			'slots' => array( '0' => array( 'id' => (string) $gwc_vt_one, 'date' => $gwc_vt_when, 'start' => '10:00', 'end' => '14:00', 'min' => '2', 'max' => '9' ) ),
		),
	),
	array( 'gwc_vt_notify' => '1' )
);

do_action( 'shutdown' );

gwc_vt_ed_check( 'renaming a role and widening a maximum mails nobody', 0 === count( $GLOBALS['gwc_vt_mail'] ), (string) count( $GLOBALS['gwc_vt_mail'] ) );
gwc_vt_ed_check( 'the rename reached the time', 'Kitchen crew' === (string) get_post_meta( $gwc_vt_one, GWC_VT_SHIFT_ACTIVITY, true ) );
gwc_vt_ed_check( 'the role note reached the time', 'Closed shoes.' === (string) get_post_meta( $gwc_vt_one, GWC_VT_SHIFT_NOTES, true ) );

/* ── Emptying a role's name is refused, and changes nothing ──────────────── */

$gwc_vt_landed = gwc_vt_ed_save(
	$gwc_vt_event,
	array(
		'0' => array(
			'name'  => '',
			'slots' => array( '0' => array( 'id' => (string) $gwc_vt_one, 'date' => $gwc_vt_when, 'start' => '08:00', 'end' => '09:00', 'min' => '2', 'max' => '9' ) ),
		),
	)
);

gwc_vt_ed_check( 'emptying a role name is refused', 'no-role' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );
gwc_vt_ed_check( 'and the time it had is untouched', '10:00' === (string) get_post_meta( $gwc_vt_one, GWC_VT_SHIFT_START, true ), (string) get_post_meta( $gwc_vt_one, GWC_VT_SHIFT_START, true ) );

/* ── A time that cannot be read is refused ───────────────────────────────── */

$gwc_vt_landed = gwc_vt_ed_save(
	$gwc_vt_event,
	array(
		'0' => array(
			'name'  => 'Kitchen crew',
			'slots' => array( '0' => array( 'id' => '0', 'date' => $gwc_vt_when, 'start' => '15:00', 'end' => '09:00' ) ),
		),
	)
);

gwc_vt_ed_check( 'an end before its start is refused', 'bad-time' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );

/* ── An event with no name is refused ────────────────────────────────────── */

$gwc_vt_landed = gwc_vt_ed_save( $gwc_vt_event, array(), array( 'gwc_vt_title' => '' ) );
gwc_vt_ed_check( 'an event with no name is refused', 'no-title' === (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '' ), (string) ( $gwc_vt_landed['gwc_vt_event_result'] ?? '-' ) );

/* ── Teardown ────────────────────────────────────────────────────────────── */

remove_filter( 'wp_redirect', 'gwc_vt_ed_catch_redirect', 1 );
remove_filter( 'pre_wp_mail', 'gwc_vt_ed_catch_mail', 10 );

foreach ( gwc_vt_event_slot_ids( $gwc_vt_event, array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ) ) as $gwc_vt_slot ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_slot, array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ) ) as $gwc_vt_signup ) {
		wp_delete_post( (int) $gwc_vt_signup, true );
	}

	wp_delete_post( (int) $gwc_vt_slot, true );
}

wp_delete_post( (int) $gwc_vt_vol, true );
wp_delete_post( (int) $gwc_vt_event, true );

/* Settings are put back by the shutdown handler registered above. */

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

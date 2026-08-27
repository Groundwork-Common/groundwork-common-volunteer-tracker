<?php
/**
 * Every event screen, rendered.
 *
 * ── Why this exists as well as tests/integration/events.php ──────────────────
 * That file asserts behavior. This one asserts that the screens come up at all,
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
 *   bin/wpenv run cli -- wp eval-file \
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
	'the public grid selects by shift ID',
	false !== strpos( $gwc_vt_grid, 'gwc_vt_slots[' . $gwc_vt_slots[0] . ']' )
);

gwc_vt_sc_check( 'the public grid groups by role', false !== strpos( $gwc_vt_grid, '<legend>Greeter' ) );
gwc_vt_sc_check( 'the public grid shows a count', false !== strpos( $gwc_vt_grid, 'place' ) );

/* The one that matters most. Somebody is on two of these slots. */
gwc_vt_sc_check( 'THE PUBLIC GRID NAMES NOBODY', false === strpos( $gwc_vt_grid, 'Dana' ) );

/* ── Every sub-view of the schedule behaves the same way ─────────────────────
 * These screens grew one at a time and stopped agreeing about the things a
 * screen does rather than shows: where the way out is, where a notice lands,
 * and whether anything is said at all. The worst of it was silent — the event
 * editor and the roster are where every event redirect ARRIVES, and neither
 * printed the result it arrived with, so saving an event said nothing and a
 * refused roster addition looked like a button that does nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

echo "\n── The sub-views agree ──────────────────────────────────────────\n";

$GLOBALS['gwc_vt_sc_views'] = array(
	'the schedule'     => array( 'html' => gwc_vt_sc_render( 'the schedule, again', function () {
		gwc_vt_render_schedule_list();
	} ), 'back' => false ),
	'the month'        => array( 'html' => gwc_vt_sc_render( 'the month', function () {
		gwc_vt_render_schedule_month();
	} ), 'back' => false ),
	'the events list'  => array( 'html' => gwc_vt_sc_render( 'the events list, again', function () {
		gwc_vt_render_events_list();
	} ), 'back' => false ),
	'the event editor' => array( 'html' => $gwc_vt_editor, 'back' => true ),
	'the roster'       => array( 'html' => $gwc_vt_roster, 'back' => true ),
	'the call-off'     => array( 'html' => $gwc_vt_ask_slot, 'back' => true ),
	'the drop-role'    => array( 'html' => $gwc_vt_ask_role, 'back' => true ),
	'the shift editor' => array( 'html' => gwc_vt_sc_render( 'the shift editor', function () use ( $gwc_vt_slots ) {
		gwc_vt_render_shift_editor( (int) $gwc_vt_slots[0] );
	} ), 'back' => true ),
);

foreach ( $GLOBALS['gwc_vt_sc_views'] as $gwc_vt_sc_what => $gwc_vt_sc_view ) {
	/* Core moves every notice to just after this, so a screen without one puts
	 * its messages somewhere else than the screen beside it does. */
	gwc_vt_sc_check(
		$gwc_vt_sc_what . ' marks where its header ends, once',
		1 === substr_count( (string) $gwc_vt_sc_view['html'], 'wp-header-end' ),
		substr_count( (string) $gwc_vt_sc_view['html'], 'wp-header-end' ) . ' marker(s)'
	);

	if ( ! $gwc_vt_sc_view['back'] ) {
		continue;
	}

	gwc_vt_sc_check(
		$gwc_vt_sc_what . ' offers the way back, in the one shape',
		1 === substr_count( (string) $gwc_vt_sc_view['html'], 'class="gwcvt-back"' ),
		substr_count( (string) $gwc_vt_sc_view['html'], 'class="gwcvt-back"' ) . ' link(s)'
	);
}

/* The three lists offer the same three lists. The events view used to offer
 * "Coming up" and itself, so arriving there took the past and the calendar off
 * the screen, with the browser's own back button as the only way to either. */
$GLOBALS['gwc_vt_sc_bars'] = array();

foreach ( array( 'the schedule', 'the month', 'the events list' ) as $gwc_vt_sc_list ) {
	preg_match(
		'~<ul class="subsubsub">.*?</ul>~s',
		(string) $GLOBALS['gwc_vt_sc_views'][ $gwc_vt_sc_list ]['html'],
		$gwc_vt_sc_hit
	);

	/* Compared on the links themselves, not the markup: which one is marked
	 * current is the only difference the three are allowed. Whitespace is
	 * collapsed after the attribute goes, because the view that marks nothing
	 * still prints the space the attribute would have sat in. */
	$GLOBALS['gwc_vt_sc_bars'][ $gwc_vt_sc_list ] = trim(
		(string) preg_replace(
			'~\s+~',
			' ',
			(string) preg_replace(
				'~class="current" aria-current="page"~',
				'',
				(string) ( $gwc_vt_sc_hit[0] ?? '' )
			)
		)
	);
}

gwc_vt_sc_check(
	'all three lists carry the same three links',
	1 === count( array_unique( $GLOBALS['gwc_vt_sc_bars'] ) )
		&& '' !== reset( $GLOBALS['gwc_vt_sc_bars'] ),
	implode( ' / ', array_map( 'strlen', $GLOBALS['gwc_vt_sc_bars'] ) ) . ' bytes'
);

/* Every list offers the calendar, and offers it in the same place: the right of
 * the tablenav, beside the count, where core keeps the Media library's own
 * list-or-grid switch. The events list had no toggle at all, so the calendar
 * was unreachable from it without going back to the schedule first. */
foreach ( array( 'the schedule', 'the month', 'the events list' ) as $gwc_vt_sc_list ) {
	$gwc_vt_sc_html = (string) $GLOBALS['gwc_vt_sc_views'][ $gwc_vt_sc_list ]['html'];

	gwc_vt_sc_check(
		$gwc_vt_sc_list . ' offers the list/calendar toggle',
		1 === substr_count( $gwc_vt_sc_html, 'gwcvt-schedule__views' ),
		substr_count( $gwc_vt_sc_html, 'gwcvt-schedule__views' ) . ' toggle(s)'
	);

	gwc_vt_sc_check(
		'and puts it in the tablenav, on the right with the count',
		strpos( $gwc_vt_sc_html, 'gwcvt-schedule__views' ) > strpos( $gwc_vt_sc_html, 'tablenav top' )
			&& strpos( $gwc_vt_sc_html, 'gwcvt-schedule__views' ) < strpos( $gwc_vt_sc_html, 'displaying-num' )
	);

	/* And the header block above it is the one core builds on every list: the
	 * view links, then the search floated right, then the tablenav. */
	/* The rows the block is made of, in core's order. The table is checked only
	 * where there is one: the month draws a calendar of divs, and the header
	 * above it is the same header. */
	$gwc_vt_sc_order = array( 'subsubsub', 'class="search-box"', 'tablenav top' );

	if ( false !== strpos( $gwc_vt_sc_html, '<table' ) ) {
		$gwc_vt_sc_order[] = '<table';
	}

	$gwc_vt_sc_at = array();

	foreach ( $gwc_vt_sc_order as $gwc_vt_sc_part ) {
		$gwc_vt_sc_at[ $gwc_vt_sc_part ] = strpos( $gwc_vt_sc_html, $gwc_vt_sc_part );
	}

	$gwc_vt_sc_sorted = $gwc_vt_sc_at;
	asort( $gwc_vt_sc_sorted );

	gwc_vt_sc_check(
		'and wears the header block core builds on every list',
		! in_array( false, $gwc_vt_sc_at, true )
			&& array_keys( $gwc_vt_sc_sorted ) === $gwc_vt_sc_order,
		implode( ' → ', array_keys( $gwc_vt_sc_sorted ) )
	);

	/* And one way to add, the same on all three. The two buttons this replaced
	 * asked somebody to choose between two words before they had been told what
	 * either means; the screen behind this one asks the question instead. */
	gwc_vt_sc_check(
		'and offers one way to add, not a choice of vocabulary',
		1 === substr_count( $gwc_vt_sc_html, 'class="page-title-action"' )
			&& false !== strpos( $gwc_vt_sc_html, 'add=new' ),
		substr_count( $gwc_vt_sc_html, 'class="page-title-action"' ) . ' title action(s)'
	);
}

/* The screen that button leads to, which is the whole point of there being one
 * button: it asks the question in the reader's terms rather than making them
 * know the vocabulary first. Both routes out of it still exist, because the
 * dashboard and the how-to guide link straight at them. */
$GLOBALS['gwc_vt_sc_add'] = gwc_vt_sc_render( 'the add chooser', function () {
	$_GET['add'] = 'new';
	gwc_vt_render_schedule_screen();
	unset( $_GET['add'] );
} );

gwc_vt_sc_check(
	'the chooser offers both, and says what each is for',
	false !== strpos( $GLOBALS['gwc_vt_sc_add'], 'shift=new' )
		&& false !== strpos( $GLOBALS['gwc_vt_sc_add'], 'gwc_vt_event=new' )
		&& false !== strpos( $GLOBALS['gwc_vt_sc_add'], 'Several roles on one occasion' )
);

gwc_vt_sc_check(
	'and keeps the words the buttons it replaced used',
	false !== strpos( $GLOBALS['gwc_vt_sc_add'], 'Add New Shift' )
		&& false !== strpos( $GLOBALS['gwc_vt_sc_add'], 'Add New Event' )
);

/* Knowing which you want is still allowed. */
$GLOBALS['gwc_vt_sc_direct'] = gwc_vt_sc_render( 'the direct route to a new event', function () {
	$_GET['gwc_vt_event'] = 'new';
	gwc_vt_render_schedule_screen();
	unset( $_GET['gwc_vt_event'] );
} );

gwc_vt_sc_check(
	'the direct address still opens the editor rather than the chooser',
	false !== strpos( $GLOBALS['gwc_vt_sc_direct'], 'gwc_vt_save_event' )
		&& false === strpos( $GLOBALS['gwc_vt_sc_direct'], 'Several roles on one occasion' )
);

/* ── A result in the URL is a result on the screen ───────────────────────── */

$_GET['gwc_vt_event_result'] = 'saved';

$GLOBALS['gwc_vt_sc_said'] = array(
	'the event editor' => gwc_vt_sc_render( 'the event editor, arrived at from a save', function () use ( $gwc_vt_event ) {
		gwc_vt_render_event_editor( $gwc_vt_event );
	} ),
	'the roster'       => gwc_vt_sc_render( 'the roster, arrived at from a save', function () use ( $gwc_vt_event ) {
		gwc_vt_render_event_roster( $gwc_vt_event );
	} ),
	'the events list'  => gwc_vt_sc_render( 'the events list, arrived at from a save', function () {
		gwc_vt_render_events_list();
	} ),
);

unset( $_GET['gwc_vt_event_result'] );

foreach ( $GLOBALS['gwc_vt_sc_said'] as $gwc_vt_sc_where => $gwc_vt_sc_html ) {
	gwc_vt_sc_check(
		$gwc_vt_sc_where . ' says what the redirect that landed on it did',
		false !== strpos( (string) $gwc_vt_sc_html, 'Saved.' )
	);
}

/* ── And a URL naming something that is gone says so ─────────────────────── */

$GLOBALS['gwc_vt_sc_gone'] = array(
	'a shift that is not there' => array( 'shift', (string) ( $gwc_vt_event + 4000 ), 'That shift is not there' ),
	'an event that is not there' => array( 'gwc_vt_event', (string) ( $gwc_vt_event + 4000 ), 'That event is not there' ),
);

foreach ( $GLOBALS['gwc_vt_sc_gone'] as $gwc_vt_sc_case => $gwc_vt_sc_ask ) {
	$_GET[ $gwc_vt_sc_ask[0] ] = $gwc_vt_sc_ask[1];

	$gwc_vt_sc_html = gwc_vt_sc_render( $gwc_vt_sc_case . ' lands somewhere', 'gwc_vt_render_schedule_screen' );

	unset( $_GET[ $gwc_vt_sc_ask[0] ] );

	gwc_vt_sc_check(
		'and ' . $gwc_vt_sc_case . ' says so rather than silently showing the schedule',
		false !== strpos( $gwc_vt_sc_html, $gwc_vt_sc_ask[2] )
	);
}

/* A time that is not part of this event lands on the event, and says why. */
$_GET['gwc_vt_event'] = (string) $gwc_vt_event;
$_GET['view']         = 'call-off';
$_GET['slot']         = (string) ( $gwc_vt_event + 4000 );

$GLOBALS['gwc_vt_sc_slot'] = gwc_vt_sc_render( 'a time that is not on this event', 'gwc_vt_render_schedule_screen' );

unset( $_GET['gwc_vt_event'], $_GET['view'], $_GET['slot'] );

gwc_vt_sc_check(
	'and it says that, on the event it fell back to',
	false !== strpos( $GLOBALS['gwc_vt_sc_slot'], 'That time is not part of this event' )
);

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

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

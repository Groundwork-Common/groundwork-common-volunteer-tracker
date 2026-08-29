<?php
/**
 * A menu of six nouns, and two screens that are still there without being on it.
 *
 * ── Why this needs more than a unit test ─────────────────────────────────────
 * tests/MenuTest.php asserts the ordering and the hiding against a fixture and a
 * stubbed remove_submenu_page(). What it cannot assert is the half of core's
 * behaviour the whole change rests on: that remove_submenu_page() takes the
 * entry off the menu and leaves the PAGE registered. If that were not true —
 * or if it stopped being true — "Log a day" would not be relocated to a button,
 * it would be deleted, along with the "Log the hours" link on every shift.
 *
 * The other half is what removing the entry costs. get_admin_page_title() reads
 * a page's title back out of $submenu, so taking the entry away left $title
 * null: no <title>, and a deprecation notice printed across the top of wp-admin
 * from inside core's own admin-header.php on PHP 8.1 and up. The page still
 * rendered, which is exactly why it needs a test rather than an eye.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/menu.php
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
function gwc_vt_menu_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

require_once ABSPATH . 'wp-admin/includes/admin.php';

/* ── Borrow the menu globals, and give them back ─────────────────────────────
 * Same reasoning as the settings borrow in tests/integration/letters-switch.php.
 * Registered on shutdown so it runs whether this finishes, fails an assertion or
 * fatals; PHP runs shutdown functions on exit(), so the exit( 1 ) at the foot is
 * safe beside it.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_menu_before']       = $GLOBALS['submenu'] ?? null;
$GLOBALS['gwc_vt_menu_title_before'] = $GLOBALS['title'] ?? null;
$GLOBALS['gwc_vt_menu_settings']     = get_option( GWC_VT_SETTINGS_OPTION, array() );

/**
 * Put the menu globals and the settings back exactly as they were found.
 */
function gwc_vt_menu_restore(): void {
	if ( null === ( $GLOBALS['gwc_vt_menu_before'] ?? null ) ) {
		unset( $GLOBALS['submenu'] );
	} else {
		$GLOBALS['submenu'] = $GLOBALS['gwc_vt_menu_before'];
	}

	if ( null === ( $GLOBALS['gwc_vt_menu_title_before'] ?? null ) ) {
		unset( $GLOBALS['title'] );
	} else {
		$GLOBALS['title'] = $GLOBALS['gwc_vt_menu_title_before'];
	}

	update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_menu_settings'] );
	gwc_vt_settings_cache( null, true );
}

/**
 * Put the site in a named state and rebuild the menu core would build.
 *
 * ── Why this file now asks the question twice ────────────────────────────────
 * It used to assert against whatever the site happened to have switched on, and
 * on a bare install it failed: scheduling is off until an organization turns it
 * on, so gwc_vt_register_schedule_menu() returns early and the Schedule row the
 * band map names is not there. Red for a reason that is not a bug — and a suite
 * with a permanent red in it is one people learn to read past, which is the same
 * failure as the seven scripts that printed FAIL and exited zero.
 *
 * The state a site starts in is worth a check of its own, so both are checked.
 *
 * @param array $settings Settings to overlay on the site's own.
 * @return array{slugs: string[], labels: string[], ruled: string[]}
 */
function gwc_vt_menu_build( array $settings ): array {
	update_option( GWC_VT_SETTINGS_OPTION, array_merge( $GLOBALS['gwc_vt_menu_settings'], $settings ) );
	gwc_vt_settings_cache( null, true );

	$GLOBALS['submenu'] = array();

	/* The three WordPress adds itself for the two post types, before any
	 * admin_menu callback runs. Spelled out rather than loading
	 * wp-admin/menu.php, which wants a screen, $parent_file, $self and a dozen
	 * other request globals that do not exist under WP-CLI. */
	$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = array(
		array( 'Hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
		array( 'Log hours', 'edit_posts', 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
		array( 'Volunteers', 'edit_posts', 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
	);

	/* Then this plugin's own, through the real add_submenu_page(). */
	do_action( 'admin_menu', '' );

	$rows = (array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() );

	$ruled = array();

	foreach ( $rows as $row ) {
		if ( false !== strpos( (string) ( $row[4] ?? '' ), 'gwc-vt-rule' ) ) {
			$ruled[] = (string) ( $row[2] ?? '' );
		}
	}

	return array(
		'slugs'  => array_map(
			static function ( array $item ): string {
				return (string) ( $item[2] ?? '' );
			},
			$rows
		),
		'labels' => array_map(
			static function ( array $item ): string {
				return (string) ( $item[0] ?? '' );
			},
			$rows
		),
		'ruled'  => $ruled,
	);
}

/**
 * The rule the arrangement asks for, given what is actually on the menu.
 *
 * Every band after the first that has any rows opens with one. Computed rather
 * than written out, because which bands have rows is the thing that changes
 * between a bare install and a running organization — and asserting a fixed
 * list is what made this script fail on a site nobody had set up yet.
 *
 * @param string[] $slugs The menu's slugs, in order.
 * @return string[] The slugs that should carry a rule.
 */
function gwc_vt_menu_expected_rules( array $slugs ): array {
	$expected = array();

	foreach ( gwc_vt_menu_bands() as $band ) {
		$present = array_values( array_intersect( $slugs, $band ) );

		if ( $present ) {
			$expected[] = $present[0];
		}
	}

	/* The first band opens the menu, and the row that opens a menu carries no
	 * rule above it. */
	return array_slice( $expected, 1 );
}

register_shutdown_function( 'gwc_vt_menu_restore' );

/* An administrator, so every capability-gated entry registers. */
$gwc_vt_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( ! $gwc_vt_admins ) {
	echo "FAIL  no administrator to run as\n";
	echo "\n1 FAILED\n";
	exit( 1 );
}

wp_set_current_user( (int) $gwc_vt_admins[0] );

/* ── Twice: as a new install has it, and with everything switched on ─────────
 * Everything below holds in both states. What differs is which bands have rows
 * in them, which is why the rule is computed from the menu rather than written
 * out — see gwc_vt_menu_expected_rules().
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_menu_states'] = array(
	/* A site nobody has set up yet. Scheduling and letters are both off until an
	 * organization says otherwise, which is the state the rest of this suite is
	 * careful to test and the one this script used to fail on. */
	'as a new install has it' => array(
		'shifts_enabled'  => false,
		'signup_enabled'  => false,
		'letters_enabled' => false,
	),

	/* And a running organization, where the whole band map has something to
	 * describe. */
	'with everything on'      => array(
		'shifts_enabled'  => true,
		'letters_enabled' => true,
	),
);

foreach ( $GLOBALS['gwc_vt_menu_states'] as $gwc_vt_state => $gwc_vt_state_settings ) {
	$gwc_vt_menu    = gwc_vt_menu_build( $gwc_vt_state_settings );
	$gwc_vt_slugs   = $gwc_vt_menu['slugs'];
	$gwc_vt_labels  = $gwc_vt_menu['labels'];
	$gwc_vt_ruled   = $gwc_vt_menu['ruled'];
	$gwc_vt_in      = ', ' . $gwc_vt_state;

	/* ── The menu lists places ───────────────────────────────────────────── */

	gwc_vt_menu_check(
		'Log a day is off the menu' . $gwc_vt_in,
		! in_array( GWC_VT_QUICK_ADD_PAGE, $gwc_vt_slugs, true ),
		implode( ' · ', $gwc_vt_labels )
	);

	gwc_vt_menu_check(
		'the post type’s own add-new entry is off the menu' . $gwc_vt_in,
		! in_array( 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
	);

	gwc_vt_menu_check(
		'Hours is still on it' . $gwc_vt_in,
		in_array( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
	);

	/* Settings opens the setting-up band rather than ending the menu — the
	 * owner's own arrangement, Settings then Credentials then Help. */
	gwc_vt_menu_check(
		'the menu ends with the setting-up band, in that order' . $gwc_vt_in,
		array( GWC_VT_SETTINGS_PAGE, GWC_VT_CREDENTIALS_PAGE, GWC_VT_HELP_PAGE ) === array_slice( $gwc_vt_slugs, -3 ),
		implode( ' · ', array_slice( $gwc_vt_slugs, -3 ) )
	);

	gwc_vt_menu_check(
		'the menu is a list, not a sparse array' . $gwc_vt_in,
		array_keys( (array) $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ) === range( 0, count( $gwc_vt_slugs ) - 1 )
	);

	/* ── The bands, drawn against the menu core actually builds ──────────────
	 * tests/MenuTest.php asserts this against a fixture. What it cannot assert
	 * is that the rows core adds itself — Hours and Volunteers, from the two
	 * post types, before any admin_menu callback runs — carry the slugs the
	 * bands name them by. Get one of those wrong and the band map is describing
	 * a menu nobody has, silently, because a slug that matches nothing simply
	 * takes no rule.
	 * ─────────────────────────────────────────────────────────────────────── */

	gwc_vt_menu_check(
		'a rule opens each band that has rows' . $gwc_vt_in,
		gwc_vt_menu_expected_rules( $gwc_vt_slugs ) === $gwc_vt_ruled,
		implode( ' · ', $gwc_vt_ruled )
	);

	gwc_vt_menu_check(
		'the row that opens the menu carries none' . $gwc_vt_in,
		'' === (string) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ][0][4] ?? '' ),
		(string) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ][0][2] ?? '' )
	);

	/* Credentials is named in the order rather than left in the "anything we
	 * have not heard of" remainder, which is where it sat for two releases. It
	 * is in the setting-up band now rather than beside the volunteers —
	 * asserted by position, because it was always present and the question is
	 * only where. */
	gwc_vt_menu_check(
		'Credentials sits in the setting-up band, below the record' . $gwc_vt_in,
		array_search( GWC_VT_CREDENTIALS_PAGE, $gwc_vt_slugs, true ) > array_search( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
			&& array_search( GWC_VT_CREDENTIALS_PAGE, $gwc_vt_slugs, true ) > array_search( GWC_VT_SETTINGS_PAGE, $gwc_vt_slugs, true ),
		implode( ' · ', $gwc_vt_slugs )
	);
}

/* ── And the arrangement itself, which only a set-up site can show ───────────
 * "Every band finds a row" is the check that the grouping is not describing
 * screens nobody has. It is a question about the band MAP, so it is asked in
 * the state where every band is supposed to have something — asking it of a
 * bare install is asking whether an organization has switched scheduling on,
 * which is not this script's business.
 *
 * The three rules are written out here rather than computed, because in this
 * state the arrangement is a decision somebody made and is worth pinning
 * literally. gwc_vt_menu_expected_rules() above keeps it honest in both states;
 * this keeps it honest about which three.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_full = gwc_vt_menu_build( $GLOBALS['gwc_vt_menu_states']['with everything on'] );

foreach ( gwc_vt_menu_bands() as $gwc_vt_band => $gwc_vt_band_slugs ) {
	gwc_vt_menu_check(
		'the ' . $gwc_vt_band . ' band matches rows that exist, with everything on',
		array() !== array_intersect( $gwc_vt_band_slugs, $gwc_vt_full['slugs'] ),
		implode( ' · ', $gwc_vt_band_slugs )
	);
}

gwc_vt_menu_check(
	'the rules fall between plan, record and setting-up, with everything on',
	array( GWC_VT_SCHEDULE_PAGE, 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE, GWC_VT_SETTINGS_PAGE ) === $gwc_vt_full['ruled'],
	implode( ' · ', $gwc_vt_full['ruled'] )
);

$gwc_vt_slugs  = $gwc_vt_full['slugs'];
$gwc_vt_labels = $gwc_vt_full['labels'];

/* ── The stylesheet the rule needs ───────────────────────────────────────────
 * The class is inert without it, and it is printed inline on admin_head rather
 * than enqueued on this plugin's screens, because the sidebar is drawn on every
 * screen in wp-admin. Asserted from the output rather than from the hook, so a
 * handler that returns early still fails this.
 * ─────────────────────────────────────────────────────────────────────────── */

ob_start();
gwc_vt_menu_rule_style();
$gwc_vt_style = (string) ob_get_clean();

gwc_vt_menu_check(
	'the rule’s stylesheet is printed where the menu is',
	false !== strpos( $gwc_vt_style, '.gwc-vt-rule' ) && false !== strpos( $gwc_vt_style, 'border-top' ),
	$gwc_vt_style
);

$gwc_vt_kept = $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ];
unset( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] );

ob_start();
gwc_vt_menu_rule_style();
$gwc_vt_quiet = (string) ob_get_clean();

$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $gwc_vt_kept;

gwc_vt_menu_check(
	'and nothing at all where it is not',
	'' === $gwc_vt_quiet,
	$gwc_vt_quiet
);

/* ── But the pages are still there ───────────────────────────────────────────
 * The promise the button relocation rests on. remove_submenu_page() unsets the
 * menu entry and never touches $_registered_pages, so admin.php still serves
 * the screen — a bookmark works, and so does every gwc_vt_shift_log_url() this
 * plugin prints on a shift.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_hook = get_plugin_page_hookname( GWC_VT_QUICK_ADD_PAGE, GWC_VT_MENU_SLUG );

gwc_vt_menu_check(
	'the Log a day screen is still registered',
	! empty( $GLOBALS['_registered_pages'][ $gwc_vt_hook ] ),
	$gwc_vt_hook
);

/* ── And it still knows its own name ─────────────────────────────────────────
 * get_admin_page_title() searches $submenu, which no longer holds the entry, so
 * without gwc_vt_restore_quick_add_title() this comes back empty and core's
 * admin-header.php hands a null to strip_tags().
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['title']       = null;
$GLOBALS['plugin_page'] = GWC_VT_QUICK_ADD_PAGE;

do_action( 'load-' . $gwc_vt_hook );

gwc_vt_menu_check(
	'the Log a day screen has a title without its menu entry',
	is_string( $GLOBALS['title'] ) && '' !== $GLOBALS['title'],
	var_export( $GLOBALS['title'], true )
);

/* ── The button that replaced the entry ──────────────────────────────────────
 * WordPress renders the Hours page-title-action from 'add_new' up to 6.3
 * and from 'add_new_item' from 6.4 on, and this plugin's floor is 6.3. Setting
 * one and not the other reads correctly on the version you happen to develop
 * against and says the old name on the other, silently. So: they must agree.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_labels_obj = get_post_type_object( GWC_VT_ENTRY_TYPE )->labels;

gwc_vt_menu_check(
	'both add-new labels say the same thing',
	$gwc_vt_labels_obj->add_new === $gwc_vt_labels_obj->add_new_item,
	$gwc_vt_labels_obj->add_new . ' / ' . $gwc_vt_labels_obj->add_new_item
);

gwc_vt_menu_check(
	'and neither still says the old menu name',
	'Log hours' !== $gwc_vt_labels_obj->add_new_item,
	$gwc_vt_labels_obj->add_new_item
);

/* ── The sidebar carries the plugin's name, not one post type's ──────────────
 * "Volunteer Hours" was right when hours were the whole product. The menu now
 * holds the schedule, events, volunteers and letters, and naming the lot after
 * one of its six screens made the other five look like they belonged to
 * something else.
 *
 * Only menu_name moved. The post type's own plural is still "Volunteer Hours",
 * because those records are exactly that — it is what Hours and the entry
 * screens are titled from, and changing it would rename the records rather than
 * the menu.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_menu_check(
	'the sidebar says Volunteer Tracker',
	'Volunteer Tracker' === $gwc_vt_labels_obj->menu_name,
	$gwc_vt_labels_obj->menu_name
);

gwc_vt_menu_check(
	'and the post type is still called what its records are',
	'Volunteer Hours' === $gwc_vt_labels_obj->name,
	$gwc_vt_labels_obj->name
);

/* ── The footer says whose version each number is ────────────────────────────
 * The right-hand footer is WordPress's, and it says "Version 7.1" — meaning
 * WordPress. That is unambiguous everywhere else in wp-admin, where the left
 * says "Thank you for creating with WordPress"; on our screens the left says
 * "Built by Groundwork Common", and a bare number beside it reads as this
 * plugin's. A plugin at 1.0.0 looked like it was at 7.1.
 * ─────────────────────────────────────────────────────────────────────────── */

echo "\n── The footer names both versions ───────────────────────────────\n";

require_once ABSPATH . 'wp-admin/includes/screen.php';

set_current_screen( 'edit-' . GWC_VT_ENTRY_TYPE );

$GLOBALS['gwc_vt_menu_footer'] = gwc_vt_admin_footer_version( 'Version ' . get_bloginfo( 'version' ) );

gwc_vt_menu_check(
	'ours is named and is the plugin’s own version',
	false !== strpos( $GLOBALS['gwc_vt_menu_footer'], 'Volunteer Tracker ' . GWC_VT_VERSION ),
	$GLOBALS['gwc_vt_menu_footer']
);

gwc_vt_menu_check(
	'and WordPress’s is named as WordPress’s',
	false !== strpos( $GLOBALS['gwc_vt_menu_footer'], 'WordPress ' . get_bloginfo( 'version' ) ),
	$GLOBALS['gwc_vt_menu_footer']
);

gwc_vt_menu_check(
	'so no number on the line is unlabelled',
	false === strpos( str_replace( 'WordPress ' . get_bloginfo( 'version' ), '', $GLOBALS['gwc_vt_menu_footer'] ), 'Version ' ),
	$GLOBALS['gwc_vt_menu_footer']
);

/* An update to WordPress turns that footer into the prompt to install it —
 * sometimes the only one on the page. Ours goes in front of it; core's is not
 * touched, because tidying a label must never take a security prompt off a
 * screen. */
$GLOBALS['gwc_vt_menu_nag'] = gwc_vt_admin_footer_version(
	'<a href="https://example.org/update-core.php">Get Version 9.9</a>'
);

gwc_vt_menu_check(
	'an update prompt is kept exactly as core wrote it',
	false !== strpos( $GLOBALS['gwc_vt_menu_nag'], '<a href="https://example.org/update-core.php">Get Version 9.9</a>' )
		&& false !== strpos( $GLOBALS['gwc_vt_menu_nag'], 'Volunteer Tracker ' . GWC_VT_VERSION ),
	$GLOBALS['gwc_vt_menu_nag']
);

/* Somebody else's screen keeps somebody else's footer. */
set_current_screen( 'edit-post' );

gwc_vt_menu_check(
	'and a screen that is not ours is left alone',
	'Version 9.9' === gwc_vt_admin_footer_version( 'Version 9.9' ),
	gwc_vt_admin_footer_version( 'Version 9.9' )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

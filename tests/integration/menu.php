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
 *   npx @wordpress/env run cli -- wp eval-file \
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

/**
 * Put the menu globals back exactly as they were found.
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

/* ── Build the menu the way an admin request does ────────────────────────── */

$GLOBALS['submenu'] = array();

/* The three WordPress adds itself for the two post types, before any admin_menu
 * callback runs. Spelled out rather than loading wp-admin/menu.php, which wants
 * a screen, $parent_file, $self and a dozen other request globals that do not
 * exist under WP-CLI. */
$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = array(
	array( 'Hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
	array( 'Log hours', 'edit_posts', 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
	array( 'Volunteers', 'edit_posts', 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
);

/* Then this plugin's own, through the real add_submenu_page(). */
do_action( 'admin_menu', '' );

$gwc_vt_slugs = array_map(
	static function ( array $item ): string {
		return (string) ( $item[2] ?? '' );
	},
	(array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() )
);

$gwc_vt_labels = array_map(
	static function ( array $item ): string {
		return (string) ( $item[0] ?? '' );
	},
	(array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() )
);

/* ── The menu lists places ───────────────────────────────────────────────── */

gwc_vt_menu_check(
	'Log a day is off the menu',
	! in_array( GWC_VT_QUICK_ADD_PAGE, $gwc_vt_slugs, true ),
	implode( ' · ', $gwc_vt_labels )
);

gwc_vt_menu_check(
	'the post type’s own add-new entry is off the menu',
	! in_array( 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
);

gwc_vt_menu_check(
	'Hours is still on it',
	in_array( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
);

gwc_vt_menu_check(
	'Settings is last',
	GWC_VT_SETTINGS_PAGE === end( $gwc_vt_slugs ),
	(string) end( $gwc_vt_slugs )
);

gwc_vt_menu_check(
	'the menu is a list, not a sparse array',
	array_keys( (array) $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ) === range( 0, count( $gwc_vt_slugs ) - 1 )
);

/* ── The bands, drawn against the menu core actually builds ──────────────────
 * tests/MenuTest.php asserts this against a fixture. What it cannot assert is
 * that the rows core adds itself — Hours and Volunteers, from the two post
 * types, before any admin_menu callback runs — carry the slugs the bands name
 * them by. Get one of those wrong and the band map is describing a menu nobody
 * has, silently, because a slug that matches nothing simply takes no rule.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_ruled = array();

foreach ( (array) $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] as $gwc_vt_row ) {
	if ( false !== strpos( (string) ( $gwc_vt_row[4] ?? '' ), 'gwc-vt-rule' ) ) {
		$gwc_vt_ruled[] = (string) ( $gwc_vt_row[2] ?? '' );
	}
}

gwc_vt_menu_check(
	'a rule opens each band after the first',
	array( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE, 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, GWC_VT_HELP_PAGE ) === $gwc_vt_ruled,
	implode( ' · ', $gwc_vt_ruled )
);

gwc_vt_menu_check(
	'the row that opens the menu carries none',
	'' === (string) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ][0][4] ?? '' ),
	(string) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ][0][2] ?? '' )
);

/* Both moved out of the "anything we have not heard of" remainder at the foot
 * of the menu, which is where they landed for two releases because neither was
 * named in the order. Asserted by position rather than by presence: they were
 * always present, just at the bottom. */
gwc_vt_menu_check(
	'Offers and Credentials sit with the volunteers, not below Letters issued',
	array_search( GWC_VT_APPLICATIONS_PAGE, $gwc_vt_slugs, true ) < array_search( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true )
		&& array_search( GWC_VT_CREDENTIALS_PAGE, $gwc_vt_slugs, true ) < array_search( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE, $gwc_vt_slugs, true ),
	implode( ' · ', $gwc_vt_slugs )
);

/* Every band has to find something, or the grouping is describing screens that
 * are not on this menu. */
foreach ( gwc_vt_menu_bands() as $gwc_vt_band => $gwc_vt_band_slugs ) {
	gwc_vt_menu_check(
		'the ' . $gwc_vt_band . ' band matches rows that exist',
		array() !== array_intersect( $gwc_vt_band_slugs, $gwc_vt_slugs ),
		implode( ' · ', $gwc_vt_band_slugs )
	);
}

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

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

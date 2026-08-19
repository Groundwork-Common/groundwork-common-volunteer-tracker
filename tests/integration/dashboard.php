<?php
/**
 * The dashboard against real data.
 *
 * The unit suite covers the ordering rules with counts handed to it. This
 * covers where those counts come from, what the year adds up to, and the one
 * thing the screen must not do: show somebody a link they cannot follow, or a
 * name they went nowhere to see.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/dashboard.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/entries.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [got: ' . $got . ']' : '' ), "\n";
}

$GLOBALS['gwc_vt_settings_before'] = get_option( GWC_VT_SETTINGS_OPTION );

update_option(
	GWC_VT_SETTINGS_OPTION,
	array(
		'org_name'         => 'Zzytest Riverbend Food Bank',
		'hour_format'      => 'decimal',
		'hour_increment'   => 15,
		'shifts_enabled'   => true,
		'retention_months' => 0,
	)
);
gwc_vt_settings_cache( null, true );

/**
 * Create a volunteer.
 *
 * @param string $name Display name.
 * @return int
 */
function gwc_vt_make_volunteer( string $name ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether it is attested.
 * @return int
 */
function gwc_vt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Zzytest sorting donations' );

	if ( $verified ) {
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_BY, 1 );
	}

	gwc_vt_refresh_totals( $volunteer_id );

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

wp_set_current_user( 1 );

/* ── The counts come from somewhere ──────────────────────────────────────── */

$gwc_vt_counts = gwc_vt_dashboard_counts();

gwc_vt_check(
	'every queue reports a number',
	array( 'unreconciled', 'understaffed', 'overdue', 'unverified', 'unmatched' ) === array_keys( $gwc_vt_counts ),
	implode( ',', array_keys( $gwc_vt_counts ) )
);

foreach ( $gwc_vt_counts as $gwc_vt_key => $gwc_vt_n ) {
	gwc_vt_check( 'the ' . $gwc_vt_key . ' count is a number that is not negative', is_int( $gwc_vt_n ) && $gwc_vt_n >= 0, (string) $gwc_vt_n );
}

/* ── A deadline that has passed ──────────────────────────────────────────── */

$gwc_vt_late = gwc_vt_make_volunteer( 'Zzytest Tomás Beaulieu' );
update_post_meta( $gwc_vt_late, GWC_VT_VOLUNTEER_REQUIRED, 30 * 60 );
update_post_meta( $gwc_vt_late, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() - ( 9 * DAY_IN_SECONDS ) ) );

gwc_vt_check( 'somebody past their deadline is counted', in_array( $gwc_vt_late, gwc_vt_overdue_requirement_ids(), true ) );

/* Somebody who finished is never overdue, whatever the date says. */
$gwc_vt_done = gwc_vt_make_volunteer( 'Zzytest Marcus Delacroix' );
update_post_meta( $gwc_vt_done, GWC_VT_VOLUNTEER_REQUIRED, 2 * 60 );
update_post_meta( $gwc_vt_done, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );
gwc_vt_make_entry( $gwc_vt_done, gmdate( 'Y-m-d' ), 2 * 60, true );

gwc_vt_check( 'somebody who finished is not', ! in_array( $gwc_vt_done, gwc_vt_overdue_requirement_ids(), true ) );

/* And somebody with no requirement at all is never in this query. */
$gwc_vt_ordinary = gwc_vt_make_volunteer( 'Zzytest Priya Nandakumar' );
gwc_vt_check( 'an ordinary volunteer is not', ! in_array( $gwc_vt_ordinary, gwc_vt_overdue_requirement_ids(), true ) );

/* ── The year ────────────────────────────────────────────────────────────────
 * The one figure on the screen that is a claim rather than a prompt.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_year  = gmdate( 'Y' );
$gwc_vt_start = $gwc_vt_year . '-01-01';
$gwc_vt_today = gwc_vt_today();

/* Cold, or the baseline is whatever was cached before these fixtures existed —
 * which is exactly the mistake the first version of this file made, and it read
 * as the totals being wrong rather than the measurement being. */
delete_transient( GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_start . '|' . $gwc_vt_today ) );

$gwc_vt_before = gwc_vt_org_totals( $gwc_vt_start, $gwc_vt_today );

$gwc_vt_person = gwc_vt_make_volunteer( 'Zzytest Wilhelmina Okonjo' );
gwc_vt_make_entry( $gwc_vt_person, $gwc_vt_year . '-01-02', 180, true );
gwc_vt_make_entry( $gwc_vt_person, $gwc_vt_year . '-01-03', 120, false );

/* An old one, to prove the range is doing something. */
gwc_vt_make_entry( $gwc_vt_person, '2019-06-01', 600, true );

delete_transient( GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_start . '|' . $gwc_vt_today ) );

$gwc_vt_after = gwc_vt_org_totals( $gwc_vt_start, $gwc_vt_today );

gwc_vt_check( 'verified hours this year went up by the verified entry', ( $gwc_vt_after['verified'] - $gwc_vt_before['verified'] ) === 180, (string) ( $gwc_vt_after['verified'] - $gwc_vt_before['verified'] ) );
gwc_vt_check( 'and the unverified one is counted apart from it', ( $gwc_vt_after['pending'] - $gwc_vt_before['pending'] ) === 120, (string) ( $gwc_vt_after['pending'] - $gwc_vt_before['pending'] ) );
gwc_vt_check( 'an entry from years ago is outside the year', ( $gwc_vt_after['verified'] - $gwc_vt_before['verified'] ) !== 780 );
gwc_vt_check( 'shifts recorded counts both', ( $gwc_vt_after['entries'] - $gwc_vt_before['entries'] ) === 2, (string) ( $gwc_vt_after['entries'] - $gwc_vt_before['entries'] ) );

/* ── The cache, and the thing that clears it ─────────────────────────────────
 * An hour-old total is fine for a figure nobody watches change. It is not fine
 * for a coordinator who logs a day and then wonders where their afternoon went.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_key = GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_start . '|' . $gwc_vt_today );

gwc_vt_org_totals( $gwc_vt_start, $gwc_vt_today );
gwc_vt_check( 'the year figures are cached', false !== get_transient( $gwc_vt_key ) );

gwc_vt_forget_org_totals();
gwc_vt_check( 'and forgotten when hours change', false === get_transient( $gwc_vt_key ) );

/* The hooks, not just the function. Every path that creates or moves hours has
 * to be on this list — including the two that do not fire gwc_vt_entry_saved,
 * which is how the public form nearly ended up leaving the figure an hour
 * stale. */
foreach ( array( 'gwc_vt_entry_saved', 'gwc_vt_entry_verified', 'gwc_vt_entry_unverified', 'gwc_vt_self_log_received', 'gwc_vt_entry_attached' ) as $gwc_vt_hook ) {
	gwc_vt_org_totals( $gwc_vt_start, $gwc_vt_today );
	do_action( $gwc_vt_hook, 0, 1 );

	gwc_vt_check( $gwc_vt_hook . ' clears the year figures', false === get_transient( $gwc_vt_key ) );
}

/* ── The reporting year is filterable ────────────────────────────────────── */

gwc_vt_check( 'the reporting year starts in January by default', $gwc_vt_start === gwc_vt_reporting_year_start(), gwc_vt_reporting_year_start() );

add_filter( 'gwc_vt_reporting_year_start', 'gwc_vt_test_fiscal_year' );

/**
 * A July fiscal year.
 *
 * @return string
 */
function gwc_vt_test_fiscal_year(): string {
	return gmdate( 'Y' ) . '-07-01';
}

gwc_vt_check( 'a fiscal year can be set from a filter', gmdate( 'Y' ) . '-07-01' === gwc_vt_reporting_year_start() );

remove_filter( 'gwc_vt_reporting_year_start', 'gwc_vt_test_fiscal_year' );

/* A filter that returns nonsense falls back rather than producing a figure
 * measured from nowhere. */
add_filter( 'gwc_vt_reporting_year_start', 'gwc_vt_test_bad_year' );

/**
 * Not a date.
 *
 * @return string
 */
function gwc_vt_test_bad_year(): string {
	return 'the beginning of time';
}

gwc_vt_check( 'and an unreadable one falls back to January', $gwc_vt_start === gwc_vt_reporting_year_start(), gwc_vt_reporting_year_start() );

remove_filter( 'gwc_vt_reporting_year_start', 'gwc_vt_test_bad_year' );

/* ── The map shows only what the person can reach ────────────────────────────
 * A link that refuses when clicked is worse than an absent one: the first
 * teaches somebody the screen is broken, the second teaches them nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The titles of the map's groups, for whoever is currently signed in.
 *
 * @return string[]
 */
function gwc_vt_map_titles(): array {
	return array_map(
		static function ( array $group ): string {
			return (string) $group['title'];
		},
		gwc_vt_dashboard_map()
	);
}

$gwc_vt_admin_sees = gwc_vt_map_titles();

gwc_vt_check( 'an administrator sees the letters group', in_array( 'Letters', $gwc_vt_admin_sees, true ), implode( ',', $gwc_vt_admin_sees ) );
gwc_vt_check( 'and the setting-up group', in_array( 'Setting up', $gwc_vt_admin_sees, true ) );

$gwc_vt_author = wp_insert_user(
	array(
		'user_login' => 'zzytest_author',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'zzytest-author@example.test',
		'role'       => 'author',
	)
);

if ( ! is_wp_error( $gwc_vt_author ) ) {
	wp_set_current_user( (int) $gwc_vt_author );

	$gwc_vt_author_sees = gwc_vt_map_titles();

	gwc_vt_check( 'somebody who cannot issue letters is not offered them', ! in_array( 'Letters', $gwc_vt_author_sees, true ), implode( ',', $gwc_vt_author_sees ) );
	gwc_vt_check( 'nor the settings they cannot open', ! in_array( 'Setting up', $gwc_vt_author_sees, true ) );
	gwc_vt_check( 'but they still get the hours they can log', in_array( 'Hours', $gwc_vt_author_sees, true ) );
	gwc_vt_check( 'and the shifts they can staff', in_array( 'Shifts', $gwc_vt_author_sees, true ) );

	wp_set_current_user( 1 );
	wp_delete_user( (int) $gwc_vt_author );
}

/* ── With shifts switched off there is no schedule to offer ──────────────── */

$gwc_vt_settings                   = (array) get_option( GWC_VT_SETTINGS_OPTION );
$gwc_vt_settings['shifts_enabled'] = false;
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_settings );
gwc_vt_settings_cache( null, true );

gwc_vt_check( 'with shifts off the map drops them', ! in_array( 'Shifts', gwc_vt_map_titles(), true ), implode( ',', gwc_vt_map_titles() ) );
gwc_vt_check( 'and hours are still there', in_array( 'Hours', gwc_vt_map_titles(), true ) );

$gwc_vt_settings['shifts_enabled'] = true;
update_option( GWC_VT_SETTINGS_OPTION, $gwc_vt_settings );
gwc_vt_settings_cache( null, true );

/* ── Every line goes somewhere ───────────────────────────────────────────── */

foreach ( array( 'unreconciled', 'understaffed', 'overdue', 'unverified', 'unmatched' ) as $gwc_vt_key_name ) {
	$gwc_vt_url = gwc_vt_dashboard_item_url( $gwc_vt_key_name );

	gwc_vt_check( 'the ' . $gwc_vt_key_name . ' line has somewhere to go', '' !== $gwc_vt_url && false !== strpos( $gwc_vt_url, 'wp-admin' ), $gwc_vt_url );
}

foreach ( gwc_vt_dashboard_map() as $gwc_vt_group ) {
	foreach ( (array) $gwc_vt_group['links'] as $gwc_vt_link ) {
		gwc_vt_check(
			'the map link "' . $gwc_vt_link['label'] . '" has a destination',
			'' !== $gwc_vt_link['url'] && false !== strpos( $gwc_vt_link['url'], 'wp-admin' ),
			(string) $gwc_vt_link['url']
		);
	}
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array_unique( array_filter( $GLOBALS['gwc_vt_made'] ) ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

delete_transient( $gwc_vt_key );

if ( false === $GLOBALS['gwc_vt_settings_before'] ) {
	delete_option( GWC_VT_SETTINGS_OPTION );
} else {
	update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

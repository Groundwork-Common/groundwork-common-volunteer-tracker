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
$GLOBALS['gwcvt_failures'] = 0;
$GLOBALS['gwcvt_made']     = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwcvt_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwcvt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [got: ' . $got . ']' : '' ), "\n";
}

$GLOBALS['gwcvt_settings_before'] = get_option( GWCVT_SETTINGS_OPTION );

update_option(
	GWCVT_SETTINGS_OPTION,
	array(
		'org_name'         => 'Zzytest Riverbend Food Bank',
		'hour_format'      => 'decimal',
		'hour_increment'   => 15,
		'shifts_enabled'   => true,
		'retention_months' => 0,
	)
);
gwcvt_settings_cache( null, true );

/**
 * Create a volunteer.
 *
 * @param string $name Display name.
 * @return int
 */
function gwcvt_make_volunteer( string $name ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwcvt_made'][] = $id;

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
function gwcvt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $id, GWCVT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, 'Zzytest sorting donations' );

	if ( $verified ) {
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_BY, 1 );
	}

	gwcvt_refresh_totals( $volunteer_id );

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

wp_set_current_user( 1 );

/* ── The counts come from somewhere ──────────────────────────────────────── */

$gwcvt_counts = gwcvt_dashboard_counts();

gwcvt_check(
	'every queue reports a number',
	array( 'unreconciled', 'understaffed', 'overdue', 'unverified', 'unmatched' ) === array_keys( $gwcvt_counts ),
	implode( ',', array_keys( $gwcvt_counts ) )
);

foreach ( $gwcvt_counts as $gwcvt_key => $gwcvt_n ) {
	gwcvt_check( 'the ' . $gwcvt_key . ' count is a number that is not negative', is_int( $gwcvt_n ) && $gwcvt_n >= 0, (string) $gwcvt_n );
}

/* ── A deadline that has passed ──────────────────────────────────────────── */

$gwcvt_late = gwcvt_make_volunteer( 'Zzytest Tomás Beaulieu' );
update_post_meta( $gwcvt_late, GWCVT_VOLUNTEER_REQUIRED, 30 * 60 );
update_post_meta( $gwcvt_late, GWCVT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() - ( 9 * DAY_IN_SECONDS ) ) );

gwcvt_check( 'somebody past their deadline is counted', in_array( $gwcvt_late, gwcvt_overdue_requirement_ids(), true ) );

/* Somebody who finished is never overdue, whatever the date says. */
$gwcvt_done = gwcvt_make_volunteer( 'Zzytest Marcus Delacroix' );
update_post_meta( $gwcvt_done, GWCVT_VOLUNTEER_REQUIRED, 2 * 60 );
update_post_meta( $gwcvt_done, GWCVT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );
gwcvt_make_entry( $gwcvt_done, gmdate( 'Y-m-d' ), 2 * 60, true );

gwcvt_check( 'somebody who finished is not', ! in_array( $gwcvt_done, gwcvt_overdue_requirement_ids(), true ) );

/* And somebody with no requirement at all is never in this query. */
$gwcvt_ordinary = gwcvt_make_volunteer( 'Zzytest Priya Nandakumar' );
gwcvt_check( 'an ordinary volunteer is not', ! in_array( $gwcvt_ordinary, gwcvt_overdue_requirement_ids(), true ) );

/* ── The year ────────────────────────────────────────────────────────────────
 * The one figure on the screen that is a claim rather than a prompt.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_year  = gmdate( 'Y' );
$gwcvt_start = $gwcvt_year . '-01-01';
$gwcvt_today = gwcvt_today();

/* Cold, or the baseline is whatever was cached before these fixtures existed —
 * which is exactly the mistake the first version of this file made, and it read
 * as the totals being wrong rather than the measurement being. */
delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_start . '|' . $gwcvt_today ) );

$gwcvt_before = gwcvt_org_totals( $gwcvt_start, $gwcvt_today );

$gwcvt_person = gwcvt_make_volunteer( 'Zzytest Wilhelmina Okonjo' );
gwcvt_make_entry( $gwcvt_person, $gwcvt_year . '-01-02', 180, true );
gwcvt_make_entry( $gwcvt_person, $gwcvt_year . '-01-03', 120, false );

/* An old one, to prove the range is doing something. */
gwcvt_make_entry( $gwcvt_person, '2019-06-01', 600, true );

delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_start . '|' . $gwcvt_today ) );

$gwcvt_after = gwcvt_org_totals( $gwcvt_start, $gwcvt_today );

gwcvt_check( 'verified hours this year went up by the verified entry', ( $gwcvt_after['verified'] - $gwcvt_before['verified'] ) === 180, (string) ( $gwcvt_after['verified'] - $gwcvt_before['verified'] ) );
gwcvt_check( 'and the unverified one is counted apart from it', ( $gwcvt_after['pending'] - $gwcvt_before['pending'] ) === 120, (string) ( $gwcvt_after['pending'] - $gwcvt_before['pending'] ) );
gwcvt_check( 'an entry from years ago is outside the year', ( $gwcvt_after['verified'] - $gwcvt_before['verified'] ) !== 780 );
gwcvt_check( 'shifts recorded counts both', ( $gwcvt_after['entries'] - $gwcvt_before['entries'] ) === 2, (string) ( $gwcvt_after['entries'] - $gwcvt_before['entries'] ) );

/* ── The cache, and the thing that clears it ─────────────────────────────────
 * An hour-old total is fine for a figure nobody watches change. It is not fine
 * for a coordinator who logs a day and then wonders where their afternoon went.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_key = GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_start . '|' . $gwcvt_today );

gwcvt_org_totals( $gwcvt_start, $gwcvt_today );
gwcvt_check( 'the year figures are cached', false !== get_transient( $gwcvt_key ) );

gwcvt_forget_org_totals();
gwcvt_check( 'and forgotten when hours change', false === get_transient( $gwcvt_key ) );

/* The hooks, not just the function. Every path that creates or moves hours has
 * to be on this list — including the two that do not fire gwcvt_entry_saved,
 * which is how the public form nearly ended up leaving the figure an hour
 * stale. */
foreach ( array( 'gwcvt_entry_saved', 'gwcvt_entry_verified', 'gwcvt_entry_unverified', 'gwcvt_self_log_received', 'gwcvt_entry_attached' ) as $gwcvt_hook ) {
	gwcvt_org_totals( $gwcvt_start, $gwcvt_today );
	do_action( $gwcvt_hook, 0, 1 );

	gwcvt_check( $gwcvt_hook . ' clears the year figures', false === get_transient( $gwcvt_key ) );
}

/* ── The reporting year is filterable ────────────────────────────────────── */

gwcvt_check( 'the reporting year starts in January by default', $gwcvt_start === gwcvt_reporting_year_start(), gwcvt_reporting_year_start() );

add_filter( 'gwcvt_reporting_year_start', 'gwcvt_test_fiscal_year' );

/**
 * A July fiscal year.
 *
 * @return string
 */
function gwcvt_test_fiscal_year(): string {
	return gmdate( 'Y' ) . '-07-01';
}

gwcvt_check( 'a fiscal year can be set from a filter', gmdate( 'Y' ) . '-07-01' === gwcvt_reporting_year_start() );

remove_filter( 'gwcvt_reporting_year_start', 'gwcvt_test_fiscal_year' );

/* A filter that returns nonsense falls back rather than producing a figure
 * measured from nowhere. */
add_filter( 'gwcvt_reporting_year_start', 'gwcvt_test_bad_year' );

/**
 * Not a date.
 *
 * @return string
 */
function gwcvt_test_bad_year(): string {
	return 'the beginning of time';
}

gwcvt_check( 'and an unreadable one falls back to January', $gwcvt_start === gwcvt_reporting_year_start(), gwcvt_reporting_year_start() );

remove_filter( 'gwcvt_reporting_year_start', 'gwcvt_test_bad_year' );

/* ── The map shows only what the person can reach ────────────────────────────
 * A link that refuses when clicked is worse than an absent one: the first
 * teaches somebody the screen is broken, the second teaches them nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The titles of the map's groups, for whoever is currently signed in.
 *
 * @return string[]
 */
function gwcvt_map_titles(): array {
	return array_map(
		static function ( array $group ): string {
			return (string) $group['title'];
		},
		gwcvt_dashboard_map()
	);
}

$gwcvt_admin_sees = gwcvt_map_titles();

gwcvt_check( 'an administrator sees the letters group', in_array( 'Letters', $gwcvt_admin_sees, true ), implode( ',', $gwcvt_admin_sees ) );
gwcvt_check( 'and the setting-up group', in_array( 'Setting up', $gwcvt_admin_sees, true ) );

$gwcvt_author = wp_insert_user(
	array(
		'user_login' => 'zzytest_author',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'zzytest-author@example.test',
		'role'       => 'author',
	)
);

if ( ! is_wp_error( $gwcvt_author ) ) {
	wp_set_current_user( (int) $gwcvt_author );

	$gwcvt_author_sees = gwcvt_map_titles();

	gwcvt_check( 'somebody who cannot issue letters is not offered them', ! in_array( 'Letters', $gwcvt_author_sees, true ), implode( ',', $gwcvt_author_sees ) );
	gwcvt_check( 'nor the settings they cannot open', ! in_array( 'Setting up', $gwcvt_author_sees, true ) );
	gwcvt_check( 'but they still get the hours they can log', in_array( 'Hours', $gwcvt_author_sees, true ) );
	gwcvt_check( 'and the shifts they can staff', in_array( 'Shifts', $gwcvt_author_sees, true ) );

	wp_set_current_user( 1 );
	wp_delete_user( (int) $gwcvt_author );
}

/* ── With shifts switched off there is no schedule to offer ──────────────── */

$gwcvt_settings                   = (array) get_option( GWCVT_SETTINGS_OPTION );
$gwcvt_settings['shifts_enabled'] = false;
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_settings );
gwcvt_settings_cache( null, true );

gwcvt_check( 'with shifts off the map drops them', ! in_array( 'Shifts', gwcvt_map_titles(), true ), implode( ',', gwcvt_map_titles() ) );
gwcvt_check( 'and hours are still there', in_array( 'Hours', gwcvt_map_titles(), true ) );

$gwcvt_settings['shifts_enabled'] = true;
update_option( GWCVT_SETTINGS_OPTION, $gwcvt_settings );
gwcvt_settings_cache( null, true );

/* ── Every line goes somewhere ───────────────────────────────────────────── */

foreach ( array( 'unreconciled', 'understaffed', 'overdue', 'unverified', 'unmatched' ) as $gwcvt_key_name ) {
	$gwcvt_url = gwcvt_dashboard_item_url( $gwcvt_key_name );

	gwcvt_check( 'the ' . $gwcvt_key_name . ' line has somewhere to go', '' !== $gwcvt_url && false !== strpos( $gwcvt_url, 'wp-admin' ), $gwcvt_url );
}

foreach ( gwcvt_dashboard_map() as $gwcvt_group ) {
	foreach ( (array) $gwcvt_group['links'] as $gwcvt_link ) {
		gwcvt_check(
			'the map link "' . $gwcvt_link['label'] . '" has a destination',
			'' !== $gwcvt_link['url'] && false !== strpos( $gwcvt_link['url'], 'wp-admin' ),
			(string) $gwcvt_link['url']
		);
	}
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array_unique( array_filter( $GLOBALS['gwcvt_made'] ) ) as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

delete_transient( $gwcvt_key );

if ( false === $GLOBALS['gwcvt_settings_before'] ) {
	delete_option( GWCVT_SETTINGS_OPTION );
} else {
	update_option( GWCVT_SETTINGS_OPTION, $GLOBALS['gwcvt_settings_before'] );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

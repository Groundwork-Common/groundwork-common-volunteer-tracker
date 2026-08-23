<?php
/**
 * Logging hours, totaling them, and the REST lookup — against real WordPress.
 *
 * The unit suite stubs get_posts(), so what it proves about the query layer is
 * only as good as the stub. This runs the same functions against a real
 * database, real meta_query date ranges, and a real WP_REST_Server.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/entries.php
 *
 * It creates its own fixtures and deletes them again, so it is safe to re-run.
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly, everywhere, and not `global $x` alongside a bare
 * top-level `$x = 0`. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL — while `global` in the helper below reaches
 * the real global. The two are different variables, the counter increments one
 * and the summary reads the other, and the script cheerfully prints ALL PASS
 * underneath a list of failures. That happened. */
$GLOBALS['gwc_vt_failures'] = 0;

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

/* ── Fixtures ────────────────────────────────────────────────────────────── */

/* $GLOBALS again, for the reason above — and this one is why the note is worth
 * having twice. As a local, this array stayed empty while the helpers appended
 * to the global, so the cleanup loop at the bottom iterated nothing and every
 * run left its fixtures behind for the next one to find. */
$GLOBALS['gwc_vt_made'] = array();

/**
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwc_vt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

/**
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @param bool   $verified     Whether to mark it attested.
 * @return int
 */
function gwc_vt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified = false ): int {
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
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWC_VT_ENTRY_VERIFIED_BY, 1 );
	}

	$GLOBALS['gwc_vt_made'][] = $id;

	return (int) $id;
}

$gwc_vt_jane = gwc_vt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwc_vt_omar = gwc_vt_make_volunteer( 'Zzytest Omar Delacroix' );

gwc_vt_make_entry( $gwc_vt_jane, '2026-03-02', 210, true );   // 3.5 h verified
gwc_vt_make_entry( $gwc_vt_jane, '2026-03-09', 180, true );   // 3 h   verified
gwc_vt_make_entry( $gwc_vt_jane, '2026-04-06', 90, false );   // 1.5 h pending
gwc_vt_make_entry( $gwc_vt_omar, '2026-03-02', 600, true );   // someone else entirely

/* ── Totals ──────────────────────────────────────────────────────────────── */

$gwc_vt_totals = gwc_vt_compute_totals( $gwc_vt_jane );

gwc_vt_check( 'verified minutes', 390 === $gwc_vt_totals->verified_minutes, (string) $gwc_vt_totals->verified_minutes );
gwc_vt_check( 'pending minutes', 90 === $gwc_vt_totals->pending_minutes, (string) $gwc_vt_totals->pending_minutes );
gwc_vt_check( 'entry count', 3 === $gwc_vt_totals->entries, (string) $gwc_vt_totals->entries );
gwc_vt_check( 'total minutes', 480 === $gwc_vt_totals->total_minutes(), (string) $gwc_vt_totals->total_minutes() );
gwc_vt_check( 'first shift', '2026-03-02' === $gwc_vt_totals->first, $gwc_vt_totals->first );
gwc_vt_check( 'last shift', '2026-04-06' === $gwc_vt_totals->last, $gwc_vt_totals->last );

gwc_vt_check(
    'another volunteer’s hours are not included',
    600 === gwc_vt_compute_totals( $gwc_vt_omar )->verified_minutes
);

/* ── Date ranges ─────────────────────────────────────────────────────────── */

$gwc_vt_march = gwc_vt_compute_totals( $gwc_vt_jane, array( 'from' => '2026-03-01', 'to' => '2026-03-31' ) );
gwc_vt_check( 'a date range excludes April', 2 === $gwc_vt_march->entries, (string) $gwc_vt_march->entries );
gwc_vt_check( 'a date range totals correctly', 390 === $gwc_vt_march->verified_minutes, (string) $gwc_vt_march->verified_minutes );

$gwc_vt_edge = gwc_vt_compute_totals( $gwc_vt_jane, array( 'from' => '2026-03-02', 'to' => '2026-03-02' ) );
gwc_vt_check( 'a range is inclusive at both ends', 1 === $gwc_vt_edge->entries, (string) $gwc_vt_edge->entries );

$gwc_vt_open = gwc_vt_compute_totals( $gwc_vt_jane, array( 'from' => '2026-04-01' ) );
gwc_vt_check( 'an open-ended range works', 1 === $gwc_vt_open->entries, (string) $gwc_vt_open->entries );

/* ── Trash ───────────────────────────────────────────────────────────────── */

$gwc_vt_doomed = gwc_vt_make_entry( $gwc_vt_jane, '2026-05-01', 60, true );
gwc_vt_check( 'a new entry counts', 450 === gwc_vt_compute_totals( $gwc_vt_jane )->verified_minutes );

wp_trash_post( $gwc_vt_doomed );
gwc_vt_check( 'a trashed entry stops counting', 390 === gwc_vt_compute_totals( $gwc_vt_jane )->verified_minutes );

/* ── The rollup cache ────────────────────────────────────────────────────── */

$gwc_vt_cached = gwc_vt_volunteer_totals( $gwc_vt_jane );
gwc_vt_check(
	'the cached rollup agrees with a fresh computation',
	$gwc_vt_cached->verified_minutes === gwc_vt_compute_totals( $gwc_vt_jane )->verified_minutes,
	(string) $gwc_vt_cached->verified_minutes
);

gwc_vt_make_entry( $gwc_vt_jane, '2026-05-04', 120, true );
gwc_vt_check(
	'saving an entry invalidates the rollup',
	510 === gwc_vt_volunteer_totals( $gwc_vt_jane )->verified_minutes,
	(string) gwc_vt_volunteer_totals( $gwc_vt_jane )->verified_minutes
);

/* ── The derived title ───────────────────────────────────────────────────── */

$gwc_vt_titled = gwc_vt_make_entry( $gwc_vt_jane, '2026-06-01', 210, false );
gwc_vt_retitle_entry( $gwc_vt_titled );

gwc_vt_check(
	'the title is derived from the shift',
	false !== strpos( get_the_title( $gwc_vt_titled ), 'Zzytest Jane Quimby' )
		&& false !== strpos( get_the_title( $gwc_vt_titled ), '2026-06-01' )
		&& false !== strpos( get_the_title( $gwc_vt_titled ), '3.5' ),
	get_the_title( $gwc_vt_titled )
);

/* ── REST ────────────────────────────────────────────────────────────────────
 * The two halves that matter: the purpose-built route works for staff, and the
 * auto-generated post type routes do not exist for anybody.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_routes = rest_get_server()->get_routes();

foreach ( array( GWC_VT_ENTRY_TYPE, GWC_VT_VOLUNTEER_TYPE ) as $gwc_vt_type ) {
	gwc_vt_check( '/wp/v2/' . $gwc_vt_type . ' is not registered', ! isset( $gwc_vt_routes[ '/wp/v2/' . $gwc_vt_type ] ) );

	$gwc_vt_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwc_vt_type ) );
	gwc_vt_check( '/wp/v2/' . $gwc_vt_type . ' 404s', 404 === $gwc_vt_response->get_status(), (string) $gwc_vt_response->get_status() );
}

// Logged out.
wp_set_current_user( 0 );
$gwc_vt_request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
$gwc_vt_request->set_param( 'search', 'Zzytest' );
$gwc_vt_response = rest_do_request( $gwc_vt_request );
gwc_vt_check( 'the lookup refuses a logged-out request', 401 === $gwc_vt_response->get_status() || 403 === $gwc_vt_response->get_status(), (string) $gwc_vt_response->get_status() );

// As an administrator.
wp_set_current_user( 1 );
$gwc_vt_request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
$gwc_vt_request->set_param( 'search', 'Zzytest' );
$gwc_vt_response = rest_do_request( $gwc_vt_request );
$gwc_vt_data     = $gwc_vt_response->get_data();

gwc_vt_check( 'the lookup answers staff', 200 === $gwc_vt_response->get_status(), (string) $gwc_vt_response->get_status() );
gwc_vt_check( 'the lookup found both volunteers', is_array( $gwc_vt_data ) && 2 === count( $gwc_vt_data ), (string) ( is_array( $gwc_vt_data ) ? count( $gwc_vt_data ) : -1 ) );

/* The assertion that matters most in this file. An endpoint returning volunteer
 * records sits one careless line away from returning the email address, the
 * phone number, or a court case number — the volunteer post carries all three.
 * Asserting the EXACT key set fails when something is added, which a
 * "does it contain id and label" check never would. */
$gwc_vt_keys = is_array( $gwc_vt_data ) && isset( $gwc_vt_data[0] ) ? array_keys( $gwc_vt_data[0] ) : array();
sort( $gwc_vt_keys );
gwc_vt_check(
	'the lookup returns id and label and nothing else',
	array( 'id', 'label' ) === $gwc_vt_keys,
	implode( ',', $gwc_vt_keys )
);

$gwc_vt_request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
$gwc_vt_request->set_param( 'search', 'a' );
gwc_vt_check( 'a one-character search is refused', 400 === rest_do_request( $gwc_vt_request )->get_status() );

$gwc_vt_request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
gwc_vt_check( 'a missing search term is refused', 400 === rest_do_request( $gwc_vt_request )->get_status() );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

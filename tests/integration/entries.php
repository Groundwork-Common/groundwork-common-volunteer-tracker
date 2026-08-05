<?php
/**
 * Logging hours, totalling them, and the REST lookup — against real WordPress.
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
$GLOBALS['gwcvt_failures'] = 0;

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

/* ── Fixtures ────────────────────────────────────────────────────────────── */

/* $GLOBALS again, for the reason above — and this one is why the note is worth
 * having twice. As a local, this array stayed empty while the helpers appended
 * to the global, so the cleanup loop at the bottom iterated nothing and every
 * run left its fixtures behind for the next one to find. */
$GLOBALS['gwcvt_made'] = array();

/**
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address.
 * @return int
 */
function gwcvt_make_volunteer( string $name, string $email = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWCVT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWCVT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwcvt_made'][] = $id;

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
function gwcvt_make_entry( int $volunteer_id, string $date, int $minutes, bool $verified = false ): int {
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
	update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $id, GWCVT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	if ( $verified ) {
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, GWCVT_ENTRY_VERIFIED_BY, 1 );
	}

	$GLOBALS['gwcvt_made'][] = $id;

	return (int) $id;
}

$gwcvt_jane = gwcvt_make_volunteer( 'Zzytest Jane Quimby', 'jane@example.test' );
$gwcvt_omar = gwcvt_make_volunteer( 'Zzytest Omar Delacroix' );

gwcvt_make_entry( $gwcvt_jane, '2026-03-02', 210, true );   // 3.5 h verified
gwcvt_make_entry( $gwcvt_jane, '2026-03-09', 180, true );   // 3 h   verified
gwcvt_make_entry( $gwcvt_jane, '2026-04-06', 90, false );   // 1.5 h pending
gwcvt_make_entry( $gwcvt_omar, '2026-03-02', 600, true );   // someone else entirely

/* ── Totals ──────────────────────────────────────────────────────────────── */

$gwcvt_totals = gwcvt_compute_totals( $gwcvt_jane );

gwcvt_check( 'verified minutes', 390 === $gwcvt_totals->verified_minutes, (string) $gwcvt_totals->verified_minutes );
gwcvt_check( 'pending minutes', 90 === $gwcvt_totals->pending_minutes, (string) $gwcvt_totals->pending_minutes );
gwcvt_check( 'entry count', 3 === $gwcvt_totals->entries, (string) $gwcvt_totals->entries );
gwcvt_check( 'total minutes', 480 === $gwcvt_totals->total_minutes(), (string) $gwcvt_totals->total_minutes() );
gwcvt_check( 'first shift', '2026-03-02' === $gwcvt_totals->first, $gwcvt_totals->first );
gwcvt_check( 'last shift', '2026-04-06' === $gwcvt_totals->last, $gwcvt_totals->last );

gwcvt_check(
    'another volunteer’s hours are not included',
    600 === gwcvt_compute_totals( $gwcvt_omar )->verified_minutes
);

/* ── Date ranges ─────────────────────────────────────────────────────────── */

$gwcvt_march = gwcvt_compute_totals( $gwcvt_jane, array( 'from' => '2026-03-01', 'to' => '2026-03-31' ) );
gwcvt_check( 'a date range excludes April', 2 === $gwcvt_march->entries, (string) $gwcvt_march->entries );
gwcvt_check( 'a date range totals correctly', 390 === $gwcvt_march->verified_minutes, (string) $gwcvt_march->verified_minutes );

$gwcvt_edge = gwcvt_compute_totals( $gwcvt_jane, array( 'from' => '2026-03-02', 'to' => '2026-03-02' ) );
gwcvt_check( 'a range is inclusive at both ends', 1 === $gwcvt_edge->entries, (string) $gwcvt_edge->entries );

$gwcvt_open = gwcvt_compute_totals( $gwcvt_jane, array( 'from' => '2026-04-01' ) );
gwcvt_check( 'an open-ended range works', 1 === $gwcvt_open->entries, (string) $gwcvt_open->entries );

/* ── Trash ───────────────────────────────────────────────────────────────── */

$gwcvt_doomed = gwcvt_make_entry( $gwcvt_jane, '2026-05-01', 60, true );
gwcvt_check( 'a new entry counts', 450 === gwcvt_compute_totals( $gwcvt_jane )->verified_minutes );

wp_trash_post( $gwcvt_doomed );
gwcvt_check( 'a trashed entry stops counting', 390 === gwcvt_compute_totals( $gwcvt_jane )->verified_minutes );

/* ── The rollup cache ────────────────────────────────────────────────────── */

$gwcvt_cached = gwcvt_volunteer_totals( $gwcvt_jane );
gwcvt_check(
	'the cached rollup agrees with a fresh computation',
	$gwcvt_cached->verified_minutes === gwcvt_compute_totals( $gwcvt_jane )->verified_minutes,
	(string) $gwcvt_cached->verified_minutes
);

gwcvt_make_entry( $gwcvt_jane, '2026-05-04', 120, true );
gwcvt_check(
	'saving an entry invalidates the rollup',
	510 === gwcvt_volunteer_totals( $gwcvt_jane )->verified_minutes,
	(string) gwcvt_volunteer_totals( $gwcvt_jane )->verified_minutes
);

/* ── The derived title ───────────────────────────────────────────────────── */

$gwcvt_titled = gwcvt_make_entry( $gwcvt_jane, '2026-06-01', 210, false );
gwcvt_retitle_entry( $gwcvt_titled );

gwcvt_check(
	'the title is derived from the shift',
	false !== strpos( get_the_title( $gwcvt_titled ), 'Zzytest Jane Quimby' )
		&& false !== strpos( get_the_title( $gwcvt_titled ), '2026-06-01' )
		&& false !== strpos( get_the_title( $gwcvt_titled ), '3.5' ),
	get_the_title( $gwcvt_titled )
);

/* ── REST ────────────────────────────────────────────────────────────────────
 * The two halves that matter: the purpose-built route works for staff, and the
 * auto-generated post type routes do not exist for anybody.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_routes = rest_get_server()->get_routes();

foreach ( array( GWCVT_ENTRY_TYPE, GWCVT_VOLUNTEER_TYPE ) as $gwcvt_type ) {
	gwcvt_check( '/wp/v2/' . $gwcvt_type . ' is not registered', ! isset( $gwcvt_routes[ '/wp/v2/' . $gwcvt_type ] ) );

	$gwcvt_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwcvt_type ) );
	gwcvt_check( '/wp/v2/' . $gwcvt_type . ' 404s', 404 === $gwcvt_response->get_status(), (string) $gwcvt_response->get_status() );
}

// Logged out.
wp_set_current_user( 0 );
$gwcvt_request = new WP_REST_Request( 'GET', '/gwcvt/v1/volunteers' );
$gwcvt_request->set_param( 'search', 'Zzytest' );
$gwcvt_response = rest_do_request( $gwcvt_request );
gwcvt_check( 'the lookup refuses a logged-out request', 401 === $gwcvt_response->get_status() || 403 === $gwcvt_response->get_status(), (string) $gwcvt_response->get_status() );

// As an administrator.
wp_set_current_user( 1 );
$gwcvt_request = new WP_REST_Request( 'GET', '/gwcvt/v1/volunteers' );
$gwcvt_request->set_param( 'search', 'Zzytest' );
$gwcvt_response = rest_do_request( $gwcvt_request );
$gwcvt_data     = $gwcvt_response->get_data();

gwcvt_check( 'the lookup answers staff', 200 === $gwcvt_response->get_status(), (string) $gwcvt_response->get_status() );
gwcvt_check( 'the lookup found both volunteers', is_array( $gwcvt_data ) && 2 === count( $gwcvt_data ), (string) ( is_array( $gwcvt_data ) ? count( $gwcvt_data ) : -1 ) );

/* The assertion that matters most in this file. An endpoint returning volunteer
 * records sits one careless line away from returning the email address, the
 * phone number, or a court case number — the volunteer post carries all three.
 * Asserting the EXACT key set fails when something is added, which a
 * "does it contain id and label" check never would. */
$gwcvt_keys = is_array( $gwcvt_data ) && isset( $gwcvt_data[0] ) ? array_keys( $gwcvt_data[0] ) : array();
sort( $gwcvt_keys );
gwcvt_check(
	'the lookup returns id and label and nothing else',
	array( 'id', 'label' ) === $gwcvt_keys,
	implode( ',', $gwcvt_keys )
);

$gwcvt_request = new WP_REST_Request( 'GET', '/gwcvt/v1/volunteers' );
$gwcvt_request->set_param( 'search', 'a' );
gwcvt_check( 'a one-character search is refused', 400 === rest_do_request( $gwcvt_request )->get_status() );

$gwcvt_request = new WP_REST_Request( 'GET', '/gwcvt/v1/volunteers' );
gwcvt_check( 'a missing search term is refused', 400 === rest_do_request( $gwcvt_request )->get_status() );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( $gwcvt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwcvt_failures'] > 0 ) {
	exit( 1 );
}

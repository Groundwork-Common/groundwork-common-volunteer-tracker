<?php
/**
 * The dashboard's counts read every row, not just the first page.
 *
 * The unit suite stubs WordPress and cannot answer this: paging is a property of
 * the database, and the failure it guards against — a walk that stops early, or
 * one that repeats rows because the order is not total — only appears against
 * real SQL.
 *
 * Both numbers this covers are printed on the dashboard, and one of them is
 * described in inc/dashboard.php as "what goes into a Form 990 or a grant
 * report". Both used to pass a large posts_per_page and take whatever came back,
 * which silently reported a smaller organisation than the one running the site
 * once the cap was reached.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/dashboard-paging.php
 *
 * @package VolunteerTracker
 */

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

	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $got ? "  ($got)" : '' );
}

/* ── Fixtures ────────────────────────────────────────────────────────────────
 * Every entry is inserted with the SAME post_date, on purpose. That is the
 * condition the first version of the walker got wrong: get_posts() orders by
 * post_date by default, ties are broken however MySQL likes on any given query,
 * and offset paging over a non-total order returns some rows twice and others
 * never. Identical timestamps are not contrived — a seed run, a CSV import, or
 * a coordinator logging a morning's worth of shifts all produce them.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Paging Fixture',
	)
);

$GLOBALS['gwc_vt_made'][] = $gwc_vt_volunteer;

/* A date in the past, and that matters. wp_insert_post() silently rewrites a
 * 'publish' post whose post_date is in the future to the 'future' status, so a
 * fixture dated next decade is invisible to any query filtering on publish —
 * which reads exactly like a walker that returns nothing. */
$gwc_vt_when     = '2017-03-04 09:00:00';
$gwc_vt_day      = '2017-03-04';
$gwc_vt_from     = '2017-01-01';
$gwc_vt_to       = '2017-12-31';
$gwc_vt_expected = array();
$gwc_vt_minutes  = 0;

/* Whatever is already in that window, so the assertions hold on a site that has
 * records there rather than only on an empty one. */
delete_transient( GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_from . '|' . $gwc_vt_to ) );
$gwc_vt_baseline = gwc_vt_org_totals( $gwc_vt_from, $gwc_vt_to );

for ( $gwc_vt_i = 0; $gwc_vt_i < 7; $gwc_vt_i++ ) {
	$gwc_vt_entry = wp_insert_post(
		array(
			'post_type'     => GWC_VT_ENTRY_TYPE,
			'post_status'   => 'publish',
			'post_title'    => 'paging fixture ' . $gwc_vt_i,
			'post_date'     => $gwc_vt_when,
			'post_date_gmt' => $gwc_vt_when,
		)
	);

	update_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_DATE, $gwc_vt_day );
	update_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_MINUTES, 60 );
	update_post_meta( $gwc_vt_entry, GWC_VT_ENTRY_VOLUNTEER, (string) $gwc_vt_volunteer );

	$GLOBALS['gwc_vt_made'][] = $gwc_vt_entry;
	$gwc_vt_expected[]        = (int) $gwc_vt_entry;
	$gwc_vt_minutes          += 60;
}

sort( $gwc_vt_expected );

printf( "%d entries, all sharing one post_date.\n\n", count( $gwc_vt_expected ) );

/* ── The walk, at every page size that straddles the boundary ───────────────── */

$gwc_vt_args = array(
	'post_type'   => GWC_VT_ENTRY_TYPE,
	'post_status' => array( 'publish', 'pending' ),
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a fixture, scoped to this run's own rows.
	'meta_key'    => GWC_VT_ENTRY_DATE,
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
	'meta_value'  => $gwc_vt_day,
);

foreach ( array( 1, 2, 3, 6, 7, 8 ) as $gwc_vt_page ) {
	$gwc_vt_seen = array();

	gwc_vt_walk_matching_ids(
		$gwc_vt_args,
		static function ( int $id ) use ( &$gwc_vt_seen ): void {
			$gwc_vt_seen[] = $id;
		},
		$gwc_vt_page
	);

	sort( $gwc_vt_seen );

	gwc_vt_check(
		sprintf( 'page size %d walks every row exactly once', $gwc_vt_page ),
		$gwc_vt_seen === $gwc_vt_expected,
		sprintf( 'walked %d, unique %d, expected %d', count( $gwc_vt_seen ), count( array_unique( $gwc_vt_seen ) ), count( $gwc_vt_expected ) )
	);
}

/* ── And the figure itself ──────────────────────────────────────────────────── */

delete_transient( GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_from . '|' . $gwc_vt_to ) );
$gwc_vt_totals = gwc_vt_org_totals( $gwc_vt_from, $gwc_vt_to );

gwc_vt_check(
	'the year total counts every entry',
	(int) $gwc_vt_baseline['entries'] + count( $gwc_vt_expected ) === (int) $gwc_vt_totals['entries'],
	sprintf( 'entries=%d expected=%d', $gwc_vt_totals['entries'], (int) $gwc_vt_baseline['entries'] + count( $gwc_vt_expected ) )
);

gwc_vt_check(
	'the year total sums every minute',
	(int) $gwc_vt_baseline['pending'] + $gwc_vt_minutes === (int) $gwc_vt_totals['pending'],
	sprintf( 'pending=%d expected=%d', $gwc_vt_totals['pending'], (int) $gwc_vt_baseline['pending'] + $gwc_vt_minutes )
);

/* ── Clean up ───────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

delete_transient( GWC_VT_YEAR_TOTALS_KEY . '_' . md5( $gwc_vt_from . '|' . $gwc_vt_to ) );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

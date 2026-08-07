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

$gwcvt_volunteer = wp_insert_post(
	array(
		'post_type'   => GWCVT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Paging Fixture',
	)
);

$GLOBALS['gwcvt_made'][] = $gwcvt_volunteer;

/* A date in the past, and that matters. wp_insert_post() silently rewrites a
 * 'publish' post whose post_date is in the future to the 'future' status, so a
 * fixture dated next decade is invisible to any query filtering on publish —
 * which reads exactly like a walker that returns nothing. */
$gwcvt_when     = '2017-03-04 09:00:00';
$gwcvt_day      = '2017-03-04';
$gwcvt_from     = '2017-01-01';
$gwcvt_to       = '2017-12-31';
$gwcvt_expected = array();
$gwcvt_minutes  = 0;

/* Whatever is already in that window, so the assertions hold on a site that has
 * records there rather than only on an empty one. */
delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_from . '|' . $gwcvt_to ) );
$gwcvt_baseline = gwcvt_org_totals( $gwcvt_from, $gwcvt_to );

for ( $gwcvt_i = 0; $gwcvt_i < 7; $gwcvt_i++ ) {
	$gwcvt_entry = wp_insert_post(
		array(
			'post_type'     => GWCVT_ENTRY_TYPE,
			'post_status'   => 'publish',
			'post_title'    => 'paging fixture ' . $gwcvt_i,
			'post_date'     => $gwcvt_when,
			'post_date_gmt' => $gwcvt_when,
		)
	);

	update_post_meta( $gwcvt_entry, GWCVT_ENTRY_DATE, $gwcvt_day );
	update_post_meta( $gwcvt_entry, GWCVT_ENTRY_MINUTES, 60 );
	update_post_meta( $gwcvt_entry, GWCVT_ENTRY_VOLUNTEER, (string) $gwcvt_volunteer );

	$GLOBALS['gwcvt_made'][] = $gwcvt_entry;
	$gwcvt_expected[]        = (int) $gwcvt_entry;
	$gwcvt_minutes          += 60;
}

sort( $gwcvt_expected );

printf( "%d entries, all sharing one post_date.\n\n", count( $gwcvt_expected ) );

/* ── The walk, at every page size that straddles the boundary ───────────────── */

$gwcvt_args = array(
	'post_type'   => GWCVT_ENTRY_TYPE,
	'post_status' => array( 'publish', 'pending' ),
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a fixture, scoped to this run's own rows.
	'meta_key'    => GWCVT_ENTRY_DATE,
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
	'meta_value'  => $gwcvt_day,
);

foreach ( array( 1, 2, 3, 6, 7, 8 ) as $gwcvt_page ) {
	$gwcvt_seen = array();

	gwcvt_walk_matching_ids(
		$gwcvt_args,
		static function ( int $id ) use ( &$gwcvt_seen ): void {
			$gwcvt_seen[] = $id;
		},
		$gwcvt_page
	);

	sort( $gwcvt_seen );

	gwcvt_check(
		sprintf( 'page size %d walks every row exactly once', $gwcvt_page ),
		$gwcvt_seen === $gwcvt_expected,
		sprintf( 'walked %d, unique %d, expected %d', count( $gwcvt_seen ), count( array_unique( $gwcvt_seen ) ), count( $gwcvt_expected ) )
	);
}

/* ── And the figure itself ──────────────────────────────────────────────────── */

delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_from . '|' . $gwcvt_to ) );
$gwcvt_totals = gwcvt_org_totals( $gwcvt_from, $gwcvt_to );

gwcvt_check(
	'the year total counts every entry',
	(int) $gwcvt_baseline['entries'] + count( $gwcvt_expected ) === (int) $gwcvt_totals['entries'],
	sprintf( 'entries=%d expected=%d', $gwcvt_totals['entries'], (int) $gwcvt_baseline['entries'] + count( $gwcvt_expected ) )
);

gwcvt_check(
	'the year total sums every minute',
	(int) $gwcvt_baseline['pending'] + $gwcvt_minutes === (int) $gwcvt_totals['pending'],
	sprintf( 'pending=%d expected=%d', $gwcvt_totals['pending'], (int) $gwcvt_baseline['pending'] + $gwcvt_minutes )
);

/* ── Clean up ───────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwcvt_made'] as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( $gwcvt_from . '|' . $gwcvt_to ) );

echo "\n", ( 0 === $GLOBALS['gwcvt_failures'] ? "ALL PASS\n" : $GLOBALS['gwcvt_failures'] . " CHECK(S) FAILED\n" );

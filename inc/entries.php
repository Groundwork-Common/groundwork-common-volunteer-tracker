<?php
/**
 * Finding entries and adding them up.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Keeping the rollup honest ───────────────────────────────────────────────
 * The obvious wiring — recompute on save_post — is wrong in two ways that both
 * fail silently, and both were caught by tests/integration/entries.php rather
 * than by reading the code.
 *
 * First, save_post fires DURING wp_insert_post(), before any caller has had a
 * chance to write the entry's meta. On a new entry there is no volunteer to
 * recompute for yet, so the handler reads an empty _gwc_vt_volunteer, does
 * nothing, and the meta written on the next line never triggers anything. The
 * admin screen happens to work because gwc_vt_save_entry() writes meta and then
 * refreshes explicitly; an import, a WP-CLI script or another plugin does not.
 *
 * Second, deleted_post fires AFTER the post's meta has been deleted, so the
 * same read returns nothing and a deleted entry leaves the volunteer's total
 * including hours that no longer exist. That one is worse: it is wrong in the
 * direction of over-reporting, on the number a letter is built from.
 *
 * So invalidation hangs off the meta writes themselves, which is the one event
 * every path has in common, and the recompute is deferred to shutdown so that
 * saving six meta keys costs one recompute rather than six.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The entry meta that changes what a total comes to. */
const GWC_VT_TOTAL_AFFECTING_META = array(
	GWC_VT_ENTRY_VOLUNTEER,
	GWC_VT_ENTRY_DATE,
	GWC_VT_ENTRY_MINUTES,
	GWC_VT_ENTRY_VERIFIED_AT,
);

add_action( 'added_post_meta', 'gwc_vt_invalidate_from_meta', 10, 3 );
add_action( 'updated_post_meta', 'gwc_vt_invalidate_from_meta', 10, 3 );
add_action( 'deleted_post_meta', 'gwc_vt_invalidate_from_meta', 10, 3 );

/* Fires BEFORE the write, which is the only moment the outgoing volunteer ID is
 * still readable. Moving an entry from one volunteer to another changes two
 * totals, and without this the one it left keeps the hours forever. */
add_action( 'update_post_meta', 'gwc_vt_invalidate_previous_volunteer', 10, 4 );
add_action( 'delete_post_meta', 'gwc_vt_invalidate_previous_volunteer', 10, 3 );

/* Status changes do not touch meta, so they need their own trigger. A trashed
 * entry stops counting, which means trashing one changes a total. */
add_action( 'save_post_' . GWC_VT_ENTRY_TYPE, 'gwc_vt_invalidate_from_entry', 10, 1 );
add_action( 'trashed_post', 'gwc_vt_invalidate_from_entry', 10, 1 );
add_action( 'untrashed_post', 'gwc_vt_invalidate_from_entry', 10, 1 );

/* before_delete_post, not deleted_post: the meta saying whose entry this was is
 * gone by the time the latter runs. */
add_action( 'before_delete_post', 'gwc_vt_invalidate_from_entry', 10, 1 );

add_action( 'shutdown', 'gwc_vt_flush_dirty_totals' );

/* ── What this costs, honestly ───────────────────────────────────────────────
 * A busy food bank might log five thousand entries. That is five thousand
 * posts and roughly forty thousand rows in wp_postmeta.
 *
 * There is no index on meta_value, but there is one on meta_key(191). So
 * `meta_key = '_gwc_vt_volunteer' AND meta_value = '123'` scans the five
 * thousand rows carrying that key and filters them — single-digit milliseconds,
 * and still fine well past a hundred thousand entries. meta_query is genuinely
 * adequate here, and the shadow index somebody will eventually propose is not
 * worth the two places it would then be possible for the truth to live.
 *
 * The thing that DOES matter is the N+1. Fetching entry IDs and then reading
 * meta off each one is one query per entry; update_postmeta_cache() on the
 * whole set is one query for all of them. That single line is the difference
 * between two queries and five thousand.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every entry belonging to a volunteer.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param array $args         Optional. 'from' and 'to' as Y-m-d, 'statuses' as an array.
 * @return int[] Entry post IDs, with their meta primed.
 */
function gwc_vt_entry_ids_for_volunteer( int $volunteer_id, array $args = array() ): array {
	if ( $volunteer_id < 1 ) {
		return array();
	}

	$from     = (string) ( $args['from'] ?? '' );
	$to       = (string) ( $args['to'] ?? '' );
	$statuses = $args['statuses'] ?? array( 'publish', 'pending' );

	$query = array(
		'post_type'              => GWC_VT_ENTRY_TYPE,
		'post_status'            => (array) $statuses,
		'fields'                 => 'ids',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
		'ignore_sticky_posts'    => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed by meta_key; see the note above on why this is fast enough at this plugin's scale.
		'meta_key'               => GWC_VT_ENTRY_VOLUNTEER,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		'meta_value'             => (string) $volunteer_id,
	);

	/* A date range is a second clause, so the volunteer match moves into
	 * meta_query with it. Y-m-d sorts lexicographically the same way it sorts
	 * chronologically, which is the entire reason the date is stored as a
	 * string rather than a timestamp — BETWEEN on 'CHAR' does the right thing.
	 */
	if ( '' !== $from || '' !== $to ) {
		unset( $query['meta_key'], $query['meta_value'] );

		$clauses = array(
			array(
				'key'   => GWC_VT_ENTRY_VOLUNTEER,
				'value' => (string) $volunteer_id,
			),
		);

		if ( '' !== $from && '' !== $to ) {
			$clauses[] = array(
				'key'     => GWC_VT_ENTRY_DATE,
				'value'   => array( $from, $to ),
				'compare' => 'BETWEEN',
				'type'    => 'CHAR',
			);
		} elseif ( '' !== $from ) {
			$clauses[] = array(
				'key'     => GWC_VT_ENTRY_DATE,
				'value'   => $from,
				'compare' => '>=',
				'type'    => 'CHAR',
			);
		} else {
			$clauses[] = array(
				'key'     => GWC_VT_ENTRY_DATE,
				'value'   => $to,
				'compare' => '<=',
				'type'    => 'CHAR',
			);
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above.
		$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), $clauses );
	}

	$ids = get_posts( $query );
	$ids = array_map( 'intval', (array) $ids );

	/* The one line between this and an N+1. Everything downstream reads meta off
	 * these IDs; without this each read is its own query. */
	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return $ids;
}

/**
 * Has a staff member attested to this entry?
 *
 * The definition of "verified", for everything that reads one entry at a time:
 * gwc_vt_total_from_ids() below, the letter builder, and the organization-wide
 * figures on the dashboard.
 *
 * Two more copies of this test exist and cannot call this, because they are
 * SQL rather than PHP — the EXISTS/NOT EXISTS pairs in gwc_vt_verify_queue_ids()
 * (inc/verify.php) and in the hours list's filter (inc/admin-verify.php). Adding
 * a condition here — a revocation flag, a check that GWC_VT_ENTRY_VERIFIED_METHOD
 * is still a registered method — means changing those two by hand or the queue
 * will offer entries the totals already count.
 *
 * @param int $entry_id Entry post ID.
 * @return bool
 */
function gwc_vt_entry_is_verified( int $entry_id ): bool {
	return '' !== (string) get_post_meta( $entry_id, GWC_VT_ENTRY_VERIFIED_AT, true );
}

/**
 * How this entry was attested to.
 *
 * An absent value reads as 'staff' rather than as unknown. That is what makes
 * emailed supervisor confirmation addable later without touching a single
 * existing row — see the note on the constant in inc/cpt.php.
 *
 * @param int $entry_id Entry post ID.
 * @return string
 */
function gwc_vt_entry_method( int $entry_id ): string {
	$method = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_VERIFIED_METHOD, true );
	return '' !== $method ? $method : 'staff';
}

/**
 * Add up a set of entries.
 *
 * Takes IDs rather than fetching them, so the letter and the rollup can run the
 * same arithmetic over different queries and cannot disagree about what
 * "verified" means.
 *
 * @param int[] $entry_ids Entry post IDs, meta already primed.
 * @return GWC_VT_Totals
 */
function gwc_vt_total_from_ids( array $entry_ids ): GWC_VT_Totals {
	$verified = 0;
	$pending  = 0;
	$dates    = array();

	foreach ( $entry_ids as $entry_id ) {
		$entry_id = (int) $entry_id;
		$minutes  = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );
		$date     = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true );

		if ( '' !== $date ) {
			$dates[] = $date;
		}

		if ( gwc_vt_entry_is_verified( $entry_id ) ) {
			$verified += $minutes;
		} else {
			$pending += $minutes;
		}
	}

	sort( $dates );

	return new GWC_VT_Totals(
		$verified,
		$pending,
		count( $entry_ids ),
		$dates ? (string) reset( $dates ) : '',
		$dates ? (string) end( $dates ) : '',
		time()
	);
}

/**
 * Compute a volunteer's totals from the entries themselves.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param array $args         Passed to gwc_vt_entry_ids_for_volunteer().
 * @return GWC_VT_Totals
 */
function gwc_vt_compute_totals( int $volunteer_id, array $args = array() ): GWC_VT_Totals {
	return gwc_vt_total_from_ids( gwc_vt_entry_ids_for_volunteer( $volunteer_id, $args ) );
}

/* ── The rollup cache, and the line it does not cross ────────────────────────
 * gwc_vt_volunteer_totals() reads a cached figure off the volunteer record. It
 * exists for the screens that show many volunteers at once — the volunteer
 * list, the Letters picker — where recomputing would be one query per row.
 *
 * The letter never reads it. gwc_vt_build_letter() calls gwc_vt_compute_totals()
 * every time, over the entries themselves.
 *
 * That is not an oversight to be tidied up later. A letter is produced perhaps
 * twice a year per volunteer and its correctness is the entire product; two
 * queries is not a price worth thinking about. A cached number that is subtly
 * stale is exactly the failure this plugin cannot have, because the person
 * holding the letter has no way to know.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A volunteer's totals, from the cache, computing them if it is missing.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return GWC_VT_Totals
 */
function gwc_vt_volunteer_totals( int $volunteer_id ): GWC_VT_Totals {
	/* Something changed this request and the recompute is still queued for
	 * shutdown. Reading the stored figure here would hand back a number this
	 * request has already invalidated — which is exactly the "saved it and the
	 * screen still shows the old total" bug, and it is worth two queries to not
	 * have it. */
	if ( ! empty( $GLOBALS['gwc_vt_dirty_totals'][ $volunteer_id ] ) ) {
		return gwc_vt_refresh_totals( $volunteer_id );
	}

	$stored = get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_TOTALS, true );

	if ( is_array( $stored ) && isset( $stored['computed_at'] ) ) {
		return GWC_VT_Totals::from_array( $stored );
	}

	return gwc_vt_refresh_totals( $volunteer_id );
}

/**
 * Recompute and store a volunteer's totals.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return GWC_VT_Totals
 */
function gwc_vt_refresh_totals( int $volunteer_id ): GWC_VT_Totals {
	$totals = gwc_vt_compute_totals( $volunteer_id );

	if ( $volunteer_id > 0 ) {
		update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_TOTALS, $totals->to_array() );
	}

	/* No longer dirty. Without this a caller that refreshes explicitly still
	 * leaves the volunteer queued, and shutdown recomputes it a second time. */
	unset( $GLOBALS['gwc_vt_dirty_totals'][ $volunteer_id ] );

	return $totals;
}

/**
 * Note that a volunteer's totals need recomputing, without doing it yet.
 *
 * Deduplicated, so a save that writes six meta keys queues one recompute.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_mark_totals_dirty( int $volunteer_id ): void {
	if ( $volunteer_id < 1 ) {
		return;
	}

	$GLOBALS['gwc_vt_dirty_totals'][ $volunteer_id ] = true;
}

/**
 * Recompute everything queued during this request.
 *
 * On shutdown, which fires after a redirect's headers have gone out but while
 * PHP is still running — so the person who pressed Update is not waiting on it,
 * and the next page load reads a fresh figure.
 */
function gwc_vt_flush_dirty_totals(): void {
	$dirty = $GLOBALS['gwc_vt_dirty_totals'] ?? array();

	/* Cleared before the loop, not after. gwc_vt_refresh_totals() writes post
	 * meta, which fires the very hooks that populate this list — and a set that
	 * refilled itself as it was being drained would recompute forever. */
	$GLOBALS['gwc_vt_dirty_totals'] = array();

	foreach ( array_keys( $dirty ) as $volunteer_id ) {
		gwc_vt_refresh_totals( (int) $volunteer_id );
	}
}

/**
 * An entry's meta changed.
 *
 * @param int    $meta_id  Ignored.
 * @param int    $post_id  The post the meta belongs to.
 * @param string $meta_key Which key changed.
 */
function gwc_vt_invalidate_from_meta( $meta_id, $post_id, $meta_key ): void {
	if ( ! in_array( (string) $meta_key, GWC_VT_TOTAL_AFFECTING_META, true ) ) {
		return;
	}

	gwc_vt_invalidate_from_entry( $post_id );
}

/**
 * An entry is about to be reassigned — remember who is losing the hours.
 *
 * @param int|array $meta_ids Ignored.
 * @param int       $post_id  The post the meta belongs to.
 * @param string    $meta_key Which key is being written.
 */
function gwc_vt_invalidate_previous_volunteer( $meta_ids, $post_id, $meta_key ): void {
	if ( GWC_VT_ENTRY_VOLUNTEER !== (string) $meta_key ) {
		return;
	}

	$post_id = (int) $post_id;

	if ( get_post_type( $post_id ) !== GWC_VT_ENTRY_TYPE ) {
		return;
	}

	gwc_vt_mark_totals_dirty( (int) get_post_meta( $post_id, GWC_VT_ENTRY_VOLUNTEER, true ) );
}

/**
 * Queue a recompute for whichever volunteer an entry belongs to.
 *
 * @param int $post_id The entry that changed.
 */
function gwc_vt_invalidate_from_entry( $post_id ): void {
	$post_id = (int) $post_id;

	if ( get_post_type( $post_id ) !== GWC_VT_ENTRY_TYPE ) {
		return;
	}

	gwc_vt_mark_totals_dirty( (int) get_post_meta( $post_id, GWC_VT_ENTRY_VOLUNTEER, true ) );
}

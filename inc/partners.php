<?php
/**
 * Reading partners, and the two operations that change one.
 *
 * The queries are ordinary. The merge is not, and it is most of this file.
 *
 * ── WordPress does not merge terms, and it is not close ──────────────────────
 * This was written down as a thing core gave us for free, and it is worth
 * spelling out how wrong that was, because the belief is reasonable and the
 * screen looks like it ought to have the feature:
 *
 *   - There is no `merge` anywhere in wp-admin/edit-tags.php or in
 *     wp-admin/includes/class-wp-terms-list-table.php. No UI, and no API.
 *   - Renaming a near-duplicate onto the real one FAILS rather than merging.
 *     wp_update_term() returns duplicate_term_slug (wp-includes/taxonomy.php:3376)
 *     and wp_insert_term() returns term_exists (:2604).
 *
 * So the single operation that keeps an aggregation key an aggregation key is
 * ours. Without it the taxonomy degrades into free text one typo at a time,
 * which is the outcome a taxonomy was chosen to avoid.
 *
 * ── What a merge has to get right ────────────────────────────────────────────
 * Four things, and three of them are silent when wrong:
 *
 *   1. EVERY OBJECT TYPE. Written against volunteers alone, a merge leaves
 *      every entry relationship pointing at a term that has been deleted. This
 *      reads gwc_vt_partner_object_types() and get_objects_in_term(), which do not
 *      know or care which types are registered, so #211's entries are carried
 *      by this code unchanged.
 *
 *   2. THE UNION, NOT AN APPEND. wp_set_object_terms() with append = false
 *      REPLACES every term in the taxonomy. A volunteer holding the survivor
 *      and a loser must end with the survivor once — so the new set is computed
 *      from what they actually hold, minus the losers, plus the survivor.
 *
 *   3. CHILDREN. This taxonomy is hierarchical. wp_delete_term() reparents a
 *      deleted term's children to ITS parent, which silently moves a local
 *      chapter out from under the national body somebody just merged into.
 *      Children are moved deliberately, before anything is deleted.
 *
 *   4. TERM META IS CHOSEN, NEVER GUESSED. Two terms with two different CRM IDs
 *      is precisely the case where somebody has selected the wrong pair. The
 *      caller passes what to keep; this function never picks. A silent
 *      correction on save is a bug even when the correction is right.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Reading ─────────────────────────────────────────────────────────────── */

/**
 * Every partner, alphabetically.
 *
 * @param array $args Passed through to get_terms(); 'hide_empty' defaults off,
 *                    because a partner defined this morning has nobody on
 *                    it yet and must still be pickable.
 * @return WP_Term[]
 */
function gwc_vt_partner_terms( array $args = array() ): array {
	$terms = get_terms(
		array_merge(
			array(
				'taxonomy'   => GWC_VT_PARTNER_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			),
			$args
		)
	);

	return is_wp_error( $terms ) ? array() : (array) $terms;
}

/** How many partners a page of the list holds. */
const GWC_VT_PARTNERS_PER_PAGE = 20;

/**
 * One page of partners, and how many there are in total.
 *
 * ── Why the search is core's and not this file's own ─────────────────────────
 * The list used to be filtered in PHP against gwc_vt_partner_normalize(), the
 * same shape the duplicate finder compares — so "acme corp" found "ACME Corp.".
 * That is a nice property and it cannot be paged: normalizing happens after the
 * rows are already in memory, which is the thing paging exists to avoid.
 *
 * get_terms()'s own `search` is a LIKE over the name and the slug, and MySQL's
 * default collation is case-insensitive, so "acme corp" still finds "ACME Corp."
 * and "Acme Corporation". What it no longer finds is a match that differs by
 * punctuation alone — "acmecorp" for "Acme Corp." — and the duplicate finder,
 * which still normalizes, is the thing that catches those and offers them.
 *
 * @param array $args page (1-based), per_page, search.
 * @return array{terms: WP_Term[], total: int}
 */
function gwc_vt_partner_page( array $args = array() ): array {
	$per_page = max( 1, (int) ( $args['per_page'] ?? GWC_VT_PARTNERS_PER_PAGE ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$search   = trim( (string) ( $args['search'] ?? '' ) );

	$query = array(
		'taxonomy'   => GWC_VT_PARTNER_TAXONOMY,
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	);

	if ( '' !== $search ) {
		$query['search'] = $search;
	}

	$total = wp_count_terms( array_merge( $query, array( 'fields' => 'count' ) ) );
	$total = is_wp_error( $total ) ? 0 : (int) $total;

	$terms = get_terms(
		array_merge(
			$query,
			array(
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		)
	);

	return array(
		'terms' => is_wp_error( $terms ) ? array() : (array) $terms,
		'total' => $total,
	);
}

/**
 * One partner, or null.
 *
 * @param int $term_id Term ID.
 * @return WP_Term|null
 */
function gwc_vt_partner( int $term_id ): ?WP_Term {
	if ( $term_id < 1 ) {
		return null;
	}

	$term = get_term( $term_id, GWC_VT_PARTNER_TAXONOMY );

	return $term instanceof WP_Term ? $term : null;
}

/**
 * The four fields on one partner.
 *
 * @param int $term_id Term ID.
 * @return array<string, string> Meta key => value, every key present.
 */
function gwc_vt_partner_field_values( int $term_id ): array {
	$values = array();

	foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
		$values[ $key ] = (string) get_term_meta( $term_id, $key, true );
	}

	return $values;
}

/**
 * Write a partner's details.
 *
 * The one function that writes this meta, so sanitizing happens once and the
 * merge screen and the term editor cannot drift apart on what is allowed. The
 * same rule gwc_vt_set_shift_credentials() follows next door, and for the same
 * reason CLAUDE.md records about it.
 *
 * Deletes rather than storing an empty string, so "never filled in" and
 * "cleared" look the same to everything downstream.
 *
 * @param int   $term_id Term ID.
 * @param array $values  Meta key => raw value. Keys not present are untouched.
 * @return bool Whether the term was real.
 */
function gwc_vt_set_partner_fields( int $term_id, array $values ): bool {
	if ( ! gwc_vt_partner( $term_id ) ) {
		return false;
	}

	foreach ( gwc_vt_partner_fields() as $key => $field ) {
		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}

		$clean = 'email' === $field['type']
			? gwc_vt_sanitize_partner_email( $values[ $key ] )
			: gwc_vt_sanitize_partner_text( $values[ $key ] );

		if ( '' === $clean ) {
			delete_term_meta( $term_id, $key );
			continue;
		}

		update_term_meta( $term_id, $key, $clean );
	}

	return true;
}

/**
 * The query that means "carrying this partner".
 *
 * ── One definition, because a count and its link must agree ──────────────────
 * This existed twice for about an hour, and the two copies disagreed on one
 * word: the count passed include_children => false and the list-table filter
 * passed true. So a national body with two local chapters showed "3 volunteers"
 * over a list of five people, and nothing anywhere said why.
 *
 * That is precisely the trap CLAUDE.md records — the dashboard counting overdue
 * volunteers with one function and linking to an unfiltered list, the nag
 * counting event slots and linking to a view that excluded them. Where a screen
 * acts on a count, it filters by the same function that produced it.
 *
 * Children are INCLUDED, which is the answer the hierarchy exists to give: if a
 * site has bothered to say Riverside Chapter is part of the National Trust,
 * then the National Trust's people are the chapter's people too.
 *
 * @param int $term_id Term ID.
 * @return array A tax_query, ready for WP_Query.
 */
function gwc_vt_partner_query( int $term_id ): array {
	return array(
		array(
			'taxonomy'         => GWC_VT_PARTNER_TAXONOMY,
			'field'            => 'term_id',
			'terms'            => $term_id,
			'include_children' => true,
		),
	);
}

/**
 * The statuses a count must look at.
 *
 * ── 'any' is not "any", and this is the second time it has bitten ───────────
 * WP_Query's 'any' means every status whose exclude_from_search is false — so
 * it silently drops gwc_vt_vol_inactive, which is how this plugin records
 * somebody who has stopped coming. Their hours stay, their name stays, and they
 * are still on the volunteer list's All view, because gwc_vt_volunteer sets
 * show_in_admin_all_list => true on that status deliberately.
 *
 * So a count written with 'any' reports fewer people than the list it links to
 * shows, with no way to tell from either screen. That is the trap CLAUDE.md
 * records about post_status 'any' AND the one it records about a count and the
 * screen it links to, at the same time.
 *
 * Derived rather than listed: show_in_admin_all_list is exactly the question
 * "does the All view show this", which is exactly what the link opens. A status
 * added later is covered without anybody editing this.
 *
 * @return string[]
 */
function gwc_vt_partner_count_statuses(): array {
	return array_values( get_post_stati( array( 'show_in_admin_all_list' => true ) ) );
}

/**
 * How many of each kind of thing carry this partner.
 *
 * ── Why not the term's own count column ──────────────────────────────────────
 * WP_Term::$count is one number across every object type in the taxonomy. Today
 * that is volunteers and the number means something. The moment #211 registers
 * entries as well it becomes volunteers PLUS entries added together, which is
 * not a quantity of anything — and the terms list table will go on printing it
 * under a heading that says "Count".
 *
 * So every screen in this plugin reads this instead, and says which is which.
 *
 * @param int      $term_id Term ID.
 * @param string[] $types   Optional. Only these object types; all of them by default.
 * @return array<string, int> Post type => how many.
 */
function gwc_vt_partner_counts( int $term_id, array $types = array() ): array {
	$counts = array();

	/* Callers that show one number ask for one. The list draws a row per partner
	 * and prints only the volunteer count, so asking for every registered type
	 * there was a query per row for a figure nothing rendered. */
	$wanted = $types ? array_intersect( gwc_vt_partner_object_types(), $types ) : gwc_vt_partner_object_types();

	foreach ( $wanted as $type ) {
		/* posts_per_page => 1 and found_posts, rather than fetching every ID and
		 * counting them in PHP. This runs once per row on a screen that lists
		 * every partner a site has, and the difference is a SQL COUNT against
		 * dragging every volunteer's ID into memory to call count() on it. */
		$found = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => gwc_vt_partner_count_statuses(),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => gwc_vt_partner_query( $term_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- counting one term on one type; there is no other way to ask it.
			)
		);

		$counts[ $type ] = (int) $found->found_posts;
	}

	return $counts;
}

/* ── What a partner actually contributed ─────────────────────────────────────
 * The question the whole feature exists to answer, and the one place it is
 * answered. Everything about the shape of this is a rule written down
 * elsewhere in the plugin, gathered here because this is where they all land at
 * once.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The statuses an hours total counts.
 *
 * The same two gwc_vt_org_totals() uses for the organization's own figure in
 * inc/dashboard.php — the number that goes into a Form 990 or a grant report.
 * A partner's contribution and the organization's own total are the same kind
 * of claim about the same records, and two different status sets would mean the
 * parts did not add up to the whole.
 *
 * @return string[]
 */
function gwc_vt_partner_hours_statuses(): array {
	return array( 'publish', 'pending' );
}

/**
 * The entries attributed to one partner.
 *
 * @param int   $term_id Term ID.
 * @param array $args    from, to (Y-m-d). Either may be empty for open-ended.
 * @return int[] Entry post IDs.
 */
function gwc_vt_partner_entry_ids( int $term_id, array $args = array() ): array {
	if ( ! gwc_vt_partner( $term_id ) ) {
		return array();
	}

	$from = (string) ( $args['from'] ?? '' );
	$to   = (string) ( $args['to'] ?? '' );

	$query = array(
		'post_type'              => GWC_VT_ENTRY_TYPE,
		'post_status'            => gwc_vt_partner_hours_statuses(),
		'update_post_term_cache' => false,
		'tax_query'              => gwc_vt_partner_query( $term_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term on one type; there is no other way to ask it.
	);

	if ( '' !== $from || '' !== $to ) {
		$range = array(
			'key'     => GWC_VT_ENTRY_DATE,
			'type'    => 'CHAR',
			'compare' => 'BETWEEN',
			'value'   => array( '' !== $from ? $from : '0000-00-00', '' !== $to ? $to : '9999-12-31' ),
		);

		$query['meta_query'] = array( $range ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed range on the entry's own date, which is the only date anybody means.
	}

	$found = array();

	/* Walked in batches rather than fetched at once, and ordered by ID inside
	 * gwc_vt_walk_matching_ids() for the reason its own comment gives: offset
	 * paging is only correct over a total order, and post_date is not one. */
	gwc_vt_walk_matching_ids(
		$query,
		static function ( int $entry_id ) use ( &$found ): void {
			$found[] = $entry_id;
		}
	);

	return $found;
}

/**
 * What one partner contributed.
 *
 * ── The arithmetic is not this function's ────────────────────────────────────
 * It collects IDs and hands them to gwc_vt_total_from_ids(), which is what the
 * letter and every per-volunteer rollup use. That is deliberate and it is the
 * rule inc/entries.php states about itself: one definition of what "verified"
 * means, so a condition added to gwc_vt_entry_is_verified() reaches the letter,
 * the rollup, the dashboard and this together.
 *
 * A partner's hours were very nearly summed here with a fresh loop and a fresh
 * idea of the word. gwc_vt_org_totals() has the scar — it was the only reader
 * deciding for itself, and it says so in its own comment.
 *
 * ── Read from entries, never from volunteers ─────────────────────────────────
 * See gwc_vt_partner_object_types(). Somebody who came once with Acme and twice
 * alone has one term on their record and three entries; counting people would
 * attribute all three.
 *
 * Not cached. gwc_vt_org_totals() caches for an hour because it is drawn on the
 * dashboard on every load and answers a question about the whole year. This is
 * asked when somebody has gone looking for one partner, often just after
 * verifying something — which is the moment a stale figure is worst.
 *
 * @param int   $term_id Term ID.
 * @param array $args    from, to (Y-m-d).
 * @return GWC_VT_Totals
 */
function gwc_vt_partner_hours( int $term_id, array $args = array() ): GWC_VT_Totals {
	$ids = gwc_vt_partner_entry_ids( $term_id, $args );

	/* gwc_vt_total_from_ids() reads two meta keys per entry and its docblock
	 * says "meta already primed" — without this, a partner with four hundred
	 * entries is eight hundred queries.
	 *
	 * gwc_vt_walk_matching_ids() does prime each batch as it goes, so this is
	 * usually a no-op; update_meta_cache() only queries the IDs it has not
	 * already got. It is here anyway because depending on another function's
	 * internal caching is the kind of thing that is true until somebody
	 * reasonably changes it, and the failure would be silent and slow rather
	 * than wrong. */
	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return gwc_vt_total_from_ids( $ids );
}

/**
 * A URL for the volunteer list, filtered to one partner.
 *
 * The one place this link is built, so a count and the screen it opens cannot
 * disagree — the rule this plugin has about a number and what it links to.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function gwc_vt_partner_volunteers_url( int $term_id ): string {
	$term = gwc_vt_partner( $term_id );

	if ( ! $term ) {
		return '';
	}

	return add_query_arg(
		array(
			'post_type'           => GWC_VT_VOLUNTEER_TYPE,
			GWC_VT_PARTNER_FILTER => $term_id,
		),
		admin_url( 'edit.php' )
	);
}

/**
 * A URL for the hours list, filtered to one partner.
 *
 * The other half of the rule gwc_vt_partner_volunteers_url() keeps: the number
 * on the Partners screen and the screen it opens are built from one function,
 * so they cannot come to disagree.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function gwc_vt_partner_hours_url( int $term_id ): string {
	if ( ! gwc_vt_partner( $term_id ) ) {
		return '';
	}

	return add_query_arg(
		array(
			'post_type'           => GWC_VT_ENTRY_TYPE,
			GWC_VT_PARTNER_FILTER => $term_id,
		),
		admin_url( 'edit.php' )
	);
}

/**
 * The partners on one entry, as a sentence.
 *
 * Empty for a day somebody came on their own, which is most days — and that
 * emptiness is the point: an entry says who was there that Saturday, not who
 * the person is affiliated with in general.
 *
 * @param int $entry_id Entry post ID.
 * @return string
 */
function gwc_vt_entry_partner_names( int $entry_id ): string {
	$names = wp_get_object_terms( $entry_id, GWC_VT_PARTNER_TAXONOMY, array( 'fields' => 'names' ) );

	if ( is_wp_error( $names ) || ! $names ) {
		return '';
	}

	return implode( ', ', array_map( 'strval', (array) $names ) );
}

/* ── Finding the duplicates before somebody sums them ────────────────────── */

/**
 * One partner name, reduced to what it is actually called.
 *
 * For COMPARISON only. Nothing is ever stored in this shape and nothing is
 * displayed in it — it exists so that "Acme Corp", "ACME Corp." and "Acme
 * Corporation" land on the same string and can be offered as a merge.
 *
 * @param string $name As typed.
 * @return string
 */
function gwc_vt_partner_normalize( string $name ): string {
	$name = remove_accents( $name );
	$name = strtolower( $name );

	/* Before punctuation is stripped, or "Bread & Roses" and "Bread and Roses"
	 * reduce to "breadroses" and "breadandroses" and never meet. */
	$name = str_replace( '&', ' and ', $name );

	$name = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );
	$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

	/* A leading article, which people put in inconsistently and nobody means. */
	$name = (string) preg_replace( '/^the /', '', $name );

	/* And trailing legal forms, which are the single commonest way one
	 * partner ends up as two terms. Repeated because "Acme Co Ltd" carries
	 * two of them. */
	$suffixes = 'inc|incorporated|llc|llp|ltd|limited|co|company|corp|corporation|plc|gmbh|pty|nv|sa|foundation|trust';

	for ( $pass = 0; $pass < 3; $pass++ ) {
		$shorter = (string) preg_replace( '/ (' . $suffixes . ')$/', '', $name );

		if ( $shorter === $name ) {
			break;
		}

		$name = $shorter;
	}

	return trim( $name );
}

/**
 * Partners that look like the same partner.
 *
 * ── Exact match after normalizing, and deliberately nothing cleverer ─────────
 * Edit distance was considered and rejected. "Acme North" and "Acme South" are
 * two real places four characters apart in a ten-character string, and any
 * threshold loose enough to catch a genuine typo proposes merging them. A
 * proposal is a button somebody presses, the merge is irreversible, and the
 * failure would be two branches of a real partner silently becoming one.
 *
 * Normalizing catches the case that actually happens — punctuation, casing and
 * "Inc" — and it can explain itself on the screen, which a distance score
 * cannot.
 *
 * Nothing here merges anything. It proposes.
 *
 * @return array<string, WP_Term[]> Normalized name => the terms sharing it.
 */
function gwc_vt_partner_duplicate_clusters(): array {
	$by_shape = array();

	foreach ( gwc_vt_partner_terms() as $term ) {
		$shape = gwc_vt_partner_normalize( (string) $term->name );

		if ( '' === $shape ) {
			continue;
		}

		$by_shape[ $shape ][] = $term;
	}

	foreach ( array_keys( $by_shape ) as $shape ) {
		if ( count( $by_shape[ $shape ] ) < 2 ) {
			unset( $by_shape[ $shape ] );
		}
	}

	ksort( $by_shape );

	return $by_shape;
}

/**
 * Which of the four fields disagree across a set of partners.
 *
 * What the merge screen shows before it will do anything. Only values that are
 * actually present count as an opinion — three blanks and one telephone number
 * is not a conflict, it is the answer.
 *
 * @param int[] $term_ids Terms about to be merged.
 * @return array<string, string[]> Meta key => the distinct non-empty values.
 */
function gwc_vt_partner_field_conflicts( array $term_ids ): array {
	$conflicts = array();

	foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
		$values = array();

		foreach ( $term_ids as $term_id ) {
			$value = (string) get_term_meta( (int) $term_id, $key, true );

			if ( '' !== $value && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		if ( count( $values ) > 1 ) {
			$conflicts[ $key ] = $values;
		}
	}

	return $conflicts;
}

/**
 * What the survivor should end up holding, when nobody has had to choose.
 *
 * The survivor's own value where it has one, otherwise the first value any
 * loser offers. Only ever used for fields gwc_vt_partner_field_conflicts() did not
 * report — where there is a disagreement, the caller passes an explicit answer
 * and this is not consulted.
 *
 * @param int   $survivor_id Term that lives.
 * @param int[] $loser_ids   Terms that do not.
 * @return array<string, string>
 */
function gwc_vt_partner_merged_fields( int $survivor_id, array $loser_ids ): array {
	$merged = array();

	foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
		$value = (string) get_term_meta( $survivor_id, $key, true );

		if ( '' === $value ) {
			foreach ( $loser_ids as $loser_id ) {
				$offered = (string) get_term_meta( (int) $loser_id, $key, true );

				if ( '' !== $offered ) {
					$value = $offered;
					break;
				}
			}
		}

		$merged[ $key ] = $value;
	}

	return $merged;
}

/* ── The merge ───────────────────────────────────────────────────────────── */

/**
 * Fold several partners into one.
 *
 * @param int   $survivor_id The term that lives.
 * @param int[] $loser_ids   The terms that do not.
 * @param array $fields      Meta key => the value to keep. Anything omitted
 *                           falls to gwc_vt_partner_merged_fields().
 * @return array|WP_Error What moved, or why nothing did.
 */
function gwc_vt_merge_partners( int $survivor_id, array $loser_ids, array $fields = array() ) {
	$survivor = gwc_vt_partner( $survivor_id );

	if ( ! $survivor ) {
		return new WP_Error(
			'gwc_vt_partner_missing',
			__( 'That partner no longer exists.', 'groundwork-common-volunteer-tracker' )
		);
	}

	/* Cleaned before anything is counted, so the report at the end describes
	 * what happened rather than what was asked for. */
	$losers = array();

	foreach ( $loser_ids as $loser_id ) {
		$loser_id = (int) $loser_id;

		if ( $loser_id === $survivor_id || ! gwc_vt_partner( $loser_id ) ) {
			continue;
		}

		$losers[] = $loser_id;
	}

	$losers = array_values( array_unique( $losers ) );

	if ( ! $losers ) {
		return new WP_Error(
			'gwc_vt_partner_nothing_to_merge',
			__( 'Choose at least one other partner to fold in.', 'groundwork-common-volunteer-tracker' )
		);
	}

	/* ── 1. The details, before the losers are gone ──────────────────────────
	 * Read from the terms that are about to be deleted, so it has to happen
	 * first. Explicit choices win; anything not in dispute falls back. */
	$keep = gwc_vt_partner_merged_fields( $survivor_id, $losers );

	foreach ( $fields as $key => $value ) {
		if ( array_key_exists( $key, $keep ) ) {
			$keep[ $key ] = $value;
		}
	}

	gwc_vt_set_partner_fields( $survivor_id, $keep );

	/* ── 2. The relationships, across every object type ──────────────────────
	 * get_objects_in_term() reads the relationship table and does not care what
	 * post type anything is, which is what makes this carry #211's entries
	 * without being edited.
	 *
	 * The new set is computed per object rather than appended, because
	 * wp_set_object_terms() with append = false replaces the lot — somebody
	 * holding both the survivor and a loser has to end with the survivor once,
	 * and their OTHER partners have to survive untouched. */
	$objects = get_objects_in_term( $losers, GWC_VT_PARTNER_TAXONOMY );
	$objects = is_wp_error( $objects ) ? array() : array_map( 'intval', (array) $objects );
	$objects = array_values( array_unique( $objects ) );

	$moved = 0;

	foreach ( $objects as $object_id ) {
		$held = wp_get_object_terms( $object_id, GWC_VT_PARTNER_TAXONOMY, array( 'fields' => 'ids' ) );
		$held = is_wp_error( $held ) ? array() : array_map( 'intval', (array) $held );

		$wanted = array_values( array_unique( array_merge( array_diff( $held, $losers ), array( $survivor_id ) ) ) );

		$result = wp_set_object_terms( $object_id, $wanted, GWC_VT_PARTNER_TAXONOMY );

		if ( ! is_wp_error( $result ) ) {
			++$moved;
		}
	}

	/* ── 3. The children, before anything is deleted ─────────────────────────
	 * wp_delete_term() reparents a deleted term's children to that term's
	 * PARENT, which quietly moves a local chapter out from under the national
	 * body somebody has just merged into. Moved deliberately instead.
	 *
	 * The survivor is skipped when it is itself a child of a loser — it cannot
	 * be its own parent — and is given the loser's parent below. */
	$reparented = 0;

	foreach ( $losers as $loser_id ) {
		$children = get_terms(
			array(
				'taxonomy'   => GWC_VT_PARTNER_TAXONOMY,
				'hide_empty' => false,
				'parent'     => $loser_id,
				'fields'     => 'ids',
			)
		);

		foreach ( ( is_wp_error( $children ) ? array() : (array) $children ) as $child_id ) {
			$child_id = (int) $child_id;

			if ( $child_id === $survivor_id || in_array( $child_id, $losers, true ) ) {
				continue;
			}

			wp_update_term( $child_id, GWC_VT_PARTNER_TAXONOMY, array( 'parent' => $survivor_id ) );
			++$reparented;
		}
	}

	/* The survivor's own place in the tree, when it was hanging off one of the
	 * terms about to disappear. Walk up past anything being merged, so
	 * merging a parent and a child together leaves the survivor where the
	 * parent was rather than orphaned or pointing at a deleted row. */
	$survivor = gwc_vt_partner( $survivor_id );
	$parent   = $survivor ? (int) $survivor->parent : 0;
	$guard    = 0;

	while ( $parent > 0 && in_array( $parent, $losers, true ) && $guard < 50 ) {
		$above  = gwc_vt_partner( $parent );
		$parent = $above ? (int) $above->parent : 0;
		++$guard;
	}

	if ( $survivor && (int) $survivor->parent !== $parent ) {
		wp_update_term( $survivor_id, GWC_VT_PARTNER_TAXONOMY, array( 'parent' => $parent ) );
	}

	/* ── 4. And only now are they gone ───────────────────────────────────── */
	$names = array();

	foreach ( $losers as $loser_id ) {
		$term = gwc_vt_partner( $loser_id );

		if ( $term ) {
			$names[] = (string) $term->name;
		}

		wp_delete_term( $loser_id, GWC_VT_PARTNER_TAXONOMY );
	}

	/* Counts are maintained by wp_set_object_terms() and wp_delete_term(), but
	 * both were called for several terms in a loop and the survivor's row was
	 * read in between. Cleared so the screen that renders next asks the
	 * database rather than showing a number from before the merge — the counts
	 * on that screen link to lists, and the two must agree. */
	clean_term_cache( array_merge( array( $survivor_id ), $losers ), GWC_VT_PARTNER_TAXONOMY );

	/**
	 * Fires after partners have been folded together.
	 *
	 * A site that mirrors this taxonomy into a CRM gets its chance here; the
	 * loser terms are already gone, which is why their names are passed.
	 *
	 * @param int      $survivor_id The term that lives.
	 * @param int[]    $loser_ids   The terms that were deleted.
	 * @param string[] $names       What those terms were called.
	 */
	do_action( 'gwc_vt_partners_merged', $survivor_id, $losers, $names );

	return array(
		'survivor'   => $survivor_id,
		'merged'     => count( $losers ),
		'names'      => $names,
		'objects'    => $moved,
		'reparented' => $reparented,
	);
}

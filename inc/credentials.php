<?php
/**
 * Who holds what, and whether it is still good.
 *
 * The query layer over inc/credential-cpt.php, in the same relation shifts.php
 * has to shift-cpt.php: the types and their meta keys are declared there, and
 * everything that asks a question about them lives here.
 *
 * The two functions that write are here rather than on the screen that calls
 * them, and deliberately: an earlier feature put its validation inside an admin
 * handler, and the tests written against it exercised a helper rather than the
 * thing that runs. Whether a date is usable and whether a credential is still
 * defined are decisions, not markup, so they are reachable without a screen.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** What a volunteer's standing on one credential can be. */
const GWC_VT_HOLDS_CURRENT = 'current';
const GWC_VT_HOLDS_EXPIRED = 'expired';
const GWC_VT_HOLDS_NEVER   = 'never';

/**
 * Every credential record belonging to a volunteer, newest grant first.
 *
 * Children by post_parent, which is what makes the eraser's job one query
 * rather than a walk over every credential that has ever been defined.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int[]
 */
function gwc_vt_credential_record_ids( int $volunteer_id ): array {
	if ( $volunteer_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_RECORD_TYPE,
			'post_parent'            => $volunteer_id,
			'post_status'            => 'publish',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded per volunteer, not site-wide: one row per grant of one credential to one person, so 200 is a volunteer who renewed something every quarter for fifty years. The eraser and the delete path both walk this list, and a page of 100 would silently leave the rest behind.
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	/* Sorted in PHP on the granted date rather than by SQL on post_date: the
	 * date somebody did the course is not the date it was typed in, and a
	 * coordinator entering six months of paperwork on a Tuesday would otherwise
	 * see them all in the wrong order. */
	usort(
		$ids,
		static function ( int $a, int $b ): int {
			return strcmp(
				(string) get_post_meta( $b, GWC_VT_RECORD_DATE, true ),
				(string) get_post_meta( $a, GWC_VT_RECORD_DATE, true )
			);
		}
	);

	return $ids;
}

/**
 * One record, as a plain array, with its expiry worked out.
 *
 * @param int $record_id Record post ID.
 * @return array Empty when it is not one of ours.
 */
function gwc_vt_credential_record( int $record_id ): array {
	if ( GWC_VT_RECORD_TYPE !== get_post_type( $record_id ) ) {
		return array();
	}

	$credential_id = (int) get_post_meta( $record_id, GWC_VT_RECORD_CREDENTIAL, true );
	$credential    = gwc_vt_credential( $credential_id );
	$date          = (string) get_post_meta( $record_id, GWC_VT_RECORD_DATE, true );

	return array(
		'id'         => $record_id,
		'volunteer'  => (int) wp_get_post_parent_id( $record_id ),
		'credential' => $credential_id,
		/* The definition may have been deleted out from under this record by
		 * somebody working in the database. The record still says what it says;
		 * it simply has nothing to be measured against, so it never counts as
		 * current. */
		'name'       => $credential ? $credential['name'] : '',
		'orphan'     => ! $credential,
		'date'       => $date,
		'expires'    => $credential ? gwc_vt_credential_expires_on( $date, $credential['months'] ) : '',
		'by'         => (int) get_post_meta( $record_id, GWC_VT_RECORD_BY, true ),
	);
}

/**
 * Whether a record is still good today.
 *
 * @param array  $record From gwc_vt_credential_record().
 * @param string $today  Optional Y-m-d, for tests.
 * @return bool
 */
function gwc_vt_record_is_current( array $record, string $today = '' ): bool {
	if ( ! $record || ! empty( $record['orphan'] ) || '' === (string) $record['date'] ) {
		return false;
	}

	$today = '' !== $today ? $today : gwc_vt_today();

	/* Granted in the future is not held yet. A typo of 2027 for 2026 should not
	 * clear somebody for a shift this Saturday. */
	if ( (string) $record['date'] > $today ) {
		return false;
	}

	if ( '' === (string) $record['expires'] ) {
		return true;
	}

	/* Good ON the day it expires. A waiver dated to run out on the 30th covers
	 * the 30th — the alternative surprises somebody at the door on a day their
	 * paperwork says is fine. */
	return (string) $record['expires'] >= $today;
}

/**
 * Where a volunteer stands on one credential.
 *
 * Reads every record they hold of it, because the newest grant is the one that
 * counts and renewals are the normal case.
 *
 * @param int    $volunteer_id  Volunteer post ID.
 * @param int    $credential_id Credential post ID.
 * @param string $today         Optional Y-m-d, for tests.
 * @return string One of GWC_VT_HOLDS_CURRENT, _EXPIRED or _NEVER.
 */
function gwc_vt_volunteer_holds( int $volunteer_id, int $credential_id, string $today = '' ): string {
	$found = false;

	foreach ( gwc_vt_credential_record_ids( $volunteer_id ) as $record_id ) {
		$record = gwc_vt_credential_record( (int) $record_id );

		if ( (int) $record['credential'] !== $credential_id ) {
			continue;
		}

		$found = true;

		if ( gwc_vt_record_is_current( $record, $today ) ) {
			return GWC_VT_HOLDS_CURRENT;
		}
	}

	/* "Expired" and "never had it" are told apart because they are different
	 * conversations: one is a renewal somebody has to book, the other is a
	 * course they have never been on. */
	return $found ? GWC_VT_HOLDS_EXPIRED : GWC_VT_HOLDS_NEVER;
}

/**
 * When a volunteer's hold on a credential runs out, or ''.
 *
 * The newest current grant's expiry — which is what a coordinator means by
 * "when does Priya's safeguarding run out".
 *
 * @param int    $volunteer_id  Volunteer post ID.
 * @param int    $credential_id Credential post ID.
 * @param string $today         Optional Y-m-d, for tests.
 * @return string Y-m-d, or '' when they do not hold it or it never expires.
 */
function gwc_vt_volunteer_holds_until( int $volunteer_id, int $credential_id, string $today = '' ): string {
	foreach ( gwc_vt_credential_record_ids( $volunteer_id ) as $record_id ) {
		$record = gwc_vt_credential_record( (int) $record_id );

		if ( (int) $record['credential'] !== $credential_id ) {
			continue;
		}

		if ( gwc_vt_record_is_current( $record, $today ) ) {
			return (string) $record['expires'];
		}
	}

	return '';
}

/**
 * Delete every credential record belonging to a volunteer.
 *
 * Here rather than in inc/privacy.php because it is the answer to "what does
 * this volunteer hold", asked backwards, and both the eraser and the retention
 * sweep want it. WordPress does not cascade a delete to children, so nothing
 * gets these for free.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int How many were removed.
 */
function gwc_vt_delete_credential_records( int $volunteer_id ): int {
	$gone = 0;

	foreach ( gwc_vt_credential_record_ids( $volunteer_id ) as $record_id ) {
		/**
		 * Fires before one credential record is deleted.
		 *
		 * @param int $record_id    The record.
		 * @param int $volunteer_id Whose it was.
		 */
		do_action( 'gwc_vt_credential_record_deleted', (int) $record_id, $volunteer_id );

		if ( wp_delete_post( (int) $record_id, true ) ) {
			++$gone;
		}
	}

	return $gone;
}

/**
 * Records whose volunteer is gone, for the retention sweep to clear up.
 *
 * A record is a child of a volunteer, and a staff member deleting a volunteer
 * post from wp-admin takes a route that fires no hook of this plugin's. Those
 * children survive, holding a name-shaped fact about somebody with no record
 * left — which is exactly what the sweep is for.
 *
 * @param int $limit How many to return.
 * @return int[]
 */
function gwc_vt_orphan_credential_record_ids( int $limit = 100 ): array {
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_RECORD_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, $limit ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	$orphans = array();

	foreach ( array_map( 'intval', (array) $ids ) as $record_id ) {
		$parent = (int) wp_get_post_parent_id( $record_id );

		if ( $parent < 1 || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $parent ) ) {
			$orphans[] = $record_id;
		}
	}

	return $orphans;
}

/**
 * Record that a volunteer holds a credential.
 *
 * One record per grant, and a new grant does not replace the last one — the
 * history of "renewed every year since 2019" is worth more than the single row
 * it would collapse into, and nothing here is large enough for that to cost
 * anything. gwc_vt_credential_record_ids() returns them newest first, so the
 * current standing is the first row that has not expired.
 *
 * The date is the day the person actually did the thing, not the day somebody
 * typed it in. Expiry is derived from it, so back-dating a class taken in
 * March and entered in June expires it in March — which is right, and is why
 * the field is not just today's date.
 *
 * @param int    $volunteer_id  Volunteer post ID.
 * @param int    $credential_id Credential post ID.
 * @param string $granted       Y-m-d, the day it was granted.
 * @param int    $recorded_by   User ID, or 0 for the current user.
 * @return int|WP_Error The new record, or why not.
 */
function gwc_vt_record_credential( int $volunteer_id, int $credential_id, string $granted, int $recorded_by = 0 ) {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return new WP_Error( 'gwc_vt_no_volunteer', __( 'That volunteer no longer exists.', 'groundwork-common-volunteer-tracker' ) );
	}

	$credential = gwc_vt_credential( $credential_id );

	if ( ! $credential ) {
		return new WP_Error( 'gwc_vt_no_credential', __( 'That credential no longer exists.', 'groundwork-common-volunteer-tracker' ) );
	}

	/* A retired credential may still be recorded against somebody. Retiring one
	 * means the organization has stopped asking for it, not that the people who
	 * hold it stopped holding it — and a coordinator catching up on paperwork
	 * from before the change is entering exactly this. */

	$granted = gwc_vt_usable_date( $granted );

	if ( '' === $granted ) {
		return new WP_Error( 'gwc_vt_bad_date', __( 'Give the date it was granted, as a real date on or before today.', 'groundwork-common-volunteer-tracker' ) );
	}

	$record_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_RECORD_TYPE,
			'post_status' => 'publish',
			'post_parent' => $volunteer_id,
			/* Never a name. The record is found by parent, and a title that
			 * carried one would put it in every admin search result and in the
			 * post list table of anybody who guessed the post type. */
			'post_title'  => sprintf( 'credential-%d-%d', $volunteer_id, $credential_id ),
		),
		true
	);

	if ( is_wp_error( $record_id ) ) {
		return $record_id;
	}

	$record_id = (int) $record_id;

	update_post_meta( $record_id, GWC_VT_RECORD_CREDENTIAL, $credential_id );
	update_post_meta( $record_id, GWC_VT_RECORD_DATE, $granted );
	update_post_meta( $record_id, GWC_VT_RECORD_BY, $recorded_by > 0 ? $recorded_by : get_current_user_id() );

	/**
	 * Fires after a credential has been recorded against a volunteer.
	 *
	 * @param int $record_id     The new record.
	 * @param int $volunteer_id  Whose it is.
	 * @param int $credential_id What they hold.
	 */
	do_action( 'gwc_vt_credential_recorded', $record_id, $volunteer_id, $credential_id );

	return $record_id;
}

/**
 * A date this may be, or an empty string.
 *
 * Its own function because it is the one piece of gwc_vt_record_credential()
 * worth asserting on its own, and because "a real date, not in the future"
 * is three separate mistakes a person makes at a keyboard: the typo, the
 * transposed month, and the year they have not started writing yet.
 *
 * Refuses rather than clamping. A silent correction on save is a bug even when
 * the correction is right — the same reasoning entry dates carry.
 *
 * @param string $granted Whatever arrived.
 * @param string $today   Y-m-d to compare against, defaults to the site's today.
 * @return string Y-m-d, or ''.
 */
function gwc_vt_usable_date( string $granted, string $today = '' ): string {
	$granted = trim( $granted );

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $granted ) ) {
		return '';
	}

	list( $year, $month, $day ) = array_map( 'intval', explode( '-', $granted ) );

	/* checkdate, not strtotime. strtotime happily reads 2025-02-31 as the third
	 * of March, which is how a typo becomes a credential that expires a month
	 * later than the certificate in the filing cabinet says. */
	if ( ! checkdate( $month, $day, $year ) ) {
		return '';
	}

	if ( '' === $today ) {
		$today = gwc_vt_today();
	}

	return $granted > $today ? '' : $granted;
}

/**
 * Volunteers holding something that has lapsed.
 *
 * The one credential fact that is a queue: working it makes it shorter, because
 * recording the renewal takes the person off the list. "Who has never had a
 * waiver" is not one — nothing shrinks it, and until a shift asks for a
 * credential there is no way to know the person needed it at all.
 *
 * Walks the records rather than the volunteers. A site with two thousand
 * volunteers and forty credential records should ask forty questions, not
 * eighty thousand, and the pairs that could possibly have lapsed are exactly
 * the ones somebody has recorded.
 *
 * Lives here rather than in inc/dashboard.php because two screens read it and
 * they must not disagree: the dashboard counts with it, and the volunteer list
 * filters by it. A count that links to a differently-derived list is the bug
 * this plugin has a rule about.
 *
 * @param string $today Y-m-d to judge against. Defaults to the site's today.
 * @return int[] Volunteer IDs, each once.
 */
function gwc_vt_lapsed_credential_ids( string $today = '' ): array {
	$pairs  = array();
	$lapsed = array();

	gwc_vt_walk_matching_ids(
		array(
			'post_type'              => GWC_VT_RECORD_TYPE,
			'post_status'            => 'publish',
			'update_post_term_cache' => false,
		),
		static function ( int $record_id ) use ( &$pairs ): void {
			$record = gwc_vt_credential_record( $record_id );

			/* An orphan — the definition was deleted underneath it — can never
			 * be lapsed, because there is no interval left to measure it
			 * against. gwc_vt_orphan_credential_record_ids() is what collects
			 * those, and it is not this list's job. */
			if ( ! $record || $record['orphan'] || $record['volunteer'] < 1 ) {
				return;
			}

			/* Keyed, so a volunteer who renewed the same class four times is
			 * asked about once. */
			$pairs[ $record['volunteer'] . ':' . $record['credential'] ] = array( $record['volunteer'], $record['credential'] );
		}
	);

	foreach ( $pairs as $pair ) {
		list( $volunteer_id, $credential_id ) = $pair;

		/* Asked of the volunteer rather than of this record, because a later
		 * grant of the same credential is what makes an expired one harmless —
		 * and gwc_vt_volunteer_holds() is the function that knows it. */
		/* Inactive volunteers are left out, and this is the count-and-screen
		 * rule rather than a preference. The dashboard's line says "N have a
		 * credential that has lapsed" and links to the volunteer list filtered
		 * by this very function; that list does not show inactive records, so
		 * counting them here says three and then shows two. It is also the
		 * right answer on its own — chasing a renewal from somebody who has
		 * stopped volunteering is work that cannot be finished. */
		if ( GWC_VT_VOLUNTEER_INACTIVE === get_post_status( $volunteer_id ) ) {
			continue;
		}

		if ( GWC_VT_HOLDS_EXPIRED === gwc_vt_volunteer_holds( $volunteer_id, $credential_id, $today ) ) {
			$lapsed[ $volunteer_id ] = $volunteer_id;
		}
	}

	return array_values( $lapsed );
}

/**
 * Everybody who holds one credential, asked the other way round.
 *
 * The rest of this file answers "what does this person hold". A coordinator
 * staffing Saturday asks the inverse — "who has a food handler card" — and
 * until now the only way to find out was to open volunteers one at a time.
 *
 * ── Why this walks records rather than volunteers ────────────────────────────
 * A site has hundreds of volunteers and a handful of records per credential.
 * Asking every volunteer whether they hold this one is a question per
 * volunteer; asking which records point at this credential is one indexed
 * meta query, and the answer is already the shortlist.
 *
 * ── Why the standing is asked per volunteer and not read off the record ──────
 * Because a person may hold the same credential twice — renewed last month, and
 * the lapsed one from three years ago is still on file. Reading each record
 * would report that person as both current AND expired. gwc_vt_volunteer_holds()
 * is the function that already knows a later grant supersedes an earlier one,
 * and it is the one the volunteer's own record renders from, so the list and
 * the record cannot disagree about somebody.
 *
 * @param int    $credential_id Credential post ID.
 * @param string $state         'current', 'expired', or 'any' for both.
 * @param string $today         Y-m-d to judge against. Defaults to the site's today.
 * @return int[] Volunteer IDs, each once, in no particular order.
 */
function gwc_vt_credential_holder_ids( int $credential_id, string $state = 'any', string $today = '' ): array {
	if ( $credential_id < 1 || ! gwc_vt_credential( $credential_id ) ) {
		return array();
	}

	$volunteers = array();

	gwc_vt_walk_matching_ids(
		array(
			'post_type'              => GWC_VT_RECORD_TYPE,
			'post_status'            => 'publish',
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key against one value; the alternative is a query per volunteer.
			'meta_query'             => array(
				array(
					'key'     => GWC_VT_RECORD_CREDENTIAL,
					'value'   => $credential_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		),
		static function ( int $record_id ) use ( &$volunteers ): void {
			$volunteer_id = (int) wp_get_post_parent_id( $record_id );

			/* Keyed, so somebody who renewed four times is asked about once —
			 * and so the answer below is computed once for them rather than
			 * four times with the same result. */
			if ( $volunteer_id > 0 && GWC_VT_VOLUNTEER_TYPE === get_post_type( $volunteer_id ) ) {
				/* ── Inactive volunteers are not holders for this purpose ─────
				 * They still hold the thing; the record of it is untouched and
				 * their own screen still shows it. But this number is a
				 * staffing number — "two hold it, one has lapsed" is read
				 * before deciding who can work — and somebody who has stopped
				 * volunteering cannot work it.
				 *
				 * It also has to be excluded because of the rule about a count
				 * and the screen it links to: that link opens the volunteer
				 * list, whose All view does not show inactive records, so a
				 * count that included them said three and then showed two.
				 * tests/integration/credentials.php asserts exactly that, and
				 * caught this the day the status was added. */
				if ( GWC_VT_VOLUNTEER_INACTIVE !== get_post_status( $volunteer_id ) ) {
					$volunteers[ $volunteer_id ] = $volunteer_id;
				}
			}
		}
	);

	$wanted = array();

	foreach ( $volunteers as $volunteer_id ) {
		$holds = gwc_vt_volunteer_holds( $volunteer_id, $credential_id, $today );

		/* An invariant guard, not a reachable branch: the set above was built
		 * from records pointing at THIS credential, so gwc_vt_volunteer_holds()
		 * has one to find and never answers "never". Kept because 'any' below
		 * would otherwise include whatever a future change let through, and
		 * labelled because a sabotage of it passes — which would otherwise
		 * read as a check that is missing rather than a branch that cannot
		 * fire. */
		if ( GWC_VT_HOLDS_NEVER === $holds ) {
			continue;
		}

		if ( 'any' === $state || $holds === $state ) {
			$wanted[] = $volunteer_id;
		}
	}

	return $wanted;
}

/**
 * How many hold one credential, currently and lapsed.
 *
 * Both numbers from one walk. The definitions screen shows them side by side,
 * and computing them separately would walk the same records twice to produce
 * two halves of one sentence.
 *
 * @param int    $credential_id Credential post ID.
 * @param string $today         Y-m-d to judge against.
 * @return array{current:int, expired:int}
 */
function gwc_vt_credential_holder_counts( int $credential_id, string $today = '' ): array {
	return array(
		'current' => count( gwc_vt_credential_holder_ids( $credential_id, GWC_VT_HOLDS_CURRENT, $today ) ),
		'expired' => count( gwc_vt_credential_holder_ids( $credential_id, GWC_VT_HOLDS_EXPIRED, $today ) ),
	);
}

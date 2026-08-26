<?php
/**
 * What a shift asks people to hold.
 *
 * The join between inc/credentials.php and the schedule. Nothing here decides
 * what to do about a missing credential — that is the roster's job and the
 * signup handler's — it only answers two questions: what does this shift ask
 * for, and what is this person short of.
 *
 * ── One meta row per credential, never a serialized array ────────────────────
 * So `meta_query` can ask "which shifts need credential 7" against an indexed
 * column instead of LIKE-ing its way through serialized data. It also means the
 * value cannot ride the $fields array in gwc_vt_handle_save_shift(): that array
 * goes through gwc_vt_shift_meta_value(), which falls through to (string) and
 * turns a list into the word "Array" while emitting a notice the suite fails
 * on. Credentials are written by gwc_vt_set_shift_credentials() and by nothing
 * else.
 *
 * ── An event's credentials add to its slots', they do not replace them ───────
 * This is a deliberate exception to inc/admin-event.php's rule that the most
 * specific non-empty value wins and nothing appends. The real case is an
 * event-wide liability waiver plus a food handler card on the kitchen role
 * only, and under replacement the kitchen slot would silently stop asking for
 * the waiver. Union is the only reading that cannot lose one.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A credential a shift asks for. Repeated: one row per credential.
 */
const GWC_VT_SHIFT_CREDENTIAL = '_gwc_vt_shift_credential';

/**
 * As many as one shift may ask for.
 *
 * A ceiling, not a page. A shift asking for more than a handful is a shift
 * nobody can staff, and the number exists so that a mangled POST cannot write
 * ten thousand meta rows.
 */
const GWC_VT_SHIFT_CREDENTIALS_MAX = 20;

/**
 * What this shift asks for, on its own.
 *
 * Its own rows only — not its event's. gwc_vt_required_credential_ids() is the
 * one that answers "what must somebody hold to do this", and the difference
 * matters on the editing screen, which has to show what a slot asks for
 * without pretending the event's waiver was typed into it.
 *
 * @param int $shift_id Shift post ID.
 * @return int[]
 */
function gwc_vt_shift_credential_ids( int $shift_id ): array {
	if ( $shift_id < 1 ) {
		return array();
	}

	$ids = get_post_meta( $shift_id, GWC_VT_SHIFT_CREDENTIAL, false );

	return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
}

/**
 * Set what a shift asks for.
 *
 * Deletes and rewrites rather than diffing. The list is at most twenty rows and
 * a diff would be three loops to save two queries — and a diff that is subtly
 * wrong leaves a shift asking for something nobody chose, which is the failure
 * this whole feature exists to prevent.
 *
 * Anything not currently defined is dropped. A credential deleted from the
 * database while a shift still pointed at it would otherwise be a requirement
 * nobody could ever satisfy and nobody could see to remove.
 *
 * @param int   $shift_id       Shift post ID.
 * @param int[] $credential_ids What it should ask for.
 * @return int[] What was actually written.
 */
function gwc_vt_set_shift_credentials( int $shift_id, array $credential_ids ): array {
	if ( $shift_id < 1 ) {
		return array();
	}

	delete_post_meta( $shift_id, GWC_VT_SHIFT_CREDENTIAL );

	$live    = gwc_vt_live_credential_ids();
	$written = array();

	foreach ( array_unique( array_map( 'intval', $credential_ids ) ) as $credential_id ) {
		if ( ! in_array( $credential_id, $live, true ) ) {
			continue;
		}

		if ( count( $written ) >= GWC_VT_SHIFT_CREDENTIALS_MAX ) {
			break;
		}

		add_post_meta( $shift_id, GWC_VT_SHIFT_CREDENTIAL, $credential_id );

		$written[] = $credential_id;
	}

	return $written;
}

/**
 * Everything somebody must hold to do this shift.
 *
 * The shift's own, plus its event's when it is a slot. Union, per the note at
 * the top of this file.
 *
 * @param int $shift_id Shift post ID.
 * @return int[]
 */
function gwc_vt_required_credential_ids( int $shift_id ): array {
	$ids = gwc_vt_shift_credential_ids( $shift_id );

	$event_id = (int) wp_get_post_parent_id( $shift_id );

	if ( $event_id > 0 && GWC_VT_EVENT_TYPE === get_post_type( $event_id ) ) {
		$ids = array_merge( $ids, gwc_vt_shift_credential_ids( $event_id ) );
	}

	/* Live only, and asked here rather than only on write. A credential retired
	 * after a shift was set up must stop being asked for immediately — that is
	 * what retiring means, and a coordinator should not have to go and edit
	 * forty Saturdays to make it true. */
	$live = gwc_vt_live_credential_ids();

	return array_values( array_intersect( array_unique( array_map( 'intval', $ids ) ), $live ) );
}

/**
 * What this person is short of for this shift.
 *
 * Returns credential IDs grouped by what the definition says to do about them,
 * so a caller never has to ask a second question to know whether to refuse
 * somebody or merely say something.
 *
 * Expired and never-recorded are both "missing". They are told apart on the
 * screen, because "their class ran out in March" and "we have never seen a
 * certificate" are different conversations — but neither is holding it.
 *
 * @param int    $volunteer_id Volunteer post ID. 0 for somebody not yet known.
 * @param int    $shift_id     Shift post ID.
 * @param string $today        Y-m-d to judge against. Defaults to the site's today.
 * @return array{block:int[], report:int[]}
 */
function gwc_vt_missing_credentials( int $volunteer_id, int $shift_id, string $today = '' ): array {
	$missing = array(
		'block'  => array(),
		'report' => array(),
	);

	foreach ( gwc_vt_required_credential_ids( $shift_id ) as $credential_id ) {
		$credential = gwc_vt_credential( $credential_id );

		if ( ! $credential ) {
			continue;
		}

		/* Volunteer 0 — a public signup by somebody who has not signed in — is
		 * short of everything. Not because we looked them up and found nothing:
		 * we have not looked, and hard rule 4 says we may not. There is simply
		 * nobody here to hold anything. */
		if ( $volunteer_id > 0 && GWC_VT_HOLDS_CURRENT === gwc_vt_volunteer_holds( $volunteer_id, $credential_id, $today ) ) {
			continue;
		}

		$missing[ 'block' === $credential['mode'] ? 'block' : 'report' ][] = $credential_id;
	}

	return $missing;
}

/**
 * Whether this shift asks for anything at all.
 *
 * A cheap gate for the roster, which draws one of these per row and would
 * otherwise walk every volunteer's records on a screen where no credential is
 * asked for.
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_asks_for_credentials( int $shift_id ): bool {
	return array() !== gwc_vt_required_credential_ids( $shift_id );
}

/**
 * The names of some credentials, in a readable list.
 *
 * @param int[] $credential_ids Credential post IDs.
 * @return string
 */
function gwc_vt_credential_names( array $credential_ids ): string {
	$names = array();

	foreach ( $credential_ids as $credential_id ) {
		$credential = gwc_vt_credential( (int) $credential_id );

		if ( $credential ) {
			$names[] = $credential['name'];
		}
	}

	return implode( ', ', $names );
}

/**
 * The credentials a save asked for.
 *
 * Its own function, called at both write points in gwc_vt_handle_save_shift()
 * and again by the repeat editor, rather than a value on the $fields array.
 * That array goes through gwc_vt_shift_meta_value(), which casts anything it
 * does not recognise with (string) — so a list arrives in the database as the
 * word "Array", and the notice it emits is one the test suite fails on.
 *
 * A disabled checkbox posts nothing, which is exactly right for an inherited
 * one: the slot never stores its event's credentials, it reads them at the
 * point of asking.
 *
 * @param array $posted Already-unslashed $_POST.
 * @return int[]
 */
function gwc_vt_posted_credential_ids( array $posted ): array {
	$ids = array();

	foreach ( (array) ( $posted['gwc_vt_credentials'] ?? array() ) as $credential_id ) {
		$credential_id = absint( $credential_id );

		if ( $credential_id > 0 ) {
			$ids[] = $credential_id;
		}
	}

	return $ids;
}

/**
 * Take a credential off every shift that asked for it.
 *
 * Called when a definition is deleted outright — not when it is retired.
 * Retiring leaves the rows alone deliberately: gwc_vt_required_credential_ids()
 * filters retired credentials out at read time, so putting one back into use
 * restores every shift that asked for it rather than losing the lot.
 *
 * @param int $credential_id Credential post ID.
 * @return int How many shifts were changed.
 */
function gwc_vt_detach_credential( int $credential_id ): int {
	if ( $credential_id < 1 ) {
		return 0;
	}

	$shift_ids = get_posts(
		array(
			'post_type'              => array( GWC_VT_SHIFT_TYPE, GWC_VT_EVENT_TYPE ),
			'post_status'            => array_values( get_post_stati() ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key against one value, run once when a definition is deleted.
			'meta_query'             => array(
				array(
					'key'     => GWC_VT_SHIFT_CREDENTIAL,
					'value'   => $credential_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a ceiling on a one-off cleanup when a definition is deleted, not a page: every row found is written to, so paginating would leave the rest pointing at an ID WordPress may reuse.
			'posts_per_page'         => 500,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $shift_ids as $shift_id ) {
		delete_post_meta( (int) $shift_id, GWC_VT_SHIFT_CREDENTIAL, $credential_id );
	}

	return count( $shift_ids );
}

add_action( 'before_delete_post', 'gwc_vt_detach_deleted_credential', 10, 2 );

/**
 * Catch a credential being deleted from anywhere.
 *
 * Including the post list table, which fires no hook of this plugin's. Without
 * this a deleted definition leaves rows on forty shifts pointing at nothing —
 * harmless today, because reads filter against the live list, and a landmine
 * the moment WordPress reuses the ID.
 *
 * @param int          $post_id The post being deleted.
 * @param WP_Post|null $post    The post, on WordPress 6.1 and later.
 */
function gwc_vt_detach_deleted_credential( $post_id, $post = null ): void {
	$type = $post instanceof WP_Post ? $post->post_type : get_post_type( (int) $post_id );

	if ( GWC_VT_CREDENTIAL_TYPE !== $type ) {
		return;
	}

	gwc_vt_detach_credential( (int) $post_id );
}

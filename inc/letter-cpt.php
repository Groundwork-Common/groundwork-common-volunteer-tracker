<?php
/**
 * The issued-letter log.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_LETTER_TYPE = 'gwc_vt_letter';

const GWC_VT_LETTER_VOLUNTEER  = '_gwc_vt_letter_volunteer';
const GWC_VT_LETTER_BY         = '_gwc_vt_letter_by';
const GWC_VT_LETTER_MEDIUM     = '_gwc_vt_letter_medium';
const GWC_VT_LETTER_RECIPIENT  = '_gwc_vt_letter_recipient';
const GWC_VT_LETTER_RANGE_FROM = '_gwc_vt_letter_from';
const GWC_VT_LETTER_RANGE_TO   = '_gwc_vt_letter_to';
const GWC_VT_LETTER_MINUTES    = '_gwc_vt_letter_minutes';
const GWC_VT_LETTER_ENTRIES    = '_gwc_vt_letter_entries';
const GWC_VT_LETTER_SENT_OK    = '_gwc_vt_letter_sent_ok';

add_action( 'init', 'gwc_vt_register_letter_type' );

/* ── Where the house convention bends, and why ───────────────────────────────
 * Everything else in this family stores data as post meta on the thing it
 * describes. An audit log cannot work that way. It is append-only, it is
 * queried by date across every volunteer at once, and above all it has to
 * survive the deletion of the record it describes — a log that disappears along
 * with the thing it was logging is not a log.
 *
 * A custom table is out; that rule is right and is kept. Bounded post meta on
 * the volunteer loses records, which disqualifies it outright. So: a third post
 * type, one post per issuance. post_title is the reference code, which gives
 * lookup by reference for free through the normal post query. post_date is the
 * issue time, which gives date ordering for free.
 *
 * A letter issued against a volunteer who has since been purged leaves a
 * dangling ID. For an audit log that is the CORRECT record — "a letter was
 * issued, for somebody no longer on file" is exactly what a court asking about
 * it needs to hear — rather than a bug to tidy up.
 *
 * Two departures follow, and both are deliberate:
 *
 *   show_ui is false. Nobody edits an audit log; an editable one is not an
 *   audit log. The Letters screen renders it read-only.
 *
 *   It survives uninstall, because uninstall never deletes posts. Also correct:
 *   the record that a document was issued outlives the plugin that issued it.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the letter log.
 */
function gwc_vt_register_letter_type(): void {
	$args = array(
		'labels'              => array(
			'name'          => _x( 'Issued letters', 'post type general name', 'groundwork-common-volunteer-tracker' ),
			'singular_name' => _x( 'Issued letter', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		),
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'exclude_from_search' => true,
		'supports'            => false,
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the issued-letter log post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_LETTER_TYPE, apply_filters( 'gwc_vt_letter_post_type_args', $args ) );
}

/**
 * Record that a letter was produced.
 *
 * Called for printing as well as for emailing. A printed letter is just as much
 * a document that left the building as an emailed one, and a log that only knew
 * about email would answer "no letter was issued" about a letter somebody is
 * holding.
 *
 * @param GWC_VT_Letter $letter    The letter.
 * @param string        $medium    'print' or 'email'.
 * @param string        $recipient Address it went to, for email.
 * @param bool          $sent_ok   Whether delivery was accepted.
 * @return int The log post ID.
 */
function gwc_vt_log_letter( GWC_VT_Letter $letter, string $medium, string $recipient = '', bool $sent_ok = true ): int {
	$record_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_LETTER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $letter->reference,
		)
	);

	if ( is_wp_error( $record_id ) || ! $record_id ) {
		return 0;
	}

	$record_id = (int) $record_id;

	update_post_meta( $record_id, GWC_VT_LETTER_VOLUNTEER, $letter->volunteer_id );
	update_post_meta( $record_id, GWC_VT_LETTER_BY, get_current_user_id() );
	update_post_meta( $record_id, GWC_VT_LETTER_MEDIUM, $medium );
	update_post_meta( $record_id, GWC_VT_LETTER_RECIPIENT, $recipient );
	update_post_meta( $record_id, GWC_VT_LETTER_RANGE_FROM, $letter->from );
	update_post_meta( $record_id, GWC_VT_LETTER_RANGE_TO, $letter->to );
	update_post_meta( $record_id, GWC_VT_LETTER_MINUTES, $letter->verified_minutes );
	update_post_meta( $record_id, GWC_VT_LETTER_ENTRIES, $letter->entry_count() );
	update_post_meta( $record_id, GWC_VT_LETTER_SENT_OK, $sent_ok ? 1 : 0 );

	/**
	 * Fires after a letter has been issued and logged.
	 *
	 * @param int          $record_id The log post ID.
	 * @param GWC_VT_Letter $letter    The letter.
	 * @param string       $medium    'print' or 'email'.
	 */
	do_action( 'gwc_vt_letter_issued', $record_id, $letter, $medium );

	return $record_id;
}

/**
 * Find an issued letter by its reference code.
 *
 * @param string $reference The reference code.
 * @return array|null
 */
function gwc_vt_find_letter_record( string $reference ) {
	$reference = strtoupper( trim( $reference ) );

	if ( '' === $reference ) {
		return null;
	}

	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_LETTER_TYPE,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			/* Matched on the title, which IS the reference. `title` rather than
			 * `s`, because a search would also match a reference that merely
			 * contains this one as a substring. */
			'title'                  => $reference,
		)
	);

	if ( empty( $ids ) ) {
		return null;
	}

	return gwc_vt_letter_record( (int) $ids[0] );
}

/**
 * Read one log entry.
 *
 * @param int $record_id Log post ID.
 * @return array
 */
function gwc_vt_letter_record( int $record_id ): array {
	return array(
		'id'           => $record_id,
		'reference'    => (string) get_the_title( $record_id ),
		'volunteer_id' => (int) get_post_meta( $record_id, GWC_VT_LETTER_VOLUNTEER, true ),
		'issued_by'    => (int) get_post_meta( $record_id, GWC_VT_LETTER_BY, true ),
		'medium'       => (string) get_post_meta( $record_id, GWC_VT_LETTER_MEDIUM, true ),
		'recipient'    => (string) get_post_meta( $record_id, GWC_VT_LETTER_RECIPIENT, true ),
		'from'         => (string) get_post_meta( $record_id, GWC_VT_LETTER_RANGE_FROM, true ),
		'to'           => (string) get_post_meta( $record_id, GWC_VT_LETTER_RANGE_TO, true ),
		'minutes'      => (int) get_post_meta( $record_id, GWC_VT_LETTER_MINUTES, true ),
		'entries'      => (int) get_post_meta( $record_id, GWC_VT_LETTER_ENTRIES, true ),
		'sent_ok'      => (bool) get_post_meta( $record_id, GWC_VT_LETTER_SENT_OK, true ),
		'issued_at'    => (string) get_post_field( 'post_date', $record_id ),
	);
}

/**
 * The most recent letters, for the log on the Letters screen.
 *
 * Unbounded storage, deliberately, unlike the post portal's ten-entry changeset
 * log. A changeset is written on every edit; a letter is issued a handful of
 * times per volunteer, ever. It is trimmed only by the retention sweep, along
 * with the volunteer it concerns.
 *
 * @param int $limit How many to return.
 * @return array[]
 */
function gwc_vt_recent_letters( int $limit = 20 ): array {
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_LETTER_TYPE,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => max( 1, $limit ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		)
	);

	return array_map( 'gwc_vt_letter_record', array_map( 'intval', (array) $ids ) );
}

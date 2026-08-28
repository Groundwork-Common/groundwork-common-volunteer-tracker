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

/* ── Which entries the letter listed, and why that is only IDs ───────────────
 * Issuing and delivering used to be one act, so the document a court received
 * was rendered in the same instant its reference was minted and there was
 * nothing to remember. Now a letter is issued once and printed, posted or
 * emailed afterwards — which means a delivery next week has to reproduce the
 * letter that was issued, not whatever the period matches by then. One shift
 * verified in between and the page would state a different total than the
 * reference on it digests.
 *
 * So the log remembers which entries were on it. IDs and nothing else: no
 * dates, no hours, no activity, no supervisor. That matters because this log
 * holds no name and outlives the volunteer on purpose — a stored copy of the
 * shift list would put the volunteer's service history into the one record
 * designed to survive their erasure, and the log would then have to die with
 * them, which is the opposite of what it is for.
 *
 * An ID is not a fact about a person. Once the entries are gone the rebuild
 * finds nothing and says so, which is the correct answer to "show me the letter
 * you sent about somebody you have since erased".
 *
 * Stored as a comma-separated string rather than a serialized array, so it is
 * legible in the database and immune to the class of bug where a serialized
 * value comes back as the literal string "Array". */
const GWC_VT_LETTER_ENTRY_IDS = '_gwc_vt_letter_entry_ids';

/* ── One row per delivery ────────────────────────────────────────────────────
 * Repeated post meta rather than a fourth post type: deliveries are only ever
 * read for one letter, never queried across letters, and a post type would buy
 * ordering and lookup that nothing asks for.
 *
 * Each row is an array of when, by, medium, recipient and ok. The letter's own
 * MEDIUM/RECIPIENT/SENT_OK meta stays where it is and is not written any more —
 * gwc_vt_letter_deliveries() reads it as a delivery when there are no rows, so
 * every letter issued before this existed still reports how it went out. */
const GWC_VT_LETTER_DELIVERY = '_gwc_vt_letter_delivery';

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
 * @param string        $medium    '' when the letter is only being issued, or
 *                                 'print'/'post'/'email' when it is being
 *                                 issued and delivered in one act, which is
 *                                 what the produce screen still does.
 * @param string        $recipient Where it went, for a medium that has a
 *                                 destination.
 * @param bool          $sent_ok   Whether delivery was accepted.
 * @return int The log post ID.
 */
function gwc_vt_log_letter( GWC_VT_Letter $letter, string $medium = '', string $recipient = '', bool $sent_ok = true ): int {
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
	update_post_meta( $record_id, GWC_VT_LETTER_ENTRY_IDS, implode( ',', array_map( 'intval', $letter->entry_ids ) ) );

	/* A letter issued without going anywhere yet records no delivery. The
	 * produce screen still issues and delivers in one act and passes a medium,
	 * which is written as the first delivery so both flows read alike. */
	if ( '' !== $medium ) {
		gwc_vt_log_delivery( $record_id, $medium, $recipient, $sent_ok );
	}

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

/* ── Issuing, and then delivering ────────────────────────────────────────────
 * These were one act. A letter was produced by printing it or emailing it, and
 * the log recorded that. It made the invariants easy — the document existed
 * for exactly as long as the instant its reference was minted — and it made the
 * audit trail thin: a letter printed and posted to a probation office was
 * logged as "printed", with nowhere to say where it went.
 *
 * So issuing mints the reference and writes the record, and printing, posting
 * and emailing are things that happen to a letter that already exists. Each one
 * appends a row. A letter can have none, which means it was issued and has not
 * gone anywhere yet, and that is a legitimate state rather than a mistake.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Record that an issued letter went somewhere.
 *
 * @param int    $record_id Log post ID.
 * @param string $medium    'print', 'post' or 'email'.
 * @param string $recipient Where it went: an address for email, an addressee
 *                          for post, and '' for print, which has no destination
 *                          the plugin can know about.
 * @param bool   $ok        Whether it was accepted. Only email can say no.
 * @return bool
 */
function gwc_vt_log_delivery( int $record_id, string $medium, string $recipient = '', bool $ok = true ): bool {
	if ( GWC_VT_LETTER_TYPE !== get_post_type( $record_id ) ) {
		return false;
	}

	$delivery = array(
		'when'      => current_time( 'mysql', true ),
		'by'        => (int) get_current_user_id(),
		'medium'    => $medium,
		'recipient' => $recipient,
		'ok'        => $ok ? 1 : 0,
	);

	$added = (bool) add_post_meta( $record_id, GWC_VT_LETTER_DELIVERY, $delivery );

	/**
	 * Fires after a letter has gone somewhere.
	 *
	 * @param int   $record_id The issued letter.
	 * @param array $delivery  when, by, medium, recipient, ok.
	 */
	do_action( 'gwc_vt_letter_delivered', $record_id, $delivery );

	return $added;
}

/**
 * Every delivery of one letter, oldest first.
 *
 * ── The letters issued before deliveries existed ─────────────────────────────
 * Those carry MEDIUM, RECIPIENT and SENT_OK on the record itself, because
 * issuing was delivering. Reading them as a single delivery is not a migration
 * — nothing is rewritten — and it means the audit trail reads the same for a
 * letter sent last year as for one sent this morning. A migration would have
 * been the wrong instinct anyway: rewriting rows in an append-only log to make
 * them look like they were written by newer code is exactly what an audit log
 * must not do.
 *
 * @param int $record_id Log post ID.
 * @return array<int, array>
 */
function gwc_vt_letter_deliveries( int $record_id ): array {
	$rows = (array) get_post_meta( $record_id, GWC_VT_LETTER_DELIVERY, false );

	$deliveries = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['medium'] ) ) {
			continue;
		}

		$deliveries[] = array(
			'when'      => (string) ( $row['when'] ?? '' ),
			'by'        => (int) ( $row['by'] ?? 0 ),
			'medium'    => (string) $row['medium'],
			'recipient' => (string) ( $row['recipient'] ?? '' ),
			'ok'        => ! empty( $row['ok'] ),
		);
	}

	if ( $deliveries ) {
		return $deliveries;
	}

	$legacy = (string) get_post_meta( $record_id, GWC_VT_LETTER_MEDIUM, true );

	if ( '' === $legacy ) {
		return array();
	}

	return array(
		array(
			'when'      => (string) get_post_field( 'post_date_gmt', $record_id ),
			'by'        => (int) get_post_meta( $record_id, GWC_VT_LETTER_BY, true ),
			'medium'    => $legacy,
			'recipient' => (string) get_post_meta( $record_id, GWC_VT_LETTER_RECIPIENT, true ),
			'ok'        => (bool) get_post_meta( $record_id, GWC_VT_LETTER_SENT_OK, true ),
		),
	);
}

/**
 * Read one log entry.
 *
 * @param int $record_id Log post ID.
 * @return array
 */
function gwc_vt_letter_record( int $record_id ): array {
	$ids = (string) get_post_meta( $record_id, GWC_VT_LETTER_ENTRY_IDS, true );

	return array(
		'id'           => $record_id,
		'reference'    => (string) get_the_title( $record_id ),
		'entry_ids'    => '' === $ids ? array() : array_map( 'intval', explode( ',', $ids ) ),
		'deliveries'   => gwc_vt_letter_deliveries( $record_id ),
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
 * Has this organization ever issued a letter?
 *
 * One indexed query for one ID, memoized for the request, and only ever asked
 * on a site that has not answered the letters_decided question — so where it
 * runs at all it runs once per admin page, and the moment somebody saves the
 * Logging tab it stops running entirely.
 *
 * It exists so the first-run prompt can stay quiet where the question has
 * already been answered by action rather than by a setting. An organization
 * with letters in its log has told us it issues them.
 *
 * @return bool
 */
function gwc_vt_any_letter_issued(): bool {
	static $answer = null;

	if ( null !== $answer ) {
		return $answer;
	}

	$answer = (bool) get_posts(
		array(
			'post_type'              => GWC_VT_LETTER_TYPE,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	return $answer;
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

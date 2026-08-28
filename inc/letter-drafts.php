<?php
/**
 * Reading and writing the letters somebody means to send.
 *
 * Four functions and no cleverness: make one, list them for a volunteer, read
 * one, and remove them. The period is sanitized on the way in by the same
 * function every other date on this plugin goes through, so a draft cannot hold
 * a date the letter builder would refuse.
 *
 * See inc/letter-draft-cpt.php for why a draft holds a period and nothing else.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Start a letter for somebody, covering a period or all of their time.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $from         Y-m-d, or '' for their first shift.
 * @param string $to           Y-m-d, or '' for the day the draft is made.
 * @param string $addressee    Optional. Who the letter is for.
 * @param string $matter       Optional. What it concerns, usually a case number.
 * @return int The draft's ID, or 0.
 */
function gwc_vt_add_letter_draft( int $volunteer_id, string $from = '', string $to = '', string $addressee = '', string $matter = '' ): int {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return 0;
	}

	$from = gwc_vt_sanitize_date( $from );
	$to   = gwc_vt_sanitize_date( $to );

	/* ── The period stops moving the moment the draft exists ─────────────────
	 * "Everything on record" used to mean "up to whenever somebody gets round to
	 * issuing this", so a draft made in March and issued in June covered three
	 * months nobody had looked at. The end is pinned to the day the draft is
	 * made, which is also what makes the period printable as two real dates
	 * rather than as a description of one.
	 *
	 * The start is left open when it was left open: it means "from their first
	 * shift", and the earliest shift on record cannot move later. A shift
	 * BACKDATED into the period after the draft was made still would — which is
	 * what the as-of rule below is for, since backdating one means verifying it,
	 * and that has a time on it. */
	if ( '' === $to ) {
		$to = (string) wp_date( 'Y-m-d' );
	}

	/* Turned round rather than refused. Two dates the wrong way round is a
	 * typing order, not a decision, and the alternative is an error message
	 * about something the screen can see for itself. */
	if ( '' !== $from && '' !== $to && $from > $to ) {
		list( $from, $to ) = array( $to, $from );
	}

	$draft_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_DRAFT_TYPE,
			'post_status' => 'publish',
			'post_parent' => $volunteer_id,
			/* Not shown anywhere: the box renders the period from the meta, so
			 * that one function decides how a period reads. This is for the
			 * database and for anybody reading it with a query. */
			'post_title'  => sprintf(
				/* translators: 1: a volunteer's name, 2: a period, already worded. */
				__( 'Letter draft — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
				get_the_title( $volunteer_id ),
				gwc_vt_letter_period_words( $from, $to )
			),
		)
	);

	if ( is_wp_error( $draft_id ) || ! $draft_id ) {
		return 0;
	}

	$draft_id = (int) $draft_id;

	update_post_meta( $draft_id, GWC_VT_DRAFT_FROM, $from );
	update_post_meta( $draft_id, GWC_VT_DRAFT_TO, $to );
	update_post_meta( $draft_id, GWC_VT_DRAFT_BY, (int) get_current_user_id() );
	update_post_meta( $draft_id, GWC_VT_DRAFT_ADDRESSEE, gwc_vt_normalize_lines( $addressee ) );
	update_post_meta( $draft_id, GWC_VT_DRAFT_MATTER, $matter );

	/**
	 * Fires after a letter draft has been started.
	 *
	 * @param int $draft_id     The draft.
	 * @param int $volunteer_id The volunteer it is about.
	 */
	do_action( 'gwc_vt_letter_draft_added', $draft_id, $volunteer_id );

	return $draft_id;
}

/**
 * One draft, as an array, or an empty array.
 *
 * @param int $draft_id Draft post ID.
 * @return array
 */
function gwc_vt_letter_draft( int $draft_id ): array {
	if ( GWC_VT_DRAFT_TYPE !== get_post_type( $draft_id ) ) {
		return array();
	}

	return array(
		'id'        => $draft_id,
		'volunteer' => (int) wp_get_post_parent_id( $draft_id ),
		'from'      => (string) get_post_meta( $draft_id, GWC_VT_DRAFT_FROM, true ),
		'to'        => (string) get_post_meta( $draft_id, GWC_VT_DRAFT_TO, true ),
		'by'        => (int) get_post_meta( $draft_id, GWC_VT_DRAFT_BY, true ),
		'started'   => (string) get_post_time( 'Y-m-d', true, $draft_id ),
		/* The moment the draft was made, which is the moment its figures are
		 * fixed to. Not stored as meta: the post's own creation time is exactly
		 * this fact, and a second copy of it is a second thing that can be
		 * wrong. See the note on 'verified_as_of' in gwc_vt_build_letter(). */
		'as_of'     => (string) get_post_field( 'post_date_gmt', $draft_id ),
		'addressee' => (string) get_post_meta( $draft_id, GWC_VT_DRAFT_ADDRESSEE, true ),
		'matter'    => (string) get_post_meta( $draft_id, GWC_VT_DRAFT_MATTER, true ),
	);
}

/**
 * One line ending, and one way to fold an address onto one line.
 *
 * A browser posts a textarea with CRLF and sanitize_textarea_field() leaves it
 * that way, so the stored value carries a \r that nothing downstream expects.
 * Folding on "\n" alone then left the \r behind, and the screen printed a line
 * break followed by a comma.
 *
 * Normalized on the way in so the database holds one form, and folded through
 * one function so the row, the data attribute and anything later agree.
 *
 * @param string $text Any multi-line text.
 * @return string
 */
function gwc_vt_normalize_lines( string $text ): string {
	return (string) str_replace( array( "\r\n", "\r" ), "\n", $text );
}

/**
 * An address on one line, for a screen that has room for one.
 *
 * @param string $text A possibly multi-line address.
 * @return string
 */
function gwc_vt_one_line( string $text ): string {
	$parts = array_filter( array_map( 'trim', explode( "\n", gwc_vt_normalize_lines( $text ) ) ), 'strlen' );

	return implode( ', ', $parts );
}

/**
 * What to build a draft's letter from, in one place.
 *
 * The box draws a draft's figures and the issue handler produces the letter, and
 * the two must agree exactly — somebody reads the row, presses Issue, and the
 * letter has to say what the row said. One function so there is one answer.
 *
 * @param array $draft From gwc_vt_letter_draft().
 * @return array Arguments for gwc_vt_build_letter().
 */
function gwc_vt_letter_args_for_draft( array $draft ): array {
	return array(
		'from'           => (string) ( $draft['from'] ?? '' ),
		'to'             => (string) ( $draft['to'] ?? '' ),
		'verified_as_of' => (string) ( $draft['as_of'] ?? '' ),
		'addressee'      => (string) ( $draft['addressee'] ?? '' ),
		'matter'         => (string) ( $draft['matter'] ?? '' ),
	);
}

/**
 * Every draft on one volunteer, oldest first.
 *
 * Oldest first because these are a queue: the one somebody has been meaning to
 * deal with longest is the one that matters, which is the same argument the
 * applications queue makes about itself.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return array<int, array>
 */
function gwc_vt_letter_drafts( int $volunteer_id ): array {
	if ( $volunteer_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'      => GWC_VT_DRAFT_TYPE,
			'post_parent'    => $volunteer_id,
			'post_status'    => array( 'publish' ),
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	$drafts = array();

	foreach ( (array) $ids as $id ) {
		$drafts[] = gwc_vt_letter_draft( (int) $id );
	}

	return $drafts;
}

/**
 * Remove a volunteer's drafts.
 *
 * Called when a letter is issued from one, when somebody discards one, and from
 * both privacy paths — a draft is about a person, so it goes when they do. The
 * issued-letter log is untouched by all three, deliberately.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int How many went.
 */
function gwc_vt_delete_letter_drafts( int $volunteer_id ): int {
	$gone = 0;

	foreach ( gwc_vt_letter_drafts( $volunteer_id ) as $draft ) {
		if ( wp_delete_post( (int) $draft['id'], true ) ) {
			++$gone;
		}
	}

	return $gone;
}

/**
 * Throw one draft away.
 *
 * @param int $draft_id Draft post ID.
 * @return bool
 */
function gwc_vt_discard_letter_draft( int $draft_id ): bool {
	if ( GWC_VT_DRAFT_TYPE !== get_post_type( $draft_id ) ) {
		return false;
	}

	return (bool) wp_delete_post( $draft_id, true );
}

/**
 * Retire the draft a letter was just issued from.
 *
 * Separate from discarding only in what it means: this one is the draft having
 * done its job, and it is called from the two handlers that write the log. The
 * guard is why it can be called with 0 — most letters are issued from no draft
 * at all, and the caller should not have to know that.
 *
 * @param int $draft_id Draft post ID, or 0.
 * @return bool
 */
function gwc_vt_finish_letter_draft( int $draft_id ): bool {
	return $draft_id > 0 && gwc_vt_discard_letter_draft( $draft_id );
}

/**
 * Drafts left behind by a volunteer deleted from the post list.
 *
 * The same sweep gwc_vt_orphan_credential_record_ids() exists for, and for the
 * same reason: WordPress does not cascade a delete to children, so the one
 * route that fires none of this plugin's own hooks — a staff member deleting
 * the volunteer from the list table — leaves these attached to nothing.
 *
 * @param int $limit How many to collect at a time.
 * @return int[]
 */
function gwc_vt_orphan_letter_draft_ids( int $limit = 100 ): array {
	$ids = get_posts(
		array(
			'post_type'      => GWC_VT_DRAFT_TYPE,
			'post_status'    => array( 'publish' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	$orphans = array();

	foreach ( (array) $ids as $id ) {
		$parent = (int) wp_get_post_parent_id( (int) $id );

		if ( $parent < 1 || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $parent ) ) {
			$orphans[] = (int) $id;
		}
	}

	return $orphans;
}

/**
 * How a period reads, in one place.
 *
 * Every screen that shows a draft or an issued letter, and the draft's own
 * stored title, come through here — so a period is worded identically wherever
 * somebody meets it, and a change to that wording is one edit.
 *
 * ── Why the unbounded case takes two more arguments ──────────────────────────
 * "Everything on record, up to the day it is issued" is true and says no dates,
 * which is the one thing somebody looking at a list of letters wants from this
 * column. It also disagreed in tone with the letter itself, which has always
 * named both ends — the earliest shift it lists, and the day it went out.
 *
 * So the caller passes the ends when it knows them. A draft knows its start
 * (the earliest shift on the letter it would produce) and cannot know its end,
 * because the end is the day somebody issues it and nobody has. An issued
 * letter knows both, from the log. Naming today's date on a draft would be a
 * date that is wrong tomorrow, so the draft says where the end comes from
 * instead — the same sentence the document uses.
 *
 * gwc_vt_display_date() rather than gwc_vt_local_date(): a period is calendar
 * dates, which were never instants, and putting a plain Y-m-d through the
 * timezone conversion shifts it across a day boundary on every site west of
 * UTC. inc/verify.php has the long version beside the two functions.
 *
 * @param string $from        Y-m-d or '' — the period that was asked for.
 * @param string $to          Y-m-d or '' — as above.
 * @param string $covers_from Optional. Y-m-d of the earliest shift, when the
 *                            caller knows it and the period is unbounded.
 * @param string $covers_to   Optional. Y-m-d the letter was issued.
 * @return string
 */
function gwc_vt_letter_period_words( string $from, string $to, string $covers_from = '', string $covers_to = '' ): string {
	if ( '' === $from && '' === $to ) {
		if ( '' !== $covers_from && '' !== $covers_to ) {
			return sprintf(
				/* translators: 1: a date, 2: a later date. */
				__( '%1$s to %2$s', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_display_date( $covers_from ),
				gwc_vt_display_date( $covers_to )
			);
		}

		if ( '' !== $covers_from ) {
			return sprintf(
				/* translators: %s: the date of the earliest shift on record. */
				__( '%s to the day it is issued', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_display_date( $covers_from )
			);
		}

		if ( '' !== $covers_to ) {
			return sprintf(
				/* translators: %s: the date the letter was issued. */
				__( 'Everything on record, up to %s', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_display_date( $covers_to )
			);
		}

		/* Nothing on record to name a start with. The figures column beside this
		 * says the same thing in its own words, and neither is guessing. */
		return __( 'Everything on record, up to the day it is issued', 'groundwork-common-volunteer-tracker' );
	}

	if ( '' === $to ) {
		return sprintf(
			/* translators: %s: a date. */
			__( 'From %s onwards', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_display_date( $from )
		);
	}

	if ( '' === $from ) {
		return sprintf(
			/* translators: %s: a date. */
			__( 'Everything up to %s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_display_date( $to )
		);
	}

	return sprintf(
		/* translators: 1: a date, 2: a later date. */
		__( '%1$s to %2$s', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_display_date( $from ),
		gwc_vt_display_date( $to )
	);
}

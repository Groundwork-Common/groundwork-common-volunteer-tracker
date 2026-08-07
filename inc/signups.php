<?php
/**
 * Putting somebody on a shift, and taking them off again.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** How long a settling lock may be held before it is assumed abandoned. */
const GWCVT_SIGNUP_LOCK_TTL = 30;

/**
 * Put somebody on a shift.
 *
 * Idempotent by design. A second signup for the same shift by the same person
 * updates the one that exists rather than making another, which matters more
 * than it looks: the public form gives an identical answer whether it accepted a
 * new signup or refreshed an old one, so somebody who clicks Submit twice — or
 * who is checking whether their friend is already down for Saturday — learns
 * nothing from the screen either way. Only the confirmation email says which
 * happened, and only the address that signed up receives it.
 *
 * @param int   $shift_id Shift post ID.
 * @param array $args     volunteer_id, claim_name, claim_email, source.
 * @return int Signup post ID, or 0.
 */
function gwcvt_add_signup( int $shift_id, array $args = array() ): int {
	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return 0;
	}

	$volunteer_id = (int) ( $args['volunteer_id'] ?? 0 );
	$name         = mb_substr( sanitize_text_field( (string) ( $args['claim_name'] ?? '' ) ), 0, 100 );
	$email        = sanitize_email( (string) ( $args['claim_email'] ?? '' ) );
	$source       = 'self' === ( $args['source'] ?? '' ) ? 'self' : 'staff';

	if ( $volunteer_id > 0 && GWCVT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return 0;
	}

	if ( $volunteer_id < 1 && '' === $name ) {
		return 0;
	}

	$signup_id = gwcvt_find_signup( $shift_id, $volunteer_id, $email );

	if ( $signup_id > 0 ) {
		/* Withdrawn and signing up again is a normal thing to do, and it counts as
		 * a fresh booking: the created time moves, the reminder is owed again, and
		 * the revision bumps — which retires the cancellation link in the first
		 * email, because that link should not un-book the second booking. */
		update_post_meta( $signup_id, GWCVT_SIGNUP_CREATED, current_time( 'mysql', true ) );
		update_post_meta( $signup_id, GWCVT_SIGNUP_REVISION, (int) get_post_meta( $signup_id, GWCVT_SIGNUP_REVISION, true ) + 1 );
		delete_post_meta( $signup_id, GWCVT_SIGNUP_REMINDED );

		if ( GWCVT_SIGNUP_WITHDRAWN === get_post_status( $signup_id ) ) {
			wp_update_post(
				array(
					'ID'          => $signup_id,
					'post_status' => GWCVT_SIGNUP_WAITLIST,
				)
			);
		}
	} else {
		$signup_id = wp_insert_post(
			array(
				'post_type'   => GWCVT_SIGNUP_TYPE,
				'post_parent' => $shift_id,
				/* Every signup starts on the waiting list and is promoted by
				 * gwcvt_settle_signups() below. Inserting as published and demoting
				 * afterwards would leave a window in which the shift really was
				 * over its maximum, which is exactly the window two people signing
				 * up at once land in. */
				'post_status' => GWCVT_SIGNUP_WAITLIST,
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $signup_id ) || ! $signup_id ) {
			return 0;
		}

		$signup_id = (int) $signup_id;

		update_post_meta( $signup_id, GWCVT_SIGNUP_CREATED, current_time( 'mysql', true ) );
		update_post_meta( $signup_id, GWCVT_SIGNUP_REVISION, 1 );
		update_post_meta( $signup_id, GWCVT_SIGNUP_SOURCE, $source );
	}

	update_post_meta( $signup_id, GWCVT_SIGNUP_VOLUNTEER, (string) $volunteer_id );

	/* Claims are kept even once a volunteer is attached, because they are what
	 * the person typed and the record of a match should show both halves. They
	 * are cleared by the privacy eraser and the retention sweep, which is where
	 * clearing them belongs. */
	if ( $volunteer_id < 1 ) {
		update_post_meta( $signup_id, GWCVT_SIGNUP_CLAIM_NAME, $name );
	}

	if ( '' !== $email && is_email( $email ) ) {
		update_post_meta( $signup_id, GWCVT_SIGNUP_CLAIM_EMAIL, $email );
	}

	gwcvt_settle_signups( $shift_id );
	gwcvt_retitle_signup( $signup_id );

	/**
	 * Fires after somebody has been put on a shift.
	 *
	 * @param int $signup_id The signup.
	 * @param int $shift_id  The shift.
	 */
	do_action( 'gwcvt_signup_received', $signup_id, $shift_id );

	return $signup_id;
}

/**
 * An existing signup for this person on this shift, in any status.
 *
 * ── Why this is not the oracle the self-log form refuses to be ───────────────
 * inc/self-log.php will not ask whether a submitted address belongs to a
 * volunteer, because the answer would be a way to find out who volunteers here.
 * This lookup is a different question with a different blast radius: it asks
 * only whether this address is already on THIS shift, it never touches the
 * volunteer table, and its answer changes nothing the visitor can see. What it
 * buys is that clicking Submit twice does not book you twice.
 *
 * @param int    $shift_id     Shift post ID.
 * @param int    $volunteer_id Volunteer post ID, or 0.
 * @param string $email        Claimed address, or ''.
 * @return int Signup post ID, or 0.
 */
function gwcvt_find_signup( int $shift_id, int $volunteer_id, string $email = '' ): int {
	if ( $volunteer_id < 1 && ( '' === $email || ! is_email( $email ) ) ) {
		return 0;
	}

	$match = $volunteer_id > 0
		? array(
			'key'   => GWCVT_SIGNUP_VOLUNTEER,
			'value' => (string) $volunteer_id,
		)
		: array(
			'key'   => GWCVT_SIGNUP_CLAIM_EMAIL,
			'value' => $email,
		);

	$ids = get_posts(
		array(
			'post_type'      => GWCVT_SIGNUP_TYPE,
			'post_parent'    => $shift_id,
			'post_status'    => array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( $match ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_meta_query -- scoped to one shift's children.
		)
	);

	return $ids ? (int) $ids[0] : 0;
}

/* ── Capacity ────────────────────────────────────────────────────────────────
 * Capacity is advisory, and that is a decision rather than an omission.
 *
 * A signup that arrives over the maximum is recorded on the waiting list, not
 * refused. Somebody working off a court order with a deadline should never be
 * bounced by a number a coordinator typed in March; the coordinator can see the
 * list and decide, which is a conversation, whereas a refusal is a dead end the
 * volunteer has no way to appeal.
 *
 * That in turn is why "3 places left" on a cached page is harmless: the page is
 * a hint and this function is the authority.
 *
 * Settling never demotes. A coordinator who lowers the maximum below the number
 * of people already on the roster has made the shift smaller, not un-invited the
 * four people who were already coming.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Promote from the waiting list until the shift is full.
 *
 * @param int $shift_id Shift post ID.
 */
function gwcvt_settle_signups( int $shift_id ): void {
	$locked = gwcvt_take_signup_lock( $shift_id );

	$max    = (int) get_post_meta( $shift_id, GWCVT_SHIFT_MAX, true );
	$filled = gwcvt_shift_filled( $shift_id );

	$waiting = gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WAITLIST ) );

	foreach ( $waiting as $signup_id ) {
		if ( $max > 0 && $filled >= $max ) {
			break;
		}

		wp_update_post(
			array(
				'ID'          => $signup_id,
				'post_status' => 'publish',
			)
		);

		++$filled;
	}

	if ( $locked ) {
		gwcvt_release_signup_lock( $shift_id );
	}
}

/**
 * Try to take the settling lock for a shift.
 *
 * Uses add_option() as the atomic primitive: it returns false when the key
 * already exists, which is a test-and-set in one statement rather than a read
 * followed by a write that another request can slip between.
 *
 * A failed attempt does not stop the caller. Settling anyway can overfill a
 * shift by one when two people submit in the same instant; refusing to settle
 * would leave somebody stranded on a waiting list for a shift with room. One
 * extra person is a conversation. A volunteer who was never told they had a
 * place is not.
 *
 * @param int $shift_id Shift post ID.
 * @return bool Whether the lock is ours to release.
 */
function gwcvt_take_signup_lock( int $shift_id ): bool {
	$key = 'gwcvt_signup_lock_' . $shift_id;
	$now = time();

	if ( add_option( $key, $now, '', false ) ) {
		return true;
	}

	/* A lock older than its lifetime belonged to a request that died mid-settle —
	 * a fatal, a timeout, a killed worker. Stolen rather than waited on, because
	 * nothing here is holding it and nothing will ever come back to release it. */
	if ( ( $now - (int) get_option( $key ) ) > GWCVT_SIGNUP_LOCK_TTL ) {
		update_option( $key, $now, false );
		return true;
	}

	return false;
}

/**
 * Give the settling lock back.
 *
 * @param int $shift_id Shift post ID.
 */
function gwcvt_release_signup_lock( int $shift_id ): void {
	delete_option( 'gwcvt_signup_lock_' . $shift_id );
}

/* ── Coming off a shift ──────────────────────────────────────────────────── */

/**
 * Take somebody off a shift.
 *
 * Withdrawn rather than deleted, so the coordinator looking at Saturday can see
 * that two people dropped rather than wondering whether they imagined them.
 *
 * @param int $signup_id Signup post ID.
 * @return bool
 */
function gwcvt_withdraw_signup( int $signup_id ): bool {
	if ( GWCVT_SIGNUP_TYPE !== get_post_type( $signup_id ) ) {
		return false;
	}

	if ( GWCVT_SIGNUP_WITHDRAWN === get_post_status( $signup_id ) ) {
		return true;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	wp_update_post(
		array(
			'ID'          => $signup_id,
			'post_status' => GWCVT_SIGNUP_WITHDRAWN,
		)
	);

	/* Their place goes to whoever is next, immediately. A waiting list that only
	 * moves when a coordinator remembers to look at it is a list of people who
	 * were told no. */
	gwcvt_settle_signups( $shift_id );

	/**
	 * Fires after somebody has come off a shift.
	 *
	 * @param int $signup_id The signup.
	 * @param int $shift_id  The shift.
	 */
	do_action( 'gwcvt_signup_withdrawn', $signup_id, $shift_id );

	return true;
}

/**
 * Attach a signup made by a stranger to a volunteer record.
 *
 * Deliberately separate from anything that records attendance, exactly as
 * attaching an hour entry is separate from verifying it: matching answers who
 * this is, and nothing more.
 *
 * @param int $signup_id    Signup post ID.
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwcvt_attach_signup( int $signup_id, int $volunteer_id ): bool {
	if ( GWCVT_SIGNUP_TYPE !== get_post_type( $signup_id ) ) {
		return false;
	}

	if ( GWCVT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return false;
	}

	update_post_meta( $signup_id, GWCVT_SIGNUP_VOLUNTEER, (string) $volunteer_id );
	gwcvt_retitle_signup( $signup_id );

	/**
	 * Fires after a signup has been matched to a volunteer.
	 *
	 * @param int $signup_id    The signup.
	 * @param int $volunteer_id The volunteer it now belongs to.
	 */
	do_action( 'gwcvt_signup_attached', $signup_id, $volunteer_id );

	return true;
}

/**
 * What makes two signups the same person, for the purpose of spotting a clash.
 *
 * A matched volunteer answers by record. An unmatched one answers by the address
 * they typed, which is the only handle there is.
 *
 * ── This is not a lookup, and the difference is the whole point ──────────────
 * It never queries anything. It turns ONE signup that the caller already holds
 * into a string, so that a set of signups the caller already holds can be
 * grouped. No code path asks "does this address belong to anybody", which is
 * the question inc/self-log.php refuses to let the public form ask.
 *
 * It lives here rather than beside the roster screen that first needed it
 * because the reminder pass groups by it too, and that pass runs on cron with
 * no admin bundle loaded at all. A function the digest needs which only exists
 * on admin requests is a fatal at three in the morning on a site nobody is
 * watching.
 *
 * Returns '' when there is neither, so a signup with no handle is never grouped
 * with another one that also has none.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwcvt_signup_person_key( int $signup_id ): string {
	$volunteer_id = (int) get_post_meta( $signup_id, GWCVT_SIGNUP_VOLUNTEER, true );

	if ( $volunteer_id > 0 ) {
		return 'v' . $volunteer_id;
	}

	$email = (string) get_post_meta( $signup_id, GWCVT_SIGNUP_CLAIM_EMAIL, true );

	return '' !== $email ? 'e' . strtolower( $email ) : '';
}

/* ── Finding somebody's signups ──────────────────────────────────────────────
 * Both of these exist for the privacy tools and the retention sweep, and the
 * first one is the reason they are needed at all: a signup made through the
 * public form by somebody who was never matched to a volunteer record holds a
 * name and an email address and belongs to no volunteer. Every privacy path in
 * this plugin before now started from gwcvt_volunteers_by_email(), which would
 * never have found it — so a person who signed up once, never turned up, and
 * later asked to be forgotten would have been told there was nothing on file
 * while their name sat on a roster.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Signups carrying a claimed address, whether or not anybody matched them.
 *
 * @param string $email The address.
 * @return int[]
 */
function gwcvt_signups_by_claim_email( string $email ): array {
	$email = sanitize_email( $email );

	if ( '' === $email || ! is_email( $email ) ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWCVT_SIGNUP_TYPE,
			'post_status'            => array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ),
			'fields'                 => 'ids',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- every signup on one shift, to settle the waiting list. 500 is a ceiling on work, not a page size; the query is ids-only with no_found_rows, so the cost is one indexed column and no SQL_CALC_FOUND_ROWS.
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- run once per privacy request or retention sweep.
			'meta_key'               => GWCVT_SIGNUP_CLAIM_EMAIL,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'meta_value'             => $email,
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Every signup attached to one volunteer.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int[]
 */
function gwcvt_signup_ids_for_volunteer( int $volunteer_id ): array {
	if ( $volunteer_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWCVT_SIGNUP_TYPE,
			'post_status'            => array( 'publish', GWCVT_SIGNUP_WAITLIST, GWCVT_SIGNUP_WITHDRAWN ),
			'fields'                 => 'ids',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- every signup on one shift, to settle the waiting list. 500 is a ceiling on work, not a page size; the query is ids-only with no_found_rows, so the cost is one indexed column and no SQL_CALC_FOUND_ROWS.
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- as above.
			'meta_key'               => GWCVT_SIGNUP_VOLUNTEER,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'meta_value'             => (string) $volunteer_id,
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Strip the name and address somebody typed, keeping the place on the roster.
 *
 * The same trade the hours make: what a shift was and who staffed it is the
 * organisation's own record, and it identifies nobody once the claim is gone.
 *
 * @param int $signup_id Signup post ID.
 */
function gwcvt_clear_signup_claims( int $signup_id ): void {
	delete_post_meta( $signup_id, GWCVT_SIGNUP_CLAIM_NAME );
	delete_post_meta( $signup_id, GWCVT_SIGNUP_CLAIM_EMAIL );

	gwcvt_retitle_signup( $signup_id );
}

/* ── The cancellation token ──────────────────────────────────────────────────
 * A volunteer with no account still has to be able to get off a shift, and the
 * only channel we have to them is the address they signed up with. So the
 * confirmation email carries a link that proves nothing except that its holder
 * read that mailbox.
 *
 * It is a capability URL, and it is worth being plain about the size of that: it
 * is exactly as sensitive as the email it arrived in, and no more. It grants one
 * thing — cancelling one signup — and it cannot be turned into a way to read
 * anybody else's, because the signup ID is inside the digest.
 *
 * Derived rather than stored: no table of live tokens to leak, and no way for
 * one to outlive what it refers to. The revision counter is in the digest, so a
 * signup that was withdrawn and made again does not answer to the link in the
 * first email.
 *
 * The revision is a counter and not the created timestamp, which is what it was
 * first written as. A mysql datetime has one-second resolution, so withdrawing
 * and re-booking inside the same second — a double-submit, or anything
 * scripted — produced an identical digest and left the old link working.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The cancellation token for a signup.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwcvt_signup_token( int $signup_id ): string {
	$created  = (string) get_post_meta( $signup_id, GWCVT_SIGNUP_CREATED, true );
	$revision = (int) get_post_meta( $signup_id, GWCVT_SIGNUP_REVISION, true );

	return hash_hmac( 'sha256', $signup_id . '|' . $created . '|' . $revision, wp_salt( 'gwcvt_signup' ) );
}

/**
 * Whether a token is the one this signup answers to.
 *
 * @param int    $signup_id Signup post ID.
 * @param string $token     The token from the link.
 * @return bool
 */
function gwcvt_signup_token_valid( int $signup_id, string $token ): bool {
	if ( '' === $token || GWCVT_SIGNUP_TYPE !== get_post_type( $signup_id ) ) {
		return false;
	}

	return hash_equals( gwcvt_signup_token( $signup_id ), $token );
}

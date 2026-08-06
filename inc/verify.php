<?php
/**
 * Attestation: who says these hours were worked, and how.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── One method today, and the shape for the next one ────────────────────────
 * In this version a staff member with gwcvt_verify_hours attests to a shift.
 * That is the same trust model as the paper forms this replaces — somebody at
 * the organisation signs to say it happened — and it is deliberately all that
 * ships first, because the alternative designs all involve emailing a link to
 * somebody outside the organisation and that is a token story, a deliverability
 * story and a rate-limiting story before it is a feature.
 *
 * The registry below exists so that adding one costs an add_filter rather than
 * a migration. Three things make that true:
 *
 *   1. _gwcvt_verified_method is written from the very first release, holding
 *      'staff'. It is never backfilled — gwcvt_entry_method() reads an absent
 *      value as 'staff' — so entries logged today read correctly forever.
 *   2. Nothing outside this file branches on the method string. The letter asks
 *      the registry for a line of text; the admin asks it for a badge. Neither
 *      knows what methods exist.
 *   3. New meta keys are free. This plugin never calls register_meta and never
 *      runs dbDelta, so _gwcvt_supervisor_email and a token hash can appear
 *      later and read as '' on every row that predates them.
 *
 * So GWCVT_SCHEMA_VERSION does not move when supervisor confirmation lands.
 * That is what decoupling it from the plugin version is for.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every way an entry can come to be attested.
 *
 * Each method is an array of callables:
 *
 *   label       fn(): string                      What to call it in the admin.
 *   letter_line fn( array $context ): string       One line for the letter.
 *   admin_badge fn( array $context ): string       Short label for a list cell.
 *   can_apply   fn( int $user_id, int $entry_id ): bool
 *
 * can_apply takes the user FIRST, matching gwcvt_user_can_verify() — which is
 * the plugin's single answer to "may this person attest to this record" and is
 * what the built-in method points at directly. The argument order is worth
 * stating because getting it backwards is silent: the check runs, both values
 * are integers, and every verification simply returns false with no error
 * anywhere. That shipped once and VerifyTest is what caught it.
 *
 * @return array<string, array<string, callable>>
 */
function gwcvt_attestation_methods(): array {
	$methods = array(
		'staff' => array(
			'label'       => static fn(): string => __( 'Staff attestation', 'groundwork-common-volunteer-tracker' ),
			'letter_line' => 'gwcvt_staff_letter_line',
			'admin_badge' => 'gwcvt_staff_admin_badge',
			'can_apply'   => 'gwcvt_user_can_verify',
		),
	);

	/**
	 * The ways an entry can be attested to.
	 *
	 * @param array<string, array<string, callable>> $methods Keyed by method slug.
	 */
	return (array) apply_filters( 'gwcvt_attestation_methods', $methods );
}

/**
 * One method, or the staff one if the slug is not registered.
 *
 * Falling back rather than returning null: a site that removes a method by
 * filter, or an entry attested by a plugin that has since been deactivated,
 * must still render a letter. An unknown method reads as the plainest true
 * statement available rather than as a fatal.
 *
 * @param string $method Method slug.
 * @return array<string, callable>
 */
function gwcvt_attestation_method( string $method ): array {
	$methods = gwcvt_attestation_methods();

	if ( isset( $methods[ $method ] ) && is_array( $methods[ $method ] ) ) {
		return $methods[ $method ];
	}

	return $methods['staff'] ?? array();
}

/**
 * What the letter and the admin need to know about an entry's attestation.
 *
 * @param int $entry_id Entry post ID.
 * @return array{verified:bool, at:string, by:int, by_name:string, method:string}
 */
function gwcvt_attestation_context( int $entry_id ): array {
	$at = (string) get_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_AT, true );
	$by = (int) get_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_BY, true );

	$user = $by > 0 ? get_userdata( $by ) : null;

	return array(
		'verified' => '' !== $at,
		'at'       => $at,
		'by'       => $by,
		/* The attester's name is resolved at read time rather than copied onto
		 * the entry when it was verified. A display name that changes should
		 * change everywhere; a name copied at write time makes two letters for
		 * the same hours disagree about who signed them. A deleted account
		 * leaves the ID, which is the honest record — see the fallback below. */
		'by_name'  => $user ? (string) $user->display_name : '',
		'method'   => gwcvt_entry_method( $entry_id ),
	);
}

/**
 * The letter's sentence for a staff-attested shift.
 *
 * @param array $context From gwcvt_attestation_context().
 * @return string
 */
function gwcvt_staff_letter_line( array $context ): string {
	if ( ! $context['verified'] ) {
		return __( 'Not verified', 'groundwork-common-volunteer-tracker' );
	}

	$date = gwcvt_local_date( $context['at'] );

	if ( '' === $context['by_name'] ) {
		/* The account has been deleted since. Saying so is better than naming
		 * nobody and better than inventing a name — a reader checking this
		 * letter with the organisation needs to know the record is thinner than
		 * it looks. */
		return sprintf(
			/* translators: %s: a date. */
			__( 'Verified %s by a staff member whose account has since been removed', 'groundwork-common-volunteer-tracker' ),
			$date
		);
	}

	return sprintf(
		/* translators: 1: a date, 2: a staff member's name. */
		__( 'Verified %1$s by %2$s', 'groundwork-common-volunteer-tracker' ),
		$date,
		$context['by_name']
	);
}

/**
 * The short form, for an admin list cell.
 *
 * @param array $context From gwcvt_attestation_context().
 * @return string
 */
function gwcvt_staff_admin_badge( array $context ): string {
	return $context['verified']
		? gwcvt_verification_label( 'verified' )
		: gwcvt_verification_label( 'unverified' );
}

/**
 * A stored GMT datetime, as a date in the site's timezone.
 *
 * @param string $gmt_datetime Y-m-d H:i:s in GMT.
 * @return string
 */
function gwcvt_local_date( string $gmt_datetime ): string {
	if ( '' === $gmt_datetime ) {
		return '';
	}

	$timestamp = (int) strtotime( $gmt_datetime . ' UTC' );

	return $timestamp > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $timestamp ) : '';
}

/* ── A calendar date, as the site writes dates ───────────────────────────────
 * Beside gwcvt_local_date() rather than folded into it, because the two answer
 * different questions. That one converts an instant — a GMT timestamp recorded
 * when somebody attested — into a date in the site's timezone. This one formats
 * a date that never was an instant: a shift happened on a day, and no timezone
 * conversion applies to it. Passing a plain Y-m-d through the other would shift
 * it across a day boundary on any site west of UTC.
 *
 * It exists because the letter printed both. Its letterhead date went through
 * wp_date(); every row of the itemised table, and the "Period covered" line,
 * printed the raw stored value. So one document read "August 6, 2026" at the top
 * and "2026-03-04" in every row below it — on the page whose whole job is being
 * read by somebody who checks documents for a living.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A stored calendar date, as the site formats dates.
 *
 * @param string $date Y-m-d.
 * @return string The formatted date, or the input unchanged if it is not one.
 */
function gwcvt_display_date( string $date ): string {
	$date = trim( $date );

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
		/* Returned as-is rather than blanked. A letter is built from records that
		 * may predate any validation this plugin has ever had, and a row that
		 * printed nothing where a date belongs is worse than one printing an
		 * unformatted date somebody can still read. */
		return $date;
	}

	$format = trim( (string) get_option( 'date_format' ) );

	/* An empty format would make gmdate() return an empty string, and a letter
	 * with a blank where a date belongs is worse than one showing 2026-03-04.
	 * The option is always set on a real site — which is the reason to handle it
	 * here rather than assume it. */
	if ( '' === $format ) {
		return $date;
	}

	$timestamp = gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );

	/* Midday UTC and gmdate() rather than wp_date(): the point is to format the
	 * date that was stored, not to move it into a timezone it never had. */
	return (string) gmdate( $format, (int) $timestamp );
}

/**
 * The line of text the letter prints under a shift.
 *
 * @param int $entry_id Entry post ID.
 * @return string
 */
function gwcvt_attestation_line( int $entry_id ): string {
	$context = gwcvt_attestation_context( $entry_id );
	$method  = gwcvt_attestation_method( $context['method'] );

	if ( empty( $method['letter_line'] ) || ! is_callable( $method['letter_line'] ) ) {
		return '';
	}

	return (string) call_user_func( $method['letter_line'], $context );
}

/**
 * The badge the admin list shows.
 *
 * @param int $entry_id Entry post ID.
 * @return string
 */
function gwcvt_attestation_badge( int $entry_id ): string {
	$context = gwcvt_attestation_context( $entry_id );
	$method  = gwcvt_attestation_method( $context['method'] );

	if ( empty( $method['admin_badge'] ) || ! is_callable( $method['admin_badge'] ) ) {
		return '';
	}

	return (string) call_user_func( $method['admin_badge'], $context );
}

/* ── Changing the state ──────────────────────────────────────────────────── */

/**
 * Attest to a shift.
 *
 * Idempotent, and deliberately so: verifying an already-verified entry leaves
 * the original timestamp and attester alone rather than restamping them. The
 * timestamp records when somebody first said this happened, and a double-click
 * on a Verify button must not rewrite that.
 *
 * @param int    $entry_id Entry post ID.
 * @param int    $user_id  Who is attesting.
 * @param string $method   Attestation method slug.
 * @return bool Whether the entry is verified after this call.
 */
function gwcvt_verify_entry( int $entry_id, int $user_id, string $method = 'staff' ): bool {
	if ( get_post_type( $entry_id ) !== GWCVT_ENTRY_TYPE ) {
		return false;
	}

	if ( gwcvt_entry_is_verified( $entry_id ) ) {
		return true;
	}

	$methods = gwcvt_attestation_methods();

	if ( ! isset( $methods[ $method ] ) ) {
		return false;
	}

	$can = $methods[ $method ]['can_apply'] ?? null;

	if ( ! is_callable( $can ) || ! call_user_func( $can, $user_id, $entry_id ) ) {
		return false;
	}

	update_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_AT, (string) current_time( 'mysql', true ) );
	update_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_BY, $user_id );
	update_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_METHOD, $method );

	/* A self-logged entry arrives as 'pending' — meaning no human has accepted
	 * this record yet — and attesting to it IS that acceptance. Publishing it
	 * here rather than making staff do both is the difference between a
	 * one-click triage and a two-step one nobody finishes. */
	if ( 'pending' === get_post_status( $entry_id ) ) {
		wp_update_post(
			array(
				'ID'          => $entry_id,
				'post_status' => 'publish',
			)
		);
	}

	gwcvt_forget_unverified_count();

	/**
	 * Fires after an entry has been attested to.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param int    $user_id  Who attested.
	 * @param string $method   Attestation method slug.
	 */
	do_action( 'gwcvt_entry_verified', $entry_id, $user_id, $method );

	return true;
}

/**
 * Withdraw an attestation.
 *
 * Clears all three keys together. Leaving the method behind would make
 * gwcvt_entry_method() report how an entry was verified that is not verified,
 * which is the sort of half-state that later reads as a bug in the letter.
 *
 * @param int $entry_id Entry post ID.
 * @return bool
 */
function gwcvt_unverify_entry( int $entry_id ): bool {
	if ( get_post_type( $entry_id ) !== GWCVT_ENTRY_TYPE ) {
		return false;
	}

	if ( ! gwcvt_entry_is_verified( $entry_id ) ) {
		return true;
	}

	delete_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_AT );
	delete_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_BY );
	delete_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_METHOD );

	gwcvt_forget_unverified_count();

	/**
	 * Fires after an attestation has been withdrawn.
	 *
	 * @param int $entry_id Entry post ID.
	 */
	do_action( 'gwcvt_entry_unverified', $entry_id );

	return true;
}

/* ── How many are waiting ────────────────────────────────────────────────────
 * A NOT EXISTS meta query is a LEFT JOIN over every entry, which is fine on a
 * few thousand rows and not something to run on every admin page load for the
 * sake of a number in a menu bubble. Cached, and dropped whenever anything
 * could have changed it — a short TTL as well, so a count that somehow drifts
 * corrects itself rather than persisting until someone notices.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCVT_UNVERIFIED_COUNT_KEY = 'gwcvt_unverified_count';

/**
 * How many entries nobody has attested to.
 *
 * @return int
 */
function gwcvt_unverified_count(): int {
	$cached = get_transient( GWCVT_UNVERIFIED_COUNT_KEY );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	/* WP_Query with found_posts rather than get_posts() with a limit. A limit
	 * would make the bubble read "200" on a site with four thousand unverified
	 * shifts, which is not a smaller truth — it is a wrong one, and the number
	 * exists precisely to tell somebody how far behind they are. found_posts
	 * costs a COUNT and gives the real figure. */
	$query = new WP_Query(
		array(
			'post_type'              => GWCVT_ENTRY_TYPE,
			'post_status'            => array( 'publish', 'pending' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cached below; see the note above.
			'meta_query'             => array(
				array(
					'key'     => GWCVT_ENTRY_VERIFIED_AT,
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	$count = (int) $query->found_posts;

	set_transient( GWCVT_UNVERIFIED_COUNT_KEY, $count, 5 * MINUTE_IN_SECONDS );

	return $count;
}

/**
 * Drop the cached count.
 */
function gwcvt_forget_unverified_count(): void {
	delete_transient( GWCVT_UNVERIFIED_COUNT_KEY );
}

add_action( 'save_post_' . GWCVT_ENTRY_TYPE, 'gwcvt_forget_unverified_count' );
add_action( 'deleted_post', 'gwcvt_forget_unverified_count' );
add_action( 'trashed_post', 'gwcvt_forget_unverified_count' );
add_action( 'untrashed_post', 'gwcvt_forget_unverified_count' );

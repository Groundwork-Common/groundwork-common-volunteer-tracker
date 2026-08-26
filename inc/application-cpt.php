<?php
/**
 * Somebody who has offered to volunteer, and nobody has said yes to yet.
 *
 * ── Why this is not a volunteer record ───────────────────────────────────────
 * inc/self-log.php states the rule this type exists to keep: an anonymous form
 * cannot create an identity record, only a row somebody must triage. Before
 * this, the only code path that made a gwc_vt_volunteer post was
 * gwc_vt_create_volunteer_from_entry() in inc/admin-triage.php, run by a signed-in
 * human. That stays true.
 *
 * What a public form produces is one of these: a name, an address and whatever
 * else the person typed, held as CLAIMS, with no volunteer record anywhere
 * until a staff member presses a button.
 *
 * Three things fall out of that, and all three are the point:
 *
 *   Spam never reaches the volunteer list. It reaches a queue somebody
 *   discards, which is a different and much cheaper problem.
 *
 *   There is no enumeration oracle. The handler never asks whether the
 *   submitted address already belongs to a volunteer — so it cannot answer,
 *   and a form that cannot answer cannot be asked whether a named person is
 *   working off a court order. The same reasoning as hard rule 4, applied to a
 *   second public surface.
 *
 *   Nothing about the record is authoritative until a person makes it so. What
 *   a stranger typed is a claim; what a coordinator approved is a record.
 *
 * ── Why its own type, rather than a pending volunteer ────────────────────────
 * A gwc_vt_volunteer with post_status 'pending' was the obvious alternative and
 * is worse in a way that would not show up for months: every query in this
 * plugin that reaches for volunteers asks for 'publish', and several ask for
 * 'any'. A pending identity record would be invisible to most of them and
 * visible to a few — including, eventually, one that counts or lists people.
 * A separate type cannot leak into a volunteer query by being forgotten about.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'gwc_vt_register_application_type' );

/** An offer to volunteer, awaiting a person. */
const GWC_VT_APPLICATION_TYPE = 'gwc_vt_application';

/* ── What the person typed ───────────────────────────────────────────────────
 * Named CLAIM_ throughout, matching the entry and signup meta, because that is
 * exactly what they are: unverified assertions by somebody nobody has met. The
 * naming is load-bearing — it is what stops a later reader treating any of it
 * as established.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWC_VT_APPLICATION_NAME  = '_gwc_vt_application_name';
const GWC_VT_APPLICATION_EMAIL = '_gwc_vt_application_email';
const GWC_VT_APPLICATION_PHONE = '_gwc_vt_application_phone';
const GWC_VT_APPLICATION_NOTE  = '_gwc_vt_application_note';

/* The service requirement, when the organization has switched that question on.
 * Held apart from the rest because it is the most sensitive thing on the form:
 * a public page collecting, and this site storing, the fact that a named person
 * is under a court order. */
const GWC_VT_APPLICATION_REQUIRED     = '_gwc_vt_application_required_minutes';
const GWC_VT_APPLICATION_REQUIRED_BY  = '_gwc_vt_application_required_by';
const GWC_VT_APPLICATION_REQUIRED_FOR = '_gwc_vt_application_required_for';

/** When it arrived, and what became of it. */
const GWC_VT_APPLICATION_CREATED  = '_gwc_vt_application_created';
const GWC_VT_APPLICATION_APPROVED = '_gwc_vt_application_volunteer';

/** Set aside rather than acted on. */
const GWC_VT_APPLICATION_DISCARDED = 'gwc_vt_discarded';

/**
 * Register the type and the status a discarded application takes.
 */
function gwc_vt_register_application_type(): void {
	/* Discarded rather than trashed. The trash is emptied on a schedule
	 * WordPress owns, and an organization that discarded somebody's offer should
	 * be able to say what it did with the information for as long as its own
	 * retention policy says — not for thirty days. It is also the difference
	 * between "we said no" and "this was never here". */
	register_post_status(
		GWC_VT_APPLICATION_DISCARDED,
		array(
			'label'                     => _x( 'Discarded', 'application status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: how many are discarded. */
			'label_count'               => _n_noop( 'Discarded <span class="count">(%s)</span>', 'Discarded <span class="count">(%s)</span>', 'groundwork-common-volunteer-tracker' ),
		)
	);

	$args = array(
		'labels'              => array(
			'name'          => _x( 'Volunteer offers', 'post type general name', 'groundwork-common-volunteer-tracker' ),
			'singular_name' => _x( 'Volunteer offer', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		),

		/* Every one of these is the same answer the volunteer type gives, for a
		 * stronger version of the same reason. A volunteer record is somebody
		 * the organization knows; this is a stranger's name and address sitting
		 * on a public web server. show_in_rest stays false forever — hard rule
		 * 2 is about a weaker case than this one. */
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'can_export'          => false,
		'supports'            => array( 'title' ),
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the volunteer-offer post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_APPLICATION_TYPE, apply_filters( 'gwc_vt_application_post_type_args', $args ) );
}

/**
 * One offer, as a plain array.
 *
 * @param int $application_id Application post ID.
 * @return array Empty when it is not one of ours.
 */
function gwc_vt_application_record( int $application_id ): array {
	if ( GWC_VT_APPLICATION_TYPE !== get_post_type( $application_id ) ) {
		return array();
	}

	return array(
		'id'           => $application_id,
		'name'         => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_NAME, true ),
		'email'        => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_EMAIL, true ),
		'phone'        => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_PHONE, true ),
		'note'         => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_NOTE, true ),
		'required'     => (int) get_post_meta( $application_id, GWC_VT_APPLICATION_REQUIRED, true ),
		'required_by'  => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_REQUIRED_BY, true ),
		'required_for' => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_REQUIRED_FOR, true ),
		'created'      => (string) get_post_meta( $application_id, GWC_VT_APPLICATION_CREATED, true ),
		'volunteer_id' => (int) get_post_meta( $application_id, GWC_VT_APPLICATION_APPROVED, true ),
		'status'       => (string) get_post_status( $application_id ),
	);
}

/**
 * Offers nobody has dealt with yet, oldest first.
 *
 * Oldest first on purpose, unlike most lists here. Somebody who offered to help
 * three weeks ago and heard nothing is the one this queue exists for; newest
 * first would bury them under this morning's arrivals.
 *
 * @param int $limit How many to return.
 * @return int[]
 */
function gwc_vt_pending_application_ids( int $limit = 100 ): array {
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_APPLICATION_TYPE,
			'post_status'            => 'pending',
			'posts_per_page'         => max( 1, $limit ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'ASC',
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return $ids;
}

/**
 * How many offers are waiting.
 *
 * @return int
 */
function gwc_vt_pending_application_count(): int {
	$counts = wp_count_posts( GWC_VT_APPLICATION_TYPE );

	return (int) ( $counts->pending ?? 0 );
}

/**
 * Remove an offer and everything on it.
 *
 * A real delete rather than a status change, because this is what the eraser
 * and the retention sweep call. Discarding an offer sets a status and keeps
 * what was written — that is the organization recording its own decision. This
 * is the other thing entirely: the information going away.
 *
 * @param int $application_id Application post ID.
 * @return bool
 */
function gwc_vt_delete_application( int $application_id ): bool {
	if ( GWC_VT_APPLICATION_TYPE !== get_post_type( $application_id ) ) {
		return false;
	}

	/**
	 * Fires before an offer to volunteer is deleted.
	 *
	 * @param int $application_id The offer.
	 */
	do_action( 'gwc_vt_application_deleted', $application_id );

	return (bool) wp_delete_post( $application_id, true );
}

/**
 * Offers that have been sitting unanswered longer than the retention policy.
 *
 * Measured from when it arrived, not from any activity, because an offer has
 * none — nobody has done anything with it, which is the whole problem. An
 * organization that leaves offers unanswered for two years is holding
 * strangers' contact details for two years, and the retention policy is exactly
 * the promise that says it will not.
 *
 * @param int $months How long records may be kept.
 * @param int $limit  How many to return.
 * @return int[]
 */
function gwc_vt_stale_application_ids( int $months, int $limit = 100 ): array {
	if ( $months < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_APPLICATION_TYPE,
			'post_status'            => array( 'pending', GWC_VT_APPLICATION_DISCARDED ),
			'posts_per_page'         => max( 1, $limit ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'date_query'             => array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ),
				),
			),
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * What became of an offer, in a sentence for the person who sent it.
 *
 * Written for the export rather than for the queue, which is why it is plain
 * about a refusal. Somebody asking what an organization holds about them is
 * owed "you offered and we said no" rather than a status slug.
 *
 * @param array $offer From gwc_vt_application_record().
 * @return string
 */
function gwc_vt_application_outcome_label( array $offer ): string {
	if ( GWC_VT_APPLICATION_DISCARDED === ( $offer['status'] ?? '' ) ) {
		return __( 'It was not taken up.', 'groundwork-common-volunteer-tracker' );
	}

	if ( (int) ( $offer['volunteer_id'] ?? 0 ) > 0 ) {
		return __( 'It was accepted, and a volunteer record was created.', 'groundwork-common-volunteer-tracker' );
	}

	return __( 'It is still waiting for somebody to look at it.', 'groundwork-common-volunteer-tracker' );
}

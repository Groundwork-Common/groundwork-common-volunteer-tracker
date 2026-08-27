<?php
/**
 * Credentials: the things a volunteer has to hold before doing certain work.
 *
 * A training course, a signed liability waiver, a background check. Defined
 * once by an admin, held by a volunteer from a date, and expiring on whatever
 * schedule the organization renews it on.
 *
 * ── The word ─────────────────────────────────────────────────────────────────
 * Credential, never requirement. "Requirement" already means something else in
 * this plugin and has since 0.12.0: the number of service HOURS a court or a
 * school ordered somebody to complete, in inc/required.php. The two are
 * unrelated — one is a debt of hours, the other is a thing you hold — and a
 * codebase where "requirement" means both is a codebase where somebody
 * eventually writes the wrong one into a letter.
 *
 * Nothing in this feature may use the word.
 *
 * ── Two types, not one ───────────────────────────────────────────────────────
 * The DEFINITION (gwc_vt_credential) is what the organization requires: a name,
 * how often it must be renewed, and whether missing it stops somebody or merely
 * gets reported.
 *
 * The RECORD (gwc_vt_cred_record) is one volunteer holding one credential from
 * one date, parented to the volunteer.
 *
 * A record rather than a meta value on the volunteer, and the reasons compound:
 *
 *   Renewal is the feature. "Every twelve months" means a person holds the same
 *   credential repeatedly, and one meta value holds one date — so a renewal
 *   either destroys the previous one or becomes a serialized array that cannot
 *   be queried. When somebody first did the safeguarding course is a real
 *   question with a real answer, and it should survive the second time.
 *
 *   The key space would be unbounded. _gwc_vt_credential_<id> means the eraser
 *   has to enumerate every credential that has EVER existed to know what to
 *   delete, and a definition removed last year leaves meta nobody sweeps. A
 *   record is one post_parent query away and the eraser deletes what it returns.
 *
 *   Provenance. "Recorded by Dana on 4 March" is the difference between a claim
 *   and a record, and that distinction is the spine of this plugin. A meta value
 *   has nowhere to put the person who wrote it.
 *
 * ── Expiry is computed, never stored ─────────────────────────────────────────
 * A record holds the date it was granted and nothing else. When it runs out is
 * derived from the definition's interval every time it is asked.
 *
 * Storing it would mean that an admin changing a credential from 24 months to
 * 12 has to rewrite every record on the site — and a rewrite that half
 * finishes leaves wrong answers that look right. Deriving means the change is
 * simply true from the moment it is saved. The same reasoning
 * gwc_vt_requirement_progress() uses, and the opposite of the cached rollup,
 * which is allowed to be stale because nothing gates on it.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'gwc_vt_register_credential_types' );

/** What the organization requires. */
const GWC_VT_CREDENTIAL_TYPE = 'gwc_vt_credential';

/** One volunteer holding one credential, from one date. */
const GWC_VT_RECORD_TYPE = 'gwc_vt_cred_record';

/* ── The definition ──────────────────────────────────────────────────────── */

/** How many months before it must be done again. 0 means it never expires. */
const GWC_VT_CREDENTIAL_MONTHS = '_gwc_vt_credential_months';

/** 'block' or 'report'. See gwc_vt_credential_modes(). */
const GWC_VT_CREDENTIAL_MODE = '_gwc_vt_credential_mode';

/** What it is, in a sentence, for whoever is recording it. */
const GWC_VT_CREDENTIAL_NOTE = '_gwc_vt_credential_note';

/* Retired rather than deleted, for the reason gwc_vt_application discarded ones
 * are kept: deleting a definition would orphan every record of somebody holding
 * it and every shift that asked for it, silently. Seventeen characters, which
 * matters — post_status is varchar(20) and a longer slug is discarded without a
 * word. Count them. */
const GWC_VT_CREDENTIAL_RETIRED = 'gwc_vt_cr_retired';

/* ── The record ──────────────────────────────────────────────────────────── */

/** Which definition this record is of. */
const GWC_VT_RECORD_CREDENTIAL = '_gwc_vt_record_credential';

/** The day it was granted. Y-m-d, a wall-clock date like a shift's. */
const GWC_VT_RECORD_DATE = '_gwc_vt_record_date';

/** The WP user who wrote it down. */
const GWC_VT_RECORD_BY = '_gwc_vt_record_by';

/** Longest a renewal interval may be, in months. */
const GWC_VT_CREDENTIAL_MAX_MONTHS = 600;

/**
 * Register both types, and the status a retired definition takes.
 */
function gwc_vt_register_credential_types(): void {
	register_post_status(
		GWC_VT_CREDENTIAL_RETIRED,
		array(
			'label'                     => _x( 'Retired', 'credential status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: how many credentials are retired. */
			'label_count'               => _nx_noop( 'Retired <span class="count">(%s)</span>', 'Retired <span class="count">(%s)</span>', 'credential status', 'groundwork-common-volunteer-tracker' ),
		)
	);

	/* The definition holds no personal data — a name and an interval — and is
	 * still not public, because what an organization requires of its volunteers
	 * is its own business and a public list of it is a list nobody asked to
	 * publish. show_in_rest stays false either way: hard rule 2. */
	$definition = array(
		'labels'              => array(
			'name'          => _x( 'Credentials', 'post type general name', 'groundwork-common-volunteer-tracker' ),
			'singular_name' => _x( 'Credential', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		),
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
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the credential post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_CREDENTIAL_TYPE, apply_filters( 'gwc_vt_credential_post_type_args', $definition ) );

	/* The record IS personal data — it says a named person did a background
	 * check on a date — so everything above applies with more force, plus
	 * can_export false: WordPress's own exporter must not carry it, because
	 * inc/privacy.php exports it deliberately and in a shape it controls. */
	$record = array(
		'labels'              => array(
			'name'          => _x( 'Credential records', 'post type general name', 'groundwork-common-volunteer-tracker' ),
			'singular_name' => _x( 'Credential record', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		),
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
	 * Arguments for the credential-record post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_RECORD_TYPE, apply_filters( 'gwc_vt_credential_record_post_type_args', $record ) );
}

/**
 * What a credential does when somebody has not got it.
 *
 * A function and not a const, because a const is evaluated at include time and
 * would freeze these in English for the request — the trap CLAUDE.md records
 * for every translated table in this codebase.
 *
 * @return array<string, string> Mode slug => what it means.
 */
function gwc_vt_credential_modes(): array {
	return array(
		'report' => __( 'Report that it is missing', 'groundwork-common-volunteer-tracker' ),
		'block'  => __( 'Stop them signing up', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * One credential definition, as a plain array.
 *
 * @param int $credential_id Credential post ID.
 * @return array Empty when it is not one of ours.
 */
function gwc_vt_credential( int $credential_id ): array {
	if ( GWC_VT_CREDENTIAL_TYPE !== get_post_type( $credential_id ) ) {
		return array();
	}

	$mode = (string) get_post_meta( $credential_id, GWC_VT_CREDENTIAL_MODE, true );

	return array(
		'id'      => $credential_id,
		'name'    => (string) get_the_title( $credential_id ),
		'months'  => max( 0, (int) get_post_meta( $credential_id, GWC_VT_CREDENTIAL_MONTHS, true ) ),
		/* Anything unrecognised reads as 'report'. A credential whose mode has
		 * been lost must not start silently blocking people. */
		'mode'    => isset( gwc_vt_credential_modes()[ $mode ] ) ? $mode : 'report',
		'note'    => (string) get_post_meta( $credential_id, GWC_VT_CREDENTIAL_NOTE, true ),
		'retired' => GWC_VT_CREDENTIAL_RETIRED === get_post_status( $credential_id ),
	);
}

/**
 * Every credential still in use, oldest first.
 *
 * Retired ones are left out: they are kept so that records of somebody holding
 * them still mean something, not so that they can be asked for again.
 *
 * @return int[]
 */
function gwc_vt_live_credential_ids(): array {
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_CREDENTIAL_TYPE,
			'post_status'            => 'publish',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- these are definitions, not records: an organization that asks for more than a handful of things has a different problem, and 200 is a ceiling rather than a page. Every caller renders the whole list, so paginating it would mean a volunteer's record silently omitting a credential nobody had scrolled to.
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
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
 * Every credential, retired ones included.
 *
 * The live list answers "what does the organization ask for", which is the
 * right question for a shift and the wrong one for a holder list:
 * retiring a credential stops it being asked for and does not take it away from
 * the people who did it. "Who did the forklift training before we dropped it"
 * has a real answer, and this is the function that can give it.
 *
 * @return int[] Ordered by name, live before retired.
 */
function gwc_vt_all_credential_ids(): array {
	$retired = get_posts(
		array(
			'post_type'              => GWC_VT_CREDENTIAL_TYPE,
			'post_status'            => GWC_VT_CREDENTIAL_RETIRED,
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- definitions, not records; see the note on gwc_vt_live_credential_ids().
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		)
	);

	return array_merge( gwc_vt_live_credential_ids(), array_map( 'intval', (array) $retired ) );
}

/**
 * When a credential granted on a date runs out.
 *
 * ── The end-of-month problem, decided ────────────────────────────────────────
 * PHP's own answer is wrong for this. strtotime( '+12 months', 31 January )
 * gives 3 March, because it adds one to the month and then normalises the
 * overflow — so somebody who did their course on the 31st is told it expired
 * two or three days early, forever, and nobody can see why.
 *
 * Clamped to the last day of the target month instead: 31 January plus twelve
 * months is 31 January; 31 January plus one month is 28 or 29 February. That is
 * what a person means by "renew it in a year", and it is what every calendar
 * app does.
 *
 * @param string $date   Y-m-d the credential was granted.
 * @param int    $months How many months it lasts. 0 means never.
 * @return string Y-m-d, or '' when it never expires or the date is unreadable.
 */
function gwc_vt_credential_expires_on( string $date, int $months ): string {
	if ( $months < 1 || '' === $date ) {
		return '';
	}

	$granted = date_create_immutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );

	if ( ! $granted ) {
		return '';
	}

	$day = (int) $granted->format( 'j' );

	/* Move to the first of the month, add the months, then put the day back —
	 * clamped to whatever that month actually has. Adding to the first can never
	 * overflow, which is the whole trick. */
	$target = $granted->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
	$last   = (int) $target->format( 't' );

	return $target->setDate(
		(int) $target->format( 'Y' ),
		(int) $target->format( 'n' ),
		min( $day, $last )
	)->format( 'Y-m-d' );
}

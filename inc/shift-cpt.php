<?php
/**
 * The scheduled shift: an occasion that has not happened yet.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_SHIFT_TYPE = 'gwcvt_shift';

/* Cancelled, as a status rather than a meta flag, because it is the shift's
 * lifecycle and post_status is where this plugin keeps lifecycle — the same
 * split that puts 'pending' on an untriaged entry and the attestation in meta.
 * A cancelled shift is kept rather than trashed: people signed up for it, and
 * "this was cancelled" is an answer the coordinator owes them. */
const GWCVT_SHIFT_CANCELLED = 'gwcvt_cancelled';

/* Shift meta. Constants for the same reason the entry's are: the schedule
 * screen, the roster, the reminder pass and the reconciler all read these, and
 * a typo in a meta key reads as an empty value rather than as an error. */
const GWCVT_SHIFT_DATE       = '_gwcvt_shift_date';
const GWCVT_SHIFT_START      = '_gwcvt_shift_start';
const GWCVT_SHIFT_END        = '_gwcvt_shift_end';
const GWCVT_SHIFT_OVERNIGHT  = '_gwcvt_shift_ends_next_day';
const GWCVT_SHIFT_ACTIVITY   = '_gwcvt_shift_activity';
const GWCVT_SHIFT_SUPERVISOR = '_gwcvt_shift_supervisor';
const GWCVT_SHIFT_LOCATION   = '_gwcvt_shift_location';
const GWCVT_SHIFT_NOTES      = '_gwcvt_shift_notes';
const GWCVT_SHIFT_MIN        = '_gwcvt_shift_min';
const GWCVT_SHIFT_MAX        = '_gwcvt_shift_max';
const GWCVT_SHIFT_SERIES     = '_gwcvt_shift_series';
const GWCVT_SHIFT_REASON     = '_gwcvt_shift_cancelled_reason';

/* GMT timestamp of when the roster was turned into hour entries. Absent means
 * not yet — the same shape as GWCVT_ENTRY_VERIFIED_AT, and for the same reason:
 * there is no 'false' to store and no third state to drift. */
const GWCVT_SHIFT_RECONCILED = '_gwcvt_shift_reconciled_at';

add_action( 'init', 'gwcvt_register_shift_type' );

/**
 * Register the shift type and its cancelled status.
 *
 * ── Why a shift is not an hour entry ─────────────────────────────────────────
 * They look similar enough to merge and merging them is the one mistake in this
 * feature that reaches a court letter.
 *
 * An hour entry is a claim about the past: Jane worked three and a half hours on
 * 4 March, and Marcus attested to it. A shift is a plan: Saturday nine to twelve,
 * food sorting, we need six people. The plan is not evidence of anything. If a
 * signup could become an hour by sitting on the calendar until the date passed,
 * then somebody who never showed up would accrue hours towards a document a
 * probation officer reads, and nobody would find out until they read it.
 *
 * So they are separate types, and turning a roster into hours is an explicit act
 * by a person who was there. See inc/admin-quick-add.php.
 *
 * ── Why there is no admin UI ─────────────────────────────────────────────────
 * show_ui is false, following gwcvt_letter rather than gwcvt_entry. The default
 * post list cannot show what a coordinator needs to know about a shift — the
 * question is never "list the posts", it is "which of these is underfilled and
 * soon", which is a sorted, computed view. inc/admin-schedule.php is that view.
 *
 * Everything else is the family rule: not public, not queryable, not searchable,
 * no archive, no REST. A shift carries a location and a supervisor's name, and
 * its children carry the names and addresses of people who signed up.
 */
function gwcvt_register_shift_type(): void {
	register_post_status(
		GWCVT_SHIFT_CANCELLED,
		array(
			'label'                     => _x( 'Cancelled', 'shift status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of cancelled shifts. */
			'label_count'               => _n_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'groundwork-common-volunteer-tracker' ),
		)
	);

	$labels = array(
		'name'          => _x( 'Shifts', 'post type general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name' => _x( 'Shift', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		'menu_name'     => _x( 'Schedule', 'admin menu', 'groundwork-common-volunteer-tracker' ),
		'add_new_item'  => __( 'Add a shift', 'groundwork-common-volunteer-tracker' ),
		'edit_item'     => __( 'Edit shift', 'groundwork-common-volunteer-tracker' ),
		'not_found'     => __( 'Nothing scheduled yet.', 'groundwork-common-volunteer-tracker' ),
		'all_items'     => __( 'Schedule', 'groundwork-common-volunteer-tracker' ),
	);

	$args = array(
		'labels'              => $labels,
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

		/* Nothing, and the literal false rather than array() — register_post_type()
		 * reads an empty array as "unspecified" and falls through to title and
		 * editor. The title is derived on save by gwcvt_retitle_shift(). */
		'supports'            => false,
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the shift post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWCVT_SHIFT_TYPE, apply_filters( 'gwcvt_shift_post_type_args', $args ) );
}

/**
 * The title a shift is stored under.
 *
 * Derived rather than typed, exactly as an entry's is, because everything in it
 * is already recorded in meta and a title field would be a box whose contents
 * are overwritten the moment somebody presses Save. It exists so that admin
 * search, the trash, and anything reading the post list finds something a human
 * recognises.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwcvt_shift_title( int $shift_id ): string {
	$activity = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ) );
	$date     = (string) get_post_meta( $shift_id, GWCVT_SHIFT_DATE, true );

	if ( '' === $activity ) {
		$activity = __( 'Volunteer shift', 'groundwork-common-volunteer-tracker' );
	}

	if ( '' === $date ) {
		return $activity;
	}

	return sprintf(
		/* translators: 1: what the shift is, 2: a date, 3: a time range. */
		__( '%1$s — %2$s, %3$s', 'groundwork-common-volunteer-tracker' ),
		$activity,
		gwcvt_shift_date_label( $shift_id ),
		gwcvt_shift_time_label( $shift_id )
	);
}

/**
 * Write the derived title.
 *
 * @param int $shift_id Shift post ID.
 */
function gwcvt_retitle_shift( int $shift_id ): void {
	wp_update_post(
		array(
			'ID'         => $shift_id,
			'post_title' => gwcvt_shift_title( $shift_id ),
		)
	);
}

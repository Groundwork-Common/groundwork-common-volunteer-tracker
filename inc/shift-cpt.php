<?php
/**
 * The scheduled shift: an occasion that has not happened yet.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_SHIFT_TYPE = 'gwc_vt_shift';

/* Cancelled, as a status rather than a meta flag, because it is the shift's
 * lifecycle and post_status is where this plugin keeps lifecycle — the same
 * split that puts 'pending' on an untriaged entry and the attestation in meta.
 * A cancelled shift is kept rather than trashed: people signed up for it, and
 * "this was cancelled" is an answer the coordinator owes them. */
const GWC_VT_SHIFT_CANCELLED = 'gwc_vt_cancelled';

/* Shift meta. Constants for the same reason the entry's are: the schedule
 * screen, the roster, the reminder pass and the reconciler all read these, and
 * a typo in a meta key reads as an empty value rather than as an error. */
const GWC_VT_SHIFT_DATE       = '_gwc_vt_shift_date';
const GWC_VT_SHIFT_START      = '_gwc_vt_shift_start';
const GWC_VT_SHIFT_END        = '_gwc_vt_shift_end';
const GWC_VT_SHIFT_OVERNIGHT  = '_gwc_vt_shift_ends_next_day';
const GWC_VT_SHIFT_ACTIVITY   = '_gwc_vt_shift_activity';
const GWC_VT_SHIFT_SUPERVISOR = '_gwc_vt_shift_supervisor';
const GWC_VT_SHIFT_LOCATION   = '_gwc_vt_shift_location';
const GWC_VT_SHIFT_NOTES      = '_gwc_vt_shift_notes';
const GWC_VT_SHIFT_MIN        = '_gwc_vt_shift_min';
const GWC_VT_SHIFT_MAX        = '_gwc_vt_shift_max';
const GWC_VT_SHIFT_SERIES     = '_gwc_vt_shift_series';

/* The ceiling on a shift's minimum and maximum. A name rather than the literal
 * 500 it used to be in four places: the two save handlers clamped with
 * min( 500, ... ) and the three form inputs carried max="500", with nothing
 * tying them together. Raising one and not the others silently gives the form
 * and the handler different answers. */
const GWC_VT_SHIFT_CAPACITY_MAX = 500;

/* How much of a cancellation reason is kept. Settled at 300 because the shift
 * screen was the odd one out at 200 while both event screens took 300 — the
 * same meta key on the same post type, holding two different truths depending
 * on which button called the shift off. Shortening a coordinator's explanation
 * is the wrong direction, so the longer one won. Every form's maxlength and
 * every handler's cap now read this. */
const GWC_VT_SHIFT_REASON_MAX = 300;
const GWC_VT_SHIFT_REASON     = '_gwc_vt_shift_cancelled_reason';

/* GMT timestamp of when the roster was turned into hour entries. Absent means
 * not yet — the same shape as GWC_VT_ENTRY_VERIFIED_AT, and for the same reason:
 * there is no 'false' to store and no third state to drift. */
const GWC_VT_SHIFT_RECONCILED = '_gwc_vt_shift_reconciled_at';

add_action( 'init', 'gwc_vt_register_shift_type' );

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
 * then somebody who never showed up would accrue hours toward a document a
 * probation officer reads, and nobody would find out until they read it.
 *
 * So they are separate types, and turning a roster into hours is an explicit act
 * by a person who was there. See inc/admin-quick-add.php.
 *
 * ── Why there is no admin UI ─────────────────────────────────────────────────
 * show_ui is false, following gwc_vt_letter rather than gwc_vt_entry. The default
 * post list cannot show what a coordinator needs to know about a shift — the
 * question is never "list the posts", it is "which of these is underfilled and
 * soon", which is a sorted, computed view. inc/admin-schedule.php is that view.
 *
 * Everything else is the family rule: not public, not queryable, not searchable,
 * no archive, no REST. A shift carries a location and a supervisor's name, and
 * its children carry the names and addresses of people who signed up.
 */
function gwc_vt_register_shift_type(): void {
	register_post_status(
		GWC_VT_SHIFT_CANCELLED,
		array(
			'label'                     => _x( 'Canceled', 'shift status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: how many are canceled. */
			'label_count'               => _n_noop( 'Canceled <span class="count">(%s)</span>', 'Canceled <span class="count">(%s)</span>', 'groundwork-common-volunteer-tracker' ),
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
		 * editor. The title is derived on save by gwc_vt_retitle_shift(). */
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
	register_post_type( GWC_VT_SHIFT_TYPE, apply_filters( 'gwc_vt_shift_post_type_args', $args ) );
}

/**
 * The title a shift is stored under.
 *
 * Derived rather than typed, exactly as an entry's is, because everything in it
 * is already recorded in meta and a title field would be a box whose contents
 * are overwritten the moment somebody presses Save. It exists so that admin
 * search, the trash, and anything reading the post list finds something a human
 * recognizes.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_title( int $shift_id ): string {
	$activity = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) );
	$date     = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );

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
		gwc_vt_shift_date_label( $shift_id ),
		gwc_vt_shift_time_label( $shift_id )
	);
}

/**
 * Write the derived title.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_retitle_shift( int $shift_id ): void {
	wp_update_post(
		array(
			'ID'         => $shift_id,
			'post_title' => gwc_vt_shift_title( $shift_id ),
		)
	);
}

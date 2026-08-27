<?php
/**
 * The event: one occasion, several roles, several times.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_EVENT_TYPE = 'gwc_vt_event';

/* Called off, as a status rather than a meta flag, for the same reason a shift's
 * is: post_status is where this plugin keeps lifecycle. A cancelled event is
 * kept rather than trashed — people signed up for it, and "this was called off"
 * is an answer the organization owes them.
 *
 * ── The name is short because wp_posts.post_status is varchar(20) ────────────
 * This was 'gwc_vt_event_cancelled' first, which is twenty-one characters. The
 * column takes twenty. WordPress does not error, does not truncate and does not
 * warn: wp_insert_post() sanitizes a status it cannot store and the row keeps
 * the one it already had.
 *
 * So "Call it off" reported success, the event stayed published, and it went on
 * taking signups. Nothing on any screen said otherwise. tests/integration/
 * events.php asserts a cancelled event reads back as cancelled for exactly this
 * reason — the unit suite never touches a database and could not have caught it.
 *
 * Anything added here has to fit in twenty characters. Count them. */
const GWC_VT_EVENT_CANCELLED = 'gwc_vt_ev_cancelled';

/* Event meta. Constants for the same reason the shift's are: the schedule
 * screen, the editor, the public grid and the emails all read these, and a typo
 * in a meta key reads as an empty value rather than as an error. */
const GWC_VT_EVENT_DATE        = '_gwc_vt_event_date';
const GWC_VT_EVENT_END_DATE    = '_gwc_vt_event_end_date';
const GWC_VT_EVENT_LOCATION    = '_gwc_vt_event_location';
const GWC_VT_EVENT_DESCRIPTION = '_gwc_vt_event_description';
const GWC_VT_EVENT_SUPERVISOR  = '_gwc_vt_event_supervisor';
const GWC_VT_EVENT_REASON      = '_gwc_vt_event_cancelled_reason';

/* Which run of the same event this one is, holding the ID of the first of them.
 *
 * The same convention as GWC_VT_SHIFT_SERIES and for the same reason: the row
 * that started it is already unique and already means something when you see it,
 * where a generated identifier is one more thing to keep unique.
 *
 * A monthly meal service is twelve real events, each with its own roles, its own
 * roster and its own cancellation — materialised for exactly the reasons
 * inc/recurrence.php gives for shifts. This meta is what lets a row say "one of
 * twelve" without any of them depending on the others to exist. */
const GWC_VT_EVENT_SERIES = '_gwc_vt_event_series';

add_action( 'init', 'gwc_vt_register_event_type' );

/**
 * Register the event type and its cancelled status.
 *
 * ── Why an event is not a new kind of slot ───────────────────────────────────
 * A SignUp Genius slot is (a role) x (a time window) x (how many people). A
 * gwc_vt_shift is already exactly that. So an event is a CONTAINER over shifts
 * and nothing below it changes:
 *
 *   gwc_vt_event --post_parent--> gwc_vt_shift --post_parent--> gwc_vt_signup
 *
 * Every slot stays a shift and every signup stays a signup, so waiting lists and
 * the settling lock, reminders and their idempotency flag, cancellation tokens,
 * calendar files, the privacy exporter, the retention sweep, no-show derivation
 * and reconciliation into hour entries all keep working with no new code.
 *
 * A parallel slot type would need every one of those written twice, and the one
 * that would hurt is reconciliation: two paths by which hours reach a court
 * letter is two places for hours to reach a court letter WRONGLY, and the second
 * would be the one nobody has been staring at for four releases.
 *
 * post_parent does not collide with GWC_VT_SHIFT_SERIES. A series is "this shift,
 * repeated"; an event is "these different roles, one occasion". They are
 * orthogonal and they compose, which is what keeps a recurring festival
 * representable.
 *
 * ── Why there is no admin UI ─────────────────────────────────────────────────
 * show_ui is false, following gwc_vt_shift. The default post list cannot show
 * what a coordinator needs to know about an event — the question is never "list
 * the posts", it is "which of these is short of people, and how soon".
 * inc/admin-event.php is that view.
 *
 * Everything else is the family rule: not public, not queryable, not searchable,
 * no archive, no REST. An event carries a location and a description, and its
 * children carry the names of the people who signed up.
 */
function gwc_vt_register_event_type(): void {
	register_post_status(
		GWC_VT_EVENT_CANCELLED,
		array(
			'label'                     => _x( 'Canceled', 'event status', 'groundwork-common-volunteer-tracker' ),
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
		'name'          => _x( 'Events', 'post type general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name' => _x( 'Event', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		'menu_name'     => _x( 'Events', 'admin menu', 'groundwork-common-volunteer-tracker' ),
		'add_new_item'  => __( 'Add an event', 'groundwork-common-volunteer-tracker' ),
		'edit_item'     => __( 'Edit event', 'groundwork-common-volunteer-tracker' ),
		'not_found'     => __( 'No events yet.', 'groundwork-common-volunteer-tracker' ),
		'all_items'     => __( 'Events', 'groundwork-common-volunteer-tracker' ),
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

		/* An event supports 'title' where a shift supports nothing, and that is
		 * the one place in this plugin where a typed title is right. A shift's
		 * title is derived because everything in it is already in meta; an event
		 * has a name a human chose — "Fall Festival" — and that name is what a
		 * volunteer recognizes on a page and in an email. */
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the event post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_EVENT_TYPE, apply_filters( 'gwc_vt_event_post_type_args', $args ) );
}

/* ── The dates are derived, and that is the point ────────────────────────────
 * An event stores a first and last day, but nobody types them. They are written
 * from the slots on every grid save by gwc_vt_event_refresh_dates() in
 * inc/events.php, which is where the slots can be read.
 *
 * A coordinator building a grid has already said when every slot is. A date
 * field beside it would be a second source of truth, and it disagrees with the
 * first the moment somebody moves one slot — silently, because nothing checks
 * one against the other.
 *
 * They are STORED rather than computed on read so that an event can be ordered
 * by meta the way a shift is, in one query rather than by loading every event's
 * children to find out when it is. The same trade shifts already make.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Has this event been called off?
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function gwc_vt_event_is_cancelled( int $event_id ): bool {
	return GWC_VT_EVENT_CANCELLED === get_post_status( $event_id );
}

/**
 * An event's name, as something to show.
 *
 * Falls back rather than returning empty: an event with no title is a draft
 * somebody has not finished, and "(untitled event)" is a thing a coordinator can
 * click on where an empty string is not.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwc_vt_event_name( int $event_id ): string {
	$title = trim( (string) get_the_title( $event_id ) );

	if ( '' === $title ) {
		return __( '(untitled event)', 'groundwork-common-volunteer-tracker' );
	}

	return $title;
}

/**
 * An event's day, or its span, as the site formats dates.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwc_vt_event_date_label( int $event_id ): string {
	$from = (string) get_post_meta( $event_id, GWC_VT_EVENT_DATE, true );
	$to   = (string) get_post_meta( $event_id, GWC_VT_EVENT_END_DATE, true );

	if ( '' === $from ) {
		return '';
	}

	/* gwc_vt_recurrence_date() is the validity check here and nothing more. It
	 * builds the instant at midnight UTC, so formatting its timestamp with
	 * wp_date() moves the date into the site's zone: an event stored as
	 * 2026-10-12 printed as 11 October on America/New_York, on the public grid
	 * and in both the confirmation and the reminder mail.
	 *
	 * gwc_vt_display_date() formats the date that was stored instead, which is
	 * what a calendar date wants — an event happened on a day, it never was an
	 * instant, and no conversion applies to it. The box comment above that
	 * function in inc/verify.php names this exact hazard.
	 *
	 * gwc_vt_shift_date_label() looks parallel to this one and is correct, but
	 * only because gwc_vt_shift_starts() builds its instant in
	 * gwc_vt_timezone() rather than UTC. The two are not interchangeable, which
	 * is why this went unnoticed. */
	$starts = gwc_vt_recurrence_date( $from );

	if ( null === $starts ) {
		return '';
	}

	/* No 'D j M Y' fallback for an empty date_format any more: the helper owns
	 * that policy now and returns the stored Y-m-d, on the same reasoning as the
	 * letter — a readable unformatted date beats a second, divergent default. */
	$first = gwc_vt_display_date( $from );

	if ( '' === $to || $to === $from ) {
		return $first;
	}

	$ends = gwc_vt_recurrence_date( $to );

	if ( null === $ends ) {
		return $first;
	}

	return sprintf(
		/* translators: 1: the first day of an event, 2: the last day. */
		__( '%1$s – %2$s', 'groundwork-common-volunteer-tracker' ),
		$first,
		gwc_vt_display_date( $to )
	);
}

/**
 * Does this event run over more than one day?
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function gwc_vt_event_is_multi_day( int $event_id ): bool {
	$from = (string) get_post_meta( $event_id, GWC_VT_EVENT_DATE, true );
	$to   = (string) get_post_meta( $event_id, GWC_VT_EVENT_END_DATE, true );

	return '' !== $from && '' !== $to && $to !== $from;
}

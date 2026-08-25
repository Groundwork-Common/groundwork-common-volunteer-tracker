<?php
/**
 * Reading events: their slots, how full they are, and when they are.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** No event may hold more slots than this. A grid past it is a mistake. */
const GWC_VT_EVENT_MAX_SLOTS = 200;

/* ── An event owns nothing of its own ────────────────────────────────────────
 * Every question about an event is really a question about its slots, and every
 * slot is an ordinary gwc_vt_shift. So this file is joins and sums, and it holds
 * no state that a shift does not already hold.
 *
 * The one exception is the pair of derived dates, written back onto the event by
 * gwc_vt_event_refresh_dates() so that a list of events can be ordered by meta in
 * one query. See the box comment in inc/event-cpt.php.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The slots on an event, soonest first.
 *
 * Sorted in PHP rather than by the database, deliberately. Ordering by a shift's
 * date AND its start time means two meta joins and an ORDER BY across both, and
 * an event has tens of slots rather than thousands — so one query and a sort
 * beats a query that has to be read twice to be understood.
 *
 * @param int      $event_id Event post ID.
 * @param string[] $statuses Which statuses to include.
 * @return int[] Shift post IDs.
 */
function gwc_vt_event_slot_ids( int $event_id, array $statuses = array( 'publish' ) ): array {
	if ( $event_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'        => GWC_VT_SHIFT_TYPE,
			'post_parent'      => $event_id,
			'post_status'      => $statuses,
			'posts_per_page'   => GWC_VT_EVENT_MAX_SLOTS,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( ! $ids ) {
		return array();
	}

	/* One priming call rather than a query per row: the grid reads six meta keys
	 * off every slot, and a festival with a dozen of them would otherwise be
	 * seventy-two queries. */
	update_postmeta_cache( $ids );

	usort( $ids, 'gwc_vt_compare_slots' );

	return $ids;
}

/**
 * Order two slots by when they happen, then by ID.
 *
 * The ID tiebreak keeps the order stable for two slots at the same time, so a
 * grid does not reshuffle itself between page loads.
 *
 * @param int $a Shift post ID.
 * @param int $b Shift post ID.
 * @return int
 */
function gwc_vt_compare_slots( int $a, int $b ): int {
	$a_key = (string) get_post_meta( $a, GWC_VT_SHIFT_DATE, true ) . ' ' . (string) get_post_meta( $a, GWC_VT_SHIFT_START, true );
	$b_key = (string) get_post_meta( $b, GWC_VT_SHIFT_DATE, true ) . ' ' . (string) get_post_meta( $b, GWC_VT_SHIFT_START, true );

	$compared = strcmp( $a_key, $b_key );

	return 0 !== $compared ? $compared : ( $a <=> $b );
}

/**
 * The event a shift belongs to, or 0 for a standalone shift.
 *
 * @param int $shift_id Shift post ID.
 * @return int
 */
function gwc_vt_event_for_shift( int $shift_id ): int {
	$parent = (int) get_post_field( 'post_parent', $shift_id );

	if ( $parent < 1 || GWC_VT_EVENT_TYPE !== get_post_type( $parent ) ) {
		return 0;
	}

	return $parent;
}

/**
 * An event's slots grouped by role, each group in time order.
 *
 * The shape the grid, the public page and the roster all render from. Roles come
 * out in the order their earliest slot happens, so a day reads top to bottom.
 *
 * @param int      $event_id Event post ID.
 * @param string[] $statuses Which slot statuses to include.
 * @return array<string, int[]> Role name => shift post IDs.
 */
function gwc_vt_event_roles( int $event_id, array $statuses = array( 'publish' ) ): array {
	$roles = array();

	foreach ( gwc_vt_event_slot_ids( $event_id, $statuses ) as $shift_id ) {
		$role = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) );

		if ( '' === $role ) {
			$role = __( 'Volunteering', 'groundwork-common-volunteer-tracker' );
		}

		$roles[ $role ][] = $shift_id;
	}

	return $roles;
}

/* ── How full, and how short ─────────────────────────────────────────────── */

/**
 * How many people are on an event's roster, across every slot.
 *
 * One person holding three slots counts three times, and that is right: the
 * number answers "how many places are taken", not "how many humans are coming".
 * A festival needing twenty-two places filled does not care that four of them
 * are the same enthusiast.
 *
 * @param int $event_id Event post ID.
 * @return int
 */
function gwc_vt_event_filled( int $event_id ): int {
	$filled = 0;

	foreach ( gwc_vt_event_slot_ids( $event_id ) as $shift_id ) {
		$filled += gwc_vt_shift_filled( $shift_id );
	}

	return $filled;
}

/**
 * How many places an event has, or null when any slot is uncapped.
 *
 * Null rather than a partial sum, because "8 of 16" on an event where one slot
 * takes as many as turn up is a number that means nothing and looks like it
 * means something.
 *
 * @param int $event_id Event post ID.
 * @return int|null
 */
function gwc_vt_event_capacity( int $event_id ): ?int {
	$slots = gwc_vt_event_slot_ids( $event_id );

	if ( ! $slots ) {
		return null;
	}

	$total = 0;

	foreach ( $slots as $shift_id ) {
		$max = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );

		if ( $max < 1 ) {
			return null;
		}

		$total += $max;
	}

	return $total;
}

/**
 * The slots on an event that are short of the people they need.
 *
 * @param int $event_id Event post ID.
 * @return int[] Shift post IDs.
 */
function gwc_vt_event_short_slot_ids( int $event_id ): array {
	$short = array();

	foreach ( gwc_vt_event_slot_ids( $event_id ) as $shift_id ) {
		if ( gwc_vt_shift_is_understaffed( $shift_id ) ) {
			$short[] = $shift_id;
		}
	}

	return $short;
}

/**
 * Times on this event that have happened and whose hours are not logged.
 *
 * The same three conditions gwc_vt_unreconciled_shift_ids() uses, asked of one
 * event's slots — deliberately the same, because that function is what counts
 * for the dashboard and the nag, and a badge here that disagreed with the number
 * being nagged about would be worse than no badge.
 *
 * It exists because an event slot IS an ordinary shift and was being counted in
 * that nag, while the only screens offering a way to log a shift's hours listed
 * standalone shifts only. The count said five and the list showed none.
 *
 * @param int $event_id Event post ID.
 * @return int[]
 */
function gwc_vt_event_unlogged_slot_ids( int $event_id ): array {
	$due = array();

	foreach ( gwc_vt_event_slot_ids( $event_id ) as $shift_id ) {
		if ( gwc_vt_shift_is_reconciled( $shift_id ) || ! gwc_vt_shift_has_ended( $shift_id ) ) {
			continue;
		}

		// A time nobody came to is not a chore somebody forgot. Same rule, same reason.
		if ( 0 === gwc_vt_shift_filled( $shift_id ) ) {
			continue;
		}

		$due[] = (int) $shift_id;
	}

	return $due;
}

/**
 * How full an event is, as a sentence for a coordinator.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwc_vt_event_fill_label( int $event_id ): string {
	$filled   = gwc_vt_event_filled( $event_id );
	$capacity = gwc_vt_event_capacity( $event_id );

	if ( null !== $capacity ) {
		return sprintf(
			/* translators: 1: places taken, 2: places available. */
			__( '%1$d of %2$d', 'groundwork-common-volunteer-tracker' ),
			$filled,
			$capacity
		);
	}

	return sprintf(
		/* translators: %d: how many places are taken. */
		_n( '%d signed up', '%d signed up', $filled, 'groundwork-common-volunteer-tracker' ),
		$filled
	);
}

/* ── When ────────────────────────────────────────────────────────────────── */

/**
 * Write an event's first and last day from its slots.
 *
 * Called on every grid save. Reads cancelled slots as well as published ones —
 * a cancelled Sunday is still part of when the event was, and dropping it would
 * silently shorten an event on the screen that lists it.
 *
 * @param int $event_id Event post ID.
 */
function gwc_vt_event_refresh_dates( int $event_id ): void {
	$dates = array();

	foreach ( gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ) ) as $shift_id ) {
		$date = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );

		if ( null !== gwc_vt_recurrence_date( $date ) ) {
			$dates[] = $date;
		}
	}

	if ( ! $dates ) {
		delete_post_meta( $event_id, GWC_VT_EVENT_DATE );
		delete_post_meta( $event_id, GWC_VT_EVENT_END_DATE );
		return;
	}

	sort( $dates );

	update_post_meta( $event_id, GWC_VT_EVENT_DATE, $dates[0] );
	update_post_meta( $event_id, GWC_VT_EVENT_END_DATE, $dates[ count( $dates ) - 1 ] );
}

/**
 * Has every slot on this event finished?
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function gwc_vt_event_has_ended( int $event_id ): bool {
	$slots = gwc_vt_event_slot_ids( $event_id );

	if ( ! $slots ) {
		return false;
	}

	foreach ( $slots as $shift_id ) {
		if ( ! gwc_vt_shift_has_ended( $shift_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Is this event taking signups at all?
 *
 * True when the event is published and at least one of its slots is still open.
 * The per-slot answer is still gwc_vt_shift_is_signup_visible(); this only says
 * whether the page is worth rendering.
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function gwc_vt_event_is_open( int $event_id ): bool {
	if ( 'publish' !== get_post_status( $event_id ) ) {
		return false;
	}

	foreach ( gwc_vt_event_slot_ids( $event_id ) as $shift_id ) {
		if ( gwc_vt_shift_is_open( $shift_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Events in a date range, soonest first.
 *
 * The same named-meta-clause construction gwc_vt_shifts_between() uses, and for
 * the same reason: "when is it" and "when was it typed in" are different
 * questions and only one of them is being asked.
 *
 * @param array $args from, to (Y-m-d), statuses, limit.
 * @return int[] Event post IDs.
 */
function gwc_vt_events_between( array $args = array() ): array {
	$from     = (string) ( $args['from'] ?? gwc_vt_today() );
	$to       = (string) ( $args['to'] ?? '' );
	$statuses = (array) ( $args['statuses'] ?? array( 'publish' ) );
	$limit    = (int) ( $args['limit'] ?? 100 );

	/* Compared against the LAST day, so a two-day event is still "coming up" on
	 * its second morning. Comparing the first day would drop a festival off the
	 * schedule halfway through itself. */
	$range = array(
		'key'     => GWC_VT_EVENT_END_DATE,
		'value'   => $from,
		'compare' => '>=',
		'type'    => 'CHAR',
	);

	if ( '' !== $to ) {
		$range = array(
			'relation'          => 'AND',
			'ends_after'        => array(
				'key'     => GWC_VT_EVENT_END_DATE,
				'value'   => $from,
				'compare' => '>=',
				'type'    => 'CHAR',
			),
			'gwc_vt_event_date' => array(
				'key'     => GWC_VT_EVENT_DATE,
				'value'   => $to,
				'compare' => '<=',
				'type'    => 'CHAR',
			),
		);
	}

	$ids = get_posts(
		array(
			'post_type'      => GWC_VT_EVENT_TYPE,
			'post_status'    => $statuses,
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => $range, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the only way to order by the event's own dates; the table is two rows per event.
			'orderby'        => array(
				'gwc_vt_event_date' => 'ASC',
				'ID'                => 'ASC',
			),
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return $ids;
}

/* ── Overlap ─────────────────────────────────────────────────────────────────
 * Two slots clash when one begins before the other ends, on both sides. Touching
 * is not overlapping: set-up 07:30-09:00 followed by greeting 09:00-12:00 is a
 * normal morning, and reporting it as a clash would train coordinators to ignore
 * the flag that matters.
 *
 * Strict, with no tolerance. A fuzzy answer — "within fifteen minutes" — needs a
 * setting, and this does not deserve a setting.
 *
 * Instants rather than wall-clock strings, so an event spanning midnight or two
 * days compares correctly. gwc_vt_shift_ends() already accounts for the overnight
 * flag.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Do two slots overlap in time?
 *
 * @param int $a Shift post ID.
 * @param int $b Shift post ID.
 * @return bool False when either has no readable time — an unreadable slot is
 *              not evidence of a clash.
 */
function gwc_vt_shifts_overlap( int $a, int $b ): bool {
	if ( $a === $b ) {
		return false;
	}

	$a_from = gwc_vt_shift_starts( $a );
	$a_to   = gwc_vt_shift_ends( $a );
	$b_from = gwc_vt_shift_starts( $b );
	$b_to   = gwc_vt_shift_ends( $b );

	if ( null === $a_from || null === $a_to || null === $b_from || null === $b_to ) {
		return false;
	}

	return $a_from->getTimestamp() < $b_to->getTimestamp()
		&& $b_from->getTimestamp() < $a_to->getTimestamp();
}

/**
 * The first clashing pair in a list of slots, or an empty array.
 *
 * First rather than all: the public form reports one clash and asks once, and a
 * visitor handed four simultaneous warnings gives up rather than reading them.
 * The roster, which can afford the space, walks the pairs itself.
 *
 * @param int[] $shift_ids Shift post IDs.
 * @return int[] Two shift IDs, or empty.
 */
function gwc_vt_first_overlapping_pair( array $shift_ids ): array {
	$shift_ids = array_values( array_unique( array_map( 'intval', $shift_ids ) ) );
	$count     = count( $shift_ids );

	for ( $i = 0; $i < $count; $i++ ) {
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( gwc_vt_shifts_overlap( $shift_ids[ $i ], $shift_ids[ $j ] ) ) {
				return array( $shift_ids[ $i ], $shift_ids[ $j ] );
			}
		}
	}

	return array();
}

/**
 * One slot, named the way a clash warning has to name it.
 *
 * Role and time, because "12 October 09:00-12:00" twice over tells somebody
 * nothing about which two things they picked.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_slot_label( int $shift_id ): string {
	$role = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) );
	$when = gwc_vt_shift_time_label( $shift_id );

	if ( '' === $role ) {
		$role = __( 'Volunteering', 'groundwork-common-volunteer-tracker' );
	}

	if ( '' === $when ) {
		return $role;
	}

	return sprintf(
		/* translators: 1: a role, 2: a time range. */
		_x( '%1$s %2$s', 'a slot, named in a clash warning', 'groundwork-common-volunteer-tracker' ),
		$role,
		$when
	);
}

/* ── Where the admin screens live ────────────────────────────────────────────
 * URL builders rather than screens, beside gwc_vt_schedule_url() and for the same
 * reason: the daily summary builds them and the summary runs on cron, where the
 * admin bundle is not loaded at all.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The editor for one event.
 *
 * @param int $event_id Event post ID, or 0 for a new one.
 * @return string
 */
function gwc_vt_event_edit_url( int $event_id ): string {
	return gwc_vt_schedule_url( array( 'gwc_vt_event' => $event_id > 0 ? $event_id : 'new' ) );
}

/**
 * The roster for one event.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwc_vt_event_roster_url( int $event_id ): string {
	return gwc_vt_schedule_url(
		array(
			'gwc_vt_event' => $event_id,
			'view'         => 'roster',
		)
	);
}

/* ── An event, in the schedule's own vocabulary ──────────────────────────────
 * An event is a container over shifts by post_parent, so it has no roster and
 * no minimum of its own — its state is the worst news among its slots. Said in
 * the same six words gwc_vt_shift_state() uses, because the filter chips and
 * the calendar draw both kinds of row and a coordinator asking "show me what is
 * short" means both.
 *
 * Ordered the way the shift version is, and for the same reasons: called off
 * beats everything, then what has already happened, then what can still be
 * acted on.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Which state an event is in.
 *
 * @param int $event_id Event post ID.
 * @return string One of the keys in gwc_vt_shift_state_labels().
 */
function gwc_vt_event_state( int $event_id ): string {
	if ( gwc_vt_event_is_cancelled( $event_id ) ) {
		return 'cancelled';
	}

	/* Hours waiting on any of its times is the thing somebody has to go and do,
	 * so it outranks a time that is short — by the point hours are outstanding,
	 * being short of people is history. */
	if ( gwc_vt_event_unlogged_slot_ids( $event_id ) ) {
		return 'awaiting';
	}

	if ( gwc_vt_event_short_slot_ids( $event_id ) ) {
		return 'short';
	}

	$slots = gwc_vt_event_slot_ids( $event_id );

	/* Every time finished and written up. Nothing left to do about it, which is
	 * what 'logged' means on a shift. An event with no times at all is not
	 * "logged" — it is a draft somebody has not filled in, so it stays neutral. */
	if ( $slots ) {
		$done = true;

		foreach ( $slots as $slot_id ) {
			if ( ! gwc_vt_shift_is_reconciled( $slot_id ) ) {
				$done = false;
				break;
			}
		}

		if ( $done ) {
			return 'logged';
		}
	}

	return 'ok';
}

/**
 * How full an event is, and whether that is a problem — the chip's second line.
 *
 * The event answer to gwc_vt_shift_fill_summary(), and it says "Event" first
 * because a chip on a calendar has to tell you whether clicking it opens one
 * roster or a whole day. That is the same thing the near-black bar on the chip
 * says, for anybody who cannot see the bar.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwc_vt_event_fill_summary( int $event_id ): string {
	$state = gwc_vt_event_state( $event_id );
	$lead  = __( 'Event', 'groundwork-common-volunteer-tracker' );

	if ( 'cancelled' === $state || 'awaiting' === $state || 'logged' === $state ) {
		return $lead . ' · ' . gwc_vt_shift_state_label( $state );
	}

	$summary = $lead . ' · ' . gwc_vt_event_fill_label( $event_id );

	if ( 'short' === $state ) {
		$short = count( gwc_vt_event_short_slot_ids( $event_id ) );

		return $summary . ' · ' . sprintf(
			/* translators: %d: how many of an event's times are short of people. */
			_n( '%d short', '%d short', $short, 'groundwork-common-volunteer-tracker' ),
			$short
		);
	}

	return $summary;
}

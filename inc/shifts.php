<?php
/**
 * Reading shifts: when they are, how long, how full, and whether they are open.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Wall time in, instants out ──────────────────────────────────────────────
 * A shift stores '09:00', not a timestamp. The Saturday nine o'clock shift is at
 * nine o'clock in March and at nine o'clock in November, and storing the instant
 * would move one of them by an hour when the clocks change.
 *
 * So the record holds what a person would write on a noticeboard, and every
 * question that needs a real moment in time — has it ended, is it within the
 * reminder window, what goes in the calendar file — asks for one here, through
 * gwc_vt_timezone(). One conversion, one place.
 *
 * gwc_vt_shift_instant_at() takes its zone as an argument rather than reaching
 * for the site's, so that the conversion itself can be tested against a zone
 * that actually observes daylight saving.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Read a typed time of day into H:i, or ''.
 *
 * @param string $raw What was posted.
 * @return string
 */
function gwc_vt_sanitize_time( string $raw ): string {
	$value = trim( $raw );

	if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
		return '';
	}

	return $value;
}

/**
 * A wall-clock date and time as a real moment.
 *
 * @param string       $date     Y-m-d.
 * @param string       $time     H:i.
 * @param DateTimeZone $timezone Where the organization is.
 * @return DateTimeImmutable|null
 */
function gwc_vt_shift_instant_at( string $date, string $time, DateTimeZone $timezone ): ?DateTimeImmutable {
	if ( '' === $time || null === gwc_vt_recurrence_date( $date ) ) {
		return null;
	}

	$instant = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );

	return false === $instant ? null : $instant;
}

/**
 * When a shift starts.
 *
 * @param int $shift_id Shift post ID.
 * @return DateTimeImmutable|null
 */
function gwc_vt_shift_starts( int $shift_id ): ?DateTimeImmutable {
	return gwc_vt_shift_instant_at(
		(string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ),
		(string) get_post_meta( $shift_id, GWC_VT_SHIFT_START, true ),
		gwc_vt_timezone()
	);
}

/**
 * When a shift ends.
 *
 * @param int $shift_id Shift post ID.
 * @return DateTimeImmutable|null
 */
function gwc_vt_shift_ends( int $shift_id ): ?DateTimeImmutable {
	$date = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );

	if ( get_post_meta( $shift_id, GWC_VT_SHIFT_OVERNIGHT, true ) ) {
		$next = gwc_vt_recurrence_date( $date );

		if ( null === $next ) {
			return null;
		}

		$date = $next->modify( '+1 day' )->format( 'Y-m-d' );
	}

	return gwc_vt_shift_instant_at(
		$date,
		(string) get_post_meta( $shift_id, GWC_VT_SHIFT_END, true ),
		gwc_vt_timezone()
	);
}

/**
 * How long a shift is, in minutes.
 *
 * Minutes, like every other duration in this plugin, because this number becomes
 * the prefilled hours on an entry the moment somebody reconciles the roster —
 * and an entry's duration is an integer number of minutes. See the box comment
 * in inc/settings.php.
 *
 * @param string $start     H:i.
 * @param string $end       H:i.
 * @param bool   $next_day  Whether the end time is on the following day.
 * @return int Zero when the times cannot be read or do not describe a duration.
 */
function gwc_vt_shift_duration( string $start, string $end, bool $next_day = false ): int {
	$start = gwc_vt_sanitize_time( $start );
	$end   = gwc_vt_sanitize_time( $end );

	if ( '' === $start || '' === $end ) {
		return 0;
	}

	$from = ( (int) substr( $start, 0, 2 ) * 60 ) + (int) substr( $start, 3, 2 );
	$to   = ( (int) substr( $end, 0, 2 ) * 60 ) + (int) substr( $end, 3, 2 );

	if ( $next_day ) {
		$to += 1440;
	}

	$minutes = $to - $from;

	/* A shift that ends before it starts is a typo, not a twenty-three hour
	 * overnight — the overnight case is the explicit flag above. Nothing is
	 * guessed; the screen refuses it and says why. */
	if ( $minutes < 1 || $minutes > GWC_VT_MAX_ENTRY_MINUTES ) {
		return 0;
	}

	return $minutes;
}

/**
 * How long a stored shift is, in minutes.
 *
 * @param int $shift_id Shift post ID.
 * @return int
 */
function gwc_vt_shift_minutes( int $shift_id ): int {
	return gwc_vt_shift_duration(
		(string) get_post_meta( $shift_id, GWC_VT_SHIFT_START, true ),
		(string) get_post_meta( $shift_id, GWC_VT_SHIFT_END, true ),
		(bool) get_post_meta( $shift_id, GWC_VT_SHIFT_OVERNIGHT, true )
	);
}

/* ── State, computed rather than stored ──────────────────────────────────────
 * "Closed to signups" is not a status anybody writes. It is the answer to a
 * comparison — is it cancelled, has it started, is it past its cutoff — and
 * storing it would mean a scheduled task whose job is to make a row agree with
 * the clock, plus every bug where that task did not run.
 *
 * The same argument as the colophon storing WHEN it was collapsed rather than
 * THAT it was, so that thirty days falls out of a comparison.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Has this shift been cancelled?
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_is_cancelled( int $shift_id ): bool {
	return GWC_VT_SHIFT_CANCELLED === get_post_status( $shift_id );
}

/**
 * Has the roster been turned into hour entries?
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_is_reconciled( int $shift_id ): bool {
	return '' !== (string) get_post_meta( $shift_id, GWC_VT_SHIFT_RECONCILED, true );
}

/**
 * Has the shift finished?
 *
 * The gate on reconciling. An entry dated in the future is silently clamped to
 * today by gwc_vt_save_entry(), so writing hours for a shift that has not ended
 * would record the wrong date on a document a court reads.
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_has_ended( int $shift_id ): bool {
	$ends = gwc_vt_shift_ends( $shift_id );

	if ( null === $ends ) {
		return false;
	}

	return $ends->getTimestamp() <= time();
}

/**
 * Is this shift accepting signups?
 *
 * Being full is deliberately not part of this. A full shift still accepts
 * people, onto the waiting list — see gwc_vt_signup_settle().
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_is_open( int $shift_id ): bool {
	if ( 'publish' !== get_post_status( $shift_id ) ) {
		return false;
	}

	$starts = gwc_vt_shift_starts( $shift_id );

	if ( null === $starts ) {
		return false;
	}

	$cutoff = max( 0, (int) gwc_vt_setting( 'signup_cutoff_hours' ) ) * HOUR_IN_SECONDS;

	return ( $starts->getTimestamp() - $cutoff ) > time();
}

/* ── What a shift is made of ─────────────────────────────────────────────────
 * The writable attributes were enumerated in three places — the shift screen's
 * save, the event grid's save, and the bare key list the copy path looped. Add
 * one attribute and it had to be added to all three or it went silently missing
 * from standalone shifts, event slots or copies, with nothing failing loudly.
 *
 * That is not hypothetical: the copy path wrote every value with (string), so a
 * copied event's slots carried "4" where a saved one carried 4 — one field, two
 * shapes in the database, and anything doing a strict comparison or a NUMERIC
 * meta_query saw both.
 *
 * The split here is deliberate. These two own the SHAPE a value is stored in.
 * The per-field length caps on the text attributes stay in the save handlers,
 * because those sanitise what somebody typed into a particular form and are
 * about input, not storage.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every meta key a shift's attributes are written to.
 *
 * Not GWC_VT_SHIFT_SERIES, GWC_VT_SHIFT_REASON or GWC_VT_SHIFT_RECONCILED:
 * those record what happened to a shift rather than what it is, and copying one
 * to a new shift would be a lie about the copy.
 *
 * @return string[]
 */
function gwc_vt_shift_meta_keys(): array {
	return array(
		GWC_VT_SHIFT_DATE,
		GWC_VT_SHIFT_START,
		GWC_VT_SHIFT_END,
		GWC_VT_SHIFT_OVERNIGHT,
		GWC_VT_SHIFT_ACTIVITY,
		GWC_VT_SHIFT_SUPERVISOR,
		GWC_VT_SHIFT_LOCATION,
		GWC_VT_SHIFT_NOTES,
		GWC_VT_SHIFT_MIN,
		GWC_VT_SHIFT_MAX,
	);
}

/**
 * One shift attribute, in the shape it is stored in.
 *
 * @param string $key   One of gwc_vt_shift_meta_keys().
 * @param mixed  $value The value to store.
 * @return mixed
 */
function gwc_vt_shift_meta_value( string $key, $value ) {
	if ( GWC_VT_SHIFT_MIN === $key || GWC_VT_SHIFT_MAX === $key ) {
		return min( GWC_VT_SHIFT_CAPACITY_MAX, absint( $value ) );
	}

	if ( GWC_VT_SHIFT_OVERNIGHT === $key ) {
		return empty( $value ) ? 0 : 1;
	}

	return (string) $value;
}

/**
 * The attributes whose changing means a volunteer has to be told.
 *
 * The activity, the notes, the supervisor and the capacity are all deliberately
 * absent. They can change without affecting whether somebody can come, and
 * mailing thirty people because a coordinator fixed a spelling is how an
 * organization teaches its volunteers to ignore its email.
 *
 * A named list rather than whatever keys the comparison array happened to hold:
 * the loop below used to take its key set from its own literal, so adding a key
 * there for any other reason would have silently widened what counts as a move.
 *
 * @return string[] Snapshot keys, mapped to the meta key each reads.
 */
function gwc_vt_shift_movement_keys(): array {
	return array(
		'date'     => GWC_VT_SHIFT_DATE,
		'start'    => GWC_VT_SHIFT_START,
		'end'      => GWC_VT_SHIFT_END,
		'next_day' => GWC_VT_SHIFT_OVERNIGHT,
		'location' => GWC_VT_SHIFT_LOCATION,
	);
}

/**
 * Did anything change that a volunteer would need to know about?
 *
 * @param int   $shift_id Shift post ID.
 * @param array $was      What it looked like before the save.
 * @return bool
 */
function gwc_vt_shift_moved( int $shift_id, array $was ): bool {
	foreach ( gwc_vt_shift_movement_keys() as $key => $meta_key ) {
		if ( (string) ( $was[ $key ] ?? '' ) !== (string) get_post_meta( $shift_id, $meta_key, true ) ) {
			return true;
		}
	}

	return false;
}

/* ── Which clock these times are on ──────────────────────────────────────────
 * A shift stores wall-clock time and nothing about a zone, which is right — see
 * the note above. But a volunteer reading "1:00 pm" in an email on a phone that
 * is still on last week's timezone has no way to know which one o'clock is
 * meant, and the calendar file beside it carries a UTC instant that their
 * calendar app will helpfully convert. Two numbers, no stated anchor.
 *
 * So the times say which clock they are on. The abbreviation depends on when —
 * a list running from October to December is EDT for the first half and EST for
 * the second — which is why this takes a shift rather than reading the setting
 * once, and why the list helper below asks whether they all agree before saying
 * it only at the top.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * What the clock is called at the moment a shift starts.
 *
 * @param int $shift_id Shift post ID.
 * @return string Empty when the shift has no readable start.
 */
function gwc_vt_shift_timezone_label( int $shift_id ): string {
	$starts = gwc_vt_shift_starts( $shift_id );

	return null === $starts ? '' : gwc_vt_timezone_label( $starts->getTimestamp() );
}

/**
 * The one label a whole list shares, or empty when it does not share one.
 *
 * A list of Saturdays either side of a daylight-saving change genuinely has two
 * answers, and saying "all times EDT" over a December row would be stating
 * something false to save a repetition. So: one label when they agree and the
 * caller can say it once, nothing when they do not and the caller must say it
 * per row.
 *
 * @param int[] $shift_ids Shift post IDs.
 * @return string
 */
function gwc_vt_shared_timezone_label( array $shift_ids ): string {
	$labels = array();

	foreach ( $shift_ids as $shift_id ) {
		$label = gwc_vt_shift_timezone_label( (int) $shift_id );

		if ( '' !== $label ) {
			$labels[ $label ] = true;
		}
	}

	return 1 === count( $labels ) ? (string) array_key_first( $labels ) : '';
}

/* ── The roster ──────────────────────────────────────────────────────────── */

/**
 * The signups on a shift.
 *
 * @param int      $shift_id Shift post ID.
 * @param string[] $statuses Which statuses to include.
 * @return int[] Signup post IDs, oldest first.
 */
function gwc_vt_shift_signup_ids( int $shift_id, array $statuses = array( 'publish' ) ): array {
	if ( $shift_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'        => GWC_VT_SIGNUP_TYPE,
			'post_parent'      => $shift_id,
			'post_status'      => $statuses,
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the roster of one shift. 500 is far above any shift a person could staff, and is a bound rather than a page; the query is ids-only with no_found_rows, so the cost is one indexed column and no SQL_CALC_FOUND_ROWS.
			'posts_per_page'   => 500,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	/* One cache priming call rather than a query per row. The roster screen reads
	 * four meta keys off every signup, and a shift with thirty people on it would
	 * otherwise be a hundred and twenty queries. */
	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return $ids;
}

/**
 * How many people are on the roster proper.
 *
 * @param int $shift_id Shift post ID.
 * @return int
 */
function gwc_vt_shift_filled( int $shift_id ): int {
	return count( gwc_vt_shift_signup_ids( $shift_id ) );
}

/**
 * How many places are left, or null when the shift has no maximum.
 *
 * Never negative: a shift that has somehow been overfilled reads as full rather
 * than as minus one, because "minus one place left" on a public page is a bug
 * report addressed to a volunteer.
 *
 * @param int $shift_id Shift post ID.
 * @return int|null
 */
function gwc_vt_shift_spots_left( int $shift_id ): ?int {
	$max = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );

	if ( $max < 1 ) {
		return null;
	}

	return max( 0, $max - gwc_vt_shift_filled( $shift_id ) );
}

/**
 * Is the shift short of the number of people it needs?
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwc_vt_shift_is_understaffed( int $shift_id ): bool {
	$min = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );

	if ( $min < 1 ) {
		return false;
	}

	return gwc_vt_shift_filled( $shift_id ) < $min;
}

/* ── Finding shifts ──────────────────────────────────────────────────────── */

/**
 * Shifts in a date range, soonest first.
 *
 * Ordered by the shift's own date rather than by post date, through a named meta
 * clause — the same construction inc/admin-verify.php uses on the hours list, and
 * for the same reason: "when is it" and "when was it typed in" are different
 * questions and only one of them is the one anybody is asking.
 *
 * ── 'parent' defaults to EVERYTHING, and it must stay that way ───────────────
 * An event's slots are ordinary shifts whose post_parent is the event, so this
 * function returns them alongside standalone shifts unless a caller says
 * otherwise. That default is load-bearing.
 *
 * gwc_vt_understaffed_shift_ids() and gwc_vt_unreconciled_shift_ids() both run
 * through here. Filter parented shifts out by default and the reconciliation nag
 * silently stops covering events — and the failure mode is hours nobody typed
 * up, on the number a letter is built from. Nothing on any screen would say so.
 *
 * Exactly two callers opt IN to `parent => 0`, and both want a flat list of
 * standalone shifts because they show events separately:
 *
 *   - gwc_vt_public_shift_ids(), or every festival slot appears loose on the
 *     generic signup page with no idea what it belongs to;
 *   - the schedule screen's flat view, which groups events into one row.
 *
 * Never opt out by default.
 *
 * @param array $args from, to (Y-m-d), statuses, limit, parent.
 * @return int[] Shift post IDs.
 */
function gwc_vt_shifts_between( array $args = array() ): array {
	$from     = (string) ( $args['from'] ?? gwc_vt_today() );
	$to       = (string) ( $args['to'] ?? '' );
	$statuses = (array) ( $args['statuses'] ?? array( 'publish' ) );
	$limit    = (int) ( $args['limit'] ?? 200 );

	/* Null rather than 0, because 0 is a real answer meaning "standalone only".
	 * Absent has to be distinguishable from "no parent", or the default becomes
	 * the opt-in and the trap above springs. */
	$parent = array_key_exists( 'parent', $args ) ? (int) $args['parent'] : null;

	$range = array(
		'key'     => GWC_VT_SHIFT_DATE,
		'value'   => $from,
		'compare' => '>=',
		'type'    => 'CHAR',
	);

	if ( '' !== $to ) {
		$range = array(
			'key'     => GWC_VT_SHIFT_DATE,
			'value'   => array( $from, $to ),
			'compare' => 'BETWEEN',
			'type'    => 'CHAR',
		);
	}

	$query = array(
		'post_type'      => GWC_VT_SHIFT_TYPE,
		'post_status'    => $statuses,
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the only way to order by the shift's own date; the table is one row per shift.
			'gwc_vt_shift_date' => $range,
		),
		'orderby'        => array(
			'gwc_vt_shift_date' => 'ASC',
			'ID'                => 'ASC',
		),
	);

	if ( null !== $parent ) {
		$query['post_parent'] = $parent;
	}

	$ids = get_posts( $query );

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	return $ids;
}

/**
 * Shifts that have finished and whose hours have never been logged.
 *
 * The query the reconciliation nag is built on. An unreconciled shift is not an
 * error — somebody types Saturday up on Monday — but one that is still
 * unreconciled a fortnight later is hours nobody will ever remember, and those
 * hours are what a letter is made of.
 *
 * @param int $limit How many to return.
 * @return int[]
 */
function gwc_vt_unreconciled_shift_ids( int $limit = 50 ): array {
	$candidates = gwc_vt_shifts_between(
		array(
			'from'  => gmdate( 'Y-m-d', time() - ( 180 * DAY_IN_SECONDS ) ),
			'to'    => gwc_vt_today(),
			'limit' => max( $limit * 4, 100 ),
		)
	);

	$due = array();

	foreach ( $candidates as $shift_id ) {
		if ( count( $due ) >= $limit ) {
			break;
		}

		if ( gwc_vt_shift_is_reconciled( $shift_id ) || ! gwc_vt_shift_has_ended( $shift_id ) ) {
			continue;
		}

		/* A shift nobody signed up for and nobody walked into is not a chore
		 * somebody forgot; it is a Saturday that did not happen. Left out of the
		 * nag, and still reconcilable by hand from the schedule. */
		if ( 0 === gwc_vt_shift_filled( $shift_id ) ) {
			continue;
		}

		$due[] = $shift_id;
	}

	return $due;
}

/**
 * Shifts coming up that are short of the people they need.
 *
 * The other half of the daily summary. Only shifts that have not started —
 * being short of people last Saturday is not something anybody can act on, and
 * a summary full of things that cannot be acted on is a summary nobody reads.
 *
 * @param int $days How far ahead to look.
 * @return int[]
 */
function gwc_vt_understaffed_shift_ids( int $days = 7 ): array {
	$candidates = gwc_vt_shifts_between(
		array(
			'from'  => gwc_vt_today(),
			'to'    => gmdate( 'Y-m-d', time() + ( max( 1, $days ) * DAY_IN_SECONDS ) ),
			'limit' => 100,
		)
	);

	$short = array();

	foreach ( $candidates as $shift_id ) {
		$starts = gwc_vt_shift_starts( $shift_id );

		if ( null === $starts || $starts->getTimestamp() <= time() ) {
			continue;
		}

		if ( gwc_vt_shift_is_understaffed( $shift_id ) ) {
			$short[] = $shift_id;
		}
	}

	return $short;
}

/* ── Where the admin screens live ────────────────────────────────────────────
 * URL builders rather than screens, and they sit here rather than beside the
 * screens they point at because the daily summary builds both — and the summary
 * runs on cron, where the admin bundle is not loaded at all. A digest that
 * fatals at three in the morning on a site nobody is watching is a feature that
 * silently stops existing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The schedule screen's own URL.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function gwc_vt_schedule_url( array $args = array() ): string {
	return add_query_arg(
		array_merge(
			array(
				'post_type' => GWC_VT_ENTRY_TYPE,
				'page'      => GWC_VT_SCHEDULE_PAGE,
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

/**
 * The screen that turns one shift's roster into hours.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_log_url( int $shift_id ): string {
	return add_query_arg(
		array(
			'post_type'    => GWC_VT_ENTRY_TYPE,
			'page'         => GWC_VT_QUICK_ADD_PAGE,
			'gwc_vt_shift' => $shift_id,
		),
		admin_url( 'edit.php' )
	);
}

/* ── Labels ──────────────────────────────────────────────────────────────── */

/**
 * A shift's date, as the site formats dates.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_date_label( int $shift_id ): string {
	$starts = gwc_vt_shift_starts( $shift_id );

	if ( null === $starts ) {
		return (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );
	}

	$format = (string) get_option( 'date_format' );

	return (string) wp_date( '' !== $format ? $format : 'D j M Y', $starts->getTimestamp() );
}

/**
 * A shift's time range, as the site formats times.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_time_label( int $shift_id ): string {
	$starts = gwc_vt_shift_starts( $shift_id );
	$ends   = gwc_vt_shift_ends( $shift_id );

	if ( null === $starts || null === $ends ) {
		return '';
	}

	$format = (string) get_option( 'time_format' );
	$format = '' !== $format ? $format : 'H:i';

	return sprintf(
		/* translators: 1: a start time, 2: an end time. */
		__( '%1$s–%2$s', 'groundwork-common-volunteer-tracker' ),
		(string) wp_date( $format, $starts->getTimestamp() ),
		(string) wp_date( $format, $ends->getTimestamp() )
	);
}

/**
 * How full a shift is, as a sentence for a coordinator.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_fill_label( int $shift_id ): string {
	$filled = gwc_vt_shift_filled( $shift_id );
	$max    = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );

	if ( $max > 0 ) {
		return sprintf(
			/* translators: 1: how many people have signed up, 2: how many the shift takes. */
			__( '%1$d of %2$d', 'groundwork-common-volunteer-tracker' ),
			$filled,
			$max
		);
	}

	return sprintf(
		/* translators: %d: how many people have signed up. */
		_n( '%d signed up', '%d signed up', $filled, 'groundwork-common-volunteer-tracker' ),
		$filled
	);
}

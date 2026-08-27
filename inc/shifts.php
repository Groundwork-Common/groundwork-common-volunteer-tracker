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

/* ── What state a shift is in, said once ─────────────────────────────────────
 * The schedule row and the dashboard's shift line each worked this out for
 * themselves, and they disagreed: the row knew about cancelled and awaiting and
 * had no idea what full meant, the line knew short and full and nothing else. A
 * shift that had ended with nobody on it read as neutral in one place and as
 * "filling normally" in the other.
 *
 * That was survivable while there were two of them. The redesign draws a shift
 * as a coloured chip in three more places — the dashboard week strip, the month
 * calendar, the shift drawer — and colour is doing real work there: it is how
 * "which Saturday is in trouble" gets answered without reading a row. Five
 * screens each deciding for themselves what red means is five chances to be
 * inconsistent about the one thing the colour is for.
 *
 * So: one function, one vocabulary of six words, and every screen asks it.
 *
 * ── Colour is reinforcement, never the only signal ───────────────────────────
 * Every one of these states has words to go with it — gwc_vt_shift_state_label()
 * for the state itself, gwc_vt_shift_fill_label() for the numbers — and no
 * screen may use the tint alone. "Short of people" and "full" is exactly the
 * distinction somebody with a colour vision deficiency must not have to infer
 * from a red square.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Which state a shift is in.
 *
 * @param int $shift_id Shift post ID.
 * @return string One of the keys in gwc_vt_shift_state_labels().
 */
function gwc_vt_shift_state( int $shift_id ): string {
	return gwc_vt_shift_state_from(
		array(
			'cancelled'    => gwc_vt_shift_is_cancelled( $shift_id ),
			'ended'        => gwc_vt_shift_has_ended( $shift_id ),
			'reconciled'   => gwc_vt_shift_is_reconciled( $shift_id ),
			'understaffed' => gwc_vt_shift_is_understaffed( $shift_id ),
			'filled'       => gwc_vt_shift_filled( $shift_id ),
			'max'          => (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true ),
		)
	);
}

/**
 * The same decision, over facts rather than a post ID.
 *
 * Split out so the precedence can be asserted without a database — the same
 * reason inc/dashboard.php keeps its counting apart from its screen. The
 * ordering below is the whole of this function and every line of it is a
 * decision somebody could reasonably have made differently.
 *
 * @param array $facts cancelled, ended, reconciled, understaffed, filled, max.
 * @return string
 */
function gwc_vt_shift_state_from( array $facts ): string {
	$cancelled    = (bool) ( $facts['cancelled'] ?? false );
	$ended        = (bool) ( $facts['ended'] ?? false );
	$reconciled   = (bool) ( $facts['reconciled'] ?? false );
	$understaffed = (bool) ( $facts['understaffed'] ?? false );
	$filled       = (int) ( $facts['filled'] ?? 0 );
	$max          = (int) ( $facts['max'] ?? 0 );

	/* First, because it survives everything else. A called-off shift that was
	 * full and had its hours logged is still called off, and that is the only
	 * thing anybody needs to know about it. */
	if ( $cancelled ) {
		return 'cancelled';
	}

	if ( $ended ) {
		if ( $reconciled ) {
			return 'logged';
		}

		/* Somebody has to have been on it. A shift that ended with an empty
		 * roster has no hours waiting to be written up, so calling it "awaiting"
		 * would put a permanent amber row on the schedule for work that never
		 * happened — and the dashboard's own worklist counts the same way. */
		if ( $filled > 0 ) {
			return 'awaiting';
		}

		/* Past, empty, nothing owed. Neutral rather than a seventh word: the
		 * schedule has always drawn this as an ordinary row, and the numbers
		 * beside it say "0 of 8" whatever colour it carries. */
		return 'ok';
	}

	/* Before full, because a shift can only be both when its minimum is above
	 * its maximum — and if somebody has managed to configure that, the state
	 * worth showing is the one that needs a phone call. Understaffed is already
	 * false for a shift that has ended; the check above has handled those. */
	if ( $understaffed ) {
		return 'short';
	}

	if ( $max > 0 && $filled >= $max ) {
		return 'full';
	}

	return 'ok';
}

/**
 * The words for each state.
 *
 * A function with a memo and not a const, for the reason every translated table
 * in this plugin is: a const is evaluated before the request's translations
 * load, which is invisible on an English site and total on every other one.
 *
 * 'full' and 'logged' are separate states that share a colour, and the month
 * calendar's legend says "Full, or hours logged" for the pair. They keep their
 * own words here because a row is one or the other and can say which.
 *
 * @return array<string, string>
 */
function gwc_vt_shift_state_labels(): array {
	static $labels = null;

	if ( null === $labels ) {
		$labels = array(
			'short'     => __( 'Short of people', 'groundwork-common-volunteer-tracker' ),
			'ok'        => __( 'Filling normally', 'groundwork-common-volunteer-tracker' ),
			'full'      => __( 'Full', 'groundwork-common-volunteer-tracker' ),
			'logged'    => __( 'Hours logged', 'groundwork-common-volunteer-tracker' ),
			'awaiting'  => __( 'Happened, hours not logged', 'groundwork-common-volunteer-tracker' ),
			'cancelled' => __( 'Called off', 'groundwork-common-volunteer-tracker' ),
		);
	}

	return $labels;
}

/**
 * The words for one state, or '' when it is not one.
 *
 * @param string $state A key from gwc_vt_shift_state_labels().
 * @return string
 */
function gwc_vt_shift_state_label( string $state ): string {
	return (string) ( gwc_vt_shift_state_labels()[ $state ] ?? '' );
}

/**
 * How full a shift is, and whether that is a problem — "3 of 8 · needs 6".
 *
 * The numbers are not enough on their own: "3 of 8" does not say whether three
 * is a problem, and on a calendar chip the colour that would say so is the one
 * thing some readers cannot see. So the sentence says it.
 *
 * Said here rather than at each caller because the dashboard's week strip, its
 * list, and eventually the month calendar all print it, and a chip reading
 * "needs 6" beside a row reading "full" would be two screens disagreeing about
 * one shift.
 *
 * @param int    $shift_id Shift post ID.
 * @param string $state    From gwc_vt_shift_state(), when the caller has it.
 * @return string
 */
function gwc_vt_shift_fill_summary( int $shift_id, string $state = '' ): string {
	$state   = '' !== $state ? $state : gwc_vt_shift_state( $shift_id );
	$summary = gwc_vt_shift_fill_label( $shift_id );

	if ( 'cancelled' === $state ) {
		return gwc_vt_shift_state_label( 'cancelled' );
	}

	if ( 'awaiting' === $state ) {
		return gwc_vt_shift_state_label( 'awaiting' );
	}

	if ( 'logged' === $state ) {
		return gwc_vt_shift_state_label( 'logged' );
	}

	if ( 'short' === $state ) {
		$min = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );

		if ( $min > 0 ) {
			return $summary . ' · ' . sprintf(
				/* translators: %d: how many people the shift needs. */
				__( 'needs %d', 'groundwork-common-volunteer-tracker' ),
				$min
			);
		}
	}

	if ( 'full' === $state ) {
		return $summary . ' · ' . __( 'full', 'groundwork-common-volunteer-tracker' );
	}

	return $summary;
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

/* ── How far each worklist line looks ────────────────────────────────────────
 * Named, because the number is now in two places that have to agree: the
 * counter below, and the link the dashboard builds to the screen that shows
 * what it counted. They were 180 and 7 here against 120 and 400 on the
 * schedule, so the count and the list were answering about different stretches
 * of the calendar — a line reading "5 need their hours logged" over a list
 * showing three of them, because the other two were four months back.
 *
 * A constant rather than a default argument for the same reason
 * gwc_vt_schedule_url() lives in this file: dashboard.php builds the link, runs
 * on cron, and cannot see anything in the admin bundle.
 * ─────────────────────────────────────────────────────────────────────────── */

/** How far back the unlogged-hours line looks. */
const GWC_VT_UNRECONCILED_DAYS = 180;

/** How far ahead the short-of-people line looks. */
const GWC_VT_UNDERSTAFFED_DAYS = 7;

/**
 * Shifts that have finished and whose hours have never been logged.
 *
 * The query the reconciliation nag is built on. An unreconciled shift is not an
 * error — somebody types Saturday up on Monday — but one that is still
 * unreconciled a fortnight later is hours nobody will ever remember, and those
 * hours are what a letter is made of.
 *
 * Counts an event's times individually — no `parent` argument — because the
 * daily digest is built on this and an event whose Saturday morning was never
 * written up is exactly what that email exists to mention. The schedule list
 * collapses events to one row by default, which is why the link the dashboard
 * builds to this asks it not to; see gwc_vt_schedule_slots().
 *
 * @param int $limit How many to return.
 * @return int[]
 */
function gwc_vt_unreconciled_shift_ids( int $limit = 50 ): array {
	$candidates = gwc_vt_shifts_between(
		array(
			'from'  => gmdate( 'Y-m-d', time() - ( GWC_VT_UNRECONCILED_DAYS * DAY_IN_SECONDS ) ),
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
function gwc_vt_understaffed_shift_ids( int $days = GWC_VT_UNDERSTAFFED_DAYS ): array {
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

/* ── Editing a whole repeat ──────────────────────────────────────────────────
 * The time moves an hour for the summer, the supervisor changes, the location
 * moves to the annexe. Twenty occurrences, one decision.
 *
 * The data model already allows it: recurrence creates real rows and
 * GWC_VT_SHIFT_SERIES links each to the first, so this is a loop over sibling
 * IDs rather than a change to how anything is stored. What it needs is not
 * storage but a screen, and this is the half of that which is not markup.
 *
 * Kept out of gwc_vt_handle_save_shift() deliberately. That handler is what
 * every other screen posts to, and giving it a mode that multiplies its blast
 * radius by twenty is how a save handler becomes something nobody can reason
 * about. This is its own page, its own nonce and its own handler.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The fields a repeat may change across every occurrence. */
const GWC_VT_REPEAT_FIELDS = array( 'time', 'activity', 'location', 'supervisor', 'notes', 'places', 'credentials' );

/**
 * Which occurrences a batch would change, and why it would leave the rest.
 *
 * Pure over the database it reads: no writes, so the confirmation screen and
 * the handler can call it with the same arguments and cannot disagree about
 * what is about to happen. The screen states these numbers; the handler acts on
 * them; the notice afterwards reports them. One function, per the rule in
 * CLAUDE.md about a count and the screen it links to.
 *
 * @param int   $series_id The first occurrence's ID.
 * @param array $opts      past: include occurrences that have happened.
 *                         cancelled: include occurrences that were called off.
 * @return array{change:int[], past:int[], cancelled:int[], people:int}
 */
function gwc_vt_repeat_targets( int $series_id, array $opts = array() ): array {
	$take_past      = ! empty( $opts['past'] );
	$take_cancelled = ! empty( $opts['cancelled'] );

	$out = array(
		'change'    => array(),
		'past'      => array(),
		'cancelled' => array(),
		'people'    => 0,
	);

	foreach ( gwc_vt_shift_series_ids( $series_id ) as $shift_id ) {
		$shift_id = (int) $shift_id;

		/* Cancelled first, and tested before "has it happened", because a
		 * called-off occurrence is a different kind of exclusion from a past
		 * one and the report has to be able to say which. A shift that is both
		 * is reported as cancelled: that is the answer this organization gave
		 * whoever had signed up, and it is the more surprising thing to change.
		 *
		 * Off by default for that reason. A cancellation is an answer somebody
		 * was given — "this is not happening" — and quietly rewriting its time
		 * un-answers it while leaving the notice that was sent standing. */
		if ( gwc_vt_shift_is_cancelled( $shift_id ) ) {
			$out['cancelled'][] = $shift_id;

			if ( ! $take_cancelled ) {
				continue;
			}
		} elseif ( gwc_vt_shift_has_ended( $shift_id ) ) {
			$out['past'][] = $shift_id;

			if ( ! $take_past ) {
				continue;
			}
		}

		$out['change'][] = $shift_id;

		/* Only the ones that could actually be told: a past occurrence's roster
		 * is not going to be emailed about a change to a shift that is over, and
		 * the handler will not send to it either. Counting them here would put a
		 * number on the screen larger than the one the send would produce. */
		if ( ! gwc_vt_shift_has_ended( $shift_id ) && ! gwc_vt_shift_is_cancelled( $shift_id ) ) {
			$out['people'] += count( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) );
		}
	}

	return $out;
}

/**
 * The values a repeat edit would write, for the fields it was asked to change.
 *
 * Date is not among them and cannot be: moving every occurrence by a day is a
 * different repeat, not an edit to this one. GWC_VT_SHIFT_SERIES is not either
 * — rewriting it would move occurrences between repeats, which is not what
 * anybody pressing "change the whole repeat" is asking for.
 *
 * Reads the same POST names as the single-shift editor and sanitizes them the
 * same way, so the two paths cannot drift about what a valid location is.
 *
 * @param array $posted Unslashed POST.
 * @param array $wanted Which of GWC_VT_REPEAT_FIELDS to change.
 * @return array{fields: array<string, string>, error: string}
 */
function gwc_vt_repeat_changes( array $posted, array $wanted ): array {
	$fields = array();

	if ( in_array( 'time', $wanted, true ) ) {
		$start     = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $posted['gwc_vt_start'] ?? '' ) ) );
		$end       = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $posted['gwc_vt_end'] ?? '' ) ) );
		$overnight = ! empty( $posted['gwc_vt_overnight'] );

		if ( gwc_vt_shift_duration( $start, $end, $overnight ) < 1 ) {
			return array(
				'fields'      => array(),
				'credentials' => null,
				'error'       => 'bad-time',
			);
		}

		$fields[ GWC_VT_SHIFT_START ]     = $start;
		$fields[ GWC_VT_SHIFT_END ]       = $end;
		$fields[ GWC_VT_SHIFT_OVERNIGHT ] = gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, $overnight );
	}

	if ( in_array( 'activity', $wanted, true ) ) {
		$fields[ GWC_VT_SHIFT_ACTIVITY ] = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_activity'] ?? '' ) ), 0, 200 );
	}

	if ( in_array( 'location', $wanted, true ) ) {
		$fields[ GWC_VT_SHIFT_LOCATION ] = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_location'] ?? '' ) ), 0, 200 );
	}

	if ( in_array( 'supervisor', $wanted, true ) ) {
		$fields[ GWC_VT_SHIFT_SUPERVISOR ] = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_supervisor'] ?? '' ) ), 0, 100 );
	}

	if ( in_array( 'notes', $wanted, true ) ) {
		$fields[ GWC_VT_SHIFT_NOTES ] = mb_substr( sanitize_textarea_field( (string) ( $posted['gwc_vt_notes'] ?? '' ) ), 0, 1000 );
	}

	if ( in_array( 'places', $wanted, true ) ) {
		$fields[ GWC_VT_SHIFT_MIN ] = gwc_vt_shift_meta_value( GWC_VT_SHIFT_MIN, $posted['gwc_vt_min'] ?? 0 );
		$fields[ GWC_VT_SHIFT_MAX ] = gwc_vt_shift_meta_value( GWC_VT_SHIFT_MAX, $posted['gwc_vt_max'] ?? 0 );
	}

	/* Beside $fields rather than in it, and null rather than an empty array
	 * when it was not asked for.
	 *
	 * $fields is a map of meta key to one scalar, written with
	 * update_post_meta(); credentials are many rows under one key. And clearing
	 * every credential off a repeat is a thing somebody may legitimately want,
	 * so "an empty list" has to be distinguishable from "leave them alone" —
	 * which an empty array cannot do. */
	$credentials = in_array( 'credentials', $wanted, true )
		? gwc_vt_posted_credential_ids( $posted )
		: null;

	return array(
		'fields'      => $fields,
		'credentials' => $credentials,
		'error'       => '',
	);
}

/**
 * Write one set of values across a repeat's occurrences.
 *
 * @param int[]      $shift_ids   Occurrences to change.
 * @param array      $fields      Meta key => value.
 * @param bool       $notify      Whether to tell the rosters of the ones still to come.
 * @param int[]|null $credentials What every occurrence should ask people to hold,
 *                                or null to leave that alone. An empty array is
 *                                a real instruction: ask for nothing.
 * @return array{changed:int, told:int}
 */
function gwc_vt_apply_repeat_changes( array $shift_ids, array $fields, bool $notify, $credentials = null ): array {
	$changed = 0;
	$told    = 0;

	/* Both, because credentials are not in $fields. Checking only $fields would
	 * make "change the credentials across the whole repeat, and nothing else"
	 * a no-op that reports success. */
	if ( ! $fields && null === $credentials ) {
		return array(
			'changed' => 0,
			'told'    => 0,
		);
	}

	foreach ( $shift_ids as $shift_id ) {
		$shift_id = (int) $shift_id;

		if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
			continue;
		}

		/* Snapshot per occurrence, because gwc_vt_shift_moved() asks whether
		 * THIS shift moved. A repeat where somebody had already corrected one
		 * Saturday's time by hand has an occurrence that is not changing, and
		 * mailing its roster to say it did is the mass-mail-as-side-effect this
		 * plugin refuses on the single-shift path too. */
		$was = gwc_vt_shift_snapshot( $shift_id );

		foreach ( $fields as $key => $value ) {
			update_post_meta( $shift_id, $key, $value );
		}

		if ( null !== $credentials ) {
			gwc_vt_set_shift_credentials( $shift_id, (array) $credentials );
		}

		gwc_vt_retitle_shift( $shift_id );

		++$changed;

		if ( ! $notify || gwc_vt_shift_has_ended( $shift_id ) || gwc_vt_shift_is_cancelled( $shift_id ) ) {
			continue;
		}

		if ( ! gwc_vt_shift_moved( $shift_id, $was ) ) {
			continue;
		}

		foreach ( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $signup_id ) {
			gwc_vt_queue_signup_mail( 'changed', (int) $signup_id, array( 'was' => $was ) );
			++$told;
		}
	}

	return array(
		'changed' => $changed,
		'told'    => $told,
	);
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

/* ── The repeat a shift came from ────────────────────────────────────────────
 * Twenty Saturdays created by one repeat are indistinguishable on the schedule
 * from twenty shifts somebody made by hand, which is most of why nobody dares
 * edit one of them: there is no way to tell, from the row, whether changing it
 * changes the other nineteen. (It does not. Every occurrence is its own post —
 * see the note at the top of inc/recurrence.php.)
 *
 * So the row says so. The series ID is on every occurrence; the pattern and the
 * end date are read back off the siblings' dates by
 * gwc_vt_recurrence_pattern_of(), which is pure and lives beside the arithmetic
 * that produced them.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every date in a shift's series, ascending.
 *
 * Memoized per request and keyed by series, because the schedule calls this once
 * per row: a page of twenty Saturdays from one repeat is one query, not twenty.
 * Not a persistent cache — a series changes whenever a shift in it is added,
 * cancelled or deleted, and the invalidation would have to hang off three write
 * paths to buy a saving on a screen that already runs two queries.
 *
 * @param int $series_id The first occurrence's post ID, as stored on every one.
 * @return string[] Y-m-d, ascending. Empty when the series is unknown.
 */
function gwc_vt_shift_series_dates( int $series_id ): array {
	static $seen = array();

	if ( $series_id < 1 ) {
		return array();
	}

	if ( isset( $seen[ $series_id ] ) ) {
		return $seen[ $series_id ];
	}

	$dates = array();

	foreach ( gwc_vt_shift_series_ids( $series_id ) as $id ) {
		$date = (string) get_post_meta( $id, GWC_VT_SHIFT_DATE, true );

		if ( '' !== $date ) {
			$dates[] = $date;
		}
	}

	sort( $dates );

	$seen[ $series_id ] = $dates;

	return $dates;
}

/**
 * Which shift is the head of this one's repeat, or 0 for a one-off.
 *
 * The series is stored as the first occurrence's own ID, so the head's meta
 * points at itself and reading it needs no special case. A shift with no series
 * meta at all was made on its own and has no repeat to edit.
 *
 * @param int $shift_id Shift post ID.
 * @return int
 */
function gwc_vt_shift_series_id( int $shift_id ): int {
	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return 0;
	}

	return max( 0, (int) get_post_meta( $shift_id, GWC_VT_SHIFT_SERIES, true ) );
}

/**
 * Every occurrence one repeat made, oldest first.
 *
 * Split out of gwc_vt_shift_series_dates(), which used to do the query itself.
 * Editing a whole repeat needs the rows rather than the dates, and two copies
 * of "which shifts did this repeat make" is two places for the status list to
 * drift — a batch write that missed cancelled occurrences while the note above
 * it counted them would report a number it did not do.
 *
 * Every status, including cancelled: a called-off Saturday is still one of the
 * dates the repeat created, and leaving it out would make a fully cancelled
 * fortnight read as a gap in the pattern.
 *
 * @param int $series_id The first occurrence's ID.
 * @return int[]
 */
function gwc_vt_shift_series_ids( int $series_id ): array {
	if ( $series_id < 1 ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_SHIFT_TYPE,
			'post_status'            => array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ),
			'posts_per_page'         => GWC_VT_RECURRENCE_MAX,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the series ID is meta; there is no other way to ask which shifts one repeat made.
				array(
					'key'   => GWC_VT_SHIFT_SERIES,
					'value' => (string) $series_id,
				),
			),
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	usort(
		$ids,
		static function ( int $a, int $b ): int {
			return strcmp(
				(string) get_post_meta( $a, GWC_VT_SHIFT_DATE, true ),
				(string) get_post_meta( $b, GWC_VT_SHIFT_DATE, true )
			);
		}
	);

	return $ids;
}

/**
 * What a row says about the repeat a shift belongs to, or '' when it has none.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_shift_repeat_note( int $shift_id ): string {
	$series = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_SERIES, true );

	if ( $series < 1 ) {
		return '';
	}

	$dates = gwc_vt_shift_series_dates( $series );

	/* One date is not a repeat. It is what is left of one after everything else
	 * was deleted, and there is nothing useful to say about it. */
	if ( count( $dates ) < 2 ) {
		return '';
	}

	$pattern = gwc_vt_recurrence_pattern_of( $dates );
	$last    = gwc_vt_shift_date_label_from( (string) end( $dates ) );
	$notes   = gwc_vt_recurrence_repeat_notes();

	if ( isset( $notes[ $pattern ] ) ) {
		return sprintf( $notes[ $pattern ], $last );
	}

	/* An edited series that no longer looks like any pattern still says what it
	 * is, because the useful half of the sentence is "this is one of several and
	 * they were made together" rather than the name of the rhythm. */
	return sprintf(
		/* translators: 1: how many were made together. 2: the last date in the run. */
		__( 'One of %1$d made together, through %2$s', 'groundwork-common-volunteer-tracker' ),
		count( $dates ),
		$last
	);
}

/* ── Labels ──────────────────────────────────────────────────────────────── */

/**
 * A bare Y-m-d, as the site formats dates.
 *
 * The date half of gwc_vt_shift_date_label() without a post to read it from —
 * the series dates are strings, and loading twenty shifts to format one of them
 * would be a query for a comma.
 *
 * UTC passed back in for the reason gwc_vt_recurrence_date_label() does it: a
 * bare calendar date has no time of day, and letting wp_date() move it into the
 * site's zone lands the previous evening on any site behind UTC.
 *
 * @param string $date Y-m-d.
 * @return string
 */
function gwc_vt_shift_date_label_from( string $date ): string {
	$parsed = gwc_vt_recurrence_date( $date );

	if ( null === $parsed ) {
		return $date;
	}

	$format = (string) get_option( 'date_format' );

	return (string) wp_date(
		'' !== $format ? $format : 'D j M Y',
		$parsed->getTimestamp(),
		new DateTimeZone( 'UTC' )
	);
}

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
			/* translators: 1: how many places are taken, 2: how many places there are. */
			_x( '%1$d of %2$d', 'places taken of places available', 'groundwork-common-volunteer-tracker' ),
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

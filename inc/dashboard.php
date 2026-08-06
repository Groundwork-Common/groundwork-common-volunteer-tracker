<?php
/**
 * What is true right now: what is waiting, and what the year adds up to.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** How long the reporting-year figures are cached for. */
const GWCVT_YEAR_TOTALS_TTL = HOUR_IN_SECONDS;

/** The transient the reporting-year figures live in. */
const GWCVT_YEAR_TOTALS_KEY = 'gwcvt_year_totals';

/* ── Everything here is a queue ──────────────────────────────────────────────
 * That is the test a line has to pass to appear on the dashboard: dealing with
 * it makes it go away. It is what separates a worklist from a status board, and
 * it is why there is no panel here listing who is working off a requirement —
 * no amount of work makes a list of who is under a court order shorter, so it
 * is not a queue, and a standing roster of the mandated people does not belong
 * on the screen everybody lands on. What appears instead is the one line that
 * IS a queue: somebody has passed their deadline, go and look.
 *
 * The names go with it. Every count here links to the screen that holds the
 * names, and that screen is where somebody has gone deliberately.
 * ─────────────────────────────────────────────────────────────────────────── */

/* ── Nothing waiting, or nothing yet? ────────────────────────────────────────
 * The worklist leaves out every queue that is empty, which is right: a screen
 * reporting "0 waiting" five times over is one people stop reading. But it means
 * a site with no volunteers, no hours and no shifts produced the same screen as
 * a site where everything is done — and said, to somebody who installed the
 * plugin ninety seconds ago, that everything logged has been verified and no
 * shift this week is short of people.
 *
 * Both sentences are true of an empty database and neither is any use. So the
 * all-clear needs one thing the counts cannot tell it: whether there has ever
 * been anything here.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Does this site have any volunteer records at all yet?
 *
 * Deliberately not counting auto-drafts. Opening "Add volunteer" and wandering
 * off creates one, and a site that looked started because somebody opened a
 * screen would lose the guidance at exactly the moment it was needed.
 *
 * @return bool
 */
function gwcvt_has_any_records(): bool {
	$looking = array(
		GWCVT_VOLUNTEER_TYPE => array( 'publish' ),
		GWCVT_ENTRY_TYPE     => array( 'publish' ),
		GWCVT_SHIFT_TYPE     => array( 'publish', 'draft' ),
	);

	foreach ( $looking as $type => $statuses ) {
		$counts = (array) wp_count_posts( $type );

		foreach ( $statuses as $status ) {
			if ( (int) ( $counts[ $status ] ?? 0 ) > 0 ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Everything the dashboard needs to know, in one pass.
 *
 * @return array<string, int>
 */
function gwcvt_dashboard_counts(): array {
	return array(
		'unreconciled' => count( gwcvt_unreconciled_shift_ids( 20 ) ),
		'understaffed' => count( gwcvt_understaffed_shift_ids( 7 ) ),
		'overdue'      => count( gwcvt_overdue_requirement_ids() ),
		'unverified'   => gwcvt_unverified_count(),
		'unmatched'    => gwcvt_unmatched_count(),
	);
}

/**
 * The worklist: what is waiting, in the order it should be dealt with, with
 * anything at zero left out entirely.
 *
 * Pure, and takes plain counts rather than doing its own queries, so the two
 * rules that matter — the ordering, and that nothing empty appears — can be
 * asserted without a database. See DashboardTest.
 *
 * ── Why this order ──────────────────────────────────────────────────────────
 * Not by size, and not by how loud it feels. By what is lost if it waits.
 *
 * Unlogged hours first: a shift that happened and was never typed up is hours
 * on nobody's record, and every week that passes is a week further from anybody
 * remembering them. Then shifts still short of people, because that one has a
 * deadline — on Sunday there is nothing to be done about Saturday. Then a
 * missed requirement, which matters enormously to one person but cannot be
 * fixed today. Verifying and matching come last: both keep, and neither loses
 * anything overnight.
 *
 * @param array<string, int> $counts From gwcvt_dashboard_counts().
 * @return array<int, array{key:string, count:int, severity:string, what:string, why:string, action:string}>
 */
function gwcvt_dashboard_items( array $counts ): array {
	/* Neither form carries the number. The count is rendered beside the sentence
	 * as its own element, large, because that is the thing being scanned — so a
	 * sentence that restated it read "2  2 shifts this week are short of people".
	 *
	 * Which is also why there is no placeholder rather than one in both forms:
	 * a placeholder in only the plural is a real bug, since Russian, Polish and
	 * Arabic use what gettext calls the singular for 21, 31 and 101 as well, and
	 * WP-CLI warns about exactly that. Dropping it from both sides fixes the
	 * mismatch instead of papering over it, and translate_nooped_plural() still
	 * picks the form from $count either way. The one thing it costs is a
	 * language that would want the number mid-sentence, and there is nowhere to
	 * put it — the number is a column, and the column is the design.
	 */
	/* Every line names the job, verb first. The screen exists to be acted on, and
	 * a sentence that describes a state — "a shift has happened and its hours
	 * are not logged" — makes the reader do the translation into a task before
	 * they can decide anything. The state belongs in the second line, which is
	 * there to answer "and if I leave it?". */
	$defined = array(
		'unreconciled' => array(
			'severity' => 'critical',
			'what'     => _n_noop(
				'Write up a shift that has already happened',
				'Write up shifts that have already happened',
				'groundwork-common-volunteer-tracker'
			),
			'why'      => __( 'Until somebody types them up, those hours are on nobody’s record and cannot reach a letter.', 'groundwork-common-volunteer-tracker' ),
			'action'   => __( 'Log the hours', 'groundwork-common-volunteer-tracker' ),
		),
		'understaffed' => array(
			'severity' => 'critical',
			'what'     => _n_noop(
				'Find people for a shift this week',
				'Find people for shifts this week',
				'groundwork-common-volunteer-tracker'
			),
			'why'      => __( 'There is still time to ring round. On Sunday there is nothing to be done about Saturday.', 'groundwork-common-volunteer-tracker' ),
			'action'   => __( 'Open the schedule', 'groundwork-common-volunteer-tracker' ),
		),
		'overdue'      => array(
			'severity' => 'waiting',
			'what'     => _n_noop(
				'Check on somebody past their deadline',
				'Check on people past their deadline',
				'groundwork-common-volunteer-tracker'
			),
			'why'      => __( 'Hours of theirs may be logged and simply not verified yet — that may be all it is. The names are on the volunteer list.', 'groundwork-common-volunteer-tracker' ),
			'action'   => __( 'See who', 'groundwork-common-volunteer-tracker' ),
		),
		'unverified'   => array(
			'severity' => 'waiting',
			'what'     => _n_noop(
				'Verify a shift somebody has logged',
				'Verify shifts somebody has logged',
				'groundwork-common-volunteer-tracker'
			),
			'why'      => __( 'Only verified hours appear on a letter, so these count for nobody yet.', 'groundwork-common-volunteer-tracker' ),
			'action'   => __( 'Verify them', 'groundwork-common-volunteer-tracker' ),
		),
		'unmatched'    => array(
			'severity' => 'waiting',
			'what'     => _n_noop(
				'Match somebody who sent in hours',
				'Match people who sent in hours',
				'groundwork-common-volunteer-tracker'
			),
			'why'      => __( 'What somebody typed into the public form is a claim until a person says whose it is.', 'groundwork-common-volunteer-tracker' ),
			'action'   => __( 'Match them', 'groundwork-common-volunteer-tracker' ),
		),
	);

	$items = array();

	foreach ( $defined as $key => $item ) {
		$count = (int) ( $counts[ $key ] ?? 0 );

		if ( $count < 1 ) {
			continue;
		}

		$items[] = array(
			'key'      => $key,
			'count'    => $count,
			'severity' => (string) $item['severity'],
			'what'     => translate_nooped_plural( $item['what'], $count, 'groundwork-common-volunteer-tracker' ),
			'why'      => (string) $item['why'],
			'action'   => (string) $item['action'],
		);
	}

	/**
	 * The dashboard's worklist, after empty entries have been dropped.
	 *
	 * @param array $items  The lines, in order.
	 * @param array $counts What they were built from.
	 */
	return (array) apply_filters( 'gwcvt_dashboard_items', $items, $counts );
}

/**
 * Where each worklist line goes.
 *
 * Separate from the item itself because it is the one part that needs the admin
 * loaded — gwcvt_dashboard_items() stays pure and testable.
 *
 * @param string $key Item key.
 * @return string
 */
function gwcvt_dashboard_item_url( string $key ): string {
	switch ( $key ) {
		case 'unreconciled':
			$waiting = gwcvt_unreconciled_shift_ids( 1 );

			return $waiting
				? gwcvt_shift_log_url( (int) $waiting[0] )
				: gwcvt_schedule_url( array( 'when' => 'past' ) );

		case 'understaffed':
			return gwcvt_schedule_url();

		case 'overdue':
			/* Filtered to the people this line counted. The bare list was every
			 * volunteer the site has ever had, in no order, under a link reading
			 * "See who". The filter's slug is spelled out rather than referenced
			 * through GWCVT_VOLUNTEER_FILTER because that constant lives in the
			 * admin bundle and this file is loaded for cron and WP-CLI too. */
			return add_query_arg(
				array(
					'post_type'         => GWCVT_VOLUNTEER_TYPE,
					'gwcvt_requirement' => 'overdue',
				),
				admin_url( 'edit.php' )
			);

		case 'unverified':
			return add_query_arg(
				array(
					'post_type'   => GWCVT_ENTRY_TYPE,
					'gwcvt_state' => 'unverified',
				),
				admin_url( 'edit.php' )
			);

		case 'unmatched':
			return add_query_arg(
				array(
					'post_type'   => GWCVT_ENTRY_TYPE,
					'gwcvt_state' => 'unmatched',
				),
				admin_url( 'edit.php' )
			);
	}

	return admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE );
}

/* ── Where this week stops ───────────────────────────────────────────────────
 * "Coming up" runs a fortnight and is split in two, because those two halves
 * are answers to different questions. This week is what a coordinator can still
 * do something about — ring round, move somebody, cancel while people can still
 * be told. Next week is what they should be thinking about, and nothing more.
 * Fourteen undifferentiated rows would answer neither.
 *
 * Where the week breaks is the site's business, not this plugin's: WordPress
 * already asks on Settings → General and a great many places outside Europe and
 * North America answer Saturday or Sunday.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The last day of this week and the last day of the one after.
 *
 * Pure calendar arithmetic on date strings, with no timezone in it — midnight
 * on a bare Y-m-d has the same weekday everywhere, which is the whole reason
 * shifts store a date and a wall-clock time rather than an instant.
 *
 * @param string $today         Y-m-d.
 * @param int    $start_of_week 0 for Sunday through 6 for Saturday, as WordPress stores it.
 * @return array{this_week:string, fortnight:string}
 */
function gwcvt_fortnight_bounds( string $today, int $start_of_week ): array {
	$midnight = (int) strtotime( $today . ' 00:00:00 UTC' );

	if ( ! $midnight ) {
		$today    = gmdate( 'Y-m-d' );
		$midnight = (int) strtotime( $today . ' 00:00:00 UTC' );
	}

	$start_of_week = ( ( $start_of_week % 7 ) + 7 ) % 7;

	/* How far into the week today already is, so the remainder is what is left
	 * of it. On the first day of the week that is nothing and this week runs a
	 * full seven days; on the last it is six and this week ends tonight. */
	$elapsed = ( (int) gmdate( 'w', $midnight ) - $start_of_week + 7 ) % 7;

	$this_week = $midnight + ( ( 6 - $elapsed ) * DAY_IN_SECONDS );

	return array(
		'this_week' => gmdate( 'Y-m-d', $this_week ),
		'fortnight' => gmdate( 'Y-m-d', $this_week + ( 7 * DAY_IN_SECONDS ) ),
	);
}

/* ── Requirements that have run out of time ─────────────────────────────────
 * Counted here, never listed here. See the box comment at the top.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Volunteers whose deadline has passed with the hours not done.
 *
 * @return int[]
 */
function gwcvt_overdue_requirement_ids(): array {
	$candidates = get_posts(
		array(
			'post_type'              => GWCVT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 200,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- two indexed keys, once per dashboard load.
			'meta_query'             => array(
				array(
					'key'     => GWCVT_VOLUNTEER_REQUIRED_BY,
					'value'   => gwcvt_today(),
					'compare' => '<',
					'type'    => 'CHAR',
				),
			),
		)
	);

	$overdue = array();

	foreach ( (array) $candidates as $volunteer_id ) {
		$volunteer_id = (int) $volunteer_id;

		if ( ! gwcvt_has_requirement( $volunteer_id ) ) {
			continue;
		}

		if ( gwcvt_requirement_progress( $volunteer_id )['overdue'] ) {
			$overdue[] = $volunteer_id;
		}
	}

	return $overdue;
}

/* ── The year ───────────────────────────────────────────────────────────────
 * One figure, and the only one on this screen that is a claim rather than a
 * prompt: it is what goes into a Form 990 or a grant report. Verified hours
 * only, for the same reason a letter states only those — nobody has attested to
 * the rest.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * When the reporting year starts, as Y-m-d.
 *
 * A filter rather than a setting. Most organisations report on the calendar
 * year; the ones that do not know exactly when theirs begins and have somebody
 * who can add a line to a theme. A setting would put a question on the Settings
 * screen that almost nobody needs to answer, and answering it wrongly would
 * quietly misstate a figure that goes to a funder.
 *
 * @return string
 */
function gwcvt_reporting_year_start(): string {
	$start = gmdate( 'Y' ) . '-01-01';

	/**
	 * The first day of the reporting year.
	 *
	 * @param string $start Y-m-d.
	 */
	$start = (string) apply_filters( 'gwcvt_reporting_year_start', $start );

	return '' !== gwcvt_sanitize_date( $start ) ? $start : gmdate( 'Y' ) . '-01-01';
}

/**
 * The organisation's own totals for a date range.
 *
 * Cached for an hour. This is the one query on the screen that grows with the
 * size of the organisation, and it answers a question whose answer does not
 * change minute to minute — unlike everything above it, which is a queue and
 * has to be current or it is worse than absent.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array{verified:int, pending:int, entries:int, volunteers:int}
 */
function gwcvt_org_totals( string $from, string $to ): array {
	$cache_key = GWCVT_YEAR_TOTALS_KEY . '_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = get_posts(
		array(
			'post_type'              => GWCVT_ENTRY_TYPE,
			'post_status'            => array( 'publish', 'pending' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 5000,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed range, cached for an hour.
			'meta_query'             => array(
				array(
					'key'     => GWCVT_ENTRY_DATE,
					'value'   => array( $from, $to ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
			),
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( $ids ) {
		update_postmeta_cache( $ids );
	}

	$totals = array(
		'verified'   => 0,
		'pending'    => 0,
		'entries'    => 0,
		'volunteers' => 0,
	);

	$people = array();

	foreach ( $ids as $entry_id ) {
		$minutes = (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true );

		if ( $minutes < 1 ) {
			continue;
		}

		if ( '' !== (string) get_post_meta( $entry_id, GWCVT_ENTRY_VERIFIED_AT, true ) ) {
			$totals['verified'] += $minutes;
		} else {
			$totals['pending'] += $minutes;
		}

		++$totals['entries'];

		$volunteer = (int) get_post_meta( $entry_id, GWCVT_ENTRY_VOLUNTEER, true );

		if ( $volunteer > 0 ) {
			$people[ $volunteer ] = true;
		}
	}

	$totals['volunteers'] = count( $people );

	set_transient( $cache_key, $totals, GWCVT_YEAR_TOTALS_TTL );

	return $totals;
}

/**
 * Forget the cached year figures.
 *
 * Hooked to the same writes the per-volunteer rollup listens for, so a
 * coordinator who logs a day does not then read an hour-old total and wonder
 * where their afternoon went.
 */
function gwcvt_forget_org_totals(): void {
	delete_transient( GWCVT_YEAR_TOTALS_KEY . '_' . md5( gwcvt_reporting_year_start() . '|' . gwcvt_today() ) );
}

add_action( 'gwcvt_entry_saved', 'gwcvt_forget_org_totals' );
add_action( 'gwcvt_entry_verified', 'gwcvt_forget_org_totals' );
add_action( 'gwcvt_entry_unverified', 'gwcvt_forget_org_totals' );

/* The two paths that create or move hours without going through
 * gwcvt_entry_saved. A submission through the public form lands as a pending
 * entry and shifts the "awaiting verification" figure; attaching one to a
 * volunteer changes how many people the year counts. Neither is urgent enough
 * to matter much, and both are one line, and a figure that is quietly an hour
 * out is the kind of thing somebody eventually reports as a bug. */
add_action( 'gwcvt_self_log_received', 'gwcvt_forget_org_totals' );
add_action( 'gwcvt_entry_attached', 'gwcvt_forget_org_totals' );

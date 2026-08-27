<?php
/**
 * Folding a run of called-off Saturdays, and saying which repeat a row came from.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * The pattern arithmetic is pure and is covered by tests/RecurrenceTest.php.
 * Everything here is about real posts:
 *
 *   gwc_vt_fold_cancelled_repeats() reads each row's post status and its series
 *   meta. What it must get right is CONSECUTIVE — a cancelled Saturday in
 *   October and another in December are two separate answers this organization
 *   owes two different sets of people, and a fold that reached across the live
 *   shifts between them would hide one of them rather than tidy it.
 *
 *   gwc_vt_shift_repeat_note() derives the pattern and the end date from the
 *   siblings, which means a query per series and a real meta lookup.
 *
 * Neither is reachable from the unit suite: tests/bootstrap.php has no
 * get_posts(), deliberately, for the reasons its own comment gives.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/schedule-folding.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_sf_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sf_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * Delete every shift this script created.
 *
 * On shutdown so it runs whether this finishes, fails an assertion or fatals;
 * PHP runs shutdown functions on exit(), so the exit( 1 ) at the foot is safe
 * beside it. Force-deleted rather than trashed — a trashed fixture still
 * answers a meta query and would fold into the next run's rows.
 */
function gwc_vt_sf_cleanup(): void {
	foreach ( (array) ( $GLOBALS['gwc_vt_sf_made'] ?? array() ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

register_shutdown_function( 'gwc_vt_sf_cleanup' );

/**
 * One shift, on a date, in a series, with a status.
 *
 * @param string $date   Y-m-d.
 * @param int    $series The series ID, or 0 to fill in with the shift's own.
 * @param string $status Post status.
 * @return int
 */
function gwc_vt_sf_make_shift( string $date, int $series, string $status ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	$id = (int) $id;

	$GLOBALS['gwc_vt_sf_made'][] = $id;

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Folding fixture' );
	update_post_meta( $id, GWC_VT_SHIFT_SERIES, (string) ( $series > 0 ? $series : $id ) );

	if ( 'publish' !== $status ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			)
		);
	}

	return $id;
}

/**
 * The rows the schedule would build for a set of shifts, in date order.
 *
 * @param int[] $ids Shift post IDs.
 * @return array[]
 */
function gwc_vt_sf_rows( array $ids ): array {
	$rows = array();

	foreach ( $ids as $id ) {
		$rows[] = array(
			'type' => 'shift',
			'id'   => (int) $id,
			'date' => (string) get_post_meta( (int) $id, GWC_VT_SHIFT_DATE, true ),
		);
	}

	usort(
		$rows,
		static function ( array $a, array $b ) {
			return strcmp( $a['date'], $b['date'] ) ?: ( $a['id'] <=> $b['id'] );
		}
	);

	return $rows;
}

/* ── A weekly series with six called off in the middle ───────────────────────
 * Twelve Saturdays. Occurrences three to eight are cancelled, which is the
 * shape the design was drawn from: six struck-through rows burying everything
 * a coordinator could act on that fortnight.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sf_run    = gwc_vt_recurrence_dates( '2031-03-01', 'weekly', '2031-05-17' );
$gwc_vt_sf_series = 0;
$gwc_vt_sf_ids    = array();

foreach ( $gwc_vt_sf_run['dates'] as $gwc_vt_sf_i => $gwc_vt_sf_date ) {
	$gwc_vt_sf_status = ( $gwc_vt_sf_i >= 2 && $gwc_vt_sf_i <= 7 ) ? GWC_VT_SHIFT_CANCELLED : 'publish';
	$gwc_vt_sf_id     = gwc_vt_sf_make_shift( $gwc_vt_sf_date, $gwc_vt_sf_series, $gwc_vt_sf_status );

	if ( 0 === $gwc_vt_sf_series ) {
		$gwc_vt_sf_series = $gwc_vt_sf_id;
		update_post_meta( $gwc_vt_sf_id, GWC_VT_SHIFT_SERIES, (string) $gwc_vt_sf_series );
	}

	$gwc_vt_sf_ids[] = $gwc_vt_sf_id;
}

$gwc_vt_sf_before = gwc_vt_sf_rows( $gwc_vt_sf_ids );
$gwc_vt_sf_after  = gwc_vt_fold_cancelled_repeats( $gwc_vt_sf_before );

gwc_vt_sf_check(
	'twelve shifts become seven rows',
	7 === count( $gwc_vt_sf_after ),
	count( $gwc_vt_sf_before ) . ' rows in, ' . count( $gwc_vt_sf_after ) . ' out'
);

$gwc_vt_sf_folded = array_values(
	array_filter(
		$gwc_vt_sf_after,
		static function ( array $row ): bool {
			return ( $row['folded'] ?? 0 ) > 1;
		}
	)
);

gwc_vt_sf_check(
	'exactly one row stands for more than itself',
	1 === count( $gwc_vt_sf_folded ),
	(string) count( $gwc_vt_sf_folded )
);

gwc_vt_sf_check(
	'and it says it stands for six',
	isset( $gwc_vt_sf_folded[0]['folded'] ) && 6 === $gwc_vt_sf_folded[0]['folded'],
	isset( $gwc_vt_sf_folded[0]['folded'] ) ? (string) $gwc_vt_sf_folded[0]['folded'] : 'nothing folded'
);

gwc_vt_sf_check(
	'the folded row starts at the first of them',
	isset( $gwc_vt_sf_folded[0]['date'] ) && $gwc_vt_sf_run['dates'][2] === $gwc_vt_sf_folded[0]['date'],
	( $gwc_vt_sf_folded[0]['date'] ?? '?' ) . ' expected ' . $gwc_vt_sf_run['dates'][2]
);

gwc_vt_sf_check(
	'and reaches the last of them',
	isset( $gwc_vt_sf_folded[0]['folded_to'] ) && $gwc_vt_sf_run['dates'][7] === $gwc_vt_sf_folded[0]['folded_to'],
	( $gwc_vt_sf_folded[0]['folded_to'] ?? '?' ) . ' expected ' . $gwc_vt_sf_run['dates'][7]
);

/* The shifts are still there. Folding decides how many lines get drawn, not how
 * many shifts exist — anything counting occurrences counts twelve either way. */
gwc_vt_sf_check(
	'folding removed no shift',
	12 === count( $gwc_vt_sf_before ),
	(string) count( $gwc_vt_sf_before )
);

/* ── Two cancelled occurrences with live shifts between them ─────────────────
 * The case the whole thing turns on. Cancel two more, far apart, and neither
 * may join the other or the run above.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $gwc_vt_sf_ids[9],
		'post_status' => GWC_VT_SHIFT_CANCELLED,
	)
);

wp_update_post(
	array(
		'ID'          => $gwc_vt_sf_ids[11],
		'post_status' => GWC_VT_SHIFT_CANCELLED,
	)
);

$gwc_vt_sf_apart = gwc_vt_fold_cancelled_repeats( gwc_vt_sf_rows( $gwc_vt_sf_ids ) );

$gwc_vt_sf_folds = array_values(
	array_filter(
		$gwc_vt_sf_apart,
		static function ( array $row ): bool {
			return ( $row['folded'] ?? 0 ) > 1;
		}
	)
);

gwc_vt_sf_check(
	'two cancellations with a live shift between them stay two rows',
	1 === count( $gwc_vt_sf_folds ),
	count( $gwc_vt_sf_folds ) . ' folded groups; only the run of six should fold'
);

gwc_vt_sf_check(
	'so the list is one row shorter for each of them, not one for both',
	7 === count( $gwc_vt_sf_apart ),
	(string) count( $gwc_vt_sf_apart )
);

/* ── Adjacent cancellations from different repeats ───────────────────────────
 * Two shifts on consecutive days, both called off, made by two different
 * repeats. They are adjacent in the list and must still be two rows: "one
 * repeat was called off" is the claim the fold makes, and it would be false.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_sf_a = gwc_vt_sf_make_shift( '2031-08-02', 0, GWC_VT_SHIFT_CANCELLED );
$gwc_vt_sf_b = gwc_vt_sf_make_shift( '2031-08-03', 0, GWC_VT_SHIFT_CANCELLED );

$gwc_vt_sf_mixed = gwc_vt_fold_cancelled_repeats( gwc_vt_sf_rows( array( $gwc_vt_sf_a, $gwc_vt_sf_b ) ) );

gwc_vt_sf_check(
	'adjacent cancellations from different repeats do not fold together',
	2 === count( $gwc_vt_sf_mixed ),
	(string) count( $gwc_vt_sf_mixed )
);

/* A shift with no series at all — every shift made by hand — folds with
 * nothing, however many cancelled ones sit beside it. */
$gwc_vt_sf_loose = gwc_vt_sf_make_shift( '2031-09-06', 0, GWC_VT_SHIFT_CANCELLED );
delete_post_meta( $gwc_vt_sf_loose, GWC_VT_SHIFT_SERIES );

$gwc_vt_sf_lonely = gwc_vt_sf_make_shift( '2031-09-07', 0, GWC_VT_SHIFT_CANCELLED );
delete_post_meta( $gwc_vt_sf_lonely, GWC_VT_SHIFT_SERIES );

$gwc_vt_sf_none = gwc_vt_fold_cancelled_repeats( gwc_vt_sf_rows( array( $gwc_vt_sf_loose, $gwc_vt_sf_lonely ) ) );

gwc_vt_sf_check(
	'hand-made cancelled shifts never fold',
	2 === count( $gwc_vt_sf_none ),
	(string) count( $gwc_vt_sf_none )
);

/* ── What the row says about its repeat ──────────────────────────────────── */

$gwc_vt_sf_note = gwc_vt_shift_repeat_note( $gwc_vt_sf_ids[0] );

gwc_vt_sf_check(
	'a row from a weekly repeat says so',
	false !== strpos( $gwc_vt_sf_note, 'weekly' ),
	$gwc_vt_sf_note
);

gwc_vt_sf_check(
	'and names the last date in the series, not the last on the screen',
	'' !== $gwc_vt_sf_note
		&& false !== strpos( $gwc_vt_sf_note, gwc_vt_shift_date_label_from( (string) end( $gwc_vt_sf_run['dates'] ) ) ),
	$gwc_vt_sf_note
);

gwc_vt_sf_check(
	'a shift with no series says nothing',
	'' === gwc_vt_shift_repeat_note( $gwc_vt_sf_loose ),
	gwc_vt_shift_repeat_note( $gwc_vt_sf_loose )
);

/* Cancelled occurrences count towards the pattern: a fortnight of called-off
 * Saturdays must not make a weekly series read as a monthly one. */
gwc_vt_sf_check(
	'cancelled occurrences still count as dates in the series',
	12 === count( gwc_vt_shift_series_dates( $gwc_vt_sf_series ) ),
	(string) count( gwc_vt_shift_series_dates( $gwc_vt_sf_series ) )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code.
 * Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

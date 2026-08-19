<?php
/**
 * Turning "every Saturday until December" into a list of dates.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** No single run may create more shifts than this. */
const GWC_VT_RECURRENCE_MAX = 200;

/** Nor reach further ahead than this, in months. */
const GWC_VT_RECURRENCE_HORIZON_MONTHS = 12;

/* ── Occurrences are materialised, not computed ──────────────────────────────
 * "Every Saturday through 31 December" creates twenty-odd real shift posts up
 * front, each independently editable, cancellable and deletable.
 *
 * The alternative — store the rule, compute the occurrences on read — is more
 * compact and is a trap. The moment a coordinator closes the Saturday after
 * Thanksgiving, or moves one shift an hour later for the summer, the rule needs
 * an exception list; and once there is an exception list, every query has to
 * reconcile the rule against it, a signup has to attach to something that does
 * not exist as a row, and "which shifts is Jane on" stops being a query. Real
 * rows are the boring answer and the boring answer is right here.
 *
 * The cost is a cap, because a coordinator who types "every day until 2099" must
 * not create thirty thousand posts. The cap is reported rather than silently
 * applied — see the 'capped' key — because a screen that quietly makes fewer
 * shifts than you asked for is a screen that loses you a month of Saturdays.
 *
 * ── Why this file touches no WordPress ───────────────────────────────────────
 * Calendar arithmetic, and nothing else, so it is unit-testable without a
 * database and so the DST question below can be reasoned about in one place.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The patterns a coordinator can pick, keyed by the value stored on the form.
 *
 * A function with a static memo rather than a const, because the labels are
 * translated and a const is evaluated before the request's translation is
 * loaded — invisible on an English site and total on every other one.
 *
 * @return array<string, string>
 */
function gwc_vt_recurrence_patterns(): array {
	static $patterns = null;

	if ( null === $patterns ) {
		$patterns = array(
			'once'        => __( 'Just this once', 'groundwork-common-volunteer-tracker' ),
			'daily'       => __( 'Every day', 'groundwork-common-volunteer-tracker' ),
			'weekdays'    => __( 'Every weekday', 'groundwork-common-volunteer-tracker' ),
			'weekly'      => __( 'Every week', 'groundwork-common-volunteer-tracker' ),
			'fortnightly' => __( 'Every other week', 'groundwork-common-volunteer-tracker' ),
			'monthly'     => __( 'Every month, on the same weekday', 'groundwork-common-volunteer-tracker' ),
		);
	}

	return $patterns;
}

/**
 * Every date a recurrence lands on.
 *
 * ── Dates, deliberately, and never timestamps ────────────────────────────────
 * The arithmetic here is calendar arithmetic: add seven days to 14 March and get
 * 21 March. It never touches a time of day and never converts to an instant, so
 * a daylight-saving boundary between two occurrences cannot move one of them.
 *
 * That is the whole reason a shift stores its start as local wall time — '09:00'
 * — rather than as a timestamp. Add 7 * 86400 seconds to "9am on the Saturday
 * before the clocks change" and you get 8am or 10am on the Saturday after. Add
 * one week to a date and keep the string '09:00', and the nine o'clock shift is
 * at nine o'clock all year, which is what everybody involved already assumed.
 *
 * The UTC zone below is not a claim about where the organisation is. It is the
 * absence of a claim: these are calendar dates, and pinning the arithmetic to a
 * fixed zone stops PHP's default timezone from making it drift.
 *
 * @param string $start   First occurrence, Y-m-d.
 * @param string $pattern One of gwc_vt_recurrence_patterns().
 * @param string $until   Last date to consider, Y-m-d. Ignored for 'once'.
 * @return array{dates: string[], capped: string} 'capped' is '', 'count' or 'horizon'.
 */
function gwc_vt_recurrence_dates( string $start, string $pattern, string $until ): array {
	$none = array(
		'dates'  => array(),
		'capped' => '',
	);

	$from = gwc_vt_recurrence_date( $start );

	if ( null === $from ) {
		return $none;
	}

	if ( ! isset( gwc_vt_recurrence_patterns()[ $pattern ] ) ) {
		return $none;
	}

	if ( 'once' === $pattern ) {
		return array(
			'dates'  => array( $from->format( 'Y-m-d' ) ),
			'capped' => '',
		);
	}

	$to = gwc_vt_recurrence_date( $until );

	/* No end date is not "forever" — it is a question nobody answered, and the
	 * honest answer is one shift rather than two hundred. */
	if ( null === $to || $to < $from ) {
		return array(
			'dates'  => array( $from->format( 'Y-m-d' ) ),
			'capped' => '',
		);
	}

	$horizon = $from->modify( '+' . GWC_VT_RECURRENCE_HORIZON_MONTHS . ' months' );
	$capped  = '';

	if ( $to > $horizon ) {
		$to     = $horizon;
		$capped = 'horizon';
	}

	$dates = array();
	$step  = 0;

	/* A step ceiling as well as an occurrence cap, because 'monthly' can return
	 * nothing for a step — a month with no fifth Saturday — and a loop bounded
	 * only by how many dates it has collected would spin on a pattern that stops
	 * producing them. Twice the cap is far more headroom than any real pattern
	 * needs and is still a number rather than a hope. */
	$ceiling = GWC_VT_RECURRENCE_MAX * 2;

	// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- $dates grows inside the loop; its count is the cap being enforced, so hoisting it would make the guard never fire.
	while ( count( $dates ) < GWC_VT_RECURRENCE_MAX && $step < $ceiling ) {
		$date = gwc_vt_recurrence_step( $from, $pattern, $step );
		++$step;

		if ( null === $date ) {
			/* A month with no fifth Saturday. Skipped rather than nudged to the
			 * fourth or to the first of the next month — a coordinator who said
			 * "the fifth Saturday" and silently got a different one would not find
			 * out until somebody turned up to a locked door. */
			continue;
		}

		if ( $date > $to ) {
			break;
		}

		$dates[] = $date->format( 'Y-m-d' );
	}

	if ( count( $dates ) >= GWC_VT_RECURRENCE_MAX ) {
		/* Only report the count cap if it actually bit — a run that lands on
		 * exactly two hundred occurrences and then hits its end date has not been
		 * truncated, and saying so would send somebody looking for lost shifts. */
		$next = gwc_vt_recurrence_step( $from, $pattern, $step );

		if ( null !== $next && $next <= $to ) {
			$capped = 'count';
		}
	}

	return array(
		'dates'  => $dates,
		'capped' => $capped,
	);
}

/**
 * The nth occurrence after the first, or null when that step has no date.
 *
 * @param DateTimeImmutable $from    The first occurrence.
 * @param string            $pattern Pattern key.
 * @param int               $step    0 is the first occurrence itself.
 * @return DateTimeImmutable|null
 */
function gwc_vt_recurrence_step( DateTimeImmutable $from, string $pattern, int $step ): ?DateTimeImmutable {
	if ( 0 === $step ) {
		return $from;
	}

	switch ( $pattern ) {
		case 'daily':
			return $from->modify( '+' . $step . ' days' );

		case 'weekly':
			return $from->modify( '+' . $step . ' weeks' );

		case 'fortnightly':
			return $from->modify( '+' . ( $step * 2 ) . ' weeks' );

		case 'weekdays':
			return gwc_vt_recurrence_weekday_step( $from, $step );

		case 'monthly':
			return gwc_vt_recurrence_monthly_step( $from, $step );
	}

	return null;
}

/**
 * The nth weekday after the start, skipping weekends.
 *
 * A start date that is itself a weekend is kept rather than moved: it is the
 * date the coordinator typed, and shifting somebody's first shift to a day they
 * did not choose is a worse surprise than one Saturday in a weekday series. The
 * shift screen says so when the two disagree.
 *
 * @param DateTimeImmutable $from The first occurrence.
 * @param int               $step How many weekdays on.
 * @return DateTimeImmutable
 */
function gwc_vt_recurrence_weekday_step( DateTimeImmutable $from, int $step ): DateTimeImmutable {
	$date = $from;

	for ( $i = 0; $i < $step; $i++ ) {
		$date = $date->modify( '+1 day' );

		while ( (int) $date->format( 'N' ) > 5 ) {
			$date = $date->modify( '+1 day' );
		}
	}

	return $date;
}

/**
 * The same ordinal weekday, n months on. "The second Saturday" rather than "the
 * fourteenth", because a monthly volunteer shift is a weekday to everybody who
 * turns up to it.
 *
 * @param DateTimeImmutable $from  The first occurrence.
 * @param int               $step  How many months on.
 * @return DateTimeImmutable|null Null when that month has no such weekday.
 */
function gwc_vt_recurrence_monthly_step( DateTimeImmutable $from, int $step ): ?DateTimeImmutable {
	$weekday = (int) $from->format( 'N' );
	$ordinal = (int) floor( ( (int) $from->format( 'j' ) - 1 ) / 7 ) + 1;

	/* Anchored to the first of the month before adding, so that starting from the
	 * 31st does not have PHP roll a short month over into the next one. */
	$month = $from->modify( 'first day of this month' )->modify( '+' . $step . ' months' );

	$date = $month->modify( '+' . ( $ordinal - 1 ) . ' weeks' );

	$offset = ( $weekday - (int) $date->format( 'N' ) + 7 ) % 7;
	$date   = $date->modify( '+' . $offset . ' days' );

	if ( $date->format( 'Y-m' ) !== $month->format( 'Y-m' ) ) {
		return null;
	}

	return $date;
}

/**
 * Read a Y-m-d into a date, or null.
 *
 * Stricter than DateTimeImmutable's own parsing, which happily reads '2026-02-31'
 * as 3 March. The same check gwc_vt_sanitize_date() makes, and made separately
 * here so this file stays free of the rest of the plugin.
 *
 * @param string $value Y-m-d.
 * @return DateTimeImmutable|null
 */
function gwc_vt_recurrence_date( string $value ): ?DateTimeImmutable {
	$value = trim( $value );

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
		return null;
	}

	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}

	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );

	return false === $date ? null : $date;
}

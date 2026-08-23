<?php
/**
 * How many hours somebody still has to do.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Verified hours count, and only those ────────────────────────────────────
 * A requirement is met by hours a member of staff has attested to, because
 * those are the only ones the organization is prepared to stand behind — they
 * are also the only ones a letter states a total for. Counting unverified hours
 * toward it would tell a volunteer they were finished on the strength of a row
 * nobody had looked at.
 *
 * Hours waiting to be verified are reported alongside rather than folded in, so
 * a coordinator can see that somebody is four hours short and has six sitting
 * unverified — which is a different problem, with a different fix, and one they
 * can do something about in ten seconds.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * What a volunteer has to complete, in minutes. Zero means nothing recorded.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return int
 */
function gwc_vt_required_minutes( int $volunteer_id ): int {
	return max( 0, (int) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED, true ) );
}

/**
 * Does this volunteer have a requirement recorded at all?
 *
 * Most do not. Everything this file draws is hidden when this is false, so an
 * organization that never hosts mandated service never sees any of it.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwc_vt_has_requirement( int $volunteer_id ): bool {
	return gwc_vt_required_minutes( $volunteer_id ) > 0;
}

/**
 * Where somebody has got to.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return array{
 *     required:int, verified:int, pending:int, remaining:int, percent:int,
 *     met:bool, deadline:string, days_left:?int, overdue:bool
 * }
 */
function gwc_vt_requirement_progress( int $volunteer_id ): array {
	$required = gwc_vt_required_minutes( $volunteer_id );
	$totals   = gwc_vt_volunteer_totals( $volunteer_id );

	$verified  = (int) $totals->verified_minutes;
	$remaining = max( 0, $required - $verified );

	$deadline  = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_BY, true );
	$days_left = null;
	$met       = $required > 0 && $verified >= $required;

	if ( '' !== $deadline ) {
		$days_left = gwc_vt_days_until( $deadline );
	}

	return array(
		'required'  => $required,
		'verified'  => $verified,
		'pending'   => (int) $totals->pending_minutes,
		'remaining' => $remaining,

		/* Capped at 100. Somebody who does more than they had to has done more
		 * than they had to; a bar reading 140% reads as a bug. */
		'percent'   => $required > 0 ? min( 100, (int) floor( ( $verified / $required ) * 100 ) ) : 0,
		'met'       => $met,
		'deadline'  => $deadline,
		'days_left' => $days_left,

		/* Overdue is a fact, not a prediction. The date has passed and the hours
		 * are not there. This plugin does not guess whether somebody is "on
		 * track" — that would need a rate it has no business inventing, and
		 * being told you are behind by software that guessed is worse than not
		 * being told. */
		'overdue'   => ! $met && null !== $days_left && $days_left < 0,
	);
}

/**
 * Whole days from today until a date, negative once it has passed.
 *
 * @param string $date Y-m-d.
 * @return int|null Null when the date cannot be read.
 */
function gwc_vt_days_until( string $date ): ?int {
	$then = gwc_vt_recurrence_date( $date );
	$now  = gwc_vt_recurrence_date( gwc_vt_today() );

	if ( null === $then || null === $now ) {
		return null;
	}

	return (int) $now->diff( $then )->format( '%r%a' );
}

/**
 * Where somebody has got to, as a sentence.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string Empty when nothing is required of them.
 */
function gwc_vt_requirement_label( int $volunteer_id ): string {
	if ( ! gwc_vt_has_requirement( $volunteer_id ) ) {
		return '';
	}

	$progress = gwc_vt_requirement_progress( $volunteer_id );

	if ( $progress['met'] ) {
		return sprintf(
			/* translators: %s: a number of hours, already formatted. */
			__( 'Completed — %s verified', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_format_hours( $progress['verified'] )
		);
	}

	return sprintf(
		/* translators: 1: hours verified so far, 2: hours required. Both already formatted, so the sentence must not add a unit of its own. */
		__( '%1$s of %2$s', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_format_hours( $progress['verified'] ),
		gwc_vt_format_hours( $progress['required'] )
	);
}

/**
 * The deadline, as a sentence, or ''.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string
 */
function gwc_vt_requirement_deadline_label( int $volunteer_id ): string {
	$progress = gwc_vt_requirement_progress( $volunteer_id );

	if ( '' === $progress['deadline'] || null === $progress['days_left'] ) {
		return '';
	}

	if ( $progress['met'] ) {
		return '';
	}

	if ( $progress['overdue'] ) {
		return sprintf(
			/* translators: %d: how many days ago the deadline was. */
			_n( 'Due %d day ago', 'Due %d days ago', abs( $progress['days_left'] ), 'groundwork-common-volunteer-tracker' ),
			abs( $progress['days_left'] )
		);
	}

	if ( 0 === $progress['days_left'] ) {
		return __( 'Due today', 'groundwork-common-volunteer-tracker' );
	}

	return sprintf(
		/* translators: %d: how many days are left. */
		_n( '%d day left', '%d days left', $progress['days_left'], 'groundwork-common-volunteer-tracker' ),
		$progress['days_left']
	);
}

/**
 * Read a typed requirement into minutes.
 *
 * The same parser the hours fields use, so "40", "40h" and "2400m" all work and
 * a requirement is typed the way somebody says it out loud.
 *
 * One difference: a requirement is not a shift, so it is not capped at a day.
 * Court-ordered service routinely runs to hundreds of hours.
 *
 * @param string $raw What was typed.
 * @return int Minutes; zero for anything unreadable or empty.
 */
function gwc_vt_parse_required( string $raw ): int {
	$value = trim( $raw );

	if ( '' === $value ) {
		return 0;
	}

	/* gwc_vt_parse_hours() refuses anything over a day, which is right for a
	 * shift and wrong here. Hours are the only unit anybody states a
	 * requirement in, so this reads a plain number of them and nothing else —
	 * "80h 30m" is not how a court order is written. */
	if ( ! preg_match( '/^(\d+(?:\.\d+)?)\s*(?:h(?:(?:ou)?rs?)?)?$/i', $value, $m ) ) {
		return 0;
	}

	$minutes = (int) round( ( (float) $m[1] ) * 60 );

	/* A ceiling, because a typo in this field is silent. Ten thousand hours is
	 * five years of full-time work; nothing a nonprofit records as community
	 * service comes near it, and a stray zero is far more likely. */
	if ( $minutes < 1 || $minutes > 600000 ) {
		return 0;
	}

	return $minutes;
}

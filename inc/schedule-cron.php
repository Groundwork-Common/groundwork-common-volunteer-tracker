<?php
/**
 * The two scheduled passes: reminders, and the coordinator's daily summary.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_REMINDER_EVENT = 'gwcvt_shift_reminders';
const GWCVT_DIGEST_EVENT   = 'gwcvt_coordinator_digest';

/** No single reminder pass may send more than this. */
const GWCVT_REMINDER_BATCH = 200;

add_action( 'init', 'gwcvt_schedule_shift_events' );
add_action( GWCVT_REMINDER_EVENT, 'gwcvt_run_reminders' );
add_action( GWCVT_DIGEST_EVENT, 'gwcvt_run_digest' );

/* ── Scheduled only while the feature is on ──────────────────────────────────
 * The retention sweep next door is scheduled unconditionally and no-ops when the
 * period is zero, which is right for it: retention applies to every install and
 * the question is only how long.
 *
 * These two are different. A site that never turns scheduling on has no shifts,
 * no signups and nothing for either pass to do — and an hourly event pointing at
 * a function that always returns immediately is still a permanent row in every
 * cron listing that site's owner ever looks at, plus an hourly wake-up on a host
 * that charges for them. So they are scheduled when shifts are switched on and
 * unscheduled when they are switched off.
 *
 * The disabling half matters as much as the enabling one. Without it, turning
 * the feature off would leave the events behind forever.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Keep the two events in step with the setting.
 */
function gwcvt_schedule_shift_events(): void {
	$wanted = (bool) gwcvt_setting( 'shifts_enabled' );

	foreach ( array( GWCVT_REMINDER_EVENT => 'hourly', GWCVT_DIGEST_EVENT => 'daily' ) as $event => $recurrence ) {
		$next = wp_next_scheduled( $event );

		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $event );
			continue;
		}

		if ( ! $wanted && $next ) {
			wp_unschedule_event( $next, $event );
		}
	}
}

/* ── Reminders ───────────────────────────────────────────────────────────────
 * Hourly rather than daily, because the lead time is in hours and a daily pass
 * would send a "two days before" reminder anywhere from 24 to 48 hours out
 * depending on what time the site's cron happens to fire.
 *
 * The pass is idempotent, and that is what makes it safe on a schedule nobody
 * is watching: a signup carries the time its reminder went, absence means it has
 * not, and the query only looks at signups with no such timestamp. A cron run
 * that is skipped — a quiet site with no visitors to trigger wp-cron — sends
 * late on the next one rather than never, and a run that fires twice sends
 * nothing the second time.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Send the reminders that are due.
 *
 * @return int How many went out.
 */
function gwcvt_run_reminders(): int {
	if ( ! gwcvt_setting( 'shifts_enabled' ) || ! gwcvt_setting( 'reminder_enabled' ) ) {
		return 0;
	}

	$lead = max( 1, (int) gwcvt_setting( 'reminder_lead_hours' ) ) * HOUR_IN_SECONDS;
	$now  = time();
	$sent = 0;

	/* A window in dates to get the candidate shifts out of the database, then an
	 * exact comparison on instants to decide. The date range is deliberately
	 * wider than the lead time by a day at each end — a shift's date is a
	 * calendar day and its start is a wall clock, so the day a shift falls on
	 * says only roughly when it begins. */
	$shifts = gwcvt_shifts_between(
		array(
			'from'  => gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ),
			'to'    => gmdate( 'Y-m-d', $now + $lead + DAY_IN_SECONDS ),
			'limit' => 100,
		)
	);

	foreach ( $shifts as $shift_id ) {
		if ( $sent >= GWCVT_REMINDER_BATCH ) {
			break;
		}

		$starts = gwcvt_shift_starts( $shift_id );

		if ( null === $starts ) {
			continue;
		}

		$begins = $starts->getTimestamp();

		/* Not yet inside the window, or already begun. A reminder about a shift
		 * that started an hour ago is worse than none: it tells somebody who
		 * forgot that they have already let people down. */
		if ( $begins > ( $now + $lead ) || $begins <= $now ) {
			continue;
		}

		/* The roster only. Somebody on the waiting list has no place, and telling
		 * them to turn up on Saturday would be telling them something untrue. */
		foreach ( gwcvt_shift_signup_ids( $shift_id ) as $signup_id ) {
			if ( $sent >= GWCVT_REMINDER_BATCH ) {
				break;
			}

			if ( '' !== (string) get_post_meta( $signup_id, GWCVT_SIGNUP_REMINDED, true ) ) {
				continue;
			}

			/* Stamped before the send, not after. wp_mail() returning false does
			 * not mean nothing was delivered — a message can be accepted by the
			 * MTA and still report an error here — and the failure worth avoiding
			 * is an hourly pass that reminds the same person every hour forever
			 * because delivery keeps reporting failure. */
			update_post_meta( $signup_id, GWCVT_SIGNUP_REMINDED, current_time( 'mysql', true ) );

			gwcvt_send_shift_reminder( (int) $signup_id );

			++$sent;
		}
	}

	return $sent;
}

/* ── The coordinator's daily summary ─────────────────────────────────────────
 * One message a day, and only on a day when there is something in it. A digest
 * that arrives every morning saying "nothing to report" is a digest that gets a
 * filter rule inside a fortnight, and then the one that says "Saturday has two
 * of six" gets filtered along with it.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Send the daily summary, if there is anything to say.
 *
 * @return bool Whether a message went out.
 */
function gwcvt_run_digest(): bool {
	if ( ! gwcvt_setting( 'shifts_enabled' ) || ! gwcvt_setting( 'digest_enabled' ) ) {
		return false;
	}

	$short   = gwcvt_understaffed_shift_ids( 7 );
	$waiting = gwcvt_unreconciled_shift_ids( 20 );

	if ( ! $short && ! $waiting ) {
		return false;
	}

	$to = trim( (string) gwcvt_setting( 'digest_recipient' ) );

	if ( '' === $to ) {
		$to = (string) get_option( 'admin_email' );
	}

	if ( ! is_email( $to ) ) {
		return false;
	}

	return gwcvt_send_email(
		$to,
		sprintf(
			/* translators: %s: the organisation's name. */
			__( '%s: volunteer shifts needing attention', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name()
		),
		gwcvt_digest_body( $short, $waiting )
	);
}

/**
 * The summary itself.
 *
 * @param int[] $short   Shifts short of people.
 * @param int[] $waiting Shifts whose hours have not been logged.
 * @return string
 */
function gwcvt_digest_body( array $short, array $waiting ): string {
	$lines = array();

	if ( $short ) {
		$lines[] = sprintf( '<h2>%s</h2>', esc_html__( 'Short of people this week', 'groundwork-common-volunteer-tracker' ) );
		$lines[] = '<ul>';

		foreach ( $short as $shift_id ) {
			$lines[] = sprintf(
				'<li><a href="%1$s">%2$s</a> — %3$s</li>',
				esc_url( gwcvt_schedule_url( array( 'shift' => $shift_id ) ) ),
				esc_html( gwcvt_shift_one_line( (int) $shift_id ) ),
				esc_html( gwcvt_shift_fill_label( (int) $shift_id ) )
			);
		}

		$lines[] = '</ul>';
	}

	if ( $waiting ) {
		$lines[] = sprintf( '<h2>%s</h2>', esc_html__( 'Happened, hours not logged', 'groundwork-common-volunteer-tracker' ) );

		/* Said rather than assumed. An unreconciled shift is not untidiness — it
		 * is hours nobody will remember in a fortnight, and those hours are what
		 * a verification letter is made of. */
		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'Until these are logged they are not on anybody\'s record, and they cannot appear on a verification letter.', 'groundwork-common-volunteer-tracker' )
		);

		$lines[] = '<ul>';

		foreach ( $waiting as $shift_id ) {
			$lines[] = sprintf(
				'<li><a href="%1$s">%2$s</a> — %3$s</li>',
				esc_url( gwcvt_shift_log_url( (int) $shift_id ) ),
				esc_html( gwcvt_shift_one_line( (int) $shift_id ) ),
				esc_html( gwcvt_shift_fill_label( (int) $shift_id ) )
			);
		}

		$lines[] = '</ul>';
	}

	$lines[] = sprintf(
		'<p><small>%s</small></p>',
		esc_html__( 'You are getting this because the daily summary is switched on under Volunteer Hours → Settings → Shifts. Nothing is sent on a day with nothing to report.', 'groundwork-common-volunteer-tracker' )
	);

	return implode( "\n", $lines );
}

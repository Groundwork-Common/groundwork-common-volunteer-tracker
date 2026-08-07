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

	foreach ( array(
		GWCVT_REMINDER_EVENT => 'hourly',
		GWCVT_DIGEST_EVENT   => 'daily',
	) as $event => $recurrence ) {
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
	$due  = array();

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
			if ( '' !== (string) get_post_meta( $signup_id, GWCVT_SIGNUP_REMINDED, true ) ) {
				continue;
			}

			$due[] = (int) $signup_id;
		}
	}

	foreach ( gwcvt_group_due_reminders( $due ) as $group ) {
		if ( $sent >= GWCVT_REMINDER_BATCH ) {
			break;
		}

		/* Stamped before the send, not after, and stamped on EVERY slot this
		 * message is about to name. wp_mail() returning false does not mean
		 * nothing was delivered — a message can be accepted by the MTA and still
		 * report an error here — and the failure worth avoiding is an hourly pass
		 * that reminds the same person every hour forever. */
		foreach ( $group['signups'] as $signup_id ) {
			update_post_meta( $signup_id, GWCVT_SIGNUP_REMINDED, current_time( 'mysql', true ) );
		}

		if ( $group['event'] > 0 && count( $group['signups'] ) > 1 ) {
			gwcvt_send_event_reminder( $group['event'], $group['signups'] );
		} else {
			gwcvt_send_shift_reminder( (int) $group['signups'][0] );
		}

		++$sent;
	}

	return $sent;
}

/* ── What keeps the pass idempotent once it groups ───────────────────────────
 * Not one flag per email — that framing is what made grouping look impossible.
 * The invariant that matters is:
 *
 *   A SLOT'S FLAG IS SET IF AND ONLY IF THAT SLOT WAS NAMED IN A MESSAGE THAT
 *   WAS SENT.
 *
 * Mark every slot you are about to mention, before sending, then send once.
 *
 * Which settles multi-day events: only slots already inside the reminder window
 * are gathered above, so only those are named and only those are marked. A
 * festival's Sunday slots keep their own flags and get their own message when
 * they come due.
 *
 * Name a slot without marking it and it is reminded about twice. Mark one
 * without naming it and it is NEVER reminded about — and that failure is silent,
 * which is why tests/integration/events.php asserts it directly.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Gather due signups into the messages that will carry them.
 *
 * One group per person per event; standalone shifts stay one group each.
 *
 * @param int[] $signup_ids Signups owed a reminder.
 * @return array<int, array{event: int, signups: int[]}>
 */
function gwcvt_group_due_reminders( array $signup_ids ): array {
	$groups = array();

	foreach ( $signup_ids as $signup_id ) {
		$signup_id = (int) $signup_id;
		$shift_id  = (int) get_post_field( 'post_parent', $signup_id );
		$event_id  = gwcvt_event_for_shift( $shift_id );

		/* A standalone shift is its own group, keyed by the signup, so nothing
		 * about the existing single-shift path changes. */
		if ( $event_id < 1 ) {
			$groups[ 's' . $signup_id ] = array(
				'event'   => 0,
				'signups' => array( $signup_id ),
			);
			continue;
		}

		$person = gwcvt_signup_person_key( $signup_id );

		/* No handle to group by — no volunteer record and no address on file. It
		 * gets its own message rather than being lumped in with every other
		 * anonymous row, which would mail one person about somebody else's slots. */
		$key = '' !== $person ? 'e' . $event_id . '|' . $person : 'x' . $signup_id;

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'event'   => $event_id,
				'signups' => array(),
			);
		}

		$groups[ $key ]['signups'][] = $signup_id;
	}

	foreach ( $groups as $key => $group ) {
		usort(
			$groups[ $key ]['signups'],
			static function ( int $a, int $b ) {
				return gwcvt_compare_slots(
					(int) get_post_field( 'post_parent', $a ),
					(int) get_post_field( 'post_parent', $b )
				);
			}
		);
	}

	return array_values( $groups );
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

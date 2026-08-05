<?php
/**
 * What a volunteer is told, and when.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'shutdown', 'gwcvt_send_queued_confirmations' );

/* ── The first mail this plugin sends by itself ──────────────────────────────
 * Until now every message left this site because a member of staff pressed a
 * button. This one is sent by a stranger filling in a form, which is a real
 * change in what the plugin does when it is installed — hence the two switches
 * and the pinned page in front of it.
 *
 * It is not optional, though, and that is the argument for building it into
 * signup rather than offering it as a setting. A signup with no confirmation
 * leaves a person with no record of what they agreed to, no note of where to go,
 * and no way to back out except telephoning during office hours. That is not a
 * lighter-weight version of the feature; it is a broken one.
 *
 * Everything goes through gwcvt_send_email(), so the GWCVT_MAIL_MODE staging
 * guard covers it for free — which matters more here than anywhere else in the
 * plugin, because this is the message a restored database copy would send to
 * real volunteers about shifts that are not happening.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Note that a message is owed, to be sent once the page has been answered.
 *
 * Queued rather than sent inline, for two different reasons that land on the
 * same answer.
 *
 * On the public form: the handler measures its own response time to a floor of a
 * quarter second, and wp_mail() on a site using SMTP can take several. A signup
 * that visibly takes four seconds while a honeypot hit takes a quarter of one has
 * told the person on the other end which of the two they triggered, and the whole
 * point of the identical responses is that it does not.
 *
 * On the admin screens: cancelling a shift with thirty people on it is thirty
 * messages, and a coordinator watching a Cancel button spin for half a minute
 * presses it again.
 *
 * Sending after output fixes both. It is not a queue that survives a fatal —
 * for that this would need a real job store — and the trade is deliberate: a
 * missed reminder is recoverable because the pass is idempotent, and a missed
 * confirmation is visible to the person who did not get one.
 *
 * @param string $kind      'confirmation', 'reminder', 'cancelled' or 'changed'.
 * @param int    $signup_id Signup post ID.
 * @param array  $context   Anything the message needs beyond the signup.
 */
function gwcvt_queue_signup_mail( string $kind, int $signup_id, array $context = array() ): void {
	if ( ! isset( $GLOBALS['gwcvt_pending_mail'] ) ) {
		$GLOBALS['gwcvt_pending_mail'] = array();
	}

	$GLOBALS['gwcvt_pending_mail'][] = array(
		'kind'    => $kind,
		'signup'  => $signup_id,
		'context' => $context,
	);
}

/**
 * Note that a confirmation is owed.
 *
 * @param int $signup_id Signup post ID.
 */
function gwcvt_queue_signup_confirmation( int $signup_id ): void {
	gwcvt_queue_signup_mail( 'confirmation', $signup_id );
}

/**
 * Send whatever was queued during this request.
 */
function gwcvt_send_queued_confirmations(): void {
	$queued = (array) ( $GLOBALS['gwcvt_pending_mail'] ?? array() );

	$GLOBALS['gwcvt_pending_mail'] = array();

	foreach ( $queued as $item ) {
		$signup_id = (int) ( $item['signup'] ?? 0 );
		$context   = (array) ( $item['context'] ?? array() );

		switch ( (string) ( $item['kind'] ?? '' ) ) {
			case 'confirmation':
				gwcvt_send_signup_confirmation( $signup_id );
				break;

			case 'reminder':
				gwcvt_send_shift_reminder( $signup_id );
				break;

			case 'cancelled':
				gwcvt_send_shift_cancelled_notice( $signup_id, (string) ( $context['reason'] ?? '' ) );
				break;

			case 'changed':
				gwcvt_send_shift_changed_notice( $signup_id, (array) ( $context['was'] ?? array() ) );
				break;
		}
	}
}

/**
 * Tell somebody they are signed up, where to go, and how to get out of it.
 *
 * @param int $signup_id Signup post ID.
 * @return bool
 */
function gwcvt_send_signup_confirmation( int $signup_id ): bool {
	$email = gwcvt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$waiting = GWCVT_SIGNUP_WAITLIST === get_post_status( $signup_id );

	$subject = $waiting
		? sprintf(
			/* translators: 1: the organisation's name, 2: a date. */
			__( '%1$s: you are on the waiting list for %2$s', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name(),
			gwcvt_shift_date_label( $shift_id )
		)
		: sprintf(
			/* translators: 1: the organisation's name, 2: a date. */
			__( '%1$s: you are signed up for %2$s', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name(),
			gwcvt_shift_date_label( $shift_id )
		);

	return gwcvt_send_email( $email, $subject, gwcvt_signup_confirmation_body( $signup_id, $shift_id, $waiting ) );
}

/**
 * The confirmation itself.
 *
 * Deliberately plain. This is not a document the way a verification letter is —
 * it carries no claim about anybody's hours and nothing a court will read — so
 * it says what, where, when, and here is how to cancel, and stops.
 *
 * @param int  $signup_id Signup post ID.
 * @param int  $shift_id  Shift post ID.
 * @param bool $waiting   Whether they are on the waiting list rather than the roster.
 * @return string
 */
function gwcvt_signup_confirmation_body( int $signup_id, int $shift_id, bool $waiting ): string {
	$lines = array();

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html(
			$waiting
				? sprintf(
					/* translators: %s: the organisation's name. */
					__( 'Thank you for offering to help %s. That shift is full at the moment, so you are on the waiting list — we will be in touch if a place comes free.', 'groundwork-common-volunteer-tracker' ),
					gwcvt_org_name()
				)
				: sprintf(
					/* translators: %s: the organisation's name. */
					__( 'Thank you for signing up to volunteer with %s. Here are the details.', 'groundwork-common-volunteer-tracker' ),
					gwcvt_org_name()
				)
		)
	);

	$lines[] = gwcvt_shift_details_table( $shift_id );

	$notes = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_NOTES, true ) );

	if ( '' !== $notes ) {
		$lines[] = sprintf( '<p>%s</p>', nl2br( esc_html( $notes ) ) );
	}

	/* Said plainly rather than left implied. Somebody who cannot make it and
	 * cannot find how to say so simply does not turn up, and a coordinator who
	 * knows the night before can call somebody else.
	 *
	 * Both the link and the sentence about it are conditional together: a site
	 * with no public page pinned has nowhere for this to go, and an instruction
	 * to use a link that is not there reads as a broken email. */
	$manage = gwcvt_signup_manage_url( $signup_id );

	if ( '' !== $manage ) {
		$lines[] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $manage ),
			esc_html__( 'Add it to your calendar, or cancel', 'groundwork-common-volunteer-tracker' )
		);

		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'If you cannot make it, please use that link to let us know — it helps more than you would think.', 'groundwork-common-volunteer-tracker' )
		);
	}

	/* Said once, in the first message they get, rather than on every one. What
	 * signing up does with their details is worth stating plainly; repeating it
	 * on every reminder is how a footer stops being read. */
	$lines[] = sprintf(
		'<p><small>%s</small></p>',
		esc_html(
			sprintf(
				/* translators: 1: the organisation's name, 2: its contact details. */
				__( 'Sent by %1$s (%2$s). Signing up records your name and email address so we know who is coming; it does not create an account.', 'groundwork-common-volunteer-tracker' ),
				gwcvt_org_name(),
				gwcvt_org_contact()
			)
		)
	);

	return implode( "\n", $lines );
}

/* ── The reminder ────────────────────────────────────────────────────────── */

/**
 * Remind somebody that they said they would come.
 *
 * @param int $signup_id Signup post ID.
 * @return bool
 */
function gwcvt_send_shift_reminder( int $signup_id ): bool {
	$email = gwcvt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: a date, 2: the organisation's name. */
					__( 'A reminder that you are down to volunteer on %1$s with %2$s.', 'groundwork-common-volunteer-tracker' ),
					gwcvt_shift_date_label( $shift_id ),
					gwcvt_org_name()
				)
			)
		),
		gwcvt_shift_details_table( $shift_id ),
	);

	$notes = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_NOTES, true ) );

	if ( '' !== $notes ) {
		$lines[] = sprintf( '<p>%s</p>', nl2br( esc_html( $notes ) ) );
	}

	/* The cancellation link goes in the reminder as well as the confirmation,
	 * and this is the one that gets used. Somebody who realises two days out
	 * that they cannot make it is looking at this message, not hunting for one
	 * they got three weeks ago. */
	$manage = gwcvt_signup_manage_url( $signup_id );

	if ( '' !== $manage ) {
		$lines[] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $manage ),
			esc_html__( 'Add it to your calendar, or cancel', 'groundwork-common-volunteer-tracker' )
		);

		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'If you cannot make it, please use that link to let us know — there is still time for us to ask somebody else.', 'groundwork-common-volunteer-tracker' )
		);
	}

	$lines[] = gwcvt_email_footer();

	return gwcvt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organisation's name, 2: a date. */
			__( '%1$s: a reminder about %2$s', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name(),
			gwcvt_shift_date_label( $shift_id )
		),
		implode( "\n", $lines )
	);
}

/* ── When the plan changes ───────────────────────────────────────────────── */

/**
 * Tell somebody a shift was called off.
 *
 * @param int    $signup_id Signup post ID.
 * @param string $reason    Why, as the coordinator typed it.
 * @return bool
 */
function gwcvt_send_shift_cancelled_notice( int $signup_id, string $reason = '' ): bool {
	$email = gwcvt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: a date, 2: the organisation's name. */
					__( 'The shift on %1$s with %2$s has been cancelled. Please do not come.', 'groundwork-common-volunteer-tracker' ),
					gwcvt_shift_date_label( $shift_id ),
					gwcvt_org_name()
				)
			)
		),
	);

	if ( '' !== $reason ) {
		$lines[] = sprintf( '<p>%s</p>', esc_html( $reason ) );
	}

	$lines[] = gwcvt_shift_details_table( $shift_id );

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html__( 'We are sorry for the short notice, and we hope to see you another time. There is nothing you need to do.', 'groundwork-common-volunteer-tracker' )
	);

	$lines[] = gwcvt_email_footer();

	return gwcvt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organisation's name, 2: a date. */
			__( '%1$s: the shift on %2$s is cancelled', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name(),
			gwcvt_shift_date_label( $shift_id )
		),
		implode( "\n", $lines )
	);
}

/**
 * Tell somebody a shift moved.
 *
 * The old details are quoted alongside the new ones. A message that says only
 * "the details have changed" makes the reader go and find the original to work
 * out what — and the thing that changed is usually the thing that decides
 * whether they can still come.
 *
 * @param int   $signup_id Signup post ID.
 * @param array $was       What the shift looked like before.
 * @return bool
 */
function gwcvt_send_shift_changed_notice( int $signup_id, array $was = array() ): bool {
	$email = gwcvt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: the organisation's name. */
					__( 'A shift you signed up for with %s has changed. Here are the new details.', 'groundwork-common-volunteer-tracker' ),
					gwcvt_org_name()
				)
			)
		),
		gwcvt_shift_details_table( $shift_id ),
	);

	$before = trim( (string) ( $was['label'] ?? '' ) );

	if ( '' !== $before ) {
		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the shift's previous date, time and place. */
					__( 'It was previously: %s', 'groundwork-common-volunteer-tracker' ),
					$before
				)
			)
		);
	}

	$manage = gwcvt_signup_manage_url( $signup_id );

	if ( '' !== $manage ) {
		$lines[] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $manage ),
			esc_html__( 'Update your calendar, or cancel if this no longer suits', 'groundwork-common-volunteer-tracker' )
		);
	}

	$lines[] = gwcvt_email_footer();

	return gwcvt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organisation's name, 2: a date. */
			__( '%1$s: the shift on %2$s has changed', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name(),
			gwcvt_shift_date_label( $shift_id )
		),
		implode( "\n", $lines )
	);
}

/* ── Shared pieces ───────────────────────────────────────────────────────── */

/**
 * What, when, where and who to ask for, as a table.
 *
 * One copy, because four messages describing the same shift differently is four
 * chances for three of them to agree while the fourth drifts.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwcvt_shift_details_table( int $shift_id ): string {
	$rows = array(
		__( 'What', 'groundwork-common-volunteer-tracker' )  => (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ),
		__( 'When', 'groundwork-common-volunteer-tracker' )  => trim( gwcvt_shift_date_label( $shift_id ) . ', ' . gwcvt_shift_time_label( $shift_id ), ', ' ),
		__( 'Where', 'groundwork-common-volunteer-tracker' ) => (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ),
	);

	$supervisor = (string) get_post_meta( $shift_id, GWCVT_SHIFT_SUPERVISOR, true );

	if ( '' !== $supervisor ) {
		$rows[ __( 'Ask for', 'groundwork-common-volunteer-tracker' ) ] = $supervisor;
	}

	$out = '<table cellpadding="4" cellspacing="0" border="0">';

	foreach ( $rows as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}

		$out .= sprintf(
			'<tr><th align="left" valign="top">%1$s</th><td>%2$s</td></tr>',
			esc_html( (string) $label ),
			esc_html( (string) $value )
		);
	}

	return $out . '</table>';
}

/**
 * The line at the bottom of every message to a volunteer.
 *
 * @return string
 */
function gwcvt_email_footer(): string {
	return sprintf(
		'<p><small>%s</small></p>',
		esc_html(
			sprintf(
				/* translators: 1: the organisation's name, 2: its contact details. */
				__( 'Sent by %1$s (%2$s).', 'groundwork-common-volunteer-tracker' ),
				gwcvt_org_name(),
				gwcvt_org_contact()
			)
		)
	);
}

/**
 * A shift's date, time and place in one line, for quoting what it used to be.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwcvt_shift_one_line( int $shift_id ): string {
	$parts = array_filter(
		array(
			gwcvt_shift_date_label( $shift_id ),
			gwcvt_shift_time_label( $shift_id ),
			(string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ),
		),
		'strlen'
	);

	return implode( ', ', $parts );
}

/**
 * The page somebody manages one signup from.
 *
 * A capability URL: it authorises exactly one thing about exactly one signup,
 * and it is no more sensitive than the mailbox it arrives in. See the note on
 * the token in inc/signups.php.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwcvt_signup_manage_url( int $signup_id ): string {
	$page = (int) gwcvt_setting( 'schedule_page' );

	/* Empty rather than the home page, and every caller checks. A site can have
	 * shifts and reminders switched on without ever pinning a public page — the
	 * coordinator takes names on the phone — and in that configuration there is
	 * nowhere for this link to go. Sending "click here to cancel" pointing at the
	 * front page is worse than sending no link: the volunteer clicks it, finds
	 * nothing, and assumes they have cancelled. */
	if ( $page < 1 ) {
		return '';
	}

	return add_query_arg(
		array(
			'gwcvt_signup' => $signup_id,
			'gwcvt_k'      => gwcvt_signup_token( $signup_id ),
		),
		(string) get_permalink( $page )
	);
}

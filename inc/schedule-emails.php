<?php
/**
 * What a volunteer is told, and when.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'shutdown', 'gwc_vt_send_queued_confirmations' );

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
 * Everything goes through gwc_vt_send_email(), so the GWC_VT_MAIL_MODE staging
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
function gwc_vt_queue_signup_mail( string $kind, int $signup_id, array $context = array() ): void {
	if ( ! isset( $GLOBALS['gwc_vt_pending_mail'] ) ) {
		$GLOBALS['gwc_vt_pending_mail'] = array();
	}

	$GLOBALS['gwc_vt_pending_mail'][] = array(
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
function gwc_vt_queue_signup_confirmation( int $signup_id ): void {
	gwc_vt_queue_signup_mail( 'confirmation', $signup_id );
}

/**
 * Send whatever was queued during this request.
 */
function gwc_vt_send_queued_confirmations(): void {
	$queued = (array) ( $GLOBALS['gwc_vt_pending_mail'] ?? array() );

	$GLOBALS['gwc_vt_pending_mail'] = array();

	foreach ( $queued as $item ) {
		$signup_id = (int) ( $item['signup'] ?? 0 );
		$context   = (array) ( $item['context'] ?? array() );

		switch ( (string) ( $item['kind'] ?? '' ) ) {
			case 'confirmation':
				gwc_vt_send_signup_confirmation( $signup_id );
				break;

			case 'event-confirmation':
				gwc_vt_send_event_confirmation(
					(int) ( $context['event'] ?? 0 ),
					array_map( 'intval', (array) ( $context['signups'] ?? array() ) )
				);
				break;

			case 'reminder':
				gwc_vt_send_shift_reminder( $signup_id );
				break;

			case 'cancelled':
				gwc_vt_send_shift_cancelled_notice( $signup_id, (string) ( $context['reason'] ?? '' ) );
				break;

			case 'changed':
				gwc_vt_send_shift_changed_notice( $signup_id, (array) ( $context['was'] ?? array() ) );
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
function gwc_vt_send_signup_confirmation( int $signup_id ): bool {
	$email = gwc_vt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$waiting = GWC_VT_SIGNUP_WAITLIST === get_post_status( $signup_id );

	$subject = $waiting
		? sprintf(
			/* translators: 1: the organization's name, 2: a date. */
			__( '%1$s: you are on the waiting list for %2$s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_shift_date_label( $shift_id )
		)
		: sprintf(
			/* translators: 1: the organization's name, 2: a date. */
			__( '%1$s: you are signed up for %2$s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_shift_date_label( $shift_id )
		);

	return gwc_vt_send_email( $email, $subject, gwc_vt_signup_confirmation_body( $signup_id, $shift_id, $waiting ) );
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
function gwc_vt_signup_confirmation_body( int $signup_id, int $shift_id, bool $waiting ): string {
	$lines = array();

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html(
			$waiting
				? sprintf(
					/* translators: %s: the organization's name. */
					__( 'Thank you for offering to help %s. That shift is full at the moment, so you are on the waiting list — we will be in touch if a place comes free.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_org_name()
				)
				: sprintf(
					/* translators: %s: the organization's name. */
					__( 'Thank you for signing up to volunteer with %s. Here are the details.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_org_name()
				)
		)
	);

	$lines[] = gwc_vt_shift_details_table( $shift_id );

	$notes = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_NOTES, true ) );

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
	$manage = gwc_vt_signup_manage_url( $signup_id );

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
				/* translators: 1: the organization's name, 2: its contact details. */
				__( 'Sent by %1$s (%2$s). Signing up records your name and email address so we know who is coming; it does not create an account.', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_org_name(),
				gwc_vt_org_contact()
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
function gwc_vt_send_shift_reminder( int $signup_id ): bool {
	$email = gwc_vt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: a date, 2: the organization's name. */
					__( 'A reminder that you are down to volunteer on %1$s with %2$s.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_shift_date_label( $shift_id ),
					gwc_vt_org_name()
				)
			)
		),
		gwc_vt_shift_details_table( $shift_id ),
	);

	$notes = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_NOTES, true ) );

	if ( '' !== $notes ) {
		$lines[] = sprintf( '<p>%s</p>', nl2br( esc_html( $notes ) ) );
	}

	/* The cancellation link goes in the reminder as well as the confirmation,
	 * and this is the one that gets used. Somebody who realizes two days out
	 * that they cannot make it is looking at this message, not hunting for one
	 * they got three weeks ago. */
	$manage = gwc_vt_signup_manage_url( $signup_id );

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

	$lines[] = gwc_vt_email_footer();

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organization's name, 2: a date. */
			__( '%1$s: a reminder about %2$s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_shift_date_label( $shift_id )
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
function gwc_vt_send_shift_cancelled_notice( int $signup_id, string $reason = '' ): bool {
	$email = gwc_vt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: a date, 2: the organization's name. */
					__( 'The shift on %1$s with %2$s has been canceled. Please do not come.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_shift_date_label( $shift_id ),
					gwc_vt_org_name()
				)
			)
		),
	);

	if ( '' !== $reason ) {
		$lines[] = sprintf( '<p>%s</p>', esc_html( $reason ) );
	}

	$lines[] = gwc_vt_shift_details_table( $shift_id );

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html__( 'We are sorry for the short notice, and we hope to see you another time. There is nothing you need to do.', 'groundwork-common-volunteer-tracker' )
	);

	$lines[] = gwc_vt_email_footer();

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organization's name, 2: a date. */
			__( '%1$s: the shift on %2$s is canceled', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_shift_date_label( $shift_id )
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
function gwc_vt_send_shift_changed_notice( int $signup_id, array $was = array() ): bool {
	$email = gwc_vt_signup_email( $signup_id );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	$lines = array(
		sprintf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: the organization's name. */
					__( 'A shift you signed up for with %s has changed. Here are the new details.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_org_name()
				)
			)
		),
		gwc_vt_shift_details_table( $shift_id ),
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

	$manage = gwc_vt_signup_manage_url( $signup_id );

	if ( '' !== $manage ) {
		$lines[] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $manage ),
			esc_html__( 'Update your calendar, or cancel if this no longer suits', 'groundwork-common-volunteer-tracker' )
		);
	}

	$lines[] = gwc_vt_email_footer();

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organization's name, 2: a date. */
			__( '%1$s: the shift on %2$s has changed', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_shift_date_label( $shift_id )
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
function gwc_vt_shift_details_table( int $shift_id ): string {
	$rows = array(
		__( 'What', 'groundwork-common-volunteer-tracker' )  => (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ),
		__( 'When', 'groundwork-common-volunteer-tracker' )  => trim( gwc_vt_shift_date_label( $shift_id ) . ', ' . trim( gwc_vt_shift_time_label( $shift_id ) . ' ' . gwc_vt_shift_timezone_label( $shift_id ) ), ', ' ),
		__( 'Where', 'groundwork-common-volunteer-tracker' ) => (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ),
	);

	$supervisor = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_SUPERVISOR, true );

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
function gwc_vt_email_footer(): string {
	return sprintf(
		'<p><small>%s</small></p>',
		esc_html(
			sprintf(
				/* translators: 1: the organization's name, 2: its contact details. */
				__( 'Sent by %1$s (%2$s).', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_org_name(),
				gwc_vt_org_contact()
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
function gwc_vt_shift_one_line( int $shift_id ): string {
	$parts = array_filter(
		array(
			gwc_vt_shift_date_label( $shift_id ),
			trim( gwc_vt_shift_time_label( $shift_id ) . ' ' . gwc_vt_shift_timezone_label( $shift_id ) ),
			(string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ),
		),
		'strlen'
	);

	return implode( ', ', $parts );
}

/**
 * What a shift looks like now, for quoting back once it has changed.
 *
 * Read before anything is written and handed to gwc_vt_queue_signup_mail() as
 * 'was'. Two things read it: gwc_vt_shift_moved(), which decides whether
 * anybody is told at all, and gwc_vt_send_shift_changed_notice(), which prints
 * the 'label' as the "It was previously: ..." sentence.
 *
 * One function because it was two. The shift screen built these six keys and
 * the event grid built the same five and stopped, with no 'label' — and the
 * notice reads that key through a `?? ''` and an emptiness check, so the event
 * grid's copy failed silently: no notice, no warning, just a shorter email that
 * never said what had changed. What changed is usually what decides whether
 * somebody can still come.
 *
 * @param int $shift_id Shift post ID.
 * @return array
 */
function gwc_vt_shift_snapshot( int $shift_id ): array {
	$snapshot = array();

	foreach ( gwc_vt_shift_movement_keys() as $key => $meta_key ) {
		$snapshot[ $key ] = (string) get_post_meta( $shift_id, $meta_key, true );
	}

	/* Derived from the five above rather than a sixth field, which is why
	 * gwc_vt_shift_moved() compares the movement keys by name and not whatever
	 * this array happens to contain. */
	$snapshot['label'] = gwc_vt_shift_one_line( $shift_id );

	return $snapshot;
}

/**
 * The page somebody manages one signup from.
 *
 * A capability URL: it authorizes exactly one thing about exactly one signup,
 * and it is no more sensitive than the mailbox it arrives in. See the note on
 * the token in inc/signups.php.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_signup_manage_url( int $signup_id ): string {
	$page = (int) gwc_vt_setting( 'schedule_page' );

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
			'gwc_vt_signup' => $signup_id,
			'gwc_vt_k'      => gwc_vt_signup_token( $signup_id ),
		),
		(string) get_permalink( $page )
	);
}

/* ── One event, one message ──────────────────────────────────────────────────
 * Four slots must not be four emails. Somebody who selects a morning and an
 * afternoon and a set-up is doing one thing, and a mailbox with four near
 * identical messages in it is how an organization teaches its volunteers to
 * filter its address.
 *
 * Each slot carries ITS OWN cancel link and its own calendar link, and that is
 * what makes per-signup tokens sufficient. "I can only drop the Sunday" is
 * answered by having four links rather than by inventing a token that spans
 * slots — which would mean one forwarded email disclosing the lot.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Note that one message covering several slots is owed.
 *
 * @param int   $event_id   Event post ID.
 * @param int[] $signup_ids The signups this submission made.
 */
function gwc_vt_queue_event_confirmation( int $event_id, array $signup_ids ): void {
	if ( ! $signup_ids ) {
		return;
	}

	gwc_vt_queue_signup_mail(
		'event-confirmation',
		(int) $signup_ids[0],
		array(
			'event'   => $event_id,
			'signups' => array_map( 'intval', $signup_ids ),
		)
	);
}

/**
 * Tell somebody what they signed up for, all of it, in one message.
 *
 * @param int   $event_id   Event post ID.
 * @param int[] $signup_ids Signups from one submission.
 * @return bool
 */
function gwc_vt_send_event_confirmation( int $event_id, array $signup_ids ): bool {
	$signup_ids = array_values( array_filter( array_map( 'intval', $signup_ids ) ) );

	if ( ! $signup_ids || GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		return false;
	}

	$email = gwc_vt_signup_email( $signup_ids[0] );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	/* Ordered by when they happen rather than by which checkbox was selected first. The
	 * message is a plan for a day, and a day runs in one direction. */
	usort(
		$signup_ids,
		static function ( int $a, int $b ) {
			return gwc_vt_compare_slots(
				(int) get_post_field( 'post_parent', $a ),
				(int) get_post_field( 'post_parent', $b )
			);
		}
	);

	$where = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ) );

	$lines = array(
		sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: the event's name, 2: a date, 3: the organization's name. */
					__( 'Thank you for signing up for %1$s on %2$s with %3$s. Here is what you put your name down for.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_event_name( $event_id ),
					gwc_vt_event_date_label( $event_id ),
					gwc_vt_org_name()
				)
			)
		),
	);

	if ( '' !== $where ) {
		$lines[] = sprintf( '<p>%s</p>', esc_html( $where ) );
	}

	foreach ( $signup_ids as $signup_id ) {
		$lines[] = gwc_vt_event_slot_block( (int) $signup_id );
	}

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html__( 'Each one has its own link. Canceling one takes you off that one only and leaves the rest as they are.', 'groundwork-common-volunteer-tracker' )
	);

	$supervisor = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_SUPERVISOR, true ) );

	if ( '' !== $supervisor ) {
		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a staff member's name. */
					__( '%s will be looking after the day.', 'groundwork-common-volunteer-tracker' ),
					$supervisor
				)
			)
		);
	}

	$lines[] = gwc_vt_email_footer();

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organization's name, 2: the event's name. */
			__( '%1$s: you are signed up for %2$s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_event_name( $event_id )
		),
		implode( "\n", $lines )
	);
}

/**
 * One slot inside a grouped message: what it is, and its two links.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_event_slot_block( int $signup_id ): string {
	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return '';
	}

	$role    = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) );
	$notes   = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_NOTES, true ) );
	$waiting = GWC_VT_SIGNUP_WAITLIST === get_post_status( $signup_id );
	$manage  = gwc_vt_signup_manage_url( $signup_id );

	$block = array( '<hr />' );

	if ( '' !== $role ) {
		$block[] = sprintf( '<p><strong>%s</strong><br />', esc_html( $role ) );
	} else {
		$block[] = '<p>';
	}

	$block[] = sprintf(
		'%s, %s</p>',
		esc_html( gwc_vt_shift_date_label( $shift_id ) ),
		esc_html( trim( gwc_vt_shift_time_label( $shift_id ) . ' ' . gwc_vt_shift_timezone_label( $shift_id ) ) )
	);

	if ( $waiting ) {
		$block[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'This one is full, so you are on the waiting list for it. We will be in touch if a place comes free.', 'groundwork-common-volunteer-tracker' )
		);
	}

	if ( '' !== $notes ) {
		$block[] = sprintf( '<p>%s</p>', nl2br( esc_html( $notes ) ) );
	}

	/* No link at all rather than one pointing at the front page. A site can run
	 * events without pinning a public page, and "click here to cancel" landing
	 * on nothing is worse than no link: they click it, find nothing, and assume
	 * they have cancelled. */
	if ( '' !== $manage ) {
		$block[] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $manage ),
			esc_html__( 'Add this one to your calendar, or cancel it', 'groundwork-common-volunteer-tracker' )
		);
	}

	return implode( "\n", $block );
}

/**
 * Remind somebody of everything they hold on one event, in one message.
 *
 * Today's single-shift reminder says "you are down to volunteer on Saturday".
 * Exact for one shift. For somebody holding three places at a festival it is
 * ambiguous in the worst possible way — the decline link drops ONE signup, and
 * the message never says which. So each slot is named and each carries its own
 * link, and the copy says plainly that using one leaves the others alone.
 *
 * @param int   $event_id   Event post ID.
 * @param int[] $signup_ids The signups being reminded about, in time order.
 * @return bool
 */
function gwc_vt_send_event_reminder( int $event_id, array $signup_ids ): bool {
	$signup_ids = array_values( array_filter( array_map( 'intval', $signup_ids ) ) );

	if ( ! $signup_ids || GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		return false;
	}

	$email = gwc_vt_signup_email( $signup_ids[0] );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$where = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ) );

	$lines = array(
		sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: the organization's name, 2: a date, 3: the event's name. */
					__( 'A reminder that you are down to volunteer with %1$s on %2$s — %3$s. Here is your day.', 'groundwork-common-volunteer-tracker' ),
					gwc_vt_org_name(),
					gwc_vt_event_date_label( $event_id ),
					gwc_vt_event_name( $event_id )
				)
			)
		),
	);

	if ( '' !== $where ) {
		$lines[] = sprintf( '<p>%s</p>', esc_html( $where ) );
	}

	foreach ( $signup_ids as $signup_id ) {
		$lines[] = gwc_vt_event_slot_block( (int) $signup_id );
	}

	$lines[] = sprintf(
		'<p>%s</p>',
		esc_html__( 'If you cannot make one of them, please use its own link to let us know — it takes you off that one only, and there is still time for us to ask somebody else.', 'groundwork-common-volunteer-tracker' )
	);

	$lines[] = gwc_vt_email_footer();

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: 1: the organization's name, 2: the event's name. */
			__( '%1$s: a reminder about %2$s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name(),
			gwc_vt_event_name( $event_id )
		),
		implode( "\n", $lines )
	);
}

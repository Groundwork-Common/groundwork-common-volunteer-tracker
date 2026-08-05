<?php
/**
 * The calendar file somebody adds a shift to their phone with.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'gwcvt_maybe_serve_ics' );

/**
 * Serve a calendar file to whoever holds the link.
 *
 * A GET that reads and returns, which is the only kind of GET this feature has.
 * Authorised by the token alone — there is no session here and never will be —
 * and it answers for exactly one signup.
 */
function gwcvt_maybe_serve_ics(): void {
	if ( ! gwcvt_signups_open() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a capability URL; the token below is what authorises it, and a nonce would tie a mailed link to a session that does not exist.
	if ( ! isset( $_GET['gwcvt_ics'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$signup_id = absint( wp_unslash( $_GET['gwcvt_ics'] ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$token = isset( $_GET['gwcvt_k'] ) ? sanitize_text_field( wp_unslash( $_GET['gwcvt_k'] ) ) : '';

	if ( ! gwcvt_signup_token_valid( $signup_id, $token ) ) {
		return;
	}

	$ics = gwcvt_signup_ics( $signup_id );

	if ( '' === $ics ) {
		return;
	}

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="shift.ics"' );

	echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a calendar file, escaped for its own format by gwcvt_ics_escape(); HTML escaping here would corrupt it.
	exit;
}

/* ── A link, not an attachment ───────────────────────────────────────────────
 * The obvious shape is a .ics attached to the confirmation email. It is not the
 * one used here, for two reasons: attaching a file through wp_mail() means
 * either writing it to disk first or reaching into PHPMailer on a hook, and mail
 * filters at a fair number of organisations strip calendar attachments outright.
 * A link works in every client and needs no plumbing.
 *
 * ── UTC instants, derived from stored wall time ──────────────────────────────
 * A shift stores '09:00'. A calendar file has to carry a real moment, and there
 * are two honest ways to do that: a local time with a VTIMEZONE block spelling
 * out the zone's daylight-saving rules, or a UTC instant with a Z on the end.
 *
 * A hand-written VTIMEZONE is a transcription of somebody else's timezone
 * database that goes stale silently and is wrong exactly when the clocks change.
 * A UTC instant is computed once, here, from the same wall time and the same
 * gwcvt_timezone() everything else uses, and it is correct for one fixed
 * occurrence — which is all these events ever are, because occurrences are real
 * rows rather than a recurrence rule. See inc/recurrence.php.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The calendar file for one signup.
 *
 * @param int $signup_id Signup post ID.
 * @return string Empty when the signup or its shift cannot be read.
 */
function gwcvt_signup_ics( int $signup_id ): string {
	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return '';
	}

	$starts = gwcvt_shift_starts( $shift_id );
	$ends   = gwcvt_shift_ends( $shift_id );

	if ( null === $starts || null === $ends ) {
		return '';
	}

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$description = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_NOTES, true ) );

	$supervisor = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_SUPERVISOR, true ) );

	if ( '' !== $supervisor ) {
		$description .= ( '' !== $description ? "\n\n" : '' ) . sprintf(
			/* translators: %s: a staff member's name. */
			__( 'Ask for %s when you arrive.', 'groundwork-common-volunteer-tracker' ),
			$supervisor
		);
	}

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Groundwork Common//Volunteer Tracker//EN',
		'CALSCALE:GREGORIAN',

		/* PUBLISH, emphatically not REQUEST. REQUEST is the iTIP verb for "you
		 * are invited, please reply", and a client that sees it will offer the
		 * volunteer Accept and Decline buttons that send an RSVP to an address
		 * nothing here is listening on. The cancellation link in the email is
		 * where declining actually happens. */
		'METHOD:PUBLISH',
		'BEGIN:VEVENT',
		'UID:gwcvt-signup-' . $signup_id . '@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . gmdate( 'Ymd\THis\Z', $starts->getTimestamp() ),
		'DTEND:' . gmdate( 'Ymd\THis\Z', $ends->getTimestamp() ),
		'SUMMARY:' . gwcvt_ics_escape( gwcvt_shift_summary( $shift_id ) ),
		'LOCATION:' . gwcvt_ics_escape( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) ),
		'DESCRIPTION:' . gwcvt_ics_escape( $description ),
		'END:VEVENT',
		'END:VCALENDAR',
	);

	/**
	 * The lines of a shift's calendar file, before they are folded.
	 *
	 * @param string[] $lines     Unfolded content lines.
	 * @param int      $signup_id The signup.
	 * @param int      $shift_id  The shift.
	 */
	$lines = (array) apply_filters( 'gwcvt_ics_event', $lines, $signup_id, $shift_id );

	$folded = array();

	foreach ( $lines as $line ) {
		$folded[] = gwcvt_ics_fold( (string) $line );
	}

	/* CRLF, not "\n". RFC 5545 says CRLF and enough clients enforce it that a
	 * file with bare newlines is a file some people cannot open. */
	return implode( "\r\n", $folded ) . "\r\n";
}

/**
 * What the event is called in somebody's calendar.
 *
 * The organisation's name is in it, because this lands in a calendar beside
 * dentist appointments and "Sorting the produce delivery" on its own is not
 * something anybody will recognise in three weeks.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwcvt_shift_summary( int $shift_id ): string {
	$activity = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ) );

	if ( '' === $activity ) {
		$activity = __( 'Volunteering', 'groundwork-common-volunteer-tracker' );
	}

	/* _x() rather than __(), because inc/signup-cpt.php builds a signup's title
	 * from the same two-placeholder format with an entirely different meaning —
	 * a person and a shift, rather than a job and an organisation. One msgid for
	 * both would give a translator one entry to fill in and make it wrong in
	 * whichever of the two places their word order did not suit. */
	return sprintf(
		/* translators: 1: what the shift is, 2: the organisation's name. */
		_x( '%1$s — %2$s', 'a shift, as it appears in a calendar', 'groundwork-common-volunteer-tracker' ),
		$activity,
		gwcvt_org_name()
	);
}

/**
 * Escape a value for a calendar property.
 *
 * Backslash first, or it escapes the escapes added after it.
 *
 * @param string $value Raw text.
 * @return string
 */
function gwcvt_ics_escape( string $value ): string {
	$value = str_replace( "\r\n", "\n", $value );

	return str_replace(
		array( '\\', ';', ',', "\n" ),
		array( '\\\\', '\;', '\,', '\n' ),
		$value
	);
}

/**
 * Fold a content line to 75 octets, as the spec requires.
 *
 * Octets rather than characters: a fold that lands in the middle of a multi-byte
 * character produces a file some clients refuse and others render as mojibake,
 * and volunteer names and locations are exactly where the multi-byte characters
 * are. So the split points are chosen by byte, and never inside a sequence.
 *
 * @param string $line One unfolded content line.
 * @return string
 */
function gwcvt_ics_fold( string $line ): string {
	if ( strlen( $line ) <= 75 ) {
		return $line;
	}

	$out       = '';
	$remaining = $line;
	$limit     = 75;

	while ( strlen( $remaining ) > $limit ) {
		$take = $limit;

		/* Walk back off a continuation byte so a UTF-8 sequence is never cut in
		 * half. 0x80–0xBF are continuation bytes; anything else starts a
		 * character and is a safe place to break. */
		while ( $take > 1 && ( ord( $remaining[ $take ] ) & 0xC0 ) === 0x80 ) {
			--$take;
		}

		$out      .= substr( $remaining, 0, $take ) . "\r\n ";
		$remaining = substr( $remaining, $take );

		// Continuation lines carry a leading space, which counts against the 75.
		$limit = 74;
	}

	return $out . $remaining;
}

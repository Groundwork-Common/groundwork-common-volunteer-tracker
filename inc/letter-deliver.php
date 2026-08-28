<?php
/**
 * Delivering a letter that has already been issued.
 *
 * ── Why issuing and delivering came apart ────────────────────────────────────
 * They used to be one act: a letter was produced by printing it or by emailing
 * it, and the log recorded that. It made the invariants easy — the document
 * existed for exactly as long as the instant its reference was minted, so there
 * was never a copy to keep or a version to disagree with.
 *
 * It also made the audit trail thin in the one place it is asked about. A
 * letter printed and posted to a probation office was logged as "printed", and
 * the log had nowhere to say where it went. "A letter about this person left
 * the building on the 28th" is not the answer to "did you send it to us".
 *
 * So issuing mints the reference and writes the record, and printing, posting
 * and emailing are things that happen to a letter that already exists. Each
 * appends a delivery row: when, by whom, by what means, and to whom. A letter
 * with no deliveries has been issued and has not gone anywhere, which is a
 * legitimate state.
 *
 * ── The problem that separation creates, and the answer ──────────────────────
 * The reference is a digest over what the letter states, including every shift
 * it lists. When the two acts were one, the page and the code could not
 * disagree. Now a delivery can happen days later, and one shift verified in
 * between would put a bigger number on a page under a code that says otherwise.
 *
 * So the log remembers which entries the letter listed — IDs only, see the note
 * beside GWC_VT_LETTER_ENTRY_IDS — and a delivery rebuilds from those rather
 * than from the period. Rebuilt that way, the document reproduces exactly and
 * carries the reference it was issued under.
 *
 * When it does not reproduce — an entry was corrected, or deleted — the
 * delivery is refused rather than sent. This is the one place where refusing is
 * clearly right: the alternative is posting a document to a court whose own
 * reference will fail when they ring up to check it. The screen says the
 * records have moved and offers a new letter, which is a true statement and an
 * action, rather than a page nobody can rely on.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_letter_issue', 'gwc_vt_handle_letter_issue' );
add_action( 'admin_post_gwc_vt_letter_deliver_print', 'gwc_vt_handle_deliver_print' );
add_action( 'admin_post_gwc_vt_letter_deliver_post', 'gwc_vt_handle_deliver_post' );
add_action( 'admin_post_gwc_vt_letter_deliver_email', 'gwc_vt_handle_deliver_email' );

/**
 * Issue a letter, and send it nowhere.
 *
 * The whole of what issuing is: the reference is minted, the record is written,
 * and the draft it came from has done its job. Nothing is rendered, because
 * nothing has gone anywhere.
 */
function gwc_vt_handle_letter_issue(): void {
	/* Built from the draft by gwc_vt_letter_request(), which is what fixes the
	 * figures: the draft pins the end of its period and the moment its
	 * attestations are counted as of, so the letter states what the person who
	 * drafted it saw rather than whatever has been verified since. */
	$request = gwc_vt_letter_request();

	$record_id = gwc_vt_log_letter( $request['letter'] );

	if ( $record_id < 1 ) {
		gwc_vt_letters_redirect( 'issue-failed', $request );
	}

	gwc_vt_finish_letter_draft( (int) $request['draft'] );

	gwc_vt_letters_redirect( 'issued', $request );
}

/**
 * The shared front half of the three delivery handlers.
 *
 * ── Addressed by record ID, and not by reference ─────────────────────────────
 * A reference is a digest over what the letter states, so two letters issued on
 * the same day, for the same volunteer, over the same shifts have the SAME
 * code. That is correct — they are the same document, and a court ringing up
 * about either gets the same answer — but it makes the reference a poor way to
 * say *which row of the log* a delivery belongs to. Looking one up by reference
 * returns whichever row the query happened to order first, and the delivery
 * would be recorded against a letter nobody touched.
 *
 * The document would still be right, which is what makes this the kind of bug
 * that survives: everything a human looks at is correct and the audit trail
 * quietly points at the wrong row. So deliveries name the record.
 *
 * @param string $action The admin_post action, which is also the nonce action.
 * @return array{record:array, letter:GWC_VT_Letter, request:array}
 */
function gwc_vt_delivery_request( string $action ): array {
	if ( ! gwc_vt_letters_enabled() ) {
		wp_die(
			esc_html__( 'This organization does not issue verification letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not available', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	gwc_vt_require_cap( 'issue' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- named in the nonce action checked immediately below.
	$record_id = isset( $_REQUEST['record'] ) ? absint( wp_unslash( $_REQUEST['record'] ) ) : 0;

	check_admin_referer( $action . '_' . $record_id );

	if ( GWC_VT_LETTER_TYPE !== get_post_type( $record_id ) ) {
		wp_die(
			esc_html__( 'That letter is not in the log.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	$record = gwc_vt_letter_record( $record_id );

	$letter = gwc_vt_rebuild_issued_letter( $record );

	$request = array(
		'volunteer_id' => (int) $record['volunteer_id'],
		'from'         => (string) $record['from'],
		'to'           => (string) $record['to'],
		'back'         => true,
	);

	if ( ! $letter instanceof GWC_VT_Letter ) {
		/* The volunteer has been anonymized or erased. The letter was certainly
		 * issued and the log still says so, but there is nothing left to build
		 * a page out of, and a blank one would be a document rather than an
		 * explanation. */
		gwc_vt_letters_redirect( 'gone', $request );
	}

	if ( ! gwc_vt_rebuild_is_faithful( $record, $letter ) ) {
		gwc_vt_letters_redirect( 'stale', $request );
	}

	/* The code and the date it was issued under, not the ones the rebuild just
	 * minted. Only now, and never before the check above — see the note on
	 * gwc_vt_stamp_issued_letter(). */
	gwc_vt_stamp_issued_letter( $record, $letter );

	return array(
		'record'  => $record,
		'letter'  => $letter,
		'request' => $request,
	);
}

/**
 * Put an issued letter on paper.
 *
 * Recorded, because paper is how most of these actually leave: somebody prints
 * it and hands it over the desk. Where it goes after that is not something the
 * plugin can know, which is why a print carries no recipient — as opposed to a
 * post, which does.
 */
function gwc_vt_handle_deliver_print(): void {
	$delivery = gwc_vt_delivery_request( 'gwc_vt_letter_deliver_print' );

	gwc_vt_log_delivery( (int) $delivery['record']['id'], 'print' );

	gwc_vt_private_document_headers();

	echo gwc_vt_render_letter( $delivery['letter'], 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete document, escaped as it was assembled in inc/render.php.
	exit;
}

/**
 * Put an issued letter on paper, addressed to somebody.
 *
 * The same document as a print, and a different record: this one names who it
 * was posted to. That is the fact the log was missing, and the reason these two
 * are separate actions rather than one with a checkbox — a coordinator handing
 * a letter to the volunteer at the desk should not have to answer a question
 * about an addressee, and one posting it to a court should not have to
 * remember to.
 */
function gwc_vt_handle_deliver_post(): void {
	$delivery = gwc_vt_delivery_request( 'gwc_vt_letter_deliver_post' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- gwc_vt_delivery_request() checked the nonce over this whole request.
	$addressee = isset( $_REQUEST['addressee'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['addressee'] ) ) ) : '';

	if ( '' === $addressee ) {
		gwc_vt_letters_redirect( 'no-addressee', $delivery['request'] );
	}

	gwc_vt_log_delivery( (int) $delivery['record']['id'], 'post', $addressee );

	gwc_vt_private_document_headers();

	echo gwc_vt_render_letter( $delivery['letter'], 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete document, escaped as it was assembled in inc/render.php.
	exit;
}

/**
 * Email an issued letter.
 *
 * A failed send is recorded as a failed send rather than dropped, for the same
 * reason it always was: this plugin reads that answer to decide whether
 * anything went, and a log that only records successes cannot tell the
 * difference between "we never sent it" and "our mail server was down".
 */
function gwc_vt_handle_deliver_email(): void {
	$delivery = gwc_vt_delivery_request( 'gwc_vt_letter_deliver_email' );
	$letter   = $delivery['letter'];

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$typed = isset( $_REQUEST['recipient'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['recipient'] ) ) ) : '';

	/* Refused on what somebody typed, not on what survived sanitizing:
	 * sanitize_email() strips "dana@" down to nothing, which is
	 * indistinguishable from having typed none, and quietly posting a court
	 * letter to the address on file instead is the mailbox version of the
	 * silent-correction rule. */
	if ( '' !== $typed && ! is_email( sanitize_email( $typed ) ) ) {
		gwc_vt_letters_redirect( 'bad-email', $delivery['request'] );
	}

	$recipient = '' !== $typed
		? sanitize_email( $typed )
		: (string) get_post_meta( $letter->volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		gwc_vt_letters_redirect( 'no-email', $delivery['request'] );
	}

	$sent = gwc_vt_send_email(
		$recipient,
		gwc_vt_letter_subject( $letter ),
		gwc_vt_render_letter( $letter, 'email' )
	);

	gwc_vt_log_delivery( (int) $delivery['record']['id'], 'email', $recipient, $sent );

	gwc_vt_letters_redirect( $sent ? 'delivered-email' : 'send-failed', $delivery['request'] );
}

/**
 * A nonced URL for delivering an issued letter.
 *
 * The nonce action carries the record ID, so one minted for a delivery of one
 * letter cannot be replayed against another — and the ID rather than the
 * reference, for the reason set out on gwc_vt_delivery_request().
 *
 * @param string $action    admin_post action.
 * @param int    $record_id The log record's post ID.
 * @return string
 */
function gwc_vt_delivery_url( string $action, int $record_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => $action,
				'record' => $record_id,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $record_id
	);
}

/**
 * How a delivery reads on the record.
 *
 * @param array $delivery From gwc_vt_letter_deliveries().
 * @return string
 */
function gwc_vt_delivery_words( array $delivery ): string {
	$when = gwc_vt_local_date( (string) $delivery['when'] );

	if ( ! $delivery['ok'] ) {
		return sprintf(
			/* translators: 1: a date, 2: an email address. */
			__( 'Email to %2$s failed, %1$s', 'groundwork-common-volunteer-tracker' ),
			$when,
			(string) $delivery['recipient']
		);
	}

	if ( 'email' === $delivery['medium'] ) {
		return sprintf(
			/* translators: 1: a date, 2: an email address. */
			__( 'Emailed to %2$s, %1$s', 'groundwork-common-volunteer-tracker' ),
			$when,
			(string) $delivery['recipient']
		);
	}

	if ( 'post' === $delivery['medium'] ) {
		return sprintf(
			/* translators: 1: a date, 2: who it was addressed to. */
			__( 'Posted to %2$s, %1$s', 'groundwork-common-volunteer-tracker' ),
			$when,
			(string) $delivery['recipient']
		);
	}

	return sprintf(
		/* translators: %s: a date. */
		__( 'Printed %s', 'groundwork-common-volunteer-tracker' ),
		$when
	);
}

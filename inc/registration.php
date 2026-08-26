<?php
/**
 * Offering to volunteer, from a page on the site.
 *
 * ── The same threat model as the hours form ──────────────────────────────────
 * inc/self-log.php sets out why that surface is the one the plugin assumes the
 * worst about. Everything there applies here and one thing more: the hours form
 * is used by people the organization already knows, whereas this one is aimed
 * squarely at strangers. So the defenses are the same three, in the same order,
 * and they are not repeated in comments here — read that file.
 *
 *   1. OFF until somebody turns it on.
 *   2. No lookup. The handler never asks whether this address already belongs
 *      to a volunteer, so it cannot answer, and there is no oracle to build.
 *   3. Every outcome looks identical. Accepted, honeypotted and rate-limited
 *      return the same message and the same shape, asserted byte for byte.
 *
 * The stamp, the rate limiter and the client-IP reader are shared with the
 * hours form rather than reimplemented, which is why a change to either
 * protects both and why neither can quietly drift into being weaker.
 *
 * ── What it produces ─────────────────────────────────────────────────────────
 * A gwc_vt_application, never a volunteer. See the long note in
 * inc/application-cpt.php.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'gwc_vt_registration_dispatch', 9 );

/**
 * Handle a front-end offer.
 */
function gwc_vt_registration_dispatch(): void {
	/* Checked before the request is even looked at, and deliberately not merely
	 * "do not render the form". A handler that ran while only the form was
	 * hidden would accept posts to a feature the site had switched off. */
	if ( ! gwc_vt_registration_enabled() ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		: '';

	if ( 'POST' !== $method ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- deciding whether this request is ours at all; the nonce is verified in the handler below.
	if ( ! isset( $_POST['gwc_vt_registration_nonce'] ) ) {
		return;
	}

	if ( ! gwc_vt_is_registration_page() ) {
		return;
	}

	gwc_vt_handle_registration();
}

/**
 * Whether offers are being accepted at all.
 *
 * @return bool
 */
function gwc_vt_registration_enabled(): bool {
	return (bool) gwc_vt_setting( 'registration_enabled' );
}

/**
 * Whether the form should ask about required service.
 *
 * Two switches rather than one, because they are two decisions. An organization
 * can want offers from its website without wanting a public page to ask
 * strangers whether they are under a court order.
 *
 * @return bool
 */
function gwc_vt_registration_asks_required(): bool {
	return gwc_vt_registration_enabled() && (bool) gwc_vt_setting( 'registration_ask_required' );
}

/**
 * Whether the form invites a photograph.
 *
 * A third switch, and gated on the form being on for the same reason the
 * required-service question is: a site that armed this and then switched the
 * form off is not inviting anything.
 *
 * @return bool
 */
function gwc_vt_registration_asks_photo(): bool {
	return gwc_vt_registration_enabled() && (bool) gwc_vt_setting( 'registration_ask_photo' );
}

/**
 * Whether this request is on the page the form was pinned to.
 *
 * @return bool
 */
function gwc_vt_is_registration_page(): bool {
	if ( ! gwc_vt_registration_enabled() ) {
		return false;
	}

	/* Outside a main query — a widget, a REST render, WP-CLI — is_page() is not
	 * a meaningful question. The same answer gwc_vt_is_self_log_page() gives,
	 * for the same reason. */
	if ( ! did_action( 'template_redirect' ) ) {
		return true;
	}

	return is_page( (int) gwc_vt_setting( 'registration_page' ) );
}

/**
 * Read, check and record one offer.
 */
function gwc_vt_handle_registration(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_registration_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_registration_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_registration' ) ) {
		gwc_vt_registration_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	/* The honeypot, spelled exactly as the hours form spells it: a visible text
	 * input in an off-screen wrapper, not type="hidden" and not an inline
	 * display:none, both of which the scripts worth stopping already skip. */
	if ( '' !== trim( (string) ( $posted['gwc_vt_website'] ?? '' ) ) ) {
		gwc_vt_registration_result( 'accepted', $started );
		return;
	}

	$age = gwc_vt_form_age( (string) ( $posted['gwc_vt_t'] ?? '' ) );

	if ( null === $age || $age > GWC_VT_FORM_MAX_AGE ) {
		gwc_vt_registration_result( 'expired', $started );
		return;
	}

	if ( $age < GWC_VT_FORM_MIN_AGE ) {
		gwc_vt_registration_result( 'accepted', $started );
		return;
	}

	$code = (string) gwc_vt_setting( 'registration_code' );

	if ( '' !== $code && ! hash_equals( $code, trim( (string) ( $posted['gwc_vt_code'] ?? '' ) ) ) ) {
		gwc_vt_registration_result( 'bad-code', $started );
		return;
	}

	$name  = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_name'] ?? '' ) ), 0, 100 );
	$email = sanitize_email( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	/* Both required, and this is the one place the two public forms differ on
	 * what they insist on. The hours form takes a name without an address,
	 * because a shift that happened happened whether or not anybody can be
	 * written to about it. An offer to volunteer with no way to reply is not an
	 * offer anybody can accept. */
	if ( '' === $name || '' === $email || ! is_email( $email ) ) {
		gwc_vt_registration_result( 'incomplete', $started );
		return;
	}

	/* Counted before it is reported, so a refused attempt still counts against
	 * the limit — otherwise the limiter is a speed bump somebody can sit on. */
	if ( gwc_vt_rate_limited( gwc_vt_client_ip(), $email ) ) {
		gwc_vt_registration_result( 'accepted', $started );
		return;
	}

	gwc_vt_insert_application( $name, $email, $posted );

	gwc_vt_registration_result( 'accepted', $started );
}

/**
 * Store an offer as a pending application attached to nobody.
 *
 * @param string $name   Claimed name.
 * @param string $email  Claimed email.
 * @param array  $posted The rest of the submission.
 * @return int
 */
function gwc_vt_insert_application( string $name, string $email, array $posted ): int {
	$application_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_APPLICATION_TYPE,
			/* Pending is the whole design. Nothing here is a record until a
			 * person makes it one. */
			'post_status' => 'pending',
			'post_title'  => $name,
		)
	);

	if ( is_wp_error( $application_id ) || ! $application_id ) {
		return 0;
	}

	$application_id = (int) $application_id;

	update_post_meta( $application_id, GWC_VT_APPLICATION_NAME, $name );
	update_post_meta( $application_id, GWC_VT_APPLICATION_EMAIL, $email );
	update_post_meta(
		$application_id,
		GWC_VT_APPLICATION_PHONE,
		mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_phone'] ?? '' ) ), 0, 40 )
	);
	update_post_meta(
		$application_id,
		GWC_VT_APPLICATION_NOTE,
		mb_substr( sanitize_textarea_field( (string) ( $posted['gwc_vt_note'] ?? '' ) ), 0, 1000 )
	);
	update_post_meta( $application_id, GWC_VT_APPLICATION_CREATED, (string) current_time( 'mysql', true ) );

	/* Only when the organization asked the question. A form that stopped asking
	 * would otherwise keep storing whatever a script kept posting — the setting
	 * has to gate the WRITE and not only the field, or turning it off leaves the
	 * door open to anybody who kept a copy of the old form. */
	if ( gwc_vt_registration_asks_required() ) {
		$required = gwc_vt_parse_required( (string) ( $posted['gwc_vt_required'] ?? '' ) );

		if ( $required > 0 ) {
			update_post_meta( $application_id, GWC_VT_APPLICATION_REQUIRED, $required );
			update_post_meta(
				$application_id,
				GWC_VT_APPLICATION_REQUIRED_BY,
				gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_required_by'] ?? '' ) ) )
			);
			update_post_meta(
				$application_id,
				GWC_VT_APPLICATION_REQUIRED_FOR,
				mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_required_for'] ?? '' ) ), 0, 120 )
			);
		}
	}

	/* The photograph, if they sent one and the organization asked. Stored last,
	 * so a picture that will not decode costs them the whole submission and not
	 * the record — the offer is already written by this point and a refused
	 * photo leaves it exactly as it would have been without one. */
	gwc_vt_store_offer_photo( $application_id );

	/**
	 * Fires after somebody has offered to volunteer.
	 *
	 * Nothing here is a volunteer record yet, and a handler that made one would
	 * defeat the point of the queue.
	 *
	 * @param int $application_id The pending offer.
	 */
	do_action( 'gwc_vt_application_received', $application_id );

	return $application_id;
}

/**
 * Take the photograph off a public submission, if there is one.
 *
 * Split out so the one place an anonymous visitor can write a file to this
 * server is a named function somebody can read in one sitting.
 *
 * Everything it does not do is the point. It never reports a failure to the
 * visitor: the outcomes of this form stay byte-identical, and "your photo was
 * rejected" would be a second channel out of a form built not to have one. A
 * picture that will not decode is simply not stored, and the offer arrives
 * without one — which is the same thing that happens when somebody chooses not
 * to send a photo at all, and is indistinguishable from it.
 *
 * The setting is checked HERE rather than by the caller, and that is
 * deliberate: gated on the WRITE and not merely on the field, so a form that
 * stopped inviting photographs stops accepting them too — otherwise anybody
 * with a copy of the old page can go on posting files at the server. Keeping
 * the gate inside also means the refusal is something a test can observe, which
 * it could not when the condition sat in the caller.
 *
 * @param int $application_id The offer just written.
 * @return string '' when a photo was stored, otherwise why not.
 */
function gwc_vt_store_offer_photo( int $application_id ): string {
	if ( ! gwc_vt_registration_asks_photo() ) {
		return 'not-asked';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce was verified in gwc_vt_handle_registration() above; the array is never used as it stands, only the three keys cast immediately below, and gwc_vt_store_photo() re-reads the bytes and decodes the image before believing any of it.
	$raw = isset( $_FILES['gwc_vt_photo'] ) ? (array) $_FILES['gwc_vt_photo'] : array();

	if ( ! $raw ) {
		return 'none';
	}

	$file = array(
		'tmp_name' => isset( $raw['tmp_name'] ) ? (string) $raw['tmp_name'] : '',
		'error'    => isset( $raw['error'] ) ? (int) $raw['error'] : UPLOAD_ERR_NO_FILE,
		'size'     => isset( $raw['size'] ) ? (int) $raw['size'] : 0,
	);

	if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
		return 'none';
	}

	return gwc_vt_store_photo( $application_id, $file );
}

/**
 * Hold the answer, at a floor, so the outcomes are not distinguishable by clock.
 *
 * Shares gwc_vt_self_log_result()'s reasoning and its floor. A separate global,
 * because a page could in principle carry both forms.
 *
 * @param string $result  Result key.
 * @param float  $started When the request began.
 */
function gwc_vt_registration_result( string $result, float $started ): void {
	$elapsed = microtime( true ) - $started;
	$floor   = 0.25;

	if ( $elapsed < $floor ) {
		usleep( (int) ( ( $floor - $elapsed ) * 1000000 ) );
	}

	$GLOBALS['gwc_vt_registration_result'] = $result;
}

/**
 * What the visitor is told.
 *
 * 'accepted' covers a real offer, a honeypot hit and a rate-limited attempt,
 * and the string is identical for all three. RegistrationTest asserts that byte
 * for byte — if these ever diverge, the form starts answering questions about
 * who has been submitting.
 *
 * The accepted wording promises no timescale and no outcome. "We will be in
 * touch" is a promise the plugin cannot keep on the organization's behalf, and
 * "you are now a volunteer" would be false — nobody has said yes yet.
 *
 * @param string $result Result key.
 * @return string
 */
function gwc_vt_registration_message( string $result ): string {
	$messages = array(
		'accepted'   => __( 'Thank you — your details have been sent to staff. Somebody will look at them and get in touch.', 'groundwork-common-volunteer-tracker' ),
		'incomplete' => __( 'Please give your name and an email address we can reply to.', 'groundwork-common-volunteer-tracker' ),
		'bad-code'   => __( 'That code was not recognized. Check the code you were given and try again.', 'groundwork-common-volunteer-tracker' ),
		'expired'    => __( 'This form had been open too long to submit safely. Your answers are below — please send them again.', 'groundwork-common-volunteer-tracker' ),
	);

	return $messages[ $result ] ?? '';
}

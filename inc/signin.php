<?php
/**
 * Letting a volunteer prove who they are, without an account.
 *
 * ── Why this exists at all ───────────────────────────────────────────────────
 * Everything else this plugin shows the public is anonymous on purpose. The
 * self-log form never looks anybody up, the signup form writes a claimed name
 * and a claimed address and lets a human resolve them later, and both return
 * one byte-identical answer to every caller. That is what stops either of them
 * answering the question this plugin must never answer: is this named person
 * working off a court order.
 *
 * The cost is that neither form knows WHO it is talking to. A shift that may
 * only be worked by somebody who has done the child-safety class cannot be
 * enforced against a typed email address, because checking it would be exactly
 * the lookup hard rule 4 forbids and would leak exactly what hard rule 3 exists
 * to hide.
 *
 * So: stop being anonymous, in the one place it matters, by asking the person
 * to prove they hold the mailbox. Once they have, telling them what they are
 * missing tells nobody else anything.
 *
 * ── What this is not ─────────────────────────────────────────────────────────
 * It is NOT a WordPress user account. No wp_insert_user, no wp_set_auth_cookie,
 * no role, no capability, no appearance in Users. A volunteer is a
 * gwc_vt_volunteer post and stays one. What this adds is a short-lived session
 * that says "the person on this browser controls the address on volunteer 41",
 * and nothing more.
 *
 * CLAUDE.md's vocabulary says a volunteer "never signs in". That half of the
 * sentence stops being true here, and the entry is being rewritten rather than
 * quietly outlived.
 *
 * ── The two things most likely to go wrong ───────────────────────────────────
 * 1. The request step is an enumeration oracle if it ever answers differently
 *    for an address that is on file. It does not: one message, one shape, one
 *    timing floor, whatever happened. gwc_vt_signin_result() clamps it the way
 *    gwc_vt_signup_result() clamps 'accepted'.
 *
 * 2. A magic link in an email is followed by things that are not people.
 *    Corporate mail scanners and link-preview bots fetch every URL in a message
 *    within seconds of delivery. A link that signs you in on GET is a link that
 *    is spent before the volunteer has read the mail — and this plugin already
 *    has the rule that covers it: nothing mutates on GET. So the link renders a
 *    confirmation with a button, and the POST behind that button is what spends
 *    the token. The same shape gwc_vt_handle_public_cancel() uses.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'gwc_vt_signin_dispatch', 9 );

/* ── What is stored, and where ───────────────────────────────────────────────
 * The token lives on the volunteer as a hash and an expiry. The session lives
 * in a transient keyed by a random id, with that id in a cookie. Nothing about
 * a session is written to the volunteer, so a person signed in on two devices
 * has two sessions and revoking one leaves the other alone.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The hashed sign-in token, on the volunteer. Never the token itself. */
const GWC_VT_SIGNIN_TOKEN = '_gwc_vt_signin_token';

/** When that token stops working. GMT mysql string. */
const GWC_VT_SIGNIN_EXPIRES = '_gwc_vt_signin_expires';

/** How long a link is good for. Long enough to read an email, short enough to matter. */
const GWC_VT_SIGNIN_TOKEN_TTL = 15 * MINUTE_IN_SECONDS;

/** How long a session lasts before the volunteer has to ask for another link. */
const GWC_VT_SIGNIN_SESSION_TTL = 12 * HOUR_IN_SECONDS;

/** The cookie holding a session id. The plugin's first cookie. */
const GWC_VT_SIGNIN_COOKIE = 'gwc_vt_session';

/**
 * Whether volunteers may sign in at all.
 *
 * @return bool
 */
function gwc_vt_signin_enabled(): bool {
	return (bool) gwc_vt_setting( 'signin_enabled' );
}

/**
 * Whether this request is on the page the sign-in form was pinned to.
 *
 * Outside a main query — a widget, a REST render, WP-CLI — is_page() is not a
 * meaningful question, and the answer is the same one gwc_vt_is_self_log_page()
 * and gwc_vt_is_registration_page() give for the same reason.
 *
 * @return bool
 */
function gwc_vt_is_signin_page(): bool {
	if ( ! gwc_vt_signin_enabled() ) {
		return false;
	}

	if ( ! did_action( 'template_redirect' ) ) {
		return true;
	}

	return is_page( (int) gwc_vt_setting( 'signin_page' ) );
}

/**
 * Handle whatever the sign-in page was asked to do.
 */
function gwc_vt_signin_dispatch(): void {
	if ( ! gwc_vt_signin_enabled() ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		: '';

	if ( 'POST' !== $method ) {
		return;
	}

	if ( ! gwc_vt_is_signin_page() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- deciding which handler this request is for; each verifies its own nonce.
	if ( isset( $_POST['gwc_vt_signin_submit'] ) ) {
		gwc_vt_handle_signin_request();
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	if ( isset( $_POST['gwc_vt_signin_confirm'] ) ) {
		gwc_vt_handle_signin_confirm();
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	if ( isset( $_POST['gwc_vt_signout_submit'] ) ) {
		gwc_vt_handle_signout();
	}
}

/* ── Asking for a link ───────────────────────────────────────────────────────
 * The whole oracle problem lives in this one function. Everything it does is
 * arranged so that the visitor's experience is identical whether the address
 * they typed is on file, is on file twice, or has never been heard of.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Take an address and, if it belongs to exactly one volunteer, mail them a link.
 */
function gwc_vt_handle_signin_request(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_signin_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_signin_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_signin' ) ) {
		gwc_vt_signin_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	/* The honeypot, spelled as the other two public forms spell it. */
	if ( '' !== trim( (string) ( $posted['gwc_vt_website'] ?? '' ) ) ) {
		gwc_vt_signin_result( 'sent', $started );
		return;
	}

	$age = gwc_vt_form_age( (string) ( $posted['gwc_vt_t'] ?? '' ) );

	if ( null === $age || $age > GWC_VT_FORM_MAX_AGE ) {
		gwc_vt_signin_result( 'expired', $started );
		return;
	}

	if ( $age < GWC_VT_FORM_MIN_AGE ) {
		gwc_vt_signin_result( 'sent', $started );
		return;
	}

	$email = sanitize_email( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	/* A malformed address is the one refusal that is safe to name, and it is
	 * safe for the reason inc/signup-handler.php gives about 'too-many' and
	 * 'clash': it is a fact about what was just typed, decided before anything
	 * touches the database. "That is not an email address" tells a stranger
	 * nothing about who this organization knows. */
	if ( '' === $email || ! is_email( $email ) ) {
		gwc_vt_signin_result( 'bad-email', $started );
		return;
	}

	if ( gwc_vt_rate_limited( gwc_vt_client_ip(), $email ) ) {
		gwc_vt_signin_result( 'sent', $started );
		return;
	}

	$volunteers = gwc_vt_volunteers_by_email( $email );

	/* ── Retired records cannot be signed in to ──────────────────────────────
	 * gwc_vt_volunteers_by_email() answers "whose record is this address on",
	 * which the privacy exporter and the eraser both need to be true of retired
	 * people as much as anybody. Signing in is a different question — may this
	 * person act here now — and the answer for somebody who has left is no.
	 *
	 * Filtered here rather than in the lookup, so that one function keeps one
	 * meaning. A stranger sees no difference either way: the response below is
	 * byte-identical whether this list empties or not, which is hard rule 3. */
	$volunteers = array_values(
		array_filter(
			$volunteers,
			static function ( $volunteer_id ): bool {
				return GWC_VT_VOLUNTEER_RETIRED !== get_post_status( (int) $volunteer_id );
			}
		)
	);

	/* Exactly one, or nothing happens.
	 *
	 * Zero is the ordinary case for a stranger and needs no explanation. TWO is
	 * the interesting one: an address on two volunteer records is a data problem
	 * somebody has to sort out, and guessing which record to sign somebody into
	 * would attach their hours to whichever the query happened to return first.
	 * Refusing silently is right — but it is invisible to staff, so it is worth
	 * knowing that this is where a duplicate address first bites. */
	if ( 1 === count( $volunteers ) ) {
		gwc_vt_issue_signin_link( (int) $volunteers[0] );
	}

	gwc_vt_signin_result( 'sent', $started );
}

/**
 * Mint a token, store its hash, and queue the link.
 *
 * ── Why the send is queued and not made here ─────────────────────────────────
 * This is the sharpest timing problem in the feature, and the 250 ms floor in
 * gwc_vt_signin_result() does not solve it on its own.
 *
 * Minting is two meta writes — microseconds, which the floor hides completely.
 * SENDING is a call to a mail server, and on a site using SMTP that is a second
 * or more. If the mail left from here, the address that matched would answer
 * measurably slower than the address that did not, every single time, and the
 * one identical sentence would be undone by a stopwatch. That is the same
 * failure hard rule 3 is written against, arriving through the clock instead of
 * through the words.
 *
 * So the token is minted now and the message goes on the queue that
 * inc/schedule-emails.php already flushes on `shutdown` — after the response
 * has been decided. Both branches do the same small amount of work before
 * answering.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool Whether a link was queued.
 */
function gwc_vt_issue_signin_link( int $volunteer_id ): bool {
	$email = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$token = wp_generate_password( 32, false );

	/* Stored as an HMAC rather than in the clear, so a database read does not
	 * hand somebody a working link. Deliberately NOT wp_hash_password(): that is
	 * bcrypt, built to be slow because passwords are guessable, and this is 32
	 * characters of wp_generate_password() entropy that nobody is guessing. The
	 * slowness would buy nothing and would be paid on every redemption.
	 *
	 * Requesting a second link overwrites the first, so only the newest works —
	 * what every password reset does, and what people expect. */
	update_post_meta( $volunteer_id, GWC_VT_SIGNIN_TOKEN, gwc_vt_hash_signin_token( $token ) );
	update_post_meta( $volunteer_id, GWC_VT_SIGNIN_EXPIRES, gmdate( 'Y-m-d H:i:s', time() + GWC_VT_SIGNIN_TOKEN_TTL ) );

	gwc_vt_queue_signup_mail( 'signin', $volunteer_id, array( 'token' => $token ) );

	return true;
}

/**
 * Actually send the link. Called from the shutdown queue, never inline.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $token        The token in the clear.
 * @return bool
 */
function gwc_vt_deliver_signin_link( int $volunteer_id, string $token ): bool {
	$email = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

	if ( '' === $email || ! is_email( $email ) || '' === $token ) {
		return false;
	}

	return gwc_vt_send_email(
		$email,
		sprintf(
			/* translators: %s: the organization's name. */
			__( '%s: your sign-in link', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name()
		),
		gwc_vt_signin_email_body( $volunteer_id, $token )
	);
}

/**
 * The hash a stored token is compared against.
 *
 * @param string $token The token as it appears in a link.
 * @return string
 */
function gwc_vt_hash_signin_token( string $token ): string {
	return hash_hmac( 'sha256', $token, wp_salt( 'gwc_vt_signin' ) );
}

/**
 * The link itself.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $token        The token in the clear.
 * @return string
 */
function gwc_vt_signin_url( int $volunteer_id, string $token ): string {
	return add_query_arg(
		array(
			'gwc_vt_who' => $volunteer_id,
			'gwc_vt_k'   => $token,
		),
		get_permalink( (int) gwc_vt_setting( 'signin_page' ) )
	);
}

/**
 * What the email says.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $token        The token in the clear.
 * @return string
 */
function gwc_vt_signin_email_body( int $volunteer_id, string $token ): string {
	$lines = array();

	$lines[] = sprintf(
		/* translators: %s: a person's name. */
		__( 'Hello %s,', 'groundwork-common-volunteer-tracker' ),
		get_the_title( $volunteer_id )
	);
	$lines[] = '';
	$lines[] = __( 'Somebody asked to sign in to your volunteer record. Use the link below and it will ask you to confirm.', 'groundwork-common-volunteer-tracker' );
	$lines[] = '';
	$lines[] = gwc_vt_signin_url( $volunteer_id, $token );
	$lines[] = '';
	$lines[] = sprintf(
		/* translators: %s: a number of minutes. */
		__( 'The link stops working in %s minutes, and can only be used once.', 'groundwork-common-volunteer-tracker' ),
		number_format_i18n( GWC_VT_SIGNIN_TOKEN_TTL / MINUTE_IN_SECONDS )
	);
	$lines[] = '';
	$lines[] = __( 'If that was not you, you can ignore this. Nobody can sign in without the link, and nothing has changed on your record.', 'groundwork-common-volunteer-tracker' );
	$lines[] = '';
	$lines[] = gwc_vt_email_footer();

	return implode( "\n", $lines );
}

/* ── Spending the token ──────────────────────────────────────────────────────
 * The link is a GET and does nothing but render a form. The POST behind that
 * form is what signs somebody in.
 *
 * That is not ceremony. Mail scanners, link-preview services and some corporate
 * gateways fetch every URL in a message on delivery — a token spent on GET is
 * one the volunteer finds already used, and they cannot tell that from an
 * attack. The plugin's own rule already covers it: nothing mutates on GET.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The volunteer and token a magic link is carrying, if it looks like one.
 *
 * @return array{volunteer:int, token:string}
 */
function gwc_vt_signin_link_parts(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; this only decides whether to draw a confirmation form. Nothing is spent until that form is posted with its own nonce.
	$who = isset( $_GET['gwc_vt_who'] ) ? absint( wp_unslash( $_GET['gwc_vt_who'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$token = isset( $_GET['gwc_vt_k'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_k'] ) ) : '';

	return array(
		'volunteer' => $who,
		'token'     => $token,
	);
}

/**
 * Whether this token still opens this volunteer's record.
 *
 * Read-only: it does not spend the token. The confirmation form calls it to
 * decide whether to draw itself, and the handler calls it again before acting.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $token        The token in the clear.
 * @return bool
 */
function gwc_vt_signin_token_valid( int $volunteer_id, string $token ): bool {
	if ( '' === $token || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return false;
	}

	$stored = (string) get_post_meta( $volunteer_id, GWC_VT_SIGNIN_TOKEN, true );

	if ( '' === $stored ) {
		return false;
	}

	$expires = (string) get_post_meta( $volunteer_id, GWC_VT_SIGNIN_EXPIRES, true );

	if ( '' === $expires || strtotime( $expires . ' UTC' ) < time() ) {
		return false;
	}

	return hash_equals( $stored, gwc_vt_hash_signin_token( $token ) );
}

/**
 * Spend the token and start a session.
 */
function gwc_vt_handle_signin_confirm(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_signin_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_signin_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_signin_confirm' ) ) {
		gwc_vt_signin_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$volunteer_id = absint( $posted['gwc_vt_who'] ?? 0 );
	$token        = sanitize_text_field( (string) ( $posted['gwc_vt_k'] ?? '' ) );

	if ( ! gwc_vt_signin_token_valid( $volunteer_id, $token ) ) {
		gwc_vt_signin_result( 'link-dead', $started );
		return;
	}

	/* Spent before the session is started, not after. A failure between the two
	 * should leave the token unusable rather than reusable. */
	gwc_vt_clear_signin_token( $volunteer_id );

	gwc_vt_start_signin_session( $volunteer_id );

	gwc_vt_signin_result( 'signed-in', $started );
}

/**
 * Forget a volunteer's outstanding link.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_clear_signin_token( int $volunteer_id ): void {
	delete_post_meta( $volunteer_id, GWC_VT_SIGNIN_TOKEN );
	delete_post_meta( $volunteer_id, GWC_VT_SIGNIN_EXPIRES );
}

/* ── The session ─────────────────────────────────────────────────────────────
 * A random id in a cookie, and the volunteer it belongs to in a transient. The
 * cookie carries no identity of its own: somebody who forges one holds a
 * meaningless string, because the mapping lives on the server and expires with
 * the transient.
 *
 * This is the first cookie this plugin has ever set.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The transient key one session lives under.
 *
 * @param string $session_id The id from the cookie.
 * @return string
 */
function gwc_vt_signin_session_key( string $session_id ): string {
	return 'gwc_vt_signin_' . hash( 'sha256', $session_id );
}

/**
 * Begin a session for a volunteer.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string The session id, for tests; the cookie is set here.
 */
function gwc_vt_start_signin_session( int $volunteer_id ): string {
	$session_id = wp_generate_password( 32, false );

	set_transient( gwc_vt_signin_session_key( $session_id ), $volunteer_id, GWC_VT_SIGNIN_SESSION_TTL );

	/* headers_sent() rather than assuming: this runs on template_redirect, which
	 * is before output on every ordinary request and after it on a site with a
	 * stray echo in a theme. Refusing to set the cookie is better than a
	 * "headers already sent" warning on somebody's front page. */
	if ( ! headers_sent() ) {
		setcookie(
			GWC_VT_SIGNIN_COOKIE,
			$session_id,
			array(
				'expires'  => time() + GWC_VT_SIGNIN_SESSION_TTL,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	$GLOBALS['gwc_vt_signin_session'] = $session_id;

	return $session_id;
}

/**
 * Which volunteer is signed in on this request, or 0.
 *
 * Reads the global first so that the request that just signed somebody in sees
 * them, before the cookie it set has made a round trip.
 *
 * @return int
 */
function gwc_vt_signed_in_volunteer(): int {
	if ( ! gwc_vt_signin_enabled() ) {
		return 0;
	}

	$session_id = (string) ( $GLOBALS['gwc_vt_signin_session'] ?? '' );

	if ( '' === $session_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a session cookie, not a form submission; the value is a lookup key and is never trusted as identity on its own.
		$session_id = isset( $_COOKIE[ GWC_VT_SIGNIN_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GWC_VT_SIGNIN_COOKIE ] ) ) : '';
	}

	if ( '' === $session_id ) {
		return 0;
	}

	$volunteer_id = (int) get_transient( gwc_vt_signin_session_key( $session_id ) );

	/* The record may have been anonymized, erased or swept since the session
	 * began. A session pointing at a post that is gone is not a session. */
	if ( $volunteer_id < 1 || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return 0;
	}

	return $volunteer_id;
}

/**
 * End the session on this browser.
 */
function gwc_vt_handle_signout(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_signout_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_signout_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_signout' ) ) {
		gwc_vt_signin_result( 'expired', $started );
		return;
	}

	gwc_vt_end_signin_session();

	gwc_vt_signin_result( 'signed-out', $started );
}

/**
 * Drop the session, server side and in the browser.
 */
function gwc_vt_end_signin_session(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the caller verified a nonce; this only names which transient to delete.
	$session_id = isset( $_COOKIE[ GWC_VT_SIGNIN_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GWC_VT_SIGNIN_COOKIE ] ) ) : '';

	if ( '' !== $session_id ) {
		/* Deleted server-side as well as expired in the browser. A signed-out
		 * session that still resolves is a signed-out session somebody kept a
		 * copy of the cookie for. */
		delete_transient( gwc_vt_signin_session_key( $session_id ) );
	}

	unset( $GLOBALS['gwc_vt_signin_session'] );

	if ( ! headers_sent() ) {
		setcookie(
			GWC_VT_SIGNIN_COOKIE,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}

/* ── What the visitor is told ────────────────────────────────────────────── */

/**
 * Hold the answer at a floor, so the outcomes are not told apart by clock.
 *
 * The same 250 ms floor and the same reasoning as gwc_vt_signup_result(): an
 * address that produced no mail is answered faster than one that did, and a
 * stopwatch is enough to read the difference.
 *
 * @param string $result  Result key.
 * @param float  $started When the request began.
 */
function gwc_vt_signin_result( string $result, float $started ): void {
	$elapsed = microtime( true ) - $started;
	$floor   = 0.25;

	if ( $elapsed < $floor ) {
		usleep( (int) ( ( $floor - $elapsed ) * 1000000 ) );
	}

	$GLOBALS['gwc_vt_signin_result'] = $result;
}

/**
 * The sentence for a result.
 *
 * 'sent' covers a real link, a honeypot, a rate-limited attempt, an address
 * nobody has, and an address two volunteers share. SignInTest asserts the
 * string is one string — if these ever diverge, the form starts answering
 * whether a given person is known to this organization.
 *
 * @param string $result Result key.
 * @return string
 */
function gwc_vt_signin_message( string $result ): string {
	$messages = array(
		'sent'       => __( 'If that address is on one of our volunteer records, a sign-in link is on its way to it. The link lasts fifteen minutes.', 'groundwork-common-volunteer-tracker' ),
		'bad-email'  => __( 'That does not look like an email address. Check it and try again.', 'groundwork-common-volunteer-tracker' ),
		'expired'    => __( 'This form had been open too long to submit safely. Please try again.', 'groundwork-common-volunteer-tracker' ),
		'link-dead'  => __( 'That link has expired or has already been used. Ask for another one below.', 'groundwork-common-volunteer-tracker' ),
		'signed-in'  => __( 'You are signed in.', 'groundwork-common-volunteer-tracker' ),
		'signed-out' => __( 'You are signed out.', 'groundwork-common-volunteer-tracker' ),
	);

	return $messages[ $result ] ?? '';
}

<?php
/**
 * The public hour-logging form: accepting a submission from a stranger.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_RATE_LIMIT_OPTION = 'gwcvt_rate_limits';

/** No scope may accumulate more keys than this before it is emptied. */
const GWCVT_RATE_LIMIT_CEILING = 5000;

/** How long a rendered form stays submittable. */
const GWCVT_FORM_MAX_AGE = 6 * HOUR_IN_SECONDS;

/** How fast a submission has to arrive to be a machine. */
const GWCVT_FORM_MIN_AGE = 3;

add_action( 'template_redirect', 'gwcvt_dispatch' );

/* ── What this file is defending ─────────────────────────────────────────────
 * Everywhere else in this plugin the person on the other end is a member of
 * staff who signed in. Here they are anonymous, and they are handing us a name,
 * an email address, and — implicitly, on a site that runs a court-ordered
 * service programme — the fact that a named person is working one off.
 *
 * So this is the one surface where the plugin has to assume the worst, and the
 * defence has three parts that matter more than the others:
 *
 * 1. It is OFF until somebody turns it on. A plugin that started accepting
 *    personal information from strangers because it was installed would be
 *    doing something nobody asked for.
 *
 * 2. There is no lookup. The handler never asks whether the submitted email
 *    belongs to an existing volunteer, so there is no code path whose behaviour
 *    depends on that answer — which means there is no oracle to build one out
 *    of. The submitted name and address are stored as CLAIMS on a pending
 *    entry, and a human attaches them to a volunteer later. An anonymous form
 *    cannot create an identity record, only a row somebody must triage.
 *
 * 3. Every outcome looks identical. Accepted, honeypotted, rate-limited — the
 *    same message, the same status, the same shape. SelfLogTest asserts that
 *    literally, byte for byte.
 *
 * The nonce is here too, and it is worth being honest about what it does: for a
 * logged-out visitor a WordPress nonce stops naive replay and cross-site
 * posting, and stops nothing that a determined script cannot get past by
 * fetching the page first. The rate limiter is the real defence.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Handle a front-end submission.
 */
function gwcvt_dispatch(): void {
	/* The first line, and deliberately not merely "do not render the form".
	 * A handler that ran while only the form was hidden would accept posts to a
	 * feature the site had switched off. */
	if ( ! gwcvt_self_log_enabled() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; the nonce is verified in gwcvt_handle_self_log().
	if ( ! isset( $_POST['gwcvt_log_hours'] ) ) {
		return;
	}

	if ( ! is_page( (int) gwcvt_setting( 'self_log_page' ) ) ) {
		return;
	}

	/* Nothing about this request may be cached, by us or by anybody in front of
	 * us. The response reflects a submission that just happened and the page
	 * carries a fresh nonce. */
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a shared convention read by caching plugins and hosts; prefixing it would mean nothing reads it.
		define( 'DONOTCACHEPAGE', true );
	}

	nocache_headers();

	gwcvt_handle_self_log();
}

/**
 * Is the public form switched on and pinned to a page?
 *
 * @return bool
 */
function gwcvt_self_log_enabled(): bool {
	return (bool) gwcvt_setting( 'self_log_enabled' ) && (int) gwcvt_setting( 'self_log_page' ) > 0;
}

/**
 * Is this request the page the form is pinned to?
 *
 * The same question gwcvt_dispatch() asks before it will accept a submission,
 * named once so the renderer cannot drift from the handler. They disagreed:
 * the handler required the pinned page and the renderer did not, so a second
 * copy of the form posted into nothing and said nothing about it.
 *
 * @return bool
 */
function gwcvt_is_self_log_page(): bool {
	if ( ! gwcvt_self_log_enabled() ) {
		return false;
	}

	/* Outside a main query — a widget, a REST render, WP-CLI — is_page() is not a
	 * meaningful question. Answering true keeps the form as it was in contexts
	 * that were never the problem. */
	if ( ! did_action( 'template_redirect' ) ) {
		return true;
	}

	return is_page( (int) gwcvt_setting( 'self_log_page' ) );
}

/**
 * Read, check and record one submission.
 */
function gwcvt_handle_self_log(): void {
	$started = microtime( true );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce check.
	$nonce = isset( $_POST['gwcvt_self_log_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwcvt_self_log_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwcvt_self_log' ) ) {
		gwcvt_self_log_result( 'expired', $started );
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	/* The honeypot. A visible text input in an off-screen wrapper — not
	 * type="hidden" and not an inline display:none, both of which the scripts
	 * worth stopping already know to skip. A real person never sees it. */
	if ( '' !== trim( (string) ( $posted['gwcvt_website'] ?? '' ) ) ) {
		gwcvt_self_log_result( 'accepted', $started );
		return;
	}

	$age = gwcvt_form_age( (string) ( $posted['gwcvt_t'] ?? '' ) );

	if ( null === $age || $age > GWCVT_FORM_MAX_AGE ) {
		/* Stale, or the stamp was forged. Re-rendered rather than discarded, so
		 * somebody who left the tab open over lunch gets their values back
		 * instead of losing the form. */
		gwcvt_self_log_result( 'expired', $started );
		return;
	}

	if ( $age < GWCVT_FORM_MIN_AGE ) {
		gwcvt_self_log_result( 'accepted', $started );
		return;
	}

	$code = (string) gwcvt_setting( 'self_log_code' );

	if ( '' !== $code && ! hash_equals( $code, trim( (string) ( $posted['gwcvt_code'] ?? '' ) ) ) ) {
		/* Wrong shared code. A distinct message, because this one is not a
		 * security boundary — it is a note handed out at the front desk, and
		 * somebody who mistypes it needs to be told so. */
		gwcvt_self_log_result( 'bad-code', $started );
		return;
	}

	$name  = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_name'] ?? '' ) ), 0, 100 );
	$email = sanitize_email( (string) ( $posted['gwcvt_email'] ?? '' ) );
	$date  = gwcvt_sanitize_date( sanitize_text_field( (string) ( $posted['gwcvt_date'] ?? '' ) ) );
	$hours = gwcvt_parse_hours( (string) ( $posted['gwcvt_hours'] ?? '' ) );

	if ( '' === $name || '' === $date || null === $hours || $hours < 1 ) {
		gwcvt_self_log_result( 'incomplete', $started );
		return;
	}

	if ( '' !== $date && ! gwcvt_setting( 'allow_future_dates' ) && $date > gwcvt_today() ) {
		gwcvt_self_log_result( 'incomplete', $started );
		return;
	}

	/* Counted before it is reported, so a refused attempt still counts against
	 * the limit — otherwise the limiter is a speed bump somebody can sit on. */
	if ( gwcvt_rate_limited( gwcvt_client_ip(), $email ) ) {
		gwcvt_self_log_result( 'accepted', $started );
		return;
	}

	gwcvt_insert_self_logged_entry( $name, $email, $date, (int) $hours, $posted );

	gwcvt_self_log_result( 'accepted', $started );
}

/**
 * Store a submission as a pending entry attached to nobody.
 *
 * @param string $name   Claimed name.
 * @param string $email  Claimed email.
 * @param string $date   Y-m-d.
 * @param int    $hours  Minutes.
 * @param array  $posted The rest of the submission.
 * @return int
 */
function gwcvt_insert_self_logged_entry( string $name, string $email, string $date, int $hours, array $posted ): int {
	$entry_id = wp_insert_post(
		array(
			'post_type'   => GWCVT_ENTRY_TYPE,
			/* Pending, meaning no human has accepted this record yet. It is not
			 * a draft — a draft reads as unfinished work by staff — and it is
			 * certainly not published, which would put an unreviewed claim into
			 * the organisation's own totals. */
			'post_status' => 'pending',
			'post_title'  => 'tmp',
		)
	);

	if ( is_wp_error( $entry_id ) || ! $entry_id ) {
		return 0;
	}

	$entry_id = (int) $entry_id;

	/* Volunteer left at 0, on purpose. See the box comment at the top: matching
	 * here would mean a code path whose behaviour depends on whether a person
	 * exists, which is the oracle this design removes structurally. */
	update_post_meta( $entry_id, GWCVT_ENTRY_VOLUNTEER, '0' );
	update_post_meta( $entry_id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, $hours );
	update_post_meta( $entry_id, GWCVT_ENTRY_SOURCE, 'self' );
	update_post_meta( $entry_id, '_gwcvt_claim_name', $name );

	if ( '' !== $email && is_email( $email ) ) {
		update_post_meta( $entry_id, '_gwcvt_claim_email', $email );
	}

	/* An allow-list, not a copy of everything posted. Only these three fields
	 * are writable from the public form; a crafted POST carrying, say, a
	 * verification timestamp finds nothing here that would accept it. */
	update_post_meta(
		$entry_id,
		GWCVT_ENTRY_ACTIVITY,
		mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_activity'] ?? '' ) ), 0, 200 )
	);
	update_post_meta(
		$entry_id,
		GWCVT_ENTRY_SUPERVISOR,
		mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_supervisor'] ?? '' ) ), 0, 100 )
	);

	gwcvt_retitle_entry( $entry_id );
	gwcvt_forget_unverified_count();

	/**
	 * Fires after the public form has recorded a submission.
	 *
	 * @param int $entry_id The pending entry.
	 */
	do_action( 'gwcvt_self_log_received', $entry_id );

	return $entry_id;
}

/* ── Telling the visitor what happened ───────────────────────────────────── */

/**
 * Record the outcome for the form to render, with a floor on how fast we answer.
 *
 * The floor exists because "refused instantly" and "accepted after a database
 * write" are distinguishable by a stopwatch. There is no existence oracle here
 * by construction, so this is belt and braces rather than the main defence —
 * but it costs a few milliseconds on a form submitted a handful of times a day.
 *
 * @param string $result  What to tell them.
 * @param float  $started microtime when the handler began.
 */
function gwcvt_self_log_result( string $result, float $started ): void {
	$elapsed = microtime( true ) - $started;
	$floor   = 0.25;

	if ( $elapsed < $floor ) {
		usleep( (int) ( ( $floor - $elapsed ) * 1000000 ) );
	}

	$GLOBALS['gwcvt_self_log_result'] = $result;
}

/**
 * What the visitor is told.
 *
 * 'accepted' covers a real submission, a honeypot hit and a rate-limited
 * attempt, and the string is identical for all three. SelfLogTest asserts that
 * byte for byte — if these ever diverge, the form starts answering questions
 * about who has been submitting.
 *
 * @param string $result Result key.
 * @return string
 */
function gwcvt_self_log_message( string $result ): string {
	$messages = array(
		'accepted'   => __( 'Thank you — your hours have been sent to staff and will appear on your record once somebody has checked them.', 'groundwork-common-volunteer-tracker' ),
		'incomplete' => __( 'Please give your name, the date, and how long you worked.', 'groundwork-common-volunteer-tracker' ),
		'bad-code'   => __( 'That code was not recognised. Check the code you were given and try again.', 'groundwork-common-volunteer-tracker' ),
		'expired'    => __( 'This form had been open too long to submit safely. Your answers are below — please send them again.', 'groundwork-common-volunteer-tracker' ),
	);

	return $messages[ $result ] ?? '';
}

/* ── The timing stamp ────────────────────────────────────────────────────────
 * A hidden field carrying when the form was rendered, HMAC'd so it cannot be
 * forged forward. A submission arriving three seconds after the page loaded was
 * not typed by a person.
 *
 * The alternative — a transient keyed by nonce — is unbounded storage driven
 * entirely by unauthenticated traffic, which is a denial-of-service surface
 * dressed as a rate limiter.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A stamp for a freshly rendered form.
 *
 * @return string
 */
function gwcvt_form_stamp(): string {
	$now = time();

	return $now . '.' . hash_hmac( 'sha256', (string) $now, wp_salt( 'gwcvt_form' ) );
}

/**
 * How old the form was when it was submitted, in seconds.
 *
 * @param string $stamp The posted stamp.
 * @return int|null Null when the stamp is missing or forged.
 */
function gwcvt_form_age( string $stamp ): ?int {
	$parts = explode( '.', trim( $stamp ), 2 );

	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return null;
	}

	$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'gwcvt_form' ) );

	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return null;
	}

	return max( 0, time() - (int) $parts[0] );
}

/* ── The rate limiter ────────────────────────────────────────────────────────
 * Three fixed windows in one non-autoloaded option: by IP, by email address,
 * and one global ceiling so a botnet spreading across addresses still runs into
 * something.
 *
 * Fixed windows rather than a sliding log, because a sliding log means storing
 * a timestamp per attempt and this is storage written by anonymous traffic. The
 * cost of a fixed window is that somebody can send two bursts either side of a
 * boundary; the cost of the alternative is an option that grows without bound
 * while under attack, which is worse.
 *
 * Every scope is pruned on write and hard-capped. If a scope somehow exceeds
 * the ceiling it is emptied rather than trimmed — an option row big enough to
 * matter is itself the problem, and starting the window over is a smaller
 * failure than an autoloaded option nobody can read.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The limits, per scope.
 *
 * @return array<string, array{max:int, window:int}>
 */
function gwcvt_rate_limits(): array {
	$limits = array(
		'ip'    => array(
			'max'    => 10,
			'window' => 15 * MINUTE_IN_SECONDS,
		),
		'email' => array(
			'max'    => 6,
			'window' => HOUR_IN_SECONDS,
		),
		'all'   => array(
			'max'    => 60,
			'window' => HOUR_IN_SECONDS,
		),
	);

	/**
	 * The public form's rate limits.
	 *
	 * @param array $limits Keyed by scope.
	 */
	return (array) apply_filters( 'gwcvt_rate_limits', $limits );
}

/**
 * Count this attempt, and say whether it is over the line.
 *
 * @param string $ip    The client address.
 * @param string $email The submitted address, if any.
 * @return bool
 */
function gwcvt_rate_limited( string $ip, string $email ): bool {
	$limits = gwcvt_rate_limits();
	$now    = time();

	$store = get_option( GWCVT_RATE_LIMIT_OPTION );
	$store = is_array( $store ) ? $store : array();

	$keys = array(
		'ip'    => '' !== $ip ? hash( 'sha256', $ip ) : '',
		'email' => '' !== $email ? hash( 'sha256', strtolower( $email ) ) : '',
		'all'   => 'all',
	);

	$limited = false;

	foreach ( $limits as $scope => $limit ) {
		$key = $keys[ $scope ] ?? '';

		if ( '' === $key ) {
			continue;
		}

		$window = max( 1, (int) $limit['window'] );
		$max    = max( 1, (int) $limit['max'] );
		$bucket = $store[ $scope ] ?? array();

		// Drop everything whose window has closed.
		foreach ( $bucket as $existing => $row ) {
			if ( ( $now - (int) ( $row['start'] ?? 0 ) ) >= $window ) {
				unset( $bucket[ $existing ] );
			}
		}

		if ( count( $bucket ) > GWCVT_RATE_LIMIT_CEILING ) {
			$bucket = array();
		}

		$row = $bucket[ $key ] ?? array(
			'count' => 0,
			'start' => $now,
		);

		++$row['count'];
		$bucket[ $key ]  = $row;
		$store[ $scope ] = $bucket;

		if ( $row['count'] > $max ) {
			$limited = true;
		}
	}

	update_option( GWCVT_RATE_LIMIT_OPTION, $store, false );

	return $limited;
}

/**
 * The client's address, or '' when it cannot be trusted.
 *
 * REMOTE_ADDR only. X-Forwarded-For is trivially spoofable by whoever is being
 * rate-limited, and a limiter keyed on a header the attacker controls is a
 * limiter that does nothing. Sites behind a proxy that rewrites REMOTE_ADDR are
 * already fine; sites behind one that does not should fix that at the server,
 * not here.
 *
 * @return string
 */
function gwcvt_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return (string) filter_var( $ip, FILTER_VALIDATE_IP );
}

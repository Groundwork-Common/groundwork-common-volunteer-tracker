<?php
/**
 * The public signup: accepting a stranger's name against a shift.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'gwcvt_signup_dispatch' );

/* ── The same surface as the hours form, and the same answer ─────────────────
 * inc/self-log.php sets out what this plugin does when the person on the other
 * end is anonymous. Everything there applies here, so this file reuses its
 * defences rather than restating them: gwcvt_rate_limited(), gwcvt_form_stamp(),
 * gwcvt_form_age() and gwcvt_client_ip() are called from here unchanged.
 *
 * One shared limiter across both forms is deliberate. A script hammering the
 * signup form and a script hammering the hours form are the same script, and two
 * separate budgets would be twice the budget.
 *
 * What is different is worth naming, because it is the part somebody could get
 * wrong later:
 *
 * 1. THE LIST IS PUBLIC AND THE ROSTER IS NOT. A visitor may see that Saturday
 *    exists, what the work is, and that three places are left. They may never
 *    see who is coming. On a site running a court-ordered service programme,
 *    "who is volunteering Saturday" is a list of people working one off, and a
 *    place count is not.
 *
 * 2. THERE IS ONE LOOKUP AND IT IS NOT AN ORACLE. gwcvt_find_signup() asks
 *    whether this address is already on THIS shift, so that pressing Submit
 *    twice does not book you twice. It never touches the volunteer table, and
 *    the visible answer is identical either way — only the confirmation email,
 *    which only that address receives, says which happened.
 *
 * 3. NOTHING MUTATES ON GET. The cancellation link in an email lands on a page
 *    with a button. Mail clients and security scanners fetch links, and a GET
 *    that withdrew a signup would eventually be withdrawn by a spam filter.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Handle a front-end signup or cancellation.
 */
function gwcvt_signup_dispatch(): void {
	/* The first line, and deliberately not merely "do not render the form". A
	 * handler that ran while only the form was hidden would accept posts to a
	 * feature the site had switched off. */
	if ( ! gwcvt_signups_open() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; the nonce is verified in each handler.
	$is_signup = isset( $_POST['gwcvt_signup_submit'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$is_cancel = isset( $_POST['gwcvt_cancel_submit'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$is_event = isset( $_POST['gwcvt_event_submit'] );

	if ( ! $is_signup && ! $is_cancel && ! $is_event ) {
		return;
	}

	/* Still a gate, and it now has two keys. An event grid lives on whatever page
	 * the site put the block on, so the pinned schedule page is no longer the
	 * only place a submission can legitimately arrive from. What has NOT changed
	 * is that a handler must never run where no form was rendered — that would be
	 * accepting posts to a feature the site has not put anywhere. */
	if ( ! gwcvt_signup_page_allows_posts() ) {
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

	if ( $is_cancel ) {
		gwcvt_handle_public_cancel();
		return;
	}

	if ( $is_event ) {
		gwcvt_handle_public_event_signup();
		return;
	}

	gwcvt_handle_public_signup();
}

/**
 * May this request carry a signup at all?
 *
 * The pinned schedule page, or any page whose content places an event grid. A
 * post to anywhere else is refused before a nonce is even looked at.
 *
 * @return bool
 */
function gwcvt_signup_page_allows_posts(): bool {
	if ( is_page( (int) gwcvt_setting( 'schedule_page' ) ) ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return has_shortcode( (string) $post->post_content, 'volunteer_event' )
		|| has_block( 'groundwork-common-volunteer-tracker/event-grid', $post );
}

/**
 * Is signing up switched on, and pinned to a page?
 *
 * Both switches, because scheduling without public signup is a supported way to
 * run this — a coordinator taking names on the phone — and the schedule existing
 * must not be what puts a form on the internet.
 *
 * @return bool
 */
function gwcvt_signups_open(): bool {
	return (bool) gwcvt_setting( 'shifts_enabled' )
		&& (bool) gwcvt_setting( 'signup_enabled' )
		&& (int) gwcvt_setting( 'schedule_page' ) > 0;
}

/**
 * Read, check and record one signup.
 */
function gwcvt_handle_public_signup(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwcvt_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwcvt_signup_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwcvt_signup' ) ) {
		gwcvt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	/* The honeypot. A visible text input in an off-screen wrapper — not
	 * type="hidden" and not an inline display:none, both of which the scripts
	 * worth stopping already know to skip. */
	if ( '' !== trim( (string) ( $posted['gwcvt_website'] ?? '' ) ) ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$age = gwcvt_form_age( (string) ( $posted['gwcvt_t'] ?? '' ) );

	if ( null === $age || $age > GWCVT_FORM_MAX_AGE ) {
		gwcvt_signup_result( 'expired', $started );
		return;
	}

	if ( $age < GWCVT_FORM_MIN_AGE ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$code = (string) gwcvt_setting( 'signup_code' );

	if ( '' !== $code && ! hash_equals( $code, trim( (string) ( $posted['gwcvt_code'] ?? '' ) ) ) ) {
		/* A distinct message, because this one is not a security boundary — it is
		 * a word somebody was given, and a person who mistypes it needs telling. */
		gwcvt_signup_result( 'bad-code', $started );
		return;
	}

	$shift_id = absint( $posted['gwcvt_shift'] ?? 0 );
	$name     = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_name'] ?? '' ) ), 0, 100 );
	$email    = sanitize_email( (string) ( $posted['gwcvt_email'] ?? '' ) );

	/* An address is required here where the hours form leaves it optional, and
	 * the reason is the confirmation: a signup nobody can be told about is a
	 * commitment with no record and no way out of it. */
	if ( '' === $name || '' === $email || ! is_email( $email ) ) {
		gwcvt_signup_result( 'incomplete', $started );
		return;
	}

	if ( ! gwcvt_shift_is_signup_visible( $shift_id ) ) {
		/* Closed, cancelled, in the past, a draft, or not a shift at all. One
		 * message for all of them: the visitor's list was simply out of date, and
		 * enumerating why would answer questions about shifts they cannot see. */
		gwcvt_signup_result( 'unavailable', $started );
		return;
	}

	/* Counted before it is reported, so a refused attempt still counts against
	 * the limit — otherwise the limiter is a speed bump somebody can sit on. */
	if ( gwcvt_rate_limited( gwcvt_client_ip(), $email ) ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$signup_id = gwcvt_add_signup(
		$shift_id,
		array(
			'claim_name'  => $name,
			'claim_email' => $email,
			'source'      => 'self',
		)
	);

	if ( $signup_id > 0 ) {
		gwcvt_queue_signup_confirmation( $signup_id );
	}

	gwcvt_signup_result( 'accepted', $started );
}

/* ── One submission, several slots ───────────────────────────────────────────
 * The dangerous one, and the danger is entirely in what it says back.
 *
 * gwcvt_add_signup() is idempotent — a second signup for the same slot by the
 * same address refreshes the first rather than making another — and this form
 * never checks whose address it was given. So a stranger can type somebody
 * else's address and tick three boxes. Three new signups, two new and one
 * refresh, or three refreshes: IF THE RESPONSE TELLS THOSE APART, they have
 * learned which of those slots that person was already on, without ever being
 * told a name. On a site running court-ordered service that is a disclosure
 * about a named person, obtained by somebody who supplied nothing but a guess.
 *
 * So the leak is a count of WHAT THE WRITE DID. A count of what was ticked would
 * in fact be safe — it reports only what the sender already posted, and a
 * honeypot hit could produce it too. The rule bans all of them anyway, because
 * "the response carries no number" is one assertion a test can hold, and "only
 * numbers derived from the request" is a rule the next friendly copy edit will
 * break. Write it down or somebody competent decides the rule is superstition.
 *
 * ── Why the clash check runs before the honeypot ─────────────────────────────
 * Both slots are in the POST, so the check reads only what the sender ticked and
 * the times already printed on the page. Running it FIRST means every path gives
 * the same answer to the same POST — otherwise a bot filling the honeypot would
 * get 'accepted' where a clean clashing submission got a warning, and the
 * difference between those two answers is a honeypot detector.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Read, check and record one submission covering several slots.
 */
function gwcvt_handle_public_event_signup(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwcvt_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwcvt_signup_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwcvt_signup' ) ) {
		gwcvt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$event_id = absint( $posted['gwcvt_event'] ?? 0 );
	$wanted   = array_map( 'absint', array_keys( (array) ( $posted['gwcvt_slots'] ?? array() ) ) );
	$wanted   = array_values( array_filter( array_unique( $wanted ) ) );

	/* Kept so the form can be handed back with every tick still in place. A
	 * visitor who has to answer a question should not have to re-do the grid. */
	$GLOBALS['gwcvt_signup_picked'] = $wanted;
	$GLOBALS['gwcvt_signup_name']   = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_name'] ?? '' ) ), 0, 100 );
	$GLOBALS['gwcvt_signup_email']  = sanitize_email( (string) ( $posted['gwcvt_email'] ?? '' ) );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
		gwcvt_signup_result( 'unavailable', $started );
		return;
	}

	/* A cap on one submission. Safe to report because it depends only on what the
	 * visitor posted, not on anything the site knows about them. */
	if ( count( $wanted ) > gwcvt_event_signup_limit() ) {
		gwcvt_signup_result( 'too-many', $started );
		return;
	}

	/* Only slots belonging to THIS event, so a crafted post cannot reach across
	 * to another event's grid or to a standalone shift. */
	$mine = array();

	foreach ( $wanted as $shift_id ) {
		if ( gwcvt_event_for_shift( $shift_id ) === $event_id ) {
			$mine[] = $shift_id;
		}
	}

	if ( ! $mine || '' === $GLOBALS['gwcvt_signup_name'] || '' === $GLOBALS['gwcvt_signup_email'] || ! is_email( $GLOBALS['gwcvt_signup_email'] ) ) {
		gwcvt_signup_result( 'incomplete', $started );
		return;
	}

	if ( empty( $posted['gwcvt_clash_ok'] ) ) {
		$pair = gwcvt_first_overlapping_pair( $mine );

		if ( $pair ) {
			$GLOBALS['gwcvt_signup_clash'] = $pair;
			gwcvt_signup_result( 'clash', $started );
			return;
		}
	}

	/* The honeypot. Everything above this line is derived from the request;
	 * everything below it can depend on the database. */
	if ( '' !== trim( (string) ( $posted['gwcvt_website'] ?? '' ) ) ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$age = gwcvt_form_age( (string) ( $posted['gwcvt_t'] ?? '' ) );

	if ( null === $age || $age > GWCVT_FORM_MAX_AGE ) {
		gwcvt_signup_result( 'expired', $started );
		return;
	}

	if ( $age < GWCVT_FORM_MIN_AGE ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$code = (string) gwcvt_setting( 'signup_code' );

	if ( '' !== $code && ! hash_equals( $code, trim( (string) ( $posted['gwcvt_code'] ?? '' ) ) ) ) {
		gwcvt_signup_result( 'bad-code', $started );
		return;
	}

	$open = array();

	foreach ( $mine as $shift_id ) {
		if ( gwcvt_shift_is_signup_visible( $shift_id ) ) {
			$open[] = $shift_id;
		}
	}

	/* Any slot that closed between the page rendering and the button being
	 * pressed sends them back to the refreshed grid — with no count, and without
	 * saying which. Their list was out of date; enumerating why would answer
	 * questions about slots they cannot see. */
	if ( count( $open ) !== count( $mine ) ) {
		gwcvt_signup_result( 'unavailable', $started );
		return;
	}

	/* Counted once. It is one person pressing one button, whatever they ticked —
	 * and counted before it is reported, so a refused attempt still counts. */
	if ( gwcvt_rate_limited( gwcvt_client_ip(), $GLOBALS['gwcvt_signup_email'] ) ) {
		gwcvt_signup_result( 'accepted', $started );
		return;
	}

	$made = array();

	foreach ( $open as $shift_id ) {
		$signup_id = gwcvt_add_signup(
			$shift_id,
			array(
				'claim_name'  => $GLOBALS['gwcvt_signup_name'],
				'claim_email' => $GLOBALS['gwcvt_signup_email'],
				'source'      => 'self',
			)
		);

		if ( $signup_id > 0 ) {
			$made[] = $signup_id;
		}
	}

	if ( $made ) {
		gwcvt_queue_event_confirmation( $event_id, $made );
	}

	/* Every slot accepted, one slot accepted, a honeypot hit and a rate-limited
	 * attempt all end here, on the same string. */
	$GLOBALS['gwcvt_signup_picked'] = array();
	gwcvt_signup_result( 'accepted', $started );
}

/**
 * How many slots one submission may take.
 *
 * @return int
 */
function gwcvt_event_signup_limit(): int {
	/**
	 * The most slots one person may take in a single submission.
	 *
	 * @param int $limit Generous for any real event.
	 */
	return max( 1, (int) apply_filters( 'gwcvt_event_signup_limit', 20 ) );
}

/**
 * Withdraw a signup from the link in somebody's confirmation.
 */
function gwcvt_handle_public_cancel(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwcvt_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwcvt_cancel_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwcvt_cancel_signup' ) ) {
		gwcvt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$signup_id = absint( $posted['gwcvt_signup'] ?? 0 );
	$token     = sanitize_text_field( (string) ( $posted['gwcvt_k'] ?? '' ) );

	/* A bad token and a signup that no longer exists give the same answer. The
	 * token is the only thing that authorises this, so distinguishing them would
	 * turn the URL into a way to ask whether a given signup ID is real. */
	if ( ! gwcvt_signup_token_valid( $signup_id, $token ) ) {
		gwcvt_signup_result( 'cancel-unknown', $started );
		return;
	}

	gwcvt_withdraw_signup( $signup_id );

	gwcvt_signup_result( 'cancelled', $started );
}

/**
 * May a member of the public see and sign up for this shift?
 *
 * The single answer, used by the list, by the event grid and by the handler, so
 * a shift that is invisible cannot be signed up for by anybody who guesses its
 * ID.
 *
 * ── Event slots answer to their event as well as to themselves ───────────────
 * A slot on a DRAFT or a cancelled event is refused here, which is the whole
 * reason this check knows about events at all: a coordinator building next
 * month's festival in draft has real shift rows in the database, and without
 * this a guessed ID would book a place on an event nobody has published.
 *
 * ── Why the horizon does not apply to a slot ─────────────────────────────────
 * signup_horizon_days keeps the flat list from showing a year of Saturdays to
 * somebody who only wanted to know about this one. A visitor on an event's page
 * has already chosen that event, so the horizon has nothing to protect them
 * from — and applying it would mean a festival announced ninety days out shows a
 * grid where every row says it is unavailable, which reads as broken.
 *
 * @param int $shift_id Shift post ID.
 * @return bool
 */
function gwcvt_shift_is_signup_visible( int $shift_id ): bool {
	if ( $shift_id < 1 || GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	if ( ! gwcvt_shift_is_open( $shift_id ) ) {
		return false;
	}

	$starts = gwcvt_shift_starts( $shift_id );

	if ( null === $starts ) {
		return false;
	}

	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( $event_id > 0 ) {
		return 'publish' === get_post_status( $event_id );
	}

	$horizon = max( 1, (int) gwcvt_setting( 'signup_horizon_days' ) );

	return $starts->getTimestamp() <= ( time() + ( $horizon * DAY_IN_SECONDS ) );
}

/**
 * The shifts a visitor may see, soonest first.
 *
 * @return int[]
 */
function gwcvt_public_shift_ids(): array {
	$horizon = max( 1, (int) gwcvt_setting( 'signup_horizon_days' ) );

	/* Standalone shifts only. An event's slots belong on their event's own page,
	 * where the grid says which role each one is and what the day is; loose on
	 * this list they are half a dozen unexplained times at the same address.
	 *
	 * This is a filter on the LIST, not on what may be signed up for — an event
	 * slot is perfectly signup-able, just from the event's page. The handler
	 * still asks gwcvt_shift_is_signup_visible(), which knows the difference. */
	$ids = gwcvt_shifts_between(
		array(
			'from'   => gwcvt_today(),
			'to'     => gmdate( 'Y-m-d', time() + ( ( $horizon + 1 ) * DAY_IN_SECONDS ) ),
			'limit'  => 100,
			'parent' => 0,
		)
	);

	$visible = array();

	foreach ( $ids as $shift_id ) {
		if ( gwcvt_shift_is_signup_visible( $shift_id ) ) {
			$visible[] = $shift_id;
		}
	}

	/**
	 * The shifts shown on the public list.
	 *
	 * Filtered rather than fixed so a site can hide a shift from the public list
	 * while keeping it on the schedule. Anything removed here is also refused by
	 * the handler, because both read gwcvt_shift_is_signup_visible().
	 *
	 * @param int[] $visible Shift post IDs.
	 */
	return (array) apply_filters( 'gwcvt_schedule_visible_shifts', $visible );
}

/* ── Telling the visitor what happened ───────────────────────────────────── */

/**
 * Record the outcome for the page to render, with a floor on how fast we answer.
 *
 * The floor exists because "refused instantly" and "accepted after a database
 * write" are distinguishable by a stopwatch. Belt and braces rather than the
 * main defence, and it costs a few milliseconds on a form used a handful of
 * times a day.
 *
 * @param string $result  What to tell them.
 * @param float  $started microtime when the handler began.
 */
function gwcvt_signup_result( string $result, float $started ): void {
	$elapsed = microtime( true ) - $started;
	$floor   = 0.25;

	if ( $elapsed < $floor ) {
		usleep( (int) ( ( $floor - $elapsed ) * 1000000 ) );
	}

	$GLOBALS['gwcvt_signup_result'] = $result;
}

/**
 * What the visitor is told.
 *
 * 'accepted' covers a real signup, a honeypot hit and a rate-limited attempt,
 * and the string is identical for all three. SignupFormTest asserts that byte
 * for byte — if these ever diverge, the form starts answering questions about
 * who has been signing up.
 *
 * @param string $result Result key.
 * @return string
 */
function gwcvt_signup_message( string $result ): string {
	$messages = array(
		'accepted'       => __( 'Thank you — you are signed up. We have sent the details to your email address, along with a link you can use if you need to cancel.', 'groundwork-common-volunteer-tracker' ),
		'incomplete'     => __( 'Please choose a shift and give your name and email address.', 'groundwork-common-volunteer-tracker' ),
		'unavailable'    => __( 'That shift is no longer taking signups. The list below is up to date — please pick another.', 'groundwork-common-volunteer-tracker' ),
		'bad-code'       => __( 'That code was not recognised. Check the code you were given and try again.', 'groundwork-common-volunteer-tracker' ),
		'expired'        => __( 'This page had been open too long to submit safely. Please try again.', 'groundwork-common-volunteer-tracker' ),
		'cancelled'      => __( 'You have been taken off that shift. Thank you for letting us know.', 'groundwork-common-volunteer-tracker' ),
		'cancel-unknown' => __( 'That link is no longer valid. It may have already been used, or the shift may have changed. Please get in touch if you need to cancel.', 'groundwork-common-volunteer-tracker' ),

		/* Both of these are safe to say because both depend only on what the
		 * visitor just posted — how many boxes they ticked, and whether two of
		 * those boxes are at the same time. Neither reports anything the site
		 * knows about the address they typed. Nothing else may be added here
		 * without that being true of it as well. */
		'too-many'       => __( 'That is more times than we can take in one go. Please pick a few, send them, and come back for the rest.', 'groundwork-common-volunteer-tracker' ),
		'clash'          => __( 'Two of the times you picked overlap. Change one, or tick the box below to say you meant both.', 'groundwork-common-volunteer-tracker' ),
	);

	return $messages[ $result ] ?? '';
}

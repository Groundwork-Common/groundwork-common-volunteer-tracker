<?php
/**
 * The public signup: accepting a stranger's name against a shift.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'gwc_vt_signup_dispatch' );

/* ── The same surface as the hours form, and the same answer ─────────────────
 * inc/self-log.php sets out what this plugin does when the person on the other
 * end is anonymous. Everything there applies here, so this file reuses its
 * defences rather than restating them: gwc_vt_rate_limited(), gwc_vt_form_stamp(),
 * gwc_vt_form_age() and gwc_vt_client_ip() are called from here unchanged.
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
 *    see who is coming. On a site running a court-ordered service program,
 *    "who is volunteering Saturday" is a list of people working one off, and a
 *    place count is not.
 *
 * 2. THERE IS ONE LOOKUP AND IT IS NOT AN ORACLE. gwc_vt_find_signup() asks
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
function gwc_vt_signup_dispatch(): void {
	/* The first line, and deliberately not merely "do not render the form". A
	 * handler that ran while only the form was hidden would accept posts to a
	 * feature the site had switched off. */
	if ( ! gwc_vt_signups_open() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; the nonce is verified in each handler.
	$is_signup = isset( $_POST['gwc_vt_signup_submit'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$is_cancel = isset( $_POST['gwc_vt_cancel_submit'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$is_event = isset( $_POST['gwc_vt_event_submit'] );

	if ( ! $is_signup && ! $is_cancel && ! $is_event ) {
		return;
	}

	/* Still a gate, and it now has two keys. An event grid lives on whatever page
	 * the site put the block on, so the pinned schedule page is no longer the
	 * only place a submission can legitimately arrive from. What has NOT changed
	 * is that a handler must never run where no form was rendered — that would be
	 * accepting posts to a feature the site has not put anywhere. */
	if ( ! gwc_vt_signup_page_allows_posts() ) {
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
		gwc_vt_handle_public_cancel();
		return;
	}

	if ( $is_event ) {
		gwc_vt_handle_public_event_signup();
		return;
	}

	gwc_vt_handle_public_signup();
}

/**
 * May this request carry a signup at all?
 *
 * The pinned schedule page, or any page whose content places an event grid. A
 * post to anywhere else is refused before a nonce is even looked at.
 *
 * @return bool
 */
function gwc_vt_signup_page_allows_posts(): bool {
	if ( is_page( (int) gwc_vt_setting( 'schedule_page' ) ) ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return has_shortcode( (string) $post->post_content, 'gwc_vt_event_grid' )
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
function gwc_vt_signups_open(): bool {
	return (bool) gwc_vt_setting( 'shifts_enabled' )
		&& (bool) gwc_vt_setting( 'signup_enabled' )
		&& (int) gwc_vt_setting( 'schedule_page' ) > 0;
}

/**
 * Read, check and record one signup.
 */
function gwc_vt_handle_public_signup(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_signup_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_signup' ) ) {
		gwc_vt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$refusal = gwc_vt_form_guard( $posted );

	if ( null !== $refusal ) {
		gwc_vt_signup_result( $refusal, $started );
		return;
	}

	/* Read before the code check rather than after it, so a mistyped code can
	 * hand the rest back. Retyping a name because a word from a card was wrong
	 * is the same insult as retyping it because an address was. */
	$shift_id = absint( $posted['gwc_vt_shift'] ?? 0 );
	$name     = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_name'] ?? '' ) ), 0, 100 );
	$email    = sanitize_email( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	/* Checked here rather than up with the other guards, because the name and
	 * the address have deliberately been read first — see above. */
	if ( ! gwc_vt_form_code_ok( $posted, 'signup_code' ) ) {
		/* A distinct message, because this one is not a security boundary — it is
		 * a word somebody was given, and a person who mistypes it needs telling.
		 *
		 * The code itself is never handed back. It is closer to a password than
		 * to a name: it came off a card at the front desk, and a form that
		 * redisplays it puts it on the screen of whoever is standing behind. */
		gwc_vt_signup_result(
			'bad-code',
			$started,
			array(
				'shift'   => $shift_id,
				'name'    => $name,
				'email'   => $email,
				'invalid' => array( 'code' ),
			)
		);
		return;
	}

	/* An address is required here where the hours form leaves it optional, and
	 * the reason is the confirmation: a signup nobody can be told about is a
	 * commitment with no record and no way out of it. */
	/* Which field, rather than a sentence naming all three. Safe to be specific
	 * for the reason given below about 'too-many' and 'clash': every one of
	 * these is a fact about what was just posted, not about what the site holds.
	 * Nothing here may branch on whether the address belongs to somebody — that
	 * is hard rule 4, and it is why 'email' means "you did not give one" and
	 * never "we do not know that one". */
	$invalid = array();

	if ( $shift_id < 1 ) {
		$invalid[] = 'shift';
	}

	if ( '' === $name ) {
		$invalid[] = 'name';
	}

	/* Told apart on the RAW value, not the sanitized one. sanitize_email()
	 * reduces anything that is not an address to an empty string, so testing
	 * $email alone reports "you did not give one" to somebody who gave one and
	 * mistyped it — which is the more common mistake and the more annoying
	 * thing to be told. */
	$typed_email = trim( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	if ( '' === $typed_email ) {
		$invalid[] = 'email';
	} elseif ( '' === $email || ! is_email( $email ) ) {
		$invalid[] = 'email-format';
	}

	if ( $invalid ) {
		gwc_vt_signup_result(
			'incomplete',
			$started,
			array(
				'shift'   => $shift_id,
				'name'    => $name,
				'email'   => (string) ( $posted['gwc_vt_email'] ?? '' ),
				'invalid' => $invalid,
			)
		);
		return;
	}

	if ( ! gwc_vt_shift_is_signup_visible( $shift_id ) ) {
		/* Closed, cancelled, in the past, a draft, or not a shift at all. One
		 * message for all of them: the visitor's list was simply out of date, and
		 * enumerating why would answer questions about shifts they cannot see.
		 *
		 * The name and address come back; the shift deliberately does not. The
		 * message tells them their list was stale and to pick another, and
		 * re-selecting the row that just failed would be arguing with it. */
		gwc_vt_signup_result(
			'unavailable',
			$started,
			array(
				'name'  => $name,
				'email' => $email,
			)
		);
		return;
	}

	/* ── A shift that asks for a blocking credential ─────────────────────
	 * Two answers, and which one somebody gets depends on whether they have
	 * proved a mailbox — never on what this site knows about the address they
	 * typed.
	 *
	 * NOT SIGNED IN: told to sign in first. This is safe, and the reason is
	 * worth stating because it looks like the oracle hard rules 3 and 4 exist
	 * to prevent and is not one. Every visitor gets the identical answer for
	 * the identical shift. It is a fact about a Saturday — this one needs a
	 * waiver — and the public shift list already says so. Nothing about it
	 * varies with the person, so it cannot be made to answer whether a named
	 * person is on file, which is the question those rules protect.
	 *
	 * SIGNED IN: we know who they are because they clicked a link in their own
	 * mailbox, so telling them what they are missing tells them about
	 * themselves. That is the whole point of building sign-in first.
	 *
	 * Before the rate limiter deliberately. Both answers below are about the
	 * shift or about somebody who has authenticated, so neither is a thing
	 * worth spending the limiter's budget to protect — and a volunteer told to
	 * sign in should not be told that only after four tries. */
	$volunteer_id = gwc_vt_signed_in_volunteer();

	if ( gwc_vt_shift_needs_signin( $shift_id ) && $volunteer_id < 1 ) {
		gwc_vt_signup_result(
			'needs-signin',
			$started,
			array(
				'shift' => $shift_id,
				'name'  => $name,
				'email' => (string) ( $posted['gwc_vt_email'] ?? '' ),
			)
		);
		return;
	}

	if ( $volunteer_id > 0 ) {
		$refused = gwc_vt_signup_credential_refusal( $volunteer_id, $shift_id );

		if ( $refused ) {
			gwc_vt_signup_result(
				'credential-missing',
				$started,
				array(
					'shift'       => $shift_id,
					'name'        => $name,
					'email'       => (string) ( $posted['gwc_vt_email'] ?? '' ),
					'credentials' => gwc_vt_credential_names( $refused ),
				)
			);
			return;
		}
	}

	/* Counted before it is reported, so a refused attempt still counts against
	 * the limit — otherwise the limiter is a speed bump somebody can sit on. */
	if ( gwc_vt_rate_limited( gwc_vt_client_ip(), $email ) ) {
		gwc_vt_signup_result( 'accepted', $started );
		return;
	}

	/* Signed in means a real volunteer_id rather than a claim, which is what
	 * keeps these out of the triage queue entirely — and it is what made the
	 * refusal above possible in the first place. */
	$signup_id = gwc_vt_add_signup(
		$shift_id,
		$volunteer_id > 0
			? array(
				'volunteer_id' => $volunteer_id,
				'source'       => 'self',
			)
			: array(
				'claim_name'  => $name,
				'claim_email' => $email,
				'source'      => 'self',
			)
	);

	if ( $signup_id > 0 ) {
		gwc_vt_queue_signup_confirmation( $signup_id );
	}

	gwc_vt_signup_result( 'accepted', $started );
}

/* ── One submission, several slots ───────────────────────────────────────────
 * The dangerous one, and the danger is entirely in what it says back.
 *
 * gwc_vt_add_signup() is idempotent — a second signup for the same slot by the
 * same address refreshes the first rather than making another — and this form
 * never checks whose address it was given. So a stranger can type somebody
 * else's address and select three checkboxes. Three new signups, two new and one
 * refresh, or three refreshes: IF THE RESPONSE TELLS THOSE APART, they have
 * learned which of those slots that person was already on, without ever being
 * told a name. On a site running court-ordered service that is a disclosure
 * about a named person, obtained by somebody who supplied nothing but a guess.
 *
 * So the leak is a count of WHAT THE WRITE DID. A count of what was selected would
 * in fact be safe — it reports only what the sender already posted, and a
 * honeypot hit could produce it too. The rule bans all of them anyway, because
 * "the response carries no number" is one assertion a test can hold, and "only
 * numbers derived from the request" is a rule the next friendly copy edit will
 * break. Write it down or somebody competent decides the rule is superstition.
 *
 * ── Why the clash check runs before the honeypot ─────────────────────────────
 * Both slots are in the POST, so the check reads only what the sender selected and
 * the times already printed on the page. Running it FIRST means every path gives
 * the same answer to the same POST — otherwise a bot filling the honeypot would
 * get 'accepted' where a clean clashing submission got a warning, and the
 * difference between those two answers is a honeypot detector.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Read, check and record one submission covering several slots.
 */
function gwc_vt_handle_public_event_signup(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_signup_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_signup' ) ) {
		gwc_vt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$event_id = absint( $posted['gwc_vt_event'] ?? 0 );
	$wanted   = array_map( 'absint', array_keys( (array) ( $posted['gwc_vt_slots'] ?? array() ) ) );
	$wanted   = array_values( array_filter( array_unique( $wanted ) ) );

	/* Kept so the form can be handed back with every selection still in place. A
	 * visitor who has to answer a question should not have to re-do the grid. */
	$GLOBALS['gwc_vt_signup_picked'] = $wanted;
	$GLOBALS['gwc_vt_signup_name']   = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_name'] ?? '' ) ), 0, 100 );
	$GLOBALS['gwc_vt_signup_email']  = sanitize_email( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	if ( GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
		gwc_vt_signup_result( 'unavailable', $started );
		return;
	}

	/* A cap on one submission. Safe to report because it depends only on what the
	 * visitor posted, not on anything the site knows about them. */
	if ( count( $wanted ) > gwc_vt_event_signup_limit() ) {
		gwc_vt_signup_result( 'too-many', $started );
		return;
	}

	/* Only slots belonging to THIS event, so a crafted post cannot reach across
	 * to another event's grid or to a standalone shift. */
	$mine = array();

	foreach ( $wanted as $shift_id ) {
		if ( gwc_vt_event_for_shift( $shift_id ) === $event_id ) {
			$mine[] = $shift_id;
		}
	}

	if ( ! $mine || '' === $GLOBALS['gwc_vt_signup_name'] || '' === $GLOBALS['gwc_vt_signup_email'] || ! is_email( $GLOBALS['gwc_vt_signup_email'] ) ) {
		gwc_vt_signup_result( 'incomplete', $started );
		return;
	}

	if ( empty( $posted['gwc_vt_clash_ok'] ) ) {
		$pair = gwc_vt_first_overlapping_pair( $mine );

		if ( $pair ) {
			$GLOBALS['gwc_vt_signup_clash'] = $pair;
			gwc_vt_signup_result( 'clash', $started );
			return;
		}
	}

	/* The shared guards, and they run HERE rather than at the top. Everything
	 * above this line is derived from the request; everything below it can
	 * depend on the database. That ordering is this form's own and is the
	 * reason gwc_vt_form_guard() is a call rather than a prelude. */
	$refusal = gwc_vt_form_guard( $posted );

	if ( null !== $refusal ) {
		gwc_vt_signup_result( $refusal, $started );
		return;
	}

	if ( ! gwc_vt_form_code_ok( $posted, 'signup_code' ) ) {
		gwc_vt_signup_result( 'bad-code', $started );
		return;
	}

	$open = array();

	foreach ( $mine as $shift_id ) {
		if ( gwc_vt_shift_is_signup_visible( $shift_id ) ) {
			$open[] = $shift_id;
		}
	}

	/* Any slot that closed between the page rendering and the button being
	 * pressed sends them back to the refreshed grid — with no count, and without
	 * saying which. Their list was out of date; enumerating why would answer
	 * questions about slots they cannot see. */
	if ( count( $open ) !== count( $mine ) ) {
		gwc_vt_signup_result( 'unavailable', $started );
		return;
	}

	/* ── The credential gate, over the whole selection at once ───────────
	 * Never slot by slot, and that is the important part on this handler.
	 *
	 * The comment at the top of this function explains why the response may not
	 * tell the selected slots apart: doing so would say which of them the typed
	 * address was already on. The same reasoning applies here, so a refusal
	 * names the CREDENTIALS and never the times — one union across everything
	 * they picked, one answer.
	 *
	 * The not-signed-in answer is a fact about the slots, which are public. */
	$volunteer_id = gwc_vt_signed_in_volunteer();

	if ( $volunteer_id < 1 ) {
		foreach ( $open as $shift_id ) {
			if ( gwc_vt_shift_needs_signin( (int) $shift_id ) ) {
				gwc_vt_signup_result( 'needs-signin', $started );
				return;
			}
		}
	} else {
		$refused = array();

		foreach ( $open as $shift_id ) {
			$refused = array_merge( $refused, gwc_vt_signup_credential_refusal( $volunteer_id, (int) $shift_id ) );
		}

		if ( $refused ) {
			gwc_vt_signup_result(
				'credential-missing',
				$started,
				array( 'credentials' => gwc_vt_credential_names( array_values( array_unique( $refused ) ) ) )
			);
			return;
		}
	}

	/* Counted once. It is one person pressing one button, whatever they selected —
	 * and counted before it is reported, so a refused attempt still counts. */
	if ( gwc_vt_rate_limited( gwc_vt_client_ip(), $GLOBALS['gwc_vt_signup_email'] ) ) {
		gwc_vt_signup_result( 'accepted', $started );
		return;
	}

	$made = array();

	foreach ( $open as $shift_id ) {
		$signup_id = gwc_vt_add_signup(
			$shift_id,
			$volunteer_id > 0
				? array(
					'volunteer_id' => $volunteer_id,
					'source'       => 'self',
				)
				: array(
					'claim_name'  => $GLOBALS['gwc_vt_signup_name'],
					'claim_email' => $GLOBALS['gwc_vt_signup_email'],
					'source'      => 'self',
				)
		);

		if ( $signup_id > 0 ) {
			$made[] = $signup_id;
		}
	}

	if ( $made ) {
		gwc_vt_queue_event_confirmation( $event_id, $made );
	}

	/* Every slot accepted, one slot accepted, a honeypot hit and a rate-limited
	 * attempt all end here, on the same string. */
	$GLOBALS['gwc_vt_signup_picked'] = array();
	gwc_vt_signup_result( 'accepted', $started );
}

/**
 * How many slots one submission may take.
 *
 * @return int
 */
function gwc_vt_event_signup_limit(): int {
	/**
	 * The most slots one person may take in a single submission.
	 *
	 * @param int $limit Generous for any real event.
	 */
	return max( 1, (int) apply_filters( 'gwc_vt_event_signup_limit', 20 ) );
}

/**
 * Withdraw a signup from the link in somebody's confirmation.
 */
function gwc_vt_handle_public_cancel(): void {
	$started = microtime( true );

	$nonce = isset( $_POST['gwc_vt_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_cancel_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'gwc_vt_cancel_signup' ) ) {
		gwc_vt_signup_result( 'expired', $started );
		return;
	}

	$posted = wp_unslash( $_POST );

	$signup_id = absint( $posted['gwc_vt_signup'] ?? 0 );
	$token     = sanitize_text_field( (string) ( $posted['gwc_vt_k'] ?? '' ) );

	/* A bad token and a signup that no longer exists give the same answer. The
	 * token is the only thing that authorizes this, so distinguishing them would
	 * turn the URL into a way to ask whether a given signup ID is real. */
	if ( ! gwc_vt_signup_token_valid( $signup_id, $token ) ) {
		gwc_vt_signup_result( 'cancel-unknown', $started );
		return;
	}

	gwc_vt_withdraw_signup( $signup_id );

	gwc_vt_signup_result( 'cancelled', $started );
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
function gwc_vt_shift_is_signup_visible( int $shift_id ): bool {
	if ( $shift_id < 1 || GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return false;
	}

	if ( ! gwc_vt_shift_is_open( $shift_id ) ) {
		return false;
	}

	$starts = gwc_vt_shift_starts( $shift_id );

	if ( null === $starts ) {
		return false;
	}

	$event_id = gwc_vt_event_for_shift( $shift_id );

	if ( $event_id > 0 ) {
		return 'publish' === get_post_status( $event_id );
	}

	$horizon = max( 1, (int) gwc_vt_setting( 'signup_horizon_days' ) );

	return $starts->getTimestamp() <= ( time() + ( $horizon * DAY_IN_SECONDS ) );
}

/**
 * The shifts a visitor may see, soonest first.
 *
 * @return int[]
 */
function gwc_vt_public_shift_ids(): array {
	$horizon = max( 1, (int) gwc_vt_setting( 'signup_horizon_days' ) );

	/* Standalone shifts only. An event's slots belong on their event's own page,
	 * where the grid says which role each one is and what the day is; loose on
	 * this list they are half a dozen unexplained times at the same address.
	 *
	 * This is a filter on the LIST, not on what may be signed up for — an event
	 * slot is perfectly signup-able, just from the event's page. The handler
	 * still asks gwc_vt_shift_is_signup_visible(), which knows the difference. */
	$ids = gwc_vt_shifts_between(
		array(
			'from'   => gwc_vt_today(),
			'to'     => gmdate( 'Y-m-d', time() + ( ( $horizon + 1 ) * DAY_IN_SECONDS ) ),
			'limit'  => 100,
			'parent' => 0,
		)
	);

	$visible = array();

	foreach ( $ids as $shift_id ) {
		if ( gwc_vt_shift_is_signup_visible( $shift_id ) ) {
			$visible[] = $shift_id;
		}
	}

	/**
	 * The shifts shown on the public list.
	 *
	 * Filtered rather than fixed so a site can hide a shift from the public list
	 * while keeping it on the schedule. Anything removed here is also refused by
	 * the handler, because both read gwc_vt_shift_is_signup_visible().
	 *
	 * @param int[] $visible Shift post IDs.
	 */
	return (array) apply_filters( 'gwc_vt_schedule_visible_shifts', $visible );
}

/* ── Telling the visitor what happened ───────────────────────────────────── */

/**
 * Record the outcome for the page to render, with a floor on how fast we answer.
 *
 * The floor exists because "refused instantly" and "accepted after a database
 * write" are distinguishable by a stopwatch. Belt and braces rather than the
 * main defense, and it costs a few milliseconds on a form used a handful of
 * times a day.
 *
 * @param string $result  What to tell them.
 * @param float  $started microtime when the handler began.
 * @param array  $retry   What they sent, to hand back to the form. Ignored for
 *                        'accepted' — see the note below the assignment.
 */
function gwc_vt_signup_result( string $result, float $started, array $retry = array() ): void {
	gwc_vt_form_settle( $started );

	$GLOBALS['gwc_vt_signup_result'] = $result;

	/* Never for 'accepted', and enforced here rather than trusted at the call
	 * sites. Four paths end with that key — the honeypot, a stamp that came back
	 * too fast, the rate limiter and a real signup — and hard rule 3 is that a
	 * visitor cannot tell which of the four happened to them. A form that came
	 * back filled in after one and empty after another is exactly the oracle the
	 * rule exists to prevent, and it would be one line of somebody's tidy-up
	 * away if this were a convention instead of a check. */
	$GLOBALS['gwc_vt_signup_retry'] = ( 'accepted' === $result ) ? array() : $retry;
}

/**
 * What the visitor sent, to hand back to the form after a refusal.
 *
 * @return array
 */
function gwc_vt_signup_retry(): array {
	return (array) ( $GLOBALS['gwc_vt_signup_retry'] ?? array() );
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
function gwc_vt_signup_message( string $result ): string {
	$messages = array(
		'accepted'       => __( 'Thank you — you are signed up. We have sent the details to your email address, along with a link you can use if you need to cancel.', 'groundwork-common-volunteer-tracker' ),
		'incomplete'     => __( 'Please choose a shift and give your name and email address.', 'groundwork-common-volunteer-tracker' ),
		'unavailable'    => __( 'That shift is no longer taking signups. The list below is up to date — please pick another.', 'groundwork-common-volunteer-tracker' ),
		'bad-code'       => __( 'That code was not recognized. Check the code you were given and try again.', 'groundwork-common-volunteer-tracker' ),
		'expired'        => __( 'This page had been open too long to submit safely. Please try again.', 'groundwork-common-volunteer-tracker' ),
		'cancelled'      => __( 'You have been taken off that shift. Thank you for letting us know.', 'groundwork-common-volunteer-tracker' ),
		'cancel-unknown' => __( 'That link is no longer valid. It may have already been used, or the shift may have changed. Please get in touch if you need to cancel.', 'groundwork-common-volunteer-tracker' ),

		/* Both of these are safe to say because both depend only on what the
		 * visitor just posted — how many checkboxes they selected, and whether two of
		 * those boxes are at the same time. Neither reports anything the site
		 * knows about the address they typed. Nothing else may be added here
		 * without that being true of it as well. */
		'too-many'       => __( 'That is more times than we can take in one go. Please pick a few, send them, and come back for the rest.', 'groundwork-common-volunteer-tracker' ),
		'clash'          => __( 'Two of the times you picked overlap. Change one, or select the checkbox below to say you meant both.', 'groundwork-common-volunteer-tracker' ),

		/* Safe by the same test, and worth spelling out because this one looks
		 * least like it. It depends on the SHIFT, not on the address typed
		 * above it: every visitor sees the same sentence for the same shift,
		 * whether or not they have ever volunteered here. Nothing the site
		 * knows about a person reaches it. */
		'needs-signin'   => __( 'This shift is only open to volunteers who have signed in, because of what it asks people to hold. Sign in with your email address and then come back.', 'groundwork-common-volunteer-tracker' ),
	);

	/* Named separately because it carries what is missing, and because it is
	 * the one message here that IS about the person reading it — which is only
	 * safe because they got here by clicking a link in their own mailbox. */
	if ( 'credential-missing' === $result ) {
		$short = (string) ( gwc_vt_signup_retry()['credentials'] ?? '' );

		return '' !== $short
			? sprintf(
				/* translators: %s: a list of things they need, already joined with commas. */
				__( 'This shift needs %s, and we have no current record of yours. Please get in touch — if you have it already, we may just not have it on file yet.', 'groundwork-common-volunteer-tracker' ),
				$short
			)
			: __( 'This shift asks for something we have no current record of for you. Please get in touch.', 'groundwork-common-volunteer-tracker' );
	}

	return $messages[ $result ] ?? '';
}

/**
 * What went wrong with one field, in a sentence of its own.
 *
 * One sentence per problem, rather than a template with the field name slotted
 * into it. A slot reads well in English and badly almost everywhere else, and
 * these are the sentences a volunteer reads at the moment they are already
 * annoyed.
 *
 * Safe to be this specific for the reason given above about 'too-many' and
 * 'clash': every one of them is a fact about what was just posted. None of them
 * reports anything the site knows about the address — that is hard rule 4, and
 * it is why 'email' means "you did not give one" and can never come to mean
 * "we do not have that one".
 *
 * @param string $field One of 'shift', 'name', 'email', 'email-format', 'code'.
 * @return string
 */
function gwc_vt_signup_field_message( string $field ): string {
	$messages = array(
		'shift'        => __( 'Please choose a shift.', 'groundwork-common-volunteer-tracker' ),
		'name'         => __( 'Please give your name.', 'groundwork-common-volunteer-tracker' ),
		'email'        => __( 'Please give your email address.', 'groundwork-common-volunteer-tracker' ),
		'email-format' => __( 'That does not look like an email address. Check it and try again.', 'groundwork-common-volunteer-tracker' ),
		'code'         => __( 'That code was not recognized. Check the code you were given.', 'groundwork-common-volunteer-tracker' ),
	);

	return $messages[ $field ] ?? '';
}

/**
 * The sentence at the top of a refused form.
 *
 * Composed from the fields that actually failed, so a missing address says so
 * and does not also ask for a name that was given. Falls back to the general
 * message for the result when there is no field list — a crafted post can reach
 * a refusal without one, and a blank notice would be worse than a broad one.
 *
 * Joined with a space rather than built into one clause. Each sentence is
 * independently translatable and reads correctly on its own, which a list
 * stitched together with commas and an "and" does not in every language.
 *
 * @param string   $result  Result key.
 * @param string[] $invalid Which fields were the problem.
 * @return string
 */
function gwc_vt_signup_summary( string $result, array $invalid = array() ): string {
	$parts = array();

	foreach ( $invalid as $field ) {
		$sentence = gwc_vt_signup_field_message( (string) $field );

		if ( '' !== $sentence ) {
			$parts[] = $sentence;
		}
	}

	return $parts ? implode( ' ', $parts ) : gwc_vt_signup_message( $result );
}

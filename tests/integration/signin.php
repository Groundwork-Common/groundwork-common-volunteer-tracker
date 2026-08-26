<?php
/**
 * Signing in, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * tests/SignInTest.php covers the gate, the wording and the token's shape.
 * Everything here needs real posts: that a link is minted for an address on
 * file and for no other, that following it does not spend it, that spending it
 * works exactly once, that a session resolves to one volunteer and stops when
 * the record does, and that the mail leaves on `shutdown` rather than inline.
 *
 * ── The property that matters most ───────────────────────────────────────────
 * Asking for a link must answer the same way whatever the address turns out to
 * be. That is hard rule 3's reasoning applied to a third public surface, and it
 * is asserted here by driving the real handler with a known address and an
 * unknown one and comparing what came back — not by reading the message table,
 * which tests/SignInTest.php already does.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/signin.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a
 * top-level assignment is a local while `global` in a helper reaches the real
 * one. See the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_si_made']  = array();
$GLOBALS['gwc_vt_si_post']  = $_POST;
$GLOBALS['gwc_vt_si_opts']  = get_option( GWC_VT_SETTINGS_OPTION, array() );
$GLOBALS['gwc_vt_si_mail']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_si_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Point the settings at a state.
 *
 * @param array $changes Settings to overlay.
 */
function gwc_vt_si_settings( array $changes ): void {
	update_option( GWC_VT_SETTINGS_OPTION, array_merge( $GLOBALS['gwc_vt_si_opts'], $changes ) );
	gwc_vt_settings_cache( null, true );
}

/**
 * Ask for a link the way a browser would, and report what came back.
 *
 * @param string $email What was typed.
 * @return string The result key.
 */
function gwc_vt_si_ask( string $email ): string {
	unset( $GLOBALS['gwc_vt_signin_result'] );

	$_POST = array(
		'gwc_vt_signin_nonce'  => wp_create_nonce( 'gwc_vt_signin' ),
		/* Aged past the minimum a person takes, built the way the form builds
		 * it, so an ordinary submission is not read as a script. */
		'gwc_vt_t'             => ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwc_vt_form' ) ),
		'gwc_vt_website'       => '',
		'gwc_vt_email'         => $email,
		'gwc_vt_signin_submit' => '1',
	);

	gwc_vt_handle_signin_request();

	$_POST = array();

	return (string) ( $GLOBALS['gwc_vt_signin_result'] ?? '' );
}

/**
 * How many sign-in links are sitting on the shutdown queue.
 *
 * @return int
 */
function gwc_vt_si_queued(): int {
	$n = 0;

	foreach ( (array) ( $GLOBALS['gwc_vt_pending_mail'] ?? array() ) as $item ) {
		if ( 'signin' === ( $item['kind'] ?? '' ) ) {
			++$n;
		}
	}

	return $n;
}

wp_set_current_user( 1 );

/* The limiter is one option shared by every public form on this site, so a
 * script that posts to one spends everybody's budget. Cleared at both ends —
 * see the long note in tests/integration/registration.php. */
delete_option( GWC_VT_RATE_LIMIT_OPTION );

$GLOBALS['gwc_vt_si_page'] = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzsi sign in',
		'post_content' => '[gwc_vt_volunteer_signin]',
	)
);

$GLOBALS['gwc_vt_si_made'][] = $GLOBALS['gwc_vt_si_page'];

$GLOBALS['gwc_vt_si_who'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzsi Known Person',
	)
);

$GLOBALS['gwc_vt_si_made'][] = $GLOBALS['gwc_vt_si_who'];

update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_VOLUNTEER_EMAIL, 'zzsi-known@example.test' );

gwc_vt_si_settings(
	array(
		'signin_enabled' => true,
		'signin_page'    => $GLOBALS['gwc_vt_si_page'],
	)
);

/* ── One answer, whatever the address turns out to be ────────────────────── */

$GLOBALS['gwc_vt_pending_mail'] = array();

$GLOBALS['gwc_vt_si_a'] = gwc_vt_si_ask( 'zzsi-known@example.test' );
$GLOBALS['gwc_vt_si_queued_known'] = gwc_vt_si_queued();

$GLOBALS['gwc_vt_pending_mail'] = array();

$GLOBALS['gwc_vt_si_b'] = gwc_vt_si_ask( 'zzsi-nobody-at-all@example.test' );
$GLOBALS['gwc_vt_si_queued_unknown'] = gwc_vt_si_queued();

gwc_vt_si_check(
	'an address on file and one nobody has are answered identically',
	$GLOBALS['gwc_vt_si_a'] === $GLOBALS['gwc_vt_si_b'] && 'sent' === $GLOBALS['gwc_vt_si_a'],
	'known said "' . $GLOBALS['gwc_vt_si_a'] . '", unknown said "' . $GLOBALS['gwc_vt_si_b'] . '"'
);

gwc_vt_si_check(
	'and the sentence a visitor reads is the same string',
	gwc_vt_signin_message( $GLOBALS['gwc_vt_si_a'] ) === gwc_vt_signin_message( $GLOBALS['gwc_vt_si_b'] ),
	'the two read differently'
);

/* The difference is invisible to the visitor and real underneath: one link, and
 * only for the address that matched. */
gwc_vt_si_check(
	'a link is queued for the address on file',
	1 === $GLOBALS['gwc_vt_si_queued_known'],
	'it queued ' . $GLOBALS['gwc_vt_si_queued_known']
);

gwc_vt_si_check(
	'and none at all for the address nobody has',
	0 === $GLOBALS['gwc_vt_si_queued_unknown'],
	'it queued ' . $GLOBALS['gwc_vt_si_queued_unknown'] . ' for a stranger'
);

/* ── The mail is queued, not sent inline ─────────────────────────────────────
 * This is the timing defence, and it is not decoration. Minting a token is two
 * meta writes; SENDING is a call to a mail server, which on SMTP is a second or
 * more. Sent inline, the address that matched would answer measurably slower
 * than the one that did not, every time — and one identical sentence would be
 * undone by a stopwatch.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_pending_mail'] = array();
$GLOBALS['gwc_vt_si_sent']      = 0;

add_filter(
	'pre_wp_mail',
	static function ( $short ) {
		++$GLOBALS['gwc_vt_si_sent'];
		return true;
	},
	9
);

gwc_vt_si_ask( 'zzsi-known@example.test' );

gwc_vt_si_check(
	'asking for a link sends nothing during the request',
	0 === $GLOBALS['gwc_vt_si_sent'] && 1 === gwc_vt_si_queued(),
	$GLOBALS['gwc_vt_si_sent'] . ' message(s) went out inline'
);

gwc_vt_send_queued_confirmations();

gwc_vt_si_check(
	'and it goes out when the queue is flushed',
	1 === $GLOBALS['gwc_vt_si_sent'],
	'the flush sent ' . $GLOBALS['gwc_vt_si_sent']
);

/* ── Two records on one address ──────────────────────────────────────────── */

$GLOBALS['gwc_vt_si_twin'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzsi Known Person Again',
	)
);

$GLOBALS['gwc_vt_si_made'][] = $GLOBALS['gwc_vt_si_twin'];

update_post_meta( $GLOBALS['gwc_vt_si_twin'], GWC_VT_VOLUNTEER_EMAIL, 'zzsi-known@example.test' );

gwc_vt_clear_signin_token( $GLOBALS['gwc_vt_si_who'] );

$GLOBALS['gwc_vt_pending_mail'] = array();

$GLOBALS['gwc_vt_si_dup'] = gwc_vt_si_ask( 'zzsi-known@example.test' );

/* Signing somebody into whichever record the query happened to return first
 * would attach their hours to a record that may not be theirs. Refused — and
 * refused with the same sentence, so the ambiguity is not announced either. */
gwc_vt_si_check(
	'an address on two records mints nothing, and says so to nobody',
	'sent' === $GLOBALS['gwc_vt_si_dup'] && 0 === gwc_vt_si_queued()
		&& '' === (string) get_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_TOKEN, true ),
	'result "' . $GLOBALS['gwc_vt_si_dup'] . '", queued ' . gwc_vt_si_queued()
);

wp_delete_post( $GLOBALS['gwc_vt_si_twin'], true );

/* ── Following a link does not spend it ──────────────────────────────────────
 * Mail scanners and link-preview bots fetch every URL in a message on delivery.
 * A token spent on GET is one the volunteer finds already used, and they cannot
 * tell that from an attack.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_si_token'] = wp_generate_password( 32, false );

update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_TOKEN, gwc_vt_hash_signin_token( $GLOBALS['gwc_vt_si_token'] ) );
update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_EXPIRES, gmdate( 'Y-m-d H:i:s', time() + GWC_VT_SIGNIN_TOKEN_TTL ) );

gwc_vt_si_check(
	'a fresh token validates',
	gwc_vt_signin_token_valid( $GLOBALS['gwc_vt_si_who'], $GLOBALS['gwc_vt_si_token'] ),
	'it did not'
);

gwc_vt_si_check(
	'and asking twice does not consume it',
	gwc_vt_signin_token_valid( $GLOBALS['gwc_vt_si_who'], $GLOBALS['gwc_vt_si_token'] )
		&& '' !== (string) get_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_TOKEN, true ),
	'reading the token spent it'
);

gwc_vt_si_check(
	'a token for the wrong volunteer does not validate',
	! gwc_vt_signin_token_valid( $GLOBALS['gwc_vt_si_page'], $GLOBALS['gwc_vt_si_token'] ),
	'a token opened a post that is not a volunteer'
);

/* Expiry is checked against what is stored, so nothing in the URL carries it
 * and nothing in the URL can move it. */
update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_EXPIRES, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

gwc_vt_si_check(
	'an expired token does not validate',
	! gwc_vt_signin_token_valid( $GLOBALS['gwc_vt_si_who'], $GLOBALS['gwc_vt_si_token'] ),
	'a stale link still worked'
);

update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_SIGNIN_EXPIRES, gmdate( 'Y-m-d H:i:s', time() + GWC_VT_SIGNIN_TOKEN_TTL ) );

/* ── Spending it, exactly once ───────────────────────────────────────────── */

gwc_vt_clear_signin_token( $GLOBALS['gwc_vt_si_who'] );

gwc_vt_si_check(
	'clearing a token stops it working',
	! gwc_vt_signin_token_valid( $GLOBALS['gwc_vt_si_who'], $GLOBALS['gwc_vt_si_token'] ),
	'a cleared token still validated'
);

/* ── The session ─────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_si_session'] = gwc_vt_start_signin_session( $GLOBALS['gwc_vt_si_who'] );

gwc_vt_si_check(
	'a session resolves to the volunteer it was started for',
	gwc_vt_signed_in_volunteer() === $GLOBALS['gwc_vt_si_who'],
	'it resolved to ' . gwc_vt_signed_in_volunteer()
);

gwc_vt_si_check(
	'and the cookie carries no identity of its own',
	false === strpos( $GLOBALS['gwc_vt_si_session'], (string) $GLOBALS['gwc_vt_si_who'] ),
	'the session id has the volunteer id in it: ' . $GLOBALS['gwc_vt_si_session']
);

/* A session pointing at a record that has been anonymized, erased or swept is
 * not a session. Checked by deleting the post out from under it. */
$GLOBALS['gwc_vt_si_ghost'] = (int) wp_insert_post(
	array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzsi Ghost' )
);

$GLOBALS['gwc_vt_si_ghost_session'] = gwc_vt_start_signin_session( $GLOBALS['gwc_vt_si_ghost'] );

wp_delete_post( $GLOBALS['gwc_vt_si_ghost'], true );

gwc_vt_si_check(
	'a session outliving its volunteer resolves to nobody',
	0 === gwc_vt_signed_in_volunteer(),
	'it still resolved to ' . gwc_vt_signed_in_volunteer()
);

/* Put the real session back for the last checks. */
$GLOBALS['gwc_vt_si_session'] = gwc_vt_start_signin_session( $GLOBALS['gwc_vt_si_who'] );

gwc_vt_si_check(
	'switching the feature off ends the session',
	( function () {
		gwc_vt_si_settings( array( 'signin_enabled' => false ) );

		$out = gwc_vt_signed_in_volunteer();

		gwc_vt_si_settings(
			array(
				'signin_enabled' => true,
				'signin_page'    => $GLOBALS['gwc_vt_si_page'],
			)
		);

		return 0 === $out;
	} )(),
	'somebody stayed signed in through the switch being turned off'
);

/* ── What the page draws ─────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_si_panel'] = gwc_vt_render_signin();

gwc_vt_si_check(
	'a signed-in volunteer sees their own name and hours',
	false !== strpos( $GLOBALS['gwc_vt_si_panel'], 'Zzsi Known Person' )
		&& false !== strpos( $GLOBALS['gwc_vt_si_panel'], 'verified so far' ),
	'the panel did not render'
);

/* The one thing deliberately kept off this page. inc/volunteer-cpt.php argues
 * that what a court ordered is a fact about somebody else's document, and the
 * plugin keeps it off every outward-facing surface; this is a new one. */
update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_VOLUNTEER_REQUIRED, 2400 );
update_post_meta( $GLOBALS['gwc_vt_si_who'], GWC_VT_VOLUNTEER_REQUIRED_FOR, 'Zzsi Municipal Court' );

gwc_vt_si_check(
	'and never their court-ordered hours',
	false === strpos( gwc_vt_render_signin(), 'Zzsi Municipal Court' )
		&& false === strpos( gwc_vt_render_signin(), '40 of' ),
	'the sign-in page printed what a court required of somebody'
);

gwc_vt_end_signin_session();

gwc_vt_si_check(
	'signing out ends it',
	0 === gwc_vt_signed_in_volunteer(),
	'still signed in after signing out'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

$_POST = $GLOBALS['gwc_vt_si_post'];

update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_si_opts'] );
gwc_vt_settings_cache( null, true );

$GLOBALS['gwc_vt_pending_mail'] = array();

delete_option( GWC_VT_RATE_LIMIT_OPTION );

foreach ( get_posts( array( 'post_type' => array( 'page', GWC_VT_VOLUNTEER_TYPE ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'Zzsi' ) ) as $gwc_vt_si_post_obj ) {
	$GLOBALS['gwc_vt_si_made'][] = (int) $gwc_vt_si_post_obj->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_si_made'] ) as $gwc_vt_si_id ) {
	wp_delete_post( (int) $gwc_vt_si_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

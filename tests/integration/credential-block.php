<?php
/**
 * Blocking credentials, end to end.
 *
 * ── The two design decisions this file exists to hold in place ───────────────
 * The check is NOT inside gwc_vt_add_signup(), and settling and promoting are
 * NOT gated. Both are easy to "fix" later by somebody who reads the block as
 * incomplete, so both are asserted here with the reasoning attached — §3 and §4
 * fail if either is tightened.
 *
 * ── And the property that must not regress ───────────────────────────────────
 * The public form must not become an oracle. §5 posts the same submission for
 * the same shift from an anonymous visitor twice, once naming an address that
 * belongs to a volunteer and once naming one that does not, and compares what
 * comes back byte for byte. Hard rules 3 and 4.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/credential-block.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_cb_made']  = array();
$GLOBALS['gwc_vt_cb_post']  = $_POST;
$GLOBALS['gwc_vt_cb_opts']  = get_option( GWC_VT_SETTINGS_OPTION, array() );

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_cb_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * A credential.
 *
 * @param string $name What it is.
 * @param string $mode 'block' or 'report'.
 * @return int
 */
function gwc_vt_cb_credential( string $name, string $mode ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_CREDENTIAL_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_CREDENTIAL_MODE, $mode );
	update_post_meta( $id, GWC_VT_CREDENTIAL_MONTHS, 0 );

	$GLOBALS['gwc_vt_cb_made'][] = $id;

	return $id;
}

/**
 * A volunteer.
 *
 * @param string $name  Their name.
 * @param string $email Their address.
 * @return int
 */
function gwc_vt_cb_volunteer( string $name, string $email ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );

	$GLOBALS['gwc_vt_cb_made'][] = $id;

	return $id;
}

/**
 * A shift, open for signups.
 *
 * @param string $offset A strtotime modifier from today.
 * @param int    $max    Room for how many.
 * @return int
 */
function gwc_vt_cb_shift( string $offset, int $max = 0 ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'Zzcb shift',
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' ' . $offset ) ) );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $id, GWC_VT_SHIFT_MAX, $max );

	$GLOBALS['gwc_vt_cb_made'][] = $id;

	return $id;
}

wp_set_current_user( 1 );

$gwc_vt_cb_waiver = gwc_vt_cb_credential( 'Zzcb waiver', 'block' );
$gwc_vt_cb_food   = gwc_vt_cb_credential( 'Zzcb food card', 'report' );

$gwc_vt_cb_ada  = gwc_vt_cb_volunteer( 'Zzcb Ada Petrov', 'zzcb-ada@example.com' );
$gwc_vt_cb_bo   = gwc_vt_cb_volunteer( 'Zzcb Bo Nakamura', 'zzcb-bo@example.com' );

gwc_vt_record_credential( $gwc_vt_cb_bo, $gwc_vt_cb_waiver, gwc_vt_today() );

$gwc_vt_cb_gated = gwc_vt_cb_shift( '+7 days' );
gwc_vt_set_shift_credentials( $gwc_vt_cb_gated, array( $gwc_vt_cb_waiver ) );

echo "\n── 1. What is refused, and what is only reported ────────────────\n";

gwc_vt_cb_check(
	'somebody without a blocking credential is refused',
	array( $gwc_vt_cb_waiver ) === gwc_vt_signup_credential_refusal( $gwc_vt_cb_ada, $gwc_vt_cb_gated ),
	implode( ',', gwc_vt_signup_credential_refusal( $gwc_vt_cb_ada, $gwc_vt_cb_gated ) )
);

gwc_vt_cb_check(
	'somebody holding it is not',
	array() === gwc_vt_signup_credential_refusal( $gwc_vt_cb_bo, $gwc_vt_cb_gated ),
	implode( ',', gwc_vt_signup_credential_refusal( $gwc_vt_cb_bo, $gwc_vt_cb_gated ) )
);

/* The entire difference between the two modes. A reporting credential is said
 * on the roster and never refuses anybody — collapsing this would make the
 * setting a lie. */
$gwc_vt_cb_reporting = gwc_vt_cb_shift( '+8 days' );
gwc_vt_set_shift_credentials( $gwc_vt_cb_reporting, array( $gwc_vt_cb_food ) );

gwc_vt_cb_check(
	'a reporting credential never refuses anybody',
	array() === gwc_vt_signup_credential_refusal( $gwc_vt_cb_ada, $gwc_vt_cb_reporting ),
	implode( ',', gwc_vt_signup_credential_refusal( $gwc_vt_cb_ada, $gwc_vt_cb_reporting ) )
);

gwc_vt_cb_check(
	'but it is still reported as missing',
	array( $gwc_vt_cb_food ) === gwc_vt_missing_credentials( $gwc_vt_cb_ada, $gwc_vt_cb_reporting )['report'],
	implode( ',', gwc_vt_missing_credentials( $gwc_vt_cb_ada, $gwc_vt_cb_reporting )['report'] )
);

echo "\n── 2. gwc_vt_add_signup() is deliberately NOT the gate ──────────\n";

/* If a future change moves the check inside gwc_vt_add_signup(), this fails —
 * and it should, because that function is called by the reconciler, the seed
 * and half the fixtures, none of which are a person joining a shift. The check
 * belongs to the four handlers where a human is doing that. */
$gwc_vt_cb_direct = gwc_vt_add_signup(
	$gwc_vt_cb_gated,
	array(
		'volunteer_id' => $gwc_vt_cb_ada,
		'source'       => 'admin',
	)
);

if ( $gwc_vt_cb_direct > 0 ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_direct;
}

gwc_vt_cb_check(
	'the low-level writer still writes, uncredentialed',
	$gwc_vt_cb_direct > 0,
	(string) $gwc_vt_cb_direct
);

gwc_vt_withdraw_signup( (int) $gwc_vt_cb_direct );

echo "\n── 3. Settling is not gated either ──────────────────────────────\n";

/* A place opening at nine in the evening promotes whoever is next. Gating that
 * would fail silently on a cron pass, leaving a place empty and a person on a
 * list, with the only record in a log nobody reads. Both people below are
 * short of the waiver on purpose. */
$gwc_vt_cb_small = gwc_vt_cb_shift( '+9 days', 1 );
gwc_vt_set_shift_credentials( $gwc_vt_cb_small, array( $gwc_vt_cb_waiver ) );

$gwc_vt_cb_first = gwc_vt_add_signup( $gwc_vt_cb_small, array( 'volunteer_id' => $gwc_vt_cb_bo, 'source' => 'admin' ) );
$gwc_vt_cb_next  = gwc_vt_add_signup( $gwc_vt_cb_small, array( 'volunteer_id' => $gwc_vt_cb_ada, 'source' => 'admin' ) );

foreach ( array( $gwc_vt_cb_first, $gwc_vt_cb_next ) as $gwc_vt_cb_one ) {
	if ( $gwc_vt_cb_one > 0 ) {
		$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_one;
	}
}

gwc_vt_cb_check(
	'the second person went on the waiting list',
	GWC_VT_SIGNUP_WAITLIST === get_post_status( (int) $gwc_vt_cb_next ),
	(string) get_post_status( (int) $gwc_vt_cb_next )
);

gwc_vt_withdraw_signup( (int) $gwc_vt_cb_first );
gwc_vt_settle_signups( $gwc_vt_cb_small );

gwc_vt_cb_check(
	'settling promotes somebody short of a blocking credential',
	'publish' === get_post_status( (int) $gwc_vt_cb_next ),
	(string) get_post_status( (int) $gwc_vt_cb_next )
);

/* And the roster is where it is said. The place to catch this is a screen a
 * coordinator is looking at, not a cron pass at nine in the evening. */
ob_start();
gwc_vt_render_roster_credential_flag( (int) $gwc_vt_cb_next, $gwc_vt_cb_small );
$gwc_vt_cb_flag = (string) ob_get_clean();

gwc_vt_cb_check(
	'and the roster says they are short',
	false !== strpos( $gwc_vt_cb_flag, 'Zzcb waiver' ),
	'' === $gwc_vt_cb_flag ? 'nothing was drawn' : $gwc_vt_cb_flag
);

echo "\n── 4. The staff roster refuses, and can be overridden ───────────\n";

$GLOBALS['gwc_vt_cb_redirect'] = '';

/**
 * Catch the redirect instead of exiting.
 *
 * @param string $location Where it wanted to go.
 * @return string
 */
function gwc_vt_cb_catch_redirect( $location ) {
	$GLOBALS['gwc_vt_cb_redirect'] = (string) $location;

	throw new Exception( 'redirected' );
}

add_filter( 'wp_redirect', 'gwc_vt_cb_catch_redirect', 1 );

/**
 * Post to the roster-add handler and give back where it redirected.
 *
 * @param array $fields POST fields.
 * @return string
 */
function gwc_vt_cb_roster_add( array $fields ): string {
	$_POST                         = $fields;
	$_REQUEST                      = $fields;
	$GLOBALS['gwc_vt_cb_redirect'] = '';

	try {
		gwc_vt_handle_roster_add();
	} catch ( Exception $e ) {
		unset( $e );
	}

	return $GLOBALS['gwc_vt_cb_redirect'];
}

$gwc_vt_cb_target = gwc_vt_cb_shift( '+11 days' );
gwc_vt_set_shift_credentials( $gwc_vt_cb_target, array( $gwc_vt_cb_waiver ) );

$gwc_vt_cb_where = gwc_vt_cb_roster_add(
	array(
		'gwc_vt_shift'     => $gwc_vt_cb_target,
		'gwc_vt_volunteer' => $gwc_vt_cb_ada,
		'_wpnonce'         => wp_create_nonce( 'gwc_vt_roster_add_' . $gwc_vt_cb_target ),
	)
);

gwc_vt_cb_check(
	'the roster refuses somebody without it',
	false !== strpos( $gwc_vt_cb_where, 'credential-blocked' ),
	$gwc_vt_cb_where
);

gwc_vt_cb_check(
	'and nobody was put on the shift',
	array() === gwc_vt_shift_signup_ids( $gwc_vt_cb_target, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ),
	implode( ',', gwc_vt_shift_signup_ids( $gwc_vt_cb_target, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) )
);

/* An override with no reason must be the same as no override. A button somebody
 * can click without typing is a button they click without thinking. */
$gwc_vt_cb_where = gwc_vt_cb_roster_add(
	array(
		'gwc_vt_shift'           => $gwc_vt_cb_target,
		'gwc_vt_volunteer'       => $gwc_vt_cb_ada,
		'gwc_vt_override_reason' => '   ',
		'_wpnonce'               => wp_create_nonce( 'gwc_vt_roster_add_' . $gwc_vt_cb_target ),
	)
);

gwc_vt_cb_check(
	'an override with no reason is refused like any other',
	false !== strpos( $gwc_vt_cb_where, 'credential-blocked' ),
	$gwc_vt_cb_where
);

$gwc_vt_cb_where = gwc_vt_cb_roster_add(
	array(
		'gwc_vt_shift'           => $gwc_vt_cb_target,
		'gwc_vt_volunteer'       => $gwc_vt_cb_ada,
		'gwc_vt_override_reason' => 'Certificate seen, being entered Monday',
		'_wpnonce'               => wp_create_nonce( 'gwc_vt_roster_add_' . $gwc_vt_cb_target ),
	)
);

gwc_vt_cb_check(
	'an override with a reason gets them on',
	false !== strpos( $gwc_vt_cb_where, 'rostered-override' ),
	$gwc_vt_cb_where
);

$gwc_vt_cb_on = gwc_vt_shift_signup_ids( $gwc_vt_cb_target, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) );

foreach ( $gwc_vt_cb_on as $gwc_vt_cb_one ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_one;
}

gwc_vt_cb_check(
	'and they really are on it',
	1 === count( $gwc_vt_cb_on ),
	count( $gwc_vt_cb_on ) . ' on the roster'
);

$gwc_vt_cb_override = gwc_vt_signup_override( (int) ( $gwc_vt_cb_on[0] ?? 0 ) );

gwc_vt_cb_check(
	'the reason was recorded',
	'Certificate seen, being entered Monday' === $gwc_vt_cb_override['reason'],
	$gwc_vt_cb_override['reason']
);

gwc_vt_cb_check(
	'and who decided',
	1 === $gwc_vt_cb_override['by'],
	(string) $gwc_vt_cb_override['by']
);

/* The writer refuses an empty reason on its own, and that is asserted here
 * rather than only through the handler above — because the handler declines
 * first, so the guard inside gwc_vt_record_override() is unreachable from it
 * and a sabotage of that guard passes the whole file. It is not dead code:
 * it is a public function, the second of two write paths, and the thing that
 * makes "an override always has a reason" true of the data rather than true of
 * one screen. */
$gwc_vt_cb_spare = gwc_vt_add_signup( $gwc_vt_cb_reporting, array( 'volunteer_id' => $gwc_vt_cb_ada, 'source' => 'admin' ) );

if ( $gwc_vt_cb_spare > 0 ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_spare;
}

gwc_vt_cb_check(
	'the writer itself refuses an override with no reason',
	! gwc_vt_record_override( (int) $gwc_vt_cb_spare, 1, '   ' )
);

gwc_vt_cb_check(
	'and wrote neither half of the pair',
	'' === (string) get_post_meta( (int) $gwc_vt_cb_spare, GWC_VT_SIGNUP_OVERRIDE_BY, true )
		&& '' === (string) get_post_meta( (int) $gwc_vt_cb_spare, GWC_VT_SIGNUP_OVERRIDE_REASON, true ),
	(string) get_post_meta( (int) $gwc_vt_cb_spare, GWC_VT_SIGNUP_OVERRIDE_BY, true )
);

/* And a row with a user but no reason is not an override, however it got there
 * — the reader keys off the reason for exactly this case. */
update_post_meta( (int) $gwc_vt_cb_spare, GWC_VT_SIGNUP_OVERRIDE_BY, 1 );

gwc_vt_cb_check(
	'half a pair in the database still is not an override',
	0 === gwc_vt_signup_override( (int) $gwc_vt_cb_spare )['by'],
	(string) gwc_vt_signup_override( (int) $gwc_vt_cb_spare )['by']
);

delete_post_meta( (int) $gwc_vt_cb_spare, GWC_VT_SIGNUP_OVERRIDE_BY );

/* A block somebody can click past without a trace is not a block. The roster
 * is where the trace has to be visible. */
ob_start();
gwc_vt_render_roster_credential_flag( (int) $gwc_vt_cb_on[0], $gwc_vt_cb_target );
$gwc_vt_cb_flag = (string) ob_get_clean();

gwc_vt_cb_check(
	'the roster says who overrode it and why',
	false !== strpos( $gwc_vt_cb_flag, 'Certificate seen' ),
	'' === $gwc_vt_cb_flag ? 'nothing was drawn' : $gwc_vt_cb_flag
);

echo "\n── 5. The public form is still not an oracle ────────────────────\n";

update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge(
		$GLOBALS['gwc_vt_cb_opts'],
		array(
			'signup_enabled' => 1,
			'signin_enabled' => 1,
		)
	)
);
gwc_vt_settings_cache( null, true );

/**
 * Post to the public signup handler and give back everything it said.
 *
 * @param string $email The address to submit.
 * @param int    $shift The shift to ask for.
 * @return string
 */
function gwc_vt_cb_public( string $email, int $shift ): string {
	$GLOBALS['gwc_vt_signup_result'] = '';
	$GLOBALS['gwc_vt_signup_retry']  = array();

	$_POST = array(
		'gwc_vt_signup_submit' => '1',
		'gwc_vt_signup_nonce'  => wp_create_nonce( 'gwc_vt_signup' ),
		'gwc_vt_t'             => ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwc_vt_form' ) ),
		'gwc_vt_shift'         => (string) $shift,
		'gwc_vt_name'          => 'Zzcb Somebody',
		'gwc_vt_email'         => $email,
	);
	$_REQUEST = $_POST;

	gwc_vt_handle_public_signup();

	$result = (string) ( $GLOBALS['gwc_vt_signup_result'] ?? '' );
	$retry  = gwc_vt_signup_retry();

	/* The visitor's own typed name and address are removed before comparing,
	 * and that is not a weakening of the test — it is the whole question asked
	 * precisely. The oracle would be a response that varies with what the SITE
	 * knows about the address. What they typed is what they typed; handing it
	 * back so the form is still filled in tells them nothing they did not just
	 * type, and every refusal in this handler has always done it.
	 *
	 * Everything else — the outcome, the sentence, and which shift comes back
	 * selected — is the site's contribution, and must not differ. */
	unset( $retry['name'], $retry['email'] );

	return $result . '|' . gwc_vt_signup_message( $result ) . '|' . wp_json_encode( $retry );
}

/* An address on file and one that is not, for the same gated shift, from a
 * visitor who has not signed in. Byte-identical or this is an oracle. */
$gwc_vt_cb_known   = gwc_vt_cb_public( 'zzcb-ada@example.com', $gwc_vt_cb_gated );
$gwc_vt_cb_unknown = gwc_vt_cb_public( 'zzcb-nobody@example.com', $gwc_vt_cb_gated );

gwc_vt_cb_check(
	'a gated shift answers a known and an unknown address identically',
	$gwc_vt_cb_known === $gwc_vt_cb_unknown,
	$gwc_vt_cb_known . ' vs ' . $gwc_vt_cb_unknown
);

gwc_vt_cb_check(
	'and what it says is to sign in',
	false !== strpos( $gwc_vt_cb_known, 'needs-signin' ),
	$gwc_vt_cb_known
);

/* The same test on an ungated shift, where the answer is 'accepted' — so the
 * check above is not passing merely because both answers are refusals. */
$gwc_vt_cb_open = gwc_vt_cb_shift( '+13 days' );

$gwc_vt_cb_known   = gwc_vt_cb_public( 'zzcb-ada@example.com', $gwc_vt_cb_open );
$gwc_vt_cb_unknown = gwc_vt_cb_public( 'zzcb-nobody@example.com', $gwc_vt_cb_open );

gwc_vt_cb_check(
	'an ungated shift answers both identically too',
	$gwc_vt_cb_known === $gwc_vt_cb_unknown,
	$gwc_vt_cb_known . ' vs ' . $gwc_vt_cb_unknown
);

gwc_vt_cb_check(
	'and that answer is acceptance, not another refusal',
	false !== strpos( $gwc_vt_cb_known, 'accepted' ),
	$gwc_vt_cb_known
);

foreach ( gwc_vt_shift_signup_ids( $gwc_vt_cb_open, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $gwc_vt_cb_one ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_one;
}

echo "\n── 6. What the public list says about a gated shift ─────────────\n";

ob_start();
gwc_vt_render_shift_choice( $gwc_vt_cb_gated, 0 );
$gwc_vt_cb_row = (string) ob_get_clean();

gwc_vt_cb_check(
	'the row says so before anybody types anything',
	false !== strpos( $gwc_vt_cb_row, 'sign in to take this one' ),
	$gwc_vt_cb_row
);

ob_start();
gwc_vt_render_shift_choice( $gwc_vt_cb_open, 0 );
$gwc_vt_cb_row = (string) ob_get_clean();

gwc_vt_cb_check(
	'and an ungated shift does not',
	false === strpos( $gwc_vt_cb_row, 'sign in to take this one' )
);

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

remove_filter( 'wp_redirect', 'gwc_vt_cb_catch_redirect', 1 );

$_POST    = $GLOBALS['gwc_vt_cb_post'];
$_REQUEST = array();

update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_cb_opts'] );
gwc_vt_settings_cache( null, true );

/* Cleared at both ends. The limiter is one option shared by all three public
 * forms, so leaving it saturated breaks whatever runs next. */
delete_option( GWC_VT_RATE_LIMIT_OPTION );

foreach ( gwc_vt_credential_record_ids( $gwc_vt_cb_bo ) as $gwc_vt_cb_record ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_record;
}

foreach ( get_posts(
	array(
		'post_type'   => array( GWC_VT_CREDENTIAL_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_VOLUNTEER_TYPE, GWC_VT_SIGNUP_TYPE ),
		'post_status' => array_values( get_post_stati() ),
		'numberposts' => -1,
		's'           => 'Zzcb',
	)
) as $gwc_vt_cb_stray ) {
	$GLOBALS['gwc_vt_cb_made'][] = (int) $gwc_vt_cb_stray->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_cb_made'] ) as $gwc_vt_cb_id ) {
	wp_delete_post( (int) $gwc_vt_cb_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

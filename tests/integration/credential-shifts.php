<?php
/**
 * What a shift asks people to hold, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Every claim here is about meta rows on real posts: that a list survives as
 * many rows rather than one serialized blob, that an event's credentials reach
 * its slots without being copied into them, that a repeat writes them to every
 * occurrence rather than to the first, and that changing them mails nobody.
 *
 * ── The two that have already gone wrong in this codebase's shape ────────────
 * §3 drives the real save handler rather than calling
 * gwc_vt_set_shift_credentials() directly. A list riding the $fields array
 * would be cast to the string "Array" by gwc_vt_shift_meta_value() — silently,
 * in the database, under a screen that reported success.
 *
 * §4 creates a repeat and asserts the LAST occurrence, not the first. Writing
 * credentials once outside the creation loop gives a weekly series one shift
 * that asks for the waiver and nineteen that ask for nothing, and a test that
 * looked at the first would pass.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/credential-shifts.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_cs_made']  = array();
$GLOBALS['gwc_vt_cs_mail']  = array();
$GLOBALS['gwc_vt_cs_post']  = $_POST;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_cs_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Swallow mail and count it.
 *
 * @param mixed $short  Whatever a previous filter returned.
 * @param array $atts   wp_mail arguments.
 * @return bool
 */
function gwc_vt_cs_catch_mail( $short, $atts ) {
	$GLOBALS['gwc_vt_cs_mail'][] = (array) $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'gwc_vt_cs_catch_mail', 10, 2 );

/**
 * A credential.
 *
 * @param string $name   What it is.
 * @param string $mode   'report' or 'block'.
 * @param int    $months Renewal interval.
 * @return int
 */
function gwc_vt_cs_credential( string $name, string $mode = 'report', int $months = 0 ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_CREDENTIAL_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_CREDENTIAL_MODE, $mode );
	update_post_meta( $id, GWC_VT_CREDENTIAL_MONTHS, $months );

	$GLOBALS['gwc_vt_cs_made'][] = $id;

	return $id;
}

/**
 * A shift, straight into the database.
 *
 * @param string $date   Y-m-d.
 * @param int    $parent Event to hang it under, or 0.
 * @return int
 */
function gwc_vt_cs_shift( string $date, int $parent = 0 ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'Zzcs shift',
			'post_parent' => $parent,
		)
	);

	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );

	$GLOBALS['gwc_vt_cs_made'][] = $id;

	return $id;
}

/**
 * A date, relative to today.
 *
 * @param string $offset A strtotime modifier.
 * @return string Y-m-d.
 */
function gwc_vt_cs_date( string $offset ): string {
	return gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' ' . $offset ) );
}

wp_set_current_user( 1 );

echo "\n── 1. One row per credential ────────────────────────────────────\n";

$gwc_vt_cs_waiver = gwc_vt_cs_credential( 'Zzcs waiver', 'block' );
$gwc_vt_cs_food   = gwc_vt_cs_credential( 'Zzcs food handler', 'report' );
$gwc_vt_cs_class  = gwc_vt_cs_credential( 'Zzcs safety class', 'report', 12 );

$gwc_vt_cs_shift = gwc_vt_cs_shift( gwc_vt_cs_date( '+7 days' ) );

gwc_vt_set_shift_credentials( $gwc_vt_cs_shift, array( $gwc_vt_cs_waiver, $gwc_vt_cs_food ) );

/* Raw, unfiltered. The whole storage decision is that meta_query can ask about
 * one of these against an indexed column, which a serialized array defeats. */
$gwc_vt_cs_rows = get_post_meta( $gwc_vt_cs_shift, GWC_VT_SHIFT_CREDENTIAL, false );

gwc_vt_cs_check(
	'two credentials are two meta rows',
	2 === count( $gwc_vt_cs_rows ),
	count( $gwc_vt_cs_rows ) . ' row(s)'
);

gwc_vt_cs_check(
	'and neither is the string "Array"',
	! in_array( 'Array', array_map( 'strval', $gwc_vt_cs_rows ), true ),
	implode( '|', array_map( 'strval', $gwc_vt_cs_rows ) )
);

/* The point of the row-per-value shape, asserted the way the feature will use
 * it — a meta_query for one credential, not a LIKE over a blob. */
$gwc_vt_cs_found = get_posts(
	array(
		'post_type'      => GWC_VT_SHIFT_TYPE,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'     => GWC_VT_SHIFT_CREDENTIAL,
				'value'   => $gwc_vt_cs_waiver,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
		),
	)
);

gwc_vt_cs_check(
	'a meta_query finds the shifts that ask for one',
	in_array( $gwc_vt_cs_shift, array_map( 'intval', $gwc_vt_cs_found ), true )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_shift, array( $gwc_vt_cs_waiver ) );

gwc_vt_cs_check(
	'setting them again replaces rather than appends',
	array( $gwc_vt_cs_waiver ) === gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ) )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_shift, array() );

gwc_vt_cs_check(
	'and an empty list really clears them',
	array() === gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ) )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_shift, array( $gwc_vt_cs_waiver, 999999 ) );

gwc_vt_cs_check(
	'a credential that does not exist is refused',
	array( $gwc_vt_cs_waiver ) === gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_shift ) )
);

echo "\n── 2. An event adds to its slots, it does not replace ───────────\n";

$gwc_vt_cs_event = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzcs meal service',
	)
);

$GLOBALS['gwc_vt_cs_made'][] = $gwc_vt_cs_event;

$gwc_vt_cs_kitchen = gwc_vt_cs_shift( gwc_vt_cs_date( '+10 days' ), $gwc_vt_cs_event );

gwc_vt_set_shift_credentials( $gwc_vt_cs_event, array( $gwc_vt_cs_waiver ) );
gwc_vt_set_shift_credentials( $gwc_vt_cs_kitchen, array( $gwc_vt_cs_food ) );

$gwc_vt_cs_required = gwc_vt_required_credential_ids( $gwc_vt_cs_kitchen );
sort( $gwc_vt_cs_required );

$gwc_vt_cs_both = array( $gwc_vt_cs_waiver, $gwc_vt_cs_food );
sort( $gwc_vt_cs_both );

gwc_vt_cs_check(
	'a slot needs its own AND the event’s',
	$gwc_vt_cs_required === $gwc_vt_cs_both,
	implode( ',', $gwc_vt_cs_required ) . ' vs ' . implode( ',', $gwc_vt_cs_both )
);

gwc_vt_cs_check(
	'and the event’s were not copied onto the slot',
	array( $gwc_vt_cs_food ) === gwc_vt_shift_credential_ids( $gwc_vt_cs_kitchen ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_kitchen ) )
);

/* Which is what makes removing it from the event reach every slot. Copying
 * down would leave this one asking for a waiver nobody asks for any more. */
gwc_vt_set_shift_credentials( $gwc_vt_cs_event, array() );

gwc_vt_cs_check(
	'taking it off the event takes it off every slot',
	array( $gwc_vt_cs_food ) === gwc_vt_required_credential_ids( $gwc_vt_cs_kitchen ),
	implode( ',', gwc_vt_required_credential_ids( $gwc_vt_cs_kitchen ) )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_event, array( $gwc_vt_cs_waiver ) );

echo "\n── 3. Through the real save handler ─────────────────────────────\n";

/* Driving gwc_vt_handle_save_shift() rather than the writer, because the trap
 * is in the handler: a value in $fields is cast with (string). */
$GLOBALS['gwc_vt_cs_redirect'] = '';

/**
 * Catch the redirect instead of exiting.
 *
 * @param string $location Where it wanted to go.
 * @return string
 */
function gwc_vt_cs_catch_redirect( $location ) {
	$GLOBALS['gwc_vt_cs_redirect'] = (string) $location;

	throw new Exception( 'redirected' );
}

add_filter( 'wp_redirect', 'gwc_vt_cs_catch_redirect', 1 );

/**
 * Post to the save handler.
 *
 * @param array $fields POST fields.
 * @return void
 */
function gwc_vt_cs_save( array $fields ): void {
	$_POST                       = $fields;
	$_REQUEST                    = $fields;
	$GLOBALS['gwc_vt_cs_redirect'] = '';

	try {
		gwc_vt_handle_save_shift();
	} catch ( Exception $e ) {
		unset( $e );
	}
}

$gwc_vt_cs_edit = gwc_vt_cs_shift( gwc_vt_cs_date( '+14 days' ) );

gwc_vt_cs_save(
	array(
		'gwc_vt_shift'       => $gwc_vt_cs_edit,
		'_wpnonce'           => wp_create_nonce( 'gwc_vt_save_shift_' . $gwc_vt_cs_edit ),
		'gwc_vt_date'        => gwc_vt_cs_date( '+14 days' ),
		'gwc_vt_start'       => '09:00',
		'gwc_vt_end'         => '12:00',
		'gwc_vt_activity'    => 'Zzcs sorting',
		'gwc_vt_published'   => '1',
		'gwc_vt_credentials' => array( (string) $gwc_vt_cs_waiver, (string) $gwc_vt_cs_food ),
	)
);

gwc_vt_cs_check(
	'the save handler writes both',
	2 === count( gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) )
);

gwc_vt_cs_check(
	'and stores IDs, not the word "Array"',
	'Array' !== (string) get_post_meta( $gwc_vt_cs_edit, GWC_VT_SHIFT_CREDENTIAL, true ),
	(string) get_post_meta( $gwc_vt_cs_edit, GWC_VT_SHIFT_CREDENTIAL, true )
);

/* Clearing every box has to clear them. A handler that only writes what it was
 * given leaves a shift asking for something the coordinator just unticked. */
gwc_vt_cs_save(
	array(
		'gwc_vt_shift'     => $gwc_vt_cs_edit,
		'_wpnonce'         => wp_create_nonce( 'gwc_vt_save_shift_' . $gwc_vt_cs_edit ),
		'gwc_vt_date'      => gwc_vt_cs_date( '+14 days' ),
		'gwc_vt_start'     => '09:00',
		'gwc_vt_end'       => '12:00',
		'gwc_vt_activity'  => 'Zzcs sorting',
		'gwc_vt_published' => '1',
	)
);

gwc_vt_cs_check(
	'unticking everything clears them',
	array() === gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) )
);

echo "\n── 4. Every occurrence of a repeat, not just the first ──────────\n";

$GLOBALS['gwc_vt_cs_mail'] = array();

gwc_vt_cs_save(
	array(
		'gwc_vt_shift'       => 0,
		'_wpnonce'           => wp_create_nonce( 'gwc_vt_save_shift_0' ),
		'gwc_vt_date'        => gwc_vt_cs_date( '+21 days' ),
		'gwc_vt_start'       => '09:00',
		'gwc_vt_end'         => '12:00',
		'gwc_vt_activity'    => 'Zzcs weekly',
		'gwc_vt_published'   => '1',
		'gwc_vt_repeat'      => 'weekly',
		'gwc_vt_until'       => gwc_vt_cs_date( '+56 days' ),
		'gwc_vt_credentials' => array( (string) $gwc_vt_cs_waiver ),
	)
);

$gwc_vt_cs_series = get_posts(
	array(
		'post_type'      => GWC_VT_SHIFT_TYPE,
		'post_status'    => array_values( get_post_stati() ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		's'              => 'Zzcs weekly',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

foreach ( $gwc_vt_cs_series as $gwc_vt_cs_one ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_one;
}

gwc_vt_cs_check(
	'the repeat made several occurrences',
	count( $gwc_vt_cs_series ) > 2,
	count( $gwc_vt_cs_series ) . ' occurrence(s)'
);

$gwc_vt_cs_without = 0;

foreach ( $gwc_vt_cs_series as $gwc_vt_cs_one ) {
	if ( array( $gwc_vt_cs_waiver ) !== gwc_vt_shift_credential_ids( (int) $gwc_vt_cs_one ) ) {
		++$gwc_vt_cs_without;
	}
}

/* The LAST one matters as much as the first. Writing credentials once, outside
 * the creation loop, gives you one shift that asks and nineteen that do not. */
gwc_vt_cs_check(
	'every occurrence asks for it, not only the first',
	0 === $gwc_vt_cs_without,
	$gwc_vt_cs_without . ' occurrence(s) missing it'
);

echo "\n── 5. Changing them tells nobody ────────────────────────────────\n";

$gwc_vt_cs_vol = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzcs Ola Kowalczyk',
	)
);

update_post_meta( $gwc_vt_cs_vol, GWC_VT_VOLUNTEER_EMAIL, 'zzcs-ola@example.com' );

$GLOBALS['gwc_vt_cs_made'][] = $gwc_vt_cs_vol;

$gwc_vt_cs_signup = gwc_vt_add_signup(
	$gwc_vt_cs_edit,
	array(
		'volunteer_id' => $gwc_vt_cs_vol,
		'source'       => 'admin',
	)
);

if ( $gwc_vt_cs_signup > 0 ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_signup;
}

$GLOBALS['gwc_vt_cs_mail'] = array();

gwc_vt_cs_save(
	array(
		'gwc_vt_shift'       => $gwc_vt_cs_edit,
		'_wpnonce'           => wp_create_nonce( 'gwc_vt_save_shift_' . $gwc_vt_cs_edit ),
		'gwc_vt_date'        => gwc_vt_cs_date( '+14 days' ),
		'gwc_vt_start'       => '09:00',
		'gwc_vt_end'         => '12:00',
		'gwc_vt_activity'    => 'Zzcs sorting',
		'gwc_vt_published'   => '1',
		'gwc_vt_notify'      => '1',
		'gwc_vt_credentials' => array( (string) $gwc_vt_cs_waiver ),
	)
);

do_action( 'shutdown' );

/* A credential is not a move. Mailing thirty people because a coordinator
 * added a waiver is how an organization teaches its volunteers to ignore its
 * email — the same reasoning gwc_vt_shift_movement_keys() already carries for
 * the activity and the supervisor. */
gwc_vt_cs_check(
	'adding a credential mails nobody',
	0 === count( $GLOBALS['gwc_vt_cs_mail'] ),
	count( $GLOBALS['gwc_vt_cs_mail'] ) . ' message(s) sent'
);

gwc_vt_cs_check(
	'and it really was added',
	array( $gwc_vt_cs_waiver ) === gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) )
);

/* The control, without which the check above proves nothing. "No mail was
 * sent" is also what a broken harness, an unhooked filter or a roster of
 * nobody looks like — so move the shift's time on the same roster through the
 * same handler and watch mail arrive. */
$GLOBALS['gwc_vt_cs_mail'] = array();

gwc_vt_cs_save(
	array(
		'gwc_vt_shift'       => $gwc_vt_cs_edit,
		'_wpnonce'           => wp_create_nonce( 'gwc_vt_save_shift_' . $gwc_vt_cs_edit ),
		'gwc_vt_date'        => gwc_vt_cs_date( '+14 days' ),
		'gwc_vt_start'       => '14:00',
		'gwc_vt_end'         => '17:00',
		'gwc_vt_activity'    => 'Zzcs sorting',
		'gwc_vt_published'   => '1',
		'gwc_vt_notify'      => '1',
		'gwc_vt_credentials' => array( (string) $gwc_vt_cs_waiver ),
	)
);

do_action( 'shutdown' );

gwc_vt_cs_check(
	'but moving the time on the same roster does mail somebody',
	count( $GLOBALS['gwc_vt_cs_mail'] ) > 0,
	count( $GLOBALS['gwc_vt_cs_mail'] ) . ' message(s) sent'
);

echo "\n── 6. Who is short of what ──────────────────────────────────────\n";

$gwc_vt_cs_missing = gwc_vt_missing_credentials( $gwc_vt_cs_vol, $gwc_vt_cs_edit );

gwc_vt_cs_check(
	'somebody holding nothing is short of a blocking credential',
	array( $gwc_vt_cs_waiver ) === $gwc_vt_cs_missing['block'],
	implode( ',', $gwc_vt_cs_missing['block'] )
);

gwc_vt_record_credential( $gwc_vt_cs_vol, $gwc_vt_cs_waiver, gwc_vt_today() );

$gwc_vt_cs_missing = gwc_vt_missing_credentials( $gwc_vt_cs_vol, $gwc_vt_cs_edit );

gwc_vt_cs_check(
	'recording it clears them',
	array() === $gwc_vt_cs_missing['block'] && array() === $gwc_vt_cs_missing['report'],
	implode( ',', array_merge( $gwc_vt_cs_missing['block'], $gwc_vt_cs_missing['report'] ) )
);

/* An expired credential is missing, not held. This is the case a naive "have
 * they ever got one" check gets wrong, and it is the whole reason expiry
 * exists. */
gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array( $gwc_vt_cs_class ) );
gwc_vt_record_credential( $gwc_vt_cs_vol, $gwc_vt_cs_class, gwc_vt_cs_date( '-13 months' ) );

$gwc_vt_cs_missing = gwc_vt_missing_credentials( $gwc_vt_cs_vol, $gwc_vt_cs_edit );

gwc_vt_cs_check(
	'a lapsed credential counts as missing',
	array( $gwc_vt_cs_class ) === $gwc_vt_cs_missing['report'],
	implode( ',', $gwc_vt_cs_missing['report'] )
);

/* Nobody known is short of everything — and NOT because we looked them up.
 * There is simply no volunteer here to hold anything. Hard rule 4. */
$gwc_vt_cs_nobody = gwc_vt_missing_credentials( 0, $gwc_vt_cs_edit );

gwc_vt_cs_check(
	'a signup matched to nobody is short of everything asked for',
	array( $gwc_vt_cs_class ) === $gwc_vt_cs_nobody['report'],
	implode( ',', $gwc_vt_cs_nobody['report'] )
);

echo "\n── 7. Retiring and deleting a definition ────────────────────────\n";

gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array( $gwc_vt_cs_class, $gwc_vt_cs_waiver ) );

wp_update_post(
	array(
		'ID'          => $gwc_vt_cs_class,
		'post_status' => GWC_VT_CREDENTIAL_RETIRED,
	)
);

gwc_vt_cs_check(
	'a retired credential stops being asked for',
	array( $gwc_vt_cs_waiver ) === gwc_vt_required_credential_ids( $gwc_vt_cs_edit ),
	implode( ',', gwc_vt_required_credential_ids( $gwc_vt_cs_edit ) )
);

/* But the row survives, so putting it back into use restores every shift that
 * asked for it rather than losing the lot. */
gwc_vt_cs_check(
	'and the shift still remembers it',
	in_array( $gwc_vt_cs_class, gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ), true ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) )
);

wp_update_post(
	array(
		'ID'          => $gwc_vt_cs_class,
		'post_status' => 'publish',
	)
);

gwc_vt_cs_check(
	'putting it back brings it back',
	2 === count( gwc_vt_required_credential_ids( $gwc_vt_cs_edit ) ),
	implode( ',', gwc_vt_required_credential_ids( $gwc_vt_cs_edit ) )
);

/* Deleting is different from retiring, and has to take the rows with it —
 * including when somebody does it from the post list, which fires no hook of
 * this plugin's. */
wp_delete_post( $gwc_vt_cs_class, true );

gwc_vt_cs_check(
	'deleting a definition takes it off the shifts that asked',
	! in_array( $gwc_vt_cs_class, gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ), true ),
	implode( ',', gwc_vt_shift_credential_ids( $gwc_vt_cs_edit ) )
);

echo "\n── 8. What the roster actually says ─────────────────────────────\n";

/**
 * Render one roster flag and give back the markup.
 *
 * @param int $signup_id Signup post ID.
 * @param int $shift_id  The shift they are on.
 * @return string
 */
function gwc_vt_cs_flag( int $signup_id, int $shift_id ): string {
	ob_start();
	gwc_vt_render_roster_credential_flag( $signup_id, $shift_id );

	return (string) ob_get_clean();
}

/* Ola is on $gwc_vt_cs_edit, holds the waiver, and the shift asks for the
 * waiver plus the deleted class — which §7 detached, so only the waiver. */
gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array( $gwc_vt_cs_waiver, $gwc_vt_cs_food ) );

gwc_vt_cs_check(
	'somebody short of one is flagged',
	false !== strpos( gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit ), 'Zzcs food handler' ),
	gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit )
);

gwc_vt_cs_check(
	'and what they DO hold is not named at them',
	false === strpos( gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit ), 'Zzcs waiver' ),
	gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array( $gwc_vt_cs_waiver ) );

gwc_vt_cs_check(
	'somebody holding everything is not flagged at all',
	'' === gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit ),
	gwc_vt_cs_flag( (int) $gwc_vt_cs_signup, $gwc_vt_cs_edit )
);

/* A shift that has already happened. Nothing can be done about Saturday's
 * missing waiver on Monday, and a past roster covered in amber is a screen
 * nobody reads twice. */
$gwc_vt_cs_past = gwc_vt_cs_shift( gwc_vt_cs_date( '-7 days' ) );
gwc_vt_set_shift_credentials( $gwc_vt_cs_past, array( $gwc_vt_cs_food ) );

$gwc_vt_cs_past_signup = gwc_vt_add_signup(
	$gwc_vt_cs_past,
	array(
		'volunteer_id' => $gwc_vt_cs_vol,
		'source'       => 'admin',
	)
);

if ( $gwc_vt_cs_past_signup > 0 ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_past_signup;
}

gwc_vt_cs_check(
	'a shift that has already happened flags nobody',
	'' === gwc_vt_cs_flag( (int) $gwc_vt_cs_past_signup, $gwc_vt_cs_past ),
	gwc_vt_cs_flag( (int) $gwc_vt_cs_past_signup, $gwc_vt_cs_past )
);

/* Somebody nobody has matched to a record. We have not looked their address
 * up and may not — hard rule 4 — so there is nothing to say about them on
 * their row, and the roster says it once above the table instead. */
$gwc_vt_cs_claim = gwc_vt_add_signup(
	$gwc_vt_cs_edit,
	array(
		'claim_name'  => 'Zzcs Ruth Nakamura',
		'claim_email' => 'zzcs-ruth@example.com',
		'source'      => 'public',
	)
);

if ( $gwc_vt_cs_claim > 0 ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_claim;
}

gwc_vt_cs_check(
	'an unmatched signup gets no per-row flag',
	'' === gwc_vt_cs_flag( (int) $gwc_vt_cs_claim, $gwc_vt_cs_edit ),
	gwc_vt_cs_flag( (int) $gwc_vt_cs_claim, $gwc_vt_cs_edit )
);

gwc_vt_cs_check(
	'but the roster counts them once, above the table',
	1 === gwc_vt_roster_unmatched_count( $gwc_vt_cs_edit ),
	(string) gwc_vt_roster_unmatched_count( $gwc_vt_cs_edit )
);

/* And a site asking for nothing never sees the sentence at all. */
gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array() );

gwc_vt_cs_check(
	'a shift that asks for nothing says nothing',
	0 === gwc_vt_roster_unmatched_count( $gwc_vt_cs_edit ),
	(string) gwc_vt_roster_unmatched_count( $gwc_vt_cs_edit )
);

gwc_vt_set_shift_credentials( $gwc_vt_cs_edit, array( $gwc_vt_cs_waiver ) );

/* The screens themselves, with warnings promoted to exceptions — the check
 * that catches a renderer reading a variable somebody left behind. */
set_error_handler(
	static function ( $gwc_vt_cs_no, $gwc_vt_cs_str, $gwc_vt_cs_file, $gwc_vt_cs_line ) {
		throw new ErrorException( $gwc_vt_cs_str, 0, $gwc_vt_cs_no, $gwc_vt_cs_file, $gwc_vt_cs_line );
	},
	E_ALL
);

$GLOBALS['gwc_vt_cs_screens'] = array(
	'the shift roster'          => static function (): void {
		gwc_vt_render_shift_roster( $GLOBALS['gwc_vt_cs_edit_id'] );
	},
	'the credentials field'     => static function (): void {
		gwc_vt_render_shift_credentials_field( $GLOBALS['gwc_vt_cs_edit_id'] );
	},
	'the slot credentials field' => static function (): void {
		gwc_vt_render_shift_credentials_field( $GLOBALS['gwc_vt_cs_kitchen_id'] );
	},
);

$GLOBALS['gwc_vt_cs_edit_id']    = $gwc_vt_cs_edit;
$GLOBALS['gwc_vt_cs_kitchen_id'] = $gwc_vt_cs_kitchen;

foreach ( $GLOBALS['gwc_vt_cs_screens'] as $gwc_vt_cs_what => $gwc_vt_cs_draw ) {
	try {
		ob_start();
		$gwc_vt_cs_draw();
		$gwc_vt_cs_html = (string) ob_get_clean();

		gwc_vt_cs_check(
			$gwc_vt_cs_what . ' renders',
			'' !== trim( $gwc_vt_cs_html ),
			'' === trim( $gwc_vt_cs_html ) ? 'drew nothing' : strlen( $gwc_vt_cs_html ) . ' bytes'
		);

		gwc_vt_cs_check(
			$gwc_vt_cs_what . ' puts no list inside a paragraph',
			! preg_match( '~<p[^>]*>(?:(?!</p>).)*<ul~s', $gwc_vt_cs_html )
		);
	} catch ( Throwable $gwc_vt_cs_e ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		gwc_vt_cs_check(
			$gwc_vt_cs_what . ' renders',
			false,
			$gwc_vt_cs_e->getMessage() . ' at ' . basename( $gwc_vt_cs_e->getFile() ) . ':' . $gwc_vt_cs_e->getLine()
		);
	}
}

restore_error_handler();

/* The slot's field must show the event's waiver as inherited rather than as
 * something typed into the slot — otherwise a coordinator reads one ticked box
 * as the only thing asked for, and is wrong. */
ob_start();
gwc_vt_render_shift_credentials_field( $gwc_vt_cs_kitchen );
$gwc_vt_cs_slot_html = (string) ob_get_clean();

gwc_vt_cs_check(
	'a slot says which credentials come from its event',
	false !== strpos( $gwc_vt_cs_slot_html, 'asked for by the whole event' ),
	false !== strpos( $gwc_vt_cs_slot_html, 'asked for by the whole event' ) ? '' : 'the inherited note was not drawn'
);

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

remove_filter( 'wp_redirect', 'gwc_vt_cs_catch_redirect', 1 );
remove_filter( 'pre_wp_mail', 'gwc_vt_cs_catch_mail', 10 );

$_POST    = $GLOBALS['gwc_vt_cs_post'];
$_REQUEST = array();

foreach ( gwc_vt_credential_record_ids( $gwc_vt_cs_vol ) as $gwc_vt_cs_record ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_record;
}

foreach ( get_posts(
	array(
		'post_type'   => array( GWC_VT_CREDENTIAL_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_EVENT_TYPE, GWC_VT_VOLUNTEER_TYPE, GWC_VT_SIGNUP_TYPE ),
		'post_status' => array_values( get_post_stati() ),
		'numberposts' => -1,
		's'           => 'Zzcs',
	)
) as $gwc_vt_cs_stray ) {
	$GLOBALS['gwc_vt_cs_made'][] = (int) $gwc_vt_cs_stray->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_cs_made'] ) as $gwc_vt_cs_id ) {
	wp_delete_post( (int) $gwc_vt_cs_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

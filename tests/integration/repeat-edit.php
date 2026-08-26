<?php
/**
 * Changing one thing across every occurrence of a repeat.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Every question here is about real rows. Which occurrences a batch would touch
 * depends on each one's date and status; how many people it would email depends
 * on their rosters; and the rule that matters most — that a called-off Saturday
 * and a Saturday that has already happened are left exactly as they were — can
 * only be checked by writing and then reading back the ones that should not
 * have moved.
 *
 * The arithmetic lives in gwc_vt_repeat_targets(), which the confirmation
 * screen states and the handler acts on. Both call it, so a test that agreed
 * with only one of them would not be testing the thing that matters.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/repeat-edit.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_re_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_re_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Build a weekly repeat.
 *
 * @param int[] $offsets Days from today for each occurrence.
 * @param int   $people  How many to put on each occurrence still to come.
 * @return int[] The occurrence IDs, in the order given.
 */
function gwc_vt_re_series( array $offsets, int $people = 0 ): array {
	$series = 0;
	$made   = array();

	foreach ( $offsets as $offset ) {
		$id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SHIFT_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Zzrp Food sorting',
			)
		);

		update_post_meta( $id, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', time() + ( $offset * DAY_IN_SECONDS ) ) );
		update_post_meta( $id, GWC_VT_SHIFT_START, '09:00' );
		update_post_meta( $id, GWC_VT_SHIFT_END, '12:00' );
		update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, 'Zzrp Food sorting' );
		update_post_meta( $id, GWC_VT_SHIFT_LOCATION, 'Warehouse' );
		update_post_meta( $id, GWC_VT_SHIFT_SUPERVISOR, 'Dana Reyes' );
		update_post_meta( $id, GWC_VT_SHIFT_MIN, 4 );
		update_post_meta( $id, GWC_VT_SHIFT_MAX, 8 );

		if ( 0 === $series ) {
			$series = (int) $id;
		}

		update_post_meta( $id, GWC_VT_SHIFT_SERIES, $series );
		gwc_vt_retitle_shift( $id );

		if ( $offset > 0 && $people > 0 ) {
			for ( $n = 0; $n < $people; $n++ ) {
				gwc_vt_add_signup(
					$id,
					array(
						'claim_name'  => 'Zzrp Person ' . $n,
						'claim_email' => 'zzrp' . $n . '@example.com',
						'source'      => 'staff',
					)
				);
			}
		}

		$GLOBALS['gwc_vt_re_made'][] = (int) $id;
		$made[]                      = (int) $id;
	}

	return $made;
}

/* Three past, one called off, two still to come, two people on each future one. */
$GLOBALS['gwc_vt_re_ids'] = gwc_vt_re_series( array( -21, -14, -7, 7, 14, 21 ), 2 );

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_re_ids'][3],
		'post_status' => GWC_VT_SHIFT_CANCELLED,
	)
);

$GLOBALS['gwc_vt_re_series'] = (int) $GLOBALS['gwc_vt_re_ids'][0];

/* ── What a batch would touch ────────────────────────────────────────────── */

$gwc_vt_re_default = gwc_vt_repeat_targets( $GLOBALS['gwc_vt_re_series'] );

gwc_vt_re_check(
	'by default only the occurrences still to come are touched',
	2 === count( $gwc_vt_re_default['change'] ),
	'it would change ' . count( $gwc_vt_re_default['change'] )
);

gwc_vt_re_check(
	'and the ones that have happened are held out, with a reason',
	3 === count( $gwc_vt_re_default['past'] ),
	'it counted ' . count( $gwc_vt_re_default['past'] ) . ' past'
);

gwc_vt_re_check(
	'and the called-off one is held out separately',
	1 === count( $gwc_vt_re_default['cancelled'] )
		&& in_array( $GLOBALS['gwc_vt_re_ids'][3], $gwc_vt_re_default['cancelled'], true ),
	'it counted ' . count( $gwc_vt_re_default['cancelled'] ) . ' cancelled'
);

/* Two rosters of two, and NOT the called-off one's — the handler will not write
 * to it, so counting it would put a number on the screen larger than the send. */
gwc_vt_re_check(
	'the people count is only those who could actually be told',
	4 === $gwc_vt_re_default['people'],
	'it said ' . $gwc_vt_re_default['people'] . ' people'
);

$gwc_vt_re_everything = gwc_vt_repeat_targets(
	$GLOBALS['gwc_vt_re_series'],
	array(
		'past'      => true,
		'cancelled' => true,
	)
);

gwc_vt_re_check(
	'asking for all of them takes all of them',
	6 === count( $gwc_vt_re_everything['change'] ),
	'it would change ' . count( $gwc_vt_re_everything['change'] )
);

/* And the people count does NOT grow with them. gwc_vt_apply_repeat_changes()
 * will not write to a past or called-off roster whatever the boxes say, so
 * counting those here would put a number on the confirmation screen larger than
 * the send it is describing — on the one figure somebody is agreeing to.
 *
 * This is the assertion the default-options check above cannot make: under the
 * defaults nothing past or cancelled reaches the counting line at all, so the
 * guard on it is unreachable and a test that only looks at the default is
 * satisfied by deleting it. Found by deleting it. */
gwc_vt_re_check(
	'but the people count does not, even then',
	4 === $gwc_vt_re_everything['people'],
	'it said ' . $gwc_vt_re_everything['people'] . ' people for a batch including past and called-off ones'
);

/* ── Writing it ──────────────────────────────────────────────────────────── */

$gwc_vt_re_changes = gwc_vt_repeat_changes(
	array(
		'gwc_vt_start'    => '10:00',
		'gwc_vt_end'      => '13:00',
		'gwc_vt_location' => 'The annexe',
		'gwc_vt_activity' => 'SHOULD NOT BE WRITTEN',
	),
	array( 'time', 'location' )
);

gwc_vt_re_check(
	'only the fields that were ticked are prepared',
	! isset( $gwc_vt_re_changes['fields'][ GWC_VT_SHIFT_ACTIVITY ] )
		&& isset( $gwc_vt_re_changes['fields'][ GWC_VT_SHIFT_START ] )
		&& isset( $gwc_vt_re_changes['fields'][ GWC_VT_SHIFT_LOCATION ] ),
	'it prepared: ' . implode( ', ', array_keys( $gwc_vt_re_changes['fields'] ) )
);

gwc_vt_re_check(
	'and the date is never one of them',
	! isset( $gwc_vt_re_changes['fields'][ GWC_VT_SHIFT_DATE ] )
		&& ! isset( $gwc_vt_re_changes['fields'][ GWC_VT_SHIFT_SERIES ] ),
	'a repeat edit offered to move the dates or the series'
);

$GLOBALS['gwc_vt_re_dates_before'] = gwc_vt_shift_series_dates( $GLOBALS['gwc_vt_re_series'] );

$gwc_vt_re_done = gwc_vt_apply_repeat_changes(
	$gwc_vt_re_default['change'],
	$gwc_vt_re_changes['fields'],
	false
);

gwc_vt_re_check(
	'the write reports what it did',
	2 === $gwc_vt_re_done['changed'],
	'it reported ' . $gwc_vt_re_done['changed']
);

gwc_vt_re_check(
	'the occurrences still to come carry the new values',
	'10:00' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][4], GWC_VT_SHIFT_START, true )
		&& 'The annexe' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][5], GWC_VT_SHIFT_LOCATION, true ),
	'they were not written'
);

gwc_vt_re_check(
	'and keep the fields that were not ticked',
	'Zzrp Food sorting' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][4], GWC_VT_SHIFT_ACTIVITY, true )
		&& 'Dana Reyes' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][4], GWC_VT_SHIFT_SUPERVISOR, true ),
	'an unticked field was overwritten'
);

/* The two that must not have moved. This is the rule the whole confirmation
 * screen exists for: a cancellation is an answer somebody was already given. */
gwc_vt_re_check(
	'the ones that already happened were not touched',
	'09:00' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][0], GWC_VT_SHIFT_START, true )
		&& 'Warehouse' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][2], GWC_VT_SHIFT_LOCATION, true ),
	'a past occurrence was rewritten'
);

gwc_vt_re_check(
	'the called-off one was not touched',
	'09:00' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][3], GWC_VT_SHIFT_START, true )
		&& 'Warehouse' === (string) get_post_meta( $GLOBALS['gwc_vt_re_ids'][3], GWC_VT_SHIFT_LOCATION, true ),
	'a called-off occurrence was rewritten'
);

gwc_vt_re_check(
	'and it is still called off',
	GWC_VT_SHIFT_CANCELLED === get_post_status( $GLOBALS['gwc_vt_re_ids'][3] ),
	'the batch changed its status to ' . get_post_status( $GLOBALS['gwc_vt_re_ids'][3] )
);

gwc_vt_re_check(
	'no date moved',
	$GLOBALS['gwc_vt_re_dates_before'] === gwc_vt_shift_series_dates( $GLOBALS['gwc_vt_re_series'] ),
	'the dates changed under a repeat edit'
);

/* ── Telling the rosters ─────────────────────────────────────────────────── */

$gwc_vt_re_fresh = gwc_vt_re_series( array( 7, 14 ), 3 );

$gwc_vt_re_told = gwc_vt_apply_repeat_changes(
	gwc_vt_repeat_targets( (int) $gwc_vt_re_fresh[0] )['change'],
	gwc_vt_repeat_changes( array( 'gwc_vt_start' => '11:00', 'gwc_vt_end' => '14:00' ), array( 'time' ) )['fields'],
	true
);

gwc_vt_re_check(
	'everybody on the changed occurrences is told once each',
	6 === $gwc_vt_re_told['told'],
	'it told ' . $gwc_vt_re_told['told']
);

/* Running the same change again moves nothing, so nobody is written to. A mass
 * mail must not be a side effect of pressing the button twice — the
 * single-shift path guards this with gwc_vt_shift_moved() and so does this. */
$gwc_vt_re_again = gwc_vt_apply_repeat_changes(
	gwc_vt_repeat_targets( (int) $gwc_vt_re_fresh[0] )['change'],
	gwc_vt_repeat_changes( array( 'gwc_vt_start' => '11:00', 'gwc_vt_end' => '14:00' ), array( 'time' ) )['fields'],
	true
);

gwc_vt_re_check(
	'writing the same values again tells nobody',
	0 === $gwc_vt_re_again['told'] && 2 === $gwc_vt_re_again['changed'],
	'it told ' . $gwc_vt_re_again['told'] . ' on a change that moved nothing'
);

/* ── What it refuses ─────────────────────────────────────────────────────── */

gwc_vt_re_check(
	'an end before a start is refused, and nothing is prepared',
	'bad-time' === gwc_vt_repeat_changes( array( 'gwc_vt_start' => '14:00', 'gwc_vt_end' => '09:00' ), array( 'time' ) )['error'],
	'it accepted a backwards time'
);

gwc_vt_re_check(
	'a shift that is not part of a repeat has no series',
	0 === gwc_vt_shift_series_id( (int) wp_insert_post( array( 'post_type' => GWC_VT_SHIFT_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzrp lone' ) ) ),
	'a one-off reported a series'
);

gwc_vt_re_check(
	'and nothing is written when no field was ticked',
	0 === gwc_vt_apply_repeat_changes( $gwc_vt_re_default['change'], array(), true )['changed'],
	'an empty change wrote something'
);

/* ── The screen renders ──────────────────────────────────────────────────── */

$gwc_vt_re_get = $_GET;
$_GET          = array( 'shift' => $GLOBALS['gwc_vt_re_ids'][4] );

set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a test asserting the screen renders clean.
	static function ( int $no, string $message ): bool {
		throw new RuntimeException( $message );
	},
	E_ALL
);

$gwc_vt_re_html  = '';
$gwc_vt_re_threw = '';

try {
	ob_start();
	gwc_vt_render_repeat_screen();
	$gwc_vt_re_html = (string) ob_get_clean();
} catch ( Throwable $gwc_vt_re_e ) {
	ob_end_clean();
	$gwc_vt_re_threw = $gwc_vt_re_e->getMessage();
}

restore_error_handler();

$_GET = $gwc_vt_re_get;

gwc_vt_re_check(
	'the confirmation screen renders without a warning',
	'' === $gwc_vt_re_threw,
	$gwc_vt_re_threw
);

gwc_vt_re_check(
	'and states what it will touch before it touches it',
	false !== strpos( $gwc_vt_re_html, 'occurrences will change' )
		&& false !== strpos( $gwc_vt_re_html, 'gwc_vt_save_repeat' ),
	'the preview or the form was missing'
);

gwc_vt_re_check(
	'and offers no way to move the dates',
	false === strpos( $gwc_vt_re_html, 'name="gwc_vt_date"' ),
	'the form carried a date field'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

/* Every registered status, not 'any' — which skips the cancelled occurrences
 * this file spends most of its length creating. See tests/seed.php. */
foreach ( get_posts( array( 'post_type' => GWC_VT_SHIFT_TYPE, 'post_status' => array_values( get_post_stati() ), 'numberposts' => -1, 's' => 'Zzrp' ) ) as $gwc_vt_re_post ) {
	$GLOBALS['gwc_vt_re_made'][] = (int) $gwc_vt_re_post->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_re_made'] ) as $gwc_vt_re_id ) {
	foreach ( gwc_vt_shift_signup_ids( (int) $gwc_vt_re_id, array( 'publish', 'draft', GWC_VT_SIGNUP_WAITLIST ) ) as $gwc_vt_re_signup ) {
		wp_delete_post( (int) $gwc_vt_re_signup, true );
	}

	wp_delete_post( (int) $gwc_vt_re_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

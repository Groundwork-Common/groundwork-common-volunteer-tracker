<?php
/**
 * One way to cancel a shift, one way to snapshot it, one shape to store it in.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Three defects that were all the same thing: a piece of logic written out more
 * than once, with nothing that would notice when the copies drifted. Each is
 * asserted here against real posts, because each is about what ends up in the
 * database or in an email rather than about a pure function.
 *
 *   The cancel path. gwc_vt_call_off_slot() says of itself that it is the one
 *   place a time is cancelled. Two other paths repeated its body. Only it had
 *   the guard that refuses an already-cancelled shift, so a second POST to the
 *   shift screen's handler re-mailed the whole roster and overwrote the reason.
 *   And the reason was capped at 200 characters there and 300 on both event
 *   screens: one meta key on one post type, two truths.
 *
 *   The snapshot. The shift screen built six keys, the event grid built the same
 *   five and stopped. The missing one is 'label', which is the entire "It was
 *   previously: ..." sentence — read through a `?? ''` and an emptiness check,
 *   so its absence was a shorter email and no warning anywhere.
 *
 *   The stored shape. The copy path looped a bare key list and cast every value
 *   to (string), where every other write path normalises. The issue framed this
 *   as int-versus-string, and that half is not real — WordPress sanitises every
 *   meta value the same way on the way into a longtext column, so storing 4 and
 *   storing '4' are indistinguishable on the way back out. What does survive is
 *   emptiness: a slot with no capacity set copied as '' where a save stores 0.
 *
 * The unit suite cannot see any of these: tests/bootstrap.php deliberately has
 * no get_posts(), and all three are about what several real write paths agree
 * on rather than about one function's return.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/shift-write-paths.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a top-level
 * assignment is a local while `global` in a helper reaches the real one. The
 * counter increments one and the summary reads the other, and the script prints
 * ALL PASS under a list of failures. See the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_wp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── Count the queue, not the mail ───────────────────────────────────────────
 * The cancel path's whole risk is telling people twice, so the assertion has to
 * be on how many messages it owes. gwc_vt_queue_signup_mail() does not send —
 * it appends to $GLOBALS['gwc_vt_pending_mail'], which gwc_vt_send_queued_
 * confirmations() flushes once the page has been answered, for the reasons in
 * its docblock. Reading the queue is therefore both simpler than intercepting
 * wp_mail() and a closer test of what gwc_vt_call_off_slot() actually does.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * How many messages are owed.
 *
 * @return int
 */
function gwc_vt_wp_queued(): int {
	return count( (array) ( $GLOBALS['gwc_vt_pending_mail'] ?? array() ) );
}

/**
 * Forget whatever is owed, so the next assertion counts from zero.
 */
function gwc_vt_wp_forget_mail(): void {
	$GLOBALS['gwc_vt_pending_mail'] = array();
}

/**
 * A shift with somebody on it.
 *
 * @param int    $parent Event to hang it off, or 0 for a standalone shift.
 * @param string $date   Y-m-d.
 * @return array{shift:int,signup:int}
 */
function gwc_vt_wp_make_shift( int $parent, string $date ): array {
	$shift_id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_parent' => $parent,
			'post_status' => 'publish',
			'post_title'  => 'Zzytest slot',
		)
	);

	update_post_meta( $shift_id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $shift_id, GWC_VT_SHIFT_START, '09:00' );
	update_post_meta( $shift_id, GWC_VT_SHIFT_END, '12:00' );
	update_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, 'The old hall' );
	update_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, 'Sorting' );

	$signup_id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_SIGNUP_TYPE,
			'post_parent' => $shift_id,
			'post_status' => 'publish',
			'post_title'  => 'Zzytest signup',
		)
	);

	update_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, 'Zzytest Dana' );
	update_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_EMAIL, 'zzytest-dana@example.test' );

	return array(
		'shift'  => $shift_id,
		'signup' => $signup_id,
	);
}

$GLOBALS['gwc_vt_bin'] = array();

/* ── Cancelling twice tells nobody twice ─────────────────────────────────── */

$gwc_vt_a                = gwc_vt_wp_make_shift( 0, '2099-10-12' );
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_a['shift'];
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_a['signup'];

gwc_vt_wp_forget_mail();

$gwc_vt_told_first = gwc_vt_call_off_slot( $gwc_vt_a['shift'], 'The hall flooded', true );

gwc_vt_wp_check(
	'calling a shift off tells the one person on it',
	1 === $gwc_vt_told_first && 1 === gwc_vt_wp_queued(),
	'told ' . $gwc_vt_told_first . ', queued ' . gwc_vt_wp_queued()
);

gwc_vt_wp_check(
	'and stores the reason',
	'The hall flooded' === (string) get_post_meta( $gwc_vt_a['shift'], GWC_VT_SHIFT_REASON, true ),
	(string) get_post_meta( $gwc_vt_a['shift'], GWC_VT_SHIFT_REASON, true )
);

/* The defect: the shift screen's handler is reachable on its own, and
 * gwc_vt_shift_signup_ids() still returns the roster of a cancelled shift. */
gwc_vt_wp_forget_mail();

$gwc_vt_told_again = gwc_vt_call_off_slot( $gwc_vt_a['shift'], 'Typed again by mistake', true );

gwc_vt_wp_check(
	'calling the same shift off again tells nobody',
	0 === $gwc_vt_told_again && 0 === gwc_vt_wp_queued(),
	'told ' . $gwc_vt_told_again . ', queued ' . gwc_vt_wp_queued()
);

gwc_vt_wp_check(
	'and does not overwrite the reason it was called off for',
	'The hall flooded' === (string) get_post_meta( $gwc_vt_a['shift'], GWC_VT_SHIFT_REASON, true ),
	(string) get_post_meta( $gwc_vt_a['shift'], GWC_VT_SHIFT_REASON, true )
);

/* ── One cap, whichever screen typed it ──────────────────────────────────── */

$gwc_vt_b                = gwc_vt_wp_make_shift( 0, '2099-10-13' );
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_b['shift'];
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_b['signup'];

/* The cap itself is applied at the handler boundary, where the request is
 * sanitised — gwc_vt_call_off_slot() stores what it is handed. What is asserted
 * here is that a reason of the settled length survives storage whole, which is
 * what the shift screen's 200 broke: it truncated a coordinator's explanation
 * that both event screens kept in full. */
gwc_vt_call_off_slot( $gwc_vt_b['shift'], str_repeat( 'x', GWC_VT_SHIFT_REASON_MAX ), false );

gwc_vt_wp_check(
	'a reason of the full settled length survives storage whole',
	GWC_VT_SHIFT_REASON_MAX === mb_strlen( (string) get_post_meta( $gwc_vt_b['shift'], GWC_VT_SHIFT_REASON, true ) ),
	(string) mb_strlen( (string) get_post_meta( $gwc_vt_b['shift'], GWC_VT_SHIFT_REASON, true ) )
);

gwc_vt_wp_check(
	'and 300 is the settled figure, so the shift screen no longer truncates at 200',
	300 === GWC_VT_SHIFT_REASON_MAX,
	(string) GWC_VT_SHIFT_REASON_MAX
);

/* ── The snapshot carries the sentence the email prints ──────────────────── */

$gwc_vt_c                = gwc_vt_wp_make_shift( 0, '2099-11-20' );
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_c['shift'];
$GLOBALS['gwc_vt_bin'][] = $gwc_vt_c['signup'];

$gwc_vt_snapshot = gwc_vt_shift_snapshot( $gwc_vt_c['shift'] );

gwc_vt_wp_check(
	'a snapshot carries every movement key',
	array() === array_diff_key( gwc_vt_shift_movement_keys(), $gwc_vt_snapshot ),
	implode( ',', array_keys( $gwc_vt_snapshot ) )
);

/* The key the event grid never built. Its absence was a silently shorter email:
 * gwc_vt_send_shift_changed_notice() reads it through `?? ''` and prints the
 * "It was previously" sentence only when it is non-empty. */
gwc_vt_wp_check(
	'a snapshot carries the label the "It was previously" sentence is built from',
	isset( $gwc_vt_snapshot['label'] ) && '' !== trim( (string) $gwc_vt_snapshot['label'] ),
	(string) ( $gwc_vt_snapshot['label'] ?? '(missing)' )
);

gwc_vt_wp_check(
	'and the label really is that shift, not a placeholder',
	false !== strpos( (string) $gwc_vt_snapshot['label'], 'The old hall' ),
	(string) $gwc_vt_snapshot['label']
);

/* ── A move is still a move, and a typo is still not one ─────────────────── */

gwc_vt_wp_check(
	'nothing changed is not a move',
	! gwc_vt_shift_moved( $gwc_vt_c['shift'], $gwc_vt_snapshot )
);

update_post_meta( $gwc_vt_c['shift'], GWC_VT_SHIFT_ACTIVITY, 'Sortign' );

gwc_vt_wp_check(
	'correcting the activity is not a move, so nobody is mailed about a typo',
	! gwc_vt_shift_moved( $gwc_vt_c['shift'], $gwc_vt_snapshot ),
	(string) get_post_meta( $gwc_vt_c['shift'], GWC_VT_SHIFT_ACTIVITY, true )
);

update_post_meta( $gwc_vt_c['shift'], GWC_VT_SHIFT_LOCATION, 'The new hall' );

gwc_vt_wp_check(
	'moving it somewhere else is a move',
	gwc_vt_shift_moved( $gwc_vt_c['shift'], $gwc_vt_snapshot )
);

/* ── One stored shape, whichever path wrote it ───────────────────────────── */

$gwc_vt_saved = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest shape',
	)
);

$GLOBALS['gwc_vt_bin'][] = $gwc_vt_saved;

/* A slot saved by a handler, with no capacity given — the case the copy path
 * disagreed about. */
foreach ( gwc_vt_shift_meta_keys() as $gwc_vt_key ) {
	update_post_meta( $gwc_vt_saved, $gwc_vt_key, gwc_vt_shift_meta_value( $gwc_vt_key, GWC_VT_SHIFT_MIN === $gwc_vt_key || GWC_VT_SHIFT_MAX === $gwc_vt_key ? '' : 'x' ) );
}

/* ── What the drift actually was ─────────────────────────────────────────────
 * Not int-versus-string. WordPress runs every meta value through the same
 * sanitisation on the way into a longtext column, so update_post_meta( 4 ) and
 * update_post_meta( '4' ) are indistinguishable — both read back as '4', in the
 * same request and in a later one. That was checked rather than assumed.
 *
 * The difference that does survive is emptiness. The copy path wrote
 * (string) get_post_meta( ... ), so a slot with no capacity set copied as ''
 * where every save handler stores 0 through absint(). '' and '0' are two shapes
 * for one field, and they are not the same to a strict comparison or to a
 * meta_query with 'type' => 'NUMERIC'.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_wp_check(
	'an unset capacity is stored as 0, not as an empty string',
	'0' === (string) get_post_meta( $gwc_vt_saved, GWC_VT_SHIFT_MAX, true ),
	var_export( get_post_meta( $gwc_vt_saved, GWC_VT_SHIFT_MAX, true ), true )
);

gwc_vt_wp_check(
	'the normaliser answers 0 and 1 for the overnight flag, never an empty string',
	0 === gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, '' ) && 1 === gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, '1' ),
	var_export( gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, '' ), true )
);

gwc_vt_wp_check(
	'a capacity over the ceiling is clamped to it',
	GWC_VT_SHIFT_CAPACITY_MAX === gwc_vt_shift_meta_value( GWC_VT_SHIFT_MAX, GWC_VT_SHIFT_CAPACITY_MAX + 1 ),
	(string) gwc_vt_shift_meta_value( GWC_VT_SHIFT_MAX, GWC_VT_SHIFT_CAPACITY_MAX + 1 )
);

/* The field set is one list, so a copy cannot miss an attribute a save writes. */
gwc_vt_wp_check(
	'the shared field set covers every attribute the shift editor writes',
	array() === array_diff(
		array( GWC_VT_SHIFT_DATE, GWC_VT_SHIFT_START, GWC_VT_SHIFT_END, GWC_VT_SHIFT_OVERNIGHT, GWC_VT_SHIFT_ACTIVITY, GWC_VT_SHIFT_SUPERVISOR, GWC_VT_SHIFT_LOCATION, GWC_VT_SHIFT_NOTES, GWC_VT_SHIFT_MIN, GWC_VT_SHIFT_MAX ),
		gwc_vt_shift_meta_keys()
	),
	implode( ',', gwc_vt_shift_meta_keys() )
);

/* And what the copy path now does: same keys, same normaliser, so a copied slot
 * is === identical in shape to a saved one rather than holding "4" for 4. */
$gwc_vt_copy = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest shape copy',
	)
);

$GLOBALS['gwc_vt_bin'][] = $gwc_vt_copy;

foreach ( gwc_vt_shift_meta_keys() as $gwc_vt_key ) {
	if ( GWC_VT_SHIFT_DATE === $gwc_vt_key ) {
		continue;
	}

	update_post_meta( $gwc_vt_copy, $gwc_vt_key, gwc_vt_shift_meta_value( $gwc_vt_key, get_post_meta( $gwc_vt_saved, $gwc_vt_key, true ) ) );
}

$gwc_vt_mismatched = array();

foreach ( gwc_vt_shift_meta_keys() as $gwc_vt_key ) {
	if ( GWC_VT_SHIFT_DATE === $gwc_vt_key ) {
		continue;
	}

	if ( get_post_meta( $gwc_vt_saved, $gwc_vt_key, true ) !== get_post_meta( $gwc_vt_copy, $gwc_vt_key, true ) ) {
		$gwc_vt_mismatched[] = $gwc_vt_key;
	}
}

gwc_vt_wp_check(
	'a copied slot is identically shaped to a saved one, strictly compared',
	array() === $gwc_vt_mismatched,
	implode( ',', $gwc_vt_mismatched )
);

/* And the spelling the copy path used to have, so the assertion above is known
 * to be capable of failing rather than merely observed to pass. */
gwc_vt_wp_check(
	'the old (string) cast really did disagree with a saved slot',
	(string) get_post_meta( $gwc_vt_saved, GWC_VT_SHIFT_MAX, true ) !== (string) get_post_meta( $gwc_vt_c['shift'], GWC_VT_SHIFT_MAX, true ),
	'saved ' . var_export( get_post_meta( $gwc_vt_saved, GWC_VT_SHIFT_MAX, true ), true )
		. ' vs uncopied ' . var_export( get_post_meta( $gwc_vt_c['shift'], GWC_VT_SHIFT_MAX, true ), true )
);

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_bin'] as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

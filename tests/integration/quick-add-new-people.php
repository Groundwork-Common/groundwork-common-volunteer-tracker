<?php
/**
 * Log a day: a row that names somebody who is not on file yet.
 *
 * ── The failure this exists to prevent ───────────────────────────────────────
 * Duplicate people. Somebody who comes with a company in March and back on
 * their own in June has to find their March hours, their waiver and their
 * letter on ONE record. A screen that made a second Dana Reyes every time a
 * coordinator typed the name would split people quietly, and the halves are
 * only ever noticed when a letter comes out short of what somebody worked.
 *
 * So the three rules here are not politeness, they are the feature:
 *
 *   an exact match to one record USES it;
 *   an exact match to two REFUSES, because which is a question for a person;
 *   a name that matches nothing CREATES one.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/quick-add-new-people.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a
 * top-level assignment is a local while `global` in a helper reaches the real
 * one, and the counter and the summary would disagree. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_qa_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, ( ! $ok && '' !== $got ) ? '  [' . $got . ']' : '', "\n";
}

/**
 * A volunteer, remembered for the clean-up.
 *
 * @param string $name   Their name.
 * @param string $status Post status.
 * @return int
 */
function gwc_vt_qa_volunteer( string $name, string $status = 'publish' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => $status,
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_made'][] = $id;

	return $id;
}

wp_set_current_user( 1 );

echo "\n── A name nobody has ────────────────────────────────────────────\n";

$gwc_vt_qa_new = gwc_vt_volunteer_for_typed_name( 'Zzytest Nobody Byname' );

gwc_vt_qa_check(
	'a name that matches nothing would create a record',
	'create' === $gwc_vt_qa_new['result'] && 0 === (int) $gwc_vt_qa_new['volunteer_id'],
	$gwc_vt_qa_new['result']
);

$gwc_vt_qa_made = gwc_vt_create_volunteer_named( 'Zzytest Nobody Byname' );

$GLOBALS['gwc_vt_made'][] = $gwc_vt_qa_made;

gwc_vt_qa_check( 'and creating one gives a volunteer', GWC_VT_VOLUNTEER_TYPE === get_post_type( $gwc_vt_qa_made ) );

gwc_vt_qa_check(
	'published, so it is findable rather than sitting in drafts',
	'publish' === get_post_status( $gwc_vt_qa_made ),
	(string) get_post_status( $gwc_vt_qa_made )
);

/* No email, deliberately. An address written on a clipboard by somebody else is
 * not one to send a court letter to; it goes on the record when there is one to
 * trust. */
gwc_vt_qa_check(
	'and carries no email address',
	'' === (string) get_post_meta( $gwc_vt_qa_made, GWC_VT_VOLUNTEER_EMAIL, true )
);

echo "\n── A name somebody already has ──────────────────────────────────\n";

$gwc_vt_qa_dana = gwc_vt_qa_volunteer( 'Zzytest Dana Reyes' );

$gwc_vt_qa_found = gwc_vt_volunteer_for_typed_name( 'Zzytest Dana Reyes' );

gwc_vt_qa_check(
	'an exact match uses the record that exists',
	'found' === $gwc_vt_qa_found['result'] && $gwc_vt_qa_dana === (int) $gwc_vt_qa_found['volunteer_id'],
	$gwc_vt_qa_found['result'] . ' / ' . $gwc_vt_qa_found['volunteer_id']
);

/* The June case from the issue: somebody who stopped coming and has come back is
 * the same person. Making a second record for them would leave their March
 * hours on one and their June hours on the other, and a letter would be short. */
$gwc_vt_qa_gone = gwc_vt_qa_volunteer( 'Zzytest Marcus Returned', GWC_VT_VOLUNTEER_INACTIVE );

$gwc_vt_qa_back = gwc_vt_volunteer_for_typed_name( 'Zzytest Marcus Returned' );

gwc_vt_qa_check(
	'somebody who went inactive and came back is the same person',
	'found' === $gwc_vt_qa_back['result'] && $gwc_vt_qa_gone === (int) $gwc_vt_qa_back['volunteer_id'],
	$gwc_vt_qa_back['result']
);

echo "\n── A name two people have ───────────────────────────────────────\n";

$gwc_vt_qa_twin = gwc_vt_qa_volunteer( 'Zzytest Dana Reyes' );

$gwc_vt_qa_two = gwc_vt_volunteer_for_typed_name( 'Zzytest Dana Reyes' );

gwc_vt_qa_check(
	'two people of one name is refused, not guessed at',
	'ambiguous' === $gwc_vt_qa_two['result'],
	$gwc_vt_qa_two['result']
);

gwc_vt_qa_check(
	'and no volunteer is named as the answer',
	0 === (int) $gwc_vt_qa_two['volunteer_id']
);

echo "\n── Nothing is silently corrected ────────────────────────────────\n";

/* The rule CLAUDE.md records: a silent correction on save is a bug even when
 * the correction is right. A blank name is a row the coordinator has to look
 * at, not a volunteer called nothing. */
foreach ( array( '', '   ', "\t\n" ) as $gwc_vt_qa_blank ) {
	gwc_vt_qa_check(
		'a blank name creates nobody',
		0 === gwc_vt_create_volunteer_named( $gwc_vt_qa_blank )
	);
}

$gwc_vt_qa_empty = gwc_vt_volunteer_for_typed_name( '   ' );

gwc_vt_qa_check(
	'and is refused rather than resolved',
	'ambiguous' === $gwc_vt_qa_empty['result'],
	$gwc_vt_qa_empty['result']
);

/* Trailing space is not a different person. Two records differing by whitespace
 * are the duplicate this whole script is about, wearing a disguise. */
$gwc_vt_qa_spaced = gwc_vt_volunteer_for_typed_name( '  Zzytest Marcus Returned  ' );

gwc_vt_qa_check(
	'a name with space around it finds the same person',
	'found' === $gwc_vt_qa_spaced['result'] && $gwc_vt_qa_gone === (int) $gwc_vt_qa_spaced['volunteer_id'],
	$gwc_vt_qa_spaced['result']
);

echo "\n── A created record behaves like any other ──────────────────────\n";

/* It has to be findable by the picker afterwards, or the next row that names the
 * same person makes a second one — which is the duplicate this prevents,
 * arriving one row later. */
$gwc_vt_qa_again = gwc_vt_volunteer_for_typed_name( 'Zzytest Nobody Byname' );

gwc_vt_qa_check(
	'a record created a moment ago is found by the next row that names them',
	'found' === $gwc_vt_qa_again['result'] && $gwc_vt_qa_made === (int) $gwc_vt_qa_again['volunteer_id'],
	$gwc_vt_qa_again['result']
);

/* Asked of the route the picker actually calls, not of a query written here
 * that happens to look like it. A record the lookup cannot see is one the next
 * coordinator types again from scratch. */
$gwc_vt_qa_request = new WP_REST_Request( 'GET', '/' . GWC_VT_REST_NAMESPACE . '/volunteers' );

$gwc_vt_qa_request->set_param( 'search', 'Zzytest Nobody' );

$gwc_vt_qa_reply = rest_do_request( $gwc_vt_qa_request );
$gwc_vt_qa_ids   = array_map( 'intval', wp_list_pluck( (array) $gwc_vt_qa_reply->get_data(), 'id' ) );

gwc_vt_qa_check(
	'and by the REST lookup the picker itself uses',
	in_array( $gwc_vt_qa_made, $gwc_vt_qa_ids, true ),
	'status ' . $gwc_vt_qa_reply->get_status() . ', ids ' . implode( ',', $gwc_vt_qa_ids )
);

/* And hours logged against it total up like anybody else's. */
$gwc_vt_qa_entry = gwc_vt_log_one_entry( $gwc_vt_qa_made, '2026-03-14', 180, 'Zzytest sorting' );

$GLOBALS['gwc_vt_made'][] = $gwc_vt_qa_entry;

$gwc_vt_qa_totals = gwc_vt_compute_totals( $gwc_vt_qa_made );

gwc_vt_qa_check(
	'hours logged against it count, and arrive unverified',
	180 === (int) $gwc_vt_qa_totals->pending_minutes && 0 === (int) $gwc_vt_qa_totals->verified_minutes,
	$gwc_vt_qa_totals->verified_minutes . ' verified / ' . $gwc_vt_qa_totals->pending_minutes . ' pending'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_qa_id ) {
	wp_delete_post( (int) $gwc_vt_qa_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

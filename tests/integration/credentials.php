<?php
/**
 * Credentials, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * tests/CredentialTest.php owns the arithmetic — when a thing granted on the
 * 31st of January expires, what a zero interval means, and that no code in the
 * feature uses the word "requirement". None of that needs a post.
 *
 * Everything here does. A credential record is a child post of a volunteer, and
 * the whole point of that choice is what happens to it when the volunteer is
 * anonymized, erased or deleted — which is a question about WordPress, not
 * about this plugin's arithmetic.
 *
 * ── The property that matters most ───────────────────────────────────────────
 * WordPress does not cascade a delete to children. A credential record whose
 * volunteer has been deleted is a fact about a named person's training sitting
 * in the database with nothing left to say whose it was, and it would survive
 * every privacy tool the plugin offers. Four of the checks below exist for that
 * one sentence, and §7 sabotages exactly it.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/credentials.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a
 * top-level assignment is a local while `global` in a helper reaches the real
 * one. See the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_cr_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_cr_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * A credential, remembered for the clean-up at the end.
 *
 * @param string $name   What it is.
 * @param int    $months Renewal interval, 0 for never.
 * @param string $mode   'report' or 'block'.
 * @return int
 */
function gwc_vt_cr_credential( string $name, int $months, string $mode = 'report' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_CREDENTIAL_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_CREDENTIAL_MONTHS, $months );
	update_post_meta( $id, GWC_VT_CREDENTIAL_MODE, $mode );

	$GLOBALS['gwc_vt_cr_made'][] = $id;

	return $id;
}

/**
 * A volunteer, remembered for the clean-up at the end.
 *
 * @param string $name  Their name.
 * @param string $email Their address.
 * @return int
 */
function gwc_vt_cr_volunteer( string $name, string $email = '' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( '' !== $email ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	$GLOBALS['gwc_vt_cr_made'][] = $id;

	return $id;
}

/**
 * A date, relative to today.
 *
 * @param string $offset A strtotime modifier, e.g. '-13 months'.
 * @return string Y-m-d.
 */
function gwc_vt_cr_date( string $offset ): string {
	return gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' ' . $offset ) );
}

echo "\n── 1. Recording one ─────────────────────────────────────────────\n";

$gwc_vt_cr_waiver = gwc_vt_cr_credential( 'Zzcr liability waiver', 0 );
$gwc_vt_cr_class  = gwc_vt_cr_credential( 'Zzcr child safety class', 12, 'block' );

$gwc_vt_cr_ada = gwc_vt_cr_volunteer( 'Zzcr Ada Petrov', 'zzcr-ada@example.com' );

$gwc_vt_cr_rec = gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_waiver, gwc_vt_cr_date( '-2 days' ) );

gwc_vt_cr_check(
	'recording returns a record id',
	is_int( $gwc_vt_cr_rec ) && $gwc_vt_cr_rec > 0,
	is_wp_error( $gwc_vt_cr_rec ) ? $gwc_vt_cr_rec->get_error_message() : (string) $gwc_vt_cr_rec
);

gwc_vt_cr_check(
	'the record is a child of the volunteer',
	(int) wp_get_post_parent_id( (int) $gwc_vt_cr_rec ) === $gwc_vt_cr_ada
);

/* The title is the one thing about this post a stranger could ever see — it is
 * what an admin search matches on. A name in it would put "who holds a
 * background check" into the results of a search for that person. */
gwc_vt_cr_check(
	'the record title carries no name',
	false === stripos( (string) get_the_title( (int) $gwc_vt_cr_rec ), 'Ada' ),
	(string) get_the_title( (int) $gwc_vt_cr_rec )
);

gwc_vt_cr_check(
	'a never-expiring credential reads as current',
	GWC_VT_HOLDS_CURRENT === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_waiver ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_waiver )
);

gwc_vt_cr_check(
	'one they have never held reads as never',
	GWC_VT_HOLDS_NEVER === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class )
);

echo "\n── 2. Lapsing ───────────────────────────────────────────────────\n";

gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_class, gwc_vt_cr_date( '-13 months' ) );

gwc_vt_cr_check(
	'a 12-month credential granted 13 months ago has lapsed',
	GWC_VT_HOLDS_EXPIRED === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class )
);

/* Renewing does not overwrite. Both grants stay, and the newer one decides. */
gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_class, gwc_vt_cr_date( '-2 months' ) );

gwc_vt_cr_check(
	'renewing brings it back to current',
	GWC_VT_HOLDS_CURRENT === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class )
);

gwc_vt_cr_check(
	'the lapsed grant is kept, not replaced',
	3 === count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) ),
	(string) count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) )
);

/* Changing the interval re-derives every expiry rather than leaving stale dates
 * behind. That is the whole reason expiry is not stored.
 *
 * One month against a grant two months old, deliberately not one month old: a
 * thing granted exactly one month ago under a one-month interval expires TODAY,
 * and today is still current. That boundary is correct and it is not what this
 * check is about, so the check stays off it. */
update_post_meta( $gwc_vt_cr_class, GWC_VT_CREDENTIAL_MONTHS, 1 );

gwc_vt_cr_check(
	'shortening the interval lapses it without touching a record',
	GWC_VT_HOLDS_EXPIRED === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_class )
);

update_post_meta( $gwc_vt_cr_class, GWC_VT_CREDENTIAL_MONTHS, 12 );

echo "\n── 3. What will not be recorded ─────────────────────────────────\n";

$gwc_vt_cr_future = gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_waiver, gwc_vt_cr_date( '+1 day' ) );

gwc_vt_cr_check(
	'a date in the future is refused, not clamped',
	is_wp_error( $gwc_vt_cr_future ),
	is_wp_error( $gwc_vt_cr_future ) ? $gwc_vt_cr_future->get_error_code() : 'recorded it anyway'
);

$gwc_vt_cr_impossible = gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_waiver, '2025-02-31' );

gwc_vt_cr_check(
	'a day that does not exist is refused',
	is_wp_error( $gwc_vt_cr_impossible ),
	is_wp_error( $gwc_vt_cr_impossible ) ? $gwc_vt_cr_impossible->get_error_code() : 'recorded it anyway'
);

$gwc_vt_cr_nobody = gwc_vt_record_credential( 0, $gwc_vt_cr_waiver, gwc_vt_today() );

gwc_vt_cr_check(
	'a credential cannot be recorded against a volunteer who is not one',
	is_wp_error( $gwc_vt_cr_nobody ),
	is_wp_error( $gwc_vt_cr_nobody ) ? $gwc_vt_cr_nobody->get_error_code() : 'recorded it anyway'
);

$gwc_vt_cr_nothing = gwc_vt_record_credential( $gwc_vt_cr_ada, 0, gwc_vt_today() );

gwc_vt_cr_check(
	'a credential that is not defined cannot be recorded',
	is_wp_error( $gwc_vt_cr_nothing ),
	is_wp_error( $gwc_vt_cr_nothing ) ? $gwc_vt_cr_nothing->get_error_code() : 'recorded it anyway'
);

gwc_vt_cr_check(
	'nothing refused was written',
	3 === count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) ),
	(string) count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) )
);

echo "\n── 4. Retiring a credential ─────────────────────────────────────\n";

$gwc_vt_cr_old = gwc_vt_cr_credential( 'Zzcr retired thing', 0 );
gwc_vt_record_credential( $gwc_vt_cr_ada, $gwc_vt_cr_old, gwc_vt_today() );

wp_update_post(
	array(
		'ID'          => $gwc_vt_cr_old,
		'post_status' => GWC_VT_CREDENTIAL_RETIRED,
	)
);

gwc_vt_cr_check(
	'a retired credential drops off the live list',
	! in_array( $gwc_vt_cr_old, gwc_vt_live_credential_ids(), true )
);

gwc_vt_cr_check(
	'but the record of somebody holding it survives',
	4 === count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) ),
	(string) count( gwc_vt_credential_record_ids( $gwc_vt_cr_ada ) )
);

gwc_vt_cr_check(
	'and it still reads as held',
	GWC_VT_HOLDS_CURRENT === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_old ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, $gwc_vt_cr_old )
);

echo "\n── 5. The exporter ──────────────────────────────────────────────\n";

$gwc_vt_cr_export = gwc_vt_export_personal_data( 'zzcr-ada@example.com' );
$gwc_vt_cr_groups = array();

foreach ( (array) ( $gwc_vt_cr_export['data'] ?? array() ) as $gwc_vt_cr_item ) {
	$gwc_vt_cr_groups[] = (string) ( $gwc_vt_cr_item['group_id'] ?? '' );
}

gwc_vt_cr_check(
	'an export names every credential held',
	4 === count( array_keys( $gwc_vt_cr_groups, 'gwc_vt_credential', true ) ),
	(string) count( array_keys( $gwc_vt_cr_groups, 'gwc_vt_credential', true ) )
);

$gwc_vt_cr_said = wp_json_encode( $gwc_vt_cr_export );

gwc_vt_cr_check(
	'and says what each one is',
	is_string( $gwc_vt_cr_said ) && false !== strpos( $gwc_vt_cr_said, 'Zzcr child safety class' )
);

echo "\n── 6. Anonymizing ───────────────────────────────────────────────\n";

$gwc_vt_cr_bo   = gwc_vt_cr_volunteer( 'Zzcr Bo Nakamura', 'zzcr-bo@example.com' );
$gwc_vt_cr_bo_r = (int) gwc_vt_record_credential( $gwc_vt_cr_bo, $gwc_vt_cr_class, gwc_vt_today() );

gwc_vt_anonymize_volunteer( $gwc_vt_cr_bo );

gwc_vt_cr_check(
	'anonymizing takes the credential records with the name',
	array() === gwc_vt_credential_record_ids( $gwc_vt_cr_bo ),
	(string) count( gwc_vt_credential_record_ids( $gwc_vt_cr_bo ) )
);

/* Not merely unlinked. A post left in the database with its meta intact is one
 * a plugin, an export or a database dump still finds. */
gwc_vt_cr_check(
	'the record post itself is gone from the database',
	null === get_post( $gwc_vt_cr_bo_r ),
	null === get_post( $gwc_vt_cr_bo_r ) ? '' : 'still there'
);

gwc_vt_cr_check(
	'the volunteer record itself survives anonymizing',
	GWC_VT_VOLUNTEER_TYPE === get_post_type( $gwc_vt_cr_bo )
);

echo "\n── 7. Deleting, which WordPress does not cascade ────────────────\n";

$gwc_vt_cr_cy   = gwc_vt_cr_volunteer( 'Zzcr Cy Oyelaran', 'zzcr-cy@example.com' );
$gwc_vt_cr_cy_r = (int) gwc_vt_record_credential( $gwc_vt_cr_cy, $gwc_vt_cr_class, gwc_vt_today() );

gwc_vt_delete_volunteer( $gwc_vt_cr_cy );

gwc_vt_cr_check(
	'deleting a volunteer deletes their credential records too',
	null === get_post( $gwc_vt_cr_cy_r ),
	null === get_post( $gwc_vt_cr_cy_r ) ? '' : 'orphan survived the delete'
);

gwc_vt_cr_check(
	'and nothing of the record is left in post meta',
	'' === (string) get_post_meta( $gwc_vt_cr_cy_r, GWC_VT_RECORD_DATE, true ),
	(string) get_post_meta( $gwc_vt_cr_cy_r, GWC_VT_RECORD_DATE, true )
);

echo "\n── 8. The orphan sweep ──────────────────────────────────────────\n";

/* A staff member deleting a volunteer from wp-admin takes a route that fires
 * none of this plugin's hooks. Those children survive, and the sweep is what
 * finds them. This is the case §7 does NOT cover, and it is the likelier one. */
$gwc_vt_cr_di   = gwc_vt_cr_volunteer( 'Zzcr Di Marchetti', 'zzcr-di@example.com' );
$gwc_vt_cr_di_r = (int) gwc_vt_record_credential( $gwc_vt_cr_di, $gwc_vt_cr_class, gwc_vt_today() );

wp_delete_post( $gwc_vt_cr_di, true );

gwc_vt_cr_check(
	'a record left behind by a raw delete is findable',
	in_array( $gwc_vt_cr_di_r, gwc_vt_orphan_credential_record_ids(), true ),
	implode( ',', gwc_vt_orphan_credential_record_ids() )
);

$GLOBALS['gwc_vt_cr_made'][] = $gwc_vt_cr_di_r;

echo "\n── 8b. Who holds one, asked the other way round ─────────────────\n";

/* The inverse of everything above: not "what does this person hold" but "who
 * has a food handler card". Ada holds the waiver (never expires), the class
 * (lapsed at -13 months, renewed at -2 months) and the retired thing. */
$gwc_vt_cr_holders = gwc_vt_credential_holder_ids( $gwc_vt_cr_waiver );

gwc_vt_cr_check(
	'somebody who holds it is listed',
	in_array( $gwc_vt_cr_ada, $gwc_vt_cr_holders, true ),
	implode( ',', $gwc_vt_cr_holders )
);

gwc_vt_cr_check(
	'and somebody who has never held it is not',
	! in_array( $gwc_vt_cr_bo, gwc_vt_credential_holder_ids( $gwc_vt_cr_waiver ), true )
);

/* Somebody whose class lapsed and was never renewed, so both lists below have
 * a person in them. Without this, "the two lists never share a person" and
 * "either gives the two combined" are 1 == 1 + 0 — arithmetic that cannot
 * fail and therefore checks nothing. */
$gwc_vt_cr_gus = gwc_vt_cr_volunteer( 'Zzcr Gus Lindqvist', 'zzcr-gus@example.com' );
gwc_vt_record_credential( $gwc_vt_cr_gus, $gwc_vt_cr_class, gwc_vt_cr_date( '-20 months' ) );

/* And a DIFFERENT credential, granted today, so it is his newest record.
 *
 * That detail is the fixture doing real work. Records come back newest first,
 * so an implementation that read the standing off the first record rather than
 * asking about this credential would see the waiver, find it current, and
 * report Gus as holding a class that ran out twenty months ago. Without this
 * line the fixture's newest record happened to give the right answer anyway and
 * the sabotage passed. */
gwc_vt_record_credential( $gwc_vt_cr_gus, $gwc_vt_cr_waiver, gwc_vt_today() );

/* THE case this function exists to get right. Ada holds the class twice — a
 * grant that lapsed and a later one that did not. Walking records instead of
 * volunteers would report her as current AND expired, so she would appear on
 * both lists and the two counts would add up to more people than exist. */
$gwc_vt_cr_current = gwc_vt_credential_holder_ids( $gwc_vt_cr_class, GWC_VT_HOLDS_CURRENT );
$gwc_vt_cr_lapsed  = gwc_vt_credential_holder_ids( $gwc_vt_cr_class, GWC_VT_HOLDS_EXPIRED );

gwc_vt_cr_check(
	'somebody who renewed after lapsing counts as current',
	in_array( $gwc_vt_cr_ada, $gwc_vt_cr_current, true ),
	implode( ',', $gwc_vt_cr_current )
);

gwc_vt_cr_check(
	'and is NOT also counted as lapsed',
	! in_array( $gwc_vt_cr_ada, $gwc_vt_cr_lapsed, true ),
	implode( ',', $gwc_vt_cr_lapsed )
);

gwc_vt_cr_check(
	'somebody who never renewed is on the lapsed list',
	in_array( $gwc_vt_cr_gus, $gwc_vt_cr_lapsed, true ),
	implode( ',', $gwc_vt_cr_lapsed )
);

gwc_vt_cr_check(
	'so both lists have somebody on them',
	array() !== $gwc_vt_cr_current && array() !== $gwc_vt_cr_lapsed,
	count( $gwc_vt_cr_current ) . ' current, ' . count( $gwc_vt_cr_lapsed ) . ' lapsed'
);

gwc_vt_cr_check(
	'the two lists never share a person',
	array() === array_intersect( $gwc_vt_cr_current, $gwc_vt_cr_lapsed ),
	implode( ',', array_intersect( $gwc_vt_cr_current, $gwc_vt_cr_lapsed ) )
);

/* And "any" is the two together, not a third answer. */
$gwc_vt_cr_any = gwc_vt_credential_holder_ids( $gwc_vt_cr_class );

gwc_vt_cr_check(
	'asking for either gives exactly the two lists combined',
	count( $gwc_vt_cr_any ) === count( $gwc_vt_cr_current ) + count( $gwc_vt_cr_lapsed ),
	count( $gwc_vt_cr_any ) . ' vs ' . ( count( $gwc_vt_cr_current ) + count( $gwc_vt_cr_lapsed ) )
);

$gwc_vt_cr_counts = gwc_vt_credential_holder_counts( $gwc_vt_cr_class );

gwc_vt_cr_check(
	'the counts on the definitions screen match the lists they link to',
	$gwc_vt_cr_counts['current'] === count( $gwc_vt_cr_current )
		&& $gwc_vt_cr_counts['expired'] === count( $gwc_vt_cr_lapsed ),
	$gwc_vt_cr_counts['current'] . '/' . $gwc_vt_cr_counts['expired']
);

/* A retired credential still has holders — retiring stopped the organization
 * asking for it and did not take it off anybody. */
gwc_vt_cr_check(
	'a retired credential still lists who holds it',
	in_array( $gwc_vt_cr_ada, gwc_vt_credential_holder_ids( $gwc_vt_cr_old ), true ),
	implode( ',', gwc_vt_credential_holder_ids( $gwc_vt_cr_old ) )
);

gwc_vt_cr_check(
	'and it is offered in the filter, marked retired',
	false !== strpos( (string) ( gwc_vt_credential_filter_options()[ (string) $gwc_vt_cr_old ] ?? '' ), 'retired' ),
	(string) ( gwc_vt_credential_filter_options()[ (string) $gwc_vt_cr_old ] ?? '(absent)' )
);

gwc_vt_cr_check(
	'a credential that does not exist has no holders',
	array() === gwc_vt_credential_holder_ids( 999999 )
);

/* The list the filter would actually produce, through the same function the
 * screen uses — nobody holding it must show nobody, not everybody. */
$gwc_vt_cr_vars = gwc_vt_credential_holder_query_vars( $gwc_vt_cr_waiver, GWC_VT_HOLDS_EXPIRED );

gwc_vt_cr_check(
	'nobody lapsed on a never-expiring credential shows nobody',
	array( 'post__in' => array( 0 ) ) === $gwc_vt_cr_vars,
	wp_json_encode( $gwc_vt_cr_vars )
);

echo "\n── 9. The count and the screen it links to ──────────────────────\n";

/* CLAUDE.md: where a screen acts on a count, it filters by the same function
 * that produced it. Both halves are asserted here — that the dashboard's number
 * is the number of volunteers with something lapsed, and that following its link
 * lands on a query returning exactly those people and nobody else. */

$gwc_vt_cr_el = gwc_vt_cr_volunteer( 'Zzcr El Ferreira', 'zzcr-el@example.com' );
gwc_vt_record_credential( $gwc_vt_cr_el, $gwc_vt_cr_class, gwc_vt_cr_date( '-14 months' ) );

/* And somebody current, who must NOT appear. A filter that returned everybody
 * would pass a check that only counted the lapsed one. */
$gwc_vt_cr_fi = gwc_vt_cr_volunteer( 'Zzcr Fi Andersson', 'zzcr-fi@example.com' );
gwc_vt_record_credential( $gwc_vt_cr_fi, $gwc_vt_cr_class, gwc_vt_today() );

$gwc_vt_cr_lapsed = gwc_vt_lapsed_credential_ids();

gwc_vt_cr_check(
	'somebody whose credential ran out is on the lapsed list',
	in_array( $gwc_vt_cr_el, $gwc_vt_cr_lapsed, true )
);

gwc_vt_cr_check(
	'somebody current is not',
	! in_array( $gwc_vt_cr_fi, $gwc_vt_cr_lapsed, true )
);

/* Renewing takes them off it, which is what makes this a queue rather than a
 * statistic. A line that does not shrink when worked does not belong on the
 * dashboard at all. */
gwc_vt_record_credential( $gwc_vt_cr_el, $gwc_vt_cr_class, gwc_vt_today() );

gwc_vt_cr_check(
	'recording the renewal takes them off it',
	! in_array( $gwc_vt_cr_el, gwc_vt_lapsed_credential_ids(), true )
);

/* Back on, for the link check below. */
foreach ( gwc_vt_credential_record_ids( $gwc_vt_cr_el ) as $gwc_vt_cr_undo ) {
	$gwc_vt_cr_undo_r = gwc_vt_credential_record( (int) $gwc_vt_cr_undo );

	if ( $gwc_vt_cr_undo_r && $gwc_vt_cr_undo_r['date'] === gwc_vt_today() ) {
		wp_delete_post( (int) $gwc_vt_cr_undo, true );
	}
}

$gwc_vt_cr_count = gwc_vt_dashboard_counts()['lapsed'];

gwc_vt_cr_check(
	'the dashboard counts what the list holds',
	$gwc_vt_cr_count === count( gwc_vt_lapsed_credential_ids() ),
	$gwc_vt_cr_count . ' vs ' . count( gwc_vt_lapsed_credential_ids() )
);

/* Follow the link rather than rebuilding the query. A check that re-derived the
 * filter here would prove only that it agrees with itself. */
$gwc_vt_cr_url = gwc_vt_dashboard_item_url( 'lapsed' );

$gwc_vt_cr_args = array();
wp_parse_str( (string) wp_parse_url( $gwc_vt_cr_url, PHP_URL_QUERY ), $gwc_vt_cr_args );

gwc_vt_cr_check(
	'the link asks for a state the filter actually offers',
	isset( gwc_vt_credential_filter_options()[ (string) ( $gwc_vt_cr_args['gwc_vt_credential'] ?? '' ) ] ),
	(string) ( $gwc_vt_cr_args['gwc_vt_credential'] ?? '(none)' )
);

gwc_vt_cr_check(
	'and lands on the volunteer list',
	GWC_VT_VOLUNTEER_TYPE === (string) ( $gwc_vt_cr_args['post_type'] ?? '' ),
	(string) ( $gwc_vt_cr_args['post_type'] ?? '(none)' )
);

$gwc_vt_cr_query = new WP_Query(
	array_merge(
		array(
			'post_type'      => GWC_VT_VOLUNTEER_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		),
		gwc_vt_credential_query_vars(
			(string) ( $gwc_vt_cr_args['gwc_vt_credential'] ?? '' ),
			gwc_vt_lapsed_credential_ids()
		)
	)
);

gwc_vt_cr_check(
	'the screen shows exactly the people the number counted',
	count( $gwc_vt_cr_query->posts ) === $gwc_vt_cr_count,
	count( $gwc_vt_cr_query->posts ) . ' row(s) under a count of ' . $gwc_vt_cr_count
);

gwc_vt_cr_check(
	'and the volunteer who is current is not among them',
	! in_array( $gwc_vt_cr_fi, array_map( 'intval', $gwc_vt_cr_query->posts ), true )
);

/* The empty case, which is the one that fails silently. WP_Query IGNORES an
 * empty post__in, so a filter that passed one would list every volunteer on the
 * site under a heading saying these are the lapsed ones. */
$gwc_vt_cr_none = new WP_Query(
	array_merge(
		array(
			'post_type'      => GWC_VT_VOLUNTEER_TYPE,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		),
		gwc_vt_credential_query_vars( 'lapsed', array() )
	)
);

gwc_vt_cr_check(
	'nobody lapsed shows nobody, not everybody',
	array() === $gwc_vt_cr_none->posts,
	count( $gwc_vt_cr_none->posts ) . ' volunteer(s) listed when none had lapsed'
);

echo "\n── 10. The screens actually draw ────────────────────────────────\n";

/* Every check above works on data. None of them would have caught a renderer
 * that reads a variable somebody left behind in a refactor — which has happened
 * here, fatally, on a real page, while the whole suite stayed green. So the
 * three screens are rendered, with warnings promoted to exceptions so that an
 * undefined index is a failure rather than a line in a log nobody reads. */

wp_set_current_user( 1 );

set_error_handler(
	static function ( $gwc_vt_cr_no, $gwc_vt_cr_str, $gwc_vt_cr_file, $gwc_vt_cr_line ) {
		throw new ErrorException( $gwc_vt_cr_str, 0, $gwc_vt_cr_no, $gwc_vt_cr_file, $gwc_vt_cr_line );
	},
	E_ALL
);

/* The list filter draws nothing without a screen to check, so give it one. */
set_current_screen( 'edit-' . GWC_VT_VOLUNTEER_TYPE );

$GLOBALS['gwc_vt_cr_ada'] = $gwc_vt_cr_ada;

$GLOBALS['gwc_vt_cr_screens'] = array(
	'the definitions screen'   => static function (): void {
		gwc_vt_render_credentials_screen();
	},
	'the volunteer box'        => static function (): void {
		gwc_vt_render_volunteer_credentials_box( get_post( $GLOBALS['gwc_vt_cr_ada'] ) );
	},
	'the list filter dropdown' => static function (): void {
		gwc_vt_credential_filter_dropdown();
	},
);

foreach ( $GLOBALS['gwc_vt_cr_screens'] as $gwc_vt_cr_what => $gwc_vt_cr_draw ) {
	try {
		ob_start();
		$gwc_vt_cr_draw();
		$gwc_vt_cr_html = (string) ob_get_clean();

		gwc_vt_cr_check(
			$gwc_vt_cr_what . ' renders',
			'' !== trim( $gwc_vt_cr_html ),
			'' === trim( $gwc_vt_cr_html ) ? 'drew nothing' : strlen( $gwc_vt_cr_html ) . ' bytes'
		);

		/* A <ul> inside a <p> makes the parser close the paragraph and
		 * everything still open in it — no error, valid-looking HTML, and a
		 * script that finds nothing. Every field wrapper here is a div for
		 * exactly this reason, and this is what proves it stayed one. */
		gwc_vt_cr_check(
			$gwc_vt_cr_what . ' puts no list inside a paragraph',
			! preg_match( '~<p[^>]*>(?:(?!</p>).)*<ul~s', $gwc_vt_cr_html )
		);
	} catch ( Throwable $gwc_vt_cr_e ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		gwc_vt_cr_check(
			$gwc_vt_cr_what . ' renders',
			false,
			$gwc_vt_cr_e->getMessage() . ' at ' . basename( $gwc_vt_cr_e->getFile() ) . ':' . $gwc_vt_cr_e->getLine()
		);
	}
}

restore_error_handler();

echo "\n── 10b. And the definitions screen is an ordinary grid ──────────\n";

/* It reads as a WordPress list: All | Retired with counts, a count of what is
 * on screen, and what you can do to a row under the row's own name rather than
 * in a column of buttons. Asserted because every one of those is markup nobody
 * looks at twice, and a row action that stops being drawn for an administrator
 * is a feature that has silently gone.
 *
 * $gwc_vt_cr_old — "Zzcr retired thing" — was retired in §4 and is what tells
 * the two lists apart. */
function gwc_vt_cr_draw_credentials( string $status = '' ): string {
	if ( '' !== $status ) {
		$_GET['gwc_vt_status'] = $status;
	}

	ob_start();
	gwc_vt_render_credentials_screen();
	$html = (string) ob_get_clean();

	unset( $_GET['gwc_vt_status'] );

	return $html;
}

wp_set_current_user( 1 );

$GLOBALS['gwc_vt_cr_all']  = gwc_vt_cr_draw_credentials();
$GLOBALS['gwc_vt_cr_gone'] = gwc_vt_cr_draw_credentials( 'retired' );

gwc_vt_cr_check(
	'the table is a list table, not a table',
	false !== strpos( $GLOBALS['gwc_vt_cr_all'], 'wp-list-table widefat fixed striped table-view-list' )
);

gwc_vt_cr_check(
	'All lists what the organization asks for',
	false !== strpos( $GLOBALS['gwc_vt_cr_all'], 'Zzcr liability waiver' )
);

/* The reason the two views exist. A retired credential in the working list is
 * a thing somebody is asked to go on holding after the organization stopped
 * asking for it. */
gwc_vt_cr_check(
	'and does not list a retired one',
	false === strpos( $GLOBALS['gwc_vt_cr_all'], 'Zzcr retired thing' )
);

gwc_vt_cr_check(
	'Retired lists exactly the other one',
	false !== strpos( $GLOBALS['gwc_vt_cr_gone'], 'Zzcr retired thing' )
		&& false === strpos( $GLOBALS['gwc_vt_cr_gone'], 'Zzcr liability waiver' )
);

gwc_vt_cr_check(
	'the Retired view is offered, because there is something in it',
	false !== strpos( $GLOBALS['gwc_vt_cr_all'], 'gwc_vt_status=retired' )
);

gwc_vt_cr_check(
	'the count says how many are on the screen',
	1 === substr_count( $GLOBALS['gwc_vt_cr_all'], 'displaying-num' )
		&& false !== strpos(
			$GLOBALS['gwc_vt_cr_all'],
			(string) number_format_i18n( count( gwc_vt_live_credential_ids() ) ) . ' item'
		),
	count( gwc_vt_live_credential_ids() ) . ' live'
);

gwc_vt_cr_check(
	'the count and the rows are the same number',
	substr_count( $GLOBALS['gwc_vt_cr_all'], 'class="name column-name has-row-actions column-primary"' )
		=== count( gwc_vt_live_credential_ids() ),
	substr_count( $GLOBALS['gwc_vt_cr_all'], 'has-row-actions' ) . ' row(s)'
);

gwc_vt_cr_check(
	'retiring is a row action under the name',
	false !== strpos( $GLOBALS['gwc_vt_cr_all'], 'class="row-actions"' )
);

gwc_vt_cr_check(
	'and not a column of buttons on every row',
	false === strpos( $GLOBALS['gwc_vt_cr_all'], '<a class="button" href' )
);

/* Somebody who may read the screen but not define one gets no actions at all,
 * rather than links that would be refused. An editor can see records; only an
 * administrator can define. */
$GLOBALS['gwc_vt_cr_reader'] = wp_insert_user(
	array(
		'user_login' => 'zzcr_reader',
		'user_pass'  => wp_generate_password( 20, true ),
		'role'       => 'editor',
	)
);

if ( ! is_wp_error( $GLOBALS['gwc_vt_cr_reader'] ) ) {
	wp_set_current_user( (int) $GLOBALS['gwc_vt_cr_reader'] );

	$GLOBALS['gwc_vt_cr_read_only'] = gwc_vt_cr_draw_credentials();

	gwc_vt_cr_check(
		'somebody who cannot define one is offered no row actions',
		false === strpos( $GLOBALS['gwc_vt_cr_read_only'], 'class="row-actions"' )
			&& false !== strpos( $GLOBALS['gwc_vt_cr_read_only'], 'Zzcr liability waiver' )
	);

	wp_set_current_user( 1 );
	wp_delete_user( (int) $GLOBALS['gwc_vt_cr_reader'] );
}

echo "\n── 10c. And a credential can be edited ──────────────────────────\n";

/* The screen was add-only and said why: renaming is what somebody will want,
 * and changing the interval re-dates every expiry on the site, "which deserves
 * its own screen saying so, rather than a pencil icon". This is that screen —
 * so what is asserted is the saying-so as much as the saving.
 *
 * Driven through the real handler, because the interesting half is what the
 * handler does NOT do: it does not touch the status, so an edit cannot quietly
 * put a retired credential back into use. */
wp_set_current_user( 1 );

/**
 * Post the credential form the way the browser does.
 *
 * @param int   $credential_id The credential, or 0 to add one.
 * @param array $fields        What is in the form.
 * @return array The query the redirect landed on.
 */
function gwc_vt_cr_post_form( int $credential_id, array $fields ): array {
	$_POST = array_merge(
		array(
			'action'            => 'gwc_vt_save_credential',
			'gwc_vt_credential' => (string) $credential_id,
			'_wpnonce'          => wp_create_nonce( 'gwc_vt_save_credential_' . $credential_id ),
		),
		$fields
	);

	$_REQUEST = $_POST;
	$landed   = '';

	$catch = static function ( $location ) {
		throw new Exception( (string) $location );
	};

	add_filter( 'wp_redirect', $catch, 1 );

	try {
		gwc_vt_handle_save_credential();
	} catch ( Throwable $e ) {
		$landed = $e->getMessage();
	}

	remove_filter( 'wp_redirect', $catch, 1 );

	$_POST    = array();
	$_REQUEST = array();

	$args = array();
	parse_str( (string) wp_parse_url( $landed, PHP_URL_QUERY ), $args );

	return $args;
}

$GLOBALS['gwc_vt_cr_edit'] = gwc_vt_cr_credential( 'Zzcr editable class', 12, 'report' );
gwc_vt_record_credential( $gwc_vt_cr_ada, $GLOBALS['gwc_vt_cr_edit'], gwc_vt_today() );

$GLOBALS['gwc_vt_cr_landed'] = gwc_vt_cr_post_form(
	(int) $GLOBALS['gwc_vt_cr_edit'],
	array(
		'gwc_vt_name'   => 'Zzcr renamed class',
		'gwc_vt_months' => '24',
		'gwc_vt_mode'   => 'block',
		'gwc_vt_note'   => 'Booked through the county office',
	)
);

gwc_vt_cr_check(
	'saving an edit says it saved',
	'saved' === (string) ( $GLOBALS['gwc_vt_cr_landed']['gwc_vt_credential_did'] ?? '' ),
	(string) ( $GLOBALS['gwc_vt_cr_landed']['gwc_vt_credential_did'] ?? '-' )
);

$GLOBALS['gwc_vt_cr_after'] = gwc_vt_credential( (int) $GLOBALS['gwc_vt_cr_edit'] );

gwc_vt_cr_check(
	'and every field is what was typed',
	'Zzcr renamed class' === $GLOBALS['gwc_vt_cr_after']['name']
		&& 24 === (int) $GLOBALS['gwc_vt_cr_after']['months']
		&& 'block' === $GLOBALS['gwc_vt_cr_after']['mode']
		&& 'Booked through the county office' === $GLOBALS['gwc_vt_cr_after']['note'],
	wp_json_encode( $GLOBALS['gwc_vt_cr_after'] )
);

/* The record did not move. This is why renaming can be offered at all: every
 * record points at the credential itself, so the word changes under all of them
 * at once. */
gwc_vt_cr_check(
	'the record of somebody holding it is untouched by a rename',
	GWC_VT_HOLDS_CURRENT === gwc_vt_volunteer_holds( $gwc_vt_cr_ada, (int) $GLOBALS['gwc_vt_cr_edit'] ),
	gwc_vt_volunteer_holds( $gwc_vt_cr_ada, (int) $GLOBALS['gwc_vt_cr_edit'] )
);

/* An empty name is refused, and lands back on THIS credential's form rather
 * than on the blank one — which would offer to make a second credential out of
 * the mistake. */
$GLOBALS['gwc_vt_cr_landed'] = gwc_vt_cr_post_form(
	(int) $GLOBALS['gwc_vt_cr_edit'],
	array( 'gwc_vt_name' => '   ', 'gwc_vt_months' => '24', 'gwc_vt_mode' => 'report' )
);

gwc_vt_cr_check(
	'an empty name is refused, on the form it was typed in',
	'no-name' === (string) ( $GLOBALS['gwc_vt_cr_landed']['gwc_vt_credential_did'] ?? '' )
		&& (string) $GLOBALS['gwc_vt_cr_edit'] === (string) ( $GLOBALS['gwc_vt_cr_landed']['credential'] ?? '' ),
	(string) ( $GLOBALS['gwc_vt_cr_landed']['gwc_vt_credential_did'] ?? '-' ) . ' → ' . (string) ( $GLOBALS['gwc_vt_cr_landed']['credential'] ?? '-' )
);

gwc_vt_cr_check(
	'and the name it had is still there',
	'Zzcr renamed class' === gwc_vt_credential( (int) $GLOBALS['gwc_vt_cr_edit'] )['name']
);

/* Editing a retired one leaves it retired. Putting it back is its own action,
 * with its own sentence about what that means. */
wp_update_post(
	array(
		'ID'          => (int) $GLOBALS['gwc_vt_cr_edit'],
		'post_status' => GWC_VT_CREDENTIAL_RETIRED,
	)
);

gwc_vt_cr_post_form(
	(int) $GLOBALS['gwc_vt_cr_edit'],
	array( 'gwc_vt_name' => 'Zzcr renamed again', 'gwc_vt_months' => '6', 'gwc_vt_mode' => 'report' )
);

gwc_vt_cr_check(
	'editing a retired credential does not put it back into use',
	GWC_VT_CREDENTIAL_RETIRED === get_post_status( (int) $GLOBALS['gwc_vt_cr_edit'] ),
	(string) get_post_status( (int) $GLOBALS['gwc_vt_cr_edit'] )
);

/* And the form says what changing the interval does, with the number of people
 * it reaches — the sentence the original refusal to build this asked for. */
$_GET['credential'] = (string) $GLOBALS['gwc_vt_cr_edit'];

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_form'] = (string) ob_get_clean();

unset( $_GET['credential'] );

gwc_vt_cr_check(
	'the form warns what re-dating means, and says how many people it reaches',
	false !== strpos( $GLOBALS['gwc_vt_cr_form'], 'volunteer holds this' )
		&& false !== strpos( $GLOBALS['gwc_vt_cr_form'], 'never stored' ),
	substr_count( $GLOBALS['gwc_vt_cr_form'], 'gwcvt-credential-warning' ) . ' warning(s)'
);

gwc_vt_cr_check(
	'and it is the credential being edited that is in the fields',
	false !== strpos( $GLOBALS['gwc_vt_cr_form'], 'value="Zzcr renamed again"' )
);

/* The list offers the way in. */
/* ── The same header block every other list on this plugin wears ─────────── */

$GLOBALS['gwc_vt_cr_head'] = gwc_vt_cr_draw_credentials();

gwc_vt_cr_check(
	'the header block is core\'s, in core\'s order',
	strpos( $GLOBALS['gwc_vt_cr_head'], 'subsubsub' ) < strpos( $GLOBALS['gwc_vt_cr_head'], 'class="search-box"' )
		&& strpos( $GLOBALS['gwc_vt_cr_head'], 'class="search-box"' ) < strpos( $GLOBALS['gwc_vt_cr_head'], 'tablenav top' )
		&& strpos( $GLOBALS['gwc_vt_cr_head'], 'tablenav top' ) < strpos( $GLOBALS['gwc_vt_cr_head'], '<table' )
);

/* Searching narrows the rows and leaves the view counts alone: those counts are
 * how somebody leaves a search, and "Retired (0)" meaning "none match this
 * word" would be the screen telling them something untrue. */
$_GET['s'] = 'waiver';

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_found'] = (string) ob_get_clean();

unset( $_GET['s'] );

gwc_vt_cr_check(
	'a search leaves what matches and drops what does not',
	false !== strpos( $GLOBALS['gwc_vt_cr_found'], 'Zzcr liability waiver' )
		&& false === strpos( $GLOBALS['gwc_vt_cr_found'], 'Zzcr child safety class' )
);

gwc_vt_cr_check(
	'and the view counts still count everything',
	false !== strpos(
		$GLOBALS['gwc_vt_cr_found'],
		'(' . number_format_i18n( count( gwc_vt_live_credential_ids() ) ) . ')'
	),
	count( gwc_vt_live_credential_ids() ) . ' live'
);

/* The note is searched too: it is where a site records what the thing is to
 * them, and that is what somebody types. */
$_GET['s'] = 'county office';

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_note'] = (string) ob_get_clean();

unset( $_GET['s'] );

$_GET['s'] = 'zznothing is called this';

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_nothing'] = (string) ob_get_clean();

unset( $_GET['s'] );

gwc_vt_cr_check(
	'nothing matching says so, rather than saying nothing is defined',
	false !== strpos( $GLOBALS['gwc_vt_cr_nothing'], 'No credential matches that' )
		&& false === strpos( $GLOBALS['gwc_vt_cr_nothing'], 'Nothing defined yet' )
);

/* And the box posts back to the list somebody is on, not to the other one. */
$_GET['gwc_vt_status'] = 'retired';

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_retired_head'] = (string) ob_get_clean();

unset( $_GET['gwc_vt_status'] );

gwc_vt_cr_check(
	'searching the retired list stays on the retired list',
	false !== strpos( $GLOBALS['gwc_vt_cr_retired_head'], '<input type="hidden" name="gwc_vt_status" value="retired" />' )
);

/* ── And it behaves the way a list in wp-admin behaves ───────────────────── */

$GLOBALS['gwc_vt_cr_list'] = gwc_vt_cr_draw_credentials();

gwc_vt_cr_check(
	'the name is the link, the way every list table in wp-admin works',
	substr_count( $GLOBALS['gwc_vt_cr_list'], 'class="row-title"' )
		=== substr_count( $GLOBALS['gwc_vt_cr_list'], 'has-row-actions' )
		&& substr_count( $GLOBALS['gwc_vt_cr_list'], 'class="row-title"' ) > 0,
	substr_count( $GLOBALS['gwc_vt_cr_list'], 'class="row-title"' ) . ' link(s) for '
		. substr_count( $GLOBALS['gwc_vt_cr_list'], 'has-row-actions' ) . ' row(s)'
);

/* A link that lands on a form somebody would be refused is worse than no link.
 * An editor may read this screen and may not define. */
$GLOBALS['gwc_vt_cr_reader2'] = wp_insert_user(
	array(
		'user_login' => 'zzcr_reader2',
		'user_pass'  => wp_generate_password( 20, true ),
		'role'       => 'editor',
	)
);

if ( ! is_wp_error( $GLOBALS['gwc_vt_cr_reader2'] ) ) {
	wp_set_current_user( (int) $GLOBALS['gwc_vt_cr_reader2'] );

	$GLOBALS['gwc_vt_cr_flat'] = gwc_vt_cr_draw_credentials();

	gwc_vt_cr_check(
		'and is plain text for somebody who cannot open it',
		false === strpos( $GLOBALS['gwc_vt_cr_flat'], 'class="row-title"' )
			&& false !== strpos( $GLOBALS['gwc_vt_cr_flat'], 'Zzcr liability waiver' )
	);

	wp_set_current_user( 1 );
	wp_delete_user( (int) $GLOBALS['gwc_vt_cr_reader2'] );
}

/* The form is shaped like an add/edit screen: core moves notices to
 * wp-header-end, and the thing you do after deciding this credential is wrong
 * is beside the save, where core keeps Move to Trash. */
$_GET['credential'] = (string) $GLOBALS['gwc_vt_cr_edit'];

ob_start();
gwc_vt_render_credentials_screen();
$GLOBALS['gwc_vt_cr_shape'] = (string) ob_get_clean();

unset( $_GET['credential'] );

gwc_vt_cr_check(
	'the form marks where its header ends, once',
	1 === substr_count( $GLOBALS['gwc_vt_cr_shape'], 'wp-header-end' ),
	substr_count( $GLOBALS['gwc_vt_cr_shape'], 'wp-header-end' ) . ' marker(s)'
);

gwc_vt_cr_check(
	'and offers retiring beside the save, not only back on the list',
	false !== strpos( $GLOBALS['gwc_vt_cr_shape'], 'gwcvt-credential-aside' )
		&& false !== strpos( $GLOBALS['gwc_vt_cr_shape'], 'gwc_vt_restore_credential' ),
	'retired fixture, so it offers putting it back'
);

gwc_vt_cr_check(
	'the list offers Edit as a row action',
	false !== strpos( gwc_vt_cr_draw_credentials( 'retired' ), 'credential=' . $GLOBALS['gwc_vt_cr_edit'] )
);

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

foreach ( array( $gwc_vt_cr_ada, $gwc_vt_cr_bo, $gwc_vt_cr_el, $gwc_vt_cr_fi, $gwc_vt_cr_gus ) as $gwc_vt_cr_left ) {
	foreach ( gwc_vt_credential_record_ids( (int) $gwc_vt_cr_left ) as $gwc_vt_cr_left_r ) {
		$GLOBALS['gwc_vt_cr_made'][] = (int) $gwc_vt_cr_left_r;
	}
}

foreach ( get_posts(
	array(
		'post_type'   => array( GWC_VT_CREDENTIAL_TYPE, GWC_VT_RECORD_TYPE, GWC_VT_VOLUNTEER_TYPE ),
		/* Every registered status, not 'any' — which skips the retired
		 * credential §4 makes. See tests/seed.php. */
		'post_status' => array_values( get_post_stati() ),
		'numberposts' => -1,
		's'           => 'Zzcr',
	)
) as $gwc_vt_cr_stray ) {
	$GLOBALS['gwc_vt_cr_made'][] = (int) $gwc_vt_cr_stray->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_cr_made'] ) as $gwc_vt_cr_id ) {
	wp_delete_post( (int) $gwc_vt_cr_id, true );
}

/* ── A volunteer's panel shows what they hold, not what the org asks for ─────
 * It listed every credential defined anywhere and said "not recorded" against
 * most of them, so a volunteer who held one of six read as five pieces of bad
 * news and one fact. The list of what the organization asks for is a screen of
 * its own.
 *
 * The same change fixes the opposite miss: the old loop walked the DEFINITIONS,
 * so a credential the organization has since retired never appeared on the
 * record of somebody who still holds it. Retiring stops asking; it does not
 * un-hold.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_cr_panel_vol'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Panel Tester',
	)
);

$GLOBALS['gwc_vt_cr_panel_live'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_CREDENTIAL_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Panel Live Class',
	)
);

$GLOBALS['gwc_vt_cr_panel_gone'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_CREDENTIAL_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Panel Retired Class',
	)
);

/**
 * The credentials panel for the test volunteer.
 *
 * @return string
 */
function gwc_vt_cr_panel(): string {
	ob_start();
	gwc_vt_render_volunteer_credentials_box( get_post( (int) $GLOBALS['gwc_vt_cr_panel_vol'] ) );

	return (string) ob_get_clean();
}

/**
 * Just the table of what they hold.
 *
 * Scoped deliberately: the "Record one" select below the table lists every LIVE
 * credential, because that is how one is granted. A check over the whole panel
 * therefore finds the name of a credential nobody holds and calls it a row —
 * which is what the first draft of these checks did, and it failed the code for
 * being right.
 *
 * @return string
 */
function gwc_vt_cr_panel_table(): string {
	$panel = gwc_vt_cr_panel();

	/* The BODY, not the whole table. Every check below counts <tr> to mean "how
	 * many credentials are listed", and the table grew a header row when the
	 * three panels on a volunteer's record were made to look alike — which is a
	 * row, and is not a credential. */
	$open = strpos( $panel, '<tbody' );

	if ( false === $open ) {
		return '';
	}

	$close = strpos( $panel, '</tbody>', $open );

	return false === $close ? '' : substr( $panel, $open, $close - $open );
}

gwc_vt_cr_check(
	'holding nothing says so rather than listing every credential as missing',
	false !== strpos( gwc_vt_cr_panel(), 'Nothing recorded' )
		&& '' === gwc_vt_cr_panel_table()
);

gwc_vt_record_credential(
	(int) $GLOBALS['gwc_vt_cr_panel_vol'],
	(int) $GLOBALS['gwc_vt_cr_panel_live'],
	gmdate( 'Y-m-d' )
);

$gwc_vt_cr_panel_html = gwc_vt_cr_panel_table();

gwc_vt_cr_check(
	'one grant shows one row',
	1 === substr_count( $gwc_vt_cr_panel_html, '<tr>' )
		&& false !== strpos( $gwc_vt_cr_panel_html, 'Zzy Panel Live Class' ),
	substr_count( $gwc_vt_cr_panel_html, '<tr>' ) . ' row(s)'
);

gwc_vt_cr_check(
	'and the ones they do not hold stay off the table',
	false === strpos( $gwc_vt_cr_panel_html, 'Zzy Panel Retired Class' )
);

/* Grant the second, then retire it underneath them. */
gwc_vt_record_credential(
	(int) $GLOBALS['gwc_vt_cr_panel_vol'],
	(int) $GLOBALS['gwc_vt_cr_panel_gone'],
	gmdate( 'Y-m-d' )
);

wp_update_post(
	array(
		'ID'          => (int) $GLOBALS['gwc_vt_cr_panel_gone'],
		'post_status' => GWC_VT_CREDENTIAL_RETIRED,
	)
);

$gwc_vt_cr_panel_html = gwc_vt_cr_panel_table();

gwc_vt_cr_check(
	'a credential retired underneath them is still on their record',
	false !== strpos( $gwc_vt_cr_panel_html, 'Zzy Panel Retired Class' )
);

gwc_vt_cr_check(
	'and is marked, so it does not read as something still asked for',
	false !== strpos( $gwc_vt_cr_panel_html, 'gwcvt-held__retired' )
);

/**
 * Take the panel fixtures away again.
 */
function gwc_vt_cr_panel_cleanup(): void {
	foreach ( array( 'gwc_vt_cr_panel_vol', 'gwc_vt_cr_panel_live', 'gwc_vt_cr_panel_gone' ) as $key ) {
		if ( empty( $GLOBALS[ $key ] ) ) {
			continue;
		}

		/* Grants are child posts and WordPress does not cascade. */
		foreach ( (array) get_posts(
			array(
				'post_type'   => GWC_VT_RECORD_TYPE,
				'post_parent' => (int) $GLOBALS[ $key ],
				'post_status' => array_values( get_post_stati() ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $record_id ) {
			wp_delete_post( (int) $record_id, true );
		}

		wp_delete_post( (int) $GLOBALS[ $key ], true );
	}
}

register_shutdown_function( 'gwc_vt_cr_panel_cleanup' );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

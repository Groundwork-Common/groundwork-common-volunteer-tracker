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
 *   npx @wordpress/env run cli -- wp eval-file \
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

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

foreach ( array( $gwc_vt_cr_ada, $gwc_vt_cr_bo, $gwc_vt_cr_el, $gwc_vt_cr_fi ) as $gwc_vt_cr_left ) {
	foreach ( gwc_vt_credential_record_ids( (int) $gwc_vt_cr_left ) as $gwc_vt_cr_left_r ) {
		$GLOBALS['gwc_vt_cr_made'][] = (int) $gwc_vt_cr_left_r;
	}
}

foreach ( get_posts(
	array(
		'post_type'   => array( GWC_VT_CREDENTIAL_TYPE, GWC_VT_RECORD_TYPE, GWC_VT_VOLUNTEER_TYPE ),
		'post_status' => 'any',
		'numberposts' => -1,
		's'           => 'Zzcr',
	)
) as $gwc_vt_cr_stray ) {
	$GLOBALS['gwc_vt_cr_made'][] = (int) $gwc_vt_cr_stray->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_cr_made'] ) as $gwc_vt_cr_id ) {
	wp_delete_post( (int) $gwc_vt_cr_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

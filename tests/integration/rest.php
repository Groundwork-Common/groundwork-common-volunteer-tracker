<?php
/**
 * The one REST route, and the six post types that must not have one.
 *
 * inc/rest.php has claimed for a long time that "tests/integration/rest.php
 * asserts those 404" and that "RestTest asserts the exact key set". Neither
 * file existed. The claims were right about what ought to be checked and wrong
 * about anything checking it, which is the worse of the two failure modes: a
 * comment naming a test is a comment somebody trusts instead of looking.
 *
 * Written when the WordPress.org plugin review team pointed out that the route
 * answered a contributor with the names of volunteer records that contributor
 * could not open. The per-record filter that fixed it is still here and still
 * asserted — but the gate itself has since been raised to edit_others_posts, so
 * a contributor no longer reaches the route at all. Both layers are tested: the
 * gate, and the filter behind it, the latter through an editor stripped of
 * read_private_posts.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/rest.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a local while `global` in the helper reaches the real
 * one — the counter increments one and the summary reads the other. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_made']     = array();
$GLOBALS['gwc_vt_users']    = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $note  Optional detail, printed on failure.
 */
function gwc_vt_check( string $label, bool $ok, string $note = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( ! $ok && '' !== $note ? '  [' . $note . ']' : '' ), "\n";
}

/**
 * Create a volunteer in a given status, owned by whoever is signed in.
 *
 * @param string $name   Post title.
 * @param string $status Post status.
 * @return int
 */
function gwc_vt_make_volunteer( string $name, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => $status,
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_made'][] = (int) $id;

	return (int) $id;
}

/**
 * Create a user, replacing any left behind by an interrupted run.
 *
 * @param string $login Login name.
 * @param string $role  Role slug.
 * @return int
 */
function gwc_vt_make_user( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );

	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);

	$GLOBALS['gwc_vt_users'][] = (int) $id;

	return (int) $id;
}

/**
 * Ask the volunteer route for a search term.
 *
 * @param string $search The term.
 * @return WP_REST_Response
 */
function gwc_vt_search( string $search ) {
	$request = new WP_REST_Request( 'GET', '/' . GWC_VT_REST_NAMESPACE . '/volunteers' );
	$request->set_param( 'search', $search );

	return rest_do_request( $request );
}

/**
 * The titles a search came back with.
 *
 * @param WP_REST_Response $response From gwc_vt_search().
 * @return string[]
 */
function gwc_vt_labels( $response ): array {
	$data = $response->get_data();

	if ( ! is_array( $data ) ) {
		return array();
	}

	return array_map(
		static function ( $row ): string {
			return isset( $row['label'] ) ? (string) $row['label'] : '';
		},
		$data
	);
}

/* ── Nothing this plugin stores is in the public REST API ────────────────────
 * Hard rule 2. Flipping any of these to show_in_rest => true publishes
 * volunteer names and court-referral status at /wp/v2/ to anybody the site
 * lets read, and it is one word in one array away at all times.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_private_types = array(
	GWC_VT_VOLUNTEER_TYPE,
	GWC_VT_ENTRY_TYPE,
	GWC_VT_SHIFT_TYPE,
	GWC_VT_SIGNUP_TYPE,
	GWC_VT_EVENT_TYPE,
	GWC_VT_LETTER_TYPE,
);

foreach ( $gwc_vt_private_types as $gwc_vt_type ) {
	$gwc_vt_object = get_post_type_object( $gwc_vt_type );

	gwc_vt_check( $gwc_vt_type . ' is registered', $gwc_vt_object instanceof WP_Post_Type );

	if ( ! $gwc_vt_object instanceof WP_Post_Type ) {
		continue;
	}

	gwc_vt_check( $gwc_vt_type . ' declares show_in_rest false', false === $gwc_vt_object->show_in_rest );

	/* And asked of the server rather than only of the registration, because a
	 * third party can add the route back with register_post_type_args. */
	$gwc_vt_probe = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwc_vt_type ) );

	gwc_vt_check(
		$gwc_vt_type . ' has no route under /wp/v2/',
		404 === $gwc_vt_probe->get_status(),
		'status ' . $gwc_vt_probe->get_status()
	);
}

/* And the taxonomies, which are a second way to publish the same records and
 * are not covered by the loop above. A taxonomy registered show_in_rest => true
 * puts the list of organizations a site works with at /wp/v2/, and its term meta
 * puts a named contact's telephone number beside it.
 *
 * Read from the plugin rather than listed, so a second taxonomy is covered the
 * day somebody registers one. tests/integration/partners.php asserts the same thing
 * about gwc_vt_org in more detail; this is the sweep that would notice a NEW
 * one, which that file by definition cannot. */
foreach ( get_taxonomies( array(), 'objects' ) as $gwc_vt_taxonomy ) {
	if ( 0 !== strpos( (string) $gwc_vt_taxonomy->name, 'gwc_vt_' ) ) {
		continue;
	}

	gwc_vt_check(
		$gwc_vt_taxonomy->name . ' declares show_in_rest false',
		false === $gwc_vt_taxonomy->show_in_rest
	);

	$gwc_vt_tax_probe = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $gwc_vt_taxonomy->name ) );

	gwc_vt_check(
		$gwc_vt_taxonomy->name . ' has no route under /wp/v2/',
		404 === $gwc_vt_tax_probe->get_status(),
		'status ' . $gwc_vt_tax_probe->get_status()
	);
}

/* ── The one route that does exist ───────────────────────────────────────── */

$gwc_vt_routes = rest_get_server()->get_routes();

gwc_vt_check(
	'the volunteer lookup is registered',
	isset( $gwc_vt_routes[ '/' . GWC_VT_REST_NAMESPACE . '/volunteers' ] )
);

/* ── Set the stage ───────────────────────────────────────────────────────────
 * Created as user 1, so the drafts below belong to somebody OTHER than the
 * contributor — which is the case that matters. A contributor's own draft is
 * theirs to read and always was.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_set_current_user( 1 );

$gwc_vt_open    = gwc_vt_make_volunteer( 'Zzytest Rosalind Achebe', 'publish' );
$gwc_vt_hidden  = gwc_vt_make_volunteer( 'Zzytest Rosalind Vanterpool', 'private' );
$gwc_vt_draft   = gwc_vt_make_volunteer( 'Zzytest Rosalind Okonkwo', 'draft' );
$gwc_vt_pending = gwc_vt_make_volunteer( 'Zzytest Rosalind Baptiste', 'pending' );

$gwc_vt_contributor = gwc_vt_make_user( 'zzytest_rest_contributor', 'contributor' );
$gwc_vt_subscriber  = gwc_vt_make_user( 'zzytest_rest_subscriber', 'subscriber' );
$gwc_vt_author      = gwc_vt_make_user( 'zzytest_rest_author', 'author' );
$gwc_vt_editor      = gwc_vt_make_user( 'zzytest_rest_editor', 'editor' );

/* An editor with read_private_posts taken away: the case the per-record filter
 * still protects now that the gate itself excludes contributors. Without it,
 * that filter would be untested and would look like dead code to the next
 * person reading it. */
$gwc_vt_limited = gwc_vt_make_user( 'zzytest_rest_limited', 'editor' );
$gwc_vt_limited_user = new WP_User( $gwc_vt_limited );
$gwc_vt_limited_user->remove_cap( 'read_private_posts' );
$gwc_vt_limited_user->remove_cap( 'edit_private_posts' );

/* ── Who may ask at all ──────────────────────────────────────────────────── */

wp_set_current_user( 0 );
gwc_vt_check(
	'a signed-out visitor is refused',
	401 === gwc_vt_search( 'Zzytest' )->get_status(),
	'status ' . gwc_vt_search( 'Zzytest' )->get_status()
);

wp_set_current_user( $gwc_vt_subscriber );
gwc_vt_check(
	'a subscriber is refused',
	403 === gwc_vt_search( 'Zzytest' )->get_status(),
	'status ' . gwc_vt_search( 'Zzytest' )->get_status()
);

/* Was 200 — the route was gated on edit_posts, which a contributor has. That
 * is the security fix this section now records: a role WordPress designed for
 * "may draft a post, may not see anybody else's" has no business enumerating
 * volunteer names, and the per-record filter below was mitigating a gate that
 * should not have been open. */
wp_set_current_user( $gwc_vt_contributor );
gwc_vt_check(
	'a contributor is refused',
	403 === gwc_vt_search( 'Zzytest' )->get_status(),
	'status ' . gwc_vt_search( 'Zzytest' )->get_status()
);

wp_set_current_user( $gwc_vt_author );
gwc_vt_check(
	'so is an author — publishing your own posts is not seeing other people’s',
	403 === gwc_vt_search( 'Zzytest' )->get_status(),
	'status ' . gwc_vt_search( 'Zzytest' )->get_status()
);

wp_set_current_user( $gwc_vt_editor );
gwc_vt_check(
	'an editor may search',
	200 === gwc_vt_search( 'Zzytest' )->get_status(),
	'status ' . gwc_vt_search( 'Zzytest' )->get_status()
);

/* ── A term too short to be one ──────────────────────────────────────────── */

gwc_vt_check(
	'a one-character term is refused',
	400 === gwc_vt_search( 'Z' )->get_status(),
	'status ' . gwc_vt_search( 'Z' )->get_status()
);

/* ── Two keys wide, and asserted as an EXACT set ─────────────────────────────
 * Not "id and label are present". The volunteer post carries an email address,
 * a phone number and a case number, and this endpoint sits one careless line
 * away from returning any of them — which an assertion that only fails on a
 * MISSING key would never catch.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_set_current_user( $gwc_vt_editor );

$gwc_vt_shape = gwc_vt_search( 'Zzytest Rosalind Achebe' )->get_data();

gwc_vt_check( 'the search finds the published record', 1 === count( (array) $gwc_vt_shape ), (string) count( (array) $gwc_vt_shape ) );

if ( ! empty( $gwc_vt_shape ) ) {
	$gwc_vt_keys = array_keys( $gwc_vt_shape[0] );
	sort( $gwc_vt_keys );

	gwc_vt_check(
		'a row is exactly id and label',
		array( 'id', 'label' ) === $gwc_vt_keys,
		implode( ', ', $gwc_vt_keys )
	);

	gwc_vt_check( 'the id is an integer', is_int( $gwc_vt_shape[0]['id'] ) );
	gwc_vt_check( 'the label is a string', is_string( $gwc_vt_shape[0]['label'] ) );
}

/* ── The finding: a contributor may not enumerate what they cannot open ──────
 * Reported by the WordPress.org plugin review team against 1.0.0. The route is
 * gated on edit_posts, which is contributor-level, while the query spans four
 * statuses — three of which are readable by strictly fewer people than that.
 *
 * Note what is NOT the fix: narrowing the query's post_status list. WP_Query
 * without 'perm' => 'readable' hands back private and draft rows to anybody, so
 * the rows still arrive; the route now asks read_post about each one before
 * putting a name in the response.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_set_current_user( $gwc_vt_limited );

$gwc_vt_seen = gwc_vt_labels( gwc_vt_search( 'Zzytest Rosalind' ) );

gwc_vt_check(
	'a limited editor still sees the published volunteer',
	in_array( 'Zzytest Rosalind Achebe', $gwc_vt_seen, true ),
	implode( ' | ', $gwc_vt_seen )
);

gwc_vt_check(
	'a limited editor is not shown the private one',
	! in_array( 'Zzytest Rosalind Vanterpool', $gwc_vt_seen, true ),
	implode( ' | ', $gwc_vt_seen )
);

/* Drafts and pending records by other people ARE readable by anybody with
 * edit_others_posts, and that is correct rather than a leak — it is what the
 * capability means. Asserted so the line is deliberate: the per-record filter
 * excludes what this user genuinely may not read, and nothing more. */
gwc_vt_check(
	'and still sees somebody else’s draft, which edit_others_posts covers',
	in_array( 'Zzytest Rosalind Okonkwo', $gwc_vt_seen, true ),
	implode( ' | ', $gwc_vt_seen )
);

gwc_vt_check(
	'and somebody else’s pending record, likewise',
	in_array( 'Zzytest Rosalind Baptiste', $gwc_vt_seen, true ),
	implode( ' | ', $gwc_vt_seen )
);

/* ── And the fix took nothing away from the people it should not ─────────────
 * Matched on the surname rather than the whole label, because the label is
 * get_the_title() and core runs a private post's title through
 * private_title_format — the administrator sees "Private: Zzytest Rosalind
 * Vanterpool". That is pre-existing and wanted: somebody picking from this list
 * should be told which records are private. Asserted below so it stays a
 * decision rather than a surprise.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_set_current_user( 1 );

$gwc_vt_all  = gwc_vt_labels( gwc_vt_search( 'Zzytest Rosalind' ) );
$gwc_vt_join = implode( ' | ', $gwc_vt_all );

foreach ( array( 'Achebe', 'Vanterpool', 'Okonkwo', 'Baptiste' ) as $gwc_vt_surname ) {
	gwc_vt_check(
		'an administrator still sees ' . $gwc_vt_surname,
		false !== strpos( $gwc_vt_join, 'Zzytest Rosalind ' . $gwc_vt_surname ),
		$gwc_vt_join
	);
}

gwc_vt_check(
	'and a private record is labelled as private',
	in_array( 'Private: Zzytest Rosalind Vanterpool', $gwc_vt_all, true ),
	$gwc_vt_join
);

/* ── The triage suggestion answers to the same rule ──────────────────────────
 * Same class of leak, one screen over: the suggested match on a self-logged
 * submission names a volunteer record, and it looked those up across the same
 * four statuses.
 * ─────────────────────────────────────────────────────────────────────────── */

if ( function_exists( 'gwc_vt_suggest_volunteer_for' ) ) {
	wp_set_current_user( 1 );

	$gwc_vt_as_admin = gwc_vt_suggest_volunteer_for( '', 'Zzytest Rosalind Vanterpool' );

	gwc_vt_check(
		'the triage screen suggests the private record to an administrator',
		$gwc_vt_hidden === (int) $gwc_vt_as_admin['volunteer_id'],
		(string) $gwc_vt_as_admin['volunteer_id']
	);

	wp_set_current_user( $gwc_vt_contributor );

	$gwc_vt_as_contributor = gwc_vt_suggest_volunteer_for( '', 'Zzytest Rosalind Vanterpool' );

	gwc_vt_check(
		'and suggests nothing to a contributor who could not open it',
		0 === (int) $gwc_vt_as_contributor['volunteer_id'],
		(string) $gwc_vt_as_contributor['volunteer_id']
	);
}

/* ── Clean up ────────────────────────────────────────────────────────────── */

wp_set_current_user( 1 );

foreach ( $GLOBALS['gwc_vt_made'] as $gwc_vt_id ) {
	wp_delete_post( $gwc_vt_id, true );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $GLOBALS['gwc_vt_users'] as $gwc_vt_user_id ) {
	wp_delete_user( $gwc_vt_user_id );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

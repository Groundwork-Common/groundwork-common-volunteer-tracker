<?php
/**
 * Partners, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Almost everything here is a question about WordPress rather than about this
 * plugin's arithmetic: what register_taxonomy() does with capabilities it was
 * not given, what wp_set_object_terms() replaces, what wp_delete_term() does to
 * a deleted term's children, and whether a count is right the instant after a
 * merge rather than after a cache flush somebody remembered.
 *
 * ── The three properties that matter most ────────────────────────────────────
 * 1. A MERGE COVERS EVERY OBJECT TYPE. #211 adds hour entries as a second, and
 *    a merge written against volunteers alone would leave every entry pointing
 *    at a deleted term with nothing on any screen to say so. §5 registers a
 *    second object type for real and merges across both — it would pass today
 *    against a volunteers-only implementation, which is exactly why it must
 *    exist before that implementation ships.
 *
 * 2. THE DEFAULT CAPABILITIES ARE BELOW THIS PLUGIN'S GATE. register_taxonomy()
 *    defaults assign_terms to edit_posts, which a contributor holds. §2 asserts
 *    a contributor cannot reach any of the four. This is #213's shape, over a
 *    named contact's telephone number.
 *
 * 3. A MERGE MUST NOT GUESS. Two partners with two different CRM IDs is
 *    the case where the wrong pair has been selected, and silently keeping one
 *    is a correction nobody sees. §7.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/partners.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a
 * top-level assignment is a local while `global` in a helper reaches the real
 * one. The counter would increment one variable and the summary read another,
 * and the script would print ALL PASS under a list of failures. */
$GLOBALS['gwc_vt_failures']  = 0;
$GLOBALS['gwc_vt_partner_posts'] = array();
$GLOBALS['gwc_vt_partner_terms'] = array();
$GLOBALS['gwc_vt_partner_users'] = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_o_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	/* Only on a failure. Printed on a pass as well, the "what was seen" note
	 * reads as an explanation of something going wrong when nothing did. */
	echo $ok ? 'PASS  ' : 'FAIL  ', $label, ( ! $ok && '' !== $got ) ? '  [' . $got . ']' : '', "\n";
}

/**
 * An partner, remembered for the clean-up.
 *
 * @param string $name   What it is called.
 * @param int    $parent Parent term, or 0.
 * @return int
 */
function gwc_vt_o_org( string $name, int $parent = 0 ): int {
	$made = wp_insert_term( $name, GWC_VT_PARTNER_TAXONOMY, array( 'parent' => $parent ) );

	if ( is_wp_error( $made ) ) {
		return 0;
	}

	$id = (int) $made['term_id'];

	$GLOBALS['gwc_vt_partner_terms'][] = $id;

	return $id;
}

/**
 * A volunteer, remembered for the clean-up.
 *
 * @param string $name Their name.
 * @return int
 */
function gwc_vt_o_volunteer( string $name ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_title'  => $name,
			'post_status' => 'publish',
		)
	);

	$GLOBALS['gwc_vt_partner_posts'][] = $id;

	return $id;
}

/**
 * The partners one post holds, as IDs.
 *
 * @param int $post_id The post.
 * @return int[]
 */
function gwc_vt_o_held( int $post_id ): array {
	$ids = wp_get_object_terms( $post_id, GWC_VT_PARTNER_TAXONOMY, array( 'fields' => 'ids' ) );
	$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', (array) $ids );

	sort( $ids );

	return $ids;
}

wp_set_current_user( 1 );

echo "\n── 1. Registered the way hard rule 2 requires ───────────────────\n";

$gwc_vt_o_tax = get_taxonomy( GWC_VT_PARTNER_TAXONOMY );

gwc_vt_o_check( 'the taxonomy is registered', $gwc_vt_o_tax instanceof WP_Taxonomy );

if ( ! $gwc_vt_o_tax instanceof WP_Taxonomy ) {
	echo "\nCANNOT CONTINUE\n";
	exit( 1 );
}

gwc_vt_o_check( 'it is not public', false === $gwc_vt_o_tax->public );
gwc_vt_o_check( 'it declares show_in_rest false', false === $gwc_vt_o_tax->show_in_rest );

/* Asked of the server too, not only of the registration — a third party can add
 * a route back with register_taxonomy_args, and the registration would still
 * read false. */
$gwc_vt_o_probe = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . GWC_VT_PARTNER_TAXONOMY ) );

gwc_vt_o_check(
	'it has no route under /wp/v2/',
	404 === $gwc_vt_o_probe->get_status(),
	'status ' . $gwc_vt_o_probe->get_status()
);

/* Hierarchical is a UI decision — see the long note in inc/partner-taxonomy.php.
 * Flat gives the free-text tag metabox, which lets somebody type a near
 * duplicate into existence from a volunteer's record, which is the whole
 * failure this feature exists to prevent. */
gwc_vt_o_check(
	'it is hierarchical, so the metabox offers a checkbox list rather than free text',
	true === $gwc_vt_o_tax->hierarchical
);

$gwc_vt_o_meta = get_registered_meta_keys( 'term', GWC_VT_PARTNER_TAXONOMY );

foreach ( array_keys( gwc_vt_partner_fields() ) as $gwc_vt_o_key ) {
	gwc_vt_o_check( $gwc_vt_o_key . ' is registered', isset( $gwc_vt_o_meta[ $gwc_vt_o_key ] ) );

	gwc_vt_o_check(
		$gwc_vt_o_key . ' is not in REST',
		empty( $gwc_vt_o_meta[ $gwc_vt_o_key ]['show_in_rest'] )
	);
}

echo "\n── 2. The capabilities core would have got wrong ────────────────\n";

/* register_taxonomy() defaults assign_terms to edit_posts — contributor-level,
 * wp-includes/class-wp-taxonomy.php:434 — and the other three to
 * manage_categories, which Editor holds. Every record screen in this plugin is
 * behind gwc_vt_records_cap(). Left at the defaults, an partner's contact
 * name and telephone number sit behind a weaker gate than a volunteer's name,
 * which is #213 happening again. */
foreach ( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ) as $gwc_vt_o_cap ) {
	gwc_vt_o_check(
		$gwc_vt_o_cap . ' is the records gate, not core\'s default',
		gwc_vt_records_cap() === (string) $gwc_vt_o_tax->cap->{$gwc_vt_o_cap},
		(string) $gwc_vt_o_tax->cap->{$gwc_vt_o_cap}
	);
}

$gwc_vt_o_contributor = wp_insert_user(
	array(
		'user_login' => 'zzytest_org_contributor',
		'user_pass'  => wp_generate_password(),
		'role'       => 'contributor',
	)
);

if ( ! is_wp_error( $gwc_vt_o_contributor ) ) {
	$GLOBALS['gwc_vt_partner_users'][] = (int) $gwc_vt_o_contributor;

	foreach ( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ) as $gwc_vt_o_cap ) {
		gwc_vt_o_check(
			'a contributor cannot ' . $gwc_vt_o_cap,
			! user_can( (int) $gwc_vt_o_contributor, (string) $gwc_vt_o_tax->cap->{$gwc_vt_o_cap} )
		);
	}
}

echo "\n── 3. The fields are cleaned on the way in ──────────────────────\n";

$gwc_vt_o_acme = gwc_vt_o_org( 'Zzytest Acme Corp' );

gwc_vt_set_partner_fields(
	$gwc_vt_o_acme,
	array(
		GWC_VT_PARTNER_CRM_ID        => '  crm-771  ',
		GWC_VT_PARTNER_CONTACT_NAME  => 'Dana <script>alert(1)</script> Whitfield',
		GWC_VT_PARTNER_CONTACT_EMAIL => 'dana@example.org',
		GWC_VT_PARTNER_CONTACT_PHONE => '555-0101',
	)
);

$gwc_vt_o_values = gwc_vt_partner_field_values( $gwc_vt_o_acme );

gwc_vt_o_check( 'the CRM ID is trimmed', 'crm-771' === $gwc_vt_o_values[ GWC_VT_PARTNER_CRM_ID ], $gwc_vt_o_values[ GWC_VT_PARTNER_CRM_ID ] );

gwc_vt_o_check(
	'a script tag does not survive the contact name',
	false === strpos( $gwc_vt_o_values[ GWC_VT_PARTNER_CONTACT_NAME ], '<' ),
	$gwc_vt_o_values[ GWC_VT_PARTNER_CONTACT_NAME ]
);

/* Empty rather than a stored half-address. A field that looks filled in and
 * cannot be used is worse than one that is plainly blank. */
gwc_vt_set_partner_fields( $gwc_vt_o_acme, array( GWC_VT_PARTNER_CONTACT_EMAIL => 'not-an-address' ) );

gwc_vt_o_check(
	'something that is not an address is not stored',
	'' === (string) get_term_meta( $gwc_vt_o_acme, GWC_VT_PARTNER_CONTACT_EMAIL, true )
);

gwc_vt_set_partner_fields( $gwc_vt_o_acme, array( GWC_VT_PARTNER_CONTACT_EMAIL => 'dana@example.org' ) );

echo "\n── 4. A merge moves the relationships, and only those ───────────\n";

$gwc_vt_o_acme_dot = gwc_vt_o_org( 'Zzytest ACME Corp.' );
$gwc_vt_o_rotary   = gwc_vt_o_org( 'Zzytest Rotary' );

$gwc_vt_o_both  = gwc_vt_o_volunteer( 'Zzytest Org Marisol Adeyemi' );
$gwc_vt_o_loser = gwc_vt_o_volunteer( 'Zzytest Org Terrence Blackwood' );
$gwc_vt_o_other = gwc_vt_o_volunteer( 'Zzytest Org Ingrid Halloway' );

/* One volunteer holds BOTH the survivor and the loser, which is the case that
 * catches an append: they must end with the survivor once, not twice. Another
 * holds only the loser. A third holds an unrelated partner that must come
 * through untouched. */
wp_set_object_terms( $gwc_vt_o_both, array( $gwc_vt_o_acme, $gwc_vt_o_acme_dot ), GWC_VT_PARTNER_TAXONOMY );
wp_set_object_terms( $gwc_vt_o_loser, array( $gwc_vt_o_acme_dot, $gwc_vt_o_rotary ), GWC_VT_PARTNER_TAXONOMY );
wp_set_object_terms( $gwc_vt_o_other, array( $gwc_vt_o_rotary ), GWC_VT_PARTNER_TAXONOMY );

$gwc_vt_o_result = gwc_vt_merge_partners( $gwc_vt_o_acme, array( $gwc_vt_o_acme_dot ) );

gwc_vt_o_check( 'the merge reports success', ! is_wp_error( $gwc_vt_o_result ) );

gwc_vt_o_check(
	'the loser is gone',
	null === gwc_vt_partner( $gwc_vt_o_acme_dot )
);

gwc_vt_o_check(
	'somebody who held both ends with the survivor exactly once',
	array( $gwc_vt_o_acme ) === gwc_vt_o_held( $gwc_vt_o_both ),
	implode( ',', gwc_vt_o_held( $gwc_vt_o_both ) )
);

$gwc_vt_o_want = array( $gwc_vt_o_acme, $gwc_vt_o_rotary );
sort( $gwc_vt_o_want );

gwc_vt_o_check(
	'and their other partners survive the merge',
	$gwc_vt_o_want === gwc_vt_o_held( $gwc_vt_o_loser ),
	implode( ',', gwc_vt_o_held( $gwc_vt_o_loser ) )
);

gwc_vt_o_check(
	'somebody unrelated is untouched',
	array( $gwc_vt_o_rotary ) === gwc_vt_o_held( $gwc_vt_o_other )
);

/* The count is read straight after the merge with no flush in between. The
 * screen that renders next links these numbers to filtered lists, and a stale
 * one is a number that disagrees with what it opens. */
$gwc_vt_o_counts = gwc_vt_partner_counts( $gwc_vt_o_acme );

gwc_vt_o_check(
	'the volunteer count is right immediately, with no cache flush',
	2 === (int) $gwc_vt_o_counts[ GWC_VT_VOLUNTEER_TYPE ],
	(string) $gwc_vt_o_counts[ GWC_VT_VOLUNTEER_TYPE ]
);

echo "\n── 4b. The count and the list it links to are one query ─────────\n";

/* ── The trap this plugin keeps falling into ─────────────────────────────────
 * A count on one screen and the filtered list it links to, built from two
 * expressions of the same idea. The dashboard counted overdue volunteers with
 * one function and linked to an unfiltered list; the unlogged-hours nag counted
 * event slots and linked to a view that excluded them. Both said a number and
 * then showed something else.
 *
 * This one had exactly that shape for about an hour: the count passed
 * include_children => false and the list-table filter passed true, so a parent
 * partner read "1 volunteer" over a list of two. Both now come from
 * gwc_vt_partner_query(), and this is the check that keeps them there.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwc_vt_o_parent = gwc_vt_o_org( 'Zzytest National Grocers' );
$gwc_vt_o_branch = gwc_vt_o_org( 'Zzytest Northside Grocers', $gwc_vt_o_parent );

$gwc_vt_o_at_top    = gwc_vt_o_volunteer( 'Zzytest Org Amara Nakamura' );
$gwc_vt_o_at_branch = gwc_vt_o_volunteer( 'Zzytest Org Devon Fairweather' );

wp_set_object_terms( $gwc_vt_o_at_top, array( $gwc_vt_o_parent ), GWC_VT_PARTNER_TAXONOMY );
wp_set_object_terms( $gwc_vt_o_at_branch, array( $gwc_vt_o_branch ), GWC_VT_PARTNER_TAXONOMY );

$gwc_vt_o_parent_counts = gwc_vt_partner_counts( $gwc_vt_o_parent );

gwc_vt_o_check(
	'a parent partner counts the people who came with its chapters',
	2 === (int) $gwc_vt_o_parent_counts[ GWC_VT_VOLUNTEER_TYPE ],
	(string) $gwc_vt_o_parent_counts[ GWC_VT_VOLUNTEER_TYPE ]
);

/* And the list the number links to returns the same people. Driven as a real
 * WP_Query with the same tax_query the filter sets, because asserting the two
 * functions return equal arrays would pass for two copies that are equally
 * wrong. */
$gwc_vt_o_listed = get_posts(
	array(
		'post_type'      => GWC_VT_VOLUNTEER_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => gwc_vt_partner_query( $gwc_vt_o_parent ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the assertion is about this exact query.
	)
);

gwc_vt_o_check(
	'and the list shows exactly what the count counted',
	count( (array) $gwc_vt_o_listed ) === (int) $gwc_vt_o_parent_counts[ GWC_VT_VOLUNTEER_TYPE ],
	'counted ' . $gwc_vt_o_parent_counts[ GWC_VT_VOLUNTEER_TYPE ] . ', listed ' . count( (array) $gwc_vt_o_listed )
);

/* And an inactive volunteer is still one of that partner's people.
 *
 * WP_Query's 'any' drops gwc_vt_vol_inactive — its exclude_from_search is not
 * false — while the volunteer list's All view shows it, because the status sets
 * show_in_admin_all_list => true on purpose: somebody who has stopped coming
 * keeps their hours and their name. A count written with 'any' therefore reports
 * fewer people than the list it links to shows. Both traps CLAUDE.md records,
 * in one query. */
$gwc_vt_o_stopped = gwc_vt_o_volunteer( 'Zzytest Org Callum Oyelaran' );

wp_update_post(
	array(
		'ID'          => $gwc_vt_o_stopped,
		'post_status' => GWC_VT_VOLUNTEER_INACTIVE,
	)
);

wp_set_object_terms( $gwc_vt_o_stopped, array( $gwc_vt_o_parent ), GWC_VT_PARTNER_TAXONOMY );

$gwc_vt_o_with_stopped = gwc_vt_partner_counts( $gwc_vt_o_parent );

gwc_vt_o_check(
	'somebody who has gone inactive is still counted, as the list still lists them',
	3 === (int) $gwc_vt_o_with_stopped[ GWC_VT_VOLUNTEER_TYPE ],
	(string) $gwc_vt_o_with_stopped[ GWC_VT_VOLUNTEER_TYPE ]
);

echo "\n── 5. And it covers every object type, not just volunteers ──────\n";

/* THE CHECK THIS FILE EXISTS FOR. #211 registers hour entries as a second
 * object type, and a merge written against volunteers alone would silently
 * orphan every entry relationship. Registered here for real rather than
 * asserted about, so the check fails against that implementation today. */
register_taxonomy_for_object_type( GWC_VT_PARTNER_TAXONOMY, GWC_VT_ENTRY_TYPE );

$gwc_vt_o_second = gwc_vt_o_org( 'Zzytest Acme Corporation' );

$gwc_vt_o_entry = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_ENTRY_TYPE,
		'post_title'  => 'Zzytest org entry',
		'post_status' => 'publish',
	)
);

$GLOBALS['gwc_vt_partner_posts'][] = $gwc_vt_o_entry;

wp_set_object_terms( $gwc_vt_o_entry, array( $gwc_vt_o_second ), GWC_VT_PARTNER_TAXONOMY );

gwc_vt_o_check(
	'an entry can carry an partner once the type is registered',
	array( $gwc_vt_o_second ) === gwc_vt_o_held( $gwc_vt_o_entry )
);

gwc_vt_merge_partners( $gwc_vt_o_acme, array( $gwc_vt_o_second ) );

gwc_vt_o_check(
	'a merge moves an ENTRY relationship, not only a volunteer one',
	array( $gwc_vt_o_acme ) === gwc_vt_o_held( $gwc_vt_o_entry ),
	implode( ',', gwc_vt_o_held( $gwc_vt_o_entry ) )
);

unregister_taxonomy_for_object_type( GWC_VT_PARTNER_TAXONOMY, GWC_VT_ENTRY_TYPE );

echo "\n── 6. Children are moved deliberately, not by wp_delete_term ────\n";

/* wp_delete_term() reparents a deleted term's children to THAT TERM'S PARENT,
 * which silently moves a local chapter out from under the national body
 * somebody has just merged into. */
$gwc_vt_o_national = gwc_vt_o_org( 'Zzytest National Trust Org' );
$gwc_vt_o_dupe     = gwc_vt_o_org( 'Zzytest National Trust Organisation' );
$gwc_vt_o_chapter  = gwc_vt_o_org( 'Zzytest Riverside Chapter', $gwc_vt_o_dupe );

gwc_vt_merge_partners( $gwc_vt_o_national, array( $gwc_vt_o_dupe ) );

$gwc_vt_o_child = gwc_vt_partner( $gwc_vt_o_chapter );

gwc_vt_o_check(
	'a child of a merged partner moves to the survivor',
	$gwc_vt_o_child && $gwc_vt_o_national === (int) $gwc_vt_o_child->parent,
	$gwc_vt_o_child ? 'parent ' . (int) $gwc_vt_o_child->parent : 'gone'
);

/* And the other way round: the survivor hanging off a term about to disappear
 * must walk up past it rather than be left pointing at a deleted row. */
$gwc_vt_o_top    = gwc_vt_o_org( 'Zzytest Umbrella Body' );
$gwc_vt_o_middle = gwc_vt_o_org( 'Zzytest Middle Body', $gwc_vt_o_top );
$gwc_vt_o_leaf   = gwc_vt_o_org( 'Zzytest Leaf Body', $gwc_vt_o_middle );

gwc_vt_merge_partners( $gwc_vt_o_leaf, array( $gwc_vt_o_middle ) );

$gwc_vt_o_survivor = gwc_vt_partner( $gwc_vt_o_leaf );

gwc_vt_o_check(
	'a survivor parented under a loser walks up to the loser\'s parent',
	$gwc_vt_o_survivor && $gwc_vt_o_top === (int) $gwc_vt_o_survivor->parent,
	$gwc_vt_o_survivor ? 'parent ' . (int) $gwc_vt_o_survivor->parent : 'gone'
);

echo "\n── 7. Details are chosen, never guessed ─────────────────────────\n";

$gwc_vt_o_a = gwc_vt_o_org( 'Zzytest Bright Futures' );
$gwc_vt_o_b = gwc_vt_o_org( 'Zzytest Bright Futures Inc' );

gwc_vt_set_partner_fields( $gwc_vt_o_a, array( GWC_VT_PARTNER_CRM_ID => 'A-1', GWC_VT_PARTNER_CONTACT_PHONE => '555-0111' ) );
gwc_vt_set_partner_fields( $gwc_vt_o_b, array( GWC_VT_PARTNER_CRM_ID => 'B-2' ) );

$gwc_vt_o_conflicts = gwc_vt_partner_field_conflicts( array( $gwc_vt_o_a, $gwc_vt_o_b ) );

gwc_vt_o_check(
	'two different CRM IDs are reported as a conflict',
	isset( $gwc_vt_o_conflicts[ GWC_VT_PARTNER_CRM_ID ] )
		&& 2 === count( $gwc_vt_o_conflicts[ GWC_VT_PARTNER_CRM_ID ] )
);

/* One value and three blanks is not a disagreement, it is the answer. */
gwc_vt_o_check(
	'a field only one of them fills in is not a conflict',
	! isset( $gwc_vt_o_conflicts[ GWC_VT_PARTNER_CONTACT_PHONE ] )
);

gwc_vt_merge_partners( $gwc_vt_o_a, array( $gwc_vt_o_b ), array( GWC_VT_PARTNER_CRM_ID => 'B-2' ) );

gwc_vt_o_check(
	'the chosen value is what the survivor ends up with',
	'B-2' === (string) get_term_meta( $gwc_vt_o_a, GWC_VT_PARTNER_CRM_ID, true ),
	(string) get_term_meta( $gwc_vt_o_a, GWC_VT_PARTNER_CRM_ID, true )
);

gwc_vt_o_check(
	'and an uncontested value is carried across',
	'555-0111' === (string) get_term_meta( $gwc_vt_o_a, GWC_VT_PARTNER_CONTACT_PHONE, true )
);

echo "\n── 8. The duplicate finder proposes, and knows when not to ──────\n";

gwc_vt_o_check(
	'punctuation and capitals reduce to the same shape',
	gwc_vt_partner_normalize( 'Acme Corp' ) === gwc_vt_partner_normalize( 'ACME Corp.' )
);

gwc_vt_o_check(
	'and so does a trailing legal form',
	gwc_vt_partner_normalize( 'Bright Futures' ) === gwc_vt_partner_normalize( 'Bright Futures, Inc.' )
);

gwc_vt_o_check(
	'an ampersand and the word do not split an partner in two',
	gwc_vt_partner_normalize( 'Bread & Roses' ) === gwc_vt_partner_normalize( 'Bread and Roses' )
);

/* The false positive that would be worst: two real branches folded into one,
 * irreversibly, because an edit-distance threshold was loose enough to catch a
 * typo. */
gwc_vt_o_check(
	'two real branches are NOT proposed as the same partner',
	gwc_vt_partner_normalize( 'Acme North' ) !== gwc_vt_partner_normalize( 'Acme South' )
);

$gwc_vt_o_d1 = gwc_vt_o_org( 'Zzytest Harbour House' );
$gwc_vt_o_d2 = gwc_vt_o_org( 'Zzytest Harbour House LLC' );

$gwc_vt_o_clusters = gwc_vt_partner_duplicate_clusters();
$gwc_vt_o_found    = false;

foreach ( $gwc_vt_o_clusters as $gwc_vt_o_cluster ) {
	$gwc_vt_o_ids = array_map( 'intval', wp_list_pluck( $gwc_vt_o_cluster, 'term_id' ) );

	if ( in_array( $gwc_vt_o_d1, $gwc_vt_o_ids, true ) && in_array( $gwc_vt_o_d2, $gwc_vt_o_ids, true ) ) {
		$gwc_vt_o_found = true;
	}
}

gwc_vt_o_check( 'a real pair of duplicates is offered', $gwc_vt_o_found );

echo "\n── 9. Anonymizing takes the partners with the name ─────────\n";

$gwc_vt_o_leaver = gwc_vt_o_volunteer( 'Zzytest Org Priya Ramanathan' );

wp_set_object_terms( $gwc_vt_o_leaver, array( $gwc_vt_o_acme ), GWC_VT_PARTNER_TAXONOMY );

$gwc_vt_o_hours = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_ENTRY_TYPE,
		'post_title'  => 'Zzytest org hours',
		'post_status' => 'publish',
		'post_parent' => $gwc_vt_o_leaver,
	)
);

$GLOBALS['gwc_vt_partner_posts'][] = $gwc_vt_o_hours;

gwc_vt_anonymize_volunteer( $gwc_vt_o_leaver );

/* A term on a PERSON is a property of them, which is the category anonymizing
 * exists to remove — and "Former volunteer #412, Acme Corp" is close to naming
 * somebody when Acme sent two people. #211 keeps an ENTRY's term for the
 * opposite reason: that one is a fact about a Saturday. */
gwc_vt_o_check(
	'an anonymized volunteer holds no partners',
	array() === gwc_vt_o_held( $gwc_vt_o_leaver ),
	implode( ',', gwc_vt_o_held( $gwc_vt_o_leaver ) )
);

gwc_vt_o_check(
	'and their hours are still there',
	GWC_VT_ENTRY_TYPE === get_post_type( $gwc_vt_o_hours )
);

gwc_vt_o_check(
	'and the partner itself is untouched',
	null !== gwc_vt_partner( $gwc_vt_o_acme )
);

echo "\n── 10. Nothing about this reaches a letter ──────────────────────\n";

/* #211 is emphatic and this is where it is enforced: the letter is a record of
 * what THE ORGANIZATION — the nonprofit itself — observed about a person, and
 * the name of the company that person came with is third-party information the
 * volunteer did not ask to have disclosed to a court. It is also not a field
 * the reference code is computed over.
 *
 * ── Matched as a token, not as a substring ──────────────────────────────────
 * Both of these files are thick with the HOST organization: gwc_vt_org_name(),
 * gwc_vt_org_contact(), the gwcvt-org-* letterhead classes, {org} in the
 * wording. None of that is a partner.
 *
 * Under this taxonomy's first name, gwc_vt_org, a plain strpos() reported both
 * files as reading it, and both reports were wrong. That near-miss is what got
 * the taxonomy renamed. The token match stays anyway — the next one will not
 * announce itself either. */
$gwc_vt_o_letter_files = array( 'inc/letter.php', 'inc/render.php' );

foreach ( $gwc_vt_o_letter_files as $gwc_vt_o_file ) {
	$gwc_vt_o_source = (string) file_get_contents( GWC_VT_DIR . $gwc_vt_o_file );

	$gwc_vt_o_names = preg_match( '/\bGWC_VT_PARTNER_(TAXONOMY|CRM_ID|CONTACT_NAME|CONTACT_EMAIL|CONTACT_PHONE)\b/', $gwc_vt_o_source )
		|| preg_match( "/'" . preg_quote( GWC_VT_PARTNER_TAXONOMY, '/' ) . "'/", $gwc_vt_o_source );

	gwc_vt_o_check(
		$gwc_vt_o_file . ' never reads a volunteer\'s partner',
		! $gwc_vt_o_names
	);
}

/* And the same asked of the built letter rather than of the source, because the
 * check above is a grep and a grep can be satisfied by indirection. */
$gwc_vt_o_lv = gwc_vt_o_volunteer( 'Zzytest Org Solveig Mbeki' );

wp_set_object_terms( $gwc_vt_o_lv, array( $gwc_vt_o_acme ), GWC_VT_PARTNER_TAXONOMY );

$gwc_vt_o_letter = gwc_vt_build_letter( $gwc_vt_o_lv, array() );

gwc_vt_o_check(
	'a built letter carries no partner the volunteer came with',
	! $gwc_vt_o_letter || false === strpos( wp_json_encode( $gwc_vt_o_letter ), 'Zzytest Acme Corp' )
);

echo "\n── 11. The export finds a contact; the eraser reports them ──────\n";

gwc_vt_set_partner_fields(
	$gwc_vt_o_acme,
	array(
		GWC_VT_PARTNER_CONTACT_NAME  => 'Dana Whitfield',
		GWC_VT_PARTNER_CONTACT_EMAIL => 'zzytest-dana@example.org',
	)
);

$gwc_vt_o_export = gwc_vt_export_personal_data( 'zzytest-dana@example.org', 1 );
$gwc_vt_o_groups = wp_list_pluck( (array) $gwc_vt_o_export['data'], 'group_id' );

gwc_vt_o_check(
	'an partner contact appears in an export request',
	in_array( 'gwc_vt_partner_contact', (array) $gwc_vt_o_groups, true ),
	implode( ',', (array) $gwc_vt_o_groups )
);

/* Case-insensitively, because somebody will type it however their mail client
 * shows it. */
$gwc_vt_o_upper  = gwc_vt_export_personal_data( 'ZZYTEST-Dana@Example.org', 1 );
$gwc_vt_o_ugroup = wp_list_pluck( (array) $gwc_vt_o_upper['data'], 'group_id' );

gwc_vt_o_check(
	'and the address is matched whatever its case',
	in_array( 'gwc_vt_partner_contact', (array) $gwc_vt_o_ugroup, true )
);

/* Exported, deliberately not erased. The partner is a shared record and
 * one employee leaving must not empty the details everybody uses — so the
 * request names it for a human instead. Asserted as a NEGATIVE as well, or
 * nothing would notice an eraser that quietly started clearing them. */
$gwc_vt_o_erase = gwc_vt_erase_personal_data( 'zzytest-dana@example.org', 1 );

gwc_vt_o_check(
	'an erasure request does not clear an partner contact',
	'zzytest-dana@example.org' === (string) get_term_meta( $gwc_vt_o_acme, GWC_VT_PARTNER_CONTACT_EMAIL, true ),
	(string) get_term_meta( $gwc_vt_o_acme, GWC_VT_PARTNER_CONTACT_EMAIL, true )
);

gwc_vt_o_check(
	'and it says so, naming the partner to edit',
	! empty( $gwc_vt_o_erase['messages'] )
		&& false !== strpos( implode( ' ', (array) $gwc_vt_o_erase['messages'] ), 'Zzytest Acme Corp' ),
	implode( ' | ', (array) $gwc_vt_o_erase['messages'] )
);

echo "\n── 12. A merge refuses what it cannot do ────────────────────────\n";

gwc_vt_o_check(
	'merging a term into itself is refused',
	is_wp_error( gwc_vt_merge_partners( $gwc_vt_o_acme, array( $gwc_vt_o_acme ) ) )
);

gwc_vt_o_check(
	'merging into a term that does not exist is refused',
	is_wp_error( gwc_vt_merge_partners( 99999999, array( $gwc_vt_o_acme ) ) )
);

gwc_vt_o_check(
	'a merge with nothing to fold in is refused',
	is_wp_error( gwc_vt_merge_partners( $gwc_vt_o_acme, array() ) )
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_partner_posts'] as $gwc_vt_o_id ) {
	wp_delete_post( (int) $gwc_vt_o_id, true );
}

foreach ( $GLOBALS['gwc_vt_partner_terms'] as $gwc_vt_o_id ) {
	wp_delete_term( (int) $gwc_vt_o_id, GWC_VT_PARTNER_TAXONOMY );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $GLOBALS['gwc_vt_partner_users'] as $gwc_vt_o_id ) {
	wp_delete_user( (int) $gwc_vt_o_id );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

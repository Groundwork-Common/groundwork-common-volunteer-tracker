<?php
/**
 * The notice that points a new install at the guide, and the end of it.
 *
 * ── Why this is worth a script ──────────────────────────────────────────────
 * A notice is the easiest thing in WordPress to get permanently wrong. The two
 * failures are opposites and both are invisible from the code:
 *
 *   - it never goes away, because the dismissal was written to somewhere the
 *     next request does not read; or
 *   - it never appears, because a condition is inverted and the only site that
 *     would have shown it is one nobody tests on — a brand new one.
 *
 * So this drives the real predicate against real state: a fresh site, a site
 * with a record in it, and a reader who has dismissed it. And it drives the
 * real handler, because "the dismissal is remembered" is the whole promise.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/welcome.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a plain
 * assignment here is a local that the helpers below never see. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_wc_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_wc_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Put the reader on one of this plugin's screens.
 *
 * @param string $screen_id A WP_Screen id.
 */
function gwc_vt_wc_on_screen( string $screen_id ): void {
	set_current_screen( $screen_id );
}

require_once ABSPATH . 'wp-admin/includes/screen.php';

wp_set_current_user( 1 );

/* ── Being a new site, on a site that is not one ──────────────────────────────
 * The state this notice is about is the one nobody runs a test suite on: a
 * WordPress with nothing in it. A script that only asserted "whatever this site
 * is, the answer matches" would pass here, pass in CI, and never once execute
 * the branch that draws the notice.
 *
 * gwc_vt_has_any_records() asks wp_count_posts(), which is filterable, so the
 * emptiness is faked at the source rather than by deleting a seeded site's
 * records or by adding a hook to the plugin for the benefit of its tests. Both
 * states are then real, and both are exercised on every site this runs on.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Report every post type as empty.
 *
 * @param object $counts Counts by status.
 * @return object
 */
function gwc_vt_wc_no_records( $counts ) {
	foreach ( array_keys( (array) $counts ) as $status ) {
		$counts->$status = 0;
	}

	return $counts;
}

echo "\n── 1. A site with nothing in it ─────────────────────────────────────\n";

delete_user_meta( get_current_user_id(), GWC_VT_WELCOME_META );

gwc_vt_wc_on_screen( 'edit-' . GWC_VT_ENTRY_TYPE );

gwc_vt_wc_check(
	'the plugin screen is recognised as one of ours',
	gwc_vt_is_plugin_screen()
);

add_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

gwc_vt_wc_check(
	'a site with no records is a new one',
	! gwc_vt_has_any_records()
);

gwc_vt_wc_check(
	'and it is shown the notice',
	gwc_vt_welcome_notice_applies()
);

/* And it renders something with both links in it. Asserted on the markup, not
 * on the predicate, because a notice whose renderer returns early is a notice
 * that does not exist however true the predicate is. */
ob_start();
gwc_vt_render_welcome_notice();
$GLOBALS['gwc_vt_wc_html'] = (string) ob_get_clean();

gwc_vt_wc_check(
	'it draws, with the way in and the way out',
	false !== strpos( $GLOBALS['gwc_vt_wc_html'], 'page=' . GWC_VT_HELP_PAGE )
		&& false !== strpos( $GLOBALS['gwc_vt_wc_html'], 'gwc_vt_dismiss_welcome' )
		&& false !== strpos( $GLOBALS['gwc_vt_wc_html'], '_wpnonce' ),
	strlen( $GLOBALS['gwc_vt_wc_html'] ) . ' bytes'
);

remove_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

echo "\n── 2. Where it does not belong ──────────────────────────────────────\n";

/* Not on somebody else's screen: a plugin that puts a notice on every page in
 * wp-admin is the reason people install notice-hiding plugins. Still pretending
 * the site is empty, or both checks below pass because there is nothing to show
 * anybody rather than because the screen is wrong. */
add_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

gwc_vt_wc_on_screen( 'edit-post' );

gwc_vt_wc_check(
	'not on a screen that is not ours',
	! gwc_vt_welcome_notice_applies()
);

/* Not on the guide. Somebody reading it has already followed the notice. */
gwc_vt_wc_on_screen( 'volunteer-tracker_page_' . GWC_VT_HELP_PAGE );

gwc_vt_wc_check(
	'nor on the guide it points at',
	! gwc_vt_welcome_notice_applies()
);

echo "\n── 3. Once there is a record, the site is not new ───────────────────\n";

gwc_vt_wc_on_screen( 'edit-' . GWC_VT_ENTRY_TYPE );

/* The real count again, and a real volunteer added to it. */
remove_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

$GLOBALS['gwc_vt_wc_vol'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzwelcome Subject',
	)
);

$GLOBALS['gwc_vt_wc_made'][] = $GLOBALS['gwc_vt_wc_vol'];

/* wp_count_posts() caches per type, and gwc_vt_has_any_records() reads it. */
wp_cache_delete( _count_posts_cache_key( GWC_VT_VOLUNTEER_TYPE ), 'counts' );

gwc_vt_wc_check(
	'a site with a volunteer on it is no longer new',
	gwc_vt_has_any_records() && ! gwc_vt_welcome_notice_applies()
);

echo "\n── 4. Dismissing it, which is the promise ───────────────────────────\n";

/* Back to a new site, so the dismissal is the only thing that can hide it —
 * otherwise this section passes on a site that would have hidden it anyway. */
foreach ( $GLOBALS['gwc_vt_wc_made'] as $gwc_vt_wc_id ) {
	wp_delete_post( (int) $gwc_vt_wc_id, true );
}

$GLOBALS['gwc_vt_wc_made'] = array();

wp_cache_delete( _count_posts_cache_key( GWC_VT_VOLUNTEER_TYPE ), 'counts' );
add_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

gwc_vt_wc_check(
	'the notice is back, so what follows is about the dismissal',
	gwc_vt_welcome_notice_applies()
);

update_user_meta( get_current_user_id(), GWC_VT_WELCOME_META, gwc_vt_today() );

gwc_vt_wc_check(
	'and a dismissal hides it',
	! gwc_vt_welcome_notice_applies()
);

/* Per person, not per site. The coordinator who joins in March did not dismiss
 * anything in January, and is the reader this is for. */
$GLOBALS['gwc_vt_wc_other'] = (int) wp_insert_user(
	array(
		'user_login' => 'zzwelcome-reader',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'administrator',
	)
);

if ( $GLOBALS['gwc_vt_wc_other'] > 0 ) {
	wp_set_current_user( $GLOBALS['gwc_vt_wc_other'] );

	gwc_vt_wc_check(
		'somebody else still gets it',
		gwc_vt_welcome_notice_applies(),
		'user ' . $GLOBALS['gwc_vt_wc_other']
	);

	wp_set_current_user( 1 );
}

echo "\n── 5. The dismissal is written where the next request reads ─────────\n";

/* The handler, not a stand-in for it: the failure this guards against is a
 * dismissal written to a key the predicate does not look at, which no amount of
 * asserting on the predicate alone can see. */
delete_user_meta( get_current_user_id(), GWC_VT_WELCOME_META );

$_REQUEST['_wpnonce'] = wp_create_nonce( 'gwc_vt_dismiss_welcome_' . get_current_user_id() );
$_GET['_wpnonce']     = $_REQUEST['_wpnonce'];

/* The handler ends in exit(), which is right in a request and fatal in a test.
 * The redirect is caught and turned into an exception instead — the same shape
 * the delivery scripts use. */
add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new Exception( 'redirected: ' . (string) $location );
	},
	1
);

$GLOBALS['gwc_vt_wc_went'] = '';

try {
	gwc_vt_handle_dismiss_welcome();
} catch ( Exception $e ) {
	$GLOBALS['gwc_vt_wc_went'] = $e->getMessage();
}

remove_all_filters( 'wp_redirect', 1 );
unset( $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );

gwc_vt_wc_check(
	'the handler records it against the person who pressed it',
	'' !== (string) get_user_meta( get_current_user_id(), GWC_VT_WELCOME_META, true ),
	(string) get_user_meta( get_current_user_id(), GWC_VT_WELCOME_META, true )
);

gwc_vt_wc_check(
	'and sends them somewhere rather than to a blank page',
	false !== strpos( $GLOBALS['gwc_vt_wc_went'], 'redirected: http' ),
	$GLOBALS['gwc_vt_wc_went']
);

gwc_vt_wc_check(
	'and the notice stays gone afterwards',
	! gwc_vt_welcome_notice_applies()
);

remove_filter( 'wp_count_posts', 'gwc_vt_wc_no_records' );

echo "\n── Clean up ─────────────────────────────────────────────────────────\n";

/**
 * Put the site back exactly as it was found.
 */
function gwc_vt_wc_cleanup(): void {
	foreach ( (array) $GLOBALS['gwc_vt_wc_made'] as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	delete_user_meta( 1, GWC_VT_WELCOME_META );

	if ( ! empty( $GLOBALS['gwc_vt_wc_other'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $GLOBALS['gwc_vt_wc_other'] );
	}
}

register_shutdown_function( 'gwc_vt_wc_cleanup' );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

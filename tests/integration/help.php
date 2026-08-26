<?php
/**
 * Every screen this plugin registers has a Help tab.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 * Six of the plugin's ten screens had no contextual help, and nothing failed
 * when they shipped without it. The hook table in README.md stays current
 * because there is a RULE forcing it and somebody notices when it is broken;
 * help text had no equivalent, so it drifted for as long as features kept
 * arriving — including the single volunteer record, which is where a
 * background check gets recorded and where a photograph lives.
 *
 * This is that rule. A new screen either gets a help tab or fails here.
 *
 * ── Why it drives real screens ──────────────────────────────────────────────
 * Not by reading gwc_vt_add_screen_help() and checking it mentions a constant —
 * that would pass for a branch that returns before adding anything, and for a
 * tab added to the wrong screen id. It sets each screen for real and asks
 * WP_Screen what tabs it ended up with, which is what a coordinator pressing
 * Help actually gets.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/help.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_help_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Set a screen, run the help hook, and give back the tabs it gained.
 *
 * @param string $screen_id A WP_Screen id.
 * @return array<string, array> Whatever WP_Screen ended up holding.
 */
function gwc_vt_help_tabs_for( string $screen_id ): array {
	set_current_screen( $screen_id );

	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen ) {
		return array();
	}

	/* Cleared first: set_current_screen() reuses an object between calls, so
	 * without this a screen with no help of its own inherits the previous
	 * one's tabs and passes on somebody else's work. */
	foreach ( array_keys( $screen->get_help_tabs() ) as $existing ) {
		$screen->remove_help_tab( $existing );
	}

	do_action( 'current_screen', $screen );

	/* Settings adds its help from its own load- hook rather than from
	 * current_screen — a legitimate second route, and one this file did not
	 * follow when it was written, so Settings was the only registered screen
	 * the guard did not actually guard. Firing it here means the test covers
	 * the screen rather than one mechanism. */
	if ( false !== strpos( $screen_id, GWC_VT_SETTINGS_PAGE ) ) {
		do_action( 'gwc_vt_settings_screen_loaded' );
	}

	return $screen->get_help_tabs();
}

wp_set_current_user( 1 );

/* Letters help is gated on the feature being switched on, so switch it on —
 * otherwise those two screens are skipped for a reason that has nothing to do
 * with whether anybody wrote their help. */
$GLOBALS['gwc_vt_help_opts'] = get_option( GWC_VT_SETTINGS_OPTION, array() );

update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge( $GLOBALS['gwc_vt_help_opts'], array( 'letters_enabled' => 1 ) )
);
gwc_vt_settings_cache( null, true );

echo "\n── Every screen the plugin registers ────────────────────────────\n";

/* The page slugs, read from the constants rather than typed out again, so a
 * renamed screen breaks this loudly instead of silently dropping out of it. */
/* Read from the plugin rather than listed again here. gwc_vt_help_screens() is
 * what the Help page renders from, so a screen missing from it is a screen
 * missing from the page AND from this guard — one omission with one symptom,
 * rather than a list here that can quietly disagree with the one there. */
$GLOBALS['gwc_vt_help_screens'] = gwc_vt_help_screens();

/* Plus the two the page does not carry a heading for: producing a letter and
 * the letters log are listed only when letters are switched on, and they are
 * switched on above. Asserting the count keeps that honest. */
gwc_vt_help_check(
	'the page covers every screen with help',
	count( $GLOBALS['gwc_vt_help_screens'] ) >= 13,
	count( $GLOBALS['gwc_vt_help_screens'] ) . ' screen(s)'
);

foreach ( $GLOBALS['gwc_vt_help_screens'] as $gwc_vt_help_what => $gwc_vt_help_id ) {
	$gwc_vt_help_tabs = gwc_vt_help_tabs_for( $gwc_vt_help_id );

	gwc_vt_help_check(
		$gwc_vt_help_what . ' has a help tab',
		array() !== $gwc_vt_help_tabs,
		array() === $gwc_vt_help_tabs ? 'none on ' . $gwc_vt_help_id : count( $gwc_vt_help_tabs ) . ' tab(s)'
	);
}

echo "\n── The tabs say something ───────────────────────────────────────\n";

/* A tab with an empty body is a tab somebody opens once and never again. */
$gwc_vt_help_thin = array();

foreach ( $GLOBALS['gwc_vt_help_screens'] as $gwc_vt_help_what => $gwc_vt_help_id ) {
	foreach ( gwc_vt_help_tabs_for( $gwc_vt_help_id ) as $gwc_vt_help_tab ) {
		if ( strlen( wp_strip_all_tags( (string) ( $gwc_vt_help_tab['content'] ?? '' ) ) ) < 40 ) {
			$gwc_vt_help_thin[] = $gwc_vt_help_what . '/' . (string) ( $gwc_vt_help_tab['id'] ?? '?' );
		}
	}
}

gwc_vt_help_check(
	'no tab is empty or nearly so',
	array() === $gwc_vt_help_thin,
	implode( ', ', $gwc_vt_help_thin )
);

echo "\n── The page carries the same text, and never a second copy ──────\n";

/* The page is a second RENDERING of the tabs, not a second copy of the words.
 * If it ever grows its own text, the two drift and the one nobody edited is
 * the one somebody reads — so this asserts the page's content comes from the
 * screens rather than from itself. */
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/screen.php';

ob_start();
gwc_vt_render_help_page();
$GLOBALS['gwc_vt_help_html'] = (string) ob_get_clean();

gwc_vt_help_check(
	'the page renders',
	'' !== trim( $GLOBALS['gwc_vt_help_html'] ),
	'' === trim( $GLOBALS['gwc_vt_help_html'] ) ? 'drew nothing' : strlen( $GLOBALS['gwc_vt_help_html'] ) . ' bytes'
);

$GLOBALS['gwc_vt_help_expected'] = 0;
$GLOBALS['gwc_vt_help_absent']   = array();

foreach ( $GLOBALS['gwc_vt_help_screens'] as $gwc_vt_help_what => $gwc_vt_help_id ) {
	foreach ( gwc_vt_help_tabs_for_screen( (string) $gwc_vt_help_id ) as $gwc_vt_help_tab ) {
		++$GLOBALS['gwc_vt_help_expected'];

		/* The tab's title, and the first words of its body — enough that a page
		 * printing headings with somebody else's text under them fails. */
		$gwc_vt_help_body = wp_strip_all_tags( (string) ( $gwc_vt_help_tab['content'] ?? '' ) );
		$gwc_vt_help_open = trim( substr( $gwc_vt_help_body, 0, 40 ) );

		if ( false === strpos( $GLOBALS['gwc_vt_help_html'], (string) ( $gwc_vt_help_tab['title'] ?? '' ) )
			|| ( '' !== $gwc_vt_help_open && false === strpos( wp_strip_all_tags( $GLOBALS['gwc_vt_help_html'] ), $gwc_vt_help_open ) ) ) {
			$GLOBALS['gwc_vt_help_absent'][] = $gwc_vt_help_what . '/' . (string) ( $gwc_vt_help_tab['id'] ?? '?' );
		}
	}
}

gwc_vt_help_check(
	'every tab on every screen appears on the page',
	array() === $GLOBALS['gwc_vt_help_absent'],
	implode( ', ', $GLOBALS['gwc_vt_help_absent'] )
);

gwc_vt_help_check(
	'and the page has a heading for each screen',
	substr_count( $GLOBALS['gwc_vt_help_html'], '<h2 id=' ) === count( $GLOBALS['gwc_vt_help_screens'] ),
	substr_count( $GLOBALS['gwc_vt_help_html'], '<h2 id=' ) . ' of ' . count( $GLOBALS['gwc_vt_help_screens'] )
);

gwc_vt_help_check(
	'with a contents entry for every one of them',
	substr_count( $GLOBALS['gwc_vt_help_html'], '<li><a href="#' ) === substr_count( $GLOBALS['gwc_vt_help_html'], '<h2 id=' ),
	substr_count( $GLOBALS['gwc_vt_help_html'], '<li><a href="#' ) . ' link(s)'
);

/* Reading the page must not change the screen the reader is on — the whole
 * reason it uses WP_Screen::get() rather than set_current_screen(). */
set_current_screen( 'edit-post' );
$GLOBALS['gwc_vt_help_before'] = get_current_screen()->id;

ob_start();
gwc_vt_render_help_page();
ob_end_clean();

gwc_vt_help_check(
	'and rendering it does not move the current screen',
	$GLOBALS['gwc_vt_help_before'] === get_current_screen()->id,
	$GLOBALS['gwc_vt_help_before'] . ' became ' . get_current_screen()->id
);

echo "\n── A screen that is not ours is left alone ──────────────────────\n";

/* The hook runs on every admin screen on the site. Adding a tab to somebody
 * else's is the failure nobody would notice from inside this plugin. */
gwc_vt_help_check(
	'the posts list gets nothing from us',
	array() === gwc_vt_help_tabs_for( 'edit-post' ),
	implode( ', ', array_keys( gwc_vt_help_tabs_for( 'edit-post' ) ) )
);

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_help_opts'] );
gwc_vt_settings_cache( null, true );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

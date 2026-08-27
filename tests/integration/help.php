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
 *   bin/wpenv run cli -- wp eval-file \
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

echo "\n── And it is the right screen’s help ────────────────────────────\n";

/* Thirteen screens are routed by matching a page slug against a screen id, and
 * a mis-route is a plausible edit: the branches look alike, and getting one
 * wrong shows a reader the wrong instructions with nothing to indicate it.
 *
 * Checked as a PROPERTY rather than a list of expected tab ids per screen —
 * no two screens may show the same set. That catches the whole class without
 * another table for somebody to keep in step, and it is what a copy-paste
 * mistake in the routing actually looks like. */
$GLOBALS['gwc_vt_help_sets'] = array();

foreach ( $GLOBALS['gwc_vt_help_screens'] as $gwc_vt_help_what => $gwc_vt_help_id ) {
	$gwc_vt_help_ids_here = array_keys( gwc_vt_help_tabs_for( (string) $gwc_vt_help_id ) );

	sort( $gwc_vt_help_ids_here );

	$gwc_vt_help_key = implode( '|', $gwc_vt_help_ids_here );

	if ( '' === $gwc_vt_help_key ) {
		continue;
	}

	$GLOBALS['gwc_vt_help_sets'][ $gwc_vt_help_key ][] = (string) $gwc_vt_help_what;
}

$GLOBALS['gwc_vt_help_shared'] = array();

foreach ( $GLOBALS['gwc_vt_help_sets'] as $gwc_vt_help_who ) {
	if ( count( $gwc_vt_help_who ) > 1 ) {
		$GLOBALS['gwc_vt_help_shared'][] = implode( ' = ', $gwc_vt_help_who );
	}
}

gwc_vt_help_check(
	'no two screens show the same help',
	array() === $GLOBALS['gwc_vt_help_shared'],
	implode( '; ', $GLOBALS['gwc_vt_help_shared'] )
);

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

echo "\n── Every help tab points at the guide ───────────────────────────\n";

/* The tab is where WordPress trained people to look; the guide is where the
 * how-tos are. Without a link between them the only route is knowing the page
 * exists — which is the thing that made somebody ask where the help was. */
$GLOBALS['gwc_vt_help_unlinked']    = array();
$GLOBALS['gwc_vt_help_wrong_topic'] = array();

foreach ( $GLOBALS['gwc_vt_help_screens'] as $gwc_vt_help_what => $gwc_vt_help_id ) {
	set_current_screen( (string) $gwc_vt_help_id );

	$gwc_vt_help_screen = get_current_screen();

	foreach ( array_keys( $gwc_vt_help_screen->get_help_tabs() ) as $gwc_vt_help_old ) {
		$gwc_vt_help_screen->remove_help_tab( $gwc_vt_help_old );
	}

	$gwc_vt_help_screen->set_help_sidebar( '' );

	do_action( 'current_screen', $gwc_vt_help_screen );

	$gwc_vt_help_bar = (string) $gwc_vt_help_screen->get_help_sidebar();

	if ( false === strpos( $gwc_vt_help_bar, GWC_VT_HELP_PAGE ) ) {
		$GLOBALS['gwc_vt_help_unlinked'][] = (string) $gwc_vt_help_what;
		continue;
	}

	/* And it lands on the topic for THIS screen, not the guide's front.
	 * Checking only that the link exists passed a sabotage that sent all
	 * thirteen screens to the same place — which is the behaviour the deep
	 * link was built to replace. */
	$gwc_vt_help_want = gwc_vt_help_topic_for_screen( (string) $gwc_vt_help_id );

	if ( '' !== $gwc_vt_help_want && false === strpos( $gwc_vt_help_bar, 'topic=' . $gwc_vt_help_want ) ) {
		$GLOBALS['gwc_vt_help_wrong_topic'][] = (string) $gwc_vt_help_what . ' → ' . $gwc_vt_help_want;
	}
}

gwc_vt_help_check(
	'and lands on the topic for the screen it is on',
	array() === $GLOBALS['gwc_vt_help_wrong_topic'],
	implode( ', ', $GLOBALS['gwc_vt_help_wrong_topic'] )
);

gwc_vt_help_check(
	'every screen with help links to the guide',
	array() === $GLOBALS['gwc_vt_help_unlinked'],
	implode( ', ', $GLOBALS['gwc_vt_help_unlinked'] )
);

echo "\n── The page is a how-to guide ───────────────────────────────────\n";

/* It used to be a rendering of the Help tabs, and this section asserted every
 * tab appeared on it. That is no longer true and should not be: the tabs answer
 * "what does this mean" and the page answers "how do I". Two documents, and the
 * guard below is about whether the guide is a guide. */
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/screen.php';

/* Every topic, because the guide is one topic at a time now — twenty how-tos
 * and ninety-two steps in a single scroll was a document nobody reads twice.
 * A check that rendered once would cover a sixth of it and say so in the
 * past tense. */
$GLOBALS['gwc_vt_help_views'] = array();

foreach ( gwc_vt_help_topics() as $gwc_vt_help_t ) {
	$_GET['topic'] = (string) $gwc_vt_help_t['id'];

	ob_start();
	gwc_vt_render_help_page();
	$GLOBALS['gwc_vt_help_views'][ (string) $gwc_vt_help_t['id'] ] = (string) ob_get_clean();
}

unset( $_GET['topic'] );

$GLOBALS['gwc_vt_help_html'] = implode( '', $GLOBALS['gwc_vt_help_views'] );

gwc_vt_help_check(
	'every topic renders',
	count( $GLOBALS['gwc_vt_help_views'] ) === count( gwc_vt_help_topics() )
		&& ! in_array( '', array_map( 'trim', $GLOBALS['gwc_vt_help_views'] ), true ),
	count( $GLOBALS['gwc_vt_help_views'] ) . ' view(s)'
);

/* The point of the change: no single view is a wall. */
$GLOBALS['gwc_vt_help_longest'] = max( array_map( 'strlen', $GLOBALS['gwc_vt_help_views'] ) );

gwc_vt_help_check(
	'and no one view is longer than the whole guide used to be',
	$GLOBALS['gwc_vt_help_longest'] < 9000,
	$GLOBALS['gwc_vt_help_longest'] . ' bytes'
);

gwc_vt_help_check(
	'the page renders',
	'' !== trim( $GLOBALS['gwc_vt_help_html'] ),
	'' === trim( $GLOBALS['gwc_vt_help_html'] ) ? 'drew nothing' : strlen( $GLOBALS['gwc_vt_help_html'] ) . ' bytes'
);

$GLOBALS['gwc_vt_help_topics']   = gwc_vt_help_topics();
$GLOBALS['gwc_vt_help_taskless'] = array();
$GLOBALS['gwc_vt_help_short']    = array();
$GLOBALS['gwc_vt_help_missing']  = array();

foreach ( $GLOBALS['gwc_vt_help_topics'] as $gwc_vt_help_topic ) {
	if ( ! $gwc_vt_help_topic['tasks'] ) {
		$GLOBALS['gwc_vt_help_taskless'][] = $gwc_vt_help_topic['id'];
	}

	foreach ( $gwc_vt_help_topic['tasks'] as $gwc_vt_help_task ) {
		/* Two steps is not a procedure — it is a sentence wearing a number,
		 * and usually means the task was not thought through. */
		if ( count( (array) $gwc_vt_help_task['steps'] ) < 3 ) {
			$GLOBALS['gwc_vt_help_short'][] = (string) $gwc_vt_help_task['title'];
		}

		if ( false === strpos( $GLOBALS['gwc_vt_help_html'], (string) $gwc_vt_help_task['title'] ) ) {
			$GLOBALS['gwc_vt_help_missing'][] = (string) $gwc_vt_help_task['title'];
		}
	}
}

/* A LITERAL list, not one read back out of gwc_vt_help_topics().
 *
 * Everything else in this section derives its expectation from that function —
 * which is the function a change would break, so deleting a whole topic moved
 * the expectation with it and passed. This is the forcing function: removing a
 * topic fails here, and adding one fails until somebody comes and says the
 * guide now covers it. The same shape as DashboardTest's worklist fixture, and
 * for the same reason. */
$GLOBALS['gwc_vt_help_ids'] = array();

foreach ( $GLOBALS['gwc_vt_help_topics'] as $gwc_vt_help_topic ) {
	$GLOBALS['gwc_vt_help_ids'][] = (string) $gwc_vt_help_topic['id'];
}

sort( $GLOBALS['gwc_vt_help_ids'] );

gwc_vt_help_check(
	'the guide covers every part of the plugin it is meant to',
	array( 'credentials', 'hours', 'letters', 'public', 'schedule', 'start' ) === $GLOBALS['gwc_vt_help_ids'],
	implode( ', ', $GLOBALS['gwc_vt_help_ids'] )
);

gwc_vt_help_check( 'every topic has at least one how-to', array() === $GLOBALS['gwc_vt_help_taskless'], implode( ', ', $GLOBALS['gwc_vt_help_taskless'] ) );
gwc_vt_help_check( 'every how-to has three steps or more', array() === $GLOBALS['gwc_vt_help_short'], implode( ', ', $GLOBALS['gwc_vt_help_short'] ) );
gwc_vt_help_check( 'every how-to reaches the page', array() === $GLOBALS['gwc_vt_help_missing'], implode( ', ', $GLOBALS['gwc_vt_help_missing'] ) );

gwc_vt_help_check(
	'the steps are numbered lists, not prose',
	substr_count( $GLOBALS['gwc_vt_help_html'], '<ol>' ) === substr_count( $GLOBALS['gwc_vt_help_html'], '<h3>' ),
	substr_count( $GLOBALS['gwc_vt_help_html'], '<ol>' ) . ' list(s) for ' . substr_count( $GLOBALS['gwc_vt_help_html'], '<h3>' ) . ' how-to(s)'
);

/* Every view carries the whole tab bar, so a reader can reach any topic from
 * any other. A view that dropped the bar would be a page with no way out. */
$GLOBALS['gwc_vt_help_barless'] = array();

foreach ( $GLOBALS['gwc_vt_help_views'] as $gwc_vt_help_id => $gwc_vt_help_view ) {
	if ( substr_count( $gwc_vt_help_view, 'class="nav-tab ' ) !== count( gwc_vt_help_topics() ) ) {
		$GLOBALS['gwc_vt_help_barless'][] = (string) $gwc_vt_help_id;
	}
}

gwc_vt_help_check(
	'every view can reach every other topic',
	array() === $GLOBALS['gwc_vt_help_barless'],
	implode( ', ', $GLOBALS['gwc_vt_help_barless'] )
);

/* A topic names itself as the one being read. */
gwc_vt_help_check(
	'exactly one tab is marked current, in every view',
	1 === count( array_unique( array_map( static function ( $gwc_vt_help_view ) {
		return substr_count( (string) $gwc_vt_help_view, 'nav-tab-active' );
	}, $GLOBALS['gwc_vt_help_views'] ) ) )
		&& 1 === substr_count( (string) reset( $GLOBALS['gwc_vt_help_views'] ), 'nav-tab-active' ),
	'one per view'
);

/* An unknown topic lands on the guide rather than on an empty page — the same
 * fallback the settings screen makes for a stale bookmark. */
$_GET['topic'] = 'zznot-a-topic';

ob_start();
gwc_vt_render_help_page();
$GLOBALS['gwc_vt_help_unknown'] = (string) ob_get_clean();

unset( $_GET['topic'] );

gwc_vt_help_check(
	'an unknown topic falls back to the first, not to nothing',
	substr_count( $GLOBALS['gwc_vt_help_unknown'], '<h3>' ) > 0,
	substr_count( $GLOBALS['gwc_vt_help_unknown'], '<h3>' ) . ' how-to(s)'
);

/* ── The house style it says it is written in ────────────────────────────────
 * The Microsoft Writing Style Guide, which this repository does not otherwise
 * use. Asserted rather than trusted: a guide drifts one contributed paragraph
 * at a time, and "click" is the word everybody reaches for. */
$GLOBALS['gwc_vt_help_prose'] = wp_strip_all_tags( $GLOBALS['gwc_vt_help_html'] );

foreach ( array( 'click', 'please', 'simply', 'just ', 'easy', 'in order to' ) as $gwc_vt_help_banned ) {
	gwc_vt_help_check(
		'the guide avoids "' . trim( $gwc_vt_help_banned ) . '"',
		false === stripos( $GLOBALS['gwc_vt_help_prose'], $gwc_vt_help_banned )
	);
}

gwc_vt_help_check(
	'and uses "Select" for what a reader does to a control',
	substr_count( $GLOBALS['gwc_vt_help_prose'], 'Select ' ) > 10,
	substr_count( $GLOBALS['gwc_vt_help_prose'], 'Select ' ) . ' occurrence(s)'
);

echo "\n── Anybody who can log in may read the page ─────────────────────\n";

/* Every other screen in the plugin is behind the records capability, because
 * every other screen shows somebody else's record. This one shows none — the
 * tabs are static strings with nothing interpolated — so gating it on the
 * capability to ACT would mean somebody deciding whether to ask for access
 * cannot read what they would be asking for. */
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( array( 'subscriber', 'contributor', 'author', 'editor' ) as $gwc_vt_help_role ) {
	$gwc_vt_help_user = wp_insert_user(
		array(
			'user_login' => 'zzhelp_' . $gwc_vt_help_role,
			'user_pass'  => wp_generate_password( 20, true ),
			'role'       => $gwc_vt_help_role,
		)
	);

	if ( is_wp_error( $gwc_vt_help_user ) ) {
		continue;
	}

	wp_set_current_user( (int) $gwc_vt_help_user );

	ob_start();
	gwc_vt_render_help_page();
	$gwc_vt_help_seen = (string) ob_get_clean();

	gwc_vt_help_check(
		( in_array( substr( $gwc_vt_help_role, 0, 1 ), array( 'a', 'e', 'i', 'o', 'u' ), true ) ? 'an ' : 'a ' ) . $gwc_vt_help_role . ' can read the help page',
		substr_count( $gwc_vt_help_seen, 'class="nav-tab ' ) === count( $GLOBALS['gwc_vt_help_topics'] ),
		substr_count( $gwc_vt_help_seen, 'class="nav-tab ' ) . ' of ' . count( $GLOBALS['gwc_vt_help_topics'] ) . ' topics reachable'
	);

	wp_set_current_user( 1 );
	wp_delete_user( (int) $gwc_vt_help_user );
}

/* The guide is the SAME for every reader, and that is the change from when
 * this page rendered the tabs. Then it was personalised — a subscriber got 31
 * tabs where an administrator got 32, because one tab is gated on a
 * capability. A how-to guide should not be: somebody reading to find out what
 * the plugin does, or what they would be asking for access to, needs all of
 * it. Asserted so the difference is deliberate rather than drift. */
$gwc_vt_help_reader = wp_insert_user(
	array(
		'user_login' => 'zzhelp_reader',
		'user_pass'  => wp_generate_password( 20, true ),
		'role'       => 'subscriber',
	)
);

if ( ! is_wp_error( $gwc_vt_help_reader ) ) {
	wp_set_current_user( (int) $gwc_vt_help_reader );

	ob_start();
	gwc_vt_render_help_page();
	$gwc_vt_help_thin_page = (string) ob_get_clean();

	wp_set_current_user( 1 );

	ob_start();
	gwc_vt_render_help_page();
	$gwc_vt_help_full_page = (string) ob_get_clean();

	gwc_vt_help_check(
		'a subscriber reads exactly what an administrator reads',
		$gwc_vt_help_thin_page === $gwc_vt_help_full_page,
		substr_count( $gwc_vt_help_thin_page, '<h3>' ) . ' vs ' . substr_count( $gwc_vt_help_full_page, '<h3>' ) . ' how-tos'
	);

	wp_delete_user( (int) $gwc_vt_help_reader );
}

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

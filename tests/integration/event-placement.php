<?php
/**
 * Which page an event is actually on.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * The matching itself is pure and is asserted in tests/EventTest.php. What
 * cannot be asserted there is the half that broke: gwc_vt_event_page_id() finds
 * candidate pages with a content search before it matches anything, and a search
 * that returns the wrong set makes perfect matching irrelevant.
 *
 * That is exactly what happened. The lookup searched page content for the
 * literal "gwc_vt_event_grid", which the shortcode contains and the BLOCK does
 * not — a block placement serializes as
 * "wp:groundwork-common-volunteer-tracker/event-grid". So the block branch was
 * unreachable, and the block is the placement the editor recommends. Every
 * cancellation link in a confirmation email for a block-placed event was empty.
 *
 * A unit test with a stubbed get_posts() would have proved nothing about that,
 * which is why the bootstrap deliberately has no get_posts() at all.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/event-placement.php
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
function gwc_vt_pl_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/* ── Two events, so "found the right one" means something ────────────────── */

$gwc_vt_event_a = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Harvest Day',
	)
);

$gwc_vt_event_b = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Coat Drive',
	)
);

/* ── The block placement: the case that never worked ─────────────────────── */

$gwc_vt_block_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest Harvest Day signup',
		'post_content' => '<!-- wp:groundwork-common-volunteer-tracker/event-grid {"eventId":' . (int) $gwc_vt_event_a . '} /-->',
	)
);

gwc_vt_pl_check(
	'a block-placed event finds its page',
	gwc_vt_event_page_id( (int) $gwc_vt_event_a ) === (int) $gwc_vt_block_page,
	(string) gwc_vt_event_page_id( (int) $gwc_vt_event_a )
);

gwc_vt_pl_check(
	'and the other event is not on it',
	0 === gwc_vt_event_page_id( (int) $gwc_vt_event_b )
);

/* ── The shortcode placement ─────────────────────────────────────────────── */

$gwc_vt_short_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest Coat Drive signup',
		'post_content' => '<p>Every autumn since 2012.</p>[gwc_vt_event_grid id="' . (int) $gwc_vt_event_b . '"]',
	)
);

gwc_vt_pl_check(
	'a shortcode-placed event finds its page',
	gwc_vt_event_page_id( (int) $gwc_vt_event_b ) === (int) $gwc_vt_short_page,
	(string) gwc_vt_event_page_id( (int) $gwc_vt_event_b )
);

gwc_vt_pl_check(
	'the year in the prose beside it is not read as an id',
	0 === gwc_vt_event_page_id( 2012 )
);

/* ── The cache does not outlive the page it answered about ───────────────────
 * The sequence a coordinator actually performs: build the event, look for it,
 * find nothing, make the page. Before the generation counter that answer was
 * cached for an hour — which is the hour they are testing in.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_event_c = wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzytest Meal Service',
	)
);

// Ask before the page exists, so the miss is the thing in the cache.
gwc_vt_pl_check( 'an event on no page answers nothing', 0 === gwc_vt_event_page_id( (int) $gwc_vt_event_c ) );

$gwc_vt_late_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzytest Meal Service signup',
		'post_content' => '[gwc_vt_event_grid id="' . (int) $gwc_vt_event_c . '"]',
	)
);

gwc_vt_pl_check(
	'and answers correctly as soon as the page is made',
	gwc_vt_event_page_id( (int) $gwc_vt_event_c ) === (int) $gwc_vt_late_page,
	(string) gwc_vt_event_page_id( (int) $gwc_vt_event_c )
);

/* ── Teardown ────────────────────────────────────────────────────────────── */

foreach ( array( $gwc_vt_block_page, $gwc_vt_short_page, $gwc_vt_late_page, $gwc_vt_event_a, $gwc_vt_event_b, $gwc_vt_event_c ) as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

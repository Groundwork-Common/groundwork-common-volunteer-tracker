<?php
/**
 * One way to open a thing, and the rules that keep it one way.
 *
 * ── What this is guarding ────────────────────────────────────────────────────
 * A volunteer's record grew three secondary actions and each arrived with its
 * own idea of what pressing it does: one navigated away, one unfolded a panel
 * in place, and one showed fields that were saved by the Update button further
 * down the page. This file exists so that a fourth cannot arrive with a fourth.
 *
 * So the checks here are mostly about SAMENESS. Not "the credential sheet has a
 * close button" — that is one sheet, and one sheet passing proves nothing about
 * the next one somebody writes — but "every sheet has exactly one, drawn by the
 * same function". A pattern is only a pattern while nothing is exempt from it.
 *
 * ── The two rules a sheet can silently break ─────────────────────────────────
 * A sheet is printed on admin_footer, OUTSIDE wp-admin's <form id="post">,
 * because a form inside a form is not something HTML has: the parser drops the
 * inner tags and leaves the fields belonging to the post form, with no error
 * anywhere. So every field in a sheet has to name its form by ID, and a field
 * that forgets is submitted with the volunteer instead. That is checked here
 * for all of them at once.
 *
 * And a sheet must NOT render with the `hidden` attribute. It is hidden by CSS
 * under body.js, which is what lets it be the only copy of its form — with
 * scripting off it is simply a block at the foot of the record, working.
 * Rendering it hidden would make it unreachable without JavaScript, silently.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/sheets.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_sh_made']  = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_sh_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

wp_set_current_user( 1 );

$GLOBALS['gwc_vt_sh_vol'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzsheet Subject',
	)
);

$GLOBALS['gwc_vt_sh_made'][] = $GLOBALS['gwc_vt_sh_vol'];

echo "\n── 1. The frame is drawn in one place ───────────────────────────────\n";

/* Every sheet on this screen, rendered together the way the footer renders
 * them, so the checks below are about the set rather than about a favourite. */
ob_start();
gwc_vt_render_log_hours_sheet( $GLOBALS['gwc_vt_sh_vol'] );
gwc_vt_render_record_credential_sheet( $GLOBALS['gwc_vt_sh_vol'] );
gwc_vt_render_letter_draft_sheet( $GLOBALS['gwc_vt_sh_vol'] );
gwc_vt_render_letter_reader();
gwc_vt_render_letter_mailer( 'zzsheet@example.test' );
gwc_vt_render_letter_poster();
$GLOBALS['gwc_vt_sh_all'] = (string) ob_get_clean();

$GLOBALS['gwc_vt_sh_count'] = substr_count( $GLOBALS['gwc_vt_sh_all'], 'data-gwcvt-sheet=' );

gwc_vt_sh_check(
	'six sheets are rendered',
	6 === $GLOBALS['gwc_vt_sh_count'],
	(string) $GLOBALS['gwc_vt_sh_count']
);

/* One close control each, and every one of them the same markup, because they
 * all come out of gwc_vt_render_sheet(). Three of these were hand-written
 * copies of each other before it existed. */
gwc_vt_sh_check(
	'each has exactly one close control, and they are all the same one',
	$GLOBALS['gwc_vt_sh_count'] === substr_count( $GLOBALS['gwc_vt_sh_all'], 'data-gwcvt-sheet-close' ) - substr_count( $GLOBALS['gwc_vt_sh_all'], 'class="button" data-gwcvt-sheet-close' ),
	substr_count( $GLOBALS['gwc_vt_sh_all'], 'gwcvt-sheet__close' ) . ' crosses'
);

gwc_vt_sh_check(
	'each is a dialog, labelled by its own heading',
	$GLOBALS['gwc_vt_sh_count'] === substr_count( $GLOBALS['gwc_vt_sh_all'], 'role="dialog" aria-modal="true" aria-labelledby=' )
);

echo "\n── 2. Neither rule a sheet can silently break ───────────────────────\n";

/* Hidden by CSS under body.js, never by the attribute. Rendering it hidden
 * would make it unreachable with scripting off, and nothing would say so. */
gwc_vt_sh_check(
	'no sheet renders with the hidden attribute',
	! preg_match( '/data-gwcvt-sheet="[a-z-]+"[^>]*\shidden/', $GLOBALS['gwc_vt_sh_all'] )
);

/* ── The other half of that, which shipped broken ────────────────────────────
 * Not rendering `hidden` only works because the STYLESHEET hides them under
 * body.js. Those two rules were written and then lost — an assertion later in
 * the same edit aborted before the file was saved — so every sheet rendered
 * with `display: flex` and six of them stacked over the record the moment it
 * opened. Nothing caught it, because the check that existed opened a sheet and
 * measured it, which is the one state where flex is correct.
 *
 * So the rule is asserted where it lives. Reading the stylesheet is crude, and
 * it is the only thing here that can fail in the direction that matters: PHP
 * renders these visible on purpose, and CSS is the whole of what closes them. */
$GLOBALS['gwc_vt_sh_css'] = (string) file_get_contents( GWC_VT_DIR . 'assets/css/admin.css' );

gwc_vt_sh_check(
	'and the stylesheet is what closes them, under body.js',
	1 === preg_match( '/body\.js\s+\.gwcvt-sheet\s*\{[^}]*display:\s*none/', $GLOBALS['gwc_vt_sh_css'] )
		&& 1 === preg_match( '/body\.js\s+\.gwcvt-sheet--open\s*\{[^}]*display:\s*flex/', $GLOBALS['gwc_vt_sh_css'] )
);

/* And in that order, because both selectors match an open sheet and the later
 * one wins. Reversed, a sheet could never be opened at all. */
gwc_vt_sh_check(
	'with the open rule after the closed one, or nothing could ever open',
	strpos( $GLOBALS['gwc_vt_sh_css'], 'body.js .gwcvt-sheet--open' ) > strpos( $GLOBALS['gwc_vt_sh_css'], 'body.js .gwcvt-sheet {' )
);

/* The one that bites hardest. A field that does not name its form is submitted
 * with the volunteer instead, silently, because the parser has already put it
 * there. */
$GLOBALS['gwc_vt_sh_stray'] = array();

if ( preg_match_all( '/<(?:input|select|textarea|button)\b[^>]*>/', $GLOBALS['gwc_vt_sh_all'], $gwc_vt_sh_fields ) ) {
	foreach ( $gwc_vt_sh_fields[0] as $gwc_vt_sh_tag ) {
		/* The close control and the Cancel buttons belong to no form: they are
		 * type="button" and submit nothing. Anything else must name one. */
		if ( false !== strpos( $gwc_vt_sh_tag, 'type="button"' ) ) {
			continue;
		}

		if ( false === strpos( $gwc_vt_sh_tag, 'form="' ) ) {
			$GLOBALS['gwc_vt_sh_stray'][] = $gwc_vt_sh_tag;
		}
	}
}

gwc_vt_sh_check(
	'every field in every sheet names the form it belongs to',
	array() === $GLOBALS['gwc_vt_sh_stray'],
	implode( ' ', $GLOBALS['gwc_vt_sh_stray'] )
);

echo "\n── 3. And every trigger is the same trigger ─────────────────────────\n";

ob_start();
gwc_vt_sheet_trigger( 'zzsheet-one', 'One' );
gwc_vt_sheet_trigger( 'zzsheet-two', 'Two', 'zzsheet-extra' );
$GLOBALS['gwc_vt_sh_trigs'] = (string) ob_get_clean();

/* Rendered hidden, and unhidden by the script. The opposite way round leaves a
 * button that opens nothing on a page whose script did not load. */
gwc_vt_sh_check(
	'a trigger renders hidden',
	2 === substr_count( $GLOBALS['gwc_vt_sh_trigs'], 'data-gwcvt-sheet-trigger hidden' )
);

gwc_vt_sh_check(
	'and is a button, not a link',
	2 === substr_count( $GLOBALS['gwc_vt_sh_trigs'], '<button type="button" class="button-link"' )
		&& false === strpos( $GLOBALS['gwc_vt_sh_trigs'], '<a ' )
);

gwc_vt_sh_check(
	'and says which sheet it opens',
	false !== strpos( $GLOBALS['gwc_vt_sh_trigs'], 'data-gwcvt-sheet-open="zzsheet-one"' )
		&& false !== strpos( $GLOBALS['gwc_vt_sh_trigs'], 'data-gwcvt-sheet-open="zzsheet-two"' )
);

echo "\n── 4. What a sheet says afterwards ──────────────────────────────────\n";

/* One table, one query argument. There were two of these for a while, doing the
 * same job under different names because they were written a week apart. */
$GLOBALS['gwc_vt_sh_said'] = gwc_vt_sheet_messages();

gwc_vt_sh_check(
	'every result a handler can redirect with has something to say',
	array() === array_diff(
		array( 'hours-logged', 'hours-unreadable', 'hours-failed', 'credential-recorded', 'credential-failed', 'drafted', 'discarded', 'failed', 'issued', 'issue-failed', 'delivered-email', 'send-failed', 'no-email', 'bad-email', 'no-addressee', 'stale', 'gone' ),
		array_keys( $GLOBALS['gwc_vt_sh_said'] )
	),
	implode( ',', array_keys( $GLOBALS['gwc_vt_sh_said'] ) )
);

/* Translated tables are functions, never consts: a const is evaluated at
 * include time, which freezes the strings in English for the request. */
gwc_vt_sh_check(
	'and the table is a function, so the strings are not frozen in English',
	function_exists( 'gwc_vt_sheet_messages' ) && ! defined( 'GWC_VT_SHEET_MESSAGES' )
);

echo "\n── 5. Logging hours through the sheet ───────────────────────────────\n";

$GLOBALS['gwc_vt_sh_entry'] = gwc_vt_log_one_entry( $GLOBALS['gwc_vt_sh_vol'], '2026-07-04', 150, 'Zzsheet sorting', 'Dana' );

gwc_vt_sh_check(
	'an entry is created, complete',
	$GLOBALS['gwc_vt_sh_entry'] > 0
		&& 150 === (int) get_post_meta( $GLOBALS['gwc_vt_sh_entry'], GWC_VT_ENTRY_MINUTES, true )
		&& '2026-07-04' === (string) get_post_meta( $GLOBALS['gwc_vt_sh_entry'], GWC_VT_ENTRY_DATE, true )
		&& (string) $GLOBALS['gwc_vt_sh_vol'] === (string) get_post_meta( $GLOBALS['gwc_vt_sh_entry'], GWC_VT_ENTRY_VOLUNTEER, true )
);

/* Unverified, however it was created. Staff attesting is a separate act by a
 * separate person, and that separation is most of what makes a letter mean
 * anything. */
gwc_vt_sh_check(
	'and arrives unverified',
	! gwc_vt_entry_is_verified( $GLOBALS['gwc_vt_sh_entry'] )
);

/* The rollup is refreshed, which is the step a hand-rolled caller forgets and
 * which leaves a volunteer whose totals are quietly wrong. */
/* pending_minutes, not verified_minutes: it arrives unverified, so this is
 * where it lands until somebody attests to it. */
gwc_vt_sh_check(
	'and the volunteer’s totals know about it',
	150 === (int) gwc_vt_compute_totals( $GLOBALS['gwc_vt_sh_vol'] )->pending_minutes,
	(string) gwc_vt_compute_totals( $GLOBALS['gwc_vt_sh_vol'] )->pending_minutes
);

gwc_vt_sh_check(
	'and nothing is logged for somebody who is not a volunteer',
	0 === gwc_vt_log_one_entry( $GLOBALS['gwc_vt_sh_entry'], '2026-07-04', 60 )
		&& 0 === gwc_vt_log_one_entry( $GLOBALS['gwc_vt_sh_vol'], '2026-07-04', 0 )
		&& 0 === gwc_vt_log_one_entry( $GLOBALS['gwc_vt_sh_vol'], 'not-a-date', 60 )
);

/**
 * Take everything this script made back out.
 */
function gwc_vt_sh_cleanup(): void {
	foreach ( (array) $GLOBALS['gwc_vt_sh_made'] as $volunteer_id ) {
		foreach ( gwc_vt_entry_ids_for_volunteer( (int) $volunteer_id, array( 'statuses' => array_values( get_post_stati() ) ) ) as $entry_id ) {
			wp_delete_post( (int) $entry_id, true );
		}

		foreach ( (array) get_posts(
			array(
				'post_parent' => (int) $volunteer_id,
				'post_type'   => 'any',
				'post_status' => array_values( get_post_stati() ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $child_id ) {
			wp_delete_post( (int) $child_id, true );
		}

		wp_delete_post( (int) $volunteer_id, true );
	}
}

register_shutdown_function( 'gwc_vt_sh_cleanup' );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

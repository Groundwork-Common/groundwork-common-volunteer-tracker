<?php
/**
 * Looking at a letter that has already gone out.
 *
 * ── The thing this has to get right ──────────────────────────────────────────
 * Somebody rings up and asks for their letter again. The plugin keeps no copy
 * of what was sent — deliberately; the issued-letter log holds figures and a
 * reference and no name — so there is nothing to re-open. What it can do is
 * rebuild the letter from the same volunteer over the same period.
 *
 * A rebuild is not necessarily the same document. Hours get corrected and
 * shifts get verified after a letter goes out, and when that has happened the
 * reprint says something different and carries a different reference. Handing
 * that to a probation officer as "the letter we sent in March" is the failure
 * this screen exists to prevent, so the three states — unchanged, changed, and
 * the volunteer is gone — each have to be told apart and said out loud.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * All of it. The comparison rebuilds a letter from entries, the reference is a
 * salted digest over every printed field, and the interesting case is a record
 * edited after issuance. None of that is reachable without real posts.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter-reissue.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_lr_made']  = array();
$GLOBALS['gwc_vt_lr_get']   = $_GET;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_lr_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * A volunteer with two verified shifts, and a letter issued over them.
 *
 * @param string $name Volunteer name.
 * @return array{volunteer:int, entries:int[], record:array}
 */
function gwc_vt_lr_issue( string $name ): array {
	$volunteer = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_lr_made'][] = (int) $volunteer;

	$entries = array();

	foreach ( array( array( '2026-05-02', 180 ), array( '2026-05-09', 240 ) ) as $shift ) {
		$entry = wp_insert_post(
			array(
				'post_type'   => GWC_VT_ENTRY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Zzlr shift',
			)
		);

		update_post_meta( $entry, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer );
		update_post_meta( $entry, GWC_VT_ENTRY_DATE, $shift[0] );
		update_post_meta( $entry, GWC_VT_ENTRY_MINUTES, $shift[1] );
		update_post_meta( $entry, GWC_VT_ENTRY_ACTIVITY, 'Zzlr food sorting' );

		gwc_vt_verify_entry( (int) $entry, 1 );

		$GLOBALS['gwc_vt_lr_made'][] = (int) $entry;
		$entries[]                   = (int) $entry;
	}

	$letter = gwc_vt_build_letter(
		(int) $volunteer,
		array(
			'from' => '2026-05-01',
			'to'   => '2026-05-31',
		)
	);

	$log_id = gwc_vt_log_letter( $letter, 'print' );

	$GLOBALS['gwc_vt_lr_made'][] = (int) $log_id;

	return array(
		'volunteer' => (int) $volunteer,
		'entries'   => $entries,
		'record'    => (array) gwc_vt_find_letter_record( $letter->reference ),
	);
}

/**
 * Render the produce screen as though somebody clicked a log row.
 *
 * @param array $record The issued-letter record.
 * @return string The markup, or the exception message if it threw.
 */
function gwc_vt_lr_render( array $record ): string {
	$query = array();
	wp_parse_str( (string) wp_parse_url( gwc_vt_letter_review_url( $record ), PHP_URL_QUERY ), $query );

	$_GET = $query;

	set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a test asserting the screen renders clean.
		static function ( int $no, string $message ): bool {
			throw new RuntimeException( $message );
		},
		E_ALL
	);

	try {
		ob_start();
		gwc_vt_render_produce_letter_screen();
		$html = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		ob_end_clean();
		$html = 'THREW: ' . $e->getMessage();
	}

	restore_error_handler();

	return $html;
}

wp_set_current_user( 1 );

/* ── Unchanged: the same letter, and safe to say so ──────────────────────── */

$GLOBALS['gwc_vt_lr_same'] = gwc_vt_lr_issue( 'Zzlr Unchanged Volunteer' );

gwc_vt_lr_check(
	'a letter that was issued can be found by its reference',
	! empty( $GLOBALS['gwc_vt_lr_same']['record']['reference'] ),
	'the log record was not found'
);

gwc_vt_lr_check(
	'the link back to it carries the volunteer, the period and the reference',
	( function () {
		$url = gwc_vt_letter_review_url( $GLOBALS['gwc_vt_lr_same']['record'] );

		return false !== strpos( $url, 'volunteer=' . $GLOBALS['gwc_vt_lr_same']['volunteer'] )
			&& false !== strpos( $url, 'from=2026-05-01' )
			&& false !== strpos( $url, 'to=2026-05-31' )
			&& false !== strpos( $url, 'gwc_vt_reissue=' );
	} )(),
	gwc_vt_letter_review_url( $GLOBALS['gwc_vt_lr_same']['record'] )
);

$GLOBALS['gwc_vt_lr_same_html'] = gwc_vt_lr_render( $GLOBALS['gwc_vt_lr_same']['record'] );

gwc_vt_lr_check(
	'the screen renders without a warning',
	false === strpos( $GLOBALS['gwc_vt_lr_same_html'], 'THREW:' ),
	substr( $GLOBALS['gwc_vt_lr_same_html'], 0, 120 )
);

gwc_vt_lr_check(
	'and says nothing has changed, so a reprint is the same letter',
	false !== strpos( $GLOBALS['gwc_vt_lr_same_html'], 'notice-success' )
		&& false !== strpos( $GLOBALS['gwc_vt_lr_same_html'], 'has changed since' ),
	'it did not say the record was unchanged'
);

/* ── Changed: a different document, and it has to say so ─────────────────── */

$GLOBALS['gwc_vt_lr_diff'] = gwc_vt_lr_issue( 'Zzlr Changed Volunteer' );

/* An hour corrected after the letter went out — the ordinary case, not an
 * exotic one, which is exactly why the banner matters. */
update_post_meta( $GLOBALS['gwc_vt_lr_diff']['entries'][0], GWC_VT_ENTRY_MINUTES, 300 );
gwc_vt_invalidate_from_entry( $GLOBALS['gwc_vt_lr_diff']['entries'][0] );

$GLOBALS['gwc_vt_lr_diff_html'] = gwc_vt_lr_render( $GLOBALS['gwc_vt_lr_diff']['record'] );

gwc_vt_lr_check(
	'a record edited since is reported as changed, not as a copy',
	false !== strpos( $GLOBALS['gwc_vt_lr_diff_html'], 'notice-warning' )
		&& false !== strpos( $GLOBALS['gwc_vt_lr_diff_html'], 'not a copy of that letter' ),
	'it did not say the letter had changed'
);

/* The two facts somebody about to reprint has to have: this goes out as a NEW
 * letter, and the OLD reference stays valid for the copy already in the world. */
gwc_vt_lr_check(
	'and warns that printing it makes a new letter with a new reference',
	false !== strpos( $GLOBALS['gwc_vt_lr_diff_html'], 'new letter with a new reference' ),
	'it did not warn about the new reference'
);

gwc_vt_lr_check(
	'and names the old reference as still the one on their copy',
	false !== strpos( $GLOBALS['gwc_vt_lr_diff_html'], (string) $GLOBALS['gwc_vt_lr_diff']['record']['reference'] ),
	'the issued reference was not named'
);

/* The claim the banner makes has to be true: rebuilding really does produce a
 * different reference now. Without this the wording could be right about a
 * situation that never arises. */
gwc_vt_lr_check(
	'and the rebuild really would carry a different reference',
	( function () {
		$now = gwc_vt_build_letter(
			$GLOBALS['gwc_vt_lr_diff']['volunteer'],
			array(
				'from' => '2026-05-01',
				'to'   => '2026-05-31',
			)
		);

		return $now instanceof GWC_VT_Letter
			&& $now->reference !== $GLOBALS['gwc_vt_lr_diff']['record']['reference'];
	} )(),
	'the reference did not move when the hours did'
);

/* ── Gone: nothing to rebuild from, and nothing offered ──────────────────── */

$GLOBALS['gwc_vt_lr_gone'] = gwc_vt_lr_issue( 'Zzlr Purged Volunteer' );

gwc_vt_delete_volunteer( $GLOBALS['gwc_vt_lr_gone']['volunteer'] );

$GLOBALS['gwc_vt_lr_gone_html'] = gwc_vt_lr_render( $GLOBALS['gwc_vt_lr_gone']['record'] );

gwc_vt_lr_check(
	'a letter whose volunteer is gone says so plainly',
	false !== strpos( $GLOBALS['gwc_vt_lr_gone_html'], 'notice-error' )
		&& false !== strpos( $GLOBALS['gwc_vt_lr_gone_html'], 'removed or anonymized' ),
	'it did not explain that the record is gone'
);

/* And offers nothing to print. Rendering an empty letter over a purged record
 * would be the plugin producing a document about nobody. */
gwc_vt_lr_check(
	'and offers no way to print one',
	false === strpos( $GLOBALS['gwc_vt_lr_gone_html'], 'gwc_vt_letter_print' ),
	'a purged record still offered a print button'
);

/* ── A URL whose reference and period disagree ───────────────────────────────
 * The link carries all four values, so they agree by construction and no
 * assertion above can tell whether the record or the query string is winning.
 * A hand-edited or truncated URL is where it matters: the banner speaks for the
 * issued letter, so the letter drawn under it has to be the same period. If the
 * query string won, the screen would compare one period and render another.
 *
 * Found by removing the guard and watching every check still pass.
 * ─────────────────────────────────────────────────────────────────────────── */

$_GET = array(
	'volunteer'      => $GLOBALS['gwc_vt_lr_same']['volunteer'],
	'from'           => '2020-01-01',
	'to'             => '2020-12-31',
	'gwc_vt_reissue' => $GLOBALS['gwc_vt_lr_same']['record']['reference'],
);

set_error_handler(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a test asserting the screen renders clean.
	static function ( int $no, string $message ): bool {
		throw new RuntimeException( $message );
	},
	E_ALL
);

try {
	ob_start();
	gwc_vt_render_produce_letter_screen();
	$GLOBALS['gwc_vt_lr_forced'] = (string) ob_get_clean();
} catch ( Throwable $gwc_vt_lr_e ) {
	ob_end_clean();
	$GLOBALS['gwc_vt_lr_forced'] = 'THREW: ' . $gwc_vt_lr_e->getMessage();
}

restore_error_handler();

$_GET = $GLOBALS['gwc_vt_lr_get'];

gwc_vt_lr_check(
	'the issued letter’s own period wins over the one in the URL',
	false !== strpos( $GLOBALS['gwc_vt_lr_forced'], 'value="2026-05-01"' )
		&& false === strpos( $GLOBALS['gwc_vt_lr_forced'], 'value="2020-01-01"' ),
	'the screen used the period from the query string'
);

gwc_vt_lr_check(
	'and it still reports the letter as unchanged',
	false !== strpos( $GLOBALS['gwc_vt_lr_forced'], 'notice-success' ),
	'a mismatched URL changed what the banner said'
);

/* ── The letterhead warning appears once ─────────────────────────────────── */

gwc_vt_lr_check(
	'the letterhead warning is not drawn twice on one screen',
	substr_count( $GLOBALS['gwc_vt_lr_same_html'], 'has not been given your letterhead' ) <= 1,
	'it appeared ' . substr_count( $GLOBALS['gwc_vt_lr_same_html'], 'has not been given your letterhead' ) . ' times'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

$_GET = $GLOBALS['gwc_vt_lr_get'];

foreach ( get_posts( array( 'post_type' => array( GWC_VT_VOLUNTEER_TYPE, GWC_VT_ENTRY_TYPE ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'Zzlr' ) ) as $gwc_vt_lr_post ) {
	$GLOBALS['gwc_vt_lr_made'][] = (int) $gwc_vt_lr_post->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_lr_made'] ) as $gwc_vt_lr_id ) {
	wp_delete_post( (int) $gwc_vt_lr_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

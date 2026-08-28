<?php
/**
 * Looking at a letter that has already gone out.
 *
 * ── The thing this has to get right ──────────────────────────────────────────
 * Somebody rings up and asks for their letter again. The plugin keeps no copy
 * of what was sent — deliberately; the issued-letter log holds figures, a
 * reference and no name, and outlives the volunteer on purpose. So there is
 * nothing to re-open, only something to rebuild.
 *
 * A rebuild is not automatically the same document, and handing one to a
 * probation officer as "the letter we sent in March" is the failure everything
 * here exists to prevent.
 *
 * ── What changed, and why this file was rewritten rather than deleted ────────
 * The concern above has not moved an inch. The mechanism has, twice.
 *
 * It used to rebuild from the PERIOD and warn you when the answer had drifted,
 * on a screen of its own, offering to print the drifted version as a new letter
 * with a new reference. That screen is gone, and so is that offer: the log now
 * records which entries the letter listed, so a rebuild reproduces the letter
 * exactly or does not reproduce it at all — and when it does not, delivering it
 * is refused rather than relabelled.
 *
 * So the three states are the same three states, and each is now a different
 * answer: it reproduces, it cannot be reproduced, or there is nobody left to
 * rebuild from.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * All of it. The rebuild reads entries, the reference is a salted digest over
 * every printed field, and the interesting case is a record edited after
 * issuance. None of that is reachable without real posts.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter-reissue.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_lr_made']  = array();

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
 * @param string $name Their name.
 * @return array{volunteer:int, entries:int[], record:array}
 */
function gwc_vt_lr_issue( string $name ): array {
	$volunteer = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_lr_made'][] = $volunteer;

	$entries = array();

	foreach ( array( array( '2026-05-02', 180 ), array( '2026-05-09', 240 ) ) as $shift ) {
		$entry = (int) wp_insert_post(
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

		gwc_vt_verify_entry( $entry, 1 );

		$GLOBALS['gwc_vt_lr_made'][] = $entry;
		$entries[]                   = $entry;
	}

	$letter = gwc_vt_build_letter(
		$volunteer,
		array(
			'from' => '2026-05-01',
			'to'   => '2026-05-31',
		)
	);

	$log_id = gwc_vt_log_letter( $letter );

	$GLOBALS['gwc_vt_lr_made'][] = $log_id;

	return array(
		'volunteer' => $volunteer,
		'entries'   => $entries,
		'record'    => (array) gwc_vt_letter_record( $log_id ),
	);
}

wp_set_current_user( 1 );

echo "\n── 1. Finding it again ──────────────────────────────────────────────\n";

$GLOBALS['gwc_vt_lr_same'] = gwc_vt_lr_issue( 'Zzlr Unchanged' );

gwc_vt_lr_check(
	'a letter that was issued can be found by its reference',
	! empty( $GLOBALS['gwc_vt_lr_same']['record']['id'] ),
	(string) ( $GLOBALS['gwc_vt_lr_same']['record']['reference'] ?? '' )
);

/* The way back is the volunteer's own record, because that is where every
 * letter lives now. It used to be a screen with the reference in the query
 * string, which reopened the letter by rebuilding it from the period — a screen
 * that no longer exists and an answer that was only ever approximately right. */
$GLOBALS['gwc_vt_lr_url'] = gwc_vt_letter_review_url( $GLOBALS['gwc_vt_lr_same']['record'] );

gwc_vt_lr_check(
	'and the way back to it is the record it is about',
	false !== strpos( $GLOBALS['gwc_vt_lr_url'], 'post=' . $GLOBALS['gwc_vt_lr_same']['volunteer'] )
		&& false !== strpos( $GLOBALS['gwc_vt_lr_url'], '#gwc-vt-volunteer-letters' ),
	$GLOBALS['gwc_vt_lr_url']
);

echo "\n── 2. It reproduces, or it does not ─────────────────────────────────\n";

$GLOBALS['gwc_vt_lr_reb'] = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_lr_same']['record'] );

gwc_vt_lr_check(
	'an untouched letter reproduces exactly',
	$GLOBALS['gwc_vt_lr_reb'] instanceof GWC_VT_Letter
		&& gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_lr_same']['record'], $GLOBALS['gwc_vt_lr_reb'] )
);

/* ── The case the whole file is for ──────────────────────────────────────────
 * A shift the letter LISTS is edited afterwards. There is no version of this
 * where a document can be produced: what the page would state and what the
 * reference on it digests have come apart, and printing it anyway hands a
 * court something whose own code fails when they ring to check it.
 *
 * The old answer was to print it as a NEW letter with a new reference and warn
 * about it in prose. That put the decision on whoever read the warning. The
 * answer now is that delivering it is refused. */
$GLOBALS['gwc_vt_lr_moved'] = gwc_vt_lr_issue( 'Zzlr Corrected' );
$GLOBALS['gwc_vt_lr_first'] = (int) $GLOBALS['gwc_vt_lr_moved']['record']['entry_ids'][0];

update_post_meta( $GLOBALS['gwc_vt_lr_first'], GWC_VT_ENTRY_MINUTES, 999 );

$GLOBALS['gwc_vt_lr_broke'] = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_lr_moved']['record'] );

gwc_vt_lr_check(
	'a letter one of whose shifts was edited cannot be reproduced',
	$GLOBALS['gwc_vt_lr_broke'] instanceof GWC_VT_Letter
		&& ! gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_lr_moved']['record'], $GLOBALS['gwc_vt_lr_broke'] )
);

/* The old reference stays what it is: the code on the copy somebody is holding,
 * and still the answer to what was issued that day. Nothing here rewrites it. */
gwc_vt_lr_check(
	'and the reference on the record is untouched by any of that',
	$GLOBALS['gwc_vt_lr_moved']['record']['reference']
		=== (string) get_the_title( (int) $GLOBALS['gwc_vt_lr_moved']['record']['id'] )
);

/* A shift verified since is a different matter and must NOT break it — that is
 * ordinary, and treating it as tampering is what made the old warning fire on
 * every letter about somebody still volunteering. */
$GLOBALS['gwc_vt_lr_extra'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_ENTRY_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzlr later shift',
	)
);
$GLOBALS['gwc_vt_lr_made'][] = $GLOBALS['gwc_vt_lr_extra'];

update_post_meta( $GLOBALS['gwc_vt_lr_extra'], GWC_VT_ENTRY_VOLUNTEER, (string) $GLOBALS['gwc_vt_lr_same']['volunteer'] );
update_post_meta( $GLOBALS['gwc_vt_lr_extra'], GWC_VT_ENTRY_DATE, '2026-05-16' );
update_post_meta( $GLOBALS['gwc_vt_lr_extra'], GWC_VT_ENTRY_MINUTES, 120 );
update_post_meta( $GLOBALS['gwc_vt_lr_extra'], GWC_VT_ENTRY_ACTIVITY, 'Zzlr food sorting' );
gwc_vt_verify_entry( $GLOBALS['gwc_vt_lr_extra'], 1 );

gwc_vt_lr_check(
	'while a shift verified since leaves it reproducing perfectly',
	gwc_vt_rebuild_is_faithful(
		$GLOBALS['gwc_vt_lr_same']['record'],
		gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_lr_same']['record'] )
	)
);

echo "\n── 3. Or there is nobody left to rebuild from ───────────────────────\n";

$GLOBALS['gwc_vt_lr_gone'] = gwc_vt_lr_issue( 'Zzlr Erased' );

gwc_vt_delete_volunteer( $GLOBALS['gwc_vt_lr_gone']['volunteer'] );

/* The log entry survives, because it is the organization's own receipt of its
 * own conduct and holds no name. There is simply nothing left to build a page
 * out of, and a blank one would be a document rather than an explanation. */
gwc_vt_lr_check(
	'the log record outlives the volunteer',
	is_array( gwc_vt_letter_record( (int) $GLOBALS['gwc_vt_lr_gone']['record']['id'] ) )
		&& '' !== (string) gwc_vt_letter_record( (int) $GLOBALS['gwc_vt_lr_gone']['record']['id'] )['reference']
);

gwc_vt_lr_check(
	'but there is nothing to rebuild',
	null === gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_lr_gone']['record'] )
);

/* And the log stops offering a link rather than offering one that dies. */
gwc_vt_lr_check(
	'and no way back is offered',
	'' === gwc_vt_letter_review_url( $GLOBALS['gwc_vt_lr_gone']['record'] )
);

echo "\n── 4. The letterhead warning, where letters are made ────────────────\n";

/* Three settings fall back to something reasonable when empty, and together
 * they mean a court letter headed with a website's title over a webmaster's
 * address. The warning used to live on the screen that produced letters; it
 * moved to the box when the screen went, and it must appear once. */
$GLOBALS['gwc_vt_lr_org'] = get_option( 'blogname' );

update_option( 'gwc_vt_settings', array_merge( (array) get_option( 'gwc_vt_settings', array() ), array( 'org_name' => '' ) ) );

ob_start();
gwc_vt_render_volunteer_letters_box( get_post( $GLOBALS['gwc_vt_lr_same']['volunteer'] ) );
$GLOBALS['gwc_vt_lr_box'] = (string) ob_get_clean();

gwc_vt_lr_check(
	'the box warns about the letterhead, once',
	1 === substr_count( $GLOBALS['gwc_vt_lr_box'], 'gwcvt-letterhead-warning' )
		|| 1 === substr_count( $GLOBALS['gwc_vt_lr_box'], 'notice-warning' ),
	substr_count( $GLOBALS['gwc_vt_lr_box'], 'notice-warning' ) . ' warnings'
);

/**
 * Take everything this script made back out.
 */
function gwc_vt_lr_cleanup(): void {
	foreach ( (array) $GLOBALS['gwc_vt_lr_made'] as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

register_shutdown_function( 'gwc_vt_lr_cleanup' );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

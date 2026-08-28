<?php
/**
 * Letter drafts, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * A draft is a child post of a volunteer, and everything interesting about it is
 * a question about WordPress rather than about this plugin's arithmetic: does it
 * die with the person it names, does issuing a letter retire it, and does the
 * one route that fires none of this plugin's hooks — a staff member deleting the
 * volunteer from the post list — leave it behind.
 *
 * ── The property that matters most ───────────────────────────────────────────
 * A draft is an intention about a named person: "we are about to tell a court
 * about this one". That is the opposite of the issued-letter log, which holds
 * figures and a reference and no name and outlives the volunteer on purpose. The
 * two look alike, so §4 and §5 check that they behave as opposites — anonymizing
 * takes the drafts and leaves the log alone.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter-drafts.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function, so a top-level
 * assignment is a local while `global` in a helper reaches the real one. See the
 * note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_ld_made']    = array();
$GLOBALS['gwc_vt_ld_entries'] = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_ld_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * A volunteer, remembered for the clean-up at the end.
 *
 * @param string $name Their name.
 * @return int
 */
function gwc_vt_ld_volunteer( string $name ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	$GLOBALS['gwc_vt_ld_made'][] = $id;

	return $id;
}

/**
 * One verified hour on somebody's record, so a letter about them says something.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $date         Y-m-d.
 * @param int    $minutes      Duration.
 * @return int
 */
function gwc_vt_ld_entry( int $volunteer_id, string $date, int $minutes ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, $minutes );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, 'Sorting donations' );
	update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Dana Reyes' );

	gwc_vt_verify_entry( $id, 1 );

	$GLOBALS['gwc_vt_ld_entries'][] = $id;

	return $id;
}

wp_set_current_user( 1 );

echo "\n── 1. A draft holds a period and belongs to one person ──────────────\n";

$GLOBALS['gwc_vt_ld_vol'] = gwc_vt_ld_volunteer( 'Zzld Draftsubject' );

$GLOBALS['gwc_vt_ld_all']   = gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_vol'] );
$GLOBALS['gwc_vt_ld_range'] = gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_vol'], '2026-01-01', '2026-03-31' );

gwc_vt_ld_check(
	'both drafts were created',
	$GLOBALS['gwc_vt_ld_all'] > 0 && $GLOBALS['gwc_vt_ld_range'] > 0,
	$GLOBALS['gwc_vt_ld_all'] . ', ' . $GLOBALS['gwc_vt_ld_range']
);

$GLOBALS['gwc_vt_ld_read'] = gwc_vt_letter_draft( $GLOBALS['gwc_vt_ld_range'] );

gwc_vt_ld_check(
	'it is a child of the volunteer it is about',
	(int) $GLOBALS['gwc_vt_ld_read']['volunteer'] === $GLOBALS['gwc_vt_ld_vol'],
	(string) $GLOBALS['gwc_vt_ld_read']['volunteer']
);

gwc_vt_ld_check(
	'and it kept the period it was given',
	'2026-01-01' === $GLOBALS['gwc_vt_ld_read']['from'] && '2026-03-31' === $GLOBALS['gwc_vt_ld_read']['to'],
	$GLOBALS['gwc_vt_ld_read']['from'] . '..' . $GLOBALS['gwc_vt_ld_read']['to']
);

/* Not stored: the hours. A draft is the question, and every screen that shows
 * one recomputes the answer as it draws — see inc/letter-draft-cpt.php. */
gwc_vt_ld_check(
	'a draft stores no figures',
	'' === (string) get_post_meta( $GLOBALS['gwc_vt_ld_range'], '_gwc_vt_draft_minutes', true )
);

$GLOBALS['gwc_vt_ld_turned'] = gwc_vt_letter_draft(
	gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_vol'], '2026-03-31', '2026-01-01' )
);

gwc_vt_ld_check(
	'two dates the wrong way round are turned round, not refused',
	'2026-01-01' === $GLOBALS['gwc_vt_ld_turned']['from'] && '2026-03-31' === $GLOBALS['gwc_vt_ld_turned']['to'],
	$GLOBALS['gwc_vt_ld_turned']['from'] . '..' . $GLOBALS['gwc_vt_ld_turned']['to']
);

gwc_vt_ld_check(
	'and nothing but a volunteer can have one',
	0 === gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_range'] )
);

echo "\n── 2. Listing is per volunteer, oldest first ────────────────────────\n";

$GLOBALS['gwc_vt_ld_other'] = gwc_vt_ld_volunteer( 'Zzld Somebodyelse' );
gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_other'] );

$GLOBALS['gwc_vt_ld_list'] = gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_vol'] );

gwc_vt_ld_check(
	'only this volunteer’s drafts are listed',
	3 === count( $GLOBALS['gwc_vt_ld_list'] ),
	(string) count( $GLOBALS['gwc_vt_ld_list'] )
);

gwc_vt_ld_check(
	'oldest first — they are a queue',
	(int) $GLOBALS['gwc_vt_ld_list'][0]['id'] === $GLOBALS['gwc_vt_ld_all'],
	$GLOBALS['gwc_vt_ld_list'][0]['id'] . ' vs ' . $GLOBALS['gwc_vt_ld_all']
);

echo "\n── 3. Discarding, and issuing from one ──────────────────────────────\n";

gwc_vt_discard_letter_draft( $GLOBALS['gwc_vt_ld_turned']['id'] );

gwc_vt_ld_check(
	'a discarded draft is gone',
	2 === count( gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_vol'] ) ),
	(string) count( gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_vol'] ) )
);

/* Called from the two handlers that write the log, and called with 0 by every
 * letter issued from no draft at all — which is why it guards rather than
 * assuming its caller knows. */
gwc_vt_ld_check(
	'finishing draft 0 is a no-op, not a warning',
	false === gwc_vt_finish_letter_draft( 0 )
);

gwc_vt_ld_check(
	'finishing a real one retires it',
	true === gwc_vt_finish_letter_draft( $GLOBALS['gwc_vt_ld_range'] )
		&& array() === gwc_vt_letter_draft( $GLOBALS['gwc_vt_ld_range'] )
);

echo "\n── 4. A draft dies with the person it names ─────────────────────────\n";

$GLOBALS['gwc_vt_ld_erased'] = gwc_vt_ld_volunteer( 'Zzld Erasedlater' );
gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_erased'] );
gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_erased'], '2026-02-01', '2026-02-28' );

/* The log is written directly rather than by issuing a letter: what is being
 * checked here is that anonymizing treats the two records as opposites, and the
 * issuing path has its own coverage in tests/integration/letter.php. */
gwc_vt_log_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_erased'] ), 'print' );

$GLOBALS['gwc_vt_ld_logged'] = count( gwc_vt_letters_for_volunteer( $GLOBALS['gwc_vt_ld_erased'] ) );

gwc_vt_anonymize_volunteer( $GLOBALS['gwc_vt_ld_erased'] );

gwc_vt_ld_check(
	'anonymizing takes the drafts',
	array() === gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_erased'] ),
	(string) count( gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_erased'] ) )
);

gwc_vt_ld_check(
	'and leaves the issued-letter log alone',
	count( gwc_vt_letters_for_volunteer( $GLOBALS['gwc_vt_ld_erased'] ) ) === $GLOBALS['gwc_vt_ld_logged'],
	$GLOBALS['gwc_vt_ld_logged'] . ' → ' . count( gwc_vt_letters_for_volunteer( $GLOBALS['gwc_vt_ld_erased'] ) )
);

$GLOBALS['gwc_vt_ld_deleted'] = gwc_vt_ld_volunteer( 'Zzld Deletedlater' );
gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_deleted'] );

gwc_vt_delete_volunteer( $GLOBALS['gwc_vt_ld_deleted'] );

gwc_vt_ld_check(
	'and deleting takes them too',
	array() === gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_deleted'] )
);

echo "\n── 5. And the sweep for the route that fires no hooks ───────────────\n";

$GLOBALS['gwc_vt_ld_dropped']  = gwc_vt_ld_volunteer( 'Zzld Droppedfromlist' );
$GLOBALS['gwc_vt_ld_stranded'] = gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_dropped'] );

/* Straight to wp_delete_post(), which is what the post list does — none of this
 * plugin's own hooks fire, and WordPress does not cascade to children. */
wp_delete_post( $GLOBALS['gwc_vt_ld_dropped'], true );

gwc_vt_ld_check(
	'a draft whose volunteer was deleted from the list is left behind',
	null !== get_post( $GLOBALS['gwc_vt_ld_stranded'] )
);

gwc_vt_ld_check(
	'and the sweep finds it',
	in_array( $GLOBALS['gwc_vt_ld_stranded'], gwc_vt_orphan_letter_draft_ids(), true ),
	implode( ',', gwc_vt_orphan_letter_draft_ids() )
);

/* §5 sabotaged: an orphan sweep that looked only at whether the parent ID was
 * set would miss this one, because the ID is still there and points at nothing. */
gwc_vt_ld_check(
	'a draft with a living volunteer is not swept',
	! in_array(
		(int) gwc_vt_letter_drafts( $GLOBALS['gwc_vt_ld_other'] )[0]['id'],
		gwc_vt_orphan_letter_draft_ids(),
		true
	)
);

wp_delete_post( $GLOBALS['gwc_vt_ld_stranded'], true );

echo "\n── 6. How a period reads, in one place ──────────────────────────────\n";

/* Not "their whole time volunteering": an unbounded letter runs from the first
 * shift on record to the day it goes out, and a draft has not gone out yet, so
 * it cannot even name the end. What it can say is where the end comes from. */
gwc_vt_ld_check(
	'the unbounded case does not claim to cover all of somebody’s time',
	false === stripos( gwc_vt_letter_period_words( '', '' ), 'whole time' )
		&& false !== stripos( gwc_vt_letter_period_words( '', '' ), 'issued' ),
	gwc_vt_letter_period_words( '', '' )
);

gwc_vt_ld_check(
	'a bounded one names both dates',
	false !== strpos( gwc_vt_letter_period_words( '2026-01-01', '2026-03-31' ), gwc_vt_display_date( '2026-01-01' ) )
		&& false !== strpos( gwc_vt_letter_period_words( '2026-01-01', '2026-03-31' ), gwc_vt_display_date( '2026-03-31' ) ),
	gwc_vt_letter_period_words( '2026-01-01', '2026-03-31' )
);

/* gwc_vt_display_date(), not gwc_vt_local_date(): a period is calendar dates,
 * which were never instants, and the timezone conversion shifts a plain Y-m-d
 * across a day boundary on every site west of UTC. */
gwc_vt_ld_check(
	'and a date is not shifted by the site’s timezone',
	false !== strpos( gwc_vt_letter_period_words( '2026-01-01', '' ), gwc_vt_display_date( '2026-01-01' ) ),
	gwc_vt_letter_period_words( '2026-01-01', '' )
);

echo "\n── 7. The box, and the shapes the script folds ──────────────────────\n";

$GLOBALS['gwc_vt_ld_boxvol'] = gwc_vt_ld_volunteer( 'Zzld Boxsubject' );
update_post_meta( $GLOBALS['gwc_vt_ld_boxvol'], GWC_VT_VOLUNTEER_EMAIL, 'zzld@example.test' );
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_boxvol'], '2026-02-14', 180 );
gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_boxvol'] );

ob_start();
gwc_vt_render_volunteer_letters_box( get_post( $GLOBALS['gwc_vt_ld_boxvol'] ) );
$GLOBALS['gwc_vt_ld_box'] = (string) ob_get_clean();

/* The four the prototype settles on, in the order it puts them. "Read it" and
 * "Issue and print" were this box's own words for two of them and nobody else's. */
$GLOBALS['gwc_vt_ld_links'] = array();

if ( preg_match_all( '/<a\b[^>]*>\s*([^<]+?)\s*<\/a>/', $GLOBALS['gwc_vt_ld_box'], $gwc_vt_ld_found ) ) {
	$GLOBALS['gwc_vt_ld_links'] = array_map( 'trim', $gwc_vt_ld_found[1] );
}

gwc_vt_ld_check(
	'a draft row offers exactly Open, Issue it, Email it and Discard',
	array( 'Open', 'Issue it', 'Email it', 'Discard' ) === $GLOBALS['gwc_vt_ld_links'],
	implode( ' | ', $GLOBALS['gwc_vt_ld_links'] )
);

/* Rendered open with the button hidden, so the screen works without the script.
 * Written the other way round, a script that failed to load would leave a box of
 * buttons that do nothing on the screen where letters to courts are made. */
gwc_vt_ld_check(
	'the adder renders open, and its button renders hidden',
	false !== strpos( $GLOBALS['gwc_vt_ld_box'], 'data-gwcvt-letters-open hidden' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_box'], 'data-gwcvt-letters-panel>' )
		&& false === strpos( $GLOBALS['gwc_vt_ld_box'], 'data-gwcvt-letters-panel hidden' )
);

gwc_vt_ld_check(
	'and so do the dates the second choice needs',
	false !== strpos( $GLOBALS['gwc_vt_ld_box'], 'data-gwcvt-letters-dates>' )
);

/* The fields name the form they belong to, because a meta box renders inside
 * wp-admin's own <form id="post"> and a nested form is silently dropped. A
 * field that stopped naming it would be submitted with the volunteer instead. */
$GLOBALS['gwc_vt_ld_stray'] = array();

if ( preg_match_all( '/<input\b[^>]*>/', $GLOBALS['gwc_vt_ld_box'], $gwc_vt_ld_inputs ) ) {
	foreach ( $gwc_vt_ld_inputs[0] as $gwc_vt_ld_tag ) {
		if ( false === strpos( $gwc_vt_ld_tag, 'form="gwcvt-start-letter"' ) ) {
			$GLOBALS['gwc_vt_ld_stray'][] = $gwc_vt_ld_tag;
		}
	}
}

gwc_vt_ld_check(
	'every field in the box names the form it belongs to',
	array() === $GLOBALS['gwc_vt_ld_stray'],
	implode( ' ', $GLOBALS['gwc_vt_ld_stray'] )
);

ob_start();
gwc_vt_render_letter_mailer( 'zzld@example.test' );
$GLOBALS['gwc_vt_ld_mailer'] = (string) ob_get_clean();

/* The half of the brief this box originally dropped: a court or a school often
 * asks to be sent the letter directly, and the alternative to asking is a
 * coordinator mailing a PDF from their own account with nothing in the log. */
gwc_vt_ld_check(
	'the email panel offers the address on file and another one',
	false !== strpos( $GLOBALS['gwc_vt_ld_mailer'], 'zzld@example.test' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_mailer'], 'name="recipient"' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_mailer'], 'Another address' )
);

ob_start();
gwc_vt_render_letter_mailer( '' );
$GLOBALS['gwc_vt_ld_nomail'] = (string) ob_get_clean();

gwc_vt_ld_check(
	'and with no address on file it offers only the typed one, already chosen',
	false === strpos( $GLOBALS['gwc_vt_ld_nomail'], 'data-gwcvt-mailto="file"' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_nomail'], 'name="recipient"' )
);

echo "\n── 8. What the header line says ─────────────────────────────────────\n";

gwc_vt_ld_check(
	'nothing on the record says so',
	false !== stripos( gwc_vt_letters_box_count( 0, 0 ), 'nothing' ),
	gwc_vt_letters_box_count( 0, 0 )
);

/* The drafts are named separately because they are the half with something
 * outstanding — "3 on this record" alone does not say one is waiting. */
gwc_vt_ld_check(
	'drafts are counted out separately',
	false !== strpos( gwc_vt_letters_box_count( 1, 2 ), '·' )
		&& false !== strpos( gwc_vt_letters_box_count( 1, 2 ), '3' ),
	gwc_vt_letters_box_count( 1, 2 )
);

gwc_vt_ld_check(
	'and are not mentioned when there are none',
	false === strpos( gwc_vt_letters_box_count( 0, 2 ), '·' ),
	gwc_vt_letters_box_count( 0, 2 )
);

echo "\n── 9. A reopened letter does not call itself a draft ────────────────\n";

$GLOBALS['gwc_vt_ld_doc'] = gwc_vt_render_letter(
	gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_boxvol'] ),
	'draft',
	array( 'reference' => 'ZZLD-REOPENED-0001' )
);

/* No copy of an issued letter is kept, so "open it again" can only mean
 * "render from the records as they stand now". Saying "Draft — not issued" over
 * a letter that demonstrably went out is the one wrong answer. */
gwc_vt_ld_check(
	'the band says it is not the copy they hold, and names the reference',
	false === strpos( $GLOBALS['gwc_vt_ld_doc'], 'Draft — not issued' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_doc'], 'Not the copy that went out' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_doc'], 'ZZLD-REOPENED-0001' )
);

gwc_vt_ld_check(
	'and neither does its title or its print button',
	false === strpos( $GLOBALS['gwc_vt_ld_doc'], 'Print this draft' )
		&& false === strpos( $GLOBALS['gwc_vt_ld_doc'], '<title>Draft' )
);

/* Without the record it is a draft again, which is the case that must not have
 * been broken by teaching it the other one. */
$GLOBALS['gwc_vt_ld_plain'] = gwc_vt_render_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_boxvol'] ), 'draft' );

gwc_vt_ld_check(
	'a real draft still says it is one',
	false !== strpos( $GLOBALS['gwc_vt_ld_plain'], 'Draft — not issued' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_plain'], 'Print this draft' )
);

/**
 * Take everything this script made back out.
 */
function gwc_vt_ld_cleanup(): void {
	foreach ( (array) $GLOBALS['gwc_vt_ld_entries'] as $entry_id ) {
		wp_delete_post( (int) $entry_id, true );
	}

	foreach ( (array) $GLOBALS['gwc_vt_ld_made'] as $volunteer_id ) {
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

		foreach ( (array) gwc_vt_letters_for_volunteer( (int) $volunteer_id ) as $record ) {
			wp_delete_post( (int) $record['id'], true );
		}

		wp_delete_post( (int) $volunteer_id, true );
	}
}

register_shutdown_function( 'gwc_vt_ld_cleanup' );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

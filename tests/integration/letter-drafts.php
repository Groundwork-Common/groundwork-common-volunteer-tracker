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

/* ── And it names dates rather than describing them ──────────────────────────
 * "Everything on record, up to the day it is issued" is true and says nothing
 * a reader can check against the letter. The document has always named both
 * ends; the box now names the ones it knows. */
gwc_vt_ld_check(
	'a draft names the date it starts from',
	false !== strpos( gwc_vt_letter_period_words( '', '', '2026-02-14' ), gwc_vt_display_date( '2026-02-14' ) )
		&& false !== stripos( gwc_vt_letter_period_words( '', '', '2026-02-14' ), 'issued' ),
	gwc_vt_letter_period_words( '', '', '2026-02-14' )
);

/* The end of a draft is the day somebody issues it, and nobody has. Naming
 * today would be a date that is wrong tomorrow. */
gwc_vt_ld_check(
	'and does not invent the date it ends',
	false === strpos( gwc_vt_letter_period_words( '', '', '2026-02-14' ), gwc_vt_display_date( gmdate( 'Y-m-d' ) ) )
		|| '2026-02-14' === gmdate( 'Y-m-d' ),
	gwc_vt_letter_period_words( '', '', '2026-02-14' )
);

gwc_vt_ld_check(
	'an issued letter names both ends',
	gwc_vt_display_date( '2026-02-14' ) . ' to ' . gwc_vt_display_date( '2026-08-28' )
		=== gwc_vt_letter_period_words( '', '', '2026-02-14', '2026-08-28' ),
	gwc_vt_letter_period_words( '', '', '2026-02-14', '2026-08-28' )
);

/* A letter issued before the start date was recorded, on a volunteer whose
 * entries have since gone. One real date still beats none. */
gwc_vt_ld_check(
	'and one with nothing left to name a start with still names its end',
	false !== strpos( gwc_vt_letter_period_words( '', '', '', '2026-08-28' ), gwc_vt_display_date( '2026-08-28' ) ),
	gwc_vt_letter_period_words( '', '', '', '2026-08-28' )
);

/* A period somebody asked for is not overridden by what it turned out to cover:
 * a letter for March that lists nothing until the 9th still says March. */
gwc_vt_ld_check(
	'a period that was asked for wins over the dates it happens to cover',
	gwc_vt_letter_period_words( '2026-03-01', '2026-03-31' )
		=== gwc_vt_letter_period_words( '2026-03-01', '2026-03-31', '2026-03-09', '2026-03-30' ),
	gwc_vt_letter_period_words( '2026-03-01', '2026-03-31', '2026-03-09', '2026-03-30' )
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

/* Three, not five. Issuing is one act and produces no document: printing,
 * posting and emailing are things that happen to a letter that already exists,
 * so they belong on the issued row and cannot be reached from a draft. */
gwc_vt_ld_check(
	'a draft row offers exactly Open, Issue it and Discard',
	array( 'Open', 'Issue it', 'Discard' ) === $GLOBALS['gwc_vt_ld_links'],
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

/* The box counted its own rows in a line above them — "1 on this record · 1
 * draft" over a table with one draft row in it. Every word of that is visible
 * underneath, and the badge says which kind it is. Deleted rather than reworded:
 * a header that restates the thing it heads is the same fault as a notice
 * restating a filter, which this plugin has already removed twice. */
gwc_vt_ld_check(
	'the box does not count its own rows above them',
	! function_exists( 'gwc_vt_letters_box_count' )
		&& false === strpos( $GLOBALS['gwc_vt_ld_box'], 'on this record' ),
	function_exists( 'gwc_vt_letters_box_count' ) ? 'the function is still there' : 'in the markup'
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

echo "\n── 10. Issuing, then delivering ─────────────────────────────────────\n";

$GLOBALS['gwc_vt_ld_iss'] = gwc_vt_ld_volunteer( 'Zzld Issuedsubject' );
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_iss'], '2026-04-04', 120 );
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_iss'], '2026-04-11', 90 );

$GLOBALS['gwc_vt_ld_built'] = gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_iss'] );

gwc_vt_ld_check(
	'the builder says which entries it used',
	2 === count( $GLOBALS['gwc_vt_ld_built']->entry_ids ),
	implode( ',', $GLOBALS['gwc_vt_ld_built']->entry_ids )
);

/* Issuing with no medium: the reference is minted and the record written, and
 * the letter has not gone anywhere. That is a legitimate state now. */
$GLOBALS['gwc_vt_ld_rec_id'] = gwc_vt_log_letter( $GLOBALS['gwc_vt_ld_built'] );
$GLOBALS['gwc_vt_ld_rec']    = gwc_vt_letter_record( $GLOBALS['gwc_vt_ld_rec_id'] );

gwc_vt_ld_check(
	'issuing writes a record with no delivery on it',
	$GLOBALS['gwc_vt_ld_rec_id'] > 0 && array() === $GLOBALS['gwc_vt_ld_rec']['deliveries']
);

gwc_vt_ld_check(
	'the record remembers the date the letter starts from',
	'2026-04-04' === $GLOBALS['gwc_vt_ld_rec']['covers_from'],
	$GLOBALS['gwc_vt_ld_rec']['covers_from']
);

/* And a letter issued before that was stored reads it off the entries it
 * listed, rather than being written back — an append-only log is not somewhere
 * to quietly fill in fields that were not there at the time. */
delete_post_meta( $GLOBALS['gwc_vt_ld_rec_id'], GWC_VT_LETTER_COVERS_FROM );

gwc_vt_ld_check(
	'and works it out when it was issued before that was recorded',
	'2026-04-04' === gwc_vt_letter_covers_from( $GLOBALS['gwc_vt_ld_rec_id'] )
		&& '' === (string) get_post_meta( $GLOBALS['gwc_vt_ld_rec_id'], GWC_VT_LETTER_COVERS_FROM, true ),
	gwc_vt_letter_covers_from( $GLOBALS['gwc_vt_ld_rec_id'] )
);

update_post_meta( $GLOBALS['gwc_vt_ld_rec_id'], GWC_VT_LETTER_COVERS_FROM, '2026-04-04' );

gwc_vt_ld_check(
	'and the record remembers which entries the letter listed',
	$GLOBALS['gwc_vt_ld_built']->entry_ids === $GLOBALS['gwc_vt_ld_rec']['entry_ids'],
	implode( ',', $GLOBALS['gwc_vt_ld_rec']['entry_ids'] )
);

/* IDs and nothing else. The log holds no name and outlives the volunteer, so a
 * stored copy of the shift list would put their service history into the one
 * record designed to survive their erasure. */
gwc_vt_ld_check(
	'and remembers nothing else about them',
	'' === (string) get_post_meta( $GLOBALS['gwc_vt_ld_rec_id'], '_gwc_vt_letter_rows', true )
		&& false === strpos( (string) get_post_meta( $GLOBALS['gwc_vt_ld_rec_id'], GWC_VT_LETTER_ENTRY_IDS, true ), 'Sorting' )
);

gwc_vt_log_delivery( $GLOBALS['gwc_vt_ld_rec_id'], 'print' );
gwc_vt_log_delivery( $GLOBALS['gwc_vt_ld_rec_id'], 'post', 'Jefferson County Probation' );
gwc_vt_log_delivery( $GLOBALS['gwc_vt_ld_rec_id'], 'email', 'officer@probation.example', false );

$GLOBALS['gwc_vt_ld_sent'] = gwc_vt_letter_deliveries( $GLOBALS['gwc_vt_ld_rec_id'] );

gwc_vt_ld_check(
	'three deliveries are recorded, oldest first',
	3 === count( $GLOBALS['gwc_vt_ld_sent'] )
		&& 'print' === $GLOBALS['gwc_vt_ld_sent'][0]['medium']
		&& 'email' === $GLOBALS['gwc_vt_ld_sent'][2]['medium'],
	count( $GLOBALS['gwc_vt_ld_sent'] ) . ' rows'
);

/* The fact the log was missing: a printed letter has no destination the plugin
 * can know, and a posted one does. */
gwc_vt_ld_check(
	'a posted letter records who it was posted to',
	'Jefferson County Probation' === $GLOBALS['gwc_vt_ld_sent'][1]['recipient']
		&& '' === $GLOBALS['gwc_vt_ld_sent'][0]['recipient']
);

/* A failed send is recorded as a failed send. A log that only holds successes
 * cannot tell "we never sent it" from "our mail server was down". */
gwc_vt_ld_check(
	'and a send that failed says so',
	false === $GLOBALS['gwc_vt_ld_sent'][2]['ok']
		&& false !== stripos( gwc_vt_delivery_words( $GLOBALS['gwc_vt_ld_sent'][2] ), 'failed' ),
	gwc_vt_delivery_words( $GLOBALS['gwc_vt_ld_sent'][2] )
);

gwc_vt_ld_check(
	'each delivery says when and by what means',
	false !== stripos( gwc_vt_delivery_words( $GLOBALS['gwc_vt_ld_sent'][0] ), 'printed' )
		&& false !== stripos( gwc_vt_delivery_words( $GLOBALS['gwc_vt_ld_sent'][1] ), 'posted to' ),
	gwc_vt_delivery_words( $GLOBALS['gwc_vt_ld_sent'][1] )
);

echo "\n── 11. A delivery reproduces the letter that was issued ─────────────\n";

$GLOBALS['gwc_vt_ld_again'] = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_rec'] );

gwc_vt_ld_check(
	'a rebuild is the letter that was issued',
	$GLOBALS['gwc_vt_ld_again'] instanceof GWC_VT_Letter
		&& gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_ld_rec'], $GLOBALS['gwc_vt_ld_again'] )
);

/* The whole reason the entry IDs are stored. A shift verified after the letter
 * was issued is inside the same period, so a rebuild from the period would put
 * a bigger number on the page under a reference that digests the smaller one. */
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_iss'], '2026-04-18', 240 );

$GLOBALS['gwc_vt_ld_after'] = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_rec'] );

gwc_vt_ld_check(
	'a shift added since is not on it',
	2 === count( $GLOBALS['gwc_vt_ld_after']->entries )
		&& gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_ld_rec'], $GLOBALS['gwc_vt_ld_after'] ),
	count( $GLOBALS['gwc_vt_ld_after']->entries ) . ' rows'
);

/* And the sabotage: a shift the letter DID list, edited. That letter can no
 * longer be produced, and the delivery handlers refuse rather than post a
 * document to a court whose own reference will fail when they ring to check. */
$GLOBALS['gwc_vt_ld_victim'] = (int) $GLOBALS['gwc_vt_ld_rec']['entry_ids'][0];
$GLOBALS['gwc_vt_ld_was']    = (int) get_post_meta( $GLOBALS['gwc_vt_ld_victim'], GWC_VT_ENTRY_MINUTES, true );

update_post_meta( $GLOBALS['gwc_vt_ld_victim'], GWC_VT_ENTRY_MINUTES, 999 );

$GLOBALS['gwc_vt_ld_broken'] = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_rec'] );

gwc_vt_ld_check(
	'but a shift it listed being changed makes the rebuild unfaithful',
	! gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_ld_rec'], $GLOBALS['gwc_vt_ld_broken'] )
);

update_post_meta( $GLOBALS['gwc_vt_ld_victim'], GWC_VT_ENTRY_MINUTES, $GLOBALS['gwc_vt_ld_was'] );

gwc_vt_ld_check(
	'and putting it back makes it faithful again',
	gwc_vt_rebuild_is_faithful(
		$GLOBALS['gwc_vt_ld_rec'],
		gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_rec'] )
	)
);

/* A letter issued before any of this existed has no stored IDs and carried its
 * medium on the record. Both have to keep reading, because an audit log is not
 * something you migrate to make old rows look like new ones. */
$GLOBALS['gwc_vt_ld_old'] = gwc_vt_log_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_iss'] ), 'email', 'old@example.test' );
delete_post_meta( $GLOBALS['gwc_vt_ld_old'], GWC_VT_LETTER_ENTRY_IDS );
delete_post_meta( $GLOBALS['gwc_vt_ld_old'], GWC_VT_LETTER_DELIVERY );

$GLOBALS['gwc_vt_ld_oldrec'] = gwc_vt_letter_record( $GLOBALS['gwc_vt_ld_old'] );

gwc_vt_ld_check(
	'a letter from before deliveries existed still reports how it went out',
	1 === count( $GLOBALS['gwc_vt_ld_oldrec']['deliveries'] )
		&& 'email' === $GLOBALS['gwc_vt_ld_oldrec']['deliveries'][0]['medium']
		&& 'old@example.test' === $GLOBALS['gwc_vt_ld_oldrec']['deliveries'][0]['recipient'],
	wp_json_encode( $GLOBALS['gwc_vt_ld_oldrec']['deliveries'] )
);

gwc_vt_ld_check(
	'and one with no stored entries still rebuilds, from its period',
	gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_oldrec'] ) instanceof GWC_VT_Letter
);

echo "\n── 12. A delivery is dated when the letter was issued ───────────────\n";

/* The bug this section exists for: gwc_vt_build_letter() stamps issued_at with
 * now, because it has no idea it is reproducing something rather than producing
 * it. A letter printed a week after issue was therefore dated the day it was
 * printed, and stated a period ending that day, while the log and the box said
 * otherwise. The reference digest does not cover the date, so nothing caught
 * it — the delivery was "faithful" and wrongly dated at the same time. */
wp_update_post(
	array(
		'ID'            => $GLOBALS['gwc_vt_ld_rec_id'],
		'post_date'     => '2026-04-20 09:00:00',
		'post_date_gmt' => '2026-04-20 09:00:00',
	)
);

$GLOBALS['gwc_vt_ld_dated'] = gwc_vt_letter_record( $GLOBALS['gwc_vt_ld_rec_id'] );
$GLOBALS['gwc_vt_ld_reb']   = gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_dated'] );

gwc_vt_ld_check(
	'a fresh rebuild is dated now, which is why it has to be stamped',
	gmdate( 'Y-m-d', $GLOBALS['gwc_vt_ld_reb']->issued_at ) !== '2026-04-20',
	gmdate( 'Y-m-d', $GLOBALS['gwc_vt_ld_reb']->issued_at )
);

/* Checked BEFORE stamping, and the order is the whole point — see the note on
 * gwc_vt_stamp_issued_letter(). Stamping first would compare the record against
 * itself and answer "faithful" for every letter however far the records had
 * moved, which is the verifier-that-always-says-yes this plugin warns about,
 * and worse here because this one gates whether a document may be sent. */
gwc_vt_ld_check(
	'and is checked before it is stamped',
	gwc_vt_rebuild_is_faithful( $GLOBALS['gwc_vt_ld_dated'], $GLOBALS['gwc_vt_ld_reb'] )
);

gwc_vt_stamp_issued_letter( $GLOBALS['gwc_vt_ld_dated'], $GLOBALS['gwc_vt_ld_reb'] );

gwc_vt_ld_check(
	'stamping puts the log’s own date back on it',
	'2026-04-20' === gmdate( 'Y-m-d', $GLOBALS['gwc_vt_ld_reb']->issued_at ),
	gmdate( 'Y-m-d', $GLOBALS['gwc_vt_ld_reb']->issued_at )
);

gwc_vt_ld_check(
	'and the log’s own reference',
	$GLOBALS['gwc_vt_ld_dated']['reference'] === $GLOBALS['gwc_vt_ld_reb']->reference,
	$GLOBALS['gwc_vt_ld_reb']->reference
);

/* The document says it too, in both the places it prints a date. */
$GLOBALS['gwc_vt_ld_page'] = gwc_vt_render_letter(
	$GLOBALS['gwc_vt_ld_reb'],
	'draft',
	array_merge( $GLOBALS['gwc_vt_ld_dated'], array( 'faithful' => true ) )
);

/* Counted rather than merely present: the date is printed in the letterhead,
 * in the period sentence and in the timestamp, and the bug put today's date in
 * every one of them. Asserting the day it was rendered appears nowhere is the
 * half that would have failed before. */
/* The period sentence, checked on its own rather than by searching the page:
 * "today" appears legitimately in the attestation beside every shift verified
 * today, so a whole-document search for it proves nothing. The period is where
 * the bug showed, and where it must not. */
gwc_vt_ld_check(
	'and the period sentence names the issue date, not the day it was rendered',
	false !== strpos( gwc_vt_letter_period( $GLOBALS['gwc_vt_ld_reb'] ), gwc_vt_display_date( '2026-04-20' ) )
		&& false === strpos( gwc_vt_letter_period( $GLOBALS['gwc_vt_ld_reb'] ), gwc_vt_display_date( gmdate( 'Y-m-d' ) ) ),
	gwc_vt_letter_period( $GLOBALS['gwc_vt_ld_reb'] )
);

/* And the document itself, which prints it in the letterhead as well. */
gwc_vt_ld_check(
	'and so does the document, more than once',
	substr_count( $GLOBALS['gwc_vt_ld_page'], gwc_vt_display_date( '2026-04-20' ) ) >= 2,
	substr_count( $GLOBALS['gwc_vt_ld_page'], gwc_vt_display_date( '2026-04-20' ) ) . ' mentions of the issue date'
);

/* Reopened and reproduced exactly, so it is the letter: its real reference, no
 * band, and no print button — putting an issued letter on paper is a delivery
 * and has to be recorded, so the unlogged route is not offered beside the
 * logged one. */
gwc_vt_ld_check(
	'a faithful reproduction carries its reference and no draft band',
	false !== strpos( $GLOBALS['gwc_vt_ld_page'], $GLOBALS['gwc_vt_ld_dated']['reference'] )
		&& false === strpos( $GLOBALS['gwc_vt_ld_page'], 'Draft — not issued' )
		&& false === strpos( $GLOBALS['gwc_vt_ld_page'], 'has not been issued' )
);

gwc_vt_ld_check(
	'and offers no print button of its own',
	false === strpos( $GLOBALS['gwc_vt_ld_page'], 'gwcvt-print-button' )
);

echo "\n── 13. A delivery names the record, not the reference ───────────────\n";

/* A reference is a digest over what the letter states, so two letters issued on
 * the same day for the same volunteer over the same shifts have the SAME code.
 * That is correct, and it makes the reference useless for saying which row of
 * the log a delivery belongs to — the lookup returns whichever the query orders
 * first, and the delivery lands on a letter nobody touched. The document would
 * still be right, which is exactly what would have kept this hidden. */
$GLOBALS['gwc_vt_ld_twin'] = gwc_vt_log_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_iss'] ) );
$GLOBALS['gwc_vt_ld_twin2'] = gwc_vt_log_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_iss'] ) );

gwc_vt_ld_check(
	'two letters issued over the same shifts share a reference',
	get_the_title( $GLOBALS['gwc_vt_ld_twin'] ) === get_the_title( $GLOBALS['gwc_vt_ld_twin2'] )
		&& $GLOBALS['gwc_vt_ld_twin'] !== $GLOBALS['gwc_vt_ld_twin2'],
	get_the_title( $GLOBALS['gwc_vt_ld_twin'] )
);

gwc_vt_ld_check(
	'so a delivery URL names the record it belongs to',
	false !== strpos( gwc_vt_delivery_url( 'gwc_vt_letter_deliver_print', $GLOBALS['gwc_vt_ld_twin2'] ), 'record=' . $GLOBALS['gwc_vt_ld_twin2'] )
		&& false === strpos( gwc_vt_delivery_url( 'gwc_vt_letter_deliver_print', $GLOBALS['gwc_vt_ld_twin2'] ), 'reference=' )
);

gwc_vt_log_delivery( $GLOBALS['gwc_vt_ld_twin2'], 'print' );

gwc_vt_ld_check(
	'and lands on that one and not its twin',
	1 === count( gwc_vt_letter_deliveries( $GLOBALS['gwc_vt_ld_twin2'] ) )
		&& array() === gwc_vt_letter_deliveries( $GLOBALS['gwc_vt_ld_twin'] )
);

echo "\n── 14. A draft holds still until it is issued ───────────────────────\n";

/* ── Why a draft stopped being a live query ──────────────────────────────────
 * It used to recompute from the record every time anybody looked, on the
 * grounds that a draft is a QUESTION and the answer belongs to the moment of
 * issue. That is defensible and it was wrong in practice: somebody reads a
 * draft, tells a court roughly what it will say, issues it a fortnight later
 * and sends a different document. You reviewed one thing and posted another.
 *
 * So a draft fixes two things when it is made, and neither of them is a figure.
 * The end of its period, so "everything on record" stops meaning "up to
 * whenever somebody gets round to this". And the moment its attestations are
 * counted as of, so a shift verified afterwards is not part of this letter.
 *
 * The figures are still computed from the entries every single time — that rule
 * has not moved. What changed is that the question now names a moment, so
 * asking it twice gives the same answer. */
$GLOBALS['gwc_vt_ld_hold'] = gwc_vt_ld_volunteer( 'Zzld Heldstill' );
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_hold'], '2026-05-01', 120 );

$GLOBALS['gwc_vt_ld_fixed'] = gwc_vt_letter_draft( gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_hold'] ) );

/* "Everything on record" is pinned to the day it was asked for, which is also
 * what lets the Period column print two dates instead of describing one. */
gwc_vt_ld_check(
	'an unbounded draft pins its end to the day it was made',
	gmdate( 'Y-m-d' ) === $GLOBALS['gwc_vt_ld_fixed']['to']
		|| wp_date( 'Y-m-d' ) === $GLOBALS['gwc_vt_ld_fixed']['to'],
	$GLOBALS['gwc_vt_ld_fixed']['to']
);

gwc_vt_ld_check(
	'and remembers the moment it was made, without a second copy of it',
	'' !== $GLOBALS['gwc_vt_ld_fixed']['as_of']
		&& '' === (string) get_post_meta( (int) $GLOBALS['gwc_vt_ld_fixed']['id'], '_gwc_vt_draft_as_of', true ),
	$GLOBALS['gwc_vt_ld_fixed']['as_of']
);

gwc_vt_ld_check(
	'the draft is worth two hours when it is made',
	120 === gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_fixed'] ) )->verified_minutes
);

/* The whole point. A second apart, because the cutoff is an instant. */
sleep( 1 );
$GLOBALS['gwc_vt_ld_after_draft'] = gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_hold'], '2026-05-08', 90 );

gwc_vt_ld_check(
	'and is still worth two hours after another shift is verified',
	120 === gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_fixed'] ) )->verified_minutes,
	(string) gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_fixed'] ) )->verified_minutes
);

/* Sabotage in the other direction: without the as-of the number moves, so the
 * check above is testing the lock and not merely the absence of a shift. */
gwc_vt_ld_check(
	'while the record itself has moved on, which is what makes that a lock',
	210 === gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'] )->verified_minutes,
	(string) gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'] )->verified_minutes
);

$GLOBALS['gwc_vt_ld_frozen'] = gwc_vt_letter_record(
	gwc_vt_log_letter( gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_hold'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_fixed'] ) ) )
);

gwc_vt_ld_check(
	'issuing states what the draft said, not what the record now holds',
	120 === (int) $GLOBALS['gwc_vt_ld_frozen']['minutes'] && 1 === (int) $GLOBALS['gwc_vt_ld_frozen']['entries'],
	$GLOBALS['gwc_vt_ld_frozen']['minutes'] . 'm over ' . $GLOBALS['gwc_vt_ld_frozen']['entries']
);

gwc_vt_ld_check(
	'and does not list the shift verified after the draft was made',
	! in_array( $GLOBALS['gwc_vt_ld_after_draft'], $GLOBALS['gwc_vt_ld_frozen']['entry_ids'], true ),
	implode( ',', $GLOBALS['gwc_vt_ld_frozen']['entry_ids'] )
);

gwc_vt_ld_check(
	'and the log keeps the moment, so the check can ask the same question',
	$GLOBALS['gwc_vt_ld_fixed']['as_of'] === $GLOBALS['gwc_vt_ld_frozen']['as_of'],
	$GLOBALS['gwc_vt_ld_frozen']['as_of']
);

echo "\n── 15. So the telephone check stops crying wolf ─────────────────────\n";

/* ── The failure this fixes, which was the quiet kind ────────────────────────
 * The checker rebuilt from the record as things stand NOW, so every letter
 * about somebody still volunteering reported "changed" the moment their next
 * shift was attested to. The document was untouched. The screen said "our
 * records have changed since" in a warning box, on the ordinary case — which
 * teaches whoever answers the phone that the warning means nothing, and then
 * the one that means something gets waved through too.
 *
 * Asked as of the moment the letter was fixed to, a shift verified since is
 * simply not part of the question. Nothing was added to the status vocabulary
 * to achieve that: the question was badly posed, not the answer. */
gwc_vt_ld_check(
	'a letter still matches after the volunteer works again',
	'match' === gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status'],
	gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status']
);

/* And the alarm that should fire, still fires. Editing a shift the letter
 * LISTS is exactly what the reference exists to catch. */
$GLOBALS['gwc_vt_ld_listed'] = (int) $GLOBALS['gwc_vt_ld_frozen']['entry_ids'][0];
$GLOBALS['gwc_vt_ld_orig']   = (int) get_post_meta( $GLOBALS['gwc_vt_ld_listed'], GWC_VT_ENTRY_MINUTES, true );

update_post_meta( $GLOBALS['gwc_vt_ld_listed'], GWC_VT_ENTRY_MINUTES, 999 );

gwc_vt_ld_check(
	'but a shift the letter lists being edited still reports changed',
	'changed' === gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status'],
	gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status']
);

update_post_meta( $GLOBALS['gwc_vt_ld_listed'], GWC_VT_ENTRY_MINUTES, $GLOBALS['gwc_vt_ld_orig'] );

gwc_vt_ld_check(
	'and matches again when it is put back',
	'match' === gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status']
);

/* Withdrawing an attestation is a change too, and in the direction that
 * matters: the organization no longer stands behind what the letter states. */
gwc_vt_unverify_entry( $GLOBALS['gwc_vt_ld_listed'] );

gwc_vt_ld_check(
	'and withdrawing an attestation reports changed',
	'changed' === gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status'],
	gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status']
);

/* A letter issued before any of this has no as-of, and the checker behaves
 * exactly as it always did rather than silently treating '' as a cutoff. */
delete_post_meta( (int) $GLOBALS['gwc_vt_ld_frozen']['id'], GWC_VT_LETTER_AS_OF );

gwc_vt_ld_check(
	'a letter from before this existed is still checked the old way',
	'changed' === gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status'],
	gwc_vt_verify_reference( $GLOBALS['gwc_vt_ld_frozen']['reference'] )['status']
);


echo "\n── 16. A letter can be addressed to whoever asked for it ────────────\n";

/* ── Why this exists ─────────────────────────────────────────────────────────
 * Most letters are handed to the volunteer and need none of this. Where a court
 * or a school asked to be sent one directly, an officer's name and a case
 * number are how the paperwork gets filed at the other end — and a document
 * that goes from the organization to the requester, never through the hands of
 * the person it is about, is the strongest control in ordinary use.
 *
 * ── And why a case number is allowed on a letter when ordered hours are not ──
 * The rule those would break is about ASSERTIONS. How many hours a court
 * ordered, who ordered them and by when are facts about the court's own
 * document, and an organization repeating them back is certifying somebody
 * else's paperwork. "Re: 2026-CR-1234" asserts nothing about the person; it
 * says which correspondence this is. Removing it would not change what the
 * letter CLAIMS, only make the envelope harder to file. */
$GLOBALS['gwc_vt_ld_addr'] = gwc_vt_ld_volunteer( 'Zzld Addressed' );
gwc_vt_ld_entry( $GLOBALS['gwc_vt_ld_addr'], '2026-06-02', 150 );

$GLOBALS['gwc_vt_ld_plainD'] = gwc_vt_letter_draft( gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_addr'] ) );

/* Absent unless asked for. A "To:" block over a blank line is worse than none. */
$GLOBALS['gwc_vt_ld_plainpage'] = gwc_vt_render_letter(
	gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_addr'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_plainD'] ) ),
	'draft'
);

gwc_vt_ld_check(
	'a letter with nobody to address prints no address block at all',
	false === strpos( $GLOBALS['gwc_vt_ld_plainpage'], 'gwcvt-addressed' )
		&& false === strpos( $GLOBALS['gwc_vt_ld_plainpage'], 'Re:' )
);

$GLOBALS['gwc_vt_ld_toD'] = gwc_vt_letter_draft(
	gwc_vt_add_letter_draft(
		$GLOBALS['gwc_vt_ld_addr'],
		'',
		'',
		"Officer J. Smith\nRichmond County Probation",
		'case 2026-CR-1234'
	)
);

/* A browser posts a textarea with CRLF and sanitize_textarea_field() leaves it
 * that way. Folding on "\n" alone then left the \r behind, and the screen
 * printed a line break followed by a comma. Normalized on the way in, so the
 * database holds one form. */
$GLOBALS['gwc_vt_ld_crlf'] = gwc_vt_letter_draft(
	gwc_vt_add_letter_draft( $GLOBALS['gwc_vt_ld_addr'], '', '', "Officer J. Smith\r\nRichmond County Probation", '' )
);

gwc_vt_ld_check(
	'an address is stored with one kind of line ending',
	false === strpos( $GLOBALS['gwc_vt_ld_crlf']['addressee'], "\r" ),
	wp_json_encode( $GLOBALS['gwc_vt_ld_crlf']['addressee'] )
);

gwc_vt_ld_check(
	'and folds onto one line without leaving a stray break behind',
	'Officer J. Smith, Richmond County Probation' === gwc_vt_one_line( $GLOBALS['gwc_vt_ld_crlf']['addressee'] )
		&& 'Officer J. Smith, Richmond County Probation' === gwc_vt_one_line( "Officer J. Smith\r\nRichmond County Probation" ),
	wp_json_encode( gwc_vt_one_line( $GLOBALS['gwc_vt_ld_crlf']['addressee'] ) )
);

gwc_vt_ld_check(
	'a draft remembers who it is for and what it concerns',
	"Officer J. Smith\nRichmond County Probation" === $GLOBALS['gwc_vt_ld_toD']['addressee']
		&& 'case 2026-CR-1234' === $GLOBALS['gwc_vt_ld_toD']['matter'],
	$GLOBALS['gwc_vt_ld_toD']['matter']
);

$GLOBALS['gwc_vt_ld_toL'] = gwc_vt_build_letter(
	$GLOBALS['gwc_vt_ld_addr'],
	gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_toD'] )
);
$GLOBALS['gwc_vt_ld_topage'] = gwc_vt_render_letter( $GLOBALS['gwc_vt_ld_toL'], 'draft' );

gwc_vt_ld_check(
	'and the letter prints both, the second as a Re: line',
	false !== strpos( $GLOBALS['gwc_vt_ld_topage'], 'Officer J. Smith' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_topage'], 'Richmond County Probation' )
		&& false !== strpos( $GLOBALS['gwc_vt_ld_topage'], 'Re: case 2026-CR-1234' )
);

/* Outside the digest, deliberately. A letter sent to two officers about one
 * matter states the same service and is the same document; making the addressee
 * part of the code would produce two codes for one set of facts, and the code
 * is a statement about the facts. */
gwc_vt_ld_check(
	'addressing does not change the reference',
	gwc_vt_reference_digest( $GLOBALS['gwc_vt_ld_toL']->reference )
		=== gwc_vt_reference_digest(
			gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_addr'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_plainD'] ) )->reference
		),
	gwc_vt_reference_digest( $GLOBALS['gwc_vt_ld_toL']->reference )
);

/* It survives issuing, so reopening or re-delivering reproduces the document
 * that went out rather than an unaddressed version of it. */
$GLOBALS['gwc_vt_ld_torec'] = gwc_vt_letter_record( gwc_vt_log_letter( $GLOBALS['gwc_vt_ld_toL'] ) );

gwc_vt_ld_check(
	'the log keeps it, and a rebuild prints it again',
	'case 2026-CR-1234' === $GLOBALS['gwc_vt_ld_torec']['matter']
		&& false !== strpos(
			gwc_vt_render_letter( gwc_vt_rebuild_issued_letter( $GLOBALS['gwc_vt_ld_torec'] ), 'print' ),
			'Re: case 2026-CR-1234'
		)
);

/* The rule this sits next to, checked rather than assumed: putting a case
 * number on a letter must not have opened a route for what a court REQUIRED to
 * reach one. tests/integration/required.php owns the full sweep; this is the
 * narrow version aimed at the field just added. */
update_post_meta( $GLOBALS['gwc_vt_ld_addr'], GWC_VT_VOLUNTEER_REQUIRED, 40 * 60 );

gwc_vt_ld_check(
	'and none of it lets a required-hours figure onto the letter',
	false === stripos(
		gwc_vt_render_letter(
			gwc_vt_build_letter( $GLOBALS['gwc_vt_ld_addr'], gwc_vt_letter_args_for_draft( $GLOBALS['gwc_vt_ld_toD'] ) ),
			'draft'
		),
		'requir'
	)
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

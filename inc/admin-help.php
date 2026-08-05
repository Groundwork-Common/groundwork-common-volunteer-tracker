<?php
/**
 * Contextual help, on the screens where somebody would ask.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gwcvt_settings_screen_loaded', 'gwcvt_add_settings_help' );
add_action( 'current_screen', 'gwcvt_add_screen_help' );

/* ── Why the Help tab and not a notice ───────────────────────────────────────
 * Everything here is the answer to a question somebody has once and then never
 * again: what does verifying actually mean, why can I not email this letter,
 * what happens to these records. Put on the screen it becomes furniture that
 * every coordinator reads past every day forever. Put in the Help tab it is
 * where WordPress has trained people to look, and it costs nobody anything
 * until they go looking.
 *
 * The wording follows the same rule as the letter: it says what the plugin
 * does and does not claim, and never says what a court or a school will
 * accept. That is not ours to tell anybody.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Add one help tab.
 *
 * @param WP_Screen $screen  The screen.
 * @param string    $id      Tab id.
 * @param string    $title   Tab title.
 * @param string[]  $paragraphs Body paragraphs, already translated.
 */
function gwcvt_add_help_tab( $screen, string $id, string $title, array $paragraphs ): void {
	$content = '';

	foreach ( $paragraphs as $paragraph ) {
		$content .= '<p>' . wp_kses( $paragraph, array( 'strong' => array(), 'em' => array(), 'code' => array() ) ) . '</p>';
	}

	$screen->add_help_tab(
		array(
			'id'      => $id,
			'title'   => $title,
			'content' => $content,
		)
	);
}

/**
 * The sidebar, which is the same wherever help appears.
 *
 * @param WP_Screen $screen The screen.
 */
function gwcvt_add_help_sidebar( $screen ): void {
	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ) . '</strong></p>' .
		'<p><a href="' . esc_url( gwcvt_settings_url() ) . '">' . esc_html__( 'Settings', 'groundwork-common-volunteer-tracker' ) . '</a></p>' .
		'<p><a href="https://github.com/Groundwork-Common/groundwork-common-volunteer-tracker/issues" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Report a problem', 'groundwork-common-volunteer-tracker' ) . '</a></p>'
	);
}

/**
 * Help for the settings screen.
 *
 * Hooked to gwcvt_settings_screen_loaded, which fires from the screen's own
 * `load-` hook — help tabs have to be added before anything is output, and
 * adding them from the renderer is the mistake that makes them silently not
 * appear.
 */
function gwcvt_add_settings_help(): void {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-letter',
		__( 'The letter', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Everything the letter says about your organization comes from this screen: the letterhead, who signs it, and the wording of the opening paragraph.', 'groundwork-common-volunteer-tracker' ),
			__( 'The wording accepts placeholders — <code>{name}</code>, <code>{hours}</code>, <code>{period}</code>, <code>{org}</code> and others listed above the fields — which are filled in for each volunteer when the letter is produced.', 'groundwork-common-volunteer-tracker' ),
			__( 'The disclaimer and the note about the reference code cannot be emptied. Saving either one blank restores the built-in wording. They are what tell a reader that <strong>your organization</strong>, not this plugin, is the authoritative record-keeper — and a letter without them has quietly started implying something nobody can stand behind.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-logging',
		__( 'Recording hours', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Durations are stored as whole minutes and can be typed however you think of them: <code>3.5</code>, <code>3:30</code>, <code>3h 30m</code> or <code>210m</code> all record three and a half hours.', 'groundwork-common-volunteer-tracker' ),
			__( 'A bare number over 24 is refused rather than guessed at. Typing <code>210</code> means two hundred and ten hours, which is nobody’s shift — it is almost always minutes typed into an hours field, so the form asks rather than recording a quarter of a year.', 'groundwork-common-volunteer-tracker' ),
			__( 'Rounding is always to the nearest increment, never up. Rounding up would mean the organization crediting hours nobody worked, which on this document is the one direction of error that matters.', 'groundwork-common-volunteer-tracker' ),
			__( 'The public form is switched off until you switch it on and choose a page for it. With it on, this site accepts a name, an email address and a date from anonymous visitors. Everything sent arrives unverified and attached to nobody until a member of staff matches it.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-privacy',
		__( 'Keeping and removing records', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Nothing is ever deleted while records are kept indefinitely, which is the default. That default is deliberate: a plugin that deleted on a schedule it chose would eventually destroy the weeks of Saturdays somebody needs for a court date they have not reached yet.', 'groundwork-common-volunteer-tracker' ),
			__( 'Anonymizing is usually the right action rather than deleting. Your grant reporting and your Form 990 need the hours; they do not need the name, and the hours identify nobody once it is gone.', 'groundwork-common-volunteer-tracker' ),
			__( 'A volunteer’s record can be held back from the sweep individually, with a reason — for when a court requires you to keep something longer than your own policy. A hold also blocks an erasure request from WordPress’s privacy tools, and the reason you record is shown to whoever handles it.', 'groundwork-common-volunteer-tracker' ),
			__( 'Requests under Tools → Export Personal Data and Erase Personal Data include volunteer records, shifts and issued letters. They work whether or not you have set a retention period.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_sidebar( $screen );
}

/**
 * Help for the screens that are not the settings screen.
 *
 * On `current_screen` rather than each screen's own `load-` hook, because that
 * is one place to decide rather than four registrations to keep in step — and
 * it fires early enough for help tabs on all of them.
 *
 * @param WP_Screen $screen The screen being loaded.
 */
function gwcvt_add_screen_help( $screen ): void {
	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	if ( 'edit-' . GWCVT_ENTRY_TYPE === $screen->id ) {
		gwcvt_add_hours_help( $screen );
		return;
	}

	if ( 'edit-' . GWCVT_VOLUNTEER_TYPE === $screen->id ) {
		gwcvt_add_volunteers_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWCVT_LETTERS_PAGE ) ) {
		gwcvt_add_letters_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWCVT_QUICK_ADD_PAGE ) ) {
		gwcvt_add_quick_add_help( $screen );
	}
}

/**
 * The hours list.
 *
 * @param WP_Screen $screen The screen.
 */
function gwcvt_add_hours_help( $screen ): void {
	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-verifying',
		__( 'Verifying hours', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Click the <strong>Not yet verified</strong> badge to verify a shift. Your name and the date are recorded and appear on any letter that includes it, so verifying is you attesting, as a member of staff, that these hours were worked.', 'groundwork-common-volunteer-tracker' ),
			__( 'To do several at once, tick them and choose Verify from the Bulk actions menu. To undo one, use <strong>Withdraw verification</strong> in the row’s hover menu — it is kept out of the way on purpose, so a quick click cannot undo somebody’s attestation by accident.', 'groundwork-common-volunteer-tracker' ),
			__( 'Only verified hours are counted on a letter. Unverified ones can be listed and marked as such if you turn that on, but they are never added to the total the letter states.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-matching',
		__( 'Hours sent in by volunteers', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Shifts sent through the public form arrive attached to nobody, shown in italics as <em>not yet matched</em>. The name and email on them are what somebody typed into a form — treat them as claims until you have checked.', 'groundwork-common-volunteer-tracker' ),
			__( 'Open one and it will offer to attach it to a volunteer whose email or name matches exactly, or to create a record from what was submitted. Choose <strong>Awaiting matching</strong> in the filter above to see only these.', 'groundwork-common-volunteer-tracker' ),
			__( 'Matching and verifying are separate on purpose. Attaching says whose hours these are; verifying says they happened. Doing the first does not do the second.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_sidebar( $screen );
}

/**
 * The volunteers list.
 *
 * @param WP_Screen $screen The screen.
 */
function gwcvt_add_volunteers_help( $screen ): void {
	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-volunteers',
		__( 'Volunteer records', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'One record per person, which is what makes a letter possible — forty shifts typed as “Jane Doe”, “jane doe” and “J. Doe” cannot be added together.', 'groundwork-common-volunteer-tracker' ),
			__( 'Opening a volunteer shows every shift they have logged and every letter already issued for them, with a link to check any of those references against your current records.', 'groundwork-common-volunteer-tracker' ),
			__( 'An email address is optional, but a letter can only be emailed to somebody who has one on file. Without it the letter can still be printed.', 'groundwork-common-volunteer-tracker' ),
			__( 'Volunteers never sign in and never receive an account. Nothing here is visible to the public or through the site’s search.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_sidebar( $screen );
}

/**
 * The letters screen.
 *
 * @param WP_Screen $screen The screen.
 */
function gwcvt_add_letters_help( $screen ): void {
	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-producing',
		__( 'Producing a letter', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Choose a volunteer and, if you need one, a date range. Leaving both dates empty covers their whole time volunteering.', 'groundwork-common-volunteer-tracker' ),
			__( 'Printing opens the letter in a new tab; use your browser’s print dialog and choose “Save as PDF” if you need a file. Both printing and emailing are recorded in the log — a printed letter has left the building just as much as an emailed one.', 'groundwork-common-volunteer-tracker' ),
			__( 'The letter is built fresh from your records every time it is produced. It is never a stored copy, so it always states what you currently have on file.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-references',
		__( 'Checking a reference', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Every letter carries a reference code. Somebody who has been sent one — a court, a school, an employer — can phone and read it out, and this screen will tell you whether the letter still matches your records.', 'groundwork-common-volunteer-tracker' ),
			__( 'The code covers every detail the letter prints, not just the total: the dates, the activities, the supervisors and each shift’s hours. Two shifts swapped so the total came out the same will still show as changed.', 'groundwork-common-volunteer-tracker' ),
			__( '<strong>Records have changed</strong> is not an accusation. Hours get corrected and shifts get verified after a letter goes out, and all of that is ordinary. The whole letter is shown as your records stand now so you can compare it against the copy somebody was sent.', 'groundwork-common-volunteer-tracker' ),
			__( 'What the code proves is that a document matches your records. It does not prove the hours were worked — that is what your staff attested to, and it is stated on the letter itself.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_sidebar( $screen );
}

/**
 * The log-a-day screen.
 *
 * @param WP_Screen $screen The screen.
 */
function gwcvt_add_quick_add_help( $screen ): void {
	gwcvt_add_help_tab(
		$screen,
		'gwcvt-help-quick-add',
		__( 'Logging a day', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'For typing up a sign-in sheet. Whatever the shift had in common — the date, what people were doing, who supervised — goes in once at the top, and each volunteer and their hours go in the list below.', 'groundwork-common-volunteer-tracker' ),
			__( 'Leave any row empty to skip it. A row with only half of it filled in is reported rather than silently ignored, so nobody’s hours go missing quietly.', 'groundwork-common-volunteer-tracker' ),
			__( 'Everything logged here arrives unverified, the same as any other entry. Use the link in the confirmation to go and verify the lot.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwcvt_add_help_sidebar( $screen );
}

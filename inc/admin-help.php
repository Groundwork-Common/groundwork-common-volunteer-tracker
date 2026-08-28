<?php
/**
 * Contextual help, on the screens where somebody would ask.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'current_screen', 'gwc_vt_add_screen_help' );

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
function gwc_vt_add_help_tab( $screen, string $id, string $title, array $paragraphs ): void {
	$content = '';

	foreach ( $paragraphs as $paragraph ) {
		$content .= '<p>' . wp_kses(
			$paragraph,
			array(
				'strong' => array(),
				'em'     => array(),
				'code'   => array(),
			)
		) . '</p>';
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
function gwc_vt_add_help_sidebar( $screen ): void {
	/* The guide first, and named for what it contains rather than "Help" —
	 * somebody is already reading help, so a link called Help is a link to
	 * where they are. What they might want is the thing these tabs are not:
	 * steps, in order, for a task.
	 *
	 * This is the whole reason the page is findable at all. The tab is where
	 * WordPress trained people to look; the page is where the how-tos are; and
	 * without this line the only route between them is knowing it exists.
	 *
	 * function_exists() because inc/admin-help-page.php is required after this
	 * file — and because a site that has somehow loaded one without the other
	 * should lose a link, not fatal on every admin screen. */
	$guide = '';

	if ( function_exists( 'gwc_vt_help_page_url' ) ) {
		/* Landing on the topic for THIS screen rather than at the guide's
		 * front. Somebody reading the Credentials help wants the four how-tos
		 * for credentials, not the top of a page with ninety-two steps below
		 * it. */
		$topic = function_exists( 'gwc_vt_help_topic_for_screen' )
			? gwc_vt_help_topic_for_screen( (string) $screen->id )
			: '';

		$guide = '<p><a href="' . esc_url( gwc_vt_help_page_url( $topic ) ) . '">'
			. esc_html__( 'How-to guide', 'groundwork-common-volunteer-tracker' )
			. '</a></p>';
	}

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ) . '</strong></p>' .
		$guide .
		'<p><a href="' . esc_url( gwc_vt_settings_url() ) . '">' . esc_html__( 'Settings', 'groundwork-common-volunteer-tracker' ) . '</a></p>' .
		'<p><a href="' . esc_url( GWC_VT_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Report a problem', 'groundwork-common-volunteer-tracker' ) . '</a></p>'
	);
}

/**
 * Help for the settings screen.
 *
 * ── It used to be on its own hook, and that cost four special cases ──────────
 * It was hooked to gwc_vt_settings_screen_loaded, on the reasoning that help
 * tabs must be added before anything is output. True, and satisfied by
 * `current_screen` as well — which is where the other twelve screens add
 * theirs, and which fires during `load-{$hook}`, before any output.
 *
 * What the separate route actually bought was a screen that four different
 * things had to know was special: the guard that checks every screen has help,
 * the Help page that asks each screen what its tabs would say, the check that
 * every sidebar links to the guide, and the check that the link lands on the
 * right topic. Each needed two lines saying "and settings, differently". Each
 * was written after somebody found the screen missing from something.
 *
 * The hook still exists and is still documented — it is a public extension
 * point and removing it would break anybody using it. Help simply stopped
 * being the thing that depends on it.
 *
 * @param WP_Screen|null $screen The screen to add help to, or null for the
 *                                current one.
 */
function gwc_vt_add_settings_help( $screen = null ): void {
	/* Takes a screen, and falls back to the current one.
	 *
	 * It used to only ever ask get_current_screen(), which is right for the
	 * hook it is on and useless to anything that wants to know what this screen
	 * WOULD say — the Help page asks every screen exactly that, and got an
	 * empty section back. */
	$screen = $screen instanceof WP_Screen ? $screen : get_current_screen();

	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	/* Only when there is a Letter tab to describe. */
	if ( gwc_vt_letters_enabled() ) {
		gwc_vt_add_help_tab(
			$screen,
			'gwc-vt-help-letter',
			__( 'The letter', 'groundwork-common-volunteer-tracker' ),
			array(
				__( 'Everything the letter says about your organization comes from this screen: the letterhead, who signs it, and the wording of the opening paragraph.', 'groundwork-common-volunteer-tracker' ),
				__( 'The wording accepts placeholders — <code>{name}</code>, <code>{hours}</code>, <code>{period}</code>, <code>{org}</code> and others listed above the fields — which are filled in for each volunteer when the letter is produced.', 'groundwork-common-volunteer-tracker' ),
				__( 'The disclaimer and the note about the reference code cannot be emptied. Saving either one blank restores the built-in wording. They are what tell a reader that <strong>your organization</strong>, not this plugin, is the authoritative record-keeper — and a letter without them has quietly started implying something nobody can stand behind.', 'groundwork-common-volunteer-tracker' ),
			)
		);
	}

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-logging',
		__( 'Recording hours', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Durations are stored as whole minutes and can be typed however you think of them: <code>3.5</code>, <code>3:30</code>, <code>3h 30m</code> or <code>210m</code> all record three and a half hours.', 'groundwork-common-volunteer-tracker' ),
			__( 'A bare number over 24 is refused rather than guessed at. Typing <code>210</code> means two hundred and ten hours, which is nobody’s shift — it is almost always minutes typed into an hours field, so the form asks rather than recording a quarter of a year.', 'groundwork-common-volunteer-tracker' ),
			__( 'Rounding is always to the nearest increment, never up. Rounding up would mean the organization crediting hours nobody worked, which on this document is the one direction of error that matters.', 'groundwork-common-volunteer-tracker' ),
			__( 'The public form is switched off until you switch it on and choose a page for it. With it on, this site accepts a name, an email address and a date from anonymous visitors. Everything sent arrives unverified and attached to nobody until a member of staff matches it.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-shifts',
		__( 'Shifts', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Off until you switch it on. With it on, a Schedule screen appears where you can plan shifts ahead of time, put volunteers on them and print a roster. Nothing about it touches hours: a scheduled shift is a plan, and hours are still logged afterwards and still have to be verified.', 'groundwork-common-volunteer-tracker' ),
			__( 'Letting people sign up from your site is a second switch, because taking names on the phone and accepting them from strangers are different decisions. With it on, this site accepts a name and an email address from anonymous visitors, and the page it is on must be chosen here.', 'groundwork-common-volunteer-tracker' ),
			__( 'Reminders and the daily summary are the only mail this plugin sends without somebody pressing a button, and both are off until you turn them on. A confirmation when somebody signs up is not optional — a booking nobody can be told about is one they have no record of and no way out of.', 'groundwork-common-volunteer-tracker' ),
			__( 'The daily summary lists shifts in the next week that are short of people, and shifts that have happened without their hours being logged. Nothing is sent on a day when there is nothing to report.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-privacy',
		__( 'Keeping and removing records', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Nothing is ever deleted while records are kept indefinitely, which is the default. That default is deliberate: a plugin that deleted on a schedule it chose would eventually destroy the weeks of Saturdays somebody needs for a court date they have not reached yet.', 'groundwork-common-volunteer-tracker' ),
			__( 'Anonymizing is usually the right action rather than deleting. Your grant reporting and your Form 990 need the hours; they do not need the name, and the hours identify nobody once it is gone.', 'groundwork-common-volunteer-tracker' ),
			__( 'A volunteer’s record can be held back from the sweep individually, with a reason — for when a court requires you to keep something longer than your own policy. A hold also blocks an erasure request from WordPress’s privacy tools, and the reason you record is shown to whoever handles it.', 'groundwork-common-volunteer-tracker' ),
			__( 'Requests under Tools → Export Personal Data and Erase Personal Data include volunteer records, shifts, issued letters, and any shifts somebody signed up for — including a signup from a person who never became a volunteer, which nothing else in the plugin would find. They work whether or not you have set a retention period.', 'groundwork-common-volunteer-tracker' ),
			__( 'Deleting the plugin removes none of it. Every volunteer, shift, signup, hour entry and issued letter stays exactly where it is, and so do the two permissions this plugin adds to your roles. Deactivating does the same. That means deleting the plugin is not a way to remove somebody\'s data — use the retention policy or the Erase Personal Data tool first.', 'groundwork-common-volunteer-tracker' ),
			__( 'The one thing you can ask it to clean up on deletion is its own configuration, with the checkbox under <strong>Removing this plugin</strong>. Even armed, it deletes no records of any kind.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-copies',
		__( 'Staging and copies of this site', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'A copy of this site restored from a backup — a staging server, a developer\'s machine, a clone made to try an upgrade — has the real volunteers and the real email addresses in it. Left running, it will send reminders, confirmations and verification letters to those people, about court-ordered service, from a site nobody is watching.', 'groundwork-common-volunteer-tracker' ),
			__( 'Two constants stop it, and they go in <code>wp-config.php</code> on the copy rather than on the live site, because the copy is what you control when you make one.', 'groundwork-common-volunteer-tracker' ),
			__( 'Set <code>GWC_VT_MAIL_MODE</code> to <code>off</code> and this plugin sends nothing at all. Set it to <code>trap</code>, and also set <code>GWC_VT_MAIL_ALLOW</code> to your own address, and every message is redirected there instead, with the site\'s name in the subject and a line saying who it was really addressed to.', 'groundwork-common-volunteer-tracker' ),
			__( 'Neither constant needs to exist on the live site. Unset means normal delivery, so a site that has never heard of them behaves exactly as you would expect. Trap mode with no address to trap to sends nothing rather than falling through to the real recipient.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
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
function gwc_vt_add_screen_help( $screen ): void {
	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	if ( 'edit-' . GWC_VT_ENTRY_TYPE === $screen->id ) {
		gwc_vt_add_hours_help( $screen );
		return;
	}

	if ( 'edit-' . GWC_VT_VOLUNTEER_TYPE === $screen->id ) {
		gwc_vt_add_volunteers_help( $screen );
		return;
	}

	if ( gwc_vt_letters_enabled() && false !== strpos( (string) $screen->id, GWC_VT_PRODUCE_PAGE ) ) {
		gwc_vt_add_produce_help( $screen );
		return;
	}

	if ( gwc_vt_letters_enabled() && false !== strpos( (string) $screen->id, GWC_VT_LETTERS_PAGE ) ) {
		gwc_vt_add_letters_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_DASHBOARD_PAGE ) ) {
		gwc_vt_add_dashboard_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_SCHEDULE_PAGE ) ) {
		gwc_vt_add_schedule_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_QUICK_ADD_PAGE ) ) {
		gwc_vt_add_quick_add_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_SETTINGS_PAGE ) ) {
		gwc_vt_add_settings_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_CREDENTIALS_PAGE ) ) {
		gwc_vt_add_credentials_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_APPLICATIONS_PAGE ) ) {
		gwc_vt_add_offers_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_VERIFY_PAGE ) ) {
		gwc_vt_add_verify_help( $screen );
		return;
	}

	if ( false !== strpos( (string) $screen->id, GWC_VT_REPEAT_PAGE ) ) {
		gwc_vt_add_repeat_help( $screen );
		return;
	}

	/* The single volunteer's record, not the list. The list had help and this
	 * did not, which was the wrong way round: the list is names and totals, and
	 * this is where somebody records that a named person holds a background
	 * check and where their photograph lives. */
	if ( GWC_VT_VOLUNTEER_TYPE === $screen->id ) {
		gwc_vt_add_volunteer_record_help( $screen );
	}
}

/**
 * Defining what volunteers have to hold.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_credentials_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-credentials-what',
		__( 'What a credential is', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Something a volunteer has to <strong>hold</strong> before doing certain work — a training course, a signed waiver, a background check.', 'groundwork-common-volunteer-tracker' ),
			__( 'This is not the same as the hours a court or a school required of somebody. Those live on the volunteer’s own record and never appear here.', 'groundwork-common-volunteer-tracker' ),
			__( 'You define each one here. You record who holds it on each volunteer’s own record, which is also where you can see what somebody is missing.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-credentials-expiry',
		__( 'Renewing', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'An expiry date is worked out from the day the credential was granted, and is never stored. Change a credential from twelve months to twenty-four — <strong>Edit</strong>, on its row — and everybody who holds it is re-dated. There are no old dates left behind, and nothing is emailed. A shorter interval works the same way in the other direction: somebody can be lapsed the moment you save, so the form says how many people hold it before that field.', 'groundwork-common-volunteer-tracker' ),
			__( 'Somebody who did it on the 31st renews on the 31st, or on the last day of a month that is shorter.', 'groundwork-common-volunteer-tracker' ),
			__( 'Recording a renewal does not replace what came before it. Every grant is kept, so “renewed every year since 2019” is still there to read.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-credentials-missing',
		__( 'If somebody has not got it', 'groundwork-common-volunteer-tracker' ),
		array(
			__( '<strong>Reporting</strong> is the safer default and what most organizations want: whoever is short of it is flagged on the roster, and a person decides what to do.', 'groundwork-common-volunteer-tracker' ),
			__( '<strong>Stopping a signup</strong> is for the things nobody may work without. A staff member can still put somebody on, but only by giving a reason — which is recorded with their name and shown on the roster from then on.', 'groundwork-common-volunteer-tracker' ),
			__( 'Nobody is ever taken off a roster automatically. Somebody already accepted for a shift stays accepted, and the roster tells you rather than deciding for you.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-credentials-retiring',
		__( 'Retiring one', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Retiring a credential stops it being asked for. It does not take it away from the people who hold it, and it does not delete anything.', 'groundwork-common-volunteer-tracker' ),
			__( 'Every record of who held it is kept, and you can still see who they were. Put it back into use and every shift that asked for it asks again.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * Applications.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_offers_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-applications-what',
		__( 'What is waiting here', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'What somebody typed into the form on your site. Nothing here is a volunteer record yet, and nothing here counts toward anything.', 'groundwork-common-volunteer-tracker' ),
			__( 'That is deliberate: a public form cannot create a volunteer record, so spam and mistakes reach a queue somebody empties rather than your volunteer list.', 'groundwork-common-volunteer-tracker' ),
			__( 'Accepting one makes a volunteer record from what they wrote. Setting one aside keeps what they sent and takes it off this list.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-applications-waiting',
		__( 'Nobody is told they are waiting', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'The plugin sends nothing when an application arrives and nothing while it sits here. Somebody who applied and hears nothing for three weeks has usually concluded the answer was no.', 'groundwork-common-volunteer-tracker' ),
			__( 'The count beside this screen’s name in the menu, and the line on the dashboard, are how you find out one is waiting.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The verify queue.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_verify_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-verify-what',
		__( 'What verifying means', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'A member of your staff saying the work happened. The plugin records who did it and when, and both appear on any letter that includes those hours.', 'groundwork-common-volunteer-tracker' ),
			__( 'Only verified hours reach a letter. Everything waiting here counts toward nothing until somebody says it happened.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-verify-unmatched',
		__( 'Hours attached to nobody', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Anything sent through the public form arrives as a claim — a name and an address somebody typed, attached to no volunteer record.', 'groundwork-common-volunteer-tracker' ),
			__( 'They are held apart here until a person says whose they are. The form never looks anybody up, so what it recorded is what was typed and nothing more.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * Changing a whole repeat.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_repeat_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-repeat-what',
		__( 'Changing every occurrence', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Every occurrence of a repeat is a real shift you can edit or call off on its own. This screen changes the ones you choose, all at once.', 'groundwork-common-volunteer-tracker' ),
			__( 'It tells you how many it will touch and how many it will leave, before it does anything. Occurrences that have already happened, and ones that were called off, are left alone unless you say otherwise.', 'groundwork-common-volunteer-tracker' ),
			__( 'Changing what a shift asks people to hold <strong>replaces</strong> what each occurrence asks for rather than adding to it — so selecting that box with nothing chosen clears them across the repeat.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * One volunteer's own record.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_volunteer_record_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-record-what',
		__( 'What this record holds', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Who somebody is, how to reach them, what they have done, and what they hold. A volunteer is not a WordPress user: there is no account here and no password.', 'groundwork-common-volunteer-tracker' ),
			__( 'Hours and letters below are shown for reading, not editing. Hours are corrected on the entry itself, and a letter that has been issued is not editable at all.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-record-credentials',
		__( 'Recording a credential', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Every credential your organization asks for is listed, including the ones this person has never held — the box answers “what are they missing” as well as “what do they hold”.', 'groundwork-common-volunteer-tracker' ),
			__( 'Give the date they actually did it, not today’s date. Anything that expires counts from that day, so a class taken in March and entered in June expires in March.', 'groundwork-common-volunteer-tracker' ),
			__( 'It is saved when you press Update, along with the rest of the record.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-record-required',
		__( 'Hours somebody has to complete', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'How many hours a court or a school required, by when, and for whom. It is for your planning only and never appears on a letter.', 'groundwork-common-volunteer-tracker' ),
			__( 'It is also kept off every screen a volunteer can see. What somebody was ordered to do is a fact about another organization’s document.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The screen somebody lands on.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_dashboard_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-dashboard',
		__( 'What this screen shows', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Information on the left, action on the right. The fortnight ahead and the year’s one figure are what is true; the rail beside them — the verbs, what is waiting, and the reference checker — is what you might do about it. Nothing on it is a number for its own sake: each line in <strong>Needs you</strong> is something to do, and the count is what tells you whether to do it now.', 'groundwork-common-volunteer-tracker' ),
			__( 'The order is not by size. It runs by what is lost if it waits: hours from a shift that happened and was never typed up come first, because every week takes them further from anybody remembering them. Shifts still short of people come next, because on Sunday there is nothing to be done about Saturday. Verifying and matching sit at the bottom — both keep.', 'groundwork-common-volunteer-tracker' ),
			__( 'A queue with nothing in it does not appear at all. A screen that says “none waiting” five times over is one people stop reading, and then the line that matters gets skimmed with it.', 'groundwork-common-volunteer-tracker' ),
			__( 'Nobody is named here. Every line is a count and a link, and the names are on the screen the link goes to — which is somewhere you have gone deliberately.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-year',
		__( 'The year’s figure', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Verified hours only, for the same reason a letter states only those: nobody has attested to the rest. Hours still waiting are counted beside it rather than folded in.', 'groundwork-common-volunteer-tracker' ),
			__( 'It is the number your Form 990 and your grant reports want, and it is the only thing on this screen that leaves the building. Everything else is a prompt to do something.', 'groundwork-common-volunteer-tracker' ),
			__( 'The year runs from 1 January. If yours does not, a developer can set the start date with the <code>gwc_vt_reporting_year_start</code> filter — it is not a setting, because a wrong answer here quietly misstates a figure that goes to a funder.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	/* The checker is on this screen, so its tab is too. */
	if ( gwc_vt_letters_enabled() && current_user_can( GWC_VT_CAP_OPEN_LETTERS ) ) {
		gwc_vt_add_reference_help( $screen );
	}

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The schedule, and the single-shift view that shares its page.
 *
 * No check on whether shifts are switched on: the screen is only registered
 * when they are, so reaching this means they are.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_schedule_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-planning',
		__( 'Planning shifts', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'A shift here is a plan, not a record. Nobody accrues hours by being on a shift — hours are logged afterwards, by somebody who was there, and still have to be verified like any others.', 'groundwork-common-volunteer-tracker' ),
			__( 'A repeat creates real shifts, one per date, each of which you can edit or cancel on its own. Closing the Saturday after Thanksgiving does not disturb the rest of the season.', 'groundwork-common-volunteer-tracker' ),
			__( 'Times are the times you would write on a noticeboard. A nine o’clock shift is at nine o’clock all year, including the weekend the clocks change. For a shift that runs past midnight, select <strong>ends the next day</strong> — an end time before the start is otherwise treated as a typo and refused.', 'groundwork-common-volunteer-tracker' ),
			__( 'The minimum is what flags a shift on this screen as short of people. The maximum is not a limit on who may sign up: once it is reached, later signups go on a waiting list rather than being turned away, and you decide what to do with them.', 'groundwork-common-volunteer-tracker' ),
			__( 'Canceling and deleting are different things. A canceled shift stays on the schedule marked as canceled, so it is clear it was called off rather than never planned; deleting is only offered while nobody is signed up.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	/* Events share this screen, so they are tabs here rather than a registration
	 * of their own — gwc_vt_event_edit_url() and gwc_vt_event_roster_url() are both
	 * the schedule page with an argument. */
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-events',
		__( 'Events', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'An event is one occasion with several roles, each offered at several times — a festival, a meal service, a collection drive. Underneath it there is nothing new: every time on an event is an ordinary shift, so waiting lists, reminders, rosters and hours all behave exactly as they do for a shift you scheduled on its own.', 'groundwork-common-volunteer-tracker' ),
			__( 'You build the day as a grid. Name a role once and hang its times underneath it; the role’s supervisor, address and <strong>What to know</strong> are typed once and carried down to every time in it. Anything a single time names for itself wins over the role, and anything the role names wins over the event — most specific wins, and nothing is ever appended to anything else.', 'groundwork-common-volunteer-tracker' ),
			__( 'The date is not something you fill in. It is read from the times in the grid, because a second date field is a second answer and it disagrees with the first the moment somebody moves one time.', 'groundwork-common-volunteer-tracker' ),
			__( 'Calling off a time and dropping a role are buttons rather than something you do by clearing a field, and each asks first. Removing a time cancels it when people are on it and deletes it only when nobody is — the row tells you which before you save.', 'groundwork-common-volunteer-tracker' ),
			__( '<strong>Copy</strong> puts the whole day on a new date — roles, times, numbers and the credentials each one asks for — saved as a draft so you can check the dates before anybody sees it. Nobody is carried over.', 'groundwork-common-volunteer-tracker' ),
			__( 'An event can also run on a pattern: pick a date to run it again, then a rhythm and a date to stop. A monthly meal service is one event set to repeat rather than twelve typed in. Each run is a whole event of its own — its own roster, its own times, its own cancellation — so calling off October leaves November alone.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-event-page',
		__( 'Where volunteers see an event', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'An event has no web address of its own, and publishing one does not give it one. It is seen only where you put it: add the <strong>Volunteer Event</strong> block to a page or a post and pick the event, or paste the <code>[gwc_vt_event_grid]</code> shortcode with the event’s id into one. If you put the same event in more than one place, links back to it use whichever you published first.', 'groundwork-common-volunteer-tracker' ),
			__( 'The event editor tells you which page currently shows it, and says so plainly when no page does. That is the fastest way to check, because the answer is found by looking for the block rather than stored anywhere.', 'groundwork-common-volunteer-tracker' ),
			__( 'Three things have to be true before anybody can sign up: signing up from your site is switched on, a shifts page is pinned under Settings → Shifts, and the event is published. The pinned shifts page is needed even though the event sits somewhere of its own — every public signup goes through it.', 'groundwork-common-volunteer-tracker' ),
			__( 'An event’s times never appear on the general shifts page. That page lists shifts you scheduled on their own; an event is shown whole, on its own page, or not at all.', 'groundwork-common-volunteer-tracker' ),
			__( 'What a visitor sees is each role, each time, and how many places are left. Never who else is coming — the same rule as the shift list, and for the same reason.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-roster',
		__( 'Who is coming', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'You can put anybody on a shift yourself, which is how most offers of help at this size arrive — somebody rings up. They need a volunteer record first.', 'groundwork-common-volunteer-tracker' ),
			__( 'Taking somebody off records that they withdrew rather than removing them, so a glance at the shift shows that two people dropped instead of leaving you wondering. Whoever is next on the waiting list takes the free place right away.', 'groundwork-common-volunteer-tracker' ),
			__( '<strong>Print the roster</strong> gives you the sheet for the clipboard, with contact details and blank columns for in and out times, plus spare rows for people who turn up without having signed up.', 'groundwork-common-volunteer-tracker' ),
			__( 'If you let people sign up from your own site, what they see is what each shift is and how many places are left — never who else is coming. There is no setting that changes that, because a roster can say more about the people on it than they agreed to make public.', 'groundwork-common-volunteer-tracker' ),
			__( 'Somebody who signs up from your site is not added to your volunteer records. Their name and email are stored as claims until a member of staff matches them, exactly as with hours sent through the public form.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-logging-a-shift',
		__( 'Turning a shift into hours', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Once a shift has finished, <strong>Log the hours</strong> opens it with everybody who signed up already selected and the hours it was scheduled for filled in. Clear the checkbox for whoever did not turn up, change the hours for anybody who left early, add the people who walked in, and save once.', 'groundwork-common-volunteer-tracker' ),
			__( 'Hours cannot be logged before a shift has ended. Recorded early they would be dated the day you typed them rather than the day they were worked, and that date is what a letter prints.', 'groundwork-common-volunteer-tracker' ),
			__( 'Nothing at all is recorded for somebody who did not come. There is no absence marked anywhere — the shift simply produces no hours for them.', 'groundwork-common-volunteer-tracker' ),
			__( 'You can come back to a shift you have already logged to add somebody who was missed. Anybody who already has an entry is shown as logged and cannot be recorded twice.', 'groundwork-common-volunteer-tracker' ),
			__( 'An event works the same way, one time at a time. Open its roster and each time that has finished carries its own <strong>Log the hours</strong> link, with the people who signed up for that time already selected. The event’s row on the schedule says how many times are still waiting.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The hours list.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_hours_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-verifying',
		__( 'Verifying hours', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Click the <strong>Not yet verified</strong> badge to verify a shift. Your name and the date are recorded and appear on any letter that includes it, so verifying is you attesting, as a member of staff, that these hours were worked.', 'groundwork-common-volunteer-tracker' ),
			__( 'To do several at once, select them and choose Verify from the Bulk actions menu. To undo one, use <strong>Withdraw verification</strong> in the row’s hover menu — it is kept out of the way on purpose, so a quick click cannot undo somebody’s attestation by accident.', 'groundwork-common-volunteer-tracker' ),
			__( 'Only verified hours are counted on a letter. Unverified ones can be listed and marked as such if you turn that on, but they are never added to the total the letter states.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-matching',
		__( 'Hours sent in by volunteers', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Shifts sent through the public form arrive attached to nobody, shown in italics as <em>not yet matched</em>. The name and email on them are what somebody typed into a form — treat them as claims until you have checked.', 'groundwork-common-volunteer-tracker' ),
			__( 'Open one and it will offer to attach it to a volunteer whose email or name matches exactly, or to create a record from what was submitted. Choose <strong>Awaiting matching</strong> in the filter above to see only these.', 'groundwork-common-volunteer-tracker' ),
			__( 'Matching and verifying are separate on purpose. Attaching says whose hours these are; verifying says they happened. Doing the first does not do the second.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The volunteers list.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_volunteers_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-volunteers',
		__( 'Volunteer records', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'One record per person, which is what makes a letter possible — forty shifts typed as “Jane Doe”, “jane doe” and “J. Doe” cannot be added together.', 'groundwork-common-volunteer-tracker' ),
			__( 'Opening a volunteer shows every shift they have logged and every letter already issued for them, with a link to check any of those references against your current records.', 'groundwork-common-volunteer-tracker' ),
			__( 'An email address is optional, but a letter can only be emailed to somebody who has one on file. Without it the letter can still be printed.', 'groundwork-common-volunteer-tracker' ),
			__( 'Volunteers never sign in and never receive an account. Nothing here is visible to the public or through the site’s search.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The letters screen.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_letters_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-log',
		__( 'What this log is', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Every letter this organization has issued, and everywhere each one has been since. Printing, posting and emailing are all recorded against the letter, so the log answers “did you send it to us” and not only “a letter was produced”.', 'groundwork-common-volunteer-tracker' ),
			__( 'Rows reading <strong>record removed</strong> are letters produced for somebody whose volunteer record has since been erased or anonymized. They stay, deliberately: the log holds no name and no hours of its own, and it is the receipt of this organization’s own conduct. Losing it when a record goes would be losing it exactly when it starts to matter.', 'groundwork-common-volunteer-tracker' ),
			__( 'To produce a letter, start from the volunteer’s own record. To check a reference somebody has phoned in, use the panel on the Dashboard.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The produce-a-letter screen.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_produce_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-producing',
		__( 'Producing a letter', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Choose a volunteer and, if you need one, a date range. Leaving both dates empty covers everything on record, from their first shift to the day the letter is issued — the letter names both of those dates rather than claiming to cover all of their time.', 'groundwork-common-volunteer-tracker' ),
			__( 'Issuing a letter mints its reference and writes it into the log. Nothing has gone anywhere yet: printing, posting and emailing it are separate acts on the volunteer’s record, and each one is recorded against the letter with the date and, where there is one, who it went to.', 'groundwork-common-volunteer-tracker' ),
			__( 'The letter is built fresh from your records every time it is produced. It is never a stored copy, so it always states what you currently have on file.', 'groundwork-common-volunteer-tracker' ),
			__( 'The line above Preview says what the letter will state and whether anything of theirs is still waiting to be verified. Unverified hours are never on a letter, so a total that is about to change is worth knowing before you send one.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	gwc_vt_add_help_sidebar( $screen );
}

/**
 * The reference checker's tab, wherever it is being shown.
 *
 * Its own function because the checker moved to the dashboard and the tab went
 * with it — a help tab describing a panel that is not on the screen is worse
 * than no tab.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_reference_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-references',
		__( 'Checking a reference', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'Every letter carries a reference code. Somebody who has been sent one — a court, a school, an employer — can phone and read it out, and the panel on this screen will tell you whether the letter still matches your records.', 'groundwork-common-volunteer-tracker' ),
			__( 'The code covers every detail the letter prints, not just the total: the dates, the activities, the supervisors and each shift’s hours. Two shifts swapped so the total came out the same will still show as changed.', 'groundwork-common-volunteer-tracker' ),
			__( '<strong>Records have changed</strong> is not an accusation. Hours get corrected and shifts get verified after a letter goes out, and all of that is ordinary. The whole letter is shown as your records stand now so you can compare it against the copy somebody was sent.', 'groundwork-common-volunteer-tracker' ),
			__( 'What the code proves is that a document matches your records. It does not prove the hours were worked — that is what your staff attested to, and it is stated on the letter itself.', 'groundwork-common-volunteer-tracker' ),
		)
	);
}

/**
 * The log-a-day screen.
 *
 * @param WP_Screen $screen The screen.
 */
function gwc_vt_add_quick_add_help( $screen ): void {
	gwc_vt_add_help_tab(
		$screen,
		'gwc-vt-help-quick-add',
		__( 'Logging a day', 'groundwork-common-volunteer-tracker' ),
		array(
			__( 'For typing up a sign-in sheet. Whatever the shift had in common — the date, what people were doing, who supervised — goes in once at the top, and each volunteer and their hours go in the list below.', 'groundwork-common-volunteer-tracker' ),
			__( 'Leave any row empty to skip it. A row with only half of it filled in is reported rather than silently ignored, so nobody’s hours go missing quietly.', 'groundwork-common-volunteer-tracker' ),
			__( 'Everything logged here arrives unverified, the same as any other entry. Use the link in the confirmation to go and verify the lot.', 'groundwork-common-volunteer-tracker' ),
		)
	);

	/* The same screen behaves differently when it is opened from a shift, and
	 * somebody who has never opened it that way has no reason to know it can be.
	 * Only offered where it is true: on a site with shifts switched off, this
	 * screen only ever has the blank-day form on it. */
	if ( gwc_vt_shifts_enabled() ) {
		gwc_vt_add_help_tab(
			$screen,
			'gwc-vt-help-from-a-shift',
			__( 'Logging from a shift', 'groundwork-common-volunteer-tracker' ),
			array(
				__( 'Opened from a shift on the schedule, this screen fills itself in: the date, the activity and the supervisor come from the shift, and everybody who signed up is already listed and selected with the hours it was scheduled for.', 'groundwork-common-volunteer-tracker' ),
				__( 'That leaves you the parts that actually differ — clear the checkbox for whoever did not turn up, trim anybody who left early, and use the blank rows at the bottom for people who came without signing up.', 'groundwork-common-volunteer-tracker' ),
				__( 'Somebody who signed up through your site and has not been matched to a volunteer record yet is shown with a suggestion. Choosing who they are logs their hours and matches their signup at the same time.', 'groundwork-common-volunteer-tracker' ),
			)
		);
	}

	gwc_vt_add_help_sidebar( $screen );
}

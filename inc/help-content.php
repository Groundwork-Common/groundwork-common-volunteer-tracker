<?php
/**
 * The how-to guide, as data.
 *
 * ── What this is, and what the Help tabs are ─────────────────────────────────
 * Two different documents, and they are not duplicates of each other.
 *
 * The Help tab on each screen answers "what does this mean" — what verifying
 * IS, why a letter cannot be emailed, what happens to records. Conceptual, read
 * once, and it belongs beside the thing it explains.
 *
 * This answers "how do I". Numbered steps, in the order somebody performs them,
 * for the tasks a coordinator actually has. Somebody who has never used the
 * plugin needs this and cannot get it from thirteen conceptual tabs.
 *
 * ── House style ─────────────────────────────────────────────────────────────
 * Microsoft Writing Style Guide, which is not this repository's usual voice and
 * is deliberate here:
 *
 *   - Second person, present tense. "You select", not "the user selects".
 *   - **Select**, never "click" — the reader may be on a tablet.
 *   - Sentence case for headings. Bold for the words on screen.
 *   - One action per numbered step, starting with where to be.
 *   - No "please", no "simply", no "easy", no "just".
 *   - Task titles start with a verb: "Log a shift", not "Logging shifts".
 *
 * Data rather than markup so every string is translatable, the renderer stays
 * one loop, and tests/integration/help.php can assert the shape without
 * parsing HTML.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every how-to, grouped the way somebody's work is.
 *
 * A function rather than a const: a const is evaluated at include time, which
 * freezes every string in English for the request.
 *
 * @return array<int, array{id:string, title:string, intro:string, tasks:array}>
 */
function gwc_vt_help_topics(): array {
	return array(
		array(
			'id'    => 'start',
			'title' => __( 'Setting up', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'Do these three things once, before anybody relies on a letter. Each takes a minute, and the letter is wrong in a way nobody notices until a court reads it if you skip the first.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Set what appears on your letters', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Settings</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select the <strong>Letter</strong> tab.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter your organization’s name, address, and the phone number or email address a court or school should use to check a letter.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the name and title of the person who signs.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save changes</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Each of these falls back to something reasonable on its own — your site title, your administrator’s email address, an unlabeled line. Together those fallbacks produce a letter headed with a website’s title over a webmaster’s address.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Choose who can verify hours and who can issue letters', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Settings</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select the <strong>Permissions</strong> tab.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select the roles that may mark hours verified.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select the roles that may produce letters.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save changes</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'These are separate on purpose. The coordinator who knows the work happened and the director who signs a letter to a probation officer are often different people.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Decide how long to keep records', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Settings</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select the <strong>Privacy</strong> tab.', 'groundwork-common-volunteer-tracker' ),
						__( 'Choose how long a volunteer’s record is kept after their last activity.', 'groundwork-common-volunteer-tracker' ),
						__( 'Choose whether records are anonymized or deleted when that time passes.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save changes</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Anonymizing keeps the hours and removes the name, which is usually what an organization wants: the service record still counts toward your reporting and identifies nobody.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),

		array(
			'id'    => 'hours',
			'title' => __( 'Recording hours', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'Hours reach a letter only after somebody on your staff says the work happened. That is two steps, and they are deliberately separate.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Add a volunteer', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Volunteers</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Add volunteer</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter their name, and their email address if you have one.', 'groundwork-common-volunteer-tracker' ),
						__( 'If a court or school required a number of hours, enter how many, by when, and who required them.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Publish</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'A volunteer is not a WordPress user. No account is created, no password is set, and they never log in.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Log one shift', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Hours</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Log one shift</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Start typing the volunteer’s name and select them from the list.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the date, how long they worked, and what they did.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Publish</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Enter the duration however you say it: 3.5, 3:30, 3h 30m, or 210m all work. The figure stored is rounded to the increment you chose in Settings — always to the nearest, never up — and the screen tells you when it has rounded.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Log a whole day from a sign-in sheet', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Hours</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Log a day</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the date, the times, and what the work was. These are used for every row.', 'groundwork-common-volunteer-tracker' ),
						__( 'Add a row for each person on the sheet.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Log these hours</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
				),
				array(
					'title' => __( 'Verify hours', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Hours</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Waiting to verify</strong>, above the list.', 'groundwork-common-volunteer-tracker' ),
						__( 'Read the shifts waiting, which are grouped by the person they are about.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Verify</strong> beside each one you can confirm happened.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Your name and the date are recorded, and both appear on any letter that includes those hours. Only verified hours reach a letter.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Match hours somebody sent in themselves', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Hours</strong>, then <strong>Waiting to verify</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find the group headed with a name that is not yet a volunteer record.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Match to a volunteer</strong>, then choose the right person.', 'groundwork-common-volunteer-tracker' ),
						__( 'If they are not on file yet, select <strong>Create a volunteer from this</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Verify the hours as usual.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Anything sent through the public form arrives attached to nobody. What somebody typed is a claim until a person says whose it is.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),

		array(
			'id'    => 'letters',
			'title' => __( 'Producing a letter', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'The letter reports what your organization recorded. It carries a reference code so that anybody who receives it can phone you and check it.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Produce a verification letter', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Volunteers</strong> and open the person’s record.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Produce a letter for this volunteer</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Choose the dates to cover, or leave them empty for everything.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Print</strong> to open it for printing, or <strong>Email</strong> to send it to the volunteer.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'To save a PDF, choose your browser’s Print command and then Save as PDF. The plugin does not bundle a PDF library.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Check a reference code somebody phoned about', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Dashboard</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find the <strong>Check a reference</strong> panel.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the code the caller reads to you.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Check it</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'You are told whether the code matches a letter you issued and whether the figures on it still match your records. You are not told anything the caller is not already holding.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),

		array(
			'id'    => 'schedule',
			'title' => __( 'Planning shifts', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'A scheduled shift is a plan. Nobody accrues hours by signing up — turning a finished shift into hours is something a person does afterwards.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Schedule a shift', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Schedule</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Add a shift</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the date, the start and end times, and what the work is.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter how many people you need and how many you have room for.', 'groundwork-common-volunteer-tracker' ),
						__( 'To repeat it, choose a pattern and a date to repeat until.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Every occurrence of a repeat is a real shift you can edit or call off on its own.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Change every occurrence of a repeat', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Open any occurrence from the schedule.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Change the whole repeat</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select each thing you want to change, and enter the new value.', 'groundwork-common-volunteer-tracker' ),
						__( 'Read how many occurrences will change and how many will be left.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Change them</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Occurrences that have already happened, and ones you called off, are left alone unless you say otherwise.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Put somebody on a shift', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Open the shift from the schedule.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find <strong>Who is coming</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Start typing a name and select the volunteer.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Add to the shift</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'If the shift is full, the person goes on a waiting list and moves up automatically when somebody withdraws.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Turn a finished shift into hours', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Open the shift after it has happened.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Log the hours</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Clear the checkbox beside anybody who did not turn up.', 'groundwork-common-volunteer-tracker' ),
						__( 'Add a row for anybody who came without signing up.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Log these hours</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Everybody who signed up is already selected, with the scheduled times filled in. The hours still have to be verified, like any others.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),

		array(
			'id'    => 'credentials',
			'title' => __( 'Tracking credentials', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'A credential is something a volunteer has to hold — a training course, a signed waiver, a background check. It is not the same as hours a court required, which live on the volunteer’s own record.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Define a credential', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Credentials</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter what it is called, as you would say it out loud.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter how many months it lasts, or 0 if it never expires.', 'groundwork-common-volunteer-tracker' ),
						__( 'Choose what happens when somebody has not got it: report it, or stop them signing up.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Add it</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Reporting is the safer choice and what most organizations want. Stopping a signup is for the things nobody may work without.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Record that somebody holds one', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Volunteers</strong> and open the person’s record.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find <strong>Credentials</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Choose the credential from the list.', 'groundwork-common-volunteer-tracker' ),
						__( 'Enter the date they actually did it, which may not be today.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Update</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Anything that expires counts from the date you enter, so a class taken in March and recorded in June expires in March.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Ask for a credential on a shift', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Open the shift from the schedule.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find <strong>They have to hold</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select each credential this shift needs.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'For an event, set it on the event instead and every role on the day asks for it, in addition to anything a role asks for itself.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'See who holds a credential', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Credentials</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Find the credential in the list.', 'groundwork-common-volunteer-tracker' ),
						__( 'In <strong>Who holds it</strong>, select the number of people who hold it, or the number whose has lapsed.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'The volunteer list opens, showing only those people. You can also filter that list yourself with the two dropdowns above it.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),

		array(
			'id'    => 'public',
			'title' => __( 'Taking signups and applications from your site', 'groundwork-common-volunteer-tracker' ),
			'intro' => __( 'Three public forms, each switched off until you switch it on. None of them creates a volunteer record on its own.', 'groundwork-common-volunteer-tracker' ),
			'tasks' => array(
				array(
					'title' => __( 'Let people sign up for shifts', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Create or choose the page you want the list to appear on.', 'groundwork-common-volunteer-tracker' ),
						__( 'Add the <strong>Volunteer Shifts</strong> block to it, and publish the page.', 'groundwork-common-volunteer-tracker' ),
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Settings</strong> and select the <strong>Shifts</strong> tab.', 'groundwork-common-volunteer-tracker' ),
						__( 'Turn on signups, and choose the page you added the block to.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Save changes</strong>.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Visitors see what each shift is and how many places are left. They never see who is coming.', 'groundwork-common-volunteer-tracker' ),
				),
				array(
					'title' => __( 'Answer somebody who applied to volunteer', 'groundwork-common-volunteer-tracker' ),
					'steps' => array(
						__( 'Go to <strong>Volunteer Tracker</strong> &rsaquo; <strong>Applications</strong>.', 'groundwork-common-volunteer-tracker' ),
						__( 'Read what they sent.', 'groundwork-common-volunteer-tracker' ),
						__( 'Select <strong>Accept</strong> to make a volunteer record from it, or <strong>Set aside</strong> to take it off the list.', 'groundwork-common-volunteer-tracker' ),
					),
					'note'  => __( 'Nothing is sent to them when an application arrives or while it waits. The count beside the menu item, and the line on your dashboard, are how you find out one is here.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		),
	);
}

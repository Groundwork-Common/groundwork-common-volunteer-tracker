<?php
/**
 * The settings tabs and the one handler that saves them.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gwc_vt_render_tab_letter', 'gwc_vt_render_settings_tab' );
add_action( 'gwc_vt_render_tab_logging', 'gwc_vt_render_settings_tab' );
add_action( 'gwc_vt_render_tab_shifts', 'gwc_vt_render_settings_tab' );
add_action( 'gwc_vt_render_tab_privacy', 'gwc_vt_render_settings_tab' );
add_action( 'gwc_vt_render_tab_privacy', 'gwc_vt_render_retention_log', 20 );
add_action( 'gwc_vt_render_tab_privacy', 'gwc_vt_render_uninstall_section', 30 );
add_action( 'admin_post_gwc_vt_save_uninstall', 'gwc_vt_handle_save_uninstall' );

add_action( 'gwc_vt_render_tab_permissions', 'gwc_vt_render_permissions_tab' );
add_action( 'admin_post_gwc_vt_save_permissions', 'gwc_vt_handle_save_permissions' );
add_filter( 'gwc_vt_admin_tabs', 'gwc_vt_add_permissions_tab' );
add_action( 'admin_post_gwc_vt_save_settings', 'gwc_vt_handle_save_settings' );

/* ── One registry, three jobs ────────────────────────────────────────────────
 * Every setting is described once here, and that description drives the form,
 * the sanitizer and the help text together. The alternative — a render function
 * and a save function that each know the field list — is two lists that agree
 * until somebody adds a field to one of them, and the failure mode is a control
 * that appears on screen and silently never saves.
 *
 * The 'type' decides both how a field renders and how it is sanitized, which is
 * what stops a select ever storing a value that is not one of its options.
 *
 * Not the Settings API, for the reason the sibling plugins give: these screens
 * are dynamic enough that register_setting()'s conventions cost more than they
 * save, and an explicit admin_post_ handler with an explicit capability check
 * and an explicit nonce is easier to read than the three callbacks it replaces.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every settable setting, with the tab and section it belongs to.
 *
 * @return array<string, array>
 */
function gwc_vt_settings_fields(): array {
	$fields = array(

		/* ── Letter: letterhead ──────────────────────────────────────────── */

		'org_name'                  => array(
			'tab'         => 'letter',
			'section'     => 'letterhead',
			'type'        => 'text',
			'label'       => __( 'Organization name', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => (string) get_bloginfo( 'name' ),
			'help'        => __( 'Leave empty to use the site title.', 'groundwork-common-volunteer-tracker' ),
		),
		'org_address'               => array(
			'tab'     => 'letter',
			'section' => 'letterhead',
			'type'    => 'textarea',
			'rows'    => 3,
			'label'   => __( 'Address', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Printed under the name. One line per line.', 'groundwork-common-volunteer-tracker' ),
		),
		'org_logo'                  => array(
			'tab'     => 'letter',
			'section' => 'letterhead',
			'type'    => 'image',
			'label'   => __( 'Logo', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Printed above the organization name. Kept small on purpose — this is correspondence, not a flyer. Email programs often refuse to load images, so the name is always printed as text underneath and the letter reads correctly without it.', 'groundwork-common-volunteer-tracker' ),
		),
		'org_contact'               => array(
			'tab'         => 'letter',
			'section'     => 'letterhead',
			'type'        => 'text',
			'label'       => __( 'Contact for questions', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => (string) get_option( 'admin_email' ),
			'help'        => __( 'Where a court or school should direct questions about a letter. This is the number somebody phones to check a reference code, so make it one that is answered.', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Letter: signature ───────────────────────────────────────────── */

		'signatory_name'            => array(
			'tab'     => 'letter',
			'section' => 'signature',
			'type'    => 'text',
			'label'   => __( 'Signed by', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Printed under the signature line. Left empty, the line prints unlabeled — which looks unfinished, and is meant to.', 'groundwork-common-volunteer-tracker' ),
		),
		'signatory_title'           => array(
			'tab'     => 'letter',
			'section' => 'signature',
			'type'    => 'text',
			'label'   => __( 'Their title', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Printed under the name — "Volunteer Coordinator", "Executive Director". It tells a reader what standing the person signing has to say what the letter says.', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Letter: wording ─────────────────────────────────────────────── */

		'letter_intro'              => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 4,
			'label'       => __( 'Opening paragraph', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwc_vt_letter_intro( array() ),
			'help'        => __( 'Leave empty to use the wording shown. Plain text only — bold and italic survive, other markup does not.', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),
		'letter_disclaimer'         => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 5,
			'label'       => __( 'Disclaimer', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwc_vt_default_disclaimer(),
			'help'        => __( 'Your counsel may want particular wording, so this is yours to change. It cannot be emptied: saving it blank restores the default. A letter with no disclaimer is a letter that has quietly started implying this plugin certified something.', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),
		'letter_reference_note'     => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 3,
			'label'       => __( 'What the reference code proves', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwc_vt_default_reference_note(),
			'help'        => __( 'Also cannot be emptied. Without it, a reader reasonably assumes a code means some outside body issued the document.', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),

		/* ── Letter: contents ────────────────────────────────────────────── */

		'letter_itemize'            => array(
			'tab'     => 'letter',
			'section' => 'contents',
			'type'    => 'checkbox',
			'label'   => __( 'List every shift', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'On by default. A total with nothing behind it is a number the reader has to take on faith, which is what this document exists to avoid.', 'groundwork-common-volunteer-tracker' ),
		),
		'letter_include_unverified' => array(
			'tab'     => 'letter',
			'section' => 'contents',
			'type'    => 'checkbox',
			'label'   => __( 'Also list hours nobody has verified', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Off by default. When on, unverified shifts appear marked as such and are still excluded from the verified total — they are never quietly added in.', 'groundwork-common-volunteer-tracker' ),
		),
		'reference_prefix'          => array(
			'tab'     => 'letter',
			'section' => 'contents',
			'type'    => 'text',
			'label'   => __( 'Reference prefix', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Prepended to every reference code. Letters and numbers only — the code gets read aloud over the phone and typed back in.', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Letter: email ───────────────────────────────────────────────── */

		'email_subject'             => array(
			'tab'         => 'letter',
			'section'     => 'email',
			'type'        => 'text',
			'label'       => __( 'Subject line', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => __( 'Your volunteer service verification from {org}', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),
		'email_intro'               => array(
			'tab'     => 'letter',
			'section' => 'email',
			'type'    => 'textarea',
			'rows'    => 3,
			'label'   => __( 'Covering note', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Appears above the letter in the email, outside it. The letter itself is identical whether printed or emailed, and this is how a short “here is your letter” message gets added without changing the document.', 'groundwork-common-volunteer-tracker' ),
			'tokens'  => true,
		),
		'from_name'                 => array(
			'tab'     => 'letter',
			'section' => 'email',
			'type'    => 'text',
			'label'   => __( 'From name', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Leave both empty to use whatever WordPress would have used.', 'groundwork-common-volunteer-tracker' ),
		),
		'from_email'                => array(
			'tab'     => 'letter',
			'section' => 'email',
			'type'    => 'email',
			'label'   => __( 'From address', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Use an address at your own domain. A letter sent from an address somewhere else is the thing a spam filter is most likely to hold back, and the volunteer never learns it was sent.', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Logging ─────────────────────────────────────────────────────── */

		/* On the Logging tab and not on the Letter tab, which is where symmetry
		 * with shifts_enabled would have put it. Turning it off hides the Letter
		 * tab, so a switch living there would be a switch that hides the only
		 * screen it can be reached from. This tab is always present. */
		'letters_enabled'           => array(
			'tab'     => 'logging',
			'section' => 'hours',
			'type'    => 'checkbox',
			'label'   => __( 'Issue verification letters', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'On unless you clear it. Clear it if your organization records hours but never writes to a court or a school: the Letters screen and the Letter settings tab disappear, and nobody can produce or send a letter. Nothing is deleted — every letter already issued, the log of what went out, and every letter setting stay exactly where they are, and selecting this again finds them unchanged.', 'groundwork-common-volunteer-tracker' ),
		),

		'hour_increment'            => array(
			'tab'     => 'logging',
			'section' => 'hours',
			'type'    => 'select',
			'label'   => __( 'Round hours to', 'groundwork-common-volunteer-tracker' ),
			'options' => array(
				'0'  => __( 'Do not round', 'groundwork-common-volunteer-tracker' ),
				'5'  => __( '5 minutes', 'groundwork-common-volunteer-tracker' ),
				'10' => __( '10 minutes', 'groundwork-common-volunteer-tracker' ),
				'15' => __( '15 minutes', 'groundwork-common-volunteer-tracker' ),
				'30' => __( '30 minutes', 'groundwork-common-volunteer-tracker' ),
				'60' => __( 'A whole hour', 'groundwork-common-volunteer-tracker' ),
			),
			'help'    => __( 'Always to the nearest, never up — rounding up is the organization crediting hours nobody worked.', 'groundwork-common-volunteer-tracker' ),
		),
		'hour_format'               => array(
			'tab'     => 'logging',
			'section' => 'hours',
			'type'    => 'select',
			'label'   => __( 'Show hours as', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwc_vt_hour_format_labels',
			'help'    => __( 'Decimal is what a court order is usually written in.', 'groundwork-common-volunteer-tracker' ),
		),
		'allow_future_dates'        => array(
			'tab'     => 'logging',
			'section' => 'hours',
			'type'    => 'checkbox',
			'label'   => __( 'Allow shifts dated in the future', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Off by default. A shift dated next Tuesday is a typo far more often than a plan, and on a document a court reads it discredits the whole record.', 'groundwork-common-volunteer-tracker' ),
		),
		'self_log_enabled'          => array(
			'tab'     => 'logging',
			'section' => 'selflog',
			'type'    => 'checkbox',
			'label'   => __( 'Let volunteers send in their own hours', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Off until you switch it on. With it on, this site accepts a name, an email address and a date from anonymous visitors — everything they send arrives unverified and waits for a staff member. Nothing they enter appears publicly.', 'groundwork-common-volunteer-tracker' ),
		),
		'self_log_page'             => array(
			'tab'     => 'logging',
			'section' => 'selflog',
			'type'    => 'page',
			'label'   => __( 'The page the form is on', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Add the Volunteer Hours Form block, or the [gwc_vt_hours_form] shortcode, to a page and choose it here. Submissions are only accepted on this page, and it is pinned by ID so renaming it changes nothing.', 'groundwork-common-volunteer-tracker' ),
		),
		'self_log_code'             => array(
			'tab'     => 'logging',
			'section' => 'selflog',
			'type'    => 'text',
			'label'   => __( 'Require a code', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Optional. A word you give people at the front desk, which the form then asks for. Not a security control — it is the difference between a form the whole internet can post to and one only people who have been handed a card will bother with.', 'groundwork-common-volunteer-tracker' ),
		),
		'retention_months'          => array(
			'tab'     => 'privacy',
			'section' => 'retention',
			'type'    => 'select',
			'label'   => __( 'Keep volunteer records for', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwc_vt_retention_period_options',
			'help'    => __( '"Keep indefinitely" is a legitimate answer, and it is the default — a plugin that deleted records on a schedule it chose would eventually destroy the six weeks of Saturdays somebody needs for a court date. What is not legitimate is never deciding, which is why this tab nags until you save it.', 'groundwork-common-volunteer-tracker' ),
		),
		'retention_action'          => array(
			'tab'     => 'privacy',
			'section' => 'retention',
			'type'    => 'select',
			'label'   => __( 'When a record is old enough', 'groundwork-common-volunteer-tracker' ),
			'options' => array(
				'anonymize' => __( 'Remove the name and contact details, keep the hours', 'groundwork-common-volunteer-tracker' ),
				'delete'    => __( 'Delete the record and its shifts entirely', 'groundwork-common-volunteer-tracker' ),
			),
			'help'    => __( 'Anonymizing is usually right: your grant reporting and your Form 990 need the hours, and the hours identify nobody once the name is gone.', 'groundwork-common-volunteer-tracker' ),
		),
		'retention_anchor'          => array(
			'tab'     => 'privacy',
			'section' => 'retention',
			'type'    => 'select',
			'label'   => __( 'Measured from', 'groundwork-common-volunteer-tracker' ),
			'options' => array(
				'last_entry'  => __( 'Their last recorded shift', 'groundwork-common-volunteer-tracker' ),
				'verified_at' => __( 'The last time a shift of theirs was verified', 'groundwork-common-volunteer-tracker' ),
			),
			'help'    => __( 'The clock runs on the whole person, not on each shift — a record is removed when the person has been inactive this long, and their old shifts go with them. Measuring from the last shift is what most policies mean. Measuring from the last verification is later, sometimes much later, because a shift written up in March and attested in June counts from June.', 'groundwork-common-volunteer-tracker' ),
		),
		'activities'                => array(
			'tab'     => 'logging',
			'section' => 'hours',
			'type'    => 'textarea',
			'rows'    => 5,
			'label'   => __( 'Activity suggestions', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'One per line. Offered as suggestions when logging a shift; staff can still type anything. Leave empty for free text.', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Shifts ──────────────────────────────────────────────────────── */

		'shifts_enabled'            => array(
			'tab'     => 'shifts',
			'section' => 'schedule',
			'type'    => 'checkbox',
			'label'   => __( 'Plan shifts ahead of time', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Off until you switch it on. With it on, a Schedule screen appears where you can plan shifts, repeat them, put volunteers on them and print a roster. A scheduled shift is a plan and records nothing about anybody\'s hours — hours are still logged after the fact and still have to be verified.', 'groundwork-common-volunteer-tracker' ),
		),
		'shift_locations'           => array(
			'tab'     => 'shifts',
			'section' => 'schedule',
			'type'    => 'textarea',
			'rows'    => 5,
			'label'   => __( 'Location suggestions', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'One per line. Offered as suggestions when planning a shift; you can still type anything. Leave empty for free text.', 'groundwork-common-volunteer-tracker' ),
		),
		'signup_enabled'            => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'checkbox',
			'label'   => __( 'Let people sign up for shifts themselves', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Off until you switch it on. With it on, this site accepts a name and an email address from anonymous visitors. The public list shows what each shift is and how many places are left — never who else is coming. Nobody who signs up is added to your volunteer records automatically; a staff member matches them, exactly as with the hours form.', 'groundwork-common-volunteer-tracker' ),
		),
		'schedule_page'             => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'page',
			'label'   => __( 'The page the shifts are on', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Add the Volunteer Shifts block, or the [gwc_vt_shift_list] shortcode, to a page and choose it here. Signups are only accepted on this page, and it is pinned by ID so renaming it changes nothing.', 'groundwork-common-volunteer-tracker' ),
		),
		'signup_code'               => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'text',
			'label'   => __( 'Require a code', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'Optional. A word you give people, which the form then asks for. Not a security control — it is the difference between a form the whole internet can post to and one only people who have been told about it will bother with.', 'groundwork-common-volunteer-tracker' ),
		),
		'signup_horizon_days'       => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'select',
			'label'   => __( 'Show shifts up to', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwc_vt_signup_horizon_options',
			'help'    => __( 'How far ahead the public list looks. A year of Saturdays is a wall of text nobody reads to the bottom of.', 'groundwork-common-volunteer-tracker' ),
		),
		'signup_cutoff_hours'       => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'select',
			'label'   => __( 'Close signups', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwc_vt_signup_cutoff_options',
			'help'    => __( 'Right up to the start is fine for a warehouse where an extra pair of hands is always welcome. Choose earlier if you have to print a list the night before.', 'groundwork-common-volunteer-tracker' ),
		),
		'reminder_enabled'          => array(
			'tab'     => 'shifts',
			'section' => 'notices',
			'type'    => 'checkbox',
			'label'   => __( 'Remind people before their shift', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'One message per person per shift, sent once. Whoever is on the roster gets it; people on the waiting list do not, because they do not have a place yet.', 'groundwork-common-volunteer-tracker' ),
		),
		'reminder_lead_hours'       => array(
			'tab'     => 'shifts',
			'section' => 'notices',
			'type'    => 'select',
			'label'   => __( 'Send the reminder', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwc_vt_reminder_lead_options',
			'help'    => __( 'Two days is usually right: long enough that somebody who cannot make it still has time to say so, and you still have time to call somebody else.', 'groundwork-common-volunteer-tracker' ),
		),
		'digest_enabled'            => array(
			'tab'     => 'shifts',
			'section' => 'notices',
			'type'    => 'checkbox',
			'label'   => __( 'Send yourself a daily summary', 'groundwork-common-volunteer-tracker' ),
			'help'    => __( 'One message a day listing shifts in the next week that are short of people, and shifts that have happened without their hours being logged. Nothing is sent on a day when there is nothing to say.', 'groundwork-common-volunteer-tracker' ),
		),
		'digest_recipient'          => array(
			'tab'         => 'shifts',
			'section'     => 'notices',
			'type'        => 'email',
			'label'       => __( 'Send the summary to', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => (string) get_option( 'admin_email' ),
			'help'        => __( 'Leave empty to use the site’s admin address.', 'groundwork-common-volunteer-tracker' ),
		),
	);

	/**
	 * The settable settings, keyed by setting name.
	 *
	 * @param array $fields Field definitions.
	 */
	return (array) apply_filters( 'gwc_vt_settings_fields', $fields );
}

/**
 * The sections within each tab, in the order they render.
 *
 * @return array<string, array<string, string>>
 */
function gwc_vt_settings_sections(): array {
	return array(
		'letter'  => array(
			'letterhead' => __( 'Letterhead', 'groundwork-common-volunteer-tracker' ),
			'signature'  => __( 'Signature', 'groundwork-common-volunteer-tracker' ),
			'wording'    => __( 'Wording', 'groundwork-common-volunteer-tracker' ),
			'contents'   => __( 'What the letter includes', 'groundwork-common-volunteer-tracker' ),
			'email'      => __( 'Emailing it', 'groundwork-common-volunteer-tracker' ),
		),
		'logging' => array(
			'hours'   => __( 'Recording hours', 'groundwork-common-volunteer-tracker' ),
			'selflog' => __( 'The public form', 'groundwork-common-volunteer-tracker' ),
		),
		'shifts'  => array(
			'schedule' => __( 'Planning ahead', 'groundwork-common-volunteer-tracker' ),
			'signup'   => __( 'Signing up from your site', 'groundwork-common-volunteer-tracker' ),
			'notices'  => __( 'Reminders and summaries', 'groundwork-common-volunteer-tracker' ),
		),
		'privacy' => array(
			'retention' => __( 'Retention', 'groundwork-common-volunteer-tracker' ),
		),
	);
}

/**
 * How long records may be kept for.
 *
 * Written out rather than computed from the month figures, which is how this
 * first shipped and which was wrong twice over. round( 18 / 12 ) is 2, so
 * eighteen months and twenty-four months both rendered as "2 years" — two
 * options a person cannot tell apart, storing different values, with the
 * dropdown selecting the first match when the screen was reopened. And the
 * label read "2 years after that", where "that" referred to nothing at all.
 *
 * The keys are months because the arithmetic in inc/privacy.php is in calendar
 * months; the labels are what somebody setting a retention policy would say.
 * They read as the completion of the field's own label, "Keep volunteer records
 * for" — the anchor they are measured from is the separate setting below.
 *
 * @return array<string, string>
 */
function gwc_vt_retention_period_options(): array {
	return array(
		'0'  => __( 'Keep indefinitely', 'groundwork-common-volunteer-tracker' ),
		'12' => __( '1 year', 'groundwork-common-volunteer-tracker' ),
		'18' => __( '18 months', 'groundwork-common-volunteer-tracker' ),
		'24' => __( '2 years', 'groundwork-common-volunteer-tracker' ),
		'36' => __( '3 years', 'groundwork-common-volunteer-tracker' ),
		'60' => __( '5 years', 'groundwork-common-volunteer-tracker' ),
		'84' => __( '7 years', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * How far ahead the public shift list may look.
 *
 * @return array<string, string>
 */
function gwc_vt_signup_horizon_options(): array {
	return array(
		'14'  => __( '2 weeks ahead', 'groundwork-common-volunteer-tracker' ),
		'30'  => __( 'A month ahead', 'groundwork-common-volunteer-tracker' ),
		'60'  => __( '2 months ahead', 'groundwork-common-volunteer-tracker' ),
		'90'  => __( '3 months ahead', 'groundwork-common-volunteer-tracker' ),
		'180' => __( '6 months ahead', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * How long before a shift starts signups close.
 *
 * @return array<string, string>
 */
function gwc_vt_signup_cutoff_options(): array {
	return array(
		'0'  => __( 'Right up to the start', 'groundwork-common-volunteer-tracker' ),
		'2'  => __( '2 hours before', 'groundwork-common-volunteer-tracker' ),
		'12' => __( '12 hours before', 'groundwork-common-volunteer-tracker' ),
		'24' => __( 'The day before', 'groundwork-common-volunteer-tracker' ),
		'48' => __( 'Two days before', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * How long before a shift its reminder goes out.
 *
 * @return array<string, string>
 */
function gwc_vt_reminder_lead_options(): array {
	return array(
		'12' => __( 'The evening before', 'groundwork-common-volunteer-tracker' ),
		'24' => __( 'A day before', 'groundwork-common-volunteer-tracker' ),
		'48' => __( 'Two days before', 'groundwork-common-volunteer-tracker' ),
		'72' => __( 'Three days before', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * Render the settings form for the current tab.
 */
function gwc_vt_render_settings_tab(): void {
	$tab      = gwc_vt_current_tab();
	$sections = gwc_vt_settings_sections()[ $tab ] ?? array();
	$fields   = array_filter(
		gwc_vt_settings_fields(),
		static fn( array $field ): bool => ( $field['tab'] ?? '' ) === $tab
	);

	if ( ! $fields ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwc_vt_save_settings" />
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
		<?php wp_nonce_field( 'gwc_vt_save_settings' ); ?>

		<?php foreach ( $sections as $section => $section_label ) : ?>
			<?php
			$in_section = array_filter(
				$fields,
				static fn( array $field ): bool => ( $field['section'] ?? '' ) === $section
			);

			if ( ! $in_section ) {
				continue;
			}
			?>
			<h2><?php echo esc_html( $section_label ); ?></h2>

			<?php if ( 'wording' === $section ) : ?>
				<p class="description gwcvt-token-help">
					<?php
					printf(
						/* translators: %s: a list of placeholder tokens. */
						esc_html__( 'These accept placeholders: %s', 'groundwork-common-volunteer-tracker' ),
						'<code>' . implode( '</code> <code>', array_map( 'esc_html', gwc_vt_token_names() ) ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $in_section as $key => $field ) : ?>
						<?php gwc_vt_render_settings_field( $key, $field ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * The tokens a letter's wording can use.
 *
 * Built from a real letter model so the list on screen cannot drift from the
 * list the renderer actually substitutes.
 *
 * @return string[]
 */
function gwc_vt_token_names(): array {
	$sample = new GWC_VT_Letter( 0, '', '', '', array(), 0, 0, false, '', time() );

	return array_keys( gwc_vt_letter_tokens( $sample ) );
}

/**
 * One row of the settings form.
 *
 * @param string $key   Setting name.
 * @param array  $field Field definition.
 */
function gwc_vt_render_settings_field( string $key, array $field ): void {
	$type  = (string) ( $field['type'] ?? 'text' );
	$value = gwc_vt_setting( $key );
	$id    = 'gwcvt-' . str_replace( '_', '-', $key );
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) ( $field['label'] ?? $key ) ); ?></label>
		</th>
		<td>
			<?php
			switch ( $type ) {
				case 'checkbox':
					printf(
						'<label><input type="checkbox" id="%1$s" name="gwc_vt[%2$s]" value="1" %3$s /> %4$s</label>',
						esc_attr( $id ),
						esc_attr( $key ),
						checked( (bool) $value, true, false ),
						esc_html( (string) ( $field['label'] ?? '' ) )
					);
					break;

				case 'textarea':
					printf(
						'<textarea id="%1$s" name="gwc_vt[%2$s]" rows="%3$d" class="large-text" placeholder="%4$s">%5$s</textarea>',
						esc_attr( $id ),
						esc_attr( $key ),
						(int) ( $field['rows'] ?? 3 ),
						esc_attr( (string) ( $field['placeholder'] ?? '' ) ),
						esc_textarea( (string) $value )
					);
					break;

				case 'image':
					$attachment = (int) $value;
					$preview    = $attachment > 0 ? wp_get_attachment_image_url( $attachment, 'medium' ) : '';
					?>
					<div class="gwcvt-media" data-gwcvt-media>
						<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="gwc_vt[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $attachment ); ?>" />
						<div class="gwcvt-media__preview" <?php echo '' === $preview ? 'hidden' : ''; ?>>
							<img src="<?php echo esc_url( $preview ); ?>" alt="" />
						</div>
						<button type="button" class="button gwcvt-media__choose">
							<?php echo esc_html( '' !== $preview ? __( 'Change logo', 'groundwork-common-volunteer-tracker' ) : __( 'Choose a logo', 'groundwork-common-volunteer-tracker' ) ); ?>
						</button>
						<button type="button" class="button-link gwcvt-media__remove" <?php echo '' === $preview ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Remove', 'groundwork-common-volunteer-tracker' ); ?>
						</button>
					</div>
					<?php
					break;

				case 'page':
					/* `name` and `id` are escaped by core: wp_dropdown_pages() builds
					 * its <select> with esc_attr() around both. The sniff flags every
					 * argument to a function that echoes and cannot see that, so those
					 * two findings are not defects.
					 *
					 * `show_option_none` is NOT escaped by core — it is concatenated
					 * into the <option> raw, unlike option_none_value beside it. An
					 * earlier version of this annotation claimed core escaped all
					 * three, which was checked and found to be untrue, so it is
					 * escaped here instead of being covered by the same excuse. The
					 * string is our own and harmless today; the point is that the
					 * reason written down has to be the reason that is true. */
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					wp_dropdown_pages(
						array(
							'name'              => 'gwc_vt[' . $key . ']',
							'id'                => $id,
							'selected'          => (int) $value,
							'show_option_none'  => esc_html__( '— not set —', 'groundwork-common-volunteer-tracker' ),
							'option_none_value' => '0',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					break;

				case 'select':
					$options = $field['options'] ?? array();
					$options = is_callable( $options ) ? call_user_func( $options ) : (array) $options;

					printf( '<select id="%1$s" name="gwc_vt[%2$s]">', esc_attr( $id ), esc_attr( $key ) );

					foreach ( $options as $option_value => $option_label ) {
						printf(
							'<option value="%1$s" %2$s>%3$s</option>',
							esc_attr( (string) $option_value ),
							selected( (string) $value, (string) $option_value, false ),
							esc_html( (string) $option_label )
						);
					}

					echo '</select>';
					break;

				default:
					printf(
						'<input type="%1$s" id="%2$s" name="gwc_vt[%3$s]" value="%4$s" class="regular-text" placeholder="%5$s" />',
						esc_attr( 'email' === $type ? 'email' : 'text' ),
						esc_attr( $id ),
						esc_attr( $key ),
						esc_attr( (string) $value ),
						esc_attr( (string) ( $field['placeholder'] ?? '' ) )
					);
			}
			?>

			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $field['help'] ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Save the settings form.
 */
function gwc_vt_handle_save_settings(): void {
	/* Capability before nonce, house rule, and wp_die() rather than a return so
	 * the only way to reach the write below is to have passed both. */
	gwc_vt_require_cap( 'manage' );

	check_admin_referer( 'gwc_vt_save_settings' );

	/* Not sanitized here, and that is the design rather than an oversight. This
	 * is a bag of settings whose types differ per field, so there is no single
	 * sanitizer that is right for all of them. Nothing is trusted on the way in:
	 * the loop below reads only the keys gwc_vt_settings_fields() declares — a
	 * key nobody declared is never looked at — and every value it does read goes
	 * through gwc_vt_sanitize_setting() against that field's own definition
	 * before it reaches $stored. Sanitizing twice, once generically here, would
	 * flatten the textarea fields the letter depends on. */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked directly above; sanitized per field at the foreach below.
	$posted = isset( $_POST['gwc_vt'] ) ? (array) wp_unslash( $_POST['gwc_vt'] ) : array();
	$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

	/* A tab nobody can see is a tab nobody may write. The loop below reads only
	 * the fields belonging to $tab, so a crafted POST naming a hidden tab would
	 * otherwise write that tab's settings — every field it did not carry going
	 * in as an unchecked default. A hidden screen whose handler still answers is
	 * not hidden. */
	if ( ! isset( gwc_vt_admin_tabs()[ $tab ] ) ) {
		wp_safe_redirect( gwc_vt_settings_url() );
		exit;
	}

	$stored = get_option( GWC_VT_SETTINGS_OPTION );
	$stored = is_array( $stored ) ? $stored : array();

	foreach ( gwc_vt_settings_fields() as $key => $field ) {
		/* Only this tab's fields. Without the check, saving the Letter tab would
		 * write every OTHER tab's settings back as their unchecked defaults —
		 * because a checkbox that is not on the page posts nothing, which is
		 * indistinguishable from one that was cleared. */
		if ( ( $field['tab'] ?? '' ) !== $tab ) {
			continue;
		}

		$stored[ $key ] = gwc_vt_sanitize_setting( $posted[ $key ] ?? null, $field );
	}

	/* Saving this tab IS the decision, including saving it as "keep
	 * indefinitely". The notice below goes away once somebody has considered the
	 * question, not once they have answered it a particular way. */
	if ( 'privacy' === $tab ) {
		$stored['retention_decided'] = true;
	}

	/* Same rule for the letter switch, which lives on this tab: saving it IS the
	 * decision, including saving it with letters left on. */
	if ( 'logging' === $tab ) {
		$stored['letters_decided'] = true;
	}

	update_option( GWC_VT_SETTINGS_OPTION, $stored );
	gwc_vt_settings_cache( null, true );

	/**
	 * Fires after the settings have been saved.
	 *
	 * @param string $tab Which tab was saved.
	 */
	do_action( 'gwc_vt_settings_saved', $tab );

	wp_safe_redirect( add_query_arg( 'gwc_vt_saved', '1', gwc_vt_settings_url( $tab ) ) );
	exit;
}

/**
 * Clean one posted value according to its field definition.
 *
 * @param mixed $raw   What was posted, or null if absent.
 * @param array $field Field definition.
 * @return mixed
 */
function gwc_vt_sanitize_setting( $raw, array $field ) {
	$type = (string) ( $field['type'] ?? 'text' );

	switch ( $type ) {
		case 'checkbox':
			return null !== $raw;

		case 'textarea':
			/* sanitize_textarea_field, never wp_kses_post. The email inliner in
			 * inc/render.php is hand-written and can only work because this
			 * plugin controls the letter's markup completely — arbitrary HTML
			 * here would mean it had to become a real CSS engine, which would
			 * mean a Composer dependency. */
			return sanitize_textarea_field( (string) $raw );

		case 'select':
			$options = $field['options'] ?? array();
			$options = is_callable( $options ) ? call_user_func( $options ) : (array) $options;
			$value   = sanitize_text_field( (string) $raw );

			// A value that is not one of the options is refused, not stored.
			return array_key_exists( $value, $options ) ? $value : '';

		case 'image':
			$attachment = absint( $raw );

			/* Refused unless it really is an image in the media library. A
			 * stored ID pointing at a PDF, or at an attachment somebody has
			 * since deleted, would render as a broken image on a document
			 * handed to a court. */
			if ( $attachment < 1 || 'attachment' !== get_post_type( $attachment ) ) {
				return 0;
			}

			return wp_attachment_is_image( $attachment ) ? $attachment : 0;

		case 'page':
			$page_id = absint( $raw );

			/* Refused unless it really is a page. A pinned ID pointing at an
			 * attachment or a deleted post is a form that renders and silently
			 * never accepts anything. */
			return ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) ? $page_id : 0;

		case 'email':
			$value = sanitize_email( (string) $raw );
			return is_email( $value ) ? $value : '';

		default:
			return sanitize_text_field( (string) $raw );
	}
}

add_action( 'admin_notices', 'gwc_vt_settings_saved_notice' );

/**
 * Confirm a save.
 */
function gwc_vt_settings_saved_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; decides whether to print a confirmation after a redirect.
	if ( ! isset( $_GET['gwc_vt_saved'] ) || ! gwc_vt_is_plugin_screen() ) {
		return;
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Settings saved.', 'groundwork-common-volunteer-tracker' )
	);
}

add_action( 'admin_notices', 'gwc_vt_retention_undecided_notice' );

/* ── Asking once whether this organization issues letters ────────────────────
 * letters_enabled defaults ON, which is right — the letter is the original
 * product and defaulting it off would take half the plugin away from every
 * existing install on update. But a default that is right for most sites is
 * still a decision nobody made on the ones it is wrong for, and those are the
 * sites that would benefit most: an organization that schedules volunteers and
 * logs hours and will never write to a court carries the whole letter surface,
 * and all of its most alarming settings, until somebody happens to notice a
 * checkbox.
 *
 * So it is asked, once, and the asking stops for good the moment the Logging
 * tab is saved — whatever it is saved as.
 *
 * Three things keep it from becoming noise, and each is a deliberate difference
 * from the retention notice above:
 *
 *   It is an info notice, not a warning. Nothing is at stake here but a screen
 *   full of settings nobody needs, and dressing that as a warning is how a site
 *   learns to ignore the warning that does matter.
 *
 *   It stays silent on any site that has ever issued a letter. Issuing one is
 *   the answer, and asking somebody to confirm a decision they have
 *   demonstrably made is exactly how a prompt becomes noise. That is also what
 *   keeps this from appearing on every established install on update.
 *
 *   It is not shown to anybody who could not act on it.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action( 'admin_notices', 'gwc_vt_letters_undecided_notice' );

/**
 * Ask once whether this organization issues verification letters.
 */
function gwc_vt_letters_undecided_notice(): void {
	if ( ! gwc_vt_is_plugin_screen() || ! current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		return;
	}

	if ( gwc_vt_letters_decided() ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
		esc_html__( 'Does your organization issue verification letters?', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'They are switched on, which is what most organizations want: a volunteer can be given a letter stating the hours you have verified, for a court or a school. If yours records hours but never writes to either, you can turn the whole thing off — the Letters screen and its settings go away, and nothing you have recorded is affected. Either answer settles this.', 'groundwork-common-volunteer-tracker' ),
		esc_url( gwc_vt_settings_url( 'logging' ) ),
		esc_html__( 'Answer now', 'groundwork-common-volunteer-tracker' )
	);
}

/**
 * Nag until retention has been considered.
 *
 * Scoped strictly to this plugin's own screens, and not dismissible — a notice
 * that can be dismissed is one that gets dismissed in the first week, which is
 * the opposite of making a decision deliberate. It disappears the moment the
 * Privacy tab is saved, whatever it is saved as.
 */
function gwc_vt_retention_undecided_notice(): void {
	if ( ! gwc_vt_is_plugin_screen() || ! current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		return;
	}

	if ( gwc_vt_setting( 'retention_decided' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
		esc_html__( 'How long should volunteer records be kept?', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'This plugin stores names, contact details and — for court-ordered service — information about somebody’s legal obligations. Nothing is being deleted while this is unanswered. Keeping records indefinitely is a perfectly good answer; not having decided is not.', 'groundwork-common-volunteer-tracker' ),
		esc_url( gwc_vt_settings_url( 'privacy' ) ),
		esc_html__( 'Decide now', 'groundwork-common-volunteer-tracker' )
	);
}

/* ── Who is allowed to do what ───────────────────────────────────────────────
 * This plugin adds exactly two capabilities, and inc/access.php is explicit
 * about why they are two rather than one: the person who logs Saturday's hours
 * and the person who signs a letter to a probation officer are frequently
 * different people, and the letter is the higher-trust action.
 *
 * That reasoning was right and unusable. Both were granted to administrator and
 * editor and to nothing else, and no screen anywhere showed who held them or let
 * anybody change it — so realizing the separation the code argues for meant
 * installing a capability-manager plugin or writing a filter in PHP. Meanwhile a
 * coordinator on any other role found the Verify button simply absent, with
 * nothing on screen explaining why.
 *
 * A role matrix rather than a per-user one, because WordPress's own model is
 * roles and because a per-user grant becomes invisible the moment somebody
 * changes that user's role.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Add the Permissions tab.
 *
 * @param array $tabs Slug => label.
 * @return array
 */
function gwc_vt_add_permissions_tab( $tabs ): array {
	$tabs = (array) $tabs;

	/* Before Privacy, after the three that describe what the plugin does. Who may
	 * use it belongs beside how long its records are kept. */
	$privacy = $tabs['privacy'] ?? null;
	unset( $tabs['privacy'] );

	$tabs['permissions'] = __( 'Permissions', 'groundwork-common-volunteer-tracker' );

	if ( null !== $privacy ) {
		$tabs['privacy'] = $privacy;
	}

	return $tabs;
}

/**
 * The roles these capabilities can sensibly be granted to.
 *
 * Anything that cannot edit posts is left out: a matrix offering to let
 * Subscriber verify hours invites somebody to select it and then wonder why the
 * screen is not there.
 *
 * @return array<string, string> Role slug => display name.
 */
function gwc_vt_permission_roles(): array {
	$roles = array();

	foreach ( (array) wp_roles()->roles as $slug => $role ) {
		$caps = (array) ( $role['capabilities'] ?? array() );

		if ( empty( $caps['edit_posts'] ) ) {
			continue;
		}

		$roles[ (string) $slug ] = translate_user_role( (string) ( $role['name'] ?? $slug ) );
	}

	return $roles;
}

/**
 * One checkbox in the matrix.
 *
 * @param string $name  Field name.
 * @param string $slug  Role slug.
 * @param string $label Role display name.
 * @param string $title Screen-reader sentence.
 * @param bool   $held  Whether the role holds the capability.
 * @param bool   $fixed Whether it cannot be changed.
 */
function gwc_vt_render_permission_box( string $name, string $slug, string $label, string $title, bool $held, bool $fixed ): void {
	?>
	<label>
		<input
			type="checkbox"
			name="<?php echo esc_attr( $name ); ?>[]"
			value="<?php echo esc_attr( $slug ); ?>"
			<?php checked( $held ); ?>
			<?php disabled( $fixed ); ?>
		/>
		<span class="screen-reader-text">
			<?php
			printf(
				esc_html( $title ),
				esc_html( $label )
			);
			?>
		</span>
	</label>
	<?php
	/* A disabled checkbox posts nothing, so the fixed row needs a hidden field
	 * or saving the form would revoke what it is showing as selected. */
	if ( $fixed ) :
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" />
		<?php
	endif;
}

/**
 * The Permissions tab.
 */
function gwc_vt_render_permissions_tab(): void {
	$roles  = gwc_vt_permission_roles();
	$verify = gwc_vt_cap( 'verify' );
	$issue  = gwc_vt_cap( 'issue' );
	?>
	<h2><?php esc_html_e( 'Who can do what', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<p class="description" style="max-width:44em">
		<?php esc_html_e( 'Anybody who can edit posts on this site can log hours and see volunteer records. These two are held separately because they are bigger decisions: attesting that hours were worked, and putting your organization\'s name on a letter to a court. They are frequently different people.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>

	<p class="description" style="max-width:44em">
		<?php esc_html_e( 'A role with neither still sees the hours list and can type up a shift. It simply has no Verify button and no Letters screen, rather than being shown buttons that would refuse it.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwc_vt_save_permissions" />
		<?php wp_nonce_field( 'gwc_vt_save_permissions' ); ?>

		<table class="widefat striped" style="max-width:44em">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Role', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Verify hours', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Issue letters', 'groundwork-common-volunteer-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $roles as $slug => $label ) : ?>
					<?php
					$role  = get_role( $slug );
					$fixed = 'administrator' === $slug;
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<?php
							gwc_vt_render_permission_box(
								'gwc_vt_verify',
								(string) $slug,
								(string) $label,
								/* translators: %s: a role name. */
								__( 'Let %s verify hours', 'groundwork-common-volunteer-tracker' ),
								(bool) ( $role && $role->has_cap( $verify ) ),
								$fixed
							);
							?>
						</td>
						<td>
							<?php
							gwc_vt_render_permission_box(
								'gwc_vt_issue',
								(string) $slug,
								(string) $label,
								/* translators: %s: a role name. */
								__( 'Let %s issue letters', 'groundwork-common-volunteer-tracker' ),
								(bool) ( $role && $role->has_cap( $issue ) ),
								$fixed
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="max-width:44em">
			<?php esc_html_e( 'Administrator is fixed. A site where nobody can issue a letter has no way to give one back, and an administrator can change this screen anyway.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * Save the permissions matrix.
 */
function gwc_vt_handle_save_permissions(): void {
	gwc_vt_require_cap( 'manage' );
	check_admin_referer( 'gwc_vt_save_permissions' );

	$posted = wp_unslash( $_POST );

	$wanted = array(
		gwc_vt_cap( 'verify' ) => array_map( 'sanitize_key', (array) ( $posted['gwc_vt_verify'] ?? array() ) ),
		gwc_vt_cap( 'issue' )  => array_map( 'sanitize_key', (array) ( $posted['gwc_vt_issue'] ?? array() ) ),
	);

	foreach ( gwc_vt_permission_roles() as $slug => $label ) {
		$role = get_role( $slug );

		if ( ! $role ) {
			continue;
		}

		foreach ( $wanted as $cap => $granted ) {
			$should = in_array( (string) $slug, $granted, true );

			/* add_cap() writes the whole role back to the options table, so only
			 * touch a role whose answer has actually changed. */
			if ( $should === $role->has_cap( $cap ) ) {
				continue;
			}

			if ( $should ) {
				$role->add_cap( $cap );
				continue;
			}

			/* An explicit false rather than remove_cap(). gwc_vt_grant_capabilities()
			 * runs on every init and restores a capability whose key is missing
			 * altogether — it cannot tell "somebody removed this" from "this role
			 * never heard of it". A stored false is what says a person decided no,
			 * and it is the one form that survives the next page load. */
			$role->add_cap( $cap, false );
		}
	}

	wp_safe_redirect( add_query_arg( 'gwc_vt_saved', '1', gwc_vt_settings_url( 'permissions' ) ) );
	exit;
}

/* ── What deleting the plugin does ───────────────────────────────────────────
 * uninstall.php removes nothing but a handful of options unless the site has
 * explicitly armed it, and even then it never touches a post, a meta row or a
 * capability. That policy is right — a plugin that dropped a volunteer's
 * court-ordered service history because somebody clicked Delete on the Plugins
 * screen would be indefensible.
 *
 * What was wrong is that it was invisible. The flag appeared only in
 * uninstall.php, with no screen, no notice and one line in a changelog five
 * releases back. So both outcomes were reachable by surprise: an organization
 * that deleted the plugin and believed the records went with it, and one obliged
 * to remove those records with no way to do it short of WP-CLI.
 *
 * Stated here, on the tab that is already about keeping and removing records,
 * and armed only by a deliberate act on this screen.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Whether an uninstall is currently allowed to delete this plugin's options.
 *
 * @return bool
 */
function gwc_vt_destructive_uninstall_armed(): bool {
	return (bool) get_option( 'gwc_vt_allow_destructive_uninstall', false );
}

/**
 * The "Removing this plugin" section.
 */
function gwc_vt_render_uninstall_section(): void {
	$armed = gwc_vt_destructive_uninstall_armed();
	?>
	<h2><?php esc_html_e( 'Removing this plugin', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<p class="description" style="max-width:44em">
		<?php esc_html_e( 'Deleting this plugin from the Plugins screen leaves every volunteer record, every logged shift, every scheduled shift and signup, and the log of letters issued, exactly where they are. Deactivating it does the same. Nothing here removes a person\'s hours by accident, and reinstalling picks up where you left off.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>

	<p class="description" style="max-width:44em">
		<?php esc_html_e( 'That is the right default and it is worth knowing about, because it also means deleting the plugin is not a way to remove somebody\'s data. To do that, use the retention policy above, or WordPress\'s own Erase Personal Data tool, before you delete anything.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:44em">
		<input type="hidden" name="action" value="gwc_vt_save_uninstall" />
		<?php wp_nonce_field( 'gwc_vt_save_uninstall' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'On deletion', 'groundwork-common-volunteer-tracker' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="gwc_vt_allow_destructive_uninstall" value="1" <?php checked( $armed ); ?> />
							<?php esc_html_e( 'Also remove this plugin\'s settings when it is deleted', 'groundwork-common-volunteer-tracker' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default. With it on, deleting the plugin also removes its settings, its retention log and the record of what it had been asked to do. It still does not delete a single volunteer, shift, signup, hour entry or issued letter — nothing this plugin can do from this screen will.', 'groundwork-common-volunteer-tracker' ); ?>
						</p>
						<?php if ( $armed ) : ?>
							<p class="description">
								<strong><?php esc_html_e( 'Currently armed.', 'groundwork-common-volunteer-tracker' ); ?></strong>
								<?php esc_html_e( 'Deleting the plugin will discard its configuration, so a reinstall starts from the defaults and the retention question is asked again.', 'groundwork-common-volunteer-tracker' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save', 'groundwork-common-volunteer-tracker' ) ); ?>
	</form>
	<?php
}

/**
 * Save the uninstall preference.
 */
function gwc_vt_handle_save_uninstall(): void {
	gwc_vt_require_cap( 'manage' );
	check_admin_referer( 'gwc_vt_save_uninstall' );

	$armed = ! empty( $_POST['gwc_vt_allow_destructive_uninstall'] );

	/* Non-autoloaded: read once, by uninstall.php, on a request where nothing
	 * else of ours is loaded. */
	update_option( 'gwc_vt_allow_destructive_uninstall', $armed, false );

	wp_safe_redirect( add_query_arg( 'gwc_vt_saved', '1', gwc_vt_settings_url( 'privacy' ) ) );
	exit;
}

/**
 * What the last few sweeps did.
 */
function gwc_vt_render_retention_log(): void {
	$log = gwc_vt_retention_log();
	?>
	<h2><?php esc_html_e( 'Recent sweeps', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<?php if ( ! $log ) : ?>
		<p class="description">
			<?php esc_html_e( 'Nothing has been purged yet. The sweep runs once a day and does nothing at all while records are kept indefinitely.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<?php return; ?>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:44em">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Records', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Held back', 'groundwork-common-volunteer-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $log as $run ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $run['at'] ?? '' ) ); ?></td>
					<td>
						<?php
						echo esc_html(
							'delete' === ( $run['action'] ?? '' )
								? __( 'Deleted', 'groundwork-common-volunteer-tracker' )
								: __( 'Anonymized', 'groundwork-common-volunteer-tracker' )
						);
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) ( $run['purged'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) ( $run['held'] ?? 0 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

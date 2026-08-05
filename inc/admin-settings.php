<?php
/**
 * The settings tabs and the one handler that saves them.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gwcvt_render_tab_letter', 'gwcvt_render_settings_tab' );
add_action( 'gwcvt_render_tab_logging', 'gwcvt_render_settings_tab' );
add_action( 'gwcvt_render_tab_shifts', 'gwcvt_render_settings_tab' );
add_action( 'gwcvt_render_tab_privacy', 'gwcvt_render_settings_tab' );
add_action( 'gwcvt_render_tab_privacy', 'gwcvt_render_retention_log', 20 );
add_action( 'admin_post_gwcvt_save_settings', 'gwcvt_handle_save_settings' );

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
function gwcvt_settings_fields(): array {
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
			'help'    => __( 'Printed under the signature line. Left empty, the line prints unlabelled — which looks unfinished, and is meant to.', 'groundwork-common-volunteer-tracker' ),
		),
		'signatory_title'           => array(
			'tab'     => 'letter',
			'section' => 'signature',
			'type'    => 'text',
			'label'   => __( 'Their title', 'groundwork-common-volunteer-tracker' ),
		),

		/* ── Letter: wording ─────────────────────────────────────────────── */

		'letter_intro'              => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 4,
			'label'       => __( 'Opening paragraph', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwcvt_letter_intro( array() ),
			'help'        => __( 'Leave empty to use the wording shown. Plain text only — bold and italic survive, other markup does not.', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),
		'letter_disclaimer'         => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 5,
			'label'       => __( 'Disclaimer', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwcvt_default_disclaimer(),
			'help'        => __( 'Your counsel may want particular wording, so this is yours to change. It cannot be emptied: saving it blank restores the default. A letter with no disclaimer is a letter that has quietly started implying this plugin certified something.', 'groundwork-common-volunteer-tracker' ),
			'tokens'      => true,
		),
		'letter_reference_note'     => array(
			'tab'         => 'letter',
			'section'     => 'wording',
			'type'        => 'textarea',
			'rows'        => 3,
			'label'       => __( 'What the reference code proves', 'groundwork-common-volunteer-tracker' ),
			'placeholder' => gwcvt_default_reference_note(),
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
		),

		/* ── Logging ─────────────────────────────────────────────────────── */

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
			'options' => 'gwcvt_hour_format_labels',
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
			'help'    => __( 'Add the Volunteer Hours Form block, or the [volunteer_hours_form] shortcode, to a page and choose it here. Submissions are only accepted on this page, and it is pinned by ID so renaming it changes nothing.', 'groundwork-common-volunteer-tracker' ),
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
			'options' => 'gwcvt_retention_period_options',
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
			'help'    => __( 'Anonymising is usually right: your grant reporting and your Form 990 need the hours, and the hours identify nobody once the name is gone.', 'groundwork-common-volunteer-tracker' ),
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
			'help'    => __( 'Add the Volunteer Shifts block, or the [volunteer_shifts] shortcode, to a page and choose it here. Signups are only accepted on this page, and it is pinned by ID so renaming it changes nothing.', 'groundwork-common-volunteer-tracker' ),
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
			'options' => 'gwcvt_signup_horizon_options',
			'help'    => __( 'How far ahead the public list looks. A year of Saturdays is a wall of text nobody reads to the bottom of.', 'groundwork-common-volunteer-tracker' ),
		),
		'signup_cutoff_hours'       => array(
			'tab'     => 'shifts',
			'section' => 'signup',
			'type'    => 'select',
			'label'   => __( 'Close signups', 'groundwork-common-volunteer-tracker' ),
			'options' => 'gwcvt_signup_cutoff_options',
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
			'options' => 'gwcvt_reminder_lead_options',
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
	return (array) apply_filters( 'gwcvt_settings_fields', $fields );
}

/**
 * The sections within each tab, in the order they render.
 *
 * @return array<string, array<string, string>>
 */
function gwcvt_settings_sections(): array {
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
function gwcvt_retention_period_options(): array {
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
function gwcvt_signup_horizon_options(): array {
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
function gwcvt_signup_cutoff_options(): array {
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
function gwcvt_reminder_lead_options(): array {
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
function gwcvt_render_settings_tab(): void {
	$tab      = gwcvt_current_tab();
	$sections = gwcvt_settings_sections()[ $tab ] ?? array();
	$fields   = array_filter(
		gwcvt_settings_fields(),
		static fn( array $field ): bool => ( $field['tab'] ?? '' ) === $tab
	);

	if ( ! $fields ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwcvt_save_settings" />
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
		<?php wp_nonce_field( 'gwcvt_save_settings' ); ?>

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
						'<code>' . implode( '</code> <code>', array_map( 'esc_html', gwcvt_token_names() ) ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $in_section as $key => $field ) : ?>
						<?php gwcvt_render_settings_field( $key, $field ); ?>
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
function gwcvt_token_names(): array {
	$sample = new GWCVT_Letter( 0, '', '', '', array(), 0, 0, false, '', time() );

	return array_keys( gwcvt_letter_tokens( $sample ) );
}

/**
 * One row of the settings form.
 *
 * @param string $key   Setting name.
 * @param array  $field Field definition.
 */
function gwcvt_render_settings_field( string $key, array $field ): void {
	$type  = (string) ( $field['type'] ?? 'text' );
	$value = gwcvt_setting( $key );
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
						'<label><input type="checkbox" id="%1$s" name="gwcvt[%2$s]" value="1" %3$s /> %4$s</label>',
						esc_attr( $id ),
						esc_attr( $key ),
						checked( (bool) $value, true, false ),
						esc_html( (string) ( $field['label'] ?? '' ) )
					);
					break;

				case 'textarea':
					printf(
						'<textarea id="%1$s" name="gwcvt[%2$s]" rows="%3$d" class="large-text" placeholder="%4$s">%5$s</textarea>',
						esc_attr( $id ),
						esc_attr( $key ),
						(int) ( $field['rows'] ?? 3 ),
						esc_attr( (string) ( $field['placeholder'] ?? '' ) ),
						esc_textarea( (string) $value )
					);
					break;

				case 'page':
					wp_dropdown_pages(
						array(
							'name'              => 'gwcvt[' . $key . ']',
							'id'                => $id,
							'selected'          => (int) $value,
							'show_option_none'  => __( '— not set —', 'groundwork-common-volunteer-tracker' ),
							'option_none_value' => '0',
						)
					);
					break;

				case 'select':
					$options = $field['options'] ?? array();
					$options = is_callable( $options ) ? call_user_func( $options ) : (array) $options;

					printf( '<select id="%1$s" name="gwcvt[%2$s]">', esc_attr( $id ), esc_attr( $key ) );

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
						'<input type="%1$s" id="%2$s" name="gwcvt[%3$s]" value="%4$s" class="regular-text" placeholder="%5$s" />',
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
function gwcvt_handle_save_settings(): void {
	/* Capability before nonce, house rule, and wp_die() rather than a return so
	 * the only way to reach the write below is to have passed both. */
	gwcvt_require_cap( 'manage' );

	check_admin_referer( 'gwcvt_save_settings' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = isset( $_POST['gwcvt'] ) ? (array) wp_unslash( $_POST['gwcvt'] ) : array();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

	$stored = get_option( GWCVT_SETTINGS_OPTION );
	$stored = is_array( $stored ) ? $stored : array();

	foreach ( gwcvt_settings_fields() as $key => $field ) {
		/* Only this tab's fields. Without the check, saving the Letter tab would
		 * write every OTHER tab's settings back as their unchecked defaults —
		 * because a checkbox that is not on the page posts nothing, which is
		 * indistinguishable from one that was unticked. */
		if ( ( $field['tab'] ?? '' ) !== $tab ) {
			continue;
		}

		$stored[ $key ] = gwcvt_sanitize_setting( $posted[ $key ] ?? null, $field );
	}

	/* Saving this tab IS the decision, including saving it as "keep
	 * indefinitely". The notice below goes away once somebody has considered the
	 * question, not once they have answered it a particular way. */
	if ( 'privacy' === $tab ) {
		$stored['retention_decided'] = true;
	}

	update_option( GWCVT_SETTINGS_OPTION, $stored );
	gwcvt_settings_cache( null, true );

	/**
	 * Fires after the settings have been saved.
	 *
	 * @param string $tab Which tab was saved.
	 */
	do_action( 'gwcvt_settings_saved', $tab );

	wp_safe_redirect( add_query_arg( 'gwcvt_saved', '1', gwcvt_settings_url( $tab ) ) );
	exit;
}

/**
 * Clean one posted value according to its field definition.
 *
 * @param mixed $raw   What was posted, or null if absent.
 * @param array $field Field definition.
 * @return mixed
 */
function gwcvt_sanitize_setting( $raw, array $field ) {
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

add_action( 'admin_notices', 'gwcvt_settings_saved_notice' );

/**
 * Confirm a save.
 */
function gwcvt_settings_saved_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; decides whether to print a confirmation after a redirect.
	if ( ! isset( $_GET['gwcvt_saved'] ) || ! gwcvt_is_plugin_screen() ) {
		return;
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Settings saved.', 'groundwork-common-volunteer-tracker' )
	);
}

add_action( 'admin_notices', 'gwcvt_retention_undecided_notice' );

/**
 * Nag until retention has been considered.
 *
 * Scoped strictly to this plugin's own screens, and not dismissible — a notice
 * that can be dismissed is one that gets dismissed in the first week, which is
 * the opposite of making a decision deliberate. It disappears the moment the
 * Privacy tab is saved, whatever it is saved as.
 */
function gwcvt_retention_undecided_notice(): void {
	if ( ! gwcvt_is_plugin_screen() || ! current_user_can( gwcvt_cap( 'manage' ) ) ) {
		return;
	}

	if ( gwcvt_setting( 'retention_decided' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
		esc_html__( 'How long should volunteer records be kept?', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'This plugin stores names, contact details and — for court-ordered service — information about somebody’s legal obligations. Nothing is being deleted while this is unanswered. Keeping records indefinitely is a perfectly good answer; not having decided is not.', 'groundwork-common-volunteer-tracker' ),
		esc_url( gwcvt_settings_url( 'privacy' ) ),
		esc_html__( 'Decide now', 'groundwork-common-volunteer-tracker' )
	);
}

/**
 * What the last few sweeps did.
 */
function gwcvt_render_retention_log(): void {
	$log = gwcvt_retention_log();
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
								: __( 'Anonymised', 'groundwork-common-volunteer-tracker' )
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

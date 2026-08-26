<?php
/**
 * The public "I would like to help" form.
 *
 * A near-twin of inc/form.php, and the resemblance is deliberate: the two
 * public forms should look, behave and fail the same way, because a visitor who
 * has used one should not have to learn the other. Where this one differs it is
 * noted.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The form, or the reason there is not one.
 *
 * @return string
 */
function gwc_vt_render_registration_form(): string {
	if ( ! gwc_vt_registration_enabled() ) {
		/* Silent on the front end. Somebody reading a public page should not be
		 * told that a feature exists but is switched off — that is a note for
		 * the administrator, and the block editor shows them one. */
		return '';
	}

	/* Not on the pinned page. The handler refuses a submission from anywhere
	 * else, so a working-looking form here would take somebody's details and
	 * silently drop them. Same reasoning, same shape, as the hours form. */
	if ( ! gwc_vt_is_registration_page() ) {
		return sprintf(
			'<div class="gwcvt-form"><div class="gwcvt-form__message gwcvt-form__message--problem"><p>%s</p></div></div>',
			esc_html__( 'This form is set up on another page, so it cannot take your details here. Please use the form on the page your organization gave you.', 'groundwork-common-volunteer-tracker' )
		);
	}

	$result  = (string) ( $GLOBALS['gwc_vt_registration_result'] ?? '' );
	$message = '' !== $result ? gwc_vt_registration_message( $result ) : '';
	$code    = (string) gwc_vt_setting( 'registration_code' );

	/* Accepted, and the form does not come back — re-rendering it filled in
	 * invites a second identical offer from somebody unsure the first worked.
	 * Returned before the buffer opens, because a return inside an ob_start()
	 * block is how output buffers get left open. */
	if ( 'accepted' === $result ) {
		return sprintf(
			'<div class="gwcvt-form"><div class="gwcvt-form__message gwcvt-form__message--ok" role="status"><p>%s</p></div></div>',
			esc_html( $message )
		);
	}

	$keep = gwc_vt_registration_values();
	$asks = gwc_vt_registration_asks_required();

	ob_start();
	?>
	<div class="gwcvt-form">
		<?php if ( '' !== $message ) : ?>
			<div class="gwcvt-form__message gwcvt-form__message--problem" role="alert">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" class="gwcvt-form__fields">
			<?php wp_nonce_field( 'gwc_vt_registration', 'gwc_vt_registration_nonce' ); ?>
			<input type="hidden" name="gwc_vt_t" value="<?php echo esc_attr( gwc_vt_form_stamp() ); ?>" />

			<?php
			/* The honeypot, spelled as inc/form.php spells it: off screen via a
			 * stylesheet class rather than type="hidden" or an inline
			 * display:none, both of which the scripts worth stopping skip.
			 * Sharing the field name with the hours form is intentional — one
			 * decoy to keep working, not two. */
			?>
			<div class="gwcvt-form__hp" aria-hidden="true">
				<label for="gwcvt-reg-website"><?php esc_html_e( 'Leave this field empty', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-reg-website" name="gwc_vt_website" tabindex="-1" autocomplete="off" value="" />
			</div>

			<fieldset class="gwcvt-form__group">
				<legend><?php esc_html_e( 'Tell us about yourself', 'groundwork-common-volunteer-tracker' ); ?></legend>

				<p class="gwcvt-form__field">
					<label for="gwcvt-reg-name"><?php esc_html_e( 'Your name', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-reg-name" name="gwc_vt_name" maxlength="100" required value="<?php echo esc_attr( $keep['name'] ); ?>" />
				</p>

				<p class="gwcvt-form__field">
					<label for="gwcvt-reg-email"><?php esc_html_e( 'Your email', 'groundwork-common-volunteer-tracker' ); ?></label>
					<?php
					/* Required here, unlike the hours form. A shift that happened
					 * happened whether or not anybody can write about it; an
					 * offer to help with no way to reply is not one anybody can
					 * accept. */
					?>
					<input type="email" id="gwcvt-reg-email" name="gwc_vt_email" maxlength="200" required aria-describedby="gwcvt-reg-email-help" value="<?php echo esc_attr( $keep['email'] ); ?>" />
					<span class="gwcvt-form__help" id="gwcvt-reg-email-help"><?php esc_html_e( 'How we will reply. We do not publish it or pass it on.', 'groundwork-common-volunteer-tracker' ); ?></span>
				</p>

				<p class="gwcvt-form__field">
					<label for="gwcvt-reg-phone"><?php esc_html_e( 'Your phone number', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-reg-phone" name="gwc_vt_phone" maxlength="40" aria-describedby="gwcvt-reg-phone-help" value="<?php echo esc_attr( $keep['phone'] ); ?>" />
					<span class="gwcvt-form__help" id="gwcvt-reg-phone-help"><?php esc_html_e( 'Optional. Useful if a shift is short at the last minute.', 'groundwork-common-volunteer-tracker' ); ?></span>
				</p>

				<p class="gwcvt-form__field">
					<label for="gwcvt-reg-note"><?php esc_html_e( 'Anything we should know', 'groundwork-common-volunteer-tracker' ); ?></label>
					<textarea id="gwcvt-reg-note" name="gwc_vt_note" rows="4" maxlength="1000" aria-describedby="gwcvt-reg-note-help"><?php echo esc_textarea( $keep['note'] ); ?></textarea>
					<span class="gwcvt-form__help" id="gwcvt-reg-note-help"><?php esc_html_e( 'When you are free, what you would like to do, anything we should plan around. Optional.', 'groundwork-common-volunteer-tracker' ); ?></span>
				</p>
			</fieldset>

			<?php if ( $asks ) : ?>
				<?php
				/* Its own fieldset, its own legend, and an explanation of why it
				 * is being asked. This is the question that tells the
				 * organization somebody is under a court order, and a bare
				 * "Required hours" box beside a phone number would collect that
				 * from people who had not understood they were disclosing it.
				 *
				 * Every field in here is optional, and the legend says so. An
				 * organization that switched this on still has volunteers who
				 * are simply volunteering. */
				?>
				<fieldset class="gwcvt-form__group">
					<legend><?php esc_html_e( 'Are you completing required service? (optional)', 'groundwork-common-volunteer-tracker' ); ?></legend>

					<p class="gwcvt-form__help">
						<?php esc_html_e( 'Some people volunteer with us to complete hours required by a court or a school. If that is you, telling us now means we can plan around your deadline. If it is not, leave this part empty — it makes no difference to whether we would like your help.', 'groundwork-common-volunteer-tracker' ); ?>
					</p>

					<p class="gwcvt-form__field">
						<label for="gwcvt-reg-required"><?php esc_html_e( 'How many hours you need', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="text" id="gwcvt-reg-required" name="gwc_vt_required" maxlength="20" inputmode="decimal" value="<?php echo esc_attr( $keep['required'] ); ?>" />
					</p>

					<p class="gwcvt-form__field">
						<label for="gwcvt-reg-required-by"><?php esc_html_e( 'The date they are due by', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="date" id="gwcvt-reg-required-by" name="gwc_vt_required_by" value="<?php echo esc_attr( $keep['required_by'] ); ?>" />
					</p>

					<p class="gwcvt-form__field">
						<label for="gwcvt-reg-required-for"><?php esc_html_e( 'Who requires them', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="text" id="gwcvt-reg-required-for" name="gwc_vt_required_for" maxlength="120" aria-describedby="gwcvt-reg-required-for-help" value="<?php echo esc_attr( $keep['required_for'] ); ?>" />
						<span class="gwcvt-form__help" id="gwcvt-reg-required-for-help"><?php esc_html_e( 'The court, school or program. Used only for your own paperwork, and never published.', 'groundwork-common-volunteer-tracker' ); ?></span>
					</p>
				</fieldset>
			<?php endif; ?>

			<?php if ( '' !== $code ) : ?>
				<p class="gwcvt-form__field">
					<label for="gwcvt-reg-code"><?php esc_html_e( 'The code you were given', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-reg-code" name="gwc_vt_code" maxlength="60" required value="" />
				</p>
			<?php endif; ?>

			<p class="gwcvt-form__submit">
				<button type="submit"><?php esc_html_e( 'Send my details', 'groundwork-common-volunteer-tracker' ); ?></button>
			</p>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * What was typed, handed back so nobody retypes it after a stale submission.
 *
 * Every key is always present, so the template can read them without a null
 * coalesce on each — inc/form.php's own habit, and the reason a missing key
 * there is a notice rather than an empty box.
 *
 * @return array<string, string>
 */
function gwc_vt_registration_values(): array {
	$keys = array( 'name', 'email', 'phone', 'note', 'required', 'required_by', 'required_for' );
	$out  = array_fill_keys( $keys, '' );

	/* Unslashed once, the way gwc_vt_submitted_values() does it, rather than key
	 * by key. Every value is sanitized below and escaped again at output. */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; these values are only echoed back into the form the visitor just posted, and every one is escaped at output.
	$posted = wp_unslash( $_POST );

	if ( ! $posted ) {
		return $out;
	}

	foreach ( $keys as $key ) {
		$raw = (string) ( $posted[ 'gwc_vt_' . $key ] ?? '' );

		$out[ $key ] = 'note' === $key
			? sanitize_textarea_field( $raw )
			: sanitize_text_field( $raw );
	}

	return $out;
}

/**
 * The shortcode.
 *
 * @return string
 */
function gwc_vt_registration_shortcode(): string {
	/* The block gets its stylesheet from block.json's "style" handle; a
	 * shortcode has no such wiring and has to ask. Enqueued only when the form
	 * actually renders, so a page carrying the shortcode with the feature
	 * switched off loads nothing.
	 *
	 * This is not cosmetic. The honeypot is hidden by .gwcvt-form__hp in
	 * assets/css/form.css and by nothing else — without the stylesheet the decoy
	 * is a visible box labelled "Leave this field empty", which real people
	 * duly fill in, and every one of those offers is then silently discarded as
	 * spam. Seen on the first render of this form, which is why it says so here.
	 */
	$form = gwc_vt_render_registration_form();

	if ( '' !== $form ) {
		wp_enqueue_style( 'gwc-vt-form' );
	}

	return $form;
}

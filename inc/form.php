<?php
/**
 * The public hour-logging form.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the form.
 *
 * Returns markup rather than echoing, because both the block and the shortcode
 * need a string.
 *
 * @return string
 */
function gwcvt_render_self_log_form(): string {
	if ( ! gwcvt_self_log_enabled() ) {
		/* Silent on the front end. Somebody reading a public page should not be
		 * told that a feature exists but is switched off — that is a note for
		 * the administrator, and the block editor shows them one. */
		return '';
	}

	$result  = (string) ( $GLOBALS['gwcvt_self_log_result'] ?? '' );
	$message = '' !== $result ? gwcvt_self_log_message( $result ) : '';
	$code    = (string) gwcvt_setting( 'self_log_code' );

	/* Accepted, and the form does not come back. Re-rendering it filled in
	 * invites a second identical submission from somebody who is not sure the
	 * first one worked — and this is the one outcome where the visitor has
	 * nothing left to do.
	 *
	 * Returned before the buffer opens rather than by breaking out of the
	 * template below, because a `return` in the middle of an ob_start() block is
	 * how output buffers get left open. */
	if ( 'accepted' === $result ) {
		return sprintf(
			'<div class="gwcvt-form"><div class="gwcvt-form__message gwcvt-form__message--ok" role="status"><p>%s</p></div></div>',
			esc_html( $message )
		);
	}

	/* Values are handed back on every other outcome. Somebody whose form went
	 * stale over lunch should not have to retype it. */
	$keep = gwcvt_submitted_values();

	ob_start();
	?>
	<div class="gwcvt-form">
		<?php if ( '' !== $message ) : ?>
			<div class="gwcvt-form__message gwcvt-form__message--problem" role="alert">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" class="gwcvt-form__fields">
			<?php wp_nonce_field( 'gwcvt_self_log', 'gwcvt_self_log_nonce' ); ?>
			<input type="hidden" name="gwcvt_t" value="<?php echo esc_attr( gwcvt_form_stamp() ); ?>" />

			<?php
			/* The honeypot. Off screen via a stylesheet class rather than
			 * type="hidden" or an inline display:none — both of which the
			 * scripts worth stopping already skip. aria-hidden and tabindex
			 * keep it away from anybody using a screen reader or the keyboard. */
			?>
			<div class="gwcvt-form__hp" aria-hidden="true">
				<label for="gwcvt-website"><?php esc_html_e( 'Leave this field empty', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-website" name="gwcvt_website" tabindex="-1" autocomplete="off" value="" />
			</div>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-name"><?php esc_html_e( 'Your name', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-self-name" name="gwcvt_name" maxlength="100" required value="<?php echo esc_attr( $keep['name'] ?? '' ); ?>" />
			</p>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-email"><?php esc_html_e( 'Your email', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="email" id="gwcvt-self-email" name="gwcvt_email" maxlength="200" value="<?php echo esc_attr( $keep['email'] ?? '' ); ?>" />
				<span class="gwcvt-form__help"><?php esc_html_e( 'Helps staff match these hours to your record. Optional.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-date"><?php esc_html_e( 'Date you volunteered', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input
					type="date"
					id="gwcvt-self-date"
					name="gwcvt_date"
					required
					<?php echo gwcvt_setting( 'allow_future_dates' ) ? '' : 'max="' . esc_attr( gwcvt_today() ) . '"'; ?>
					value="<?php echo esc_attr( $keep['date'] ?? '' ); ?>"
				/>
			</p>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-hours"><?php esc_html_e( 'How long you worked', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-self-hours" name="gwcvt_hours" inputmode="decimal" required value="<?php echo esc_attr( $keep['hours'] ?? '' ); ?>" />
				<span class="gwcvt-form__help"><?php esc_html_e( 'For example 3.5, 3:30, or 3h 30m.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-activity"><?php esc_html_e( 'What you did', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-self-activity" name="gwcvt_activity" maxlength="200" list="gwcvt-self-activities" value="<?php echo esc_attr( $keep['activity'] ?? '' ); ?>" />
				<?php $vocabulary = gwcvt_activity_vocabulary(); ?>
				<?php if ( $vocabulary ) : ?>
					<datalist id="gwcvt-self-activities">
						<?php foreach ( $vocabulary as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				<?php endif; ?>
			</p>

			<p class="gwcvt-form__field">
				<label for="gwcvt-self-supervisor"><?php esc_html_e( 'Who supervised you', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-self-supervisor" name="gwcvt_supervisor" maxlength="100" value="<?php echo esc_attr( $keep['supervisor'] ?? '' ); ?>" />
			</p>

			<?php if ( '' !== $code ) : ?>
				<p class="gwcvt-form__field">
					<label for="gwcvt-self-code"><?php esc_html_e( 'Code from the front desk', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-self-code" name="gwcvt_code" autocomplete="off" required value="" />
				</p>
			<?php endif; ?>

			<p class="gwcvt-form__actions">
				<button type="submit" name="gwcvt_log_hours" value="1"><?php esc_html_e( 'Send my hours', 'groundwork-common-volunteer-tracker' ); ?></button>
			</p>

			<p class="gwcvt-form__note">
				<?php esc_html_e( 'Hours you send here are checked by staff before they count towards anything. Nothing you enter appears publicly.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * What was submitted, cleaned, for handing back on a failed attempt.
 *
 * @return array<string, string>
 */
function gwcvt_submitted_values(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; these values are only echoed back into the form the visitor just posted, and every one is escaped at output.
	$posted = wp_unslash( $_POST );

	if ( ! isset( $posted['gwcvt_log_hours'] ) ) {
		return array();
	}

	return array(
		'name'       => mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_name'] ?? '' ) ), 0, 100 ),
		'email'      => sanitize_email( (string) ( $posted['gwcvt_email'] ?? '' ) ),
		'date'       => gwcvt_sanitize_date( sanitize_text_field( (string) ( $posted['gwcvt_date'] ?? '' ) ) ),
		'hours'      => mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_hours'] ?? '' ) ), 0, 20 ),
		'activity'   => mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_activity'] ?? '' ) ), 0, 200 ),
		'supervisor' => mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_supervisor'] ?? '' ) ), 0, 100 ),
	);
}

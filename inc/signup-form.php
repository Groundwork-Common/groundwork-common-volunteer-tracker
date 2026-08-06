<?php
/**
 * What a visitor sees: the shifts, the form, and their own booking.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Counts, never names ─────────────────────────────────────────────────────
 * The single rule this file exists to keep. A visitor may see that Saturday
 * exists, what the work is, where it is, and that three places are left. They
 * may never see who else is coming.
 *
 * On a site running a court-ordered service programme, the roster for Saturday
 * is a list of people working one off. A place count says nothing about anybody;
 * a first name says everything about one person. There is no toggle for this and
 * there should not be one — a site that wanted to publish its roster would be
 * publishing something it does not have the right to publish.
 *
 * ── One form, no JavaScript ─────────────────────────────────────────────────
 * Every shift is a radio inside one form, with the name and address at the
 * bottom. The alternative — a form per shift, or a button that reveals one —
 * means either a dozen nonces on a page or a feature that does nothing with
 * JavaScript switched off. A radio list is plain HTML, works in every reader,
 * and is one nonce.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The whole public surface: a booking to manage, or the list and the form.
 *
 * @return string Empty when signing up is switched off, so a page that still
 *                has the block on it renders nothing rather than something
 *                broken.
 */
function gwcvt_render_shift_list(): string {
	if ( ! gwcvt_signups_open() ) {
		return '';
	}

	$manage = gwcvt_render_signup_manage();

	if ( '' !== $manage ) {
		return $manage;
	}

	$result   = (string) ( $GLOBALS['gwcvt_signup_result'] ?? '' );
	$shifts   = gwcvt_public_shift_ids();
	$code     = (string) gwcvt_setting( 'signup_code' );
	$selected = 0;

	ob_start();
	?>
	<div class="gwcvt-shifts">
		<?php if ( '' !== $result ) : ?>
			<p class="gwcvt-shifts__message" role="status"><?php echo esc_html( gwcvt_signup_message( $result ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! $shifts ) : ?>
			<p class="gwcvt-shifts__empty">
				<?php esc_html_e( 'There is nothing on the calendar just now. Please check back, or get in touch.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
			</div>
			<?php
			return (string) ob_get_clean();
		endif;
		?>

		<form method="post" class="gwcvt-shifts__form">
			<?php wp_nonce_field( 'gwcvt_signup', 'gwcvt_signup_nonce' ); ?>
			<input type="hidden" name="gwcvt_t" value="<?php echo esc_attr( gwcvt_form_stamp() ); ?>" />

			<fieldset class="gwcvt-shifts__list">
				<legend><?php esc_html_e( 'Choose a shift', 'groundwork-common-volunteer-tracker' ); ?></legend>

				<?php foreach ( $shifts as $shift_id ) : ?>
					<?php gwcvt_render_shift_choice( $shift_id, $selected ); ?>
				<?php endforeach; ?>
			</fieldset>

			<div class="gwcvt-shifts__field">
				<label for="gwcvt-signup-name"><?php esc_html_e( 'Your name', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-signup-name" name="gwcvt_name" required maxlength="100" autocomplete="name" />
			</div>

			<div class="gwcvt-shifts__field">
				<label for="gwcvt-signup-email"><?php esc_html_e( 'Your email address', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="email" id="gwcvt-signup-email" name="gwcvt_email" required maxlength="200" autocomplete="email" />
				<p class="gwcvt-shifts__help">
					<?php esc_html_e( 'So we can send you the details and a link to cancel if you need to. It does not create an account.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			</div>

			<?php if ( '' !== $code ) : ?>
				<div class="gwcvt-shifts__field">
					<label for="gwcvt-signup-code"><?php esc_html_e( 'The code you were given', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-signup-code" name="gwcvt_code" maxlength="50" autocomplete="off" />
				</div>
			<?php endif; ?>

			<?php
			/* The honeypot. A real text input in an off-screen wrapper rather than
			 * type="hidden" or an inline display:none, both of which the scripts
			 * worth stopping already skip. Nobody sees it; anything that fills it
			 * in gets the same answer as a successful signup. */
			?>
			<div class="gwcvt-shifts__hp" aria-hidden="true">
				<label for="gwcvt-signup-website"><?php esc_html_e( 'Leave this field empty', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-signup-website" name="gwcvt_website" tabindex="-1" autocomplete="off" />
			</div>

			<p>
				<button type="submit" name="gwcvt_signup_submit" value="1" class="gwcvt-shifts__button">
					<?php esc_html_e( 'Sign me up', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * One shift, as something a visitor can choose.
 *
 * @param int $shift_id Shift post ID.
 * @param int $selected Which shift is preselected, if any.
 */
function gwcvt_render_shift_choice( int $shift_id, int $selected ): void {
	$spots  = gwcvt_shift_spots_left( $shift_id );
	$full   = null !== $spots && $spots < 1;
	$notes  = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_NOTES, true ) );
	$where  = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) );
	$what   = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ) );
	$row_id = 'gwcvt-shift-' . $shift_id;
	?>
	<div class="gwcvt-shift<?php echo $full ? ' gwcvt-shift--full' : ''; ?>">
		<input
			type="radio"
			id="<?php echo esc_attr( $row_id ); ?>"
			name="gwcvt_shift"
			value="<?php echo esc_attr( (string) $shift_id ); ?>"
			<?php checked( $selected, $shift_id ); ?>
			required
		/>
		<label for="<?php echo esc_attr( $row_id ); ?>">
			<span class="gwcvt-shift__when">
				<?php echo esc_html( gwcvt_shift_date_label( $shift_id ) ); ?>,
				<?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?>
			</span>

			<?php if ( '' !== $what ) : ?>
				<span class="gwcvt-shift__what"><?php echo esc_html( $what ); ?></span>
			<?php endif; ?>

			<?php if ( '' !== $where ) : ?>
				<span class="gwcvt-shift__where"><?php echo esc_html( $where ); ?></span>
			<?php endif; ?>

			<span class="gwcvt-shift__places">
				<?php
				/* A count, and never a name. See the box comment at the top of
				 * this file for why there is no version of this that lists who is
				 * coming. */
				if ( $full ) {
					esc_html_e( 'Full — you can join the waiting list', 'groundwork-common-volunteer-tracker' );
				} elseif ( null !== $spots ) {
					printf(
						/* translators: %d: how many places are left. */
						esc_html( _n( '%d place left', '%d places left', $spots, 'groundwork-common-volunteer-tracker' ) ),
						(int) $spots
					);
				} else {
					esc_html_e( 'Places available', 'groundwork-common-volunteer-tracker' );
				}
				?>
			</span>

			<?php if ( '' !== $notes ) : ?>
				<span class="gwcvt-shift__notes"><?php echo esc_html( $notes ); ?></span>
			<?php endif; ?>
		</label>
	</div>
	<?php
}

/* ── Somebody's own booking ──────────────────────────────────────────────── */

/**
 * The panel somebody reaches from the link in their confirmation.
 *
 * @return string Empty when there is no valid token on the request, which is
 *                every ordinary visit.
 */
function gwcvt_render_signup_manage(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a capability URL; the token is what authorises it.
	if ( ! isset( $_GET['gwcvt_signup'] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$signup_id = absint( wp_unslash( $_GET['gwcvt_signup'] ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$token = isset( $_GET['gwcvt_k'] ) ? sanitize_text_field( wp_unslash( $_GET['gwcvt_k'] ) ) : '';

	$result = (string) ( $GLOBALS['gwcvt_signup_result'] ?? '' );

	/* An answered cancellation, or a link that no longer works. Both end here
	 * with a sentence and no further controls — and a stale token and a deleted
	 * signup deliberately produce the same one, because telling them apart would
	 * turn this URL into a way to ask which signup IDs are real. */
	if ( 'cancelled' === $result || 'cancel-unknown' === $result || ! gwcvt_signup_token_valid( $signup_id, $token ) ) {
		return sprintf(
			'<div class="gwcvt-shifts"><p class="gwcvt-shifts__message" role="status">%s</p></div>',
			esc_html( gwcvt_signup_message( '' !== $result ? $result : 'cancel-unknown' ) )
		);
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	if ( GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return sprintf(
			'<div class="gwcvt-shifts"><p class="gwcvt-shifts__message" role="status">%s</p></div>',
			esc_html( gwcvt_signup_message( 'cancel-unknown' ) )
		);
	}

	$withdrawn = GWCVT_SIGNUP_WITHDRAWN === get_post_status( $signup_id );
	$waiting   = GWCVT_SIGNUP_WAITLIST === get_post_status( $signup_id );

	ob_start();
	?>
	<div class="gwcvt-shifts gwcvt-shifts--manage">
		<h2 class="gwcvt-shifts__heading"><?php esc_html_e( 'Your shift', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<div class="gwcvt-shift gwcvt-shift--mine">
			<span class="gwcvt-shift__when">
				<?php echo esc_html( gwcvt_shift_date_label( $shift_id ) ); ?>,
				<?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?>
			</span>
			<span class="gwcvt-shift__what"><?php echo esc_html( (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ) ); ?></span>

			<?php $where = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) ); ?>
			<?php if ( '' !== $where ) : ?>
				<span class="gwcvt-shift__where"><?php echo esc_html( $where ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $withdrawn ) : ?>
			<p class="gwcvt-shifts__message">
				<?php esc_html_e( 'You are not on this shift. If that is wrong, please sign up again below or get in touch.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php else : ?>
			<?php if ( $waiting ) : ?>
				<p class="gwcvt-shifts__message">
					<?php esc_html_e( 'This shift is full, so you are on the waiting list. We will be in touch if a place comes free.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			<?php endif; ?>

			<p>
				<a class="gwcvt-shifts__button gwcvt-shifts__button--quiet" href="<?php echo esc_url( gwcvt_signup_ics_url( $signup_id ) ); ?>">
					<?php esc_html_e( 'Add it to your calendar', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>

			<?php
			/* ── The cancel button is a POST, and that is not a formality ──────
			 * The link that reaches this page arrives in an email. Mail clients
			 * prefetch links, security appliances follow them to check where they
			 * go, and previews fetch them to build a thumbnail. A GET that
			 * withdrew a signup would eventually be withdrawn by a spam filter,
			 * and the volunteer would find out by not being expected. */
			?>
			<form method="post" class="gwcvt-shifts__cancel">
				<?php wp_nonce_field( 'gwcvt_cancel_signup', 'gwcvt_cancel_nonce' ); ?>
				<input type="hidden" name="gwcvt_signup" value="<?php echo esc_attr( (string) $signup_id ); ?>" />
				<input type="hidden" name="gwcvt_k" value="<?php echo esc_attr( $token ); ?>" />

				<p><?php esc_html_e( 'Cannot make it any more?', 'groundwork-common-volunteer-tracker' ); ?></p>

				<?php if ( gwcvt_event_for_shift( $shift_id ) > 0 ) : ?>
					<?php
					/* Said before the button, not after it. A token authorises one
					 * slot and no more — widening it to everything this address
					 * holds would mean a lookup keyed on an email address, and a
					 * forwarded confirmation would then disclose the lot. The cost
					 * of that narrowness is that somebody holding three places
					 * cannot tell what this button will do, so it is spelled out. */
					?>
					<p class="gwcvt-shifts__help">
						<?php esc_html_e( 'This takes you off this one only. Anything else you signed up for stays as it is — each has its own link in your confirmation email.', 'groundwork-common-volunteer-tracker' ); ?>
					</p>
				<?php endif; ?>

				<button type="submit" name="gwcvt_cancel_submit" value="1" class="gwcvt-shifts__button gwcvt-shifts__button--quiet">
					<?php esc_html_e( 'Cancel my place', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php
		/* A way back, because cancelling was otherwise a dead end: somebody who
		 * picked the wrong time had nowhere to go. Both destinations are already
		 * public, so this discloses nothing — it just saves them hunting for the
		 * page they came from. */
		$back = gwcvt_signup_return_url( $signup_id );

		if ( '' !== $back ) :
			?>
			<p class="gwcvt-shifts__back">
				<a href="<?php echo esc_url( $back ); ?>">
					<?php esc_html_e( 'See the other times', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * The calendar-file link for one signup.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwcvt_signup_ics_url( int $signup_id ): string {
	$page = (int) gwcvt_setting( 'schedule_page' );

	/* Empty rather than the home page — see the note on
	 * gwcvt_signup_manage_url(), which this mirrors. */
	if ( $page < 1 ) {
		return '';
	}

	return add_query_arg(
		array(
			'gwcvt_ics' => $signup_id,
			'gwcvt_k'   => gwcvt_signup_token( $signup_id ),
		),
		(string) get_permalink( $page )
	);
}

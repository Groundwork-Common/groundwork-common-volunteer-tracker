<?php
/**
 * The sign-in page: ask for a link, confirm one, or see your own record.
 *
 * Three states on one page, chosen in this order:
 *
 *   1. Signed in     — the panel: your hours, your shifts, sign out.
 *   2. Holding a link — the confirmation button. Drawn for a GET carrying a
 *                       token, because following the link must not spend it.
 *   3. Neither       — the form that asks for an address.
 *
 * A near-twin of inc/registration-form.php in shape, because a visitor who has
 * used one of this plugin's public forms should not have to learn another.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The whole page, or nothing.
 *
 * @return string
 */
function gwc_vt_render_signin(): string {
	if ( ! gwc_vt_signin_enabled() ) {
		/* Silent on the front end, as the other two forms are: a visitor should
		 * never be told a feature exists but is switched off. */
		return '';
	}

	if ( ! gwc_vt_is_signin_page() ) {
		return sprintf(
			'<div class="gwcvt-form"><div class="gwcvt-form__message gwcvt-form__message--problem"><p>%s</p></div></div>',
			esc_html__( 'Signing in is set up on another page, so it cannot be done here. Please use the page your organization gave you.', 'groundwork-common-volunteer-tracker' )
		);
	}

	$result  = (string) ( $GLOBALS['gwc_vt_signin_result'] ?? '' );
	$message = '' !== $result ? gwc_vt_signin_message( $result ) : '';

	if ( gwc_vt_signed_in_volunteer() > 0 ) {
		return gwc_vt_render_signin_panel( gwc_vt_signed_in_volunteer(), $message );
	}

	/* A link in hand, not yet spent. Checked before the ask-for-a-link form so
	 * that arriving from an email lands on the button rather than on the box
	 * they just filled in. */
	$link = gwc_vt_signin_link_parts();

	if ( $link['volunteer'] > 0 && '' !== $link['token'] && gwc_vt_signin_token_valid( $link['volunteer'], $link['token'] ) ) {
		return gwc_vt_render_signin_confirm( $link['volunteer'], $link['token'] );
	}

	return gwc_vt_render_signin_request( $message );
}

/**
 * Ask for a link.
 *
 * @param string $message What the last attempt produced, if anything.
 * @return string
 */
function gwc_vt_render_signin_request( string $message ): string {
	ob_start();
	?>
	<div class="gwcvt-form">
		<?php if ( '' !== $message ) : ?>
			<?php
			/* role="status" and not "alert": every outcome of this form is the
			 * same sentence, and none of them is an emergency. */
			?>
			<div class="gwcvt-form__message gwcvt-form__message--ok" role="status">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" class="gwcvt-form__fields">
			<?php wp_nonce_field( 'gwc_vt_signin', 'gwc_vt_signin_nonce' ); ?>
			<input type="hidden" name="gwc_vt_t" value="<?php echo esc_attr( gwc_vt_form_stamp() ); ?>" />

			<div class="gwcvt-form__hp" aria-hidden="true">
				<label for="gwcvt-signin-website"><?php esc_html_e( 'Leave this field empty', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-signin-website" name="gwc_vt_website" tabindex="-1" autocomplete="off" value="" />
			</div>

			<fieldset class="gwcvt-form__group">
				<legend><?php esc_html_e( 'Sign in', 'groundwork-common-volunteer-tracker' ); ?></legend>

				<p class="gwcvt-form__help">
					<?php esc_html_e( 'There is no password. Give us the email address your organization has for you and we will send a link that signs you in.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<p class="gwcvt-form__field">
					<label for="gwcvt-signin-email"><?php esc_html_e( 'Your email', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input
						type="email"
						id="gwcvt-signin-email"
						name="gwc_vt_email"
						maxlength="200"
						required
						autocomplete="email"
					/>
				</p>
			</fieldset>

			<p class="gwcvt-form__submit">
				<button type="submit" name="gwc_vt_signin_submit" value="1">
					<?php esc_html_e( 'Email me a link', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * A link was followed. Ask before spending it.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $token        The token in the clear.
 * @return string
 */
function gwc_vt_render_signin_confirm( int $volunteer_id, string $token ): string {
	ob_start();
	?>
	<div class="gwcvt-form">
		<form method="post" class="gwcvt-form__fields">
			<?php wp_nonce_field( 'gwc_vt_signin_confirm', 'gwc_vt_signin_nonce' ); ?>
			<input type="hidden" name="gwc_vt_who" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
			<input type="hidden" name="gwc_vt_k" value="<?php echo esc_attr( $token ); ?>" />

			<fieldset class="gwcvt-form__group">
				<legend><?php esc_html_e( 'Sign in', 'groundwork-common-volunteer-tracker' ); ?></legend>

				<p>
					<?php
					printf(
						/* translators: %s: a person's name. */
						esc_html__( 'Signing in as %s.', 'groundwork-common-volunteer-tracker' ),
						'<strong>' . esc_html( get_the_title( $volunteer_id ) ) . '</strong>'
					);
					?>
				</p>

				<p class="gwcvt-form__help">
					<?php esc_html_e( 'The name is shown so you can tell you have the right link before you use it. The link works once.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			</fieldset>

			<p class="gwcvt-form__submit">
				<button type="submit" name="gwc_vt_signin_confirm" value="1">
					<?php esc_html_e( 'Sign me in', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * What a signed-in volunteer sees.
 *
 * Read-only apart from cancelling a shift, and deliberately narrow. Everything
 * here is theirs; nothing here is anybody else's.
 *
 * Note what is NOT drawn: how many hours a court or a school required of them.
 * inc/volunteer-cpt.php argues that what was ordered is a fact about somebody
 * else's document rather than about anything this organization observed, and
 * keeps it off every outward-facing surface. This is a new outward-facing
 * surface, and putting it here would be a decision, not a detail.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $message      What the last action produced, if anything.
 * @return string
 */
function gwc_vt_render_signin_panel( int $volunteer_id, string $message ): string {
	$totals = gwc_vt_volunteer_totals( $volunteer_id );

	ob_start();
	?>
	<div class="gwcvt-form gwcvt-mine">
		<?php if ( '' !== $message ) : ?>
			<div class="gwcvt-form__message gwcvt-form__message--ok" role="status">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endif; ?>

		<h2 class="gwcvt-mine__heading">
			<?php
			printf(
				/* translators: %s: a person's name. */
				esc_html__( 'Hello, %s', 'groundwork-common-volunteer-tracker' ),
				esc_html( get_the_title( $volunteer_id ) )
			);
			?>
		</h2>

		<p class="gwcvt-mine__hours">
			<?php
			printf(
				/* translators: %s: a duration, already formatted, so the sentence must not add a unit of its own. */
				esc_html__( '%s verified so far.', 'groundwork-common-volunteer-tracker' ),
				esc_html( gwc_vt_format_hours( $totals->verified_minutes ) )
			);
			?>
			<?php if ( $totals->pending_minutes > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: a duration, already formatted. */
					esc_html__( 'A further %s is recorded and waiting for a staff member to check it.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_format_hours( $totals->pending_minutes ) )
				);
				?>
			<?php endif; ?>
		</p>

		<?php gwc_vt_render_my_shifts( $volunteer_id ); ?>

		<form method="post" class="gwcvt-mine__out">
			<?php wp_nonce_field( 'gwc_vt_signout', 'gwc_vt_signout_nonce' ); ?>
			<button type="submit" name="gwc_vt_signout_submit" value="1" class="gwcvt-shifts__button gwcvt-shifts__button--quiet">
				<?php esc_html_e( 'Sign out', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * The shifts this volunteer is down for, soonest first.
 *
 * Only what is still to come. A list that also carried last spring would bury
 * the thing somebody opened this page to check.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_my_shifts( int $volunteer_id ): void {
	$mine = array();

	foreach ( gwc_vt_signup_ids_for_volunteer( $volunteer_id ) as $signup_id ) {
		$shift_id = (int) wp_get_post_parent_id( (int) $signup_id );

		if ( $shift_id < 1 || gwc_vt_shift_has_ended( $shift_id ) ) {
			continue;
		}

		$mine[] = array(
			'signup' => (int) $signup_id,
			'shift'  => $shift_id,
			'date'   => (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ),
		);
	}

	usort(
		$mine,
		static function ( array $a, array $b ): int {
			return strcmp( $a['date'], $b['date'] );
		}
	);
	?>
	<h3 class="gwcvt-mine__heading"><?php esc_html_e( 'What you are down for', 'groundwork-common-volunteer-tracker' ); ?></h3>

	<?php if ( ! $mine ) : ?>
		<p class="gwcvt-form__help"><?php esc_html_e( 'Nothing coming up.', 'groundwork-common-volunteer-tracker' ); ?></p>
	<?php else : ?>

	<ul class="gwcvt-mine__shifts">
		<?php foreach ( $mine as $row ) : ?>
			<li>
				<strong><?php echo esc_html( gwc_vt_shift_date_label( $row['shift'] ) ); ?></strong>
				<?php
				$what  = (string) get_post_meta( $row['shift'], GWC_VT_SHIFT_ACTIVITY, true );
				$where = (string) get_post_meta( $row['shift'], GWC_VT_SHIFT_LOCATION, true );
				?>
				<?php if ( '' !== $what ) : ?>
					<span class="gwcvt-mine__what"><?php echo esc_html( $what ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $where ) : ?>
					<span class="gwcvt-mine__where"><?php echo esc_html( $where ); ?></span>
				<?php endif; ?>

				<?php
				/* The manage link that already exists, reused rather than
				 * rebuilt: gwc_vt_signup_manage_url() carries the same signed
				 * token the confirmation email carries, and lands on the same
				 * confirm-then-post flow on the schedule page. Building a second
				 * route to the same action would be a second thing to keep in
				 * step with gwc_vt_signup_token()'s revision bumping.
				 *
				 * It returns '' when no schedule page is pinned, and the link is
				 * simply not drawn then — the same thing the confirmation email
				 * does with it. */
				$manage = gwc_vt_signup_manage_url( $row['signup'] );
				?>
				<?php if ( '' !== $manage ) : ?>
					<a href="<?php echo esc_url( $manage ); ?>">
						<?php esc_html_e( 'Change or cancel this one', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<?php
}

/**
 * The shortcode.
 *
 * @return string
 */
function gwc_vt_signin_shortcode(): string {
	$out = gwc_vt_render_signin();

	if ( '' !== $out ) {
		wp_enqueue_style( 'gwc-vt-form' );
	}

	return $out;
}

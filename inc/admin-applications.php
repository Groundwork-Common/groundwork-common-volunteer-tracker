<?php
/**
 * The queue where an offer to volunteer becomes a volunteer.
 *
 * ── The one screen that creates an identity record from a stranger ───────────
 * inc/application-cpt.php explains why a public form cannot make a volunteer.
 * This is the other half: the button a person presses to say yes. It sits
 * behind the same capability as editing a volunteer, it is the only route from
 * an application to a gwc_vt_volunteer, and it is the only place in this file
 * that writes one.
 *
 * Accepting copies the claims onto a real record and marks the application
 * approved rather than deleting it, so the organization can still say where a
 * volunteer came from and what they originally told it. Discarding sets a
 * status rather than trashing, so "we said no" stays distinguishable from "this
 * was never here" — and so the trash's own thirty-day schedule does not decide
 * the retention policy.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'gwc_vt_register_applications_screen', 14 );
add_action( 'admin_post_gwc_vt_approve_application', 'gwc_vt_handle_approve_application' );
add_action( 'admin_post_gwc_vt_discard_application', 'gwc_vt_handle_discard_application' );


/**
 * Register the screen, and hide it when the feature is off.
 *
 * Registered either way. An organization that switches self-registration off
 * with offers still in the queue must still be able to reach them — the
 * alternative is a screen that vanishes holding somebody's personal details,
 * with no way to discard them and nothing saying they are still there.
 */
function gwc_vt_register_applications_screen(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_applications_title(),
		gwc_vt_applications_menu_label(),
		gwc_vt_records_cap(),
		GWC_VT_APPLICATIONS_PAGE,
		'gwc_vt_render_applications_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_applications_title' );
	}
}

/**
 * The screen's title.
 *
 * @return string
 */
function gwc_vt_applications_title(): string {
	return __( 'Applications', 'groundwork-common-volunteer-tracker' );
}

/**
 * The menu entry, with a count when anything is waiting.
 *
 * The bubble is how somebody finds out an offer arrived at all. Nothing emails
 * them — a plugin that started sending mail to an address it found in the
 * options table because a feature was switched on would be doing something
 * nobody asked for — so the menu has to carry it.
 *
 * @return string
 */
function gwc_vt_applications_menu_label(): string {
	$waiting = gwc_vt_pending_application_count();

	if ( $waiting < 1 ) {
		return gwc_vt_applications_title();
	}

	return sprintf(
		/* translators: 1: the menu label, 2: how many applications are waiting. */
		__( '%1$s %2$s', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_applications_title(),
		sprintf(
			'<span class="awaiting-mod"><span class="pending-count">%s</span></span>',
			esc_html( number_format_i18n( $waiting ) )
		)
	);
}

/**
 * Put the title back after the menu label's markup replaces it.
 *
 * Get_admin_page_title() would otherwise return the label with the count bubble
 * inside it, and the <h1> would carry a stray span. The same shape as the other
 * screens whose menu entry and title differ.
 */
function gwc_vt_restore_applications_title(): void {
	if ( ! empty( $GLOBALS['title'] ) && gwc_vt_applications_title() === $GLOBALS['title'] ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen.
	$GLOBALS['title'] = gwc_vt_applications_title();
}

/**
 * A nonced URL for accepting or discarding one offer.
 *
 * The nonce action carries the application ID, so one minted for one offer
 * cannot be replayed against another.
 *
 * @param string $action         admin_post action name.
 * @param int    $application_id Application post ID.
 * @return string
 */
function gwc_vt_application_action_url( string $action, int $application_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'      => $action,
				'application' => $application_id,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $application_id
	);
}

/**
 * The queue.
 */
function gwc_vt_render_applications_screen(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to see volunteer applications.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$waiting = gwc_vt_pending_application_ids();
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_applications_title() ); ?></h1>

		<?php gwc_vt_applications_notice(); ?>

		<?php if ( ! gwc_vt_registration_enabled() ) : ?>
			<div class="notice notice-info">
				<p>
					<?php esc_html_e( 'The form that feeds this queue is switched off, so nothing new will arrive. Anything already here is still yours to deal with.', 'groundwork-common-volunteer-tracker' ); ?>
					<a href="<?php echo esc_url( gwc_vt_settings_url( 'logging' ) ); ?>">
						<?php esc_html_e( 'Settings → Logging', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! $waiting ) : ?>
			<p class="description">
				<?php esc_html_e( 'Nobody is waiting. Applications sent through the form on your site appear here, and nothing becomes a volunteer record until you say so.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php else : ?>

		<p class="description">
			<?php esc_html_e( 'Oldest first — somebody who applied three weeks ago and heard nothing is who this queue is for. Nothing here is a volunteer record yet, and none of it appears anywhere else on your site.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<table class="widefat striped gwcvt-offers">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Who', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What they said', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Applied', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $waiting as $application_id ) : ?>
					<?php gwc_vt_render_application_row( gwc_vt_application_record( (int) $application_id ) ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * One offer.
 *
 * @param array $offer From gwc_vt_application_record().
 */
function gwc_vt_render_application_row( array $offer ): void {
	if ( ! $offer ) {
		return;
	}
	?>
	<tr>
		<td>
			<?php if ( gwc_vt_has_photo( $offer['id'] ) ) : ?>
				<?php
				/* alt is empty on purpose: the name is right beside it, and a
				 * screen reader announcing "photograph of Rosalind Achebe" next
				 * to a heading reading Rosalind Achebe says it twice. */
				?>
				<img
					class="gwcvt-offers__photo"
					src="<?php echo esc_url( gwc_vt_photo_url( $offer['id'] ) ); ?>"
					alt=""
					width="72"
					height="72"
				/><br />
			<?php endif; ?>
			<strong><?php echo esc_html( $offer['name'] ); ?></strong><br />
			<?php if ( '' !== $offer['email'] ) : ?>
				<a href="<?php echo esc_url( 'mailto:' . $offer['email'] ); ?>"><?php echo esc_html( $offer['email'] ); ?></a><br />
			<?php endif; ?>
			<?php if ( '' !== $offer['phone'] ) : ?>
				<span class="description"><?php echo esc_html( $offer['phone'] ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<?php if ( '' !== $offer['note'] ) : ?>
				<p><?php echo esc_html( $offer['note'] ); ?></p>
			<?php endif; ?>

			<?php if ( $offer['required'] > 0 ) : ?>
				<?php
				/* Said plainly, because it is the thing on this row that changes
				 * how the conversation goes — and because a coordinator who
				 * misses it plans a volunteer's Saturdays around nothing in
				 * particular while a deadline runs out. */
				?>
				<?php
				/* A label and a value rather than a sentence. gwc_vt_format_hours()
				 * respects the site's hour_format, so it returns "40" on one site
				 * and "40h 00m" on another — and any sentence with a unit in it
				 * reads "40h 00m hours" on the second. The letter solves this the
				 * same way, with "%s of verified time"; here there is no need for
				 * a sentence at all. */
				$said = array( gwc_vt_format_hours( $offer['required'] ) );

				if ( '' !== $offer['required_by'] ) {
					$said[] = sprintf(
						/* translators: %s: a date. */
						__( 'due %s', 'groundwork-common-volunteer-tracker' ),
						gwc_vt_display_date( $offer['required_by'] )
					);
				}

				if ( '' !== $offer['required_for'] ) {
					$said[] = $offer['required_for'];
				}
				?>
				<p class="gwcvt-offers__required">
					<strong><?php esc_html_e( 'Says they have required service:', 'groundwork-common-volunteer-tracker' ); ?></strong>
					<?php echo esc_html( implode( ' · ', $said ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' === $offer['note'] && $offer['required'] < 1 ) : ?>
				<span class="description"><?php esc_html_e( 'Nothing else.', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</td>
		<td><?php echo esc_html( gwc_vt_local_date( $offer['created'] ) ); ?></td>
		<td class="gwcvt-offers__actions">
			<a class="button button-primary" href="<?php echo esc_url( gwc_vt_application_action_url( 'gwc_vt_approve_application', $offer['id'] ) ); ?>">
				<?php esc_html_e( 'Add as a volunteer', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( gwc_vt_application_action_url( 'gwc_vt_discard_application', $offer['id'] ) ); ?>">
				<?php esc_html_e( 'Discard', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</td>
	</tr>
	<?php
}

/**
 * Turn an offer into a volunteer record.
 *
 * The only route from an application to a gwc_vt_volunteer, and it runs only
 * when a person with the capability clicks it.
 */
function gwc_vt_handle_approve_application(): void {
	$application_id = gwc_vt_application_request( 'gwc_vt_approve_application' );
	$offer          = gwc_vt_application_record( $application_id );

	if ( ! $offer || 'pending' !== $offer['status'] ) {
		gwc_vt_applications_redirect( 'gone' );
	}

	if ( '' === trim( $offer['name'] ) ) {
		gwc_vt_applications_redirect( 'no-name' );
	}

	$volunteer_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $offer['name'],
		)
	);

	if ( is_wp_error( $volunteer_id ) || ! $volunteer_id ) {
		gwc_vt_applications_redirect( 'failed' );
	}

	$volunteer_id = (int) $volunteer_id;

	if ( '' !== $offer['email'] && is_email( $offer['email'] ) ) {
		update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, $offer['email'] );
	}

	if ( '' !== $offer['phone'] ) {
		update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHONE, $offer['phone'] );
	}

	/* The requirement carries over only if they gave one. Written through the
	 * same three keys the volunteer meta box writes, so a record created here is
	 * indistinguishable from one typed in by hand — the point of the queue is
	 * that what comes out of it is an ordinary volunteer record. */
	if ( $offer['required'] > 0 ) {
		update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED, $offer['required'] );

		if ( '' !== $offer['required_by'] ) {
			update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_BY, $offer['required_by'] );
		}

		if ( '' !== $offer['required_for'] ) {
			update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_FOR, $offer['required_for'] );
		}
	}

	/* The photograph moves rather than being copied. Two files of the same face
	 * would be two things to delete on an erasure request and two chances to
	 * miss one — and the offer's copy has done its job the moment the record
	 * exists. Moved by handing the record the filename and clearing the offer's,
	 * so nothing is re-encoded and no second file is ever written. */
	$photo = gwc_vt_photo_file( $application_id );

	if ( '' !== $photo ) {
		update_post_meta( $volunteer_id, GWC_VT_PHOTO_KEY, $photo );
		delete_post_meta( $application_id, GWC_VT_PHOTO_KEY );
	}

	/* Approved rather than deleted. The organization can still say where a
	 * volunteer came from and what they originally wrote, which is the answer to
	 * "who added this person and on what basis". */
	wp_update_post(
		array(
			'ID'          => $application_id,
			'post_status' => 'publish',
		)
	);

	update_post_meta( $application_id, GWC_VT_APPLICATION_APPROVED, $volunteer_id );

	/**
	 * Fires after an offer has become a volunteer record.
	 *
	 * @param int $volunteer_id   The new volunteer.
	 * @param int $application_id The offer it came from.
	 */
	do_action( 'gwc_vt_application_approved', $volunteer_id, $application_id );

	gwc_vt_applications_redirect( 'approved', $volunteer_id );
}

/**
 * Set an offer aside without acting on it.
 */
function gwc_vt_handle_discard_application(): void {
	$application_id = gwc_vt_application_request( 'gwc_vt_discard_application' );
	$offer          = gwc_vt_application_record( $application_id );

	if ( ! $offer || 'pending' !== $offer['status'] ) {
		gwc_vt_applications_redirect( 'gone' );
	}

	gwc_vt_discard_application( $application_id );

	gwc_vt_applications_redirect( 'discarded' );
}

/**
 * Set an offer aside, without the redirect.
 *
 * Split from the handler because the handler ends in an exit and so cannot be
 * called by anything that wants to see what it did. The first version of the
 * discard test called gwc_vt_delete_photo() by hand and passed with this
 * function's own call to it deleted.
 *
 * @param int $application_id The offer.
 * @return bool
 */
function gwc_vt_discard_application( int $application_id ): bool {
	if ( GWC_VT_APPLICATION_TYPE !== get_post_type( $application_id ) ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $application_id,
			'post_status' => GWC_VT_APPLICATION_DISCARDED,
		)
	);

	/* The photograph goes, though the row stays. Discarding keeps what somebody
	 * wrote because that is the organization's record of a decision it made —
	 * but a face is not part of that decision, it is the most identifying thing
	 * on the row, and there is no version of "we said no" that needs a picture
	 * of the person it was said to. */
	gwc_vt_delete_photo( $application_id );

	/**
	 * Fires after an offer has been set aside.
	 *
	 * @param int $application_id The offer.
	 */
	do_action( 'gwc_vt_application_discarded', $application_id );

	return true;
}

/**
 * The offer an action was asked about, once the caller is allowed to ask.
 *
 * @param string $action The admin_post action, which is also the nonce action.
 * @return int
 */
function gwc_vt_application_request( string $action ): int {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$application_id = isset( $_GET['application'] ) ? absint( wp_unslash( $_GET['application'] ) ) : 0;

	check_admin_referer( $action . '_' . $application_id );

	return $application_id;
}

/**
 * Back to the queue with a result.
 *
 * @param string $result       What happened.
 * @param int    $volunteer_id Optional. The record that was created.
 */
function gwc_vt_applications_redirect( string $result, int $volunteer_id = 0 ): void {
	$args = array( 'gwc_vt_offer' => $result );

	if ( $volunteer_id > 0 ) {
		$args['gwc_vt_volunteer'] = $volunteer_id;
	}

	wp_safe_redirect(
		add_query_arg(
			$args,
			add_query_arg(
				array(
					'post_type' => GWC_VT_ENTRY_TYPE,
					'page'      => GWC_VT_APPLICATIONS_PAGE,
				),
				admin_url( 'edit.php' )
			)
		)
	);
	exit;
}

/**
 * Say what the last action did.
 */
function gwc_vt_applications_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwc_vt_offer'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_offer'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	$errors = array(
		'gone'    => __( 'That application had already been dealt with. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
		'no-name' => __( 'That application has no name on it, so there is nothing to create a record under. Discard it instead.', 'groundwork-common-volunteer-tracker' ),
		'failed'  => __( 'The volunteer record could not be created. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $errors[ $result ] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $result ] ) );
		return;
	}

	if ( 'discarded' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Application discarded. It is off this list, and what they sent is kept until your retention policy removes it.', 'groundwork-common-volunteer-tracker' )
		);

		return;
	}

	if ( 'approved' !== $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$volunteer_id = isset( $_GET['gwc_vt_volunteer'] ) ? absint( wp_unslash( $_GET['gwc_vt_volunteer'] ) ) : 0;
	$link         = $volunteer_id > 0 ? (string) get_edit_post_link( $volunteer_id ) : '';

	/* The link matters more than the sentence. Somebody who has just accepted an
	 * offer is about to want the record — to add a note, set a requirement they
	 * were told about on the phone, or put them on Saturday. */
	printf(
		'<div class="notice notice-success is-dismissible"><p>%1$s %2$s</p></div>',
		esc_html__( 'Added as a volunteer.', 'groundwork-common-volunteer-tracker' ),
		'' !== $link
			? sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $link ),
				esc_html__( 'Open their record', 'groundwork-common-volunteer-tracker' )
			)
			: ''
	);
}

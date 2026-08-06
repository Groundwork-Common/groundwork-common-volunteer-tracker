<?php
/**
 * Calling a time off, putting it back, and dropping a role.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwcvt_call_off_slot', 'gwcvt_handle_call_off_slot' );
add_action( 'admin_post_gwcvt_restore_slot', 'gwcvt_handle_restore_slot' );
add_action( 'admin_post_gwcvt_delete_slot', 'gwcvt_handle_delete_slot' );
add_action( 'admin_post_gwcvt_drop_role', 'gwcvt_handle_drop_role' );

/* ── Why these are actions and not fields on the grid ────────────────────────
 * They were checkboxes on the editor's one big form, and every UX defect this
 * feature shipped was a symptom of that.
 *
 * The tell was in the copy. The role control read "all 3 of its times go WHEN
 * YOU SAVE". A sentence like that only has to exist because the action is
 * deferred — an immediate action does not predict the future, it reports what it
 * did. And because the whole screen re-rendered on save, a time that had just
 * been cancelled came back looking like a time that had not: same editable
 * fields, same checkbox, one word changed in one column. A coordinator ticked
 * it, saved, saw no difference and concluded it was broken. It was not.
 *
 * inc/admin-shift.php already had the right shape and this file follows it: one
 * button per operation, its own nonce, done at once, and a notice afterwards
 * that names what changed.
 *
 * ── Which are immediate and which stop to ask ────────────────────────────────
 * Reversible or harmless things happen on a nonced link, exactly as taking
 * somebody off a roster does: putting a called-off time back on, and deleting a
 * time nobody is on.
 *
 * The two that cost somebody something stop to ask, because both need a REASON
 * typed and both decide whether thirty people get an email — neither of which
 * fits in a link. Calling off a time people are on, and dropping a whole role.
 *
 * A GET that mutates is fine here and is not fine on the public side. The rule
 * in inc/signup-handler.php is about links that arrive in email, where a mail
 * client's prefetch would follow them. These are nonced, behind a capability,
 * and reached from a screen.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The link that calls one time off, or asks first.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwcvt_call_off_slot_url( int $shift_id ): string {
	return gwcvt_schedule_url(
		array(
			'gwcvt_event' => gwcvt_event_for_shift( $shift_id ),
			'view'        => 'call-off',
			'slot'        => $shift_id,
		)
	);
}

/**
 * The link that drops one role, after asking.
 *
 * @param int    $event_id Event post ID.
 * @param string $role     The role's name.
 * @return string
 */
function gwcvt_drop_role_url( int $event_id, string $role ): string {
	return gwcvt_schedule_url(
		array(
			'gwcvt_event' => $event_id,
			'view'        => 'drop-role',
			'role'        => rawurlencode( $role ),
		)
	);
}

/**
 * A nonced link that does one thing to one time at once.
 *
 * @param string $action Which admin_post action.
 * @param int    $shift_id Shift post ID.
 * @return string
 */
function gwcvt_slot_action_url( string $action, int $shift_id ): string {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=' . rawurlencode( $action ) . '&gwcvt_slot=' . $shift_id ),
		$action . '_' . $shift_id
	);
}

/* ── Asking ──────────────────────────────────────────────────────────────── */

/**
 * "Call this time off?" — the whole screen, one decision on it.
 *
 * @param int $shift_id Shift post ID.
 */
function gwcvt_render_call_off_slot( int $shift_id ): void {
	$event_id = gwcvt_event_for_shift( $shift_id );
	$roster   = gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) );
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Call off a time', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<p>
			<?php
			printf(
				/* translators: 1: a role and time, 2: an event's name. */
				esc_html__( 'You are about to call off %1$s, part of %2$s.', 'groundwork-common-volunteer-tracker' ),
				'<strong>' . esc_html( gwcvt_slot_label( $shift_id ) ) . '</strong>',
				'<strong>' . esc_html( gwcvt_event_name( $event_id ) ) . '</strong>'
			);
			?>
		</p>

		<?php if ( $roster ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						esc_html(
							/* translators: %d: how many people are on it. */
							_n(
								'%d person is on it. The time stays on the schedule marked as called off, rather than being deleted.',
								'%d people are on it. The time stays on the schedule marked as called off, rather than being deleted.',
								count( $roster ),
								'groundwork-common-volunteer-tracker'
							)
						),
						(int) count( $roster )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwcvt_call_off_slot" />
			<input type="hidden" name="gwcvt_slot" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<?php wp_nonce_field( 'gwcvt_call_off_slot_' . $shift_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-call-off-reason"><?php esc_html_e( 'Why', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-call-off-reason" name="gwcvt_reason" class="regular-text" maxlength="300" />
							<p class="description"><?php esc_html_e( 'Shown on the schedule, and in the email if you send one.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<?php if ( $roster ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Telling people', 'groundwork-common-volunteer-tracker' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="gwcvt_notify" value="1" checked />
									<?php
									printf(
										esc_html(
											/* translators: %d: how many people are on it. */
											_n( 'Email the %d person who signed up', 'Email the %d people who signed up', count( $roster ), 'groundwork-common-volunteer-tracker' )
										),
										(int) count( $roster )
									);
									?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Call it off', 'groundwork-common-volunteer-tracker' ); ?></button>
				<a class="button" href="<?php echo esc_url( gwcvt_event_edit_url( $event_id ) ); ?>"><?php esc_html_e( 'Leave it alone', 'groundwork-common-volunteer-tracker' ); ?></a>
			</p>
		</form>
	</div>
	<?php
}

/**
 * "Drop this role?" — what goes, and what merely stops.
 *
 * @param int    $event_id Event post ID.
 * @param string $role     The role's name.
 */
function gwcvt_render_drop_role( int $event_id, string $role ): void {
	$roles = gwcvt_event_roles( $event_id, array( 'publish', 'draft' ) );
	$slots = $roles[ $role ] ?? array();

	$busy = array();
	$idle = array();

	foreach ( $slots as $shift_id ) {
		if ( gwcvt_shift_signup_ids( (int) $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) ) {
			$busy[] = (int) $shift_id;
			continue;
		}

		$idle[] = (int) $shift_id;
	}
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Drop a role', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php if ( ! $slots ) : ?>
			<p><?php esc_html_e( 'That role has no times left on it.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( gwcvt_event_edit_url( $event_id ) ); ?>"><?php esc_html_e( 'Back to the event', 'groundwork-common-volunteer-tracker' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>

		<p>
			<?php
			printf(
				/* translators: 1: a role's name, 2: an event's name. */
				esc_html__( 'You are about to drop %1$s from %2$s.', 'groundwork-common-volunteer-tracker' ),
				'<strong>' . esc_html( $role ) . '</strong>',
				'<strong>' . esc_html( gwcvt_event_name( $event_id ) ) . '</strong>'
			);
			?>
		</p>

		<?php if ( $busy ) : ?>
			<h2><?php esc_html_e( 'Called off, and kept', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'People are on these, so they stay on the schedule marked as called off rather than being deleted.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( $busy as $shift_id ) : ?>
					<li>
						<?php echo esc_html( gwcvt_shift_date_label( $shift_id ) . ' · ' . gwcvt_shift_time_label( $shift_id ) ); ?>
						— <?php echo esc_html( gwcvt_shift_fill_label( $shift_id ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $idle ) : ?>
			<h2><?php esc_html_e( 'Deleted', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Nobody is on these, so they simply go.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( $idle as $shift_id ) : ?>
					<li><?php echo esc_html( gwcvt_shift_date_label( $shift_id ) . ' · ' . gwcvt_shift_time_label( $shift_id ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwcvt_drop_role" />
			<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="gwcvt_role" value="<?php echo esc_attr( $role ); ?>" />
			<?php wp_nonce_field( 'gwcvt_drop_role_' . $event_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-drop-reason"><?php esc_html_e( 'Why', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td><input type="text" id="gwcvt-drop-reason" name="gwcvt_reason" class="regular-text" maxlength="300" /></td>
					</tr>
					<?php if ( $busy ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Telling people', 'groundwork-common-volunteer-tracker' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="gwcvt_notify" value="1" checked />
									<?php esc_html_e( 'Email everybody who was on a time this calls off', 'groundwork-common-volunteer-tracker' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Drop the role', 'groundwork-common-volunteer-tracker' ); ?></button>
				<a class="button" href="<?php echo esc_url( gwcvt_event_edit_url( $event_id ) ); ?>"><?php esc_html_e( 'Leave it alone', 'groundwork-common-volunteer-tracker' ); ?></a>
			</p>
		</form>
	</div>
	<?php
}

/* ── Doing ───────────────────────────────────────────────────────────────── */

/**
 * Call one time off.
 */
function gwcvt_handle_call_off_slot(): void {
	gwcvt_require_shift_cap( 'publish_posts' );

	$shift_id = isset( $_POST['gwcvt_slot'] ) ? absint( wp_unslash( $_POST['gwcvt_slot'] ) ) : 0;

	check_admin_referer( 'gwcvt_call_off_slot_' . $shift_id );

	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( $event_id < 1 ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$reason = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_reason'] ?? '' ) ), 0, 300 );
	$notify = ! empty( $posted['gwcvt_notify'] );

	$told = gwcvt_call_off_slot( $shift_id, $reason, $notify );

	gwcvt_event_refresh_dates( $event_id );

	gwcvt_event_redirect(
		$event_id,
		'called-off-slot',
		array(
			'gwcvt_slot' => $shift_id,
			'gwcvt_told' => $told,
		)
	);
}

/**
 * Put a called-off time back on.
 */
function gwcvt_handle_restore_slot(): void {
	gwcvt_require_shift_cap( 'publish_posts' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below against this value.
	$shift_id = isset( $_GET['gwcvt_slot'] ) ? absint( wp_unslash( $_GET['gwcvt_slot'] ) ) : 0;

	check_admin_referer( 'gwcvt_restore_slot_' . $shift_id );

	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( $event_id < 1 || ! gwcvt_shift_is_cancelled( $shift_id ) ) {
		gwcvt_event_redirect( $event_id, 'unknown' );
	}

	/* It comes back with the event's own visibility rather than published
	 * outright: putting a time back on a draft event must not publish it. */
	wp_update_post(
		array(
			'ID'          => $shift_id,
			'post_status' => 'publish' === get_post_status( $event_id ) ? 'publish' : 'draft',
		)
	);

	/* The reason is left in place. That it was once called off is the
	 * organisation's own record, and somebody who was told may still be acting
	 * on what they were told. */
	gwcvt_event_refresh_dates( $event_id );

	gwcvt_event_redirect( $event_id, 'restored-slot', array( 'gwcvt_slot' => $shift_id ) );
}

/**
 * Delete a time nobody is on.
 */
function gwcvt_handle_delete_slot(): void {
	gwcvt_require_shift_cap( 'delete_posts' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below against this value.
	$shift_id = isset( $_GET['gwcvt_slot'] ) ? absint( wp_unslash( $_GET['gwcvt_slot'] ) ) : 0;

	check_admin_referer( 'gwcvt_delete_slot_' . $shift_id );

	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( $event_id < 1 ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	/* Checked here as well as hidden on the screen. The screen is not the only
	 * thing that can reach this, and a time somebody signed up for is called off
	 * rather than deleted — "it never existed" is only true of a typo. */
	if ( gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) ) {
		gwcvt_event_redirect( $event_id, 'has-roster' );
	}

	wp_delete_post( $shift_id, true );
	gwcvt_event_refresh_dates( $event_id );

	gwcvt_event_redirect( $event_id, 'deleted-slot' );
}

/**
 * Drop a whole role.
 */
function gwcvt_handle_drop_role(): void {
	gwcvt_require_shift_cap( 'publish_posts' );

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_drop_role_' . $event_id );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$role   = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_role'] ?? '' ) ), 0, 200 );
	$reason = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_reason'] ?? '' ) ), 0, 300 );
	$notify = ! empty( $posted['gwcvt_notify'] );

	$roles = gwcvt_event_roles( $event_id, array( 'publish', 'draft' ) );

	if ( ! isset( $roles[ $role ] ) ) {
		gwcvt_event_redirect( $event_id, 'unknown-role' );
	}

	$off     = 0;
	$deleted = 0;
	$told    = 0;

	/* Decided per time rather than for the role as a whole, so a role with one
	 * busy Saturday and three empty ones leaves the Saturday on the schedule,
	 * called off, and takes the other three away. */
	foreach ( $roles[ $role ] as $shift_id ) {
		if ( gwcvt_shift_signup_ids( (int) $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) ) {
			$told += gwcvt_call_off_slot( (int) $shift_id, $reason, $notify );
			++$off;
			continue;
		}

		wp_delete_post( (int) $shift_id, true );
		++$deleted;
	}

	gwcvt_event_refresh_dates( $event_id );

	/**
	 * Fires after a whole role has been dropped from an event.
	 *
	 * @param int    $event_id The event.
	 * @param string $role     The role's name.
	 * @param array  $counts   off, deleted, told.
	 */
	do_action(
		'gwcvt_event_role_dropped',
		$event_id,
		$role,
		array(
			'off'     => $off,
			'deleted' => $deleted,
			'told'    => $told,
		)
	);

	gwcvt_event_redirect(
		$event_id,
		'dropped-role',
		array(
			'gwcvt_cancelled' => $off,
			'gwcvt_deleted'   => $deleted,
			'gwcvt_told'      => $told,
		)
	);
}

/**
 * Call one time off, and tell whoever was on it.
 *
 * The one place a time is cancelled, so the roster, the reason and the mail
 * cannot drift apart between the single-time path and the whole-role one.
 *
 * @param int    $shift_id Shift post ID.
 * @param string $reason   Why, as the coordinator typed it.
 * @param bool   $notify   Whether to write to the people on it.
 * @return int How many were told.
 */
function gwcvt_call_off_slot( int $shift_id, string $reason, bool $notify ): int {
	if ( gwcvt_shift_is_cancelled( $shift_id ) ) {
		return 0;
	}

	$roster = gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) );

	wp_update_post(
		array(
			'ID'          => $shift_id,
			'post_status' => GWCVT_SHIFT_CANCELLED,
		)
	);

	update_post_meta( $shift_id, GWCVT_SHIFT_REASON, $reason );

	$told = 0;

	if ( $notify ) {
		foreach ( $roster as $signup_id ) {
			gwcvt_queue_signup_mail( 'cancelled', (int) $signup_id, array( 'reason' => $reason ) );
			++$told;
		}
	}

	/** This action is documented in inc/admin-shift.php */
	do_action( 'gwcvt_shift_cancelled', $shift_id, $reason, $roster );

	return $told;
}

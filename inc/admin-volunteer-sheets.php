<?php
/**
 * The three things you can do to a volunteer without leaving their record.
 *
 * Log hours, draft a verification letter, record a credential. Each is a sheet
 * over the record, each commits on its own, and each is reached the same way —
 * see inc/admin-sheet.php for why that mattered enough to unify.
 *
 * The letters sheet is not here: it is large, it has three siblings of its own
 * (the reader, the mailer, the poster) and it lives with the rest of the letter
 * flow in inc/admin-volunteer-letters.php. What is here is the two that had
 * nowhere else to go, and the footer that prints all of them.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_log_hours', 'gwc_vt_handle_log_hours' );
add_action( 'admin_post_gwc_vt_record_credential', 'gwc_vt_handle_record_credential' );
add_action( 'admin_footer', 'gwc_vt_render_volunteer_sheets' );


/**
 * Record one shift from the sheet.
 */
function gwc_vt_handle_log_hours(): void {
	$volunteer_id = isset( $_POST['volunteer'] ) ? absint( wp_unslash( $_POST['volunteer'] ) ) : 0;

	/* gwc_vt_records_cap(), not gwc_vt_cap() — that one knows verify, issue and
	 * manage, and none of them is "may write down somebody's hours". See the
	 * note on edit_posts being contributor-level in inc/access.php. */
	if ( ! current_user_can( gwc_vt_records_cap() ) ) {
		wp_die(
			esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'gwc_vt_log_hours_' . $volunteer_id );

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
		exit;
	}

	$posted = wp_unslash( $_POST );

	/* Parsed rather than cast, and refused rather than corrected. "two and a
	 * half" and "2:30" are both things a coordinator types, and a value this
	 * cannot read must not silently become zero hours on a record a letter is
	 * built from — which is the rule the entry editor already follows. */
	$minutes = gwc_vt_parse_hours( (string) ( $posted['hours'] ?? '' ) );

	if ( null === $minutes || $minutes < 1 ) {
		gwc_vt_sheet_redirect( $volunteer_id, 'hours-unreadable', 'gwc-vt-volunteer-hours' );
	}

	$entry_id = gwc_vt_log_one_entry(
		$volunteer_id,
		sanitize_text_field( (string) ( $posted['date'] ?? '' ) ),
		(int) $minutes,
		sanitize_text_field( (string) ( $posted['activity'] ?? '' ) ),
		sanitize_text_field( (string) ( $posted['supervisor'] ?? '' ) )
	);

	gwc_vt_sheet_redirect(
		$volunteer_id,
		$entry_id > 0 ? 'hours-logged' : 'hours-failed',
		'gwc-vt-volunteer-hours'
	);
}

/**
 * Record that somebody holds a credential.
 *
 * ── What changed by moving this into a sheet ─────────────────────────────────
 * It used to be fields inside wp-admin's own post form, written by the
 * volunteer's Update button further down the page — a form that was not a form,
 * whose own comment had to say so. Somebody who filled it in and did not scroll
 * down recorded nothing, and nothing told them.
 *
 * A sheet commits. That is the whole point of one.
 */
function gwc_vt_handle_record_credential(): void {
	$volunteer_id = isset( $_POST['volunteer'] ) ? absint( wp_unslash( $_POST['volunteer'] ) ) : 0;

	check_admin_referer( 'gwc_vt_record_credential_' . $volunteer_id );

	if ( ! gwc_vt_can_record_credentials() ) {
		wp_die(
			esc_html__( 'Recording that somebody holds a credential needs permission to verify hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not allowed', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
		exit;
	}

	$posted = wp_unslash( $_POST );

	$result = gwc_vt_record_credential(
		$volunteer_id,
		absint( $posted['gwc_vt_record_credential'] ?? 0 ),
		sanitize_text_field( (string) ( $posted['gwc_vt_record_date'] ?? '' ) )
	);

	/* The recorder says WHY it refused, and this screen used to answer every
	 * refusal with "could not be recorded" — no cause and no way out, in front
	 * of somebody who has typed a date wrong and can fix it in four seconds.
	 * Mapped by error code rather than by passing the message through the URL:
	 * the strings stay translatable and nothing arbitrary is rendered back. */
	$outcome = 'credential-recorded';

	if ( is_wp_error( $result ) || ! $result ) {
		$refusals = array(
			'gwc_vt_bad_date'      => 'credential-bad-date',
			'gwc_vt_no_credential' => 'credential-gone',
		);

		$code    = is_wp_error( $result ) ? $result->get_error_code() : '';
		$outcome = $refusals[ $code ] ?? 'credential-failed';
	}

	gwc_vt_sheet_redirect( $volunteer_id, $outcome, 'gwc-vt-volunteer-credentials' );
}


/**
 * The sheet that logs one shift.
 *
 * The four things an entry needs and nothing else. Anything beyond them —
 * attaching it to a shift, attesting to it, correcting it — is done by opening
 * the entry, which is a screen with room for it.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_log_hours_sheet( int $volunteer_id ): void {
	gwc_vt_render_sheet(
		'log-hours',
		__( 'Log hours', 'groundwork-common-volunteer-tracker' ),
		static function () use ( $volunteer_id ): void {
			?>
			<input type="hidden" form="gwcvt-log-hours" name="action" value="gwc_vt_log_hours" />
			<input type="hidden" form="gwcvt-log-hours" name="volunteer" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
			<input type="hidden" form="gwcvt-log-hours" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gwc_vt_log_hours_' . $volunteer_id ) ); ?>" />

			<p>
				<label for="gwcvt-log-date"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="date" form="gwcvt-log-hours" id="gwcvt-log-date" name="date" required
					value="<?php echo esc_attr( gwc_vt_today() ); ?>" max="<?php echo esc_attr( gwc_vt_today() ); ?>" />
			</p>

			<p>
				<label for="gwcvt-log-hours-field"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="text" form="gwcvt-log-hours" id="gwcvt-log-hours-field" name="hours" class="small-text" inputmode="decimal" required
					aria-describedby="gwcvt-log-hours-hint" />
				<span class="description" id="gwcvt-log-hours-hint"><?php esc_html_e( 'Accepts 2.5, 2:30, 2h 30m or 150m.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>

			<p>
				<label for="gwcvt-log-activity"><?php esc_html_e( 'Activity', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="text" form="gwcvt-log-hours" id="gwcvt-log-activity" name="activity" class="regular-text" />
			</p>

			<p>
				<label for="gwcvt-log-supervisor"><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="text" form="gwcvt-log-hours" id="gwcvt-log-supervisor" name="supervisor" class="regular-text" />
			</p>
			<?php
		},
		static function (): void {
			?>
			<button type="submit" form="gwcvt-log-hours" class="button button-primary"><?php esc_html_e( 'Log it', 'groundwork-common-volunteer-tracker' ); ?></button>
			<button type="button" class="button" data-gwcvt-sheet-close><?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?></button>
			<span class="description"><?php esc_html_e( 'Arrives unverified.', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php
		},
		'gwcvt-sheet--narrow'
	);
}

/**
 * The sheet that records a credential.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_record_credential_sheet( int $volunteer_id ): void {
	$live = gwc_vt_live_credential_ids();

	gwc_vt_render_sheet(
		'record-credential',
		__( 'Record a credential', 'groundwork-common-volunteer-tracker' ),
		static function () use ( $volunteer_id, $live ): void {
			?>
			<input type="hidden" form="gwcvt-record-credential-form" name="action" value="gwc_vt_record_credential" />
			<input type="hidden" form="gwcvt-record-credential-form" name="volunteer" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
			<input type="hidden" form="gwcvt-record-credential-form" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gwc_vt_record_credential_' . $volunteer_id ) ); ?>" />

			<p>
				<label for="gwcvt-record-credential"><?php esc_html_e( 'Credential', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<select form="gwcvt-record-credential-form" id="gwcvt-record-credential" name="gwc_vt_record_credential" required>
					<option value="0"><?php esc_html_e( '— choose —', 'groundwork-common-volunteer-tracker' ); ?></option>
					<?php foreach ( $live as $credential_id ) : ?>
						<?php $credential = gwc_vt_credential( (int) $credential_id ); ?>
						<?php if ( $credential ) : ?>
							<option value="<?php echo esc_attr( (string) $credential['id'] ); ?>"><?php echo esc_html( $credential['name'] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="gwcvt-record-date"><?php esc_html_e( 'Granted on', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="date" form="gwcvt-record-credential-form" id="gwcvt-record-date" name="gwc_vt_record_date" required
					value="<?php echo esc_attr( gwc_vt_today() ); ?>" max="<?php echo esc_attr( gwc_vt_today() ); ?>" />
				<span class="description"><?php esc_html_e( 'The day they did it. Expiry counts from there.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>
			<?php
		},
		static function (): void {
			?>
			<button type="submit" form="gwcvt-record-credential-form" class="button button-primary"><?php esc_html_e( 'Record it', 'groundwork-common-volunteer-tracker' ); ?></button>
			<button type="button" class="button" data-gwcvt-sheet-close><?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?></button>
			<?php
		},
		'gwcvt-sheet--narrow'
	);
}

/**
 * Everything that has to live outside wp-admin's post form.
 *
 * The form elements first, then the sheets. Both are printed here rather than
 * beside the panels they belong to, because a form inside wp-admin's own
 * <form id="post"> is silently dropped by the parser — see inc/admin-sheet.php.
 */
function gwc_vt_render_volunteer_sheets(): void {
	if ( ! gwc_vt_on_volunteer_editor() ) {
		return;
	}

	global $post;

	$volunteer_id = (int) $post->ID;
	gwc_vt_sheet_form( 'gwcvt-log-hours', admin_url( 'admin-post.php' ) );
	gwc_vt_sheet_form( 'gwcvt-record-credential-form', admin_url( 'admin-post.php' ) );

	if ( gwc_vt_can_see_records() ) {
		gwc_vt_render_log_hours_sheet( $volunteer_id );
	}

	if ( gwc_vt_can_record_credentials() ) {
		gwc_vt_render_record_credential_sheet( $volunteer_id );
	}
}

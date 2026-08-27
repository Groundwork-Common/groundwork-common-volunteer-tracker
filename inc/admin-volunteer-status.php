<?php
/**
 * Retiring a volunteer, and putting one back.
 *
 * ── What retiring is, and what it is not ─────────────────────────────────────
 * It says somebody stopped volunteering here. It keeps every hour they worked,
 * keeps their name on the letters that report those hours, and keeps them
 * findable by the privacy tools — because leaving does not put a person outside
 * the law, and a record the retention sweep cannot see is one it can never
 * purge.
 *
 * Anonymizing and deleting already existed and are answers to a different
 * question. Both are about privacy; neither says the ordinary thing, which is
 * that a person moved away. Reaching for anonymize to mean "they left" destroys
 * a name the organization is entitled to keep, and reaching for delete destroys
 * the hours. This is the third door, and it is the one most people want.
 *
 * It is reversible in one click, which is why it needs no confirmation screen —
 * unlike the two beside it, which need one each and have one each.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_gwc_vt_deactivate_volunteer', 'gwc_vt_handle_deactivate_volunteer' );
add_action( 'admin_post_gwc_vt_activate_volunteer', 'gwc_vt_handle_activate_volunteer' );
add_filter( 'post_row_actions', 'gwc_vt_volunteer_row_actions', 10, 2 );
add_action( 'admin_notices', 'gwc_vt_volunteer_status_notice' );
add_filter( 'wp_insert_post_data', 'gwc_vt_keep_volunteer_status', 10, 2 );
add_action( 'add_meta_boxes_' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_rename_volunteer_submit_box' );
add_action( 'add_meta_boxes_' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_add_volunteer_status_box' );

/* ── Quick Edit would silently reactivate somebody ────────────────────────────
 * This is the half of the change that is not presentation. Core's inline editor
 * posts a status from a <select> it builds from a fixed list — Published,
 * Scheduled, Pending, Draft, and Private in bulk — and wp_ajax_inline_save()
 * then does, verbatim:
 *
 *     $data['post_status'] = $data['_status'];
 *
 * A custom status is not in that list and cannot be. So quick-editing an inactive
 * volunteer to fix a typo in their name sets them to Published on the way past,
 * and nothing anywhere says so. That is the silent-correction-on-save bug this
 * codebase already has a rule about, arriving through a door nobody opened.
 *
 * Hiding the control is not the fix, because a hidden <select> still posts. The
 * fix is that an inline or bulk edit may not move a volunteer between statuses
 * at all: retiring has its own action, with its own nonce, and it is the only
 * thing that should be able to.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Keep a volunteer's status through a quick or bulk edit.
 *
 * @param array $data    The row about to be written.
 * @param array $postarr What was submitted.
 * @return array
 */
function gwc_vt_keep_volunteer_status( $data, $postarr ) {
	if ( ! is_array( $data ) || GWC_VT_VOLUNTEER_TYPE !== ( $data['post_type'] ?? '' ) ) {
		return $data;
	}

	$id = (int) ( $postarr['ID'] ?? 0 );

	if ( $id < 1 ) {
		return $data;
	}

	/* Only the two generic editors. gwc_vt_handle_deactivate_volunteer() and its
	 * opposite call wp_update_post() directly and must still work, as must a
	 * site moving a record to the trash. */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- reading which editor sent this, not form data; core has already verified its own nonce by the time post data is filtered, and the branch only ever makes the write more conservative.
	$inline = isset( $_POST['_inline_edit'] ) || isset( $_POST['bulk_edit'] ) || isset( $_GET['bulk_edit'] );

	if ( ! $inline || 'trash' === ( $data['post_status'] ?? '' ) ) {
		return $data;
	}

	$data['post_status'] = (string) get_post_status( $id );

	return $data;
}

/**
 * "Publish" is not what that panel does to a person.
 *
 * Re-registered rather than replaced: core's own callback still renders it, so
 * the hidden fields and the Update button keep working exactly as they do
 * everywhere else. Only the heading changes, and admin.css hides the rows
 * inside it that describe a post rather than a person — the status, the
 * visibility, the publish-immediately date, and Save Draft.
 */
function gwc_vt_rename_volunteer_submit_box(): void {
	remove_meta_box( 'submitdiv', GWC_VT_VOLUNTEER_TYPE, 'side' );

	add_meta_box(
		'submitdiv',
		__( 'Save', 'groundwork-common-volunteer-tracker' ),
		'post_submit_meta_box',
		GWC_VT_VOLUNTEER_TYPE,
		'side',
		'high'
	);
}

/* ── Standing is not a thing you save ────────────────────────────────────────
 * It lived in the Save panel, next to Update, and that was wrong in a way that
 * cost work rather than looking untidy: the link is a nonced GET to
 * admin-post.php, so it fires immediately and navigates away, while everything
 * else in that panel is staged and applied on Update. Somebody who fixed a typo
 * in a name and then made the person inactive lost the typo fix — silently,
 * except for a browser dialog about leaving the page, which is not an
 * explanation.
 *
 * It is still an immediate action; it is simply no longer sitting among the
 * controls that are not. A panel of its own is honest about being a different
 * kind of thing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A panel about the person rather than about saving them.
 *
 * 'core' priority so it lands under the Save box rather than above it: the
 * first thing on this side of the screen should be the one that gets you out
 * of it.
 */
function gwc_vt_add_volunteer_status_box(): void {
	if ( ! gwc_vt_can_see_records() ) {
		return;
	}

	add_meta_box(
		'gwc-vt-volunteer-standing',
		__( 'Status', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_status_box',
		GWC_VT_VOLUNTEER_TYPE,
		'side',
		'core'
	);
}

/**
 * Where they stand, how long they have been at it, and what they have done.
 *
 * The three facts a coordinator opening somebody's record wants before they
 * read anything else. Computed rather than read from the rollup cache: this is
 * one record and one query, and the cache exists for the list screen where it
 * is twenty.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_status_box( $post ): void {
	if ( ! is_a( $post, 'WP_Post' ) || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return;
	}

	$inactive = GWC_VT_VOLUNTEER_INACTIVE === $post->post_status;
	$totals   = gwc_vt_volunteer_totals( (int) $post->ID );
	?>
	<div class="gwcvt-standing">
		<?php
		/* ── Only the exception gets a sentence ───────────────────────────────
		 * There was one for both states, and the active one said "Volunteering
		 * here." on a record that is, by being open, a volunteer's. It told a
		 * coordinator something they already knew about nearly every person
		 * they would ever open, which is the definition of a line that trains
		 * people to stop reading the panel it is in.
		 *
		 * Inactive is different: it has a consequence somebody may not know —
		 * that this person stops being offered when a shift needs staffing —
		 * and the facts below cannot say it. So that one keeps its line and the
		 * other has none. */
		?>
		<?php if ( $inactive ) : ?>
			<p class="gwcvt-standing__where">
				<span class="dashicons dashicons-groups"></span>
				<?php esc_html_e( 'Inactive — not offered when you staff a shift.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php endif; ?>


		<?php
		/* ── A label and a value, never a sentence with a unit in it ──────────
		 * gwc_vt_format_hours() respects the site's hour_format, so it returns
		 * "10" on one site and "10h 00m" on another, and "%s hours" reads
		 * "10h 00m hours" on the second. The applications screen and the letter
		 * both solve this the same way and say so; this is the third. */
		?>
		<dl class="gwcvt-standing__facts">
			<div>
				<dt><?php esc_html_e( 'Volunteering', 'groundwork-common-volunteer-tracker' ); ?></dt>
				<dd><?php echo esc_html( gwc_vt_volunteer_tenure( $post, $totals, $inactive ) ); ?></dd>
			</div>

			<?php if ( ! $totals->is_empty() ) : ?>
				<div>
					<dt><?php esc_html_e( 'Verified time', 'groundwork-common-volunteer-tracker' ); ?></dt>
					<dd><?php echo esc_html( gwc_vt_format_hours( $totals->verified_minutes ) ); ?></dd>
				</div>

				<?php if ( $totals->pending_minutes > 0 ) : ?>
					<div>
						<dt><?php esc_html_e( 'Awaiting verification', 'groundwork-common-volunteer-tracker' ); ?></dt>
						<dd><?php echo esc_html( gwc_vt_format_hours( $totals->pending_minutes ) ); ?></dd>
					</div>
				<?php endif; ?>

				<div>
					<dt><?php esc_html_e( 'Shifts', 'groundwork-common-volunteer-tracker' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $totals->entries ) ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>

		<?php if ( $totals->is_empty() ) : ?>
			<p class="description"><?php esc_html_e( 'No hours logged yet.', 'groundwork-common-volunteer-tracker' ); ?></p>
		<?php endif; ?>

		<?php if ( current_user_can( 'edit_post', $post->ID ) ) : ?>
			<p class="gwcvt-standing__action">
				<a href="<?php echo esc_url( gwc_vt_volunteer_status_url( (int) $post->ID, ! $inactive ) ); ?>">
					<?php
					echo esc_html(
						$inactive
							? __( 'Make them active', 'groundwork-common-volunteer-tracker' )
							: __( 'Make them inactive', 'groundwork-common-volunteer-tracker' )
					);
					?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * How long they have been at it, said the way it is true.
 *
 * Measured from their first logged shift rather than from the day somebody
 * typed the record in, because those are different facts and only one of them
 * is about volunteering. Somebody with no hours has no tenure to report, so
 * that case says when the record was added instead and does not pretend.
 *
 * A person who has stopped gets a span with two ends. "Volunteering since 2019"
 * about somebody who left in 2021 is a sentence that is simply not true.
 *
 * @param WP_Post       $post     The volunteer.
 * @param GWC_VT_Totals $totals   Their totals.
 * @param bool          $inactive Whether they have stopped.
 * @return string
 */
function gwc_vt_volunteer_tenure( $post, $totals, bool $inactive ): string {
	if ( '' === $totals->first ) {
		return sprintf(
			/* translators: %s: a date. */
			__( 'Added %s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_display_date( substr( (string) $post->post_date, 0, 10 ) )
		);
	}

	if ( $inactive ) {
		return sprintf(
			/* translators: 1: the date of their first shift. 2: the date of their last. */
			_x( '%1$s to %2$s', 'volunteer tenure', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_display_date( $totals->first ),
			gwc_vt_display_date( $totals->last )
		);
	}

	$since = strtotime( $totals->first . ' 00:00:00' );

	if ( false === $since || $since > time() ) {
		/* A first shift dated in the future is a typo somebody will fix, and
		 * "volunteering for -3 days" is not the way to tell them. */
		return sprintf(
			/* translators: %s: a date. */
			__( 'since %s', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_display_date( $totals->first )
		);
	}

	return sprintf(
		/* translators: 1: a date. 2: a length of time, such as "1 year". */
		__( 'since %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_display_date( $totals->first ),
		human_time_diff( $since )
	);
}

/**
 * Read and check one activate-or-deactivate request.
 *
 * The same shape as gwc_vt_credential_request(): the nonce and the capability
 * are checked in one place, so a second action cannot be added later that
 * checks one of them and forgets the other.
 *
 * @param string $action The admin_post action being answered.
 * @return int The volunteer.
 */
function gwc_vt_volunteer_status_request( string $action ): int {
	$volunteer_id = isset( $_REQUEST['volunteer'] ) ? absint( wp_unslash( $_REQUEST['volunteer'] ) ) : 0;

	check_admin_referer( $action . '_' . $volunteer_id );

	if ( ! gwc_vt_can_see_records() || ! current_user_can( 'edit_post', $volunteer_id ) ) {
		wp_die(
			esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		wp_die(
			esc_html__( 'That is not a volunteer record.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Nothing to do', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 400 )
		);
	}

	return $volunteer_id;
}

/**
 * Mark a volunteer inactive.
 */
function gwc_vt_handle_deactivate_volunteer(): void {
	$volunteer_id = gwc_vt_volunteer_status_request( 'gwc_vt_deactivate_volunteer' );

	wp_update_post(
		array(
			'ID'          => $volunteer_id,
			'post_status' => GWC_VT_VOLUNTEER_INACTIVE,
		)
	);

	/**
	 * Fires after a volunteer has been made inactive.
	 *
	 * Their hours, their name and their records are all untouched.
	 *
	 * @param int $volunteer_id The volunteer.
	 */
	do_action( 'gwc_vt_volunteer_deactivated', $volunteer_id );

	gwc_vt_volunteer_status_redirect( 'inactive', $volunteer_id );
}

/**
 * Make an inactive volunteer active again.
 */
function gwc_vt_handle_activate_volunteer(): void {
	$volunteer_id = gwc_vt_volunteer_status_request( 'gwc_vt_activate_volunteer' );

	wp_update_post(
		array(
			'ID'          => $volunteer_id,
			'post_status' => 'publish',
		)
	);

	/**
	 * Fires after an inactive volunteer has been made active again.
	 *
	 * @param int $volunteer_id The volunteer.
	 */
	do_action( 'gwc_vt_volunteer_reactivated', $volunteer_id );

	gwc_vt_volunteer_status_redirect( 'active', $volunteer_id );
}

/**
 * Back to wherever they were, with something to read.
 *
 * @param string $result       What happened.
 * @param int    $volunteer_id The volunteer.
 */
function gwc_vt_volunteer_status_redirect( string $result, int $volunteer_id ): void {
	$back = wp_get_referer();

	if ( ! $back ) {
		$back = admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'gwc_vt_volunteer_result' => $result,
				'gwc_vt_volunteer'        => $volunteer_id,
			),
			remove_query_arg( array( 'gwc_vt_volunteer_result', 'gwc_vt_volunteer' ), $back )
		)
	);
	exit;
}

/**
 * Say what happened, and offer the way back.
 *
 * Retiring is one click and reversible, so the undo belongs in the notice
 * rather than behind a confirmation screen nobody wanted.
 */
function gwc_vt_volunteer_status_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a redirect result to render a message; nothing is written.
	$result = isset( $_GET['gwc_vt_volunteer_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_volunteer_result'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$volunteer_id = isset( $_GET['gwc_vt_volunteer'] ) ? absint( wp_unslash( $_GET['gwc_vt_volunteer'] ) ) : 0;

	if ( '' === $result || 0 === $volunteer_id || ! gwc_vt_can_see_records() ) {
		return;
	}

	$name = get_the_title( $volunteer_id );

	if ( 'inactive' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: a volunteer's name. */
					__( '%s is inactive. Their hours and their name are untouched, and they are no longer offered when you are staffing a shift.', 'groundwork-common-volunteer-tracker' ),
					$name
				)
			),
			wp_kses(
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( gwc_vt_volunteer_status_url( $volunteer_id, false ) ),
					esc_html__( 'Make them active again', 'groundwork-common-volunteer-tracker' )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);

		return;
	}

	if ( 'active' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: a volunteer's name. */
					__( '%s is active again, and can be put on shifts.', 'groundwork-common-volunteer-tracker' ),
					$name
				)
			)
		);
	}
}

/**
 * The nonced link that makes somebody inactive, or active again.
 *
 * @param int  $volunteer_id The volunteer.
 * @param bool $deactivate   True to make inactive, false to make active.
 * @return string
 */
function gwc_vt_volunteer_status_url( int $volunteer_id, bool $deactivate = true ): string {
	$action = $deactivate ? 'gwc_vt_deactivate_volunteer' : 'gwc_vt_activate_volunteer';

	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => $action,
				'volunteer' => $volunteer_id,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $volunteer_id
	);
}

/**
 * Offer it on the row, next to Edit.
 *
 * @param array<string, string> $actions What WordPress offers.
 * @param WP_Post               $post    The row.
 * @return array<string, string>
 */
function gwc_vt_volunteer_row_actions( $actions, $post ) {
	if ( ! is_a( $post, 'WP_Post' ) || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return $actions;
	}

	if ( ! gwc_vt_can_see_records() || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$inactive = GWC_VT_VOLUNTEER_INACTIVE === $post->post_status;

	$actions['gwc_vt_status'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( gwc_vt_volunteer_status_url( (int) $post->ID, ! $inactive ) ),
		$inactive
			? esc_html__( 'Make active', 'groundwork-common-volunteer-tracker' )
			: esc_html__( 'Make inactive', 'groundwork-common-volunteer-tracker' )
	);

	return $actions;
}


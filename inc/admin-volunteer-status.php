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
add_filter( 'views_edit-' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_volunteer_views' );
add_action( 'admin_notices', 'gwc_vt_volunteer_status_notice' );
add_filter( 'wp_insert_post_data', 'gwc_vt_keep_volunteer_status', 10, 2 );
add_action( 'add_meta_boxes_' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_rename_volunteer_submit_box' );
add_action( 'post_submitbox_misc_actions', 'gwc_vt_volunteer_submitbox_status' );

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

/**
 * Say whether they are active, and offer the switch, inside that box.
 *
 * Where the status row used to be, and saying the one thing about a volunteer's
 * standing that means anything here.
 */
function gwc_vt_volunteer_submitbox_status(): void {
	$post = get_post();

	if ( ! $post || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return;
	}

	if ( ! gwc_vt_can_see_records() || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}

	$inactive = GWC_VT_VOLUNTEER_INACTIVE === $post->post_status;
	?>
	<div class="misc-pub-section gwcvt-pub-status">
		<span class="dashicons dashicons-groups"></span>
		<?php
		echo esc_html(
			$inactive
				? __( 'Inactive — not offered when staffing a shift.', 'groundwork-common-volunteer-tracker' )
				: __( 'Volunteering here.', 'groundwork-common-volunteer-tracker' )
		);
		?>
		<a class="gwcvt-pub-status__action" href="<?php echo esc_url( gwc_vt_volunteer_status_url( (int) $post->ID, ! $inactive ) ); ?>">
			<?php
			echo esc_html(
				$inactive
					? __( 'Make them active', 'groundwork-common-volunteer-tracker' )
					: __( 'Make them inactive', 'groundwork-common-volunteer-tracker' )
			);
			?>
		</a>
	</div>
	<?php
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

/**
 * The lifecycle, as one strip of views above the list.
 *
 * ── Why applications are a view here rather than a menu row ──────────────────
 * Somebody who applies, is approved, volunteers for two years and then stops is
 * one person the whole way through, and the menu used to present the first step
 * of that as an unrelated screen in a different band. This reads it as what it
 * is.
 *
 * The records stay two post types underneath, and that is not a compromise —
 * see the block comment in inc/application-cpt.php. A stranger's typed name is
 * a claim, and a claim that lives in the volunteer table is one forgotten query
 * away from being listed, counted, emailed or put on a shift. Seven queries in
 * this plugin already ask for 'pending', so "one forgotten query" is not a
 * hypothetical. The interface can tell the truth about the lifecycle without
 * the tables having to.
 *
 * @param array<string, string> $views What WordPress offers.
 * @return array<string, string>
 */
function gwc_vt_volunteer_views( $views ) {
	if ( ! is_array( $views ) ) {
		return $views;
	}

	/* ── The core statuses carry no meaning on this list ─────────────────────
	 * WP_Posts_List_Table::get_views() offers one view per status, which is
	 * right for posts, where a draft and a published post are different kinds
	 * of thing. A volunteer is a person the organization knows; there is no
	 * such thing as a draft one, and "Published (24)" beside "All (24)" is a
	 * filter that never narrows anything.
	 *
	 * Removed from the strip rather than from the type. A record that somehow
	 * ends up in one of these is still queried, still counted and still
	 * editable — gwc_vt_volunteer_statuses() names all four deliberately — it
	 * simply has no tab of its own. Hiding a filter is safe in a way that
	 * refusing a status would not be.
	 *
	 * Trash stays: deleting a volunteer is real, reversible, and somebody has
	 * to be able to find what they deleted. */
	foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $noise ) {
		unset( $views[ $noise ] );
	}

	$waiting = gwc_vt_pending_application_count();

	$label = __( 'Applied', 'groundwork-common-volunteer-tracker' );

	if ( $waiting > 0 ) {
		$label .= sprintf(
			' <span class="count">(%s)</span>',
			esc_html( number_format_i18n( $waiting ) )
		);
	}

	$views['gwc_vt_applied'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE . '&page=' . GWC_VT_APPLICATIONS_PAGE ) ),
		$label
	);

	/* Read as the life it describes, whatever order core assembled them in:
	 * everybody, then the ones who stopped, then the ones asking to start, then
	 * the bin. "Mine" keeps the place core gives it, second, because moving a
	 * view somebody already knows the position of is its own small tax. Anything a site has added of its own keeps its place at the end
	 * rather than being dropped. */
	$order  = array( 'all', 'mine', GWC_VT_VOLUNTEER_INACTIVE, 'gwc_vt_applied', 'trash' );
	$sorted = array();

	foreach ( $order as $key ) {
		if ( isset( $views[ $key ] ) ) {
			$sorted[ $key ] = $views[ $key ];
			unset( $views[ $key ] );
		}
	}

	return array_merge( $sorted, $views );
}

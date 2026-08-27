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

add_action( 'admin_post_gwc_vt_retire_volunteer', 'gwc_vt_handle_retire_volunteer' );
add_action( 'admin_post_gwc_vt_restore_volunteer', 'gwc_vt_handle_restore_volunteer' );
add_filter( 'post_row_actions', 'gwc_vt_volunteer_row_actions', 10, 2 );
add_filter( 'views_edit-' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_volunteer_views' );
add_action( 'admin_notices', 'gwc_vt_volunteer_retire_notice' );

/**
 * Read and check one retire-or-restore request.
 *
 * The same shape as gwc_vt_credential_request(): the nonce and the capability
 * are checked in one place, so a second action cannot be added later that
 * checks one of them and forgets the other.
 *
 * @param string $action The admin_post action being answered.
 * @return int The volunteer.
 */
function gwc_vt_volunteer_retire_request( string $action ): int {
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
 * Retire a volunteer.
 */
function gwc_vt_handle_retire_volunteer(): void {
	$volunteer_id = gwc_vt_volunteer_retire_request( 'gwc_vt_retire_volunteer' );

	wp_update_post(
		array(
			'ID'          => $volunteer_id,
			'post_status' => GWC_VT_VOLUNTEER_RETIRED,
		)
	);

	/**
	 * Fires after a volunteer has been retired.
	 *
	 * Their hours, their name and their records are all untouched.
	 *
	 * @param int $volunteer_id The volunteer.
	 */
	do_action( 'gwc_vt_volunteer_retired', $volunteer_id );

	gwc_vt_volunteer_retire_redirect( 'retired', $volunteer_id );
}

/**
 * Put a retired volunteer back.
 */
function gwc_vt_handle_restore_volunteer(): void {
	$volunteer_id = gwc_vt_volunteer_retire_request( 'gwc_vt_restore_volunteer' );

	wp_update_post(
		array(
			'ID'          => $volunteer_id,
			'post_status' => 'publish',
		)
	);

	/**
	 * Fires after a retired volunteer has been put back.
	 *
	 * @param int $volunteer_id The volunteer.
	 */
	do_action( 'gwc_vt_volunteer_restored', $volunteer_id );

	gwc_vt_volunteer_retire_redirect( 'restored', $volunteer_id );
}

/**
 * Back to wherever they were, with something to read.
 *
 * @param string $result       What happened.
 * @param int    $volunteer_id The volunteer.
 */
function gwc_vt_volunteer_retire_redirect( string $result, int $volunteer_id ): void {
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
function gwc_vt_volunteer_retire_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a redirect result to render a message; nothing is written.
	$result = isset( $_GET['gwc_vt_volunteer_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_volunteer_result'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$volunteer_id = isset( $_GET['gwc_vt_volunteer'] ) ? absint( wp_unslash( $_GET['gwc_vt_volunteer'] ) ) : 0;

	if ( '' === $result || 0 === $volunteer_id || ! gwc_vt_can_see_records() ) {
		return;
	}

	$name = get_the_title( $volunteer_id );

	if ( 'retired' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: a volunteer's name. */
					__( '%s is retired. Their hours and their name are untouched, and they are no longer offered when you are staffing a shift.', 'groundwork-common-volunteer-tracker' ),
					$name
				)
			),
			wp_kses(
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( gwc_vt_volunteer_retire_url( $volunteer_id, false ) ),
					esc_html__( 'Put them back', 'groundwork-common-volunteer-tracker' )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);

		return;
	}

	if ( 'restored' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: a volunteer's name. */
					__( '%s is back. They can be put on shifts again.', 'groundwork-common-volunteer-tracker' ),
					$name
				)
			)
		);
	}
}

/**
 * The nonced link that retires somebody, or puts them back.
 *
 * @param int  $volunteer_id The volunteer.
 * @param bool $retire       True to retire, false to restore.
 * @return string
 */
function gwc_vt_volunteer_retire_url( int $volunteer_id, bool $retire = true ): string {
	$action = $retire ? 'gwc_vt_retire_volunteer' : 'gwc_vt_restore_volunteer';

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

	$retired = GWC_VT_VOLUNTEER_RETIRED === $post->post_status;

	$actions['gwc_vt_retire'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( gwc_vt_volunteer_retire_url( (int) $post->ID, ! $retired ) ),
		$retired
			? esc_html__( 'Put back', 'groundwork-common-volunteer-tracker' )
			: esc_html__( 'Retire', 'groundwork-common-volunteer-tracker' )
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
	$order  = array( 'all', 'mine', GWC_VT_VOLUNTEER_RETIRED, 'gwc_vt_applied', 'trash' );
	$sorted = array();

	foreach ( $order as $key ) {
		if ( isset( $views[ $key ] ) ) {
			$sorted[ $key ] = $views[ $key ];
			unset( $views[ $key ] );
		}
	}

	return array_merge( $sorted, $views );
}

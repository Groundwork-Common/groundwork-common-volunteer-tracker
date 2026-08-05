<?php
/**
 * Verification where staff look for it: the hours list, and the entry itself.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_' . GWCVT_ENTRY_TYPE . '_posts_columns', 'gwcvt_add_verified_column', 20 );
add_action( 'manage_' . GWCVT_ENTRY_TYPE . '_posts_custom_column', 'gwcvt_render_verified_column', 10, 2 );
add_filter( 'post_row_actions', 'gwcvt_entry_row_actions', 10, 2 );

add_action( 'restrict_manage_posts', 'gwcvt_verified_filter_dropdown' );
add_action( 'pre_get_posts', 'gwcvt_apply_verified_filter' );

add_filter( 'bulk_actions-edit-' . GWCVT_ENTRY_TYPE, 'gwcvt_register_bulk_actions' );
add_filter( 'handle_bulk_actions-edit-' . GWCVT_ENTRY_TYPE, 'gwcvt_handle_bulk_actions', 10, 3 );
add_action( 'admin_notices', 'gwcvt_bulk_action_notice' );

add_action( 'admin_post_gwcvt_verify_entry', 'gwcvt_handle_verify_entry' );
add_action( 'admin_post_gwcvt_unverify_entry', 'gwcvt_handle_unverify_entry' );

add_action( 'add_meta_boxes', 'gwcvt_add_verify_meta_box' );
add_action( 'admin_menu', 'gwcvt_add_pending_bubble', 20 );

/* ── The column ──────────────────────────────────────────────────────────── */

/**
 * Add the verification column, before the trailing core columns.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwcvt_add_verified_column( $columns ): array {
	$columns = (array) $columns;

	$columns['gwcvt_verified'] = __( 'Verified', 'groundwork-common-volunteer-tracker' );

	return $columns;
}

/**
 * Render the verification cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Entry post ID.
 */
function gwcvt_render_verified_column( $column, $post_id ): void {
	if ( 'gwcvt_verified' !== $column ) {
		return;
	}

	$post_id = (int) $post_id;
	$context = gwcvt_attestation_context( $post_id );

	if ( ! $context['verified'] ) {
		printf(
			'<span class="gwcvt-badge gwcvt-badge--waiting">%s</span>',
			esc_html( gwcvt_verification_label( 'unverified' ) )
		);
		return;
	}

	printf(
		'<span class="gwcvt-badge gwcvt-badge--verified">%1$s</span><br /><span class="gwcvt-badge__detail">%2$s</span>',
		esc_html( gwcvt_verification_label( 'verified' ) ),
		esc_html( gwcvt_attestation_line( $post_id ) )
	);
}

/* ── Row actions ─────────────────────────────────────────────────────────── */

/**
 * Offer Verify or Withdraw on each row.
 *
 * A nonced link handled server-side rather than a script and an endpoint. This
 * is the same reasoning as the colophon toggle: the action is rare enough per
 * person that a page load costs nothing, and the alternative is a script, a
 * nonce shipped to the browser and an AJAX route to maintain.
 *
 * @param array   $actions Existing row actions.
 * @param WP_Post $post    The row's post.
 * @return array
 */
function gwcvt_entry_row_actions( $actions, $post ): array {
	$actions = (array) $actions;

	if ( GWCVT_ENTRY_TYPE !== $post->post_type ) {
		return $actions;
	}

	$entry_id = (int) $post->ID;

	if ( ! gwcvt_user_can_verify( get_current_user_id(), $entry_id ) ) {
		return $actions;
	}

	if ( gwcvt_entry_is_verified( $entry_id ) ) {
		$actions['gwcvt_unverify'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( gwcvt_verify_action_url( 'gwcvt_unverify_entry', $entry_id ) ),
			esc_html__( 'Withdraw verification', 'groundwork-common-volunteer-tracker' )
		);

		return $actions;
	}

	$actions['gwcvt_verify'] = sprintf(
		'<a href="%1$s"><strong>%2$s</strong></a>',
		esc_url( gwcvt_verify_action_url( 'gwcvt_verify_entry', $entry_id ) ),
		esc_html__( 'Verify', 'groundwork-common-volunteer-tracker' )
	);

	return $actions;
}

/**
 * A nonced URL for one entry's verify or unverify action.
 *
 * The nonce action carries the entry ID, so a nonce minted for one shift cannot
 * be replayed against another.
 *
 * @param string $action   admin_post action name.
 * @param int    $entry_id Entry post ID.
 * @return string
 */
function gwcvt_verify_action_url( string $action, int $entry_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'   => $action,
				'entry'    => $entry_id,
				'returnto' => rawurlencode( gwcvt_current_admin_url() ),
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $entry_id
	);
}

/**
 * Where to send the browser back to.
 *
 * @return string
 */
function gwcvt_current_admin_url(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; used to rebuild the current list view and validated with wp_safe_redirect on the way back.
	$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

	return admin_url( 'edit.php' . ( '' !== $query ? '?' . $query : '?post_type=' . GWCVT_ENTRY_TYPE ) );
}

/* ── The handlers ────────────────────────────────────────────────────────── */

/**
 * Verify one entry.
 */
function gwcvt_handle_verify_entry(): void {
	$entry_id = gwcvt_verify_request_entry( 'gwcvt_verify_entry' );

	gwcvt_verify_entry( $entry_id, get_current_user_id() );

	gwcvt_redirect_back( 'verified' );
}

/**
 * Withdraw one entry's verification.
 */
function gwcvt_handle_unverify_entry(): void {
	$entry_id = gwcvt_verify_request_entry( 'gwcvt_unverify_entry' );

	gwcvt_unverify_entry( $entry_id );

	gwcvt_redirect_back( 'unverified' );
}

/**
 * The shared front half of both handlers: which entry, and may you.
 *
 * Capability before nonce, house rule. Both are checked here rather than in
 * gwcvt_verify_entry() as well as here — the model function checks
 * authorisation too, through the method registry's can_apply, because it is
 * also reachable from bulk actions and from WP-CLI.
 *
 * @param string $action The admin_post action, which is also the nonce prefix.
 * @return int
 */
function gwcvt_verify_request_entry( string $action ): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce for this exact entry is verified below; this read only identifies which entry that is.
	$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;

	if ( $entry_id < 1 || GWCVT_ENTRY_TYPE !== get_post_type( $entry_id ) ) {
		wp_die(
			esc_html__( 'That hour entry does not exist.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	if ( ! gwcvt_user_can_verify( get_current_user_id(), $entry_id ) ) {
		wp_die(
			esc_html__( 'You do not have permission to verify hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( $action . '_' . $entry_id );

	return $entry_id;
}

/**
 * Back to the list, with a note about what happened.
 *
 * @param string $result What to tell the user.
 */
function gwcvt_redirect_back( string $result ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in gwcvt_verify_request_entry() before this runs.
	$raw = isset( $_GET['returnto'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['returnto'] ) ) ) : '';

	$target = '' !== $raw ? $raw : admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE );

	/* wp_safe_redirect, not wp_redirect. The return URL came in on the query
	 * string, and although it was put there by our own link a moment ago, a
	 * redirect target from a request parameter is an open redirect unless
	 * something checks the host. wp_safe_redirect is that something. */
	wp_safe_redirect( add_query_arg( 'gwcvt_result', $result, $target ) );
	exit;
}

/* ── Bulk ────────────────────────────────────────────────────────────────── */

/**
 * Add Verify and Withdraw to the bulk menu.
 *
 * @param array $actions Existing bulk actions.
 * @return array
 */
function gwcvt_register_bulk_actions( $actions ): array {
	$actions = (array) $actions;

	if ( ! current_user_can( gwcvt_cap( 'verify' ) ) ) {
		return $actions;
	}

	$actions['gwcvt_verify']   = __( 'Verify', 'groundwork-common-volunteer-tracker' );
	$actions['gwcvt_unverify'] = __( 'Withdraw verification', 'groundwork-common-volunteer-tracker' );

	return $actions;
}

/**
 * Apply a bulk verify or withdraw.
 *
 * Core supplies and checks the nonce for bulk actions, which is the whole
 * reason this goes through handle_bulk_actions- rather than a hand-rolled
 * admin_post handler with a checkbox list of its own.
 *
 * Each entry is still authorised individually through gwcvt_verify_entry(): a
 * user who may verify most of a selection but not one of them verifies the rest
 * and silently skips that one, rather than the selection succeeding or failing
 * as a block.
 *
 * @param string $redirect_to Where core will send the browser.
 * @param string $action      Which bulk action was chosen.
 * @param array  $post_ids    The selected posts.
 * @return string
 */
function gwcvt_handle_bulk_actions( $redirect_to, $action, $post_ids ): string {
	if ( ! in_array( $action, array( 'gwcvt_verify', 'gwcvt_unverify' ), true ) ) {
		return $redirect_to;
	}

	$user_id = get_current_user_id();
	$done    = 0;
	$skipped = 0;

	foreach ( (array) $post_ids as $post_id ) {
		$post_id = (int) $post_id;

		if ( ! gwcvt_user_can_verify( $user_id, $post_id ) ) {
			++$skipped;
			continue;
		}

		$ok = 'gwcvt_verify' === $action
			? gwcvt_verify_entry( $post_id, $user_id )
			: gwcvt_unverify_entry( $post_id );

		if ( $ok ) {
			++$done;
		} else {
			++$skipped;
		}
	}

	return add_query_arg(
		array(
			'gwcvt_result'  => 'gwcvt_verify' === $action ? 'verified' : 'unverified',
			'gwcvt_done'    => $done,
			'gwcvt_skipped' => $skipped,
		),
		$redirect_to
	);
}

/**
 * Say what the last action did.
 */
function gwcvt_bulk_action_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; this only decides which sentence to print after a redirect.
	$result = isset( $_GET['gwcvt_result'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_result'] ) ) : '';

	if ( ! in_array( $result, array( 'verified', 'unverified' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$done = isset( $_GET['gwcvt_done'] ) ? absint( wp_unslash( $_GET['gwcvt_done'] ) ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwcvt_skipped'] ) ? absint( wp_unslash( $_GET['gwcvt_skipped'] ) ) : 0;

	$message = 'verified' === $result
		? sprintf(
			/* translators: %d: number of hour entries. */
			_n( '%d entry verified.', '%d entries verified.', $done, 'groundwork-common-volunteer-tracker' ),
			$done
		)
		: sprintf(
			/* translators: %d: number of hour entries. */
			_n( 'Verification withdrawn from %d entry.', 'Verification withdrawn from %d entries.', $done, 'groundwork-common-volunteer-tracker' ),
			$done
		);

	if ( $skipped > 0 ) {
		$message .= ' ' . sprintf(
			/* translators: %d: number of hour entries that were skipped. */
			_n( '%d was skipped — you cannot verify it.', '%d were skipped — you cannot verify them.', $skipped, 'groundwork-common-volunteer-tracker' ),
			$skipped
		);
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html( $message )
	);
}

/* ── Filtering the list ──────────────────────────────────────────────────── */

/**
 * A verified/unverified dropdown above the hours list.
 *
 * @param string $post_type The list's post type.
 */
function gwcvt_verified_filter_dropdown( $post_type ): void {
	if ( GWCVT_ENTRY_TYPE !== $post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$current = isset( $_GET['gwcvt_state'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_state'] ) ) : '';
	?>
	<label class="screen-reader-text" for="gwcvt_state">
		<?php esc_html_e( 'Filter by verification', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="gwcvt_state" id="gwcvt_state">
		<option value=""><?php esc_html_e( 'Any verification state', 'groundwork-common-volunteer-tracker' ); ?></option>
		<option value="unverified" <?php selected( $current, 'unverified' ); ?>>
			<?php echo esc_html( gwcvt_verification_label( 'unverified' ) ); ?>
		</option>
		<option value="verified" <?php selected( $current, 'verified' ); ?>>
			<?php echo esc_html( gwcvt_verification_label( 'verified' ) ); ?>
		</option>
	</select>
	<?php
}

/**
 * Apply that dropdown.
 *
 * @param WP_Query $query The query about to run.
 */
function gwcvt_apply_verified_filter( $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( GWCVT_ENTRY_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$state = isset( $_GET['gwcvt_state'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_state'] ) ) : '';

	if ( ! in_array( $state, array( 'verified', 'unverified' ), true ) ) {
		return;
	}

	$query->set(
		'meta_query',
		array(
			array(
				'key'     => GWCVT_ENTRY_VERIFIED_AT,
				'compare' => 'verified' === $state ? 'EXISTS' : 'NOT EXISTS',
			),
		)
	);
}

/* ── On the entry itself ─────────────────────────────────────────────────── */

/**
 * A verification panel in the sidebar of the entry editor.
 */
function gwcvt_add_verify_meta_box(): void {
	if ( ! current_user_can( gwcvt_cap( 'verify' ) ) ) {
		return;
	}

	add_meta_box(
		'gwcvt-verify',
		__( 'Verification', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_verify_meta_box',
		GWCVT_ENTRY_TYPE,
		'side',
		'high'
	);
}

/**
 * Render it.
 *
 * @param WP_Post $post The entry.
 */
function gwcvt_render_verify_meta_box( $post ): void {
	$entry_id = (int) $post->ID;
	$context  = gwcvt_attestation_context( $entry_id );

	if ( 'auto-draft' === get_post_status( $entry_id ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save the shift first, then verify it.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	if ( $context['verified'] ) {
		printf(
			'<p><span class="gwcvt-badge gwcvt-badge--verified">%1$s</span></p><p class="description">%2$s</p>',
			esc_html( gwcvt_verification_label( 'verified' ) ),
			esc_html( gwcvt_attestation_line( $entry_id ) )
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( gwcvt_verify_action_url( 'gwcvt_unverify_entry', $entry_id ) ),
			esc_html__( 'Withdraw verification', 'groundwork-common-volunteer-tracker' )
		);

		return;
	}

	printf(
		'<p><span class="gwcvt-badge gwcvt-badge--waiting">%s</span></p>',
		esc_html( gwcvt_verification_label( 'unverified' ) )
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Verifying records that you, as a member of staff, attest these hours were worked. Your name and the date are recorded and appear on the verification letter.', 'groundwork-common-volunteer-tracker' )
	);

	printf(
		'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
		esc_url( gwcvt_verify_action_url( 'gwcvt_verify_entry', $entry_id ) ),
		esc_html__( 'Verify these hours', 'groundwork-common-volunteer-tracker' )
	);
}

/* ── The bubble ──────────────────────────────────────────────────────────── */

/**
 * Put the unverified count next to the menu item, the way core does for
 * comments and updates.
 *
 * Core's own markup and classes rather than something bespoke, so it inherits
 * the admin colour scheme and reads as part of WordPress rather than as a
 * decoration this plugin added.
 */
function gwcvt_add_pending_bubble(): void {
	global $menu;

	if ( ! is_array( $menu ) || ! current_user_can( gwcvt_cap( 'verify' ) ) ) {
		return;
	}

	$count = gwcvt_unverified_count();

	if ( $count < 1 ) {
		return;
	}

	$slug = 'edit.php?post_type=' . GWCVT_ENTRY_TYPE;

	foreach ( $menu as $index => $item ) {
		if ( ! isset( $item[2] ) || $slug !== $item[2] ) {
			continue;
		}

		$menu[ $index ][0] .= sprintf(
			' <span class="awaiting-mod"><span class="pending-count">%s</span></span>',
			esc_html( number_format_i18n( $count ) )
		);

		break;
	}
}

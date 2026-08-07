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
add_action( 'admin_notices', 'gwcvt_bulk_unverify_confirm' );

add_action( 'admin_post_gwcvt_verify_entry', 'gwcvt_handle_verify_entry' );
add_action( 'admin_post_gwcvt_unverify_entry', 'gwcvt_handle_unverify_entry' );
add_action( 'admin_post_gwcvt_bulk_unverify', 'gwcvt_handle_bulk_unverify' );

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
		/* ── The badge IS the verify control ──────────────────────────────
		 * It was a plain span, and Verify lived only in the hover row
		 * actions — which means the most obvious thing on the row did
		 * nothing, and the thing that did something was invisible until you
		 * knew to hover and unreachable on a touch screen.
		 *
		 * A coordinator working down a queue of thirty shifts clicks the
		 * status, because the status is what they are reading. So it is a
		 * link now, with an accessible name that says what clicking does
		 * rather than what the state is — "Not yet verified" as a link name
		 * would announce the opposite of the action. The visible text stays
		 * the status, because that is what the column is for.
		 *
		 * Withdrawing stays a row action: undoing is rare, deliberate, and
		 * should not sit under the cursor of somebody clicking quickly.
		 * ─────────────────────────────────────────────────────────────── */
		if ( ! gwcvt_user_can_verify( get_current_user_id(), $post_id ) ) {
			printf(
				'<span class="gwcvt-badge gwcvt-badge--waiting">%s</span>',
				esc_html( gwcvt_verification_label( 'unverified' ) )
			);
			return;
		}

		printf(
			'<a class="gwcvt-badge gwcvt-badge--waiting gwcvt-badge--action" href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( gwcvt_verify_action_url( 'gwcvt_verify_entry', $post_id ) ),
			esc_attr(
				sprintf(
					/* translators: %s: an hour entry's description, e.g. "Jane Doe — 2026-03-04 — 3.5". */
					__( 'Verify these hours: %s', 'groundwork-common-volunteer-tracker' ),
					get_the_title( $post_id )
				)
			),
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

	/* ── Withdrawal stops to ask ─────────────────────────────────────────────
	 * Verifying in bulk is additive and undoable. Withdrawing is neither: it
	 * removes an attestation naming a person and a date, and re-verifying does
	 * not put that back — it records a NEW name and a new date. Two clicks could
	 * rewrite the provenance of a page of hours, on the one fact a letter rests
	 * on, with no undo.
	 *
	 * So it goes to a confirmation first, in the same spirit as the interstitial
	 * that calling off an event's time gets. Not a JS confirm(): this needs to
	 * say what will happen and what cannot be got back, which a browser dialog
	 * cannot do and a screen reader announces poorly.
	 * ─────────────────────────────────────────────────────────────────────── */
	if ( 'gwcvt_unverify' === $action ) {
		$eligible = array();

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( gwcvt_user_can_verify( $user_id, $post_id ) && gwcvt_entry_is_verified( $post_id ) ) {
				$eligible[] = $post_id;
			}
		}

		if ( ! $eligible ) {
			return add_query_arg(
				array(
					'gwcvt_result'  => 'unverified',
					'gwcvt_done'    => 0,
					'gwcvt_skipped' => count( (array) $post_ids ),
				),
				$redirect_to
			);
		}

		return add_query_arg(
			array(
				'gwcvt_confirm' => 'unverify',
				'gwcvt_ids'     => implode( ',', $eligible ),
				'gwcvt_skipped' => max( 0, count( (array) $post_ids ) - count( $eligible ) ),
			),
			$redirect_to
		);
	}

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
 * The entry IDs a confirmation is being asked about.
 *
 * @return int[]
 */
function gwcvt_confirm_ids(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; nothing is written until the confirmation form below is submitted with its own nonce.
	$raw = isset( $_GET['gwcvt_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['gwcvt_ids'] ) ) : '';

	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	return array_values( array_unique( $ids ) );
}

/**
 * Ask before withdrawing a selection's attestations.
 */
function gwcvt_bulk_unverify_confirm(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWCVT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$confirm = isset( $_GET['gwcvt_confirm'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_confirm'] ) ) : '';

	if ( 'unverify' !== $confirm ) {
		return;
	}

	$ids = gwcvt_confirm_ids();

	if ( ! $ids ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwcvt_skipped'] ) ? absint( wp_unslash( $_GET['gwcvt_skipped'] ) ) : 0;
	?>
	<div class="notice notice-warning">
		<p>
			<strong>
				<?php
				printf(
					esc_html(
						/* translators: %d: how many shifts. */
						_n(
							'Withdraw verification from %d shift?',
							'Withdraw verification from %d shifts?',
							count( $ids ),
							'groundwork-common-volunteer-tracker'
						)
					),
					(int) count( $ids )
				);
				?>
			</strong>
		</p>
		<p>
			<?php esc_html_e( 'Each of these carries a staff member\'s name and the date they attested to it. Withdrawing removes that. Verifying them again afterwards does not restore it — it records whoever does it, on the day they do it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'The hours themselves are untouched. They stop counting towards anything, and they stop appearing as verified on a letter, until somebody attests to them again.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php if ( $skipped > 0 ) : ?>
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %d: how many of the selected shifts are not affected. */
						_n(
							'%d of the shifts you selected is not included — either it was never verified, or it is not yours to verify.',
							'%d of the shifts you selected are not included — either they were never verified, or they are not yours to verify.',
							$skipped,
							'groundwork-common-volunteer-tracker'
						)
					),
					(int) $skipped
				);
				?>
			</p>
		<?php endif; ?>

		<ul style="margin-left:1.5em;list-style:disc">
			<?php foreach ( array_slice( $ids, 0, 10 ) as $id ) : ?>
				<li><?php echo esc_html( get_the_title( (int) $id ) ); ?></li>
			<?php endforeach; ?>
			<?php if ( count( $ids ) > 10 ) : ?>
				<li>
					<em>
						<?php
						printf(
							esc_html(
								/* translators: %d: how many more shifts are in the selection. */
								_n( 'and %d more', 'and %d more', count( $ids ) - 10, 'groundwork-common-volunteer-tracker' )
							),
							(int) ( count( $ids ) - 10 )
						);
						?>
					</em>
				</li>
			<?php endif; ?>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px">
			<input type="hidden" name="action" value="gwcvt_bulk_unverify" />
			<input type="hidden" name="gwcvt_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
			<input type="hidden" name="returnto" value="<?php echo esc_attr( gwcvt_current_list_url() ); ?>" />
			<?php wp_nonce_field( 'gwcvt_bulk_unverify' ); ?>

			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Withdraw verification', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( gwcvt_current_list_url() ); ?>">
				<?php esc_html_e( 'Leave it alone', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</form>
	</div>
	<?php
}

/**
 * This list screen, without the confirmation arguments on it.
 *
 * @return string
 */
function gwcvt_current_list_url(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- building a link back to the screen the user is already on.
	$query = wp_unslash( $_GET );

	unset( $query['gwcvt_confirm'], $query['gwcvt_ids'], $query['gwcvt_skipped'], $query['_wpnonce'] );

	$clean = array();

	foreach ( (array) $query as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
	}

	$clean['post_type'] = GWCVT_ENTRY_TYPE;

	return add_query_arg( $clean, admin_url( 'edit.php' ) );
}

/**
 * Withdraw a confirmed selection.
 *
 * Every entry is authorised again here rather than trusted from the confirmation
 * link — the ids travelled through a URL, and a handler that acts on what a URL
 * told it is a handler that acts on whatever anybody puts in one.
 */
function gwcvt_handle_bulk_unverify(): void {
	gwcvt_require_cap( 'verify' );
	check_admin_referer( 'gwcvt_bulk_unverify' );

	$raw = isset( $_POST['gwcvt_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['gwcvt_ids'] ) ) : '';
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	$user_id = get_current_user_id();
	$done    = 0;
	$skipped = 0;

	foreach ( array_unique( $ids ) as $entry_id ) {
		$entry_id = (int) $entry_id;

		if ( ! gwcvt_user_can_verify( $user_id, $entry_id ) || ! gwcvt_unverify_entry( $entry_id ) ) {
			++$skipped;
			continue;
		}

		++$done;
	}

	$returnto = isset( $_POST['returnto'] ) ? esc_url_raw( wp_unslash( $_POST['returnto'] ) ) : '';
	$base     = '' !== $returnto ? $returnto : add_query_arg( 'post_type', GWCVT_ENTRY_TYPE, admin_url( 'edit.php' ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'gwcvt_result'  => 'unverified',
				'gwcvt_done'    => $done,
				'gwcvt_skipped' => $skipped,
			),
			$base
		)
	);
	exit;
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

	/* Green only when something actually happened. A selection where every row
	 * was skipped used to come back as a success notice reading "0 entries
	 * verified. 12 were skipped" — which announces, in the colour reserved for
	 * things going right, that nothing went right. */
	printf(
		'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
		$done > 0 ? 'notice-success' : 'notice-warning',
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
		<option value="unmatched" <?php selected( $current, 'unmatched' ); ?>>
			<?php esc_html_e( 'Awaiting matching', 'groundwork-common-volunteer-tracker' ); ?>
		</option>
	</select>
	<?php
}

/**
 * Filter and order the hours list.
 *
 * ── Why the default order had to change ──────────────────────────────────────
 * WordPress orders a post list by post_date, which for an hour entry is when
 * somebody typed it in. A coordinator opening this screen is looking for last
 * weekend's shifts, and what they got was creation order — which, once records
 * arrive from more than one route, reads as no order at all. The seeded demo
 * opened as 2023-06-24, 2026-07-18, 2026-08-01, 2026-07-25.
 *
 * So the default is the shift date, newest first. The Date column stays
 * sortable, and an explicit sort still wins.
 *
 * The meta_query looks redundant and is not: ordering by a meta key uses an
 * INNER JOIN, so an entry with no _gwcvt_date would silently vanish from the
 * list. The EXISTS-or-NOT-EXISTS pair keeps those rows in, sorted last — a
 * dateless entry is broken and needs a human, and hiding it is the worst
 * possible response.
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

	$clauses = array( 'relation' => 'AND' );

	if ( in_array( $state, array( 'verified', 'unverified' ), true ) ) {
		$clauses[] = array(
			'key'     => GWCVT_ENTRY_VERIFIED_AT,
			'compare' => 'verified' === $state ? 'EXISTS' : 'NOT EXISTS',
		);
	}

	if ( 'unmatched' === $state ) {
		/* Sent in through the public form and not yet attached to anybody.
		 * Stored as the string '0', so a value comparison rather than
		 * NOT EXISTS — the key is always written. */
		$clauses[] = array(
			'key'     => GWCVT_ENTRY_VOLUNTEER,
			'value'   => '0',
			'compare' => '=',
		);
	}

	/* The NAME goes on the EXISTS clause, not on the OR group around it.
	 * WP_Meta_Query collects named clauses so orderby can address them, and a
	 * name on a group addresses nothing — the sort is silently dropped and the
	 * list falls back to post date. Which is exactly what happened, and looked
	 * like the ordering code not running at all. */
	$clauses[] = array(
		'relation'         => 'OR',
		'gwcvt_shift_date' => array(
			'key'     => GWCVT_ENTRY_DATE,
			'compare' => 'EXISTS',
		),
		array(
			'key'     => GWCVT_ENTRY_DATE,
			'compare' => 'NOT EXISTS',
		),
	);

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin list screen, paginated by core.
	$query->set( 'meta_query', $clauses );

	// An explicit sort from the column headers wins.
	if ( '' === (string) $query->get( 'orderby' ) ) {
		$query->set(
			'orderby',
			array(
				'gwcvt_shift_date' => 'DESC',
				'ID'               => 'DESC',
			)
		);
	}
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

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- appending the pending-count bubble to this plugin's own menu item, which is how core itself renders the comments count. There is no API for it.
		$menu[ $index ][0] .= sprintf(
			' <span class="awaiting-mod"><span class="pending-count">%s</span></span>',
			esc_html( number_format_i18n( $count ) )
		);

		break;
	}
}

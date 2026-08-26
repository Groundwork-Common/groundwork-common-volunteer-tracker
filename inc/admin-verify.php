<?php
/**
 * Verification where staff look for it: the hours list, and the entry itself.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_' . GWC_VT_ENTRY_TYPE . '_posts_columns', 'gwc_vt_add_verified_column', 20 );
add_action( 'manage_' . GWC_VT_ENTRY_TYPE . '_posts_custom_column', 'gwc_vt_render_verified_column', 10, 2 );
add_filter( 'post_row_actions', 'gwc_vt_entry_row_actions', 10, 2 );

add_action( 'restrict_manage_posts', 'gwc_vt_verified_filter_dropdown' );
add_action( 'pre_get_posts', 'gwc_vt_apply_verified_filter' );

add_filter( 'bulk_actions-edit-' . GWC_VT_ENTRY_TYPE, 'gwc_vt_register_bulk_actions' );
add_filter( 'handle_bulk_actions-edit-' . GWC_VT_ENTRY_TYPE, 'gwc_vt_handle_bulk_actions', 10, 3 );
add_action( 'admin_notices', 'gwc_vt_bulk_action_notice' );
add_action( 'admin_notices', 'gwc_vt_bulk_unverify_confirm' );

add_action( 'admin_post_gwc_vt_verify_entry', 'gwc_vt_handle_verify_entry' );
add_action( 'admin_post_gwc_vt_unverify_entry', 'gwc_vt_handle_unverify_entry' );
add_action( 'admin_post_gwc_vt_bulk_unverify', 'gwc_vt_handle_bulk_unverify' );

add_action( 'add_meta_boxes', 'gwc_vt_add_verify_meta_box' );
add_action( 'admin_menu', 'gwc_vt_add_pending_bubble', 20 );
add_action( 'admin_menu', 'gwc_vt_register_verify_queue', 14 );
add_filter( 'views_edit-' . GWC_VT_ENTRY_TYPE, 'gwc_vt_verify_queue_view' );

/* ── The column ──────────────────────────────────────────────────────────── */

/**
 * Add the verification column, before the trailing core columns.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwc_vt_add_verified_column( $columns ): array {
	$columns = (array) $columns;

	$columns['gwc_vt_verified'] = __( 'Verified', 'groundwork-common-volunteer-tracker' );

	return $columns;
}

/**
 * Render the verification cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Entry post ID.
 */
function gwc_vt_render_verified_column( $column, $post_id ): void {
	if ( 'gwc_vt_verified' !== $column ) {
		return;
	}

	$post_id = (int) $post_id;
	$context = gwc_vt_attestation_context( $post_id );

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
		if ( ! gwc_vt_user_can_verify( get_current_user_id(), $post_id ) ) {
			printf(
				'<span class="gwcvt-badge gwcvt-badge--waiting">%s</span>',
				esc_html( gwc_vt_verification_label( 'unverified' ) )
			);
			return;
		}

		printf(
			'<a class="gwcvt-badge gwcvt-badge--waiting gwcvt-badge--action" href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( gwc_vt_verify_action_url( 'gwc_vt_verify_entry', $post_id ) ),
			esc_attr(
				sprintf(
					/* translators: %s: an hour entry's description, e.g. "Jane Doe — 2026-03-04 — 3.5". */
					__( 'Verify these hours: %s', 'groundwork-common-volunteer-tracker' ),
					get_the_title( $post_id )
				)
			),
			esc_html( gwc_vt_verification_label( 'unverified' ) )
		);
		return;
	}

	printf(
		'<span class="gwcvt-badge gwcvt-badge--verified">%1$s</span><br /><span class="gwcvt-badge__detail">%2$s</span>',
		esc_html( gwc_vt_verification_label( 'verified' ) ),
		esc_html( gwc_vt_attestation_line( $post_id ) )
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
function gwc_vt_entry_row_actions( $actions, $post ): array {
	$actions = (array) $actions;

	if ( GWC_VT_ENTRY_TYPE !== $post->post_type ) {
		return $actions;
	}

	$entry_id = (int) $post->ID;

	if ( ! gwc_vt_user_can_verify( get_current_user_id(), $entry_id ) ) {
		return $actions;
	}

	if ( gwc_vt_entry_is_verified( $entry_id ) ) {
		$actions['gwc_vt_unverify'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( gwc_vt_verify_action_url( 'gwc_vt_unverify_entry', $entry_id ) ),
			esc_html__( 'Withdraw verification', 'groundwork-common-volunteer-tracker' )
		);

		return $actions;
	}

	/* gwc_vt_verify_entry() refuses an entry nobody has matched, so offering
	 * Verify here would be offering what the model will decline. Point at the
	 * thing that has to happen first instead: matching is done on the entry's
	 * own screen, where gwc_vt_render_triage_actions() puts the buttons. */
	if ( gwc_vt_entry_volunteer_id( $entry_id ) < 1 ) {
		$edit = get_edit_post_link( $entry_id );

		if ( $edit ) {
			$actions['gwc_vt_match'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit ),
				esc_html__( 'Match to a volunteer first', 'groundwork-common-volunteer-tracker' )
			);
		}

		return $actions;
	}

	$actions['gwc_vt_verify'] = sprintf(
		'<a href="%1$s"><strong>%2$s</strong></a>',
		esc_url( gwc_vt_verify_action_url( 'gwc_vt_verify_entry', $entry_id ) ),
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
 * @param string $returnto Where to come back to. Defaults to the current screen.
 * @return string
 */
function gwc_vt_verify_action_url( string $action, int $entry_id, string $returnto = '' ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'   => $action,
				'entry'    => $entry_id,
				'returnto' => rawurlencode( '' !== $returnto ? $returnto : gwc_vt_current_admin_url() ),
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
function gwc_vt_current_admin_url(): string {
	$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

	return admin_url( 'edit.php' . ( '' !== $query ? '?' . $query : '?post_type=' . GWC_VT_ENTRY_TYPE ) );
}


/* ── The queue, grouped by the person it is about ────────────────────────────
 * Six waiting entries meant six trips through the post editor. Attestation does
 * not happen one entry at a time — it happens one person at a time, because the
 * thing a supervisor is remembering is "yes, Priya was here, all three times".
 * So the screen is grouped by volunteer and every row has its own button.
 *
 * ── It is a fourth caller, not a fourth write path ───────────────────────────
 * The question this screen had to answer before it was worth building was
 * whether it becomes a third place to keep the capability check right, beside
 * the list table and the entry editor. It does not, and the reason is that
 * there was never more than one place: gwc_vt_verify_entry() checks
 * authorization itself, through the attestation method registry's can_apply,
 * because it is also reachable from bulk actions and from WP-CLI.
 *
 * So this screen adds no handler. Every button here is a nonced link to
 * gwc_vt_handle_verify_entry() — the same URL the list table's row action
 * builds, from the same gwc_vt_verify_action_url(), which already carries a
 * returnto and already comes back to wherever it was clicked from.
 *
 * The list table keeps its bulk actions and stays the general-purpose view of
 * every hour ever logged. This is the queue.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Add the screen, and take it straight off the menu.
 *
 * The menu lists six nouns and this is a view of one of them. It is reached
 * from the dashboard's worklist line and from the All hours screen's own list
 * of views, which is where WordPress puts "the same list, narrowed".
 */
function gwc_vt_register_verify_queue(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_verify_queue_title(),
		gwc_vt_verify_queue_title(),
		'edit_posts',
		GWC_VT_VERIFY_PAGE,
		'gwc_vt_render_verify_queue'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_verify_queue_title' );
	}
}

/**
 * The screen's title, said once.
 *
 * @return string
 */
function gwc_vt_verify_queue_title(): string {
	return __( 'All hours — verify', 'groundwork-common-volunteer-tracker' );
}

/**
 * Give the screen its title back.
 *
 * Taken off the menu by gwc_vt_hidden_menu_items(), and get_admin_page_title()
 * reads a page's title out of $submenu — see the longer note on
 * gwc_vt_restore_quick_add_title(), which is the same problem for the same
 * reason.
 */
function gwc_vt_restore_verify_queue_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_verify_queue_title();
}

/**
 * The screen's own URL.
 *
 * @return string
 */
function gwc_vt_verify_queue_url(): string {
	return add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_VERIFY_PAGE,
		),
		admin_url( 'edit.php' )
	);
}

/**
 * Offer it beside "All" on the hours list.
 *
 * The list table's views are where WordPress puts "the same list, narrowed",
 * and this is the only place on that screen it would not be a fourth button
 * competing with the two for logging.
 *
 * @param array $views The list table's view links.
 * @return array
 */
function gwc_vt_verify_queue_view( $views ): array {
	$views = (array) $views;

	if ( ! current_user_can( 'edit_posts' ) ) {
		return $views;
	}

	$waiting = gwc_vt_unverified_count();

	/* Nothing waiting, nothing to offer — the same rule the dashboard worklist
	 * follows. A link reading "Waiting to verify (0)" is a link nobody needs to
	 * be told about. */
	if ( $waiting < 1 ) {
		return $views;
	}

	$views['gwc_vt_verify'] = sprintf(
		'<a href="%1$s">%2$s <span class="count">(%3$s)</span></a>',
		esc_url( gwc_vt_verify_queue_url() ),
		esc_html__( 'Waiting to verify', 'groundwork-common-volunteer-tracker' ),
		esc_html( number_format_i18n( $waiting ) )
	);

	return $views;
}

/**
 * The queue.
 */
function gwc_vt_render_verify_queue(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$waiting = gwc_vt_unverified_count();
	$entries = gwc_vt_unverified_entry_ids();

	/* Grouped by the person, and the unmatched ones held apart. An entry nobody
	 * has said whose it is cannot be attested to — that is the verify/log/match
	 * separation, and this screen shows it rather than hiding those rows. */
	$groups    = array();
	$unmatched = array();

	foreach ( $entries as $entry_id ) {
		$volunteer_id = gwc_vt_entry_volunteer_id( $entry_id );

		if ( $volunteer_id < 1 ) {
			$unmatched[] = $entry_id;
			continue;
		}

		$groups[ $volunteer_id ][] = $entry_id;
	}
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_verify_queue_title() ); ?></h1>

		<a href="<?php echo esc_url( gwc_vt_quick_add_url() ); ?>" class="page-title-action">
			<?php esc_html_e( 'Log a day', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Log one shift', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwc_vt_bulk_action_notice(); ?>
		<?php gwc_vt_render_verify_letter_cta(); ?>

		<p class="gwcvt-verify__intro">
			<?php esc_html_e( 'Verifying an entry is you attesting the shift happened. Only verified hours appear on a letter.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<div class="gwcvt-verify__summary gwcvt-verify__summary--<?php echo esc_attr( $waiting > 0 ? 'waiting' : 'clear' ); ?>">
			<span class="gwcvt-verify__count"><?php echo esc_html( number_format_i18n( $waiting ) ); ?></span>
			<span>
				<?php
				echo $waiting > 0
					? esc_html(
						sprintf(
							/* translators: %s: how many entries are waiting, already formatted. */
							_n(
								'entry is waiting. Only verified hours reach a letter, so it counts for nobody yet.',
								'entries are waiting. Only verified hours reach a letter, so these count for nobody yet.',
								$waiting,
								'groundwork-common-volunteer-tracker'
							),
							number_format_i18n( $waiting )
						)
					)
					: esc_html__( 'Nothing is waiting. Every hour logged has somebody’s name against it.', 'groundwork-common-volunteer-tracker' );
				?>
			</span>
		</div>

		<?php
		foreach ( $groups as $volunteer_id => $ids ) {
			gwc_vt_render_verify_group( (int) $volunteer_id, $ids );
		}

		if ( $unmatched ) {
			gwc_vt_render_verify_unmatched( $unmatched );
		}
		?>
	</div>
	<?php
}

/* ── Closing the loop the dashboard opens ────────────────────────────────────
 * The design puts "All verified — produce their letter →" on the volunteer's
 * group header the moment their last waiting entry is attested to. On a screen
 * that reloads, that group is gone by then: verifying the last one takes them
 * out of the queue, which is the queue working correctly.
 *
 * So the sentence moves to where the moment actually happens — a notice at the
 * top of the screen they land back on, naming the person and offering the
 * letter. It fires only when it is true: the volunteer is looked up again after
 * the write, and the offer appears only if nothing of theirs is waiting any
 * more. Somebody who verified the second-to-last entry gets no offer, which is
 * the honest answer.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Where a verify button on this screen comes back to.
 *
 * Carries the volunteer so the screen can check, after the write, whether that
 * was the last one.
 *
 * @param int $entry_id The entry being verified.
 * @return string
 */
function gwc_vt_verify_return_url( int $entry_id ): string {
	$volunteer_id = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, true );

	if ( $volunteer_id < 1 ) {
		return gwc_vt_verify_queue_url();
	}

	return add_query_arg( 'gwc_vt_finished', $volunteer_id, gwc_vt_verify_queue_url() );
}

/**
 * "All verified — produce their letter", when that is now true.
 */
function gwc_vt_render_verify_letter_cta(): void {
	if ( ! gwc_vt_letters_enabled() || ! current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the write it follows checked its own nonce, and this only decides whether to offer a link.
	$volunteer_id = isset( $_GET['gwc_vt_finished'] ) ? absint( wp_unslash( $_GET['gwc_vt_finished'] ) ) : 0;

	if ( $volunteer_id < 1 || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return;
	}

	/* Asked again rather than assumed. The button that sent us here verified one
	 * entry; whether it was the LAST one is a question about everything else on
	 * file, and the answer may have changed while somebody was reading. */
	foreach ( gwc_vt_entry_ids_for_volunteer( $volunteer_id ) as $entry_id ) {
		if ( ! gwc_vt_entry_is_verified( (int) $entry_id ) ) {
			return;
		}
	}

	$letter = gwc_vt_produce_letter_url( $volunteer_id );
	?>
	<div class="notice notice-success gwcvt-verify__done">
		<p>
			<?php
			printf(
				/* translators: %s: a volunteer's name. */
				esc_html__( 'Everything on file for %s is verified.', 'groundwork-common-volunteer-tracker' ),
				esc_html( get_the_title( $volunteer_id ) )
			);
			?>
			<a href="<?php echo esc_url( $letter ); ?>">
				<?php esc_html_e( 'Produce their letter', 'groundwork-common-volunteer-tracker' ); ?> &rarr;
			</a>
		</p>
	</div>
	<?php
}

/**
 * One volunteer, and everything of theirs that is waiting.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param int[] $ids          Their unverified entries.
 */
function gwc_vt_render_verify_group( int $volunteer_id, array $ids ): void {
	/* How many of this person's hours are done, out of everything on file — not
	 * out of what is on this screen. "2 of 9 verified" is the sentence somebody
	 * needs before they decide whether to produce a letter; "0 of 3" would only
	 * describe the queue, which they can already see. */
	$all      = gwc_vt_entry_ids_for_volunteer( $volunteer_id );
	$total    = count( $all );
	$verified = $total - count( $ids );
	?>
	<div class="gwcvt-verify__group">
		<div class="gwcvt-verify__head">
			<strong><?php echo esc_html( get_the_title( $volunteer_id ) ); ?></strong>
			<span class="gwcvt-verify__note">
				<?php
				printf(
					/* translators: 1: how many of their entries are verified. 2: how many they have. */
					esc_html__( '%1$s of %2$s verified', 'groundwork-common-volunteer-tracker' ),
					esc_html( number_format_i18n( $verified ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</div>

		<?php foreach ( $ids as $entry_id ) : ?>
			<?php gwc_vt_render_verify_row( (int) $entry_id ); ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * One waiting entry.
 *
 * @param int $entry_id Entry post ID.
 */
function gwc_vt_render_verify_row( int $entry_id ): void {
	$minutes    = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );
	$activity   = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true );
	$supervisor = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_SUPERVISOR, true );
	$date       = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true );
	$may        = gwc_vt_user_can_verify( get_current_user_id(), $entry_id );
	?>
	<div class="gwcvt-verify__row">
		<span class="gwcvt-verify__date"><?php echo esc_html( '' !== $date ? gwc_vt_shift_date_label_from( $date ) : '' ); ?></span>

		<?php /* Rendered from integer minutes, never from a stored decimal. */ ?>
		<span class="gwcvt-verify__hours"><?php echo esc_html( gwc_vt_format_hours( $minutes ) ); ?></span>

		<span class="gwcvt-verify__what">
			<a href="<?php echo esc_url( get_edit_post_link( $entry_id ) ); ?>"><?php echo esc_html( $activity ); ?></a>
			<?php if ( '' !== $supervisor ) : ?>
				<span class="gwcvt-verify__by">
					<?php
					printf(
						/* translators: %s: a staff member's name. */
						esc_html__( 'Supervised by %s', 'groundwork-common-volunteer-tracker' ),
						esc_html( $supervisor )
					);
					?>
				</span>
			<?php endif; ?>
		</span>

		<span class="gwcvt-verify__source"><?php echo esc_html( gwc_vt_entry_source_label( $entry_id ) ); ?></span>

		<span class="gwcvt-verify__act">
			<?php if ( $may ) : ?>
				<?php
				/* The same nonced URL the list table's row action builds, going
				 * to the same handler. The weight of the word is the point:
				 * this is somebody saying the shift happened, and a button
				 * reading "Verify" alone lets it feel like filing. */
				?>
				<a class="button" href="<?php echo esc_url( gwc_vt_verify_action_url( 'gwc_vt_verify_entry', $entry_id, gwc_vt_verify_return_url( $entry_id ) ) ); ?>">
					<?php esc_html_e( 'I attest — verify', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php else : ?>
				<span class="gwcvt-verify__cannot"><?php esc_html_e( 'Not yours to verify', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</span>
	</div>
	<?php
}

/**
 * The claims nobody has said whose they are.
 *
 * @param int[] $ids Unmatched entries.
 */
function gwc_vt_render_verify_unmatched( array $ids ): void {
	?>
	<div class="gwcvt-verify__group gwcvt-verify__group--unmatched">
		<div class="gwcvt-verify__head">
			<strong><?php esc_html_e( 'Sent through the public form — match first', 'groundwork-common-volunteer-tracker' ); ?></strong>
			<span class="gwcvt-verify__note">
				<?php esc_html_e( 'A claim until somebody says whose it is', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>

		<?php foreach ( $ids as $entry_id ) : ?>
			<?php
			$entry_id = (int) $entry_id;
			$minutes  = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );
			$date     = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true );
			$claimed  = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_CLAIM_NAME, true );
			?>
			<div class="gwcvt-verify__row gwcvt-verify__row--unmatched">
				<span class="gwcvt-verify__date"><?php echo esc_html( '' !== $date ? gwc_vt_shift_date_label_from( $date ) : '' ); ?></span>
				<span class="gwcvt-verify__hours"><?php echo esc_html( gwc_vt_format_hours( $minutes ) ); ?></span>

				<span class="gwcvt-verify__what">
					<a href="<?php echo esc_url( get_edit_post_link( $entry_id ) ); ?>">
						<?php echo esc_html( '' !== $claimed ? $claimed : __( 'Somebody', 'groundwork-common-volunteer-tracker' ) ); ?>
					</a>
					<span class="gwcvt-verify__by"><?php echo esc_html( (string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true ) ); ?></span>
				</span>

				<?php
				/* The match controls, reused from the triage screen rather than
				 * rebuilt: gwc_vt_render_triage_actions() already offers the
				 * suggestion, the picker and "create a record from this", and it
				 * already refuses anybody who may not read a volunteer. */
				?>
				<span class="gwcvt-verify__match" colspan="2">
					<?php gwc_vt_render_triage_actions( $entry_id ); ?>
				</span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ── The handlers ────────────────────────────────────────────────────────── */

/**
 * Verify one entry.
 */
function gwc_vt_handle_verify_entry(): void {
	$entry_id = gwc_vt_verify_request_entry( 'gwc_vt_verify_entry' );

	gwc_vt_verify_entry( $entry_id, get_current_user_id() );

	gwc_vt_redirect_back( 'verified' );
}

/**
 * Withdraw one entry's verification.
 */
function gwc_vt_handle_unverify_entry(): void {
	$entry_id = gwc_vt_verify_request_entry( 'gwc_vt_unverify_entry' );

	gwc_vt_unverify_entry( $entry_id );

	gwc_vt_redirect_back( 'unverified' );
}

/**
 * The shared front half of both handlers: which entry, and may you.
 *
 * Capability before nonce, house rule. Both are checked here rather than in
 * gwc_vt_verify_entry() as well as here — the model function checks
 * authorization too, through the method registry's can_apply, because it is
 * also reachable from bulk actions and from WP-CLI.
 *
 * @param string $action The admin_post action, which is also the nonce prefix.
 * @return int
 */
function gwc_vt_verify_request_entry( string $action ): int {
	$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;

	if ( $entry_id < 1 || GWC_VT_ENTRY_TYPE !== get_post_type( $entry_id ) ) {
		wp_die(
			esc_html__( 'That hour entry does not exist.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	if ( ! gwc_vt_user_can_verify( get_current_user_id(), $entry_id ) ) {
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
function gwc_vt_redirect_back( string $result ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in gwc_vt_verify_request_entry() before this runs.
	$raw = isset( $_GET['returnto'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['returnto'] ) ) ) : '';

	$target = '' !== $raw ? $raw : admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE );

	/* wp_safe_redirect, not wp_redirect. The return URL came in on the query
	 * string, and although it was put there by our own link a moment ago, a
	 * redirect target from a request parameter is an open redirect unless
	 * something checks the host. wp_safe_redirect is that something. */
	wp_safe_redirect( add_query_arg( 'gwc_vt_result', $result, $target ) );
	exit;
}

/* ── Bulk ────────────────────────────────────────────────────────────────── */

/**
 * Add Verify and Withdraw to the bulk menu.
 *
 * @param array $actions Existing bulk actions.
 * @return array
 */
function gwc_vt_register_bulk_actions( $actions ): array {
	$actions = (array) $actions;

	if ( ! current_user_can( gwc_vt_cap( 'verify' ) ) ) {
		return $actions;
	}

	$actions['gwc_vt_verify']   = __( 'Verify', 'groundwork-common-volunteer-tracker' );
	$actions['gwc_vt_unverify'] = __( 'Withdraw verification', 'groundwork-common-volunteer-tracker' );

	return $actions;
}

/**
 * Apply a bulk verify or withdraw.
 *
 * Core supplies and checks the nonce for bulk actions, which is the whole
 * reason this goes through handle_bulk_actions- rather than a hand-rolled
 * admin_post handler with a checkbox list of its own.
 *
 * Each entry is still authorized individually through gwc_vt_verify_entry(): a
 * user who may verify most of a selection but not one of them verifies the rest
 * and silently skips that one, rather than the selection succeeding or failing
 * as a block.
 *
 * @param string $redirect_to Where core will send the browser.
 * @param string $action      Which bulk action was chosen.
 * @param array  $post_ids    The selected posts.
 * @return string
 */
function gwc_vt_handle_bulk_actions( $redirect_to, $action, $post_ids ): string {
	if ( ! in_array( $action, array( 'gwc_vt_verify', 'gwc_vt_unverify' ), true ) ) {
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
	if ( 'gwc_vt_unverify' === $action ) {
		$eligible = array();

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( gwc_vt_user_can_verify( $user_id, $post_id ) && gwc_vt_entry_is_verified( $post_id ) ) {
				$eligible[] = $post_id;
			}
		}

		if ( ! $eligible ) {
			return add_query_arg(
				array(
					'gwc_vt_result'  => 'unverified',
					'gwc_vt_done'    => 0,
					'gwc_vt_skipped' => count( (array) $post_ids ),
				),
				$redirect_to
			);
		}

		return add_query_arg(
			array(
				'gwc_vt_confirm' => 'unverify',
				'gwc_vt_ids'     => implode( ',', $eligible ),
				'gwc_vt_skipped' => max( 0, count( (array) $post_ids ) - count( $eligible ) ),
			),
			$redirect_to
		);
	}

	$done     = 0;
	$skipped  = 0;
	$nameless = 0;

	foreach ( (array) $post_ids as $post_id ) {
		$post_id = (int) $post_id;

		if ( ! gwc_vt_user_can_verify( $user_id, $post_id ) ) {
			++$skipped;
			continue;
		}

		/* Counted apart from $skipped, because the two are not the same news and
		 * the notice has to be able to say which happened. "You cannot verify
		 * it" is about this user's permissions and there is nothing they can do;
		 * "nobody has said whose these are" is about the record and there is
		 * something they can do about it. Reporting the second as the first
		 * sends a coordinator to an administrator over a record they could have
		 * matched themselves. */
		if ( 'gwc_vt_verify' === $action && gwc_vt_entry_volunteer_id( $post_id ) < 1 ) {
			++$nameless;
			continue;
		}

		$ok = 'gwc_vt_verify' === $action
			? gwc_vt_verify_entry( $post_id, $user_id )
			: gwc_vt_unverify_entry( $post_id );

		if ( $ok ) {
			++$done;
		} else {
			++$skipped;
		}
	}

	return add_query_arg(
		array(
			'gwc_vt_result'   => 'gwc_vt_verify' === $action ? 'verified' : 'unverified',
			'gwc_vt_done'     => $done,
			'gwc_vt_skipped'  => $skipped,
			'gwc_vt_nameless' => $nameless,
		),
		$redirect_to
	);
}

/**
 * The entry IDs a confirmation is being asked about.
 *
 * @return int[]
 */
function gwc_vt_confirm_ids(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; nothing is written until the confirmation form below is submitted with its own nonce.
	$raw = isset( $_GET['gwc_vt_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_ids'] ) ) : '';

	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	return array_values( array_unique( $ids ) );
}

/**
 * Ask before withdrawing a selection's attestations.
 */
function gwc_vt_bulk_unverify_confirm(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$confirm = isset( $_GET['gwc_vt_confirm'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_confirm'] ) ) : '';

	if ( 'unverify' !== $confirm ) {
		return;
	}

	$ids = gwc_vt_confirm_ids();

	if ( ! $ids ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwc_vt_skipped'] ) ? absint( wp_unslash( $_GET['gwc_vt_skipped'] ) ) : 0;
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
			<?php esc_html_e( 'The hours themselves are untouched. They stop counting toward anything, and they stop appearing as verified on a letter, until somebody attests to them again.', 'groundwork-common-volunteer-tracker' ); ?>
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
			<input type="hidden" name="action" value="gwc_vt_bulk_unverify" />
			<input type="hidden" name="gwc_vt_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
			<input type="hidden" name="returnto" value="<?php echo esc_attr( gwc_vt_current_list_url() ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_bulk_unverify' ); ?>

			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Withdraw verification', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( gwc_vt_current_list_url() ); ?>">
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
function gwc_vt_current_list_url(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- building a link back to the screen the user is already on.
	$query = wp_unslash( $_GET );

	unset( $query['gwc_vt_confirm'], $query['gwc_vt_ids'], $query['gwc_vt_skipped'], $query['_wpnonce'] );

	$clean = array();

	foreach ( (array) $query as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
	}

	$clean['post_type'] = GWC_VT_ENTRY_TYPE;

	return add_query_arg( $clean, admin_url( 'edit.php' ) );
}

/**
 * Withdraw a confirmed selection.
 *
 * Every entry is authorized again here rather than trusted from the confirmation
 * link — the ids traveled through a URL, and a handler that acts on what a URL
 * told it is a handler that acts on whatever anybody puts in one.
 */
function gwc_vt_handle_bulk_unverify(): void {
	gwc_vt_require_cap( 'verify' );
	check_admin_referer( 'gwc_vt_bulk_unverify' );

	$raw = isset( $_POST['gwc_vt_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['gwc_vt_ids'] ) ) : '';
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

	$user_id = get_current_user_id();
	$done    = 0;
	$skipped = 0;

	foreach ( array_unique( $ids ) as $entry_id ) {
		$entry_id = (int) $entry_id;

		if ( ! gwc_vt_user_can_verify( $user_id, $entry_id ) || ! gwc_vt_unverify_entry( $entry_id ) ) {
			++$skipped;
			continue;
		}

		++$done;
	}

	$returnto = isset( $_POST['returnto'] ) ? esc_url_raw( wp_unslash( $_POST['returnto'] ) ) : '';
	$base     = '' !== $returnto ? $returnto : add_query_arg( 'post_type', GWC_VT_ENTRY_TYPE, admin_url( 'edit.php' ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'gwc_vt_result'  => 'unverified',
				'gwc_vt_done'    => $done,
				'gwc_vt_skipped' => $skipped,
			),
			$base
		)
	);
	exit;
}

/**
 * Say what the last action did.
 */
function gwc_vt_bulk_action_notice(): void {
	/* Scoped to the screen the redirect actually lands on, like every other
	 * notice this plugin adds. Reading a query argument was not enough on its
	 * own: gwc_vt_result is just a string in a URL, so wp-admin/index.php?
	 * gwc_vt_result=verified&gwc_vt_done=999 printed "999 entries verified" on
	 * the dashboard, on somebody else's screen, about work nobody had done.
	 * Harmless as a prank and wrong twice over — guideline 11 is about not
	 * putting notices on screens that are not yours, and this plugin's whole
	 * claim is that what it says about a record is true. */
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; this only decides which sentence to print after a redirect.
	$result = isset( $_GET['gwc_vt_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_result'] ) ) : '';

	if ( ! in_array( $result, array( 'verified', 'unverified' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$done = isset( $_GET['gwc_vt_done'] ) ? absint( wp_unslash( $_GET['gwc_vt_done'] ) ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwc_vt_skipped'] ) ? absint( wp_unslash( $_GET['gwc_vt_skipped'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$nameless = isset( $_GET['gwc_vt_nameless'] ) ? absint( wp_unslash( $_GET['gwc_vt_nameless'] ) ) : 0;

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

	if ( $nameless > 0 ) {
		$message .= ' ' . sprintf(
			/* translators: %d: number of hour entries that name no volunteer. */
			_n(
				'%d names no volunteer yet — match it to somebody and it can be verified.',
				'%d name no volunteer yet — match them to somebody and they can be verified.',
				$nameless,
				'groundwork-common-volunteer-tracker'
			),
			$nameless
		);
	}

	/* Green only when something actually happened. A selection where every row
	 * was skipped used to come back as a success notice reading "0 entries
	 * verified. 12 were skipped" — which announces, in the color reserved for
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
function gwc_vt_verified_filter_dropdown( $post_type ): void {
	if ( GWC_VT_ENTRY_TYPE !== $post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$current = isset( $_GET['gwc_vt_state'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_state'] ) ) : '';
	?>
	<label class="screen-reader-text" for="gwc_vt_state">
		<?php esc_html_e( 'Filter by verification', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="gwc_vt_state" id="gwc_vt_state">
		<option value=""><?php esc_html_e( 'Any verification state', 'groundwork-common-volunteer-tracker' ); ?></option>
		<option value="unverified" <?php selected( $current, 'unverified' ); ?>>
			<?php echo esc_html( gwc_vt_verification_label( 'unverified' ) ); ?>
		</option>
		<option value="verified" <?php selected( $current, 'verified' ); ?>>
			<?php echo esc_html( gwc_vt_verification_label( 'verified' ) ); ?>
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
 * INNER JOIN, so an entry with no _gwc_vt_date would silently vanish from the
 * list. The EXISTS-or-NOT-EXISTS pair keeps those rows in, sorted last — a
 * dateless entry is broken and needs a human, and hiding it is the worst
 * possible response.
 *
 * @param WP_Query $query The query about to run.
 */
function gwc_vt_apply_verified_filter( $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( GWC_VT_ENTRY_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$state = isset( $_GET['gwc_vt_state'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_state'] ) ) : '';

	$clauses = array( 'relation' => 'AND' );

	if ( in_array( $state, array( 'verified', 'unverified' ), true ) ) {
		$clauses[] = array(
			'key'     => GWC_VT_ENTRY_VERIFIED_AT,
			'compare' => 'verified' === $state ? 'EXISTS' : 'NOT EXISTS',
		);
	}

	if ( 'unmatched' === $state ) {
		/* Sent in through the public form and not yet attached to anybody.
		 * Stored as the string '0', so a value comparison rather than
		 * NOT EXISTS — the key is always written. */
		$clauses[] = array(
			'key'     => GWC_VT_ENTRY_VOLUNTEER,
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
		'relation'          => 'OR',
		'gwc_vt_shift_date' => array(
			'key'     => GWC_VT_ENTRY_DATE,
			'compare' => 'EXISTS',
		),
		array(
			'key'     => GWC_VT_ENTRY_DATE,
			'compare' => 'NOT EXISTS',
		),
	);

	// An admin list screen, paginated by core.
	$query->set( 'meta_query', $clauses );

	// An explicit sort from the column headers wins.
	if ( '' === (string) $query->get( 'orderby' ) ) {
		$query->set(
			'orderby',
			array(
				'gwc_vt_shift_date' => 'DESC',
				'ID'                => 'DESC',
			)
		);
	}
}

/* ── On the entry itself ─────────────────────────────────────────────────── */

/**
 * A verification panel in the sidebar of the entry editor.
 */
function gwc_vt_add_verify_meta_box(): void {
	if ( ! current_user_can( gwc_vt_cap( 'verify' ) ) ) {
		return;
	}

	add_meta_box(
		'gwc-vt-verify',
		__( 'Verification', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_verify_meta_box',
		GWC_VT_ENTRY_TYPE,
		'side',
		'high'
	);
}

/**
 * Render it.
 *
 * @param WP_Post $post The entry.
 */
function gwc_vt_render_verify_meta_box( $post ): void {
	$entry_id = (int) $post->ID;
	$context  = gwc_vt_attestation_context( $entry_id );

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
			esc_html( gwc_vt_verification_label( 'verified' ) ),
			esc_html( gwc_vt_attestation_line( $entry_id ) )
		);

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( gwc_vt_verify_action_url( 'gwc_vt_unverify_entry', $entry_id ) ),
			esc_html__( 'Withdraw verification', 'groundwork-common-volunteer-tracker' )
		);

		return;
	}

	printf(
		'<p><span class="gwcvt-badge gwcvt-badge--waiting">%s</span></p>',
		esc_html( gwc_vt_verification_label( 'unverified' ) )
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Verifying records that you, as a member of staff, attest these hours were worked. Your name and the date are recorded and appear on the verification letter.', 'groundwork-common-volunteer-tracker' )
	);

	printf(
		'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
		esc_url( gwc_vt_verify_action_url( 'gwc_vt_verify_entry', $entry_id ) ),
		esc_html__( 'Verify these hours', 'groundwork-common-volunteer-tracker' )
	);
}

/* ── The bubble ──────────────────────────────────────────────────────────── */

/**
 * Put the unverified count next to the menu item, the way core does for
 * comments and updates.
 *
 * Core's own markup and classes rather than something bespoke, so it inherits
 * the admin color scheme and reads as part of WordPress rather than as a
 * decoration this plugin added.
 */
function gwc_vt_add_pending_bubble(): void {
	global $menu;

	if ( ! is_array( $menu ) || ! current_user_can( gwc_vt_cap( 'verify' ) ) ) {
		return;
	}

	$count = gwc_vt_unverified_count();

	if ( $count < 1 ) {
		return;
	}

	$slug = 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE;

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

<?php
/**
 * Naming the partners volunteers come with, and folding the duplicates.
 *
 * ── Two screens, on purpose ─────────────────────────────────────────────────
 * This one lists partners, proposes the duplicates, and merges. Editing
 * one — its name, its parent, its CRM ID and contact — is core's own term
 * editor at edit-tags.php, where gwc_vt_partner_edit_fields() renders the four
 * fields below.
 *
 * That split is the whole argument for a taxonomy. Renaming a term changes the
 * word everywhere at once with no record moving, and core already does that
 * correctly, including the slug, the cache and the list table. Rebuilding it
 * here would be a second implementation of the one thing we were not going to
 * have to write.
 *
 * What core does NOT do is merge, and that is not a gap in its UI — there is no
 * merge in wp-admin/edit-tags.php, none in the terms list table, and none in
 * the API. Renaming a near-duplicate onto the real one fails outright with
 * duplicate_term_slug. So the merge is this screen, and it is why this screen
 * exists at all.
 *
 * ── Adding one is deliberate ────────────────────────────────────────────────
 * There is a small add form here, and there is deliberately no way to create an
 * partner from a volunteer's record. The taxonomy is hierarchical so that
 * the metabox on a volunteer is a checkbox list of things that already exist —
 * see the long note in inc/partner-taxonomy.php. Somebody typing a company's name
 * into a person's record is exactly how "Acme Corp" and "ACME Corp." both come
 * to exist, and the whole feature is built to stop that.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_partners_screen', 14 );
add_action( 'admin_post_gwc_vt_add_partner', 'gwc_vt_handle_add_partner' );
add_action( 'admin_post_gwc_vt_merge_partners', 'gwc_vt_handle_merge_partners' );

/* The four fields, on core's own add and edit forms. */
add_action( GWC_VT_PARTNER_TAXONOMY . '_add_form_fields', 'gwc_vt_partner_add_fields' );
add_action( GWC_VT_PARTNER_TAXONOMY . '_edit_form_fields', 'gwc_vt_partner_edit_fields' );
add_action( 'created_' . GWC_VT_PARTNER_TAXONOMY, 'gwc_vt_save_partner_fields' );
add_action( 'edited_' . GWC_VT_PARTNER_TAXONOMY, 'gwc_vt_save_partner_fields' );

/* And the filter, on both lists a partner can be attached to. */
add_action( 'restrict_manage_posts', 'gwc_vt_partner_filter_dropdown', 12 );
add_action( 'pre_get_posts', 'gwc_vt_apply_partner_filter' );

/* The total, above the hours list, whenever that filter is on. */
add_action( 'admin_notices', 'gwc_vt_partner_hours_summary' );

/**
 * Register the screen.
 */
function gwc_vt_register_partners_screen(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_partners_title(),
		gwc_vt_partners_title(),
		gwc_vt_records_cap(),
		GWC_VT_PARTNERS_PAGE,
		'gwc_vt_render_partners_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_partners_title' );
	}
}

/**
 * The screen's name.
 *
 * @return string
 */
function gwc_vt_partners_title(): string {
	return __( 'Partners', 'groundwork-common-volunteer-tracker' );
}

/**
 * Put the title back after the menu is reordered.
 *
 * The title is read out of $submenu by get_admin_page_title(), and the
 * ordering pass rewrites that array — the same fix every other screen carries.
 */
function gwc_vt_restore_partners_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a <title> and nothing else.
	$merging = isset( $_GET['merge'] );

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = $merging
		? __( 'Fold partners together', 'groundwork-common-volunteer-tracker' )
		: gwc_vt_partners_title();
}

/**
 * A URL for this screen.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function gwc_vt_partners_url( array $args = array() ): string {
	return add_query_arg(
		array_merge( array( 'page' => GWC_VT_PARTNERS_PAGE ), $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * Where core edits one partner.
 *
 * Built here rather than typed at three call sites, because the taxonomy is
 * show_in_menu => false and the URL therefore needs the post type spelled out
 * or core cannot work out which menu to highlight.
 *
 * @param int $term_id Term ID.
 * @return string
 */
function gwc_vt_partner_edit_url( int $term_id ): string {
	return add_query_arg(
		array(
			'taxonomy'  => GWC_VT_PARTNER_TAXONOMY,
			'post_type' => GWC_VT_VOLUNTEER_TYPE,
			'tag_ID'    => $term_id,
		),
		admin_url( 'term.php' )
	);
}

/* ── The screen ──────────────────────────────────────────────────────────── */

/**
 * The list, or the merge form.
 */
function gwc_vt_render_partners_screen(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die( esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; chooses which view to draw, and the merge itself is nonced on POST.
	$merging = isset( $_GET['merge'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['merge'] ) ) : array();
	$merging = array_values( array_filter( $merging ) );

	if ( $merging ) {
		gwc_vt_render_partner_merge_form( $merging );
		return;
	}

	gwc_vt_render_partners_list();
}

/**
 * Every partner, what carries it, and what to do about the duplicates.
 */
function gwc_vt_render_partners_list(): void {
	$partners   = gwc_vt_partner_terms();
	$duplicates = gwc_vt_partner_duplicate_clusters();
	?>
	<div class="wrap gwcvt-partners">
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_partners_title() ); ?></h1>
		<hr class="wp-header-end" />

		<?php gwc_vt_partner_notice(); ?>

		<p class="gwcvt-partners__intro">
			<?php esc_html_e( 'The companies, schools and groups your volunteers come with. Naming one here is what lets you count its hours as one number instead of several.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php if ( $duplicates ) : ?>
			<div class="notice notice-warning gwcvt-partners__duplicates">
				<h2><?php esc_html_e( 'These look like the same partner', 'groundwork-common-volunteer-tracker' ); ?></h2>

				<p>
					<?php esc_html_e( 'Their names match once punctuation, capitals and words like “Inc” are set aside. Nothing has been changed — look at each one and decide.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<ul>
					<?php foreach ( $duplicates as $cluster ) : ?>
						<?php
						$ids   = array();
						$names = array();

						foreach ( $cluster as $term ) {
							$ids[]   = (int) $term->term_id;
							$names[] = (string) $term->name;
						}
						?>
						<li>
							<strong><?php echo esc_html( implode( ' · ', $names ) ); ?></strong>
							<a href="<?php echo esc_url( gwc_vt_partners_url( array( 'merge' => $ids ) ) ); ?>">
								<?php esc_html_e( 'Look at these', 'groundwork-common-volunteer-tracker' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( ! $partners ) : ?>
			<p><?php esc_html_e( 'No partners yet. Add the first one below.', 'groundwork-common-volunteer-tracker' ); ?></p>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( GWC_VT_PARTNERS_PAGE ); ?>" />

				<table class="wp-list-table widefat fixed striped gwcvt-partners__table">
					<thead>
						<tr>
							<td class="manage-column check-column"></td>
							<th scope="col"><?php esc_html_e( 'Partner', 'groundwork-common-volunteer-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hours verified', 'groundwork-common-volunteer-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?></th>
							<th scope="col"><?php esc_html_e( 'CRM ID', 'groundwork-common-volunteer-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $partners as $partner ) : ?>
							<?php gwc_vt_render_partner_row( $partner ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="gwcvt-partners__actions">
					<button type="submit" class="button">
						<?php esc_html_e( 'Fold the selected ones together', 'groundwork-common-volunteer-tracker' ); ?>
					</button>
					<span class="description">
						<?php esc_html_e( 'You choose which one survives on the next screen. Nothing changes until then.', 'groundwork-common-volunteer-tracker' ); ?>
					</span>
				</p>
			</form>
		<?php endif; ?>

		<?php gwc_vt_render_partner_add_form(); ?>
	</div>
	<?php
}

/**
 * One row.
 *
 * @param WP_Term $partner The partner.
 */
function gwc_vt_render_partner_row( WP_Term $partner ): void {
	$fields = gwc_vt_partner_field_values( (int) $partner->term_id );

	/* Not $partner->count. That column is one number across every object type in
	 * the taxonomy, so the moment #211 registers entries it becomes volunteers
	 * plus entries added together — see gwc_vt_partner_counts(). */
	$counts     = gwc_vt_partner_counts( (int) $partner->term_id );
	$volunteers = (int) ( $counts[ GWC_VT_VOLUNTEER_TYPE ] ?? 0 );
	$url        = gwc_vt_partner_volunteers_url( (int) $partner->term_id );
	?>
	<tr>
		<th scope="row" class="check-column">
			<label class="screen-reader-text" for="gwcvt-partner-<?php echo esc_attr( (string) $partner->term_id ); ?>">
				<?php
				printf(
					/* translators: %s: a partner's name. */
					esc_html__( 'Select %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( $partner->name )
				);
				?>
			</label>
			<input
				type="checkbox"
				id="gwcvt-partner-<?php echo esc_attr( (string) $partner->term_id ); ?>"
				name="merge[]"
				value="<?php echo esc_attr( (string) $partner->term_id ); ?>"
			/>
		</th>
		<td>
			<strong><a href="<?php echo esc_url( gwc_vt_partner_edit_url( (int) $partner->term_id ) ); ?>"><?php echo esc_html( $partner->name ); ?></a></strong>

			<?php if ( (int) $partner->parent > 0 ) : ?>
				<?php $parent = gwc_vt_partner( (int) $partner->parent ); ?>
				<?php if ( $parent ) : ?>
					<div class="row-actions">
						<?php
						printf(
							/* translators: %s: the name of a parent partner. */
							esc_html__( 'Part of %s', 'groundwork-common-volunteer-tracker' ),
							esc_html( $parent->name )
						);
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $volunteers > 0 && '' !== $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( number_format_i18n( $volunteers ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( number_format_i18n( $volunteers ) ); ?>
			<?php endif; ?>
		</td>
		<td>
			<?php
			/* ── The number this whole feature exists to produce ──────────────
			 * Counted from ENTRIES, never from the people in the column beside
			 * it. Somebody who came once with Acme and twice on their own is one
			 * volunteer and three entries, and only one of those two numbers is
			 * an answer to "what did Acme contribute".
			 *
			 * The link opens the hours list narrowed by the same function this
			 * total was built from, so pressing the number shows the records it
			 * came from. */
			$hours     = gwc_vt_partner_hours( (int) $partner->term_id );
			$hours_url = gwc_vt_partner_hours_url( (int) $partner->term_id );
			?>
			<?php if ( $hours->entries > 0 && '' !== $hours_url ) : ?>
				<a href="<?php echo esc_url( $hours_url ); ?>">
					<?php echo esc_html( gwc_vt_format_hours( $hours->verified_minutes ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( gwc_vt_format_hours( $hours->verified_minutes ) ); ?>
			<?php endif; ?>

			<?php if ( $hours->pending_minutes > 0 ) : ?>
				<div class="row-actions">
					<?php
					printf(
						/* translators: %s: a duration, already formatted. */
						esc_html__( '%s awaiting verification', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwc_vt_format_hours( $hours->pending_minutes ) )
					);
					?>
				</div>
			<?php endif; ?>
		</td>
		<td>
			<?php echo esc_html( $fields[ GWC_VT_PARTNER_CONTACT_NAME ] ); ?>
			<?php if ( '' !== $fields[ GWC_VT_PARTNER_CONTACT_EMAIL ] ) : ?>
				<div class="row-actions"><?php echo esc_html( $fields[ GWC_VT_PARTNER_CONTACT_EMAIL ] ); ?></div>
			<?php endif; ?>
		</td>
		<td><?php echo esc_html( $fields[ GWC_VT_PARTNER_CRM_ID ] ); ?></td>
	</tr>
	<?php
}

/**
 * Adding one.
 */
function gwc_vt_render_partner_add_form(): void {
	?>
	<div class="gwcvt-partners__add">
		<h2><?php esc_html_e( 'Add a partner', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_add_partner" />
			<?php wp_nonce_field( 'gwc_vt_add_partner' ); ?>

			<p>
				<label for="gwcvt-partner-name"><?php esc_html_e( 'What it is called', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="text" id="gwcvt-partner-name" name="gwc_vt_partner_name" class="regular-text" required maxlength="<?php echo esc_attr( (string) GWC_VT_PARTNER_FIELD_MAX ); ?>" />
			</p>

			<p class="description">
				<?php esc_html_e( 'Write it as you would say it. You can add the CRM ID and a contact after it exists, and you can rename it later without moving any records.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Add it', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * The merge form: what will move, what disagrees, and which one lives.
 *
 * @param int[] $term_ids The partners to fold together.
 */
function gwc_vt_render_partner_merge_form( array $term_ids ): void {
	$partners = array();

	foreach ( $term_ids as $term_id ) {
		$partner = gwc_vt_partner( (int) $term_id );

		if ( $partner ) {
			$partners[] = $partner;
		}
	}
	?>
	<div class="wrap gwcvt-partners">
		<h1><?php esc_html_e( 'Fold partners together', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php if ( count( $partners ) < 2 ) : ?>
			<p><?php esc_html_e( 'Choose at least two partners to fold together.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<p><a href="<?php echo esc_url( gwc_vt_partners_url() ); ?>"><?php esc_html_e( 'Back to partners', 'groundwork-common-volunteer-tracker' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>

		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'This cannot be undone.', 'groundwork-common-volunteer-tracker' ); ?></strong>
				<?php esc_html_e( 'The partners you do not keep are deleted, and everything that pointed at them points at the one you keep instead. No volunteer and no hour entry is deleted.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_merge_partners" />
			<?php wp_nonce_field( 'gwc_vt_merge_partners' ); ?>

			<?php foreach ( $partners as $partner ) : ?>
				<input type="hidden" name="partners[]" value="<?php echo esc_attr( (string) $partner->term_id ); ?>" />
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Which one do you want to keep?', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="widefat striped gwcvt-partners__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Keep', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Partner', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $partners as $index => $partner ) : ?>
						<?php $counts = gwc_vt_partner_counts( (int) $partner->term_id ); ?>
						<tr>
							<td>
								<input
									type="radio"
									id="gwcvt-survivor-<?php echo esc_attr( (string) $partner->term_id ); ?>"
									name="survivor"
									value="<?php echo esc_attr( (string) $partner->term_id ); ?>"
									<?php checked( 0, $index ); ?>
									required
								/>
							</td>
							<td>
								<label for="gwcvt-survivor-<?php echo esc_attr( (string) $partner->term_id ); ?>">
									<strong><?php echo esc_html( $partner->name ); ?></strong>
								</label>
							</td>
							<td>
								<?php
								echo esc_html(
									number_format_i18n( (int) ( $counts[ GWC_VT_VOLUNTEER_TYPE ] ?? 0 ) )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			/* The name is the one field that is never a collision — whichever
			 * term survives keeps its own, and renaming afterwards is core's
			 * job and changes nothing else. Only the four details can disagree,
			 * and where they do the operator answers rather than the code
			 * guessing: two different CRM IDs is exactly the case where the
			 * wrong pair has been selected. */
			$conflicts = gwc_vt_partner_field_conflicts( wp_list_pluck( $partners, 'term_id' ) );
			$fields    = gwc_vt_partner_fields();
			?>

			<?php if ( $conflicts ) : ?>
				<h2><?php esc_html_e( 'These details disagree', 'groundwork-common-volunteer-tracker' ); ?></h2>

				<p>
					<?php esc_html_e( 'Choose what the one you keep should end up with. If two partners have different CRM IDs, it is worth checking you have picked the right pair before going on.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<?php
				/* ── Nothing is preselected, and that is the point ────────────
				 * The obvious version checks the first value so the form is
				 * never invalid. What that actually does is answer the question
				 * on the operator's behalf and then record their silence as
				 * agreement — which is the same thing as the code guessing, one
				 * step removed.
				 *
				 * These fieldsets only appear when two partners genuinely
				 * disagree about a stored fact, which usually means the wrong
				 * pair has been selected. That is the moment to make somebody
				 * stop, so `required` with no default is right here and would be
				 * friction anywhere else. */
				?>
				<?php foreach ( $conflicts as $key => $values ) : ?>
					<fieldset class="gwcvt-partners__conflict">
						<legend><strong><?php echo esc_html( (string) $fields[ $key ]['label'] ); ?></strong></legend>

						<?php foreach ( $values as $value ) : ?>
							<p>
								<label>
									<input
										type="radio"
										name="fields[<?php echo esc_attr( $key ); ?>]"
										value="<?php echo esc_attr( $value ); ?>"
										required
									/>
									<?php echo esc_html( $value ); ?>
								</label>
							</p>
						<?php endforeach; ?>

						<p>
							<label>
								<input type="radio" name="fields[<?php echo esc_attr( $key ); ?>]" value="" required />
								<?php esc_html_e( 'Leave it empty', 'groundwork-common-volunteer-tracker' ); ?>
							</label>
						</p>
					</fieldset>
				<?php endforeach; ?>
			<?php endif; ?>

			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Fold them together', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( gwc_vt_partners_url() ); ?>">
					<?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		</form>
	</div>
	<?php
}

/* ── What happened last time ─────────────────────────────────────────────── */

/**
 * The notice after an add or a merge.
 */
function gwc_vt_partner_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; renders a sentence about an action that was itself nonced.
	$result = isset( $_GET['gwc_vt_partner_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_partner_result'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$count = isset( $_GET['gwc_vt_partner_count'] ) ? absint( wp_unslash( $_GET['gwc_vt_partner_count'] ) ) : 0;

	$messages = array(
		'added'      => __( 'Partner added.', 'groundwork-common-volunteer-tracker' ),
		'exists'     => __( 'There is already a partner with that name.', 'groundwork-common-volunteer-tracker' ),
		'empty'      => __( 'Give the partner a name.', 'groundwork-common-volunteer-tracker' ),
		'not-enough' => __( 'Choose at least two partners to fold together.', 'groundwork-common-volunteer-tracker' ),
		'gone'       => __( 'That partner no longer exists. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( 'merged' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: how many partners were folded in. */
					_n(
						'Folded in %d partner. Everything that pointed at it now points at the one you kept.',
						'Folded in %d partners. Everything that pointed at them now points at the one you kept.',
						$count,
						'groundwork-common-volunteer-tracker'
					),
					$count
				)
			)
		);

		return;
	}

	if ( ! isset( $messages[ $result ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		'added' === $result ? 'success' : 'error',
		esc_html( $messages[ $result ] )
	);
}

/**
 * Send the operator back with a word about what happened.
 *
 * @param string $result What happened.
 * @param int    $count  How many, where that means anything.
 */
function gwc_vt_partner_redirect( string $result, int $count = 0 ): void {
	wp_safe_redirect(
		gwc_vt_partners_url(
			array(
				'gwc_vt_partner_result' => $result,
				'gwc_vt_partner_count'  => $count,
			)
		)
	);

	exit;
}

/* ── The two handlers ────────────────────────────────────────────────────── */

/**
 * Add a partner.
 */
function gwc_vt_handle_add_partner(): void {
	check_admin_referer( 'gwc_vt_add_partner' );

	if ( ! gwc_vt_can_see_records() ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ) );
	}

	$name = isset( $_POST['gwc_vt_partner_name'] )
		? gwc_vt_sanitize_partner_text( wp_unslash( $_POST['gwc_vt_partner_name'] ) )
		: '';

	if ( '' === $name ) {
		gwc_vt_partner_redirect( 'empty' );
	}

	$made = wp_insert_term( $name, GWC_VT_PARTNER_TAXONOMY );

	/* term_exists is the ordinary answer to typing a name that is already
	 * there, and it is worth saying so rather than reporting a failure: the
	 * operator wanted a partner by that name and there is one. */
	if ( is_wp_error( $made ) ) {
		gwc_vt_partner_redirect( 'term_exists' === $made->get_error_code() ? 'exists' : 'empty' );
	}

	gwc_vt_partner_redirect( 'added' );
}

/**
 * Fold several partners into one.
 */
function gwc_vt_handle_merge_partners(): void {
	check_admin_referer( 'gwc_vt_merge_partners' );

	if ( ! gwc_vt_can_see_records() ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ) );
	}

	$survivor = isset( $_POST['survivor'] ) ? absint( wp_unslash( $_POST['survivor'] ) ) : 0;

	$offered = isset( $_POST['partners'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['partners'] ) ) : array();
	$offered = array_values( array_filter( $offered ) );

	if ( $survivor < 1 || count( $offered ) < 2 ) {
		gwc_vt_partner_redirect( 'not-enough' );
	}

	/* Everything offered except the one being kept. Derived rather than posted
	 * separately, so a form that lost a checkbox cannot ask for a merge into a
	 * term that was never on the screen. */
	$losers = array_values( array_diff( $offered, array( $survivor ) ) );

	$fields = array();

	if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized by gwc_vt_set_partner_fields() through the field's own callback, which is the one place that decides what is allowed.
		$posted = (array) wp_unslash( $_POST['fields'] );

		foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$fields[ $key ] = (string) $posted[ $key ];
			}
		}
	}

	$result = gwc_vt_merge_partners( $survivor, $losers, $fields );

	if ( is_wp_error( $result ) ) {
		gwc_vt_partner_redirect( 'gwc_vt_partner_missing' === $result->get_error_code() ? 'gone' : 'not-enough' );
	}

	gwc_vt_partner_redirect( 'merged', (int) $result['merged'] );
}

/* ── The four fields, on core's own term forms ───────────────────────────── */

/**
 * On the add form, where there is no term yet.
 */
function gwc_vt_partner_add_fields(): void {
	foreach ( gwc_vt_partner_fields() as $key => $field ) {
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<input type="<?php echo esc_attr( $field['type'] ); ?>" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="" maxlength="<?php echo esc_attr( (string) GWC_VT_PARTNER_FIELD_MAX ); ?>" />
			<?php if ( '' !== $field['help'] ) : ?>
				<p><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

/**
 * On the edit form, which is a table.
 *
 * @param WP_Term $term The partner.
 */
function gwc_vt_partner_edit_fields( $term ): void {
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$values = gwc_vt_partner_field_values( (int) $term->term_id );

	foreach ( gwc_vt_partner_fields() as $key => $field ) {
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			</th>
			<td>
				<input type="<?php echo esc_attr( $field['type'] ); ?>" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $values[ $key ] ); ?>" maxlength="<?php echo esc_attr( (string) GWC_VT_PARTNER_FIELD_MAX ); ?>" />
				<?php if ( '' !== $field['help'] ) : ?>
					<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}

/**
 * Save them.
 *
 * Core nonces its own term forms before either hook fires, so this does not
 * re-check one — there is no request that reaches created_/edited_ without
 * having been through wp-admin/edit-tags.php or term.php.
 *
 * @param int $term_id The partner.
 */
function gwc_vt_save_partner_fields( $term_id ): void {
	if ( ! gwc_vt_can_see_records() ) {
		return;
	}

	$values = array();

	foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies its own term form's nonce before created_/edited_ fires.
			continue;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce as above; sanitized by gwc_vt_set_partner_fields() through each field's own callback.
		$values[ $key ] = (string) wp_unslash( $_POST[ $key ] );
	}

	if ( $values ) {
		gwc_vt_set_partner_fields( (int) $term_id, $values );
	}
}

/* ── The filter on the volunteer list ────────────────────────────────────── */

/**
 * The dropdown, above the volunteer list.
 *
 * Only once anything is defined — a control offering to filter by a thing the
 * site does not have can only ever return nothing.
 */
function gwc_vt_partner_filter_dropdown(): void {
	$screen = get_current_screen();

	/* Both lists a partner can be attached to, read from the taxonomy rather
	 * than named — a third object type gets its filter for free, the same way
	 * the merge does. */
	$here = $screen instanceof WP_Screen ? (string) $screen->id : '';
	$mine = false;

	foreach ( gwc_vt_partner_object_types() as $type ) {
		if ( 'edit-' . $type === $here ) {
			$mine = true;
		}
	}

	if ( ! $mine ) {
		return;
	}

	$partners = gwc_vt_partner_terms();

	if ( ! $partners ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$current = isset( $_GET[ GWC_VT_PARTNER_FILTER ] ) ? absint( wp_unslash( $_GET[ GWC_VT_PARTNER_FILTER ] ) ) : 0;
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( GWC_VT_PARTNER_FILTER ); ?>">
		<?php esc_html_e( 'Filter by partner', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="<?php echo esc_attr( GWC_VT_PARTNER_FILTER ); ?>" id="<?php echo esc_attr( GWC_VT_PARTNER_FILTER ); ?>">
		<option value="0"><?php esc_html_e( 'Any partner', 'groundwork-common-volunteer-tracker' ); ?></option>
		<?php foreach ( $partners as $partner ) : ?>
			<option value="<?php echo esc_attr( (string) $partner->term_id ); ?>" <?php selected( $current, (int) $partner->term_id ); ?>>
				<?php echo esc_html( $partner->name ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * What the rows on screen add up to.
 *
 * ── Computed from the query that drew the list, not from the term ────────────
 * The obvious version asks gwc_vt_partner_hours() for the partner's totals and
 * prints them above the table. That number is right about the partner and wrong
 * about the screen the moment anything else is filtering — a verification state,
 * a search, a date — and it would sit directly above a list that disagreed with
 * it.
 *
 * This plugin has been caught by that twice: the dashboard counting overdue
 * volunteers and linking to an unfiltered list, and the unlogged-hours nag
 * counting slots a view then excluded. So this re-runs the MAIN QUERY with its
 * paging removed and totals what comes back. It says what is on the screen,
 * whatever else the coordinator has narrowed it by.
 *
 * The arithmetic is still gwc_vt_total_from_ids(), so "verified" means here
 * what it means on the letter.
 */
function gwc_vt_partner_hours_summary(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	if ( ! gwc_vt_can_see_records() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$term_id = isset( $_GET[ GWC_VT_PARTNER_FILTER ] ) ? absint( wp_unslash( $_GET[ GWC_VT_PARTNER_FILTER ] ) ) : 0;

	$partner = $term_id > 0 ? gwc_vt_partner( $term_id ) : null;

	if ( ! $partner ) {
		return;
	}

	$main = $GLOBALS['wp_query'] ?? null;

	if ( ! $main instanceof WP_Query ) {
		return;
	}

	/* The same vars, unpaged. 'fields' and 'posts_per_page' are the only things
	 * that change — narrow it any further and this stops describing the list. */
	$vars = $main->query_vars;

	unset( $vars['paged'], $vars['offset'] );

	/* nopaging as well as posts_per_page, and it is not belt and braces.
	 * WP_Query only drops the LIMIT when nopaging is true; the hours list's own
	 * vars carry nopaging => false, so posts_per_page => -1 on its own produced
	 * `LIMIT 0, -1` — which is not valid SQL and returns nothing. The list
	 * showed two rows and the line above it said nought. */
	$vars['nopaging']       = true;
	$vars['posts_per_page'] = -1;
	$vars['fields']         = 'ids';
	$vars['no_found_rows']  = true;

	/* ── WP_Query, and deliberately not get_posts() ──────────────────────────
	 * get_posts() is not a thin wrapper. Among other defaults it does
	 *
	 *     if ( empty( $r['post_status'] ) ) { $r['post_status'] = 'publish'; }
	 *
	 * and the hours list arrives here with post_status set to the empty string,
	 * which WP_Query reads as "every status this user may see". So the same
	 * vars through get_posts() silently dropped every pending entry: the list
	 * showed two rows and the line above it said nought, which is precisely the
	 * disagreement this function exists to prevent.
	 *
	 * pre_get_posts still fires for this query. Everything this plugin hangs
	 * there checks is_main_query() first, so nothing is applied twice — and the
	 * vars already carry the filters in any case. */
	$counter = new WP_Query();

	$totals = gwc_vt_total_from_ids( array_map( 'intval', (array) $counter->query( $vars ) ) );
	?>
	<div class="notice notice-info gwcvt-partners__summary">
		<p>
			<strong><?php echo esc_html( $partner->name ); ?></strong>
			<?php
			printf(
				/* translators: 1: a duration, already formatted; 2: how many hour entries. */
				esc_html( _n( '— %1$s verified across %2$s entry shown.', '— %1$s verified across %2$s entries shown.', (int) $totals->entries, 'groundwork-common-volunteer-tracker' ) ),
				esc_html( gwc_vt_format_hours( $totals->verified_minutes ) ),
				esc_html( number_format_i18n( (int) $totals->entries ) )
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

		<?php if ( '' !== $totals->first && '' !== $totals->last ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: the earliest date shown; 2: the latest. */
					esc_html__( 'Earliest %1$s, latest %2$s.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_shift_date_label_from( $totals->first ) ),
					esc_html( gwc_vt_shift_date_label_from( $totals->last ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Apply it.
 *
 * @param WP_Query $query The query.
 */
function gwc_vt_apply_partner_filter( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( ! in_array( (string) $query->get( 'post_type' ), gwc_vt_partner_object_types(), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$term_id = isset( $_GET[ GWC_VT_PARTNER_FILTER ] ) ? absint( wp_unslash( $_GET[ GWC_VT_PARTNER_FILTER ] ) ) : 0;

	if ( $term_id < 1 || ! gwc_vt_partner( $term_id ) ) {
		return;
	}

	/* The same function the count on the Partners screen is built from, so
	 * the number and the list it opens cannot disagree — see gwc_vt_partner_query()
	 * for the hour these two spent disagreeing about include_children. */
	$query->set(
		'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a list-table filter on one taxonomy; there is no other way to ask.
		gwc_vt_partner_query( $term_id )
	);
}

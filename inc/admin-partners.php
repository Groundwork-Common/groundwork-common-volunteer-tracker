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
add_action( 'admin_post_gwc_vt_save_partner', 'gwc_vt_handle_save_partner' );
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
	/* ── edit.php with the post type, not admin.php ──────────────────────────
	 * This screen is a submenu of edit.php?post_type=gwc_vt_entry, and that is
	 * the URL gwc_vt_credentials_url() builds for the sibling screen next door.
	 *
	 * admin.php?page=<slug> mostly works — wp-admin/admin.php has a back-compat
	 * path that finds a submenu by its own slug — and "mostly" is the problem.
	 * It resolves through $admin_page_hooks and $_parent_pages, and where it
	 * misses, the answer is the bare string "Cannot load gwc-vt-partners." with
	 * no clue what it wanted. The pagination links were the first thing to hit
	 * it, in a fresh browser session and not in one that had already been to the
	 * real URL, which is as good a description of a trap as any.
	 *
	 * The canonical URL is not ambiguous and costs one more query argument. */
	return add_query_arg(
		array_merge(
			array(
				'post_type' => GWC_VT_ENTRY_TYPE,
				'page'      => GWC_VT_PARTNERS_PAGE,
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

/* ── The screen ──────────────────────────────────────────────────────────── */

/**
 * The list, or the merge form.
 */
function gwc_vt_render_partners_screen(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to see partners.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	/* Folding two together is the one view that takes several rows, so it is
	 * asked for as several and checked first. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between this screen's views; every form it draws carries its own nonce.
	$merging = isset( $_GET['merge'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['merge'] ) ) : array();
	$merging = array_values( array_filter( $merging ) );

	/* From the duplicate list's link, which names the pair and nothing else, or
	 * from the bulk action above the table. Anything else that happens to carry
	 * merge[] — a half-used bulk row where nobody picked an action — falls
	 * through to the list rather than opening a confirmation nobody asked for. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$bulk = isset( $_GET['gwc_vt_bulk'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_bulk'] ) ) : '';

	if ( $merging && ( '' === $bulk || 'merge' === $bulk ) ) {
		gwc_vt_render_partner_merge_form( $merging );
		return;
	}

	/* 'new', or the id of the one being edited. sanitize_key() keeps digits, so
	 * one value carries both and absint() tells them apart — the same shape the
	 * Credentials screen uses, because these are the same screen wearing two
	 * different nouns. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$asked = isset( $_GET['partner'] ) ? sanitize_key( wp_unslash( $_GET['partner'] ) ) : '';

	if ( 'new' === $asked ) {
		gwc_vt_render_partner_form( 0 );
		return;
	}

	$editing = absint( $asked );

	if ( $editing > 0 ) {
		/* A stale bookmark lands on the list saying so, rather than on a form
		 * that would write to nothing. */
		if ( ! gwc_vt_partner( $editing ) ) {
			gwc_vt_partner_redirect( 'gone' );
		}

		gwc_vt_render_partner_form( $editing );
		return;
	}

	gwc_vt_render_partners_list();
}

/**
 * What was searched for.
 *
 * @return string
 */
function gwc_vt_partners_search(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list search; read-only, and core does not nonce these.
	return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
}

/**
 * Every partner, and what to do about the duplicates.
 *
 * ── Core's shape, because this plugin agreed on one ──────────────────────────
 * Heading, an Add New button beside it where wp-admin puts one on every list it
 * ships, the search box in core's slot, the row of controls above the table with
 * the count on the right, `wp-list-table` markup, the name as the link, and the
 * second way in on hover. `gwc_vt_render_list_search()` and
 * `gwc_vt_render_list_tablenav()` in inc/admin-screen.php are that agreement —
 * five screens once had five arrangements, and the note above those functions
 * records why they stopped.
 *
 * The first version of this screen had the add form under the table. That is
 * the arrangement admin-credentials.php was deliberately moved AWAY from: it
 * leaves the bottom half of a screen as a form nobody asked for yet, and puts
 * the add affordance in the one place a WordPress administrator has never had
 * to look for it.
 */
function gwc_vt_render_partners_list(): void {
	$term = gwc_vt_partners_search();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list navigation; read-only, and core does not nonce paging.
	$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

	$found      = gwc_vt_partner_page(
		array(
			'page'   => $page,
			'search' => $term,
		)
	);
	$showing    = $found['terms'];
	$duplicates = gwc_vt_partner_duplicate_clusters();
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_partners_title() ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( gwc_vt_partners_url( array( 'partner' => 'new' ) ) ); ?>">
			<?php esc_html_e( 'Add New Partner', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwc_vt_partner_notice(); ?>

		<?php if ( $duplicates ) : ?>
			<div class="notice notice-warning gwcvt-partners__duplicates">
				<p><strong><?php esc_html_e( 'These look like the same partner', 'groundwork-common-volunteer-tracker' ); ?></strong></p>
				<ul>
					<?php foreach ( $duplicates as $cluster ) : ?>
						<?php
						$ids   = array();
						$names = array();

						foreach ( $cluster as $one ) {
							$ids[]   = (int) $one->term_id;
							$names[] = (string) $one->name;
						}
						?>
						<li>
							<?php echo esc_html( implode( ' · ', $names ) ); ?>
							<a href="<?php echo esc_url( gwc_vt_partners_url( array( 'merge' => $ids ) ) ); ?>">
								<?php esc_html_e( 'Look at these', 'groundwork-common-volunteer-tracker' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		gwc_vt_render_list_search(
			array(
				'id'          => 'gwcvt-partner-search',
				'value'       => $term,
				'keep'        => array(
					'post_type' => GWC_VT_ENTRY_TYPE,
					'page'      => GWC_VT_PARTNERS_PAGE,
				),
				'label'       => __( 'Find a partner', 'groundwork-common-volunteer-tracker' ),
				'placeholder' => __( 'Find a partner', 'groundwork-common-volunteer-tracker' ),
				'button'      => __( 'Find', 'groundwork-common-volunteer-tracker' ),
			)
		);
		?>

		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( GWC_VT_ENTRY_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( GWC_VT_PARTNERS_PAGE ); ?>" />

			<?php
			/* Folding two together is a bulk action, so it is where wp-admin
			 * keeps bulk actions: the left of the row above the table, beside
			 * the count. It was a button under the table, which is nowhere. */
			gwc_vt_render_list_tablenav(
				(int) $found['total'],
				'gwc_vt_render_partner_bulk_actions',
				null,
				array(
					'per_page' => GWC_VT_PARTNERS_PER_PAGE,
					'current'  => $page,
					'base'     => gwc_vt_partners_url( '' !== $term ? array( 's' => $term ) : array() ),
				)
			);
			?>

			<table class="wp-list-table widefat fixed striped table-view-list gwcvt-partners">
				<thead>
					<?php gwc_vt_render_partner_headings(); ?>
				</thead>
				<tbody id="the-list">
					<?php if ( $showing ) : ?>
						<?php foreach ( $showing as $partner ) : ?>
							<?php gwc_vt_render_partner_row( $partner ); ?>
						<?php endforeach; ?>
					<?php else : ?>
						<tr class="no-items">
							<td class="colspanchange" colspan="6">
								<?php
								echo '' !== $term
									? esc_html__( 'No partner of that name.', 'groundwork-common-volunteer-tracker' )
									: esc_html__( 'No partners yet.', 'groundwork-common-volunteer-tracker' );
								?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<?php gwc_vt_render_partner_headings(); ?>
				</tfoot>
			</table>
		</form>
	</div>
	<?php
}

/**
 * The bulk action, in the slot core keeps for one.
 */
function gwc_vt_render_partner_bulk_actions(): void {
	?>
	<label class="screen-reader-text" for="gwcvt-partner-bulk">
		<?php esc_html_e( 'Action for the selected partners', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="gwc_vt_bulk" id="gwcvt-partner-bulk">
		<option value=""><?php esc_html_e( 'Bulk actions', 'groundwork-common-volunteer-tracker' ); ?></option>
		<option value="merge"><?php esc_html_e( 'Fold together', 'groundwork-common-volunteer-tracker' ); ?></option>
	</select>
	<?php submit_button( __( 'Apply', 'groundwork-common-volunteer-tracker' ), 'action', '', false ); ?>
	<?php
}

/**
 * The column headings, printed above the table and below it.
 */
function gwc_vt_render_partner_headings(): void {
	?>
	<tr>
		<td class="manage-column column-cb check-column">
			<label class="screen-reader-text" for="gwcvt-partner-all">
				<?php esc_html_e( 'Select all partners', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input id="gwcvt-partner-all" type="checkbox" />
		</td>
		<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Partner', 'groundwork-common-volunteer-tracker' ); ?></th>
		<th scope="col" class="manage-column"><?php esc_html_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?></th>
		<th scope="col" class="manage-column"><?php esc_html_e( 'Verified hours', 'groundwork-common-volunteer-tracker' ); ?></th>
		<th scope="col" class="manage-column"><?php esc_html_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?></th>
		<th scope="col" class="manage-column"><?php esc_html_e( 'CRM ID', 'groundwork-common-volunteer-tracker' ); ?></th>
	</tr>
	<?php
}

/**
 * One row, in core's shape.
 *
 * @param WP_Term $partner The partner.
 */
function gwc_vt_render_partner_row( WP_Term $partner ): void {
	$fields = gwc_vt_partner_field_values( (int) $partner->term_id );

	/* Not $partner->count. That column is one number across every object type in
	 * the taxonomy — volunteers PLUS entries added together, which is not a
	 * quantity of anything. See gwc_vt_partner_counts(). */
	$counts     = gwc_vt_partner_counts( (int) $partner->term_id, array( GWC_VT_VOLUNTEER_TYPE ) );
	$volunteers = (int) ( $counts[ GWC_VT_VOLUNTEER_TYPE ] ?? 0 );
	$hours      = gwc_vt_partner_hours( (int) $partner->term_id );
	$edit       = gwc_vt_partners_url( array( 'partner' => (int) $partner->term_id ) );
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

		<td class="column-primary has-row-actions">
			<?php
			/* The name is the link, because in every list table in wp-admin the
			 * title is what you press to open the thing. */
			?>
			<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $partner->name ); ?></a></strong>

			<?php if ( (int) $partner->parent > 0 ) : ?>
				<?php $parent = gwc_vt_partner( (int) $partner->parent ); ?>
				<?php if ( $parent ) : ?>
					<div class="gwcvt-partners__sub">
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

			<div class="row-actions">
				<span class="edit">
					<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'groundwork-common-volunteer-tracker' ); ?></a>
				</span>
			</div>

			<button type="button" class="toggle-row">
				<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'groundwork-common-volunteer-tracker' ); ?></span>
			</button>
		</td>

		<td data-colname="<?php esc_attr_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php $url = gwc_vt_partner_volunteers_url( (int) $partner->term_id ); ?>
			<?php if ( $volunteers > 0 && '' !== $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( number_format_i18n( $volunteers ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( number_format_i18n( $volunteers ) ); ?>
			<?php endif; ?>
		</td>

		<td data-colname="<?php esc_attr_e( 'Verified hours', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php
			/* Counted from ENTRIES, never from the people in the column beside
			 * it, and the link opens the hours list narrowed by the same
			 * function this total was built from. */
			$hours_url = gwc_vt_partner_hours_url( (int) $partner->term_id );
			?>
			<?php if ( $hours->entries > 0 && '' !== $hours_url ) : ?>
				<a href="<?php echo esc_url( $hours_url ); ?>"><?php echo esc_html( gwc_vt_format_hours( $hours->verified_minutes ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( gwc_vt_format_hours( $hours->verified_minutes ) ); ?>
			<?php endif; ?>

			<?php if ( $hours->pending_minutes > 0 ) : ?>
				<div class="gwcvt-partners__sub">
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

		<td data-colname="<?php esc_attr_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php echo esc_html( $fields[ GWC_VT_PARTNER_CONTACT_NAME ] ); ?>
			<?php if ( '' !== $fields[ GWC_VT_PARTNER_CONTACT_EMAIL ] ) : ?>
				<div class="gwcvt-partners__sub"><?php echo esc_html( $fields[ GWC_VT_PARTNER_CONTACT_EMAIL ] ); ?></div>
			<?php endif; ?>
		</td>

		<td data-colname="<?php esc_attr_e( 'CRM ID', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php echo esc_html( $fields[ GWC_VT_PARTNER_CRM_ID ] ); ?>
		</td>
	</tr>
	<?php
}

/**
 * Adding one, or changing one, on a view of its own.
 *
 * ── One form, both jobs ──────────────────────────────────────────────────────
 * The same shape gwc_vt_render_credential_form() has, and for the same reason:
 * the questions are identical, and a second form is a second place for them to
 * drift.
 *
 * It replaces a link out to core's term editor. That link worked, and it took
 * somebody off this plugin's screens into a taxonomy page with a Description
 * field this feature does not use and a Count column that adds volunteers to
 * hour entries. Owning the form is what the rest of the plugin does.
 *
 * @param int $partner_id Term ID, or 0 to add.
 */
function gwc_vt_render_partner_form( int $partner_id ): void {
	$partner = $partner_id > 0 ? gwc_vt_partner( $partner_id ) : null;
	$fields  = $partner ? gwc_vt_partner_field_values( $partner_id ) : array();
	$others  = array();

	foreach ( gwc_vt_partner_terms() as $one ) {
		/* A partner cannot be its own parent, and cannot be parented under one
		 * of its own children — offering either is offering a cycle. */
		if ( $partner && ( (int) $one->term_id === $partner_id || term_is_ancestor_of( $partner_id, (int) $one->term_id, GWC_VT_PARTNER_TAXONOMY ) ) ) {
			continue;
		}

		$others[] = $one;
	}
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline">
			<?php
			echo $partner
				? esc_html__( 'Edit a partner', 'groundwork-common-volunteer-tracker' )
				: esc_html__( 'Add New Partner', 'groundwork-common-volunteer-tracker' );
			?>
		</h1>
		<hr class="wp-header-end" />

		<?php gwc_vt_partner_notice(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_save_partner" />
			<input type="hidden" name="gwc_vt_partner_id" value="<?php echo esc_attr( (string) $partner_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_save_partner' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-partner-name"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input
								type="text"
								id="gwcvt-partner-name"
								name="gwc_vt_partner_name"
								class="regular-text"
								required
								maxlength="<?php echo esc_attr( (string) GWC_VT_PARTNER_FIELD_MAX ); ?>"
								value="<?php echo esc_attr( $partner ? (string) $partner->name : '' ); ?>"
							/>
						</td>
					</tr>

					<?php if ( $others ) : ?>
						<tr>
							<th scope="row"><label for="gwcvt-partner-parent"><?php esc_html_e( 'Part of', 'groundwork-common-volunteer-tracker' ); ?></label></th>
							<td>
								<select id="gwcvt-partner-parent" name="gwc_vt_partner_parent">
									<option value="0"><?php esc_html_e( '— none —', 'groundwork-common-volunteer-tracker' ); ?></option>
									<?php foreach ( $others as $one ) : ?>
										<option value="<?php echo esc_attr( (string) $one->term_id ); ?>" <?php selected( $partner ? (int) $partner->parent : 0, (int) $one->term_id ); ?>>
											<?php echo esc_html( $one->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'For a local chapter of a national body.', 'groundwork-common-volunteer-tracker' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>

					<?php foreach ( gwc_vt_partner_fields() as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<input
									type="<?php echo esc_attr( $field['type'] ); ?>"
									id="<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $key ); ?>"
									class="regular-text"
									maxlength="<?php echo esc_attr( (string) GWC_VT_PARTNER_FIELD_MAX ); ?>"
									value="<?php echo esc_attr( (string) ( $fields[ $key ] ?? '' ) ); ?>"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button type="submit" class="button button-primary">
					<?php
					echo $partner
						? esc_html__( 'Save this partner', 'groundwork-common-volunteer-tracker' )
						: esc_html__( 'Add it', 'groundwork-common-volunteer-tracker' );
					?>
				</button>
				<a class="button" href="<?php echo esc_url( gwc_vt_partners_url() ); ?>">
					<?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
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
				<?php esc_html_e( 'No volunteer or hour entry is deleted.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_merge_partners" />
			<?php wp_nonce_field( 'gwc_vt_merge_partners' ); ?>

			<?php foreach ( $partners as $partner ) : ?>
				<input type="hidden" name="partners[]" value="<?php echo esc_attr( (string) $partner->term_id ); ?>" />
			<?php endforeach; ?>

			<?php
			/* Nothing preselected here either, and for the stronger version of
			 * the reason given below about the conflicting details: this is the
			 * consequential choice on an irreversible screen, and a default
			 * would let somebody press the button without having made it. */
			?>
			<h2><?php esc_html_e( 'Which one to keep', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="widefat striped gwcvt-partners__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Keep', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Partner', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $partners as $partner ) : ?>
						<?php $counts = gwc_vt_partner_counts( (int) $partner->term_id ); ?>
						<tr>
							<td>
								<input
									type="radio"
									id="gwcvt-survivor-<?php echo esc_attr( (string) $partner->term_id ); ?>"
									name="survivor"
									value="<?php echo esc_attr( (string) $partner->term_id ); ?>"
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
		'saved'      => __( 'Saved.', 'groundwork-common-volunteer-tracker' ),
		'exists'     => __( 'There is already a partner with that name.', 'groundwork-common-volunteer-tracker' ),
		'empty'      => __( 'Give the partner a name.', 'groundwork-common-volunteer-tracker' ),
		'not-enough' => __( 'Choose at least two partners to fold together.', 'groundwork-common-volunteer-tracker' ),
		'gone'       => __( 'That partner no longer exists.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( 'merged' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: how many partners were folded in. */
					_n( 'Folded in %d partner.', 'Folded in %d partners.', $count, 'groundwork-common-volunteer-tracker' ),
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
		in_array( $result, array( 'added', 'saved' ), true ) ? 'success' : 'error',
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
 * Add a partner, or save the one being edited.
 *
 * One handler for both, because they are one form. A missing or unknown id is
 * an add; a real one is a save.
 */
function gwc_vt_handle_save_partner(): void {
	check_admin_referer( 'gwc_vt_save_partner' );

	if ( ! gwc_vt_can_see_records() ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'groundwork-common-volunteer-tracker' ) );
	}

	$partner_id = isset( $_POST['gwc_vt_partner_id'] ) ? absint( wp_unslash( $_POST['gwc_vt_partner_id'] ) ) : 0;

	$name = isset( $_POST['gwc_vt_partner_name'] )
		? gwc_vt_sanitize_partner_text( wp_unslash( $_POST['gwc_vt_partner_name'] ) )
		: '';

	if ( '' === $name ) {
		gwc_vt_partner_redirect( 'empty' );
	}

	$parent = isset( $_POST['gwc_vt_partner_parent'] ) ? absint( wp_unslash( $_POST['gwc_vt_partner_parent'] ) ) : 0;

	/* Checked rather than trusted: a posted parent that is not a partner, or is
	 * this partner, or is below it, would each build something the tree cannot
	 * hold. */
	if ( $parent > 0 && ( ! gwc_vt_partner( $parent ) || $parent === $partner_id || ( $partner_id > 0 && term_is_ancestor_of( $partner_id, $parent, GWC_VT_PARTNER_TAXONOMY ) ) ) ) {
		$parent = 0;
	}

	if ( $partner_id > 0 && gwc_vt_partner( $partner_id ) ) {
		$done = wp_update_term(
			$partner_id,
			GWC_VT_PARTNER_TAXONOMY,
			array(
				'name'   => $name,
				'parent' => $parent,
			)
		);

		if ( is_wp_error( $done ) ) {
			gwc_vt_partner_redirect( 'exists' );
		}

		gwc_vt_save_partner_fields_from_post( $partner_id );

		gwc_vt_partner_redirect( 'saved' );
	}

	$made = wp_insert_term( $name, GWC_VT_PARTNER_TAXONOMY, array( 'parent' => $parent ) );

	/* term_exists is the ordinary answer to typing a name that is already
	 * there, and it is worth saying so rather than reporting a failure: the
	 * operator wanted a partner by that name and there is one. */
	if ( is_wp_error( $made ) ) {
		gwc_vt_partner_redirect( 'term_exists' === $made->get_error_code() ? 'exists' : 'empty' );
	}

	gwc_vt_save_partner_fields_from_post( (int) $made['term_id'] );

	gwc_vt_partner_redirect( 'added' );
}

/**
 * The four details, off a posted form.
 *
 * @param int $partner_id Term ID.
 */
function gwc_vt_save_partner_fields_from_post( int $partner_id ): void {
	$values = array();

	foreach ( array_keys( gwc_vt_partner_fields() ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the form's nonce.
			continue;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce as above; sanitized by gwc_vt_set_partner_fields() through each field's own callback.
		$values[ $key ] = (string) wp_unslash( $_POST[ $key ] );
	}

	if ( $values ) {
		gwc_vt_set_partner_fields( $partner_id, $values );
	}
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
 * Which partner a list is filtered to, however the URL said so.
 *
 * ── Two URLs reach the same list, and only one of them was recognised ────────
 * This plugin's own `gwc_vt_partner_is=<id>`, which the dropdown posts and both
 * URL helpers build — and core's `taxonomy=gwc_vt_partner&term=<slug>`, which is
 * what the Partners column on the hours list links to and which WP_Query honours
 * regardless of the taxonomy having no query var of its own.
 *
 * Reading only the first meant the total above the list appeared when somebody
 * used the dropdown and vanished when they pressed the partner's name in the
 * row — the same list, filtered the same way, described in one case and not the
 * other. One resolver, so the dropdown, the filter and the total all answer from
 * the same question.
 *
 * @return int Term ID, or 0.
 */
function gwc_vt_partner_filtered_term(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$term_id = isset( $_GET[ GWC_VT_PARTNER_FILTER ] ) ? absint( wp_unslash( $_GET[ GWC_VT_PARTNER_FILTER ] ) ) : 0;

	if ( $term_id < 1 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$slug = isset( $_GET['term'] ) ? sanitize_title( wp_unslash( $_GET['term'] ) ) : '';

		if ( GWC_VT_PARTNER_TAXONOMY === $taxonomy && '' !== $slug ) {
			$term = get_term_by( 'slug', $slug, GWC_VT_PARTNER_TAXONOMY );

			$term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
		}
	}

	return gwc_vt_partner( $term_id ) ? $term_id : 0;
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

	$partner = gwc_vt_partner( gwc_vt_partner_filtered_term() );

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
				esc_html( _n( '%1$s verified across %2$s entry shown.', '%1$s verified across %2$s entries shown.', (int) $totals->entries, 'groundwork-common-volunteer-tracker' ) ),
				esc_html( gwc_vt_format_hours( $totals->verified_minutes ) ),
				esc_html( number_format_i18n( (int) $totals->entries ) )
			);
			?>

			<?php if ( $totals->pending_minutes > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: a duration, already formatted. */
					esc_html__( '%s awaiting verification.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_format_hours( $totals->pending_minutes ) )
				);
				?>
			<?php endif; ?>
		</p>
	</div>
	<?php
}

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
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( GWC_VT_PARTNER_FILTER ); ?>">
		<?php esc_html_e( 'Filter by partner', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<?php
	gwc_vt_partner_dropdown(
		array(
			'name'            => GWC_VT_PARTNER_FILTER,
			'id'              => GWC_VT_PARTNER_FILTER,
			'selected'        => gwc_vt_partner_filtered_term(),
			'show_option_all' => __( 'Any partner', 'groundwork-common-volunteer-tracker' ),
		)
	);
}

/**
 * A partner chooser, in core's own control.
 *
 * ── Core's, rather than a hand-rolled <select> or a combobox ─────────────────
 * wp_dropdown_categories() is what wp-admin puts on its own post list for a
 * hierarchical taxonomy, and it does two things the hand-written selects here
 * did not: it indents a chapter under the national body it is part of, so the
 * tree this taxonomy is hierarchical FOR is visible in the control; and it
 * draws nothing at all when there is nothing to choose.
 *
 * #235 proposed replacing these with the REST-backed combobox the volunteer
 * field uses. On doing the work that is the wrong trade: the combobox needs
 * JavaScript to function at all, and a chooser that cannot be used without it
 * is worse at every size than a long select. Core ships a plain select for
 * categories on every WordPress site, including ones with hundreds. The
 * combobox stays worth having as an enhancement over this — over, not instead
 * of — and that is noted on the issue rather than done here.
 *
 * @param array $args Passed through; name, id, selected and one of
 *                    show_option_all / show_option_none.
 */
function gwc_vt_partner_dropdown( array $args ): void {
	wp_dropdown_categories(
		array_merge(
			array(
				'taxonomy'          => GWC_VT_PARTNER_TAXONOMY,
				'hierarchical'      => true,
				'hide_empty'        => false,
				'hide_if_empty'     => true,
				'value_field'       => 'term_id',
				'orderby'           => 'name',
				'option_none_value' => 0,
			),
			$args
		)
	);
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

	$term_id = gwc_vt_partner_filtered_term();

	if ( $term_id < 1 ) {
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

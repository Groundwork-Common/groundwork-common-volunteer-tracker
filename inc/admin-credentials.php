<?php
/**
 * Defining what volunteers have to hold.
 *
 * One screen, listing every credential with the interval it renews on and what
 * it does when somebody has not got it, and an **Add New Credential** button
 * beside the heading — where WordPress puts one on every list it ships. The
 * form used to sit under the table, on the argument that four fields do not
 * justify a page load. What that actually bought was a screen whose bottom half
 * is a form nobody asked for yet, and an add affordance in the one place a
 * WordPress administrator has never had to look for it. The button and its own
 * view are the same two clicks as scrolling, and they are the two clicks
 * everybody already knows.
 *
 * Both views are this one page: `?credential=new` draws the form, anything else
 * draws the list. Same screen id, so the Help tabs and the capability check
 * above are unchanged, and the same pattern the Schedule screen uses for a new
 * shift.
 *
 * ── Who may do what ──────────────────────────────────────────────────────────
 * Two different jobs, and they are not the same person's.
 *
 *   DEFINING a credential is configuration. It changes what the organization
 *   asks of everybody, and eventually who may sign up at all — so it needs
 *   manage_options, the same bar the Permissions tab sets.
 *
 *   RECORDING that a named person holds one is an attestation in everything but
 *   name: a staff member saying they saw the certificate. It sits behind the
 *   capability that already means exactly that, gwc_vt_cap( 'verify' ), and it
 *   lives on the volunteer's own record rather than here.
 *
 * No new capability. inc/access.php argues at length for keeping the set small,
 * and neither of these is a new kind of decision — they are two existing ones
 * pointed at a new noun.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'gwc_vt_register_credentials_screen', 14 );
add_action( 'admin_post_gwc_vt_save_credential', 'gwc_vt_handle_save_credential' );
add_action( 'admin_post_gwc_vt_retire_credential', 'gwc_vt_handle_retire_credential' );
add_action( 'admin_post_gwc_vt_restore_credential', 'gwc_vt_handle_restore_credential' );


/**
 * Register the screen.
 *
 * Registered whatever the state of anything else, for the reason the offers
 * queue is: a screen that disappears while it still holds definitions leaves
 * somebody with records pointing at things they cannot see or retire.
 */
function gwc_vt_register_credentials_screen(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_credentials_title(),
		gwc_vt_credentials_title(),
		gwc_vt_records_cap(),
		GWC_VT_CREDENTIALS_PAGE,
		'gwc_vt_render_credentials_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_credentials_title' );
	}
}

/**
 * The screen's name.
 *
 * @return string
 */
function gwc_vt_credentials_title(): string {
	return __( 'Credentials', 'groundwork-common-volunteer-tracker' );
}

/**
 * Put the title back after the menu is reordered.
 *
 * Get_admin_page_title() reads it out of $submenu, and the ordering pass
 * rewrites that array — the same fix every other screen here carries.
 */
function gwc_vt_restore_credentials_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	/* Which of the three views, the way core titles Edit Post rather than
	 * Posts. Read from the URL because this runs on `load-`, before anything has
	 * decided what to draw — and the tab is written from it before that. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a <title> and nothing else.
	$asked = isset( $_GET['credential'] ) ? sanitize_key( wp_unslash( $_GET['credential'] ) ) : '';

	if ( 'new' === $asked ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
		$GLOBALS['title'] = __( 'Add New Credential', 'groundwork-common-volunteer-tracker' );
		return;
	}

	if ( absint( $asked ) > 0 ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
		$GLOBALS['title'] = __( 'Edit a credential', 'groundwork-common-volunteer-tracker' );
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_credentials_title();
}

/**
 * Whether this user may define credentials, as opposed to reading the screen.
 *
 * @return bool
 */
function gwc_vt_can_define_credentials(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * A nonced URL for one credential's action.
 *
 * The nonce action carries the ID, so one minted for one credential cannot be
 * replayed against another.
 *
 * @param string $action        admin_post action name.
 * @param int    $credential_id Credential post ID.
 * @return string
 */
function gwc_vt_credential_action_url( string $action, int $credential_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'     => $action,
				'credential' => $credential_id,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $credential_id
	);
}

/**
 * The screen's own URL, which is also where every action comes back to.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function gwc_vt_credentials_url( array $args = array() ): string {
	return add_query_arg(
		array_merge(
			array(
				'post_type' => GWC_VT_ENTRY_TYPE,
				'page'      => GWC_VT_CREDENTIALS_PAGE,
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

/**
 * The screen, in whichever of its two views was asked for.
 */
function gwc_vt_render_credentials_screen(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to see credentials.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	/* Anybody may read this screen; only an administrator may add. So the add
	 * view is gated as well as the button that reaches it — a URL typed by
	 * somebody without the capability lands on the list rather than on a form
	 * whose submission would be refused. */
	/* 'new', or the id of the one being edited. sanitize_key() keeps digits, so
	 * one value carries both and absint() below tells them apart. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between this screen's views; the form it draws carries its own nonce.
	$asked = isset( $_GET['credential'] ) ? sanitize_key( wp_unslash( $_GET['credential'] ) ) : '';

	if ( 'new' === $asked && gwc_vt_can_define_credentials() ) {
		gwc_vt_render_credential_form( 0 );
		return;
	}

	/* An id opens the same form with the credential in it. Gated the same way as
	 * adding, and on the credential existing: a stale bookmark lands on the list
	 * saying so rather than on a form that would write to nothing. */
	$editing = absint( $asked );

	if ( $editing > 0 && gwc_vt_can_define_credentials() ) {
		if ( GWC_VT_CREDENTIAL_TYPE !== get_post_type( $editing ) ) {
			gwc_vt_credentials_redirect( 'gone' );
		}

		gwc_vt_render_credential_form( $editing );
		return;
	}

	gwc_vt_render_credentials_list();
}

/**
 * Which of the two lists somebody asked for.
 *
 * The same shape as core's own status views: "All" is the working list and does
 * not include the retired ones, which have their own link beside it. Retiring is
 * not deleting — README's ledger has why a retired credential keeps its holders
 * — so the retired view is a place, not a bin.
 *
 * @return string 'retired', or 'all'.
 */
function gwc_vt_credentials_status(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between this screen's two lists; nothing is written from it.
	$asked = isset( $_GET['gwc_vt_status'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_status'] ) ) : '';

	return 'retired' === $asked ? 'retired' : 'all';
}

/**
 * All | Retired, with counts, in core's own markup.
 *
 * Retired is offered only when there is one, which is what core does with Trash:
 * a link reading "Retired (0)" is a permanent invitation to an empty screen.
 *
 * @param int    $live    How many are being asked for.
 * @param int    $retired How many have been retired.
 * @param string $current Which list is being read.
 */
function gwc_vt_render_credentials_views( int $live, int $retired, string $current ): void {
	?>
	<ul class="subsubsub">
		<li class="all">
			<a
				href="<?php echo esc_url( gwc_vt_credentials_url() ); ?>"
				<?php echo 'all' === $current ? 'class="current" aria-current="page"' : ''; ?>
			>
				<?php esc_html_e( 'All', 'groundwork-common-volunteer-tracker' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( $live ) ); ?>)</span>
			</a>
			<?php echo $retired > 0 ? ' |' : ''; ?>
		</li>
		<?php if ( $retired > 0 ) : ?>
			<li class="retired">
				<a
					href="<?php echo esc_url( gwc_vt_credentials_url( array( 'gwc_vt_status' => 'retired' ) ) ); ?>"
					<?php echo 'retired' === $current ? 'class="current" aria-current="page"' : ''; ?>
				>
					<?php esc_html_e( 'Retired', 'groundwork-common-volunteer-tracker' ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $retired ) ); ?>)</span>
				</a>
			</li>
		<?php endif; ?>
	</ul>
	<?php
}

/**
 * The columns, named once for the head, the foot and each row's `data-colname`.
 *
 * One array rather than three copies: a heading and the label a narrow screen
 * shows above the same cell disagreeing is the kind of thing nobody sees on the
 * wide screen it was written on.
 *
 * @return array<string, string> Column key => heading.
 */
function gwc_vt_credential_columns(): array {
	return array(
		'name'    => __( 'Credential', 'groundwork-common-volunteer-tracker' ),
		'renewal' => __( 'Renewed', 'groundwork-common-volunteer-tracker' ),
		'mode'    => __( 'If somebody has not got it', 'groundwork-common-volunteer-tracker' ),
		'holders' => __( 'Who holds it', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * The head, which is also the foot.
 *
 * Core's list tables repeat the headings under a long table, and this is one on
 * a site that asks for a dozen things.
 */
function gwc_vt_render_credential_headings(): void {
	?>
	<tr>
		<?php foreach ( gwc_vt_credential_columns() as $key => $heading ) : ?>
			<th
				scope="col"
				class="manage-column column-<?php echo esc_attr( $key ); ?><?php echo 'name' === $key ? ' column-primary' : ''; ?>"
			>
				<?php echo esc_html( $heading ); ?>
			</th>
		<?php endforeach; ?>
	</tr>
	<?php
}

/**
 * Every credential in the list somebody asked for.
 *
 * The grid is core's: the name is the primary column and carries what you can do
 * to the row underneath it, rather than a column of buttons standing to
 * attention on every row whether or not anybody can press them. Retiring is a
 * once-a-year decision, and a permanent button is loud for a rare one.
 */
function gwc_vt_render_credentials_list(): void {
	$live    = gwc_vt_live_credential_ids();
	$retired = array_map(
		'intval',
		(array) get_posts(
			array(
				'post_type'      => GWC_VT_CREDENTIAL_TYPE,
				'post_status'    => GWC_VT_CREDENTIAL_RETIRED,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);

	$status  = gwc_vt_credentials_status();
	$showing = 'retired' === $status ? $retired : $live;
	$columns = gwc_vt_credential_columns();
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_credentials_title() ); ?></h1>
		<?php if ( gwc_vt_can_define_credentials() ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( gwc_vt_credentials_url( array( 'credential' => 'new' ) ) ); ?>">
				<?php esc_html_e( 'Add New Credential', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		<?php endif; ?>
		<hr class="wp-header-end" />

		<?php gwc_vt_credentials_notice(); ?>

		<?php gwc_vt_render_credentials_views( count( $live ), count( $retired ), $status ); ?>

		<div class="tablenav top">
			<div class="tablenav-pages one-page">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: a number of credentials, already formatted. */
						esc_html( _n( '%s item', '%s items', count( $showing ), 'groundwork-common-volunteer-tracker' ) ),
						esc_html( number_format_i18n( count( $showing ) ) )
					);
					?>
				</span>
			</div>
			<br class="clear" />
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list gwcvt-credentials">
			<thead>
				<?php gwc_vt_render_credential_headings(); ?>
			</thead>
			<tbody id="the-list">
				<?php if ( $showing ) : ?>
					<?php foreach ( $showing as $credential_id ) : ?>
						<?php gwc_vt_render_credential_row( gwc_vt_credential( (int) $credential_id ) ); ?>
					<?php endforeach; ?>
				<?php else : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>">
							<?php if ( 'retired' === $status ) : ?>
								<?php esc_html_e( 'Nothing has been retired.', 'groundwork-common-volunteer-tracker' ); ?>
							<?php elseif ( gwc_vt_can_define_credentials() ) : ?>
								<?php esc_html_e( 'Nothing defined yet. Select “Add New Credential” to define the first one.', 'groundwork-common-volunteer-tracker' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Nothing defined yet.', 'groundwork-common-volunteer-tracker' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<?php gwc_vt_render_credential_headings(); ?>
			</tfoot>
		</table>

		<?php if ( ! gwc_vt_can_define_credentials() ) : ?>
			<p class="description">
				<?php esc_html_e( 'Defining a credential changes what your organization asks of everybody, so it needs a site administrator. Recording that somebody holds one does not — that is on each volunteer’s record.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * One credential.
 *
 * @param array $credential From gwc_vt_credential().
 */
function gwc_vt_render_credential_row( array $credential ): void {
	if ( ! $credential ) {
		return;
	}

	$modes   = gwc_vt_credential_modes();
	$columns = gwc_vt_credential_columns();

	/* Built here rather than inside the attribute: an opening PHP tag in the
	 * middle of one is what the coding standard objects to, and it is right —
	 * the markup below is easier to read for it. */
	$opens = sprintf(
		/* translators: %s: a credential's name. */
		__( '%s (Edit)', 'groundwork-common-volunteer-tracker' ),
		$credential['name']
	);
	?>
	<tr>
		<td class="name column-name has-row-actions column-primary" data-colname="<?php echo esc_attr( $columns['name'] ); ?>">
			<?php
			/* The name is the link, because in every list table in wp-admin the
			 * title is what you press to open the thing. Hover actions are the
			 * second way in and were the only one here, which is a screen that
			 * looks like a list and does not behave like one.
			 *
			 * Plain text for somebody who cannot define credentials: a link that
			 * lands on a form they would be refused is worse than no link. */
			?>
			<strong>
				<?php if ( gwc_vt_can_define_credentials() ) : ?>
					<a
						class="row-title"
						href="<?php echo esc_url( gwc_vt_credentials_url( array( 'credential' => $credential['id'] ) ) ); ?>"
						aria-label="<?php echo esc_attr( $opens ); ?>"
					>
						<?php echo esc_html( $credential['name'] ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $credential['name'] ); ?>
				<?php endif; ?>
			</strong>
			<?php if ( '' !== $credential['note'] ) : ?>
				<br /><span class="description"><?php echo esc_html( $credential['note'] ); ?></span>
			<?php endif; ?>

			<?php if ( gwc_vt_can_define_credentials() ) : ?>
				<div class="row-actions">
					<span class="edit">
						<a href="<?php echo esc_url( gwc_vt_credentials_url( array( 'credential' => $credential['id'] ) ) ); ?>">
							<?php esc_html_e( 'Edit', 'groundwork-common-volunteer-tracker' ); ?>
						</a> |
					</span>
					<span class="gwcvt-retire">
						<?php if ( $credential['retired'] ) : ?>
							<a href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_restore_credential', $credential['id'] ) ); ?>">
								<?php esc_html_e( 'Put it back', 'groundwork-common-volunteer-tracker' ); ?>
							</a>
						<?php else : ?>
							<a href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_retire_credential', $credential['id'] ) ); ?>">
								<?php esc_html_e( 'Retire', 'groundwork-common-volunteer-tracker' ); ?>
							</a>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>

			<?php
			/* Core's own control, and core's own script behind it: on a narrow
			 * screen every column but this one is hidden until it is pressed. */
			?>
			<button type="button" class="toggle-row">
				<span class="screen-reader-text">
					<?php esc_html_e( 'Show more details', 'groundwork-common-volunteer-tracker' ); ?>
				</span>
			</button>
		</td>
		<td class="renewal column-renewal" data-colname="<?php echo esc_attr( $columns['renewal'] ); ?>">
			<?php
			echo esc_html(
				$credential['months'] > 0
					? sprintf(
						/* translators: %s: a number of months, already formatted. */
						_n( 'Every %s month', 'Every %s months', $credential['months'], 'groundwork-common-volunteer-tracker' ),
						number_format_i18n( $credential['months'] )
					)
					: __( 'Never expires', 'groundwork-common-volunteer-tracker' )
			);
			?>
		</td>
		<td class="mode column-mode" data-colname="<?php echo esc_attr( $columns['mode'] ); ?>">
			<?php echo esc_html( $modes[ $credential['mode'] ] ?? '' ); ?>
		</td>
		<td class="holders column-holders" data-colname="<?php echo esc_attr( $columns['holders'] ); ?>">
			<?php gwc_vt_render_credential_holders( $credential['id'] ); ?>
		</td>
	</tr>
	<?php
}

/**
 * The form, for a new credential or one that already exists.
 *
 * ── Why editing exists now ───────────────────────────────────────────────────
 * This screen was add-only, and said so: renaming is the change somebody will
 * want, changing the interval silently re-dates every expiry on the site, and
 * "that deserves its own screen saying so, rather than a pencil icon."
 *
 * The screen is this one, and the saying-so is the point rather than a nicety.
 * An expiry is derived from the interval every time it is asked for — nothing is
 * stored — so moving a class from twelve months to six does not schedule a
 * change, it makes everybody who did it seven months ago lapsed as soon as the
 * page reloads. The form says how many people hold it before the field that does
 * that, in the same shape the repeat editor states what it will touch.
 *
 * Renaming is free, which is the other half of why this can be one form: every
 * record points at the credential by ID, so the word can change under them all
 * without a single record moving.
 *
 * @param int $credential_id The credential to edit, or 0 to add one.
 */
function gwc_vt_render_credential_form( int $credential_id = 0 ): void {
	$editing = $credential_id > 0;
	$holds   = $editing ? gwc_vt_credential_holder_counts( $credential_id ) : array();
	$held_by = $editing ? (int) ( $holds['current'] ?? 0 ) + (int) ( $holds['expired'] ?? 0 ) : 0;

	$credential = $editing
		? gwc_vt_credential( $credential_id )
		: array(
			'name'    => '',
			'months'  => 0,
			'mode'    => 'report',
			'note'    => '',
			'retired' => false,
		);
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline">
			<?php
			echo $editing
				? esc_html__( 'Edit a credential', 'groundwork-common-volunteer-tracker' )
				: esc_html__( 'Add New Credential', 'groundwork-common-volunteer-tracker' );
			?>
		</h1>

		<?php if ( $editing ) : ?>
			<?php
			/* The same button core keeps on an edit screen: having finished with
			 * this one, the next thing somebody does is define another. */
			?>
			<a class="page-title-action" href="<?php echo esc_url( gwc_vt_credentials_url( array( 'credential' => 'new' ) ) ); ?>">
				<?php esc_html_e( 'Add New Credential', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		<?php endif; ?>

		<?php /* Where core moves notices to. Without it they land above the heading. */ ?>
		<hr class="wp-header-end" />

		<?php gwc_vt_credentials_notice(); ?>

		<?php if ( $editing && $credential['retired'] ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'This one is retired. Nobody is being asked for it, and the records of who holds it are still here — editing it changes what those records are records of.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_save_credential" />
			<input type="hidden" name="gwc_vt_credential" value="<?php echo esc_attr( (string) $credential_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_save_credential_' . $credential_id ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gwcvt-credential-name"><?php esc_html_e( 'What it is', 'groundwork-common-volunteer-tracker' ); ?></label></th>
					<td>
						<input type="text" id="gwcvt-credential-name" name="gwc_vt_name" class="regular-text" maxlength="120" value="<?php echo esc_attr( (string) $credential['name'] ); ?>" required />
						<p class="description"><?php esc_html_e( 'What you would call it out loud — “Child safety class”, “Liability waiver”.', 'groundwork-common-volunteer-tracker' ); ?></p>
						<?php if ( $editing && $held_by > 0 ) : ?>
							<p class="description">
								<?php esc_html_e( 'Renaming is safe: every record points at this credential itself, so the word changes everywhere at once and no record moves.', 'groundwork-common-volunteer-tracker' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gwcvt-credential-months"><?php esc_html_e( 'Renewed every', 'groundwork-common-volunteer-tracker' ); ?></label></th>
					<td>
						<input type="number" id="gwcvt-credential-months" name="gwc_vt_months" min="0" max="<?php echo esc_attr( (string) GWC_VT_CREDENTIAL_MAX_MONTHS ); ?>" step="1" value="<?php echo esc_attr( (string) $credential['months'] ); ?>" />
						<?php esc_html_e( 'months', 'groundwork-common-volunteer-tracker' ); ?>
						<p class="description"><?php esc_html_e( 'Zero means it never expires. Somebody who did it on the 31st of a month renews on the 31st, or on the last day of a month that is shorter.', 'groundwork-common-volunteer-tracker' ); ?></p>

						<?php if ( $editing && $held_by > 0 ) : ?>
							<?php
							/* The consequence, stated before the field that has
							 * it and with the number of people it reaches. An
							 * expiry is worked out from this every time it is
							 * asked for, so a shorter interval does not schedule
							 * anything — it changes who is lapsed, now. */
							?>
							<p class="description gwcvt-credential-warning">
								<strong>
									<?php
									printf(
										esc_html(
											/* translators: %d: how many volunteers hold this credential. */
											_n(
												'%d volunteer holds this. Changing the interval re-dates their renewal.',
												'%d volunteers hold this. Changing the interval re-dates every one of their renewals.',
												$held_by,
												'groundwork-common-volunteer-tracker'
											)
										),
										(int) $held_by
									);
									?>
								</strong>
								<?php esc_html_e( 'Expiry is worked out from this number each time it is asked for and is never stored, so a shorter interval takes effect the moment you save — somebody who did it eleven months ago is lapsed as soon as the page reloads. Nothing is emailed either way.', 'groundwork-common-volunteer-tracker' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gwcvt-credential-mode"><?php esc_html_e( 'If somebody has not got it', 'groundwork-common-volunteer-tracker' ); ?></label></th>
					<td>
						<select id="gwcvt-credential-mode" name="gwc_vt_mode">
							<?php foreach ( gwc_vt_credential_modes() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, (string) $credential['mode'] ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Reporting is the safer default and the one most organizations want: you find out who is short, and decide. Stopping somebody is for the things nobody may work without.', 'groundwork-common-volunteer-tracker' ); ?></p>
						<?php if ( $editing ) : ?>
							<p class="description">
								<?php esc_html_e( 'Changing this to stopping people applies to signups made from now on. Nobody already on a shift is taken off it.', 'groundwork-common-volunteer-tracker' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gwcvt-credential-note"><?php esc_html_e( 'A note', 'groundwork-common-volunteer-tracker' ); ?></label></th>
					<td>
						<input type="text" id="gwcvt-credential-note" name="gwc_vt_note" class="regular-text" maxlength="200" value="<?php echo esc_attr( (string) $credential['note'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional, and for staff. Where the course is booked, who countersigns the form — whatever the person recording it needs to know.', 'groundwork-common-volunteer-tracker' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="gwcvt-credential-submit">
				<?php
				submit_button(
					$editing
						? __( 'Save this credential', 'groundwork-common-volunteer-tracker' )
						: __( 'Add it', 'groundwork-common-volunteer-tracker' ),
					'primary',
					'submit',
					false
				);
				?>

				<?php if ( $editing ) : ?>
					<?php
					/* Beside the save, where core keeps "Move to Trash": the
					 * thing somebody wants after deciding this credential is
					 * wrong is to stop asking for it, and hunting back to the
					 * list for a hover action is the long way round.
					 *
					 * It is a link and not a second submit, because it is a
					 * different action rather than another way to save this
					 * form — and it carries its own nonce for that reason. */
					?>
					<?php if ( $credential['retired'] ) : ?>
						<a class="gwcvt-credential-aside" href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_restore_credential', $credential_id ) ); ?>">
							<?php esc_html_e( 'Put it back into use', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					<?php else : ?>
						<a class="gwcvt-credential-aside submitdelete" href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_retire_credential', $credential_id ) ); ?>">
							<?php esc_html_e( 'Retire it', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</form>

		<p>
			<a href="<?php echo esc_url( gwc_vt_credentials_url() ); ?>">
				<?php esc_html_e( '← Back to credentials', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Write a credential, new or existing.
 *
 * One handler for both, because everything that makes a credential is the same
 * either way and a second one would be a second place for the mode fallback to
 * be got wrong. The nonce carries the ID, so one minted for the add form cannot
 * be replayed against an existing credential.
 */
function gwc_vt_handle_save_credential(): void {
	gwc_vt_require_credential_cap();

	$credential_id = isset( $_POST['gwc_vt_credential'] ) ? absint( wp_unslash( $_POST['gwc_vt_credential'] ) ) : 0;

	check_admin_referer( 'gwc_vt_save_credential_' . $credential_id );

	if ( $credential_id > 0 && GWC_VT_CREDENTIAL_TYPE !== get_post_type( $credential_id ) ) {
		gwc_vt_credentials_redirect( 'gone' );
	}

	$posted = wp_unslash( $_POST );

	$name = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_name'] ?? '' ) ), 0, 120 );

	if ( '' === trim( $name ) ) {
		gwc_vt_credentials_redirect( 'no-name', $credential_id );
	}

	if ( $credential_id > 0 ) {
		/* The status is not touched. A retired credential stays retired through
		 * an edit — putting it back is its own action, with its own sentence
		 * about what it means, and a rename must not quietly undo it. */
		$saved = wp_update_post(
			array(
				'ID'         => $credential_id,
				'post_title' => $name,
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			gwc_vt_credentials_redirect( 'failed', $credential_id );
		}
	} else {
		$credential_id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_CREDENTIAL_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		if ( is_wp_error( $credential_id ) || ! $credential_id ) {
			gwc_vt_credentials_redirect( 'failed' );
		}

		$credential_id = (int) $credential_id;
	}

	$mode = sanitize_key( (string) ( $posted['gwc_vt_mode'] ?? '' ) );

	update_post_meta(
		$credential_id,
		GWC_VT_CREDENTIAL_MONTHS,
		min( GWC_VT_CREDENTIAL_MAX_MONTHS, absint( $posted['gwc_vt_months'] ?? 0 ) )
	);

	/* Anything unrecognised becomes 'report'. A mode that arrived mangled must
	 * not start refusing people. */
	update_post_meta(
		$credential_id,
		GWC_VT_CREDENTIAL_MODE,
		isset( gwc_vt_credential_modes()[ $mode ] ) ? $mode : 'report'
	);

	update_post_meta(
		$credential_id,
		GWC_VT_CREDENTIAL_NOTE,
		mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_note'] ?? '' ) ), 0, 200 )
	);

	if ( ! empty( $posted['gwc_vt_credential'] ) ) {
		/**
		 * Fires after an existing credential has been edited.
		 *
		 * @param int $credential_id The credential.
		 */
		do_action( 'gwc_vt_credential_edited', $credential_id );

		gwc_vt_credentials_redirect( 'saved' );
	}

	/**
	 * Fires after a credential has been defined.
	 *
	 * @param int $credential_id The new credential.
	 */
	do_action( 'gwc_vt_credential_defined', $credential_id );

	gwc_vt_credentials_redirect( 'added' );
}

/**
 * Take a credential out of use, without destroying what it means.
 */
function gwc_vt_handle_retire_credential(): void {
	$credential_id = gwc_vt_credential_request( 'gwc_vt_retire_credential' );

	wp_update_post(
		array(
			'ID'          => $credential_id,
			'post_status' => GWC_VT_CREDENTIAL_RETIRED,
		)
	);

	/**
	 * Fires after a credential has been retired.
	 *
	 * @param int $credential_id The credential.
	 */
	do_action( 'gwc_vt_credential_retired', $credential_id );

	gwc_vt_credentials_redirect( 'retired' );
}

/**
 * Put a retired credential back into use.
 */
function gwc_vt_handle_restore_credential(): void {
	$credential_id = gwc_vt_credential_request( 'gwc_vt_restore_credential' );

	wp_update_post(
		array(
			'ID'          => $credential_id,
			'post_status' => 'publish',
		)
	);

	gwc_vt_credentials_redirect( 'restored' );
}

/**
 * The credential an action was asked about, once the caller may ask.
 *
 * @param string $action The admin_post action, which is also the nonce action.
 * @return int
 */
function gwc_vt_credential_request( string $action ): int {
	gwc_vt_require_credential_cap();

	$credential_id = isset( $_GET['credential'] ) ? absint( wp_unslash( $_GET['credential'] ) ) : 0;

	check_admin_referer( $action . '_' . $credential_id );

	if ( GWC_VT_CREDENTIAL_TYPE !== get_post_type( $credential_id ) ) {
		gwc_vt_credentials_redirect( 'gone' );
	}

	return $credential_id;
}

/**
 * Refuse anybody who may not define credentials.
 *
 * Capability before nonce, which is the house order: a nonce failure on a
 * request somebody was never allowed to make is a 403 dressed up as an expired
 * page.
 */
function gwc_vt_require_credential_cap(): void {
	if ( ! gwc_vt_can_define_credentials() ) {
		wp_die(
			esc_html__( 'You do not have permission to define credentials.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}
}

/**
 * Back to the screen with a result.
 *
 * Which of the two views is decided here rather than by each caller, because it
 * follows from the result and nothing else: the two ways adding can fail send
 * somebody back to the form they were filling in, and everything else — retired,
 * restored, added, gone — is about the list. A caller that had to choose is a
 * caller that can choose wrong, and "we could not save that" over a table
 * somebody was not looking at is the way it would go wrong.
 *
 * @param string $result        What happened.
 * @param int    $credential_id Which credential's form to come back to, or 0
 *                              for the blank one.
 */
function gwc_vt_credentials_redirect( string $result, int $credential_id = 0 ): void {
	$to_form = in_array( $result, array( 'no-name', 'failed' ), true );

	/* Back to the form somebody was filling in, which for an edit is that
	 * credential's own: "that could not be saved" belongs over the fields it
	 * could not save, and sending an edit to the blank add form would offer to
	 * make a second credential out of the mistake. */
	$form = $credential_id > 0 ? (string) $credential_id : 'new';

	wp_safe_redirect(
		gwc_vt_credentials_url(
			array_merge(
				array( 'gwc_vt_credential_did' => $result ),
				$to_form ? array( 'credential' => $form ) : array()
			)
		)
	);
	exit;
}

/**
 * Say what the last action did.
 */
function gwc_vt_credentials_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwc_vt_credential_did'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_credential_did'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	$errors = array(
		'no-name' => __( 'Give the credential a name. Nothing was added.', 'groundwork-common-volunteer-tracker' ),
		'failed'  => __( 'That could not be saved. Nothing was added.', 'groundwork-common-volunteer-tracker' ),
		'gone'    => __( 'That credential no longer exists.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $errors[ $result ] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $result ] ) );
		return;
	}

	$done = array(
		'added'    => __( 'Credential added. Record who holds it on each volunteer’s own record.', 'groundwork-common-volunteer-tracker' ),
		/* No count of who was re-dated: the number would be right and the
		 * sentence would read as though something had been done to those people,
		 * where what changed is the question being asked about them. */
		'saved'    => __( 'Saved. Any expiry is worked out from the new interval from now on.', 'groundwork-common-volunteer-tracker' ),
		/* Said in full, because retiring is the action whose consequences are
		 * least obvious: it stops the credential being asked for, and leaves
		 * every record of somebody holding it exactly where it is. */
		'retired'  => __( 'Retired. Nobody will be asked for it again, and every record of somebody holding it is kept.', 'groundwork-common-volunteer-tracker' ),
		'restored' => __( 'Back in use.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $done[ $result ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $done[ $result ] ) );
	}
}

/**
 * Who holds one credential, as two numbers that are also the way to the names.
 *
 * The definitions screen is where somebody is already looking at a credential,
 * so it is where "and who has it" belongs — rather than a report screen of its
 * own that has to be found.
 *
 * Both numbers link, and both link through gwc_vt_credential_holders_url(), so
 * the count and the list it opens are the same question asked once. A zero is
 * not a link: there is nothing to open, and a link that lands on an empty list
 * is a link that wasted somebody's click.
 *
 * @param int $credential_id Credential post ID.
 */
function gwc_vt_render_credential_holders( int $credential_id ): void {
	$counts = gwc_vt_credential_holder_counts( $credential_id );

	if ( 0 === $counts['current'] && 0 === $counts['expired'] ) {
		printf( '<span class="description">%s</span>', esc_html__( 'Nobody yet', 'groundwork-common-volunteer-tracker' ) );
		return;
	}

	/* "Held" and "Lapsed" — the same two words the badges on the volunteer's own
	 * record use, and the same two the filter dropdown offers. One vocabulary
	 * across the three screens that talk about the same fact.
	 *
	 * This used to read "1 holds it" and "1 has let it lapse". The first is a
	 * bare number doing the work of a subject, which reads as a fragment; the
	 * second says somebody was careless about a class the organization may not
	 * have run since. Neither is a thing to tell a coordinator scanning a
	 * table, and the second is not the plugin's business to say.
	 *
	 * Counting nouns rather than conjugating verbs also means no singular and
	 * plural forms to keep in step — "1 held" and "4 held" are both right. */
	$said = array();

	if ( $counts['current'] > 0 ) {
		$said[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( gwc_vt_credential_holders_url( $credential_id, GWC_VT_HOLDS_CURRENT ) ),
			esc_html(
				sprintf(
					/* translators: %s: a count of people, already formatted. Reads "4 held" under a column headed "Who holds it". */
					__( '%s held', 'groundwork-common-volunteer-tracker' ),
					number_format_i18n( $counts['current'] )
				)
			)
		);
	}

	if ( $counts['expired'] > 0 ) {
		$said[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( gwc_vt_credential_holders_url( $credential_id, GWC_VT_HOLDS_EXPIRED ) ),
			esc_html(
				sprintf(
					/* translators: %s: a count of people, already formatted. Reads "1 lapsed". */
					__( '%s lapsed', 'groundwork-common-volunteer-tracker' ),
					number_format_i18n( $counts['expired'] )
				)
			)
		);
	}

	echo wp_kses(
		implode( ' &middot; ', $said ),
		array( 'a' => array( 'href' => array() ) )
	);
}

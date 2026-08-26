<?php
/**
 * Defining what volunteers have to hold.
 *
 * One screen, listing every credential with the interval it renews on and what
 * it does when somebody has not got it. Adding one is a form at the bottom
 * rather than a second screen: there are three fields, and a page load to reach
 * three fields is a page load nobody wanted.
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

/** Where credentials are defined. */
const GWC_VT_CREDENTIALS_PAGE = 'gwc-vt-credentials';

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
 * The screen.
 */
function gwc_vt_render_credentials_screen(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to see credentials.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$live    = gwc_vt_live_credential_ids();
	$retired = get_posts(
		array(
			'post_type'      => GWC_VT_CREDENTIAL_TYPE,
			'post_status'    => GWC_VT_CREDENTIAL_RETIRED,
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_credentials_title() ); ?></h1>

		<?php gwc_vt_credentials_notice(); ?>

		<p class="description">
			<?php esc_html_e( 'Things a volunteer has to hold before doing certain work — a training course, a signed waiver, a background check. You define them here and record who holds them on each volunteer’s own record.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php
		/* Said plainly rather than left to be discovered. Until shifts can ask
		 * for a credential, "stop them signing up" has nothing to stop — and a
		 * setting that appears to do something it does not is worse than one
		 * that is not there. */
		?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Recording who holds what works now. Attaching credentials to shifts and events — so that a missing one is reported, or stops somebody signing up — is still being built, so nothing here refuses anybody yet.', 'groundwork-common-volunteer-tracker' ); ?></p>
		</div>

		<?php if ( $live || $retired ) : ?>
			<table class="widefat striped gwcvt-credentials">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Credential', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Renewed', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'If somebody has not got it', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Who holds it', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_merge( $live, array_map( 'intval', (array) $retired ) ) as $credential_id ) : ?>
						<?php gwc_vt_render_credential_row( gwc_vt_credential( (int) $credential_id ) ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Nothing defined yet.', 'groundwork-common-volunteer-tracker' ); ?></p>
		<?php endif; ?>

		<?php if ( gwc_vt_can_define_credentials() ) : ?>
			<?php gwc_vt_render_credential_form(); ?>
		<?php else : ?>
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

	$modes = gwc_vt_credential_modes();
	?>
	<tr>
		<td>
			<strong><?php echo esc_html( $credential['name'] ); ?></strong>
			<?php if ( $credential['retired'] ) : ?>
				<span class="gwcvt-badge gwcvt-badge--cancelled"><?php esc_html_e( 'Retired', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $credential['note'] ) : ?>
				<br /><span class="description"><?php echo esc_html( $credential['note'] ); ?></span>
			<?php endif; ?>
		</td>
		<td>
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
		<td><?php echo esc_html( $modes[ $credential['mode'] ] ?? '' ); ?></td>
		<td><?php gwc_vt_render_credential_holders( $credential['id'] ); ?></td>
		<td class="gwcvt-credentials__actions">
			<?php if ( gwc_vt_can_define_credentials() ) : ?>
				<?php if ( $credential['retired'] ) : ?>
					<a class="button" href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_restore_credential', $credential['id'] ) ); ?>">
						<?php esc_html_e( 'Put it back', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( gwc_vt_credential_action_url( 'gwc_vt_retire_credential', $credential['id'] ) ); ?>">
						<?php esc_html_e( 'Retire', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * The add form.
 *
 * Three fields and no editing of an existing one in this version. Renaming a
 * credential is the change somebody will want and it is not free: every record
 * of somebody holding it points here by ID, so a rename is safe, but changing
 * the interval silently re-dates every expiry on the site. That deserves its
 * own screen saying so, rather than a pencil icon.
 */
function gwc_vt_render_credential_form(): void {
	?>
	<h2><?php esc_html_e( 'Add a credential', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwc_vt_save_credential" />
		<?php wp_nonce_field( 'gwc_vt_save_credential_0' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gwcvt-credential-name"><?php esc_html_e( 'What it is', 'groundwork-common-volunteer-tracker' ); ?></label></th>
				<td>
					<input type="text" id="gwcvt-credential-name" name="gwc_vt_name" class="regular-text" maxlength="120" required />
					<p class="description"><?php esc_html_e( 'What you would call it out loud — “Child safety class”, “Liability waiver”.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gwcvt-credential-months"><?php esc_html_e( 'Renewed every', 'groundwork-common-volunteer-tracker' ); ?></label></th>
				<td>
					<input type="number" id="gwcvt-credential-months" name="gwc_vt_months" min="0" max="<?php echo esc_attr( (string) GWC_VT_CREDENTIAL_MAX_MONTHS ); ?>" step="1" value="0" />
					<?php esc_html_e( 'months', 'groundwork-common-volunteer-tracker' ); ?>
					<p class="description"><?php esc_html_e( 'Zero means it never expires. Somebody who did it on the 31st of a month renews on the 31st, or on the last day of a month that is shorter.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gwcvt-credential-mode"><?php esc_html_e( 'If somebody has not got it', 'groundwork-common-volunteer-tracker' ); ?></label></th>
				<td>
					<select id="gwcvt-credential-mode" name="gwc_vt_mode">
						<?php foreach ( gwc_vt_credential_modes() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Reporting is the safer default and the one most organizations want: you find out who is short, and decide. Stopping somebody is for the things nobody may work without.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gwcvt-credential-note"><?php esc_html_e( 'A note', 'groundwork-common-volunteer-tracker' ); ?></label></th>
				<td>
					<input type="text" id="gwcvt-credential-note" name="gwc_vt_note" class="regular-text" maxlength="200" />
					<p class="description"><?php esc_html_e( 'Optional, and for staff. Where the course is booked, who countersigns the form — whatever whoever records this needs to know.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Add it', 'groundwork-common-volunteer-tracker' ) ); ?>
	</form>
	<?php
}

/**
 * Write a new credential.
 */
function gwc_vt_handle_save_credential(): void {
	gwc_vt_require_credential_cap();

	check_admin_referer( 'gwc_vt_save_credential_0' );

	$posted = wp_unslash( $_POST );

	$name = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_name'] ?? '' ) ), 0, 120 );

	if ( '' === trim( $name ) ) {
		gwc_vt_credentials_redirect( 'no-name' );
	}

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
	$mode          = sanitize_key( (string) ( $posted['gwc_vt_mode'] ?? '' ) );

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
 * @param string $result What happened.
 */
function gwc_vt_credentials_redirect( string $result ): void {
	wp_safe_redirect(
		add_query_arg(
			array(
				'post_type'             => GWC_VT_ENTRY_TYPE,
				'page'                  => GWC_VT_CREDENTIALS_PAGE,
				'gwc_vt_credential_did' => $result,
			),
			admin_url( 'edit.php' )
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

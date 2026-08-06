<?php
/**
 * The Letters screen: produce one, send one, check one.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwcvt_register_letters_menu', 11 );
add_action( 'admin_post_gwcvt_letter_print', 'gwcvt_handle_letter_print' );
add_action( 'admin_post_gwcvt_letter_send', 'gwcvt_handle_letter_send' );

/**
 * Hang the Letters screen off the Volunteer Hours menu.
 */
function gwcvt_register_letters_menu(): void {
	add_submenu_page(
		GWCVT_MENU_SLUG,
		__( 'Verification Letters', 'groundwork-common-volunteer-tracker' ),
		__( 'Letters', 'groundwork-common-volunteer-tracker' ),
		GWCVT_CAP_OPEN_LETTERS,
		GWCVT_LETTERS_PAGE,
		'gwcvt_render_letters_screen'
	);
}

/**
 * A URL for the Letters screen.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function gwcvt_letters_url( array $args = array() ): string {
	return add_query_arg(
		array_merge( array( 'page' => GWCVT_LETTERS_PAGE ), $args ),
		admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE )
	);
}

/**
 * The screen.
 */
function gwcvt_render_letters_screen(): void {
	if ( ! current_user_can( GWCVT_CAP_OPEN_LETTERS ) ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	/* Producing one is the higher-trust half and stays where it was. Somebody who
	 * can only verify gets the reference checker and the log. */
	$can_issue = current_user_can( gwcvt_cap( 'issue' ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from these.
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$from = isset( $_GET['from'] ) ? gwcvt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$to = isset( $_GET['to'] ) ? gwcvt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';

	$letter = $volunteer_id > 0
		? gwcvt_build_letter( $volunteer_id, array( 'from' => $from, 'to' => $to ) )
		: null;
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Verification Letters', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php gwcvt_letters_notice(); ?>

		<div class="gwcvt-letters-layout">
			<div class="gwcvt-letters-main">
			<?php if ( ! $can_issue ) : ?>
				<h2 class="title"><?php esc_html_e( 'Checking a letter', 'groundwork-common-volunteer-tracker' ); ?></h2>
				<p class="description" style="max-width:40em">
					<?php esc_html_e( 'You can check whether a reference somebody has phoned in about still matches this organization\'s records. Producing a letter is a separate permission — ask an administrator if you need it.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			<?php else : ?>
				<h2 class="title"><?php esc_html_e( 'Produce a letter', 'groundwork-common-volunteer-tracker' ); ?></h2>

				<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="gwcvt-letter-form">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( GWCVT_ENTRY_TYPE ); ?>" />
					<input type="hidden" name="page" value="<?php echo esc_attr( GWCVT_LETTERS_PAGE ); ?>" />

					<div class="gwcvt-field">
						<label for="gwcvt-letter-volunteer">
							<strong><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></strong>
						</label>
						<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
							<input
								type="text"
								id="gwcvt-letter-volunteer"
								class="regular-text"
								autocomplete="off"
								role="combobox"
								aria-expanded="false"
								aria-autocomplete="list"
								aria-controls="gwcvt-letter-volunteer-results"
								placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
								value="<?php echo esc_attr( $volunteer_id > 0 ? get_the_title( $volunteer_id ) : '' ); ?>"
							/>
							<input type="hidden" name="volunteer" id="gwcvt-letter-volunteer-id" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
							<ul id="gwcvt-letter-volunteer-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
						</div>
					</div>

					<div class="gwcvt-field-row">
						<div class="gwcvt-field">
							<label for="gwcvt-letter-from"><strong><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></strong></label>
							<input type="date" id="gwcvt-letter-from" name="from" value="<?php echo esc_attr( $from ); ?>" />
						</div>
						<div class="gwcvt-field">
							<label for="gwcvt-letter-to"><strong><?php esc_html_e( 'To', 'groundwork-common-volunteer-tracker' ); ?></strong></label>
							<input type="date" id="gwcvt-letter-to" name="to" value="<?php echo esc_attr( $to ); ?>" />
						</div>
					</div>

					<p class="description">
						<?php esc_html_e( 'Leave both dates empty to cover their whole time volunteering.', 'groundwork-common-volunteer-tracker' ); ?>
					</p>

					<p><button type="submit" class="button"><?php esc_html_e( 'Preview', 'groundwork-common-volunteer-tracker' ); ?></button></p>
				</form>

				<?php gwcvt_render_letter_preview( $letter, $volunteer_id, $from, $to ); ?>
			<?php endif; ?>
			</div>

			<div class="gwcvt-letters-aside">
				<?php gwcvt_render_reference_checker(); ?>
				<?php gwcvt_render_letter_log(); ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * What the chosen volunteer's letter would say, and the two ways to issue it.
 *
 * @param GWCVT_Letter|null $letter       The letter, or null.
 * @param int               $volunteer_id Volunteer post ID.
 * @param string            $from         Y-m-d or ''.
 * @param string            $to           Y-m-d or ''.
 */
function gwcvt_render_letter_preview( $letter, int $volunteer_id, string $from, string $to ): void {
	if ( ! $letter instanceof GWCVT_Letter ) {
		if ( $volunteer_id > 0 ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'That volunteer no longer exists.', 'groundwork-common-volunteer-tracker' )
			);
		}
		return;
	}

	$email = (string) get_post_meta( $letter->volunteer_id, GWCVT_VOLUNTEER_EMAIL, true );
	?>
	<hr />
	<h2 class="title"><?php echo esc_html( $letter->volunteer_name ); ?></h2>

	<?php if ( $letter->is_empty() ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'There are no published hour entries for this volunteer in that period, so there is nothing to verify. A letter stating zero hours is not something to send.', 'groundwork-common-volunteer-tracker' ); ?></p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<table class="widefat striped gwcvt-preview">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Verified hours', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><strong><?php echo esc_html( gwcvt_format_hours( $letter->verified_minutes ) ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Verified shifts', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $letter->verified_count() ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Period', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><?php echo esc_html( gwcvt_letter_period( $letter ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Reference', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><code><?php echo esc_html( $letter->reference ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<?php if ( 0 === $letter->verified_minutes ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'None of these hours has been verified yet. Verify them before issuing a letter — otherwise it reports zero verified hours.', 'groundwork-common-volunteer-tracker' ); ?></p>
		</div>
	<?php endif; ?>

	<?php gwcvt_render_letterhead_warning(); ?>

	<p class="gwcvt-letter-actions">
		<a
			class="button button-primary"
			target="_blank"
			rel="noopener"
			href="<?php echo esc_url( gwcvt_letter_action_url( 'gwcvt_letter_print', $volunteer_id, $from, $to ) ); ?>"
		>
			<?php esc_html_e( 'Open the letter to print', 'groundwork-common-volunteer-tracker' ); ?>
		</a>

		<?php if ( '' !== $email && is_email( $email ) ) : ?>
			<a
				class="button"
				href="<?php echo esc_url( gwcvt_letter_action_url( 'gwcvt_letter_send', $volunteer_id, $from, $to ) ); ?>"
				onclick="return confirm( '<?php echo esc_js( sprintf( /* translators: %s: an email address. */ __( 'Email this letter to %s?', 'groundwork-common-volunteer-tracker' ), $email ) ); ?>' );"
			>
				<?php
				printf(
					/* translators: %s: an email address. */
					esc_html__( 'Email it to %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( $email )
				);
				?>
			</a>
		<?php else : ?>
			<span class="description">
				<?php
				printf(
					/* translators: %s: a link to the volunteer's record. */
					esc_html__( 'No email address on file, so it cannot be emailed. %s', 'groundwork-common-volunteer-tracker' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $volunteer_id ) ) . '">'
						. esc_html__( 'Add one to their record', 'groundwork-common-volunteer-tracker' )
						. '</a>'
				);
				?>
			</span>
		<?php endif; ?>
	</p>

	<p class="description">
		<?php esc_html_e( 'Both actions are recorded in the log, including printing — a printed letter has left the building just as much as an emailed one.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>
	<?php
}

/**
 * A nonced URL for issuing a letter.
 *
 * The nonce action carries the volunteer ID, so one minted for a preview of one
 * person cannot be replayed to issue a letter about somebody else.
 *
 * @param string $action       admin_post action.
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $from         Y-m-d or ''.
 * @param string $to           Y-m-d or ''.
 * @return string
 */
function gwcvt_letter_action_url( string $action, int $volunteer_id, string $from, string $to ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => $action,
				'volunteer' => $volunteer_id,
				'from'      => $from,
				'to'        => $to,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $volunteer_id
	);
}

/**
 * The shared front half of both issue handlers.
 *
 * @param string $action admin_post action and nonce prefix.
 * @return array{letter:GWCVT_Letter, volunteer_id:int, from:string, to:string}
 */
function gwcvt_letter_request(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce for this exact volunteer is checked below; this read identifies which.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;

	gwcvt_require_cap( 'issue' );

	check_admin_referer( $action . '_' . $volunteer_id );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
	$from = isset( $_GET['from'] ) ? gwcvt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
	$to = isset( $_GET['to'] ) ? gwcvt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';

	$letter = gwcvt_build_letter( $volunteer_id, array( 'from' => $from, 'to' => $to ) );

	if ( ! $letter instanceof GWCVT_Letter ) {
		wp_die(
			esc_html__( 'That volunteer does not exist.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	return array(
		'letter'       => $letter,
		'volunteer_id' => $volunteer_id,
		'from'         => $from,
		'to'           => $to,
	);
}

/* ── Whose letterhead is this? ───────────────────────────────────────────────
 * Three settings fall back to something reasonable when empty, and the letter
 * prints perfectly well without any of them: the organisation's name becomes the
 * WordPress site title, the contact becomes the site's admin email, and the
 * signature line prints the literal word "Signature".
 *
 * Each fallback is right on its own. Together they mean a coordinator who never
 * opened the Letter tab can hand a court a document headed with a website's
 * title, giving a webmaster's address as the number to ring, over a line reading
 * "Signature" where a person's name belongs — with nothing anywhere saying so.
 *
 * A warning and not a block. The plugin does not get to decide that somebody's
 * letterhead is wrong; it does have to say what it is about to print.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The letterhead values still coming from somewhere other than the settings.
 *
 * @return string[] Sentences, each naming one fallback.
 */
function gwcvt_letterhead_gaps(): array {
	$gaps = array();

	if ( '' === trim( (string) gwcvt_setting( 'org_name' ) ) ) {
		$gaps[] = sprintf(
			/* translators: %s: the site's title. */
			__( 'The letter will be headed “%s”, which is this website\'s title rather than an organization name anybody chose.', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_name()
		);
	}

	if ( '' === trim( (string) gwcvt_setting( 'org_contact' ) ) ) {
		$gaps[] = sprintf(
			/* translators: %s: an email address. */
			__( 'Questions about it will be directed to %s, this site\'s administrator address. That is the contact somebody uses to check a reference code, so it should be one that is answered.', 'groundwork-common-volunteer-tracker' ),
			gwcvt_org_contact()
		);
	}

	if ( '' === trim( (string) gwcvt_setting( 'signatory_name' ) ) ) {
		$gaps[] = __( 'Nobody is named under the signature line, so it prints the word “Signature”. That is deliberate — it looks unfinished because it is — but it will look that way to whoever receives it.', 'groundwork-common-volunteer-tracker' );
	}

	return $gaps;
}

/**
 * Say what this letter would carry that nobody chose.
 */
function gwcvt_render_letterhead_warning(): void {
	$gaps = gwcvt_letterhead_gaps();

	if ( ! $gaps ) {
		return;
	}
	?>
	<div class="notice notice-warning inline">
		<p><strong><?php esc_html_e( 'This letter has not been given your letterhead yet.', 'groundwork-common-volunteer-tracker' ); ?></strong></p>

		<?php foreach ( $gaps as $gap ) : ?>
			<p><?php echo esc_html( $gap ); ?></p>
		<?php endforeach; ?>

		<p>
			<a href="<?php echo esc_url( gwcvt_settings_url( 'letter' ) ); ?>">
				<?php esc_html_e( 'Set it up on the Letter tab', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/* ── Printing ────────────────────────────────────────────────────────────────
 * A standalone document that exits, not a wp-admin screen. An admin screen
 * drags the admin bar, the menu and core's stylesheet into the print, and the
 * @media print rules needed to hide all of that break on every WordPress
 * release. Rendering our own document is both simpler and stable.
 *
 * The route is admin-post.php rather than a front-end pretty URL, because a URL
 * for a letter is a URL that leaks, and this letter states that a named person
 * is performing court-ordered service. admin-post.php requires a session before
 * our handler runs; the handler then checks the capability and a per-volunteer
 * nonce.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Render a letter for printing.
 */
function gwcvt_handle_letter_print(): void {
	$request = gwcvt_letter_request();
	$letter  = $request['letter'];

	gwcvt_log_letter( $letter, 'print' );

	/* This is the response in this plugin with the most personal data in it.
	 * Nothing about it should be cached, stored by an intermediary, or indexed. */
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	header( 'Referrer-Policy: no-referrer', true );

	echo gwcvt_render_letter( $letter, 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete document, escaped as it was assembled in inc/render.php.
	exit;
}

/**
 * Email a letter to the volunteer.
 */
function gwcvt_handle_letter_send(): void {
	$request = gwcvt_letter_request();
	$letter  = $request['letter'];

	$recipient = (string) get_post_meta( $letter->volunteer_id, GWCVT_VOLUNTEER_EMAIL, true );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		gwcvt_letters_redirect( 'no-email', $request );
	}

	$sent = gwcvt_send_email(
		$recipient,
		gwcvt_letter_subject( $letter ),
		gwcvt_render_letter( $letter, 'email' )
	);

	gwcvt_log_letter( $letter, 'email', $recipient, $sent );

	gwcvt_letters_redirect( $sent ? 'sent' : 'send-failed', $request );
}

/**
 * Back to the Letters screen with a result.
 *
 * @param string $result  What happened.
 * @param array  $request From gwcvt_letter_request().
 */
function gwcvt_letters_redirect( string $result, array $request ): void {
	wp_safe_redirect(
		gwcvt_letters_url(
			array(
				'volunteer'    => $request['volunteer_id'],
				'from'         => $request['from'],
				'to'           => $request['to'],
				'gwcvt_letter' => $result,
			)
		)
	);
	exit;
}

/**
 * Say what happened.
 */
function gwcvt_letters_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which sentence to print after a redirect.
	$result = isset( $_GET['gwcvt_letter'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_letter'] ) ) : '';

	$messages = array(
		'sent'        => array( 'success', __( 'The letter was emailed and recorded in the log.', 'groundwork-common-volunteer-tracker' ) ),
		'send-failed' => array( 'error', __( 'WordPress could not send the email. The attempt is recorded in the log. Check the site’s mail configuration, or print the letter instead.', 'groundwork-common-volunteer-tracker' ) ),
		'no-email'    => array( 'error', __( 'That volunteer has no email address on file.', 'groundwork-common-volunteer-tracker' ) ),
	);

	if ( ! isset( $messages[ $result ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $result ][0] ),
		esc_html( $messages[ $result ][1] )
	);
}

/* ── Checking a reference ────────────────────────────────────────────────────
 * This is what turns the reference code from decoration into evidence. A court
 * or a school phones the organisation, reads out the code, and somebody on the
 * front desk can answer in ten seconds.
 *
 * Three answers, and the wording of each is deliberate — see the note on
 * gwcvt_verify_reference(). In particular a mismatch is reported as "the
 * records have changed", never as "invalid" or "forged": hours get corrected,
 * an entry gets verified after the letter went out, a duplicate is removed. All
 * ordinary, none anybody's fault.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The reference checker box.
 */
function gwcvt_render_reference_checker(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; nothing is written and no data is disclosed that the viewer cannot already see.
	$code = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
	?>
	<div class="gwcvt-box">
		<h2><?php esc_html_e( 'Check a reference', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'Somebody has phoned about a letter. Type the reference code printed on it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( GWCVT_ENTRY_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( GWCVT_LETTERS_PAGE ); ?>" />

			<p>
				<label class="screen-reader-text" for="gwcvt-reference"><?php esc_html_e( 'Reference code', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-reference" name="reference" class="regular-text code" value="<?php echo esc_attr( $code ); ?>" />
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Check it', 'groundwork-common-volunteer-tracker' ); ?></button></p>
		</form>

		<?php
		if ( '' === trim( $code ) ) {
			echo '</div>';
			return;
		}

		$result = gwcvt_verify_reference( $code );

		if ( 'unknown' === $result['status'] ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div></div>',
				esc_html__( 'No letter with that reference has been issued from this site.', 'groundwork-common-volunteer-tracker' )
			);
			return;
		}

		$record = $result['letter'];
		$name   = $record['volunteer_id'] > 0 ? get_the_title( $record['volunteer_id'] ) : '';
		$name   = '' !== $name ? $name : __( 'a volunteer whose record has since been removed', 'groundwork-common-volunteer-tracker' );

		if ( 'match' === $result['status'] ) {
			printf(
				'<div class="notice notice-success inline"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
				esc_html__( 'This letter matches our current records.', 'groundwork-common-volunteer-tracker' ),
				esc_html(
					sprintf(
						/* translators: 1: a volunteer's name, 2: a duration, 3: a number of shifts, 4: a date. */
						__( '%1$s — %2$s verified across %3$s. Issued %4$s.', 'groundwork-common-volunteer-tracker' ),
						$name,
						gwcvt_format_hours( $record['minutes'] ),
						sprintf(
							/* translators: %d: number of shifts. */
							_n( '%d shift', '%d shifts', $record['entries'], 'groundwork-common-volunteer-tracker' ),
							$record['entries']
						),
						$record['issued_at']
					)
				)
			);
		} else {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p></div>',
				esc_html__( 'This letter was issued from this site, but our records have changed since.', 'groundwork-common-volunteer-tracker' ),
				esc_html(
					sprintf(
						/* translators: 1: a volunteer's name, 2: a duration, 3: a date. */
						__( 'The letter said: %1$s, %2$s verified. Issued %3$s.', 'groundwork-common-volunteer-tracker' ),
						$name,
						gwcvt_format_hours( $record['minutes'] ),
						$record['issued_at']
					)
				),
				esc_html( gwcvt_reference_difference_note( $result, $record ) )
			);
		}

		gwcvt_render_reference_comparison( $result );
		?>
	</div>
	<?php
}

/**
 * What actually differs, said in a way that does not read as a contradiction.
 *
 * The obvious wording — "the letter said 29.75 verified, the records now say
 * 29.75 verified" — prints the same number twice under a heading announcing a
 * change, and anybody reading it reasonably concludes the screen is broken.
 *
 * It is not: that is the most interesting case there is. The totals agreeing
 * while the code disagrees means somebody altered the DETAIL — an activity, a
 * date, a supervisor, or two shifts swapped so the sum came out the same. Those
 * are precisely the edits a reader comparing totals would miss, and precisely
 * why the digest covers every field the letter prints. So the screen has to
 * name that case rather than show the number twice and leave it hanging.
 *
 * @param array $result From gwcvt_verify_reference().
 * @param array $record The stored log entry.
 * @return string
 */
function gwcvt_reference_difference_note( array $result, array $record ): string {
	if ( ! isset( $result['current']['minutes'] ) ) {
		return __( 'That volunteer’s record no longer exists, so there is nothing left to compare against.', 'groundwork-common-volunteer-tracker' );
	}

	$now = (int) $result['current']['minutes'];

	if ( $now === (int) $record['minutes'] ) {
		return __( 'The total is unchanged, so the difference is in the detail — a date, an activity, a supervisor, or hours moved between shifts. Compare the letter below against the copy you were sent to see which.', 'groundwork-common-volunteer-tracker' );
	}

	return sprintf(
		/* translators: %s: a duration. */
		__( 'The records now say: %s verified. This is ordinary — hours get corrected and shifts get verified after a letter goes out. Compare the letter below against the copy you were sent.', 'groundwork-common-volunteer-tracker' ),
		gwcvt_format_hours( $now )
	);
}

/**
 * The letter as our records would produce it today, in full.
 *
 * ── Why the whole document and not a summary ─────────────────────────────────
 * A summary answers "do the totals agree". The question somebody holding a
 * printed letter is actually asking is "is this the same document" — and the
 * ways a letter gets altered are mostly not the total. An activity description
 * rewritten, a date nudged, a supervisor's name changed, two shifts swapped so
 * the sum is unchanged: none of those move a figure anybody would think to
 * compare, and all of them change what the document says.
 *
 * The reference digest now covers every one of those (see
 * gwcvt_letter_fingerprint), so the plugin can say THAT something differs. It
 * cannot say WHAT. Rendering the current letter in full is what lets a person
 * put the two side by side and see it.
 *
 * Deliberately not an iframe of the print view: that route logs an issuance
 * every time it is opened, and checking a reference is not issuing a letter.
 * The audit log would fill up with letters nobody sent.
 *
 * @param array $result From gwcvt_verify_reference().
 */
function gwcvt_render_reference_comparison( array $result ): void {
	$letter = $result['rebuilt'] ?? null;

	if ( ! $letter instanceof GWCVT_Letter ) {
		return;
	}

	wp_enqueue_style( 'gwcvt-letter' );
	?>
	<details class="gwcvt-comparison" <?php echo 'changed' === $result['status'] ? 'open' : ''; ?>>
		<summary>
			<?php
			echo esc_html(
				'changed' === $result['status']
					? __( 'Show the letter as our records stand now — compare it against the copy you were sent', 'groundwork-common-volunteer-tracker' )
					: __( 'Show the full letter', 'groundwork-common-volunteer-tracker' )
			);
			?>
		</summary>

		<div class="gwcvt-comparison__paper">
			<?php
			echo gwcvt_letter_body( $letter, 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in inc/render.php.
			?>
		</div>

		<p class="description">
			<?php esc_html_e( 'This is generated from the records as they stand right now. It is not a stored copy of what was sent — this plugin does not keep one.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</details>
	<?php
}

/**
 * The recent-issuance log.
 */
function gwcvt_render_letter_log(): void {
	$records = gwcvt_recent_letters( 15 );
	?>
	<div class="gwcvt-box">
		<h2><?php esc_html_e( 'Recently issued', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<?php if ( ! $records ) : ?>
			<p class="description"><?php esc_html_e( 'No letters have been issued yet.', 'groundwork-common-volunteer-tracker' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gwcvt-log">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Reference', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'How', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $records as $record ) : ?>
						<tr>
							<td><code><?php echo esc_html( $record['reference'] ); ?></code></td>
							<td>
								<?php
								$name = $record['volunteer_id'] > 0 ? get_the_title( $record['volunteer_id'] ) : '';
								echo esc_html( '' !== $name ? $name : __( 'record removed', 'groundwork-common-volunteer-tracker' ) );
								?>
							</td>
							<td>
								<?php
								if ( 'email' === $record['medium'] ) {
									echo esc_html(
										$record['sent_ok']
											? __( 'Emailed', 'groundwork-common-volunteer-tracker' )
											: __( 'Email failed', 'groundwork-common-volunteer-tracker' )
									);
								} else {
									esc_html_e( 'Printed', 'groundwork-common-volunteer-tracker' );
								}
								?>
							</td>
							<td><?php echo esc_html( $record['issued_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

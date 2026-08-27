<?php
/**
 * The Letters screen: produce one, send one, check one.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_letters_menu', 11 );
add_action( 'admin_post_gwc_vt_letter_print', 'gwc_vt_handle_letter_print' );
add_action( 'admin_post_gwc_vt_letter_send', 'gwc_vt_handle_letter_send' );

/**
 * Hang the Letters screen off the Volunteer Tracker menu.
 */
function gwc_vt_register_letters_menu(): void {
	if ( ! gwc_vt_letters_enabled() ) {
		return;
	}

	add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_letters_page_title(),
		gwc_vt_letters_page_title(),
		GWC_VT_CAP_OPEN_LETTERS,
		GWC_VT_LETTERS_PAGE,
		'gwc_vt_render_letters_screen'
	);

	/* Producing one is its own screen, registered and then taken off the menu by
	 * gwc_vt_hidden_menu_items(). It is reached from the volunteer it is about. */
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_produce_page_title(),
		gwc_vt_produce_page_title(),
		gwc_vt_cap( 'issue' ),
		GWC_VT_PRODUCE_PAGE,
		'gwc_vt_render_produce_letter_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_produce_page_title' );
	}
}

/* ── The two ways out of a screen you cannot do anything on ──────────────────
 * This page is a log. Everything it lists has already happened, and the two
 * things somebody arrives wanting — produce a letter, check a reference
 * somebody has read down the phone — both live elsewhere. That was said in
 * prose and left as prose, which made the screen a cul-de-sac: it told you
 * where to go and then made you find it.
 *
 * They are links rather than buttons on purpose. A page-title action here would
 * read as the primary thing to do on a screen whose primary thing is reading,
 * and there is a sharper reason than tidiness for the first one:
 * gwc_vt_hidden_menu_items() takes "Produce a letter" off the menu because a
 * menu entry is "an invitation to start from a blank form and go looking for
 * somebody, which is the flow it replaced". A button here would rebuild exactly
 * that. The link goes to the volunteer LIST instead — you pick the person, then
 * the letter, which is the order the whole feature is built around.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Where to go from here, since it is not from here.
 *
 * Each is offered only to somebody who can actually open it, the same rule the
 * dashboard's quick actions follow: a link that lands on a permission notice is
 * worse than no link, because it reads as a thing you are supposed to be able
 * to do.
 */
function gwc_vt_letters_next_steps(): void {
	$links = array();

	if ( gwc_vt_can_see_records() ) {
		$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) ) . '">'
			. esc_html__( 'Produce one from a volunteer’s record', 'groundwork-common-volunteer-tracker' )
			. '</a>';
	}

	/* The reference checker is gated exactly as this screen is, so the only
	 * question left is whether they can open the dashboard it sits on.
	 *
	 * #gwcvt-reference is the checker's own input, which
	 * gwc_vt_render_reference_checker() already labels by that id — so the link
	 * lands on the box rather than at the top of the dashboard, and it lands
	 * there without a second id being invented for the same thing. Two elements
	 * carrying it would break the <label for> as well as the anchor. */
	if ( current_user_can( gwc_vt_records_cap() ) ) {
		$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE . '&page=' . GWC_VT_DASHBOARD_PAGE ) ) . '#gwcvt-reference">'
			. esc_html__( 'Check a reference somebody has phoned in', 'groundwork-common-volunteer-tracker' )
			. '</a>';
	}

	if ( ! $links ) {
		return;
	}

	printf(
		'<p class="description gwcvt-letters__next">%s</p>',
		wp_kses( implode( ' &middot; ', $links ), array( 'a' => array( 'href' => array() ) ) )
	);
}

/**
 * The log screen's name.
 *
 * "Verification letters" is what the document is called. It is the phrase this
 * plugin's own first sentence uses — hours logged, staff attesting to them, and
 * a verification letter a court or a school will accept — so the menu says what
 * the product says.
 *
 * ── What this name stopped doing, and what covers it now ─────────────────────
 * Two earlier names, "Letter records" and then "Letters issued", were both
 * chosen to stop this row reading as the place you WRITE one. Producing a
 * letter starts from the volunteer's own record, so a menu item called
 * "Letters" sends somebody here to do a thing they cannot do here. "Records"
 * refused the invitation with a noun and "issued" refused it with a tense; this
 * name refuses it with neither.
 *
 * That work now falls on gwc_vt_letters_next_steps() above, which says where to
 * go as two links rather than as a sentence describing where to go. Those links
 * are load-bearing, not decoration: take them away and the name alone invites
 * somebody to write a letter on a screen that only lists them. Do not let the
 * name shorten to "Letters" either, which would sharpen the invitation while
 * removing the last word that qualifies it.
 *
 * @return string
 */
function gwc_vt_letters_page_title(): string {
	return __( 'Verification letters', 'groundwork-common-volunteer-tracker' );
}

/**
 * The produce screen's name.
 *
 * @return string
 */
function gwc_vt_produce_page_title(): string {
	return __( 'Produce a letter', 'groundwork-common-volunteer-tracker' );
}

/**
 * Give the produce screen its title back.
 *
 * Off the menu, so get_admin_page_title() cannot find it — the same problem
 * gwc_vt_restore_quick_add_title() describes at length.
 */
function gwc_vt_restore_produce_page_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_produce_page_title();
}

/**
 * Where a letter for one volunteer is produced.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $from         Y-m-d or ''.
 * @param string $to           Y-m-d or ''.
 * @return string
 */
function gwc_vt_produce_letter_url( int $volunteer_id, string $from = '', string $to = '' ): string {
	$args = array(
		'post_type' => GWC_VT_ENTRY_TYPE,
		'page'      => GWC_VT_PRODUCE_PAGE,
		'volunteer' => $volunteer_id,
	);

	if ( '' !== $from ) {
		$args['from'] = $from;
	}

	if ( '' !== $to ) {
		$args['to'] = $to;
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * A URL for the Letters screen.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function gwc_vt_letters_url( array $args = array() ): string {
	return add_query_arg(
		array_merge( array( 'page' => GWC_VT_LETTERS_PAGE ), $args ),
		admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE )
	);
}

/* ── Three things, three places ──────────────────────────────────────────────
 * This screen used to be all of it: produce a letter on the left, check a
 * reference and read the issued log down the right. Three jobs that share a
 * noun and nothing else.
 *
 * A letter is about ONE PERSON, so producing one starts from that person — from
 * their record, their row on the volunteer list, or the verify queue's offer
 * when their last hours are attested to. Arriving at a blank form and going
 * looking for somebody is the flow that made "which Priya?" a question.
 *
 * Checking a reference is not about a person the organization is choosing; it
 * is about a phone call that has already happened, and it is now a panel on the
 * dashboard, which is the screen whoever picks up the phone is already on. The
 * capability that gates it is unchanged — GWC_VT_CAP_OPEN_LETTERS, satisfied by
 * either verifying or issuing, so the front desk can still answer without the
 * right to sign. Anybody who could reach this screen can reach the dashboard:
 * both hang off a parent menu that needs edit_posts to appear at all.
 *
 * What is left here is the log, and it stays its own page for a reason that is
 * not tidiness: it outlives the volunteer records it refers to. The "record
 * removed" rows are the receipt of this organization's own conduct, and a
 * screen that only existed while there was somebody to produce a letter FOR
 * would lose them exactly when they start to matter.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The issued log.
 */
function gwc_vt_render_letters_screen(): void {
	/* A hidden screen whose handler still answers is not hidden. The menu is not
	 * registered when letters are off, so this is unreachable through the
	 * interface — and unreachable through the interface is not the same as
	 * unreachable. */
	if ( ! gwc_vt_letters_enabled() ) {
		wp_die(
			esc_html__( 'This organization does not issue verification letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not available', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	if ( ! current_user_can( GWC_VT_CAP_OPEN_LETTERS ) ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_letters_page_title() ); ?></h1>

		<?php gwc_vt_letters_notice(); ?>

		<p class="description gwcvt-letters__intro">
			<?php esc_html_e( 'Every letter that has left the building, printed or emailed.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php gwc_vt_letters_next_steps(); ?>

		<div class="gwcvt-letters__log">
			<?php gwc_vt_render_letter_log(); ?>
		</div>
	</div>
	<?php
}

/**
 * Producing a letter for one volunteer.
 */
function gwc_vt_render_produce_letter_screen(): void {
	if ( ! gwc_vt_letters_enabled() ) {
		wp_die(
			esc_html__( 'This organization does not issue verification letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not available', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	if ( ! current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		wp_die(
			esc_html__( 'You do not have permission to produce letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from these.
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$from = isset( $_GET['from'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$to = isset( $_GET['to'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';

	/* An issued letter somebody has come back to look at. Read before the build
	 * so its volunteer and period win over an empty query string — the link
	 * carries all three, but a hand-edited URL that kept the reference and lost
	 * the dates would otherwise rebuild a different period under a banner
	 * claiming to be about this one. */
	$reissue = gwc_vt_reissue_record();

	if ( $reissue ) {
		$volunteer_id = (int) $reissue['volunteer_id'];
		$from         = (string) $reissue['from'];
		$to           = (string) $reissue['to'];
	}

	$letter = $volunteer_id > 0
		? gwc_vt_build_letter(
			$volunteer_id,
			array(
				'from' => $from,
				'to'   => $to,
			)
		)
		: null;
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_produce_page_title() ); ?></h1>

		<?php gwc_vt_letters_notice(); ?>
		<?php gwc_vt_render_reissue_banner( $reissue ); ?>

		<?php
		/* Only when there is no preview below. gwc_vt_render_letter_preview()
		 * draws the same warning immediately above the print and send buttons,
		 * which is where it earns its place — it is the compounding-fallback
		 * trap, and the moment to read it is the moment before the document
		 * leaves. Both fired unconditionally, so the screen carried the warning
		 * twice whenever a letter was on it. Kept here for the empty screen,
		 * where somebody arriving to produce their first letter should see it
		 * before they pick anybody. */
		if ( ! $letter instanceof GWC_VT_Letter ) {
			gwc_vt_render_letterhead_warning();
		}
		?>

		<?php if ( $volunteer_id > 0 && $letter instanceof GWC_VT_Letter ) : ?>
			<?php
			/* Where they came from, said as a path rather than a back button:
			 * this screen is reached from three places and a browser's back
			 * button is the only one of them that knows which. */
			?>
			<p class="gwcvt-letters__crumbs">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) ); ?>">
					<?php esc_html_e( 'Volunteers', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span aria-hidden="true">&rarr;</span>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $volunteer_id ) ); ?>">
					<?php echo esc_html( $letter->volunteer_name ); ?>
				</a>
				<span aria-hidden="true">&rarr;</span>
				<strong><?php echo esc_html( gwc_vt_produce_page_title() ); ?></strong>
			</p>
		<?php endif; ?>

		<div class="gwcvt-letters-main">
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="gwcvt-letter-form">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( GWC_VT_ENTRY_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( GWC_VT_PRODUCE_PAGE ); ?>" />

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

				<?php gwc_vt_render_letter_readiness( $volunteer_id, $from, $to ); ?>

				<p><button type="submit" class="button"><?php esc_html_e( 'Preview the letter', 'groundwork-common-volunteer-tracker' ); ?></button></p>
			</form>

			<?php gwc_vt_render_letter_preview( $letter, $volunteer_id, $from, $to ); ?>
		</div>
	</div>
	<?php
}

/**
 * What the letter will say, before anybody presses Preview.
 *
 * ── Recomputed, never read off the rollup ────────────────────────────────────
 * The volunteer's cached total is right there and would answer this in one
 * lookup. It must not be used: a sentence that disagrees with the letter printed
 * thirty seconds later is worse than no sentence, and the letter is built from
 * entries every time precisely so it cannot inherit a stale number. So this goes
 * through gwc_vt_build_letter() — the same path the print and email handlers
 * use.
 *
 * ── And built again, with include_unverified forced on ───────────────────────
 * Not handed the letter the screen already built, which would look like the
 * obvious saving and would be wrong half the time. GWC_VT_Letter's
 * unverified_minutes is only populated when the letter was asked to LIST
 * unattested shifts, and whether it was is a site setting — so reading that
 * field would give the right answer on a site with the setting on and a
 * confident zero on a site with it off. "Nothing of theirs is waiting" is
 * exactly the sentence that must not be wrong.
 *
 * The second half is the one worth having at all. It is the difference between
 * a total somebody can hand over and a total that is about to change, and it is
 * invisible on the letter itself, which reports only what is attested.
 *
 * @param int    $volunteer_id Volunteer post ID, or 0 before one is chosen.
 * @param string $from         Y-m-d or ''.
 * @param string $to           Y-m-d or ''.
 */
function gwc_vt_render_letter_readiness( int $volunteer_id, string $from, string $to ): void {
	if ( $volunteer_id < 1 ) {
		return;
	}

	$letter = gwc_vt_build_letter(
		$volunteer_id,
		array(
			'from'               => $from,
			'to'                 => $to,
			'include_unverified' => true,
		)
	);

	if ( ! $letter instanceof GWC_VT_Letter || $letter->is_empty() ) {
		return;
	}

	$unverified = $letter->unverified_minutes;
	?>
	<div class="gwcvt-readiness gwcvt-readiness--<?php echo esc_attr( $unverified > 0 ? 'waiting' : 'clear' ); ?>">
		<p>
			<strong>
				<?php
				/* "%s of verified time", not "%s hours" — gwc_vt_format_hours()
				 * respects the site's hour_format, so on a site set to hours and
				 * minutes it returns "3h 15m" and appending a unit would read
				 * "3h 15m hours". The letter itself solved this the same way; see
				 * the unverified note in inc/render.php. */
				printf(
					/* translators: 1: a duration, e.g. "12.5". 2: how many shifts. */
					esc_html__( 'The letter will state %1$s of verified time across %2$s.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_format_hours( $letter->verified_minutes ) ),
					esc_html(
						sprintf(
							/* translators: %d: number of shifts. */
							_n( '%d shift', '%d shifts', $letter->verified_count(), 'groundwork-common-volunteer-tracker' ),
							$letter->verified_count()
						)
					)
				);
				?>
			</strong>

			<?php
			echo $unverified > 0
				? esc_html(
					sprintf(
						/* translators: %s: a duration, e.g. "3". */
						__( 'A further %s of their recorded time is not verified yet, and is not in that total. Verifying it would change what the letter says.', 'groundwork-common-volunteer-tracker' ),
						gwc_vt_format_hours( $unverified )
					)
				)
				: esc_html__( 'Nothing of theirs is waiting to be verified, so nothing is about to change that total.', 'groundwork-common-volunteer-tracker' );
			?>
		</p>
	</div>
	<?php
}

/**
 * What the chosen volunteer's letter would say, and the two ways to issue it.
 *
 * @param GWC_VT_Letter|null $letter       The letter, or null.
 * @param int                $volunteer_id Volunteer post ID.
 * @param string             $from         Y-m-d or ''.
 * @param string             $to           Y-m-d or ''.
 */
function gwc_vt_render_letter_preview( $letter, int $volunteer_id, string $from, string $to ): void {
	if ( ! $letter instanceof GWC_VT_Letter ) {
		if ( $volunteer_id > 0 ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'That volunteer no longer exists.', 'groundwork-common-volunteer-tracker' )
			);
		}
		return;
	}

	$email = (string) get_post_meta( $letter->volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );
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
				<td><strong><?php echo esc_html( gwc_vt_format_hours( $letter->verified_minutes ) ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Verified shifts', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $letter->verified_count() ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Period', 'groundwork-common-volunteer-tracker' ); ?></th>
				<td><?php echo esc_html( gwc_vt_letter_period( $letter ) ); ?></td>
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

	<?php gwc_vt_render_letterhead_warning(); ?>

	<p class="gwcvt-letter-actions">
		<a
			class="button button-primary"
			target="_blank"
			rel="noopener"
			href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_print', $volunteer_id, $from, $to ) ); ?>"
		>
			<?php esc_html_e( 'Open the letter to print', 'groundwork-common-volunteer-tracker' ); ?>
		</a>

		<?php if ( '' !== $email && is_email( $email ) ) : ?>
			<a
				class="button"
				href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_send', $volunteer_id, $from, $to ) ); ?>"
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

/* ── Looking at a letter that has already gone out ───────────────────────────
 * Somebody rings up: "can you send me that letter again". The plugin keeps no
 * copy of what was sent — deliberately, and the issued-letter log holds no name
 * — so there is nothing to re-open. What it can do is rebuild the letter from
 * the same volunteer over the same period, which is exactly what the reference
 * checker already does.
 *
 * The whole difficulty is that a rebuild is not necessarily the same document.
 * Hours get corrected and shifts get verified after a letter goes out, and when
 * that has happened the reprint says something different and carries a
 * different reference. Handing that to a probation officer as "the letter we
 * sent in March" is the failure this screen exists to prevent, so the state is
 * worked out before anything is drawn and said at the top in the plainest words
 * available.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The screen for looking at an issued letter again.
 *
 * The produce screen, with the volunteer and dates already filled in and the
 * reference carried alongside so it can say whether the two still agree. A
 * separate screen was considered and rejected: it would be the produce screen
 * with one extra sentence, and two screens that render a letter is two places
 * for the letterhead warning and the print button to drift apart.
 *
 * @param array $record From gwc_vt_letter_record().
 * @return string
 */
function gwc_vt_letter_review_url( array $record ): string {
	return add_query_arg(
		'gwc_vt_reissue',
		rawurlencode( (string) ( $record['reference'] ?? '' ) ),
		gwc_vt_produce_letter_url(
			(int) ( $record['volunteer_id'] ?? 0 ),
			(string) ( $record['from'] ?? '' ),
			(string) ( $record['to'] ?? '' )
		)
	);
}

/**
 * The issued letter this screen was asked to look at again, if any.
 *
 * @return array Empty when there is none, or the reference names nothing.
 */
function gwc_vt_reissue_record(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from it.
	$reference = isset( $_GET['gwc_vt_reissue'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_reissue'] ) ) : '';

	if ( '' === $reference ) {
		return array();
	}

	$record = gwc_vt_find_letter_record( $reference );

	return is_array( $record ) ? $record : array();
}

/**
 * Say whether printing this again would produce the same document.
 *
 * Three answers, and the middle one is the reason the banner exists at all.
 *
 * Unchanged: reprint freely, it is the same letter and the same reference.
 *
 * Changed: the records have moved since. The reprint is a NEW letter with a NEW
 * reference — it is not a copy of what was sent, and the old reference stays
 * the one on the copy the volunteer is holding. Both facts have to be said,
 * because somebody reprinting "the March letter" and posting it to a court is
 * otherwise sending a document that disagrees with the one already on file
 * there, with nothing on either page to explain why.
 *
 * Gone: the volunteer's record has been anonymized, erased or swept. There is
 * nothing to rebuild from, so there is nothing to print, and the screen says
 * that rather than rendering an empty letter.
 *
 * @param array $record From gwc_vt_letter_record().
 */
function gwc_vt_render_reissue_banner( array $record ): void {
	if ( ! $record ) {
		return;
	}

	$result = gwc_vt_verify_reference( (string) $record['reference'] );
	$status = (string) ( $result['status'] ?? 'unknown' );

	$issued = sprintf(
		/* translators: 1: a date, 2: a reference code. */
		__( 'Issued %1$s, reference %2$s.', 'groundwork-common-volunteer-tracker' ),
		$record['issued_at'],
		$record['reference']
	);

	if ( 'match' === $status ) {
		printf(
			'<div class="notice notice-success"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html( $issued ),
			esc_html__( 'Nothing on this volunteer’s record has changed since. Printing or emailing it again produces the same letter, with the same reference.', 'groundwork-common-volunteer-tracker' )
		);

		return;
	}

	if ( ! isset( $result['current']['minutes'] ) ) {
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html( $issued ),
			esc_html__( 'This volunteer’s record has since been removed or anonymized, so there is nothing left to build a letter from. The log entry above is what remains, and it is enough to confirm that a letter was issued and what it stated.', 'groundwork-common-volunteer-tracker' )
		);

		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p><strong>%4$s</strong></p></div>',
		esc_html( $issued ),
		esc_html__( 'This is not a copy of that letter — no copy is kept. It is the letter your records would produce today, and they have changed since.', 'groundwork-common-volunteer-tracker' ),
		esc_html( gwc_vt_reference_difference_note( $result, $record ) ),
		esc_html(
			sprintf(
				/* translators: %s: the reference code of the letter that was issued. */
				__( 'If you print or send this, it goes out as a new letter with a new reference. %s stays the reference on the copy the volunteer already has, and will still check out as what you issued that day.', 'groundwork-common-volunteer-tracker' ),
				$record['reference']
			)
		)
	);
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
function gwc_vt_letter_action_url( string $action, int $volunteer_id, string $from, string $to ): string {
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
 * @return array{letter:GWC_VT_Letter, volunteer_id:int, from:string, to:string}
 */
function gwc_vt_letter_request(): array {
	$action       = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;

	/* Both admin_post_ handlers come through here, so this is the one place
	 * producing a letter has to be refused. Before the capability check for the
	 * same reason the capability check is before the nonce: the cheapest refusal
	 * that is always correct goes first. */
	if ( ! gwc_vt_letters_enabled() ) {
		wp_die(
			esc_html__( 'This organization does not issue verification letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not available', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	gwc_vt_require_cap( 'issue' );

	check_admin_referer( $action . '_' . $volunteer_id );

	$from = isset( $_GET['from'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
	$to   = isset( $_GET['to'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';

	$letter = gwc_vt_build_letter(
		$volunteer_id,
		array(
			'from' => $from,
			'to'   => $to,
		)
	);

	if ( ! $letter instanceof GWC_VT_Letter ) {
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
 * prints perfectly well without any of them: the organization's name becomes the
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
function gwc_vt_letterhead_gaps(): array {
	$gaps = array();

	if ( '' === trim( (string) gwc_vt_setting( 'org_name' ) ) ) {
		$gaps[] = sprintf(
			/* translators: %s: the site's title. */
			__( 'The letter will be headed “%s”, which is this website\'s title rather than an organization name anybody chose.', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_name()
		);
	}

	if ( '' === trim( (string) gwc_vt_setting( 'org_contact' ) ) ) {
		$gaps[] = sprintf(
			/* translators: %s: an email address. */
			__( 'Questions about it will be directed to %s, this site\'s administrator address. That is the contact somebody uses to check a reference code, so it should be one that is answered.', 'groundwork-common-volunteer-tracker' ),
			gwc_vt_org_contact()
		);
	}

	if ( '' === trim( (string) gwc_vt_setting( 'signatory_name' ) ) ) {
		$gaps[] = __( 'Nobody is named under the signature line, so it prints the word “Signature”. That is deliberate — it looks unfinished because it is — but it will look that way to whoever receives it.', 'groundwork-common-volunteer-tracker' );
	}

	return $gaps;
}

/**
 * Say what this letter would carry that nobody chose.
 */
function gwc_vt_render_letterhead_warning(): void {
	$gaps = gwc_vt_letterhead_gaps();

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
			<a href="<?php echo esc_url( gwc_vt_settings_url( 'letter' ) ); ?>">
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
function gwc_vt_handle_letter_print(): void {
	$request = gwc_vt_letter_request();
	$letter  = $request['letter'];

	gwc_vt_log_letter( $letter, 'print' );

	/* This is the response in this plugin with the most personal data in it.
	 * Nothing about it should be cached, stored by an intermediary, or indexed.
	 * The two roster sheets say the same thing about themselves and now share
	 * this, rather than each carrying its own copy that only one of them kept
	 * up to date. */
	gwc_vt_private_document_headers();

	echo gwc_vt_render_letter( $letter, 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete document, escaped as it was assembled in inc/render.php.
	exit;
}

/**
 * Email a letter to the volunteer.
 */
function gwc_vt_handle_letter_send(): void {
	$request = gwc_vt_letter_request();
	$letter  = $request['letter'];

	$recipient = (string) get_post_meta( $letter->volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		gwc_vt_letters_redirect( 'no-email', $request );
	}

	$sent = gwc_vt_send_email(
		$recipient,
		gwc_vt_letter_subject( $letter ),
		gwc_vt_render_letter( $letter, 'email' )
	);

	gwc_vt_log_letter( $letter, 'email', $recipient, $sent );

	gwc_vt_letters_redirect( $sent ? 'sent' : 'send-failed', $request );
}

/**
 * Back to the Letters screen with a result.
 *
 * @param string $result  What happened.
 * @param array  $request From gwc_vt_letter_request().
 */
function gwc_vt_letters_redirect( string $result, array $request ): void {
	/* Back to the produce screen, not to the records log. Somebody who has just
	 * printed a letter is looking at the letter — the log's job starts later,
	 * and landing them there would answer a question they have not asked while
	 * losing the volunteer and the dates they were working with. */
	wp_safe_redirect(
		add_query_arg(
			'gwc_vt_letter',
			$result,
			gwc_vt_produce_letter_url(
				(int) $request['volunteer_id'],
				(string) $request['from'],
				(string) $request['to']
			)
		)
	);
	exit;
}

/**
 * Say what happened.
 */
function gwc_vt_letters_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which sentence to print after a redirect.
	$result = isset( $_GET['gwc_vt_letter'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_letter'] ) ) : '';

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
 * or a school phones the organization, reads out the code, and somebody on the
 * front desk can answer in ten seconds.
 *
 * Three answers, and the wording of each is deliberate — see the note on
 * gwc_vt_verify_reference(). In particular a mismatch is reported as "the
 * records have changed", never as "invalid" or "forged": hours get corrected,
 * an entry gets verified after the letter went out, a duplicate is removed. All
 * ordinary, none anybody's fault.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The reference checker box.
 *
 * Takes the page it should come back to, because it is no longer only on the
 * Letters screen: the dashboard's rail carries it too, on the grounds that
 * whoever picks up the phone is not necessarily whoever issues letters, and the
 * dashboard is where they already are. The form is a GET, so the answer is
 * rendered by whichever screen the query lands on — pointing it at the wrong
 * one would answer the question on a page the person was not looking at.
 *
 * @param string $page The admin page slug to submit to.
 */
function gwc_vt_render_reference_checker( string $page = GWC_VT_LETTERS_PAGE ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; nothing is written and no data is disclosed that the viewer cannot already see.
	$code = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
	?>
	<div class="gwcvt-box">
		<h2><?php esc_html_e( 'Check a reference', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'Somebody has phoned about a letter. Type the reference code printed on it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( GWC_VT_ENTRY_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />

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

		$result = gwc_vt_verify_reference( $code );

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
						gwc_vt_format_hours( $record['minutes'] ),
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
						gwc_vt_format_hours( $record['minutes'] ),
						$record['issued_at']
					)
				),
				esc_html( gwc_vt_reference_difference_note( $result, $record ) )
			);
		}

		gwc_vt_render_reference_comparison( $result );
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
 * @param array $result From gwc_vt_verify_reference().
 * @param array $record The stored log entry.
 * @return string
 */
function gwc_vt_reference_difference_note( array $result, array $record ): string {
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
		gwc_vt_format_hours( $now )
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
 * gwc_vt_letter_fingerprint), so the plugin can say THAT something differs. It
 * cannot say WHAT. Rendering the current letter in full is what lets a person
 * put the two side by side and see it.
 *
 * Deliberately not an iframe of the print view: that route logs an issuance
 * every time it is opened, and checking a reference is not issuing a letter.
 * The audit log would fill up with letters nobody sent.
 *
 * @param array $result From gwc_vt_verify_reference().
 */
function gwc_vt_render_reference_comparison( array $result ): void {
	$letter = $result['rebuilt'] ?? null;

	if ( ! $letter instanceof GWC_VT_Letter ) {
		return;
	}

	wp_enqueue_style( 'gwc-vt-letter' );
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
			echo gwc_vt_letter_body( $letter, 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in inc/render.php.
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
function gwc_vt_render_letter_log(): void {
	$records = gwc_vt_recent_letters( 15 );
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
							<td>
								<?php
								/* The reference is the link, because it is the
								 * thing somebody is holding when they ring up.
								 * A record whose volunteer has been purged is
								 * still worth opening — the banner there says
								 * what became of it, which is the answer to the
								 * question that brought them. */
								?>
								<a href="<?php echo esc_url( gwc_vt_letter_review_url( $record ) ); ?>">
									<code><?php echo esc_html( $record['reference'] ); ?></code>
								</a>
							</td>
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

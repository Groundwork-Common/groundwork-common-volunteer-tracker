<?php
/**
 * Letters, on the record they are about.
 *
 * ── Why this is a box and not a screen ───────────────────────────────────────
 * Producing a letter was its own page: you went to it, searched for the person
 * you had just been looking at, chose dates, and acted in one sitting. Three
 * things were wrong with that and only the third is obvious.
 *
 * You were already on the record. The usual route in was a button on the
 * volunteer that carried their ID in the URL, so the screen's own volunteer
 * picker existed to re-answer a question that had just been answered — and
 * answering it wrongly, by typing a name and not selecting it, produced a
 * screen that looked broken. A box on the record cannot ask the question at all.
 *
 * Nothing survived. A court asks in March, the hours are short until April, and
 * the person who sends it is not the person who was asked. Everything about the
 * intention lived in a URL, so the answer was "remember, and do it again".
 *
 * And the screen had no memory of what it had produced. The letters this
 * volunteer had already been sent were in a different box on a different screen.
 *
 * ── What is here ─────────────────────────────────────────────────────────────
 * One table. The drafts somebody has started, then the letters that have gone
 * out, with the acts that move a row from the first group to the second. The
 * figures are recomputed as the box draws, for the same reason the letter is:
 * a number stored yesterday is a number that disagrees with the record today.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_add_letter_draft', 'gwc_vt_handle_add_letter_draft' );
add_action( 'admin_post_gwc_vt_discard_letter_draft', 'gwc_vt_handle_discard_letter_draft' );
add_action( 'admin_notices', 'gwc_vt_volunteer_letters_notice' );
add_action( 'admin_footer', 'gwc_vt_letters_box_footer' );

/**
 * Refuse when this organization does not issue letters at all.
 *
 * The box is not registered then, so neither handler is reachable through the
 * interface — and unreachable through the interface is not the same as
 * unreachable. The same guard the letter screens open with, for the same reason.
 */
function gwc_vt_letters_box_require_letters(): void {
	if ( ! gwc_vt_letters_enabled() ) {
		wp_die(
			esc_html__( 'This organization does not issue verification letters.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not available', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}
}

/**
 * Back to the volunteer, with a word about what happened.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $result       What to say.
 */
function gwc_vt_letters_box_redirect( int $volunteer_id, string $result ): void {
	wp_safe_redirect(
		add_query_arg(
			array( 'gwc_vt_letter_did' => $result ),
			(string) get_edit_post_link( $volunteer_id, 'redirect' )
		) . '#gwc-vt-volunteer-letters'
	);
	exit;
}

/**
 * Start a letter for this volunteer.
 */
function gwc_vt_handle_add_letter_draft(): void {
	$volunteer_id = isset( $_POST['volunteer'] ) ? absint( wp_unslash( $_POST['volunteer'] ) ) : 0;

	gwc_vt_letters_box_require_letters();
	gwc_vt_require_cap( 'issue' );
	check_admin_referer( 'gwc_vt_add_letter_draft_' . $volunteer_id );

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
		exit;
	}

	$posted = wp_unslash( $_POST );

	/* The period is only read when somebody asked for one. Two date inputs left
	 * at their defaults must not become a period nobody chose. */
	$ranged = 'range' === sanitize_key( (string) ( $posted['gwc_vt_period'] ?? 'all' ) );

	$draft_id = gwc_vt_add_letter_draft(
		$volunteer_id,
		$ranged ? sanitize_text_field( (string) ( $posted['from'] ?? '' ) ) : '',
		$ranged ? sanitize_text_field( (string) ( $posted['to'] ?? '' ) ) : ''
	);

	gwc_vt_letters_box_redirect( $volunteer_id, $draft_id > 0 ? 'drafted' : 'failed' );
}

/**
 * Throw a draft away.
 *
 * Allowed precisely because a draft has not been anywhere. The issued-letter log
 * has no equivalent action and never will: a letter that went out is a fact
 * about this organization's conduct, and the record of it is not the
 * organization's to delete once somebody is holding the letter.
 */
function gwc_vt_handle_discard_letter_draft(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- named in the nonce action checked two lines below.
	$draft_id = isset( $_GET['draft'] ) ? absint( wp_unslash( $_GET['draft'] ) ) : 0;

	gwc_vt_letters_box_require_letters();
	gwc_vt_require_cap( 'issue' );
	check_admin_referer( 'gwc_vt_discard_letter_draft_' . $draft_id );

	$draft = gwc_vt_letter_draft( $draft_id );

	if ( ! $draft ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
		exit;
	}

	gwc_vt_discard_letter_draft( $draft_id );

	gwc_vt_letters_box_redirect( (int) $draft['volunteer'], 'discarded' );
}

/**
 * What the last act did, said on the screen it came back to.
 */
function gwc_vt_volunteer_letters_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a word about a completed redirect; nothing acts on it.
	$did = isset( $_GET['gwc_vt_letter_did'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_letter_did'] ) ) : '';

	/* The last four are set by the send handler in inc/admin-letters.php, which
	 * redirects here when the act started here. One table rather than two,
	 * because a reader who has just pressed a button on this box does not care
	 * which file answered it. */
	$said = array(
		'drafted'     => array( 'success', __( 'Draft started. It is on this record until somebody issues it, and nothing has been sent.', 'groundwork-common-volunteer-tracker' ) ),
		'discarded'   => array( 'success', __( 'Draft discarded. There was nothing in the issued-letter log to remove.', 'groundwork-common-volunteer-tracker' ) ),
		'failed'      => array( 'error', __( 'That draft could not be started. Nothing was saved.', 'groundwork-common-volunteer-tracker' ) ),
		'sent'        => array( 'success', __( 'Letter issued and emailed. It has a reference now, and it is in the issued-letter log.', 'groundwork-common-volunteer-tracker' ) ),
		'send-failed' => array( 'error', __( 'The letter could not be emailed, so nothing was sent. It is recorded as a failed send, and the draft is still here to try again.', 'groundwork-common-volunteer-tracker' ) ),
		'no-email'    => array( 'error', __( 'There is no email address on this record, so there was nowhere to send it. Add one above, or use “Issue it” and send it yourself.', 'groundwork-common-volunteer-tracker' ) ),
		'bad-email'   => array( 'error', __( 'That is not an email address, so nothing was sent. The draft is still here.', 'groundwork-common-volunteer-tracker' ) ),
	);

	if ( ! isset( $said[ $did ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $said[ $did ][0] ),
		esc_html( $said[ $did ][1] )
	);
}


/* ── Progressive enhancement, and which way round it runs ────────────────────
 * Three things on this box are nicer with JavaScript: the adder is folded
 * behind one button, the letter opens in a panel over the record instead of a
 * new tab, and emailing asks where to send it.
 *
 * All three are built so the screen works with the script absent, and the
 * script takes things AWAY rather than adding them:
 *
 *   The adder renders open, and "Add a letter" renders hidden. The script
 *   unhides the button and folds the panel. A reader without it gets the form.
 *
 *   "Open" is a plain link to the letter. The script intercepts it and shows the
 *   same document in an iframe instead. Without it, the link opens in a tab.
 *
 *   "Email it" is a plain link that sends to the address on the record. The
 *   script intercepts it and offers the dialog, where another address can be
 *   typed. Without it, the link still sends — to the address on file, which is
 *   what it says it does.
 *
 * Written the other way round, a script that failed to load would leave a box
 * of buttons that do nothing on the screen where letters to courts are made.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The box.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_letters_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save this volunteer, then start a letter for them.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	$drafts = gwc_vt_letter_drafts( $volunteer_id );
	$issued = gwc_vt_letters_for_volunteer( $volunteer_id );

	// Newest first in both groups, and the drafts above: those are the ones with something left to do.
	usort( $issued, static fn( array $a, array $b ): int => strcmp( (string) $b['issued_at'], (string) $a['issued_at'] ) );
	usort( $drafts, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );
	?>
	<div class="gwcvt-letters-box" data-gwcvt-letters>
		<p class="gwcvt-letters-box__count">
			<?php echo esc_html( gwc_vt_letters_box_count( count( $drafts ), count( $issued ) ) ); ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Period', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it states', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reference', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $drafts && ! $issued ) : ?>
					<tr>
						<td colspan="5" class="description">
							<?php esc_html_e( 'No letters yet. Add one when a court or a school asks for proof of somebody’s service.', 'groundwork-common-volunteer-tracker' ); ?>
						</td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $drafts as $draft ) : ?>
					<?php gwc_vt_render_letter_draft_row( $draft ); ?>
				<?php endforeach; ?>

				<?php foreach ( $issued as $record ) : ?>
					<?php gwc_vt_render_letter_issued_row( $record ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php gwc_vt_render_letter_draft_form( $volunteer_id ); ?>
	</div>
	<?php
}

/**
 * What is on this record, in the header line.
 *
 * The drafts are named separately because they are the half with something
 * outstanding — "3 on this record" alone does not say that one of them is
 * waiting for somebody.
 *
 * @param int $drafts How many drafts.
 * @param int $issued How many have gone out.
 * @return string
 */
function gwc_vt_letters_box_count( int $drafts, int $issued ): string {
	$total = $drafts + $issued;

	if ( 0 === $total ) {
		return __( 'Nothing on this record yet.', 'groundwork-common-volunteer-tracker' );
	}

	$words = sprintf(
		/* translators: %s: how many letters, already formatted for the locale. */
		_n( '%s on this record', '%s on this record', $total, 'groundwork-common-volunteer-tracker' ),
		number_format_i18n( $total )
	);

	if ( 0 === $drafts ) {
		return $words;
	}

	return $words . ' · ' . sprintf(
		/* translators: %s: how many drafts, already formatted for the locale. */
		_n( '%s draft', '%s drafts', $drafts, 'groundwork-common-volunteer-tracker' ),
		number_format_i18n( $drafts )
	);
}

/**
 * One draft, with the figures as they stand this second.
 *
 * @param array $draft From gwc_vt_letter_draft().
 */
function gwc_vt_render_letter_draft_row( array $draft ): void {
	$volunteer_id = (int) $draft['volunteer'];

	$letter = gwc_vt_build_letter(
		$volunteer_id,
		array(
			'from' => $draft['from'],
			'to'   => $draft['to'],
		)
	);

	$empty = ! $letter instanceof GWC_VT_Letter || $letter->is_empty();
	?>
	<tr class="gwcvt-letters-box__row gwcvt-letters-box__row--draft">
		<td><?php echo esc_html( gwc_vt_letter_period_words( $draft['from'], $draft['to'] ) ); ?></td>
		<td>
			<?php if ( $empty ) : ?>
				<span class="description"><?php esc_html_e( 'Nothing verified in that period yet', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php else : ?>
				<strong><?php echo esc_html( gwc_vt_format_hours( $letter->verified_minutes ) ); ?></strong>
				<div class="description">
					<?php
					printf(
						/* translators: %d: number of shifts. */
						esc_html( _n( '%d shift', '%d shifts', $letter->verified_count(), 'groundwork-common-volunteer-tracker' ) ),
						(int) $letter->verified_count()
					);
					?>
				</div>
			<?php endif; ?>
		</td>
		<td><span class="gwcvt-badge gwcvt-badge--draft"><?php esc_html_e( 'Draft', 'groundwork-common-volunteer-tracker' ); ?></span></td>
		<td class="description"><?php esc_html_e( '— none until issued', 'groundwork-common-volunteer-tracker' ); ?></td>
		<td class="gwcvt-letters-box__actions">
			<?php if ( $empty ) : ?>
				<?php /* No issue action at all, and a line saying what would bring one back — an empty actions cell reads as a row somebody has broken. */ ?>
				<div class="description"><?php esc_html_e( 'Verify some hours, and this can be issued.', 'groundwork-common-volunteer-tracker' ); ?></div>
			<?php else : ?>
				<a
					href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_preview', $volunteer_id, $draft['from'], $draft['to'] ) ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					data-gwcvt-letter-open
					data-gwcvt-letter-title="<?php echo esc_attr( sprintf( /* translators: %s: a volunteer's name. */ __( 'Draft for %s', 'groundwork-common-volunteer-tracker' ), get_the_title( $volunteer_id ) ) ); ?>"
				>
					<?php esc_html_e( 'Open', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span aria-hidden="true"> | </span>
				<a href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_print', $volunteer_id, $draft['from'], $draft['to'], (int) $draft['id'] ) ); ?>" data-gwcvt-letter-issue>
					<?php esc_html_e( 'Issue it', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span aria-hidden="true"> | </span>
				<a
					href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_send', $volunteer_id, $draft['from'], $draft['to'], (int) $draft['id'], array( 'back' => 1 ) ) ); ?>"
					data-gwcvt-letter-mail="draft"
					data-gwcvt-letter-from="<?php echo esc_attr( (string) $draft['from'] ); ?>"
					data-gwcvt-letter-to="<?php echo esc_attr( (string) $draft['to'] ); ?>"
					data-gwcvt-letter-draft="<?php echo esc_attr( (string) $draft['id'] ); ?>"
				>
					<?php esc_html_e( 'Email it', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span aria-hidden="true"> | </span>
			<?php endif; ?>

			<a class="gwcvt-letters-box__discard" href="<?php echo esc_url( gwc_vt_discard_draft_url( (int) $draft['id'] ) ); ?>">
				<?php esc_html_e( 'Discard', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</td>
	</tr>
	<?php
}

/**
 * One letter that has gone out.
 *
 * @param array $record From gwc_vt_letters_for_volunteer().
 */
function gwc_vt_render_letter_issued_row( array $record ): void {
	$volunteer_id = (int) $record['volunteer_id'];
	$from         = (string) $record['from'];
	$to           = (string) $record['to'];
	?>
	<tr class="gwcvt-letters-box__row gwcvt-letters-box__row--issued">
		<td><?php echo esc_html( gwc_vt_letter_period_words( $from, $to ) ); ?></td>
		<td>
			<strong><?php echo esc_html( gwc_vt_format_hours( (int) $record['minutes'] ) ); ?></strong>
			<div class="description">
				<?php
				echo esc_html(
					'email' === $record['medium']
						? sprintf(
							/* translators: %s: an email address. */
							__( 'Emailed to %s', 'groundwork-common-volunteer-tracker' ),
							(string) $record['recipient']
						)
						: __( 'Printed', 'groundwork-common-volunteer-tracker' )
				);
				?>
			</div>
		</td>
		<td>
			<span class="gwcvt-badge gwcvt-badge--issued"><?php esc_html_e( 'Issued', 'groundwork-common-volunteer-tracker' ); ?></span>
			<div class="description"><?php echo esc_html( gwc_vt_local_date( (string) $record['issued_at'] ) ); ?></div>
		</td>
		<td><code><?php echo esc_html( (string) $record['reference'] ); ?></code></td>
		<td class="gwcvt-letters-box__actions">
			<?php
			/* The reference travels with it, so the band across the top of the
			 * document can say "this is not the copy they are holding" instead of
			 * "this is a draft" — which is what it would otherwise say about a
			 * letter that demonstrably went out. */
			?>
			<a
				href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_preview', $volunteer_id, $from, $to, 0, array( 'reference' => (string) $record['reference'] ) ) ); ?>"
				target="_blank"
				rel="noopener noreferrer"
				data-gwcvt-letter-open
				data-gwcvt-letter-title="<?php echo esc_attr( sprintf( /* translators: %s: a volunteer's name. */ __( 'Letter for %s', 'groundwork-common-volunteer-tracker' ), get_the_title( $volunteer_id ) ) ); ?>"
			>
				<?php esc_html_e( 'Open', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<span aria-hidden="true"> | </span>
			<a
				href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_send', $volunteer_id, $from, $to, 0, array( 'back' => 1 ) ) ); ?>"
				data-gwcvt-letter-mail="issued"
				data-gwcvt-letter-from="<?php echo esc_attr( $from ); ?>"
				data-gwcvt-letter-to="<?php echo esc_attr( $to ); ?>"
				data-gwcvt-letter-draft="0"
			>
				<?php esc_html_e( 'Email it again', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</td>
	</tr>
	<?php
}

/**
 * Adding one.
 *
 * ── Why the fields are not in a form ─────────────────────────────────────────
 * A meta box is rendered inside wp-admin's own <form id="post">, and a form
 * inside a form is not something HTML has. The parser does not complain; it
 * drops the inner tags and leaves the fields behind, still on the page, now
 * belonging to the post form. So "Add a letter" looked right, rendered right,
 * and saved the volunteer.
 *
 * The fix is the HTML5 `form` attribute: the fields live here, the form element
 * they belong to is printed at the end of the document by
 * gwc_vt_letters_box_footer(), and a field naming another form is not submitted
 * with the one it sits inside.
 *
 * ── And why the period is two radios ─────────────────────────────────────────
 * Empty dates are the common case, and a pair of blank inputs does not say that
 * leaving them blank means anything.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_letter_draft_form( int $volunteer_id ): void {
	?>
	<div class="gwcvt-letters-box__adder">
		<?php /* Hidden until the script unhides it — see the note above the box. */ ?>
		<p class="gwcvt-letters-box__open" data-gwcvt-letters-open hidden>
			<button type="button" class="button button-primary" data-gwcvt-letters-add>
				<?php esc_html_e( 'Add a letter', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
			<span class="description"><?php esc_html_e( 'Saved as a draft. Nothing is sent and nothing is logged until you issue it.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</p>

		<div class="gwcvt-letters-box__panel" data-gwcvt-letters-panel>
			<input type="hidden" form="gwcvt-start-letter" name="action" value="gwc_vt_add_letter_draft" />
			<input type="hidden" form="gwcvt-start-letter" name="volunteer" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
			<input type="hidden" form="gwcvt-start-letter" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gwc_vt_add_letter_draft_' . $volunteer_id ) ); ?>" />

			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'What the letter covers', 'groundwork-common-volunteer-tracker' ); ?></legend>

				<div class="gwcvt-letters-box__choice">
					<label>
						<input type="radio" form="gwcvt-start-letter" name="gwc_vt_period" value="all" checked data-gwcvt-letters-period="all" />
						<?php esc_html_e( 'Everything on record', 'groundwork-common-volunteer-tracker' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'From their first shift to the day it is issued. The letter names both dates.', 'groundwork-common-volunteer-tracker' ); ?></span>
				</div>

				<div class="gwcvt-letters-box__choice">
					<label>
						<input type="radio" form="gwcvt-start-letter" name="gwc_vt_period" value="range" data-gwcvt-letters-period="range" />
						<?php esc_html_e( 'A period', 'groundwork-common-volunteer-tracker' ); ?>
					</label>
					<span class="description"><?php esc_html_e( 'For a court or a school that asked about particular months.', 'groundwork-common-volunteer-tracker' ); ?></span>

					<span class="gwcvt-letters-box__dates" data-gwcvt-letters-dates>
						<label for="gwcvt-draft-from-<?php echo esc_attr( (string) $volunteer_id ); ?>"><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="date" form="gwcvt-start-letter" id="gwcvt-draft-from-<?php echo esc_attr( (string) $volunteer_id ); ?>" name="from" />
						<label for="gwcvt-draft-to-<?php echo esc_attr( (string) $volunteer_id ); ?>"><?php esc_html_e( 'To', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="date" form="gwcvt-start-letter" id="gwcvt-draft-to-<?php echo esc_attr( (string) $volunteer_id ); ?>" name="to" />
					</span>
				</div>
			</fieldset>

			<p class="gwcvt-letters-box__foot">
				<button type="submit" form="gwcvt-start-letter" class="button button-primary"><?php esc_html_e( 'Save the draft', 'groundwork-common-volunteer-tracker' ); ?></button>
				<button type="button" class="button" data-gwcvt-letters-cancel hidden><?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?></button>
				<span class="description"><?php esc_html_e( 'The figures are worked out when you open it, and again when you issue it.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Everything that has to live outside wp-admin's post form.
 *
 * The two form elements the box's fields belong to, and the two panels. Printed
 * on the volunteer screen only, and only when the box that needs them was
 * registered — the same two conditions the box itself is registered under.
 */
function gwc_vt_letters_box_footer(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	if ( ! gwc_vt_letters_enabled() || ! current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		return;
	}

	global $post;

	$volunteer_id = $post instanceof WP_Post ? (int) $post->ID : 0;

	if ( $volunteer_id < 1 || 'auto-draft' === get_post_status( $volunteer_id ) ) {
		return;
	}

	$on_file = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

	/* Empty on purpose: every field it submits is up in the box, naming it by
	 * ID. The email form's three variable fields are filled by the script from
	 * whichever row was pressed — the nonce is not one of them, because it
	 * covers the action and the volunteer, and both are fixed on this screen. */
	?>
	<form id="gwcvt-start-letter" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"></form>

	<form id="gwcvt-email-letter" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwc_vt_letter_send" />
		<input type="hidden" name="volunteer" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
		<input type="hidden" name="back" value="1" />
		<input type="hidden" name="from" value="" data-gwcvt-mail-field="from" />
		<input type="hidden" name="to" value="" data-gwcvt-mail-field="to" />
		<input type="hidden" name="draft" value="0" data-gwcvt-mail-field="draft" />
		<?php wp_nonce_field( 'gwc_vt_letter_send_' . $volunteer_id ); ?>
	</form>

	<?php gwc_vt_render_letter_reader(); ?>
	<?php gwc_vt_render_letter_mailer( $on_file ); ?>
	<?php
}

/**
 * The panel a letter is read in.
 *
 * An iframe rather than markup fetched and injected, so gwc_vt_render_letter()
 * stays the only thing that has ever produced a letter — the rule that keeps a
 * theme, and now a script, from owning a document a court reads. It is empty
 * until something is opened, so nothing is fetched by arriving on the screen.
 *
 * The footer is filled from the row that was pressed: the script moves that
 * row's own action links into it, so the panel offers exactly what the row did
 * and there is no second copy of those URLs to keep in step.
 */
function gwc_vt_render_letter_reader(): void {
	?>
	<div class="gwcvt-sheet" data-gwcvt-letter-reader hidden>
		<div class="gwcvt-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="gwcvt-reader-title">
			<div class="gwcvt-sheet__head">
				<h2 id="gwcvt-reader-title" data-gwcvt-reader-title><?php esc_html_e( 'Letter', 'groundwork-common-volunteer-tracker' ); ?></h2>
				<button type="button" class="button-link gwcvt-sheet__close" data-gwcvt-sheet-close>
					<?php esc_html_e( 'Close', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</div>

			<iframe class="gwcvt-sheet__doc" title="<?php esc_attr_e( 'The letter', 'groundwork-common-volunteer-tracker' ); ?>" data-gwcvt-reader-frame src="about:blank"></iframe>

			<div class="gwcvt-sheet__foot">
				<span data-gwcvt-reader-actions></span>
				<span class="description" data-gwcvt-reader-note></span>
				<span class="description gwcvt-sheet__note--draft" hidden data-gwcvt-reader-note-draft><?php esc_html_e( 'Reading a draft records nothing.', 'groundwork-common-volunteer-tracker' ); ?></span>
				<span class="description gwcvt-sheet__note--issued" hidden data-gwcvt-reader-note-issued><?php esc_html_e( 'Every send is logged, and issues a new letter with its own reference.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}

/**
 * The panel that asks where a letter is going.
 *
 * The reason it exists: the address on the record is the usual answer and not
 * the only one. A probation officer or a school often asks to be sent it
 * directly, and the alternative to asking is a coordinator printing the letter,
 * saving a PDF and attaching it to their own mail — which sends the same
 * document with nothing in the log to say where it went.
 *
 * @param string $on_file The address on the volunteer's record, which may be ''.
 */
function gwc_vt_render_letter_mailer( string $on_file ): void {
	$has_address = '' !== $on_file && is_email( $on_file );
	?>
	<div class="gwcvt-sheet gwcvt-sheet--narrow" data-gwcvt-letter-mailer hidden>
		<div class="gwcvt-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="gwcvt-mailer-title">
			<div class="gwcvt-sheet__head">
				<h2 id="gwcvt-mailer-title"><?php esc_html_e( 'Email this letter', 'groundwork-common-volunteer-tracker' ); ?></h2>
				<button type="button" class="button-link gwcvt-sheet__close" data-gwcvt-sheet-close>
					<?php esc_html_e( 'Close', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</div>

			<div class="gwcvt-sheet__body">
				<p class="description" hidden data-gwcvt-mailer-what="draft">
					<?php esc_html_e( 'This is a draft. Sending it issues it, gives it a reference, and writes the log line.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
				<p class="description" hidden data-gwcvt-mailer-what="issued">
					<?php esc_html_e( 'This one has already gone out. Sending it again produces a new letter from today’s records, with a new reference — the one they are holding stays valid.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Where to send it', 'groundwork-common-volunteer-tracker' ); ?></legend>

					<?php if ( $has_address ) : ?>
						<div class="gwcvt-letters-box__choice">
							<label>
								<input type="radio" form="gwcvt-email-letter" name="gwc_vt_mailto" value="file" checked data-gwcvt-mailto="file" />
								<?php echo esc_html( $on_file ); ?>
							</label>
							<span class="description"><?php esc_html_e( 'The address on this record.', 'groundwork-common-volunteer-tracker' ); ?></span>
						</div>
					<?php endif; ?>

					<div class="gwcvt-letters-box__choice">
						<label>
							<input type="radio" form="gwcvt-email-letter" name="gwc_vt_mailto" value="other" <?php checked( ! $has_address ); ?> data-gwcvt-mailto="other" />
							<?php esc_html_e( 'Another address', 'groundwork-common-volunteer-tracker' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'A probation officer or a school who asked to receive it directly.', 'groundwork-common-volunteer-tracker' ); ?></span>

						<span class="gwcvt-letters-box__dates" data-gwcvt-mailer-other>
							<label class="screen-reader-text" for="gwcvt-mail-address"><?php esc_html_e( 'Email address', 'groundwork-common-volunteer-tracker' ); ?></label>
							<input type="email" form="gwcvt-email-letter" id="gwcvt-mail-address" name="recipient" class="regular-text" placeholder="name@example.org" />
						</span>
					</div>
				</fieldset>
			</div>

			<div class="gwcvt-sheet__foot">
				<button type="submit" form="gwcvt-email-letter" class="button button-primary"><?php esc_html_e( 'Send it', 'groundwork-common-volunteer-tracker' ); ?></button>
				<button type="button" class="button" data-gwcvt-sheet-close><?php esc_html_e( 'Cancel', 'groundwork-common-volunteer-tracker' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * A nonced URL for throwing a draft away.
 *
 * @param int $draft_id Draft post ID.
 * @return string
 */
function gwc_vt_discard_draft_url( int $draft_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'gwc_vt_discard_letter_draft',
				'draft'  => $draft_id,
			),
			admin_url( 'admin-post.php' )
		),
		'gwc_vt_discard_letter_draft_' . $draft_id
	);
}

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
add_action( 'admin_footer', 'gwc_vt_letter_draft_form_element' );

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

	$said = array(
		'drafted'   => array( 'success', __( 'Draft started. It is on this record until somebody issues it, and nothing has been sent.', 'groundwork-common-volunteer-tracker' ) ),
		'discarded' => array( 'success', __( 'Draft discarded. There was nothing in the issued-letter log to remove.', 'groundwork-common-volunteer-tracker' ) ),
		'failed'    => array( 'error', __( 'That draft could not be started. Nothing was saved.', 'groundwork-common-volunteer-tracker' ) ),
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

	// Newest first among the issued, oldest first among the drafts — see gwc_vt_letter_drafts().
	usort( $issued, static fn( array $a, array $b ): int => strcmp( $b['issued_at'], $a['issued_at'] ) );
	?>
	<table class="widefat striped gwcvt-letters-box">
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
						<?php esc_html_e( 'No letters yet. Start one when a court or a school asks for proof of somebody’s service.', 'groundwork-common-volunteer-tracker' ); ?>
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
	<?php
}

/**
 * One draft, with the figures as they stand this second.
 *
 * @param array $draft From gwc_vt_letter_draft().
 */
function gwc_vt_render_letter_draft_row( array $draft ): void {
	$letter = gwc_vt_build_letter(
		(int) $draft['volunteer'],
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
				<?php /* No issue action at all, and a line saying what would bring one back — an empty actions cell would read as a row somebody has broken. */ ?>
				<div class="description"><?php esc_html_e( 'Verify some hours, and this can be issued.', 'groundwork-common-volunteer-tracker' ); ?></div>
			<?php else : ?>
				<a href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_preview', (int) $draft['volunteer'], $draft['from'], $draft['to'] ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Read it', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span aria-hidden="true"> | </span>
				<a href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_print', (int) $draft['volunteer'], $draft['from'], $draft['to'], (int) $draft['id'] ) ); ?>">
					<?php esc_html_e( 'Issue and print', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<?php
				$email = (string) get_post_meta( (int) $draft['volunteer'], GWC_VT_VOLUNTEER_EMAIL, true );

				if ( '' !== $email && is_email( $email ) ) :
					?>
					<span aria-hidden="true"> | </span>
					<a
						href="<?php echo esc_url( gwc_vt_letter_action_url( 'gwc_vt_letter_send', (int) $draft['volunteer'], $draft['from'], $draft['to'], (int) $draft['id'] ) ); ?>"
						onclick="return confirm( '<?php echo esc_js( sprintf( /* translators: %s: an email address. */ __( 'Issue this letter and email it to %s?', 'groundwork-common-volunteer-tracker' ), $email ) ); ?>' );"
					>
						<?php esc_html_e( 'Issue and email', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! $empty ) : ?>
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
	?>
	<tr class="gwcvt-letters-box__row gwcvt-letters-box__row--issued">
		<td><?php echo esc_html( gwc_vt_letter_period_words( (string) $record['from'], (string) $record['to'] ) ); ?></td>
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
			<a href="<?php echo esc_url( gwc_vt_letter_review_url( $record ) ); ?>">
				<?php esc_html_e( 'Open it again', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<span aria-hidden="true"> | </span>
			<a href="<?php echo esc_url( gwc_vt_dashboard_reference_url( (string) $record['reference'] ) ); ?>">
				<?php esc_html_e( 'Check it', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</td>
	</tr>
	<?php
}

/**
 * Starting one.
 *
 * ── Why the fields are not in a form ─────────────────────────────────────────
 * A meta box is rendered inside wp-admin's own <form id="post">, and a form
 * inside a form is not something HTML has. The parser does not complain; it
 * drops the inner tags and leaves the fields behind, still on the page, now
 * belonging to the post form. So "Start a letter" looked right, rendered right,
 * and saved the volunteer.
 *
 * The fix is the HTML5 `form` attribute: the fields live here, the form element
 * they belong to is printed at the end of the document by
 * gwc_vt_letter_draft_form_element(), and a field naming another form is not
 * submitted with the one it sits inside. Discarding does not need any of this —
 * it carries nothing but an ID, so it is a nonced link, the same as every other
 * row action in this plugin.
 *
 * ── And why the period is two radios ─────────────────────────────────────────
 * Empty dates are the common case, and a pair of blank inputs does not say that
 * leaving them blank means anything.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_letter_draft_form( int $volunteer_id ): void {
	?>
	<div class="gwcvt-letters-box__add">
		<input type="hidden" form="gwcvt-start-letter" name="action" value="gwc_vt_add_letter_draft" />
		<input type="hidden" form="gwcvt-start-letter" name="volunteer" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
		<input type="hidden" form="gwcvt-start-letter" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gwc_vt_add_letter_draft_' . $volunteer_id ) ); ?>" />

		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'What the letter covers', 'groundwork-common-volunteer-tracker' ); ?></legend>

			<div class="gwcvt-letters-box__choice">
				<label>
					<input type="radio" form="gwcvt-start-letter" name="gwc_vt_period" value="all" checked />
					<?php esc_html_e( 'Everything on record', 'groundwork-common-volunteer-tracker' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'From their first shift to the day it is issued. The letter names both dates.', 'groundwork-common-volunteer-tracker' ); ?></span>
			</div>

			<div class="gwcvt-letters-box__choice">
				<label>
					<input type="radio" form="gwcvt-start-letter" name="gwc_vt_period" value="range" />
					<?php esc_html_e( 'A period', 'groundwork-common-volunteer-tracker' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'For a court or a school that asked about particular months.', 'groundwork-common-volunteer-tracker' ); ?></span>

				<span class="gwcvt-letters-box__dates">
					<label for="gwcvt-draft-from-<?php echo esc_attr( (string) $volunteer_id ); ?>"><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="date" form="gwcvt-start-letter" id="gwcvt-draft-from-<?php echo esc_attr( (string) $volunteer_id ); ?>" name="from" />
					<label for="gwcvt-draft-to-<?php echo esc_attr( (string) $volunteer_id ); ?>"><?php esc_html_e( 'To', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="date" form="gwcvt-start-letter" id="gwcvt-draft-to-<?php echo esc_attr( (string) $volunteer_id ); ?>" name="to" />
				</span>
			</div>
		</fieldset>

		<p>
			<button type="submit" form="gwcvt-start-letter" class="button button-primary"><?php esc_html_e( 'Start a letter', 'groundwork-common-volunteer-tracker' ); ?></button>
			<span class="description"><?php esc_html_e( 'Saved as a draft. Nothing is sent and nothing is logged until you issue it.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</p>
	</div>
	<?php
}

/**
 * The form element the box's fields belong to, printed outside the post form.
 *
 * Empty on purpose: every field it submits is up in the box, pointing at this
 * by ID. It is printed on the volunteer screen only, and only when the box that
 * needs it was registered.
 */
function gwc_vt_letter_draft_form_element(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	if ( ! gwc_vt_letters_enabled() || ! current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		return;
	}

	printf(
		'<form id="gwcvt-start-letter" method="post" action="%s"></form>',
		esc_url( admin_url( 'admin-post.php' ) )
	);
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

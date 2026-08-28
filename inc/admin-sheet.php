<?php
/**
 * One way to open a thing.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * A volunteer's record grew three secondary actions, and each arrived with its
 * own idea of what happens when you press it:
 *
 *   Log hours          navigated to another screen entirely
 *   Draft a letter     unfolded a panel in place
 *   Record a credential  showed fields that were saved by the volunteer's own
 *                        Update button, further down the page
 *
 * Three patterns on one screen, and the third is the worst of them: a form that
 * is not a form, whose own comment had to say "the box looks like a form and is
 * not one". Somebody who fills it in and does not scroll down has recorded
 * nothing, and nothing tells them.
 *
 * So all three open a sheet, and a sheet commits. What you pressed and what
 * happens are the same act, in one place, and the screen behind it does not
 * move while you decide.
 *
 * ── What a sheet is ──────────────────────────────────────────────────────────
 * A panel over the page. It is NOT a wp-admin "modal" in the media-library
 * sense — no backbone, no template, no state machine — and it deliberately does
 * not use core's .media-modal classes, whose CSS is loaded only where the media
 * library is.
 *
 * Rendered by PHP and shown by assets/js/admin-sheet.js. Every word in it is
 * markup that exists before any script runs, which is why none of this needs
 * translated strings in JavaScript.
 *
 * ── How it stays usable with no JavaScript, without rendering twice ──────────
 * A sheet is NOT rendered with the `hidden` attribute, and that is deliberate.
 * It is hidden by CSS under `body.js` — the class WordPress itself swaps in
 * from `no-js` on every admin page.
 *
 * So with scripting on it is hidden before it can flash, and with scripting off
 * it is simply a block of the page: a heading, its fields, its buttons, at the
 * foot of the record, working. The alternative was rendering the contents twice
 * — once inline as a fallback and once in the sheet — which duplicates every id
 * in it, and duplicate ids are a bug that shows up as a label that focuses the
 * wrong field.
 *
 * ── The rule the whole thing rests on ────────────────────────────────────────
 * A sheet lives OUTSIDE wp-admin's <form id="post">, because it is printed on
 * admin_footer, and a form inside a form is not something HTML has. Fields in a
 * sheet therefore name the form they belong to by ID. That is not a workaround
 * bolted on: it is the only reason any of this can be a real form at all, and
 * the reason the letters box's fields already work that way.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * The control that opens one.
 *
 * A <button> and never a link: it opens a panel on this page rather than going
 * anywhere, and something that navigates nowhere is not a link however it is
 * painted. .button-link is wp-admin's own way of drawing that.
 *
 * Rendered hidden, and unhidden by CSS under `body.js`. With no scripting there
 * is nothing for it to open — the sheet is already on the page — so a button
 * that would do nothing never appears. See gwc_vt_render_sheet().
 *
 * @param string $sheet The sheet's id.
 * @param string $label What the button says.
 * @param string $extra Optional. Extra classes.
 */
function gwc_vt_sheet_trigger( string $sheet, string $label, string $extra = '' ): void {
	printf(
		'<p class="gwcvt-sheet-trigger %1$s" data-gwcvt-sheet-trigger hidden><button type="button" class="button-link" data-gwcvt-sheet-open="%2$s">%3$s</button></p>',
		esc_attr( $extra ),
		esc_attr( $sheet ),
		esc_html( $label )
	);
}

/**
 * A sheet, with a heading, a body and a row of actions.
 *
 * ── Why the body is a callback ───────────────────────────────────────────────
 * So that the thing being opened is written where it belongs — the credential
 * fields in the credentials file, the letter fields in the letters file — and
 * this function never learns what any of them contain. It renders the frame and
 * the close control, which are the only parts that have to agree.
 *
 * @param string   $sheet  The sheet's id, matching a trigger.
 * @param string   $title  The heading.
 * @param callable $body   Prints the contents.
 * @param callable $foot   Optional. Prints the actions.
 * @param string   $extra  Optional. Extra classes, e.g. gwcvt-sheet--narrow.
 */
function gwc_vt_render_sheet( string $sheet, string $title, callable $body, $foot = null, string $extra = '' ): void {
	$heading = 'gwcvt-sheet-title-' . $sheet;
	?>
	<div class="gwcvt-sheet <?php echo esc_attr( $extra ); ?>" data-gwcvt-sheet="<?php echo esc_attr( $sheet ); ?>">
		<div class="gwcvt-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $heading ); ?>">
			<div class="gwcvt-sheet__head">
				<h2 id="<?php echo esc_attr( $heading ); ?>"><?php echo esc_html( $title ); ?></h2>

				<?php
				/* A dashicon cross at the top right, where wp-admin puts one and
				 * therefore where somebody looks. The word is still there for a
				 * screen reader. */
				?>
				<button type="button" class="gwcvt-sheet__close" data-gwcvt-sheet-close>
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Close', 'groundwork-common-volunteer-tracker' ); ?></span>
				</button>
			</div>

			<div class="gwcvt-sheet__body">
				<?php call_user_func( $body ); ?>
			</div>

			<?php if ( is_callable( $foot ) ) : ?>
				<div class="gwcvt-sheet__foot">
					<?php call_user_func( $foot ); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * The empty form a sheet's fields belong to.
 *
 * ── Why every sheet needs one, and why it is empty ───────────────────────────
 * A sheet is printed on admin_footer, outside wp-admin's own <form id="post">,
 * because a form inside a form is not something HTML has — the parser drops the
 * inner tags and leaves the fields belonging to the post form, silently. So the
 * fields stay in the sheet and name their form by ID, and the form itself is
 * this: an empty element, sitting where a form is allowed to sit.
 *
 * Five of these were being written out by hand in two files. The one that takes
 * no action attribute is not an oversight — the letter deliveries point their
 * form at whichever row was pressed, because each row's href is already a
 * complete nonced URL naming one letter.
 *
 * @param string $id     The form's id, which fields name.
 * @param string $action Optional. Where it posts, or '' for the script to set.
 * @param bool   $blank  Optional. Whether to open the response in a new tab.
 */
function gwc_vt_sheet_form( string $id, string $action = '', bool $blank = false ): void {
	printf(
		'<form id="%1$s" method="post" action="%2$s"%3$s></form>',
		esc_attr( $id ),
		esc_url( $action ),
		$blank ? ' target="_blank"' : ''
	);
}

/**
 * Whether this screen should be drawing sheets at all.
 *
 * One question, asked by everything that renders one, so that a sheet and the
 * trigger that opens it cannot disagree about whether they exist.
 *
 * @return bool
 */
function gwc_vt_on_volunteer_editor(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen instanceof WP_Screen || GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return false;
	}

	global $post;

	/* Not on a record that does not exist yet. Everything a sheet writes belongs
	 * to a volunteer, and an auto-draft has no identity to hang it on. */
	return $post instanceof WP_Post && 'auto-draft' !== get_post_status( $post );
}

/* ── Back to the record, and what to say when you get there ──────────────────
 * Three handlers redirect here and a fourth is one feature away. They all want
 * the same two things: the volunteer's editor, and one word about what just
 * happened — so they share one query argument, one redirect and one table of
 * sentences, rather than each growing its own.
 *
 * There were two of these for a while, gwc_vt_letter_did and gwc_vt_did, doing
 * the same job with different names because they were written a week apart.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Send somebody back to a volunteer's record with a word about what happened.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $result       A key in gwc_vt_sheet_messages().
 * @param string $anchor       Optional. Which panel to land on.
 */
function gwc_vt_sheet_redirect( int $volunteer_id, string $result, string $anchor = '' ): void {
	wp_safe_redirect(
		add_query_arg(
			array( 'gwc_vt_did' => $result ),
			(string) get_edit_post_link( $volunteer_id, 'redirect' )
		) . ( '' !== $anchor ? '#' . $anchor : '' )
	);
	exit;
}

/**
 * Everything a sheet can have to say afterwards.
 *
 * A function and not a const, because these are translated: a const is
 * evaluated at include time, which freezes the strings in English for the
 * request. See the note in inc/i18n.php.
 *
 * @return array<string, array{0:string, 1:string}> Keyed by result, each
 *                                                  a status and a sentence.
 */
function gwc_vt_sheet_messages(): array {
	return array(
		/* Logging hours. */
		'hours-logged'        => array( 'success', __( 'Hours logged. They are waiting to be verified.', 'groundwork-common-volunteer-tracker' ) ),
		'hours-unreadable'    => array( 'error', __( 'Those hours could not be read, so nothing was logged. Try 2, 2.5 or 2:30.', 'groundwork-common-volunteer-tracker' ) ),
		'hours-failed'        => array( 'error', __( 'Those hours could not be logged.', 'groundwork-common-volunteer-tracker' ) ),

		/* Credentials. */
		'credential-recorded' => array( 'success', __( 'Credential recorded.', 'groundwork-common-volunteer-tracker' ) ),
		'credential-failed'   => array( 'error', __( 'That credential could not be recorded.', 'groundwork-common-volunteer-tracker' ) ),

		/* Letters: drafting one. */
		'drafted'             => array( 'success', __( 'Draft started. Nothing has been sent.', 'groundwork-common-volunteer-tracker' ) ),
		'discarded'           => array( 'success', __( 'Draft discarded.', 'groundwork-common-volunteer-tracker' ) ),
		'failed'              => array( 'error', __( 'That draft could not be started.', 'groundwork-common-volunteer-tracker' ) ),

		/* And issuing and delivering it. */
		'issued'              => array( 'success', __( 'Letter issued. It has a reference now — send it whenever you are ready.', 'groundwork-common-volunteer-tracker' ) ),
		'issue-failed'        => array( 'error', __( 'That letter could not be issued.', 'groundwork-common-volunteer-tracker' ) ),
		'delivered-email'     => array( 'success', __( 'Emailed, and recorded against the letter.', 'groundwork-common-volunteer-tracker' ) ),
		'send-failed'         => array( 'error', __( 'The letter could not be emailed, so nothing was sent. The attempt is recorded against it.', 'groundwork-common-volunteer-tracker' ) ),
		'no-email'            => array( 'error', __( 'There is no email address on this record. Add one above, or print it and send it yourself.', 'groundwork-common-volunteer-tracker' ) ),
		'bad-email'           => array( 'error', __( 'That is not an email address, so nothing was sent.', 'groundwork-common-volunteer-tracker' ) ),
		'no-addressee'        => array( 'error', __( 'Nothing was recorded, because a posted letter needs somebody to have been posted to.', 'groundwork-common-volunteer-tracker' ) ),

		/* The one refusal that needs explaining. See inc/letter-deliver.php. */
		'stale'               => array( 'error', __( 'This letter can no longer be produced: one of the shifts it lists has changed since it was issued, so the document would state something its own reference contradicts. Issue a new one — the old letter stays in the log, and stays valid as what you sent that day.', 'groundwork-common-volunteer-tracker' ) ),
		'gone'                => array( 'error', __( 'There is nothing left to build this letter from. The record it was about has been erased.', 'groundwork-common-volunteer-tracker' ) ),
	);
}

/**
 * Say it, on the screen it came back to.
 */
function gwc_vt_sheet_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a word about a completed redirect; nothing acts on it.
	$did = isset( $_GET['gwc_vt_did'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_did'] ) ) : '';

	$said = gwc_vt_sheet_messages();

	if ( ! isset( $said[ $did ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $said[ $did ][0] ),
		esc_html( $said[ $did ][1] )
	);
}

add_action( 'admin_notices', 'gwc_vt_sheet_notice' );

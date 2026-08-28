<?php
/**
 * The Letters screen: produce one, send one, check one.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_letters_menu', 11 );
add_action( 'admin_post_gwc_vt_letter_preview', 'gwc_vt_handle_letter_preview' );

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

	/* There is no screen for PRODUCING one. There was, and it was registered
	 * here and then hidden from the menu, because a menu entry would have been
	 * "an invitation to start from a blank form and go looking for somebody".
	 * The box on the volunteer's own record replaced it outright: you are
	 * already on the person, so the screen's first question had no reason to be
	 * asked and its answer was the thing that went wrong. */
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
 * there is no longer a screen to send anybody to. Letters are made in a box on
 * the volunteer's own record, so the link goes to the volunteer LIST — you pick
 * the person, then the letter, which is the order the whole feature is built
 * around, and was the order even when a screen existed to do it the other way.
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
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_letters_page_title() ); ?></h1>
		<hr class="wp-header-end" />


		<?php gwc_vt_letters_next_steps(); ?>

		<div class="gwcvt-letters__log">
			<?php gwc_vt_render_letter_log(); ?>
		</div>
	</div>
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
 * Where to go to look at an issued letter again.
 *
 * The volunteer's own record, anchored to the letters box, because that is
 * where every letter now lives. It used to be the produce screen with the
 * reference in the query string, which reopened it by rebuilding from the
 * period — a screen that no longer exists and an answer that was only ever
 * approximately the letter.
 *
 * Empty when the volunteer has been erased. The log record outlives them on
 * purpose and stays perfectly readable; there is simply no record left to open.
 *
 * @param array $record From gwc_vt_letter_record().
 * @return string A URL, or '' when there is nowhere to go.
 */
function gwc_vt_letter_review_url( array $record ): string {
	$volunteer_id = (int) ( $record['volunteer_id'] ?? 0 );

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return '';
	}

	return (string) get_edit_post_link( $volunteer_id, 'url' ) . '#gwc-vt-volunteer-letters';
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
 * @param int    $draft_id     The draft this is being issued from, or 0.
 * @param array  $extra        Anything else the handler reads — 'back' to end up
 *                             on the volunteer's record, 'reference' to open a
 *                             letter that already went out.
 * @return string
 */
function gwc_vt_letter_action_url( string $action, int $volunteer_id, string $from, string $to, int $draft_id = 0, array $extra = array() ): string {
	$args = array(
		'action'    => $action,
		'volunteer' => $volunteer_id,
		'from'      => $from,
		'to'        => $to,
	);

	/* Which draft this is being issued from, when it is being issued from one.
	 * The handler removes it after logging: the log record holds the volunteer,
	 * the period, the minutes and the reference, so keeping the draft as well
	 * would be two rows describing one letter and a screen having to decide
	 * which of them is true the day they disagree. */
	if ( $draft_id > 0 ) {
		$args['draft'] = $draft_id;
	}

	return wp_nonce_url(
		add_query_arg( array_merge( $args, $extra ), admin_url( 'admin-post.php' ) ),
		$action . '_' . $volunteer_id
	);
}

/**
 * The shared front half of every issue handler.
 *
 * $_REQUEST rather than $_GET because the letters box on a volunteer's own
 * record sends by POST: the address to send to can be typed into a dialog, and a
 * typed value does not belong in a URL that ends up in a browser history and a
 * server log. Everything here is covered by the nonce either way —
 * check_admin_referer() has always read $_REQUEST.
 *
 * @return array{letter:GWC_VT_Letter, volunteer_id:int, from:string, to:string, draft:int, recipient:string, typed:bool, back:bool}
 */
function gwc_vt_letter_request(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- named in the nonce action checked below, which is the check.
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$volunteer_id = isset( $_REQUEST['volunteer'] ) ? absint( wp_unslash( $_REQUEST['volunteer'] ) ) : 0;

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

	$from = isset( $_REQUEST['from'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) ) : '';
	$to   = isset( $_REQUEST['to'] ) ? gwc_vt_sanitize_date( sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) ) : '';

	/* Covered by the nonce above with everything else in this request: which
	 * draft, if any, this letter is being issued from. */
	$draft_id = isset( $_REQUEST['draft'] ) ? absint( wp_unslash( $_REQUEST['draft'] ) ) : 0;

	/* An address somebody typed into the email dialog, for the probation officer
	 * or the school who asked to receive it directly. Empty means "the address on
	 * this record", which is what the handler falls back to — but a typed address
	 * that does not parse is refused rather than quietly replaced, because
	 * silently sending a court letter somewhere other than where it was addressed
	 * is the worst kind of helpful. */
	$typed     = isset( $_REQUEST['recipient'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['recipient'] ) ) ) : '';
	$recipient = '' !== $typed ? sanitize_email( $typed ) : '';

	/* Whether this act started on the volunteer's own record, and should end
	 * there. Without it, issuing from the box lands on a screen the reader was
	 * not on, having lost the record they were working with. */
	$back = ! empty( $_REQUEST['back'] );

	/* ── Built from the draft when there is one ──────────────────────────────
	 * A draft fixes the end of its period and the moment its attestations are
	 * counted as of, and carries who the letter is for. Building from the URL's
	 * period alone ignores all three — so previewing a draft showed different
	 * figures from the row above it and from the letter that issuing would
	 * produce, which is the whole thing the lock exists to prevent.
	 *
	 * Here rather than in each handler, because every caller that is given a
	 * draft wants the same answer, and the two that were doing it separately had
	 * already drifted apart once. */
	$draft = $draft_id > 0 ? gwc_vt_letter_draft( $draft_id ) : array();

	$letter = $draft
		? gwc_vt_build_letter( (int) $draft['volunteer'], gwc_vt_letter_args_for_draft( $draft ) )
		: gwc_vt_build_letter(
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
		'draft'        => $draft_id,
		'recipient'    => $recipient,
		/* Whether somebody typed anything at all, which sanitize_email() cannot
		 * be asked afterwards: it strips "dana@" down to nothing, and an empty
		 * $recipient is otherwise indistinguishable from "they did not type one",
		 * which would send a court letter to the address on file instead. A
		 * silent correction on the way to a mailbox is the same bug as a silent
		 * correction on the way to a database. */
		'typed'        => '' !== $typed,
		'back'         => $back,
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
 * Render the letter as a draft, and record nothing.
 *
 * The whole of the difference from printing is the line that is not here:
 * gwc_vt_log_letter(). Everything else — the capability, the per-volunteer
 * nonce, the private-document headers, the rebuild from entries rather than
 * from the rollup — is identical, because this is the same letter and the same
 * reasons apply to showing it.
 *
 * Not logging is the point and is also the risk, and the document says so about
 * itself: a band across the top that survives printing, and no reference. What
 * this buys is that somebody can read what a court will read — the letterhead,
 * the shift table, the disclaimer, the signature block — before the act of
 * looking becomes an issued letter.
 */
function gwc_vt_handle_letter_preview(): void {
	$request = gwc_vt_letter_request();

	$letter = $request['letter'];

	/* ── Opening a letter that already went out ──────────────────────────────
	 * Covered by the same nonce as the rest of the request. It is rebuilt from
	 * the entries the letter listed, exactly as a delivery is, so that reading
	 * one and printing one show the same document — they used to differ, which
	 * is an unhelpful surprise on the two actions that sit next to each other.
	 *
	 * Only when that rebuild no longer matches does this fall back to today's
	 * records and band the page. A reference naming nothing falls through to
	 * the draft band, which is the safe direction: it claims less, not more. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- gwc_vt_letter_request() checked the nonce over this whole request.
	$record_id = isset( $_REQUEST['record'] ) ? absint( wp_unslash( $_REQUEST['record'] ) ) : 0;

	/* The record, not the reference: two letters issued the same day over the
	 * same shifts share a code, so a reference cannot say which row of the log
	 * is being opened. See gwc_vt_delivery_request(). */
	$issued = GWC_VT_LETTER_TYPE === get_post_type( $record_id )
		? gwc_vt_letter_record( $record_id )
		: array();

	if ( $issued ) {
		$rebuild = gwc_vt_rebuild_issued_letter( $issued );

		$issued['faithful'] = $rebuild instanceof GWC_VT_Letter
			&& gwc_vt_rebuild_is_faithful( $issued, $rebuild );

		if ( $issued['faithful'] ) {
			/* It IS the letter — reference, date and all — so it is rendered as
			 * the document it is, and there is nothing for a band to warn
			 * about. Only the stamp puts the log's own identity back on; see
			 * the note on gwc_vt_stamp_issued_letter() for why that happens
			 * after the check and never before it. */
			gwc_vt_stamp_issued_letter( $issued, $rebuild );

			$letter = $rebuild;
		}
	}

	gwc_vt_private_document_headers();

	echo gwc_vt_render_letter( $letter, 'draft', $issued ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete document, escaped as it was assembled in inc/render.php.
	exit;
}

/**
 * Back to the record with a result.
 *
 * A thin wrapper over gwc_vt_sheet_redirect(), kept because the delivery
 * handlers pass the request array they already have rather than picking it
 * apart at four call sites.
 *
 * @param string $result  A key in gwc_vt_sheet_messages().
 * @param array  $request From gwc_vt_letter_request().
 */
function gwc_vt_letters_redirect( string $result, array $request ): void {
	gwc_vt_sheet_redirect( (int) $request['volunteer_id'], $result, 'gwc-vt-volunteer-letters' );
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
			<?php gwc_vt_render_list_tablenav( count( $records ) ); ?>

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
								<?php
								/* Linked to the record it is about, when there
								 * still is one. A letter whose volunteer has
								 * been erased stays in the log — that is what
								 * the log is for — and simply has nowhere to
								 * go, so the reference prints without a link
								 * rather than as one that dies. */
								$gwc_vt_review = gwc_vt_letter_review_url( $record );
								?>
								<?php if ( '' !== $gwc_vt_review ) : ?>
									<a href="<?php echo esc_url( $gwc_vt_review ); ?>">
										<code><?php echo esc_html( $record['reference'] ); ?></code>
									</a>
								<?php else : ?>
									<code><?php echo esc_html( $record['reference'] ); ?></code>
								<?php endif; ?>
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

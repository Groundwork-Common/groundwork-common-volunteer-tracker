<?php
/**
 * The letter, as a document.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── One template, two media ─────────────────────────────────────────────────
 * The printed letter and the emailed letter are the same document. That is not
 * a tidiness preference — the whole credibility argument rests on it. Two
 * templates drift, and the day they have drifted is the day a court receives a
 * letter that differs from the copy the organization has on file.
 *
 * So there is one function that produces the markup, and the only difference
 * between the media is how the styling gets attached:
 *
 *   print  links assets/css/letter.css
 *   email  runs the same markup through gwc_vt_inline_letter_styles()
 *
 * That inliner is about a dozen selector-to-declaration rules applied as style
 * attributes. Writing one by hand is only possible because this plugin controls
 * the letter markup completely — which is in turn why the intro and disclaimer
 * settings are sanitized with sanitize_textarea_field() and paragraphed by us,
 * never wp_kses_post(). If an administrator could put arbitrary HTML into the
 * intro, the inliner would have to become a real CSS engine, and a real CSS
 * engine is a Composer dependency.
 *
 * The result is typographic rather than designed. For a document a court reads,
 * that is the right aesthetic anyway.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Whether this rendering carries a real reference code.
 *
 * Three renderings, two answers, and the distinction is not the medium alone —
 * which is why it is a function rather than a condition spelled out wherever it
 * is needed. A draft has no reference, because it is in no log and a code that
 * fails the only test it exists for is worse than no code. A print or an email
 * has one. And an issued letter REOPENED and rebuilt exactly goes through the
 * draft medium while having one, because it is the letter.
 *
 * That third case was missed twice — once in the token table, once in the note
 * under the disclaimer, where a letter printed its own code and then said "this
 * draft has none" two lines below it. Both were the same fact stated in two
 * places and only corrected in one, so now it is stated here.
 *
 * @param string $medium     'print', 'email' or 'draft'.
 * @param bool   $reproduced Whether this is an issued letter, rebuilt exactly.
 * @return bool
 */
function gwc_vt_letter_shows_reference( string $medium, bool $reproduced ): bool {
	return 'draft' !== $medium || $reproduced;
}

/**
 * Render a letter.
 *
 * ── The third medium, and why it is not a fourth document ───────────────────
 * 'draft' is 'print' with two differences, and it exists because the screen
 * that says "Preview the letter" was showing four figures in a table: verified
 * hours, shifts, the period and the reference. Useful, and not a preview — the
 * letterhead, the shift table, the wording and the disclaimer could not be read
 * by anybody before the letter had been issued, because opening one to print
 * IS issuing it.
 *
 * So a draft is the same document, rendered by the same function, from the same
 * letter object. Two things are taken off it:
 *
 *   The reference. It is the digest a court checks by telephone, and the log is
 *   what it is checked against. A draft is in no log, so a reference on it would
 *   be a code that fails the only test it exists for. The space says so instead.
 *
 *   The claim to be issued. A band across the top says this is a draft and that
 *   nothing has gone out — visible on paper as well as on screen, because the
 *   one thing a draft must not become is a letter somebody printed and handed
 *   over while the organization's own log knows nothing about it.
 *
 * ── And the same document again, for one already issued ─────────────────────
 * No copy of an issued letter is kept, deliberately. So "open it again" can only
 * ever mean "render from the records as they stand now", which may not be what
 * went out. That is a different sentence from "this is a draft", and the band
 * says so when $issued names the log record it is being opened from: the
 * reference on the copy somebody is holding stays theirs, and this rendering is
 * not it.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @param string        $medium 'print', 'email' or 'draft'.
 * @param array         $issued Optional. The log record this is being reopened
 *                              from, which changes the band a draft carries.
 * @return string A complete HTML document.
 */
function gwc_vt_render_letter( GWC_VT_Letter $letter, string $medium = 'print', array $issued = array() ): string {
	$body = gwc_vt_letter_body( $letter, $medium, ! empty( $issued['faithful'] ) );

	/* Everything a draft does, the print document does — it is the same paper. */
	$on_paper = 'print' === $medium || 'draft' === $medium;

	if ( 'email' === $medium ) {
		$body = gwc_vt_inline_letter_styles( $body );
	}

	/* The letter reopened from its own log record, rebuilt faithfully — not a
	 * draft and not a rendering that has drifted, but the document itself. It
	 * carries no band, because there is nothing to warn about. */
	$reproduced = ! empty( $issued['faithful'] );

	/* And no print button, either. Putting an issued letter on paper is a
	 * delivery and has to be recorded, which is what the Print action on the
	 * record does; a button here would put the same page through the printer
	 * with nothing in the log to say it went anywhere. A browser can of course
	 * print any page it is shown — what this avoids is OFFERING the unlogged
	 * route beside the logged one. */
	$show_toolbar = $on_paper && ! $issued;

	ob_start();
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( gwc_vt_letter_title( $letter, $medium, $issued ) ); ?></title>
	<?php if ( $on_paper ) : ?>
		<?php gwc_vt_print_document_styles(); ?>
	<?php endif; ?>
</head>
<body class="gwcvt-letter-page">
	<?php if ( ! gwc_vt_letter_shows_reference( $medium, $reproduced ) ) : ?>
		<?php
		/* NOT hidden under @media print, unlike the toolbar below it. A draft
		 * that loses its banner on paper is an issued letter that nothing
		 * recorded. */
		?>
		<div class="gwcvt-letter-draft" role="note">
			<?php if ( isset( $issued['reference'] ) ) : ?>
				<strong><?php esc_html_e( 'Not the copy that went out', 'groundwork-common-volunteer-tracker' ); ?></strong>
				<span>
					<?php
					printf(
						/* translators: %s: a reference code. */
						esc_html__( 'No copy of a letter is kept. This is what your records would produce today, and they may have changed since. %s stays the reference on the letter they are holding, and still checks out as what you issued that day.', 'groundwork-common-volunteer-tracker' ),
						esc_html( (string) $issued['reference'] )
					);
					?>
				</span>
			<?php else : ?>
				<strong><?php esc_html_e( 'Draft — not issued', 'groundwork-common-volunteer-tracker' ); ?></strong>
				<span><?php esc_html_e( 'Nobody has been sent this and nothing has been recorded. Use “Issue it” or “Email it” on the volunteer’s record — issuing is what gives a letter its reference.', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $show_toolbar ) : ?>
		<?php /* Hidden by the stylesheet under @media print, so it never appears on paper. */ ?>
		<div class="gwcvt-letter-toolbar">
			<button type="button" class="gwcvt-print-button" onclick="window.print()">
				<?php
				if ( isset( $issued['reference'] ) ) {
					/* Printing this does not issue anything and does not log
					 * anything — it is a rendering of a letter that already
					 * went out, and the band above says so. */
					esc_html_e( 'Print this copy', 'groundwork-common-volunteer-tracker' );
				} elseif ( 'draft' === $medium ) {
					esc_html_e( 'Print this draft', 'groundwork-common-volunteer-tracker' );
				} else {
					esc_html_e( 'Print this letter', 'groundwork-common-volunteer-tracker' );
				}
				?>
			</button>
			<span class="gwcvt-letter-toolbar__hint">
				<?php esc_html_e( 'Choose “Save as PDF” in the print dialog to email it yourself.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<?php
	/* The covering note sits OUTSIDE the letter, deliberately. A short "here is
	 * your letter" message is a reasonable thing to want on an email, but
	 * putting it inside the document would mean the emailed letter and the
	 * printed one were no longer the same document — which is the one property
	 * the whole credibility argument rests on. So it goes above, as a note
	 * accompanying the letter rather than part of it. */
	if ( 'email' === $medium ) {
		$note = trim( (string) gwc_vt_setting( 'email_intro' ) );

		if ( '' !== $note ) {
			printf(
				'<div class="gwcvt-covering-note" style="font-family:Georgia,\'Times New Roman\',serif;font-size:12pt;line-height:1.55;color:#1a1a1a;max-width:44em;margin:0 auto 8px;padding:16px 16px 0;">%s</div>',
				wp_kses(
					nl2br( gwc_vt_replace_tokens( $note, gwc_vt_letter_tokens( $letter ) ) ),
					gwc_vt_letter_allowed_html()
				)
			);
		}
	}
	?>

	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwc_vt_letter_body(). ?>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/* ── The structural wording ──────────────────────────────────────────────────
 * The prose an organization is most likely to want to change — the opening
 * paragraph, the disclaimer, the reference note — is a setting, editable on the
 * Letter tab. The strings below are the document's furniture: headings, column
 * headers, row labels. They are translatable, and they are filterable here for
 * a site that needs different vocabulary ("Community Service Record" rather
 * than "Volunteer Service Verification", say) without wanting to reimplement
 * the template.
 *
 * There is deliberately NO theme-overridable template file. A theme that owns
 * this markup is a theme that can delete the disclaimer, and the disclaimer not
 * being deletable is the one structural promise this plugin makes about its own
 * output. Filters can change what the furniture SAYS; nothing can remove the
 * paragraph that says who is answerable for the hours.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The letter's fixed wording, all of it, in one filterable table.
 *
 * @param GWC_VT_Letter $letter The letter being rendered.
 * @return array<string, string>
 */
function gwc_vt_letter_strings( GWC_VT_Letter $letter ): array {
	$strings = array(
		'heading'          => __( 'Volunteer Service Verification', 'groundwork-common-volunteer-tracker' ),
		'shifts_heading'   => __( 'Shifts recorded', 'groundwork-common-volunteer-tracker' ),
		'label_volunteer'  => __( 'Volunteer', 'groundwork-common-volunteer-tracker' ),
		'label_period'     => __( 'Period covered', 'groundwork-common-volunteer-tracker' ),
		'label_hours'      => __( 'Verified hours', 'groundwork-common-volunteer-tracker' ),
		'label_shifts'     => __( 'Shifts', 'groundwork-common-volunteer-tracker' ),
		'col_date'         => __( 'Date', 'groundwork-common-volunteer-tracker' ),
		'col_hours'        => __( 'Hours', 'groundwork-common-volunteer-tracker' ),
		'col_activity'     => __( 'Activity', 'groundwork-common-volunteer-tracker' ),
		'col_supervisor'   => __( 'Supervised by', 'groundwork-common-volunteer-tracker' ),
		'col_verification' => __( 'Verification', 'groundwork-common-volunteer-tracker' ),
		'unverified_cell'  => __( 'Not verified — not included in the total above', 'groundwork-common-volunteer-tracker' ),
		'signature_blank'  => __( 'Signature', 'groundwork-common-volunteer-tracker' ),
	);

	/**
	 * The letter's fixed wording.
	 *
	 * @param array<string, string> $strings Keyed by role in the document.
	 * @param GWC_VT_Letter          $letter  The letter being rendered.
	 */
	return (array) apply_filters( 'gwc_vt_letter_strings', $strings, $letter );
}

/**
 * The letter itself, without the document wrapper.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @param string        $medium 'print' or 'email'.
 * @param bool          $reproduced Whether this is an issued letter reopened
 *                                  and rebuilt exactly — which keeps its own
 *                                  reference rather than printing "none".
 * @return string
 */
function gwc_vt_letter_body( GWC_VT_Letter $letter, string $medium, bool $reproduced = false ): string {
	$org       = gwc_vt_org_name();
	$tokens    = gwc_vt_letter_tokens( $letter, $medium, $reproduced );
	$itemize   = (bool) gwc_vt_setting( 'letter_itemize' );
	$signatory = trim( (string) gwc_vt_setting( 'signatory_name' ) );
	$title     = trim( (string) gwc_vt_setting( 'signatory_title' ) );
	$address   = trim( (string) gwc_vt_setting( 'org_address' ) );
	$contact   = gwc_vt_org_contact();
	$strings   = gwc_vt_letter_strings( $letter );
	$logo      = gwc_vt_letter_logo_url();

	ob_start();
	?>
	<div class="gwcvt-letter">

		<header class="gwcvt-letterhead">
			<?php if ( '' !== $logo ) : ?>
				<?php
				/* alt="" on purpose. The organization's name is printed as text
				 * directly beneath, so alt text here would have a screen reader
				 * announce it twice. The image is decoration; the name is the
				 * information.
				 *
				 * And it stays decoration in the other direction too: email
				 * clients block remote images by default, so a letter whose
				 * letterhead is only a logo would arrive anonymous. The name is
				 * always printed. */
				?>
				<img class="gwcvt-org-logo" src="<?php echo esc_url( $logo ); ?>" alt="" />
			<?php endif; ?>
			<p class="gwcvt-org"><?php echo esc_html( $org ); ?></p>
			<?php if ( '' !== $address ) : ?>
				<p class="gwcvt-org-address"><?php echo nl2br( esc_html( $address ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $contact ) : ?>
				<p class="gwcvt-org-contact"><?php echo esc_html( $contact ); ?></p>
			<?php endif; ?>
		</header>

		<p class="gwcvt-date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), $letter->issued_at ) ); ?></p>

		<h1 class="gwcvt-heading"><?php echo esc_html( $strings['heading'] ); ?></h1>

		<p class="gwcvt-intro"><?php echo wp_kses( gwc_vt_letter_intro( $tokens ), gwc_vt_letter_allowed_html() ); ?></p>

		<table class="gwcvt-summary-table">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html( $strings['label_volunteer'] ); ?></th>
					<td><?php echo esc_html( $letter->volunteer_name ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( $strings['label_period'] ); ?></th>
					<td><?php echo esc_html( gwc_vt_letter_period( $letter ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( $strings['label_hours'] ); ?></th>
					<td class="gwcvt-total"><strong><?php echo esc_html( gwc_vt_format_hours( $letter->verified_minutes ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( $strings['label_shifts'] ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $letter->verified_count() ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $itemize && ! $letter->is_empty() ) : ?>
			<h2 class="gwcvt-subheading"><?php echo esc_html( $strings['shifts_heading'] ); ?></h2>

			<?php
			/* Itemised by default. A total with nothing behind it is a number the
			 * reader has to take on faith, and taking it on faith is exactly what
			 * this document is trying not to require. */
			?>
			<table class="gwcvt-entries">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( $strings['col_date'] ); ?></th>
						<th scope="col"><?php echo esc_html( $strings['col_hours'] ); ?></th>
						<th scope="col"><?php echo esc_html( $strings['col_activity'] ); ?></th>
						<th scope="col"><?php echo esc_html( $strings['col_supervisor'] ); ?></th>
						<th scope="col"><?php echo esc_html( $strings['col_verification'] ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $letter->entries as $entry ) : ?>
						<tr class="<?php echo $entry->verified ? 'gwcvt-row' : 'gwcvt-row gwcvt-row--unverified'; ?>">
							<td><?php echo esc_html( gwc_vt_display_date( $entry->date ) ); ?></td>
							<td><?php echo esc_html( gwc_vt_format_hours( $entry->minutes ) ); ?></td>
							<td><?php echo esc_html( $entry->activity ); ?></td>
							<td><?php echo esc_html( $entry->supervisor ); ?></td>
							<td>
								<?php
								echo esc_html( $entry->verified ? $entry->attestation : $strings['unverified_cell'] );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $letter->has_unverified() ) : ?>
				<p class="gwcvt-unverified-note">
					<?php
					printf(
						/* translators: %s: a duration, e.g. "1.5". */
						esc_html__( 'This letter also lists %s of recorded but unverified time. No member of staff has attested to those shifts, and they are excluded from the verified total above.', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwc_vt_format_hours( $letter->unverified_minutes ) )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		/* ── The signature block ─────────────────────────────────────────────
		 * A typed name and title above a ruled line for a human to sign. Never
		 * an image of a signature, and never a rendered script font pretending
		 * to be one: a human signing this IS the attestation, and the plugin's
		 * job is to leave room for one rather than simulate it.
		 *
		 * If no signatory is configured the line still prints, unlabeled. That
		 * looks visibly unfinished, which is the correct prompt to go and fill
		 * it in before sending one to a court.
		 * ─────────────────────────────────────────────────────────────────── */
		?>
		<div class="gwcvt-signature">
			<p class="gwcvt-signature__rule">&nbsp;</p>
			<p class="gwcvt-signature__name"><?php echo esc_html( '' !== $signatory ? $signatory : $strings['signature_blank'] ); ?></p>
			<?php if ( '' !== $title ) : ?>
				<p class="gwcvt-signature__title"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<p class="gwcvt-signature__org"><?php echo esc_html( $org ); ?></p>
		</div>

		<footer class="gwcvt-disclaimer">
			<p><?php echo wp_kses( gwc_vt_replace_tokens( gwc_vt_disclaimer(), $tokens ), gwc_vt_letter_allowed_html() ); ?></p>
			<?php if ( ! gwc_vt_letter_shows_reference( $medium, $reproduced ) ) : ?>
				<?php
				/* No reference on a draft. It is the digest somebody checks by
				 * telephone against the issued-letter log, and a draft is in no
				 * log — a code that fails the only test it exists for is worse
				 * than no code.
				 *
				 * $reproduced is the third case and the one this branch missed:
				 * an issued letter reopened and rebuilt exactly is rendered
				 * through the draft medium, and it does have a reference. It
				 * said "this draft has none" underneath the code it was printing
				 * two lines further up. Every place that reads the medium to
				 * decide whether there is a reference has to read this as
				 * well — the token table, the band, the title, and here. */
				?>
				<p class="gwcvt-reference-note"><?php esc_html_e( 'A reference is given when the letter is issued. This draft has none, so there is nothing to check by telephone.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<?php else : ?>
				<p class="gwcvt-reference-note"><?php echo wp_kses( gwc_vt_replace_tokens( gwc_vt_reference_note(), $tokens ), gwc_vt_letter_allowed_html() ); ?></p>
				<p class="gwcvt-reference"><?php echo esc_html( $letter->reference ); ?></p>
			<?php endif; ?>
		</footer>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * What may survive in the intro and disclaimer.
 *
 * Almost nothing, and that is the point — see the note at the top of this file
 * about why the inliner can be hand-written. Emphasis and line breaks only.
 *
 * @return array
 */
function gwc_vt_letter_allowed_html(): array {
	return array(
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);
}

/**
 * The values a letter's configurable text can refer to.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @param string        $medium 'print', 'email' or 'draft' — a draft has no
 *                              reference, so {reference} says so instead.
 * @param bool          $reproduced Whether this is an issued letter reopened
 *                                  and rebuilt exactly — which keeps its own
 *                                  reference rather than printing "none".
 * @return array<string, string>
 */
function gwc_vt_letter_tokens( GWC_VT_Letter $letter, string $medium = 'print', bool $reproduced = false ): array {
	/* A draft has no reference, and {reference} is a token an administrator can
	 * put in the intro, the disclaimer or the reference note — so taking the
	 * printed reference off the footer is not enough on its own. Every route the
	 * code can reach the page by goes through this array, which is why the
	 * substitution happens here rather than in three places that would have to
	 * agree. */
	/* A reopened letter that rebuilt faithfully has its real reference back on
	 * it — gwc_vt_stamp_issued_letter() put it there — and printing "none" over
	 * the code a court is holding would be the one substitution that matters
	 * getting it exactly backwards. It is told apart from a draft by having one
	 * at all: gwc_vt_build_letter() always mints a reference, but a draft's is
	 * never written to the log and never printed. */
	$reference = gwc_vt_letter_shows_reference( $medium, $reproduced )
		? $letter->reference
		: __( 'none — this copy has not been issued', 'groundwork-common-volunteer-tracker' );

	return array(
		'{org}'       => gwc_vt_org_name(),
		'{name}'      => $letter->volunteer_name,
		'{hours}'     => gwc_vt_format_hours( $letter->verified_minutes ),
		'{shifts}'    => (string) number_format_i18n( $letter->verified_count() ),
		'{period}'    => gwc_vt_letter_period( $letter ),
		'{contact}'   => gwc_vt_org_contact(),
		'{reference}' => $reference,
		'{timestamp}' => (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $letter->issued_at ),
		'{timezone}'  => gwc_vt_timezone_label( $letter->issued_at ),
	);
}

/**
 * Substitute {tokens}.
 *
 * @param string $text   Text containing tokens.
 * @param array  $tokens Token map.
 * @return string
 */
function gwc_vt_replace_tokens( string $text, array $tokens ): string {
	return str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
}

/**
 * The opening paragraph.
 *
 * @param array $tokens Token map.
 * @return string
 */
function gwc_vt_letter_intro( array $tokens ): string {
	$intro = trim( (string) gwc_vt_setting( 'letter_intro' ) );

	if ( '' === $intro ) {
		$intro = __( 'This letter confirms that {name} performed {hours} hours of verified volunteer service with {org} during {period}. The shifts below are recorded in our volunteer hour log, and each was attested to by a member of our staff.', 'groundwork-common-volunteer-tracker' );
	}

	return gwc_vt_replace_tokens( $intro, $tokens );
}

/**
 * The period, in words.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @return string
 */
function gwc_vt_letter_period( GWC_VT_Letter $letter ): string {
	/* Formatted, like every other date on the document. The stored Y-m-d is what
	 * the reference code is computed over and what the checker compares — this is
	 * only how it is written on the page. */
	$from = gwc_vt_display_date( $letter->from );
	$to   = gwc_vt_display_date( $letter->to );

	if ( '' !== $letter->from && '' !== $letter->to ) {
		return sprintf(
			/* translators: 1: a date, 2: a later date. */
			__( '%1$s to %2$s', 'groundwork-common-volunteer-tracker' ),
			$from,
			$to
		);
	}

	if ( '' !== $letter->from ) {
		/* translators: %s: a date. */
		return sprintf( __( 'from %s onwards', 'groundwork-common-volunteer-tracker' ), $from );
	}

	if ( '' !== $letter->to ) {
		/* translators: %s: a date. */
		return sprintf( __( 'up to %s', 'groundwork-common-volunteer-tracker' ), $to );
	}

	/* ── The unbounded case, which used to overclaim ─────────────────────────
	 * It said "their entire time volunteering with us", and that is not what the
	 * letter covers. It covers what this organization had recorded between their
	 * first shift and the day the letter was produced — which is the same thing
	 * only if they never volunteer again and nothing is verified late. On a
	 * document somebody hands to a court, "entire" is a claim about the future
	 * and about other organizations, and this plugin makes neither.
	 *
	 * So it names the two ends it actually has. The start is the earliest shift
	 * on the letter and the end is the day it was issued, both of which are on
	 * the page anyway — the first in the itemised table, the second in the
	 * timestamp — so the sentence agrees with the rest of the document instead of
	 * summarising it more grandly.
	 *
	 * The reference is unaffected: gwc_vt_letter_reference() digests the raw
	 * from and to, which stay empty, so every letter already issued still checks
	 * out against its own code. */
	$earliest = gwc_vt_letter_earliest_date( $letter );

	$issued = gwc_vt_display_date( (string) wp_date( 'Y-m-d', $letter->issued_at ) );

	if ( '' === $earliest ) {
		/* No entries at all, which is a letter this plugin refuses to issue —
		 * but a preview of one can reach here. */
		return sprintf(
			/* translators: %s: a date. */
			__( 'their service on record up to %s', 'groundwork-common-volunteer-tracker' ),
			$issued
		);
	}

	return sprintf(
		/* translators: 1: the date of their first shift, 2: the date of the letter. */
		__( '%1$s to %2$s, the date of this letter', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_display_date( $earliest ),
		$issued
	);
}

/**
 * The document's title, which is also the suggested filename when printing.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @param string        $medium 'print', 'email' or 'draft'.
 * @param array         $issued Optional. The log record this is being reopened
 *                              from — a copy of one, not a draft.
 * @return string
 */
function gwc_vt_letter_title( GWC_VT_Letter $letter, string $medium = 'print', array $issued = array() ): string {
	if ( isset( $issued['reference'] ) && ! empty( $issued['faithful'] ) ) {
		/* Reopened and rebuilt exactly. It is the letter, so it is named the
		 * way the letter is named — and the print dialog suggests the same
		 * filename it suggested the day it went out. */
		return sprintf(
			/* translators: 1: a volunteer's name, 2: the organization's name. */
			__( 'Volunteer service verification — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
			$letter->volunteer_name,
			gwc_vt_org_name()
		);
	}

	if ( isset( $issued['reference'] ) ) {
		return sprintf(
			/* translators: 1: a volunteer's name, 2: the organization's name. */
			__( 'Copy — volunteer service verification — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
			$letter->volunteer_name,
			gwc_vt_org_name()
		);
	}

	if ( 'draft' === $medium ) {
		return sprintf(
			/* translators: 1: a volunteer's name, 2: the organization's name. */
			__( 'Draft — volunteer service verification — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
			$letter->volunteer_name,
			gwc_vt_org_name()
		);
	}

	return sprintf(
		/* translators: 1: a volunteer's name, 2: the organization's name. */
		__( 'Volunteer service verification — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
		$letter->volunteer_name,
		gwc_vt_org_name()
	);
}

/**
 * The organization's logo, if one is set and still exists.
 *
 * A sized version rather than the original: the letterhead caps the height in
 * CSS either way, but a four-megabyte original inside an emailed letter is rude
 * to whoever opens it on a phone.
 *
 * @return string An absolute URL, or ''.
 */
function gwc_vt_letter_logo_url(): string {
	$attachment = (int) gwc_vt_setting( 'org_logo' );

	if ( $attachment < 1 ) {
		return '';
	}

	/* Absolute, because this markup is also emailed — a relative URL would
	 * resolve against the reader's mail client and find nothing. */
	$url = wp_get_attachment_image_url( $attachment, 'medium' );

	return is_string( $url ) ? $url : '';
}

/**
 * How to reach the organization about this letter.
 *
 * @return string
 */
function gwc_vt_org_contact(): string {
	$contact = trim( (string) gwc_vt_setting( 'org_contact' ) );

	return '' !== $contact ? $contact : (string) get_option( 'admin_email' );
}

/* ── The inliner ─────────────────────────────────────────────────────────────
 * Email clients discard <style> blocks and stylesheet links, so an emailed
 * letter has to carry its styling on each element. Every general-purpose CSS
 * inliner is a Composer package, and this plugin has no dependencies — so this
 * is a hand-written map from the letter's own class names to declarations.
 *
 * It is only viable because the markup above is entirely ours: a fixed set of
 * classes, no nesting to resolve, no cascade to compute, no user HTML. Adding a
 * class to the template means adding a row here, and the integration test
 * asserts the emailed letter carries inline styles and no <link>.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Apply the letter's styling as style attributes.
 *
 * @param string $html The letter body.
 * @return string
 */
function gwc_vt_inline_letter_styles( string $html ): string {
	$rules = array(
		'gwcvt-letter'           => 'font-family:Georgia,"Times New Roman",serif;font-size:12pt;line-height:1.55;color:#1a1a1a;max-width:44em;margin:0 auto;padding:16px;',
		'gwcvt-letterhead'       => 'border-bottom:1px solid #999;padding-bottom:10px;margin-bottom:22px;',
		'gwcvt-org-logo'         => 'display:block;max-height:56px;width:auto;margin:0 0 8px;',
		'gwcvt-org'              => 'font-size:15pt;font-weight:bold;margin:0 0 3px;',
		'gwcvt-org-address'      => 'margin:0;font-size:10pt;color:#444;',
		'gwcvt-org-contact'      => 'margin:2px 0 0;font-size:10pt;color:#444;',
		'gwcvt-date'             => 'margin:0 0 18px;font-size:11pt;color:#444;',
		'gwcvt-heading'          => 'font-size:15pt;margin:0 0 14px;',
		'gwcvt-subheading'       => 'font-size:12pt;margin:26px 0 8px;',
		'gwcvt-intro'            => 'margin:0 0 20px;',
		'gwcvt-summary-table'    => 'border-collapse:collapse;width:100%;margin:0 0 8px;',
		'gwcvt-entries'          => 'border-collapse:collapse;width:100%;font-size:10.5pt;',
		/* Carries the cell padding as well as its own size, because the classless
		 * cell pass below deliberately leaves classed cells alone — otherwise
		 * this one would be styled twice and lose whichever came second. */
		'gwcvt-total'            => 'padding:4px 0;vertical-align:top;font-size:13pt;',
		'gwcvt-row--unverified'  => 'color:#555;font-style:italic;',
		'gwcvt-unverified-note'  => 'font-size:10pt;color:#555;margin:10px 0 0;',
		'gwcvt-signature'        => 'margin:40px 0 0;',
		'gwcvt-signature__rule'  => 'border-bottom:1px solid #333;width:22em;margin:0 0 6px;height:2.4em;',
		'gwcvt-signature__name'  => 'margin:0;font-weight:bold;',
		'gwcvt-signature__title' => 'margin:0;font-size:10.5pt;color:#444;',
		'gwcvt-signature__org'   => 'margin:0;font-size:10.5pt;color:#444;',
		'gwcvt-disclaimer'       => 'margin:34px 0 0;padding-top:12px;border-top:1px solid #ccc;font-size:9.5pt;color:#444;line-height:1.5;',
		'gwcvt-reference-note'   => 'margin:8px 0 0;',
		'gwcvt-reference'        => 'margin:8px 0 0;font-family:Menlo,Consolas,monospace;font-size:10pt;color:#1a1a1a;letter-spacing:.04em;',
	);

	/* ── One pass, exact class names, one style attribute ────────────────────
	 * The first version ran a regex per rule and appended a style attribute
	 * each time it matched. Two things were wrong with that.
	 *
	 * \b treats a hyphen as a word boundary, so the rule for `gwcvt-org`
	 * matched inside `gwcvt-org-logo`, `gwcvt-org-address` and
	 * `gwcvt-org-contact`. And appending meant an element could end up with
	 * TWO style attributes — where HTML takes the first and silently discards
	 * the second, so which rule won depended on the order the rules happened to
	 * be listed in. The address and contact lines rendered correctly by luck;
	 * the logo did not, and arrived as bold 15pt text with no height cap.
	 *
	 * So: walk each element once, split its class attribute on whitespace,
	 * match names exactly, and write a single style attribute. Nothing depends
	 * on rule order any more.
	 * ─────────────────────────────────────────────────────────────────────── */
	return (string) preg_replace_callback(
		'/<([a-z]+)([^>]*\bclass="([^"]*)"[^>]*?)>/i',
		static function ( array $matched ) use ( $rules ): string {
			$declarations = '';

			foreach ( preg_split( '/\s+/', trim( $matched[3] ) ) as $class ) {
				if ( '' !== $class && isset( $rules[ $class ] ) ) {
					$declarations .= $rules[ $class ];
				}
			}

			if ( '' === $declarations ) {
				return $matched[0];
			}

			$attributes = rtrim( $matched[2] );
			$closes     = '';

			// <img … /> — the slash belongs after the attributes, not inside them.
			if ( '/' === substr( $attributes, -1 ) ) {
				$attributes = rtrim( substr( $attributes, 0, -1 ) );
				$closes     = ' /';
			}

			return '<' . $matched[1] . $attributes . ' style="' . $declarations . '"' . $closes . '>';
		},
		gwc_vt_inline_letter_cells( $html )
	);
}

/**
 * The table cells and headers, which carry no classes of their own.
 *
 * @param string $html The letter body.
 * @return string
 */
function gwc_vt_inline_letter_cells( string $html ): string {
	/* Only cells with NO class of their own. A classed cell is handled by the
	 * class pass, and styling it here as well would give it two style
	 * attributes — of which HTML silently keeps the first. */
	return str_replace(
		array( '<th scope="row">', '<th scope="col">', '<td>' ),
		array(
			'<th scope="row" style="text-align:left;padding:4px 12px 4px 0;font-weight:normal;color:#444;vertical-align:top;width:11em;">',
			'<th scope="col" style="text-align:left;padding:5px 8px;border-bottom:1px solid #999;font-size:10pt;">',
			'<td style="padding:5px 8px;border-bottom:1px solid #e0e0e0;vertical-align:top;">',
		),
		$html
	);
}

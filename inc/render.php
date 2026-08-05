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
 * letter that differs from the copy the organisation has on file.
 *
 * So there is one function that produces the markup, and the only difference
 * between the media is how the styling gets attached:
 *
 *   print  links assets/css/letter.css
 *   email  runs the same markup through gwcvt_inline_letter_styles()
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
 * Render a letter.
 *
 * @param GWCVT_Letter $letter The letter.
 * @param string       $medium 'print' or 'email'.
 * @return string A complete HTML document.
 */
function gwcvt_render_letter( GWCVT_Letter $letter, string $medium = 'print' ): string {
	$body = gwcvt_letter_body( $letter, $medium );

	if ( 'email' === $medium ) {
		$body = gwcvt_inline_letter_styles( $body );
	}

	ob_start();
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( gwcvt_letter_title( $letter ) ); ?></title>
	<?php if ( 'print' === $medium ) : ?>
		<link rel="stylesheet" href="<?php echo esc_url( GWCVT_URL . 'assets/css/letter.css?ver=' . GWCVT_VERSION ); ?>" />
	<?php endif; ?>
</head>
<body class="gwcvt-letter-page">
	<?php if ( 'print' === $medium ) : ?>
		<?php /* Hidden by the stylesheet under @media print, so it never appears on paper. */ ?>
		<div class="gwcvt-letter-toolbar">
			<button type="button" class="gwcvt-print-button" onclick="window.print()">
				<?php esc_html_e( 'Print this letter', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
			<span class="gwcvt-letter-toolbar__hint">
				<?php esc_html_e( 'Choose “Save as PDF” in the print dialog to email it yourself.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>
	<?php endif; ?>

	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwcvt_letter_body(). ?>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/**
 * The letter itself, without the document wrapper.
 *
 * @param GWCVT_Letter $letter The letter.
 * @param string       $medium 'print' or 'email'.
 * @return string
 */
function gwcvt_letter_body( GWCVT_Letter $letter, string $medium ): string {
	$org       = gwcvt_org_name();
	$tokens    = gwcvt_letter_tokens( $letter );
	$itemize   = (bool) gwcvt_setting( 'letter_itemize' );
	$signatory = trim( (string) gwcvt_setting( 'signatory_name' ) );
	$title     = trim( (string) gwcvt_setting( 'signatory_title' ) );
	$address   = trim( (string) gwcvt_setting( 'org_address' ) );
	$contact   = gwcvt_org_contact();

	ob_start();
	?>
	<div class="gwcvt-letter">

		<header class="gwcvt-letterhead">
			<p class="gwcvt-org"><?php echo esc_html( $org ); ?></p>
			<?php if ( '' !== $address ) : ?>
				<p class="gwcvt-org-address"><?php echo nl2br( esc_html( $address ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $contact ) : ?>
				<p class="gwcvt-org-contact"><?php echo esc_html( $contact ); ?></p>
			<?php endif; ?>
		</header>

		<p class="gwcvt-date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), $letter->issued_at ) ); ?></p>

		<h1 class="gwcvt-heading"><?php esc_html_e( 'Volunteer Service Verification', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<p class="gwcvt-intro"><?php echo wp_kses( gwcvt_letter_intro( $tokens ), gwcvt_letter_allowed_html() ); ?></p>

		<table class="gwcvt-summary-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></th>
					<td><?php echo esc_html( $letter->volunteer_name ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Period covered', 'groundwork-common-volunteer-tracker' ); ?></th>
					<td><?php echo esc_html( gwcvt_letter_period( $letter ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verified hours', 'groundwork-common-volunteer-tracker' ); ?></th>
					<td class="gwcvt-total"><strong><?php echo esc_html( gwcvt_format_hours( $letter->verified_minutes ) ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shifts', 'groundwork-common-volunteer-tracker' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $letter->verified_count() ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $itemize && ! $letter->is_empty() ) : ?>
			<h2 class="gwcvt-subheading"><?php esc_html_e( 'Shifts recorded', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<?php
			/* Itemised by default. A total with nothing behind it is a number the
			 * reader has to take on faith, and taking it on faith is exactly what
			 * this document is trying not to require. */
			?>
			<table class="gwcvt-entries">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Activity', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Verification', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $letter->entries as $entry ) : ?>
						<tr class="<?php echo $entry->verified ? 'gwcvt-row' : 'gwcvt-row gwcvt-row--unverified'; ?>">
							<td><?php echo esc_html( $entry->date ); ?></td>
							<td><?php echo esc_html( gwcvt_format_hours( $entry->minutes ) ); ?></td>
							<td><?php echo esc_html( $entry->activity ); ?></td>
							<td><?php echo esc_html( $entry->supervisor ); ?></td>
							<td>
								<?php
								echo esc_html(
									$entry->verified
										? $entry->attestation
										: __( 'Not verified — not included in the total above', 'groundwork-common-volunteer-tracker' )
								);
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
						esc_html( gwcvt_format_hours( $letter->unverified_minutes ) )
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
		 * If no signatory is configured the line still prints, unlabelled. That
		 * looks visibly unfinished, which is the correct prompt to go and fill
		 * it in before sending one to a court.
		 * ─────────────────────────────────────────────────────────────────── */
		?>
		<div class="gwcvt-signature">
			<p class="gwcvt-signature__rule">&nbsp;</p>
			<p class="gwcvt-signature__name"><?php echo esc_html( '' !== $signatory ? $signatory : __( 'Signature', 'groundwork-common-volunteer-tracker' ) ); ?></p>
			<?php if ( '' !== $title ) : ?>
				<p class="gwcvt-signature__title"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<p class="gwcvt-signature__org"><?php echo esc_html( $org ); ?></p>
		</div>

		<footer class="gwcvt-disclaimer">
			<p><?php echo wp_kses( gwcvt_replace_tokens( gwcvt_disclaimer(), $tokens ), gwcvt_letter_allowed_html() ); ?></p>
			<p class="gwcvt-reference-note"><?php echo wp_kses( gwcvt_replace_tokens( gwcvt_reference_note(), $tokens ), gwcvt_letter_allowed_html() ); ?></p>
			<p class="gwcvt-reference"><?php echo esc_html( $letter->reference ); ?></p>
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
function gwcvt_letter_allowed_html(): array {
	return array(
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);
}

/**
 * The values a letter's configurable text can refer to.
 *
 * @param GWCVT_Letter $letter The letter.
 * @return array<string, string>
 */
function gwcvt_letter_tokens( GWCVT_Letter $letter ): array {
	return array(
		'{org}'       => gwcvt_org_name(),
		'{name}'      => $letter->volunteer_name,
		'{hours}'     => gwcvt_format_hours( $letter->verified_minutes ),
		'{shifts}'    => (string) number_format_i18n( $letter->verified_count() ),
		'{period}'    => gwcvt_letter_period( $letter ),
		'{contact}'   => gwcvt_org_contact(),
		'{reference}' => $letter->reference,
		'{timestamp}' => (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $letter->issued_at ),
		'{timezone}'  => gwcvt_timezone_label( $letter->issued_at ),
	);
}

/**
 * The site's timezone, named the way a person would name it.
 *
 * wp_timezone_string() returns whatever is in the setting, which for a site
 * configured by UTC offset is a string like "+00:00". On a letter that reads as
 * a bug — "Generated 5 August 2026 3:53 pm (+00:00)" — and the timestamp is one
 * of the things making this document credible, so it cannot look broken.
 *
 * A named zone gives a real abbreviation through wp_date( 'T' ): EDT, GMT, AEST.
 * An offset site gets "UTC" or "UTC+2", which is what somebody would say aloud.
 *
 * @param int $timestamp When the letter was produced.
 * @return string
 */
function gwcvt_timezone_label( int $timestamp ): string {
	$zone = (string) wp_timezone_string();

	if ( '' === $zone ) {
		return 'UTC';
	}

	// A named zone, e.g. "America/New_York".
	if ( false === strpos( $zone, ':' ) && '+' !== $zone[0] && '-' !== $zone[0] ) {
		$abbreviation = (string) wp_date( 'T', $timestamp );

		return '' !== $abbreviation ? $abbreviation : $zone;
	}

	// An offset, e.g. "+00:00" or "-05:30".
	$hours   = (int) substr( $zone, 0, 3 );
	$minutes = (int) substr( $zone, 4, 2 );

	if ( 0 === $hours && 0 === $minutes ) {
		return 'UTC';
	}

	return 'UTC' . sprintf( '%+d', $hours ) . ( 0 !== $minutes ? sprintf( ':%02d', $minutes ) : '' );
}

/**
 * Substitute {tokens}.
 *
 * @param string $text   Text containing tokens.
 * @param array  $tokens Token map.
 * @return string
 */
function gwcvt_replace_tokens( string $text, array $tokens ): string {
	return str_replace( array_keys( $tokens ), array_values( $tokens ), $text );
}

/**
 * The opening paragraph.
 *
 * @param array $tokens Token map.
 * @return string
 */
function gwcvt_letter_intro( array $tokens ): string {
	$intro = trim( (string) gwcvt_setting( 'letter_intro' ) );

	if ( '' === $intro ) {
		$intro = __( 'This letter confirms that {name} performed {hours} hours of verified volunteer service with {org} during {period}. The shifts below are recorded in our volunteer hour log, and each was attested to by a member of our staff.', 'groundwork-common-volunteer-tracker' );
	}

	return gwcvt_replace_tokens( $intro, $tokens );
}

/**
 * The period, in words.
 *
 * @param GWCVT_Letter $letter The letter.
 * @return string
 */
function gwcvt_letter_period( GWCVT_Letter $letter ): string {
	if ( '' !== $letter->from && '' !== $letter->to ) {
		return sprintf(
			/* translators: 1: a date, 2: a date. */
			__( '%1$s to %2$s', 'groundwork-common-volunteer-tracker' ),
			$letter->from,
			$letter->to
		);
	}

	if ( '' !== $letter->from ) {
		/* translators: %s: a date. */
		return sprintf( __( 'from %s onwards', 'groundwork-common-volunteer-tracker' ), $letter->from );
	}

	if ( '' !== $letter->to ) {
		/* translators: %s: a date. */
		return sprintf( __( 'up to %s', 'groundwork-common-volunteer-tracker' ), $letter->to );
	}

	return __( 'their entire time volunteering with us', 'groundwork-common-volunteer-tracker' );
}

/**
 * The document's title, which is also the suggested filename when printing.
 *
 * @param GWCVT_Letter $letter The letter.
 * @return string
 */
function gwcvt_letter_title( GWCVT_Letter $letter ): string {
	return sprintf(
		/* translators: 1: a volunteer's name, 2: the organisation's name. */
		__( 'Volunteer service verification — %1$s — %2$s', 'groundwork-common-volunteer-tracker' ),
		$letter->volunteer_name,
		gwcvt_org_name()
	);
}

/**
 * How to reach the organisation about this letter.
 *
 * @return string
 */
function gwcvt_org_contact(): string {
	$contact = trim( (string) gwcvt_setting( 'org_contact' ) );

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
function gwcvt_inline_letter_styles( string $html ): string {
	$rules = array(
		'gwcvt-letter'            => 'font-family:Georgia,"Times New Roman",serif;font-size:12pt;line-height:1.55;color:#1a1a1a;max-width:44em;margin:0 auto;padding:16px;',
		'gwcvt-letterhead'        => 'border-bottom:1px solid #999;padding-bottom:10px;margin-bottom:22px;',
		'gwcvt-org'               => 'font-size:15pt;font-weight:bold;margin:0 0 3px;',
		'gwcvt-org-address'       => 'margin:0;font-size:10pt;color:#444;',
		'gwcvt-org-contact'       => 'margin:2px 0 0;font-size:10pt;color:#444;',
		'gwcvt-date'              => 'margin:0 0 18px;font-size:11pt;color:#444;',
		'gwcvt-heading'           => 'font-size:15pt;margin:0 0 14px;',
		'gwcvt-subheading'        => 'font-size:12pt;margin:26px 0 8px;',
		'gwcvt-intro'             => 'margin:0 0 20px;',
		'gwcvt-summary-table'     => 'border-collapse:collapse;width:100%;margin:0 0 8px;',
		'gwcvt-entries'           => 'border-collapse:collapse;width:100%;font-size:10.5pt;',
		'gwcvt-total'             => 'font-size:13pt;',
		'gwcvt-row--unverified'   => 'color:#555;font-style:italic;',
		'gwcvt-unverified-note'   => 'font-size:10pt;color:#555;margin:10px 0 0;',
		'gwcvt-signature'         => 'margin:40px 0 0;',
		'gwcvt-signature__rule'   => 'border-bottom:1px solid #333;width:22em;margin:0 0 6px;height:2.4em;',
		'gwcvt-signature__name'   => 'margin:0;font-weight:bold;',
		'gwcvt-signature__title'  => 'margin:0;font-size:10.5pt;color:#444;',
		'gwcvt-signature__org'    => 'margin:0;font-size:10.5pt;color:#444;',
		'gwcvt-disclaimer'        => 'margin:34px 0 0;padding-top:12px;border-top:1px solid #ccc;font-size:9.5pt;color:#444;line-height:1.5;',
		'gwcvt-reference-note'    => 'margin:8px 0 0;',
		'gwcvt-reference'         => 'margin:8px 0 0;font-family:Menlo,Consolas,monospace;font-size:10pt;color:#1a1a1a;letter-spacing:.04em;',
	);

	foreach ( $rules as $class => $declarations ) {
		/* Matches class="…" containing this class as a whole word, and appends
		 * a style attribute. Deliberately not a DOM parse: loading DOMDocument
		 * to add fourteen attributes would rewrite the entity encoding of every
		 * name on the page, and a mangled apostrophe in a volunteer's surname is
		 * exactly the sort of small wrongness this document cannot afford. */
		$html = preg_replace(
			'/(<[a-z]+[^>]*\bclass="[^"]*\b' . preg_quote( $class, '/' ) . '\b[^"]*")/i',
			'$1 style="' . $declarations . '"',
			$html
		);
	}

	/* The table cells and headers, which have no classes of their own. */
	$html = str_replace(
		array( '<th scope="row">', '<th scope="col">', '<td>', '<td class="gwcvt-total"' ),
		array(
			'<th scope="row" style="text-align:left;padding:4px 12px 4px 0;font-weight:normal;color:#444;vertical-align:top;width:11em;">',
			'<th scope="col" style="text-align:left;padding:5px 8px;border-bottom:1px solid #999;font-size:10pt;">',
			'<td style="padding:5px 8px;border-bottom:1px solid #e0e0e0;vertical-align:top;">',
			'<td style="padding:5px 8px;border-bottom:1px solid #e0e0e0;vertical-align:top;"',
		),
		$html
	);

	return $html;
}

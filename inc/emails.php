<?php
/**
 * Sending mail, and the guard that stops a staging site sending it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── The staging guard ───────────────────────────────────────────────────────
 * This matters more here than in any sibling plugin. A staging clone of a
 * nonprofit's site, restored from production and left running, will happily
 * email verification letters about court-ordered service to the real
 * volunteers and the real probation officers on file. That is the worst thing
 * this plugin could do, it takes one forgotten database copy to arrange, and
 * nobody would find out until somebody phoned.
 *
 * So two constants, both defined in wp-config.php on the copy rather than on
 * production, because the copy is what you control when you make it:
 *
 *   GWC_VT_MAIL_MODE   'off'   send nothing at all
 *                     'trap'  redirect every message to GWC_VT_MAIL_ALLOW
 *   GWC_VT_MAIL_ALLOW  the address 'trap' redirects to
 *
 * Undefined means normal delivery, so production needs no configuration and a
 * site that never heard of these constants behaves exactly as expected.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Send one message.
 *
 * @param string $to      Recipient address.
 * @param string $subject Subject line.
 * @param string $body    HTML body.
 * @param array  $headers Extra headers.
 * @return bool Whether the message was accepted for delivery.
 */
function gwc_vt_send_email( string $to, string $subject, string $body, array $headers = array() ): bool {
	$mode = defined( 'GWC_VT_MAIL_MODE' ) ? strtolower( (string) GWC_VT_MAIL_MODE ) : '';

	if ( 'off' === $mode ) {
		return false;
	}

	if ( 'trap' === $mode ) {
		$trap = defined( 'GWC_VT_MAIL_ALLOW' ) ? (string) GWC_VT_MAIL_ALLOW : '';

		/* Trap mode with nowhere to send is the same as off. Falling through to
		 * the real recipient here would defeat the entire point of the setting
		 * at exactly the moment somebody was relying on it. */
		if ( '' === $trap || ! is_email( $trap ) ) {
			return false;
		}

		$subject = '[' . wp_parse_url( home_url(), PHP_URL_HOST ) . '] ' . $subject;
		$body    = '<p><strong>' . esc_html(
			sprintf(
				/* translators: %s: the address the message was originally addressed to. */
				__( 'Trapped by GWC_VT_MAIL_MODE. This was addressed to %s.', 'groundwork-common-volunteer-tracker' ),
				$to
			)
		) . '</strong></p>' . $body;

		$to = $trap;
	}

	if ( ! is_email( $to ) ) {
		return false;
	}

	$headers[] = 'Content-Type: text/html; charset=UTF-8';

	$from_name  = trim( (string) gwc_vt_setting( 'from_name' ) );
	$from_email = trim( (string) gwc_vt_setting( 'from_email' ) );

	if ( '' !== $from_email && is_email( $from_email ) ) {
		$headers[] = sprintf(
			'From: %s <%s>',
			'' !== $from_name ? $from_name : gwc_vt_org_name(),
			$from_email
		);
	}

	/**
	 * Filter a message before it is sent.
	 *
	 * @param array $message to, subject, body and headers.
	 */
	$message = (array) apply_filters(
		'gwc_vt_email',
		array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		)
	);

	return (bool) wp_mail(
		(string) $message['to'],
		(string) $message['subject'],
		(string) $message['body'],
		(array) $message['headers']
	);
}

/**
 * The subject line for a verification letter.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @return string
 */
function gwc_vt_letter_subject( GWC_VT_Letter $letter ): string {
	$subject = trim( (string) gwc_vt_setting( 'email_subject' ) );

	if ( '' === $subject ) {
		$subject = __( 'Your volunteer service verification from {org}', 'groundwork-common-volunteer-tracker' );
	}

	return gwc_vt_replace_tokens( $subject, gwc_vt_letter_tokens( $letter ) );
}

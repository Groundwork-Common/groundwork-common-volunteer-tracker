<?php
/**
 * Front-end output for the volunteer offer form block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwc_vt_offer_form = gwc_vt_render_registration_form();

if ( '' === $gwc_vt_offer_form ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwc_vt_offer_form // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwc_vt_render_registration_form().
);

<?php
/**
 * Front-end output for the hours form block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwc_vt_form = gwc_vt_render_self_log_form();

if ( '' === $gwc_vt_form ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwc_vt_form // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwc_vt_render_self_log_form().
);

<?php
/**
 * Front-end output for the hours form block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwcvt_form = gwcvt_render_self_log_form();

if ( '' === $gwcvt_form ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwcvt_form // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwcvt_render_self_log_form().
);

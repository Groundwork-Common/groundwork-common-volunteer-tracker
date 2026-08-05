<?php
/**
 * Front-end output for the shift list block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwcvt_list = gwcvt_render_shift_list();

if ( '' === $gwcvt_list ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwcvt_list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwcvt_render_shift_list().
);

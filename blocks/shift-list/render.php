<?php
/**
 * Front-end output for the shift list block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwc_vt_list = gwc_vt_render_shift_list();

if ( '' === $gwc_vt_list ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwc_vt_list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwc_vt_render_shift_list().
);

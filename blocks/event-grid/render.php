<?php
/**
 * Front-end output for the event grid block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwc_vt_event_id = isset( $attributes['eventId'] ) ? (int) $attributes['eventId'] : 0;

if ( $gwc_vt_event_id < 1 ) {
	return;
}

$gwc_vt_grid = gwc_vt_render_event_grid( $gwc_vt_event_id );

if ( '' === $gwc_vt_grid ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwc_vt_grid // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwc_vt_render_event_grid().
);

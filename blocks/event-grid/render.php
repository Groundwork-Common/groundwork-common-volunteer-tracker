<?php
/**
 * Front-end output for the event grid block.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

$gwcvt_event_id = isset( $attributes['eventId'] ) ? (int) $attributes['eventId'] : 0;

if ( $gwcvt_event_id < 1 ) {
	return;
}

$gwcvt_grid = gwcvt_render_event_grid( $gwcvt_event_id );

if ( '' === $gwcvt_grid ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	wp_kses_data( get_block_wrapper_attributes() ),
	$gwcvt_grid // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled and escaped in gwcvt_render_event_grid().
);

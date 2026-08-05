<?php
/**
 * The hours form block, and the shortcode that ships beside it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwcvt_register_block' );
add_action( 'enqueue_block_editor_assets', 'gwcvt_localize_block_editor' );

/* ── A shortcode as well, permanently ────────────────────────────────────────
 * Not a deprecation path and not a fallback — a supported way to place the form
 * that happens to work in a classic editor, a widget, a page builder, and a
 * theme template. The organisations this plugin is for are not all on block
 * themes, and telling one of them their site is too old to log volunteer hours
 * is not an answer.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the block and the shortcode.
 */
function gwcvt_register_block(): void {
	if ( function_exists( 'register_block_type_from_metadata' ) ) {
		register_block_type_from_metadata( GWCVT_DIR . 'blocks/hours-form' );
		register_block_type_from_metadata( GWCVT_DIR . 'blocks/shift-list' );
	}

	add_shortcode( 'volunteer_hours_form', 'gwcvt_form_shortcode' );
	add_shortcode( 'volunteer_shifts', 'gwcvt_shifts_shortcode' );
}

/**
 * The shift list as a shortcode.
 *
 * @param array $atts Ignored; the list has no per-instance options.
 * @return string
 */
function gwcvt_shifts_shortcode( $atts = array() ): string {
	$list = gwcvt_render_shift_list();

	if ( '' !== $list ) {
		wp_enqueue_style( 'gwcvt-schedule' );
	}

	return $list;
}

/**
 * The shortcode.
 *
 * @param array $atts Ignored; the form has no per-instance options.
 * @return string
 */
function gwcvt_form_shortcode( $atts = array() ): string {
	/* The block gets its stylesheet from block.json's "style" handle; a
	 * shortcode has no such wiring and has to ask. Enqueued only when the form
	 * actually renders, so a page with the shortcode on it but the feature
	 * switched off loads nothing. */
	$form = gwcvt_render_self_log_form();

	if ( '' !== $form ) {
		wp_enqueue_style( 'gwcvt-form' );
	}

	return $form;
}

/**
 * Tell the editor what the form's settings are.
 *
 * The block cannot read a WordPress option from JavaScript without either a
 * REST route or this. A route would mean exposing the plugin's configuration to
 * anybody who can edit a post; a handful of booleans localised onto the editor
 * script does not.
 */
function gwcvt_localize_block_editor(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	$current = $screen && 'page' === $screen->post_type ? (int) get_the_ID() : 0;

	wp_localize_script(
		'groundwork-common-volunteer-tracker-hours-form-editor-script',
		'GWCVT_EDITOR',
		array(
			'selfLogEnabled' => (bool) gwcvt_setting( 'self_log_enabled' ),
			'pinnedPage'     => (int) gwcvt_setting( 'self_log_page' ),
			'currentPage'    => $current,
		)
	);

	wp_localize_script(
		'groundwork-common-volunteer-tracker-shift-list-editor-script',
		'GWCVT_SHIFT_EDITOR',
		array(
			'shiftsEnabled' => (bool) gwcvt_setting( 'shifts_enabled' ),
			'signupEnabled' => (bool) gwcvt_setting( 'signup_enabled' ),
			'pinnedPage'    => (int) gwcvt_setting( 'schedule_page' ),
			'currentPage'   => $current,
		)
	);
}

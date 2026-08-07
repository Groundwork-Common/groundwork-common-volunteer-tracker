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
		register_block_type_from_metadata( GWCVT_DIR . 'blocks/event-grid' );

		/* ── The editor scripts' translations ────────────────────────────────
		 * Registered here, immediately after the handles exist, rather than in
		 * inc/enqueue.php beside the picker's. That call was the only one, so
		 * every string in the three edit.js files was extracted into the POT and
		 * could never render translated — the warnings telling an editor that
		 * the form is switched off, or pinned to another page, stayed in English
		 * on a Spanish site.
		 *
		 * The handle is derived by register_block_type_from_metadata() from the
		 * block name plus '-editor-script', which is why these strings look like
		 * they were guessed. They are the same names inc/block.php's
		 * wp_localize_script() calls already rely on.
		 * ─────────────────────────────────────────────────────────────────── */
		foreach ( array( 'hours-form', 'shift-list', 'event-grid' ) as $block ) {
			wp_set_script_translations(
				'groundwork-common-volunteer-tracker-' . $block . '-editor-script',
				'groundwork-common-volunteer-tracker',
				GWCVT_DIR . 'languages'
			);
		}
	}

	add_shortcode( 'volunteer_hours_form', 'gwcvt_form_shortcode' );
	add_shortcode( 'volunteer_shifts', 'gwcvt_shifts_shortcode' );
	add_shortcode( 'volunteer_event', 'gwcvt_event_shortcode' );
}

/**
 * One event's grid as a shortcode.
 *
 * Takes the event's ID, because unlike the shift list there is more than one of
 * these and the block has to be told which. A shortcode with no id renders
 * nothing rather than guessing at the next event — a page that silently
 * advertised a different occasion after the first one passed would be worse than
 * a page that showed nothing.
 *
 * @param array $atts id.
 * @return string
 */
function gwcvt_event_shortcode( $atts = array() ): string {
	$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'volunteer_event' );

	$grid = gwcvt_render_event_grid( (int) $atts['id'] );

	if ( '' !== $grid ) {
		wp_enqueue_style( 'gwcvt-schedule' );
	}

	return $grid;
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

	/* The events to choose from, by name and date. A list rather than a REST
	 * route for the same reason as the booleans above — and pointedly not the
	 * event type made queryable, which would publish a location and the shape of
	 * an organisation's calendar to anybody who asked. */
	$events = array();

	foreach ( gwcvt_events_between(
		array(
			'from'  => gwcvt_today(),
			'limit' => 50,
		)
	) as $event_id ) {
		$events[] = array(
			'value' => (string) $event_id,
			'label' => sprintf(
				/* translators: 1: an event's name, 2: when it is. */
				_x( '%1$s — %2$s', 'an event, in the block editor picker', 'groundwork-common-volunteer-tracker' ),
				gwcvt_event_name( (int) $event_id ),
				gwcvt_event_date_label( (int) $event_id )
			),
		);
	}

	wp_localize_script(
		'groundwork-common-volunteer-tracker-event-grid-editor-script',
		'GWCVT_EVENT_EDITOR',
		array(
			'shiftsEnabled' => (bool) gwcvt_setting( 'shifts_enabled' ),
			'signupEnabled' => (bool) gwcvt_setting( 'signup_enabled' ),
			'events'        => $events,
		)
	);
}

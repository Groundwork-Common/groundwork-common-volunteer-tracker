<?php
/**
 * The hours form block, and the shortcode that ships beside it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwc_vt_register_block' );
add_action( 'enqueue_block_editor_assets', 'gwc_vt_localize_block_editor' );

/* ── A shortcode as well, permanently ────────────────────────────────────────
 * Not a deprecation path and not a fallback — a supported way to place the form
 * that happens to work in a classic editor, a widget, a page builder, and a
 * theme template. The organizations this plugin is for are not all on block
 * themes, and telling one of them their site is too old to log volunteer hours
 * is not an answer.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the block and the shortcode.
 */
function gwc_vt_register_block(): void {
	if ( function_exists( 'register_block_type_from_metadata' ) ) {
		register_block_type_from_metadata( GWC_VT_DIR . 'blocks/hours-form' );
		register_block_type_from_metadata( GWC_VT_DIR . 'blocks/shift-list' );
		register_block_type_from_metadata( GWC_VT_DIR . 'blocks/event-grid' );
		register_block_type_from_metadata( GWC_VT_DIR . 'blocks/volunteer-form' );
		register_block_type_from_metadata( GWC_VT_DIR . 'blocks/volunteer-signin' );

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
		foreach ( array( 'hours-form', 'shift-list', 'event-grid', 'volunteer-form', 'volunteer-signin' ) as $block ) {
			wp_set_script_translations(
				'groundwork-common-volunteer-tracker-' . $block . '-editor-script',
				'groundwork-common-volunteer-tracker',
				GWC_VT_DIR . 'languages'
			);
		}
	}

	add_shortcode( 'gwc_vt_hours_form', 'gwc_vt_form_shortcode' );
	add_shortcode( 'gwc_vt_shift_list', 'gwc_vt_shifts_shortcode' );
	add_shortcode( 'gwc_vt_event_grid', 'gwc_vt_event_shortcode' );
	add_shortcode( 'gwc_vt_volunteer_form', 'gwc_vt_registration_shortcode' );
	add_shortcode( 'gwc_vt_volunteer_signin', 'gwc_vt_signin_shortcode' );
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
function gwc_vt_event_shortcode( $atts = array() ): string {
	$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'gwc_vt_event_grid' );

	$grid = gwc_vt_render_event_grid( (int) $atts['id'] );

	if ( '' !== $grid ) {
		wp_enqueue_style( 'gwc-vt-schedule' );
	}

	return $grid;
}

/**
 * The shift list as a shortcode.
 *
 * @param array $atts Ignored; the list has no per-instance options.
 * @return string
 */
function gwc_vt_shifts_shortcode( $atts = array() ): string {
	$list = gwc_vt_render_shift_list();

	if ( '' !== $list ) {
		wp_enqueue_style( 'gwc-vt-schedule' );
	}

	return $list;
}

/**
 * The shortcode.
 *
 * @param array $atts Ignored; the form has no per-instance options.
 * @return string
 */
function gwc_vt_form_shortcode( $atts = array() ): string {
	/* The block gets its stylesheet from block.json's "style" handle; a
	 * shortcode has no such wiring and has to ask. Enqueued only when the form
	 * actually renders, so a page with the shortcode on it but the feature
	 * switched off loads nothing. */
	$form = gwc_vt_render_self_log_form();

	if ( '' !== $form ) {
		wp_enqueue_style( 'gwc-vt-form' );
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
function gwc_vt_localize_block_editor(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	$current = $screen && 'page' === $screen->post_type ? (int) get_the_ID() : 0;

	wp_localize_script(
		'groundwork-common-volunteer-tracker-hours-form-editor-script',
		'GWC_VT_EDITOR',
		array(
			'selfLogEnabled' => (bool) gwc_vt_setting( 'self_log_enabled' ),
			'pinnedPage'     => (int) gwc_vt_setting( 'self_log_page' ),
			'currentPage'    => $current,
		)
	);

	wp_localize_script(
		'groundwork-common-volunteer-tracker-volunteer-signin-editor-script',
		'GWC_VT_SIGNIN_EDITOR',
		array(
			'signinEnabled' => (bool) gwc_vt_setting( 'signin_enabled' ),
			'pinnedPage'    => (int) gwc_vt_setting( 'signin_page' ),
			'currentPage'   => $current,
		)
	);

	wp_localize_script(
		'groundwork-common-volunteer-tracker-volunteer-form-editor-script',
		'GWC_VT_OFFER_EDITOR',
		array(
			'registrationEnabled' => (bool) gwc_vt_setting( 'registration_enabled' ),
			'pinnedPage'          => (int) gwc_vt_setting( 'registration_page' ),
			'currentPage'         => $current,
			/* So the editor can say that the form asks about court-ordered
			 * service. gwc_vt_registration_asks_required() rather than the raw
			 * setting, because the question is off whenever the form is. */
			'asksRequired'        => gwc_vt_registration_asks_required(),
		)
	);

	wp_localize_script(
		'groundwork-common-volunteer-tracker-shift-list-editor-script',
		'GWC_VT_SHIFT_EDITOR',
		array(
			'shiftsEnabled' => (bool) gwc_vt_setting( 'shifts_enabled' ),
			'signupEnabled' => (bool) gwc_vt_setting( 'signup_enabled' ),
			'pinnedPage'    => (int) gwc_vt_setting( 'schedule_page' ),
			'currentPage'   => $current,
		)
	);

	/* The events to choose from, by name and date. A list rather than a REST
	 * route for the same reason as the booleans above — and pointedly not the
	 * event type made queryable, which would publish a location and the shape of
	 * an organization's calendar to anybody who asked. */
	$events = array();

	foreach ( gwc_vt_events_between(
		array(
			'from'  => gwc_vt_today(),
			'limit' => 50,
		)
	) as $event_id ) {
		$events[] = array(
			'value' => (string) $event_id,
			'label' => sprintf(
				/* translators: 1: an event's name, 2: when it is. */
				_x( '%1$s — %2$s', 'an event, in the block editor picker', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_event_name( (int) $event_id ),
				gwc_vt_event_date_label( (int) $event_id )
			),
		);
	}

	wp_localize_script(
		'groundwork-common-volunteer-tracker-event-grid-editor-script',
		'GWC_VT_EVENT_EDITOR',
		array(
			'shiftsEnabled' => (bool) gwc_vt_setting( 'shifts_enabled' ),
			'signupEnabled' => (bool) gwc_vt_setting( 'signup_enabled' ),
			'events'        => $events,
		)
	);
}

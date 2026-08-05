<?php
/**
 * Registering and loading assets.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwcvt_register_front_assets' );
add_action( 'admin_enqueue_scripts', 'gwcvt_enqueue_admin_assets' );

/**
 * Register the front-end stylesheet.
 *
 * Registered on init, not enqueued. blocks/hours-form/block.json names the
 * handle in its "style" key, so WordPress enqueues it only on pages that
 * actually contain the block — which is the whole benefit of naming a handle
 * there rather than a file. The shortcode path enqueues it explicitly.
 */
function gwcvt_register_front_assets(): void {
	wp_register_style(
		'gwcvt-form',
		GWCVT_URL . 'assets/css/form.css',
		array(),
		GWCVT_VERSION
	);

	/* The shift list's stylesheet, on the same terms: blocks/shift-list's
	 * block.json names this handle in its "style" key, so it loads only on pages
	 * that actually contain the block. Separate from gwcvt-form because the two
	 * surfaces are independent — a site can run the hours form without the
	 * schedule, or the schedule without the hours form. */
	wp_register_style(
		'gwcvt-schedule',
		GWCVT_URL . 'assets/css/schedule.css',
		array(),
		GWCVT_VERSION
	);

	/* The letter's own stylesheet, registered rather than enqueued. The print
	 * view links it by URL because it renders a standalone document; the
	 * reference checker enqueues this handle so it can show the same letter
	 * inside wp-admin. One stylesheet either way — see the note at the top of
	 * inc/render.php about the printed and emailed letters being one document. */
	wp_register_style(
		'gwcvt-letter',
		GWCVT_URL . 'assets/css/letter.css',
		array(),
		GWCVT_VERSION
	);
}

/**
 * The admin stylesheet and, on the entry screen, the volunteer picker.
 *
 * Scoped rather than loaded everywhere. A plugin that puts its stylesheet on
 * every wp-admin page is a plugin that eventually restyles somebody else's
 * screen, and the check costs one function call.
 *
 * @param string $hook_suffix The current admin page.
 */
function gwcvt_enqueue_admin_assets( $hook_suffix ): void {
	if ( ! gwcvt_is_plugin_screen() ) {
		return;
	}

	wp_enqueue_style(
		'gwcvt-admin',
		GWCVT_URL . 'assets/css/admin.css',
		array(),
		GWCVT_VERSION
	);

	/* The picker appears in two places: the entry editor, and the Letters
	 * screen. One script serves both — it binds to every [data-gwcvt-picker] on
	 * the page rather than to a known ID, so a third caller costs nothing. */
	$screen = get_current_screen();

	$on_entry_editor = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
		&& $screen
		&& GWCVT_ENTRY_TYPE === $screen->post_type;

	$on_letters   = $screen && false !== strpos( (string) $screen->id, GWCVT_LETTERS_PAGE );
	$on_quick_add = $screen && false !== strpos( (string) $screen->id, GWCVT_QUICK_ADD_PAGE );
	$on_schedule  = $screen && false !== strpos( (string) $screen->id, GWCVT_SCHEDULE_PAGE );

	if ( ! $on_entry_editor && ! $on_letters && ! $on_quick_add && ! $on_schedule ) {
		return;
	}

	/* wp-api-fetch carries the X-WP-Nonce middleware, which is the ergonomic
	 * half of why the lookup is a REST route rather than an admin-ajax action:
	 * nothing here has to ship a nonce to the browser or remember to send it. */
	wp_enqueue_script(
		'gwcvt-admin-picker',
		GWCVT_URL . 'assets/js/admin-picker.js',
		array( 'wp-api-fetch' ),
		GWCVT_VERSION,
		true
	);

	if ( $on_quick_add ) {
		wp_enqueue_script(
			'gwcvt-quick-add',
			GWCVT_URL . 'assets/js/admin-quick-add.js',
			array( 'gwcvt-admin-picker' ),
			GWCVT_VERSION,
			true
		);
	}

	wp_set_script_translations(
		'gwcvt-admin-picker',
		'groundwork-common-volunteer-tracker',
		GWCVT_DIR . 'languages'
	);
}

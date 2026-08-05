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

	$on_letters = $screen && false !== strpos( (string) $screen->id, GWCVT_LETTERS_PAGE );

	if ( ! $on_entry_editor && ! $on_letters ) {
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

	wp_set_script_translations(
		'gwcvt-admin-picker',
		'groundwork-common-volunteer-tracker',
		GWCVT_DIR . 'languages'
	);
}

<?php
/**
 * Registering and loading assets.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'gwcvt_enqueue_admin_assets' );

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

	// The picker only exists on the entry editor.
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || GWCVT_ENTRY_TYPE !== $screen->post_type ) {
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

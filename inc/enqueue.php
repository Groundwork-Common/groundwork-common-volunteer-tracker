<?php
/**
 * Registering and loading assets.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwc_vt_register_front_assets' );
add_action( 'admin_enqueue_scripts', 'gwc_vt_enqueue_admin_assets' );

/**
 * Register the front-end stylesheet.
 *
 * Registered on init, not enqueued. blocks/hours-form/block.json names the
 * handle in its "style" key, so WordPress enqueues it only on pages that
 * actually contain the block — which is the whole benefit of naming a handle
 * there rather than a file. The shortcode path enqueues it explicitly.
 */
function gwc_vt_register_front_assets(): void {
	wp_register_style(
		'gwc-vt-form',
		GWC_VT_URL . 'assets/css/form.css',
		array(),
		GWC_VT_VERSION
	);

	/* The shift list's stylesheet, on the same terms: blocks/shift-list's
	 * block.json names this handle in its "style" key, so it loads only on pages
	 * that actually contain the block. Separate from gwcvt-form because the two
	 * surfaces are independent — a site can run the hours form without the
	 * schedule, or the schedule without the hours form. */
	wp_register_style(
		'gwc-vt-schedule',
		GWC_VT_URL . 'assets/css/schedule.css',
		array(),
		GWC_VT_VERSION
	);

	/* The letter's own stylesheet, registered rather than enqueued. Three
	 * standalone documents print it into their own <head> via
	 * gwc_vt_print_document_styles(); the reference checker enqueues this handle
	 * so it can show the same letter inside wp-admin. One stylesheet either way
	 * — see the note at the top of inc/render.php about the printed and emailed
	 * letters being one document. */
	wp_register_style(
		'gwc-vt-letter',
		GWC_VT_URL . 'assets/css/letter.css',
		array(),
		GWC_VT_VERSION
	);
}

/* ── Styling a document that is not a WordPress page ─────────────────────────
 * Three places here render a complete <!doctype html> document and exit: the
 * printed letter, the shift roster and the event roster. None of them runs the
 * template loader, so none of them ever reaches wp_head() — which is exactly
 * the point, and is the same argument as the one against a theme-overridable
 * letter template. A theme that can inject into that <head> is a theme that can
 * restyle a court document.
 *
 * These used to write the <link> by hand for that reason, with a phpcs:ignore
 * explaining it. That is defensible and it is still the wrong shape: the
 * version query string had to be remembered per call site and two of the three
 * had forgotten it, so a letter.css change did not reach a browser that had
 * cached the old one.
 *
 * wp_print_styles() with an explicit handle is the built-in function for this.
 * It prints that one handle and nothing else — passing a handle deliberately
 * skips the 'wp_print_styles' action, so nobody else gets to add to the
 * document — and the version comes off the registration.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Print one registered stylesheet into a standalone document's <head>.
 *
 * @param string $handle A style handle registered by gwc_vt_register_front_assets().
 */
function gwc_vt_print_document_styles( string $handle = 'gwc-vt-letter' ): void {
	/* These documents render from admin_post_* handlers, long after init. The
	 * guard is for the integration scripts, which call the renderers directly. */
	if ( ! wp_style_is( $handle, 'registered' ) ) {
		gwc_vt_register_front_assets();
	}

	wp_enqueue_style( $handle );

	/* Rendering the same letter twice in one request has to produce the same
	 * document twice. do_items() marks a handle done and skips it forever after,
	 * so without this the second render would come out unstyled — which is the
	 * sort of thing that is invisible until the day somebody prints two. */
	$styles       = wp_styles();
	$styles->done = array_values( array_diff( $styles->done, array( $handle ) ) );

	wp_print_styles( $handle );
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
function gwc_vt_enqueue_admin_assets( $hook_suffix ): void {
	/* WordPress's own dashboard is not a plugin screen and must not become one.
	 * The widget there gets its own two-kilobyte sheet rather than the 64KB
	 * admin one — see the header of assets/css/widget.css. Gated on the same
	 * capability that decides whether the widget is registered at all, so a
	 * contributor who will never see it does not fetch its stylesheet. */
	if ( 'index.php' === $hook_suffix ) {
		if ( gwc_vt_can_see_dashboard_widget() ) {
			wp_enqueue_style(
				'gwc-vt-widget',
				GWC_VT_URL . 'assets/css/widget.css',
				array(),
				GWC_VT_VERSION
			);
		}

		return;
	}

	if ( ! gwc_vt_is_plugin_screen() ) {
		return;
	}

	wp_enqueue_style(
		'gwc-vt-admin',
		GWC_VT_URL . 'assets/css/admin.css',
		array(),
		GWC_VT_VERSION
	);

	$screen = get_current_screen();

	// The logo chooser, on the settings screen only.
	if ( $screen && false !== strpos( (string) $screen->id, GWC_VT_SETTINGS_PAGE ) ) {
		wp_enqueue_media();
		wp_enqueue_script(
			'gwc-vt-admin-media',
			GWC_VT_URL . 'assets/js/admin-media.js',
			array(),
			GWC_VT_VERSION,
			true
		);
	}

	/* "Log a day" on the Hours list, moved up beside the heading. Enqueued
	 * above the early return below, because that return covers the four screens
	 * the picker serves and the entries LIST is not one of them — it is the
	 * editor that is. */
	if ( 'edit' === (string) ( $screen->base ?? '' ) && GWC_VT_ENTRY_TYPE === (string) ( $screen->post_type ?? '' ) ) {
		wp_enqueue_script(
			'gwc-vt-admin-title-actions',
			GWC_VT_URL . 'assets/js/admin-title-actions.js',
			array(),
			GWC_VT_VERSION,
			true
		);
	}

	/* The letters box on a volunteer's record. Enhancement only, and enqueued
	 * above the early return below because the volunteer editor is not one of the
	 * screens the picker serves. No wp_set_script_translations() call, and none
	 * needed: the script has no strings — every word it shows is markup PHP
	 * already rendered, and it only shows and hides it. */
	if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
		&& $screen
		&& GWC_VT_VOLUNTEER_TYPE === (string) ( $screen->post_type ?? '' )
		&& gwc_vt_letters_enabled()
		&& current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		wp_enqueue_script(
			'gwc-vt-admin-letters-box',
			GWC_VT_URL . 'assets/js/admin-letters-box.js',
			array(),
			GWC_VT_VERSION,
			true
		);
	}

	/* The picker appears wherever somebody has to name a volunteer: the entry
	 * editor, the produce-a-letter screen, the log-a-day rows, the schedule's
	 * roster box and the drawer. One script serves all of them — it binds to
	 * every [data-gwcvt-picker] on the page rather than to a known ID, so a
	 * further caller costs nothing.
	 *
	 * The Letters screen is on this list for its own reason: it is the records
	 * log now and has no picker, but it does show the issued table, and leaving
	 * the handle enqueued there costs one HTTP request against the chance of a
	 * later panel needing it. Dropping it is a one-line change if that stops
	 * being true. */
	$on_entry_editor = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
		&& $screen
		&& GWC_VT_ENTRY_TYPE === $screen->post_type;

	$on_letters   = $screen && false !== strpos( (string) $screen->id, GWC_VT_LETTERS_PAGE );
	$on_produce   = $screen && false !== strpos( (string) $screen->id, GWC_VT_PRODUCE_PAGE );
	$on_quick_add = $screen && false !== strpos( (string) $screen->id, GWC_VT_QUICK_ADD_PAGE );
	$on_schedule  = $screen && false !== strpos( (string) $screen->id, GWC_VT_SCHEDULE_PAGE );

	if ( ! $on_entry_editor && ! $on_letters && ! $on_produce && ! $on_quick_add && ! $on_schedule ) {
		return;
	}

	/* wp-api-fetch carries the X-WP-Nonce middleware, which is the ergonomic
	 * half of why the lookup is a REST route rather than an admin-ajax action:
	 * nothing here has to ship a nonce to the browser or remember to send it. */
	wp_enqueue_script(
		'gwc-vt-admin-picker',
		GWC_VT_URL . 'assets/js/admin-picker.js',
		array( 'wp-api-fetch' ),
		GWC_VT_VERSION,
		true
	);

	/* ── Taking the reader to the answer they asked for ──────────────────────
	 * "Preview the letter" reloads this screen with the preview UNDER the form,
	 * and on a laptop the form, the readiness note and the button fill the
	 * viewport — so pressing it looks like a repaint and nothing else, which is
	 * how it was reported.
	 *
	 * Enhancement, not carriage: the preview is rendered by PHP and is on the
	 * page either way. This only moves the viewport to it, and only when there
	 * is one, so a reader with JavaScript off scrolls instead. It follows the
	 * reader's own reduced-motion setting rather than always animating.
	 * ─────────────────────────────────────────────────────────────────────── */
	if ( $on_produce ) {
		wp_add_inline_script(
			'gwc-vt-admin-picker',
			'( function () {' .
				'var preview = document.querySelector( ".gwcvt-preview, .gwcvt-letters-main .notice-warning" );' .
				'if ( ! preview || ! window.location.search.match( /[?&]volunteer=\\d/ ) ) { return; }' .
				'var still = window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;' .
				'preview.scrollIntoView( { block: "start", behavior: still ? "auto" : "smooth" } );' .
			'}() );'
		);
	}

	if ( $on_quick_add ) {
		wp_enqueue_script(
			'gwc-vt-quick-add',
			GWC_VT_URL . 'assets/js/admin-quick-add.js',
			array( 'gwc-vt-admin-picker' ),
			GWC_VT_VERSION,
			true
		);
	}

	/* The event grid's "add another" buttons. Enhancement only — the screen
	 * renders one spare role and one spare time without it, and a save ignores
	 * anything blank, so the grid is buildable with this script absent. */
	if ( $on_schedule ) {
		wp_enqueue_script(
			'gwc-vt-event-grid',
			GWC_VT_URL . 'assets/js/admin-event-grid.js',
			array(),
			GWC_VT_VERSION,
			true
		);

		/* The repeat preview on the add-a-shift form. wp-api-fetch for the same
		 * reason the picker takes it: it carries the X-WP-Nonce middleware, so
		 * nothing here has to ship a nonce to the browser or remember to send
		 * it. No strings of its own — every word it prints comes back from the
		 * route already translated, which is why there is no
		 * wp_set_script_translations() call for this handle. */
		wp_enqueue_script(
			'gwc-vt-shift-repeat',
			GWC_VT_URL . 'assets/js/admin-shift-repeat.js',
			array( 'wp-api-fetch' ),
			GWC_VT_VERSION,
			true
		);

		/* The shift drawer. Depends on the picker as well as wp-api-fetch,
		 * because the panel it injects carries one and tells it to bind — a
		 * dependency in the ordering sense rather than an API one, which is
		 * exactly what wp_enqueue_script's third argument is for.
		 *
		 * No strings of its own: every word it shows comes back from the route
		 * already translated, which is why there is no
		 * wp_set_script_translations() call for this handle either. */
		wp_enqueue_script(
			'gwc-vt-shift-drawer',
			GWC_VT_URL . 'assets/js/admin-shift-drawer.js',
			array( 'wp-api-fetch', 'gwc-vt-admin-picker' ),
			GWC_VT_VERSION,
			true
		);
	}

	wp_set_script_translations(
		'gwc-vt-admin-picker',
		'groundwork-common-volunteer-tracker',
		GWC_VT_DIR . 'languages'
	);
}

<?php
/**
 * One page carrying everything the Help tabs say.
 *
 * ── Why a page as well as the tabs, and not instead of them ──────────────────
 * inc/admin-help.php argues for the Help tab, and the argument is right as far
 * as it goes: it is where WordPress has trained people to look, and it costs
 * nobody anything until they go looking.
 *
 * It assumes somebody knows the Help tab exists. Plenty do not. It is collapsed
 * by default, it sits in a strip most people read as decoration, and a
 * coordinator who has never opened one on any WordPress screen will not start
 * here. "Where is the help?" is a question this plugin should not need somebody
 * to already know the answer to.
 *
 * So both. The tab stays, because contextual help belongs beside the thing it
 * explains. The page exists because a menu item is findable, and because
 * somebody reading before they start has nowhere to sit down otherwise.
 *
 * ── Why it is not a rendering of the Help tabs ──────────────────────────────
 * It was, briefly, and that was the wrong document. The tabs answer "what does
 * this mean" — what verifying IS, why a letter cannot be emailed. Conceptual,
 * read once, and rightly beside the thing they explain. Somebody who has never
 * opened the plugin needs "how do I", in order, with the steps numbered, and
 * cannot get that from thirteen conceptual tabs however well written.
 *
 * So the tabs stay where they are and this carries its own document, written to
 * the Microsoft Writing Style Guide. inc/help-content.php holds it as data and
 * explains the conventions; this file is the loop that prints it.
 *
 * The two are not duplicates and must not become each other: if a how-to here
 * starts explaining what a credential IS, it belongs in the tab, and if a tab
 * starts listing steps, they belong here.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_help_page', 90 );

/** Where the help lives. */
const GWC_VT_HELP_PAGE = 'gwc-vt-help';

/**
 * Every screen with help, and what to call it here.
 *
 * One definition. The page renders from it and tests/integration/help.php
 * checks against it, so a screen added to the plugin and not to this list is a
 * screen the guard stops guarding — which is the failure that put six screens
 * without help into a release in the first place.
 *
 * @return array<string, string> Label => WP_Screen id.
 */
function gwc_vt_help_screens(): array {
	$page = 'volunteer-tracker_page_';

	$screens = array(
		__( 'The dashboard', 'groundwork-common-volunteer-tracker' )        => $page . GWC_VT_DASHBOARD_PAGE,
		__( 'The schedule', 'groundwork-common-volunteer-tracker' )         => $page . GWC_VT_SCHEDULE_PAGE,
		__( 'Hours', 'groundwork-common-volunteer-tracker' )                => 'edit-' . GWC_VT_ENTRY_TYPE,
		__( 'Logging a day', 'groundwork-common-volunteer-tracker' )        => $page . GWC_VT_QUICK_ADD_PAGE,
		__( 'Verifying hours', 'groundwork-common-volunteer-tracker' )      => $page . GWC_VT_VERIFY_PAGE,
		__( 'Volunteers', 'groundwork-common-volunteer-tracker' )           => 'edit-' . GWC_VT_VOLUNTEER_TYPE,
		__( 'One volunteer’s record', 'groundwork-common-volunteer-tracker' ) => GWC_VT_VOLUNTEER_TYPE,
		__( 'Credentials', 'groundwork-common-volunteer-tracker' )          => $page . GWC_VT_CREDENTIALS_PAGE,
		__( 'Offers to volunteer', 'groundwork-common-volunteer-tracker' )  => $page . GWC_VT_APPLICATIONS_PAGE,
		__( 'Changing a whole repeat', 'groundwork-common-volunteer-tracker' ) => $page . GWC_VT_REPEAT_PAGE,
		__( 'Settings', 'groundwork-common-volunteer-tracker' )             => $page . GWC_VT_SETTINGS_PAGE,
	);

	/* The two letter screens only when the organization issues letters — their
	 * help is gated the same way, so listing them unconditionally would print
	 * two empty headings on a site that does not. */
	if ( gwc_vt_letters_enabled() ) {
		$screens[ __( 'Producing a letter', 'groundwork-common-volunteer-tracker' ) ] = $page . GWC_VT_PRODUCE_PAGE;
		$screens[ __( 'Letters issued', 'groundwork-common-volunteer-tracker' ) ]     = $page . GWC_VT_LETTERS_PAGE;
	}

	return $screens;
}

/**
 * What one screen's Help tab would say, without disturbing this one.
 *
 * WP_Screen::get() builds the object; set_current_screen() would have made it
 * current, replacing the screen being rendered. The tabs are cleared first
 * because WP_Screen::get() returns a cached instance for an id already seen,
 * which would otherwise hand back tabs added on a previous pass and print them
 * twice.
 *
 * @param string $screen_id A WP_Screen id.
 * @return array<int, array> Tabs, in the order the screen adds them.
 */
function gwc_vt_help_tabs_for_screen( string $screen_id ): array {
	if ( ! class_exists( 'WP_Screen' ) ) {
		return array();
	}

	$screen = WP_Screen::get( $screen_id );

	foreach ( array_keys( $screen->get_help_tabs() ) as $existing ) {
		$screen->remove_help_tab( $existing );
	}

	/* The plugin's own routing, not a second copy of it: whatever a screen gets
	 * contextually is exactly what appears here. */
	gwc_vt_add_screen_help( $screen );

	/* Settings adds its help from its own load- hook rather than from
	 * current_screen. Two routes, and this page has to follow both or it prints
	 * a heading with nothing under it. */
	if ( false !== strpos( $screen_id, GWC_VT_SETTINGS_PAGE ) ) {
		gwc_vt_add_settings_help( $screen );
	}

	return array_values( $screen->get_help_tabs() );
}

/**
 * Add the page.
 */
function gwc_vt_register_help_page(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_help_page_title(),
		gwc_vt_help_page_title(),
		gwc_vt_records_cap(),
		GWC_VT_HELP_PAGE,
		'gwc_vt_render_help_page'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_help_title' );
	}
}

/**
 * The screen's name.
 *
 * @return string
 */
function gwc_vt_help_page_title(): string {
	return __( 'Help', 'groundwork-common-volunteer-tracker' );
}

/**
 * Put the title back after the menu is reordered.
 */
function gwc_vt_restore_help_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_help_page_title();
}

/**
 * The page.
 */
function gwc_vt_render_help_page(): void {
	if ( ! current_user_can( 'read' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$topics = gwc_vt_help_topics();
	?>
	<div class="wrap gwcvt-wrap gwcvt-help">
		<h1><?php echo esc_html( gwc_vt_help_page_title() ); ?></h1>

		<p class="description gwcvt-help__lede">
			<?php esc_html_e( 'How to do the things this plugin is for. Every screen also has a Help tab at the top right, which explains what the screen means rather than how to use it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<ul class="gwcvt-help__contents">
			<?php foreach ( $topics as $topic ) : ?>
				<li><a href="#gwcvt-help-<?php echo esc_attr( $topic['id'] ); ?>"><?php echo esc_html( $topic['title'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>

		<?php foreach ( $topics as $topic ) : ?>
			<h2 id="gwcvt-help-<?php echo esc_attr( $topic['id'] ); ?>"><?php echo esc_html( $topic['title'] ); ?></h2>

			<?php if ( '' !== (string) ( $topic['intro'] ?? '' ) ) : ?>
				<p class="gwcvt-help__intro"><?php echo esc_html( (string) $topic['intro'] ); ?></p>
			<?php endif; ?>

			<?php foreach ( (array) ( $topic['tasks'] ?? array() ) as $task ) : ?>
				<div class="gwcvt-help__task">
					<h3><?php echo esc_html( (string) ( $task['title'] ?? '' ) ); ?></h3>

					<ol>
						<?php foreach ( (array) ( $task['steps'] ?? array() ) as $step ) : ?>
							<li>
								<?php
								/* Bold marks the words that appear on screen, which is
								 * the one piece of markup a step needs and the reason
								 * these are not plain strings. */
								echo wp_kses(
									(string) $step,
									array(
										'strong' => array(),
										'em'     => array(),
										'code'   => array(),
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ol>

					<?php if ( '' !== (string) ( $task['note'] ?? '' ) ) : ?>
						<p class="gwcvt-help__note"><?php echo esc_html( (string) $task['note'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * A stable anchor for one screen's section.
 *
 * Kept for tests/integration/help.php, which still walks every screen to check
 * each one has a Help tab — that guard outlived the page rendering from it.
 *
 * @param string $screen_id A WP_Screen id.
 * @return string
 */
function gwc_vt_help_anchor( string $screen_id ): string {
	return 'gwcvt-help-' . sanitize_html_class( $screen_id );
}

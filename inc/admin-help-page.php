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
 * ── Why `read` and not the capability every other screen uses ────────────────
 * Every screen in this plugin is behind gwc_vt_records_cap(), because every one
 * of them shows somebody else's record. This page shows none. Not one word here
 * is site data: the tabs are static strings that describe how the plugin works,
 * with nothing interpolated into them — no name, no total, no setting.
 *
 * Gating documentation on the capability to act would mean somebody deciding
 * whether to ask for access cannot read what they would be asking for, and
 * somebody who has just lost access cannot read why the screens went. Neither
 * is worth protecting a page that says "verifying means a member of staff said
 * the work happened".
 *
 * `read` is what WordPress gives everybody who can log in at all, subscribers
 * included. On a site where volunteers have accounts for something else
 * entirely, they can read this and reach nothing it describes.
 *
 * ── Why this is not a copy of the text ───────────────────────────────────────
 * Not one word of help lives in this file. It asks each screen what its Help
 * tab would say and prints the answers — the same functions, the same routing,
 * the same strings. Two copies of thirty-three tabs would drift within a
 * release, and the one nobody edited would be the one somebody read.
 *
 * The mechanism is WP_Screen::get(), which builds a screen object WITHOUT
 * making it current. set_current_screen() would have been simpler and would
 * have replaced the screen this page is being rendered on halfway through
 * rendering it.
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
		'read',
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

	$screens = gwc_vt_help_screens();
	?>
	<div class="wrap gwcvt-wrap gwcvt-help">
		<h1><?php echo esc_html( gwc_vt_help_page_title() ); ?></h1>

		<p class="description">
			<?php esc_html_e( 'Everything here also appears in the Help tab at the top right of the screen it is about, where it sits beside the thing it explains. This is the same text in one place, for reading before you start.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php
		/* A list of links to the sections below. On a page this long the
		 * alternative is scrolling past nine screens to reach the tenth. */
		?>
		<ul class="gwcvt-help__contents">
			<?php foreach ( $screens as $label => $screen_id ) : ?>
				<li><a href="#<?php echo esc_attr( gwc_vt_help_anchor( (string) $screen_id ) ); ?>"><?php echo esc_html( $label ); ?></a></li>
			<?php endforeach; ?>
		</ul>

		<?php foreach ( $screens as $label => $screen_id ) : ?>
			<?php $tabs = gwc_vt_help_tabs_for_screen( (string) $screen_id ); ?>
			<?php if ( ! $tabs ) : ?>
				<?php continue; ?>
			<?php endif; ?>

			<h2 id="<?php echo esc_attr( gwc_vt_help_anchor( (string) $screen_id ) ); ?>"><?php echo esc_html( $label ); ?></h2>

			<?php foreach ( $tabs as $tab ) : ?>
				<h3><?php echo esc_html( (string) ( $tab['title'] ?? '' ) ); ?></h3>

				<?php
				/* The tab's own body, already assembled and escaped by
				 * gwc_vt_add_help_tab() — which wp_kses()es every paragraph to
				 * strong, em and code before it ever reaches a screen. */
				echo wp_kses(
					(string) ( $tab['content'] ?? '' ),
					array(
						'p'      => array(),
						'strong' => array(),
						'em'     => array(),
						'code'   => array(),
					)
				);
				?>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * A stable anchor for one screen's section.
 *
 * @param string $screen_id A WP_Screen id.
 * @return string
 */
function gwc_vt_help_anchor( string $screen_id ): string {
	return 'gwcvt-help-' . sanitize_html_class( $screen_id );
}

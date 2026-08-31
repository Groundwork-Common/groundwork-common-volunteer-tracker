<?php
/**
 * One page carrying the how-to guide.
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
 * of them shows somebody else's record. This page shows none — it is a guide to
 * the software, with no site data in it at all.
 *
 * Gating documentation on the capability to act would mean somebody deciding
 * whether to ask for access cannot read what they would be asking for, and
 * somebody who has just lost access cannot read why the screens went.
 *
 * `read` is what WordPress gives everybody who can log in at all, subscribers
 * included.
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


/**
 * Every screen that must have a Help tab, and what to call it when one does not.
 *
 * The page below renders the how-to guide and not this — the list survived that
 * change because it is what tests/integration/help.php walks. It is the one
 * place a screen is named, so a screen added to the plugin and not to this list
 * is a screen the guard stops guarding, which is the failure that put six
 * screens without help into a release in the first place.
 *
 * It lives here rather than in the test because a list in tests/ is a list that
 * can quietly disagree with the plugin; and the labels are translated because
 * they are what a failure reads out.
 *
 * A screen only registered when a feature is switched on is listed only then,
 * for the same reason: the guard would otherwise fail over help for a screen
 * that is not there to have any.
 *
 * @return array<string, string> Label => WP_Screen id.
 */
function gwc_vt_help_screens(): array {
	$page = 'volunteer-tracker_page_';

	$screens = array(
		__( 'The dashboard', 'groundwork-common-volunteer-tracker' )        => $page . GWC_VT_DASHBOARD_PAGE,
		__( 'Hours', 'groundwork-common-volunteer-tracker' )                => 'edit-' . GWC_VT_ENTRY_TYPE,
		__( 'Logging a day', 'groundwork-common-volunteer-tracker' )        => $page . GWC_VT_QUICK_ADD_PAGE,
		__( 'Verifying hours', 'groundwork-common-volunteer-tracker' )      => $page . GWC_VT_VERIFY_PAGE,
		__( 'Volunteers', 'groundwork-common-volunteer-tracker' )           => 'edit-' . GWC_VT_VOLUNTEER_TYPE,
		__( 'One volunteer’s record', 'groundwork-common-volunteer-tracker' ) => GWC_VT_VOLUNTEER_TYPE,
		__( 'Credentials', 'groundwork-common-volunteer-tracker' )          => $page . GWC_VT_CREDENTIALS_PAGE,
		__( 'Organizations', 'groundwork-common-volunteer-tracker' )        => $page . GWC_VT_PARTNERS_PAGE,
		__( 'Applications', 'groundwork-common-volunteer-tracker' )         => $page . GWC_VT_APPLICATIONS_PAGE,
		__( 'Changing a whole repeat', 'groundwork-common-volunteer-tracker' ) => $page . GWC_VT_REPEAT_PAGE,
		__( 'Settings', 'groundwork-common-volunteer-tracker' )             => $page . GWC_VT_SETTINGS_PAGE,
	);

	/* The schedule only when the organization runs shifts. The screen itself is
	 * registered under that switch, so on a site without it there is nothing to
	 * hold a Help tab and the guard would be asking after help for a screen that
	 * does not exist. The repeat editor is listed unconditionally above because
	 * it is registered unconditionally.
	 *
	 * Added after the dashboard rather than beside it: order here is only what a
	 * failure reads out, and a conditional entry cannot be spelled in the middle
	 * of an array literal. */
	if ( gwc_vt_shifts_enabled() ) {
		$screens[ __( 'The schedule', 'groundwork-common-volunteer-tracker' ) ] = $page . GWC_VT_SCHEDULE_PAGE;
	}

	/* Only when the organization issues letters — the help is gated the same
	 * way, so listing it unconditionally would ask after help that a site
	 * without letters has no screen to carry.
	 *
	 * One entry, not two. Producing a letter had a screen of its own and it is
	 * gone: letters are written in a box on the volunteer's record, and a
	 * volunteer's record is not somewhere this list can send anybody — it needs
	 * a volunteer, and which one is exactly the question the old screen asked
	 * and got wrong. The log is a real destination and the guide covers the
	 * writing. */
	if ( gwc_vt_letters_enabled() ) {
		$screens[ __( 'Verification letters', 'groundwork-common-volunteer-tracker' ) ] = $page . GWC_VT_LETTERS_PAGE;
	}

	return $screens;
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

	$topics  = gwc_vt_help_topics();
	$current = gwc_vt_current_help_topic();
	?>
	<div class="wrap gwcvt-wrap gwcvt-help">
		<h1 class="wp-heading-inline"><?php echo esc_html( gwc_vt_help_page_title() ); ?></h1>
		<hr class="wp-header-end" />

		<p class="description gwcvt-help__lede">
			<?php esc_html_e( 'How to do the things this plugin is for. Every screen also has a Help tab at the top right, which explains what the screen means rather than how to use it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php
		/* The same tab bar the Settings screen uses. One topic at a time,
		 * because twenty how-tos and ninety-two steps in a single scroll is a
		 * document nobody reads twice — and because a tab is a real URL, so a
		 * topic can be linked to, bookmarked, and reached with the back button.
		 *
		 * No JavaScript. A reader with it switched off gets the same guide. */
		?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Help topics', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php foreach ( $topics as $topic ) : ?>
				<a
					class="nav-tab <?php echo $topic['id'] === $current ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( gwc_vt_help_page_url( (string) $topic['id'] ) ); ?>"
					<?php echo $topic['id'] === $current ? 'aria-current="page"' : ''; ?>
				>
					<?php echo esc_html( $topic['title'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php foreach ( $topics as $topic ) : ?>
			<?php if ( $topic['id'] !== $current ) : ?>
				<?php continue; ?>
			<?php endif; ?>

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

			<?php
			/* A topic may end in a reference rather than in more how-tos. One
			 * does: every setting, read out of the registry that draws the
			 * settings form — see gwc_vt_help_settings_reference(). */
			?>
			<?php if ( ! empty( $topic['reference'] ) ) : ?>
				<?php gwc_vt_render_help_settings_reference(); ?>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Where the how-to guide lives.
 *
 * @param string $topic Which topic to open, or '' for the first.
 * @return string
 */
function gwc_vt_help_page_url( string $topic = '' ): string {
	$args = array(
		'post_type' => GWC_VT_ENTRY_TYPE,
		'page'      => GWC_VT_HELP_PAGE,
	);

	if ( '' !== $topic ) {
		$args['topic'] = $topic;
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * Which topic is being read.
 *
 * Falls back to the first rather than to nothing, so a bookmark pointing at a
 * topic that has since been renamed lands on the guide instead of on an empty
 * page — the same shape as gwc_vt_current_tab() on the settings screen.
 *
 * @return string
 */
function gwc_vt_current_help_topic(): string {
	$topics = array();

	foreach ( gwc_vt_help_topics() as $topic ) {
		$topics[] = (string) $topic['id'];
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from this value.
	$wanted = isset( $_GET['topic'] ) ? sanitize_key( wp_unslash( $_GET['topic'] ) ) : '';

	return in_array( $wanted, $topics, true ) ? $wanted : (string) ( $topics[0] ?? '' );
}

/**
 * The topic that answers the screen somebody is on.
 *
 * So the link in a Help tab lands on the four how-tos for credentials rather
 * than at the top of a guide with ninety-two steps in it. A screen with no
 * obvious topic gets the guide's front, which is the setting-up one.
 *
 * @param string $screen_id A WP_Screen id.
 * @return string A topic id, or '' for the guide's front.
 */
function gwc_vt_help_topic_for_screen( string $screen_id ): string {
	$map = array(
		GWC_VT_CREDENTIALS_PAGE  => 'credentials',
		GWC_VT_PARTNERS_PAGE     => 'partners',
		GWC_VT_APPLICATIONS_PAGE => 'public',
		GWC_VT_SCHEDULE_PAGE     => 'schedule',
		GWC_VT_REPEAT_PAGE       => 'schedule',
		GWC_VT_VERIFY_PAGE       => 'hours',
		GWC_VT_QUICK_ADD_PAGE    => 'hours',
		GWC_VT_LETTERS_PAGE      => 'letters',
		GWC_VT_SETTINGS_PAGE     => 'settings',
	);

	foreach ( $map as $page => $topic ) {
		if ( false !== strpos( $screen_id, (string) $page ) ) {
			return $topic;
		}
	}

	/* The list tables and one volunteer's record, which are named rather than
	 * matched on a page slug. */
	if ( 'edit-' . GWC_VT_ENTRY_TYPE === $screen_id || GWC_VT_VOLUNTEER_TYPE === $screen_id || 'edit-' . GWC_VT_VOLUNTEER_TYPE === $screen_id ) {
		return 'hours';
	}

	return '';
}

/**
 * Every setting, tab by tab.
 *
 * A definition list rather than a table: the label is a term and its help is
 * the definition, that is what a reader is doing here, and it reflows on a
 * narrow screen where a two-column table does not.
 */
function gwc_vt_render_help_settings_reference(): void {
	?>
	<div class="gwcvt-help__reference">
		<h3><?php esc_html_e( 'What every setting does', 'groundwork-common-volunteer-tracker' ); ?></h3>

		<?php foreach ( gwc_vt_help_settings_reference() as $tab ) : ?>
			<div class="gwcvt-help__tab">
				<h4><?php echo esc_html( (string) $tab['tab'] ); ?></h4>

				<?php if ( '' !== (string) ( $tab['note'] ?? '' ) ) : ?>
					<p class="gwcvt-help__note"><?php echo esc_html( (string) $tab['note'] ); ?></p>
				<?php endif; ?>

				<?php foreach ( (array) $tab['sections'] as $section ) : ?>
					<h5><?php echo esc_html( (string) $section['section'] ); ?></h5>

					<dl class="gwcvt-help__settings">
						<?php foreach ( (array) $section['fields'] as $field ) : ?>
							<dt><?php echo esc_html( (string) $field['label'] ); ?></dt>
							<dd>
								<?php
								echo '' !== (string) $field['help']
									? esc_html( (string) $field['help'] )
									: esc_html__( 'The label says it.', 'groundwork-common-volunteer-tracker' );
								?>
							</dd>
						<?php endforeach; ?>
					</dl>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

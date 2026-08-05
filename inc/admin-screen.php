<?php
/**
 * The settings screen.
 *
 * Currently the colophon, the footer line, and the screen test both of them
 * scope themselves with. The tab shell, the Fields editor and the Letters
 * screen land on top of this file in the milestones that need them;
 * gwcvt_render_colophon() is called by that shell when it arrives.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* The settings screen's slug, and the parent every submenu hangs off. Named
 * here because the footer filter, the tab shell and the help tabs all have to
 * agree about what "our screen" means, and three copies of a string is three
 * chances for two of them to agree while the third drifts. */
const GWCVT_MENU_SLUG     = 'edit.php?post_type=gwcvt_entry';
const GWCVT_SETTINGS_PAGE = 'gwcvt-settings';
const GWCVT_LETTERS_PAGE  = 'gwcvt-letters';
const GWCVT_QUICK_ADD_PAGE = 'gwcvt-log-a-day';
const GWCVT_SCHEDULE_PAGE  = 'gwcvt-schedule';

add_filter( 'admin_footer_text', 'gwcvt_admin_footer_text' );
add_action( 'admin_init', 'gwcvt_handle_colophon_toggle' );
add_action( 'admin_menu', 'gwcvt_register_menu' );

/**
 * Hang the settings screen off the Volunteer Hours menu.
 *
 * A submenu of the post type rather than a top-level menu of its own. The post
 * type already owns a menu; a second top-level entry for the same plugin is the
 * thing that makes an admin sidebar unreadable one plugin at a time.
 */
function gwcvt_register_menu(): void {
	$hook = add_submenu_page(
		GWCVT_MENU_SLUG,
		__( 'Volunteer Tracker Settings', 'groundwork-common-volunteer-tracker' ),
		__( 'Settings', 'groundwork-common-volunteer-tracker' ),
		gwcvt_cap( 'manage' ),
		GWCVT_SETTINGS_PAGE,
		'gwcvt_render_settings_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwcvt_settings_screen_loaded' );
	}
}

/**
 * Runs when the settings screen is opened, before anything is rendered.
 *
 * Contextual help lands here in a later milestone; it is a hook rather than
 * inline in the renderer because help tabs must be added before the page starts
 * outputting, and doing it from the renderer is the mistake that makes them
 * silently not appear.
 */
function gwcvt_settings_screen_loaded(): void {
	/**
	 * Fires when the Volunteer Tracker settings screen loads.
	 */
	do_action( 'gwcvt_settings_screen_loaded' );
}

/**
 * The tabs, and what each is for.
 *
 * @return array<string, string>
 */
function gwcvt_admin_tabs(): array {
	$tabs = array(
		'letter'  => __( 'Letter', 'groundwork-common-volunteer-tracker' ),
		'logging' => __( 'Logging', 'groundwork-common-volunteer-tracker' ),
		'shifts'  => __( 'Shifts', 'groundwork-common-volunteer-tracker' ),
		'privacy' => __( 'Privacy', 'groundwork-common-volunteer-tracker' ),
	);

	/**
	 * The settings screen's tabs, keyed by slug.
	 *
	 * @param array<string, string> $tabs Slug => label.
	 */
	return (array) apply_filters( 'gwcvt_admin_tabs', $tabs );
}

/**
 * Which tab is being viewed.
 *
 * @return string
 */
function gwcvt_current_tab(): string {
	$tabs = gwcvt_admin_tabs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from this value.
	$wanted = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	return isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
}

/**
 * The settings screen.
 */
function gwcvt_render_settings_screen(): void {
	gwcvt_require_cap( 'manage' );

	$tabs    = gwcvt_admin_tabs();
	$current = gwcvt_current_tab();
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php gwcvt_render_colophon(); ?>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings sections', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( gwcvt_settings_url( $slug ) ); ?>"
					<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
				>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		/* Each tab's form arrives with the milestone that needs it: the letter
		 * template, the self-log form, and retention. Until then the screen is
		 * honest about being unfinished rather than showing controls that do
		 * nothing. */
		?>
		<div class="gwcvt-tab-body">
			<?php
			/**
			 * Render the body of a settings tab.
			 *
			 * @param string $current The tab being viewed.
			 */
			do_action( 'gwcvt_render_tab_' . $current );

			if ( ! has_action( 'gwcvt_render_tab_' . $current ) ) :
				?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %s: the name of a settings tab. */
							esc_html__( 'The %s settings are not built yet. Hours logging works without them.', 'groundwork-common-volunteer-tracker' ),
							'<strong>' . esc_html( $tabs[ $current ] ) . '</strong>'
						);
						?>
					</p>
				</div>
				<?php
			endif;
			?>
		</div>
	</div>
	<?php
}

/**
 * A URL for one tab of the settings screen.
 *
 * @param string $tab Tab slug.
 * @return string
 */
function gwcvt_settings_url( string $tab = '' ): string {
	$args = array( 'page' => GWCVT_SETTINGS_PAGE );

	if ( '' !== $tab ) {
		$args['tab'] = $tab;
	}

	return add_query_arg( $args, admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE ) );
}

/**
 * Is the screen being rendered one of this plugin's?
 *
 * Matched on the `gwcvt` prefix rather than against a list of screen IDs. The
 * sibling plugins name their screens explicitly and that is the more careful
 * habit in general — but here every post type this plugin registers is
 * `gwcvt_*` and every admin page is `gwcvt-*`, so the prefix IS the list, and a
 * list would be a second copy of it that a later post type could be added
 * without. Nothing outside this plugin has a screen ID containing this string.
 *
 * @return bool
 */
function gwcvt_is_plugin_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return false;
	}

	return 0 === strpos( (string) $screen->post_type, 'gwcvt_' )
		|| false !== strpos( (string) $screen->id, 'gwcvt' );
}

/**
 * Replace the admin footer line on this plugin's own screens.
 *
 * Where WordPress already says "Thank you for creating with WordPress" — the
 * bottom of the page, quiet, expected, and out of the way of the work. That is
 * the whole reason it goes here and not above the entries list: somebody
 * working through a queue of unverified shifts is not the person to interrupt,
 * and they would see a panel there dozens of times a week.
 *
 * An unrecognised screen returns the text untouched. Rewriting core's footer on
 * somebody else's page would be exactly the kind of reach the directory
 * guidelines are about.
 *
 * @param string $text The existing footer text.
 * @return string
 */
function gwcvt_admin_footer_text( $text ) {
	if ( ! gwcvt_is_plugin_screen() ) {
		return $text;
	}

	return sprintf(
		/* translators: %s: Groundwork Common, linked to the company site. */
		esc_html__( 'Built by %s — technology leadership and support for nonprofits.', 'groundwork-common-volunteer-tracker' ),
		sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( GWCVT_GWC_URL ),
			esc_html__( 'Groundwork Common', 'groundwork-common-volunteer-tracker' )
		)
	);
}

/* ── Collapsing the colophon ─────────────────────────────────────────────────
 * Collapsible, never dismissible. Somebody who has opened the settings screen
 * has chosen to be here, and a panel that can be dismissed forever is one that
 * gets dismissed in the first week and never informs anybody of anything again.
 * Thirty days is long enough that it is not nagging and short enough that it is
 * still a colophon.
 *
 * Stored per user rather than per site. One administrator collapsing it should
 * not decide for their colleagues, and user meta is where a personal display
 * preference belongs.
 *
 * The stored value is WHEN it was collapsed, not THAT it was — which is what
 * makes the thirty days fall out of a comparison instead of needing a scheduled
 * event to come round and clear a flag.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCVT_COLOPHON_META   = 'gwcvt_colophon_collapsed_at';
const GWCVT_COLOPHON_SNOOZE = 30 * DAY_IN_SECONDS;

/**
 * Is a collapse from $collapsed_at still in force at $now?
 *
 * Split out from the user-meta read so the only part with a decision in it can
 * be tested without WordPress. Zero means never collapsed.
 *
 * @param int $collapsed_at Unix time the user collapsed it, or 0.
 * @param int $now          Unix time now.
 * @return bool
 */
function gwcvt_colophon_snoozed( int $collapsed_at, int $now ): bool {
	if ( $collapsed_at <= 0 ) {
		return false;
	}

	/* A timestamp in the future means a clock changed, a database was moved
	 * between servers, or somebody edited the row. Treating it as "snoozed
	 * until then" could hide the panel for years, so an impossible value snoozes
	 * for nothing. */
	if ( $collapsed_at > $now ) {
		return false;
	}

	return ( $now - $collapsed_at ) < GWCVT_COLOPHON_SNOOZE;
}

/**
 * Whether to render the colophon collapsed for the current user.
 *
 * @return bool
 */
function gwcvt_colophon_is_collapsed(): bool {
	$at = (int) get_user_meta( get_current_user_id(), GWCVT_COLOPHON_META, true );
	return gwcvt_colophon_snoozed( $at, time() );
}

/**
 * Collapse or expand, then send the browser back where it was.
 *
 * A nonced link handled server-side rather than a script and an AJAX route.
 * This runs at most twice a month per person, so a page load costs nothing —
 * and the alternative would add an endpoint, a nonce to ship to the browser and
 * a script, all to avoid a reload nobody will notice.
 */
function gwcvt_handle_colophon_toggle(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified below before anything is written.
	if ( ! isset( $_GET['gwcvt_colophon'] ) ) {
		return;
	}

	if ( ! current_user_can( gwcvt_cap( 'manage' ) ) ) {
		return;
	}

	check_admin_referer( 'gwcvt_colophon' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
	$wanted = sanitize_key( wp_unslash( $_GET['gwcvt_colophon'] ) );

	if ( 'collapse' === $wanted ) {
		update_user_meta( get_current_user_id(), GWCVT_COLOPHON_META, time() );
	} else {
		delete_user_meta( get_current_user_id(), GWCVT_COLOPHON_META );
	}

	/* Back to the same tab, minus the toggle. Without stripping the arguments a
	 * refresh would re-fire the toggle, and the nonce in the URL would outlive
	 * its usefulness in the address bar. */
	wp_safe_redirect( remove_query_arg( array( 'gwcvt_colophon', '_wpnonce' ) ) );
	exit;
}

/**
 * The collapse/expand link, nonced.
 *
 * @param string $action 'collapse' or 'expand'.
 * @return string
 */
function gwcvt_colophon_toggle_url( string $action ): string {
	return wp_nonce_url( add_query_arg( 'gwcvt_colophon', $action ), 'gwcvt_colophon' );
}

/**
 * Who made this, and the one thing worth asking of someone using it.
 *
 * Between the page title and the tab bar, and on the settings screen only.
 * Above the tabs because it introduces the whole screen rather than any one
 * section of it, and because a colophon below five tabs of settings is one
 * nobody reaches.
 *
 * Still not a notice. A plugin that interrupts an unrelated admin page to talk
 * about its author is the behaviour the directory guidelines exist to stop, and
 * it earns the dismissal it gets. Somebody who has opened this screen has
 * chosen to be here; that is the whole difference.
 *
 * Two asks, in the order they are actually worth: a referral, then ongoing
 * support for the work itself.
 *
 * Neither is called a donation. Groundwork Common is a services practice, not
 * a charity, so "donate" would imply a tax status that does not exist — and
 * asking a nonprofit to donate to its vendor points the arrow the wrong way.
 * Sponsorship is the honest word for paying to keep freely released software
 * maintained, and it describes the exchange accurately: the money buys
 * continued work, not goodwill.
 */
function gwcvt_render_colophon(): void {
	?>
	<?php if ( gwcvt_colophon_is_collapsed() ) : ?>
		<div class="gwcvt-colophon gwcvt-colophon--collapsed">
			<span class="gwcvt-colophon__logo" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Groundwork Common', 'groundwork-common-volunteer-tracker' ); ?></span>
			<a class="gwcvt-colophon__toggle" href="<?php echo esc_url( gwcvt_colophon_toggle_url( 'expand' ) ); ?>">
				<?php esc_html_e( 'Show', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="gwcvt-colophon">
		<a class="gwcvt-colophon__toggle" href="<?php echo esc_url( gwcvt_colophon_toggle_url( 'collapse' ) ); ?>">
			<?php esc_html_e( 'Hide for 30 days', 'groundwork-common-volunteer-tracker' ); ?>
		</a>

		<div class="gwcvt-colophon__main">
			<h2 class="gwcvt-colophon__brand">
				<?php
				/*
				 * The wordmark carries the name visually and the heading carries
				 * it to everything else. Marked aria-hidden and paired with real
				 * text rather than given alt text, because an <img alt="Groundwork
				 * Common"> immediately after a heading saying the same words is
				 * read out twice.
				 *
				 * Two files, swapped by colour scheme in the stylesheet: the logo
				 * is ink on transparent, so one version or the other disappears
				 * depending on what it is sitting on. Naming is by BACKGROUND,
				 * not by ink — "-light" is the one for light backgrounds.
				 */
				?>
				<a href="<?php echo esc_url( GWCVT_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="screen-reader-text"><?php esc_html_e( 'Groundwork Common', 'groundwork-common-volunteer-tracker' ); ?></span>
					<span class="gwcvt-colophon__logo" aria-hidden="true"></span>
				</a>
			</h2>

			<p>
				<?php
				/* The anchor is built here rather than carried inside the
				 * translatable string, so a translator is never handed markup
				 * they can break and no HTML has to survive a round trip through
				 * translate.wordpress.org. */
				$gwcvt_gwc_link = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( GWCVT_GWC_URL ),
					esc_html__( 'Groundwork Common', 'groundwork-common-volunteer-tracker' )
				);

				printf(
					/* translators: %s: Groundwork Common, linked to the company site. */
					esc_html__( '%s provides technology leadership and support for nonprofits — fractional, by the project, or alongside an in-house team. We release tools like this one because good technology work should leave an organization more capable, not more dependent on whoever built it.', 'groundwork-common-volunteer-tracker' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled directly above from esc_url() and esc_html__().
					$gwcvt_gwc_link
				);
				?>
			</p>

			<p>
				<?php esc_html_e( 'If you find this plugin useful, the most valuable thing you can do for us is mention us to a nonprofit who might benefit from our services. Referrals are how our business continues to grow its impact and reach.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php /* Directly under the referral ask, which is what it answers. */ ?>
			<p>
				<a class="button" href="<?php echo esc_url( GWCVT_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Learn about Groundwork Common', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		</div>

		<?php /* Second column: the two things a reader can act on. */ ?>
		<div class="gwcvt-colophon__aside">
			<?php if ( '' !== GWCVT_SPONSOR_URL ) : ?>
				<p>
					<?php esc_html_e( 'You can also support our WordPress plugins directly. While we offer the plugin free to you, it costs us to maintain it — the security updates, the compatibility testing against each new WordPress release, the bug nobody but you has hit. We can’t do it without your support, and we appreciate whatever support you can give.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( GWCVT_SPONSOR_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Support our work', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p>
				<a href="https://github.com/Groundwork-Common/groundwork-common-volunteer-tracker/issues" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Report a problem', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php
}

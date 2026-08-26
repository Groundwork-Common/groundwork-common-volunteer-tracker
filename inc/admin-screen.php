<?php
/**
 * The settings screen.
 *
 * Currently the colophon, the footer line, and the screen test both of them
 * scope themselves with. The tab shell, the Fields editor and the Letters
 * screen land on top of this file in the milestones that need them;
 * gwc_vt_render_colophon() is called by that shell when it arrives.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* The settings screen's slug, and the parent every submenu hangs off. Named
 * here because the footer filter, the tab shell and the help tabs all have to
 * agree about what "our screen" means, and three copies of a string is three
 * chances for two of them to agree while the third drifts. */
const GWC_VT_MENU_SLUG      = 'edit.php?post_type=gwc_vt_entry';
const GWC_VT_SETTINGS_PAGE  = 'gwc-vt-settings';
const GWC_VT_LETTERS_PAGE   = 'gwc-vt-letters';
const GWC_VT_QUICK_ADD_PAGE = 'gwc-vt-log-a-day';
const GWC_VT_SCHEDULE_PAGE  = 'gwc-vt-schedule';
const GWC_VT_DASHBOARD_PAGE = 'gwc-vt-dashboard';
const GWC_VT_VERIFY_PAGE    = 'gwc-vt-verify';
const GWC_VT_PRODUCE_PAGE   = 'gwc-vt-produce-letter';
const GWC_VT_REPEAT_PAGE    = 'gwc-vt-repeat';

add_filter( 'admin_footer_text', 'gwc_vt_admin_footer_text' );
add_action( 'admin_init', 'gwc_vt_handle_colophon_toggle' );
add_action( 'admin_menu', 'gwc_vt_register_menu' );

/* Priority 98, and 99 for the ordering: both after every screen has registered
 * itself, including any a site has added of its own. Neither can be done at
 * registration time because the two screens WordPress adds for the post type —
 * All hours and Log hours — are not added by this plugin at all.
 *
 * Hiding runs first so that gwc_vt_order_menu() re-keys a menu that is already
 * the right length. Removing afterwards would leave a hole in an array whose
 * keys WordPress reads as positions. */
add_action( 'admin_menu', 'gwc_vt_hide_menu_verbs', 98 );
add_action( 'admin_menu', 'gwc_vt_order_menu', 99 );

/* ── Why the menu is reordered rather than registered in order ───────────────
 * Left alone, this menu came out as: All hours, Log hours, Volunteers,
 * Settings, Letters, Log a day, Schedule.
 *
 * Settings fourth, in the middle of the working screens, because admin-screen
 * .php registers at the default priority and the screens that came later
 * registered at 11, 12 and 13. Nobody chose that order; it is the order the
 * files happen to load in, and it grew one entry at a time over five releases.
 *
 * The order below is the coordinator's week instead: where you start, then what
 * is coming, then who is coming, then what they did, then what gets produced
 * for them, then — last, always — the settings.
 *
 * It is also six entries rather than eight, because two of the originals were
 * verbs. See gwc_vt_hide_menu_verbs() below.
 *
 * add_submenu_page() takes a position argument, which looks like the answer and
 * is not: positions are per-registration integers with no coordination between
 * plugins, WordPress ignores them entirely for post type submenus, and two
 * items claiming the same slot resolve by float-key collision in a way nobody
 * can predict. Rewriting the array once, at the end, is the only method that
 * says what it means.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The order the Volunteer Tracker submenu appears in.
 *
 * By slug, and anything not named here keeps its place at the end — a site that
 * has added its own screen to this menu should not lose it because this list
 * had not heard of it.
 *
 * @return string[]
 */
function gwc_vt_menu_order(): array {
	/* ── The order is a volunteer's life, not a screen's importance ───────────
	 * What is coming, then who is coming, then what they did, then what gets
	 * produced for them. It reads forwards.
	 *
	 * It puts the schedule above the hours list, which means the first item is
	 * not what the top-level "Volunteer Tracker" link opens — that still goes to
	 * All hours. Worth knowing rather than worth avoiding: the top-level link
	 * has a destination either way, and a menu ordered by when things happen is
	 * easier to hold in your head than one ordered by which screen we thought
	 * got opened most. */
	$order = array(
		/* Where you land. Not part of the sequence below so much as the place
		 * you start it from. */
		GWC_VT_DASHBOARD_PAGE,

		// What is coming.
		GWC_VT_SCHEDULE_PAGE,

		// Who is coming, and what each of them still has to do.
		'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE,

		/* What they did, and the queue of what nobody has attested to yet.
		 *
		 * Writing it up used to be two more entries here — Log a day, then the
		 * single-entry way. Both are verbs, and gwc_vt_hide_menu_verbs() now
		 * takes them off the menu and puts them on this screen as buttons, so
		 * naming them here would be describing a menu that no longer exists. */
		'edit.php?post_type=' . GWC_VT_ENTRY_TYPE,
	);

	// What gets produced for them, when this organization produces it.
	if ( gwc_vt_letters_enabled() ) {
		$order[] = GWC_VT_LETTERS_PAGE;
	}

	/**
	 * The order of the Volunteer Tracker submenu, by slug.
	 *
	 * Settings is deliberately absent from this list: anything not named here
	 * is appended, and Settings is appended after that, so it lands at the
	 * bottom however many screens a site adds.
	 *
	 * Naming it here anyway will place it wherever you put it. That is the
	 * point of a filter — a site that has explicitly asked for Settings third
	 * has said something, and overriding it would be this plugin arguing with
	 * somebody about their own admin.
	 *
	 * @param string[] $order Submenu slugs, in the order they should appear.
	 */
	return (array) apply_filters( 'gwc_vt_menu_order', $order );
}

/* ── A menu of places, and buttons for the verbs ─────────────────────────────
 * "Log a day" and "Log hours" are not destinations. They are the two ways of
 * writing up work that has already happened, and both of them are things you
 * do to the hours you are already looking at. On the menu they read as two more
 * screens to hold in your head, between Volunteers and Letters, and they took
 * the submenu to eight entries when six of them are nouns.
 *
 * So they come off the menu and go on All hours as page-title-action buttons —
 * "Log a day" and "Log one shift" — which is where the work they do begins.
 *
 * Neither page is deregistered. remove_submenu_page() unsets the menu entry and
 * leaves $_registered_pages alone, so both remain reachable by URL: a bookmark
 * still works, and so does every link in this plugin that points at them. That
 * is the difference between this and simply not calling add_submenu_page() —
 * which would take the Log-a-day screen off the site entirely, along with the
 * "Log the hours" link on every shift.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The submenu slugs that are verbs rather than places.
 *
 * @return string[]
 */
function gwc_vt_hidden_menu_items(): array {
	$hidden = array(
		GWC_VT_QUICK_ADD_PAGE,
		'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE,

		/* Not a verb, but not a place either: the verify queue is All hours
		 * narrowed to what is waiting, and it is offered where WordPress offers
		 * a narrowed list — beside "All" on that screen's own views. */
		GWC_VT_VERIFY_PAGE,

		/* Producing a letter is about one person, so it is reached from that
		 * person: their record, their row on the volunteer list, and the verify
		 * queue's offer when their last hours are attested to. A menu item would
		 * be an invitation to start from a blank form and go looking for
		 * somebody, which is the flow it replaced. */
		GWC_VT_PRODUCE_PAGE,

		/* Changing a whole repeat is reached from an occurrence of that repeat,
		 * because "which repeat" is the one thing the screen cannot ask you. A
		 * menu entry would land on a form with nothing to apply across. */
		GWC_VT_REPEAT_PAGE,
	);

	/**
	 * Submenu entries to take off the Volunteer Tracker menu, by slug.
	 *
	 * Returning an empty array puts both back, for a site that would rather
	 * have them. The pages themselves are never deregistered either way, so
	 * this only decides whether they are listed.
	 *
	 * @param string[] $hidden Submenu slugs to remove.
	 */
	return (array) apply_filters( 'gwc_vt_hidden_menu_items', $hidden );
}

/**
 * Take those entries off the menu.
 */
function gwc_vt_hide_menu_verbs(): void {
	foreach ( gwc_vt_hidden_menu_items() as $slug ) {
		remove_submenu_page( GWC_VT_MENU_SLUG, (string) $slug );
	}
}

/**
 * Put the submenu in that order.
 */
function gwc_vt_order_menu(): void {
	$parent = GWC_VT_MENU_SLUG;

	if ( empty( $GLOBALS['submenu'][ $parent ] ) || ! is_array( $GLOBALS['submenu'][ $parent ] ) ) {
		return;
	}

	$items   = $GLOBALS['submenu'][ $parent ];
	$by_slug = array();

	foreach ( $items as $item ) {
		$by_slug[ (string) ( $item[2] ?? '' ) ] = $item;
	}

	$ordered = array();

	foreach ( gwc_vt_menu_order() as $slug ) {
		if ( isset( $by_slug[ $slug ] ) ) {
			$ordered[] = $by_slug[ $slug ];
			unset( $by_slug[ $slug ] );
		}
	}

	/* Whatever is left, in the order it was registered — a screen this plugin
	 * has never heard of keeps its place rather than vanishing. Settings is
	 * pulled out of that remainder so it stays at the bottom however many
	 * screens a site adds. */
	$settings = $by_slug[ GWC_VT_SETTINGS_PAGE ] ?? null;
	unset( $by_slug[ GWC_VT_SETTINGS_PAGE ] );

	foreach ( $by_slug as $item ) {
		$ordered[] = $item;
	}

	if ( null !== $settings ) {
		$ordered[] = $settings;
	}

	/* Re-keyed from zero. WordPress reads the keys as positions when it renders,
	 * and leaving the originals would put everything back where it started. */
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $submenu is the documented way to order a menu; core exposes no API for it, and this writes one plugin's own branch of it rather than replacing the global.
	$GLOBALS['submenu'][ $parent ] = array_values( $ordered );
}

/**
 * Hang the settings screen off the Volunteer Tracker menu.
 *
 * A submenu of the post type rather than a top-level menu of its own. The post
 * type already owns a menu; a second top-level entry for the same plugin is the
 * thing that makes an admin sidebar unreadable one plugin at a time.
 */
function gwc_vt_register_menu(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		__( 'Volunteer Tracker Settings', 'groundwork-common-volunteer-tracker' ),
		__( 'Settings', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_cap( 'manage' ),
		GWC_VT_SETTINGS_PAGE,
		'gwc_vt_render_settings_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_settings_screen_loaded' );
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
function gwc_vt_settings_screen_loaded(): void {
	/**
	 * Fires when the Volunteer Tracker settings screen loads.
	 */
	do_action( 'gwc_vt_settings_screen_loaded' );
}

/**
 * The tabs, and what each is for.
 *
 * @return array<string, string>
 */
function gwc_vt_admin_tabs(): array {
	$tabs = array();

	/* First when it is there at all, because it is the original product and the
	 * tab most sites open. Absent entirely when letters are off — and
	 * gwc_vt_current_tab() falls back to array_key_first(), so a bookmark
	 * pointing at ?tab=letter lands on Logging rather than on an empty screen. */
	if ( gwc_vt_letters_enabled() ) {
		$tabs['letter'] = __( 'Letter', 'groundwork-common-volunteer-tracker' );
	}

	$tabs['logging'] = __( 'Logging', 'groundwork-common-volunteer-tracker' );
	$tabs['shifts']  = __( 'Shifts', 'groundwork-common-volunteer-tracker' );
	$tabs['privacy'] = __( 'Privacy', 'groundwork-common-volunteer-tracker' );

	/**
	 * The settings screen's tabs, keyed by slug.
	 *
	 * @param array<string, string> $tabs Slug => label.
	 */
	return (array) apply_filters( 'gwc_vt_admin_tabs', $tabs );
}

/**
 * Which tab is being viewed.
 *
 * @return string
 */
function gwc_vt_current_tab(): string {
	$tabs = gwc_vt_admin_tabs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is written from this value.
	$wanted = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	return isset( $tabs[ $wanted ] ) ? $wanted : (string) array_key_first( $tabs );
}

/**
 * The settings screen.
 */
function gwc_vt_render_settings_screen(): void {
	gwc_vt_require_cap( 'manage' );

	$tabs    = gwc_vt_admin_tabs();
	$current = gwc_vt_current_tab();
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php gwc_vt_render_colophon(); ?>

		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings sections', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( gwc_vt_settings_url( $slug ) ); ?>"
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
			do_action( 'gwc_vt_render_tab_' . $current );

			if ( ! has_action( 'gwc_vt_render_tab_' . $current ) ) :
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
function gwc_vt_settings_url( string $tab = '' ): string {
	$args = array( 'page' => GWC_VT_SETTINGS_PAGE );

	if ( '' !== $tab ) {
		$args['tab'] = $tab;
	}

	return add_query_arg( $args, admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ) );
}

/**
 * Is the screen being rendered one of this plugin's?
 *
 * Matched on the `gwc_vt` prefix rather than against a list of screen IDs. The
 * sibling plugins name their screens explicitly and that is the more careful
 * habit in general — but here every post type this plugin registers is
 * `gwc_vt_*` and every admin page is `gwcvt-*`, so the prefix IS the list, and a
 * list would be a second copy of it that a later post type could be added
 * without. Nothing outside this plugin has a screen ID containing this string.
 *
 * @return bool
 */
function gwc_vt_is_plugin_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return false;
	}

	return 0 === strpos( (string) $screen->post_type, 'gwc_vt_' )
		|| false !== strpos( (string) $screen->id, 'gwc_vt' );
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
function gwc_vt_admin_footer_text( $text ) {
	if ( ! gwc_vt_is_plugin_screen() ) {
		return $text;
	}

	return sprintf(
		/* translators: %s: Groundwork Common, linked to the company site. */
		esc_html__( 'Built by %s — technology leadership and support for nonprofits.', 'groundwork-common-volunteer-tracker' ),
		sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( GWC_VT_GWC_URL ),
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

const GWC_VT_COLOPHON_META   = 'gwc_vt_colophon_collapsed_at';
const GWC_VT_COLOPHON_SNOOZE = 30 * DAY_IN_SECONDS;

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
function gwc_vt_colophon_snoozed( int $collapsed_at, int $now ): bool {
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

	return ( $now - $collapsed_at ) < GWC_VT_COLOPHON_SNOOZE;
}

/**
 * Whether to render the colophon collapsed for the current user.
 *
 * @return bool
 */
function gwc_vt_colophon_is_collapsed(): bool {
	$at = (int) get_user_meta( get_current_user_id(), GWC_VT_COLOPHON_META, true );
	return gwc_vt_colophon_snoozed( $at, time() );
}

/**
 * Collapse or expand, then send the browser back where it was.
 *
 * A nonced link handled server-side rather than a script and an AJAX route.
 * This runs at most twice a month per person, so a page load costs nothing —
 * and the alternative would add an endpoint, a nonce to ship to the browser and
 * a script, all to avoid a reload nobody will notice.
 */
function gwc_vt_handle_colophon_toggle(): void {
	if ( ! isset( $_GET['gwc_vt_colophon'] ) ) {
		return;
	}

	if ( ! current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		return;
	}

	check_admin_referer( 'gwc_vt_colophon' );

	$wanted = sanitize_key( wp_unslash( $_GET['gwc_vt_colophon'] ) );

	if ( 'collapse' === $wanted ) {
		update_user_meta( get_current_user_id(), GWC_VT_COLOPHON_META, time() );
	} else {
		delete_user_meta( get_current_user_id(), GWC_VT_COLOPHON_META );
	}

	/* Back to the same tab, minus the toggle. Without stripping the arguments a
	 * refresh would re-fire the toggle, and the nonce in the URL would outlive
	 * its usefulness in the address bar. */
	wp_safe_redirect( remove_query_arg( array( 'gwc_vt_colophon', '_wpnonce' ) ) );
	exit;
}

/**
 * The collapse/expand link, nonced.
 *
 * @param string $action 'collapse' or 'expand'.
 * @return string
 */
function gwc_vt_colophon_toggle_url( string $action ): string {
	return wp_nonce_url( add_query_arg( 'gwc_vt_colophon', $action ), 'gwc_vt_colophon' );
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
 * about its author is the behavior the directory guidelines exist to stop, and
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
function gwc_vt_render_colophon(): void {
	?>
	<?php if ( gwc_vt_colophon_is_collapsed() ) : ?>
		<div class="gwcvt-colophon gwcvt-colophon--collapsed">
			<span class="gwcvt-colophon__logo" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Groundwork Common', 'groundwork-common-volunteer-tracker' ); ?></span>
			<a class="gwcvt-colophon__toggle" href="<?php echo esc_url( gwc_vt_colophon_toggle_url( 'expand' ) ); ?>">
				<?php esc_html_e( 'Show', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="gwcvt-colophon">
		<a class="gwcvt-colophon__toggle" href="<?php echo esc_url( gwc_vt_colophon_toggle_url( 'collapse' ) ); ?>">
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
				 * Two files, swapped by color scheme in the stylesheet: the logo
				 * is ink on transparent, so one version or the other disappears
				 * depending on what it is sitting on. Naming is by BACKGROUND,
				 * not by ink — "-light" is the one for light backgrounds.
				 */
				?>
				<a href="<?php echo esc_url( GWC_VT_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
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
				$gwc_vt_gwc_link = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( GWC_VT_GWC_URL ),
					esc_html__( 'Groundwork Common', 'groundwork-common-volunteer-tracker' )
				);

				printf(
					/* translators: %s: Groundwork Common, linked to the company site. */
					esc_html__( '%s provides technology leadership and support for nonprofits — fractional, by the project, or alongside an in-house team. We release tools like this one because good technology work should leave an organization more capable, not more dependent on whoever built it.', 'groundwork-common-volunteer-tracker' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled directly above from esc_url() and esc_html__().
					$gwc_vt_gwc_link
				);
				?>
			</p>

			<p>
				<?php esc_html_e( 'If you find this plugin useful, the most valuable thing you can do for us is mention us to a nonprofit who might benefit from our services. Referrals are how our business continues to grow its impact and reach.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php /* Directly under the referral ask, which is what it answers. */ ?>
			<p>
				<a class="button" href="<?php echo esc_url( GWC_VT_GWC_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Learn about Groundwork Common', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		</div>

		<?php /* Second column: the two things a reader can act on. */ ?>
		<div class="gwcvt-colophon__aside">
			<?php if ( '' !== GWC_VT_SPONSOR_URL ) : ?>
				<p>
					<?php esc_html_e( 'You can also support our WordPress plugins directly. While we offer the plugin free to you, it costs us to maintain it — the security updates, the compatibility testing against each new WordPress release, the bug nobody but you has hit. We can’t do it without your support, and we appreciate whatever support you can give.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( GWC_VT_SPONSOR_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Support our work', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p>
				<a href="<?php echo esc_url( GWC_VT_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Report a problem', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php
}

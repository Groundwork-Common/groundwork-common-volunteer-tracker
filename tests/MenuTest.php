<?php
/**
 * The order of the Volunteer Hours submenu.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
		$GLOBALS['submenu'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['submenu'] );
	}

	/**
	 * The submenu as WordPress leaves it: registration order, which is the
	 * order the files happened to load in.
	 *
	 * @return array[]
	 */
	private function as_registered(): array {
		return array(
			/* The two WordPress adds for the post type, and Volunteers, which it
			 * adds for that one — all three from wp-admin/menu.php, before any
			 * admin_menu callback runs. */
			array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			array( 'Log hours', 'edit_posts', 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			array( 'Volunteers', 'edit_posts', 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),

			// Then this plugin's own, in the order the files load.
			array( 'Dashboard', 'edit_posts', GWC_VT_DASHBOARD_PAGE ),
			array( 'Settings', 'manage_options', GWC_VT_SETTINGS_PAGE ),
			array( 'Letters', 'gwc_vt_issue_letters', GWC_VT_LETTERS_PAGE ),
			array( 'Log a day', 'edit_posts', GWC_VT_QUICK_ADD_PAGE ),
			array( 'Schedule', 'edit_posts', GWC_VT_SCHEDULE_PAGE ),
			array( 'Offers to volunteer', 'edit_others_posts', GWC_VT_APPLICATIONS_PAGE ),
			array( 'Credentials', 'edit_others_posts', GWC_VT_CREDENTIALS_PAGE ),
			array( 'Help', 'read', GWC_VT_HELP_PAGE ),
		);
	}

	/**
	 * The classes on each row, in the order they would render.
	 *
	 * @return string[]
	 */
	private function classes(): array {
		return array_map(
			static function ( array $item ): string {
				return (string) ( $item[4] ?? '' );
			},
			(array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() )
		);
	}

	/**
	 * The labels of the rows a rule is drawn above.
	 *
	 * @return string[]
	 */
	private function ruled(): array {
		$ruled = array();

		foreach ( (array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() ) as $item ) {
			if ( false !== strpos( (string) ( $item[4] ?? '' ), 'gwc-vt-rule' ) ) {
				$ruled[] = (string) $item[0];
			}
		}

		return $ruled;
	}

	/**
	 * The labels, in the order they would render.
	 *
	 * @return string[]
	 */
	private function labels(): array {
		return array_map(
			static function ( array $item ): string {
				return (string) $item[0];
			},
			(array) ( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] ?? array() )
		);
	}

	/**
	 * Both passes, in the order admin_menu fires them: hide at 98, order at 99.
	 *
	 * Every test that cares about the finished menu goes through this, because
	 * asserting either half alone would describe a menu no user ever sees.
	 */
	private function build(): void {
		gwc_vt_hide_menu_verbs();
		gwc_vt_order_menu();
	}

	/**
	 * What is coming, then who is coming, then what they did, then what gets
	 * produced for them. It reads forwards, and every entry is a place.
	 */
	public function test_it_puts_the_screens_in_the_order_things_happen(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->build();

		$this->assertSame(
			array(
				'Dashboard',
				'Schedule',
				'Volunteers',
				'Offers to volunteer',
				'Credentials',
				'All hours',
				'Letters',
				'Help',
				'Settings',
			),
			$this->labels()
		);
	}

	/* ── Verbs are not destinations ──────────────────────────────────────────
	 * "Log a day" and "Log hours" came off the menu and became buttons on All
	 * hours. What is asserted here is that they leave the MENU — the pages stay
	 * registered, which is core's behaviour in remove_submenu_page() and is
	 * what keeps every "Log the hours" link in this plugin working.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_two_logging_verbs_leave_the_menu(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->build();

		$labels = $this->labels();

		$this->assertNotContains( 'Log a day', $labels );
		$this->assertNotContains( 'Log hours', $labels );
	}

	/**
	 * Eleven entries in, nine out. The count is the point of the hiding pass, so
	 * it is asserted as a count rather than left to be inferred from the order
	 * above.
	 */
	public function test_the_menu_is_nine_entries(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->assertCount( 11, $this->as_registered() );

		$this->build();

		$this->assertCount( 9, $this->labels() );
	}

	/* ── The bands, and the rules between them ───────────────────────────────
	 * Nine rows read as four things: where you land, the work ahead and who
	 * will do it, the work already done and what it produces, and the setting
	 * up. What is asserted here is which rows OPEN a band, because that is what
	 * a rule is drawn above — not that the class exists somewhere.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_a_rule_opens_each_band_after_the_first(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->build();

		$this->assertSame(
			array( 'Schedule', 'All hours', 'Help' ),
			$this->ruled(),
			'three rules, above the row that opens each band after the first'
		);
	}

	/**
	 * Nothing above the row that opens the menu. A rule there is not a
	 * separator, it is a line hanging under the menu's own heading.
	 */
	public function test_the_first_row_carries_no_rule(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->build();

		$classes = $this->classes();

		$this->assertSame( 'Dashboard', $this->labels()[0] );
		$this->assertSame( '', $classes[0] );
	}

	/**
	 * The rule goes on whichever row of a band actually turned up, not on a
	 * named slug that may not be there.
	 *
	 * Letters off is the case that ships: All hours is then the only row in the
	 * record band, and it has to take the rule or the band merges into the one
	 * above it with nothing to say so.
	 */
	public function test_a_band_missing_its_first_row_still_gets_its_rule(): void {
		$items = array();

		foreach ( $this->as_registered() as $item ) {
			// Both rows of the setup band gone, and the schedule with them.
			if ( in_array( (string) $item[2], array( GWC_VT_SCHEDULE_PAGE, GWC_VT_HELP_PAGE ), true ) ) {
				continue;
			}

			$items[] = $item;
		}

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		$this->build();

		$this->assertSame(
			array( 'Volunteers', 'All hours', 'Settings' ),
			$this->ruled(),
			'the band opens on the first row it has left, not on the slug named first'
		);
	}

	/**
	 * A band with nothing left in it draws no rule, rather than moving its rule
	 * onto the next band and reporting a grouping that is not there.
	 */
	public function test_an_empty_band_draws_no_rule(): void {
		$items = array();

		foreach ( $this->as_registered() as $item ) {
			if ( in_array(
				(string) $item[2],
				array( GWC_VT_HELP_PAGE, GWC_VT_SETTINGS_PAGE ),
				true
			) ) {
				continue;
			}

			$items[] = $item;
		}

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		$this->build();

		$this->assertSame( array( 'Schedule', 'All hours' ), $this->ruled() );
	}

	/**
	 * A class a row already carries is kept. Core builds the class attribute
	 * from index 4, and replacing it rather than appending would drop whatever
	 * put it there.
	 */
	public function test_an_existing_class_is_kept(): void {
		$items = $this->as_registered();

		foreach ( $items as $index => $item ) {
			if ( GWC_VT_SCHEDULE_PAGE === (string) $item[2] ) {
				$items[ $index ][3] = 'Schedule';
				$items[ $index ][4] = 'acme-highlight';
			}
		}

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		$this->build();

		$classes = $this->classes();
		$labels  = $this->labels();

		$this->assertSame(
			'acme-highlight gwc-vt-rule',
			$classes[ array_search( 'Schedule', $labels, true ) ]
		);
	}

	/**
	 * Somebody else's screen belongs to no band, so it takes no rule — a line
	 * above it would say it opens a group of ours, which it does not.
	 */
	public function test_an_unknown_screen_takes_no_rule(): void {
		$items   = $this->as_registered();
		$items[] = array( 'Grant report', 'edit_posts', 'acme-grant-report' );

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		$this->build();

		$this->assertNotContains( 'Grant report', $this->ruled() );
	}

	/**
	 * One registry, so a row cannot be ordered as one thing and ruled off as
	 * another: the order is flattened out of the bands.
	 */
	public function test_the_order_is_the_bands_flattened(): void {
		$flattened = array();

		foreach ( gwc_vt_menu_bands() as $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( GWC_VT_SETTINGS_PAGE !== $slug ) {
					$flattened[] = $slug;
				}
			}
		}

		$this->assertSame( $flattened, gwc_vt_menu_order() );
	}

	/**
	 * Settings is in a band and out of the order, and both halves matter: the
	 * band is what keeps the setup rule landing if Help is ever taken off the
	 * menu, and staying out of the order is what keeps Settings below a screen
	 * a site has added of its own.
	 */
	public function test_settings_is_banded_but_not_ordered(): void {
		$banded = array();

		foreach ( gwc_vt_menu_bands() as $slugs ) {
			$banded = array_merge( $banded, $slugs );
		}

		$this->assertContains( GWC_VT_SETTINGS_PAGE, $banded );
		$this->assertNotContains( GWC_VT_SETTINGS_PAGE, gwc_vt_menu_order() );
	}

	/**
	 * Filtering the bands moves the rows and the rules together. That is the
	 * point of it being one array, and it is the extension point a site should
	 * reach for rather than gwc_vt_menu_order().
	 */
	public function test_a_filter_on_the_bands_moves_rows_and_rules_together(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwc_vt_menu_bands',
			static function (): array {
				return array(
					'mine'  => array( GWC_VT_DASHBOARD_PAGE, GWC_VT_CREDENTIALS_PAGE ),
					'yours' => array( GWC_VT_SCHEDULE_PAGE, GWC_VT_SETTINGS_PAGE ),
				);
			}
		);

		$this->build();

		$labels = $this->labels();

		$this->assertSame( 'Dashboard', $labels[0] );
		$this->assertSame( 'Credentials', $labels[1] );
		$this->assertSame( 'Schedule', $labels[2] );
		$this->assertSame( array( 'Schedule' ), $this->ruled() );

		gwc_vt_test_reset_filters();
	}

	/**
	 * Every screen this plugin puts on the menu is in a band or is deliberately
	 * off the menu.
	 *
	 * Read out of the source rather than listed here, because the failure this
	 * catches is somebody adding a screen and nobody noticing it has no band —
	 * which is exactly how Offers and Credentials ended up at the bottom of the
	 * menu below Letter records, and how they stayed there for two releases.
	 */
	public function test_every_page_slug_is_banded_or_deliberately_hidden(): void {
		$source = (string) file_get_contents( GWC_VT_DIR . 'inc/admin-screen.php' );

		preg_match_all( "/^const (GWC_VT_[A-Z_]+_PAGE)\s*=\s*'([a-z0-9-]+)';/m", $source, $found );

		$this->assertNotEmpty( $found[2], 'the page slug constants moved out of admin-screen.php' );

		$banded = array( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE );

		foreach ( gwc_vt_menu_bands() as $slugs ) {
			$banded = array_merge( $banded, $slugs );
		}

		$hidden = gwc_vt_hidden_menu_items();

		foreach ( $found[2] as $index => $slug ) {
			$this->assertTrue(
				in_array( $slug, $banded, true ) || in_array( $slug, $hidden, true ),
				$found[1][ $index ] . ' is neither in a band nor deliberately off the menu, so it would land in the remainder at the bottom'
			);
		}
	}

	/**
	 * A site that wants them back gets them back, and they land where
	 * gwc_vt_menu_order() would have put them rather than at the end.
	 */
	public function test_a_filter_can_put_the_verbs_back(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwc_vt_hidden_menu_items',
			static function (): array {
				return array();
			}
		);

		$this->build();

		$this->assertContains( 'Log a day', $this->labels() );
		$this->assertContains( 'Log hours', $this->labels() );

		gwc_vt_test_reset_filters();
	}

	/**
	 * Hiding leaves a hole in an array whose keys WordPress reads as positions,
	 * which is why it runs before the ordering pass rather than after it. If
	 * that order is ever reversed this is the test that says so.
	 */
	public function test_the_menu_is_still_a_list_after_hiding(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		$this->build();

		$this->assertSame(
			range( 0, count( $this->labels() ) - 1 ),
			array_keys( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] )
		);
	}

	/**
	 * The one that was unambiguously wrong. Settings sat fourth because
	 * admin-screen.php registers at the default priority and the screens added
	 * in later releases registered at 11, 12 and 13.
	 */
	public function test_settings_is_last(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		gwc_vt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Settings', end( $labels ) );
	}

	/**
	 * The parent's own screen is still in the menu, just not first.
	 *
	 * Worth asserting because it is the one thing this ordering gives up: the
	 * top-level "Volunteer Hours" link opens All hours, which is now third. The
	 * link still has a destination, and a menu ordered by when things happen is
	 * easier to hold in your head than one ordered by which screen we guessed
	 * got opened most — but losing the screen entirely would be a different
	 * matter, so it is checked.
	 */
	public function test_the_parents_own_screen_is_still_present(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		gwc_vt_order_menu();

		$slugs = array_map(
			static function ( array $item ): string {
				return (string) $item[2];
			},
			$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ]
		);

		$this->assertContains( GWC_VT_MENU_SLUG, $slugs );
	}

	/* ── Somebody else's screen ──────────────────────────────────────────────
	 * A site that has added its own page to this menu must not lose it because
	 * a list in this plugin had never heard of it.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_an_unknown_screen_is_kept(): void {
		$items   = $this->as_registered();
		$items[] = array( 'Grant report', 'edit_posts', 'acme-grant-report' );

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		gwc_vt_order_menu();

		$this->assertContains( 'Grant report', $this->labels() );
	}

	public function test_an_unknown_screen_sits_above_settings(): void {
		$items   = $this->as_registered();
		$items[] = array( 'Grant report', 'edit_posts', 'acme-grant-report' );

		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $items;

		gwc_vt_order_menu();

		$labels = $this->labels();

		$this->assertLessThan(
			array_search( 'Settings', $labels, true ),
			array_search( 'Grant report', $labels, true ),
			'settings has to stay at the bottom however many screens a site adds'
		);
	}

	/* ── Not losing anything ─────────────────────────────────────────────── */

	public function test_nothing_is_added_or_dropped(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		gwc_vt_order_menu();

		$before = array_map(
			static function ( array $item ): string {
				return (string) $item[2];
			},
			$this->as_registered()
		);

		$after = array_map(
			static function ( array $item ): string {
				return (string) $item[2];
			},
			$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ]
		);

		sort( $before );
		sort( $after );

		$this->assertSame( $before, $after );
	}

	/**
	 * Re-keyed from zero. WordPress reads the array keys as positions when it
	 * renders, so leaving the originals would put everything back where it
	 * started — the change would appear to do nothing at all.
	 */
	public function test_the_result_is_a_list_not_a_sparse_array(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = array(
			5  => array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			10 => array( 'Settings', 'manage_options', GWC_VT_SETTINGS_PAGE ),
			15 => array( 'Schedule', 'edit_posts', GWC_VT_SCHEDULE_PAGE ),
		);

		gwc_vt_order_menu();

		$this->assertSame(
			array( 0, 1, 2 ),
			array_keys( $GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] )
		);
	}

	/* ── Nothing to do ───────────────────────────────────────────────────── */

	public function test_it_does_nothing_when_the_menu_is_not_there(): void {
		gwc_vt_order_menu();

		$this->assertSame( array(), $GLOBALS['submenu'] );
	}

	/**
	 * A screen registered but not present — a capability the current user does
	 * not hold, so WordPress never added it — is simply absent rather than
	 * leaving a hole.
	 */
	public function test_a_screen_the_user_cannot_see_is_simply_absent(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = array(
			array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			array( 'Log a day', 'edit_posts', GWC_VT_QUICK_ADD_PAGE ),
		);

		gwc_vt_order_menu();

		$this->assertSame( array( 'All hours', 'Log a day' ), $this->labels() );
		$this->assertCount( 2, $this->labels(), 'a screen that was never added must not appear' );
	}

	/**
	 * The order is filterable, and a filter that does not mention Settings
	 * still gets Settings at the bottom.
	 */
	public function test_a_filter_reorders_and_settings_still_lands_last(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwc_vt_menu_order',
			static function (): array {
				return array( GWC_VT_SCHEDULE_PAGE, GWC_VT_LETTERS_PAGE );
			}
		);

		gwc_vt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Schedule', $labels[0] );
		$this->assertSame( 'Letters', $labels[1] );
		$this->assertSame( 'Settings', end( $labels ) );

		gwc_vt_test_reset_filters();
	}

	/**
	 * But a filter that names Settings puts it where it says. A site that has
	 * explicitly asked for Settings in a particular place has said something,
	 * and overriding it would be this plugin arguing with somebody about their
	 * own admin menu.
	 */
	public function test_a_filter_that_names_settings_wins(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwc_vt_menu_order',
			static function (): array {
				return array( GWC_VT_SETTINGS_PAGE, GWC_VT_SCHEDULE_PAGE );
			}
		);

		gwc_vt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Settings', $labels[0] );
		$this->assertSame( 'Schedule', $labels[1] );

		gwc_vt_test_reset_filters();
	}
}

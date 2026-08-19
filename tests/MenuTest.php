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
			array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			array( 'Log hours', 'edit_posts', 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
			array( 'Volunteers', 'edit_posts', 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
			array( 'Settings', 'manage_options', GWC_VT_SETTINGS_PAGE ),
			array( 'Letters', 'gwc_vt_issue_letters', GWC_VT_LETTERS_PAGE ),
			array( 'Log a day', 'edit_posts', GWC_VT_QUICK_ADD_PAGE ),
			array( 'Schedule', 'edit_posts', GWC_VT_SCHEDULE_PAGE ),
		);
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
	 * What is coming, then who is coming, then what they did, then writing it
	 * up, then what gets produced for them. It reads forwards.
	 */
	public function test_it_puts_the_screens_in_the_order_things_happen(): void {
		$GLOBALS['submenu'][ GWC_VT_MENU_SLUG ] = $this->as_registered();

		gwc_vt_order_menu();

		$this->assertSame(
			array( 'Schedule', 'Volunteers', 'All hours', 'Log a day', 'Log hours', 'Letters', 'Settings' ),
			$this->labels()
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

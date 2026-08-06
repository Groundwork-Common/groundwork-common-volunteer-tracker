<?php
/**
 * The order of the Volunteer Hours submenu.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
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
			array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWCVT_ENTRY_TYPE ),
			array( 'Log hours', 'edit_posts', 'post-new.php?post_type=' . GWCVT_ENTRY_TYPE ),
			array( 'Volunteers', 'edit_posts', 'edit.php?post_type=' . GWCVT_VOLUNTEER_TYPE ),
			array( 'Settings', 'manage_options', GWCVT_SETTINGS_PAGE ),
			array( 'Letters', 'gwcvt_issue_letters', GWCVT_LETTERS_PAGE ),
			array( 'Log a day', 'edit_posts', GWCVT_QUICK_ADD_PAGE ),
			array( 'Schedule', 'edit_posts', GWCVT_SCHEDULE_PAGE ),
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
			(array) ( $GLOBALS['submenu'][ GWCVT_MENU_SLUG ] ?? array() )
		);
	}

	public function test_it_puts_the_screens_in_the_order_somebody_works_in(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		gwcvt_order_menu();

		$this->assertSame(
			array( 'All hours', 'Log a day', 'Log hours', 'Schedule', 'Volunteers', 'Letters', 'Settings' ),
			$this->labels()
		);
	}

	/**
	 * The one that was unambiguously wrong. Settings sat fourth because
	 * admin-screen.php registers at the default priority and the screens added
	 * in later releases registered at 11, 12 and 13.
	 */
	public function test_settings_is_last(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		gwcvt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Settings', end( $labels ) );
	}

	/**
	 * The parent's own target stays first. A menu whose first submenu is not
	 * what the top-level item opens is a mis-click waiting to happen.
	 */
	public function test_the_parents_own_screen_stays_first(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		gwcvt_order_menu();

		$this->assertSame( GWCVT_MENU_SLUG, $GLOBALS['submenu'][ GWCVT_MENU_SLUG ][0][2] );
	}

	/* ── Somebody else's screen ──────────────────────────────────────────────
	 * A site that has added its own page to this menu must not lose it because
	 * a list in this plugin had never heard of it.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_an_unknown_screen_is_kept(): void {
		$items   = $this->as_registered();
		$items[] = array( 'Grant report', 'edit_posts', 'acme-grant-report' );

		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $items;

		gwcvt_order_menu();

		$this->assertContains( 'Grant report', $this->labels() );
	}

	public function test_an_unknown_screen_sits_above_settings(): void {
		$items   = $this->as_registered();
		$items[] = array( 'Grant report', 'edit_posts', 'acme-grant-report' );

		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $items;

		gwcvt_order_menu();

		$labels = $this->labels();

		$this->assertLessThan(
			array_search( 'Settings', $labels, true ),
			array_search( 'Grant report', $labels, true ),
			'settings has to stay at the bottom however many screens a site adds'
		);
	}

	/* ── Not losing anything ─────────────────────────────────────────────── */

	public function test_nothing_is_added_or_dropped(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		gwcvt_order_menu();

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
			$GLOBALS['submenu'][ GWCVT_MENU_SLUG ]
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
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = array(
			5  => array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWCVT_ENTRY_TYPE ),
			10 => array( 'Settings', 'manage_options', GWCVT_SETTINGS_PAGE ),
			15 => array( 'Schedule', 'edit_posts', GWCVT_SCHEDULE_PAGE ),
		);

		gwcvt_order_menu();

		$this->assertSame(
			array( 0, 1, 2 ),
			array_keys( $GLOBALS['submenu'][ GWCVT_MENU_SLUG ] )
		);
	}

	/* ── Nothing to do ───────────────────────────────────────────────────── */

	public function test_it_does_nothing_when_the_menu_is_not_there(): void {
		gwcvt_order_menu();

		$this->assertSame( array(), $GLOBALS['submenu'] );
	}

	/**
	 * A screen registered but not present — a capability the current user does
	 * not hold, so WordPress never added it — is simply absent rather than
	 * leaving a hole.
	 */
	public function test_a_screen_the_user_cannot_see_is_simply_absent(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = array(
			array( 'All hours', 'edit_posts', 'edit.php?post_type=' . GWCVT_ENTRY_TYPE ),
			array( 'Log a day', 'edit_posts', GWCVT_QUICK_ADD_PAGE ),
		);

		gwcvt_order_menu();

		$this->assertSame( array( 'All hours', 'Log a day' ), $this->labels() );
	}

	/**
	 * The order is filterable, and a filter that does not mention Settings
	 * still gets Settings at the bottom.
	 */
	public function test_a_filter_reorders_and_settings_still_lands_last(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwcvt_menu_order',
			static function (): array {
				return array( GWCVT_SCHEDULE_PAGE, GWCVT_LETTERS_PAGE );
			}
		);

		gwcvt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Schedule', $labels[0] );
		$this->assertSame( 'Letters', $labels[1] );
		$this->assertSame( 'Settings', end( $labels ) );

		gwcvt_test_reset_filters();
	}

	/**
	 * But a filter that names Settings puts it where it says. A site that has
	 * explicitly asked for Settings in a particular place has said something,
	 * and overriding it would be this plugin arguing with somebody about their
	 * own admin menu.
	 */
	public function test_a_filter_that_names_settings_wins(): void {
		$GLOBALS['submenu'][ GWCVT_MENU_SLUG ] = $this->as_registered();

		add_filter(
			'gwcvt_menu_order',
			static function (): array {
				return array( GWCVT_SETTINGS_PAGE, GWCVT_SCHEDULE_PAGE );
			}
		);

		gwcvt_order_menu();

		$labels = $this->labels();

		$this->assertSame( 'Settings', $labels[0] );
		$this->assertSame( 'Schedule', $labels[1] );

		gwcvt_test_reset_filters();
	}
}

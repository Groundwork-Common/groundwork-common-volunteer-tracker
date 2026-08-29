/**
 * Every screen the plugin adds, opened.
 *
 * The cheapest test in the suite and the one most likely to catch a real
 * regression, because a screen that has stopped loading is a screen that fails
 * here and passes everywhere else — the unit suite runs against a stubbed
 * WordPress and the integration scripts run without a browser, so neither can
 * see a fatal in a renderer or a missing script dependency.
 *
 * The list is read from the source. See tests/e2e/support/source.js for why.
 */
const { test, expect } = require( '../support/harness.js' );
const { screenSlugs } = require( '../support/source.js' );

/**
 * A page's own body text, with wp-admin's furniture taken off.
 *
 * The menu, the toolbar and the footer are on every screen and say the same
 * thing on all of them, so a match against the whole body is a match against
 * the chrome as often as not.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<string>} The text inside .wrap.
 */
async function content( page ) {
	return ( await page.locator( '#wpbody-content' ).innerText() ).trim();
}

/* ── What must never be on a screen ──────────────────────────────────────────
 * WP_DEBUG and WP_DEBUG_DISPLAY are both on in this environment, which is the
 * whole reason to look: a notice, a warning or a deprecation prints into the
 * markup rather than into a log nobody reads. On a site with display off, the
 * same bug is invisible until it is a fatal.
 * ─────────────────────────────────────────────────────────────────────────── */
const PHP_COMPLAINTS =
	/(Warning|Notice|Deprecated|Fatal error|Parse error):\s|There has been a critical error/;

const SCREENS = screenSlugs();

test.describe( 'the admin screens', () => {
	test( 'the source still has ten of them', () => {
		/* Not a tautology: this is the guard on the regex in source.js. If the
		 * constants move or are renamed, screenSlugs() quietly returns fewer,
		 * every loop below runs over a shorter list, and the run goes green
		 * while testing less. A count that has to be changed on purpose is the
		 * cheapest way to make that a decision rather than an accident. */
		expect( SCREENS.map( ( s ) => s.slug ) ).toEqual( [
			'gwc-vt-applications',
			'gwc-vt-credentials',
			'gwc-vt-dashboard',
			'gwc-vt-help',
			'gwc-vt-letters',
			'gwc-vt-log-a-day',
			'gwc-vt-repeat',
			'gwc-vt-schedule',
			'gwc-vt-settings',
			'gwc-vt-verify',
		] );
	} );

	for ( const { constant, slug } of SCREENS ) {
		test( `${ slug } opens (${ constant })`, async ( { page, admin } ) => {
			const response = await page.goto( admin.screenUrl( slug ) );

			expect( response.status() ).toBe( 200 );

			const body = await content( page );

			expect( body ).not.toMatch( PHP_COMPLAINTS );
			expect( body ).not.toContain(
				'Sorry, you are not allowed to access this page'
			);

			/* One heading, and a real one. A screen whose <h1> is empty is the
			 * symptom gwc_vt_restore_quick_add_title() exists to fix: taking a
			 * verb off the menu takes its title with it, because core finds a
			 * page title by searching $submenu for the slug. */
			const heading = page.locator( '#wpbody-content h1' ).first();

			await expect( heading ).toBeVisible();
			expect( ( await heading.innerText() ).trim() ).not.toBe( '' );
		} );

		/* The guide is the one screen with no help tab, and that is right: a
		 * "Help" panel on the help page would point at itself. Every other
		 * screen has one, which is what tests/integration/help.php was written
		 * to keep true after six of ten had drifted into having none. */
		if ( 'gwc-vt-help' === slug ) {
			continue;
		}

		test( `${ slug } has a help tab`, async ( { page, admin } ) => {
			/* integration/help.php checks the hook table. This checks the tab
			 * is on the rendered screen, which is the half that broke: a help
			 * tab added from a renderer rather than on `load-` silently does
			 * not appear, and the screen looks perfectly fine without it.
			 *
			 * The panel is collapsed until pressed, so the assertion is that
			 * the tab exists rather than that its contents are visible. */
			await admin.visit( slug );

			await expect(
				page.locator( '#contextual-help-link-wrap' )
			).toHaveCount( 1 );

			expect(
				await page.locator( '#contextual-help-wrap .contextual-help-tabs a' ).count()
			).toBeGreaterThan( 0 );

			/* And the sidebar points at the guide, on the topic for this
			 * screen. Without that link the only route to the how-tos is
			 * knowing the page exists — which is the thing that made somebody
			 * ask where the help was. */
			const sidebar = page.locator( '#contextual-help-wrap .contextual-help-sidebar a' );

			expect(
				( await sidebar.evaluateAll( ( links ) =>
					links.map( ( link ) => link.getAttribute( 'href' ) || '' )
				) ).join( ' ' )
			).toContain( 'gwc-vt-help' );
		} );
	}

	test( 'the volunteer list opens', async ( { page } ) => {
		const response = await page.goto(
			'/wp-admin/edit.php?post_type=gwc_vt_volunteer'
		);

		expect( response.status() ).toBe( 200 );
		expect( await content( page ) ).not.toMatch( PHP_COMPLAINTS );
	} );

	test( 'the entries list opens', async ( { page, admin } ) => {
		const response = await page.goto( admin.screenUrl() );

		expect( response.status() ).toBe( 200 );
		expect( await content( page ) ).not.toMatch( PHP_COMPLAINTS );
	} );

	test( 'the menu carries the plugin', async ( { page, admin } ) => {
		await admin.visit( 'gwc-vt-dashboard' );

		const menu = page.locator( '#adminmenu' );

		await expect( menu ).toContainText( 'Volunteer Tracker' );

		/* The verbs are deliberately absent — see gwc_vt_hidden_menu_items().
		 * Asserted because putting one back is a decision, and a decision made
		 * by accident is the kind this suite exists to catch. */
		await expect( menu ).not.toContainText( 'Log a day' );
	} );
} );

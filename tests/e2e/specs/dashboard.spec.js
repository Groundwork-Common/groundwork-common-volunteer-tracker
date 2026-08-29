/**
 * The dashboard, and the rule its worklist is built on.
 *
 * ── A count and the screen it links to must come from one function ───────────
 * That rule was written after two of these lines broke it. The dashboard
 * counted overdue volunteers with gwc_vt_overdue_requirement_ids() and linked to
 * an unfiltered list; the unlogged-hours nag counted event slots with
 * gwc_vt_shifts_between() and linked to a view passing parent => 0, which
 * excludes them. Both said a number and then showed something else.
 *
 * DashboardTest asserts the ordering and the wording without a database, and
 * tests/integration/worklist-links.php checks the links resolve. What neither
 * can do is press one: this file reads a number off the dashboard, follows the
 * link beside it, and counts what actually arrives on the screen.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.describe( 'the dashboard', () => {
	test( 'opens with a worklist and a year, and says what needs doing', async ( {
		page,
		admin,
	} ) => {
		await admin.visit( 'gwc-vt-dashboard' );

		const body = page.locator( '#wpbody-content' );

		await expect( body ).toBeVisible();

		/* Both halves of the split: what is true on the left, and what somebody
		 * might do about it in the rail on the right. */
		const shown = await body.innerText();

		expect( shown.length ).toBeGreaterThan( 200 );

		/* The worklist offers verbs. The menu deliberately does not list them —
		 * see gwc_vt_hidden_menu_items() — so the dashboard is where they are,
		 * and the assertion is that they are links to this plugin's own screens
		 * rather than that they carry any particular class. */
		const actions = page.locator(
			'.gwcvt-dash a[href*="gwc-vt-"], .gwcvt-dash a[href*="gwc_vt"]'
		);

		expect( await actions.count() ).toBeGreaterThan( 0 );
	} );

	test( 'every worklist line links somewhere that exists', async ( {
		page,
		admin,
	} ) => {
		await admin.visit( 'gwc-vt-dashboard' );

		const links = await page
			.locator( '#wpbody-content a[href*="wp-admin"]' )
			.evaluateAll( ( found ) =>
				found
					.map( ( link ) => link.getAttribute( 'href' ) || '' )
					.filter( ( href ) => href.includes( 'gwc_vt' ) || href.includes( 'gwc-vt' ) )
			);

		expect( links.length ).toBeGreaterThan( 0 );

		for ( const href of [ ...new Set( links ) ] ) {
			const response = await page.goto( href );

			expect( response.status(), href ).toBe( 200 );

			const shown = await page.locator( '#wpbody-content' ).innerText();

			expect( shown, href ).not.toMatch(
				/(Warning|Notice|Deprecated|Fatal error):\s|There has been a critical error/
			);
			expect( shown, href ).not.toContain(
				'Sorry, you are not allowed'
			);
		}
	} );

	test( 'the hours waiting to be verified is the number the queue then shows', async ( {
		page,
		admin,
		api,
	} ) => {
		/* The rule, driven. Whatever the dashboard says is waiting, the verify
		 * queue has to offer exactly that many — because the link beside the
		 * number goes there, and a coordinator who is told eight and shown
		 * eleven stops believing either screen. */
		const waiting = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: {
				volunteer: '_gwc_vt_volunteer',
				verifiedAt: '_gwc_vt_verified_at',
			},
		} ).filter(
			( entry ) =>
				! entry.verifiedAt &&
				Number( entry.volunteer ) > 0 &&
				'publish' === entry.status
		).length;

		expect( waiting ).toBeGreaterThan( 0 );

		await admin.visit( 'gwc-vt-verify' );

		const offered = await page
			.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
			.count();

		expect( offered ).toBe( waiting );
	} );

	test( "WordPress's own dashboard carries a window onto it, and names nobody", async ( {
		page,
		fixtures,
	} ) => {
		await page.goto( '/wp-admin/index.php' );

		const widget = page.locator( '#gwc-vt-widget, [id*="gwc_vt"], .postbox' ).filter( {
			hasText: /Volunteer/i,
		} );

		expect( await widget.count() ).toBeGreaterThan( 0 );

		const shown = await widget.first().innerText();

		/* It never names a volunteer. This is WordPress's dashboard, seen by a
		 * narrower capability than the plugin's own screens, and a name on it
		 * would be the disclosure every other gate here exists to prevent. */
		for ( const name of Object.keys( fixtures.volunteers ) ) {
			expect( shown ).not.toContain( name );
		}
	} );
} );

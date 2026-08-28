/**
 * The three REST routes, the calendar file, and the endpoints that must not
 * exist.
 *
 * ── Hard rule 2, driven rather than read ─────────────────────────────────────
 * No post type here may be show_in_rest. That would publish volunteer names and
 * court-referral status at /wp/v2/, on a site where the whole point is that
 * those records are not public. tests/CapsTest.php can assert the registration
 * arguments; only a request can assert that the address really is closed — and
 * the address is what somebody would actually try.
 *
 * ── And the three routes that do exist ───────────────────────────────────────
 * They are read-only and internal: a shift panel for the schedule's drawer, a
 * recurrence preview for the repeat picker, and a volunteer search for every
 * picker in the admin. Two of them disclose nothing; /shift-panel discloses a
 * roster, which is names, so its gate is doing real work.
 */
const { test, expect, reset } = require( '../support/harness.js' );
const { restNamespace, restRoutes, postTypes } = require( '../support/source.js' );

test.beforeAll( reset );

const NS = restNamespace();

/**
 * Call a route the way the plugin's own scripts call it.
 *
 * Through wp.apiFetch, from inside a page that has it — not through
 * page.request. Cookie authentication is not enough on its own: WordPress
 * requires the `X-WP-Nonce` header before it will treat a cookied REST request
 * as coming from the signed-in user, and without it every route here answers
 * 401, which reads exactly like a broken permission callback.
 *
 * wp-api-fetch's nonce middleware is where that header comes from, and taking
 * it from `wpApiSettings` instead does not work — core hands the value to
 * apiFetch in an inline script rather than in a localized object. Driving
 * apiFetch is both simpler and more faithful: it is the request the browser
 * actually makes.
 *
 * @param {import('@playwright/test').Page} page  The page, on an admin screen.
 * @param {string}                          path  The route, e.g. '/gwc-vt/v1/volunteers'.
 * @return {Promise<{status: number, body: string}>} What came back.
 */
async function asStaff( page, path ) {
	return page.evaluate( async ( route ) => {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			throw new Error(
				'wp.apiFetch is not on this screen — wp-api-fetch is no longer enqueued.'
			);
		}

		try {
			const response = await window.wp.apiFetch( {
				path: route,
				parse: false,
			} );

			return { status: response.status, body: await response.text() };
		} catch ( error ) {
			return { status: 0, body: String( error && error.message ) };
		}
	}, path );
}

test.describe( 'the REST surface', () => {
	test( 'the plugin registers exactly the three routes it is meant to', async ( {
		page,
	} ) => {
		/* Read from the source, so a fourth route added without a test here
		 * fails this rather than going unnoticed. */
		expect( restRoutes() ).toEqual( [
			'/recurrence-preview',
			'/shift-panel',
			'/volunteers',
		] );

		const index = await page.request.get( `/wp-json/${ NS }` );

		expect( index.status() ).toBe( 200 );

		const listed = Object.keys( ( await index.json() ).routes || {} );

		for ( const route of restRoutes() ) {
			expect( listed ).toContain( `/${ NS }${ route }` );
		}
	} );

	test( 'no post type of this plugin is at /wp/v2', async ( { page } ) => {
		for ( const type of postTypes() ) {
			const response = await page.request.get( `/wp-json/wp/v2/${ type }` );

			/* 404: the route was never registered. Not 401 or 403, which would
			 * mean the collection exists and is merely guarded — and a guard is
			 * a thing that can be relaxed by a plugin, a filter, or somebody
			 * adding an authenticated integration later. */
			expect( response.status() ).toBe( 404 );
		}
	} );

	test( 'the volunteer search answers staff and nobody else', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		await admin.visit( 'gwc-vt-schedule' );

		const mine = await asStaff( page, `/${ NS }/volunteers?search=Marcus` );

		expect( mine.status ).toBe( 200 );

		const found = JSON.parse( mine.body );

		expect( Array.isArray( found ) ).toBe( true );
		expect(
			found.some( ( one ) => String( one.label ).includes( 'Marcus' ) )
		).toBe( true );

		expect( fixtures.volunteers[ 'Marcus Delacroix' ] ).toBeTruthy();
	} );

	test( 'the recurrence preview answers without writing anything', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( 'gwc-vt-schedule' );

		const before = api( 'posts', { type: 'gwc_vt_shift' } ).length;

		const response = await asStaff(
			page,
			`/${ NS }/recurrence-preview?start=2027-01-04&pattern=weekly&until=2027-02-01`
		);

		expect( response.status ).toBe( 200 );

		/* The route answers in sentences, already translated — which is why the
		 * script that draws this preview ships no strings of its own and takes
		 * no wp_set_script_translations() call. So this asserts what it says,
		 * not a machine-readable list of dates it does not return. */
		const preview = JSON.parse( response.body );

		expect( preview.count ).toBe( 5 );
		expect( preview.repeats ).toBe( true );
		expect( preview.detail ).toContain( 'January 4, 2027' );
		expect( preview.detail ).toContain( 'February 1, 2027' );

		/* And it says the thing that stops somebody expecting one row: each
		 * occurrence is a real shift with its own roster. */
		expect( preview.detail ).toContain( 'own roster' );

		/* A preview writes nothing. It is the only kind of GET this plugin
		 * has. */
		expect( api( 'posts', { type: 'gwc_vt_shift' } ).length ).toBe( before );
	} );

	test( 'the shift panel is the one route that guards a roster', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		await admin.visit( 'gwc-vt-schedule' );

		const shift = fixtures.shifts.find( ( one ) =>
			fixtures.signups.some(
				( signup ) =>
					signup.parent === one.id && Number( signup.volunteer ) > 0
			)
		);

		expect( shift ).toBeTruthy();

		const mine = await asStaff(
			page,
			`/${ NS }/shift-panel?shift=${ shift.id }`
		);

		expect( mine.status ).toBe( 200 );

		/* This is the one route that discloses a roster, and it is the reason
		 * its gate is edit_posts rather than something looser: it says who is
		 * coming, which on a site running a court-ordered service programme is
		 * a list of people working one off. */
		expect( mine.body ).toContain( 'Marcus' );
	} );
} );

test.describe( 'the REST surface, to a stranger', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'every route is closed to somebody who is not signed in', async ( {
		page,
		fixtures,
	} ) => {
		const shift = fixtures.shifts[ 0 ];

		const tries = [
			`/wp-json/${ NS }/volunteers?search=Marcus`,
			`/wp-json/${ NS }/shift-panel?shift=${ shift.id }`,
			`/wp-json/${ NS }/recurrence-preview?start=2027-01-04&pattern=weekly`,
		];

		for ( const url of tries ) {
			const response = await page.request.get( url );

			expect( response.status() ).toBeGreaterThanOrEqual( 401 );

			/* And it disclosed nothing on the way out. A refusal that named a
			 * volunteer would be the disclosure the gate exists to prevent. */
			const body = await response.text();

			for ( const name of Object.keys( fixtures.volunteers ) ) {
				expect( body ).not.toContain( name );
			}
		}
	} );
} );

test.describe( 'the calendar file', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	/** Long enough not to be taken for a machine. */
	const HUMAN = 3500;

	test( 'is served to the token that was emailed, and to nothing else', async ( {
		page,
		mailbox,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.schedule.url );

		await page
			.locator( 'input[name="gwc_vt_shift"]:not([disabled])' )
			.first()
			.check();
		await page.fill( '#gwcvt-signup-name', 'Thandeka Bergström' );
		await page.fill( '#gwcvt-signup-email', 'thandeka@example.test' );
		await page.waitForTimeout( HUMAN );
		await page.locator( 'button[name="gwc_vt_signup_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const [ mail ] = mailbox.to( 'thandeka@example.test' );

		const href = /href="([^"]*gwc_vt_signup=[^"]*)"/.exec( mail.message );

		await page.goto( href[ 1 ].replace( /&#0?38;|&amp;/g, '&' ) );

		const calendar = page.locator( 'a[href*="gwc_vt_ics="]' );

		await expect( calendar ).toBeVisible();

		const url = ( await calendar.getAttribute( 'href' ) ).replace(
			/&#0?38;|&amp;/g,
			'&'
		);

		const served = await page.request.get( url );

		expect( served.status() ).toBe( 200 );
		expect( served.headers()[ 'content-type' ] ).toContain( 'text/calendar' );

		const ics = await served.text();

		expect( ics ).toContain( 'BEGIN:VCALENDAR' );
		expect( ics ).toContain( 'BEGIN:VEVENT' );

		/* A private document, like the roster sheets and the letter. This was
		 * the one of the four with no Cache-Control at all. */
		expect( served.headers()[ 'cache-control' ] ).toContain( 'private' );

		/* And the token is what authorizes it. There is no session here and
		 * never will be, so a wrong token has to be the end of it. */
		const forged = await page.request.get(
			url.replace( /gwc_vt_k=[A-Za-z0-9]+/, 'gwc_vt_k=deadbeef' )
		);

		expect( forged.headers()[ 'content-type' ] || '' ).not.toContain(
			'text/calendar'
		);
	} );
} );

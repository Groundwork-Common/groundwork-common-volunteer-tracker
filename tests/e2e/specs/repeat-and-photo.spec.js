/**
 * Two screens that have nothing to do with each other and one thing in common:
 * each is the last of its kind, so neither earns a file.
 *
 * Covers gwc_vt_save_repeat and gwc_vt_photo.
 *
 * ── Changing a whole repeat ──────────────────────────────────────────────────
 * The screen is reached from an occurrence and never from the menu, because
 * "which repeat" is the one question it cannot ask you: a menu entry would land
 * on a form with nothing to apply across. Everything on it is opt-in — a
 * checkbox per field — so a save cannot quietly rewrite the six things somebody
 * did not mean to touch.
 *
 * ── Serving a photo ──────────────────────────────────────────────────────────
 * A volunteer's picture is not in the media library and has no public URL. It
 * is served by a handler that checks a capability on EVERY request, and answers
 * 403 rather than 404 to anybody who may not see it — because distinguishing
 * "no such volunteer" from "not yours to see" would tell anybody who can log in
 * at all whether a given record exists, which for these records is most of what
 * somebody would want to know.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.describe( 'changing a whole repeat', () => {
	test( 'applies only what was ticked, across every occurrence', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* The seeded weekly series. A repeat is what this screen exists for,
		 * and a single shift has nothing to apply across. */
		const series = fixtures.shifts.filter( ( shift ) => Number( shift.series ) > 0 );

		expect( series.length ).toBeGreaterThan( 1 );

		const one = series[ 0 ];
		const seriesId = Number( one.series );

		await admin.visit( 'gwc-vt-repeat', { shift: one.id } );

		const form = admin.formFor( 'gwc_vt_save_repeat' ).first();

		await expect( form ).toBeVisible();

		/* Tick the one field being changed, and nothing else. */
		await form.locator( 'input[value="location"]' ).check();
		await form
			.locator( 'input[name="gwc_vt_location"]' )
			.fill( 'The collection van' );

		await form
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule|page=gwc-vt-repeat/ );

		const after = api( 'posts', {
			type: 'gwc_vt_shift',
			meta: {
				series: '_gwc_vt_shift_series',
				location: '_gwc_vt_shift_location',
				activity: '_gwc_vt_shift_activity',
			},
		} ).filter( ( shift ) => Number( shift.series ) === seriesId );

		expect( after.length ).toBeGreaterThan( 1 );

		/* Every future occurrence moved. */
		const moved = after.filter(
			( shift ) => 'The collection van' === shift.location
		);

		expect( moved.length ).toBeGreaterThan( 1 );

		/* And nothing else did. The activity was not ticked, so it is what it
		 * was — which is the whole point of a per-field opt-in, and the thing
		 * that would break silently if a save started writing every field it
		 * was handed. */
		for ( const shift of after ) {
			expect( shift.activity ).toBe( one.activity );
		}
	} );

	test( 'is reached from an occurrence, and not from the menu', async ( {
		page,
		admin,
	} ) => {
		await admin.visit( 'gwc-vt-schedule' );

		await expect( page.locator( '#adminmenu' ) ).not.toContainText(
			'Change the whole repeat'
		);
	} );
} );

test.describe( "a volunteer's photograph", () => {
	test( 'is served to somebody who may see the record', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		/* The seed gives two applications a picture, which is where a photo
		 * first arrives: somebody offering to volunteer sent one. */
		await admin.visit( 'gwc-vt-applications' );

		const image = page.locator( 'img[src*="action=gwc_vt_photo"]' ).first();

		await expect( image ).toBeVisible();

		const src = await image.getAttribute( 'src' );

		const response = await page.request.get( src );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toMatch( /^image\// );

		/* Never cached by anything shared, and never sniffable into a document.
		 * These files are decoded and re-encoded on the way in, so an SVG
		 * cannot reach here — the header is the second lock on that door. */
		expect( response.headers()[ 'cache-control' ] ).toContain( 'private' );
		expect( response.headers()[ 'x-content-type-options' ] ).toBe( 'nosniff' );

		expect( fixtures.applications.length ).toBeGreaterThan( 0 );
	} );

	test( 'answers 403, not 404, to somebody who may not', async ( {
		page,
		browser,
		admin,
		api,
	} ) => {
		await admin.visit( 'gwc-vt-applications' );

		const src = await page
			.locator( 'img[src*="action=gwc_vt_photo"]' )
			.first()
			.getAttribute( 'src' );

		/* A subscriber: signed in, and with no business seeing volunteer
		 * records. */
		api( 'user.ensure', {
			login: 'gwcvt-e2e-subscriber',
			role: 'subscriber',
			pass: 'e2e-password',
		} );

		const context = await browser.newContext( {
			baseURL: page.url().split( '/wp-admin' )[ 0 ],
		} );
		const theirs = await context.newPage();

		await theirs.goto( '/wp-login.php' );
		await theirs.fill( '#user_login', 'gwcvt-e2e-subscriber' );
		await theirs.fill( '#user_pass', 'e2e-password' );
		await theirs.click( '#wp-submit' );

		const response = await theirs.request.get( src );

		/* 403 rather than 404. The difference is the disclosure: a 404 for a
		 * record that does not exist and a 403 for one that does would answer
		 * "is this person on file" to anybody with a login. */
		expect( response.status() ).toBe( 403 );

		await context.close();
	} );
} );

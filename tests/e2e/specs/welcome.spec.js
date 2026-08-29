/**
 * The one notice a new install gets, and the end of it.
 *
 * Covers gwc_vt_dismiss_welcome.
 *
 * ── Why this file exists at all ──────────────────────────────────────────────
 * It was written because coverage.spec.js failed. `gwc_vt_dismiss_welcome`
 * arrived on main while this suite was on a branch, and the guard noticed
 * before a person did — which is the whole reason the guard reads the source
 * rather than a list somebody keeps.
 *
 * ── Why it needs an empty site ───────────────────────────────────────────────
 * The notice has two end conditions and either one finishes it: somebody
 * dismisses it, or the site stops being new. Every other spec here runs against
 * a seeded organization, where the second condition is already met — so this is
 * the one file that purges and does not reseed, and it is the only place the
 * notice can be seen at all.
 */
const { test, expect, reset } = require( '../support/harness.js' );

/** Where the dismissal is remembered, per user. */
const META = 'gwc_vt_welcome_dismissed';

/** wp-env's administrator, who is who this suite is signed in as. */
const WHO = 'admin';

test.beforeAll( reset );

/* Put the fixture back for everything that runs after this file, whatever
 * happened in it. Every other spec file resets on its own way in, so this is
 * belt and braces — but a purged site is a bad thing to leave behind for
 * somebody debugging by hand. */
test.afterAll( reset );

test.describe( 'the welcome notice', () => {
	test( 'is not offered to an organization that is already running', async ( {
		page,
		admin,
		api,
	} ) => {
		api( 'user.meta', { login: WHO, key: META, clear: true } );

		await admin.visit( 'gwc-vt-dashboard' );

		/* The seeded site has six volunteers and twenty-six entries on it. It
		 * has not been new for a long time, and gwc_vt_has_any_records() is the
		 * same test the dashboard's "Start here" uses — so the two appear and
		 * go together rather than one outliving the other on one screen. */
		await expect( page.locator( '.gwcvt-welcome' ) ).toHaveCount( 0 );
	} );

	test( 'points a new install at the guide, and can be ended for good', async ( {
		page,
		admin,
		api,
	} ) => {
		api( 'purge' );
		api( 'user.meta', { login: WHO, key: META, clear: true } );

		await admin.visit( 'gwc-vt-dashboard' );

		const notice = page.locator( '.gwcvt-welcome' );

		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText( 'Start with the guide' );

		/* It offers the guide, which is the point of it. */
		await expect(
			notice.locator( `a[href*="${ 'gwc-vt-help' }"]` )
		).toHaveCount( 1 );

		/* And not on the guide itself: somebody reading it has followed the
		 * notice, or did not need it. */
		await admin.visit( 'gwc-vt-help' );

		await expect( page.locator( '.gwcvt-welcome' ) ).toHaveCount( 0 );

		/* Ending it is a link rather than core's dismiss button, because that
		 * button hides a notice for one page load and this promises more. */
		await admin.visit( 'gwc-vt-dashboard' );

		const end = page.locator( 'a[href*="action=gwc_vt_dismiss_welcome"]' );

		await expect( end ).toHaveCount( 1 );
		await end.click();

		/* Back where they were, by wp_get_referer() rather than by a return URL
		 * built out of REQUEST_URI — which is how an authenticated open
		 * redirect gets written. */
		await page.waitForURL( /page=gwc-vt-dashboard/ );

		await expect( page.locator( '.gwcvt-welcome' ) ).toHaveCount( 0 );

		/* Permanent, and remembered as the day rather than as a 1 — so that one
		 * day "when did this site stop being new" has an answer instead of a
		 * flag. */
		const remembered = api( 'user.meta', { login: WHO, key: META } );

		expect( remembered.value ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );

		/* Still gone on the next screen, and the one after that. */
		await admin.visit( 'gwc-vt-schedule' );

		await expect( page.locator( '.gwcvt-welcome' ) ).toHaveCount( 0 );
	} );

	test( 'is per person, so one reader does not decide for the next', async ( {
		page,
		browser,
		baseURL,
		admin,
		api,
	} ) => {
		api( 'purge' );
		api( 'user.meta', { login: WHO, key: META, value: '2026-01-01' } );

		/* The administrator has ended it. */
		await admin.visit( 'gwc-vt-dashboard' );

		await expect( page.locator( '.gwcvt-welcome' ) ).toHaveCount( 0 );

		/* The coordinator who joins in March did not dismiss anything in
		 * January, and is exactly the reader this is for. */
		api( 'user.ensure', { login: 'gwcvt-e2e-editor', role: 'editor' } );
		api( 'user.meta', { login: 'gwcvt-e2e-editor', key: META, clear: true } );

		const context = await browser.newContext( { baseURL } );
		const theirs = await context.newPage();

		try {
			await theirs.goto( '/wp-login.php' );
			await theirs.fill( '#user_login', 'gwcvt-e2e-editor' );
			await theirs.fill( '#user_pass', 'e2e-password' );
			await theirs.click( '#wp-submit' );
			await theirs.waitForURL( /wp-admin/ );

			await theirs.goto(
				'/wp-admin/edit.php?post_type=gwc_vt_entry&page=gwc-vt-dashboard'
			);

			await expect( theirs.locator( '.gwcvt-welcome' ) ).toBeVisible();
		} finally {
			await context.close();
		}
	} );

	test( 'a dismissal link cannot be spent on somebody else', async ( {
		page,
		browser,
		baseURL,
		admin,
		api,
	} ) => {
		api( 'purge' );
		api( 'user.meta', { login: WHO, key: META, clear: true } );
		api( 'user.ensure', { login: 'gwcvt-e2e-editor', role: 'editor' } );
		api( 'user.meta', { login: 'gwcvt-e2e-editor', key: META, clear: true } );

		await admin.visit( 'gwc-vt-dashboard' );

		/* The nonce carries the user id, so a link copied out of one person's
		 * page is not the other person's link. Harmless as mischief goes, and
		 * still not this link's business. */
		const href = await page
			.locator( 'a[href*="action=gwc_vt_dismiss_welcome"]' )
			.getAttribute( 'href' );

		const context = await browser.newContext( { baseURL } );
		const theirs = await context.newPage();

		try {
			await theirs.goto( '/wp-login.php' );
			await theirs.fill( '#user_login', 'gwcvt-e2e-editor' );
			await theirs.fill( '#user_pass', 'e2e-password' );
			await theirs.click( '#wp-submit' );
			await theirs.waitForURL( /wp-admin/ );

			const response = await theirs.goto( href );

			expect( response.status() ).toBe( 403 );

			expect(
				api( 'user.meta', {
					login: 'gwcvt-e2e-editor',
					key: META,
				} ).value || ''
			).toBe( '' );
		} finally {
			await context.close();
		}
	} );
} );

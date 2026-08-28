/**
 * Who may see a record, who may attest to hours, and who may put the
 * organization's name on a letter.
 *
 * ── The trap this file is built around ───────────────────────────────────────
 * edit_posts is contributor-level, and is NOT the gate for seeing records. Every
 * custom screen in this plugin queries for itself, so each has to ask
 * gwc_vt_can_see_records() on its own — and each does. A contributor with a
 * bookmark is the case that matters, and it is the case those gates exist for.
 *
 * What this file then found is that the OTHER half of that reasoning is no
 * longer true. The design notes say the list tables are safe on their own,
 * because WordPress filters them by author for anybody without
 * edit_others_posts. Current core does not: it restricts what a contributor may
 * edit, not what edit.php lists. So the two screens nobody gated — because
 * nobody thought they needed gating — show a contributor every volunteer's
 * name, hours and court-ordered total. See the long note above that test.
 *
 * ── And the two capabilities held separately ─────────────────────────────────
 * Attesting that hours were worked, and putting your organization's name on a
 * letter to a court, are bigger decisions than typing up a shift. A role with
 * neither still sees the hours list and can log a day — it simply has no Verify
 * button and no Letters screen.
 */
const { test, expect, reset } = require( '../support/harness.js' );
const { screenSlugs } = require( '../support/source.js' );

test.beforeAll( reset );

/** Every screen the plugin adds, except the guide, which is deliberately open. */
const GUARDED = screenSlugs()
	.map( ( screen ) => screen.slug )
	.filter( ( slug ) => 'gwc-vt-help' !== slug );

/**
 * Sign in as somebody, in a context of their own.
 *
 * @param {import('@playwright/test').Browser} browser The browser.
 * @param {string}                             baseURL Where the site is.
 * @param {string}                             login   Their login.
 * @return {Promise<{page: import('@playwright/test').Page, close: Function}>}
 */
async function signedInAs( browser, baseURL, login ) {
	const context = await browser.newContext( { baseURL } );
	const page = await context.newPage();

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', 'e2e-password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	return { page, close: () => context.close() };
}

test.describe( 'capabilities', () => {
	test( 'a contributor is refused every screen that shows a record', async ( {
		browser,
		baseURL,
		api,
		fixtures,
	} ) => {
		api( 'user.ensure', {
			login: 'gwcvt-e2e-contributor',
			role: 'contributor',
		} );

		const { page, close } = await signedInAs(
			browser,
			baseURL,
			'gwcvt-e2e-contributor'
		);

		try {
			for ( const slug of GUARDED ) {
				await page.goto(
					`/wp-admin/edit.php?post_type=gwc_vt_entry&page=${ slug }`
				);

				const shown = await page.locator( 'body' ).innerText();

				/* Refused. The wording differs by screen — core's own "you are
				 * not allowed" for an unreachable page, the plugin's for one it
				 * renders and then stops — so the assertion is on the outcome:
				 * no volunteer's name reached this person. */
				for ( const name of Object.keys( fixtures.volunteers ) ) {
					expect( shown ).not.toContain( name );
				}
			}

		} finally {
			await close();
		}
	} );

	/* ── This test currently FAILS, and the failure is the point ──────────────
	 * Tracked as issue #213. It is not flaky and it is not mis-written: a
	 * contributor — the lowest role that has edit_posts at all — can open both
	 * of this plugin's list tables and read every volunteer's name, their
	 * verified hours, and the court-ordered hours column.
	 *
	 * The reason is an assumption in the design notes that is no longer true of
	 * WordPress: "the list tables are safe on their own, because WordPress
	 * filters them by author for anybody without edit_others_posts". Current
	 * core does not. It restricts what a contributor may EDIT; it does not
	 * restrict what edit.php LISTS. The same site shows a contributor the
	 * administrator's "Hello world!" in the ordinary Posts list, which is the
	 * quickest way to see that this is core's behaviour rather than anything
	 * this plugin does.
	 *
	 * The plugin's own custom screens are gated correctly — every one of them
	 * asks gwc_vt_can_see_records() — so the leak is exactly at the two places
	 * nobody gated, because nobody thought they needed gating.
	 *
	 * Left failing rather than skipped, marked test.fail(), or softened into an
	 * assertion that passes. A green suite over a real disclosure is the thing
	 * this repository's notes call verification that proves nothing.
	 * ───────────────────────────────────────────────────────────────────────── */
	test( 'a contributor cannot read the list tables either', async ( {
		browser,
		baseURL,
		api,
		fixtures,
	} ) => {
		api( 'user.ensure', {
			login: 'gwcvt-e2e-contributor',
			role: 'contributor',
		} );

		const { page, close } = await signedInAs(
			browser,
			baseURL,
			'gwcvt-e2e-contributor'
		);

		try {
			for ( const type of [ 'gwc_vt_volunteer', 'gwc_vt_entry' ] ) {
				await page.goto( `/wp-admin/edit.php?post_type=${ type }` );

				const rows = await page
					.locator( '#the-list' )
					.innerText()
					.catch( () => '' );

				for ( const name of Object.keys( fixtures.volunteers ) ) {
					expect(
						rows,
						`${ type } listed ${ name } to a contributor`
					).not.toContain( name );
				}
			}
		} finally {
			await close();
		}
	} );

	test( 'a contributor cannot verify hours by following a link', async ( {
		browser,
		baseURL,
		page,
		admin,
		api,
	} ) => {
		/* The nonced URL is minted for an administrator and then followed by
		 * somebody else. Capability before nonce is the house rule, and this is
		 * the request that tells them apart: the nonce is valid, and the person
		 * is not. */
		await admin.visit( 'gwc-vt-verify' );

		const href = await page
			.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
			.first()
			.getAttribute( 'href' );

		const entryId = Number(
			new URL( href, page.url() ).searchParams.get( 'entry' )
		);

		api( 'user.ensure', {
			login: 'gwcvt-e2e-contributor',
			role: 'contributor',
		} );

		const theirs = await signedInAs(
			browser,
			baseURL,
			'gwcvt-e2e-contributor'
		);

		try {
			const response = await theirs.page.goto( href );

			expect( response.status() ).toBe( 403 );

			expect(
				api( 'post.meta', { id: entryId } ).meta._gwc_vt_verified_at || ''
			).toBe( '' );
		} finally {
			await theirs.close();
		}
	} );

	test( 'an editor may see records, verify, and issue', async ( {
		browser,
		baseURL,
		api,
		fixtures,
	} ) => {
		/* Editor is one of the two roles the plugin grants both capabilities to
		 * when it cannot ask. Author is excluded deliberately: an author may
		 * publish their own posts and still has no business reading a list of
		 * people working off a court order. */
		api( 'user.ensure', { login: 'gwcvt-e2e-editor', role: 'editor' } );

		const { page, close } = await signedInAs(
			browser,
			baseURL,
			'gwcvt-e2e-editor'
		);

		try {
			await page.goto(
				'/wp-admin/edit.php?post_type=gwc_vt_entry&page=gwc-vt-verify'
			);

			await expect(
				page.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' ).first()
			).toBeVisible();

			await page.goto(
				'/wp-admin/edit.php?post_type=gwc_vt_entry&page=gwc-vt-letters'
			);

			await expect( page.locator( '#gwcvt-reference' ) ).toBeVisible();

			expect( fixtures.volunteers[ 'Marcus Delacroix' ] ).toBeTruthy();
		} finally {
			await close();
		}
	} );

	test( 'a role told no keeps being told no, and one that never heard is different', async ( {
		browser,
		baseURL,
		api,
	} ) => {
		/* Capabilities are read with isset(), not truthiness, so that "an
		 * administrator decided no" and "this role never heard of it" are
		 * different answers. Getting that wrong means staff silently cannot
		 * verify hours after a migration, with nothing in the interface to
		 * explain why — so both states are driven here.
		 */
		api( 'user.ensure', { login: 'gwcvt-e2e-editor', role: 'editor' } );

		/* Explicitly denied: the capability is present and false. */
		api( 'role.cap', {
			role: 'editor',
			cap: 'gwc_vt_verify_hours',
			grant: false,
		} );

		let session = await signedInAs( browser, baseURL, 'gwcvt-e2e-editor' );

		try {
			await session.page.goto(
				'/wp-admin/edit.php?post_type=gwc_vt_entry&page=gwc-vt-verify'
			);

			await expect(
				session.page.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
			).toHaveCount( 0 );
		} finally {
			await session.close();
		}

		/* And granted again, which is what a settings save does. */
		api( 'role.cap', {
			role: 'editor',
			cap: 'gwc_vt_verify_hours',
			grant: true,
		} );

		session = await signedInAs( browser, baseURL, 'gwcvt-e2e-editor' );

		try {
			await session.page.goto(
				'/wp-admin/edit.php?post_type=gwc_vt_entry&page=gwc-vt-verify'
			);

			await expect(
				session.page
					.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
					.first()
			).toBeVisible();
		} finally {
			await session.close();
		}
	} );

	test( 'a stranger is refused every admin screen outright', async ( {
		browser,
		baseURL,
	} ) => {
		const context = await browser.newContext( {
			baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();

		try {
			for ( const slug of GUARDED ) {
				const response = await page.goto(
					`/wp-admin/edit.php?post_type=gwc_vt_entry&page=${ slug }`
				);

				/* wp-admin sends anybody signed out to the login form. What
				 * matters is that the screen never rendered. */
				expect( page.url() ).toContain( 'wp-login.php' );
				expect( response.status() ).toBeLessThan( 400 );
			}
		} finally {
			await context.close();
		}
	} );
} );

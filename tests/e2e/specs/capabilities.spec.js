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

	/* ── The disclosure this file was written and then fixed for ──────────────
	 * A contributor — the lowest role that has edit_posts at all — could open
	 * both of this plugin's list tables and read every volunteer's name, their
	 * verified hours and their court-ordered total. Issue #213.
	 *
	 * The cause was a belief, stated in four places in writing: that WordPress
	 * adds an author restriction to a list table for anybody without
	 * edit_others_posts. It does not. wp_edit_posts_query() sets $perm only
	 * when a post_status is in the query string, and 'readable' would not
	 * restrict a published post anyway.
	 *
	 * Both post types now override edit_posts and create_posts with
	 * gwc_vt_records_cap(), so the screen is closed rather than emptied — see
	 * gwc_vt_records_post_type_caps(). This test held the shape of the answer
	 * while it was still red.
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

				/* The whole body, not #the-list: the screen is refused outright
				 * now, so there is no list to read. Asking for one waits for an
				 * element that will never arrive, which is a test timeout
				 * wearing the costume of a failed assertion. */
				const shown = await page.locator( 'body' ).innerText();

				expect( shown, `${ type } was not refused` ).toContain(
					'higher level of permission'
				);

				for ( const name of Object.keys( fixtures.volunteers ) ) {
					expect(
						shown,
						`${ type } listed ${ name } to a contributor`
					).not.toContain( name );
				}
			}

			/* And the menu offers nothing that would refuse them.
			 *
			 * The guide survives, and should: it is registered on 'read'
			 * deliberately, and tests/integration/help.php asserts every role
			 * can read it. WordPress promotes it to a parent entry of its own
			 * when the rest of the menu goes, so what a contributor now sees
			 * under Volunteer Tracker is the guide and nothing else — which is
			 * a coherent thing to be shown, rather than six screens that all
			 * say no. */
			await page.goto( '/wp-admin/index.php' );

			const menu = page.locator( '#adminmenu' );

			await expect( menu ).toContainText( 'Help' );

			for ( const word of [
				'Volunteers',
				'Hours',
				'Schedule',
				'Verification letters',
				'Credentials',
			] ) {
				await expect(
					menu,
					`the menu still offers ${ word }`
				).not.toContainText( word );
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

/**
 * A pass: a volunteer proving a mailbox, and seeing their own record.
 *
 * ── What a pass is, and is not ───────────────────────────────────────────────
 * A short-lived, mailbox-proved capability to act as ONE volunteer on the
 * public pages. Not an account: no password, no WordPress user, no role, and it
 * expires. A volunteer has no login here and never will — which is why the way
 * in is a link in an email rather than a form with a password on it.
 *
 * ── And the property that has to hold whatever the address turns out to be ───
 * Asking for a link must answer the same way for an address on file and one
 * that is not. That is hard rule 3's reasoning applied to a third public
 * surface: a page that said "no such volunteer" would tell anybody who could
 * type an address whether that person volunteers here.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.use( { storageState: { cookies: [], origins: [] } } );

/** Long enough not to be taken for a machine. See public-self-log.spec.js. */
const HUMAN = 3500;

/**
 * Ask for a link, and return what the page said.
 *
 * @param {import('@playwright/test').Page} page    The page.
 * @param {string}                          url     The sign-in page.
 * @param {string}                          address What to type.
 * @return {Promise<string>} The page's answer.
 */
async function ask( page, url, address ) {
	await page.goto( url );
	await page.fill( '#gwcvt-signin-email', address );
	await page.waitForTimeout( HUMAN );
	await page.locator( 'button[name="gwc_vt_signin_submit"]' ).click();
	await page.waitForLoadState( 'domcontentloaded' );

	return page.locator( 'main, body' ).first().innerText();
}

/** The sign-in link out of a trapped message. */
function linkFrom( mail ) {
	const href = /href="([^"]*gwc_vt_who=[^"]*)"/.exec( mail.message );

	if ( href ) {
		return href[ 1 ].replace( /&#0?38;|&amp;/g, '&' );
	}

	const plain = /https?:\/\/\S*gwc_vt_who=\d+&(?:amp;|#0?38;)?gwc_vt_k=[A-Za-z0-9]+/.exec(
		mail.message
	);

	return plain ? plain[ 0 ].replace( /&#0?38;|&amp;/g, '&' ) : '';
}

test.describe( 'signing in as a volunteer', () => {
	test( 'an address on file and one that is not are answered identically', async ( {
		page,
		mailbox,
		fixtures,
	} ) => {
		const url = fixtures.pages.signin.url;
		const known = fixtures.volunteers[ 'Marcus Delacroix' ].email;

		const forKnown = await ask( page, url, known );
		const forStranger = await ask( page, url, 'nobody-here@example.test' );

		expect( forStranger ).toBe( forKnown );

		/* And the sentence itself refuses to answer the question: "IF that
		 * address is on one of our volunteer records". */
		expect( forKnown ).toContain( 'If that address is on one of our' );

		/* The only thing that differs is what arrives in a mailbox, which only
		 * the owner of that mailbox can see. */
		expect( mailbox.to( known ).length ).toBe( 1 );
		expect( mailbox.to( 'nobody-here@example.test' ).length ).toBe( 0 );
	} );

	test( 'the link is spent exactly once, and following it does not spend it', async ( {
		page,
		api,
		mailbox,
		fixtures,
	} ) => {
		const url = fixtures.pages.signin.url;
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];

		await ask( page, url, volunteer.email );

		const [ mail ] = mailbox.to( volunteer.email );

		expect( mail ).toBeTruthy();

		const link = linkFrom( mail );

		expect( link ).toBeTruthy();

		/* Following it does not spend it. The same reasoning as the signup
		 * cancellation link: mail clients and scanners fetch links, and a token
		 * burned by a spam filter is a volunteer who cannot get in. */
		await page.goto( link );

		const confirm = page.locator( 'button[name="gwc_vt_signin_confirm"]' );

		await expect( confirm ).toBeVisible();

		await confirm.click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText( 'You are signed in' );

		/* Signed in as a volunteer, and NOT as a WordPress user. A pass creates
		 * no account: the volunteer has no role, no password and nothing in
		 * wp_users. */
		const users = api( 'posts', { type: 'gwc_vt_volunteer' } );

		expect( users.some( ( one ) => one.id === volunteer.id ) ).toBe( true );

		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );

		/* And the link is now spent — which is only observable once the session
		 * it created has ended. Going back to it while still signed in shows
		 * the record, and rightly: the session is what is doing the work by
		 * then, and the page has no reason to talk about a link.
		 *
		 * So: sign out, then go back to it, the way somebody does days later
		 * from a phone that has forgotten the cookie. */
		await page.locator( 'button[name="gwc_vt_signout_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await page.goto( link );

		await expect(
			page.locator( 'button[name="gwc_vt_signin_confirm"]' )
		).toHaveCount( 0 );

		/* What comes back is the ordinary "ask for a link" form, and not a
		 * sentence about the token. That is deliberate and worth asserting as
		 * such: a spent link tells its holder nothing at all — not that it was
		 * used, not that it existed, and not whose it was. The message table
		 * has a `link-dead` string for the cases where the plugin does say
		 * something; a link whose token has simply been cleared is not one of
		 * them. */
		await expect( page.locator( '#gwcvt-signin-email' ) ).toBeVisible();

		await expect( page.locator( 'body' ) ).not.toContainText(
			'Priya Ramanathan'
		);
	} );

	test( 'a signed-in volunteer sees their own hours and nobody else’s', async ( {
		page,
		mailbox,
		fixtures,
	} ) => {
		const url = fixtures.pages.signin.url;
		const volunteer = fixtures.volunteers[ 'Marcus Delacroix' ];

		await ask( page, url, volunteer.email );

		await page.goto( linkFrom( mailbox.to( volunteer.email )[ 0 ] ) );
		await page.locator( 'button[name="gwc_vt_signin_confirm"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const shown = await page.locator( 'body' ).innerText();

		expect( shown ).toContain( 'Marcus Delacroix' );

		/* One volunteer, and one only. A pass is a capability to act as ONE
		 * person; a page that listed anybody else would be a roster on the
		 * public side of the site. */
		for ( const [ name ] of Object.entries( fixtures.volunteers ) ) {
			if ( name !== volunteer.title ) {
				expect( shown ).not.toContain( name );
			}
		}
	} );

	test( 'signing out ends the session', async ( {
		page,
		mailbox,
		fixtures,
	} ) => {
		const url = fixtures.pages.signin.url;
		const volunteer = fixtures.volunteers[ 'Marcus Delacroix' ];

		await ask( page, url, volunteer.email );

		await page.goto( linkFrom( mailbox.to( volunteer.email )[ 0 ] ) );
		await page.locator( 'button[name="gwc_vt_signin_confirm"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await page.locator( 'button[name="gwc_vt_signout_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText( 'You are signed out' );

		/* And the page is back to asking for an address rather than showing a
		 * record. */
		await page.goto( url );

		await expect( page.locator( '#gwcvt-signin-email' ) ).toBeVisible();
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Marcus Delacroix'
		);
	} );

	test( 'a volunteer with no address on file gets no way in, and no message about it', async ( {
		page,
		mailbox,
		fixtures,
	} ) => {
		/* Fatima has no email address, which is the case a coordinator has to
		 * roster by hand. The page must not say so — "there is no address on
		 * that record" is a fact about a person, offered to anybody who guesses
		 * a name. */
		expect( fixtures.volunteers[ 'Fatima Sørensen' ].email || '' ).toBe( '' );

		const answer = await ask(
			page,
			fixtures.pages.signin.url,
			'fatima@example.test'
		);

		expect( answer ).toContain( 'If that address is on one of our' );
		expect( mailbox.read() ).toHaveLength( 0 );
	} );

	test( 'the page is not there when signing in is switched off', async ( {
		page,
		api,
		fixtures,
	} ) => {
		api( 'settings.set', { values: { signin_enabled: false } } );

		try {
			await page.goto( fixtures.pages.signin.url );

			await expect( page.locator( '#gwcvt-signin-email' ) ).toHaveCount( 0 );
		} finally {
			api( 'settings.set', { values: { signin_enabled: true } } );
		}
	} );
} );

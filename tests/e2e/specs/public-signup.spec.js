/**
 * Signing up for a shift from the front of the site, and cancelling again.
 *
 * ── The two rules this surface is built around ───────────────────────────────
 * THE LIST IS PUBLIC AND THE ROSTER IS NOT. A visitor may see that Saturday
 * exists, what the work is, and that three places are left. They may never see
 * who is coming — on a site running a court-ordered service programme, "who is
 * volunteering Saturday" is a list of people working one off, and a place count
 * is not.
 *
 * NOTHING MUTATES ON GET. The cancellation link in an email lands on a page
 * with a button. Mail clients and security scanners fetch links, and a GET that
 * withdrew a signup would eventually be withdrawn by a spam filter.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.use( { storageState: { cookies: [], origins: [] } } );

/** Long enough not to be taken for a machine. See public-self-log.spec.js. */
const HUMAN = 3500;

test.describe( 'signing up from the site', () => {
	test( 'the list shows what is on and how many places are left, and never who', async ( {
		page,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.schedule.url );

		const list = await page.locator( 'body' ).innerText();

		/* What is on. */
		expect( list ).toMatch( /Sorting the produce delivery|Packing weekend boxes/ );

		/* And not one name. Every seeded volunteer is on a shift somewhere;
		 * none of them may appear here. */
		for ( const name of Object.keys( fixtures.volunteers ) ) {
			expect( list ).not.toContain( name );
		}
	} );

	test( 'a stranger can take a place, and is emailed a way out', async ( {
		page,
		api,
		mailbox,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.schedule.url );

		/* The first shift still taking signups. Chosen off the page rather than
		 * out of the fixture map, because "still taking signups" is the
		 * screen's judgement and it is the one a visitor acts on. */
		const choice = page.locator( 'input[name="gwc_vt_shift"]:not([disabled])' ).first();

		await expect( choice ).toBeVisible();

		const shiftId = Number( await choice.inputValue() );

		await choice.check();

		await page.fill( '#gwcvt-signup-name', 'Halvard Nkemelu' );
		await page.fill( '#gwcvt-signup-email', 'halvard@example.test' );

		await page.waitForTimeout( HUMAN );

		await page.locator( 'button[name="gwc_vt_signup_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText(
			'you are signed up'
		);

		const signups = api( 'posts', {
			type: 'gwc_vt_signup',
			meta: {
				claimName: '_gwc_vt_signup_claim_name',
				claimEmail: '_gwc_vt_signup_claim_email',
				volunteer: '_gwc_vt_signup_volunteer',
			},
		} ).filter( ( signup ) => 'halvard@example.test' === signup.claimEmail );

		expect( signups.length ).toBe( 1 );
		expect( signups[ 0 ].parent ).toBe( shiftId );

		/* On nobody, like a self-logged hour. A stranger's signup is a claim
		 * until somebody says whose it is. */
		expect( Number( signups[ 0 ].volunteer ) ).toBe( 0 );

		/* The confirmation, with a way out in it. Only the address that signed
		 * up receives this, which is the one place the two outcomes of the
		 * duplicate check are allowed to differ. */
		const sent = mailbox.to( 'halvard@example.test' );

		expect( sent.length ).toBe( 1 );
		expect( sent[ 0 ].message ).toContain( 'gwc_vt_signup' );
	} );

	test( 'the cancellation link lands on a button, and does not act on its own', async ( {
		page,
		api,
		mailbox,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.schedule.url );

		const choice = page
			.locator( 'input[name="gwc_vt_shift"]:not([disabled])' )
			.first();

		await choice.check();
		await page.fill( '#gwcvt-signup-name', 'Odalys Ferreira' );
		await page.fill( '#gwcvt-signup-email', 'odalys@example.test' );
		await page.waitForTimeout( HUMAN );
		await page.locator( 'button[name="gwc_vt_signup_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const [ mail ] = mailbox.to( 'odalys@example.test' );

		expect( mail ).toBeTruthy();

		/* Taken out of the href, and its entities decoded.
		 *
		 * The message is HTML, and WordPress writes the separating ampersand as
		 * `&#038;` — a numeric entity, not `&amp;`. A regex that swept the raw
		 * text up to the next space therefore carried `&#038;gwc_vt_k=` into
		 * the URL, the token arrived mangled, and the page answered "that link
		 * is no longer valid": the right answer to the wrong question, which
		 * reads exactly like a broken feature. */
		const href = /href="([^"]*gwc_vt_signup=[^"]*)"/.exec( mail.message );

		expect( href ).toBeTruthy();

		const link = [
			href[ 1 ].replace( /&#0?38;|&amp;/g, '&' ),
		];

		const signup = () =>
			api( 'posts', {
				type: 'gwc_vt_signup',
				meta: { claimEmail: '_gwc_vt_signup_claim_email' },
			} ).find( ( one ) => 'odalys@example.test' === one.claimEmail );

		const before = signup();

		expect( before.status ).toBe( 'publish' );

		/* Following the link changes nothing. A mail client, a link scanner or
		 * an over-eager spam filter fetches every URL in a message; a GET that
		 * withdrew a signup would eventually be withdrawn by software. */
		await page.goto( link[ 0 ] );

		expect( signup().status ).toBe( 'publish' );

		/* It lands on a page with a button, and the button is what acts. */
		const cancel = page.locator( 'button[name="gwc_vt_cancel_submit"]' );

		await expect( cancel ).toBeVisible();

		await cancel.click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText(
			'taken off that shift'
		);

		expect( signup().status ).toBe( 'gwc_vt_withdrawn' );
	} );

	test( 'a shift that asks for a credential asks people to sign in first', async ( {
		page,
		fixtures,
	} ) => {
		/* This is a fact about the SHIFT and not about the visitor. Every
		 * visitor sees the same answer for the same shift whether or not they
		 * have ever volunteered here, so it cannot be made to say whether a
		 * named person is on file. Hard rules 3 and 4 are about not answering
		 * questions about a person; this answers one about a Saturday. */
		await page.goto( fixtures.pages.schedule.url );

		const words = await page.locator( 'body' ).innerText();

		/* The sentence names the credential and the way in, and nothing else:
		 * "Needs <credential> — sign in to take this one". */
		expect( words ).toContain( 'sign in to take this one' );

		/* And it is said the same way to everybody, which is what makes it
		 * safe: the sentence names what the shift asks for, never who holds
		 * it. */
		for ( const name of Object.keys( fixtures.volunteers ) ) {
			expect( words ).not.toContain( name );
		}
	} );

	test( 'a honeypotted signup is answered exactly as a real one, and stores nothing', async ( {
		page,
		api,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.schedule.url );

		await page
			.locator( 'input[name="gwc_vt_shift"]:not([disabled])' )
			.first()
			.check();
		await page.fill( '#gwcvt-signup-name', 'Real Person' );
		await page.fill( '#gwcvt-signup-email', 'real@example.test' );
		await page.waitForTimeout( HUMAN );
		await page.locator( 'button[name="gwc_vt_signup_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const real = await page.locator( 'body' ).innerText();

		const before = api( 'posts', { type: 'gwc_vt_signup' } ).length;

		await page.goto( fixtures.pages.schedule.url );

		await page
			.locator( 'input[name="gwc_vt_shift"]:not([disabled])' )
			.first()
			.check();
		await page.fill( '#gwcvt-signup-name', 'A Machine' );
		await page.fill( '#gwcvt-signup-email', 'machine@example.test' );
		await page.fill( '#gwcvt-signup-website', 'https://example.invalid/' );
		await page.waitForTimeout( HUMAN );
		await page.locator( 'button[name="gwc_vt_signup_submit"]' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		/* One shared limiter and one shared set of defences across both public
		 * forms — a script hammering the signup form and a script hammering the
		 * hours form are the same script. */
		expect( await page.locator( 'body' ).innerText() ).toBe( real );
		expect( api( 'posts', { type: 'gwc_vt_signup' } ).length ).toBe( before );
	} );

	test( 'the form is not there when signups are switched off', async ( {
		page,
		api,
		fixtures,
	} ) => {
		api( 'settings.set', { values: { signup_enabled: false } } );

		try {
			await page.goto( fixtures.pages.schedule.url );

			await expect(
				page.locator( 'button[name="gwc_vt_signup_submit"]' )
			).toHaveCount( 0 );

			const before = api( 'posts', { type: 'gwc_vt_signup' } ).length;

			/* And the handler is off, not merely the form. */
			await page.request.post( fixtures.pages.schedule.url, {
				form: {
					gwc_vt_signup_submit: '1',
					gwc_vt_name: 'Should Not Land',
					gwc_vt_email: 'nope@example.test',
				},
			} );

			expect( api( 'posts', { type: 'gwc_vt_signup' } ).length ).toBe(
				before
			);
		} finally {
			api( 'settings.set', { values: { signup_enabled: true } } );
		}
	} );
} );

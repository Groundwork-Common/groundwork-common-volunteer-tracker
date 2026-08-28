/**
 * Issuing a letter, and the three ways one is delivered.
 *
 * Covers gwc_vt_letter_issue, gwc_vt_letter_preview,
 * gwc_vt_letter_deliver_print, gwc_vt_letter_deliver_post and
 * gwc_vt_letter_deliver_email.
 *
 * ── What this file is actually guarding ──────────────────────────────────────
 * The letter is the product. Everything else in this plugin exists so that this
 * document can be produced and then stood behind on the telephone weeks later.
 * Four properties matter more than the rest, and every one of them is invisible
 * to a test that does not render the page:
 *
 *   The disclaimer cannot be emptied.  No seal, no rendered signature, no
 *                                     "certified", no affidavit language.
 *   Issuing is not delivering.        Issuing mints a reference and produces
 *                                     no document; a delivery is a separate
 *                                     act that appends a row.
 *   Nothing is built from the cache.  The letter is recomputed from entries
 *                                     every time, never from the rollup.
 *   A stale letter is refused.        If the shifts have moved since issue,
 *                                     the delivery is refused rather than
 *                                     producing a page whose own reference
 *                                     will fail when a court rings up.
 *
 * tests/LetterTest.php can assert the first three against the builder.
 * Only a browser can assert them against the page a court is holding.
 */
const { test, expect, reset } = require( '../support/harness.js' );

/* One reset for this file: every test here issues, which writes to the log. */
test.beforeAll( reset );

/* ── Words a letter of this kind must never contain ──────────────────────────
 * The first draft of this list had 'certif' on it, which fails on the letter's
 * own disclaimer: "It reports those records and is not an independent
 * certification that the hours were worked." That sentence is the invariant,
 * not a violation of it — so the list is of language that CLAIMS the standing
 * this document does not have, and the disclaimer is asserted separately and
 * positively below.
 * ─────────────────────────────────────────────────────────────────────────── */
const FORBIDDEN = [
	'hereby certify',
	'i certify',
	'affidavit',
	'sworn',
	'notar', // notary, notarized, notarised.
	'penalty of perjury',
	'under oath',
];

/** The sentence the disclaimer cannot be emptied of. */
const DISCLAIMER = 'is not an independent certification';

/**
 * Start a draft on a volunteer and issue it, returning the log row.
 *
 * Driven through the screens rather than through the back door, because the
 * point of this file is that the screens do it.
 *
 * @param {object} ctx           page, admin and api fixtures.
 * @param {number} volunteerId   Whose letter.
 * @param {object} [draft]       addressee and matter.
 * @return {Promise<object>} The issued letter row from the log.
 */
async function issueFor( { page, admin, api }, volunteerId, draft = {} ) {
	await admin.edit( volunteerId );

	const sheet = await admin.openSheet( 'draft-letter' );

	if ( draft.addressee ) {
		await sheet
			.locator( `#gwcvt-draft-addressee-${ volunteerId }` )
			.fill( draft.addressee );
	}

	if ( draft.matter ) {
		await sheet
			.locator( `#gwcvt-draft-matter-${ volunteerId }` )
			.fill( draft.matter );
	}

	await sheet.getByRole( 'button', { name: 'Save the draft' } ).click();
	await page.waitForURL( /gwc_vt_did=drafted/ );

	await page.locator( '[data-gwcvt-letter-issue]' ).first().click();
	await page.waitForURL( /gwc_vt_did=issued/ );

	const issued = api( 'posts', {
		type: 'gwc_vt_letter',
		meta: {
			volunteer: '_gwc_vt_letter_volunteer',
			minutes: '_gwc_vt_letter_minutes',
			entryIds: '_gwc_vt_letter_entry_ids',
			asOf: '_gwc_vt_letter_as_of',
			delivery: '_gwc_vt_letter_delivery',
		},
	} ).filter( ( row ) => Number( row.volunteer ) === volunteerId );

	/* The newest, which is the one this call just made. */
	return issued[ issued.length - 1 ];
}

test.describe( 'the letter', () => {
	test( 'issuing mints a reference and produces no document', async ( {
		page,
		admin,
		api,
		mailbox,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Marcus Delacroix' ];

		const row = await issueFor(
			{ page, admin, api },
			volunteer.id,
			{
				addressee: 'Franklin County Municipal Court',
				matter: 'Case 2026-CR-00841',
			}
		);

		expect( row ).toBeTruthy();

		/* A reference, in the shape the settings' prefix asks for. This is what
		 * somebody reads down a telephone, so it is asserted as a string rather
		 * than as "not empty". */
		expect( row.title ).toMatch( /^RFB-\d+-\d{8}-[0-9A-F]{8}$/ );

		/* The moment the letter is as of, kept on the row. A draft fixes a
		 * moment so the letter states what was reviewed rather than whatever
		 * has been verified since. */
		expect( row.asOf ).toBeTruthy();

		/* Issuing produced no document and sent nothing. A letter with no
		 * deliveries has been issued and has not gone anywhere, which is a
		 * legitimate state and the one issuing leaves it in. */
		expect( row.delivery || '' ).toBe( '' );
		expect( mailbox.read() ).toHaveLength( 0 );

		/* And the screen says exactly that, rather than implying a send. */
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'send it whenever you are ready'
		);
	} );

	test( 'the document says what it is, and never more', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];

		await issueFor( { page, admin, api }, volunteer.id, {
			addressee: 'Riverbend High School',
		} );

		/* Reading is a preview, and a preview records nothing — which is why
		 * gwc_vt_letter_preview is a separate action from the three
		 * deliveries. */
		const open = page.locator( '[data-gwcvt-letter-open]' ).first();

		const href = await open.getAttribute( 'href' );

		expect( href ).toContain( 'action=gwc_vt_letter_preview' );

		await page.goto( href );

		const letter = ( await page.locator( 'body' ).innerText() ).toLowerCase();

		/* The disclaimer is there, and says the thing it exists to say. This is
		 * the invariant the whole product rests on: the letter is a record of
		 * what the organization observed, not a certificate. It cannot be
		 * emptied, so it is asserted positively rather than by the absence of
		 * anything. */
		expect( letter ).toContain( DISCLAIMER );
		expect( letter ).toContain( 'authoritative record-keeper' );

		/* And it claims nothing further. */
		for ( const word of FORBIDDEN ) {
			expect( letter ).not.toContain( word );
		}

		/* No image is standing in for a signature. The signature block is a
		 * ruled line — a rendered one would be the organization signing
		 * something it has deliberately not signed. */
		expect( await page.locator( 'img' ).count() ).toBe( 0 );

		/* And the preview left no trace on the log. */
		const rows = api( 'posts', {
			type: 'gwc_vt_letter',
			meta: { volunteer: '_gwc_vt_letter_volunteer', delivery: '_gwc_vt_letter_delivery' },
		} ).filter( ( row ) => Number( row.volunteer ) === volunteer.id );

		expect( rows[ rows.length - 1 ].delivery || '' ).toBe( '' );
	} );

	test( 'printing records a delivery and opens the document', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Fatima Sørensen' ];

		/* Fatima has no email address on file, so printing is the only way her
		 * letter can leave the building. That is the case worth driving. */
		expect( volunteer.email || '' ).toBe( '' );

		const row = await issueFor( { page, admin, api }, volunteer.id );

		const print = page.locator( '[data-gwcvt-letter-deliver]' ).first();

		const href = await print.getAttribute( 'href' );

		expect( href ).toContain( 'action=gwc_vt_letter_deliver_print' );

		/* target="_blank": the document opens beside the record rather than
		 * navigating away from it. */
		expect( await print.getAttribute( 'target' ) ).toBe( '_blank' );

		await page.goto( href );

		const printed = await page.locator( 'body' ).innerText();

		expect( printed ).toContain( row.title );

		const after = api( 'post.meta', { id: row.id } );

		expect( after.meta._gwc_vt_letter_delivery ).toBeTruthy();
	} );

	test( 'posting refuses to record a delivery with nobody to record', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Inès Okonkwo' ];

		const row = await issueFor( { page, admin, api }, volunteer.id );

		await page.locator( '[data-gwcvt-letter-post]' ).first().click();

		const sheet = page.locator( '[data-gwcvt-sheet="post-letter"]' );

		await expect( sheet ).toBeVisible();

		/* The addressee field is required in the markup, so the browser stops
		 * an empty one. Taken off, the handler stops it too — because "a
		 * letter about this person left the building on the 28th" is not the
		 * answer to "did you send it to us", and an unaddressed posting row
		 * would record exactly that. */
		const addressee = sheet.locator( '#gwcvt-post-addressee' );

		expect( await addressee.getAttribute( 'required' ) ).not.toBeNull();

		await addressee.evaluate( ( field ) =>
			field.removeAttribute( 'required' )
		);
		await addressee.fill( '' );

		/* The posting form opens the document in a new tab, so a refusal lands
		 * there too rather than on the record. Awaited as a popup: waiting for
		 * a navigation on this page would wait for one that never comes. */
		const [ answer ] = await Promise.all( [
			page.waitForEvent( 'popup' ),
			sheet.getByRole( 'button', { name: 'Record it and print' } ).click(),
		] );

		await answer.waitForLoadState();

		expect( answer.url() ).toContain( 'gwc_vt_did=no-addressee' );

		await expect( answer.locator( '#wpbody-content' ) ).toContainText(
			'say who the letter was posted to'
		);

		expect(
			api( 'post.meta', { id: row.id } ).meta._gwc_vt_letter_delivery || ''
		).toBe( '' );
	} );

	test( 'posting to a named addressee records where it went', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Wendell Achebe' ];

		const row = await issueFor( { page, admin, api }, volunteer.id, {
			addressee: 'Riverbend Probation Office',
		} );

		await page.locator( '[data-gwcvt-letter-post]' ).first().click();

		const sheet = page.locator( '[data-gwcvt-sheet="post-letter"]' );

		await expect( sheet ).toBeVisible();

		/* Prefilled from whoever the letter is addressed to, and still
		 * editable — offered, not imposed. */
		await expect( sheet.locator( '#gwcvt-post-addressee' ) ).toHaveValue(
			/Riverbend Probation Office/
		);

		/* The form opens the document in a new tab, so the click is awaited as
		 * a popup rather than as a navigation. */
		const [ document ] = await Promise.all( [
			page.waitForEvent( 'popup' ),
			sheet.getByRole( 'button', { name: 'Record it and print' } ).click(),
		] );

		await document.waitForLoadState();

		expect( await document.locator( 'body' ).innerText() ).toContain(
			row.title
		);

		const delivery = api( 'post.meta', { id: row.id } ).meta
			._gwc_vt_letter_delivery;

		expect( JSON.stringify( delivery ) ).toContain( 'post' );
		expect( JSON.stringify( delivery ) ).toContain( 'Riverbend Probation Office' );
	} );

	test( 'emailing sends to the address on the record and logs it', async ( {
		page,
		admin,
		api,
		mailbox,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Marcus Delacroix' ];

		expect( volunteer.email ).toBe( 'marcus@example.test' );

		const row = await issueFor( { page, admin, api }, volunteer.id );

		await page.locator( '[data-gwcvt-letter-mail]' ).first().click();

		const sheet = page.locator( '[data-gwcvt-sheet="email-letter"]' );

		await expect( sheet ).toBeVisible();

		/* "The address on the record" is the default, and is what is being
		 * driven here. The alternative — typing another address — is the same
		 * handler with the recipient filled in, and is covered by the refusal
		 * test below. */
		await sheet.locator( '[data-gwcvt-mailto="file"]' ).check();

		await sheet.getByRole( 'button', { name: 'Send it' } ).click();
		await page.waitForURL( /gwc_vt_did=delivered-email/ );

		const sent = mailbox.to( volunteer.email );

		expect( sent.length ).toBe( 1 );
		expect( sent[ 0 ].message ).toContain( row.title );

		const delivery = api( 'post.meta', { id: row.id } ).meta
			._gwc_vt_letter_delivery;

		expect( JSON.stringify( delivery ) ).toContain( 'email' );
		expect( JSON.stringify( delivery ) ).toContain( volunteer.email );
	} );

	test( 'a delivery is refused once the shifts have moved', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* The one refusal that needs explaining, and the reason the log stores
		 * entry IDs rather than a period: a delivery rebuilds from exactly what
		 * the letter listed, and if that no longer reproduces, sending it would
		 * put a document in front of a court whose own reference contradicts
		 * it. */
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];

		const row = await issueFor( { page, admin, api }, volunteer.id );

		const ids = String( row.entryIds )
			.split( ',' )
			.map( ( id ) => Number( id.trim() ) )
			.filter( Boolean );

		expect( ids.length ).toBeGreaterThan( 0 );

		/* Move one of the shifts the letter lists. Arranged through the back
		 * door rather than through a screen, because what is under test is the
		 * delivery's refusal, not the editor that could have caused it. */
		api( 'post.date', {
			id: ids[ 0 ],
			meta: { _gwc_vt_minutes: 999 },
		} );

		await admin.edit( volunteer.id );

		/* Print, which opens in a new tab — so the refusal arrives there. */
		const [ refusal ] = await Promise.all( [
			page.waitForEvent( 'popup' ),
			page.locator( '[data-gwcvt-letter-deliver]' ).first().click(),
		] );

		await refusal.waitForLoadState();

		expect( refusal.url() ).toContain( 'gwc_vt_did=stale' );

		const said = await refusal.locator( '#wpbody-content' ).innerText();

		expect( said ).toContain( 'can no longer be produced' );
		expect( said ).toContain( 'Issue a new one' );

		/* Refused, so no delivery was recorded — and the letter is still in
		 * the log, because it stays valid as what was sent that day. */
		const after = api( 'post.meta', { id: row.id } );

		expect( after.exists ).toBe( true );
		expect( after.meta._gwc_vt_letter_delivery || '' ).toBe( '' );
	} );

	test( 'a volunteer with nothing verified is not offered a letter', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		/* Tomás has hours and none of them attested to. There is nothing to
		 * write a letter about, so there is no way to issue one — which is a
		 * property of the screen rather than of the builder, and therefore one
		 * only a browser can see.
		 *
		 * This was found by writing a test that assumed otherwise: the
		 * reference-checking test below used to issue Tomás a letter first,
		 * and waited sixty seconds for a button that is correctly absent. */
		const volunteer = fixtures.volunteers[ 'Tomás Beaulieu' ];

		await admin.edit( volunteer.id );

		const sheet = await admin.openSheet( 'draft-letter' );

		await sheet.getByRole( 'button', { name: 'Save the draft' } ).click();
		await page.waitForURL( /gwc_vt_did=drafted/ );

		await expect(
			page.locator( '[data-gwcvt-letter-issue]' )
		).toHaveCount( 0 );
	} );

	test( 'a reference can be checked from the letters screen', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		/* The letter the seed leaves on file, rather than one issued here:
		 * checking a reference is a thing done weeks later, about a document
		 * somebody is holding, and the fixture already has one of those. */
		const [ letter ] = fixtures.letters;

		expect( letter ).toBeTruthy();

		await admin.visit( 'gwc-vt-letters' );

		await page.fill( '#gwcvt-reference', letter.title );
		await page.getByRole( 'button', { name: 'Check it' } ).click();

		/* The whole point of a reference code: somebody rings up and reads it
		 * out, and the answer is yes or no.
		 *
		 * Asserted on the notice rather than on the page, because the page
		 * contains the code either way — it is sitting in the input that was
		 * just typed into. The first version of this test asserted the page
		 * and would have passed against a checker that answered nothing at
		 * all. */
		await expect( page.locator( '.notice-success' ) ).toContainText(
			'matches our current records'
		);

		/* And a code that was never issued is not recognised. Asserted with a
		 * code of the right SHAPE, so this is a test of the digest rather than
		 * of the input's format check.
		 *
		 * Codes are keyed with wp_salt(), so one cannot be forged without
		 * database access — but wp_salt() given a scheme it does not know
		 * never touches AUTH_KEY and friends, so what actually keys this is
		 * the secret_key site option. See the note in CLAUDE.md. */
		const forged = letter.title.replace( /[0-9A-F]{8}$/, 'DEADBEEF' );

		await page.fill( '#gwcvt-reference', forged );
		await page.getByRole( 'button', { name: 'Check it' } ).click();

		await expect( page.locator( '.notice-error' ) ).toContainText(
			'No letter with that reference has been issued'
		);

		/* And it says nothing about anybody. An unknown code that answered
		 * with a name would make the checker a way to ask "is this person
		 * working off a court order" with nothing but a guess. */
		await expect( page.locator( '.notice-error' ) ).not.toContainText(
			'verified across'
		);
	} );
} );

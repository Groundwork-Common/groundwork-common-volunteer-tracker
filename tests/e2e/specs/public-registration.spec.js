/**
 * Offering to volunteer, from the front of the site.
 *
 * ── The same surface, and the same answer ────────────────────────────────────
 * A stranger hands over a name, an address, and — where the organization has
 * switched that question on — the fact that they are working off a court order.
 * So this form follows the hours form's rules: it is off until somebody turns
 * it on, it stores a CLAIM rather than creating a record, and every outcome
 * looks the same from outside.
 *
 * What it has that the hours form does not is a photograph, which is a file
 * upload on a public endpoint — the one place in this plugin where a stranger
 * can put bytes on the disk.
 */
const { test, expect, reset } = require( '../support/harness.js' );
const { join } = require( 'node:path' );
const { writeFileSync, mkdtempSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );

test.beforeAll( reset );

test.use( { storageState: { cookies: [], origins: [] } } );

/** Long enough not to be taken for a machine. See public-self-log.spec.js. */
const HUMAN = 3500;

/** A real 1×1 PNG, written to a temporary file for the upload. */
function onePixel() {
	const png = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	);

	const path = join( mkdtempSync( join( tmpdir(), 'gwcvt-' ) ), 'photo.png' );

	writeFileSync( path, png );

	return path;
}

test.describe( 'offering to volunteer', () => {
	test( 'an offer is stored as a claim, and creates nobody', async ( {
		page,
		api,
		fixtures,
	} ) => {
		const before = api( 'posts', { type: 'gwc_vt_volunteer' } ).length;

		await page.goto( fixtures.pages.registration.url );

		await page.fill( '#gwcvt-reg-name', 'Solveig Adeyemi' );
		await page.fill( '#gwcvt-reg-email', 'solveig@example.test' );
		await page.fill( '#gwcvt-reg-phone', '(555) 0199' );
		await page.fill(
			'#gwcvt-reg-note',
			'Free on Saturday mornings. Happy to drive.'
		);

		await page.waitForTimeout( HUMAN );

		await page.getByRole( 'button', { name: 'Send my details' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText(
			'your details have been sent to staff'
		);

		const offers = api( 'posts', {
			type: 'gwc_vt_application',
			meta: {
				name: '_gwc_vt_application_name',
				email: '_gwc_vt_application_email',
				phone: '_gwc_vt_application_phone',
				note: '_gwc_vt_application_note',
			},
		} ).filter( ( offer ) => 'solveig@example.test' === offer.email );

		expect( offers.length ).toBe( 1 );
		expect( offers[ 0 ].name ).toBe( 'Solveig Adeyemi' );
		expect( offers[ 0 ].note ).toContain( 'Saturday' );

		/* And no volunteer. An anonymous form cannot create an identity
		 * record; it can only create a row somebody has to look at. */
		expect( api( 'posts', { type: 'gwc_vt_volunteer' } ).length ).toBe(
			before
		);
	} );

	test( 'the court-ordered question is asked plainly and kept off the record', async ( {
		page,
		api,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.registration.url );

		/* The question exists because mandated service is the case this plugin
		 * is built around. The field says what it is for and where it goes. */
		await expect( page.locator( 'body' ) ).toContainText(
			'never published'
		);

		await page.fill( '#gwcvt-reg-name', 'Casimir Oyelaran' );
		await page.fill( '#gwcvt-reg-email', 'casimir@example.test' );
		await page.fill( '#gwcvt-reg-required', '40' );
		await page.fill( '#gwcvt-reg-required-by', '2026-12-01' );
		await page.fill(
			'#gwcvt-reg-required-for',
			'Franklin County Municipal Court'
		);

		await page.waitForTimeout( HUMAN );

		await page.getByRole( 'button', { name: 'Send my details' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const offer = api( 'posts', {
			type: 'gwc_vt_application',
			meta: {
				email: '_gwc_vt_application_email',
				required: '_gwc_vt_application_required_minutes',
				requiredBy: '_gwc_vt_application_required_by',
				requiredFor: '_gwc_vt_application_required_for',
			},
		} ).find( ( one ) => 'casimir@example.test' === one.email );

		expect( offer ).toBeTruthy();

		/* Integer minutes, from a field a person typed hours into. Hard rule 1
		 * reaches the public form too. */
		expect( Number( offer.required ) ).toBe( 40 * 60 );
		expect( offer.requiredBy ).toBe( '2026-12-01' );
		expect( offer.requiredFor ).toContain( 'Franklin County' );

		/* The application post type is not in REST. Flipping show_in_rest on it
		 * would publish names and court-referral status at /wp/v2/, which is
		 * hard rule 2 — and this is the type where it would matter most. */
		const rest = await page.request.get( '/wp-json/wp/v2/gwc_vt_application' );

		expect( rest.status() ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'a photograph can be sent, and is not in the media library', async ( {
		page,
		api,
		fixtures,
	} ) => {
		/* Counted before, not asserted to be zero: this site may already have a
		 * letterhead logo in the library, and it should. What must not happen
		 * is that a stranger's photograph joins it. */
		const libraryBefore = api( 'posts', { type: 'attachment' } ).length;

		await page.goto( fixtures.pages.registration.url );

		await page.fill( '#gwcvt-reg-name', 'Perpetua Lindqvist' );
		await page.fill( '#gwcvt-reg-email', 'perpetua@example.test' );
		await page.setInputFiles( '#gwcvt-reg-photo', onePixel() );

		await page.waitForTimeout( HUMAN );

		await page.getByRole( 'button', { name: 'Send my details' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const offer = api( 'posts', {
			type: 'gwc_vt_application',
			meta: { email: '_gwc_vt_application_email', photo: '_gwc_vt_photo' },
		} ).find( ( one ) => 'perpetua@example.test' === one.email );

		expect( offer ).toBeTruthy();
		expect( offer.photo ).toBeTruthy();

		/* Not an attachment. A picture of somebody offering to volunteer has no
		 * business in a library every editor can browse, and no business having
		 * a guessable public URL. */
		expect( api( 'posts', { type: 'attachment' } ).length ).toBe(
			libraryBefore
		);
	} );

	test( 'a honeypotted offer is answered exactly as a real one', async ( {
		page,
		api,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.registration.url );

		await page.fill( '#gwcvt-reg-name', 'Genuine Applicant' );
		await page.fill( '#gwcvt-reg-email', 'genuine@example.test' );
		await page.waitForTimeout( HUMAN );
		await page.getByRole( 'button', { name: 'Send my details' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const real = await page.locator( 'body' ).innerText();

		const before = api( 'posts', { type: 'gwc_vt_application' } ).length;

		await page.goto( fixtures.pages.registration.url );

		await page.fill( '#gwcvt-reg-name', 'A Machine' );
		await page.fill( '#gwcvt-reg-email', 'machine@example.test' );
		await page.fill( '#gwcvt-reg-website', 'https://example.invalid/' );
		await page.waitForTimeout( HUMAN );
		await page.getByRole( 'button', { name: 'Send my details' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		expect( await page.locator( 'body' ).innerText() ).toBe( real );
		expect( api( 'posts', { type: 'gwc_vt_application' } ).length ).toBe(
			before
		);
	} );

	test( 'the form is not there when the setting is off', async ( {
		page,
		api,
		fixtures,
	} ) => {
		api( 'settings.set', { values: { registration_enabled: false } } );

		try {
			await page.goto( fixtures.pages.registration.url );

			await expect( page.locator( '#gwcvt-reg-name' ) ).toHaveCount( 0 );

			const before = api( 'posts', { type: 'gwc_vt_application' } ).length;

			await page.request.post( fixtures.pages.registration.url, {
				form: {
					gwc_vt_register: '1',
					gwc_vt_name: 'Should Not Land',
					gwc_vt_email: 'nope@example.test',
				},
			} );

			expect( api( 'posts', { type: 'gwc_vt_application' } ).length ).toBe(
				before
			);
		} finally {
			api( 'settings.set', { values: { registration_enabled: true } } );
		}
	} );
} );

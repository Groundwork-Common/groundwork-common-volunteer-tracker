/**
 * Credentials: defining them, retiring them, and recording who holds one.
 *
 * Covers gwc_vt_save_credential, gwc_vt_retire_credential,
 * gwc_vt_restore_credential and gwc_vt_remove_credential_record.
 *
 * ── The word this file is careful about ──────────────────────────────────────
 * Never "requirement". In this plugin a requirement is court-ordered service
 * hours, and a credential is a thing a volunteer must HOLD — a class, a waiver,
 * a check. CredentialTest asserts that separation over the source; this file
 * keeps to it in its own prose because a test that muddles the two is a test
 * somebody will copy.
 *
 * ── Retiring is not deleting ─────────────────────────────────────────────────
 * Retiring stops a credential being asked for. It destroys no record of who
 * held it, because "who had a food handler card in 2024" is a question an
 * inspector asks about a year nobody is running any more.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/** The credentials screen. */
const SCREEN = 'gwc-vt-credentials';

test.describe( 'credentials', () => {
	test( 'a new one can be defined, and comes back with what was typed', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( SCREEN, { credential: 'new' } );

		const form = admin.formFor( 'gwc_vt_save_credential' ).first();

		await expect( form ).toBeVisible();

		await page.fill( '#gwcvt-credential-name', 'Safeguarding briefing' );
		await page.fill( '#gwcvt-credential-months', '12' );
		await page.selectOption( '#gwcvt-credential-mode', { index: 1 } );
		await page.fill( '#gwcvt-credential-note', 'Run each spring.' );

		/* "Add it" on the blank form, "Save this credential" on an edit —
		 * different words for different acts, so the button is found by being
		 * the form's submit rather than by a label this test would have to
		 * keep in step. */
		await form.locator( 'input[type="submit"], button[type="submit"]' ).first().click();

		/* `added`, not `saved` — a new credential and an edited one say
		 * different things, and the difference is not decoration: "Credential
		 * added. Record who holds it on each volunteer's own record" tells
		 * somebody what to do next, and "Saved. Any expiry is worked out from
		 * the new interval from now on" tells them what they just changed. */
		await page.waitForURL( /gwc_vt_credential_did=added/ );

		const made = api( 'posts', {
			type: 'gwc_vt_credential',
			meta: {
				months: '_gwc_vt_credential_months',
				note: '_gwc_vt_credential_note',
			},
		} ).find( ( one ) => one.title === 'Safeguarding briefing' );

		expect( made ).toBeTruthy();
		expect( Number( made.months ) ).toBe( 12 );
		expect( made.note ).toBe( 'Run each spring.' );

		/* And it is on the screen, in the list somebody manages them from. */
		await admin.visit( SCREEN );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Safeguarding briefing'
		);
	} );

	test( 'a credential with no name is refused, over the fields it came from', async ( {
		page,
		admin,
		api,
	} ) => {
		const before = api( 'posts', { type: 'gwc_vt_credential' } ).length;

		await admin.visit( SCREEN, { credential: 'new' } );

		const name = page.locator( '#gwcvt-credential-name' );

		/* Required in the markup first, so the browser stops it. */
		expect( await name.getAttribute( 'required' ) ).not.toBeNull();

		await name.evaluate( ( field ) => field.removeAttribute( 'required' ) );
		await name.fill( '' );

		await admin
			.formFor( 'gwc_vt_save_credential' )
			.first()
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_credential_did=no-name/ );

		/* Back to the form, not to the list. "That could not be saved" belongs
		 * over the fields it could not save. */
		await expect(
			admin.formFor( 'gwc_vt_save_credential' ).first()
		).toBeVisible();

		expect( api( 'posts', { type: 'gwc_vt_credential' } ).length ).toBe(
			before
		);
	} );

	test( 'retiring one keeps every record of who held it', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const credential = fixtures.credentials[ 'Food handler card' ];

		const held = api( 'posts', {
			type: 'gwc_vt_cred_record',
			meta: { credential: '_gwc_vt_record_credential' },
		} ).filter( ( record ) => Number( record.credential ) === credential.id );

		expect( held.length ).toBeGreaterThan( 0 );

		/* Retire from the credential's own editor rather than from the list's
		 * row actions. Both offer it — the aside beside Save, and the hover
		 * action on the row — and the editor's is the one that is not parked
		 * off-screen until the pointer arrives. The row action is covered by
		 * the restore test below, which uses it. */
		await admin.visit( SCREEN, { credential: credential.id } );

		await page
			.locator( `a[href*="action=gwc_vt_retire_credential"]` )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_credential_did=/ );

		/* Retired is a status on the credential, not a deletion. */
		expect( api( 'post.meta', { id: credential.id } ).status ).toBe(
			'gwc_vt_cr_retired'
		);

		/* And every grant of it is still there, unchanged. */
		const after = api( 'posts', {
			type: 'gwc_vt_cred_record',
			meta: { credential: '_gwc_vt_record_credential' },
		} ).filter( ( record ) => Number( record.credential ) === credential.id );

		expect( after.map( ( record ) => record.id ).sort() ).toEqual(
			held.map( ( record ) => record.id ).sort()
		);
	} );

	test( 'a retired one can be brought back', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* The seed retires one, so this starts from a state somebody arrived
		 * at rather than one this file created. */
		const retired = Object.values( fixtures.credentials ).find(
			( one ) => 'gwc_vt_cr_retired' === one.status
		);

		expect( retired ).toBeTruthy();

		await admin.visit( SCREEN, { gwc_vt_status: 'retired' } );

		const back = page
			.locator(
				`a[href*="action=gwc_vt_restore_credential"][href*="credential=${ retired.id }"]`
			)
			.first();

		/* A row action, so the row is hovered first. */
		await admin.rowAction( back );

		await page.waitForURL( /gwc_vt_credential_did=/ );

		expect( api( 'post.meta', { id: retired.id } ).status ).not.toBe(
			'gwc_vt_cr_retired'
		);
	} );

	test( 'a record can be removed from a volunteer', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const records = api( 'posts', {
			type: 'gwc_vt_cred_record',
			meta: { credential: '_gwc_vt_record_credential' },
		} );

		expect( records.length ).toBeGreaterThan( 0 );

		const record = records[ 0 ];

		await admin.edit( record.parent );

		const remove = page
			.locator(
				`a[href*="action=gwc_vt_remove_credential_record"][href*="record=${ record.id }"]`
			)
			.first();

		await expect( remove ).toBeVisible();
		await remove.click();

		await page.waitForURL( /post\.php/ );

		/* Removing a record is the correction of a mistake — somebody recorded
		 * the wrong person, or the wrong credential — so it really does go.
		 * Retiring is what stops asking for one without destroying history;
		 * this is the other thing. */
		expect( api( 'post.meta', { id: record.id } ).exists ).toBe( false );

		expect( fixtures.volunteers ).toBeTruthy();
	} );
} );

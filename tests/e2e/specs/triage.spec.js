/**
 * Saying whose a self-logged claim is.
 *
 * Covers gwc_vt_attach_entry and gwc_vt_create_volunteer_from_entry.
 *
 * ── Why triage exists at all ─────────────────────────────────────────────────
 * The public hours form never looks anybody up. Hard rule 4: no code path may
 * branch on whether the submitted email matches an existing volunteer, because
 * a path that did would be an oracle for "is this named person working off a
 * court order". So a submission arrives as a CLAIM — a name and an address
 * stored on a pending entry — and a human decides whose it is afterwards.
 *
 * That is what this screen is, and it is the only place in the plugin where a
 * volunteer record can be created out of something a stranger typed.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.describe( 'triage', () => {
	test( 'a claim matching somebody on file is offered to them', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* The seed leaves two unmatched self-logged entries. One of them
		 * carries an address already on file, which is the case the suggestion
		 * exists for. */
		const claims = fixtures.entries.filter(
			( entry ) => ! Number( entry.volunteer ) && entry.claimEmail
		);

		expect( claims.length ).toBeGreaterThan( 0 );

		const known = claims.find( ( claim ) =>
			Object.values( fixtures.volunteers ).some(
				( volunteer ) =>
					volunteer.email &&
					volunteer.email.toLowerCase() === claim.claimEmail.toLowerCase()
			)
		);

		expect( known ).toBeTruthy();

		await admin.edit( known.id );

		const attach = page.locator(
			'a[href*="action=gwc_vt_attach_entry"]'
		).first();

		await expect( attach ).toBeVisible();

		/* The screen says WHY it is suggesting this person, because "their
		 * email matches exactly" and "their name matches, check it is the same
		 * person" are different amounts of confidence and the second one needs
		 * a human to look. */
		await expect( page.locator( '.gwcvt-triage-actions' ) ).toContainText(
			/matches this volunteer/
		);

		await attach.click();
		await page.waitForURL( /gwc_vt_triage=attached/ );

		const after = api( 'post.meta', { id: known.id } );

		expect( Number( after.meta._gwc_vt_volunteer ) ).toBeGreaterThan( 0 );

		/* Attached is not verified. Somebody has said whose the hours are;
		 * nobody has yet said they happened. */
		expect( after.meta._gwc_vt_verified_at || '' ).toBe( '' );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'still need verifying'
		);
	} );

	test( 'a claim matching nobody can become a record', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const emails = Object.values( fixtures.volunteers )
			.map( ( volunteer ) => ( volunteer.email || '' ).toLowerCase() )
			.filter( Boolean );

		const stranger = fixtures.entries.find(
			( entry ) =>
				! Number( entry.volunteer ) &&
				entry.claimName &&
				! emails.includes( ( entry.claimEmail || '' ).toLowerCase() )
		);

		expect( stranger ).toBeTruthy();

		const before = api( 'posts', { type: 'gwc_vt_volunteer' } ).length;

		await admin.edit( stranger.id );

		const create = page
			.locator( 'a[href*="action=gwc_vt_create_volunteer_from_entry"]' )
			.first();

		await expect( create ).toBeVisible();
		await create.click();
		await page.waitForURL( /gwc_vt_triage=created/ );

		const volunteers = api( 'posts', {
			type: 'gwc_vt_volunteer',
			meta: { email: '_gwc_vt_email' },
		} );

		expect( volunteers.length ).toBe( before + 1 );

		/* The new record carries the name and address the stranger typed, and
		 * the entry is now on it. */
		const made = volunteers.find(
			( volunteer ) => volunteer.title === stranger.claimName
		);

		expect( made ).toBeTruthy();
		expect( made.email ).toBe( stranger.claimEmail );

		const entry = api( 'post.meta', { id: stranger.id } );

		expect( Number( entry.meta._gwc_vt_volunteer ) ).toBe( made.id );
		expect( entry.meta._gwc_vt_verified_at || '' ).toBe( '' );
	} );

	test( 'an entry already on a record offers no triage', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		/* Triage is a question about a claim. Once it has an answer, asking it
		 * again is a way to move somebody's hours onto the wrong person. */
		const settled = fixtures.entries.find(
			( entry ) => Number( entry.volunteer ) > 0
		);

		await admin.edit( settled.id );

		await expect(
			page.locator( 'a[href*="action=gwc_vt_attach_entry"]' )
		).toHaveCount( 0 );

		await expect(
			page.locator( 'a[href*="action=gwc_vt_create_volunteer_from_entry"]' )
		).toHaveCount( 0 );
	} );
} );

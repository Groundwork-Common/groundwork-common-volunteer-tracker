/**
 * Offers to volunteer, and what a coordinator does with one.
 *
 * Covers gwc_vt_approve_application and gwc_vt_discard_application.
 *
 * ── Why an application is not a volunteer ────────────────────────────────────
 * The public form on the front of the site is the same kind of surface as the
 * hours form: the person on the other end is anonymous, and what they hand over
 * is a name, an address, and — on a site running a court-ordered service
 * programme — the fact that they are working one off. So an offer is stored as
 * a claim, in its own post type, and becomes a volunteer record only when a
 * human presses a button. This file is that button.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

test.describe( 'applications', () => {
	test( 'the queue lists what is waiting', async ( { page, admin, fixtures } ) => {
		await admin.visit( 'gwc-vt-applications' );

		const waiting = fixtures.applications.filter(
			( offer ) => 'gwc_vt_discarded' !== offer.status
		);

		expect( waiting.length ).toBeGreaterThan( 0 );

		const screen = await page.locator( '#wpbody-content' ).innerText();

		for ( const offer of waiting ) {
			expect( screen ).toContain( offer.name );
		}
	} );

	test( 'approving one creates the volunteer it describes', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const offer = fixtures.applications.find(
			( one ) => 'gwc_vt_discarded' !== one.status && one.name
		);

		const before = api( 'posts', { type: 'gwc_vt_volunteer' } ).length;

		await admin.visit( 'gwc-vt-applications' );

		await page
			.locator(
				`a[href*="action=gwc_vt_approve_application"][href*="application=${ offer.id }"]`
			)
			.first()
			.click();

		await page.waitForURL( /gwc_vt_offer=approved/ );

		const volunteers = api( 'posts', {
			type: 'gwc_vt_volunteer',
			meta: { email: '_gwc_vt_email', phone: '_gwc_vt_phone' },
		} );

		expect( volunteers.length ).toBe( before + 1 );

		const made = volunteers.find( ( one ) => one.title === offer.name );

		expect( made ).toBeTruthy();
		expect( made.email ).toBe( offer.email );

		/* The offer is marked approved rather than deleted, and it remembers
		 * which record it became — so "where did this person come from" has an
		 * answer later. */
		const after = api( 'post.meta', { id: offer.id } );

		expect( Number( after.meta._gwc_vt_application_volunteer ) ).toBe(
			made.id
		);

		/* And the queue no longer offers it. */
		await admin.visit( 'gwc-vt-applications' );

		await expect(
			page.locator(
				`a[href*="action=gwc_vt_approve_application"][href*="application=${ offer.id }"]`
			)
		).toHaveCount( 0 );
	} );

	test( 'discarding one keeps the record and creates nobody', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const offer = fixtures.applications
			.filter( ( one ) => 'gwc_vt_discarded' !== one.status && one.name )
			.pop();

		const before = api( 'posts', { type: 'gwc_vt_volunteer' } ).length;

		await admin.visit( 'gwc-vt-applications' );

		await page
			.locator(
				`a[href*="action=gwc_vt_discard_application"][href*="application=${ offer.id }"]`
			)
			.first()
			.click();

		await page.waitForURL( /gwc_vt_offer=discarded/ );

		/* Set aside, not deleted: gwc_vt_discarded is a status, and the status
		 * is what keeps the offer out of the queue while leaving the fact that
		 * somebody offered. */
		const after = api( 'post.meta', { id: offer.id } );

		expect( after.exists ).toBe( true );
		expect( after.status ).toBe( 'gwc_vt_discarded' );

		expect( api( 'posts', { type: 'gwc_vt_volunteer' } ).length ).toBe(
			before
		);
	} );

	test( 'an offer that has gone says so rather than half-acting', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( 'gwc-vt-applications' );

		/* Taken off the screen rather than out of the fixture map, because the
		 * two tests above this one have already dealt with the offers they
		 * named and this file resets once. What is still on the screen is what
		 * is still waiting. */
		const link = page
			.locator( 'a[href*="action=gwc_vt_approve_application"]' )
			.first();

		await expect( link ).toBeVisible();

		const href = await link.getAttribute( 'href' );
		const applicationId = Number(
			new URL( href, page.url() ).searchParams.get( 'application' )
		);

		/* Delete it out from under the link — two people at two desks, which is
		 * the ordinary way this happens. */
		const name = api( 'post.meta', { id: applicationId } ).title;

		api( 'cleanup', { prefix: name } );

		await page.goto( href );
		await page.waitForURL( /gwc_vt_offer=gone/ );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'already been dealt with'
		);

		/* "Nothing was changed" is the half that matters. A handler that
		 * reported the offer gone and had already made half a record would be
		 * saying something untrue on the screen where somebody decides whether
		 * to press it again. */
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Nothing was changed'
		);
	} );
} );

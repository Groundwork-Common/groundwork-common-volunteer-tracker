/**
 * The settings screen, and the three separate saves on it.
 *
 * Covers gwc_vt_save_settings, gwc_vt_save_permissions and
 * gwc_vt_save_uninstall.
 *
 * ── Why three handlers rather than one Settings API page ─────────────────────
 * Two of these decide something a Settings API save cannot be trusted with.
 * Permissions rewrites which roles may see a volunteer's record; the uninstall
 * checkbox arms the removal of options on delete. Both get an explicit
 * admin_post_ handler with an explicit capability check, which is the reason
 * this screen is not register_setting() the whole way down — and the reason
 * each is driven here separately.
 */
const { test, expect, reset } = require( '../support/harness.js' );

/* This file rewrites site-wide settings, so it starts from the seeded ones and
 * puts back anything it changes. */
test.beforeAll( reset );

/** The settings screen, on one tab. */
function tab( admin, name ) {
	return admin.screenUrl( 'gwc-vt-settings', { tab: name } );
}

test.describe( 'settings', () => {
	test( 'every tab on the screen is reachable and renders', async ( {
		page,
		admin,
	} ) => {
		/* The tabs are read off the screen rather than listed here.
		 *
		 * A list would have been wrong the day it was written: the first
		 * version of this test named four — letter, logging, shifts, privacy —
		 * and there are five. Permissions is added by its own filter, which is
		 * exactly the kind of tab a hand-kept list misses, and the symptom was
		 * a "permissions" test looking for its form on the privacy tab. */
		await page.goto( tab( admin, 'letter' ) );

		const names = (
			await page
				.locator( '.nav-tab-wrapper a' )
				.evaluateAll( ( links ) =>
					links.map( ( link ) => link.getAttribute( 'href' ) || '' )
				)
		)
			.map( ( href ) => new URL( href, 'http://x' ).searchParams.get( 'tab' ) )
			.filter( Boolean );

		expect( names ).toEqual(
			expect.arrayContaining( [
				'letter',
				'logging',
				'shifts',
				'privacy',
				'permissions',
			] )
		);

		for ( const name of names ) {
			const response = await page.goto( tab( admin, name ) );

			expect( response.status() ).toBe( 200 );

			/* Every tab carries at least one of the screen's three saves. */
			const forms = page.locator( 'form[action*="admin-post.php"]' );

			expect( await forms.count() ).toBeGreaterThan( 0 );
		}
	} );

	test( 'saving the letterhead writes it, and the letter picks it up', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		await page.goto( tab( admin, 'letter' ) );

		await page.fill( '#gwcvt-org-name', 'Riverbend Food Bank & Pantry' );
		await page.fill( '#gwcvt-signatory-name', 'Dana Reyes' );
		await page.fill( '#gwcvt-signatory-title', 'Director of Volunteers' );

		await admin
			.formFor( 'gwc_vt_save_settings' )
			.first()
			.getByRole( 'button', { name: /Save/ } )
			.click();

		await page.waitForURL( /page=gwc-vt-settings/ );

		const saved = api( 'settings.get' );

		expect( saved.org_name ).toBe( 'Riverbend Food Bank & Pantry' );
		expect( saved.signatory_title ).toBe( 'Director of Volunteers' );

		/* And it reaches the document, which is the only reason any of these
		 * fields exist. Checked on a letter the seed already issued, so this
		 * is about the letterhead rather than about issuing. */
		const [ letter ] = fixtures.letters;

		await admin.edit( Number( letter.volunteer ) );

		const open = page.locator( '[data-gwcvt-letter-open]' ).first();

		await page.goto( await open.getAttribute( 'href' ) );

		const rendered = await page.locator( 'body' ).innerText();

		expect( rendered ).toContain( 'Riverbend Food Bank & Pantry' );
		expect( rendered ).toContain( 'Director of Volunteers' );

		/* Put it back, so the rest of the run sees the fixture it expects. */
		api( 'settings.set', {
			values: {
				org_name: 'Riverbend Food Bank',
				signatory_title: 'Volunteer Coordinator',
			},
		} );
	} );

	test( 'the wording fields say how to get the default back', async ( {
		page,
		admin,
	} ) => {
		/* A fallback that is right on its own can be wrong in a group: the org
		 * name falls back to the site title, the contact to admin_email, the
		 * signatory to the word "Signature". Each is defensible; together they
		 * are a court letter headed with a website's title over a webmaster's
		 * address. So where several fallbacks can compound, the screen says so
		 * before the output leaves. */
		await page.goto( tab( admin, 'letter' ) );

		const helpText = await page.locator( '#wpbody-content' ).innerText();

		expect( helpText ).toContain( 'Leave empty to use the site title' );
	} );

	test( 'permissions saves through its own handler', async ( {
		page,
		admin,
		api,
	} ) => {
		await page.goto( tab( admin, 'permissions' ) );

		const form = admin.formFor( 'gwc_vt_save_permissions' ).first();

		await expect( form ).toBeVisible();

		/* An enabled box, read before and after, so the assertion is about the
		 * save rather than about one checkbox that may be arranged differently
		 * tomorrow.
		 *
		 * Enabled matters: the administrator's own two boxes are disabled, and
		 * rightly — a screen that let somebody take away their own ability to
		 * issue letters is a screen that locks the only person who can put it
		 * back out of the room. The first version of this test took the first
		 * checkbox on the form and waited sixty seconds for a disabled one. */
		const boxes = form.locator( 'input[type="checkbox"]:not([disabled])' );
		const count = await boxes.count();

		expect( count ).toBeGreaterThan( 0 );

		/* Capabilities are read with isset(), not truthiness, precisely so
		 * that "an administrator decided no" and "this role never heard of it"
		 * are different answers. Turning one off and back on has to survive
		 * that round trip. */
		const first = boxes.first();
		const was = await first.isChecked();

		await first.setChecked( ! was );
		await form.getByRole( 'button', { name: /Save/ } ).click();
		await page.waitForURL( /page=gwc-vt-settings/ );

		await page.goto( tab( admin, 'permissions' ) );

		expect(
			await admin
				.formFor( 'gwc_vt_save_permissions' )
				.first()
				.locator( 'input[type="checkbox"]:not([disabled])' )
				.first()
				.isChecked()
		).toBe( ! was );

		/* Back as it was. */
		await admin
			.formFor( 'gwc_vt_save_permissions' )
			.first()
			.locator( 'input[type="checkbox"]:not([disabled])' )
			.first()
			.setChecked( was );

		await admin
			.formFor( 'gwc_vt_save_permissions' )
			.first()
			.getByRole( 'button', { name: /Save/ } )
			.click();

		await page.waitForURL( /page=gwc-vt-settings/ );
	} );

	test( 'the uninstall checkbox arms options only, and says so', async ( {
		page,
		admin,
	} ) => {
		await page.goto( tab( admin, 'privacy' ) );

		const form = admin.formFor( 'gwc_vt_save_uninstall' ).first();

		await expect( form ).toBeVisible();

		/* Hard rule 10, on the screen. Deleting the plugin removes no records,
		 * armed or not: the checkbox arms the removal of OPTIONS. The Privacy
		 * tab has to say that, because a policy nobody can find is one people
		 * are surprised by. */
		const words = await form.innerText();

		expect( words.toLowerCase() ).toContain( 'setting' );

		const box = form.locator(
			'input[name="gwc_vt_allow_destructive_uninstall"]'
		);

		await expect( box ).toHaveCount( 1 );

		const was = await box.isChecked();

		await box.setChecked( ! was );
		await form.getByRole( 'button', { name: /Save/ } ).click();
		await page.waitForURL( /page=gwc-vt-settings/ );

		await page.goto( tab( admin, 'privacy' ) );

		const after = admin
			.formFor( 'gwc_vt_save_uninstall' )
			.first()
			.locator( 'input[name="gwc_vt_allow_destructive_uninstall"]' );

		expect( await after.isChecked() ).toBe( ! was );

		await after.setChecked( was );
		await admin
			.formFor( 'gwc_vt_save_uninstall' )
			.first()
			.getByRole( 'button', { name: /Save/ } )
			.click();

		await page.waitForURL( /page=gwc-vt-settings/ );
	} );

	test( 'a save without a nonce is refused', async ( { page, admin, api } ) => {
		await page.goto( tab( admin, 'letter' ) );

		const before = api( 'settings.get' ).org_name;

		/* The nonce field is emptied rather than the form rebuilt, so this is
		 * as close as a browser gets to a cross-site post: the right URL, the
		 * right fields, and nothing proving the person meant it. */
		await admin
			.formFor( 'gwc_vt_save_settings' )
			.first()
			.locator( 'input[name="_wpnonce"]' )
			.evaluate( ( field ) => {
				field.value = 'not-a-nonce';
			} );

		await page.fill( '#gwcvt-org-name', 'Somebody Else Entirely' );

		await admin
			.formFor( 'gwc_vt_save_settings' )
			.first()
			.getByRole( 'button', { name: /Save/ } )
			.click();

		await expect( page.locator( 'body' ) ).toContainText(
			'The link you followed has expired'
		);

		expect( api( 'settings.get' ).org_name ).toBe( before );
	} );
} );

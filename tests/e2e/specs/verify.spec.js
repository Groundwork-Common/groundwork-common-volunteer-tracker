/**
 * Attesting to hours, and taking an attestation back.
 *
 * Three write paths: gwc_vt_verify_entry, gwc_vt_unverify_entry and
 * gwc_vt_bulk_unverify.
 *
 * ── Why this is worth driving in a browser ───────────────────────────────────
 * Verification is the one fact a letter rests on, and every one of these paths
 * is a nonced URL built on one screen and spent on another. tests/VerifyTest.php
 * proves the model; tests/integration/verify.php proves the queue's ordering.
 * Neither can see the link — whether the button on the screen actually carries
 * the entry it is next to, the nonce the handler asks for, and a return URL
 * that lands somewhere real.
 */
const { test, expect, reset } = require( '../support/harness.js' );

/* One reset for this file. Every test below both reads and writes the
 * verification state of the seeded entries, so the file has to start from a
 * known one. */
test.beforeAll( reset );

/** The verify queue's slug. */
const QUEUE = 'gwc-vt-verify';

test.describe( 'verifying hours', () => {
	test( 'the queue offers an entry, and attesting to it takes it off', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( QUEUE );

		/* Rows carry the action link, one per entry. The first one is enough:
		 * what is under test is the round trip, not the ordering, which
		 * tests/integration/verify.php already asserts without a browser. */
		const attest = page
			.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
			.first();

		await expect( attest ).toBeVisible();

		const href = await attest.getAttribute( 'href' );
		const entryId = Number(
			new URL( href, page.url() ).searchParams.get( 'entry' )
		);

		expect( entryId ).toBeGreaterThan( 0 );

		/* Not verified yet — asked of the database rather than inferred from
		 * the screen, because "it is in the queue" is the screen's opinion and
		 * the meta is the fact. */
		const before = api( 'post.meta', { id: entryId } );

		expect( before.meta._gwc_vt_verified_at || '' ).toBe( '' );

		await attest.click();
		await page.waitForURL( /gwc_vt_result=verified/ );

		const after = api( 'post.meta', { id: entryId } );

		expect( after.meta._gwc_vt_verified_at ).toBeTruthy();
		expect( Number( after.meta._gwc_vt_verified_by ) ).toBeGreaterThan( 0 );

		/* And it has left the queue. The count is the coordinator's actual
		 * question — "what is still waiting" — so a screen that verified the
		 * entry and went on listing it would be wrong in the way that matters. */
		await admin.visit( QUEUE );

		await expect(
			page.locator( `a.button[href*="entry=${ entryId }"]` )
		).toHaveCount( 0 );
	} );

	test( 'the queue refuses an entry nobody has said whose it is', async ( {
		page,
		admin,
		fixtures,
	} ) => {
		/* A self-logged entry with no volunteer on it. The seed leaves two.
		 * They are in the queue, and deliberately without an attest button:
		 * verifying an unmatched claim would put a staff member's name against
		 * hours belonging to nobody. */
		const unmatched = fixtures.entries.filter(
			( entry ) => ! Number( entry.volunteer ) && entry.claimName
		);

		expect( unmatched.length ).toBeGreaterThan( 0 );

		await admin.visit( QUEUE );

		await expect(
			page.locator( '.gwcvt-verify__group--unmatched' )
		).toBeVisible();

		for ( const entry of unmatched ) {
			await expect(
				page.locator(
					`a[href*="action=gwc_vt_verify_entry"][href*="entry=${ entry.id }"]`
				)
			).toHaveCount( 0 );
		}
	} );

	test( 'withdrawing one attestation puts the entry back', async ( {
		page,
		admin,
		api,
	} ) => {
		/* The list, narrowed to what is verified.
		 *
		 * Narrowed rather than picked out of the fixture map, because the map
		 * knows an entry's id and the list table knows its page — and twenty
		 * rows is one page. Asking the screen for a verified entry gets one
		 * that is on the screen, which is the only kind a row action can be
		 * clicked on. */
		await page.goto( admin.screenUrl( '', { gwc_vt_state: 'verified' } ) );

		const withdraw = page
			.locator( 'a[href*="action=gwc_vt_unverify_entry"]' )
			.first();

		await expect( withdraw ).toHaveCount( 1 );

		const entryId = Number(
			new URL(
				await withdraw.getAttribute( 'href' ),
				page.url()
			).searchParams.get( 'entry' )
		);

		/* Hover the row first, the way a person does.
		 *
		 * wp-admin does not hide a row action with `display` — it parks it at
		 * `left: -9999em` and brings it back on :hover. So the link is in the
		 * DOM, is "visible" to a query, and cannot be clicked: Playwright's
		 * answer is "element is outside of the viewport", which reads like a
		 * scrolling problem and is not one. force: true does not help either,
		 * because the element really is nowhere near the pointer. */
		await withdraw.locator( 'xpath=ancestor::tr[1]' ).hover();
		await withdraw.click();
		await page.waitForURL( /gwc_vt_result=unverified/ );

		expect(
			api( 'post.meta', { id: entryId } ).meta._gwc_vt_verified_at || ''
		).toBe( '' );

		/* Back in the queue, which is the whole point of withdrawing. */
		await admin.visit( QUEUE );

		await expect(
			page.locator( `a.button[href*="entry=${ entryId }"]` )
		).toHaveCount( 1 );
	} );

	test( 'withdrawing in bulk asks first, and says what it will do', async ( {
		page,
		admin,
		api,
	} ) => {
		await page.goto( admin.screenUrl( '', { gwc_vt_state: 'verified' } ) );

		const boxes = page.locator( '#the-list input[name="post[]"]' );

		expect( await boxes.count() ).toBeGreaterThanOrEqual( 2 );

		const chosen = [
			Number( await boxes.nth( 0 ).inputValue() ),
			Number( await boxes.nth( 1 ).inputValue() ),
		];

		await boxes.nth( 0 ).check();
		await boxes.nth( 1 ).check();

		const verified = chosen.map( ( id ) => ( { id } ) );

		await page.selectOption( '#bulk-action-selector-top', 'gwc_vt_unverify' );
		await page.click( '#doaction' );

		/* The interstitial. Not a confirm() — it has to say what will be lost,
		 * and a browser dialog can neither say it nor have it read out. */
		await page.waitForURL( /gwc_vt_confirm=unverify/ );

		const wrap = page.locator( '#wpbody-content' );

		await expect( wrap ).toContainText( 'Withdraw verification' );

		/* Still verified: an interstitial that had already done the thing
		 * would be a confirmation of something that happened. */
		for ( const entry of verified ) {
			expect(
				api( 'post.meta', { id: entry.id } ).meta._gwc_vt_verified_at
			).toBeTruthy();
		}

		const form = page.locator( 'form[action*="admin-post.php"]' ).filter( {
			has: page.locator( 'input[name="action"][value="gwc_vt_bulk_unverify"]' ),
		} );

		await expect( form ).toHaveCount( 1 );

		await form.locator( 'button[type="submit"]' ).click();
		await page.waitForURL( /gwc_vt_result=unverified/ );

		for ( const entry of verified ) {
			expect(
				api( 'post.meta', { id: entry.id } ).meta._gwc_vt_verified_at || ''
			).toBe( '' );
		}
	} );

	test( 'a stale nonce is refused', async ( { page, admin } ) => {
		await admin.visit( QUEUE );

		const href = await page
			.locator( 'a.button[href*="action=gwc_vt_verify_entry"]' )
			.first()
			.getAttribute( 'href' );

		const url = new URL( href, page.url() );

		url.searchParams.set( '_wpnonce', 'not-a-nonce' );

		const response = await page.goto( url.toString() );

		/* Core answers a failed check_admin_referer() with its own "Are you
		 * sure" page, at 403. What matters is that it is not a 200 that did
		 * the work. */
		expect( response.status() ).toBe( 403 );
		await expect( page.locator( 'body' ) ).toContainText(
			'The link you followed has expired'
		);
	} );
} );

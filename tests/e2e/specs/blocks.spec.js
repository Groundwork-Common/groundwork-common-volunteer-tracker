/**
 * The five blocks, in the editor and on the page.
 *
 * ── Why this is the test the rest of the suite cannot substitute for ─────────
 * There is no build step. Each blocks/ * /edit.js is hand-written ES5 against
 * wp.element, and its edit.asset.php is hand-written to match. Nothing checks
 * that they agree — a missing dependency in the asset file is a block that
 * throws in the editor on a site whose script loading order differs from the
 * one it was written on, and every other test in this repository would pass.
 *
 * What a broken block looks like: the editor draws a red panel reading "This
 * block has encountered an error and cannot be previewed", or marks the block
 * invalid and offers to convert it to HTML. Both are visible only in a browser,
 * and both leave the front of the site working — so the failure is silent
 * exactly where somebody is choosing whether to put the block on a page.
 *
 * The list of blocks is read from blocks/ * /block.json. A sixth block added
 * without a line here fails the guard in coverage.spec.js.
 */
const { test, expect, reset } = require( '../support/harness.js' );
const { blocks } = require( '../support/source.js' );

test.beforeAll( reset );

/** What the editor says when a block's script has thrown or drifted. */
const BROKEN = [
	'This block has encountered an error',
	'Your site doesn’t include support for this block',
	"Your site doesn't include support for this block",
	'This block contains unexpected or invalid content',
];

/**
 * Open the block editor on a page holding one block, past its welcome guide.
 *
 * @param {import('@playwright/test').Page} page   The page.
 * @param {number}                          postId Which page.
 */
async function editor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );

	/* The welcome modal covers the canvas on a fresh profile and swallows the
	 * first click of every test that meets it. */
	const welcome = page.getByRole( 'button', { name: 'Close', exact: true } );

	if ( await welcome.isVisible().catch( () => false ) ) {
		await welcome.click();
	}

	await page
		.locator( 'iframe[name="editor-canvas"], .block-editor-writing-flow' )
		.first()
		.waitFor( { timeout: 30000 } );
}

/** The editor canvas, which is inside an iframe in current WordPress. */
function canvas( page ) {
	const frame = page.frameLocator( 'iframe[name="editor-canvas"]' );

	return frame.locator( 'body' );
}

test.describe( 'the blocks', () => {
	test( 'there are five of them, and each names itself', () => {
		expect( blocks().map( ( block ) => block.name ) ).toEqual( [
			'groundwork-common-volunteer-tracker/event-grid',
			'groundwork-common-volunteer-tracker/hours-form',
			'groundwork-common-volunteer-tracker/shift-list',
			'groundwork-common-volunteer-tracker/volunteer-form',
			'groundwork-common-volunteer-tracker/volunteer-signin',
		] );
	} );

	for ( const block of blocks() ) {
		test( `${ block.dir } draws in the editor without throwing`, async ( {
			page,
			api,
		} ) => {
			const made = api( 'page.create', {
				title: `e2e ${ block.dir }`,
				content: `<!-- wp:${ block.name } /-->`,
			} );

			const errors = [];

			page.on( 'pageerror', ( error ) => errors.push( error.message ) );

			await editor( page, made.id );

			const drawn = await canvas( page ).innerText();

			for ( const complaint of BROKEN ) {
				expect( drawn ).not.toContain( complaint );
			}

			/* And the block is actually there, rather than quietly dropped.
			 * A block whose registration failed leaves an empty canvas, which
			 * contains none of the words above either. */
			await expect(
				page
					.frameLocator( 'iframe[name="editor-canvas"]' )
					.locator( `[data-type="${ block.name }"]` )
			).toHaveCount( 1 );

			/* Nothing threw on the way. This is what a missing dependency in a
			 * hand-written edit.asset.php looks like, and it is the reason
			 * those files are checked by a browser rather than by a linter. */
			expect( errors ).toEqual( [] );

			api( 'cleanup', { prefix: `e2e ${ block.dir }` } );
		} );

		test( `${ block.dir } renders on the front of the site`, async ( {
			page,
			api,
		} ) => {
			const made = api( 'page.create', {
				title: `e2e front ${ block.dir }`,
				content: `<!-- wp:${ block.name } /-->`,
			} );

			const response = await page.goto( made.url );

			expect( response.status() ).toBe( 200 );

			const shown = await page.locator( 'body' ).innerText();

			/* WP_DEBUG_DISPLAY is on here, so a warning from a render callback
			 * prints into the page rather than into a log nobody reads. */
			expect( shown ).not.toMatch(
				/(Warning|Notice|Deprecated|Fatal error):\s|There has been a critical error/
			);

			api( 'cleanup', { prefix: `e2e front ${ block.dir }` } );
		} );
	}

	test( 'every block is offered in the inserter', async ( { page, api } ) => {
		const made = api( 'page.create', {
			title: 'e2e inserter',
			content: '<!-- wp:paragraph --><p>Nothing yet.</p><!-- /wp:paragraph -->',
		} );

		await editor( page, made.id );

		/* Registered in the editor, and findable by the name a site owner would
		 * type. A block that renders perfectly and cannot be found is a block
		 * nobody uses. */
		const registered = await page.evaluate( () =>
			window.wp.blocks
				.getBlockTypes()
				.map( ( type ) => type.name )
				.filter( ( name ) =>
					name.startsWith( 'groundwork-common-volunteer-tracker/' )
				)
				.sort()
		);

		expect( registered ).toEqual( blocks().map( ( block ) => block.name ) );

		api( 'cleanup', { prefix: 'e2e inserter' } );
	} );

	test( 'the event grid shortcode renders an event by id', async ( {
		page,
		api,
		fixtures,
	} ) => {
		/* The one shortcode that takes an attribute, because an event has no
		 * URL of its own: gwc_vt_event is public => false, so the grid is only
		 * ever seen on a page somebody placed it on, and that page has to say
		 * WHICH event. */
		const event = fixtures.events[ 0 ];

		const made = api( 'page.create', {
			title: 'e2e event shortcode',
			content: `[gwc_vt_event_grid id="${ event.id }"]`,
		} );

		const response = await page.goto( made.url );

		expect( response.status() ).toBe( 200 );

		const shown = await page.locator( 'body' ).innerText();

		expect( shown ).toContain( event.title );
		expect( shown ).not.toContain( '[gwc_vt_event_grid' );

		/* And still no roster on a public page. The grid says what times exist
		 * and how many places are left; who is coming is a staff screen. */
		for ( const name of Object.keys( fixtures.volunteers ) ) {
			expect( shown ).not.toContain( name );
		}

		api( 'cleanup', { prefix: 'e2e event shortcode' } );
	} );

	test( 'the shortcodes render the same features as the blocks', async ( {
		page,
		api,
	} ) => {
		/* Both markers exist because both placements do, and neither contains
		 * the other — which is why gwc_vt_event_page_id() searches twice. A
		 * site that has had the shortcodes since before the blocks is a site
		 * this plugin still has to work on. */
		const made = api( 'page.create', {
			title: 'e2e shortcodes',
			content:
				'[gwc_vt_hours_form]\n[gwc_vt_shift_list]\n[gwc_vt_volunteer_form]\n[gwc_vt_volunteer_signin]',
		} );

		const response = await page.goto( made.url );

		expect( response.status() ).toBe( 200 );

		const shown = await page.locator( 'body' ).innerText();

		/* Not left on the page as literal text, which is what an unregistered
		 * shortcode looks like. */
		expect( shown ).not.toContain( '[gwc_vt_hours_form]' );
		expect( shown ).not.toContain( '[gwc_vt_shift_list]' );

		expect( shown ).not.toMatch(
			/(Warning|Notice|Deprecated|Fatal error):\s/
		);

		api( 'cleanup', { prefix: 'e2e shortcodes' } );
	} );
} );

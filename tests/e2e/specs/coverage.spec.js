/**
 * The guard that makes "every path" a claim somebody can check.
 *
 * ── Why this file exists ─────────────────────────────────────────────────────
 * A suite that silently tests less than it appears to reports success either
 * way. This repository has been caught by that twice already, and both times
 * the fix was the same shape: read the list of things to cover out of the
 * SOURCE, and fail when the list and the tests disagree.
 *
 *   The integration job globs tests/integration/ rather than naming files,
 *   because the hand-written list had six names on it and the directory had
 *   eleven scripts — five milestones of coverage that ran nowhere.
 *
 *   DashboardTest reads the worklist's keys out of inc/dashboard.php, because
 *   a fixture that lists eight lines cannot notice a ninth.
 *
 * This is the same trick for the browser suite. Every admin_post action, every
 * screen, every shortcode, every REST route and every block is read from the
 * plugin's own files, and each has to be named by a spec that drives it.
 *
 * ── What "named by a spec" means, and why it is not a rubber stamp ───────────
 * The action name is what a form's hidden `action` field carries and what a
 * nonced link puts in its query string. A spec that drives the real control
 * ends up naming it — in the locator that finds the form, in the URL it waits
 * for, or in the file's own header saying what it covers. So the requirement is
 * that the literal string appears in a spec file.
 *
 * That is a weak check taken alone: somebody could satisfy it with a comment.
 * It is not weak in company. The spec that names an action is a spec somebody
 * had to write, and the twenty files beside it set the standard for what
 * writing one means. What this guard actually prevents is the other thing — a
 * forty-sixth handler added in six months, with nobody realising the browser
 * suite never learned about it.
 */
const { test, expect } = require( '../support/harness.js' );
const { readdirSync, readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const {
	adminPostActions,
	screenSlugs,
	shortcodes,
	restRoutes,
	blocks,
	postTypes,
} = require( '../support/source.js' );

/** Every spec in this directory, as one string, excluding this one. */
const SPECS = ( () => {
	const dir = __dirname;

	return readdirSync( dir )
		.filter(
			( name ) => name.endsWith( '.spec.js' ) && 'coverage.spec.js' !== name
		)
		.map( ( name ) => readFileSync( join( dir, name ), 'utf8' ) )
		.join( '\n' );
} )();

/** How many spec files there are, so an empty glob cannot look like a pass. */
const SPEC_COUNT = readdirSync( __dirname ).filter(
	( name ) => name.endsWith( '.spec.js' )
).length;

test.describe( 'coverage', () => {
	test( 'the suite is actually here', () => {
		/* A glob that matches nothing exits zero and looks exactly like a green
		 * run. The number is asserted for the same reason the integration job
		 * asserts its own count. */
		expect( SPEC_COUNT ).toBeGreaterThanOrEqual( 15 );
		expect( SPECS.length ).toBeGreaterThan( 50000 );
	} );

	test( 'every admin_post handler is driven by a spec', () => {
		const actions = adminPostActions();

		/* The count is asserted too. If the regex in source.js stops matching,
		 * this loop runs over an empty list and passes while checking nothing —
		 * which is the failure mode the whole file is written against. */
		expect( actions.length ).toBeGreaterThanOrEqual( 45 );

		const missing = actions.filter( ( action ) => ! SPECS.includes( action ) );

		expect(
			missing,
			`These admin_post handlers have no spec naming them:\n  ${ missing.join(
				'\n  '
			) }`
		).toEqual( [] );
	} );

	test( 'every admin screen is opened by a spec', () => {
		const slugs = screenSlugs().map( ( screen ) => screen.slug );

		expect( slugs.length ).toBe( 10 );

		const missing = slugs.filter( ( slug ) => ! SPECS.includes( slug ) );

		expect(
			missing,
			`These screens are never opened:\n  ${ missing.join( '\n  ' ) }`
		).toEqual( [] );
	} );

	test( 'every shortcode and every block is rendered by a spec', () => {
		const names = [
			...shortcodes(),
			...blocks().map( ( block ) => block.name ),
		];

		expect( names.length ).toBe( 10 );

		const missing = names.filter( ( name ) => ! SPECS.includes( name ) );

		expect(
			missing,
			`These placements are never rendered:\n  ${ missing.join( '\n  ' ) }`
		).toEqual( [] );
	} );

	test( 'every REST route is called by a spec', () => {
		const routes = restRoutes();

		expect( routes.length ).toBe( 3 );

		const missing = routes.filter( ( route ) => ! SPECS.includes( route ) );

		expect(
			missing,
			`These routes are never called:\n  ${ missing.join( '\n  ' ) }`
		).toEqual( [] );
	} );

	test( 'every post type is looked at by a spec', () => {
		const types = postTypes();

		expect( types.length ).toBe( 10 );

		const missing = types.filter( ( type ) => ! SPECS.includes( type ) );

		expect(
			missing,
			`These post types are never inspected:\n  ${ missing.join( '\n  ' ) }`
		).toEqual( [] );
	} );

	test( 'the public surfaces each have a spec of their own', () => {
		/* Named rather than derived, because "the public surfaces" is a product
		 * decision rather than a thing the source enumerates — and because
		 * these four are the ones where a regression is a disclosure rather
		 * than an inconvenience. */
		const files = readdirSync( __dirname );

		for ( const surface of [
			'public-self-log.spec.js',
			'public-signup.spec.js',
			'public-registration.spec.js',
			'public-signin.spec.js',
		] ) {
			expect( files, `${ surface } is missing` ).toContain( surface );
		}
	} );
} );

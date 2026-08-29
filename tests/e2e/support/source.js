/**
 * What the plugin's own source says its surfaces are.
 *
 * Every list in this file is read out of inc/ and blocks/ rather than typed
 * here. That is the same trick DashboardTest plays on the worklist and the
 * integration job plays on tests/integration/: a hand-kept list of what to
 * cover is a list that stops being complete the first time somebody adds
 * something and does not think of it — and a suite that silently covers less
 * than it looks like it does reports success either way.
 *
 * tests/e2e/specs/coverage.spec.js is what turns these into a failing test.
 */
const { readFileSync, readdirSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const { ROOT } = require( './wp.js' );

const INC = join( ROOT, 'inc' );
const BLOCKS = join( ROOT, 'blocks' );

/** Every .php under inc/, as one string. Cached: this is read many times. */
let incSource = null;

function inc() {
	if ( null === incSource ) {
		incSource = readdirSync( INC )
			.filter( ( name ) => name.endsWith( '.php' ) )
			.map( ( name ) => readFileSync( join( INC, name ), 'utf8' ) )
			.join( '\n' );
	}

	return incSource;
}

/**
 * Every action name behind an `admin_post_` handler.
 *
 * These are the plugin's write paths: 45 of them at the time of writing, and
 * every one is a form somebody submits. The name is what the form's hidden
 * `action` field carries, which is why a spec that drives the real form can be
 * checked for it.
 *
 * @return {string[]} Sorted, e.g. 'gwc_vt_verify_entry'.
 */
function adminPostActions() {
	const found = new Set();
	const pattern = /add_action\(\s*'admin_post(?:_nopriv)?_([a-z0-9_]+)'/g;

	let match;

	while ( ( match = pattern.exec( inc() ) ) ) {
		found.add( match[ 1 ] );
	}

	return [ ...found ].sort();
}

/**
 * Every admin screen the plugin adds, as { constant, slug }.
 *
 * Read from the constants in inc/admin-screen.php rather than from the
 * add_submenu_page() calls, because the calls name a constant and the
 * constant is where the string lives. Typing the strings here is exactly the
 * mistake that cost a first run of this suite an afternoon: the Log-a-day
 * screen's slug is `gwc-vt-log-a-day`, not `gwc-vt-quick-add`, and a wrong one
 * fails as a 403 that reads like a capability problem.
 *
 * @return {{constant: string, slug: string}[]}
 */
function screenSlugs() {
	const found = [];
	const pattern = /const\s+(GWC_VT_[A-Z_]*PAGE)\s*=\s*'([a-z0-9-]+)'/g;

	let match;

	while ( ( match = pattern.exec( inc() ) ) ) {
		found.push( { constant: match[ 1 ], slug: match[ 2 ] } );
	}

	return found.sort( ( a, b ) => a.slug.localeCompare( b.slug ) );
}

/**
 * Every shortcode the plugin registers.
 *
 * @return {string[]}
 */
function shortcodes() {
	const found = new Set();
	const pattern = /add_shortcode\(\s*'([a-z0-9_]+)'/g;

	let match;

	while ( ( match = pattern.exec( inc() ) ) ) {
		found.add( match[ 1 ] );
	}

	return [ ...found ].sort();
}

/**
 * Every REST route, as a path under the plugin's namespace.
 *
 * @return {string[]}
 */
function restRoutes() {
	const found = new Set();
	const pattern = /register_rest_route\(\s*GWC_VT_REST_NAMESPACE,\s*'([^']+)'/g;

	let match;

	while ( ( match = pattern.exec( inc() ) ) ) {
		found.add( match[ 1 ] );
	}

	return [ ...found ].sort();
}

/** The REST namespace, read from its constant. */
function restNamespace() {
	const match = /const\s+GWC_VT_REST_NAMESPACE\s*=\s*'([^']+)'/.exec( inc() );

	if ( ! match ) {
		throw new Error( 'GWC_VT_REST_NAMESPACE is not where it was.' );
	}

	return match[ 1 ];
}

/**
 * Every block, as { dir, name, title }.
 *
 * @return {{dir: string, name: string, title: string}[]}
 */
function blocks() {
	return readdirSync( BLOCKS, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.map( ( entry ) => {
			const json = JSON.parse(
				readFileSync( join( BLOCKS, entry.name, 'block.json' ), 'utf8' )
			);

			return { dir: entry.name, name: json.name, title: json.title };
		} )
		.sort( ( a, b ) => a.name.localeCompare( b.name ) );
}

/** Every post type the plugin registers, read from its type constants. */
function postTypes() {
	const found = new Set();
	const pattern = /const\s+GWC_VT_[A-Z_]*TYPE\s*=\s*'(gwc_vt_[a-z_]+)'/g;

	let match;

	while ( ( match = pattern.exec( inc() ) ) ) {
		found.add( match[ 1 ] );
	}

	return [ ...found ].sort();
}

module.exports = {
	adminPostActions,
	screenSlugs,
	shortcodes,
	restRoutes,
	restNamespace,
	blocks,
	postTypes,
};

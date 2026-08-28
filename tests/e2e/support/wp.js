/**
 * The bridge from Node to the WordPress under test.
 *
 * ── Why everything goes through bin/wpenv ────────────────────────────────────
 * Because that is the rule the rest of this repository follows, and the reasons
 * are in CLAUDE.md: run wp-env directly from a worktree and you get a whole new
 * four-container instance, and a mount whose basename is the worktree's rather
 * than the plugin's. A test harness that quietly built its own environment
 * would be testing a copy of the plugin nobody else is looking at.
 *
 * The wrapper also prints a banner, and wp-env prints its own two lines around
 * every command. So anything that has to come back as data is wrapped in
 * sentinels by the PHP that produced it and cut out here, rather than parsed
 * out of "whatever was on stdout" — which worked until the day the banner grew
 * a line about stray stacks.
 *
 * ── Why this is not @wordpress/e2e-test-utils-playwright ─────────────────────
 * That package is the canonical answer and it is a reasonable one. It is not
 * used here for the same reason blocks/ * /edit.js is hand-written ES5: this
 * repository's standing preference is a small amount of code it owns over a
 * dependency it does not. The utils this suite actually needs are login,
 * a wp-cli call and a mailbox, and they are on this page.
 */
const { spawnSync } = require( 'node:child_process' );
const { join } = require( 'node:path' );

/** The repository root — two levels up from tests/e2e/support. */
const ROOT = join( __dirname, '..', '..', '..' );

/** Wraps anything a PHP helper wants to hand back, so the banner can be cut off. */
const OPEN = '<<<GWCVT_E2E';
const CLOSE = 'GWCVT_E2E>>>';

/**
 * Run `bin/wpenv` with the given arguments and return its combined output.
 *
 * @param {string[]} args   Passed to bin/wpenv untouched.
 * @param {object}   [opts] timeout in ms.
 * @return {string} stdout.
 */
function wpenv( args, opts = {} ) {
	/* spawnSync rather than execFileSync, and both streams kept, because the
	 * two halves of an answer arrive on different ones: the wrapper's banner and
	 * wp-env's own framing go to stderr, WP-CLI's `Error:` line goes to stderr,
	 * and the script's output goes to stdout. execFileSync returns stdout alone,
	 * so a failure came back as an empty string and the only symptom was a
	 * parse error a long way from the cause. */
	const run = spawnSync( join( ROOT, 'bin', 'wpenv' ), args, {
		cwd: ROOT,
		encoding: 'utf8',
		timeout: opts.timeout ?? 180000,
		maxBuffer: 32 * 1024 * 1024,
	} );

	if ( run.error ) {
		throw run.error;
	}

	const out = ( run.stdout || '' ) + ( run.stderr || '' );

	if ( 0 !== run.status && ! opts.allowFailure ) {
		throw new Error(
			`bin/wpenv ${ args.join( ' ' ) } exited ${ run.status }:\n${ out }`
		);
	}

	return out;
}

/**
 * Run one WP-CLI command in the container.
 *
 * @param {string[]} args   WP-CLI arguments, e.g. [ 'post', 'list' ].
 * @param {object}   [opts] timeout in ms.
 * @return {string} What WP-CLI printed, with wp-env's own framing removed.
 */
function wpCli( args, opts = {} ) {
	const out = wpenv( [ 'run', 'cli', '--', 'wp', ...args ], opts );

	/* wp-env brackets every run with a "Starting" and a "Ran" line, and the
	 * wrapper prints its banner above them. Neither is output from wp. */
	return out
		.split( '\n' )
		.filter(
			( line ) =>
				! line.startsWith( 'wpenv:' ) &&
				! line.startsWith( 'ℹ Starting' ) &&
				! line.startsWith( '✔ Ran' )
		)
		.join( '\n' )
		.trim();
}

/**
 * Run a PHP file from tests/e2e/support inside WordPress and read back its JSON.
 *
 * The file is expected to print exactly one sentinel-wrapped JSON document. The
 * sentinels are what make this safe: a warning, a deprecation notice or a stray
 * echo lands outside them and is ignored rather than corrupting the parse — and
 * WP_DEBUG_DISPLAY is on in this environment, so those are not hypothetical.
 *
 * @param {string} script Filename under tests/e2e/support, e.g. 'fixtures.php'.
 * @param {object} [args] The script's one argument, as base64 JSON.
 * @param {object} [opts] timeout in ms.
 * @return {any} The decoded document.
 */
function wpEval( script, args = {}, opts = {} ) {
	const base = 'wp-content/plugins/groundwork-common-volunteer-tracker/tests/e2e/support';

	/* Arguments travel as one base64 argument.
	 *
	 * `wp eval-file` does hand extra arguments to the script as $args, and that
	 * is what this uses — but the value has to survive the container's
	 * entrypoint shell on the way, and a JSON document is nothing but shell
	 * metacharacters. base64 is [A-Za-z0-9+/=], which no shell touches, so the
	 * quoting question stops existing rather than being answered carefully.
	 *
	 * The obvious alternative — write a file in the mounted worktree and pass
	 * its path — was tried and is worse here. A directory created after the
	 * environment started is not visible inside the container until it is
	 * remounted, so the first run of a fresh checkout would fail with a missing
	 * file and no hint as to why.
	 */
	const packed = Buffer.from( JSON.stringify( args ), 'utf8' ).toString( 'base64' );

	const out = wpenv(
		[ 'run', 'cli', '--', 'wp', 'eval-file', `${ base }/${ script }`, packed ],
		opts
	);

	const start = out.indexOf( OPEN );
	const end = out.indexOf( CLOSE );

	if ( start < 0 || end < 0 ) {
		throw new Error(
			`${ script } printed no sentinel-wrapped result. Output was:\n${ out }`
		);
	}

	const body = out.slice( start + OPEN.length, end ).trim();

	try {
		return JSON.parse( body );
	} catch ( e ) {
		throw new Error( `${ script } printed unparseable JSON:\n${ body }` );
	}
}

wpEval.serial = 0;

module.exports = { ROOT, wpenv, wpCli, wpEval, OPEN, CLOSE };

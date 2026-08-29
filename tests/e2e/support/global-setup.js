/**
 * What has to be true before the first spec runs.
 *
 * Four things, in this order, because each one's failure is a different sentence
 * and the later ones are unreadable when an earlier one is wrong:
 *
 *   1. The site is up, and it is the site this config is pointed at.
 *   2. It is running THIS worktree's copy of the plugin, and not a sibling's.
 *   3. The fixture is rebuilt, and the mail trap is installed.
 *   4. The administrator is signed in, once, into a stored session.
 *
 * ── Why the fixture is rebuilt every run ─────────────────────────────────────
 * Because the suite writes. A run that verified an entry leaves it verified,
 * and the next run's "verify this entry" spec finds a button that is not there.
 * tests/seed.php is re-runnable by design and clears its own work first, so the
 * cheapest correct answer is to use it as the reset.
 *
 * Skip it with GWC_VT_E2E_NO_SEED=1 when iterating on one spec — but a red run
 * under that flag is not evidence of anything until it has been reproduced
 * without it.
 */
const { chromium, request } = require( '@playwright/test' );
const { createHash } = require( 'node:crypto' );
const { mkdirSync, readFileSync, writeFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const { wpEval, ROOT } = require( './wp.js' );

const STATE = join( ROOT, 'tests', 'e2e', '.state' );

/** wp-env's administrator. Fixed by wp-env itself, not by anything here. */
const ADMIN = { user: 'admin', pass: 'password' };

module.exports = async ( config ) => {
	const baseURL = config.projects[ 0 ].use.baseURL;

	mkdirSync( STATE, { recursive: true } );

	/* ── 1. Is anything there? ───────────────────────────────────────────── */
	const probe = await request.newContext();

	let reachable = false;

	try {
		const response = await probe.get( baseURL, { timeout: 15000 } );
		reachable = response.ok();
	} catch ( e ) {
		reachable = false;
	} finally {
		await probe.dispose();
	}

	if ( ! reachable ) {
		throw new Error(
			`Nothing answering at ${ baseURL }.\n\n` +
				'Start the environment first:\n\n' +
				'    bin/wpenv start\n\n' +
				'It prints the port it pinned. If that is not the one above, ' +
				'set GWC_VT_E2E_URL to match.'
		);
	}

	/* ── 2. Is it running THIS copy of the plugin? ───────────────────────────
	 * One wp-env environment is shared by every worktree of this plugin, and
	 * `bin/wpenv start` remounts it on whichever worktree ran it last. A second
	 * session working on another branch therefore takes this site over without
	 * either session noticing.
	 *
	 * What that looks like from in here is not "the environment changed". It is
	 * files that stop existing partway through a run, or — worse — a spec
	 * failing on an assertion that is perfectly true of somebody else's branch.
	 * The first long run of this suite lost a letters test that way and it was
	 * indistinguishable from a flake for an hour.
	 *
	 * So: hash the bootstrap here, ask the container for the hash of the one it
	 * loaded, and stop with one sentence when they differ.
	 * ──────────────────────────────────────────────────────────────────────── */
	const fingerprint = wpEval( 'api.php', { op: 'fingerprint' } );

	const mine = createHash( 'md5' )
		.update(
			readFileSync(
				join( ROOT, 'groundwork-common-volunteer-tracker.php' )
			)
		)
		.digest( 'hex' );

	if ( fingerprint.hash !== mine ) {
		throw new Error(
			`The site at ${ baseURL } is running a different copy of this plugin.\n\n` +
				`It has loaded ${ fingerprint.dir } (version ${ fingerprint.version }), ` +
				'whose bootstrap does not match the one in this worktree.\n\n' +
				'One wp-env environment is shared by every worktree, and `start` ' +
				'points it at whichever ran last — so another session has taken it ' +
				'over. Run:\n\n    bin/wpenv start\n\n' +
				'from this worktree, and start again.'
		);
	}

	/* ── 3. The fixture ──────────────────────────────────────────────────── */
	const fixtures =
		'1' === process.env.GWC_VT_E2E_NO_SEED
			? wpEval( 'api.php', { op: 'fixtures' } )
			: wpEval( 'api.php', { op: 'seed' }, { timeout: 300000 } );

	/* The check that turns a wrong port into one sentence. Playwright would
	 * otherwise drive a different WordPress perfectly happily — very likely a
	 * sibling plugin's, since this machine runs several — and every failure
	 * would be about a missing menu rather than about the address. */
	if ( fixtures.baseUrl.replace( /\/$/, '' ) !== baseURL.replace( /\/$/, '' ) ) {
		throw new Error(
			`The site at ${ baseURL } says its home_url is ${ fixtures.baseUrl }.\n` +
				'That is a different WordPress from the one this run is driving. ' +
				'Set GWC_VT_E2E_URL, or run bin/wpenv start from this worktree.'
		);
	}

	if ( ! fixtures.volunteers[ 'Marcus Delacroix' ] ) {
		throw new Error(
			'The fixture has no volunteers in it. tests/seed.php did not run, or ' +
				'ran against an environment where it refuses to — check ' +
				'WP_ENVIRONMENT_TYPE is local or development.'
		);
	}

	writeFileSync(
		join( STATE, 'fixtures.json' ),
		JSON.stringify( fixtures, null, '\t' )
	);

	/* ── 4. The signed-in administrator ──────────────────────────────────── */
	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN.user );
	await page.fill( '#user_pass', ADMIN.pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	/* Assert the session rather than assume it. A failed login lands back on
	 * wp-login.php with a message, which is a page, which waitForURL is happy
	 * with if the redirect happened to carry wp-admin in a query argument. */
	if ( 0 === ( await page.locator( '#wpadminbar' ).count() ) ) {
		await browser.close();
		throw new Error(
			`Could not sign in as ${ ADMIN.user } at ${ baseURL }. ` +
				'wp-env sets admin/password; a site with different credentials is ' +
				'not the environment this suite is written against.'
		);
	}

	await page.context().storageState( { path: join( STATE, 'admin.json' ) } );
	await browser.close();
};

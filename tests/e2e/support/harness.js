/**
 * What every spec starts by requiring.
 *
 *   const { test, expect } = require( '../support/harness.js' );
 *
 * Playwright's own `test` with four fixtures added: the seeded data, the back
 * door, the mailbox, and a small set of admin conveniences. Nothing here knows
 * anything about a particular screen — that belongs in the spec that drives it.
 */
const base = require( '@playwright/test' );
const { createHash } = require( 'node:crypto' );
const { readFileSync, writeFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const { wpEval, ROOT } = require( './wp.js' );

const STATE = join( ROOT, 'tests', 'e2e', '.state' );
const MAP = join( STATE, 'fixtures.json' );

/**
 * Put the site back to the seeded fixture, and rewrite the map.
 *
 * ── Why a spec file resets rather than trusting the one in global setup ──────
 * Because the suite writes, and what one spec writes the next one reads. The
 * first version of this file did not reset, and volunteer-record.spec.js
 * passed every test in isolation and failed one of them in sequence: the
 * "discard a draft" test found two drafts on Marcus Delacroix, because the
 * test above it had already started one.
 *
 * Per FILE rather than per test, deliberately. A reset is a seed and a WP-CLI
 * round trip — call it before every test and the suite spends more time
 * seeding than testing. Per file, each spec file is independent of every other
 * one, and the tests inside it are ordered by the file that owns them, which
 * is a thing a person can read.
 *
 * The consequence is worth stating plainly: tests within one file share a
 * site. Two tests in the same file that want the same volunteer in different
 * states should use different volunteers — the seed has six, in six states,
 * for exactly this.
 *
 * @return {object} The rebuilt fixture map.
 */
function reset() {
	const fixtures = wpEval( 'api.php', { op: 'seed' }, { timeout: 300000 } );

	/* Still this worktree's plugin?
	 *
	 * global-setup.js asks the same question before the run starts. It is asked
	 * again here because the answer can change DURING one: a single wp-env
	 * environment is shared by every worktree of this plugin, and
	 * `bin/wpenv start` from a sibling branch remounts it underneath whatever is
	 * running.
	 *
	 * That happened, and the nineteen failures it produced said nothing about
	 * it — one file's reset died with a container error, and every file after it
	 * inherited the wreckage. The fingerprint rides along in the seed's reply,
	 * so asking costs nothing.
	 */
	const mine = createHash( 'md5' )
		.update(
			readFileSync( join( ROOT, 'groundwork-common-volunteer-tracker.php' ) )
		)
		.digest( 'hex' );

	if ( fixtures.fingerprint && fixtures.fingerprint.hash !== mine ) {
		throw new Error(
			'The environment has been remounted onto another worktree part way ' +
				`through this run — it is now serving ${ fixtures.fingerprint.dir }.\n\n` +
				'Nothing after this point would be testing this branch. Run ' +
				'`bin/wpenv start` from this worktree and start again.'
		);
	}

	writeFileSync( MAP, JSON.stringify( fixtures, null, '\t' ) );

	return fixtures;
}

/** wp-admin's menu parent for every screen this plugin adds. */
const MENU = 'edit.php?post_type=gwc_vt_entry';

/**
 * The admin URL of one of the plugin's screens.
 *
 * @param {string} slug  A page slug, e.g. 'gwc-vt-verify'. Empty for the list.
 * @param {object} [qs]  Extra query arguments.
 * @return {string} A path, relative to baseURL.
 */
function screenUrl( slug = '', qs = {} ) {
	const params = new URLSearchParams( { post_type: 'gwc_vt_entry' } );

	if ( slug ) {
		params.set( 'page', slug );
	}

	for ( const [ key, value ] of Object.entries( qs ) ) {
		params.set( key, String( value ) );
	}

	return `/wp-admin/edit.php?${ params.toString() }`;
}

const test = base.test.extend( {
	/* ── Nothing leaves the machine ───────────────────────────────────────────
	 * wp-admin asks the outside world for two things on nearly every screen:
	 * avatars from gravatar.com, and update information from api.wordpress.org.
	 * Neither is this plugin's, and both are on the critical path of
	 * page.goto()'s `load` event — so a slow or blocked route out turns every
	 * screen in the suite into a sixty-second timeout, with a stack trace
	 * pointing at whatever assertion happened to be next.
	 *
	 * That is not hypothetical; it is what the first run of this suite did, on
	 * the quick-add screen, which draws an avatar for every volunteer it lists.
	 *
	 * Aborted rather than stubbed, because a request this plugin makes to an
	 * external host would be a bug, and a suite that quietly answered one would
	 * be hiding it.
	 */
	/* Every confirm() this run meets, in order, and all of them accepted.
	 *
	 * Playwright's default is to DISMISS a dialog, which for an
	 * `onclick="return confirm(...)"` means the handler returns false and the
	 * form never submits. Nothing errors: the click succeeds, the page does not
	 * navigate, and the assertion that follows reports that the shift was not
	 * cancelled — which is true, and which reads as a bug in the plugin. Two of
	 * this plugin's destructive controls are guarded that way, and both cost an
	 * afternoon before this fixture existed.
	 *
	 * Accepted rather than dismissed because accepting is what the person
	 * pressing a delete button does. The messages are kept so a spec can assert
	 * that the warning actually said something.
	 */
	dialogs: async ( {}, use ) => {
		await use( [] );
	},

	page: async ( { page, baseURL, dialogs }, use ) => {
		const host = new URL( baseURL ).host;

		page.on( 'dialog', async ( dialog ) => {
			dialogs.push( dialog.message() );
			await dialog.accept();
		} );

		await page.route( '**/*', ( route ) => {
			const target = new URL( route.request().url() );

			if ( 'data:' === target.protocol || target.host === host ) {
				route.continue();
				return;
			}

			route.abort();
		} );

		await use( page );
	},

	/* The fixture map as it stands now.
	 *
	 * Test-scoped and re-read every time, not cached for the worker: reset()
	 * rewrites the file, and every id in it changes when it does. A map read
	 * once at the top of the run is a map full of post ids that were deleted
	 * by the first spec file's beforeAll. */
	fixtures: async ( {}, use ) => {
		await use( JSON.parse( readFileSync( MAP, 'utf8' ) ) );
	},

	/* The back door. See tests/e2e/support/api.php for the rule it is written
	 * under: arrange and inspect, never act. */
	api: async ( {}, use ) => {
		await use( ( op, args = {} ) => wpEval( 'api.php', { op, ...args } ) );
	},

	/* Outgoing mail, trapped at pre_wp_mail by the mu-plugin api.php installs.
	 * Emptied before each test that takes this fixture, so "the last message"
	 * means the last message this test caused. */
	mailbox: async ( {}, use ) => {
		wpEval( 'api.php', { op: 'mail.clear' } );

		const read = () => wpEval( 'api.php', { op: 'mail.read' } );

		await use( {
			read,
			clear: () => wpEval( 'api.php', { op: 'mail.clear' } ),

			/**
			 * Every trapped message whose subject or body matches.
			 *
			 * @param {RegExp|string} pattern What to look for.
			 * @return {object[]} The matches, oldest first.
			 */
			matching( pattern ) {
				const re =
					pattern instanceof RegExp ? pattern : new RegExp( pattern, 'i' );

				return read().filter(
					( mail ) => re.test( mail.subject ) || re.test( mail.message )
				);
			},

			/**
			 * The messages addressed to one recipient.
			 *
			 * @param {string} address The address.
			 * @return {object[]} The matches, oldest first.
			 */
			to( address ) {
				return read().filter( ( mail ) =>
					mail.to.some( ( one ) =>
						String( one ).toLowerCase().includes( address.toLowerCase() )
					)
				);
			},
		} );
	},

	/* wp-admin conveniences. Deliberately thin: navigation, notices, and the
	 * sheet mechanism, which is the one interaction pattern every screen in
	 * this plugin shares. */
	admin: async ( { page }, use ) => {
		await use( {
			MENU,
			screenUrl,

			/**
			 * Open one of the plugin's screens.
			 *
			 * @param {string} slug A page slug, or '' for the entries list.
			 * @param {object} [qs] Extra query arguments.
			 */
			async visit( slug = '', qs = {} ) {
				await page.goto( screenUrl( slug, qs ) );
			},

			/**
			 * Open a post's editor.
			 *
			 * @param {number} id The post ID.
			 */
			async edit( id ) {
				await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
			},

			/**
			 * Open a sheet by pressing its trigger, and wait for it to be on
			 * screen.
			 *
			 * A sheet is hidden by CSS under `body.js` rather than by the
			 * `hidden` attribute — see the long note in inc/admin-sheet.php —
			 * so "is it open" is a question about visibility, and the trigger
			 * is itself hidden until the same class arrives.
			 *
			 * @param {string} sheet The sheet's id.
			 * @return {import('@playwright/test').Locator} The panel.
			 */
			async openSheet( sheet ) {
				const trigger = page.locator( `[data-gwcvt-sheet-open="${ sheet }"]` );

				await trigger.waitFor( { state: 'visible' } );
				await trigger.click();

				const panel = page.locator( `[data-gwcvt-sheet="${ sheet }"]` );

				await base.expect( panel ).toBeVisible();

				return panel;
			},

			/**
			 * Click a link inside a list table's row actions.
			 *
			 * wp-admin parks row actions at `left: -9999em` and brings them
			 * back on :hover, rather than hiding them with `display`. So the
			 * link is in the DOM, passes a visibility check, and cannot be
			 * clicked — Playwright says "element is outside of the viewport",
			 * which reads like a scrolling problem and is not one. force: true
			 * does not help; the element really is nowhere near the pointer.
			 *
			 * @param {import('@playwright/test').Locator} link The row action.
			 */
			async rowAction( link ) {
				await link.locator( 'xpath=ancestor::tr[1]' ).hover();
				await link.click();
			},

			/**
			 * The form on this page that posts to one admin_post action.
			 *
			 * Every write in this plugin goes to admin-post.php with the action
			 * in a hidden field, so this is how a spec gets hold of the exact
			 * form it means on a screen that carries several.
			 *
			 * @param {string} action The action name, e.g. 'gwc_vt_log_hours'.
			 * @return {import('@playwright/test').Locator} The form.
			 */
			formFor( action ) {
				return page.locator( 'form' ).filter( {
					has: page.locator(
						`input[name="action"][value="${ action }"]`
					),
				} );
			},

			/**
			 * The admin notice area, as text.
			 *
			 * @return {Promise<string>} Everything wp-admin is currently saying.
			 */
			async notices() {
				const found = page.locator(
					'.notice, .updated, .error, .wrap > .gwcvt-notice'
				);

				return ( await found.allTextContents() ).join( '\n' ).trim();
			},
		} );
	},
} );

module.exports = { test, expect: base.expect, reset, screenUrl, MENU };

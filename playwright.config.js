/**
 * The browser suite.
 *
 * Development tooling. Nothing here reaches a release zip — package.json,
 * playwright.config.js, node_modules and tests/ are all in .distignore, the
 * same arrangement Composer already has. See hard rule 8 in CLAUDE.md.
 *
 *   bin/wpenv start
 *   npm install && npx playwright install chromium
 *   npm run test:e2e
 *
 * ── Why one worker ───────────────────────────────────────────────────────────
 * There is one WordPress behind this suite, and most of what is under test is a
 * write. Two workers means one spec's `settings.set` landing in the middle of
 * another spec's page load, and a failure that reproduces one run in five. The
 * suite is minutes rather than seconds; the alternative is a suite nobody
 * trusts. Raise it with GWC_VT_E2E_WORKERS if you know what a given run
 * touches.
 *
 * ── Why the port is not discovered ───────────────────────────────────────────
 * bin/wpenv pins it and prints it. Reading it here would mean running the
 * wrapper — and therefore Docker — before Playwright has decided whether it is
 * going to run anything at all, including for `--list`. So the default matches
 * the wrapper's default and global-setup.js checks the site actually agrees,
 * which turns a wrong port into one sentence instead of forty timeouts.
 */
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.GWC_VT_E2E_URL || 'http://localhost:8898';

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	outputDir: './tests/e2e/.artifacts',
	globalSetup: require.resolve( './tests/e2e/support/global-setup.js' ),

	fullyParallel: false,
	workers: Number( process.env.GWC_VT_E2E_WORKERS || 1 ),

	/* No retries by default. A retry that turns a red run green is a flake
	 * being hidden, and this suite drives one database — every flake in it is
	 * something worth fixing rather than something worth re-rolling. */
	retries: Number( process.env.GWC_VT_E2E_RETRIES || 0 ),

	/* Refuse to pass a run whose specs were left focused. */
	forbidOnly: !! process.env.CI,

	timeout: 60000,
	expect: { timeout: 10000 },

	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: './tests/e2e/.report', open: 'never' } ],
	],

	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',

		/* Signed in as the administrator, because that is what nearly every
		 * screen in this plugin is for. The specs that need somebody else —
		 * a coordinator, a contributor, a stranger — say so with test.use(). */
		storageState: './tests/e2e/.state/admin.json',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );

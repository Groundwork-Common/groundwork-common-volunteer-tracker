/**
 * Logging a day's shifts in one go, and the volunteer picker it rests on.
 *
 * Covers gwc_vt_quick_add.
 *
 * ── Why the picker is the interesting part ───────────────────────────────────
 * This screen writes one entry per row, and each row names a volunteer through
 * a search box backed by the /volunteers REST route. The box is a text input
 * with a hidden field beside it; typing searches, and choosing writes the id
 * into the hidden field. Nothing about that is visible to a test that does not
 * run scripts — and the failure mode when it breaks is a screen that looks
 * fine and logs every row against volunteer 0.
 *
 * The other reason this screen needs a browser: its results list is a <ul>,
 * and every meta-box field wrapper in this plugin is a <div> rather than a <p>
 * precisely because a <ul> inside a <p> makes the parser close the paragraph
 * and everything still open inside it. No error, valid-looking HTML, and a
 * script that finds nothing and attaches no handlers.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/**
 * Type into one of the screen's pickers and choose the first match.
 *
 * @param {import('@playwright/test').Page} page  The page.
 * @param {number}                          row   Which row, from 0.
 * @param {string}                          name  What to type.
 */
async function pick( page, row, name ) {
	await page.fill( `#gwcvt-qa-name-${ row }`, name );

	const results = page.locator( `#gwcvt-qa-results-${ row } li` );

	await results.first().waitFor( { state: 'visible' } );
	await results.first().click();
}

test.describe( 'logging a day', () => {
	test( 'the picker finds a volunteer and the row is logged against them', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const marcus = fixtures.volunteers[ 'Marcus Delacroix' ];
		const priya = fixtures.volunteers[ 'Priya Ramanathan' ];

		await admin.visit( 'gwc-vt-log-a-day' );

		await page.fill( '#gwcvt-qa-date', '2026-08-15' );
		await page.fill( '#gwcvt-qa-activity', 'Packing weekend boxes' );
		await page.fill( '#gwcvt-qa-supervisor', 'Dana Reyes' );

		await pick( page, 0, 'Marcus' );
		await page.fill( '#gwcvt-qa-hours-0', '3' );

		await pick( page, 1, 'Priya' );
		await page.fill( '#gwcvt-qa-hours-1', '1:45' );

		await admin
			.formFor( 'gwc_vt_quick_add' )
			.first()
			.getByRole( 'button', { name: /Log/ } )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_qa=/ );

		const logged = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: {
				volunteer: '_gwc_vt_volunteer',
				minutes: '_gwc_vt_minutes',
				date: '_gwc_vt_date',
				activity: '_gwc_vt_activity',
				supervisor: '_gwc_vt_supervisor',
			},
		} ).filter( ( entry ) => entry.date === '2026-08-15' );

		expect( logged.length ).toBe( 2 );

		const forMarcus = logged.find(
			( entry ) => Number( entry.volunteer ) === marcus.id
		);
		const forPriya = logged.find(
			( entry ) => Number( entry.volunteer ) === priya.id
		);

		/* Named, not zero. A picker that has stopped working logs every row
		 * against nobody and the screen still says it logged two. */
		expect( forMarcus ).toBeTruthy();
		expect( forPriya ).toBeTruthy();

		/* Integer minutes, both ways of writing them. Hard rule 1. */
		expect( Number( forMarcus.minutes ) ).toBe( 180 );
		expect( Number( forPriya.minutes ) ).toBe( 105 );

		/* The day's activity and supervisor are typed once and land on every
		 * row, which is the whole reason this screen exists. */
		expect( forMarcus.activity ).toBe( 'Packing weekend boxes' );
		expect( forPriya.supervisor ).toBe( 'Dana Reyes' );
	} );

	test( 'a row with no name and no hours is skipped, not logged as nobody', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await admin.visit( 'gwc-vt-log-a-day' );

		await page.fill( '#gwcvt-qa-date', '2026-08-16' );
		await page.fill( '#gwcvt-qa-activity', 'Warehouse inventory' );

		/* One real row, and the rest left blank — which is how this screen is
		 * always used, since it renders more rows than any one day needs. */
		await pick( page, 0, 'Tomás' );
		await page.fill( '#gwcvt-qa-hours-0', '2' );

		await admin
			.formFor( 'gwc_vt_quick_add' )
			.first()
			.getByRole( 'button', { name: /Log/ } )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_qa=/ );

		const after = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: { volunteer: '_gwc_vt_volunteer', date: '_gwc_vt_date' },
		} );

		expect( after.length ).toBe( before + 1 );

		/* And nothing was written against volunteer 0. An empty row that
		 * produced an entry belonging to nobody would put a claim in the
		 * triage queue that no stranger ever made. */
		expect(
			after.filter(
				( entry ) => entry.date === '2026-08-16' && ! Number( entry.volunteer )
			)
		).toHaveLength( 0 );

		expect( fixtures.volunteers[ 'Tomás Beaulieu' ] ).toBeTruthy();
	} );

	test( 'a date it cannot read logs nothing', async ( { page, admin, api } ) => {
		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await admin.visit( 'gwc-vt-log-a-day' );

		const date = page.locator( '#gwcvt-qa-date' );

		/* Refused twice over, and the first refusal is the browser's: the
		 * field is required and is type="date", so an empty or nonsensical one
		 * never leaves the page. That is the refusal a person meets. */
		expect( await date.getAttribute( 'required' ) ).not.toBeNull();

		await page.fill( '#gwcvt-qa-date', '' );
		await pick( page, 0, 'Marcus' );
		await page.fill( '#gwcvt-qa-hours-0', '2' );

		const submit = admin
			.formFor( 'gwc_vt_quick_add' )
			.first()
			.getByRole( 'button', { name: /Log/ } )
			.first();

		await submit.click();

		expect( page.url() ).not.toContain( 'gwc_vt_qa=' );

		/* And then the handler's, when the browser is not the one asking. A
		 * validation that lives only in the markup is not a validation — and
		 * this one guards the date a letter prints. */
		await date.evaluate( ( field ) => field.removeAttribute( 'required' ) );

		await submit.click();
		await page.waitForURL( /gwc_vt_qa=/ );

		expect( page.url() ).toContain( 'gwc_vt_qa=bad-date' );
		expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe( before );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			/date/i
		);
	} );
} );

/**
 * The public hours form, as a stranger meets it.
 *
 * ── The rule this file exists for ────────────────────────────────────────────
 * Hard rule 3: accepted, honeypotted and rate-limited responses must stay
 * byte-identical. tests/SelfLogTest.php asserts that against the message table.
 * This asserts it against the rendered page — which is the thing an attacker
 * actually has, and which can differ from the message table by a hidden field,
 * a body class, or a form that comes back with different values in it.
 *
 * Diverging turns the form into an oracle for "is this named person working off
 * a court order", on a site that hosts mandated service. That is the disclosure
 * the whole design of this surface is arranged around, and it is why hard rule
 * 4 says the form never looks anybody up: with no lookup there is no code path
 * whose behaviour depends on the answer, so there is nothing to build an oracle
 * out of.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/* A stranger. Not signed in as anybody, which is the whole point — and not the
 * administrator this suite otherwise runs as, whose cookies would change what
 * the page renders. */
test.use( { storageState: { cookies: [], origins: [] } } );

/**
 * How long a person takes.
 *
 * The handler treats anything arriving within three seconds of the page being
 * rendered as a machine, and answers it exactly as it answers a real
 * submission. A test that filled the form at browser speed would therefore be
 * testing the bot path while believing it was testing the accepted one — and
 * would pass, because the two responses are identical by design. That is a
 * trap this suite has to walk into on purpose or not at all.
 */
const HUMAN = 3500;

/**
 * Fill the form, wait long enough to be a person, and send it.
 *
 * @param {import('@playwright/test').Page} page   The page.
 * @param {object}                          answer What to type.
 */
async function submit( page, answer ) {
	await page.fill( '#gwcvt-self-name', answer.name );

	if ( answer.email ) {
		await page.fill( '#gwcvt-self-email', answer.email );
	}

	await page.fill( '#gwcvt-self-date', answer.date );
	await page.fill( '#gwcvt-self-hours', answer.hours );

	if ( answer.activity ) {
		await page.fill( '#gwcvt-self-activity', answer.activity );
	}

	if ( answer.honeypot ) {
		/* The honeypot is a visible text input in an off-screen wrapper — not
		 * type="hidden" and not an inline display:none, both of which the
		 * scripts worth stopping already know to skip. Filling it is what a
		 * form-filling bot does. */
		await page.fill( '#gwcvt-website', answer.honeypot );
	}

	await page.waitForTimeout( HUMAN );

	await page.getByRole( 'button', { name: 'Send my hours' } ).click();
	await page.waitForLoadState( 'domcontentloaded' );
}

/** What the page says, with everything variable taken out of it. */
async function answerOf( page ) {
	return page
		.locator( '.gwcvt-form, main, body' )
		.first()
		.innerText();
}

test.describe( 'the public hours form', () => {
	test( 'accepts a submission as a pending claim, on nobody', async ( {
		page,
		api,
		fixtures,
	} ) => {
		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await page.goto( fixtures.pages.selfLog.url );

		await submit( page, {
			name: 'Rosalind Achterberg',
			email: 'rosalind@example.test',
			date: '2026-08-08',
			hours: '2:30',
			activity: 'Sorting the produce delivery',
		} );

		await expect( page.locator( 'body' ) ).toContainText(
			'your hours have been sent to staff'
		);

		const entries = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: {
				volunteer: '_gwc_vt_volunteer',
				minutes: '_gwc_vt_minutes',
				source: '_gwc_vt_source',
				claimName: '_gwc_vt_claim_name',
				claimEmail: '_gwc_vt_claim_email',
			},
		} );

		expect( entries.length ).toBe( before + 1 );

		const made = entries.find(
			( entry ) => 'Rosalind Achterberg' === entry.claimName
		);

		expect( made ).toBeTruthy();

		/* Pending, not published: nobody has accepted this record, and
		 * publishing it would put an unreviewed claim into the organization's
		 * own totals. */
		expect( made.status ).toBe( 'pending' );

		/* On nobody. Hard rule 4 in the database: the name and the address are
		 * CLAIMS, and a human attaches them to a volunteer later. */
		expect( Number( made.volunteer ) ).toBe( 0 );
		expect( made.claimEmail ).toBe( 'rosalind@example.test' );
		expect( made.source ).toBe( 'self' );
		expect( Number( made.minutes ) ).toBe( 150 );
	} );

	test( 'answers an address on file exactly as it answers one that is not', async ( {
		page,
		api,
		fixtures,
	} ) => {
		/* The oracle test. Marcus is on file and working off a court order;
		 * the other address has never been seen here. If the form treated them
		 * differently in any way a stranger can observe — the message, the
		 * markup, the status code, the fields that come back — then anybody
		 * could ask this page whether a named person volunteers here.
		 *
		 * Compared as rendered text rather than as a message key, because the
		 * message table is what tests/SelfLogTest.php already checks. What
		 * could still diverge is everything around it. */
		const known = fixtures.volunteers[ 'Marcus Delacroix' ].email;

		await page.goto( fixtures.pages.selfLog.url );
		await submit( page, {
			name: 'Marcus Delacroix',
			email: known,
			date: '2026-08-09',
			hours: '1',
		} );

		const forKnown = await answerOf( page );
		const statusKnown = page.url();

		await page.goto( fixtures.pages.selfLog.url );
		await submit( page, {
			name: 'Nobody In Particular',
			email: 'nobody-at-all@example.test',
			date: '2026-08-09',
			hours: '1',
		} );

		const forStranger = await answerOf( page );

		expect( forStranger ).toBe( forKnown );
		expect( page.url() ).toBe( statusKnown );

		/* And both were stored the same way: as claims, on nobody. The one
		 * whose address matches a volunteer is NOT attached to them here. */
		const claims = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: {
				volunteer: '_gwc_vt_volunteer',
				claimEmail: '_gwc_vt_claim_email',
			},
		} ).filter( ( entry ) =>
			[ known, 'nobody-at-all@example.test' ].includes( entry.claimEmail )
		);

		expect( claims.length ).toBe( 2 );

		for ( const claim of claims ) {
			expect( Number( claim.volunteer ) ).toBe( 0 );
		}
	} );

	test( 'answers a honeypotted submission exactly as it answers a real one', async ( {
		page,
		api,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.selfLog.url );
		await submit( page, {
			name: 'Genuine Person',
			date: '2026-08-10',
			hours: '2',
		} );

		const real = await answerOf( page );

		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await page.goto( fixtures.pages.selfLog.url );
		await submit( page, {
			name: 'A Machine',
			date: '2026-08-10',
			hours: '2',
			honeypot: 'https://example.invalid/',
		} );

		expect( await answerOf( page ) ).toBe( real );

		/* Identical answer, and nothing stored. That combination is the whole
		 * design: a bot cannot tell it was caught, and a coordinator's queue
		 * does not fill up with what it caught. */
		expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe( before );
	} );

	test( 'answers a submission that arrives too fast the same way, and stores nothing', async ( {
		page,
		api,
		fixtures,
	} ) => {
		await page.goto( fixtures.pages.selfLog.url );
		await submit( page, {
			name: 'Another Genuine Person',
			date: '2026-08-11',
			hours: '2',
		} );

		const real = await answerOf( page );

		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		/* No wait at all. A submission arriving three seconds after the page
		 * loaded was not typed by a person. */
		await page.goto( fixtures.pages.selfLog.url );
		await page.fill( '#gwcvt-self-name', 'Faster Than Human' );
		await page.fill( '#gwcvt-self-date', '2026-08-11' );
		await page.fill( '#gwcvt-self-hours', '2' );
		await page.getByRole( 'button', { name: 'Send my hours' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		expect( await answerOf( page ) ).toBe( real );
		expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe( before );
	} );

	test( 'says what is missing, and stores nothing, when the form is short', async ( {
		page,
		api,
		fixtures,
	} ) => {
		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await page.goto( fixtures.pages.selfLog.url );

		/* Name and hours are required in the markup, so a person meets the
		 * browser's refusal first. Taken off, the handler refuses too — and
		 * says which three things it needs, rather than "invalid". */
		for ( const id of [ '#gwcvt-self-name', '#gwcvt-self-hours' ] ) {
			await page
				.locator( id )
				.evaluate( ( field ) => field.removeAttribute( 'required' ) );
		}

		await page.fill( '#gwcvt-self-date', '2026-08-12' );
		await page.waitForTimeout( HUMAN );

		await page.getByRole( 'button', { name: 'Send my hours' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		await expect( page.locator( 'body' ) ).toContainText(
			'Please give your name, the date, and how long you worked'
		);

		expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe( before );
	} );

	test( 'is not there at all when the setting is off', async ( {
		page,
		api,
		fixtures,
	} ) => {
		api( 'settings.set', { values: { self_log_enabled: false } } );

		try {
			await page.goto( fixtures.pages.selfLog.url );

			await expect( page.locator( '#gwcvt-self-name' ) ).toHaveCount( 0 );

			/* And the handler is off too, not merely the form. A handler that
			 * ran while only the form was hidden would accept posts to a
			 * feature the site had switched off — which is the first line of
			 * gwc_vt_dispatch() and the reason it is the first line. */
			const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

			const response = await page.request.post( fixtures.pages.selfLog.url, {
				form: {
					gwc_vt_log_hours: '1',
					gwc_vt_name: 'Should Not Land',
					gwc_vt_date: '2026-08-13',
					gwc_vt_hours: '2',
				},
			} );

			expect( response.status() ).toBeLessThan( 500 );
			expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe(
				before
			);
		} finally {
			api( 'settings.set', { values: { self_log_enabled: true } } );
		}
	} );
} );

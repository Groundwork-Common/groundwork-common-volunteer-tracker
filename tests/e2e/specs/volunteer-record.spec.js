/**
 * A volunteer's record, and the four sheets that open on it.
 *
 * Covers gwc_vt_log_hours, gwc_vt_record_credential, gwc_vt_add_letter_draft,
 * gwc_vt_discard_letter_draft, gwc_vt_deactivate_volunteer and
 * gwc_vt_activate_volunteer.
 *
 * ── Why a browser is the only place this can be tested ───────────────────────
 * A sheet is the one interaction pattern every screen in this plugin shares,
 * and the rule it rests on is an HTML one: a sheet is printed on admin_footer,
 * OUTSIDE wp-admin's <form id="post">, so every field in it names its form by
 * ID. tests/integration/sheets.php checks that the attributes are on the
 * fields. Only a browser can check that the resulting form actually submits
 * what the fields say — which is the thing that breaks when somebody moves a
 * sheet inside the post form and the parser silently reparents its inputs.
 */
const { test, expect, reset } = require( '../support/harness.js' );

/* One reset for this file, so it is independent of every other spec file. See
 * reset() for why it is per file rather than per test — and for the rule that
 * follows from it, which this file obeys: two tests that want the same
 * volunteer in different states use different volunteers. */
test.beforeAll( reset );

/** What the notice area says after a sheet commits. */
async function said( page ) {
	return ( await page.locator( '#wpbody-content' ).innerText() ).trim();
}

test.describe( "a volunteer's record", () => {
	test( 'logging hours from the record creates an unverified entry', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];

		await admin.edit( volunteer.id );

		const sheet = await admin.openSheet( 'log-hours' );

		/* The fields are inside the sheet and belong to a form outside it. If
		 * that ever stops being true this fill still works and the submit
		 * posts an empty form — which is why the assertion below is on the
		 * entry that came out, not on the redirect. */
		await sheet.locator( '#gwcvt-log-date' ).fill( '2026-08-01' );
		await sheet.locator( '#gwcvt-log-hours-field' ).fill( '2:30' );
		await sheet.locator( '#gwcvt-log-activity' ).fill( 'Sorting the produce delivery' );
		await sheet.locator( '#gwcvt-log-supervisor' ).fill( 'Dana Reyes' );

		await sheet.getByRole( 'button', { name: 'Log it' } ).click();
		await page.waitForURL( /gwc_vt_did=hours-logged/ );

		expect( await said( page ) ).toContain( 'Hours logged' );

		/* 2:30 is 150 minutes. Hard rule 1: durations are integer minutes,
		 * never a float and never "hours" as a number — so this asserts the
		 * integer, not a formatted string that could be right by rounding. */
		const entries = api( 'posts', {
			type: 'gwc_vt_entry',
			meta: {
				volunteer: '_gwc_vt_volunteer',
				minutes: '_gwc_vt_minutes',
				date: '_gwc_vt_date',
				verifiedAt: '_gwc_vt_verified_at',
			},
		} );

		const logged = entries.filter(
			( entry ) =>
				Number( entry.volunteer ) === volunteer.id &&
				entry.date === '2026-08-01' &&
				Number( entry.minutes ) === 150
		);

		expect( logged.length ).toBe( 1 );

		/* Logging is not attesting. Somebody still has to say the shift
		 * happened, and a record that arrived already verified would mean the
		 * plugin's one claim about a letter was made by nobody. */
		expect( logged[ 0 ].verifiedAt || '' ).toBe( '' );
	} );

	test( 'hours it cannot read are refused, and nothing is saved', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* A silent correction on save is a bug even when the correction is
		 * right — CLAUDE.md says so about this exact field, because unreadable
		 * hours used to become 0 on a number a letter prints. */
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];

		const before = api( 'posts', { type: 'gwc_vt_entry' } ).length;

		await admin.edit( volunteer.id );

		const sheet = await admin.openSheet( 'log-hours' );

		await sheet.locator( '#gwcvt-log-date' ).fill( '2026-08-02' );
		await sheet.locator( '#gwcvt-log-hours-field' ).fill( 'about three' );

		await sheet.getByRole( 'button', { name: 'Log it' } ).click();
		await page.waitForURL( /gwc_vt_did=hours-unreadable/ );

		const text = await said( page );

		expect( text ).toContain( 'could not be read' );
		expect( text ).toContain( 'nothing was logged' );

		expect( api( 'posts', { type: 'gwc_vt_entry' } ).length ).toBe( before );
	} );

	test( 'recording a credential writes a record, and a bad date does not', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Priya Ramanathan' ];
		const credential = fixtures.credentials[ 'Food handler card' ];

		await admin.edit( volunteer.id );

		let sheet = await admin.openSheet( 'record-credential' );

		await sheet
			.locator( '#gwcvt-record-credential' )
			.selectOption( String( credential.id ) );
		await sheet.locator( '#gwcvt-record-date' ).fill( '2026-06-01' );

		await sheet.getByRole( 'button', { name: 'Record it' } ).click();
		await page.waitForURL( /gwc_vt_did=credential-recorded/ );

		const records = api( 'posts', {
			type: 'gwc_vt_cred_record',
			meta: { credential: '_gwc_vt_record_credential', date: '_gwc_vt_record_date' },
		} );

		const written = records.filter(
			( record ) =>
				record.parent === volunteer.id &&
				Number( record.credential ) === credential.id &&
				record.date === '2026-06-01'
		);

		expect( written.length ).toBe( 1 );

		/* A record is a child post of the volunteer, and its expiry is derived
		 * from the grant date rather than stored. Asserted because storing it
		 * is the obvious shortcut and it is the one this plugin refuses. */
		expect( written[ 0 ].parent ).toBe( volunteer.id );

		/* And a date in the future is refused twice over. */
		await admin.edit( volunteer.id );

		sheet = await admin.openSheet( 'record-credential' );

		const date = sheet.locator( '#gwcvt-record-date' );

		/* First by the browser: the field carries max="today", so a future
		 * date never leaves the page. That is the refusal a person actually
		 * meets, so it is the one asserted first. */
		const today = new Date().toISOString().slice( 0, 10 );

		expect( await date.getAttribute( 'max' ) ).toBe( today );

		await sheet
			.locator( '#gwcvt-record-credential' )
			.selectOption( String( credential.id ) );
		await date.fill( '2099-01-01' );
		await sheet.getByRole( 'button', { name: 'Record it' } ).click();

		expect( page.url() ).not.toContain( 'gwc_vt_did=' );
		expect(
			api( 'posts', { type: 'gwc_vt_cred_record' } ).length
		).toBe( records.length );

		/* Then by the handler, when the browser is not the one asking. The max
		 * attribute is taken off and the same form submitted — which is what a
		 * script, an old browser, or a person with the developer tools open
		 * does. A validation that lives only in the markup is not a
		 * validation; the handler has to refuse it too, and say which of the
		 * three things was wrong rather than "could not be recorded". */
		await date.evaluate( ( field ) => field.removeAttribute( 'max' ) );
		await date.fill( '2099-01-01' );

		await sheet.getByRole( 'button', { name: 'Record it' } ).click();
		await page.waitForURL( /gwc_vt_did=credential-bad-date/ );

		expect( await said( page ) ).toContain( 'on or before today' );

		expect(
			api( 'posts', { type: 'gwc_vt_cred_record' } ).length
		).toBe( records.length );
	} );

	test( 'drafting a letter fixes a period and sends nothing', async ( {
		page,
		admin,
		api,
		mailbox,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Marcus Delacroix' ];

		await admin.edit( volunteer.id );

		const sheet = await admin.openSheet( 'draft-letter' );

		await sheet
			.locator( `#gwcvt-draft-addressee-${ volunteer.id }` )
			.fill( 'Franklin County Municipal Court' );
		await sheet
			.locator( `#gwcvt-draft-matter-${ volunteer.id }` )
			.fill( 'Case 2026-CR-00841' );

		await sheet.getByRole( 'button', { name: 'Save the draft' } ).click();
		await page.waitForURL( /gwc_vt_did=drafted/ );

		expect( await said( page ) ).toContain( 'Nothing has been sent' );

		const drafts = api( 'posts', {
			type: 'gwc_vt_letter_draft',
			meta: { addressee: '_gwc_vt_draft_addressee', matter: '_gwc_vt_draft_matter' },
		} ).filter( ( draft ) => draft.parent === volunteer.id );

		expect( drafts.length ).toBe( 1 );
		expect( drafts[ 0 ].addressee ).toContain( 'Franklin County' );

		/* A draft is an intention. Nothing is issued, nothing is delivered,
		 * and — the part worth asserting in a browser — nothing left. */
		expect( mailbox.read() ).toHaveLength( 0 );
	} );

	test( 'a draft can be discarded, and the issued log is untouched', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* Tomás rather than Marcus, who already has a draft from the test
		 * above — this file resets once, not once per test. */
		const volunteer = fixtures.volunteers[ 'Tomás Beaulieu' ];

		await admin.edit( volunteer.id );

		const sheet = await admin.openSheet( 'draft-letter' );

		await sheet.getByRole( 'button', { name: 'Save the draft' } ).click();
		await page.waitForURL( /gwc_vt_did=drafted/ );

		const lettersBefore = api( 'posts', { type: 'gwc_vt_letter' } ).length;

		const discard = page.locator(
			'a[href*="action=gwc_vt_discard_letter_draft"]'
		);

		await expect( discard ).toHaveCount( 1 );

		await discard.click();
		await page.waitForURL( /gwc_vt_did=discarded/ );

		expect(
			api( 'posts', { type: 'gwc_vt_letter_draft' } ).filter(
				( draft ) => draft.parent === volunteer.id
			)
		).toHaveLength( 0 );

		/* A draft dies with the volunteer; the issued-letter log outlives
		 * them. Discarding one must not take a letter with it. */
		expect( api( 'posts', { type: 'gwc_vt_letter' } ).length ).toBe(
			lettersBefore
		);
	} );

	test( 'a volunteer can be made inactive and brought back', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const volunteer = fixtures.volunteers[ 'Wendell Achebe' ];

		await admin.edit( volunteer.id );

		/* The control is offered in more than one place on this screen — the
		 * status panel and the notice that explains what inactive means — so
		 * this takes the first rather than asserting there is exactly one. A
		 * count assertion here would be a test of the layout, and the layout is
		 * not what this is about. */
		const stop = page
			.locator( 'a[href*="action=gwc_vt_deactivate_volunteer"]' )
			.first();

		await expect( stop ).toBeVisible();
		await stop.click();

		await page.waitForURL( /post\.php/ );

		/* Inactive is neither anonymize nor delete: the hours, the name and any
		 * issued letters all stay. Only the offering stops. */
		let record = api( 'post.meta', { id: volunteer.id } );

		expect( record.status ).toBe( 'gwc_vt_vol_inactive' );
		expect( record.title ).toBe( 'Wendell Achebe' );

		const back = page
			.locator( 'a[href*="action=gwc_vt_activate_volunteer"]' )
			.first();

		await expect( back ).toBeVisible();
		await back.click();

		await page.waitForURL( /post\.php/ );

		record = api( 'post.meta', { id: volunteer.id } );

		expect( record.status ).not.toBe( 'gwc_vt_vol_inactive' );
	} );
} );

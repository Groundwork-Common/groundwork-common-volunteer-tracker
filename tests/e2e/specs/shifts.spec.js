/**
 * A shift: writing one, staffing it, calling it off, and erasing it.
 *
 * Covers gwc_vt_save_shift, gwc_vt_roster_add, gwc_vt_group_seats,
 * gwc_vt_roster_remove,
 * gwc_vt_roster_print, gwc_vt_cancel_shift and gwc_vt_delete_shift.
 *
 * ── The distinction the screen has to keep ───────────────────────────────────
 * Cancelling and deleting are not two words for the same button. A shift people
 * committed to is CALLED OFF — it stays on the schedule saying it is not
 * happening, and everybody who signed up is told. A shift nobody signed up to
 * can be erased, because erasing it takes nothing away from anybody. The delete
 * control is offered ONLY when the roster is empty, and that is a property of
 * the rendered screen rather than of any function.
 *
 * ── Why every test here writes its own shift ─────────────────────────────────
 * Because half of them destroy one. The first version of this file shared a
 * single shift across five tests and spent an afternoon on a shift that had
 * vanished by the time the last test looked for it — a failure that reads as a
 * bug in the plugin and is a bug in the test. A day each, and no test can be
 * upset by the one above it.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/**
 * Write a shift through the editor, and return what the database now holds.
 *
 * `shift=new`, not `add=new`: the Add button on the schedule goes to a chooser
 * — a shift, or an event — because those are different things and the screen
 * asks which. `shift=new` is where the chooser sends you.
 *
 * @param {object} ctx  page, admin and api fixtures.
 * @param {object} spec date, and anything else to type.
 * @return {Promise<object>} The shift, with its meta.
 */
async function writeShift( { page, admin, api }, spec ) {
	await page.goto( admin.screenUrl( 'gwc-vt-schedule', { shift: 'new' } ) );

	const form = admin.formFor( 'gwc_vt_save_shift' ).first();

	await page.fill( '#gwcvt-shift-activity', spec.activity );
	await page.fill( '#gwcvt-shift-location', spec.location || 'Main warehouse' );
	await page.fill( '#gwcvt-shift-date', spec.date );
	await page.fill( '#gwcvt-shift-start', spec.start || '09:00' );
	await page.fill( '#gwcvt-shift-end', spec.end || '12:00' );
	await page.fill( '#gwcvt-shift-min', String( spec.min ?? 1 ) );
	await page.fill( '#gwcvt-shift-max', String( spec.max ?? 6 ) );

	if ( spec.supervisor ) {
		await page.fill( '#gwcvt-shift-supervisor', spec.supervisor );
	}

	if ( spec.notes ) {
		await page.fill( '#gwcvt-shift-notes', spec.notes );
	}

	await form
		.locator( 'input[type="submit"], button[type="submit"]' )
		.first()
		.click();

	await page.waitForURL( /page=gwc-vt-schedule/ );

	const made = api( 'posts', {
		type: 'gwc_vt_shift',
		meta: {
			date: '_gwc_vt_shift_date',
			start: '_gwc_vt_shift_start',
			end: '_gwc_vt_shift_end',
			activity: '_gwc_vt_shift_activity',
			location: '_gwc_vt_shift_location',
			min: '_gwc_vt_shift_min',
			max: '_gwc_vt_shift_max',
			supervisor: '_gwc_vt_shift_supervisor',
			notes: '_gwc_vt_shift_notes',
		},
	} ).filter( ( shift ) => shift.date === spec.date );

	if ( 1 !== made.length ) {
		throw new Error(
			`Expected one shift on ${ spec.date }, found ${ made.length }.`
		);
	}

	return made[ 0 ];
}

/**
 * Put somebody on a shift through the roster's picker.
 *
 * The picker is a text box, a hidden id beside it and a <ul> of results — the
 * same component the log-a-day screen uses, and the same reason it has to be
 * driven in a browser: nothing about it is visible to a test that does not run
 * scripts, and when it breaks the screen looks fine and rosters nobody.
 *
 * @param {object} ctx     page and admin fixtures.
 * @param {number} shiftId Which shift.
 * @param {string} name    What to type into the picker.
 */
async function roster( { page, admin }, shiftId, name ) {
	await page.goto( admin.screenUrl( 'gwc-vt-schedule', { shift: shiftId } ) );

	await page.fill( '#gwcvt-roster-name', name );

	const results = page.locator( '#gwcvt-roster-results li' );

	await results.first().waitFor( { state: 'visible' } );
	await results.first().click();

	await admin
		.formFor( 'gwc_vt_roster_add' )
		.first()
		.locator( 'input[type="submit"], button[type="submit"]' )
		.first()
		.click();

	await page.waitForURL( /page=gwc-vt-schedule/ );
}

test.describe( 'a shift', () => {
	test( 'can be written, and comes back saying what was typed', async ( {
		page,
		admin,
		api,
	} ) => {
		const shift = await writeShift(
			{ page, admin, api },
			{
				date: '2026-11-14',
				activity: 'Sorting the produce delivery',
				location: 'Main warehouse',
				start: '09:00',
				end: '12:00',
				min: 2,
				max: 6,
				supervisor: 'Dana Reyes',
				notes: 'Van arrives at eight.',
			}
		);

		expect( shift.activity ).toBe( 'Sorting the produce delivery' );
		expect( shift.location ).toBe( 'Main warehouse' );
		expect( shift.start ).toBe( '09:00' );
		expect( shift.end ).toBe( '12:00' );
		expect( Number( shift.min ) ).toBe( 2 );
		expect( Number( shift.max ) ).toBe( 6 );
		expect( shift.supervisor ).toBe( 'Dana Reyes' );
		expect( shift.notes ).toContain( 'Van arrives' );

		/* And it is on the schedule, which is where the person who wrote it
		 * goes to look for it. */
		await admin.visit( 'gwc-vt-schedule' );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Sorting the produce delivery'
		);
	} );

	test( 'somebody can be put on it, and taken off again', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const shift = await writeShift(
			{ page, admin, api },
			{ date: '2026-11-15', activity: 'Packing weekend boxes' }
		);

		await roster( { page, admin }, shift.id, 'Priya' );

		const priya = fixtures.volunteers[ 'Priya Ramanathan' ];

		const on = () =>
			api( 'posts', {
				type: 'gwc_vt_signup',
				meta: { volunteer: '_gwc_vt_signup_volunteer' },
			} ).filter(
				( one ) =>
					one.parent === shift.id &&
					Number( one.volunteer ) === priya.id &&
					'gwc_vt_withdrawn' !== one.status
			);

		expect( on() ).toHaveLength( 1 );

		/* And the roster says so by name. This screen is staff-only, and is
		 * the one place a roster may be read: on a site running a court-ordered
		 * service programme, "who is coming Saturday" is a list of people
		 * working one off. A place count is not, which is why the public list
		 * shows one and never the other. */
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Priya Ramanathan'
		);

		await admin.rowAction(
			page.locator( 'a[href*="action=gwc_vt_roster_remove"]' ).first()
		);

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( on() ).toHaveLength( 0 );
	} );

	test( 'a roster can be printed for the day', async ( {
		page,
		admin,
		api,
	} ) => {
		const shift = await writeShift(
			{ page, admin, api },
			{ date: '2026-11-16', activity: 'Warehouse inventory' }
		);

		await roster( { page, admin }, shift.id, 'Marcus' );

		/* Offered only once there is a roster to print, so it appears here and
		 * not on the empty shift above. */
		const link = page.locator( 'a[href*="action=gwc_vt_roster_print"]' );

		expect( await link.count() ).toBeGreaterThan( 0 );

		/* target="_blank": the sheet opens beside the shift rather than
		 * navigating away from it. Followed directly here, because what is
		 * under test is the document rather than which tab it lands in. */
		expect( await link.first().getAttribute( 'target' ) ).toBe( '_blank' );

		await page.goto( await link.first().getAttribute( 'href' ) );

		const sheet = await page.locator( 'body' ).innerText();

		expect( sheet ).toContain( 'Warehouse inventory' );
		expect( sheet ).toContain( 'Marcus Delacroix' );
	} );

	test( 'calling one off keeps it on the schedule, with its reason', async ( {
		page,
		admin,
		api,
		mailbox,
		dialogs,
	} ) => {
		const shift = await writeShift(
			{ page, admin, api },
			{ date: '2026-11-17', activity: 'Front desk intake' }
		);

		await roster( { page, admin }, shift.id, 'Marcus' );

		mailbox.clear();

		const form = admin.formFor( 'gwc_vt_cancel_shift' ).first();

		await expect( form ).toBeVisible();

		await page.fill( '#gwcvt-cancel-reason', 'The delivery was postponed.' );

		/* The notify box exists only when somebody is signed up, which is why
		 * this test rosters first. Its label counts them, because "email the 1
		 * person signed up" is a different decision from "email the 12". */
		await expect( page.locator( '#gwcvt-cancel-notify' ) ).toBeVisible();
		await page.check( '#gwcvt-cancel-notify' );

		await form
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		/* It asked first. An onclick confirm() is a thin guard and this is not
		 * a test of confirm() — but a destructive control that stopped asking
		 * would be a change somebody made on purpose, so it is asserted. */
		expect( dialogs.join( ' ' ) ).toContain( 'Cancel this shift?' );

		const after = api( 'post.meta', { id: shift.id } );

		/* Called off, not erased: a status, and the reason kept beside it. */
		expect( after.status ).toBe( 'gwc_vt_cancelled' );
		expect( after.meta._gwc_vt_shift_cancelled_reason ).toContain(
			'postponed'
		);

		/* And the person who had committed to it was told, at the address on
		 * their record. */
		expect( mailbox.to( 'marcus@example.test' ).length ).toBeGreaterThan( 0 );

		/* Still on the schedule, saying so. A called-off shift that vanished
		 * would leave six people driving across town. */
		await admin.visit( 'gwc-vt-schedule' );

		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Front desk intake'
		);
	} );

	test( 'a shift nobody signed up to can be erased, and one with a roster cannot', async ( {
		page,
		admin,
		api,
		dialogs,
	} ) => {
		const empty = await writeShift(
			{ page, admin, api },
			{ date: '2026-11-18', activity: 'Driving the collection van' }
		);

		await page.goto(
			admin.screenUrl( 'gwc-vt-schedule', { shift: empty.id } )
		);

		/* A link rather than a form, unlike cancelling — because deleting takes
		 * nothing away from anybody, so it needs no reason typed and no
		 * decision about who to email. */
		const erase = page.locator( 'a[href*="action=gwc_vt_delete_shift"]' );

		await expect( erase ).toHaveCount( 1 );
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'canceled, not erased'
		);

		await erase.click();
		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( dialogs.join( ' ' ) ).toContain( 'Nobody is signed up' );
		expect( api( 'post.meta', { id: empty.id } ).exists ).toBe( false );

		/* And the other half of the rule: once somebody is on it, the control
		 * is not there to press. */
		const staffed = await writeShift(
			{ page, admin, api },
			{ date: '2026-11-19', activity: 'Holiday distribution' }
		);

		await roster( { page, admin }, staffed.id, 'Priya' );

		await expect(
			page.locator( 'a[href*="action=gwc_vt_delete_shift"]' )
		).toHaveCount( 0 );
	} );

	test( 'a group holds places before any of the names exist', async ( {
		page,
		admin,
		api,
	} ) => {
		const shift = api( 'posts', { type: 'gwc_vt_shift' } )[ 0 ];

		expect( shift ).toBeTruthy();

		await page.goto( admin.screenUrl( 'gwc-vt-schedule', { shift: shift.id } ) );

		/* ── No volunteer record, and that is the whole point ────────────────
		 * "Acme Corp is bringing twelve on Saturday" is knowable three weeks
		 * before any of the twelve names are. The form above this one needs a
		 * volunteer to add anybody; this one deliberately needs none. */
		await page.fill( '#gwcvt-group-name', 'Beaulieu Freight' );
		await page.fill( '#gwcvt-group-seats', '12' );
		await page.fill( '#gwcvt-group-email', 'rota@beaulieu.example' );

		await page
			.locator( 'form.gwcvt-group-hold-add input[type="submit"], form.gwcvt-group-hold-add button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_shift_result=held/ );

		/* One line on the roster, not twelve blanks — the blanks belong on the
		 * printed sheet, where somebody writes names on them. */
		const row = page
			.locator( '.gwcvt-roster tbody tr' )
			.filter( { hasText: 'Beaulieu Freight' } );

		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( '12 seats' );

		/* And the places are really held: the shift is twelve fuller. */
		const held = api( 'posts', { type: 'gwc_vt_signup' } ).find(
			( one ) => one.title && one.title.includes( 'Beaulieu Freight' )
		);

		expect( held ).toBeTruthy();
	} );

	test( 'a hold is resized in place, not taken off and made again', async ( {
		page,
		admin,
		api,
	} ) => {
		const shift = api( 'posts', { type: 'gwc_vt_shift' } )[ 1 ];

		expect( shift ).toBeTruthy();

		await page.goto( admin.screenUrl( 'gwc-vt-schedule', { shift: shift.id } ) );

		await page.fill( '#gwcvt-group-name', 'Okonkwo Scouts' );
		await page.fill( '#gwcvt-group-seats', '8' );
		await page
			.locator( 'form.gwcvt-group-hold-add input[type="submit"], form.gwcvt-group-hold-add button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_shift_result=held/ );

		const row = page
			.locator( '.gwcvt-roster tbody tr' )
			.filter( { hasText: 'Okonkwo Scouts' } );

		await expect( row ).toContainText( '8 seats' );

		/* Changed where it is, through gwc_vt_group_seats — deleting and
		 * re-making would move the booking's date, retire the cancellation link
		 * already in the contact's inbox, and re-owe a reminder that has gone. */
		await row.locator( 'input[name="gwc_vt_seats"]' ).fill( '5' );
		await row.locator( 'input[type="submit"]' ).first().click();

		await page.waitForURL( /gwc_vt_shift_result=seats-changed/ );

		await expect(
			page.locator( '.gwcvt-roster tbody tr' ).filter( { hasText: 'Okonkwo Scouts' } )
		).toContainText( '5 seats' );
	} );

} );

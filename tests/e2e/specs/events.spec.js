/**
 * An event, its times, and the people on them.
 *
 * Covers gwc_vt_save_event, gwc_vt_copy_event, gwc_vt_cancel_event,
 * gwc_vt_delete_event, gwc_vt_call_off_slot, gwc_vt_restore_slot,
 * gwc_vt_delete_slot, gwc_vt_drop_role, gwc_vt_event_roster_add,
 * gwc_vt_signup_promote and gwc_vt_event_roster_print.
 *
 * ── What an event actually is ────────────────────────────────────────────────
 * A container over shifts by post_parent. Every slot on the grid is an ordinary
 * gwc_vt_shift, which is why waiting lists, reminders, rosters and
 * reconciliation all work on one unchanged. That is the design decision this
 * file is really testing: if a slot ever stopped being a shift, everything else
 * in the plugin would have to learn about events, and none of it has.
 *
 * ── And the grid it is edited on ─────────────────────────────────────────────
 * Field names carry explicit indexes — gwc_vt_roles[0][slots][2][date] — and
 * assets/js/admin-event-grid.js renumbers them when a row is cloned. That
 * renumbering has already been wrong once in a way that looked right: the
 * patterns were end-anchored, so the field NAMES renumbered and the ids and
 * labels did not. A cloned row saved perfectly while every label in it pointed
 * at the first row's input. tests/js/renumber.test.mjs guards the patterns;
 * this file drives the button.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/** The blank event editor. */
function newEventUrl( admin ) {
	return admin.screenUrl( 'gwc-vt-schedule', { gwc_vt_event: 'new' } );
}

/** One event's editor. */
function eventUrl( admin, id, extra = {} ) {
	return admin.screenUrl( 'gwc-vt-schedule', { gwc_vt_event: id, ...extra } );
}

/**
 * Fill one slot row of the grid.
 *
 * @param {import('@playwright/test').Page} page  The page.
 * @param {number}                          role  Role index.
 * @param {number}                          slot  Slot index within it.
 * @param {object}                          spec  date, start, end, max.
 */
async function fillSlot( page, role, slot, spec ) {
	const id = `#gwcvt-slot-${ role }-${ slot }`;

	await page.fill( `${ id }-date`, spec.date );
	await page.fill( `${ id }-start`, spec.start );
	await page.fill( `${ id }-end`, spec.end );
	await page.fill( `${ id }-max`, String( spec.max ?? 4 ) );
}

/**
 * Create an event with one role and one time, through the editor.
 *
 * Each test writes its own, for the reason shifts.spec.js writes its own
 * shifts: half of them destroy what they act on, and a shared one turns "this
 * event has gone" into a failure that reads as a bug in the plugin.
 *
 * @param {object} ctx  page, admin and api fixtures.
 * @param {object} spec title, role, date.
 * @return {Promise<object>} The event.
 */
async function writeEvent( { page, admin, api }, spec ) {
	await page.goto( newEventUrl( admin ) );

	await page.fill( '#gwcvt-event-title', spec.title );
	await page.fill( '#gwcvt-event-location', 'Riverbend Community Center' );
	await page.fill( '#gwcvt-event-supervisor', 'Dana Reyes' );

	await page.fill( '#gwcvt-role-0', spec.role );
	await fillSlot( page, 0, 0, {
		date: spec.date,
		start: spec.start || '09:00',
		end: spec.end || '12:00',
		max: spec.max ?? 4,
	} );

	await admin
		.formFor( 'gwc_vt_save_event' )
		.first()
		.locator( 'input[type="submit"], button[type="submit"]' )
		.first()
		.click();

	await page.waitForURL( /page=gwc-vt-schedule/ );

	const made = api( 'posts', {
		type: 'gwc_vt_event',
		meta: { location: '_gwc_vt_event_location', date: '_gwc_vt_event_date' },
	} ).find( ( one ) => one.title === spec.title );

	if ( ! made ) {
		throw new Error( `The event "${ spec.title }" was not created.` );
	}

	return made;
}

/**
 * Put somebody on one slot from the event's roster screen.
 *
 * The roster's picker is the shift drawer's, plus one thing the shift drawer
 * does not need: a slot to choose. An event has several times, and "add them to
 * the event" is not a question with one answer.
 *
 * @param {object} ctx     page and admin fixtures.
 * @param {number} eventId Which event.
 * @param {number} slotId  Which time.
 * @param {string} name    What to type into the picker.
 */
async function rosterOnto( { page, admin }, eventId, slotId, name ) {
	await page.goto( eventUrl( admin, eventId, { view: 'roster' } ) );

	await page.fill( '#gwcvt-event-roster-name', name );

	const results = page.locator( '#gwcvt-event-roster-results li' ).first();

	await results.waitFor( { state: 'visible' } );
	await results.click();

	await page.selectOption( '#gwcvt-event-roster-slot', String( slotId ) );

	await admin
		.formFor( 'gwc_vt_event_roster_add' )
		.first()
		.locator( 'input[type="submit"], button[type="submit"]' )
		.first()
		.click();

	await page.waitForURL( /gwc_vt_event=/ );
}

test.describe( 'an event', () => {
	test( 'is created as a container of ordinary shifts', async ( {
		page,
		admin,
		api,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Winter coat drive', role: 'Sorting coats', date: '2026-12-05' }
		);

		expect( event.location ).toBe( 'Riverbend Community Center' );

		/* The slot is a gwc_vt_shift, parented to the event. Not a row in some
		 * event-specific table — that is the whole design. */
		const slots = api( 'posts', {
			type: 'gwc_vt_shift',
			meta: {
				date: '_gwc_vt_shift_date',
				start: '_gwc_vt_shift_start',
				activity: '_gwc_vt_shift_activity',
				max: '_gwc_vt_shift_max',
			},
		} ).filter( ( shift ) => shift.parent === event.id );

		expect( slots.length ).toBe( 1 );
		expect( slots[ 0 ].date ).toBe( '2026-12-05' );
		expect( slots[ 0 ].start ).toBe( '09:00' );
		expect( slots[ 0 ].activity ).toBe( 'Sorting coats' );
		expect( Number( slots[ 0 ].max ) ).toBe( 4 );

		/* An event has no URL of its own — gwc_vt_event is public => false, so
		 * it is only ever seen on a page somebody placed the block or the
		 * shortcode on. Asserted because flipping that flag would publish
		 * volunteer-facing records at a guessable address. */
		const front = await page.goto( `/?p=${ event.id }`, {
			waitUntil: 'domcontentloaded',
		} );

		expect( front.status() ).toBeGreaterThanOrEqual( 400 );
	} );

	test( 'the grid can grow a role and a time without a page load', async ( {
		page,
		admin,
		api,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Spring clean-up', role: 'Litter picking', date: '2027-03-06' }
		);

		await page.goto( eventUrl( admin, event.id ) );

		const roleBlocks = page.locator( '.gwcvt-role-block' );
		const before = await roleBlocks.count();

		await page.locator( '[data-gwcvt-add-role]' ).click();

		await expect( roleBlocks ).toHaveCount( before + 1 );

		/* The cloned block's fields carry the NEXT index, in both the name and
		 * the id — the two halves the renumbering got wrong when it was
		 * end-anchored. A label pointing at the first row's input is a label
		 * that focuses the wrong field and a screen reader that announces every
		 * role as role one. */
		const added = roleBlocks.nth( before );
		const name = added.locator( 'input[type="text"]' ).first();

		expect( await name.getAttribute( 'name' ) ).toBe(
			`gwc_vt_roles[${ before }][name]`
		);
		expect( await name.getAttribute( 'id' ) ).toBe( `gwcvt-role-${ before }` );

		const label = added.locator( `label[for="gwcvt-role-${ before }"]` );

		await expect( label ).toHaveCount( 1 );
	} );

	test( 'a time with people on it is called off, and an empty one is removed', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Food drive weekend', role: 'Loading the van', date: '2027-02-13' }
		);

		const slot = api( 'posts', { type: 'gwc_vt_shift' } ).find(
			( shift ) => shift.parent === event.id
		);

		/* Somebody has to be on it before "Call it off" is offered at all. An
		 * empty time gets "Delete" instead, and the difference between the two
		 * controls IS the feature: calling off is for a time people committed
		 * to, and it needs a reason typed and a decision about who to email. */
		await rosterOnto( { page, admin }, event.id, slot.id, 'Priya' );

		await page.goto( eventUrl( admin, event.id ) );

		await page
			.locator( `a[href*="view=call-off"][href*="slot=${ slot.id }"]` )
			.first()
			.click();

		await page.waitForURL( /view=call-off/ );

		const form = admin.formFor( 'gwc_vt_call_off_slot' ).first();

		await expect( form ).toBeVisible();

		await form.locator( 'input[type="text"]' ).first().fill( 'Snow.' );
		await form
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( api( 'post.meta', { id: slot.id } ).status ).toBe(
			'gwc_vt_cancelled'
		);

		/* A cancelled row has to LOOK cancelled. It used to come back looking
		 * like every other one, so a coordinator concluded the cancellation had
		 * not worked when it had — which is worse than a feature that fails,
		 * because the state is real and nothing on the screen agrees. */
		await page.goto( eventUrl( admin, event.id ) );

		await expect( page.locator( '.gwcvt-slot--cancelled' ) ).toHaveCount( 1 );

		await page
			.locator(
				`a[href*="action=gwc_vt_restore_slot"][href*="slot=${ slot.id }"]`
			)
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( api( 'post.meta', { id: slot.id } ).status ).not.toBe(
			'gwc_vt_cancelled'
		);

		expect( fixtures.volunteers[ 'Priya Ramanathan' ] ).toBeTruthy();
	} );

	test( 'an empty time is deleted rather than called off', async ( {
		page,
		admin,
		api,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Van maintenance day', role: 'Cleaning', date: '2027-02-20' }
		);

		const slot = api( 'posts', { type: 'gwc_vt_shift' } ).find(
			( shift ) => shift.parent === event.id
		);

		await page.goto( eventUrl( admin, event.id ) );

		/* Nobody on it, so there is no reason to type and nobody to email. It
		 * is a nonced link that happens at once, exactly as taking somebody off
		 * a roster is. */
		await expect(
			page.locator( `a[href*="view=call-off"][href*="slot=${ slot.id }"]` )
		).toHaveCount( 0 );

		await page
			.locator(
				`a[href*="action=gwc_vt_delete_slot"][href*="slot=${ slot.id }"]`
			)
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( api( 'post.meta', { id: slot.id } ).exists ).toBe( false );
	} );

	test( "somebody can be rostered onto one of an event's times", async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Harvest packing', role: 'Packing', date: '2027-04-17' }
		);

		const slot = api( 'posts', { type: 'gwc_vt_shift' } ).find(
			( shift ) => shift.parent === event.id
		);

		await rosterOnto( { page, admin }, event.id, slot.id, 'Inès' );

		const ines = fixtures.volunteers[ 'Inès Okonkwo' ];

		const onEvent = api( 'posts', {
			type: 'gwc_vt_signup',
			meta: { volunteer: '_gwc_vt_signup_volunteer' },
		} ).filter(
			( signup ) =>
				Number( signup.volunteer ) === ines.id &&
				signup.parent === slot.id &&
				'gwc_vt_withdrawn' !== signup.status
		);

		expect( onEvent.length ).toBe( 1 );

		/* The roster names people, which is allowed here and nowhere public. */
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Inès Okonkwo'
		);
	} );

	test( 'a time that asks for a credential refuses somebody short of it', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* The seeded event asks for a waiver across every time on it, and the
		 * seed leaves people who do not hold one. This is the block, driven
		 * where a human actually meets it.
		 *
		 * It lives in gwc_vt_signup_credential_refusal(), called by the four
		 * handlers where a person puts somebody on a shift — never inside
		 * gwc_vt_add_signup(), which the reconciler and every fixture also
		 * call, and never on promotion or settling, which act on somebody
		 * already accepted and would fail silently on cron. */
		const event = fixtures.events[ 0 ];

		const before = api( 'posts', { type: 'gwc_vt_signup' } ).length;

		await rosterOnto( { page, admin }, event.id, event.slots[ 0 ], 'Inès' );

		expect( page.url() ).toContain( 'credential-blocked' );

		/* Refused, and nothing written. A screen that reported the block and
		 * had already rostered them would be the worst of both. */
		expect( api( 'posts', { type: 'gwc_vt_signup' } ).length ).toBe( before );

		/* And the screen says what they are short of, rather than "no". */
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			/waiver/i
		);
	} );

	test( 'somebody on the waiting list can be given a place', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		/* One place, two people: the second is waitlisted, which is the state
		 * promotion exists for. Built here rather than taken from the seed,
		 * whose waiting list is on a plain shift — and promotion is offered on
		 * an event's roster, which is the screen with room for it. */
		const event = await writeEvent(
			{ page, admin, api },
			{
				title: 'Soup kitchen evening',
				role: 'Serving',
				date: '2027-05-08',
				max: 1,
			}
		);

		const slot = api( 'posts', { type: 'gwc_vt_shift' } ).find(
			( shift ) => shift.parent === event.id
		);

		await rosterOnto( { page, admin }, event.id, slot.id, 'Marcus' );
		await rosterOnto( { page, admin }, event.id, slot.id, 'Priya' );

		const priya = fixtures.volunteers[ 'Priya Ramanathan' ];

		const hers = () =>
			api( 'posts', {
				type: 'gwc_vt_signup',
				meta: { volunteer: '_gwc_vt_signup_volunteer' },
			} ).find(
				( signup ) =>
					signup.parent === slot.id &&
					Number( signup.volunteer ) === priya.id
			);

		expect( hers().status ).toBe( 'gwc_vt_waitlist' );

		await page.goto( eventUrl( admin, event.id, { view: 'roster' } ) );

		const form = admin.formFor( 'gwc_vt_signup_promote' ).first();

		await expect( form ).toBeVisible();

		await form.getByRole( 'button' ).first().click();
		await page.waitForURL( /gwc_vt_event=/ );

		expect( hers().status ).toBe( 'publish' );
	} );

	test( 'a whole role can be dropped, after being asked', async ( {
		page,
		admin,
		api,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Bag packing day', role: 'Bagging', date: '2027-06-12' }
		);

		const slots = () =>
			api( 'posts', { type: 'gwc_vt_shift' } ).filter(
				( shift ) => shift.parent === event.id
			);

		expect( slots().length ).toBe( 1 );

		await page.goto( eventUrl( admin, event.id ) );

		/* Dropping a role takes every time in it, so it stops to ask on a
		 * screen of its own rather than on a hover action. */
		await page.locator( 'a[href*="view=drop-role"]' ).first().click();
		await page.waitForURL( /view=drop-role/ );

		const form = admin.formFor( 'gwc_vt_drop_role' ).first();

		await expect( form ).toBeVisible();
		await expect( page.locator( '#wpbody-content' ) ).toContainText(
			'Bagging'
		);

		await form
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( slots() ).toHaveLength( 0 );

		/* The event itself survives losing a role. It is a container, and an
		 * empty container is still a plan somebody is in the middle of. */
		expect( api( 'post.meta', { id: event.id } ).exists ).toBe( true );
	} );

	test( 'an event roster can be printed', async ( { page, admin, fixtures } ) => {
		const event = fixtures.events[ 0 ];

		await page.goto( eventUrl( admin, event.id, { view: 'roster' } ) );

		const link = page.locator(
			'a[href*="action=gwc_vt_event_roster_print"]'
		);

		expect( await link.count() ).toBeGreaterThan( 0 );

		await page.goto( await link.first().getAttribute( 'href' ) );

		const sheet = await page.locator( 'body' ).innerText();

		expect( sheet ).toContain( event.title );
	} );

	test( 'a whole event can be called off, and then deleted', async ( {
		page,
		admin,
		api,
	} ) => {
		const event = await writeEvent(
			{ page, admin, api },
			{ title: 'Summer fete', role: 'Running a stall', date: '2027-07-10' }
		);

		await page.goto( eventUrl( admin, event.id ) );

		const callOff = admin.formFor( 'gwc_vt_cancel_event' ).first();

		await expect( callOff ).toBeVisible();

		await page.fill( '#gwcvt-event-cancel-reason', 'The hall flooded.' );

		await callOff
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		const cancelled = api( 'post.meta', { id: event.id } );

		expect( cancelled.status ).toBe( 'gwc_vt_ev_cancelled' );
		expect( cancelled.meta._gwc_vt_event_cancelled_reason ).toContain(
			'flooded'
		);

		/* And then erased entirely, which is a separate decision on a separate
		 * button — the same distinction a single shift keeps. */
		await page.goto( eventUrl( admin, event.id ) );

		const erase = admin.formFor( 'gwc_vt_delete_event' ).first();

		await expect( erase ).toBeVisible();

		await erase
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		expect( api( 'post.meta', { id: event.id } ).exists ).toBe( false );
	} );

	test( 'an event can be copied to another day', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const event = fixtures.events[ 0 ];

		const before = api( 'posts', { type: 'gwc_vt_event' } ).length;

		await page.goto( eventUrl( admin, event.id ) );

		const form = admin.formFor( 'gwc_vt_copy_event' ).first();

		await expect( form ).toBeVisible();

		await page.fill( '#gwcvt-copy-date', '2027-01-16' );

		await form
			.locator( 'input[type="submit"], button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /page=gwc-vt-schedule/ );

		const events = api( 'posts', {
			type: 'gwc_vt_event',
			meta: { date: '_gwc_vt_event_date' },
		} );

		expect( events.length ).toBe( before + 1 );

		const copy = events.find( ( one ) => one.date === '2027-01-16' );

		expect( copy ).toBeTruthy();

		/* The copy has the times, and nobody on them. Carrying the roster
		 * across would put people down for a day they never agreed to. */
		const slots = api( 'posts', { type: 'gwc_vt_shift' } ).filter(
			( shift ) => shift.parent === copy.id
		);

		expect( slots.length ).toBeGreaterThan( 0 );

		const signups = api( 'posts', { type: 'gwc_vt_signup' } ).filter(
			( signup ) => slots.some( ( slot ) => slot.id === signup.parent )
		);

		expect( signups ).toHaveLength( 0 );
	} );
} );

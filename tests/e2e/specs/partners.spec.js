/**
 * Partners: naming them, recording who came with them, and merging.
 *
 * Covers gwc_vt_add_partner and gwc_vt_merge_partners.
 *
 * ── Why the merge needs a browser and not only a database ────────────────────
 * tests/integration/partners.php already proves gwc_vt_merge_partners() moves what it
 * should across every object type, reparents children and never guesses at a
 * conflicting field. None of that is what this file is for.
 *
 * This is the third question: does a person get through it. The merge is
 * irreversible and reached by selecting checkboxes on one screen and a radio on
 * another, and the failure this catches is the one a unit test cannot see — a
 * checkbox whose name does not reach the handler, a survivor radio that posts
 * nothing, a conflict question that renders for a field with no disagreement,
 * or a confirmation step somebody can skip.
 *
 * ── The word ─────────────────────────────────────────────────────────────────
 * "Partner" means two different things in this plugin and only one of them
 * is here. The letterhead partner — the nonprofit itself, gwc_vt_org_name()
 * — is not this. This is a partner a VOLUNTEER CAME WITH.
 */
const { test, expect, reset } = require( '../support/harness.js' );

test.beforeAll( reset );

/** The partners screen. */
const SCREEN = 'gwc-vt-partners';

/** The taxonomy, as api.php wants it. */
const TAX = 'gwc_vt_partner';

/**
 * Type into one of Log a day's pickers and choose the first match.
 *
 * The volunteer field is a combobox over the REST lookup, not a select — the
 * same helper quick-add.spec.js carries, for the same screen.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {number}                          row  Which row, from 0.
 * @param {string}                          name What to type.
 */
async function pick( page, row, name ) {
	await page.fill( `#gwcvt-qa-name-${ row }`, name );

	const results = page.locator( `#gwcvt-qa-results-${ row } li` );

	await results.first().waitFor( { state: 'visible' } );
	await results.first().click();
}

/**
 * Core's own editor for one partner.
 *
 * The taxonomy is show_in_menu => false, so the URL needs the post type spelled
 * out or wp-admin cannot work out which menu to highlight and renders the
 * screen without one.
 *
 * @param {number} id The term ID.
 * @return {string} A path, relative to baseURL.
 */
function termUrl( id ) {
	return `/wp-admin/term.php?taxonomy=${ TAX }&post_type=gwc_vt_volunteer&tag_ID=${ id }`;
}

test.describe( 'partners', () => {
	test( 'one can be added, and appears in the list', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( SCREEN );

		const form = admin.formFor( 'gwc_vt_add_partner' ).first();

		await expect( form ).toBeVisible();

		await page.fill( '#gwcvt-partner-name', 'Cranebrook Mutual' );
		await form.locator( 'button[type="submit"]' ).first().click();

		await page.waitForURL( /gwc_vt_partner_result=added/ );

		await expect(
			page.getByRole( 'link', { name: 'Cranebrook Mutual' } )
		).toBeVisible();

		const made = api( 'terms', { taxonomy: TAX } ).find(
			( one ) => one.name === 'Cranebrook Mutual'
		);

		expect( made ).toBeTruthy();
	} );

	test( 'the same name twice is refused, and says so', async ( {
		page,
		admin,
		api,
	} ) => {
		await admin.visit( SCREEN );

		await page.fill( '#gwcvt-partner-name', 'Halbrook Trust' );
		await admin
			.formFor( 'gwc_vt_add_partner' )
			.first()
			.locator( 'button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_partner_result=added/ );

		await page.fill( '#gwcvt-partner-name', 'Halbrook Trust' );
		await admin
			.formFor( 'gwc_vt_add_partner' )
			.first()
			.locator( 'button[type="submit"]' )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_partner_result=exists/ );

		expect( await admin.notices() ).toContain( 'already a partner' );

		/* And it really did not make a second one — the notice being right is
		 * not the same as the database being right. */
		const named = api( 'terms', { taxonomy: TAX } ).filter(
			( one ) => one.name === 'Halbrook Trust'
		);

		expect( named.length ).toBe( 1 );
	} );

	test( 'the contact details save on the term editor and come back', async ( {
		page,
		admin,
		api,
	} ) => {
		const org = api( 'term.ensure', {
			taxonomy: TAX,
			name: 'Pelham Foods',
		} );

		await page.goto( termUrl( org.id ) );

		await page.fill( '#gwc_vt_partner_crm_id', 'SF-4417' );
		await page.fill( '#gwc_vt_partner_contact_name', 'Rosalind Achebe' );
		await page.fill( '#gwc_vt_partner_contact_email', 'rosalind@example.org' );
		await page.fill( '#gwc_vt_partner_contact_phone', '555-0164' );

		await page
			.getByRole( 'button', { name: 'Update', exact: true } )
			.click();

		await page.waitForLoadState( 'networkidle' );

		const saved = api( 'terms', { taxonomy: TAX } ).find(
			( one ) => one.id === org.id
		);

		expect( saved.meta.gwc_vt_partner_crm_id ).toBe( 'SF-4417' );
		expect( saved.meta.gwc_vt_partner_contact_name ).toBe( 'Rosalind Achebe' );
		expect( saved.meta.gwc_vt_partner_contact_email ).toBe(
			'rosalind@example.org'
		);

		/* And the screen shows them back, because a field that saves and does
		 * not redisplay reads as one that did not save. */
		await admin.visit( SCREEN );

		await expect(
			page.locator( '.gwcvt-partners__table' ).getByText( 'SF-4417' )
		).toBeVisible();
	} );

	test( 'a volunteer is given one from a checkbox list, never a text field', async ( {
		page,
		admin,
		api,
	} ) => {
		api( 'term.ensure', { taxonomy: TAX, name: 'Adeyemi Logistics' } );

		const volunteer = api( 'posts', { type: 'gwc_vt_volunteer' } )[ 0 ];

		expect( volunteer ).toBeTruthy();

		await admin.edit( volunteer.id );

		const box = page.locator( '#gwc_vt_partnerdiv, #taxonomy-gwc_vt_partner' ).first();

		await expect( box ).toBeVisible();

		/* The whole reason the taxonomy is hierarchical. A flat one renders the
		 * tag-style free-text input, and somebody typing a company's name into
		 * a person's record is exactly how the same partner comes to exist
		 * twice. If this ever fails, read the long note in inc/partner-taxonomy.php
		 * before "fixing" it. */
		await expect(
			box.locator( 'input[type="checkbox"]' ).first()
		).toBeVisible();

		/* Zero, and this failed when it was first written. Core's
		 * post_categories_meta_box() appends an "+ Add New Partner" toggle
		 * over a free-text input for anybody with the taxonomy's edit_terms
		 * (wp-admin/includes/meta-boxes.php:676) — which is every coordinator
		 * here. The hierarchical taxonomy bought the safe checkbox list and core
		 * handed the unsafe text field back three lines later. The fix is
		 * gwc_vt_partner_meta_box(); this is the assertion that caught it. */
		expect( await box.locator( 'input[type="text"]' ).count() ).toBe( 0 );

		await box
			.locator( 'input[type="checkbox"]' )
			.first()
			.check();

		await page.locator( '#publish' ).click();
		await page.waitForLoadState( 'networkidle' );

		const held = api( 'object.terms', {
			id: volunteer.id,
			taxonomy: TAX,
		} );

		expect( held.length ).toBeGreaterThan( 0 );
	} );

	test( 'two are folded into one, and the survivor keeps everybody', async ( {
		page,
		admin,
		api,
	} ) => {
		const keep = api( 'term.ensure', { taxonomy: TAX, name: 'Vanterpool Mills' } );
		const fold = api( 'term.ensure', { taxonomy: TAX, name: 'Vanterpool Mills Inc' } );

		const people = api( 'posts', { type: 'gwc_vt_volunteer' } ).slice( 0, 2 );

		expect( people.length ).toBe( 2 );

		/* One on each, so the merge has something to move and something to
		 * leave alone. */
		api( 'object.terms.set', {
			id: people[ 0 ].id,
			taxonomy: TAX,
			terms: [ keep.id ],
		} );
		api( 'object.terms.set', {
			id: people[ 1 ].id,
			taxonomy: TAX,
			terms: [ fold.id ],
		} );

		await admin.visit( SCREEN );

		/* Offered as a duplicate before anybody goes looking, which is the half
		 * of this feature that prevents the problem rather than repairing it. */
		await expect(
			page.locator( '.gwcvt-partners__duplicates' )
		).toContainText( 'Vanterpool Mills' );

		await page.check( `#gwcvt-partner-${ keep.id }` );
		await page.check( `#gwcvt-partner-${ fold.id }` );

		await page
			.getByRole( 'button', { name: /Fold the selected ones together/i } )
			.click();

		/* A confirmation step, because the merge cannot be undone. */
		await expect(
			page.getByText( 'This cannot be undone' )
		).toBeVisible();

		/* ── And no PHP complaint anywhere on it ─────────────────────────────
		 * WP_DEBUG and WP_DEBUG_DISPLAY are both on here, so a notice prints
		 * into the markup. admin-screens.spec.js makes this check on every
		 * screen's FIRST view, and this is the one view in the plugin it cannot
		 * reach: getting here needs two terms selected and a button pressed.
		 *
		 * It is not a hypothetical. A rename left `$partners[] = $org;` in the
		 * loop that builds this form, so every row rendered as three PHP
		 * warnings where a partner's name should have been — and the whole
		 * integration suite stayed green, because it calls the merge function
		 * directly and never renders anything. */
		await expect( page.locator( '#wpbody-content' ) ).not.toContainText(
			/(Warning|Notice|Deprecated|Fatal error|Parse error):\s/
		);

		const merge = admin.formFor( 'gwc_vt_merge_partners' ).first();

		await expect( merge ).toBeVisible();

		await page.check( `#gwcvt-survivor-${ keep.id }` );

		await merge
			.getByRole( 'button', { name: /Fold them together/i } )
			.click();

		await page.waitForURL( /gwc_vt_partner_result=merged/ );

		const left = api( 'terms', { taxonomy: TAX } ).map( ( one ) => one.name );

		expect( left ).toContain( 'Vanterpool Mills' );
		expect( left ).not.toContain( 'Vanterpool Mills Inc' );

		/* Both people are on the survivor — the one who was already there, and
		 * the one who was moved. */
		for ( const person of people ) {
			expect(
				api( 'object.terms', { id: person.id, taxonomy: TAX } )
			).toContain( 'Vanterpool Mills' );
		}
	} );

	test( 'a merge asks which value to keep when two disagree', async ( {
		page,
		admin,
		api,
	} ) => {
		const a = api( 'term.ensure', { taxonomy: TAX, name: 'Okonkwo Freight' } );
		const b = api( 'term.ensure', { taxonomy: TAX, name: 'Okonkwo Freight Ltd' } );

		/* Set through the real term editor, so this test does not depend on a
		 * back door for the arrangement it is about to assert on. */
		for ( const [ term, crm ] of [
			[ a, 'CRM-AAA' ],
			[ b, 'CRM-BBB' ],
		] ) {
			await page.goto( termUrl( term.id ) );
			await page.fill( '#gwc_vt_partner_crm_id', crm );
			await page
				.getByRole( 'button', { name: 'Update', exact: true } )
				.click();
			await page.waitForLoadState( 'networkidle' );
		}

		/* Built by hand rather than through admin.visit(), which runs its
		 * arguments through URLSearchParams.set() and would flatten the pair to
		 * a single "138,139". The screen reads merge[] the way the checkbox
		 * form posts it. */
		await page.goto(
			`/wp-admin/edit.php?post_type=gwc_vt_entry&page=${ SCREEN }` +
				`&merge[]=${ a.id }&merge[]=${ b.id }`
		);

		await expect(
			page.getByText( 'These details disagree' )
		).toBeVisible();

		/* Both values offered, and neither applied until somebody says. */
		await expect( page.locator( '.gwcvt-partners__conflict' ) ).toContainText(
			'CRM-AAA'
		);
		await expect( page.locator( '.gwcvt-partners__conflict' ) ).toContainText(
			'CRM-BBB'
		);

		await page.check( `#gwcvt-survivor-${ a.id }` );

		/* Nothing is preselected — the operator has to answer. A default here
		 * would record their silence as agreement, which is the code guessing
		 * one step removed. */
		expect(
			await page
				.locator( 'input[name="fields[gwc_vt_partner_crm_id]"]:checked' )
				.count()
		).toBe( 0 );

		await page
			.locator(
				'input[name="fields[gwc_vt_partner_crm_id]"][value="CRM-BBB"]'
			)
			.check();

		await admin
			.formFor( 'gwc_vt_merge_partners' )
			.first()
			.getByRole( 'button', { name: /Fold them together/i } )
			.click();

		await page.waitForURL( /gwc_vt_partner_result=merged/ );

		const survivor = api( 'terms', { taxonomy: TAX } ).find(
			( one ) => one.id === a.id
		);

		/* The chosen one, not the survivor's own — this is the assertion that
		 * would catch a last-write-wins merge, which looks correct on any pair
		 * where the operator happened to agree with it. */
		expect( survivor.meta.gwc_vt_partner_crm_id ).toBe( 'CRM-BBB' );
	} );

	test( 'Log a day attributes every row to one partner', async ( {
		page,
		admin,
		api,
		fixtures,
	} ) => {
		const partner = api( 'term.ensure', {
			taxonomy: TAX,
			name: 'Okonkwo Bakeries',
		} );

		await admin.visit( 'gwc-vt-log-a-day' );

		await page.fill( '#gwcvt-qa-date', '2026-08-15' );
		await page.fill( '#gwcvt-qa-activity', 'Sorting the winter appeal' );

		/* One selection for the whole sheet, which is the shape the work has —
		 * a company sends people for a morning and somebody types up one list.
		 * Asked per row it would be answered per never. */
		await page.selectOption( '#gwcvt-qa-partner', String( partner.id ) );

		await pick( page, 0, 'Marcus' );
		await page.fill( '#gwcvt-qa-hours-0', '3' );

		await pick( page, 1, 'Priya' );
		await page.fill( '#gwcvt-qa-hours-1', '2' );

		await admin
			.formFor( 'gwc_vt_quick_add' )
			.first()
			.getByRole( 'button', { name: /Log/ } )
			.first()
			.click();

		await page.waitForURL( /gwc_vt_qa=/ );

		/* Both rows carry it. Read off the entries themselves rather than from
		 * a total, so this fails if only the first row was attributed. */
		const attributed = api( 'posts', { type: 'gwc_vt_entry' } ).filter(
			( one ) =>
				api( 'object.terms', { id: one.id, taxonomy: TAX } ).includes(
					'Okonkwo Bakeries'
				)
		);

		expect( attributed.length ).toBe( 2 );

		/* And the Partners screen reports the five hours as one number. */
		await admin.visit( SCREEN );

		const row = page
			.locator( '.gwcvt-partners__table tbody tr' )
			.filter( { hasText: 'Okonkwo Bakeries' } );

		await expect( row ).toContainText( '5' );
	} );

	test( 'the hours list filters by partner and totals what it shows', async ( {
		page,
		admin,
		api,
	} ) => {
		const partner = api( 'term.ensure', {
			taxonomy: TAX,
			name: 'Ferreira Haulage',
		} );

		const entries = api( 'posts', { type: 'gwc_vt_entry' } ).slice( 0, 2 );

		expect( entries.length ).toBe( 2 );

		for ( const entry of entries ) {
			api( 'object.terms.set', {
				id: entry.id,
				taxonomy: TAX,
				terms: [ partner.id ],
			} );
		}

		/* wp-admin renders one tr.no-items when a list is empty, so a bare row
		 * count says 1 for a list showing nothing. */
		const rows = page.locator( '#the-list tr:not(.no-items)' );
		const summary = page.locator( '.gwcvt-partners__summary' );

		await page.goto(
			`/wp-admin/edit.php?post_type=gwc_vt_entry&gwc_vt_partner_is=${ partner.id }`
		);

		await expect( rows ).toHaveCount( 2 );
		await expect( summary ).toContainText( 'Ferreira Haulage' );
		await expect( summary ).toContainText( '2 entries shown' );

		/* ── And it moves when the list does ─────────────────────────────────
		 * The number is computed from the main query rather than from the term,
		 * so narrowing by anything else has to change the rows and the line
		 * above them together. Asked as a property — whatever the list shows,
		 * the summary says that — rather than against a fixed figure, because
		 * how many of the seed's entries happen to be verified is not the point.
		 *
		 * This plugin has shipped the other version twice: a count from one
		 * query over a list from another. */
		for ( const state of [ 'verified', 'unverified' ] ) {
			await page.goto(
				`/wp-admin/edit.php?post_type=gwc_vt_entry` +
					`&gwc_vt_partner_is=${ partner.id }&gwc_vt_state=${ state }`
			);

			const shown = await rows.count();

			await expect(
				summary,
				`the summary should agree with the ${ state } list`
			).toContainText(
				new RegExp( `${ shown } entr(y|ies) shown` )
			);
		}
	} );

	test( 'the Partners screen totals hours and links to the records', async ( {
		page,
		admin,
		api,
	} ) => {
		const partner = api( 'term.ensure', {
			taxonomy: TAX,
			name: 'Nakamura Provisions',
		} );

		const entry = api( 'posts', { type: 'gwc_vt_entry' } )[ 0 ];

		api( 'object.terms.set', {
			id: entry.id,
			taxonomy: TAX,
			terms: [ partner.id ],
		} );

		await admin.visit( SCREEN );

		const row = page
			.locator( '.gwcvt-partners__table tbody tr' )
			.filter( { hasText: 'Nakamura Provisions' } );

		await expect( row ).toHaveCount( 1 );

		/* ── The link a number opens must show what the number counted ───────
		 * The first version of gwc_vt_partner_volunteers_url() built
		 * ?gwc_vt_partner=<slug>, which does nothing: WP_Query reads a
		 * taxonomy's query var only when it has one, and this one is
		 * query_var => false on purpose. The count said three and the link
		 * opened every volunteer on the site. So this presses the link rather
		 * than asserting a URL. */
		await row.getByRole( 'link' ).last().click();

		await page.waitForURL( /post_type=gwc_vt_entry/ );

		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '.gwcvt-partners__summary' ) ).toContainText(
			'Nakamura Provisions'
		);
	} );

	test( 'the volunteer list filters to one partner', async ( {
		page,
		admin,
		api,
	} ) => {
		const org = api( 'term.ensure', { taxonomy: TAX, name: 'Baptiste Grocers' } );
		const people = api( 'posts', { type: 'gwc_vt_volunteer' } );

		api( 'object.terms.set', {
			id: people[ 0 ].id,
			taxonomy: TAX,
			terms: [ org.id ],
		} );

		await page.goto(
			`/wp-admin/edit.php?post_type=gwc_vt_volunteer&gwc_vt_partner_is=${ org.id }`
		);

		const rows = page.locator( '#the-list tr' );

		await expect( rows ).toHaveCount( 1 );
		await expect( rows.first() ).toContainText( people[ 0 ].title );
	} );

} );

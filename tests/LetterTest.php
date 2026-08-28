<?php
/**
 * The letter: what it claims, and what makes the claim checkable.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LetterTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Set settings and clear the memo.
	 *
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWC_VT_SETTINGS_OPTION, $settings );
		gwc_vt_settings_cache( null, true );
	}

	/**
	 * A letter built by hand, without touching the query layer.
	 *
	 * gwc_vt_build_letter() needs get_posts(), which this bootstrap deliberately
	 * does not stub — see the note there. The assembly from real records is
	 * covered by tests/integration/letter.php; what belongs here is everything
	 * downstream of the model.
	 *
	 * @param array $args Overrides.
	 * @return GWC_VT_Letter
	 */
	private function letter( array $args = array() ): GWC_VT_Letter {
		$entries = $args['entries'] ?? array(
			new GWC_VT_Letter_Entry( '2026-03-02', 210, 'Sorting donations', 'Dana Reyes', true, 'Verified March 3, 2026 by Dana Reyes' ),
			new GWC_VT_Letter_Entry( '2026-03-09', 180, 'Front desk', 'Dana Reyes', true, 'Verified March 10, 2026 by Dana Reyes' ),
		);

		return new GWC_VT_Letter(
			$args['volunteer_id'] ?? 42,
			$args['volunteer_name'] ?? 'Jane Quimby',
			$args['from'] ?? '2026-03-01',
			$args['to'] ?? '2026-03-31',
			$entries,
			$args['verified_minutes'] ?? 390,
			$args['unverified_minutes'] ?? 0,
			$args['includes_unverified'] ?? false,
			$args['reference'] ?? 'REF-42-20260401-ABCD1234',
			$args['issued_at'] ?? 1_800_000_000
		);
	}

	/* ── The model ───────────────────────────────────────────────────────── */

	public function test_it_counts_what_it_lists(): void {
		$letter = $this->letter();

		$this->assertSame( 2, $letter->entry_count() );
		$this->assertSame( 2, $letter->verified_count() );
		$this->assertFalse( $letter->has_unverified() );
		$this->assertFalse( $letter->is_empty() );
	}

	public function test_an_unverified_row_is_counted_separately(): void {
		$letter = $this->letter(
			array(
				'entries'             => array(
					new GWC_VT_Letter_Entry( '2026-03-02', 210, 'Sorting', 'Dana', true, 'Verified' ),
					new GWC_VT_Letter_Entry( '2026-03-09', 90, 'Front desk', 'Dana', false, '' ),
				),
				'verified_minutes'    => 210,
				'unverified_minutes'  => 90,
				'includes_unverified' => true,
			)
		);

		$this->assertSame( 2, $letter->entry_count() );
		$this->assertSame( 1, $letter->verified_count() );
		$this->assertTrue( $letter->has_unverified() );

		/* The load-bearing assertion. An unattested shift may be shown, but it
		 * is never folded into the figure the letter claims — that figure is
		 * what a court reads as "hours completed". */
		$this->assertSame( 210, $letter->verified_minutes );
	}

	public function test_the_model_refuses_a_row_that_is_not_an_entry(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->letter( array( 'entries' => array( array( 'date' => '2026-03-02' ) ) ) );
	}

	public function test_the_model_refuses_a_wrongly_typed_duration(): void {
		$this->expectException( TypeError::class );

		/* The whole reason this is a class. A date landing in the minutes field
		 * would render without complaint and be wrong on a document somebody is
		 * relying on. */
		new GWC_VT_Letter_Entry( '2026-03-02', '2026-03-02' ); // @phpstan-ignore-line
	}

	/* ── The disclaimer ──────────────────────────────────────────────────── */

	public function test_the_disclaimer_is_never_empty(): void {
		$this->settings( array( 'letter_disclaimer' => '' ) );
		$this->assertNotSame( '', trim( gwc_vt_disclaimer() ) );

		$this->settings( array( 'letter_disclaimer' => '   ' ) );
		$this->assertSame(
			gwc_vt_default_disclaimer(),
			gwc_vt_disclaimer(),
			'A disclaimer emptied by a bad import or a direct option write must fall back, not vanish.'
		);
	}

	public function test_the_disclaimer_can_be_replaced(): void {
		$this->settings( array( 'letter_disclaimer' => 'Our counsel prefers this wording.' ) );

		$this->assertSame( 'Our counsel prefers this wording.', gwc_vt_disclaimer() );
	}

	public function test_the_reference_note_is_never_empty(): void {
		$this->settings( array( 'letter_reference_note' => '' ) );

		$this->assertSame( gwc_vt_default_reference_note(), gwc_vt_reference_note() );
	}

	public function test_the_default_disclaimer_names_the_org_as_record_keeper(): void {
		$text = gwc_vt_default_disclaimer();

		$this->assertStringContainsString( '{org}', $text );
		$this->assertStringContainsString( 'authoritative record-keeper', $text );
		$this->assertStringContainsString( 'not an independent certification', $text );
	}

	/**
	 * The words this document must never use about itself.
	 *
	 * Asserted rather than merely written down in a comment, because each one is
	 * a plausible-sounding improvement somebody will propose — and each is the
	 * plugin claiming an authority nobody at Groundwork Common has.
	 */
	#[DataProvider( 'forbidden_words' )]
	public function test_the_built_in_wording_never_overclaims( string $word ): void {
		$corpus = strtolower(
			gwc_vt_default_disclaimer() . ' ' . gwc_vt_default_reference_note() . ' ' . gwc_vt_letter_intro( array() )
		);

		$this->assertStringNotContainsString( $word, $corpus );
	}

	public static function forbidden_words(): array {
		return array(
			'certified'          => array( 'certified' ),
			'certifies'          => array( 'certifies' ),
			'official'           => array( 'official' ),
			'under penalty'      => array( 'penalty of perjury' ),
			'sworn'              => array( 'sworn' ),
			'notarised'          => array( 'notari' ),
			'guarantee'          => array( 'guarantee' ),
		);
	}

	/* ── Tokens ──────────────────────────────────────────────────────────── */

	public function test_tokens_are_substituted(): void {
		$letter = $this->letter();
		$tokens = gwc_vt_letter_tokens( $letter );

		$this->assertSame(
			'Jane Quimby did 6.5 hours for Test Food Bank.',
			gwc_vt_replace_tokens( '{name} did {hours} hours for {org}.', $tokens )
		);
	}

	public function test_an_unknown_token_is_left_alone(): void {
		$tokens = gwc_vt_letter_tokens( $this->letter() );

		/* Left visible rather than blanked. A stray {supervisor} on a letter is
		 * obvious and gets fixed; silently removing it produces a sentence with
		 * a hole in it that reads as finished. */
		$this->assertSame( 'Hello {supervisor}', gwc_vt_replace_tokens( 'Hello {supervisor}', $tokens ) );
	}

	public function test_the_period_reads_sensibly_in_all_four_shapes(): void {
		/* No date_format stored, so the dates print as they are held. The
		 * formatted case is asserted below. */
		$this->assertSame( '2026-03-01 to 2026-03-31', gwc_vt_letter_period( $this->letter() ) );
		$this->assertSame( 'from 2026-03-01 onwards', gwc_vt_letter_period( $this->letter( array( 'to' => '' ) ) ) );
		$this->assertSame( 'up to 2026-03-31', gwc_vt_letter_period( $this->letter( array( 'from' => '' ) ) ) );
		/* Not "their entire time volunteering with us", which is what this said
		 * and is not what a letter with no dates on it covers. It runs from the
		 * first thing on record to the day it goes out — so it names both, and
		 * says where the second one came from. Somebody who volunteers again next
		 * week is not contradicted by a letter that never claimed to cover it. */
		$this->assertSame(
			'2026-03-02 to 2027-01-15, the date of this letter',
			gwc_vt_letter_period( $this->letter( array( 'from' => '', 'to' => '' ) ) )
		);

		/* And with nothing on record there is no first date to name, so it says
		 * only the half it can stand behind. */
		$this->assertSame(
			'their service on record up to 2027-01-15',
			gwc_vt_letter_period(
				$this->letter(
					array(
						'from'             => '',
						'to'               => '',
						'entries'          => array(),
						'verified_minutes' => 0,
					)
				)
			)
		);
	}

	/* ── Dates on the letter ─────────────────────────────────────────────────
	 * The letterhead date has always gone through the site's date format. The
	 * itemised rows and the period line printed the raw stored value, so one
	 * document read "August 6, 2026" at the top and "2026-03-04" in every row
	 * below — to a reader whose job is checking documents.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_a_stored_date_prints_the_way_the_site_writes_dates(): void {
		update_option( 'date_format', 'F j, Y' );

		$this->assertSame( 'March 4, 2026', gwc_vt_display_date( '2026-03-04' ) );
		$this->assertSame( 'March 1, 2026 to March 31, 2026', gwc_vt_letter_period( $this->letter() ) );
	}

	/* Midday UTC, so a site west of Greenwich does not print the day before. A
	 * shift happened on a day; it was never an instant to convert. */
	public function test_a_date_does_not_slip_across_a_day_boundary(): void {
		update_option( 'date_format', 'Y-m-d' );

		$this->assertSame( '2026-01-01', gwc_vt_display_date( '2026-01-01' ) );
		$this->assertSame( '2026-12-31', gwc_vt_display_date( '2026-12-31' ) );
	}

	public function test_an_unformattable_date_is_printed_rather_than_dropped(): void {
		update_option( 'date_format', 'F j, Y' );

		/* A blank where a date belongs reads as a broken document. Anything this
		 * cannot parse is passed through for a human to see. */
		$this->assertSame( '', gwc_vt_display_date( '' ) );
		$this->assertSame( 'sometime', gwc_vt_display_date( 'sometime' ) );
	}

	public function test_no_stored_format_falls_back_to_the_stored_date(): void {
		$this->assertSame( '2026-03-04', gwc_vt_display_date( '2026-03-04' ) );
	}

	/* ── The reference code ──────────────────────────────────────────────── */

	/**
	 * Two shifts, as the letter would list them.
	 *
	 * @param array $overrides Field overrides for the first row.
	 * @return GWC_VT_Letter_Entry[]
	 */
	private function rows( array $overrides = array() ): array {
		$first = array_merge(
			array(
				'date'       => '2026-03-02',
				'minutes'    => 210,
				'activity'   => 'Sorting donations',
				'supervisor' => 'Dana Reyes',
				'verified'   => true,
			),
			$overrides
		);

		return array(
			new GWC_VT_Letter_Entry( $first['date'], $first['minutes'], $first['activity'], $first['supervisor'], $first['verified'], '' ),
			new GWC_VT_Letter_Entry( '2026-03-09', 180, 'Front desk', 'Dana Reyes', true, '' ),
		);
	}

	/**
	 * The code for a letter over those rows.
	 *
	 * @param array $overrides Field overrides for the first row.
	 * @param int   $minutes   The verified total.
	 * @return string
	 */
	private function code( array $overrides = array(), int $minutes = 390 ): string {
		return gwc_vt_reference_digest(
			gwc_vt_letter_reference( 42, '2026-03-01', '2026-03-31', $minutes, $this->rows( $overrides ) )
		);
	}

	public function test_a_reference_is_stable_for_the_same_facts(): void {
		$this->assertSame( $this->code(), $this->code(), 'Re-issuing an unchanged letter must produce the same code.' );
	}

	public function test_the_digest_is_eight_hex_characters(): void {
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{8}$/', $this->code() );
	}

	/* ── What the digest has to notice ───────────────────────────────────────
	 * The first version hashed only the volunteer, the range, the total and the
	 * count. Every case below produced an IDENTICAL code under it, and every one
	 * is a change to what the document says — so the verifier answered "matches
	 * our current records" about a letter that had been altered.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_swapping_two_shifts_changes_the_code(): void {
		/* 3.5 h and 3 h becoming 3 h and 3.5 h. Same total, same number of
		 * shifts, different document. */
		$swapped = array(
			new GWC_VT_Letter_Entry( '2026-03-02', 180, 'Sorting donations', 'Dana Reyes', true, '' ),
			new GWC_VT_Letter_Entry( '2026-03-09', 210, 'Front desk', 'Dana Reyes', true, '' ),
		);

		$this->assertNotSame(
			$this->code(),
			gwc_vt_reference_digest( gwc_vt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, $swapped ) )
		);
	}

	public function test_rewriting_an_activity_changes_the_code(): void {
		$this->assertNotSame( $this->code(), $this->code( array( 'activity' => 'Warehouse inventory' ) ) );
	}

	public function test_moving_a_date_within_the_range_changes_the_code(): void {
		$this->assertNotSame( $this->code(), $this->code( array( 'date' => '2026-03-03' ) ) );
	}

	public function test_changing_a_supervisor_changes_the_code(): void {
		$this->assertNotSame( $this->code(), $this->code( array( 'supervisor' => 'Somebody Else' ) ) );
	}

	public function test_unverifying_a_shift_changes_the_code(): void {
		$this->assertNotSame( $this->code(), $this->code( array( 'verified' => false ) ) );
	}

	public function test_changing_the_total_changes_the_code(): void {
		$this->assertNotSame( $this->code(), $this->code( array(), 391 ) );
	}

	public function test_changing_the_volunteer_or_the_range_changes_the_code(): void {
		$rows = $this->rows();

		$this->assertNotSame(
			$this->code(),
			gwc_vt_reference_digest( gwc_vt_letter_reference( 43, '2026-03-01', '2026-03-31', 390, $rows ) ),
			'A different volunteer must change the code.'
		);
		$this->assertNotSame(
			$this->code(),
			gwc_vt_reference_digest( gwc_vt_letter_reference( 42, '2026-02-01', '2026-03-31', 390, $rows ) ),
			'A different start date must change the code.'
		);
		$this->assertNotSame(
			$this->code(),
			gwc_vt_reference_digest( gwc_vt_letter_reference( 42, '2026-03-01', '2026-04-30', 390, $rows ) ),
			'A different end date must change the code.'
		);
	}

	public function test_dropping_a_shift_changes_the_code(): void {
		$one = array( new GWC_VT_Letter_Entry( '2026-03-02', 210, 'Sorting donations', 'Dana Reyes', true, '' ) );

		$this->assertNotSame(
			$this->code(),
			gwc_vt_reference_digest( gwc_vt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, $one ) )
		);
	}

	public function test_the_prefix_setting_appears_in_the_code(): void {
		$this->settings( array( 'reference_prefix' => 'fb' ) );

		$this->assertStringStartsWith( 'FB-42-', gwc_vt_letter_reference( 42, '', '', 390, $this->rows() ) );
	}

	public function test_a_prefix_with_punctuation_is_cleaned(): void {
		$this->settings( array( 'reference_prefix' => 'Food Bank/2026' ) );

		/* The code gets read aloud over the phone and typed back in. A slash in
		 * it is a transcription error waiting to happen. */
		$this->assertStringStartsWith( 'FOODBANK2026-42-', gwc_vt_letter_reference( 42, '', '', 390, $this->rows() ) );
	}

	public function test_the_digest_survives_a_different_issue_date(): void {
		/* The code embeds the day it was issued, so a letter from last March can
		 * never recompute to a byte-identical code today. Verification compares
		 * the digest alone — if it compared the whole code, every letter older
		 * than a day would be reported as changed. */
		$digest = $this->code();

		$this->assertSame( $digest, gwc_vt_reference_digest( 'REF-42-19990101-' . $digest ) );
	}

	public function test_the_reference_is_filterable(): void {
		$fixed = static fn(): string => 'FIXED-CODE';

		add_filter( 'gwc_vt_letter_reference', $fixed );
		$this->assertSame( 'FIXED-CODE', gwc_vt_letter_reference( 42, '', '', 390, $this->rows() ) );
		remove_filter( 'gwc_vt_letter_reference', $fixed );
	}

	/* ── The email inliner ───────────────────────────────────────────────── */

	public function test_the_inliner_adds_a_style_attribute_to_a_known_class(): void {
		$html = gwc_vt_inline_letter_styles( '<div class="gwcvt-letter"><p class="gwcvt-org">Test</p></div>' );

		$this->assertStringContainsString( 'class="gwcvt-letter" style="font-family:', $html );
		$this->assertStringContainsString( 'class="gwcvt-org" style="font-size:', $html );
	}

	public function test_the_inliner_leaves_unknown_classes_alone(): void {
		$html = gwc_vt_inline_letter_styles( '<div class="something-else">Test</div>' );

		$this->assertSame( '<div class="something-else">Test</div>', $html );
	}

	public function test_the_inliner_does_not_match_a_class_prefix(): void {
		$html = gwc_vt_inline_letter_styles( '<p class="gwcvt-organization">Test</p>' );

		$this->assertSame( '<p class="gwcvt-organization">Test</p>', $html );
	}

	public function test_a_hyphenated_class_does_not_inherit_its_stem(): void {
		/* The case the prefix test above missed for three releases. It used
		 * "gwcvt-organization", where the stem is followed by a letter — but the
		 * real class names are hyphenated, and \b treats a hyphen as a word
		 * boundary. So the rule for `gwcvt-org` matched inside
		 * `gwcvt-org-address`, `gwcvt-org-contact` and `gwcvt-org-logo`. */
		$html = gwc_vt_inline_letter_styles( '<p class="gwcvt-org-address">Test</p>' );

		$this->assertStringContainsString( 'font-size:10pt', $html, 'It should get its own declarations.' );
		$this->assertStringNotContainsString( 'font-weight:bold', $html, 'It must not inherit gwcvt-org’s.' );
	}

	public function test_no_element_ever_gets_two_style_attributes(): void {
		/* HTML keeps the FIRST style attribute and silently discards the rest,
		 * so a second one means whichever rule wins is decided by the order the
		 * rules happen to be listed in. That is how the logo arrived as bold
		 * 15pt text. */
		$html = gwc_vt_inline_letter_styles(
			'<header class="gwcvt-letterhead">'
			. '<img class="gwcvt-org-logo" src="x.png" alt="" />'
			. '<p class="gwcvt-org">Name</p>'
			. '<p class="gwcvt-org-address">Street</p>'
			. '<p class="gwcvt-org-contact">Phone</p>'
			. '<table class="gwcvt-summary-table"><tr><th scope="row">A</th><td class="gwcvt-total">B</td></tr></table>'
			. '</header>'
		);

		$this->assertSame( 0, preg_match_all( '/<[a-z]+[^>]*style="[^"]*"[^>]*style="/i', $html ) );
	}

	public function test_the_inliner_keeps_a_self_closing_tag_valid(): void {
		$html = gwc_vt_inline_letter_styles( '<img class="gwcvt-org-logo" src="x.png" alt="" />' );

		/* The slash belongs after the attributes. Appending a style attribute
		 * without accounting for it produces `<img … / style="…">`, which is
		 * not markup any client will render as an image. */
		$this->assertMatchesRegularExpression( '/style="[^"]*max-height[^"]*" \/>$/', $html );
	}

	public function test_a_classed_table_cell_carries_its_own_padding(): void {
		/* The classless-cell pass deliberately leaves classed cells alone, so a
		 * cell with a class has to bring the padding itself or arrive unpadded. */
		$html = gwc_vt_inline_letter_styles( '<td class="gwcvt-total">18</td>' );

		$this->assertStringContainsString( 'padding:', $html );
		$this->assertStringContainsString( 'font-size:13pt', $html );
	}
}

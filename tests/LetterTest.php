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
		gwcvt_test_reset();
	}

	/**
	 * Set settings and clear the memo.
	 *
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWCVT_SETTINGS_OPTION, $settings );
		gwcvt_settings_cache( null, true );
	}

	/**
	 * A letter built by hand, without touching the query layer.
	 *
	 * gwcvt_build_letter() needs get_posts(), which this bootstrap deliberately
	 * does not stub — see the note there. The assembly from real records is
	 * covered by tests/integration/letter.php; what belongs here is everything
	 * downstream of the model.
	 *
	 * @param array $args Overrides.
	 * @return GWCVT_Letter
	 */
	private function letter( array $args = array() ): GWCVT_Letter {
		$entries = $args['entries'] ?? array(
			new GWCVT_Letter_Entry( '2026-03-02', 210, 'Sorting donations', 'Dana Reyes', true, 'Verified March 3, 2026 by Dana Reyes' ),
			new GWCVT_Letter_Entry( '2026-03-09', 180, 'Front desk', 'Dana Reyes', true, 'Verified March 10, 2026 by Dana Reyes' ),
		);

		return new GWCVT_Letter(
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
					new GWCVT_Letter_Entry( '2026-03-02', 210, 'Sorting', 'Dana', true, 'Verified' ),
					new GWCVT_Letter_Entry( '2026-03-09', 90, 'Front desk', 'Dana', false, '' ),
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
		new GWCVT_Letter_Entry( '2026-03-02', '2026-03-02' ); // @phpstan-ignore-line
	}

	/* ── The disclaimer ──────────────────────────────────────────────────── */

	public function test_the_disclaimer_is_never_empty(): void {
		$this->settings( array( 'letter_disclaimer' => '' ) );
		$this->assertNotSame( '', trim( gwcvt_disclaimer() ) );

		$this->settings( array( 'letter_disclaimer' => '   ' ) );
		$this->assertSame(
			gwcvt_default_disclaimer(),
			gwcvt_disclaimer(),
			'A disclaimer emptied by a bad import or a direct option write must fall back, not vanish.'
		);
	}

	public function test_the_disclaimer_can_be_replaced(): void {
		$this->settings( array( 'letter_disclaimer' => 'Our counsel prefers this wording.' ) );

		$this->assertSame( 'Our counsel prefers this wording.', gwcvt_disclaimer() );
	}

	public function test_the_reference_note_is_never_empty(): void {
		$this->settings( array( 'letter_reference_note' => '' ) );

		$this->assertSame( gwcvt_default_reference_note(), gwcvt_reference_note() );
	}

	public function test_the_default_disclaimer_names_the_org_as_record_keeper(): void {
		$text = gwcvt_default_disclaimer();

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
			gwcvt_default_disclaimer() . ' ' . gwcvt_default_reference_note() . ' ' . gwcvt_letter_intro( array() )
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
		$tokens = gwcvt_letter_tokens( $letter );

		$this->assertSame(
			'Jane Quimby did 6.5 hours for Test Food Bank.',
			gwcvt_replace_tokens( '{name} did {hours} hours for {org}.', $tokens )
		);
	}

	public function test_an_unknown_token_is_left_alone(): void {
		$tokens = gwcvt_letter_tokens( $this->letter() );

		/* Left visible rather than blanked. A stray {supervisor} on a letter is
		 * obvious and gets fixed; silently removing it produces a sentence with
		 * a hole in it that reads as finished. */
		$this->assertSame( 'Hello {supervisor}', gwcvt_replace_tokens( 'Hello {supervisor}', $tokens ) );
	}

	public function test_the_period_reads_sensibly_in_all_four_shapes(): void {
		$this->assertSame( '2026-03-01 to 2026-03-31', gwcvt_letter_period( $this->letter() ) );
		$this->assertSame( 'from 2026-03-01 onwards', gwcvt_letter_period( $this->letter( array( 'to' => '' ) ) ) );
		$this->assertSame( 'up to 2026-03-31', gwcvt_letter_period( $this->letter( array( 'from' => '' ) ) ) );
		$this->assertSame(
			'their entire time volunteering with us',
			gwcvt_letter_period( $this->letter( array( 'from' => '', 'to' => '' ) ) )
		);
	}

	/* ── The reference code ──────────────────────────────────────────────── */

	/**
	 * Two shifts, as the letter would list them.
	 *
	 * @param array $overrides Field overrides for the first row.
	 * @return GWCVT_Letter_Entry[]
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
			new GWCVT_Letter_Entry( $first['date'], $first['minutes'], $first['activity'], $first['supervisor'], $first['verified'], '' ),
			new GWCVT_Letter_Entry( '2026-03-09', 180, 'Front desk', 'Dana Reyes', true, '' ),
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
		return gwcvt_reference_digest(
			gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', $minutes, $this->rows( $overrides ) )
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
			new GWCVT_Letter_Entry( '2026-03-02', 180, 'Sorting donations', 'Dana Reyes', true, '' ),
			new GWCVT_Letter_Entry( '2026-03-09', 210, 'Front desk', 'Dana Reyes', true, '' ),
		);

		$this->assertNotSame(
			$this->code(),
			gwcvt_reference_digest( gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, $swapped ) )
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
			gwcvt_reference_digest( gwcvt_letter_reference( 43, '2026-03-01', '2026-03-31', 390, $rows ) ),
			'A different volunteer must change the code.'
		);
		$this->assertNotSame(
			$this->code(),
			gwcvt_reference_digest( gwcvt_letter_reference( 42, '2026-02-01', '2026-03-31', 390, $rows ) ),
			'A different start date must change the code.'
		);
		$this->assertNotSame(
			$this->code(),
			gwcvt_reference_digest( gwcvt_letter_reference( 42, '2026-03-01', '2026-04-30', 390, $rows ) ),
			'A different end date must change the code.'
		);
	}

	public function test_dropping_a_shift_changes_the_code(): void {
		$one = array( new GWCVT_Letter_Entry( '2026-03-02', 210, 'Sorting donations', 'Dana Reyes', true, '' ) );

		$this->assertNotSame(
			$this->code(),
			gwcvt_reference_digest( gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, $one ) )
		);
	}

	public function test_the_prefix_setting_appears_in_the_code(): void {
		$this->settings( array( 'reference_prefix' => 'fb' ) );

		$this->assertStringStartsWith( 'FB-42-', gwcvt_letter_reference( 42, '', '', 390, $this->rows() ) );
	}

	public function test_a_prefix_with_punctuation_is_cleaned(): void {
		$this->settings( array( 'reference_prefix' => 'Food Bank/2026' ) );

		/* The code gets read aloud over the phone and typed back in. A slash in
		 * it is a transcription error waiting to happen. */
		$this->assertStringStartsWith( 'FOODBANK2026-42-', gwcvt_letter_reference( 42, '', '', 390, $this->rows() ) );
	}

	public function test_the_digest_survives_a_different_issue_date(): void {
		/* The code embeds the day it was issued, so a letter from last March can
		 * never recompute to a byte-identical code today. Verification compares
		 * the digest alone — if it compared the whole code, every letter older
		 * than a day would be reported as changed. */
		$digest = $this->code();

		$this->assertSame( $digest, gwcvt_reference_digest( 'REF-42-19990101-' . $digest ) );
	}

	public function test_the_reference_is_filterable(): void {
		$fixed = static fn(): string => 'FIXED-CODE';

		add_filter( 'gwcvt_letter_reference', $fixed );
		$this->assertSame( 'FIXED-CODE', gwcvt_letter_reference( 42, '', '', 390, $this->rows() ) );
		remove_filter( 'gwcvt_letter_reference', $fixed );
	}

	/* ── The email inliner ───────────────────────────────────────────────── */

	public function test_the_inliner_adds_a_style_attribute_to_a_known_class(): void {
		$html = gwcvt_inline_letter_styles( '<div class="gwcvt-letter"><p class="gwcvt-org">Test</p></div>' );

		$this->assertStringContainsString( 'class="gwcvt-letter" style="font-family:', $html );
		$this->assertStringContainsString( 'class="gwcvt-org" style="font-size:', $html );
	}

	public function test_the_inliner_leaves_unknown_classes_alone(): void {
		$html = gwcvt_inline_letter_styles( '<div class="something-else">Test</div>' );

		$this->assertSame( '<div class="something-else">Test</div>', $html );
	}

	public function test_the_inliner_does_not_match_a_class_prefix(): void {
		/* gwcvt-organisation must not pick up gwcvt-org's declarations. The word
		 * boundary in the pattern is what stops that, and without a test it is
		 * the kind of thing that only shows up as a mysteriously styled line. */
		$html = gwcvt_inline_letter_styles( '<p class="gwcvt-organisation">Test</p>' );

		$this->assertSame( '<p class="gwcvt-organisation">Test</p>', $html );
	}
}

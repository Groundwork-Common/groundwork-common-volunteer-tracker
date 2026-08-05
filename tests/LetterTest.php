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

	public function test_a_reference_is_stable_for_the_same_facts(): void {
		$a = gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, 2 );
		$b = gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, 2 );

		$this->assertSame( $a, $b, 'Re-issuing an unchanged letter must produce the same code.' );
	}

	public function test_the_digest_is_eight_hex_characters(): void {
		$digest = gwcvt_reference_digest( gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, 2 ) );

		$this->assertMatchesRegularExpression( '/^[0-9A-F]{8}$/', $digest );
	}

	/**
	 * @param string $label What differs.
	 * @param array  $args  Arguments for the second code.
	 */
	#[DataProvider( 'reference_variations' )]
	public function test_changing_any_fact_changes_the_reference( string $label, array $args ): void {
		$base = gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, 2 );
		$other = gwcvt_letter_reference( ...$args );

		$this->assertNotSame(
			gwcvt_reference_digest( $base ),
			gwcvt_reference_digest( $other ),
			$label . ' must change the code — otherwise an edited letter still verifies.'
		);
	}

	public static function reference_variations(): array {
		return array(
			'a different volunteer' => array( 'A different volunteer', array( 43, '2026-03-01', '2026-03-31', 390, 2 ) ),
			'a different start'     => array( 'A different start date', array( 42, '2026-02-01', '2026-03-31', 390, 2 ) ),
			'a different end'       => array( 'A different end date', array( 42, '2026-03-01', '2026-04-30', 390, 2 ) ),
			'one more minute'       => array( 'One more minute', array( 42, '2026-03-01', '2026-03-31', 391, 2 ) ),
			'one more shift'        => array( 'One more shift', array( 42, '2026-03-01', '2026-03-31', 390, 3 ) ),
		);
	}

	public function test_the_prefix_setting_appears_in_the_code(): void {
		$this->settings( array( 'reference_prefix' => 'fb' ) );

		$this->assertStringStartsWith( 'FB-42-', gwcvt_letter_reference( 42, '', '', 390, 2 ) );
	}

	public function test_a_prefix_with_punctuation_is_cleaned(): void {
		$this->settings( array( 'reference_prefix' => 'Food Bank/2026' ) );

		/* The code gets read aloud over the phone and typed back in. A slash in
		 * it is a transcription error waiting to happen. */
		$this->assertStringStartsWith( 'FOODBANK2026-42-', gwcvt_letter_reference( 42, '', '', 390, 2 ) );
	}

	public function test_the_digest_survives_a_different_issue_date(): void {
		/* The code embeds the day it was issued, so a letter from last March can
		 * never recompute to a byte-identical code today. Verification compares
		 * the digest alone — if it compared the whole code, every letter older
		 * than a day would be reported as changed. */
		$code = gwcvt_letter_reference( 42, '2026-03-01', '2026-03-31', 390, 2 );

		$this->assertSame(
			gwcvt_reference_digest( $code ),
			gwcvt_reference_digest( 'REF-42-19990101-' . gwcvt_reference_digest( $code ) )
		);
	}

	public function test_the_reference_is_filterable(): void {
		$fixed = static fn(): string => 'FIXED-CODE';

		add_filter( 'gwcvt_letter_reference', $fixed );
		$this->assertSame( 'FIXED-CODE', gwcvt_letter_reference( 42, '', '', 390, 2 ) );
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

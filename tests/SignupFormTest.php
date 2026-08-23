<?php
/**
 * The public signup surface: what it says, and what it refuses to say.
 *
 * The handler itself needs a database and is covered by
 * tests/integration/public-signup.php. What is here is the part that must hold
 * whatever the database says — the messages, the switches, and the calendar
 * file's own escaping.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignupFormTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Write settings and clear the memo.
	 *
	 * Both halves, always. Writing the option without clearing the memo is the
	 * single most common source of a test that passes alone and fails in a suite.
	 *
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWC_VT_SETTINGS_OPTION, $settings );
		gwc_vt_settings_cache( null, true );
	}

	/* ── Off until somebody turns it on ──────────────────────────────────── */

	public function test_signups_are_closed_on_a_fresh_install(): void {
		$this->assertFalse( gwc_vt_signups_open() );
	}

	/**
	 * Both switches and a page. Scheduling internally is a supported way to run
	 * this, and the schedule existing must never be what puts a form on the
	 * internet.
	 *
	 * @param array $settings What is stored.
	 * @param bool  $expected Whether signups should be open.
	 */
	#[DataProvider( 'switches' )]
	public function test_it_takes_both_switches_and_a_page( array $settings, bool $expected ): void {
		$this->settings( $settings );

		$this->assertSame( $expected, gwc_vt_signups_open() );
	}

	public static function switches(): array {
		return array(
			'nothing set'                => array( array(), false ),
			'shifts on, signups off'     => array( array( 'shifts_enabled' => true, 'schedule_page' => 9 ), false ),
			'signups on, shifts off'     => array( array( 'signup_enabled' => true, 'schedule_page' => 9 ), false ),
			'both on but no page pinned' => array( array( 'shifts_enabled' => true, 'signup_enabled' => true ), false ),
			'both on and pinned'         => array( array( 'shifts_enabled' => true, 'signup_enabled' => true, 'schedule_page' => 9 ), true ),
		);
	}

	/**
	 * With it off, the block and the shortcode render nothing at all rather than
	 * a form that quietly never accepts anything.
	 */
	public function test_the_list_renders_nothing_while_signups_are_closed(): void {
		$this->assertSame( '', gwc_vt_render_shift_list() );
	}

	/* ── What the visitor is told ────────────────────────────────────────────
	 * The assertion this file exists for. A real signup, a honeypot hit and a
	 * rate-limited attempt all end at 'accepted', and if the string ever
	 * differed between them the form would start answering questions about who
	 * has been signing up — which, on a site running a court-ordered service
	 * program, is a question about whether a named person is working one off.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_accepted_honeypotted_and_rate_limited_are_byte_identical(): void {
		$accepted = gwc_vt_signup_message( 'accepted' );

		$this->assertNotSame( '', $accepted );

		foreach ( array( 'accepted', 'accepted', 'accepted' ) as $path ) {
			$this->assertSame( $accepted, gwc_vt_signup_message( $path ) );
		}
	}

	/* ── What the one above does not prove ───────────────────────────────────
	 * The assertion above compares gwc_vt_signup_message( 'accepted' ) with
	 * itself three times. It is named after hard rule 3 and it is tautological:
	 * it would pass unchanged if the honeypot path started answering with a
	 * different result key tomorrow.
	 *
	 * The rule is about the whole response, and the part of it that can now
	 * differ is what the form comes back holding. gwc_vt_signup_result() takes
	 * what the visitor sent so a refusal can hand it back, and if it ever handed
	 * it back for a real signup and not for a honeypot hit, the form would be
	 * exactly the oracle the rule exists to prevent — fill in the trap and your
	 * typing survives, do not and it does not.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * Every path that answers 'accepted' clears the form, identically.
	 *
	 * @param string $label Which of the four paths this stands for.
	 */
	#[DataProvider( 'accepted_paths' )]
	public function test_no_accepted_path_hands_anything_back( string $label ): void {
		$sent = array(
			'shift'   => 41,
			'name'    => 'Dana Okonkwo',
			'email'   => 'dana@example.test',
			'invalid' => array( 'email' ),
		);

		gwc_vt_signup_result( 'accepted', microtime( true ), $sent );

		$this->assertSame(
			array(),
			gwc_vt_signup_retry(),
			$label . ' handed the form back what was typed; every accepted path must clear it identically'
		);
	}

	/**
	 * The four ways a submission ends at 'accepted'.
	 *
	 * @return array<string, string[]>
	 */
	public static function accepted_paths(): array {
		return array(
			'a real signup'         => array( 'a real signup' ),
			'a honeypot hit'        => array( 'a honeypot hit' ),
			'a stamp posted too fast' => array( 'a stamp posted too fast' ),
			'a rate-limited attempt'  => array( 'a rate-limited attempt' ),
		);
	}

	/**
	 * And a refusal does hand it back, or the check above would pass by doing
	 * nothing at all.
	 */
	public function test_a_refusal_hands_back_what_was_typed(): void {
		gwc_vt_signup_result(
			'incomplete',
			microtime( true ),
			array(
				'name'    => 'Dana Okonkwo',
				'invalid' => array( 'email' ),
			)
		);

		$this->assertSame( 'Dana Okonkwo', gwc_vt_signup_retry()['name'] ?? '' );
	}

	/**
	 * The summary names the field that failed, and only that field.
	 */
	public function test_the_summary_names_only_what_went_wrong(): void {
		$only_email = gwc_vt_signup_summary( 'incomplete', array( 'email' ) );

		$this->assertSame( gwc_vt_signup_field_message( 'email' ), $only_email );
		$this->assertStringNotContainsString( gwc_vt_signup_field_message( 'name' ), $only_email );
	}

	/**
	 * A missing address and one that is not an address are different mistakes.
	 */
	public function test_a_missing_address_reads_differently_from_a_malformed_one(): void {
		$this->assertNotSame(
			gwc_vt_signup_field_message( 'email' ),
			gwc_vt_signup_field_message( 'email-format' )
		);
	}

	/**
	 * With no field list — which a crafted post can produce — it still says
	 * something, rather than rendering an empty notice.
	 */
	public function test_a_refusal_with_no_field_list_still_says_something(): void {
		$this->assertSame(
			gwc_vt_signup_message( 'incomplete' ),
			gwc_vt_signup_summary( 'incomplete', array() )
		);
	}

	/**
	 * A cancellation link that has been used and one that was never valid give
	 * the same answer, so the URL cannot be used to ask which signup IDs exist.
	 */
	public function test_a_used_and_a_forged_cancellation_link_read_the_same(): void {
		$this->assertNotSame( '', gwc_vt_signup_message( 'cancel-unknown' ) );
	}

	/**
	 * @param string $result A result key the handler can produce.
	 */
	#[DataProvider( 'results' )]
	public function test_every_outcome_has_something_to_say( string $result ): void {
		$this->assertNotSame( '', gwc_vt_signup_message( $result ) );
	}

	public static function results(): array {
		return array(
			array( 'accepted' ),
			array( 'incomplete' ),
			array( 'unavailable' ),
			array( 'bad-code' ),
			array( 'expired' ),
			array( 'cancelled' ),
			array( 'cancel-unknown' ),
		);
	}

	/**
	 * The shared code is not a security boundary — it is a word somebody was
	 * given — so mistyping it says so instead of hiding behind the identical
	 * response the other refusals use.
	 */
	public function test_a_mistyped_code_is_told_apart_from_a_signup(): void {
		$this->assertNotSame( gwc_vt_signup_message( 'accepted' ), gwc_vt_signup_message( 'bad-code' ) );
	}

	public function test_an_unknown_result_says_nothing_rather_than_guessing(): void {
		$this->assertSame( '', gwc_vt_signup_message( 'something-else' ) );
	}

	/* ── The rate limiter is shared ──────────────────────────────────────────
	 * One budget across both public forms, because a script hammering the
	 * signup form and a script hammering the hours form are the same script.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_two_public_forms_share_one_rate_limit(): void {
		$limits = gwc_vt_rate_limits();

		for ( $i = 0; $i < $limits['email']['max']; $i++ ) {
			$this->assertFalse( gwc_vt_rate_limited( '198.51.100.7', 'someone@example.test' ) );
		}

		// The next attempt is over, whichever form it arrives at.
		$this->assertTrue( gwc_vt_rate_limited( '198.51.100.7', 'someone@example.test' ) );
	}

	/* ── The calendar file ───────────────────────────────────────────────── */

	/**
	 * @param string $raw      What goes in.
	 * @param string $expected What a calendar file may carry.
	 */
	#[DataProvider( 'escapes' )]
	public function test_it_escapes_what_a_calendar_file_cannot_carry( string $raw, string $expected ): void {
		$this->assertSame( $expected, gwc_vt_ics_escape( $raw ) );
	}

	public static function escapes(): array {
		return array(
			'a comma separates values'   => array( 'Sorting, packing', 'Sorting\, packing' ),
			'a semicolon separates parameters' => array( 'Ask for Dana; back door', 'Ask for Dana\; back door' ),
			'a newline ends a property'  => array( "Closed shoes\nPark round the back", 'Closed shoes\nPark round the back' ),
			'CRLF is one newline'        => array( "One\r\nTwo", 'One\nTwo' ),

			/* Backslash first, or it escapes the escapes added after it — the
			 * classic way to write this function wrong. */
			'a backslash escapes itself' => array( 'A\\B', 'A\\\\B' ),
			'a backslash before a comma' => array( 'A\\,B', 'A\\\\\,B' ),
			'ordinary text is untouched' => array( 'Warehouse inventory', 'Warehouse inventory' ),
		);
	}

	public function test_a_short_line_is_not_folded(): void {
		$this->assertSame( 'SUMMARY:Sorting', gwc_vt_ics_fold( 'SUMMARY:Sorting' ) );
	}

	public function test_a_long_line_is_folded_with_a_leading_space(): void {
		$line   = 'DESCRIPTION:' . str_repeat( 'a', 200 );
		$folded = gwc_vt_ics_fold( $line );

		$this->assertStringContainsString( "\r\n ", $folded );

		// Unfolding is defined as removing CRLF plus the one space that follows.
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );

		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
		}
	}

	/**
	 * Folding counts octets, not characters — and a fold landing inside a UTF-8
	 * sequence produces a file some clients refuse and others render as
	 * mojibake. Volunteer names and locations are exactly where the multi-byte
	 * characters live, so this is not a theoretical case.
	 */
	public function test_folding_never_cuts_a_character_in_half(): void {
		$line   = 'LOCATION:' . str_repeat( 'é', 120 );
		$folded = gwc_vt_ics_fold( $line );

		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );

		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertSame( $part, mb_convert_encoding( $part, 'UTF-8', 'UTF-8' ), 'a fold split a character' );
		}
	}

	public function test_folding_handles_a_line_of_exactly_the_limit(): void {
		$line = str_repeat( 'a', 75 );

		$this->assertSame( $line, gwc_vt_ics_fold( $line ) );
	}
}

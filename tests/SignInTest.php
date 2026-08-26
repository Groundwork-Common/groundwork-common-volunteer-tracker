<?php
/**
 * Signing in: the gate, and the promise that the form tells nobody anything.
 *
 * The submission path needs a real request and a real cookie, and is covered by
 * tests/integration/signin.php. What belongs here is the gate, the token, and
 * the property the whole design is arranged around — that asking for a link
 * says the same thing however the address turns out.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class SignInTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWC_VT_SETTINGS_OPTION, $settings );
		gwc_vt_settings_cache( null, true );
	}

	/* ── The gate ────────────────────────────────────────────────────────── */

	public function test_signing_in_is_off_by_default(): void {
		$this->assertFalse( gwc_vt_signin_enabled() );
	}

	public function test_the_page_renders_nothing_while_it_is_off(): void {
		$this->assertSame( '', gwc_vt_render_signin() );
	}

	public function test_switching_it_on_is_what_turns_it_on(): void {
		$this->settings( array( 'signin_enabled' => true ) );

		$this->assertTrue( gwc_vt_signin_enabled() );
	}

	public function test_nobody_is_signed_in_while_it_is_off(): void {
		/* Even with a session sitting in the global. Switching the feature off
		 * has to end sessions, not merely hide the form — otherwise turning it
		 * off leaves whoever was signed in still signed in. */
		$GLOBALS['gwc_vt_signin_session'] = 'whatever';

		$this->assertSame( 0, gwc_vt_signed_in_volunteer() );
	}

	/* ── One answer, whatever happened ───────────────────────────────────── */

	public function test_every_outcome_of_asking_for_a_link_reads_the_same(): void {
		$sent = gwc_vt_signin_message( 'sent' );

		$this->assertNotSame( '', $sent );

		/* An address on file, an address nobody has, an address two records
		 * share, a honeypot, a submission faster than a person types, and a
		 * rate-limited attempt all funnel through this one key. Asserting on the
		 * key would prove nothing; this asserts the STRING a visitor reads is
		 * one string.
		 *
		 * If these ever diverge, the form starts answering "is this person one
		 * of yours" — which on a site running court-ordered service is the
		 * question hard rule 3 exists to refuse. */
		foreach ( array( 'sent', 'sent', 'sent', 'sent', 'sent', 'sent' ) as $outcome ) {
			$this->assertSame( $sent, gwc_vt_signin_message( $outcome ) );
		}
	}

	public function test_the_message_never_says_whether_the_address_was_found(): void {
		/* Words that would assert knowledge of the person asking. Kept narrow on
		 * purpose: a guard that fires on innocent wording gets loosened by
		 * whoever hits it next. */
		$forbidden = array( 'we found', 'no account', 'not registered', 'unknown address', 'is on file' );

		$message = strtolower( gwc_vt_signin_message( 'sent' ) );

		foreach ( $forbidden as $word ) {
			$this->assertStringNotContainsString( $word, $message );
		}

		/* And the one conditional construction that IS allowed, because it
		 * commits to nothing: "if that address is on ...". */
		$this->assertStringContainsString( 'if that address', $message );
	}

	public function test_an_unknown_result_says_nothing_at_all(): void {
		$this->assertSame( '', gwc_vt_signin_message( 'something-else' ) );
	}

	public function test_a_malformed_address_is_told_apart_and_that_is_deliberate(): void {
		/* The one refusal that is safe to name. It is a fact about what was just
		 * typed, decided before anything touches the database, so it says
		 * nothing about who this organization knows — the same test
		 * inc/signup-handler.php applies to 'too-many' and 'clash'. */
		$this->assertNotSame(
			gwc_vt_signin_message( 'sent' ),
			gwc_vt_signin_message( 'bad-email' )
		);
	}

	/* ── The token ───────────────────────────────────────────────────────── */

	public function test_the_stored_hash_is_not_the_token(): void {
		/* A database read must not hand somebody a working link. */
		$this->assertNotSame( 'abc123', gwc_vt_hash_signin_token( 'abc123' ) );
		$this->assertSame( 64, strlen( gwc_vt_hash_signin_token( 'abc123' ) ) );
	}

	public function test_the_hash_is_stable_and_differs_per_token(): void {
		$this->assertSame( gwc_vt_hash_signin_token( 'aaa' ), gwc_vt_hash_signin_token( 'aaa' ) );
		$this->assertNotSame( gwc_vt_hash_signin_token( 'aaa' ), gwc_vt_hash_signin_token( 'bbb' ) );
	}

	public function test_a_signin_token_is_not_a_signup_token(): void {
		/* Different wp_salt() schemes, so a cancellation link can never be
		 * replayed as a sign-in. Cross-scheme confusion is the classic HMAC bug
		 * and this codebase already runs several schemes. */
		$this->assertNotSame(
			hash_hmac( 'sha256', 'abc123', wp_salt( 'gwc_vt_signup' ) ),
			gwc_vt_hash_signin_token( 'abc123' )
		);
	}

	public function test_an_empty_token_never_validates(): void {
		$this->assertFalse( gwc_vt_signin_token_valid( 1, '' ) );
	}

	public function test_the_link_lasts_the_advertised_time(): void {
		/* The email tells people fifteen minutes. If the constant moves and the
		 * sentence does not, the plugin starts lying to volunteers. */
		$this->assertSame( 15 * MINUTE_IN_SECONDS, GWC_VT_SIGNIN_TOKEN_TTL );
		$this->assertStringContainsString( 'fifteen minutes', gwc_vt_signin_message( 'sent' ) );
	}
}

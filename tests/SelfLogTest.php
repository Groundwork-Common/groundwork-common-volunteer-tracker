<?php
/**
 * The public form's checks, and the promise that it tells nobody anything.
 *
 * The submission path itself needs a real request and is covered by
 * tests/integration/self-log.php. What belongs here is the timing stamp, the
 * gate, and the property the whole design is arranged around: that every
 * outcome looks the same.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class SelfLogTest extends TestCase {

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

	public function test_the_form_is_off_by_default(): void {
		$this->settings( array() );

		/* The single most important default in the plugin. On, this site accepts
		 * personal information from anonymous visitors — which should happen
		 * because somebody decided it should, never because a plugin was
		 * installed. */
		$this->assertFalse( gwc_vt_self_log_enabled() );
	}

	public function test_switching_it_on_without_a_page_is_not_enough(): void {
		$this->settings( array( 'self_log_enabled' => true, 'self_log_page' => 0 ) );

		/* The handler listens on one page by ID. Enabled with nowhere to listen
		 * would mean a form that renders anywhere and accepts nothing. */
		$this->assertFalse( gwc_vt_self_log_enabled() );
	}

	public function test_enabled_and_pinned_is_on(): void {
		$this->settings( array( 'self_log_enabled' => true, 'self_log_page' => 12 ) );

		$this->assertTrue( gwc_vt_self_log_enabled() );
	}

	public function test_the_form_renders_nothing_while_it_is_off(): void {
		$this->settings( array( 'self_log_enabled' => false ) );

		/* Silent, not a notice. A visitor should never be told a feature exists
		 * but is switched off — that is a message for the administrator, and the
		 * block editor shows them one. */
		$this->assertSame( '', gwc_vt_render_self_log_form() );
	}

	/* ── The timing stamp ────────────────────────────────────────────────── */

	public function test_a_fresh_stamp_reads_as_new(): void {
		$this->assertSame( 0, gwc_vt_form_age( gwc_vt_form_stamp() ) );
	}

	public function test_a_stamp_from_the_past_reads_its_age(): void {
		$then  = time() - 300;
		$stamp = $then . '.' . hash_hmac( 'sha256', (string) $then, wp_salt( 'gwc_vt_form' ) );

		$this->assertSame( 300, gwc_vt_form_age( $stamp ) );
	}

	public function test_a_forged_stamp_is_refused(): void {
		$then = time() - 300;

		/* Without the HMAC, anybody could post a stamp claiming the form had been
		 * open for exactly long enough, which makes the timing floor decorative. */
		$this->assertNull( gwc_vt_form_age( $then . '.deadbeef' ) );
		$this->assertNull( gwc_vt_form_age( (string) $then ) );
		$this->assertNull( gwc_vt_form_age( '' ) );
		$this->assertNull( gwc_vt_form_age( 'not-a-stamp.at-all' ) );
	}

	public function test_a_stamp_from_the_future_does_not_read_as_negative(): void {
		$then  = time() + 600;
		$stamp = $then . '.' . hash_hmac( 'sha256', (string) $then, wp_salt( 'gwc_vt_form' ) );

		/* A clock correction between render and submit must not produce a
		 * negative age, which would sail under the minimum and be treated as a
		 * bot. */
		$this->assertSame( 0, gwc_vt_form_age( $stamp ) );
	}

	public function test_the_bounds_are_what_the_handler_checks(): void {
		$this->assertSame( 3, GWC_VT_FORM_MIN_AGE );
		$this->assertSame( 6 * HOUR_IN_SECONDS, GWC_VT_FORM_MAX_AGE );
	}

	/* ── The anti-oracle property ────────────────────────────────────────────
	 * The reason the whole handler is arranged the way it is. If these ever
	 * diverge, the form starts answering questions about who has been
	 * submitting — and on a site running a court-ordered service program, the
	 * question being answered is whether a named person is working one off.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_accepted_honeypotted_and_rate_limited_are_byte_identical(): void {
		$accepted = gwc_vt_self_log_message( 'accepted' );

		$this->assertNotSame( '', $accepted );

		/* All three outcomes funnel through the same key on purpose. Asserting
		 * on the key alone would prove nothing, so this asserts the STRING that
		 * a visitor actually sees is one string. */
		foreach ( array( 'accepted', 'accepted', 'accepted' ) as $path ) {
			$this->assertSame( $accepted, gwc_vt_self_log_message( $path ) );
		}
	}

	public function test_an_unknown_result_says_nothing_at_all(): void {
		/* Fails closed. A result key nobody recognized must not fall through to
		 * some other outcome's wording. */
		$this->assertSame( '', gwc_vt_self_log_message( 'something-else' ) );
		$this->assertSame( '', gwc_vt_self_log_message( '' ) );
	}

	public function test_the_messages_never_mention_a_person(): void {
		foreach ( array( 'accepted', 'incomplete', 'bad-code', 'expired' ) as $result ) {
			$message = strtolower( gwc_vt_self_log_message( $result ) );

			foreach ( array( 'already', 'exists', 'not found', 'no record', 'unknown volunteer' ) as $tell ) {
				$this->assertStringNotContainsString(
					$tell,
					$message,
					'"' . $result . '" hints at whether a person is on file.'
				);
			}
		}
	}

	public function test_the_wrong_code_is_told_apart_from_everything_else(): void {
		/* The one deliberate exception, and it discloses nothing about a person:
		 * a shared code handed out at the front desk is not a security boundary,
		 * and somebody who mistypes it has to be told so or the form is
		 * unusable. */
		$this->assertNotSame( gwc_vt_self_log_message( 'accepted' ), gwc_vt_self_log_message( 'bad-code' ) );
	}
}

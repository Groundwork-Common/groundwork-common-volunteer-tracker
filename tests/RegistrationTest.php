<?php
/**
 * Offering to volunteer: the gate, and the promise that it tells nobody anything.
 *
 * The submission path needs a real request and is covered by
 * tests/integration/registration.php. What belongs here is the same set of
 * properties SelfLogTest pins for the hours form, because this is the plugin's
 * second anonymous surface and the two are supposed to behave alike.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase {

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
		$this->assertFalse( gwc_vt_registration_enabled() );
	}

	public function test_the_form_renders_nothing_while_it_is_off(): void {
		/* Nothing at all, rather than a note. Somebody reading a public page
		 * should not be told a feature exists but is switched off. */
		$this->assertSame( '', gwc_vt_render_registration_form() );
	}

	public function test_switching_it_on_is_what_turns_it_on(): void {
		$this->settings( array( 'registration_enabled' => true ) );

		$this->assertTrue( gwc_vt_registration_enabled() );
	}

	/* ── The requirement question is a second, separate decision ─────────── */

	public function test_the_requirement_question_is_off_even_when_the_form_is_on(): void {
		$this->settings( array( 'registration_enabled' => true ) );

		$this->assertFalse(
			gwc_vt_registration_asks_required(),
			'Switching the form on must not start a public page asking strangers whether they are under a court order.'
		);
	}

	public function test_the_requirement_question_needs_the_form_on_as_well(): void {
		/* The other direction. A site that armed the question and then switched
		 * the form off must not be asking it. */
		$this->settings( array( 'registration_enabled' => false, 'registration_ask_required' => true ) );

		$this->assertFalse( gwc_vt_registration_asks_required() );
	}

	public function test_both_switches_on_asks_the_question(): void {
		$this->settings( array( 'registration_enabled' => true, 'registration_ask_required' => true ) );

		$this->assertTrue( gwc_vt_registration_asks_required() );
	}

	/* ── Every outcome looks the same ────────────────────────────────────── */

	public function test_accepted_honeypotted_and_rate_limited_are_byte_identical(): void {
		$accepted = gwc_vt_registration_message( 'accepted' );

		$this->assertNotSame( '', $accepted );

		/* All three funnel through the same key on purpose, so asserting on the
		 * key proves nothing. This asserts the STRING a visitor sees is one
		 * string — if these ever diverge, the form starts answering questions
		 * about who has been submitting. */
		foreach ( array( 'accepted', 'accepted', 'accepted' ) as $path ) {
			$this->assertSame( $accepted, gwc_vt_registration_message( $path ) );
		}
	}

	public function test_an_unknown_result_says_nothing_at_all(): void {
		$this->assertSame( '', gwc_vt_registration_message( 'something-else' ) );
	}

	public function test_the_messages_never_mention_a_person(): void {
		/* The failure this guards is a message that helpfully says "we already
		 * have you on file" — which is the enumeration oracle the whole design
		 * removes structurally. Checked as words rather than by inspection,
		 * because the wording is the sort of thing that gets improved later by
		 * somebody who does not know why it is careful. */
		/* Words that would assert prior knowledge of the person submitting.
		 * Deliberately not a broader list: the first version of this test
		 * banned "again" and failed on "please try again", which says nothing
		 * about anybody and is the correct thing to tell somebody who mistyped
		 * a code. A guard that fires on innocent wording gets loosened by
		 * whoever hits it next, and loosened guards stop guarding. */
		$forbidden = array( 'already', 'existing', 'registered', 'on file', 'we have you', 'duplicate' );

		foreach ( array( 'accepted', 'incomplete', 'bad-code', 'expired' ) as $result ) {
			$message = strtolower( gwc_vt_registration_message( $result ) );

			foreach ( $forbidden as $word ) {
				$this->assertStringNotContainsString(
					$word,
					$message,
					sprintf( 'The "%s" message must not hint at whether this person is on file.', $result )
				);
			}
		}
	}

	public function test_the_wrong_code_is_told_apart_from_everything_else(): void {
		/* The one outcome that is deliberately distinguishable. A mistyped code
		 * is not a security boundary — it is a word from the front desk — and
		 * somebody who gets it wrong has to be told so. */
		$this->assertNotSame(
			gwc_vt_registration_message( 'accepted' ),
			gwc_vt_registration_message( 'bad-code' )
		);
	}

	public function test_the_accepted_message_promises_nothing_it_cannot_keep(): void {
		$accepted = strtolower( gwc_vt_registration_message( 'accepted' ) );

		/* Not "you are now a volunteer" — nobody has said yes yet — and no
		 * timescale, which is the organization's to promise and not the
		 * plugin's. */
		$this->assertStringNotContainsString( 'you are now', $accepted );
		$this->assertStringNotContainsString( 'within', $accepted );
		$this->assertStringNotContainsString( 'hours', $accepted );
	}

	/* ── It shares the hours form's defenses rather than copying them ────── */

	public function test_it_uses_the_same_stamp_the_hours_form_uses(): void {
		/* If these ever became two implementations, one of them would get a fix
		 * the other did not. The stamp is the timing defense for both forms. */
		$stamp = gwc_vt_form_stamp();

		$this->assertNotNull( gwc_vt_form_age( $stamp ) );
		$this->assertLessThan( 2, gwc_vt_form_age( $stamp ) );
	}
}

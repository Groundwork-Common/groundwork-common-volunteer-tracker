<?php
/**
 * Credentials: the arithmetic, and the words.
 *
 * Whether somebody holds one needs real posts and is covered by
 * tests/integration/credentials.php. What belongs here is expiry — which is
 * pure, and which is the part most likely to be wrong in a way nobody notices
 * until a volunteer is turned away two days early.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CredentialTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/* ── Expiry ──────────────────────────────────────────────────────────── */

	public function test_a_credential_that_never_expires_has_no_expiry(): void {
		$this->assertSame( '', gwc_vt_credential_expires_on( '2024-03-04', 0 ) );
	}

	public function test_an_unreadable_date_has_no_expiry(): void {
		$this->assertSame( '', gwc_vt_credential_expires_on( '', 12 ) );
		$this->assertSame( '', gwc_vt_credential_expires_on( 'whenever', 12 ) );
	}

	public function test_the_ordinary_case(): void {
		$this->assertSame( '2025-03-04', gwc_vt_credential_expires_on( '2024-03-04', 12 ) );
		$this->assertSame( '2026-03-04', gwc_vt_credential_expires_on( '2024-03-04', 24 ) );
		$this->assertSame( '2024-04-04', gwc_vt_credential_expires_on( '2024-03-04', 1 ) );
	}

	/**
	 * The end of the month, which PHP gets wrong on its own.
	 *
	 * strtotime( '+1 month', 31 January ) is 2 or 3 March, because it adds one
	 * to the month number and then normalises the overflow. Somebody who did
	 * their course on the 31st would be told it ran out days early, every
	 * renewal, and nothing on screen would explain it.
	 *
	 * @param string $granted Y-m-d.
	 * @param int    $months  Interval.
	 * @param string $expect  Y-m-d.
	 */
	#[DataProvider( 'monthEnds' )]
	public function test_the_end_of_the_month_is_clamped( string $granted, int $months, string $expect ): void {
		$this->assertSame( $expect, gwc_vt_credential_expires_on( $granted, $months ) );
	}

	/**
	 * @return array<string, array{0:string, 1:int, 2:string}>
	 */
	public static function monthEnds(): array {
		return array(
			'31 Jan + 1 month lands on the last of February' => array( '2025-01-31', 1, '2025-02-28' ),
			'and in a leap year on the 29th'                 => array( '2024-01-31', 1, '2024-02-29' ),
			'31 Jan + 12 months is still the 31st'           => array( '2024-01-31', 12, '2025-01-31' ),
			'31 May + 1 month is 30 June, not 1 July'        => array( '2024-05-31', 1, '2024-06-30' ),
			'29 Feb + 12 months clamps to the 28th'          => array( '2024-02-29', 12, '2025-02-28' ),
			'30 Nov + 3 months is 28 February'               => array( '2024-11-30', 3, '2025-02-28' ),
		);
	}

	public function test_php_would_have_got_that_wrong(): void {
		/* Kept as a test rather than a comment so that anybody who "simplifies"
		 * gwc_vt_credential_expires_on() into a strtotime call is told why not.
		 * This asserts the naive answer is different, not that ours is right —
		 * the provider above does that. */
		$naive = gmdate( 'Y-m-d', strtotime( '2025-01-31 +1 month' ) );

		$this->assertNotSame(
			$naive,
			gwc_vt_credential_expires_on( '2025-01-31', 1 ),
			'If these agree, PHP has changed and this guard can go.'
		);
		$this->assertSame( '2025-03-03', $naive );
	}

	/* ── Modes ───────────────────────────────────────────────────────────── */

	public function test_the_two_modes_are_what_the_feature_promises(): void {
		$this->assertSame( array( 'report', 'block' ), array_keys( gwc_vt_credential_modes() ) );
	}

	public function test_the_modes_are_a_function_not_a_const(): void {
		/* A const is evaluated at include time, which freezes the labels in
		 * English for the request — invisible on an English site and total on
		 * every other one. The trap is recorded in CLAUDE.md. */
		$this->assertIsCallable( 'gwc_vt_credential_modes' );

		foreach ( gwc_vt_credential_modes() as $label ) {
			$this->assertNotSame( '', $label );
		}
	}

	/* ── The word ────────────────────────────────────────────────────────── */

	public function test_no_credential_code_uses_the_word_requirement(): void {
		/* "Requirement" means required service HOURS in this plugin and has
		 * since 0.12.0. A codebase where it means both is one where somebody
		 * eventually writes the wrong one onto a letter. Checked as source text
		 * so it cannot drift back in.
		 *
		 * The same shape RequiredTest uses to keep the requirement out of the
		 * letter. */
		foreach ( array( 'inc/credential-cpt.php', 'inc/credentials.php' ) as $file ) {
			$source = (string) file_get_contents( GWC_VT_DIR . $file );

			/* The prose explaining WHY the word is avoided is allowed to name
			 * it; code is not. Comments are stripped rather than exempted by
			 * hand, so a new comment does not need remembering. */
			$code = (string) preg_replace( '!/\*.*?\*/!s', '', $source );
			$code = (string) preg_replace( '!//[^\n]*!', '', $code );

			$this->assertStringNotContainsStringIgnoringCase(
				'requirement',
				$code,
				$file . ' uses a word that already means something else here.'
			);
		}
	}

	/* ── The date somebody types ─────────────────────────────────────────── */

	public function test_a_usable_date_comes_back_unchanged(): void {
		$this->assertSame( '2026-03-04', gwc_vt_usable_date( '2026-03-04', '2026-08-26' ) );
	}

	public function test_today_is_usable(): void {
		$this->assertSame( '2026-08-26', gwc_vt_usable_date( '2026-08-26', '2026-08-26' ) );
	}

	public function test_a_date_in_the_future_is_refused_rather_than_clamped(): void {
		/* Refused, not moved to today. A silent correction on save is a bug even
		 * when the correction is right — and here it would be wrong as well,
		 * since expiry counts from this date. */
		$this->assertSame( '', gwc_vt_usable_date( '2026-08-27', '2026-08-26' ) );
	}

	public function test_a_day_that_does_not_exist_is_refused(): void {
		/* checkdate, not strtotime. strtotime reads this as 3 March. */
		$this->assertSame( '', gwc_vt_usable_date( '2026-02-31', '2026-08-26' ) );
	}

	public function test_a_date_of_the_wrong_shape_is_refused(): void {
		$this->assertSame( '', gwc_vt_usable_date( '4 March 2026', '2026-08-26' ) );
		$this->assertSame( '', gwc_vt_usable_date( '2026-3-4', '2026-08-26' ) );
		$this->assertSame( '', gwc_vt_usable_date( '', '2026-08-26' ) );
	}

	/* ── The volunteer list filter ───────────────────────────────────────── */

	public function test_an_empty_state_asks_for_nothing(): void {
		$this->assertSame( array(), gwc_vt_credential_query_vars( '', array( 4, 5 ) ) );
	}

	public function test_lapsed_asks_for_the_volunteers_it_was_given(): void {
		$this->assertSame(
			array( 'post__in' => array( 4, 5 ) ),
			gwc_vt_credential_query_vars( 'lapsed', array( 4, 5 ) )
		);
	}

	public function test_an_empty_lapsed_set_shows_nobody_rather_than_everybody(): void {
		/* post__in with an empty array is IGNORED by WP_Query, so the screen
		 * would list every volunteer on the site under a heading saying these
		 * are the ones whose credentials have lapsed. */
		$this->assertSame(
			array( 'post__in' => array( 0 ) ),
			gwc_vt_credential_query_vars( 'lapsed', array() )
		);
	}

	/* ── The dashboard line ──────────────────────────────────────────────── */

	public function test_the_lapsed_line_sits_between_overdue_and_unverified(): void {
		$keys = array();

		foreach ( gwc_vt_dashboard_items(
			array(
				'unreconciled' => 1,
				'understaffed' => 1,
				'overdue'      => 1,
				'lapsed'       => 1,
				'unverified'   => 1,
				'unmatched'    => 1,
			)
		) as $item ) {
			$keys[] = $item['key'];
		}

		$this->assertSame(
			array( 'unreconciled', 'understaffed', 'overdue', 'lapsed', 'unverified', 'unmatched' ),
			$keys
		);
	}

	public function test_no_lapsed_line_when_nothing_has_lapsed(): void {
		$keys = array();

		foreach ( gwc_vt_dashboard_items( array( 'lapsed' => 0, 'unverified' => 2 ) ) as $item ) {
			$keys[] = $item['key'];
		}

		$this->assertSame( array( 'unverified' ), $keys );
	}

	public function test_the_lapsed_line_reads_as_a_job_in_both_forms(): void {
		$one  = gwc_vt_dashboard_items( array( 'lapsed' => 1 ) );
		$many = gwc_vt_dashboard_items( array( 'lapsed' => 4 ) );

		$this->assertSame( 'Renew a credential that has lapsed', $one[0]['what'] );
		$this->assertSame( 'Renew credentials that have lapsed', $many[0]['what'] );
	}
}

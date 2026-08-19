<?php
/**
 * Reading what a coordinator typed, and adding it up without drifting.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoursTest extends TestCase {

	/* ── Asking what was typed, before rounding ──────────────────────────────
	 * The entry editor needs both figures to say "you typed 3:07, we recorded
	 * 3.0". Rounding is right and stays the default; doing it in silence on the
	 * number a court reads is what was wrong.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_parser_can_be_asked_not_to_round(): void {
		gwc_vt_test_reset();
		update_option( GWC_VT_SETTINGS_OPTION, array( 'hour_increment' => 15 ) );
		gwc_vt_settings_cache( null, true );

		$this->assertSame( 187, gwc_vt_parse_hours( '3:07', false ) );
		$this->assertSame( 180, gwc_vt_parse_hours( '3:07' ) );
	}

	public function test_not_rounding_still_refuses_what_rounding_refuses(): void {
		gwc_vt_test_reset();

		// A bare number is hours, so 210 is longer than a day either way.
		$this->assertNull( gwc_vt_parse_hours( '210', false ) );
		$this->assertNull( gwc_vt_parse_hours( 'nonsense', false ) );
		$this->assertSame( 210, gwc_vt_parse_hours( '210m', false ) );
	}

	public function test_an_exact_value_is_unchanged_by_rounding(): void {
		gwc_vt_test_reset();
		update_option( GWC_VT_SETTINGS_OPTION, array( 'hour_increment' => 15 ) );
		gwc_vt_settings_cache( null, true );

		// Nothing to report when the two agree — this is the common case.
		$this->assertSame( gwc_vt_parse_hours( '3:30', false ), gwc_vt_parse_hours( '3:30' ) );
	}

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Set settings and clear the memo.
	 *
	 * The memo is normally invalidated by the update_option_* action, which is a
	 * no-op in this bootstrap — so a test that only called update_option() would
	 * read the values from before its own write and pass or fail for reasons
	 * that have nothing to do with what it is testing.
	 *
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWC_VT_SETTINGS_OPTION, $settings );
		gwc_vt_settings_cache( null, true );
	}

	/* ── Parsing ─────────────────────────────────────────────────────────── */

	/**
	 * @param string   $typed    What somebody typed.
	 * @param int|null $expected Minutes, or null if it should be refused.
	 */
	#[DataProvider( 'durations' )]
	public function test_it_reads_the_five_things_people_actually_type( string $typed, ?int $expected ): void {
		$this->settings( array( 'hour_increment' => 0 ) );

		$this->assertSame( $expected, gwc_vt_parse_hours( $typed ), 'Input: ' . var_export( $typed, true ) );
	}

	public static function durations(): array {
		return array(
			'decimal hours'          => array( '3.5', 210 ),
			'clock'                  => array( '3:30', 210 ),
			'spaced h and m'         => array( '3h 30m', 210 ),
			'unspaced h and m'       => array( '3h30m', 210 ),
			'hours spelled out'      => array( '3 hours', 180 ),
			'minutes spelled out'    => array( '30 minutes', 30 ),
			'minutes only'           => array( '210m', 210 ),
			'bare number is hours'   => array( '3', 180 ),
			'leading dot'            => array( '.5', 30 ),
			'zero'                   => array( '0', 0 ),
			'whitespace'             => array( '  3.5  ', 210 ),
			'uppercase'              => array( '3H 30M', 210 ),
			'a full day is allowed'  => array( '24', 1440 ),

			/* The refusals. A bare number over the cap is somebody typing
			 * minutes into a field that reads bare numbers as hours — see the
			 * box comment in inc/settings.php for why this is refused rather
			 * than guessed at. */
			'more than a day'        => array( '25', null ),
			'minutes over the cap'   => array( '1500m', null ),
			'negative'               => array( '-1', null ),
			'negative decimal'       => array( '-0.5', null ),
			'words'                  => array( 'abc', null ),
			'empty'                  => array( '', null ),
			'only whitespace'        => array( '   ', null ),
			'nonsense with a number' => array( '3 potatoes', null ),
			'bad clock'              => array( '3:75', null ),
		);
	}

	/* ── Rounding ────────────────────────────────────────────────────────── */

	public function test_it_rounds_to_the_configured_increment(): void {
		$this->settings( array( 'hour_increment' => 15 ) );

		$this->assertSame( 180, gwc_vt_parse_hours( '3.1' ), '186 minutes is nearer 180 than 195.' );
		$this->assertSame( 195, gwc_vt_parse_hours( '3.2' ), '192 minutes is nearer 195 than 180.' );
		$this->assertSame( 210, gwc_vt_parse_hours( '3.5' ) );
	}

	public function test_it_rounds_to_the_nearest_and_never_up(): void {
		$this->settings( array( 'hour_increment' => 15 ) );

		/* Rounding up would be the organisation systematically crediting hours
		 * nobody worked, which on this document is the one direction of error
		 * that matters. One minute past the hour is the hour. */
		$this->assertSame( 180, gwc_vt_parse_hours( '3h 1m' ) );
		$this->assertSame( 180, gwc_vt_parse_hours( '3h 7m' ) );
		$this->assertSame( 195, gwc_vt_parse_hours( '3h 8m' ) );
	}

	public function test_a_zero_increment_switches_rounding_off(): void {
		$this->settings( array( 'hour_increment' => 0 ) );

		$this->assertSame( 187, gwc_vt_parse_hours( '3h 7m' ) );
	}

	public function test_the_increment_is_filterable(): void {
		$this->settings( array( 'hour_increment' => 15 ) );

		$sixths = static fn() => 10;
		add_filter( 'gwc_vt_hour_increment', $sixths );

		$this->assertSame( 190, gwc_vt_parse_hours( '3h 7m' ), '187 minutes rounds to 190 in tens.' );

		remove_filter( 'gwc_vt_hour_increment', $sixths );
	}

	/* ── Formatting ──────────────────────────────────────────────────────── */

	public function test_decimal_formatting_does_not_pad(): void {
		/* "3.00 hours" on a letter looks machine-generated in a way that invites
		 * exactly the doubt this document exists to prevent. */
		$this->assertSame( '3', gwc_vt_format_hours( 180, 'decimal' ) );
		$this->assertSame( '3.5', gwc_vt_format_hours( 210, 'decimal' ) );
		$this->assertSame( '3.25', gwc_vt_format_hours( 195, 'decimal' ) );
		$this->assertSame( '0', gwc_vt_format_hours( 0, 'decimal' ) );
	}

	public function test_hours_and_minutes_formatting_drops_the_empty_half(): void {
		$this->assertSame( '3h 30m', gwc_vt_format_hours( 210, 'hm' ) );
		$this->assertSame( '3h', gwc_vt_format_hours( 180, 'hm' ) );
		$this->assertSame( '30m', gwc_vt_format_hours( 30, 'hm' ) );
		$this->assertSame( '0m', gwc_vt_format_hours( 0, 'hm' ) );
	}

	public function test_formatting_follows_the_setting_when_not_told_otherwise(): void {
		$this->settings( array( 'hour_format' => 'hm' ) );
		$this->assertSame( '3h 30m', gwc_vt_format_hours( 210 ) );

		$this->settings( array( 'hour_format' => 'decimal' ) );
		$this->assertSame( '3.5', gwc_vt_format_hours( 210 ) );
	}

	public function test_a_formatted_duration_parses_back_to_itself(): void {
		$this->settings( array( 'hour_increment' => 15 ) );

		foreach ( array( 15, 30, 45, 60, 90, 195, 210, 480, 1440 ) as $minutes ) {
			foreach ( array( 'decimal', 'hm' ) as $format ) {
				$this->assertSame(
					$minutes,
					gwc_vt_parse_hours( gwc_vt_format_hours( $minutes, $format ) ),
					$minutes . ' minutes did not survive a round trip through ' . $format
				);
			}
		}
	}

	/* ── Why minutes ─────────────────────────────────────────────────────── */

	/**
	 * The argument for integer minutes, made executable.
	 *
	 * A thousand six-minute shifts is exactly one hundred hours. Summed as
	 * integers it is exactly 6000 minutes. Summed as decimal hours it is not
	 * exactly 100, and the letter would print the difference.
	 *
	 * The second assertion is the one that matters: it fails if PHP ever starts
	 * summing these floats exactly, at which point this test should be deleted
	 * rather than "fixed", because its whole job is to record why the storage
	 * format is what it is.
	 */
	public function test_integer_minutes_sum_exactly_where_decimal_hours_do_not(): void {
		$minutes = 0;
		$hours   = 0.0;

		for ( $i = 0; $i < 1000; $i++ ) {
			$minutes += 6;
			$hours   += 0.1;
		}

		$this->assertSame( 6000, $minutes );
		$this->assertSame( '100', gwc_vt_format_hours( $minutes, 'decimal' ) );

		$this->assertNotSame(
			100.0,
			$hours,
			'Decimal hours summed exactly here. If that is now true of PHP, delete this test rather than adjusting it.'
		);
	}
}

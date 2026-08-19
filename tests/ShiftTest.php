<?php
/**
 * A shift's times: reading them, measuring them, and turning them into instants.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/* ── Reading a time of day ───────────────────────────────────────────── */

	/**
	 * @param string $raw      What was posted.
	 * @param string $expected What should be stored.
	 */
	#[DataProvider( 'times' )]
	public function test_it_only_stores_a_time_it_can_read_back( string $raw, string $expected ): void {
		$this->assertSame( $expected, gwc_vt_sanitize_time( $raw ) );
	}

	public static function times(): array {
		return array(
			'a padded morning'      => array( '09:00', '09:00' ),
			'the last minute'       => array( '23:59', '23:59' ),
			'midnight'              => array( '00:00', '00:00' ),
			'surrounding space'     => array( '  14:30  ', '14:30' ),
			'unpadded is refused'   => array( '9:00', '' ),
			'a 24th hour'           => array( '24:00', '' ),
			'a 60th minute'         => array( '12:60', '' ),
			'seconds are not asked for' => array( '09:00:00', '' ),
			'nothing'               => array( '', '' ),
			'words'                 => array( 'morning', '' ),
		);
	}

	/* ── How long a shift is ─────────────────────────────────────────────── */

	/**
	 * @param string $start    Start time.
	 * @param string $end      End time.
	 * @param bool   $next_day Whether the end is on the following day.
	 * @param int    $expected Minutes.
	 */
	#[DataProvider( 'durations' )]
	public function test_it_measures_a_shift_in_minutes( string $start, string $end, bool $next_day, int $expected ): void {
		$this->assertSame( $expected, gwc_vt_shift_duration( $start, $end, $next_day ) );
	}

	public static function durations(): array {
		return array(
			'a morning'                => array( '09:00', '12:00', false, 180 ),
			'a half hour'              => array( '09:00', '09:30', false, 30 ),
			'an overnight at a shelter' => array( '22:00', '06:00', true, 480 ),
			'a full day overnight'     => array( '00:00', '00:00', true, 1440 ),

			/* Backwards is a typo, not a twenty-three hour shift. The overnight
			 * case is the explicit flag, never an inference from the numbers. */
			'ending before it starts'  => array( '12:00', '09:00', false, 0 ),
			'zero length'              => array( '09:00', '09:00', false, 0 ),

			// An entry is one shift, and a shift is not longer than a day.
			'longer than a day'        => array( '00:00', '00:01', true, 0 ),

			'an unreadable start'      => array( '9am', '12:00', false, 0 ),
			'a missing end'            => array( '09:00', '', false, 0 ),
		);
	}

	/**
	 * The longest shift is the longest entry, because this number becomes an
	 * entry's minutes the moment somebody reconciles the roster.
	 */
	public function test_the_longest_shift_is_the_longest_entry(): void {
		$this->assertSame( GWC_VT_MAX_ENTRY_MINUTES, gwc_vt_shift_duration( '00:00', '00:00', true ) );
	}

	/* ── Wall time, and the instant derived from it ──────────────────────────
	 * The reason a shift stores '09:00' rather than a timestamp. Both of these
	 * shifts are at nine in the morning to everybody involved; they are an hour
	 * apart in real time because the clocks changed between them. Storing the
	 * instant would have moved one of them.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * US daylight saving begins on 8 March 2026, so these two Saturdays sit
	 * either side of it.
	 */
	public function test_the_same_wall_time_survives_a_daylight_saving_change(): void {
		$zone = new DateTimeZone( 'America/New_York' );

		$before = gwc_vt_shift_instant_at( '2026-03-07', '09:00', $zone );
		$after  = gwc_vt_shift_instant_at( '2026-03-14', '09:00', $zone );

		$this->assertNotNull( $before );
		$this->assertNotNull( $after );

		// Nine o'clock on the noticeboard, both weeks.
		$this->assertSame( '09:00', $before->format( 'H:i' ) );
		$this->assertSame( '09:00', $after->format( 'H:i' ) );

		// And a different offset from UTC, which is the whole point.
		$this->assertSame( '-05:00', $before->format( 'P' ) );
		$this->assertSame( '-04:00', $after->format( 'P' ) );

		/* Seven calendar days apart, and 167 real hours apart. A record that
		 * stored the instant and added a week would have produced 168 and moved
		 * the shift to ten in the morning. */
		$this->assertSame(
			167 * 3600,
			$after->getTimestamp() - $before->getTimestamp()
		);
	}

	/**
	 * The zone is an argument rather than a reach for the site's, so this
	 * conversion can be tested against a zone that actually observes the change.
	 */
	public function test_the_instant_reflects_the_zone_it_was_given(): void {
		$utc      = gwc_vt_shift_instant_at( '2026-06-01', '09:00', new DateTimeZone( 'UTC' ) );
		$new_york = gwc_vt_shift_instant_at( '2026-06-01', '09:00', new DateTimeZone( 'America/New_York' ) );

		$this->assertNotNull( $utc );
		$this->assertNotNull( $new_york );
		$this->assertSame( 4 * 3600, $new_york->getTimestamp() - $utc->getTimestamp() );
	}

	/**
	 * @param string $date A date.
	 * @param string $time A time.
	 */
	#[DataProvider( 'unreadable_instants' )]
	public function test_it_refuses_an_instant_it_cannot_build( string $date, string $time ): void {
		$this->assertNull( gwc_vt_shift_instant_at( $date, $time, new DateTimeZone( 'UTC' ) ) );
	}

	public static function unreadable_instants(): array {
		return array(
			'a day that does not exist' => array( '2026-02-31', '09:00' ),
			'no date'                   => array( '', '09:00' ),
			'no time'                   => array( '2026-03-07', '' ),
			'an unreadable time'        => array( '2026-03-07', '9am' ),
		);
	}

	/**
	 * Seconds are zeroed rather than inherited from the current clock — the '!'
	 * in the format string. Without it, a shift's start would drift by up to a
	 * minute depending on when the page was rendered.
	 */
	public function test_the_instant_starts_on_the_minute(): void {
		$instant = gwc_vt_shift_instant_at( '2026-03-07', '09:00', new DateTimeZone( 'UTC' ) );

		$this->assertNotNull( $instant );
		$this->assertSame( '2026-03-07 09:00:00', $instant->format( 'Y-m-d H:i:s' ) );
	}
}

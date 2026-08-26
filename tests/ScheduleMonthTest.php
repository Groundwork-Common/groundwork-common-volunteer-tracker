<?php
/**
 * The month calendar's arithmetic: which cells a month has, and which month is next.
 *
 * All of it is date arithmetic over strings, so none of it needs a database.
 * What the grid is FILLED with is a query, and that is covered by
 * tests/integration/schedule-month.php.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleMonthTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Every cell in a grid, flattened.
	 *
	 * @param array[] $weeks From gwc_vt_month_grid().
	 * @return array[]
	 */
	private function cells( array $weeks ): array {
		return array_merge( ...$weeks );
	}

	/* ── Whole weeks, and only the weeks the month needs ─────────────────── */

	public function test_a_month_is_whole_weeks(): void {
		foreach ( array( '2026-01', '2026-02', '2026-08', '2027-05' ) as $month ) {
			foreach ( range( 0, 6 ) as $start ) {
				$weeks = gwc_vt_month_grid( $month, $start, '2026-08-25' );

				foreach ( $weeks as $week ) {
					$this->assertCount( 7, $week, $month . ' start ' . $start );
				}
			}
		}
	}

	/**
	 * Five weeks for a month that fits in five, six only when it needs six.
	 *
	 * A trailing row of nothing but greyed-out numbers is a row of the screen
	 * spent saying that August is over.
	 */
	public function test_it_uses_only_the_weeks_the_month_needs(): void {
		/* February 2027 is 28 days starting on a Monday: exactly four weeks
		 * when the week starts on Monday. */
		$this->assertCount( 4, gwc_vt_month_grid( '2027-02', 1, '2027-02-01' ) );

		/* August 2026 is 31 days starting on a Saturday. Monday-first, that is
		 * five leading cells plus 31, which needs six rows. */
		$this->assertCount( 6, gwc_vt_month_grid( '2026-08', 1, '2026-08-25' ) );
	}

	/**
	 * Every day of the month is in the grid, exactly once.
	 *
	 * The assertion that a calendar is a calendar. A month with a day missing,
	 * or a day drawn twice, is a screen that miscounts the shifts on it.
	 */
	public function test_every_day_of_the_month_appears_once(): void {
		foreach ( array( '2026-02', '2026-08', '2028-02' ) as $month ) {
			foreach ( range( 0, 6 ) as $start ) {
				$inside = array_values(
					array_filter(
						$this->cells( gwc_vt_month_grid( $month, $start, '2026-08-25' ) ),
						static function ( array $day ): bool {
							return $day['in_month'];
						}
					)
				);

				$dates = array_column( $inside, 'date' );
				$days  = (int) gmdate( 't', (int) strtotime( $month . '-01 00:00:00 UTC' ) );

				$this->assertCount( $days, $dates, $month . ' start ' . $start );
				$this->assertSame( $dates, array_unique( $dates ), $month . ' start ' . $start );
			}
		}
	}

	/**
	 * The cells run consecutively, with no gap and no repeat.
	 */
	public function test_the_cells_are_consecutive_days(): void {
		$cells = $this->cells( gwc_vt_month_grid( '2026-08', 1, '2026-08-25' ) );

		foreach ( $cells as $index => $cell ) {
			if ( 0 === $index ) {
				continue;
			}

			$expected = gmdate(
				'Y-m-d',
				(int) strtotime( $cells[ $index - 1 ]['date'] . ' 00:00:00 UTC' ) + DAY_IN_SECONDS
			);

			$this->assertSame( $expected, $cell['date'] );
		}
	}

	/* ── The columns start on the site's own first day ───────────────────── */

	/**
	 * @param int    $start_of_week As WordPress stores it, 0 for Sunday.
	 * @param string $expected      The first cell's date.
	 */
	#[DataProvider( 'provide_week_starts' )]
	public function test_the_grid_opens_on_the_sites_own_first_day( int $start_of_week, string $expected ): void {
		$weeks = gwc_vt_month_grid( '2026-08', $start_of_week, '2026-08-25' );

		$this->assertSame( $expected, $weeks[0][0]['date'] );
	}

	/**
	 * 1 August 2026 is a Saturday, so every setting has a different answer and
	 * none of them can pass by accident.
	 *
	 * @return array<string, array{0:int, 1:string}>
	 */
	public static function provide_week_starts(): array {
		return array(
			'Sunday'   => array( 0, '2026-07-26' ),
			'Monday'   => array( 1, '2026-07-27' ),
			'Saturday' => array( 6, '2026-08-01' ),
		);
	}

	/**
	 * The weekday names rotate with it.
	 */
	public function test_the_weekday_header_rotates_with_the_first_day(): void {
		$sunday = gwc_vt_weekday_initials( 0 );
		$monday = gwc_vt_weekday_initials( 1 );

		$this->assertCount( 7, $sunday );
		$this->assertCount( 7, $monday );

		/* Monday-first is Sunday-first rotated by one: the same seven names, in
		 * a different order, and Sunday's first name is Monday's last. */
		$this->assertSame( $sunday[1], $monday[0] );
		$this->assertSame( $sunday[0], $monday[6] );
	}

	public function test_an_impossible_first_day_is_wrapped(): void {
		$sane = gwc_vt_month_grid( '2026-08', 1, '2026-08-25' );

		$this->assertSame( $sane, gwc_vt_month_grid( '2026-08', 8, '2026-08-25' ) );
		$this->assertSame( $sane, gwc_vt_month_grid( '2026-08', -6, '2026-08-25' ) );
	}

	/* ── Today ───────────────────────────────────────────────────────────── */

	public function test_today_is_one_cell_when_it_is_in_the_month(): void {
		$today = array_filter(
			$this->cells( gwc_vt_month_grid( '2026-08', 1, '2026-08-25' ) ),
			static function ( array $day ): bool {
				return $day['today'];
			}
		);

		$this->assertCount( 1, $today );
	}

	/**
	 * And no cell when it is not.
	 *
	 * Somebody navigating to next March must not find a day of it wearing
	 * today's pill.
	 */
	public function test_no_cell_is_today_in_another_month(): void {
		$today = array_filter(
			$this->cells( gwc_vt_month_grid( '2027-03', 1, '2026-08-25' ) ),
			static function ( array $day ): bool {
				return $day['today'];
			}
		);

		$this->assertCount( 0, $today );
	}

	/**
	 * A leading cell can be today. It is still today, and still not in the month.
	 */
	public function test_a_neighbouring_month_can_hold_today(): void {
		/* Monday-first, September 2026 opens on Monday 31 August. */
		$weeks = gwc_vt_month_grid( '2026-09', 1, '2026-08-31' );

		$this->assertSame( '2026-08-31', $weeks[0][0]['date'] );
		$this->assertTrue( $weeks[0][0]['today'] );
		$this->assertFalse( $weeks[0][0]['in_month'] );
	}

	/* ── Stepping between months ─────────────────────────────────────────── */

	/**
	 * @param string $from Y-m.
	 * @param int    $step -1 or 1.
	 * @param string $to   Y-m.
	 */
	#[DataProvider( 'provide_steps' )]
	public function test_it_steps_a_month_at_a_time( string $from, int $step, string $to ): void {
		$this->assertSame( $to, gwc_vt_month_step( $from, $step ) );
	}

	/**
	 * The year boundaries both ways, and the trap: stepping forward from a
	 * 31-day month. PHP's own "+1 month" on 31 January is 3 March, which is why
	 * the step anchors to the first before adding.
	 *
	 * @return array<string, array{0:string, 1:int, 2:string}>
	 */
	public static function provide_steps(): array {
		return array(
			'forward'            => array( '2026-08', 1, '2026-09' ),
			'back'               => array( '2026-08', -1, '2026-07' ),
			'over new year'      => array( '2026-12', 1, '2027-01' ),
			'back over new year' => array( '2027-01', -1, '2026-12' ),
			'out of a long month' => array( '2026-01', 1, '2026-02' ),
			'into a long month'  => array( '2026-02', 1, '2026-03' ),
			'back into February' => array( '2026-03', -1, '2026-02' ),
		);
	}

	/**
	 * Stepping there and back is where you started.
	 */
	public function test_stepping_both_ways_is_a_round_trip(): void {
		foreach ( array( '2026-01', '2026-02', '2026-08', '2026-12' ) as $month ) {
			$this->assertSame( $month, gwc_vt_month_step( gwc_vt_month_step( $month, 1 ), -1 ), $month );
			$this->assertSame( $month, gwc_vt_month_step( gwc_vt_month_step( $month, -1 ), 1 ), $month );
		}
	}

	public function test_a_month_it_cannot_read_does_not_move(): void {
		$this->assertSame( 'nonsense', gwc_vt_month_step( 'nonsense', 1 ) );
	}

	public function test_a_month_it_cannot_read_still_produces_a_grid(): void {
		$weeks = gwc_vt_month_grid( 'nonsense', 1, '2026-08-25' );

		$this->assertNotSame( array(), $weeks );
		$this->assertCount( 7, $weeks[0] );

		/* And it falls back to the month it was told today is in, not to 1970. */
		$this->assertStringStartsWith( '2026-0', $weeks[0][0]['date'] );
	}

	/* ── The epoch trap ──────────────────────────────────────────────────────
	 * `strtotime( … ) ?: time()` reads as "or now" and is not: strtotime()
	 * returns false for an unreadable date, and 0 is a date it reads perfectly
	 * well — 1 January 1970. A calendar that silently opened on 1970 because
	 * somebody typed a bad date into the query string gets reported as "the
	 * calendar is empty".
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_epoch_is_a_date_not_a_failure(): void {
		$this->assertSame(
			0,
			gwc_vt_midnight_utc( '1970-01-01' ),
			'midnight on the epoch is a real answer and must survive'
		);
	}

	public function test_an_unreadable_date_is_now(): void {
		$before = time();
		$got    = gwc_vt_midnight_utc( 'the day after tomorrow' );

		$this->assertGreaterThanOrEqual( $before, $got );
	}
}

<?php
/**
 * The public form's rate limiter.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase {

	private const IP    = '203.0.113.7';
	private const EMAIL = 'jane@example.test';

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/**
	 * Submit $times and return how many were refused.
	 *
	 * @param int    $times How many attempts.
	 * @param string $ip    Client address.
	 * @param string $email Submitted address.
	 * @return int
	 */
	private function attempts( int $times, string $ip = self::IP, string $email = self::EMAIL ): int {
		$refused = 0;

		for ( $i = 0; $i < $times; $i++ ) {
			if ( gwcvt_rate_limited( $ip, $email ) ) {
				++$refused;
			}
		}

		return $refused;
	}

	public function test_ordinary_use_is_never_limited(): void {
		$this->assertSame( 0, $this->attempts( 3 ) );
	}

	public function test_the_email_window_is_the_tightest(): void {
		/* Six an hour per address. The seventh is the first refusal, because a
		 * volunteer logging six shifts in an hour has already had a strange day. */
		$this->assertSame( 0, $this->attempts( 6 ) );
		$this->assertTrue( gwcvt_rate_limited( self::IP, self::EMAIL ) );
	}

	public function test_one_address_cannot_be_worked_around_by_changing_email(): void {
		// Ten from one IP, each with a different address, then an eleventh.
		for ( $i = 0; $i < 10; $i++ ) {
			gwcvt_rate_limited( self::IP, 'person' . $i . '@example.test' );
		}

		$this->assertTrue(
			gwcvt_rate_limited( self::IP, 'someone-new@example.test' ),
			'The IP window is what stops one machine enumerating with fresh addresses.'
		);
	}

	public function test_a_refused_attempt_still_counts(): void {
		$this->attempts( 10 );

		$before = get_option( GWCVT_RATE_LIMIT_OPTION );
		gwcvt_rate_limited( self::IP, self::EMAIL );
		$after = get_option( GWCVT_RATE_LIMIT_OPTION );

		/* Otherwise the limiter is a speed bump somebody can sit on: refusals
		 * that do not count mean the window never advances. */
		$this->assertNotSame( $before, $after );
	}

	public function test_a_different_address_is_a_different_window(): void {
		$this->attempts( 10 );

		$this->assertFalse(
			gwcvt_rate_limited( '198.51.100.22', 'someone-else@example.test' ),
			'One person hitting the limit must not lock out the next visitor.'
		);
	}

	public function test_a_missing_ip_does_not_bypass_the_email_window(): void {
		/* A request with no usable REMOTE_ADDR still has to be limited by
		 * something, or "make the IP unreadable" becomes the bypass. */
		for ( $i = 0; $i < 6; $i++ ) {
			gwcvt_rate_limited( '', self::EMAIL );
		}

		$this->assertTrue( gwcvt_rate_limited( '', self::EMAIL ) );
	}

	public function test_a_submission_with_neither_still_counts_globally(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			gwcvt_rate_limited( '', '' );
		}

		$this->assertTrue(
			gwcvt_rate_limited( '', '' ),
			'The global ceiling is what a botnet spreading across addresses runs into.'
		);
	}

	public function test_the_windows_are_filterable(): void {
		$tighter = static fn( array $limits ): array => array_merge(
			$limits,
			array( 'email' => array( 'max' => 1, 'window' => HOUR_IN_SECONDS ) )
		);

		add_filter( 'gwcvt_rate_limits', $tighter );

		$this->assertFalse( gwcvt_rate_limited( self::IP, self::EMAIL ) );
		$this->assertTrue( gwcvt_rate_limited( self::IP, self::EMAIL ) );

		remove_filter( 'gwcvt_rate_limits', $tighter );
	}

	public function test_the_email_key_is_case_insensitive(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			gwcvt_rate_limited( '', 'Jane@Example.Test' );
		}

		$this->assertTrue(
			gwcvt_rate_limited( '', 'jane@example.test' ),
			'Otherwise changing the capitalisation is a one-keystroke bypass.'
		);
	}

	public function test_addresses_are_not_stored_in_the_clear(): void {
		gwcvt_rate_limited( self::IP, self::EMAIL );

		$stored = wp_json_encode( get_option( GWCVT_RATE_LIMIT_OPTION ) );

		/* The limiter is a record of who submitted and when, written by
		 * anonymous traffic. Hashed, it is a set of opaque keys; in the clear it
		 * is a log of email addresses in an option anybody with database access
		 * can read. */
		$this->assertStringNotContainsString( self::EMAIL, (string) $stored );
		$this->assertStringNotContainsString( self::IP, (string) $stored );
	}

	public function test_a_closed_window_is_pruned(): void {
		gwcvt_rate_limited( self::IP, self::EMAIL );

		// Age every recorded window well past its expiry.
		$store = get_option( GWCVT_RATE_LIMIT_OPTION );

		foreach ( $store as $scope => $bucket ) {
			foreach ( $bucket as $key => $row ) {
				$store[ $scope ][ $key ]['start'] = time() - ( 2 * HOUR_IN_SECONDS );
			}
		}

		update_option( GWCVT_RATE_LIMIT_OPTION, $store );

		gwcvt_rate_limited( '198.51.100.99', 'other@example.test' );

		$after = get_option( GWCVT_RATE_LIMIT_OPTION );

		$this->assertCount(
			1,
			$after['email'],
			'Expired windows must be dropped on write, or this option grows forever under attack.'
		);
	}

	public function test_a_scope_that_somehow_explodes_is_emptied(): void {
		$bloated = array();

		for ( $i = 0; $i < GWCVT_RATE_LIMIT_CEILING + 5; $i++ ) {
			$bloated[ 'key' . $i ] = array(
				'count' => 1,
				'start' => time(),
			);
		}

		update_option( GWCVT_RATE_LIMIT_OPTION, array( 'ip' => $bloated ) );

		gwcvt_rate_limited( self::IP, self::EMAIL );

		/* Emptied rather than trimmed. An option row big enough to matter is
		 * itself the problem, and restarting the window is a smaller failure
		 * than an option too large to load. */
		$this->assertCount( 1, get_option( GWCVT_RATE_LIMIT_OPTION )['ip'] );
	}
}

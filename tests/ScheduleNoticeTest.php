<?php
/**
 * The scheduled passes: when they exist, when they run, and what counts as a
 * change worth emailing thirty people about.
 *
 * The passes themselves query the database and are covered by
 * tests/integration/notices.php. What is here is the part that decides whether
 * they happen at all.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleNoticeTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Write settings and clear the memo.
	 *
	 * @param array $settings Settings to store.
	 */
	private function settings( array $settings ): void {
		update_option( GWC_VT_SETTINGS_OPTION, $settings );
		gwc_vt_settings_cache( null, true );
	}

	/* ── The events exist only while the feature does ────────────────────────
	 * A site that never turns scheduling on has no shifts and nothing for either
	 * pass to do. An hourly event pointing at a function that returns
	 * immediately is still a permanent row in every cron listing that site's
	 * owner looks at.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_no_events_are_scheduled_while_shifts_are_off(): void {
		gwc_vt_schedule_shift_events();

		$this->assertFalse( wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
		$this->assertFalse( wp_next_scheduled( GWC_VT_DIGEST_EVENT ) );
	}

	public function test_turning_shifts_on_schedules_both(): void {
		$this->settings( array( 'shifts_enabled' => true ) );

		gwc_vt_schedule_shift_events();

		$this->assertNotFalse( wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
		$this->assertNotFalse( wp_next_scheduled( GWC_VT_DIGEST_EVENT ) );
	}

	/**
	 * The half that is easy to forget. Without it, switching the feature off
	 * would leave both events behind forever.
	 */
	public function test_turning_shifts_off_unschedules_both(): void {
		$this->settings( array( 'shifts_enabled' => true ) );
		gwc_vt_schedule_shift_events();

		$this->settings( array( 'shifts_enabled' => false ) );
		gwc_vt_schedule_shift_events();

		$this->assertFalse( wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
		$this->assertFalse( wp_next_scheduled( GWC_VT_DIGEST_EVENT ) );
	}

    /**
     * Idempotent, because it runs on every single request.
     */
	public function test_scheduling_twice_does_not_move_the_events(): void {
		$this->settings( array( 'shifts_enabled' => true ) );

		gwc_vt_schedule_shift_events();
		$first = wp_next_scheduled( GWC_VT_REMINDER_EVENT );

		gwc_vt_schedule_shift_events();

		$this->assertSame( $first, wp_next_scheduled( GWC_VT_REMINDER_EVENT ) );
	}

	/* ── Neither pass does anything while it is switched off ─────────────── */

	public function test_reminders_do_nothing_while_shifts_are_off(): void {
		$this->settings( array( 'reminder_enabled' => true ) );

		$this->assertSame( 0, gwc_vt_run_reminders() );
	}

	public function test_reminders_do_nothing_while_reminders_are_off(): void {
		$this->settings( array( 'shifts_enabled' => true ) );

		$this->assertSame( 0, gwc_vt_run_reminders() );
	}

	public function test_the_digest_does_nothing_while_it_is_off(): void {
		$this->settings( array( 'shifts_enabled' => true ) );

		$this->assertFalse( gwc_vt_run_digest() );
	}

	public function test_the_digest_does_nothing_while_shifts_are_off(): void {
		$this->settings( array( 'digest_enabled' => true ) );

		$this->assertFalse( gwc_vt_run_digest() );
	}

	/* ── A message with nowhere to point ─────────────────────────────────────
	 * A site can have shifts and reminders switched on without ever pinning a
	 * public page — the coordinator takes names on the phone. In that
	 * configuration there is nowhere for a "cancel your place" link to go, and
	 * sending one that points at the front page is worse than sending none: the
	 * volunteer clicks it, finds nothing, and assumes they have cancelled.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_there_is_no_manage_link_without_a_pinned_page(): void {
		$this->settings( array( 'shifts_enabled' => true ) );

		$this->assertSame( '', gwc_vt_signup_manage_url( 10 ) );
		$this->assertSame( '', gwc_vt_signup_ics_url( 10 ) );
	}

	/* ── What counts as a change ─────────────────────────────────────────────
	 * The list is deliberately short. Mailing thirty people because somebody
	 * fixed a spelling in the supervisor's name is how an organisation teaches
	 * its volunteers to ignore its email.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $key      The meta key that changed.
	 * @param string $value    What it changed to.
	 * @param bool   $expected Whether anybody should be told.
	 */
	#[DataProvider( 'changes' )]
	public function test_only_a_move_is_worth_telling_anybody_about( string $key, string $value, bool $expected ): void {
		$was = array(
			'date'     => '2026-03-14',
			'start'    => '09:00',
			'end'      => '12:00',
			'next_day' => '',
			'location' => 'Main warehouse',
		);

		gwc_vt_test_add_post( 5, GWC_VT_SHIFT_TYPE );

		update_post_meta( 5, GWC_VT_SHIFT_DATE, '2026-03-14' );
		update_post_meta( 5, GWC_VT_SHIFT_START, '09:00' );
		update_post_meta( 5, GWC_VT_SHIFT_END, '12:00' );
		update_post_meta( 5, GWC_VT_SHIFT_LOCATION, 'Main warehouse' );
		update_post_meta( 5, GWC_VT_SHIFT_ACTIVITY, 'Sorting' );
		update_post_meta( 5, GWC_VT_SHIFT_SUPERVISOR, 'Dana Reyes' );
		update_post_meta( 5, GWC_VT_SHIFT_NOTES, 'Closed shoes' );
		update_post_meta( 5, GWC_VT_SHIFT_MAX, 6 );

		update_post_meta( 5, $key, $value );

		$this->assertSame( $expected, gwc_vt_shift_moved( 5, $was ) );
	}

	public static function changes(): array {
		return array(
			'the date moved'          => array( GWC_VT_SHIFT_DATE, '2026-03-21', true ),
			'the start time moved'    => array( GWC_VT_SHIFT_START, '10:00', true ),
			'the end time moved'      => array( GWC_VT_SHIFT_END, '13:00', true ),
			'it became an overnight'  => array( GWC_VT_SHIFT_OVERNIGHT, '1', true ),
			'the place moved'         => array( GWC_VT_SHIFT_LOCATION, 'The community centre', true ),

			/* None of these change whether somebody can come. */
			'the activity was reworded' => array( GWC_VT_SHIFT_ACTIVITY, 'Sorting the produce delivery', false ),
			'the supervisor changed'    => array( GWC_VT_SHIFT_SUPERVISOR, 'Marcus Bell', false ),
			'the notes were expanded'   => array( GWC_VT_SHIFT_NOTES, 'Closed shoes, park round the back', false ),
			'the capacity changed'      => array( GWC_VT_SHIFT_MAX, '8', false ),
		);
	}

	public function test_saving_a_shift_unchanged_tells_nobody(): void {
		gwc_vt_test_add_post( 5, GWC_VT_SHIFT_TYPE );

		update_post_meta( 5, GWC_VT_SHIFT_DATE, '2026-03-14' );
		update_post_meta( 5, GWC_VT_SHIFT_START, '09:00' );
		update_post_meta( 5, GWC_VT_SHIFT_END, '12:00' );
		update_post_meta( 5, GWC_VT_SHIFT_LOCATION, 'Main warehouse' );

		$this->assertFalse(
			gwc_vt_shift_moved(
				5,
				array(
					'date'     => '2026-03-14',
					'start'    => '09:00',
					'end'      => '12:00',
					'next_day' => '',
					'location' => 'Main warehouse',
				)
			)
		);
	}

	/* ── The queue ───────────────────────────────────────────────────────────
	 * Cancelling a shift with thirty people on it is thirty messages, and a
	 * coordinator watching a Cancel button spin for half a minute presses it
	 * again.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_queued_mail_is_held_until_the_request_is_answered(): void {
		$GLOBALS['gwc_vt_pending_mail'] = array();

		gwc_vt_queue_signup_mail( 'cancelled', 11, array( 'reason' => 'Van in for repairs' ) );
		gwc_vt_queue_signup_mail( 'cancelled', 12, array( 'reason' => 'Van in for repairs' ) );

		$this->assertCount( 2, $GLOBALS['gwc_vt_pending_mail'] );
		$this->assertSame( 'cancelled', $GLOBALS['gwc_vt_pending_mail'][0]['kind'] );
		$this->assertSame( 11, $GLOBALS['gwc_vt_pending_mail'][0]['signup'] );
		$this->assertSame( 'Van in for repairs', $GLOBALS['gwc_vt_pending_mail'][0]['context']['reason'] );
	}

	/**
	 * Drained on the way out, so a second shutdown cannot send everything twice.
	 */
	public function test_the_queue_is_emptied_when_it_is_sent(): void {
		$GLOBALS['gwc_vt_pending_mail'] = array();

		gwc_vt_queue_signup_mail( 'cancelled', 999 );

		gwc_vt_send_queued_confirmations();

		$this->assertSame( array(), $GLOBALS['gwc_vt_pending_mail'] );
	}
}

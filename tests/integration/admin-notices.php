<?php
/**
 * What the schedule's two admin notices say, and when they say nothing.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Only because these live in inc/admin-schedule.php, which the unit bootstrap
 * does not load — it stops at the non-admin half deliberately. The functions
 * themselves are nearly pure: they read a $_GET key, look a sentence up in a
 * map and print it.
 *
 * That lookup is the whole bug. gwc_vt_schedule_notice() and
 * gwc_vt_event_notice() are the same machinery forked, and only one fork kept
 * up. The shift-side map had no 'promoted' key, so promoting somebody on a
 * standalone shift fell through to a bare `return;` and the page rendered with
 * no confirmation at all. And three handlers redirected a REFUSAL with 'saved',
 * which renders "Shift saved." — for a delete that was declined because
 * somebody had signed up in the meantime, and for two roster adds that added
 * nobody.
 *
 * A screen that reports success for a refusal is worse than one that says
 * nothing: the coordinator leaves believing the shift is gone.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/admin-notices.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_an_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

/**
 * What one notice renders for one result, with $_GET put back afterwards.
 *
 * @param string $which  'shift' or 'event'.
 * @param string $result The result key a handler redirected with.
 * @param array  $extra  Any other query arguments the notice reads.
 * @return string
 */
function gwc_vt_an_render( string $which, string $result, array $extra = array() ): string {
	$before = $_GET;

	$_GET = array_merge(
		array( 'shift' === $which ? 'gwc_vt_shift_result' : 'gwc_vt_event_result' => $result ),
		$extra
	);

	ob_start();

	if ( 'shift' === $which ) {
		gwc_vt_schedule_notice();
	} else {
		gwc_vt_event_notice();
	}

	$out = (string) ob_get_clean();

	$_GET = $before;

	return $out;
}

/* ── Promoting somebody confirms it, on either screen ─────────────────────────
 * gwc_vt_handle_signup_promote() ends in two branches: the event roster for a
 * slot on an event, gwc_vt_shift_redirect() for a standalone shift. Both send
 * 'promoted'. Only the event map had the key.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_out = gwc_vt_an_render( 'shift', 'promoted' );

gwc_vt_an_check(
	'promoting on a standalone shift says something at all',
	'' !== trim( $gwc_vt_out ),
	'' === trim( $gwc_vt_out ) ? '(nothing rendered)' : 'rendered'
);

gwc_vt_an_check(
	'and says they have a place',
	false !== strpos( $gwc_vt_out, 'They have a place now' ),
	trim( wp_strip_all_tags( $gwc_vt_out ) )
);

gwc_vt_an_check(
	'as a success, not an error',
	false !== strpos( $gwc_vt_out, 'notice-success' ),
	$gwc_vt_out
);

gwc_vt_an_check(
	'and the event screen still says the same thing',
	false !== strpos( gwc_vt_an_render( 'event', 'promoted' ), 'They have a place now' ),
	trim( wp_strip_all_tags( gwc_vt_an_render( 'event', 'promoted' ) ) )
);

/* ── A refusal never reports success ─────────────────────────────────────── */

$gwc_vt_out = gwc_vt_an_render( 'shift', 'has-roster' );

gwc_vt_an_check(
	'a delete refused because somebody signed up does not say "Shift saved."',
	false === strpos( $gwc_vt_out, 'Shift saved' ),
	trim( wp_strip_all_tags( $gwc_vt_out ) )
);

gwc_vt_an_check(
	'it says the shift can be called off but not deleted',
	false !== strpos( $gwc_vt_out, 'called off but not deleted' ),
	trim( wp_strip_all_tags( $gwc_vt_out ) )
);

gwc_vt_an_check(
	'and renders as an error',
	false !== strpos( $gwc_vt_out, 'notice-error' ),
	$gwc_vt_out
);

gwc_vt_an_check(
	'which is the same sentence the event screen has always given',
	false !== strpos( gwc_vt_an_render( 'event', 'has-roster' ), 'called off but not deleted' ),
	trim( wp_strip_all_tags( gwc_vt_an_render( 'event', 'has-roster' ) ) )
);

/* The two roster adds that refused and reported "Saved." */
foreach ( array( 'shift', 'event' ) as $gwc_vt_screen ) {
	$gwc_vt_out = gwc_vt_an_render( $gwc_vt_screen, 'no-volunteer' );

	gwc_vt_an_check(
		$gwc_vt_screen . ': a roster add with nobody chosen says so',
		false !== strpos( $gwc_vt_out, 'Choose somebody to add' ),
		trim( wp_strip_all_tags( $gwc_vt_out ) )
	);

	gwc_vt_an_check(
		$gwc_vt_screen . ': and does not claim anything was saved',
		false === strpos( $gwc_vt_out, 'Shift saved' ) && false === strpos( $gwc_vt_out, '>Saved.' ),
		trim( wp_strip_all_tags( $gwc_vt_out ) )
	);

	gwc_vt_an_check(
		$gwc_vt_screen . ': and renders as an error',
		false !== strpos( $gwc_vt_out, 'notice-error' ),
		$gwc_vt_out
	);
}

$gwc_vt_out = gwc_vt_an_render( 'event', 'wrong-slot' );

gwc_vt_an_check(
	'adding to a time that is not on this event says which problem it was',
	false !== strpos( $gwc_vt_out, 'not on this event' ),
	trim( wp_strip_all_tags( $gwc_vt_out ) )
);

/* ── The class attribute is a fixed set, and is escaped ──────────────────────
 * Not exploitable either way — the value is sanitize_key()-ed and drawn from a
 * fixed set — but the shift fork escaped it and the event fork did not, and a
 * hardening that reaches one of two forks is the shape of thing worth pinning.
 * ─────────────────────────────────────────────────────────────────────────── */

foreach ( array( 'shift' => 'promoted', 'event' => 'copied' ) as $gwc_vt_screen => $gwc_vt_key ) {
	$gwc_vt_out = gwc_vt_an_render( $gwc_vt_screen, $gwc_vt_key );

	gwc_vt_an_check(
		$gwc_vt_screen . ': the notice class is exactly one of the two expected words',
		1 === preg_match( '/class="notice notice-(success|error|warning) is-dismissible"/', $gwc_vt_out ),
		$gwc_vt_out
	);
}

/* ── A result nobody recognizes still says nothing ───────────────────────── */

foreach ( array( 'shift', 'event' ) as $gwc_vt_screen ) {
	gwc_vt_an_check(
		$gwc_vt_screen . ': an unrecognized result renders nothing rather than an empty box',
		'' === trim( gwc_vt_an_render( $gwc_vt_screen, 'zzytest-not-a-result' ) )
	);

	gwc_vt_an_check(
		$gwc_vt_screen . ': and so does no result at all',
		'' === trim( gwc_vt_an_render( $gwc_vt_screen, '' ) )
	);
}

/* ── The count sentence still lands where it did ─────────────────────────── */

gwc_vt_an_check(
	'a shift-side result still carries the "N people were emailed" sentence',
	false !== strpos( gwc_vt_an_render( 'shift', 'cancelled', array( 'gwc_vt_told' => '3' ) ), '3 people were emailed' ),
	trim( wp_strip_all_tags( gwc_vt_an_render( 'shift', 'cancelled', array( 'gwc_vt_told' => '3' ) ) ) )
);

/* Deliberately not asserted as identical to the event screen's wording. That
 * one says "3 people were told." for the same event, and the two really have
 * drifted — but settling on one is a translatable-string change the issue
 * listed as an observation rather than in its scope, so it stays visible here
 * rather than being quietly unified. */
gwc_vt_an_check(
	'and the event-side one still carries its own',
	false !== strpos( gwc_vt_an_render( 'event', 'called-off', array( 'gwc_vt_told' => '3' ) ), '3 people were told' ),
	trim( wp_strip_all_tags( gwc_vt_an_render( 'event', 'called-off', array( 'gwc_vt_told' => '3' ) ) ) )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code —
 * which is what the Integration job did until the marker check went in beside
 * it. Same form as the other scripts here. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

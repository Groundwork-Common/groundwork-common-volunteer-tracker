<?php
/**
 * A demo organisation, for working on the plugin and taking screenshots.
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/seed.php
 *
 * Re-runnable: it removes what a previous run created and builds it again.
 *
 * ── Every name here is invented, and that is not incidental ──────────────────
 * This plugin's demo data is a list of people's names next to a number of
 * volunteer hours, and for two of them next to the fact that they are working
 * off a court order. A screenshot of real data on a public plugin page is a
 * disclosure that cannot be taken back — deleting the file does not delete it
 * from whatever scraped it. So the fixtures are obviously fictional, the email
 * addresses are all on example.test (reserved by RFC 2606 and deliverable
 * nowhere), and the same rule is written into .wordpress-org/README.md for
 * whoever takes the screenshots.
 *
 * ── Why this is committed when the sibling plugins gitignore their seed ──────
 * The post portal keeps its fixtures in .dev/ and documents the recipe instead.
 * That works when the fixture is "three organisations and a user". Here the
 * fixture IS the domain — a court-ordered volunteer partway through their
 * hours, a dormant record inside the retention window, one on a hold, one with
 * no email so the letter can only be printed. Those states are the plugin's
 * whole subject, and recreating them from a paragraph of prose each time is how
 * they drift. tests/ is already in .distignore, so none of this reaches a
 * release.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** Marks everything this script creates, so a re-run removes only its own work. */
const GWC_VT_SEED_MARK = '_gwc_vt_seed';

/* ── Refuse to run anywhere that matters ─────────────────────────────────────
 * A seed script is a script that deletes things and writes settings. Pointed at
 * a live nonprofit's site it would overwrite their letterhead and their
 * retention policy. The guard is deliberately conservative: it runs on a local
 * or development environment and nowhere else, whatever anybody types.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwc_vt_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

if ( ! in_array( $gwc_vt_env, array( 'local', 'development' ), true ) ) {
	echo "Refusing to seed: WP_ENVIRONMENT_TYPE is '", $gwc_vt_env, "'.\n";
	echo "This script writes settings and deletes records. It runs on local and development only.\n";
	exit( 1 );
}

wp_set_current_user( 1 );

/* ── Clear the previous run ──────────────────────────────────────────────── */

$gwc_vt_previous = get_posts(
	array(
		'post_type'      => array( GWC_VT_ENTRY_TYPE, GWC_VT_VOLUNTEER_TYPE, GWC_VT_LETTER_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_EVENT_TYPE, GWC_VT_SIGNUP_TYPE, 'page' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a development fixture, run by hand.
		'meta_key'       => GWC_VT_SEED_MARK,
	)
);

foreach ( $gwc_vt_previous as $gwc_vt_id ) {
	wp_delete_post( (int) $gwc_vt_id, true );
}

printf( "Removed %d records from a previous run.\n", count( $gwc_vt_previous ) );

/* ── Helpers ─────────────────────────────────────────────────────────────── */

/**
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address, or '' for none.
 * @param string $phone Phone number.
 * @return int
 */
function gwc_vt_seed_volunteer( string $name, string $email = '', string $phone = '' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_SEED_MARK, 1 );

	if ( '' !== $email ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	if ( '' !== $phone ) {
		update_post_meta( $id, GWC_VT_VOLUNTEER_PHONE, $phone );
	}

	return $id;
}

/**
 * Create an hour entry.
 *
 * @param int    $volunteer_id Volunteer, or 0 for an unmatched self-logged entry.
 * @param string $date         Y-m-d.
 * @param string $hours        As a person would type it.
 * @param string $activity     What they did.
 * @param bool   $verified     Whether a staff member has attested to it.
 * @param array  $claim        Optional 'name' and 'email' for a self-logged entry.
 * @return int
 */
function gwc_vt_seed_entry( int $volunteer_id, string $date, string $hours, string $activity, bool $verified, array $claim = array() ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_ENTRY_TYPE,
			'post_status' => $claim ? 'pending' : 'publish',
			'post_title'  => 'seeding',
		)
	);

	update_post_meta( $id, GWC_VT_SEED_MARK, 1 );
	update_post_meta( $id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $id, GWC_VT_ENTRY_MINUTES, (int) gwc_vt_parse_hours( $hours ) );
	update_post_meta( $id, GWC_VT_ENTRY_ACTIVITY, $activity );
	update_post_meta( $id, GWC_VT_ENTRY_SUPERVISOR, 'Dana Reyes' );
	update_post_meta( $id, GWC_VT_ENTRY_SOURCE, $claim ? 'self' : 'staff' );

	if ( isset( $claim['name'] ) ) {
		update_post_meta( $id, '_gwc_vt_claim_name', $claim['name'] );
	}

	if ( isset( $claim['email'] ) ) {
		update_post_meta( $id, '_gwc_vt_claim_email', $claim['email'] );
	}

	if ( $verified ) {
		gwc_vt_verify_entry( $id, 1 );
	}

	gwc_vt_retitle_entry( $id );

	return $id;
}

/* ── The organisation ────────────────────────────────────────────────────────
 * The site's own title goes with it. The admin bar carries that title into every
 * screenshot, and the shooting guide's rule is that no real site name may appear
 * in one — on a developer's machine the title is usually the folder, which is a
 * branch name or a client's name and belongs on a public plugin page even less
 * than a real volunteer does.
 * ─────────────────────────────────────────────────────────────────────────── */

update_option( 'blogname', 'Riverbend Food Bank' );
update_option( 'blogdescription', 'Food, and a hand up, in the Riverbend' );

/* The account doing the seeding is the one that attests to every hour below, and
 * its display name is printed on the letter beside each shift — "Verified 6
 * August 2026 by ___". On a fresh wp-env that reads "by admin", which makes the
 * one line carrying the letter's credibility look like test data. Named to match
 * the signatory, because on a food bank this size they are the same person.
 *
 * Guarded by the environment check at the top of this file: this writes to a user
 * account, which is further than the rest of the script goes. */
wp_update_user(
	array(
		'ID'           => 1,
		'display_name' => 'Dana Reyes',
		'first_name'   => 'Dana',
		'last_name'    => 'Reyes',
	)
);

$gwc_vt_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Log your hours',
		'post_name'    => 'log-your-hours',
		'post_content' => "<!-- wp:paragraph --><p>Worked a shift with us? Send us your hours and we'll check them against our records.</p><!-- /wp:paragraph -->\n<!-- wp:groundwork-common-volunteer-tracker/hours-form /-->",
	)
);
update_post_meta( $gwc_vt_page, GWC_VT_SEED_MARK, 1 );

$gwc_vt_shift_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Volunteer with us',
		'post_name'    => 'volunteer-with-us',
		'post_content' => "<!-- wp:paragraph --><p>Here is where we need a hand over the next few weeks. Pick a shift and we'll email you the details.</p><!-- /wp:paragraph -->\n<!-- wp:groundwork-common-volunteer-tracker/shift-list /-->",
	)
);
update_post_meta( $gwc_vt_shift_page, GWC_VT_SEED_MARK, 1 );

update_option(
	GWC_VT_SETTINGS_OPTION,
	array(
		'org_name'         => 'Riverbend Food Bank',
		'org_address'      => "1420 Mill Street\nRiverbend, OH 44001",
		'org_contact'      => 'volunteers@riverbendfood.example · (555) 0142',
		'signatory_name'   => 'Dana Reyes',
		'signatory_title'  => 'Volunteer Coordinator',
		'reference_prefix' => 'RFB',
		'letter_itemize'   => true,
		'hour_increment'   => 15,
		'hour_format'      => 'decimal',
		'activities'       => "Sorting the produce delivery\nPacking weekend boxes\nWarehouse inventory\nFront desk intake\nHoliday distribution\nDriving the collection van",

		/* Retention answered rather than left open, so the nag is not the first
		 * thing in every screenshot. Two years, anonymise, from the last shift. */
		'retention_months'   => 24,
		'retention_action'   => 'anonymize',
		'retention_anchor'   => 'last_entry',
		'retention_decided'  => true,

		'self_log_enabled' => true,
		'self_log_page'    => $gwc_vt_page,

		'shifts_enabled'   => true,
		'shift_locations'  => "Main warehouse\nFront desk\nRiverbend Community Center\nThe collection van",

		'signup_enabled'      => true,
		'schedule_page'       => $gwc_vt_shift_page,
		'signup_horizon_days' => 60,
		'signup_cutoff_hours' => 0,

		'reminder_enabled'    => true,
		'reminder_lead_hours' => 48,
		'digest_enabled'      => true,
		'digest_recipient'    => 'dana@example.test',
	)
);
gwc_vt_settings_cache( null, true );

/* ── The people ──────────────────────────────────────────────────────────────
 * Chosen to cover the states the plugin actually has to handle, rather than six
 * variations of "a volunteer with some hours".
 * ─────────────────────────────────────────────────────────────────────────── */

// Working off a court order, most of the way through. The letter case.
$gwc_vt_marcus = gwc_vt_seed_volunteer( 'Marcus Delacroix', 'marcus@example.test', '(555) 0177' );

/* Forty hours ordered, nearly done, three weeks to go. The state the whole
 * hours-required feature exists for, and the one worth having on screen. */
update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED, 40 * 60 );
update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +21 days' ) ) );
update_post_meta( $gwc_vt_marcus, GWC_VT_VOLUNTEER_REQUIRED_FOR, 'Franklin County Municipal Court' );

foreach (
	array(
		array( '2026-05-02', '3:30', 'Sorting the produce delivery' ),
		array( '2026-05-09', '4', 'Warehouse inventory' ),
		array( '2026-05-16', '3', 'Packing weekend boxes' ),
		array( '2026-05-30', '4:15', 'Driving the collection van' ),
		array( '2026-06-06', '3:30', 'Sorting the produce delivery' ),
		array( '2026-06-13', '5', 'Holiday distribution' ),
		array( '2026-06-27', '2:30', 'Front desk intake' ),
		array( '2026-07-11', '4', 'Packing weekend boxes' ),
	) as $gwc_vt_shift
) {
	gwc_vt_seed_entry( $gwc_vt_marcus, $gwc_vt_shift[0], $gwc_vt_shift[1], $gwc_vt_shift[2], true );
}

// A regular volunteer, with two shifts still waiting to be checked.
$gwc_vt_priya = gwc_vt_seed_volunteer( 'Priya Ramanathan', 'priya@example.test' );

gwc_vt_seed_entry( $gwc_vt_priya, '2026-06-20', '2:45', 'Front desk intake', true );
gwc_vt_seed_entry( $gwc_vt_priya, '2026-07-04', '6', 'Holiday distribution', true );
gwc_vt_seed_entry( $gwc_vt_priya, '2026-07-18', '3', 'Warehouse inventory', false );
gwc_vt_seed_entry( $gwc_vt_priya, '2026-08-01', '3:15', 'Packing weekend boxes', false );

// Brand new. Nothing verified yet — the triage case.
$gwc_vt_tomas = gwc_vt_seed_volunteer( 'Tomás Beaulieu', 'tomas@example.test' );

/* Past their deadline and still short — the other state worth looking at, and
 * the one a coordinator most needs to spot from the list. */
update_post_meta( $gwc_vt_tomas, GWC_VT_VOLUNTEER_REQUIRED, 30 * 60 );
update_post_meta( $gwc_vt_tomas, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' -9 days' ) ) );
update_post_meta( $gwc_vt_tomas, GWC_VT_VOLUNTEER_REQUIRED_FOR, 'Riverbend High School' );

gwc_vt_seed_entry( $gwc_vt_tomas, '2026-07-25', '4', 'Sorting the produce delivery', false );
gwc_vt_seed_entry( $gwc_vt_tomas, '2026-08-01', '3:30', 'Warehouse inventory', false );

/* Verified hours but no email on file. The letter can be printed and cannot be
 * sent, which is a state the Letters screen has to say something sensible
 * about. */
$gwc_vt_fatima = gwc_vt_seed_volunteer( 'Fatima Sørensen' );

gwc_vt_seed_entry( $gwc_vt_fatima, '2026-06-06', '5', 'Holiday distribution', true );
gwc_vt_seed_entry( $gwc_vt_fatima, '2026-06-20', '4:30', 'Driving the collection van', true );

// Dormant since 2023, so due under the two-year policy. The retention case.
$gwc_vt_ines = gwc_vt_seed_volunteer( 'Inès Okonkwo', 'ines@example.test' );

gwc_vt_seed_entry( $gwc_vt_ines, '2023-04-15', '3', 'Front desk intake', true );
gwc_vt_seed_entry( $gwc_vt_ines, '2023-05-20', '3:30', 'Packing weekend boxes', true );

// Equally dormant, and held back from the sweep by an open case.
$gwc_vt_wendell = gwc_vt_seed_volunteer( 'Wendell Achebe', 'wendell@example.test' );

gwc_vt_seed_entry( $gwc_vt_wendell, '2023-03-11', '4', 'Warehouse inventory', true );
gwc_vt_seed_entry( $gwc_vt_wendell, '2023-06-24', '6', 'Holiday distribution', true );

update_post_meta( $gwc_vt_wendell, GWC_VT_VOLUNTEER_HOLD, 1 );
update_post_meta( $gwc_vt_wendell, GWC_VT_VOLUNTEER_HOLD_REASON, 'Court has asked us to keep this until the case closes' );

/* Sent in through the public form and not yet matched to anybody. Two of them,
 * because the interesting part of triage is telling them apart — one names a
 * volunteer on file, one does not. */
gwc_vt_seed_entry( 0, '2026-08-02', '3', 'Sorting the produce delivery', false, array( 'name' => 'Priya Ramanathan', 'email' => 'priya@example.test' ) );
gwc_vt_seed_entry( 0, '2026-08-03', '2:30', 'Front desk intake', false, array( 'name' => 'Joachim Whitfeather', 'email' => 'joachim@example.test' ) );

/* ── The schedule ────────────────────────────────────────────────────────────
 * Dated relative to whenever this is run, so the Schedule screen is never a list
 * of shifts from last spring. The states are chosen the same way the people
 * were: each one is a thing the screen has to handle, not a sixth variation of
 * "a Saturday with some people on it".
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Create a shift.
 *
 * @param string $date     Y-m-d.
 * @param string $start    H:i.
 * @param string $end      H:i.
 * @param string $activity What the work is.
 * @param array  $extra    location, supervisor, min, max, overnight, status.
 * @return int
 */
function gwc_vt_seed_shift( string $date, string $start, string $end, string $activity, array $extra = array() ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_SHIFT_TYPE,
			'post_status' => (string) ( $extra['status'] ?? 'publish' ),
			'post_title'  => 'tmp',
		)
	);

	update_post_meta( $id, GWC_VT_SEED_MARK, 1 );
	update_post_meta( $id, GWC_VT_SHIFT_DATE, $date );
	update_post_meta( $id, GWC_VT_SHIFT_START, $start );
	update_post_meta( $id, GWC_VT_SHIFT_END, $end );
	update_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, $activity );
	update_post_meta( $id, GWC_VT_SHIFT_SUPERVISOR, (string) ( $extra['supervisor'] ?? 'Dana Reyes' ) );
	update_post_meta( $id, GWC_VT_SHIFT_LOCATION, (string) ( $extra['location'] ?? 'Main warehouse' ) );
	update_post_meta( $id, GWC_VT_SHIFT_MIN, (int) ( $extra['min'] ?? 0 ) );
	update_post_meta( $id, GWC_VT_SHIFT_MAX, (int) ( $extra['max'] ?? 0 ) );

	if ( ! empty( $extra['overnight'] ) ) {
		update_post_meta( $id, GWC_VT_SHIFT_OVERNIGHT, 1 );
	}

	if ( ! empty( $extra['notes'] ) ) {
		update_post_meta( $id, GWC_VT_SHIFT_NOTES, (string) $extra['notes'] );
	}

	gwc_vt_retitle_shift( $id );

	return $id;
}

/**
 * Put somebody on a shift, and mark the signup as ours.
 *
 * @param int   $shift_id Shift post ID.
 * @param array $args     Passed to gwc_vt_add_signup().
 * @return int
 */
function gwc_vt_seed_signup( int $shift_id, array $args ): int {
	$id = gwc_vt_add_signup( $shift_id, $args );

	if ( $id > 0 ) {
		update_post_meta( $id, GWC_VT_SEED_MARK, 1 );
	}

	return $id;
}

/* The coming Saturday, and the ones either side of it. */
$gwc_vt_saturday = gmdate( 'Y-m-d', strtotime( 'saturday this week', strtotime( gwc_vt_today() ) ) );
$gwc_vt_next_sat = gmdate( 'Y-m-d', strtotime( $gwc_vt_saturday . ' +7 days' ) );
$gwc_vt_last_sat = gmdate( 'Y-m-d', strtotime( $gwc_vt_saturday . ' -7 days' ) );
$gwc_vt_midweek  = gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +3 days' ) );

/* Short of people, and soon. The row the whole screen exists to surface. */
$gwc_vt_short = gwc_vt_seed_shift(
	$gwc_vt_saturday,
	'09:00',
	'12:00',
	'Sorting the produce delivery',
	array(
		'min'   => 6,
		'max'   => 8,
		'notes' => 'Closed shoes. Park round the back and ask for Dana at the desk.',
	)
);

gwc_vt_seed_signup( $gwc_vt_short, array( 'volunteer_id' => $gwc_vt_marcus ) );
gwc_vt_seed_signup( $gwc_vt_short, array( 'volunteer_id' => $gwc_vt_priya ) );

/* Full, with somebody waiting — so the waiting list has something in it. */
$gwc_vt_full = gwc_vt_seed_shift(
	$gwc_vt_next_sat,
	'09:00',
	'12:00',
	'Packing weekend boxes',
	array(
		'min' => 2,
		'max' => 2,
	)
);

gwc_vt_seed_signup( $gwc_vt_full, array( 'volunteer_id' => $gwc_vt_marcus ) );
gwc_vt_seed_signup( $gwc_vt_full, array( 'volunteer_id' => $gwc_vt_tomas ) );
gwc_vt_seed_signup( $gwc_vt_full, array( 'volunteer_id' => $gwc_vt_fatima ) );

/* A weekly series, so the schedule has some depth to scroll and the series
 * behaviour has something to act on. */
$gwc_vt_series = 0;

foreach ( gwc_vt_recurrence_dates( $gwc_vt_midweek, 'weekly', gmdate( 'Y-m-d', strtotime( $gwc_vt_midweek . ' +8 weeks' ) ) )['dates'] as $gwc_vt_date ) {
	$gwc_vt_occurrence = gwc_vt_seed_shift(
		$gwc_vt_date,
		'13:00',
		'16:00',
		'Front desk intake',
		array(
			'location' => 'Front desk',
			'min'      => 2,
			'max'      => 3,
		)
	);

	if ( 0 === $gwc_vt_series ) {
		$gwc_vt_series = $gwc_vt_occurrence;
	}

	update_post_meta( $gwc_vt_occurrence, GWC_VT_SHIFT_SERIES, $gwc_vt_series );
}

/* Already happened, with a roster, and nobody has typed the hours up yet — the
 * state the reconciliation nag is built on. */
$gwc_vt_done = gwc_vt_seed_shift(
	$gwc_vt_last_sat,
	'09:00',
	'12:00',
	'Warehouse inventory',
	array( 'min' => 3 )
);

gwc_vt_seed_signup( $gwc_vt_done, array( 'volunteer_id' => $gwc_vt_marcus ) );
gwc_vt_seed_signup( $gwc_vt_done, array( 'volunteer_id' => $gwc_vt_tomas ) );

/* Called off, and still on the schedule saying so. */
$gwc_vt_off = gwc_vt_seed_shift(
	gmdate( 'Y-m-d', strtotime( $gwc_vt_next_sat . ' +7 days' ) ),
	'09:00',
	'12:00',
	'Holiday distribution',
	array(
		'location' => 'Riverbend Community Center',
		'min'      => 4,
		'status'   => GWC_VT_SHIFT_CANCELLED,
	)
);

update_post_meta( $gwc_vt_off, GWC_VT_SHIFT_REASON, 'Community Center double-booked the hall' );

/* An overnight, because shelters run them and the arithmetic is different. */
gwc_vt_seed_shift(
	$gwc_vt_next_sat,
	'22:00',
	'06:00',
	'Overnight shelter cover',
	array(
		'overnight' => true,
		'min'       => 2,
		'max'       => 2,
	)
);

/* Somebody who is not on file yet, waiting to be matched — the signup half of
 * the triage queue. */
gwc_vt_seed_signup(
	$gwc_vt_short,
	array(
		'claim_name'  => 'Joachim Whitfeather',
		'claim_email' => 'joachim@example.test',
		'source'      => 'self',
	)
);

/* ── An event ────────────────────────────────────────────────────────────────
 * One occasion, three roles, five times. A meal service is the clearest example
 * of the shape events exist for: the same afternoon needs a kitchen, a serving
 * line and somebody on the door, each wanted at a different hour and in
 * different numbers.
 *
 * Two of the times are in the past with people on them and their hours not
 * logged, because that is the state the schedule nags about and the one the
 * roster's "Log the hours" link answers. Every slot is an ordinary shift with a
 * post_parent — that is the whole design, so nothing here is special-cased.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * One event.
 *
 * @param string $name     What it is called.
 * @param array  $meta     Description, location, supervisor.
 * @return int
 */
function gwc_vt_seed_event( string $name, array $meta = array() ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_EVENT_TYPE,
			'post_status' => (string) ( $meta['status'] ?? 'publish' ),
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWC_VT_SEED_MARK, 1 );
	update_post_meta( $id, GWC_VT_EVENT_DESCRIPTION, (string) ( $meta['description'] ?? '' ) );
	update_post_meta( $id, GWC_VT_EVENT_LOCATION, (string) ( $meta['location'] ?? 'Riverbend Community Center' ) );
	update_post_meta( $id, GWC_VT_EVENT_SUPERVISOR, (string) ( $meta['supervisor'] ?? 'Dana Reyes' ) );

	return $id;
}

/**
 * One time within an event, in a role.
 *
 * @param int    $event_id The event.
 * @param string $role     What the work is. Printed on a letter, so named as work.
 * @param string $date     Y-m-d.
 * @param string $start    H:i.
 * @param string $end      H:i.
 * @param array  $extra    min, max, location, supervisor.
 * @return int
 */
function gwc_vt_seed_slot( int $event_id, string $role, string $date, string $start, string $end, array $extra = array() ): int {
	/* Empty rather than the shift helper's warehouse defaults, so a slot inherits
	 * the event's address and supervisor unless this call overrides them. The
	 * cascade is most of what the role-major grid is for, and a fixture whose
	 * every slot names its own location shows it doing nothing. */
	$extra += array(
		'location'   => '',
		'supervisor' => '',
	);

	$id = gwc_vt_seed_shift( $date, $start, $end, $role, $extra );

	wp_update_post(
		array(
			'ID'          => $id,
			'post_parent' => $event_id,
		)
	);

	return $id;
}

$gwc_vt_event = gwc_vt_seed_event(
	'Thanksgiving meal service',
	array(
		'description' => 'We serve about three hundred people across the afternoon. Come for one shift or stay for the day — the kitchen starts long before the doors open.',
		'location'    => 'Riverbend Community Center',
	)
);

/* Two days ago, so the afternoon has happened and its hours are waiting. */
$gwc_vt_event_past = gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' -2 days' ) );

/* Kitchen: the long one, and the one nobody wants at seven in the morning. */
$gwc_vt_kitchen_am = gwc_vt_seed_slot(
	$gwc_vt_event,
	'Kitchen preparation',
	$gwc_vt_event_past,
	'07:00',
	'11:00',
	array( 'min' => 4, 'max' => 6, 'location' => 'Community Center kitchen' )
);

$gwc_vt_kitchen_pm = gwc_vt_seed_slot(
	$gwc_vt_event,
	'Kitchen preparation',
	$gwc_vt_event_past,
	'11:00',
	'15:00',
	array( 'min' => 3, 'max' => 5, 'location' => 'Community Center kitchen' )
);

/* Serving: two sittings, the second still short of people. */
$gwc_vt_serving_1 = gwc_vt_seed_slot(
	$gwc_vt_event,
	'Serving the meal',
	$gwc_vt_event_past,
	'12:00',
	'14:30',
	array( 'min' => 6, 'max' => 8 )
);

$gwc_vt_serving_2 = gwc_vt_seed_slot(
	$gwc_vt_event,
	'Serving the meal',
	$gwc_vt_event_past,
	'14:30',
	'17:00',
	array( 'min' => 6, 'max' => 8 )
);

/* Welcome desk: one person, all afternoon. */
$gwc_vt_welcome = gwc_vt_seed_slot(
	$gwc_vt_event,
	'Welcome desk',
	$gwc_vt_event_past,
	'11:30',
	'17:00',
	array( 'min' => 1, 'max' => 2, 'supervisor' => 'Priya Ramanathan' )
);

/* An event's own dates are derived from its slots and are what the schedule
 * queries on, so nothing above is findable until this runs. The admin's grid
 * handler calls it after every save; a fixture that writes slots directly has to
 * call it itself, or it builds an event that exists and cannot be found. */
gwc_vt_event_refresh_dates( $gwc_vt_event );

/* Who came. Marcus is on two times that overlap, which is what the roster's
 * clash warning is for — it is not blocked, it is pointed out. */
gwc_vt_seed_signup( $gwc_vt_kitchen_am, array( 'volunteer_id' => $gwc_vt_marcus ) );
gwc_vt_seed_signup( $gwc_vt_kitchen_am, array( 'volunteer_id' => $gwc_vt_tomas ) );
gwc_vt_seed_signup( $gwc_vt_kitchen_pm, array( 'volunteer_id' => $gwc_vt_tomas ) );
gwc_vt_seed_signup( $gwc_vt_serving_1, array( 'volunteer_id' => $gwc_vt_marcus ) );
gwc_vt_seed_signup( $gwc_vt_serving_1, array( 'volunteer_id' => $gwc_vt_priya ) );
gwc_vt_seed_signup( $gwc_vt_serving_1, array( 'volunteer_id' => $gwc_vt_fatima ) );
gwc_vt_seed_signup( $gwc_vt_welcome, array( 'volunteer_id' => $gwc_vt_priya ) );

/* Somebody who is not on file, on the event too — triage reaches here as well. */
gwc_vt_seed_signup(
	$gwc_vt_serving_2,
	array(
		'claim_name'  => 'Rosalind Achterberg',
		'claim_email' => 'rosalind@example.test',
		'source'      => 'self',
	)
);

/* The page the grid is on. Without one the event is unreachable, which is
 * exactly what the editor's "Where volunteers see this" row now says — so the
 * fixture has to build the good case for a screenshot to show it. */
$gwc_vt_event_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Thanksgiving meal service',
		'post_name'    => 'thanksgiving-meal-service',
		'post_content' => '<!-- wp:groundwork-common-volunteer-tracker/event-grid {"eventId":' . $gwc_vt_event . '} /-->',
	)
);

update_post_meta( $gwc_vt_event_page, GWC_VT_SEED_MARK, 1 );

/* The lookup caches per generation, and this script has just written a page. */
if ( function_exists( 'gwc_vt_flush_event_page_cache' ) ) {
	gwc_vt_flush_event_page_cache();
}

/* ── One letter already issued ───────────────────────────────────────────────
 * So the log has a row and the reference checker has something to check.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_letter = gwc_vt_build_letter( $gwc_vt_marcus );
$gwc_vt_record = 0;

if ( $gwc_vt_letter instanceof GWC_VT_Letter ) {
	$gwc_vt_record = gwc_vt_log_letter( $gwc_vt_letter, 'print' );
	update_post_meta( $gwc_vt_record, GWC_VT_SEED_MARK, 1 );
}

/* ── What was made ───────────────────────────────────────────────────────── */

echo "\nRiverbend Food Bank is seeded.\n\n";

printf( "  %-22s %s\n", 'Volunteers', '6' );
printf(
	"  %-22s %s\n",
	'Thanksgiving',
	sprintf(
		'an event — %d roles, %d times, %d waiting to be logged',
		count( gwc_vt_event_roles( $gwc_vt_event ) ),
		count( gwc_vt_event_slot_ids( $gwc_vt_event ) ),
		count( gwc_vt_event_unlogged_slot_ids( $gwc_vt_event ) )
	)
);
printf( "  %-22s %s\n", 'Marcus Delacroix', gwc_vt_format_hours( gwc_vt_compute_totals( $gwc_vt_marcus )->verified_minutes ) . ' h verified — ready for a letter, ' . gwc_vt_requirement_label( $gwc_vt_marcus ) . ' court-ordered' );
printf( "  %-22s %s\n", 'Priya Ramanathan', 'a mix of verified and waiting' );
printf( "  %-22s %s\n", 'Tomás Beaulieu', 'nothing verified yet — ' . gwc_vt_requirement_label( $gwc_vt_tomas ) . ', ' . strtolower( gwc_vt_requirement_deadline_label( $gwc_vt_tomas ) ) );
printf( "  %-22s %s\n", 'Fatima Sørensen', 'verified, but no email — print only' );
printf( "  %-22s %s\n", 'Inès Okonkwo', 'dormant since 2023 — due under the 2-year policy' );
printf( "  %-22s %s\n", 'Wendell Achebe', 'dormant, but on a retention hold' );
printf( "  %-22s %s\n", 'Awaiting verification', gwc_vt_unverified_count() );
printf( "  %-22s %s\n", 'Self-logged, unmatched', '2' );

echo "\n";
printf( "  %-22s %s\n", 'This Saturday', gwc_vt_shift_fill_label( $gwc_vt_short ) . ' — short of people, plus one unmatched signup' );
printf( "  %-22s %s\n", 'Next Saturday', gwc_vt_shift_fill_label( $gwc_vt_full ) . ' — full, one on the waiting list' );
printf( "  %-22s %s\n", 'Front desk', 'a weekly series, nine occurrences' );
printf( "  %-22s %s\n", 'Last Saturday', gwc_vt_shift_fill_label( $gwc_vt_done ) . ' — happened, hours not logged yet' );
printf( "  %-22s %s\n", 'Holiday distribution', 'cancelled, still on the schedule' );
printf( "  %-22s %s\n", 'Overnight cover', '22:00–06:00, ends the next day' );

if ( $gwc_vt_record ) {
	printf( "  %-22s %s\n", 'Letter on file', $gwc_vt_letter->reference );
}

echo "\n  Admin     ", admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ), "\n";
echo "  Letters   ", admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE . '&page=' . GWC_VT_LETTERS_PAGE ), "\n";
echo "  Schedule  ", admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE . '&page=' . GWC_VT_SCHEDULE_PAGE ), "\n";
echo "  Form      ", get_permalink( $gwc_vt_page ), "\n";
echo "  Sign up   ", get_permalink( $gwc_vt_shift_page ), "\n\n";
echo "  Every name here is invented. See the note at the top of this file.\n";

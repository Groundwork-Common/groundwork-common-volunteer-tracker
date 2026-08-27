<?php
/**
 * A demo organization, for working on the plugin and taking screenshots.
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
 * That works when the fixture is "three organizations and a user". Here the
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
		/* Every type this script writes. A type missing from this list is one
		 * whose rows survive a re-run and accumulate — which would quietly
		 * break the "re-runnable" promise at the top of this file, and does not
		 * show up until somebody wonders why the offers queue has thirty
		 * identical Rosalinds in it. */
		'post_type'      => array( GWC_VT_ENTRY_TYPE, GWC_VT_VOLUNTEER_TYPE, GWC_VT_LETTER_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_EVENT_TYPE, GWC_VT_SIGNUP_TYPE, GWC_VT_APPLICATION_TYPE, GWC_VT_CREDENTIAL_TYPE, GWC_VT_RECORD_TYPE, 'page' ),
		/* Every registered status, and NOT 'any'.
		 *
		 * 'any' means "every status that is not exclude_from_search", and all
		 * six of this plugin's custom statuses set that flag — cancelled,
		 * ev_cancelled, waitlist, withdrawn, discarded, cr_retired. So the
		 * clear-out silently skipped them and this script's re-runnable promise
		 * had been broken for as long as those statuses have existed: a run that
		 * reported removing eighty-five records left twenty-three discarded
		 * offers, twelve cancelled shifts and eleven waiting-list signups behind
		 * it, and every re-run added more. The offers queue really did fill up
		 * with identical Rosalinds; the comment above was describing something
		 * that was already happening.
		 *
		 * Asked of the registry rather than listed, so a status added later is
		 * covered without anybody having to remember this. */
		'post_status'    => array_values( get_post_stati() ),
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

/* ── The organization ────────────────────────────────────────────────────────
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

$gwc_vt_offer_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Offer to volunteer',
		'post_name'    => 'offer-to-volunteer',
		'post_content' => "<!-- wp:paragraph --><p>Would you like to help? Tell us a little about yourself and somebody will be in touch.</p><!-- /wp:paragraph -->\n<!-- wp:groundwork-common-volunteer-tracker/volunteer-form /-->",
	)
);
update_post_meta( $gwc_vt_offer_page, GWC_VT_SEED_MARK, 1 );

$gwc_vt_signin_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Your volunteering',
		'post_name'    => 'your-volunteering',
		'post_content' => "<!-- wp:paragraph --><p>Already volunteer with us? Sign in to see your hours and what you are down for.</p><!-- /wp:paragraph -->\n<!-- wp:groundwork-common-volunteer-tracker/volunteer-signin /-->",
	)
);
update_post_meta( $gwc_vt_signin_page, GWC_VT_SEED_MARK, 1 );

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
		 * thing in every screenshot. Two years, anonymize, from the last shift. */
		'retention_months'   => 24,
		'retention_action'   => 'anonymize',
		'retention_anchor'   => 'last_entry',
		'retention_decided'  => true,

		'self_log_enabled' => true,
		'self_log_page'    => $gwc_vt_page,

		/* Both switches on, which is NOT the shipped default for either — the
		 * form is off on a new install and the required-service question is off
		 * on top of that. Turned on here because this fixture exists to show the
		 * plugin doing its job, and a demo of a queue with nothing that can
		 * reach it is a demo of an empty screen.
		 *
		 * The question is on for the same reason the seed has a court-ordered
		 * volunteer in it at all: mandated service is the case this plugin is
		 * built around, and a fixture that quietly avoided the sensitive half
		 * would be showing an easier product than the real one. */
		'registration_enabled'      => true,
		'registration_page'         => $gwc_vt_offer_page,
		'registration_ask_required' => true,
		'registration_ask_photo'    => true,

		/* On, like the other public surfaces here, because the fixture exists to
		 * show the plugin working. Off is the shipped default.
		 *
		 * Every seeded volunteer except Fatima Sørensen has an address, so the
		 * demo has somebody who can sign in and somebody who cannot — which is
		 * the case worth being able to see, since a volunteer with no address on
		 * file has no way in and a coordinator has to roster them by hand. */
		'signin_enabled'            => true,
		'signin_page'               => $gwc_vt_signin_page,

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

/* ── Credentials ─────────────────────────────────────────────────────────────
 * Three, covering the three shapes an organization actually has: one that never
 * expires and is signed once, one that runs out and is the reason the feature
 * exists, and one on a longer cycle. Plus a fourth that has been retired, so
 * the screen shows what "we stopped asking for this, and we kept the records"
 * looks like — the state that is impossible to picture from the form alone.
 *
 * Deliberately NOT everybody holding everything. The states worth looking at
 * are the awkward ones: somebody lapsed, somebody who has renewed twice, and
 * somebody who has never been recorded at all.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A credential.
 *
 * @param string $name   What it is.
 * @param int    $months Renewal interval, 0 for never.
 * @param string $mode   'report' or 'block'.
 * @param string $note   A note for staff.
 * @return int
 */
function gwc_vt_seed_credential( string $name, int $months, string $mode, string $note = '' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWC_VT_CREDENTIAL_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
			'meta_input'  => array(
				GWC_VT_SEED_MARK           => 1,
				GWC_VT_CREDENTIAL_MONTHS   => $months,
				GWC_VT_CREDENTIAL_MODE     => $mode,
				GWC_VT_CREDENTIAL_NOTE     => $note,
			),
		)
	);

	return $id;
}

/**
 * A grant of one, dated relative to today.
 *
 * Relative, never absolute. A fixed date is a fixture that is correct the week
 * it is written and quietly stops demonstrating anything a year later — a
 * "lapsed" class that becomes lapsed by six years reads as broken data rather
 * than as the state it is there to show.
 *
 * @param int    $volunteer_id  Who holds it.
 * @param int    $credential_id What they hold.
 * @param string $offset        A strtotime modifier from today, e.g. '-14 months'.
 * @return int
 */
function gwc_vt_seed_record( int $volunteer_id, int $credential_id, string $offset ): int {
	$record_id = gwc_vt_record_credential(
		$volunteer_id,
		$credential_id,
		gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' ' . $offset ) ),
		1
	);

	if ( is_wp_error( $record_id ) ) {
		printf( "  ! could not record a credential: %s\n", $record_id->get_error_message() );
		return 0;
	}

	update_post_meta( (int) $record_id, GWC_VT_SEED_MARK, 1 );

	return (int) $record_id;
}

$gwc_vt_waiver = gwc_vt_seed_credential(
	'Liability waiver',
	0,
	'block',
	'Paper form in the blue folder at the front desk. Scan it before filing.'
);

$gwc_vt_safety = gwc_vt_seed_credential(
	'Child safety class',
	12,
	'block',
	'Booked through the county — Trudy has the schedule.'
);

$gwc_vt_food = gwc_vt_seed_credential(
	'Food handler card',
	24,
	'report',
	'Only needed for the kitchen and the meal service. Anyone can take it online.'
);

$gwc_vt_forklift = gwc_vt_seed_credential(
	'Forklift certification',
	36,
	'block',
	'We stopped running the pallet racking in 2025, so nobody is asked for this now.'
);

// Everything in order. The boring case, and the one most volunteers are in.
gwc_vt_seed_record( $gwc_vt_marcus, $gwc_vt_waiver, '-8 months' );
gwc_vt_seed_record( $gwc_vt_marcus, $gwc_vt_safety, '-5 months' );
gwc_vt_seed_record( $gwc_vt_marcus, $gwc_vt_food, '-8 months' );

/* Lapsed, and the reason the dashboard has a line. The waiver is fine, so this
 * is one credential out of two — not a volunteer with nothing on file, which is
 * a different and much less interesting problem. */
gwc_vt_seed_record( $gwc_vt_priya, $gwc_vt_waiver, '-3 years' );
gwc_vt_seed_record( $gwc_vt_priya, $gwc_vt_safety, '-14 months' );

/* Renewed twice, so the history on her record reads as a history rather than a
 * single row. The oldest two have both run out and neither is a problem — which
 * is exactly what a coordinator has to be able to see at a glance. */
gwc_vt_seed_record( $gwc_vt_fatima, $gwc_vt_waiver, '-4 years' );
gwc_vt_seed_record( $gwc_vt_fatima, $gwc_vt_safety, '-38 months' );
gwc_vt_seed_record( $gwc_vt_fatima, $gwc_vt_safety, '-25 months' );
gwc_vt_seed_record( $gwc_vt_fatima, $gwc_vt_safety, '-2 months' );

// Nothing recorded at all. Brand new, and nobody has got to it yet.
gwc_vt_seed_record( $gwc_vt_tomas, $gwc_vt_waiver, '-6 days' );

/* Held a credential the organization has since retired. Retiring it after the
 * record is written is the order that matters — it is what proves the record
 * survived, which is the whole point of retiring rather than deleting. */
gwc_vt_seed_record( $gwc_vt_wendell, $gwc_vt_forklift, '-2 years' );

wp_update_post(
	array(
		'ID'          => $gwc_vt_forklift,
		'post_status' => GWC_VT_CREDENTIAL_RETIRED,
	)
);

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

/* The coming Saturday, and the ones either side of it.
 *
 * "next saturday" and "last saturday", NOT "saturday this week".
 *
 * PHP's week runs Monday to Sunday, so on a SUNDAY "saturday this week" is
 * yesterday — and the shift below it, the one labelled "short of people, and
 * soon" and written to be the row the schedule screen exists to surface, was a
 * shift that had already happened. Seed the site on a Sunday and the whole
 * fixture quietly described last week.
 *
 * The two relative formats used instead are strict in both directions on every
 * day of the week, Saturday included: "last saturday" is always before today
 * and "next saturday" always after, so the past one has always happened and the
 * coming one never has. On a Saturday that means the coming one is a week out,
 * which is right — a shift starting at nine this morning is not a shift anybody
 * still needs people for. */
$gwc_vt_saturday = gmdate( 'Y-m-d', strtotime( 'next saturday', strtotime( gwc_vt_today() ) ) );
$gwc_vt_next_sat = gmdate( 'Y-m-d', strtotime( $gwc_vt_saturday . ' +7 days' ) );
$gwc_vt_last_sat = gmdate( 'Y-m-d', strtotime( 'last saturday', strtotime( gwc_vt_today() ) ) );
/* Two days out, and never the same day as that Saturday.
 *
 * This was "+3 days", which lands ON the Saturday whenever the seed is run on a
 * Wednesday — putting every shift in the coming week onto a single date. It
 * looked fine for a year: the schedule screen shows a fortnight and did not
 * care, and the variable is called midweek, so nobody read it as a collision.
 *
 * What noticed was the dashboard widget, which shows the next two DAYS that
 * have anything on them and could only ever find one. A fixture that cannot
 * demonstrate the thing it is a fixture for is worse than no fixture: it makes
 * a working feature look broken. */
$gwc_vt_midweek = gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +2 days' ) );

if ( $gwc_vt_midweek === $gwc_vt_saturday ) {
	$gwc_vt_midweek = gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +1 day' ) );
}

/* ── The dates have to be true whatever day this is run on ───────────────────
 * A fixture whose arithmetic is wrong one day in seven is worse than a wrong
 * fixture, because six days out of seven it looks right. The Sunday bug above
 * survived a long time on exactly that: nobody seeds on a Sunday.
 *
 * So the invariants are asserted here rather than trusted. This stops the run
 * instead of building a site that describes last week — and it fires on the day
 * it matters, which no test anybody runs on a Wednesday would.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_date_rules = array(
	'last Saturday has already happened'      => $gwc_vt_last_sat < gwc_vt_today(),
	'the coming Saturday has not'             => $gwc_vt_saturday > gwc_vt_today(),
	'the one after that is after it'          => $gwc_vt_next_sat > $gwc_vt_saturday,
	'the midweek shift is still to come'      => $gwc_vt_midweek > gwc_vt_today(),
	'and is not the same day as the Saturday' => $gwc_vt_midweek !== $gwc_vt_saturday,
	'and is inside the coming week'           => $gwc_vt_midweek <= gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +7 days' ) ),
);

$gwc_vt_date_broken = array_keys( array_filter( $gwc_vt_date_rules, static function ( $gwc_vt_ok ) { return ! $gwc_vt_ok; } ) );

if ( $gwc_vt_date_broken ) {
	printf(
		"\nThe seed's dates are wrong for a %s. Nothing was written.\n\n",
		gmdate( 'l', strtotime( gwc_vt_today() ) )
	);

	foreach ( $gwc_vt_date_broken as $gwc_vt_rule ) {
		echo '  not true: ', $gwc_vt_rule, "\n";
	}

	printf(
		"\n  today %s   last %s   midweek %s   saturday %s   next %s\n\n",
		gwc_vt_today(),
		$gwc_vt_last_sat,
		$gwc_vt_midweek,
		$gwc_vt_saturday,
		$gwc_vt_next_sat
	);

	exit( 1 );
}

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
 * behavior has something to act on. */
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

/* ── What the day asks people to hold ────────────────────────────────────────
 * The waiver on the whole event, the food handler card on the kitchen only.
 * That pair is the case the union exists for: under "most specific wins" the
 * kitchen would silently stop asking for the waiver, which is the role most
 * likely to need it.
 *
 * The result on screen is worth looking at, because it is the whole of what
 * report mode does. Priya is on Serving and on Welcome, and her class lapsed —
 * but the class is not asked for here, so she is flagged for nothing. Tomás is
 * in the kitchen holding only the waiver, so he is flagged for the food handler
 * card on both kitchen times and not on anything else. Marcus holds all three
 * and is flagged nowhere. Rosalind is not matched to a record at all, so her
 * row says nothing and the roster counts her once above the table.
 * ─────────────────────────────────────────────────────────────────────────── */
gwc_vt_set_shift_credentials( $gwc_vt_event, array( $gwc_vt_waiver ) );
gwc_vt_set_shift_credentials( $gwc_vt_kitchen_am, array( $gwc_vt_food ) );
gwc_vt_set_shift_credentials( $gwc_vt_kitchen_pm, array( $gwc_vt_food ) );

/* ── And what the blocking mode does ─────────────────────────────────────────
 * The waiver blocks, so any shift asking for it is closed to anybody who has
 * not signed in — that is phase 4's whole point, and it is why the two Saturdays
 * below ask for different things.
 *
 * THIS Saturday asks only for the food handler card, which reports. The plain
 * signup form still works on it, and one of the two people on it is flagged.
 * That keeps the ordinary path — no account, no sign-in — visible, because it
 * is still how most of this plugin's signups happen.
 *
 * NEXT Saturday asks for the waiver, which blocks. The public list says so on
 * the row before anybody types anything, and the form sends them to sign in.
 * ─────────────────────────────────────────────────────────────────────────── */

/* And one ordinary shift, because the event above is deliberately in the past —
 * its hours are what the unlogged-hours line counts — and a shift that has
 * already happened flags nobody. The credentials on it still show in the
 * editor, which is what demonstrates the union; the flag needs a Saturday that
 * has not happened yet.
 *
 * This Saturday, then, which is also the one already short of people — exactly
 * where a coordinator is looking when it matters. Marcus and Priya are on it:
 * both hold the waiver, only Marcus holds the food handler card. So the roster
 * draws ONE flag on TWO rows, which is the restraint this feature is supposed
 * to have, visible rather than described. The unmatched signup on the same
 * shift makes the "nothing can be checked for them" sentence appear too. */
gwc_vt_set_shift_credentials( $gwc_vt_short, array( $gwc_vt_food ) );

/* The gated one. Marcus and Fatima hold the waiver and Tomás does not, so his
 * row carries the override rather than a flag — recorded by a real person with
 * a real sentence, which is the only form of override this plugin has. */
gwc_vt_set_shift_credentials( $gwc_vt_full, array( $gwc_vt_waiver ) );

foreach ( gwc_vt_shift_signup_ids( $gwc_vt_full, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $gwc_vt_seed_signup_id ) {
	$gwc_vt_seed_who = (int) get_post_meta( (int) $gwc_vt_seed_signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );

	if ( $gwc_vt_seed_who === $gwc_vt_tomas ) {
		gwc_vt_record_override(
			(int) $gwc_vt_seed_signup_id,
			1,
			'Waiver signed on paper at the desk — scanning it Monday'
		);
	}
}

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

/* ── Applications ────────────────────────────────────────────────────────────
 * Four, covering the states the queue actually draws rather than four
 * variations of "somebody left their name". Written as applications and not as
 * volunteers, which is the whole point of the feature: nothing here is a
 * volunteer record until a person presses the button.
 *
 * Dated backwards so the queue's oldest-first order is visible — somebody who
 * offered three weeks ago and heard nothing is who that ordering is for, and a
 * fixture where everything arrived this morning cannot show it.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A stand-in picture for an offer, and deliberately not a face.
 *
 * The note at the top of this file says the fixtures are obviously fictional,
 * because a screenshot of a real volunteer beside their court-referral status
 * is a disclosure nobody can take back. A synthesised photograph of a person
 * who does not exist fails that test in a different direction: it looks exactly
 * like a real disclosure, it is indistinguishable from one at a glance, and it
 * puts a fabricated human face on a demo of a plugin about mandated service.
 *
 * So these are flat shapes in the plugin's own colours. They show that a
 * picture is stored, that it is served through the endpoint and that it moves
 * with the record — which is the whole of what the fixture needs to
 * demonstrate — and nobody looking at one could mistake it for somebody.
 *
 * @param int    $application_id The offer.
 * @param string $seed           Anything; picks the colour.
 * @return bool Whether a picture was made and stored.
 */
function gwc_vt_seed_placeholder_photo( int $application_id, string $seed ): bool {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		/* No GD, no placeholder. The offer is still seeded and the feature is
		 * still switched on; there is simply no picture on that row. */
		return false;
	}

	$size  = 480;
	$image = imagecreatetruecolor( $size, $size );
	$tint  = hexdec( substr( md5( $seed ), 0, 2 ) ) % 3;

	$backs = array( array( 44, 90, 160 ), array( 60, 67, 74 ), array( 34, 113, 177 ) );
	$back  = $backs[ $tint ];

	imagefilledrectangle( $image, 0, 0, $size, $size, imagecolorallocate( $image, $back[0], $back[1], $back[2] ) );

	$fore = imagecolorallocate( $image, 230, 200, 120 );

	/* A circle over a rounded block: the shape of an avatar placeholder, at no
	 * point resembling a particular person. */
	imagefilledellipse( $image, (int) ( $size / 2 ), (int) ( $size * 0.38 ), (int) ( $size * 0.34 ), (int) ( $size * 0.34 ), $fore );
	imagefilledellipse( $image, (int) ( $size / 2 ), (int) ( $size * 1.05 ), (int) ( $size * 0.62 ), (int) ( $size * 0.62 ), $fore );

	$path = get_temp_dir() . 'gwcvt-seed-' . wp_generate_password( 8, false ) . '.jpg';

	imagejpeg( $image, $path, 88 );
	imagedestroy( $image );

	/* Written through the image editor and the meta key rather than through
	 * gwc_vt_store_photo(), which refuses anything is_uploaded_file() rejects —
	 * correctly, and that is every file a script can make. The bytes still go
	 * through the same resize and re-encode the real path uses. */
	$dir    = gwc_vt_photo_dir();
	$editor = '' !== $dir ? wp_get_image_editor( $path ) : null;

	if ( ! $editor || is_wp_error( $editor ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- a temp file this function just made; nothing depends on the outcome.
		return false;
	}

	$editor->resize( GWC_VT_PHOTO_MAX_EDGE, GWC_VT_PHOTO_MAX_EDGE, false );

	$saved = $editor->save( $dir . wp_generate_password( 32, false ) . '.jpg', 'image/jpeg' );

	@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- as above.

	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return false;
	}

	update_post_meta( $application_id, GWC_VT_PHOTO_KEY, basename( (string) $saved['path'] ) );

	return true;
}

/**
 * One offer to volunteer.
 *
 * @param array $offer name, email, phone, note, days, required, by, for, status.
 * @return int
 */
function gwc_vt_seed_offer( array $offer ): int {
	$when = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) ( $offer['days'] ?? 1 ) . ' days' ) );

	$id = (int) wp_insert_post(
		array(
			'post_type'     => GWC_VT_APPLICATION_TYPE,
			'post_status'   => (string) ( $offer['status'] ?? 'pending' ),
			'post_title'    => (string) $offer['name'],
			'post_date'     => $when,
			'post_date_gmt' => $when,
		)
	);

	update_post_meta( $id, GWC_VT_SEED_MARK, 1 );
	update_post_meta( $id, GWC_VT_APPLICATION_NAME, (string) $offer['name'] );
	update_post_meta( $id, GWC_VT_APPLICATION_EMAIL, (string) $offer['email'] );
	update_post_meta( $id, GWC_VT_APPLICATION_PHONE, (string) ( $offer['phone'] ?? '' ) );
	update_post_meta( $id, GWC_VT_APPLICATION_NOTE, (string) ( $offer['note'] ?? '' ) );
	update_post_meta( $id, GWC_VT_APPLICATION_CREATED, $when );

	if ( ! empty( $offer['required'] ) ) {
		update_post_meta( $id, GWC_VT_APPLICATION_REQUIRED, (int) $offer['required'] );
		update_post_meta( $id, GWC_VT_APPLICATION_REQUIRED_BY, (string) ( $offer['by'] ?? '' ) );
		update_post_meta( $id, GWC_VT_APPLICATION_REQUIRED_FOR, (string) ( $offer['for'] ?? '' ) );
	}

	return $id;
}

// Three weeks old, and nobody has answered. The reason the queue is oldest-first.
$gwc_vt_offer_rosalind = gwc_vt_seed_offer(
	array(
		'name'  => 'Rosalind Achebe',
		'email' => 'rosalind@example.test',
		'phone' => '(555) 0164',
		'note'  => 'Recently retired, free most weekday mornings. I drove a delivery van for eleven years if that is any use.',
		'days'  => 21,
	)
);

gwc_vt_seed_placeholder_photo( $gwc_vt_offer_rosalind, 'rosalind' );

// Court-ordered, which is the one that changes how the conversation goes.
$gwc_vt_offer_teodoro = gwc_vt_seed_offer(
	array(
		'name'     => 'Teodoro Vasquez',
		'email'    => 'teodoro@example.test',
		'phone'    => '(555) 0198',
		'note'     => 'Weekends are easiest for me. Happy to do anything.',
		'days'     => 6,
		'required' => 50 * 60,
		'by'       => gmdate( 'Y-m-d', strtotime( '+4 months' ) ),
		'for'      => 'Riverbend Municipal Court',
	)
);

gwc_vt_seed_placeholder_photo( $gwc_vt_offer_teodoro, 'teodoro' );

/* Yesterday, and nothing but a name and an address — no note, no requirement
 * and no picture. The row the queue has to render without any of it. */
gwc_vt_seed_offer(
	array(
		'name'  => 'Junie Halloran',
		'email' => 'junie@example.test',
		'days'  => 1,
	)
);

/* Already dealt with, so the queue is not the only state anybody sees. Neither
 * appears in the pending list; both are still findable by the privacy tools,
 * which is the point of discarding rather than deleting. */
gwc_vt_seed_offer(
	array(
		'name'   => 'Somebody Nobody Answered',
		'email'  => 'noreply@example.test',
		'note'   => 'aaaaa aaaaa buy followers aaaaa',
		'days'   => 34,
		'status' => GWC_VT_APPLICATION_DISCARDED,
	)
);

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
printf( "  %-22s %s\n", 'Applications', gwc_vt_pending_application_count() . ' waiting — one court-ordered, one three weeks old, two with a picture' );
printf( "  %-22s %s\n", 'Credentials', count( gwc_vt_live_credential_ids() ) . ' asked for, 1 retired — ' . count( gwc_vt_lapsed_credential_ids() ) . ' volunteer with one that has lapsed' );
printf( "  %-22s %s\n", 'Asked for on the day', 'a waiver across Thanksgiving, a food handler card in the kitchen' );
printf( "  %-22s %s\n", 'Flagged this Saturday', 'one of the two people on it is short of the food handler card' );
printf( "  %-22s %s\n", 'Front desk', gwc_vt_shift_date_label_from( $gwc_vt_midweek ) . ' — a different day, so the widget has two to show' );
printf( "  %-22s %s\n", 'Next Saturday', 'asks for the waiver, which blocks — signed-in volunteers only' );
printf( "  %-22s %s\n", 'One override on it', 'Tomás, put on anyway, with the reason recorded' );
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
echo "  Sign up   ", get_permalink( $gwc_vt_shift_page ), "\n";
echo "  Offer     ", get_permalink( $gwc_vt_offer_page ), "\n";
echo "  Sign in   ", get_permalink( $gwc_vt_signin_page ), "\n\n";
echo "  Every name here is invented. See the note at the top of this file.\n";

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
const GWCVT_SEED_MARK = '_gwcvt_seed';

/* ── Refuse to run anywhere that matters ─────────────────────────────────────
 * A seed script is a script that deletes things and writes settings. Pointed at
 * a live nonprofit's site it would overwrite their letterhead and their
 * retention policy. The guard is deliberately conservative: it runs on a local
 * or development environment and nowhere else, whatever anybody types.
 * ─────────────────────────────────────────────────────────────────────────── */
$gwcvt_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

if ( ! in_array( $gwcvt_env, array( 'local', 'development' ), true ) ) {
	echo "Refusing to seed: WP_ENVIRONMENT_TYPE is '", $gwcvt_env, "'.\n";
	echo "This script writes settings and deletes records. It runs on local and development only.\n";
	exit( 1 );
}

wp_set_current_user( 1 );

/* ── Clear the previous run ──────────────────────────────────────────────── */

$gwcvt_previous = get_posts(
	array(
		'post_type'      => array( GWCVT_ENTRY_TYPE, GWCVT_VOLUNTEER_TYPE, GWCVT_LETTER_TYPE, 'page' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a development fixture, run by hand.
		'meta_key'       => GWCVT_SEED_MARK,
	)
);

foreach ( $gwcvt_previous as $gwcvt_id ) {
	wp_delete_post( (int) $gwcvt_id, true );
}

printf( "Removed %d records from a previous run.\n", count( $gwcvt_previous ) );

/* ── Helpers ─────────────────────────────────────────────────────────────── */

/**
 * Create a volunteer.
 *
 * @param string $name  Display name.
 * @param string $email Email address, or '' for none.
 * @param string $phone Phone number.
 * @return int
 */
function gwcvt_seed_volunteer( string $name, string $email = '', string $phone = '' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWCVT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	update_post_meta( $id, GWCVT_SEED_MARK, 1 );

	if ( '' !== $email ) {
		update_post_meta( $id, GWCVT_VOLUNTEER_EMAIL, $email );
	}

	if ( '' !== $phone ) {
		update_post_meta( $id, GWCVT_VOLUNTEER_PHONE, $phone );
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
function gwcvt_seed_entry( int $volunteer_id, string $date, string $hours, string $activity, bool $verified, array $claim = array() ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => GWCVT_ENTRY_TYPE,
			'post_status' => $claim ? 'pending' : 'publish',
			'post_title'  => 'seeding',
		)
	);

	update_post_meta( $id, GWCVT_SEED_MARK, 1 );
	update_post_meta( $id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $id, GWCVT_ENTRY_MINUTES, (int) gwcvt_parse_hours( $hours ) );
	update_post_meta( $id, GWCVT_ENTRY_ACTIVITY, $activity );
	update_post_meta( $id, GWCVT_ENTRY_SUPERVISOR, 'Dana Reyes' );
	update_post_meta( $id, GWCVT_ENTRY_SOURCE, $claim ? 'self' : 'staff' );

	if ( isset( $claim['name'] ) ) {
		update_post_meta( $id, '_gwcvt_claim_name', $claim['name'] );
	}

	if ( isset( $claim['email'] ) ) {
		update_post_meta( $id, '_gwcvt_claim_email', $claim['email'] );
	}

	if ( $verified ) {
		gwcvt_verify_entry( $id, 1 );
	}

	gwcvt_retitle_entry( $id );

	return $id;
}

/* ── The organisation ────────────────────────────────────────────────────── */

$gwcvt_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Log your hours',
		'post_name'    => 'log-your-hours',
		'post_content' => "<!-- wp:paragraph --><p>Worked a shift with us? Send us your hours and we'll check them against our records.</p><!-- /wp:paragraph -->\n<!-- wp:groundwork-common-volunteer-tracker/hours-form /-->",
	)
);
update_post_meta( $gwcvt_page, GWCVT_SEED_MARK, 1 );

update_option(
	GWCVT_SETTINGS_OPTION,
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
		'self_log_page'    => $gwcvt_page,
	)
);
gwcvt_settings_cache( null, true );

/* ── The people ──────────────────────────────────────────────────────────────
 * Chosen to cover the states the plugin actually has to handle, rather than six
 * variations of "a volunteer with some hours".
 * ─────────────────────────────────────────────────────────────────────────── */

// Working off a court order, most of the way through. The letter case.
$gwcvt_marcus = gwcvt_seed_volunteer( 'Marcus Delacroix', 'marcus@example.test', '(555) 0177' );

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
	) as $gwcvt_shift
) {
	gwcvt_seed_entry( $gwcvt_marcus, $gwcvt_shift[0], $gwcvt_shift[1], $gwcvt_shift[2], true );
}

// A regular volunteer, with two shifts still waiting to be checked.
$gwcvt_priya = gwcvt_seed_volunteer( 'Priya Ramanathan', 'priya@example.test' );

gwcvt_seed_entry( $gwcvt_priya, '2026-06-20', '2:45', 'Front desk intake', true );
gwcvt_seed_entry( $gwcvt_priya, '2026-07-04', '6', 'Holiday distribution', true );
gwcvt_seed_entry( $gwcvt_priya, '2026-07-18', '3', 'Warehouse inventory', false );
gwcvt_seed_entry( $gwcvt_priya, '2026-08-01', '3:15', 'Packing weekend boxes', false );

// Brand new. Nothing verified yet — the triage case.
$gwcvt_tomas = gwcvt_seed_volunteer( 'Tomás Beaulieu', 'tomas@example.test' );

gwcvt_seed_entry( $gwcvt_tomas, '2026-07-25', '4', 'Sorting the produce delivery', false );
gwcvt_seed_entry( $gwcvt_tomas, '2026-08-01', '3:30', 'Warehouse inventory', false );

/* Verified hours but no email on file. The letter can be printed and cannot be
 * sent, which is a state the Letters screen has to say something sensible
 * about. */
$gwcvt_fatima = gwcvt_seed_volunteer( 'Fatima Sørensen' );

gwcvt_seed_entry( $gwcvt_fatima, '2026-06-06', '5', 'Holiday distribution', true );
gwcvt_seed_entry( $gwcvt_fatima, '2026-06-20', '4:30', 'Driving the collection van', true );

// Dormant since 2023, so due under the two-year policy. The retention case.
$gwcvt_ines = gwcvt_seed_volunteer( 'Inès Okonkwo', 'ines@example.test' );

gwcvt_seed_entry( $gwcvt_ines, '2023-04-15', '3', 'Front desk intake', true );
gwcvt_seed_entry( $gwcvt_ines, '2023-05-20', '3:30', 'Packing weekend boxes', true );

// Equally dormant, and held back from the sweep by an open case.
$gwcvt_wendell = gwcvt_seed_volunteer( 'Wendell Achebe', 'wendell@example.test' );

gwcvt_seed_entry( $gwcvt_wendell, '2023-03-11', '4', 'Warehouse inventory', true );
gwcvt_seed_entry( $gwcvt_wendell, '2023-06-24', '6', 'Holiday distribution', true );

update_post_meta( $gwcvt_wendell, GWCVT_VOLUNTEER_HOLD, 1 );
update_post_meta( $gwcvt_wendell, GWCVT_VOLUNTEER_HOLD_REASON, 'Court has asked us to keep this until the case closes' );

/* Sent in through the public form and not yet matched to anybody. Two of them,
 * because the interesting part of triage is telling them apart — one names a
 * volunteer on file, one does not. */
gwcvt_seed_entry( 0, '2026-08-02', '3', 'Sorting the produce delivery', false, array( 'name' => 'Priya Ramanathan', 'email' => 'priya@example.test' ) );
gwcvt_seed_entry( 0, '2026-08-03', '2:30', 'Front desk intake', false, array( 'name' => 'Joachim Whitfeather', 'email' => 'joachim@example.test' ) );

/* ── One letter already issued ───────────────────────────────────────────────
 * So the log has a row and the reference checker has something to check.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwcvt_letter = gwcvt_build_letter( $gwcvt_marcus );
$gwcvt_record = 0;

if ( $gwcvt_letter instanceof GWCVT_Letter ) {
	$gwcvt_record = gwcvt_log_letter( $gwcvt_letter, 'print' );
	update_post_meta( $gwcvt_record, GWCVT_SEED_MARK, 1 );
}

/* ── What was made ───────────────────────────────────────────────────────── */

echo "\nRiverbend Food Bank is seeded.\n\n";

printf( "  %-22s %s\n", 'Volunteers', '6' );
printf( "  %-22s %s\n", 'Marcus Delacroix', gwcvt_format_hours( gwcvt_compute_totals( $gwcvt_marcus )->verified_minutes ) . ' h verified — ready for a letter' );
printf( "  %-22s %s\n", 'Priya Ramanathan', 'a mix of verified and waiting' );
printf( "  %-22s %s\n", 'Tomás Beaulieu', 'nothing verified yet' );
printf( "  %-22s %s\n", 'Fatima Sørensen', 'verified, but no email — print only' );
printf( "  %-22s %s\n", 'Inès Okonkwo', 'dormant since 2023 — due under the 2-year policy' );
printf( "  %-22s %s\n", 'Wendell Achebe', 'dormant, but on a retention hold' );
printf( "  %-22s %s\n", 'Awaiting verification', gwcvt_unverified_count() );
printf( "  %-22s %s\n", 'Self-logged, unmatched', '2' );

if ( $gwcvt_record ) {
	printf( "  %-22s %s\n", 'Letter on file', $gwcvt_letter->reference );
}

echo "\n  Admin     ", admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE ), "\n";
echo "  Letters   ", admin_url( 'edit.php?post_type=' . GWCVT_ENTRY_TYPE . '&page=' . GWCVT_LETTERS_PAGE ), "\n";
echo "  Form      ", get_permalink( $gwcvt_page ), "\n\n";
echo "  Every name here is invented. See the note at the top of this file.\n";

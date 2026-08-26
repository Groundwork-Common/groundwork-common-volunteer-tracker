<?php
/**
 * The capabilities, against real roles.
 *
 * The unit suite can only prove that gwc_vt_grant_capabilities() writes what it
 * says it writes into a role double. What it cannot prove — because the stub
 * does not implement map_meta_cap — is that the capabilities actually land on
 * real WordPress roles and survive being taken away and re-granted. That is
 * this script's job.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/caps.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly, everywhere, and not `global $x` alongside a bare
 * top-level `$x = 0`. `wp eval-file` runs this file inside a function, so a
 * top-level assignment is a LOCAL — while `global` in the helper below reaches
 * the real global. The two are different variables, the counter increments one
 * and the summary reads the other, and the script cheerfully prints ALL PASS
 * underneath a list of failures. That happened. */
$GLOBALS['gwc_vt_failures'] = 0;

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 */
function gwc_vt_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n";
}

/* ── They are granted ────────────────────────────────────────────────────── */

foreach ( array( 'administrator', 'editor' ) as $gwc_vt_role_name ) {
	$gwc_vt_role = get_role( $gwc_vt_role_name );

	gwc_vt_check( $gwc_vt_role_name . ' exists', (bool) $gwc_vt_role );

	if ( ! $gwc_vt_role ) {
		continue;
	}

	gwc_vt_check( $gwc_vt_role_name . ' can verify hours', $gwc_vt_role->has_cap( 'gwc_vt_verify_hours' ) );
	gwc_vt_check( $gwc_vt_role_name . ' can issue letters', $gwc_vt_role->has_cap( 'gwc_vt_issue_letters' ) );
}

/* ── And not to everybody ────────────────────────────────────────────────── */

$gwc_vt_subscriber = get_role( 'subscriber' );
gwc_vt_check(
	'subscriber cannot verify hours',
	$gwc_vt_subscriber && ! $gwc_vt_subscriber->has_cap( 'gwc_vt_verify_hours' )
);
gwc_vt_check(
	'subscriber cannot issue letters',
	$gwc_vt_subscriber && ! $gwc_vt_subscriber->has_cap( 'gwc_vt_issue_letters' )
);

/* ── A role that lost them gets them back ────────────────────────────────────
 * The reason the grant runs on init rather than on activation. remove_cap()
 * leaves exactly what a security plugin rebuilding roles, or a restore from a
 * backup taken before install, leaves behind.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_editor = get_role( 'editor' );
$gwc_vt_editor->remove_cap( 'gwc_vt_verify_hours' );

gwc_vt_check(
	'the capability really was removed',
	! get_role( 'editor' )->has_cap( 'gwc_vt_verify_hours' )
);

gwc_vt_grant_capabilities();

gwc_vt_check(
	'a lost capability is restored by the next init',
	get_role( 'editor' )->has_cap( 'gwc_vt_verify_hours' )
);

/* ── One set to false is a decision, and is respected ─────────────────────── */

$gwc_vt_editor = get_role( 'editor' );
$gwc_vt_editor->add_cap( 'gwc_vt_issue_letters', false );

gwc_vt_grant_capabilities();

gwc_vt_check(
	'a capability cleared in a role manager stays cleared',
	! get_role( 'editor' )->has_cap( 'gwc_vt_issue_letters' )
);

// Put it back, so re-running this script starts from where it started.
get_role( 'editor' )->add_cap( 'gwc_vt_issue_letters', true );

/* ── The helpers agree with the constants ────────────────────────────────── */

gwc_vt_check( 'gwc_vt_cap(verify)', 'gwc_vt_verify_hours' === gwc_vt_cap( 'verify' ) );
gwc_vt_check( 'gwc_vt_cap(issue)', 'gwc_vt_issue_letters' === gwc_vt_cap( 'issue' ) );
gwc_vt_check( 'gwc_vt_cap(manage)', 'manage_options' === gwc_vt_cap( 'manage' ) );

echo "\n── Who may see records about other people ──────────────────────\n";

/* ── The boundary, and why it is edit_others_posts ───────────────────────────
 * Every screen and REST route here was gated on edit_posts, which a CONTRIBUTOR
 * has — a role WordPress designed for "may draft a post, may not see anybody
 * else's". That let a contributor read volunteer names off a shift roster, and
 * read a stranger's name, email, phone, photograph and the court that ordered
 * their service off the offers queue.
 *
 * The list TABLES were never affected: WordPress adds an author restriction for
 * anybody without edit_others_posts, so a contributor opening Volunteers has
 * always seen zero rows. It was the custom screens — which query for
 * themselves and so bypass that — and the REST routes behind them.
 *
 * Asserted per role rather than per capability, because the claim worth keeping
 * true is about people: a contributor and an author cannot see any of this, an
 * editor and an administrator can.
 * ─────────────────────────────────────────────────────────────────────────── */

/* An offer to volunteer, for the photograph gate below. Its own fixture rather
 * than whatever the seed left behind, because this is the check that a
 * stranger's face is not readable by a contributor and it must not depend on
 * somebody having run the seed first. */
$GLOBALS['gwc_vt_caps_offer'] = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_APPLICATION_TYPE,
		'post_status' => 'pending',
		'post_title'  => 'Zzcaps offer',
	)
);

$GLOBALS['gwc_vt_caps_shift'] = (int) ( get_posts(
	array(
		'post_type'      => GWC_VT_SHIFT_TYPE,
		'post_status'    => 'publish',
		'numberposts'    => 1,
		'fields'         => 'ids',
	)
)[0] ?? 0 );

foreach ( array( 'contributor' => false, 'author' => false, 'editor' => true, 'administrator' => true ) as $gwc_vt_caps_role => $gwc_vt_caps_may ) {
	$gwc_vt_caps_user = wp_insert_user(
		array(
			'user_login' => 'zzcaps_' . $gwc_vt_caps_role,
			'user_pass'  => wp_generate_password( 20, true ),
			'role'       => $gwc_vt_caps_role,
		)
	);

	if ( is_wp_error( $gwc_vt_caps_user ) ) {
		continue;
	}

	wp_set_current_user( (int) $gwc_vt_caps_user );

	$gwc_vt_caps_word = $gwc_vt_caps_may ? 'may' : 'may not';

	/* "an editor", "a contributor" — the label is read by a person. */
	$gwc_vt_caps_the = ( in_array( substr( $gwc_vt_caps_role, 0, 1 ), array( 'a', 'e', 'i', 'o', 'u' ), true ) ? 'an ' : 'a ' ) . $gwc_vt_caps_role;

	gwc_vt_check(
		$gwc_vt_caps_the . ' ' . $gwc_vt_caps_word . ' see records',
		gwc_vt_can_see_records() === $gwc_vt_caps_may
	);

	gwc_vt_check(
		$gwc_vt_caps_the . ' ' . $gwc_vt_caps_word . ' open the shift panel',
		gwc_vt_rest_can_open_shift_panel() === $gwc_vt_caps_may
	);

	gwc_vt_check(
		$gwc_vt_caps_the . ' ' . $gwc_vt_caps_word . ' search volunteers',
		gwc_vt_rest_can_search_volunteers() === $gwc_vt_caps_may
	);

	/* The roster itself, not just the gate in front of it — the check that
	 * would still fail if somebody re-opened the screen while leaving the
	 * permission callback alone. */
	if ( $GLOBALS['gwc_vt_caps_shift'] > 0 ) {
		$gwc_vt_caps_names = 0;

		if ( gwc_vt_rest_can_open_shift_panel() ) {
			ob_start();
			gwc_vt_render_shift_panel( $GLOBALS['gwc_vt_caps_shift'] );
			$gwc_vt_caps_html = (string) ob_get_clean();

			foreach ( gwc_vt_shift_signup_ids( $GLOBALS['gwc_vt_caps_shift'] ) as $gwc_vt_caps_signup ) {
				if ( false !== strpos( $gwc_vt_caps_html, gwc_vt_signup_name( (int) $gwc_vt_caps_signup ) ) ) {
					++$gwc_vt_caps_names;
				}
			}
		}

		/* The positive case asserts a real number, not ">= 0" — which is true
		 * of everything and would have made this half of the check decorative.
		 * It only runs when the fixture actually put somebody on the shift, so
		 * an empty roster is skipped rather than quietly passing. */
		$gwc_vt_caps_on = count( gwc_vt_shift_signup_ids( $GLOBALS['gwc_vt_caps_shift'] ) );

		if ( ! $gwc_vt_caps_may ) {
			gwc_vt_check(
				$gwc_vt_caps_the . ' sees no names on a roster',
				0 === $gwc_vt_caps_names,
				$gwc_vt_caps_names . ' name(s)'
			);
		} elseif ( $gwc_vt_caps_on > 0 ) {
			gwc_vt_check(
				$gwc_vt_caps_the . ' sees every name on a roster',
				$gwc_vt_caps_names === $gwc_vt_caps_on,
				$gwc_vt_caps_names . ' of ' . $gwc_vt_caps_on
			);
		}
	}

	/* A stranger's photograph, sitting in a queue while they wait to hear. The
	 * volunteer photo beside it is gated per-record on edit_post and always
	 * was; this one was gated on the plural edit_posts, which is how a
	 * contributor could open it. */
	if ( $GLOBALS['gwc_vt_caps_offer'] > 0 ) {
		gwc_vt_check(
			$gwc_vt_caps_the . ' ' . $gwc_vt_caps_word . ' see an offer’s photograph',
			gwc_vt_can_see_photo( $GLOBALS['gwc_vt_caps_offer'] ) === $gwc_vt_caps_may
		);
	}

	wp_set_current_user( 1 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $gwc_vt_caps_user );
}

if ( $GLOBALS['gwc_vt_caps_offer'] > 0 ) {
	wp_delete_post( $GLOBALS['gwc_vt_caps_offer'], true );
}

/* One capability name, read by the menu and by every screen behind it. Two
 * mechanisms would be two places for a site's override to apply unevenly. */
gwc_vt_check(
	'the gate is one filterable capability',
	'edit_others_posts' === gwc_vt_records_cap()
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

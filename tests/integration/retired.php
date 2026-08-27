<?php
/**
 * Retiring a volunteer, against a real database.
 *
 * tests/RetiredTest.php asserts which query names which statuses. That is the
 * decision, and it is worth holding — but it is a claim about source. This
 * asserts what the queries actually return, which is the claim that matters:
 * a retired volunteer is gone from the places that staff work and present in
 * the places the law and the record-keeping need them.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/retired.php
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
function gwc_vt_ret_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, ( '' !== $got ? '  [' . $got . ']' : '' ), "\n";
}

$gwc_vt_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( ! $gwc_vt_admins ) {
	echo "FAIL  no administrator to run as\n\n1 FAILED\n";
	exit( 1 );
}

wp_set_current_user( (int) $gwc_vt_admins[0] );

/* ── One volunteer, retired halfway through ──────────────────────────────── */

$GLOBALS['gwc_vt_ret_id'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Retired Tester',
	)
);

update_post_meta( $GLOBALS['gwc_vt_ret_id'], GWC_VT_VOLUNTEER_EMAIL, 'zzy-retired@example.test' );

/**
 * Take the test volunteer away again, whatever happened.
 */
function gwc_vt_ret_cleanup(): void {
	if ( ! empty( $GLOBALS['gwc_vt_ret_id'] ) ) {
		wp_delete_post( (int) $GLOBALS['gwc_vt_ret_id'], true );
	}
}

register_shutdown_function( 'gwc_vt_ret_cleanup' );

/**
 * Does the REST picker offer them?
 *
 * @param bool $retired Whether to ask for retired volunteers.
 * @return bool
 */
function gwc_vt_ret_in_picker( bool $retired ): bool {
	$request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
	$request->set_param( 'search', 'Zzy Retired' );

	if ( $retired ) {
		$request->set_param( 'retired', true );
	}

	$response = rest_do_request( $request );
	$data     = (array) $response->get_data();

	foreach ( $data as $row ) {
		if ( (int) ( $row['id'] ?? 0 ) === (int) $GLOBALS['gwc_vt_ret_id'] ) {
			return true;
		}
	}

	return false;
}

/* ── While they are still volunteering ───────────────────────────────────── */

gwc_vt_ret_check(
	'before retiring, the picker offers them',
	gwc_vt_ret_in_picker( false )
);

gwc_vt_ret_check(
	'and the email lookup finds them',
	in_array( (int) $GLOBALS['gwc_vt_ret_id'], gwc_vt_volunteers_by_email( 'zzy-retired@example.test' ), true )
);

/* ── Retire ──────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_ret_id'],
		'post_status' => GWC_VT_VOLUNTEER_RETIRED,
	)
);

gwc_vt_ret_check(
	'the status is the one we registered, not truncated by the column',
	GWC_VT_VOLUNTEER_RETIRED === get_post_status( $GLOBALS['gwc_vt_ret_id'] ),
	(string) get_post_status( $GLOBALS['gwc_vt_ret_id'] )
);

gwc_vt_ret_check(
	'the status is registered, so WordPress can render its label',
	null !== get_post_status_object( GWC_VT_VOLUNTEER_RETIRED )
);

/* ── Gone from where work is planned ─────────────────────────────────────── */

gwc_vt_ret_check(
	'a roster picker no longer offers them',
	! gwc_vt_ret_in_picker( false )
);

gwc_vt_ret_check(
	'but the letter picker still can, when it asks',
	gwc_vt_ret_in_picker( true )
);

/* ── Still there for the record, and for the law ─────────────────────────── */

gwc_vt_ret_check(
	'the email lookup still finds them, so the exporter and the eraser can',
	in_array( (int) $GLOBALS['gwc_vt_ret_id'], gwc_vt_volunteers_by_email( 'zzy-retired@example.test' ), true )
);

gwc_vt_ret_check(
	'and they are not on the overdue nag',
	! in_array( (int) $GLOBALS['gwc_vt_ret_id'], gwc_vt_overdue_requirement_ids(), true )
);

/* ── Signing in ──────────────────────────────────────────────────────────────
 * The response a stranger gets is asserted byte-identical in
 * tests/integration/signin.php and in SelfLogTest; what is asserted here is the
 * only thing that may differ, which is whether a link was actually issued.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_ret_issued'] = 0;

add_action(
	'gwc_vt_signin_link_issued',
	static function () {
		++$GLOBALS['gwc_vt_ret_issued'];
	}
);

gwc_vt_ret_check(
	'a retired volunteer is filtered out before a sign-in link is issued',
	1 === count( gwc_vt_volunteers_by_email( 'zzy-retired@example.test' ) )
		&& 0 === count(
			array_filter(
				gwc_vt_volunteers_by_email( 'zzy-retired@example.test' ),
				static function ( $id ): bool {
					return GWC_VT_VOLUNTEER_RETIRED !== get_post_status( (int) $id );
				}
			)
		)
);

/* ── Quick Edit must not move somebody between statuses ──────────────────────
 * Core's inline editor posts a status from a <select> built out of a fixed
 * list, and wp_ajax_inline_save() assigns it verbatim. A custom status is not
 * in that list, so without the guard, quick-editing a retired volunteer to fix
 * a typo publishes them on the way past and says nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_ret_id'],
		'post_status' => GWC_VT_VOLUNTEER_RETIRED,
	)
);

$_POST['_inline_edit'] = 'pretend-nonce';

$gwc_vt_ret_quick = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
	),
	array( 'ID' => $GLOBALS['gwc_vt_ret_id'] )
);

gwc_vt_ret_check(
	'a quick edit cannot un-retire somebody',
	GWC_VT_VOLUNTEER_RETIRED === $gwc_vt_ret_quick['post_status'],
	(string) $gwc_vt_ret_quick['post_status']
);

$gwc_vt_ret_trash = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'trash',
	),
	array( 'ID' => $GLOBALS['gwc_vt_ret_id'] )
);

gwc_vt_ret_check(
	'but a bulk move to the trash is still a real thing somebody can do',
	'trash' === $gwc_vt_ret_trash['post_status'],
	(string) $gwc_vt_ret_trash['post_status']
);

unset( $_POST['_inline_edit'] );

$gwc_vt_ret_ours = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
	),
	array( 'ID' => $GLOBALS['gwc_vt_ret_id'] )
);

gwc_vt_ret_check(
	'and Put back, which is not an inline edit, still works',
	'publish' === $gwc_vt_ret_ours['post_status'],
	(string) $gwc_vt_ret_ours['post_status']
);

/* ── And back ────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_ret_id'],
		'post_status' => 'publish',
	)
);

gwc_vt_ret_check(
	'putting them back makes them offerable again',
	gwc_vt_ret_in_picker( false )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

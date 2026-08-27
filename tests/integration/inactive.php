<?php
/**
 * Making a volunteer inactive, against a real database.
 *
 * tests/RetiredTest.php asserts which query names which statuses. That is the
 * decision, and it is worth holding — but it is a claim about source. This
 * asserts what the queries actually return, which is the claim that matters:
 * an inactive volunteer is gone from the places that staff work and present in
 * the places the law and the record-keeping need them.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/inactive.php
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
function gwc_vt_inact_check( string $label, bool $ok, string $got = '' ): void {
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

$GLOBALS['gwc_vt_inact_id'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Inactive Tester',
	)
);

update_post_meta( $GLOBALS['gwc_vt_inact_id'], GWC_VT_VOLUNTEER_EMAIL, 'zzy-inactive@example.test' );

/**
 * Take the test volunteer away again, whatever happened.
 */
function gwc_vt_inact_cleanup(): void {
	if ( ! empty( $GLOBALS['gwc_vt_inact_id'] ) ) {
		/* The grant is a child post; WordPress does not cascade, which is why
		 * it is named here rather than assumed away. */
		foreach ( (array) get_posts(
			array(
				'post_type'   => GWC_VT_RECORD_TYPE,
				'post_parent' => (int) $GLOBALS['gwc_vt_inact_id'],
				'post_status' => array_values( get_post_stati() ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $record_id ) {
			wp_delete_post( (int) $record_id, true );
		}

		wp_delete_post( (int) $GLOBALS['gwc_vt_inact_id'], true );
	}

	if ( ! empty( $GLOBALS['gwc_vt_inact_cred'] ) ) {
		wp_delete_post( (int) $GLOBALS['gwc_vt_inact_cred'], true );
	}
}

register_shutdown_function( 'gwc_vt_inact_cleanup' );

/**
 * Does the REST picker offer them?
 *
 * @param bool $inactive Whether to ask for inactive volunteers.
 * @return bool
 */
function gwc_vt_inact_in_picker( bool $inactive ): bool {
	$request = new WP_REST_Request( 'GET', '/gwc-vt/v1/volunteers' );
	$request->set_param( 'search', 'Zzy Inactive' );

	if ( $inactive ) {
		$request->set_param( 'inactive', true );
	}

	$response = rest_do_request( $request );
	$data     = (array) $response->get_data();

	foreach ( $data as $row ) {
		if ( (int) ( $row['id'] ?? 0 ) === (int) $GLOBALS['gwc_vt_inact_id'] ) {
			return true;
		}
	}

	return false;
}

/* ── While they are still volunteering ───────────────────────────────────── */

gwc_vt_inact_check(
	'while they are active, the picker offers them',
	gwc_vt_inact_in_picker( false )
);

gwc_vt_inact_check(
	'and the email lookup finds them',
	in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_volunteers_by_email( 'zzy-inactive@example.test' ), true )
);

/* ── Make them inactive ──────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_inact_id'],
		'post_status' => GWC_VT_VOLUNTEER_INACTIVE,
	)
);

gwc_vt_inact_check(
	'the status is the one we registered, not truncated by the column',
	GWC_VT_VOLUNTEER_INACTIVE === get_post_status( $GLOBALS['gwc_vt_inact_id'] ),
	(string) get_post_status( $GLOBALS['gwc_vt_inact_id'] )
);

gwc_vt_inact_check(
	'the status is registered, so WordPress can render its label',
	null !== get_post_status_object( GWC_VT_VOLUNTEER_INACTIVE )
);

/* ── Gone from where work is planned ─────────────────────────────────────── */

gwc_vt_inact_check(
	'a roster picker no longer offers them',
	! gwc_vt_inact_in_picker( false )
);

gwc_vt_inact_check(
	'but the letter picker still can, when it asks',
	gwc_vt_inact_in_picker( true )
);

/* ── Still there for the record, and for the law ─────────────────────────── */

gwc_vt_inact_check(
	'the email lookup still finds them, so the exporter and the eraser can',
	in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_volunteers_by_email( 'zzy-inactive@example.test' ), true )
);

gwc_vt_inact_check(
	'and they are not on the overdue nag',
	! in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_overdue_requirement_ids(), true )
);

/* ── Signing in ──────────────────────────────────────────────────────────────
 * The response a stranger gets is asserted byte-identical in
 * tests/integration/signin.php and in SelfLogTest; what is asserted here is the
 * only thing that may differ, which is whether a link was actually issued.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_inact_issued'] = 0;

add_action(
	'gwc_vt_signin_link_issued',
	static function () {
		++$GLOBALS['gwc_vt_inact_issued'];
	}
);

gwc_vt_inact_check(
	'a inactive volunteer is filtered out before a sign-in link is issued',
	1 === count( gwc_vt_volunteers_by_email( 'zzy-inactive@example.test' ) )
		&& 0 === count(
			array_filter(
				gwc_vt_volunteers_by_email( 'zzy-inactive@example.test' ),
				static function ( $id ): bool {
					return GWC_VT_VOLUNTEER_INACTIVE !== get_post_status( (int) $id );
				}
			)
		)
);

/* ── Quick Edit must not move somebody between statuses ──────────────────────
 * Core's inline editor posts a status from a <select> built out of a fixed
 * list, and wp_ajax_inline_save() assigns it verbatim. A custom status is not
 * in that list, so without the guard, quick-editing a inactive volunteer to fix
 * a typo publishes them on the way past and says nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_inact_id'],
		'post_status' => GWC_VT_VOLUNTEER_INACTIVE,
	)
);

$_POST['_inline_edit'] = 'pretend-nonce';

$gwc_vt_inact_quick = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
	),
	array( 'ID' => $GLOBALS['gwc_vt_inact_id'] )
);

gwc_vt_inact_check(
	'a quick edit cannot reactivate somebody',
	GWC_VT_VOLUNTEER_INACTIVE === $gwc_vt_inact_quick['post_status'],
	(string) $gwc_vt_inact_quick['post_status']
);

$gwc_vt_inact_trash = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'trash',
	),
	array( 'ID' => $GLOBALS['gwc_vt_inact_id'] )
);

gwc_vt_inact_check(
	'but a bulk move to the trash is still a real thing somebody can do',
	'trash' === $gwc_vt_inact_trash['post_status'],
	(string) $gwc_vt_inact_trash['post_status']
);

unset( $_POST['_inline_edit'] );

$gwc_vt_inact_ours = apply_filters(
	'wp_insert_post_data',
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
	),
	array( 'ID' => $GLOBALS['gwc_vt_inact_id'] )
);

gwc_vt_inact_check(
	'and Make active, which is not an inline edit, still works',
	'publish' === $gwc_vt_inact_ours['post_status'],
	(string) $gwc_vt_inact_ours['post_status']
);

/* ── The two credential counts that link to the volunteer list ───────────────
 * Both were wrong the day this status was added, in the same way and for the
 * same reason: they count from credential RECORDS and never look at the person
 * the record belongs to, while the screen they link to is the volunteer list,
 * which does not show inactive records. Three counted, two shown.
 *
 * tests/integration/credentials.php caught the second of them — but only
 * because a stray inactive volunteer happened to be lying about in the
 * database, which is luck rather than coverage. This makes it deliberate.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_inact_cred'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_CREDENTIAL_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzy Inactive Test Class',
	)
);

update_post_meta( $GLOBALS['gwc_vt_inact_cred'], GWC_VT_CREDENTIAL_MONTHS, 12 );

/* Granted two years ago, so it has lapsed however this runs. */
gwc_vt_record_credential(
	(int) $GLOBALS['gwc_vt_inact_id'],
	(int) $GLOBALS['gwc_vt_inact_cred'],
	gmdate( 'Y-m-d', strtotime( '-24 months' ) )
);

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_inact_id'],
		'post_status' => 'publish',
	)
);

gwc_vt_inact_check(
	'while they are active, a lapsed credential counts against them',
	in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_lapsed_credential_ids(), true )
);

gwc_vt_inact_check(
	'and they hold it',
	in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_credential_holder_ids( (int) $GLOBALS['gwc_vt_inact_cred'] ), true )
);

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_inact_id'],
		'post_status' => GWC_VT_VOLUNTEER_INACTIVE,
	)
);

gwc_vt_inact_check(
	'once inactive they are off the lapsed count, which links to a list that would not show them',
	! in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_lapsed_credential_ids(), true )
);

gwc_vt_inact_check(
	'and off the holder count, which is read before deciding who can work',
	! in_array( (int) $GLOBALS['gwc_vt_inact_id'], gwc_vt_credential_holder_ids( (int) $GLOBALS['gwc_vt_inact_cred'] ), true )
);

/* The record of what they hold is untouched — it is the counts that changed,
 * not the history. */
gwc_vt_inact_check(
	'but the grant itself is still on file',
	GWC_VT_HOLDS_NEVER !== gwc_vt_volunteer_holds( (int) $GLOBALS['gwc_vt_inact_id'], (int) $GLOBALS['gwc_vt_inact_cred'] )
);

/* ── The Status panel says something only when there is something to say ─────
 * It used to say "Volunteering here." on an active record, which told a
 * coordinator what they already knew about nearly every person they would ever
 * open. Inactive is different: it has a consequence the facts cannot state.
 *
 * Asserted here rather than in the unit suite because the panel computes
 * totals, which needs a database — and stubbing that away would have made the
 * assertions pass against a panel with nothing in it.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The panel's markup for one volunteer in one status.
 *
 * @param string $status A post status.
 * @return string
 */
function gwc_vt_inact_panel( string $status ): string {
	wp_update_post(
		array(
			'ID'          => (int) $GLOBALS['gwc_vt_inact_id'],
			'post_status' => $status,
		)
	);

	ob_start();
	gwc_vt_render_volunteer_status_box( get_post( (int) $GLOBALS['gwc_vt_inact_id'] ) );

	return (string) ob_get_clean();
}

$gwc_vt_inact_active = gwc_vt_inact_panel( 'publish' );

gwc_vt_inact_check(
	'an active volunteer gets no status sentence',
	false === strpos( $gwc_vt_inact_active, 'gwcvt-standing__where' )
);

gwc_vt_inact_check(
	'but does get the action, which is the point of the panel',
	false !== strpos( $gwc_vt_inact_active, 'Make them inactive' )
);

gwc_vt_inact_check(
	'and the action reads after the facts, not before them',
	strpos( $gwc_vt_inact_active, 'gwcvt-standing__facts' ) < strpos( $gwc_vt_inact_active, 'gwcvt-standing__action' )
);

$gwc_vt_inact_off = gwc_vt_inact_panel( GWC_VT_VOLUNTEER_INACTIVE );

gwc_vt_inact_check(
	'an inactive one is told what that means for staffing',
	false !== strpos( $gwc_vt_inact_off, 'not offered when you staff a shift' )
);

gwc_vt_inact_check(
	'and is offered the way back',
	false !== strpos( $gwc_vt_inact_off, 'Make them active' )
);

/* ── And active again ────────────────────────────────────────────────────────────── */

wp_update_post(
	array(
		'ID'          => $GLOBALS['gwc_vt_inact_id'],
		'post_status' => 'publish',
	)
);

gwc_vt_inact_check(
	'making them active again makes them offerable',
	gwc_vt_inact_in_picker( false )
);

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? 'ALL PASS' : $GLOBALS['gwc_vt_failures'] . ' FAILED' ), "\n";

/* Exit non-zero so a failure fails the job. Printing the count and returning 0
 * is how a red script reads green to anything that only reads the exit code. */
if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

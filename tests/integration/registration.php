<?php
/**
 * Offering to volunteer, end to end.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * The whole feature is a write path guarded by settings and a queue that turns
 * one post type into another. tests/RegistrationTest.php covers the gate and
 * the wording; everything here needs real posts: that a submission becomes a
 * pending application and never a volunteer, that the honeypot and the timing
 * check discard silently, that the requirement question is gated on the WRITE
 * and not only the field, and that approving produces a volunteer record
 * indistinguishable from a hand-typed one.
 *
 * ── The property that matters most ───────────────────────────────────────────
 * No code path here may branch on whether the submitted address already belongs
 * to a volunteer. That is hard rule 4's reasoning applied to the second public
 * surface, and it is asserted by submitting the same address twice — once when
 * a volunteer with it exists and once when none does — and checking the two
 * runs are indistinguishable.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/registration.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_rg_made']  = array();
$GLOBALS['gwc_vt_rg_post']  = $_POST;
$GLOBALS['gwc_vt_rg_opts']  = get_option( GWC_VT_SETTINGS_OPTION, array() );

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_rg_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Point the settings at a state.
 *
 * @param array $changes Settings to overlay on the defaults.
 */
function gwc_vt_rg_settings( array $changes ): void {
	update_option( GWC_VT_SETTINGS_OPTION, array_merge( $GLOBALS['gwc_vt_rg_opts'], $changes ) );
	gwc_vt_settings_cache( null, true );
}

/**
 * Post an offer the way a browser would, and return what the visitor is told.
 *
 * Drives gwc_vt_handle_registration() directly rather than through
 * template_redirect: the dispatcher's job is deciding whether a request is ours
 * at all, and it is checked separately below.
 *
 * @param array $fields What was typed.
 * @param array $tamper Optional. Overrides for the hidden fields.
 * @return string The result key the visitor's message comes from.
 */
function gwc_vt_rg_submit( array $fields, array $tamper = array() ): string {
	unset( $GLOBALS['gwc_vt_registration_result'] );

	$_POST = array_merge(
		array(
			'gwc_vt_registration_nonce' => wp_create_nonce( 'gwc_vt_registration' ),
			/* Aged past the minimum a human takes, so an ordinary submission is
			 * not read as a script. gwc_vt_form_stamp() is HMAC'd over the
			 * current second, so this is built the way the form builds it. */
			'gwc_vt_t'                  => ( time() - 30 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 30 ), wp_salt( 'gwc_vt_form' ) ),
			'gwc_vt_website'            => '',
		),
		$fields,
		$tamper
	);

	gwc_vt_handle_registration();

	$_POST = array();

	return (string) ( $GLOBALS['gwc_vt_registration_result'] ?? '' );
}

/**
 * How many offers exist, in any state.
 *
 * @return int
 */
function gwc_vt_rg_count(): int {
	return count(
		get_posts(
			array(
				'post_type'      => GWC_VT_APPLICATION_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		)
	);
}

/**
 * The most recently written offer.
 *
 * @return int
 */
function gwc_vt_rg_newest(): int {
	$waiting = gwc_vt_pending_application_ids();

	return $waiting ? (int) end( $waiting ) : 0;
}

wp_set_current_user( 1 );

/* ── Start from an empty rate limiter ────────────────────────────────────────
 * The limiter counts by IP, by address and in total, and under WP-CLI the IP is
 * the same every run — so the 'all' bucket fills up across repeated runs and
 * every later submission comes back 'accepted' having written nothing. Which is
 * the limiter working exactly as designed, and it makes this script pass on a
 * cold database and fail on a warm one.
 *
 * Found by running the four sabotages back to back and watching the restored
 * run report six failures with no code changed. Cleared here rather than worked
 * around with unique addresses, because the 'all' scope is not per-address and
 * no amount of fresh emails would have helped.
 *
 * Cleared at the END as well, and that is the part worth explaining. The first
 * version of this politely put back whatever it found — which is right on a
 * live site and wrong here, because what it found was a counter this script had
 * already saturated. Restoring it handed the next scripts in the suite an 'all'
 * bucket at 44 of 60, and tests/integration/public-signup.php and
 * tests/integration/events.php both failed on a rate limiter doing exactly its
 * job. The limiter is one option shared by all three public forms, so a script
 * that posts to one of them is spending everybody's budget.
 * ─────────────────────────────────────────────────────────────────────────── */
delete_option( GWC_VT_RATE_LIMIT_OPTION );

$GLOBALS['gwc_vt_rg_page'] = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Zzrg offers',
		'post_content' => '[gwc_vt_volunteer_form]',
	)
);

$GLOBALS['gwc_vt_rg_made'][] = (int) $GLOBALS['gwc_vt_rg_page'];

gwc_vt_rg_settings(
	array(
		'registration_enabled'      => true,
		'registration_page'         => (int) $GLOBALS['gwc_vt_rg_page'],
		'registration_ask_required' => false,
		'registration_code'         => '',
	)
);

/* ── An ordinary offer ───────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_rg_before'] = gwc_vt_rg_count();

$GLOBALS['gwc_vt_rg_result'] = gwc_vt_rg_submit(
	array(
		'gwc_vt_name'  => 'Zzrg Priya Ramanathan',
		'gwc_vt_email' => 'zzrg-priya@example.test',
		'gwc_vt_phone' => '555 0177',
		'gwc_vt_note'  => 'Saturdays, can drive.',
	)
);

gwc_vt_rg_check(
	'an ordinary offer is accepted',
	'accepted' === $GLOBALS['gwc_vt_rg_result'],
	'it said "' . $GLOBALS['gwc_vt_rg_result'] . '"'
);

gwc_vt_rg_check(
	'and one offer was written',
	gwc_vt_rg_count() === $GLOBALS['gwc_vt_rg_before'] + 1,
	'the count went from ' . $GLOBALS['gwc_vt_rg_before'] . ' to ' . gwc_vt_rg_count()
);

/* The whole design in one assertion. */
gwc_vt_rg_check(
	'and NO volunteer record was created',
	0 === count( get_posts( array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'any', 'numberposts' => -1, 's' => 'Zzrg Priya' ) ) ),
	'an anonymous form created an identity record'
);

/* end() takes its argument by reference, so it needs a variable and not a
 * function's return value — passing one is a notice, and this suite fails on
 * those. Named helper rather than a temporary at each site. */
$GLOBALS['gwc_vt_rg_offer'] = gwc_vt_application_record( gwc_vt_rg_newest() );

gwc_vt_rg_check(
	'it holds what was typed, as claims',
	'Zzrg Priya Ramanathan' === $GLOBALS['gwc_vt_rg_offer']['name']
		&& 'zzrg-priya@example.test' === $GLOBALS['gwc_vt_rg_offer']['email']
		&& '555 0177' === $GLOBALS['gwc_vt_rg_offer']['phone']
		&& 'Saturdays, can drive.' === $GLOBALS['gwc_vt_rg_offer']['note'],
	'what came back: ' . wp_json_encode( array_intersect_key( $GLOBALS['gwc_vt_rg_offer'], array_flip( array( 'name', 'email', 'phone', 'note' ) ) ) )
);

gwc_vt_rg_check(
	'and it is pending, so it is nowhere else on the site',
	'pending' === $GLOBALS['gwc_vt_rg_offer']['status'],
	'status was ' . $GLOBALS['gwc_vt_rg_offer']['status']
);

/* ── What it refuses, and how quietly ────────────────────────────────────── */

$GLOBALS['gwc_vt_rg_before'] = gwc_vt_rg_count();

gwc_vt_rg_check(
	'an offer with no email is refused',
	'incomplete' === gwc_vt_rg_submit( array( 'gwc_vt_name' => 'Zzrg Nobody' ) ),
	'it was accepted without a reply address'
);

gwc_vt_rg_check(
	'a filled honeypot is accepted and written nowhere',
	'accepted' === gwc_vt_rg_submit(
		array( 'gwc_vt_name' => 'Zzrg Spam', 'gwc_vt_email' => 'zzrg-spam@example.test' ),
		array( 'gwc_vt_website' => 'http://example.test' )
	),
	'the honeypot answered differently from an accepted offer'
);

gwc_vt_rg_check(
	'a submission faster than a person can type is accepted and written nowhere',
	'accepted' === gwc_vt_rg_submit(
		array( 'gwc_vt_name' => 'Zzrg Fast', 'gwc_vt_email' => 'zzrg-fast@example.test' ),
		array( 'gwc_vt_t' => time() . '.' . hash_hmac( 'sha256', (string) time(), wp_salt( 'gwc_vt_form' ) ) )
	),
	'a three-second submission was told apart from a real one'
);

gwc_vt_rg_check(
	'and none of the three wrote anything',
	gwc_vt_rg_count() === $GLOBALS['gwc_vt_rg_before'],
	'the count moved from ' . $GLOBALS['gwc_vt_rg_before'] . ' to ' . gwc_vt_rg_count()
);

gwc_vt_rg_check(
	'a forged timing stamp is refused',
	'expired' === gwc_vt_rg_submit(
		array( 'gwc_vt_name' => 'Zzrg Forged', 'gwc_vt_email' => 'zzrg-forged@example.test' ),
		array( 'gwc_vt_t' => ( time() - 30 ) . '.deadbeef' )
	),
	'a stamp this site did not sign was accepted'
);

/* ── There is no lookup, so there is no oracle ───────────────────────────────
 * The same address submitted twice: once with a volunteer of that address on
 * file and once without. If any code path branched on the answer, these two
 * runs would differ. Hard rule 4's reasoning, on the second public surface.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_rg_known'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzrg Existing Volunteer',
	)
);

$GLOBALS['gwc_vt_rg_made'][] = (int) $GLOBALS['gwc_vt_rg_known'];

update_post_meta( (int) $GLOBALS['gwc_vt_rg_known'], GWC_VT_VOLUNTEER_EMAIL, 'zzrg-known@example.test' );

$GLOBALS['gwc_vt_rg_a'] = gwc_vt_rg_submit(
	array( 'gwc_vt_name' => 'Zzrg Known Person', 'gwc_vt_email' => 'zzrg-known@example.test' )
);

$GLOBALS['gwc_vt_rg_b'] = gwc_vt_rg_submit(
	array( 'gwc_vt_name' => 'Zzrg Stranger', 'gwc_vt_email' => 'zzrg-nobody-at-all@example.test' )
);

gwc_vt_rg_check(
	'a known address and an unknown one are answered identically',
	$GLOBALS['gwc_vt_rg_a'] === $GLOBALS['gwc_vt_rg_b'],
	'known said "' . $GLOBALS['gwc_vt_rg_a'] . '", unknown said "' . $GLOBALS['gwc_vt_rg_b'] . '"'
);

gwc_vt_rg_check(
	'and the message a visitor sees is the same string',
	gwc_vt_registration_message( $GLOBALS['gwc_vt_rg_a'] ) === gwc_vt_registration_message( $GLOBALS['gwc_vt_rg_b'] ),
	'the two outcomes read differently'
);

/* ── The requirement question is gated on the write ──────────────────────────
 * Not only on the field. A form that stopped asking would otherwise keep
 * storing whatever a script kept posting — anybody with a copy of the old form
 * could go on sending court-order information to a site that had switched the
 * question off.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_rg_req'] = array(
	'gwc_vt_name'         => 'Zzrg Required Person',
	'gwc_vt_email'        => 'zzrg-required@example.test',
	'gwc_vt_required'     => '40',
	'gwc_vt_required_by'  => '2026-12-01',
	'gwc_vt_required_for' => 'Franklin County Municipal Court',
);

gwc_vt_rg_submit( $GLOBALS['gwc_vt_rg_req'] );

$GLOBALS['gwc_vt_rg_off'] = gwc_vt_application_record( gwc_vt_rg_newest() );

gwc_vt_rg_check(
	'with the question off, a posted requirement is not stored',
	0 === $GLOBALS['gwc_vt_rg_off']['required']
		&& '' === $GLOBALS['gwc_vt_rg_off']['required_for'],
	'it stored ' . $GLOBALS['gwc_vt_rg_off']['required'] . ' minutes for "' . $GLOBALS['gwc_vt_rg_off']['required_for'] . '"'
);

gwc_vt_rg_settings(
	array(
		'registration_enabled'      => true,
		'registration_page'         => (int) $GLOBALS['gwc_vt_rg_page'],
		'registration_ask_required' => true,
	)
);

gwc_vt_rg_submit( $GLOBALS['gwc_vt_rg_req'] );

$GLOBALS['gwc_vt_rg_on'] = gwc_vt_application_record( gwc_vt_rg_newest() );

gwc_vt_rg_check(
	'with it on, the requirement is stored',
	2400 === $GLOBALS['gwc_vt_rg_on']['required']
		&& '2026-12-01' === $GLOBALS['gwc_vt_rg_on']['required_by']
		&& 'Franklin County Municipal Court' === $GLOBALS['gwc_vt_rg_on']['required_for'],
	'it stored ' . $GLOBALS['gwc_vt_rg_on']['required'] . ' minutes'
);

/* ── Approving it ────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_rg_approve'] = (int) $GLOBALS['gwc_vt_rg_on']['id'];

$_GET = array( 'application' => $GLOBALS['gwc_vt_rg_approve'] );
$_REQUEST = array_merge( $_GET, array( '_wpnonce' => wp_create_nonce( 'gwc_vt_approve_application_' . $GLOBALS['gwc_vt_rg_approve'] ) ) );
$_GET['_wpnonce'] = $_REQUEST['_wpnonce'];

/* gwc_vt_handle_approve_application() ends in a redirect and an exit, so the
 * write is driven through its parts. The nonce and capability check are what
 * the handler adds, and they are exercised by the browser rather than here. */
$GLOBALS['gwc_vt_rg_offer_now'] = gwc_vt_application_record( $GLOBALS['gwc_vt_rg_approve'] );

$GLOBALS['gwc_vt_rg_new'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => $GLOBALS['gwc_vt_rg_offer_now']['name'],
	)
);

$GLOBALS['gwc_vt_rg_made'][] = (int) $GLOBALS['gwc_vt_rg_new'];

/* ── What the retention sweep does with an old one ───────────────────────── */

$GLOBALS['gwc_vt_rg_old'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_APPLICATION_TYPE,
		'post_status' => 'pending',
		'post_title'  => 'Zzrg Ancient Offer',
		'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-40 months' ) ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 months' ) ),
	)
);

update_post_meta( (int) $GLOBALS['gwc_vt_rg_old'], GWC_VT_APPLICATION_EMAIL, 'zzrg-ancient@example.test' );

gwc_vt_rg_check(
	'an offer older than the policy is found by the sweep',
	in_array( (int) $GLOBALS['gwc_vt_rg_old'], gwc_vt_stale_application_ids( 24 ), true ),
	'the sweep did not see it'
);

gwc_vt_rg_check(
	'and a recent one is not',
	! in_array( (int) $GLOBALS['gwc_vt_rg_offer']['id'], gwc_vt_stale_application_ids( 24 ), true ),
	'the sweep would have deleted this week\'s offers'
);

gwc_vt_rg_check(
	'sweeping it removes it',
	gwc_vt_sweep_stale_applications( 24 ) > 0 && ! get_post( (int) $GLOBALS['gwc_vt_rg_old'] ),
	'it survived the sweep'
);

/* ── The privacy tools reach it ──────────────────────────────────────────── */

gwc_vt_rg_check(
	'the exporter finds an offer by its address',
	( function () {
		$export = gwc_vt_export_personal_data( 'zzrg-priya@example.test', 1 );

		foreach ( $export['data'] ?? array() as $item ) {
			if ( 'gwc_vt_application' === ( $item['group_id'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	} )(),
	'an offer was invisible to a data request'
);

gwc_vt_rg_check(
	'and the eraser deletes it',
	( function () {
		gwc_vt_erase_personal_data( 'zzrg-priya@example.test', 1 );

		return 0 === count( gwc_vt_applications_by_email( 'zzrg-priya@example.test' ) );
	} )(),
	'the offer survived an erasure that reported itself complete'
);

/* ── The photograph ──────────────────────────────────────────────────────────
 * The one place in this plugin where an anonymous stranger can put a file on
 * the server, so the questions are: is it gated, does it land somewhere the web
 * cannot serve, who can see it, and does it go when the offer does.
 *
 * gwc_vt_store_photo() refuses anything is_uploaded_file() rejects — which is
 * every file a test can make — so these drive the same helpers by hand, the way
 * tests/integration/photo.php does. That guard is asserted there.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Store a photo against an offer, minus the is_uploaded_file() gate.
 *
 * @param int    $application_id The offer.
 * @param string $source         Path to an image.
 * @return string '' on success.
 */
function gwc_vt_rg_photo( int $application_id, string $source ): string {
	$dir   = gwc_vt_photo_dir();
	$sized = getimagesize( $source );
	$types = gwc_vt_photo_types();
	$mime  = is_array( $sized ) ? (string) ( $sized['mime'] ?? '' ) : '';

	if ( '' === $dir || ! isset( $types[ $mime ] ) ) {
		return 'wrong-type';
	}

	$editor = wp_get_image_editor( $source );

	if ( is_wp_error( $editor ) ) {
		return 'wrong-type';
	}

	$editor->resize( GWC_VT_PHOTO_MAX_EDGE, GWC_VT_PHOTO_MAX_EDGE, false );

	$saved = $editor->save( $dir . wp_generate_password( 32, false ) . '.' . $types[ $mime ], $mime );

	if ( is_wp_error( $saved ) ) {
		return 'upload-failed';
	}

	update_post_meta( $application_id, GWC_VT_PHOTO_KEY, basename( (string) $saved['path'] ) );

	return '';
}

$GLOBALS['gwc_vt_rg_jpeg'] = ( function () {
	$image = imagecreatetruecolor( 900, 900 );

	imagefilledrectangle( $image, 0, 0, 900, 900, imagecolorallocate( $image, 40, 90, 160 ) );
	imagefilledellipse( $image, 450, 450, 400, 400, imagecolorallocate( $image, 230, 200, 120 ) );

	$path = get_temp_dir() . 'zzrg-face.jpg';

	imagejpeg( $image, $path, 90 );
	imagedestroy( $image );

	return $path;
} )();

/* The field is gated, and so is the write. A form that stopped inviting photos
 * has to stop ACCEPTING them, or anybody with a copy of the old page can go on
 * posting files at the server. */
gwc_vt_rg_settings(
	array(
		'registration_enabled'   => true,
		'registration_page'      => (int) $GLOBALS['gwc_vt_rg_page'],
		'registration_ask_photo' => false,
	)
);

gwc_vt_rg_check(
	'with the photo question off, the form has no file field',
	false === strpos( gwc_vt_render_registration_form(), 'name="gwc_vt_photo"' ),
	'the form offered a file input the setting had switched off'
);

/* The real write path, refusing on the setting before it looks at $_FILES at
 * all. Asserting gwc_vt_registration_asks_photo() instead — which the first
 * version did — tests the getter and not the gate, and passed with the gate
 * removed from the handler. */
gwc_vt_rg_check(
	'and the write path refuses before it even looks for a file',
	'not-asked' === gwc_vt_store_offer_photo( gwc_vt_rg_newest() ),
	'the write was still open with the field gone'
);

gwc_vt_rg_settings(
	array(
		'registration_enabled'   => true,
		'registration_page'      => (int) $GLOBALS['gwc_vt_rg_page'],
		'registration_ask_photo' => true,
	)
);

unset( $GLOBALS['gwc_vt_registration_result'] );

gwc_vt_rg_check(
	'with it on, the form carries a file field and an enctype',
	false !== strpos( gwc_vt_render_registration_form(), 'name="gwc_vt_photo"' )
		&& false !== strpos( gwc_vt_render_registration_form(), 'enctype="multipart/form-data"' ),
	'the field or the enctype was missing — a form without the enctype posts a filename and no bytes'
);

$GLOBALS['gwc_vt_rg_shot'] = gwc_vt_rg_submit(
	array( 'gwc_vt_name' => 'Zzrg Face Person', 'gwc_vt_email' => 'zzrg-face@example.test' )
);

$GLOBALS['gwc_vt_rg_withphoto'] = gwc_vt_rg_newest();

gwc_vt_rg_photo( $GLOBALS['gwc_vt_rg_withphoto'], $GLOBALS['gwc_vt_rg_jpeg'] );

gwc_vt_rg_check(
	'an offer can carry a photograph',
	gwc_vt_has_photo( $GLOBALS['gwc_vt_rg_withphoto'] ),
	'it was not stored'
);

$GLOBALS['gwc_vt_rg_file'] = gwc_vt_photo_path( $GLOBALS['gwc_vt_rg_withphoto'] );

gwc_vt_rg_check(
	'kept where the web server will not serve it, under a name that gives nothing away',
	0 === strpos( $GLOBALS['gwc_vt_rg_file'], trailingslashit( wp_upload_dir()['basedir'] ) . 'gwc-vt-private/' )
		&& false === strpos( basename( $GLOBALS['gwc_vt_rg_file'] ), (string) $GLOBALS['gwc_vt_rg_withphoto'] ),
	$GLOBALS['gwc_vt_rg_file']
);

gwc_vt_rg_check(
	'and re-encoded down, which is what drops the EXIF a phone puts in it',
	( function () {
		$sized = getimagesize( $GLOBALS['gwc_vt_rg_file'] );

		return is_array( $sized ) && $sized[0] <= GWC_VT_PHOTO_MAX_EDGE && $sized[1] <= GWC_VT_PHOTO_MAX_EDGE;
	} )(),
	'it was stored at its original size'
);

gwc_vt_rg_check(
	'it never reaches the Media Library',
	0 === count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 's' => 'zzrg-face' ) ) ),
	'a stranger put something in the Media Library'
);

/* Who may look at the face of somebody nobody has said yes to yet. The queue's
 * capability, because that is the screen it appears on — and emphatically not
 * "any logged-in user", which on a site that allows registration is the whole
 * internet. */
gwc_vt_rg_check(
	'somebody who can work the queue may see it',
	gwc_vt_can_see_photo( $GLOBALS['gwc_vt_rg_withphoto'], 1 ),
	'an administrator was refused'
);

$GLOBALS['gwc_vt_rg_sub'] = ( function () {
	$user = get_user_by( 'login', 'zzrg_subscriber' );

	if ( $user ) {
		return (int) $user->ID;
	}

	return (int) wp_insert_user(
		array(
			'user_login' => 'zzrg_subscriber',
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => 'zzrg_subscriber@example.test',
			'role'       => 'subscriber',
		)
	);
} )();

gwc_vt_rg_check(
	'a subscriber may not',
	! gwc_vt_can_see_photo( $GLOBALS['gwc_vt_rg_withphoto'], $GLOBALS['gwc_vt_rg_sub'] ),
	'a subscriber could see the face of somebody waiting on an answer'
);

/* Genuinely logged out, which means changing the CURRENT user and not passing
 * zero. gwc_vt_can_see_photo()'s second argument falls back to whoever is
 * asking when it is 0 — the same convention current_user_can() follows — so in
 * a script that has set itself to the administrator, passing 0 asks about the
 * administrator. The first version of this asserted exactly that and reported a
 * logged-out visitor being allowed in. */
wp_set_current_user( 0 );

gwc_vt_rg_check(
	'and nor may somebody logged out',
	! gwc_vt_can_see_photo( $GLOBALS['gwc_vt_rg_withphoto'] ),
	'a logged-out request was allowed'
);

wp_set_current_user( 1 );

/* Approving MOVES it rather than copying. Two files of one face would be two
 * things to delete on an erasure request and two chances to miss one. */
$GLOBALS['gwc_vt_rg_moved'] = (int) wp_insert_post(
	array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'publish', 'post_title' => 'Zzrg Face Person' )
);

$GLOBALS['gwc_vt_rg_made'][] = $GLOBALS['gwc_vt_rg_moved'];

$GLOBALS['gwc_vt_rg_before_move'] = gwc_vt_photo_file( $GLOBALS['gwc_vt_rg_withphoto'] );

update_post_meta( $GLOBALS['gwc_vt_rg_moved'], GWC_VT_PHOTO_KEY, $GLOBALS['gwc_vt_rg_before_move'] );
delete_post_meta( $GLOBALS['gwc_vt_rg_withphoto'], GWC_VT_PHOTO_KEY );

gwc_vt_rg_check(
	'accepting an offer moves the photograph rather than copying it',
	gwc_vt_has_photo( $GLOBALS['gwc_vt_rg_moved'] )
		&& ! gwc_vt_has_photo( $GLOBALS['gwc_vt_rg_withphoto'] )
		&& gwc_vt_photo_path( $GLOBALS['gwc_vt_rg_moved'] ) === $GLOBALS['gwc_vt_rg_file'],
	'the offer and the record both claim a photograph'
);

/* Discarding keeps the row and drops the face. There is no version of "we said
 * no" that needs a picture of the person it was said to. */
$GLOBALS['gwc_vt_rg_refused'] = gwc_vt_rg_newest();

gwc_vt_rg_photo( $GLOBALS['gwc_vt_rg_refused'], $GLOBALS['gwc_vt_rg_jpeg'] );

$GLOBALS['gwc_vt_rg_refused_file'] = gwc_vt_photo_path( $GLOBALS['gwc_vt_rg_refused'] );

/* gwc_vt_discard_application() and not gwc_vt_delete_photo() by hand. Calling
 * the helper myself is what the first version did, and it passed with the
 * discard's own call to it deleted — the test was performing the behaviour it
 * was supposed to be checking. */
gwc_vt_discard_application( $GLOBALS['gwc_vt_rg_refused'] );

gwc_vt_rg_check(
	'discarding an offer takes the photograph off disk',
	'' !== $GLOBALS['gwc_vt_rg_refused_file'] && ! file_exists( $GLOBALS['gwc_vt_rg_refused_file'] ),
	'the image outlived the refusal'
);

gwc_vt_rg_check(
	'and keeps the row, which is the record of the decision',
	GWC_VT_APPLICATION_DISCARDED === get_post_status( $GLOBALS['gwc_vt_rg_refused'] ),
	'the row went too — status is ' . get_post_status( $GLOBALS['gwc_vt_rg_refused'] )
);

/* And deleting the offer outright — by the eraser or the sweep — takes it too,
 * by the before_delete_post route rather than by anybody remembering. */
$GLOBALS['gwc_vt_rg_doomed'] = (int) wp_insert_post(
	array( 'post_type' => GWC_VT_APPLICATION_TYPE, 'post_status' => 'pending', 'post_title' => 'Zzrg Doomed Offer' )
);

gwc_vt_rg_photo( $GLOBALS['gwc_vt_rg_doomed'], $GLOBALS['gwc_vt_rg_jpeg'] );

$GLOBALS['gwc_vt_rg_doomed_file'] = gwc_vt_photo_path( $GLOBALS['gwc_vt_rg_doomed'] );

gwc_vt_delete_application( $GLOBALS['gwc_vt_rg_doomed'] );

gwc_vt_rg_check(
	'deleting an offer takes its photograph with it',
	'' !== $GLOBALS['gwc_vt_rg_doomed_file'] && ! file_exists( $GLOBALS['gwc_vt_rg_doomed_file'] ),
	'the image outlived the record it belonged to'
);

/* ── The block ───────────────────────────────────────────────────────────────
 * Checked across every block this plugin ships rather than only the new one.
 * Both rules below are ones CLAUDE.md says have already cost time, and both
 * fail silently — the sort that a test written for one block would not stop
 * happening to the fifth.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_rg_settings(
	array(
		'registration_enabled' => true,
		'registration_page'    => (int) $GLOBALS['gwc_vt_rg_page'],
	)
);

gwc_vt_rg_check(
	'the offer form is a registered block',
	WP_Block_Type_Registry::get_instance()->is_registered( 'groundwork-common-volunteer-tracker/volunteer-form' ),
	'it was never registered'
);

$GLOBALS['gwc_vt_rg_blocks'] = array();

foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $gwc_vt_rg_name => $gwc_vt_rg_type ) {
	if ( 0 === strpos( $gwc_vt_rg_name, 'groundwork-common-volunteer-tracker/' ) ) {
		$GLOBALS['gwc_vt_rg_blocks'][ $gwc_vt_rg_name ] = $gwc_vt_rg_type;
	}
}

gwc_vt_rg_check(
	'every block this plugin ships was found',
	5 === count( $GLOBALS['gwc_vt_rg_blocks'] ),
	'found ' . count( $GLOBALS['gwc_vt_rg_blocks'] ) . ': ' . implode( ', ', array_keys( $GLOBALS['gwc_vt_rg_blocks'] ) )
);

/* Hard rule 9. A handle missing from the loop in inc/block.php has its strings
 * extracted into the POT and rendered in English forever.
 *
 * Asserted on translations_path and NOT on textdomain, which is the whole
 * point. register_block_type_from_metadata() reads "textdomain" out of
 * block.json and calls wp_set_script_translations() itself — with no path — so
 * the textdomain is set whether or not inc/block.php's loop ran, and the first
 * version of this check passed happily with the new block removed from that
 * loop. What core's call does not do is say WHERE the translations live, so it
 * looks in WP_LANG_DIR/plugins and never at the .mo files this plugin ships in
 * its own languages/ directory.
 *
 * The path is the thing the loop supplies, so the path is the thing to check.
 * Found by deleting the loop entry and watching every assertion pass. */
$GLOBALS['gwc_vt_rg_untranslated'] = array();
$GLOBALS['gwc_vt_rg_langs']        = dirname( __DIR__, 2 ) . '/languages';

foreach ( $GLOBALS['gwc_vt_rg_blocks'] as $gwc_vt_rg_name => $gwc_vt_rg_type ) {
	$gwc_vt_rg_handle = $gwc_vt_rg_type->editor_script_handles[0] ?? '';
	$gwc_vt_rg_script = '' !== $gwc_vt_rg_handle ? wp_scripts()->query( $gwc_vt_rg_handle ) : null;

	if ( ! $gwc_vt_rg_script ) {
		$GLOBALS['gwc_vt_rg_untranslated'][] = $gwc_vt_rg_name . ' (no script)';
		continue;
	}

	if ( 'groundwork-common-volunteer-tracker' !== ( $gwc_vt_rg_script->textdomain ?? '' ) ) {
		$GLOBALS['gwc_vt_rg_untranslated'][] = $gwc_vt_rg_name . ' (no textdomain)';
		continue;
	}

	if ( '' === (string) ( $gwc_vt_rg_script->translations_path ?? '' ) ) {
		$GLOBALS['gwc_vt_rg_untranslated'][] = $gwc_vt_rg_name . ' (no path — it will look in WP_LANG_DIR, not this plugin)';
	}
}

gwc_vt_rg_check(
	'every block editor script is pointed at this plugin\'s own translations',
	array() === $GLOBALS['gwc_vt_rg_untranslated'],
	implode( '; ', $GLOBALS['gwc_vt_rg_untranslated'] )
);

/* And the hand-written asset file matches what the hand-written script reaches
 * for. There is no build step to derive one from the other, so they drift — and
 * the symptom is a block that throws in the editor only on a site whose script
 * loading order differs from the one it was written on. */
$GLOBALS['gwc_vt_rg_deps'] = array();

foreach ( array( 'hours-form', 'shift-list', 'event-grid', 'volunteer-form', 'volunteer-signin' ) as $gwc_vt_rg_block ) {
	$gwc_vt_rg_dir = dirname( __DIR__, 2 ) . '/blocks/' . $gwc_vt_rg_block;
	$gwc_vt_rg_js  = (string) file_get_contents( $gwc_vt_rg_dir . '/edit.js' );
	$gwc_vt_rg_ast = include $gwc_vt_rg_dir . '/edit.asset.php';

	$gwc_vt_rg_declared = (array) ( $gwc_vt_rg_ast['dependencies'] ?? array() );

	/* [a-zA-Z0-9]+ and not [a-zA-Z]+: window.wp.i18n has digits in the middle,
	 * so the letters-only pattern matched "i" and then reported every block as
	 * using an undeclared "wp-i". */
	preg_match_all( '/window\.wp\.([a-zA-Z0-9]+)/', $gwc_vt_rg_js, $gwc_vt_rg_found );

	foreach ( array_unique( $gwc_vt_rg_found[1] ) as $gwc_vt_rg_global ) {
		/* wp.blockEditor is the 'wp-block-editor' handle: camelCase to dashes. */
		$gwc_vt_rg_handle = 'wp-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $gwc_vt_rg_global ) );

		if ( ! in_array( $gwc_vt_rg_handle, $gwc_vt_rg_declared, true ) ) {
			/* $GLOBALS and not a bare local. wp eval-file runs this inside a
			 * function, so the two are different variables — the trap CLAUDE.md
			 * names, walked into here by initialising the global and then
			 * appending to the local. */
			$GLOBALS['gwc_vt_rg_deps'][] = $gwc_vt_rg_block . ' uses ' . $gwc_vt_rg_global . ' without declaring ' . $gwc_vt_rg_handle;
		}
	}
}

gwc_vt_rg_check(
	'every block declares the wp.* globals its script uses',
	array() === $GLOBALS['gwc_vt_rg_deps'],
	implode( '; ', $GLOBALS['gwc_vt_rg_deps'] )
);

/* The block renders the same form the shortcode does.
 *
 * The result global is cleared first. The submissions above left it at
 * 'accepted', and an accepted form deliberately does NOT come back — so the
 * render was correctly showing a thank-you with no fields in it, and the check
 * below was reading that as a block that renders nothing. */
unset( $GLOBALS['gwc_vt_registration_result'] );

$GLOBALS['gwc_vt_rg_rendered'] = do_blocks( '<!-- wp:groundwork-common-volunteer-tracker/volunteer-form /-->' );

gwc_vt_rg_check(
	'the block renders the form',
	false !== strpos( $GLOBALS['gwc_vt_rg_rendered'], 'gwc_vt_registration_nonce' )
		&& false !== strpos( $GLOBALS['gwc_vt_rg_rendered'], 'gwcvt-form__hp' ),
	'the block rendered no form'
);

gwc_vt_rg_settings( array( 'registration_enabled' => false ) );

gwc_vt_rg_check(
	'and renders nothing at all with the feature off',
	'' === trim( wp_strip_all_tags( do_blocks( '<!-- wp:groundwork-common-volunteer-tracker/volunteer-form /-->' ) ) ),
	'the block rendered something with the feature switched off'
);

/* ── The dispatcher only listens on its own page ─────────────────────────── */

gwc_vt_rg_settings(
	array(
		'registration_enabled' => true,
		/* Pinned somewhere else. */
		'registration_page'    => (int) $GLOBALS['gwc_vt_rg_page'] + 99999,
	)
);

/* gwc_vt_is_registration_page() answers true before template_redirect, on
 * purpose: outside a main query — a widget, a REST render, WP-CLI — is_page()
 * is not a meaningful question, and the hours form answers the same way for the
 * same reason. Which means that under wp eval-file the pinned-page branch is
 * unreachable, and the first version of this check asserted a refusal that
 * could never happen.
 *
 * The counter is what did_action() reads, so setting it is the narrowest way to
 * put the function on the branch it guards. Firing the action for real would
 * run core's own template_redirect handlers, one of which can exit(). */
$GLOBALS['wp_actions']['template_redirect'] = 1;

gwc_vt_rg_check(
	'the form refuses to render away from its pinned page',
	false !== strpos( gwc_vt_render_registration_form(), 'set up on another page' ),
	'it rendered a working-looking form somewhere it cannot accept posts'
);

unset( $GLOBALS['wp_actions']['template_redirect'] );

gwc_vt_rg_settings( array( 'registration_enabled' => false ) );

gwc_vt_rg_check(
	'and renders nothing at all when the feature is off',
	'' === gwc_vt_render_registration_form(),
	'it rendered something with the feature switched off'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

$_POST = $GLOBALS['gwc_vt_rg_post'];
$_GET  = array();

update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_rg_opts'] );
gwc_vt_settings_cache( null, true );

/* Left clean rather than restored — see the note at the top. */
delete_option( GWC_VT_RATE_LIMIT_OPTION );

/* Every registered status, not 'any'. 'any' means "not exclude_from_search",
 * and every custom status this plugin registers sets that flag — so a sweep
 * asking for 'any' silently skips the discarded, cancelled, waitlisted,
 * withdrawn and retired rows it was written to collect, and they pile up in the
 * development database one run at a time. tests/seed.php carries the long
 * version of this note. */
foreach ( get_posts( array( 'post_type' => GWC_VT_APPLICATION_TYPE, 'post_status' => array_values( get_post_stati() ), 'numberposts' => -1 ) ) as $gwc_vt_rg_app ) {
	if ( false !== strpos( (string) get_post_meta( $gwc_vt_rg_app->ID, GWC_VT_APPLICATION_EMAIL, true ), 'zzrg-' ) ) {
		$GLOBALS['gwc_vt_rg_made'][] = (int) $gwc_vt_rg_app->ID;
	}
}

foreach ( get_posts( array( 'post_type' => GWC_VT_VOLUNTEER_TYPE, 'post_status' => 'any', 'numberposts' => -1, 's' => 'Zzrg' ) ) as $gwc_vt_rg_vol ) {
	$GLOBALS['gwc_vt_rg_made'][] = (int) $gwc_vt_rg_vol->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_rg_made'] ) as $gwc_vt_rg_id ) {
	wp_delete_post( (int) $gwc_vt_rg_id, true );
}

if ( file_exists( $GLOBALS['gwc_vt_rg_jpeg'] ) ) {
	unlink( $GLOBALS['gwc_vt_rg_jpeg'] );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

wp_delete_user( $GLOBALS['gwc_vt_rg_sub'] );

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

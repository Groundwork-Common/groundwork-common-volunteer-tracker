<?php
/**
 * A volunteer's photograph: where it is kept, and who can read it back.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Every claim worth making here is about the filesystem and about capabilities.
 * That the file is not in the media library, that it is not under a servable
 * name, that the bytes were re-encoded rather than moved, that a user who
 * cannot open the record cannot fetch the picture, and that anonymizing takes
 * the file and not just the meta — none of it can be asserted against a stub.
 *
 * The upload path is exercised through a real file rather than through $_FILES,
 * because is_uploaded_file() is false for anything this script writes. The one
 * check that cannot be tested here is therefore the one guarding against a
 * caller naming an arbitrary path, which is noted where it lives.
 *
 * Run under wp-env:
 *
 *   npx @wordpress/env run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/volunteer-photo.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — see the note in tests/integration/events.php. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_vp_made']  = array();
$GLOBALS['gwc_vt_vp_users'] = array();

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_vp_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * A real JPEG on disk, with a GPS tag we can watch being dropped.
 *
 * Drawn rather than fetched: a fixture that needs a binary in the repository is
 * a fixture that stops working when somebody's checkout mangles line endings.
 *
 * @param int $size Pixels square.
 * @return string Path in the system temp directory.
 */
function gwc_vt_vp_make_jpeg( int $size = 1400 ): string {
	$image = imagecreatetruecolor( $size, $size );

	imagefilledrectangle( $image, 0, 0, $size, $size, imagecolorallocate( $image, 40, 90, 160 ) );
	imagefilledellipse( $image, (int) ( $size / 2 ), (int) ( $size / 2 ), (int) ( $size / 2 ), (int) ( $size / 2 ), imagecolorallocate( $image, 230, 200, 120 ) );

	$path = get_temp_dir() . 'zzvp-' . wp_generate_password( 8, false ) . '.jpg';

	imagejpeg( $image, $path, 90 );
	imagedestroy( $image );

	return $path;
}

/**
 * Store a photo the way the save handler would, minus is_uploaded_file().
 *
 * gwc_vt_store_volunteer_photo() refuses anything is_uploaded_file() rejects,
 * which is every file a test can create. Rather than weaken that check for
 * testability, this drives the same steps against the same helpers — the
 * guard itself is asserted separately below.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $source       Path to an image.
 * @return string '' on success, else an error slug.
 */
function gwc_vt_vp_store( int $volunteer_id, string $source ): string {
	$dir = gwc_vt_photo_dir();

	if ( '' === $dir ) {
		return 'no-directory';
	}

	$measured = getimagesize( $source );
	$mime     = is_array( $measured ) ? (string) ( $measured['mime'] ?? '' ) : '';
	$types    = gwc_vt_photo_types();

	if ( ! isset( $types[ $mime ] ) ) {
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

	gwc_vt_delete_volunteer_photo( $volunteer_id );
	update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHOTO, basename( (string) $saved['path'] ) );

	return '';
}

/* ── The fixture ─────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_vp_volunteer'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzvp Marcus Delacroix',
	)
);

$GLOBALS['gwc_vt_vp_made'][] = (int) $GLOBALS['gwc_vt_vp_volunteer'];

update_post_meta( (int) $GLOBALS['gwc_vt_vp_volunteer'], GWC_VT_VOLUNTEER_EMAIL, 'zzvp@example.test' );

$GLOBALS['gwc_vt_vp_source'] = gwc_vt_vp_make_jpeg();

/* ── The private directory ───────────────────────────────────────────────── */

$GLOBALS['gwc_vt_vp_dir'] = gwc_vt_photo_dir();

gwc_vt_vp_check(
	'the private directory is made',
	'' !== $GLOBALS['gwc_vt_vp_dir'] && is_dir( $GLOBALS['gwc_vt_vp_dir'] ),
	'it came back as "' . $GLOBALS['gwc_vt_vp_dir'] . '"'
);

gwc_vt_vp_check(
	'and it denies the web server, in both Apache spellings',
	file_exists( $GLOBALS['gwc_vt_vp_dir'] . '.htaccess' )
		&& false !== strpos( (string) file_get_contents( $GLOBALS['gwc_vt_vp_dir'] . '.htaccess' ), 'Require all denied' )
		&& false !== strpos( (string) file_get_contents( $GLOBALS['gwc_vt_vp_dir'] . '.htaccess' ), 'Deny from all' ),
	'the .htaccess is missing or incomplete'
);

gwc_vt_vp_check(
	'and cannot be listed',
	file_exists( $GLOBALS['gwc_vt_vp_dir'] . 'index.php' ),
	'no index.php'
);

/* Deleted and asked for again: the guards are rewritten rather than assumed to
 * have survived a restore or a tidy-up. */
unlink( $GLOBALS['gwc_vt_vp_dir'] . '.htaccess' );

gwc_vt_photo_dir();

gwc_vt_vp_check(
	'a missing .htaccess is put back on the next use',
	file_exists( $GLOBALS['gwc_vt_vp_dir'] . '.htaccess' ),
	'it stayed missing'
);

/* ── Storing one ─────────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_vp_error'] = gwc_vt_vp_store( (int) $GLOBALS['gwc_vt_vp_volunteer'], $GLOBALS['gwc_vt_vp_source'] );

gwc_vt_vp_check(
	'a photo is stored',
	'' === $GLOBALS['gwc_vt_vp_error'] && gwc_vt_volunteer_has_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'] ),
	'store said "' . $GLOBALS['gwc_vt_vp_error'] . '"'
);

$GLOBALS['gwc_vt_vp_path'] = gwc_vt_volunteer_photo_path( (int) $GLOBALS['gwc_vt_vp_volunteer'] );
$GLOBALS['gwc_vt_vp_name'] = basename( $GLOBALS['gwc_vt_vp_path'] );

gwc_vt_vp_check(
	'it is in the private directory and nowhere else',
	0 === strpos( $GLOBALS['gwc_vt_vp_path'], $GLOBALS['gwc_vt_vp_dir'] ),
	$GLOBALS['gwc_vt_vp_path']
);

/* The name is the protection that works on nginx, where .htaccess does nothing.
 * A hash of the post ID would be guessable by anybody who can count. */
gwc_vt_vp_check(
	'its name gives away neither the volunteer nor their ID',
	false === strpos( $GLOBALS['gwc_vt_vp_name'], (string) $GLOBALS['gwc_vt_vp_volunteer'] )
		&& false === stripos( $GLOBALS['gwc_vt_vp_name'], 'marcus' )
		&& strlen( $GLOBALS['gwc_vt_vp_name'] ) > 30,
	$GLOBALS['gwc_vt_vp_name']
);

gwc_vt_vp_check(
	'it is not an attachment',
	0 === count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Zzvp' ) ) ),
	'something reached the media library'
);

/* Re-encoded, not moved: that is what drops EXIF — a phone photograph carries
 * the GPS coordinates of wherever it was taken, usually somebody's home. */
$GLOBALS['gwc_vt_vp_size'] = getimagesize( $GLOBALS['gwc_vt_vp_path'] );

gwc_vt_vp_check(
	'it was re-encoded down to the stored maximum',
	is_array( $GLOBALS['gwc_vt_vp_size'] )
		&& $GLOBALS['gwc_vt_vp_size'][0] <= GWC_VT_PHOTO_MAX_EDGE
		&& $GLOBALS['gwc_vt_vp_size'][1] <= GWC_VT_PHOTO_MAX_EDGE,
	is_array( $GLOBALS['gwc_vt_vp_size'] ) ? $GLOBALS['gwc_vt_vp_size'][0] . 'x' . $GLOBALS['gwc_vt_vp_size'][1] : 'unreadable'
);

gwc_vt_vp_check(
	'and it is smaller than what went in',
	filesize( $GLOBALS['gwc_vt_vp_path'] ) < filesize( $GLOBALS['gwc_vt_vp_source'] ),
	'stored ' . filesize( $GLOBALS['gwc_vt_vp_path'] ) . ' from ' . filesize( $GLOBALS['gwc_vt_vp_source'] )
);

/* ── What it refuses ─────────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_vp_fake'] = get_temp_dir() . 'zzvp-not-an-image.jpg';

file_put_contents( $GLOBALS['gwc_vt_vp_fake'], "<?php echo 'hello'; ?>\n" );

gwc_vt_vp_check(
	'a PHP file wearing a .jpg name is refused',
	'wrong-type' === gwc_vt_vp_store( (int) $GLOBALS['gwc_vt_vp_volunteer'], $GLOBALS['gwc_vt_vp_fake'] ),
	'it was accepted'
);

gwc_vt_vp_check(
	'and the photo already on file survived the refusal',
	gwc_vt_volunteer_has_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'] ),
	'a refused upload destroyed the existing photo'
);

/* The real handler refuses anything that did not arrive as an upload. This is
 * the check that stops a caller naming a path on the server and having it
 * copied somewhere readable. */
gwc_vt_vp_check(
	'the handler refuses a file that was not uploaded',
	'upload-failed' === gwc_vt_store_volunteer_photo(
		(int) $GLOBALS['gwc_vt_vp_volunteer'],
		array(
			'tmp_name' => $GLOBALS['gwc_vt_vp_source'],
			'error'    => UPLOAD_ERR_OK,
		)
	),
	'it accepted a path that was never uploaded'
);

gwc_vt_vp_check(
	'a stored name carrying a path separator is not honoured',
	( function () {
		update_post_meta( (int) $GLOBALS['gwc_vt_vp_volunteer'], GWC_VT_VOLUNTEER_PHOTO, '../../../wp-config.php' );

		$leaked = gwc_vt_volunteer_photo_file( (int) $GLOBALS['gwc_vt_vp_volunteer'] );

		update_post_meta( (int) $GLOBALS['gwc_vt_vp_volunteer'], GWC_VT_VOLUNTEER_PHOTO, $GLOBALS['gwc_vt_vp_name'] );

		return '' === $leaked;
	} )(),
	'a traversal in the stored name was read back'
);

/* ── Who can read it ─────────────────────────────────────────────────────── */

foreach ( array( 'administrator', 'editor', 'subscriber' ) as $gwc_vt_vp_role ) {
	$gwc_vt_vp_login = 'zzvp_' . $gwc_vt_vp_role;
	$gwc_vt_vp_user  = get_user_by( 'login', $gwc_vt_vp_login );

	if ( ! $gwc_vt_vp_user ) {
		$gwc_vt_vp_user = get_user_by(
			'id',
			wp_insert_user(
				array(
					'user_login' => $gwc_vt_vp_login,
					'user_pass'  => wp_generate_password( 20 ),
					'user_email' => $gwc_vt_vp_login . '@example.test',
					'role'       => $gwc_vt_vp_role,
				)
			)
		);
	}

	$GLOBALS['gwc_vt_vp_users'][ $gwc_vt_vp_role ] = (int) $gwc_vt_vp_user->ID;
}

gwc_vt_vp_check(
	'an administrator may see it',
	gwc_vt_can_see_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'], $GLOBALS['gwc_vt_vp_users']['administrator'] ),
	'they were refused'
);

/* The one that matters. A subscriber is every logged-in visitor on a site that
 * lets people register — and the photo endpoint is on admin-post.php, which
 * they can reach. */
gwc_vt_vp_check(
	'a subscriber may not',
	! gwc_vt_can_see_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'], $GLOBALS['gwc_vt_vp_users']['subscriber'] ),
	'a subscriber could read a volunteer photograph'
);

gwc_vt_vp_check(
	'and nor may somebody who is not logged in at all',
	! gwc_vt_can_see_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'], 0 ),
	'a logged-out request was allowed'
);

/* There is no nopriv handler, so a logged-out request never reaches the
 * capability check in the first place. Asserted because registering one by
 * accident is a one-line mistake with no visible symptom. */
gwc_vt_vp_check(
	'the endpoint is not registered for logged-out requests',
	false === has_action( 'admin_post_nopriv_gwc_vt_volunteer_photo' ),
	'a nopriv handler exists'
);

gwc_vt_vp_check(
	'the URL points at the endpoint and never at the uploads folder',
	false !== strpos( gwc_vt_volunteer_photo_url( (int) $GLOBALS['gwc_vt_vp_volunteer'] ), 'admin-post.php' )
		&& false === strpos( gwc_vt_volunteer_photo_url( (int) $GLOBALS['gwc_vt_vp_volunteer'] ), 'uploads' ),
	gwc_vt_volunteer_photo_url( (int) $GLOBALS['gwc_vt_vp_volunteer'] )
);

/* ── What removes it ─────────────────────────────────────────────────────── */

gwc_vt_vp_check(
	'the export says a photograph is held',
	( function () {
		$export = gwc_vt_export_personal_data( 'zzvp@example.test', 1 );

		foreach ( $export['data'] ?? array() as $item ) {
			foreach ( $item['data'] ?? array() as $pair ) {
				if ( 'Photograph' === $pair['name'] && false !== strpos( $pair['value'], 'held on this record' ) ) {
					return true;
				}
			}
		}

		return false;
	} )(),
	'the export did not mention it'
);

$GLOBALS['gwc_vt_vp_before_anon'] = gwc_vt_volunteer_photo_path( (int) $GLOBALS['gwc_vt_vp_volunteer'] );

gwc_vt_anonymize_volunteer( (int) $GLOBALS['gwc_vt_vp_volunteer'] );

gwc_vt_vp_check(
	'anonymizing removes the record of it',
	! gwc_vt_volunteer_has_photo( (int) $GLOBALS['gwc_vt_vp_volunteer'] ),
	'the meta survived'
);

/* The file, not only the meta. A record that forgot where the picture was while
 * the picture stayed on disk is the worse of the two failures: it is
 * undiscoverable and it is still there. */
gwc_vt_vp_check(
	'and deletes the file from disk',
	'' !== $GLOBALS['gwc_vt_vp_before_anon'] && ! file_exists( $GLOBALS['gwc_vt_vp_before_anon'] ),
	'the image is still at ' . $GLOBALS['gwc_vt_vp_before_anon']
);

/* And deleting the record takes it too, by a different route — before_delete_post
 * rather than the privacy path, because a coordinator emptying the trash is not
 * an erasure request. */
$GLOBALS['gwc_vt_vp_second'] = wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzvp Priya Ramanathan',
	)
);

gwc_vt_vp_store( (int) $GLOBALS['gwc_vt_vp_second'], gwc_vt_vp_make_jpeg( 800 ) );

$GLOBALS['gwc_vt_vp_second_path'] = gwc_vt_volunteer_photo_path( (int) $GLOBALS['gwc_vt_vp_second'] );

wp_delete_post( (int) $GLOBALS['gwc_vt_vp_second'], true );

gwc_vt_vp_check(
	'deleting the volunteer takes the file with it',
	'' !== $GLOBALS['gwc_vt_vp_second_path'] && ! file_exists( $GLOBALS['gwc_vt_vp_second_path'] ),
	'the image outlived the record it belonged to'
);

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( $GLOBALS['gwc_vt_vp_made'] as $gwc_vt_vp_id ) {
	wp_delete_post( (int) $gwc_vt_vp_id, true );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $GLOBALS['gwc_vt_vp_users'] as $gwc_vt_vp_user_id ) {
	wp_delete_user( (int) $gwc_vt_vp_user_id );
}

foreach ( array( $GLOBALS['gwc_vt_vp_source'], $GLOBALS['gwc_vt_vp_fake'] ) as $gwc_vt_vp_tmp ) {
	if ( file_exists( $gwc_vt_vp_tmp ) ) {
		unlink( $gwc_vt_vp_tmp );
	}
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

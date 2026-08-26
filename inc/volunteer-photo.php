<?php
/**
 * A photograph of a volunteer, kept where the web server will not serve it.
 *
 * ── Why this is not an attachment ────────────────────────────────────────────
 * The obvious implementation is the media library, the way the letterhead logo
 * in Settings already works. It is refused here, and the reason is not taste.
 *
 * An attachment is a file under wp-content/uploads, served straight off the
 * filesystem by Apache or nginx before PHP is involved. Nothing about the
 * volunteer post type being `public => false` reaches it: the URL is
 * unauthenticated, it is listed at /wp/v2/media, and it shows in the Media
 * Library to every author on the site. For this plugin that means a photograph
 * of somebody's face — including, for some of these records, somebody working
 * off a court order — at a public address. That is the disclosure the whole
 * plugin is arranged to prevent, and hard rule 2 refuses a far weaker version
 * of it.
 *
 * So the file goes in a private directory and is read back through PHP, which
 * checks the same capability as the record it belongs to, on every request.
 *
 * ── What actually protects the file ──────────────────────────────────────────
 * Three things, and it is worth being honest about which does the work:
 *
 *   The filename. 32 random characters from wp_generate_password(), stored in
 *   meta. This is the primary protection and it is the only one that works on
 *   every server. It is not derived from the volunteer's ID or name — a hash of
 *   the ID is guessable by anybody who can count.
 *
 *   The .htaccess. Denies everything, on Apache. DreamHost — where the beta
 *   site runs — is Apache, and so is most shared hosting. nginx ignores
 *   .htaccess entirely, which is why it is not the primary protection.
 *
 *   The index.php. Stops a directory listing if the server has indexes on.
 *
 * A caller that wants the bytes uses gwc_vt_volunteer_photo_url(), which points
 * at the capability-checked endpoint. Nothing in this plugin ever emits the
 * filesystem path or a direct uploads URL.
 *
 * ── What removes it ──────────────────────────────────────────────────────────
 * Anonymizing, erasing, the retention sweep, and deleting the volunteer post.
 * A face is the most identifying thing in these records, so anonymizing — which
 * keeps the hours — must drop it, and does.
 *
 * Uninstall does NOT remove it, per hard rule 10. Deleting the plugin removes
 * no records, and a photograph is a record.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_gwc_vt_volunteer_photo', 'gwc_vt_serve_volunteer_photo' );
add_action( 'before_delete_post', 'gwc_vt_delete_photo_with_volunteer' );

/** The stored filename, relative to the private directory. */
const GWC_VT_VOLUNTEER_PHOTO = '_gwc_vt_photo';

/** Longest edge, in pixels, a stored photo is reduced to. */
const GWC_VT_PHOTO_MAX_EDGE = 600;

/** Largest upload accepted, in bytes, before anything is decoded. */
const GWC_VT_PHOTO_MAX_BYTES = 8388608;

/**
 * What a volunteer photo may be.
 *
 * A function rather than a const because it is a table keyed by MIME type, and
 * the codebase's rule is that translated tables are functions — this one is not
 * translated, but it is read alongside gwc_vt_photo_error() which is, and
 * splitting the two spellings across one feature invites the const back.
 *
 * WebP is here because phones produce it. AVIF is not: wp_get_image_editor()
 * support depends on the host's GD or Imagick build, and a format that decodes
 * on the developer's machine and fails on shared hosting is a feature that
 * works until somebody real uses it.
 *
 * @return array<string, string> MIME type => file extension.
 */
function gwc_vt_photo_types(): array {
	return array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);
}

/**
 * The directory photos live in, created and protected on first use.
 *
 * Returns '' when the directory cannot be made or protected. A caller that gets
 * '' must refuse the upload rather than fall back to the ordinary uploads path:
 * failing open here would put the file exactly where this file exists to keep it
 * away from.
 *
 * @return string Absolute path with a trailing slash, or ''.
 */
function gwc_vt_photo_dir(): string {
	$uploads = wp_upload_dir();

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'gwc-vt-private/';

	if ( ! wp_mkdir_p( $dir ) ) {
		return '';
	}

	/* Written every time the directory is asked for, not once on activation.
	 * A migration, a restore, or somebody tidying wp-content can leave the
	 * directory without its guards, and a plugin that only ever wrote them at
	 * activation would never notice. Both writes are skipped when the file is
	 * already there, so this is two file_exists() calls in the common case. */
	$htaccess = $dir . '.htaccess';

	if ( ! file_exists( $htaccess ) ) {
		/* Both spellings: 2.4 ignores the 2.2 syntax and vice versa, and shared
		 * hosting runs both. <IfModule> so a server with neither does not 500. */
		$rules = "# Volunteer photographs. Not for the web to serve.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

		gwc_vt_write_private_file( $htaccess, $rules );
	}

	$index = $dir . 'index.php';

	if ( ! file_exists( $index ) ) {
		gwc_vt_write_private_file( $index, "<?php\n// Silence is golden.\n" );
	}

	return $dir;
}

/**
 * Write one of the directory's guard files.
 *
 * Through WP_Filesystem where it is available, because a host running WordPress
 * over FTP credentials has a uploads directory PHP cannot write to directly, and
 * file_put_contents() there fails silently into an unprotected directory.
 *
 * @param string $path     Absolute path.
 * @param string $contents What to write.
 * @return bool
 */
function gwc_vt_write_private_file( string $path, string $contents ): bool {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( WP_Filesystem() && $wp_filesystem ) {
		return (bool) $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	return false;
}

/**
 * The stored filename for a volunteer, or '' if there is no photo.
 *
 * Only ever a bare filename. A stored value carrying a slash would be a path
 * traversal waiting for a caller that concatenates without thinking, so it is
 * checked on the way out rather than trusted because this file wrote it.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string
 */
function gwc_vt_volunteer_photo_file( int $volunteer_id ): string {
	$stored = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHOTO, true );

	if ( '' === $stored || basename( $stored ) !== $stored ) {
		return '';
	}

	return $stored;
}

/**
 * The absolute path of a volunteer's photo, or '' if there is not one on disk.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string
 */
function gwc_vt_volunteer_photo_path( int $volunteer_id ): string {
	$file = gwc_vt_volunteer_photo_file( $volunteer_id );

	if ( '' === $file ) {
		return '';
	}

	$dir = gwc_vt_photo_dir();

	if ( '' === $dir || ! is_readable( $dir . $file ) ) {
		return '';
	}

	return $dir . $file;
}

/**
 * Whether this volunteer has a photo.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwc_vt_volunteer_has_photo( int $volunteer_id ): bool {
	return '' !== gwc_vt_volunteer_photo_path( $volunteer_id );
}

/**
 * The URL that serves a volunteer's photo to somebody allowed to see it.
 *
 * No nonce, deliberately. A nonce in an <img src> expires inside a day and
 * turns a cached page into broken images, and it would be guarding a read that
 * changes nothing — the CSRF a nonce prevents does not apply to fetching an
 * image, which any page on the internet can already attempt. What matters is
 * that gwc_vt_serve_volunteer_photo() re-checks the capability on every single
 * request, so a URL that leaks is a URL that stops working for whoever it
 * leaked to.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string Empty when there is no photo.
 */
function gwc_vt_volunteer_photo_url( int $volunteer_id ): string {
	if ( ! gwc_vt_volunteer_has_photo( $volunteer_id ) ) {
		return '';
	}

	return add_query_arg(
		array(
			'action'    => 'gwc_vt_volunteer_photo',
			'volunteer' => $volunteer_id,
			/* Changes when the photo changes, so a replaced picture is not the
			 * old one out of the browser cache. Not a secret and not doing any
			 * protecting — the filename it is derived from is already known to
			 * anybody who can call this. */
			'v'         => substr( md5( gwc_vt_volunteer_photo_file( $volunteer_id ) ), 0, 8 ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Who may see a volunteer's photograph.
 *
 * The same answer as who may open the record it is attached to. A separate
 * capability was considered and rejected: it would be one more thing to get
 * wrong on a migration, and there is no coherent role that may read somebody's
 * court-referral status and hours but not see their face.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @param int $user_id      Optional. Defaults to the current user.
 * @return bool
 */
function gwc_vt_can_see_photo( int $volunteer_id, int $user_id = 0 ): bool {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return false;
	}

	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	return user_can( $user_id, 'edit_post', $volunteer_id );
}

/**
 * Send the bytes, to somebody allowed to have them.
 */
function gwc_vt_serve_volunteer_photo(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read authorized by capability on every request; see the note on gwc_vt_volunteer_photo_url().
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;

	if ( ! gwc_vt_can_see_photo( $volunteer_id ) ) {
		/* 403 and nothing else. Not 404: distinguishing "no such volunteer" from
		 * "not yours to see" would answer, to anybody who can log in at all,
		 * whether a given record exists — which for these records is most of
		 * what somebody would want to know. */
		status_header( 403 );
		nocache_headers();
		exit;
	}

	$path = gwc_vt_volunteer_photo_path( $volunteer_id );

	if ( '' === $path ) {
		status_header( 404 );
		nocache_headers();
		exit;
	}

	$types = gwc_vt_photo_types();
	$mime  = (string) ( wp_check_filetype( $path, array_flip( $types ) )['type'] ?? '' );

	if ( '' === $mime ) {
		status_header( 404 );
		nocache_headers();
		exit;
	}

	nocache_headers();

	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . (string) filesize( $path ) );

	/* Private, so a shared proxy does not hold a copy that outlives the
	 * capability check. The URL carries a version argument for the browser's
	 * own cache, which is where re-fetching actually matters. */
	header( 'Cache-Control: private, max-age=0, must-revalidate' );
	header( 'X-Content-Type-Options: nosniff' );

	/* An image the browser must never be talked into treating as a document.
	 * These files are decoded and re-encoded on the way in, so an SVG or an
	 * HTML file cannot reach here — this is the second lock on that door. */
	header( 'Content-Disposition: inline; filename="volunteer.' . $types[ $mime ] . '"' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a file to the browser; WP_Filesystem has no streaming read and get_contents() would hold the whole image in memory.
	readfile( $path );
	exit;
}

/**
 * Store an uploaded photo against a volunteer, replacing any previous one.
 *
 * Everything is re-encoded through wp_get_image_editor() rather than moved into
 * place. That does three jobs at once: it proves the bytes really are a decodable
 * image rather than something wearing an image's name, it drops EXIF — which on
 * a phone photograph routinely carries the GPS coordinates of wherever it was
 * taken, usually somebody's home — and it caps the dimensions so a record does
 * not carry twelve megapixels of a face around for the next six years.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param array $file         One entry from $_FILES.
 * @return string '' on success, otherwise an error slug for gwc_vt_photo_error().
 */
function gwc_vt_store_volunteer_photo( int $volunteer_id, array $file ): string {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return 'no-volunteer';
	}

	if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
		return 'none';
	}

	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		return UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error']
			? 'too-big'
			: 'upload-failed';
	}

	$tmp = (string) $file['tmp_name'];

	/* The one check that cannot be skipped: without it a caller could name any
	 * path on the server and have it copied into the uploads directory. */
	if ( ! is_uploaded_file( $tmp ) ) {
		return 'upload-failed';
	}

	if ( filesize( $tmp ) > GWC_VT_PHOTO_MAX_BYTES ) {
		return 'too-big';
	}

	/* Read from the bytes, never from the name somebody sent. A file called
	 * portrait.jpg containing PHP is the oldest upload bug there is. */
	$measured = getimagesize( $tmp );
	$mime     = is_array( $measured ) ? (string) ( $measured['mime'] ?? '' ) : '';
	$types    = gwc_vt_photo_types();

	if ( ! isset( $types[ $mime ] ) ) {
		return 'wrong-type';
	}

	$dir = gwc_vt_photo_dir();

	if ( '' === $dir ) {
		/* Refused rather than written somewhere servable. See the note on
		 * gwc_vt_photo_dir(). */
		return 'no-directory';
	}

	$editor = wp_get_image_editor( $tmp );

	if ( is_wp_error( $editor ) ) {
		return 'wrong-type';
	}

	$editor->resize( GWC_VT_PHOTO_MAX_EDGE, GWC_VT_PHOTO_MAX_EDGE, false );

	$name  = wp_generate_password( 32, false ) . '.' . $types[ $mime ];
	$saved = $editor->save( $dir . $name, $mime );

	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return 'upload-failed';
	}

	/* The old one goes only once the new one is on disk. Deleting first would
	 * lose the existing photo to a failed re-encode. */
	gwc_vt_delete_volunteer_photo( $volunteer_id );

	update_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHOTO, basename( (string) $saved['path'] ) );

	return '';
}

/**
 * Remove a volunteer's photo, from disk and from the record.
 *
 * Safe to call for a volunteer who has none, which is what lets the retention
 * sweep and the eraser call it without asking first.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool Whether a file was removed.
 */
function gwc_vt_delete_volunteer_photo( int $volunteer_id ): bool {
	$path = gwc_vt_volunteer_photo_path( $volunteer_id );

	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHOTO );

	if ( '' === $path ) {
		return false;
	}

	/* wp_delete_file() rather than unlink(): it runs the 'wp_delete_file'
	 * filter, which is how a site using object storage or an offload plugin
	 * gets told the file is going. Reaching past it would leave the copy that
	 * actually matters in place on exactly the sites where that is worst. */
	wp_delete_file( $path );

	return ! file_exists( $path );
}

/**
 * Take the photo with the volunteer when the record is deleted for good.
 *
 * On before_delete_post rather than after: once the post is gone so is the meta,
 * and with it the only record of which file belonged to whom. The file would
 * stay in the private directory forever with nothing pointing at it.
 *
 * Note this does not fire on uninstall, and must not — hard rule 10 says
 * deleting the plugin removes no records, and a photograph is a record.
 *
 * @param int $post_id The post about to be deleted.
 */
function gwc_vt_delete_photo_with_volunteer( $post_id ): void {
	$post_id = (int) $post_id;

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $post_id ) ) {
		return;
	}

	gwc_vt_delete_volunteer_photo( $post_id );
}

/**
 * What went wrong, in a sentence somebody can act on.
 *
 * A function, not a const: evaluated at include time these would freeze in
 * English for the request. See the trap in CLAUDE.md.
 *
 * @param string $slug What gwc_vt_store_volunteer_photo() returned.
 * @return string Empty when the slug is not one of ours.
 */
function gwc_vt_photo_error( string $slug ): string {
	$errors = array(
		'too-big'       => sprintf(
			/* translators: %s: a file size, such as "8 MB". */
			__( 'That photo is larger than %s. Nothing else about the record was changed.', 'groundwork-common-volunteer-tracker' ),
			size_format( GWC_VT_PHOTO_MAX_BYTES )
		),
		'wrong-type'    => __( 'That file is not a JPEG, PNG or WebP image — or it could not be read as one. Nothing else about the record was changed.', 'groundwork-common-volunteer-tracker' ),
		'upload-failed' => __( 'The photo did not finish uploading. Nothing else about the record was changed.', 'groundwork-common-volunteer-tracker' ),
		'no-directory'  => __( 'The photo could not be stored somewhere private, so it was not stored at all. Check that the uploads folder is writable.', 'groundwork-common-volunteer-tracker' ),
		'no-volunteer'  => __( 'That record is not a volunteer.', 'groundwork-common-volunteer-tracker' ),
	);

	return (string) ( $errors[ $slug ] ?? '' );
}

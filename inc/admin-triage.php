<?php
/**
 * Turning a submission from a stranger into a shift on somebody's record.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_attach_entry', 'gwc_vt_handle_attach_entry' );
add_action( 'admin_post_gwc_vt_create_volunteer_from_entry', 'gwc_vt_handle_create_volunteer_from_entry' );
add_action( 'admin_notices', 'gwc_vt_triage_queue_notice' );
add_action( 'admin_notices', 'gwc_vt_triage_result_notice' );

/* ── Suggesting a match is not the thing the form must not do ────────────────
 * inc/self-log.php refuses to look a volunteer up, and that refusal is
 * load-bearing: a public handler whose behaviour depends on whether a person
 * exists is an oracle for whether a named person is doing court-ordered
 * service, and no amount of careful response-writing removes it once the code
 * path is there.
 *
 * This is the other side of that line. The submission has already been stored
 * as a claim; the person looking at this screen is signed in and holds
 * edit_posts; and the lookup happens in wp-admin, in response to their request,
 * about a record they can already read. Nothing leaks that they could not
 * already find by typing the name into the volunteer list.
 *
 * What was missing was the second half of the design, not a boundary. The plan
 * said staff would attach these "with a suggested match" and only the deferral
 * got built — so triage meant reading a name, retyping it into a picker, and
 * discovering only then whether the person existed.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The volunteer a submission is probably about.
 *
 * Email first and exactly, because an address is the one thing on the form that
 * is either the same string or a different person. Name only as an exact,
 * case-insensitive fallback: "J. Smith" matching "John Smith" is the kind of
 * cleverness that eventually attaches somebody's hours to the wrong record, and
 * on this document that is worse than making staff type a name.
 *
 * @param int $entry_id Entry post ID.
 * @return array{volunteer_id:int, matched_on:string}
 */
function gwc_vt_suggest_volunteer( int $entry_id ): array {
	if ( (int) get_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, true ) > 0 ) {
		return array(
			'volunteer_id' => 0,
			'matched_on'   => '',
		);
	}

	return gwc_vt_suggest_volunteer_for(
		(string) get_post_meta( $entry_id, '_gwc_vt_claim_email', true ),
		(string) get_post_meta( $entry_id, '_gwc_vt_claim_name', true )
	);
}

/**
 * The volunteer a signup is probably about.
 *
 * A signup keeps its claims under different meta keys from an entry's — see the
 * note in inc/signup-cpt.php on why they are deliberately not shared — so this
 * reads those and hands the same two strings to the same matcher. The rules
 * below are the ones that matter, and there is exactly one copy of them.
 *
 * @param int $signup_id Signup post ID.
 * @return array{volunteer_id:int, matched_on:string}
 */
function gwc_vt_suggest_volunteer_for_signup( int $signup_id ): array {
	if ( (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true ) > 0 ) {
		return array(
			'volunteer_id' => 0,
			'matched_on'   => '',
		);
	}

	return gwc_vt_suggest_volunteer_for(
		(string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_EMAIL, true ),
		(string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true )
	);
}

/**
 * The volunteer a claimed name and address probably belong to.
 *
 * @param string $email Claimed address.
 * @param string $name  Claimed name.
 * @return array{volunteer_id:int, matched_on:string}
 */
function gwc_vt_suggest_volunteer_for( string $email, string $name ): array {
	$none = array(
		'volunteer_id' => 0,
		'matched_on'   => '',
	);

	if ( '' !== $email ) {
		$by_email = gwc_vt_volunteers_by_email( $email );

		/* Exactly one, or none. Two volunteer records sharing an address is a
		 * duplicate somebody has to resolve, and picking one of them for them
		 * would bury the problem inside a record that then looks correct. */
		if ( 1 === count( $by_email ) ) {
			return array(
				'volunteer_id' => (int) $by_email[0],
				'matched_on'   => 'email',
			);
		}
	}

	$name = trim( $name );

	if ( '' === $name ) {
		return $none;
	}

	$by_name = get_posts(
		array(
			'post_type'              => GWC_VT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 2,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'title'                  => $name,
		)
	);

	if ( 1 === count( (array) $by_name ) ) {
		return array(
			'volunteer_id' => (int) $by_name[0],
			'matched_on'   => 'name',
		);
	}

	return $none;
}

/**
 * How many submissions are waiting to be attached to somebody.
 *
 * @return int
 */
function gwc_vt_unmatched_count(): int {
	$query = new WP_Query(
		array(
			'post_type'              => GWC_VT_ENTRY_TYPE,
			'post_status'            => array( 'publish', 'pending' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key lookup, on an admin screen.
			'meta_query'             => array(
				array(
					'key'     => GWC_VT_ENTRY_VOLUNTEER,
					'value'   => '0',
					'compare' => '=',
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * Say plainly that people are waiting, on the screen where the work happens.
 *
 * "Pending (2)" already existed in core's status links and reads as WordPress
 * plumbing — it says a post status, not that two people sent in hours nobody
 * has looked at. And with the list in date order those two rows sit scattered
 * among everything else rather than at the top.
 */
function gwc_vt_triage_queue_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'edit-' . GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; avoids nagging on the screen that is already the queue.
	if ( isset( $_GET['gwc_vt_state'] ) && 'unmatched' === sanitize_key( wp_unslash( $_GET['gwc_vt_state'] ) ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$waiting = gwc_vt_unmatched_count();

	if ( $waiting < 1 ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of submissions. */
				_n(
					'%d person has sent in hours through the public form and has not been matched to a volunteer record yet.',
					'%d people have sent in hours through the public form and have not been matched to a volunteer record yet.',
					$waiting,
					'groundwork-common-volunteer-tracker'
				),
				$waiting
			)
		),
		esc_url(
			add_query_arg(
				array(
					'post_type'    => GWC_VT_ENTRY_TYPE,
					'gwc_vt_state' => 'unmatched',
				),
				admin_url( 'edit.php' )
			)
		),
		esc_html__( 'Match them now', 'groundwork-common-volunteer-tracker' )
	);
}

/* ── Acting on a suggestion ──────────────────────────────────────────────── */

/**
 * A nonced URL for one triage action.
 *
 * @param string $action    admin_post action.
 * @param int    $entry_id  Entry post ID.
 * @param int    $volunteer Volunteer to attach to, if any.
 * @return string
 */
function gwc_vt_triage_url( string $action, int $entry_id, int $volunteer = 0 ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => $action,
				'entry'     => $entry_id,
				'volunteer' => $volunteer,
			),
			admin_url( 'admin-post.php' )
		),
		$action . '_' . $entry_id
	);
}

/**
 * Which entry, and may you.
 *
 * @param string $action admin_post action and nonce prefix.
 * @return int
 */
function gwc_vt_triage_request_entry( string $action ): int {
	$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;

	if ( $entry_id < 1 || GWC_VT_ENTRY_TYPE !== get_post_type( $entry_id ) ) {
		wp_die(
			esc_html__( 'That hour entry does not exist.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	if ( ! current_user_can( 'edit_post', $entry_id ) ) {
		wp_die(
			esc_html__( 'You do not have permission to edit this entry.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( $action . '_' . $entry_id );

	return $entry_id;
}

/**
 * Attach a submission to an existing volunteer.
 */
function gwc_vt_handle_attach_entry(): void {
	$entry_id = gwc_vt_triage_request_entry( 'gwc_vt_attach_entry' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in gwc_vt_triage_request_entry().
	$volunteer_id = isset( $_GET['volunteer'] ) ? absint( wp_unslash( $_GET['volunteer'] ) ) : 0;

	if ( $volunteer_id < 1 || GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		gwc_vt_triage_redirect( $entry_id, 'no-volunteer' );
	}

	gwc_vt_attach_entry_to_volunteer( $entry_id, $volunteer_id );

	gwc_vt_triage_redirect( $entry_id, 'attached' );
}

/**
 * Create a volunteer from what somebody typed, and attach the shift to it.
 *
 * The claimed name becomes a real record here, which is the one place in this
 * plugin where text from an anonymous visitor becomes an identity — so it
 * happens only when a signed-in staff member asks for it, having read the
 * name on screen first.
 */
function gwc_vt_handle_create_volunteer_from_entry(): void {
	$entry_id = gwc_vt_triage_request_entry( 'gwc_vt_create_volunteer_from_entry' );

	if ( ! current_user_can( 'publish_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to create a volunteer record.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$name  = trim( (string) get_post_meta( $entry_id, '_gwc_vt_claim_name', true ) );
	$email = (string) get_post_meta( $entry_id, '_gwc_vt_claim_email', true );

	if ( '' === $name ) {
		gwc_vt_triage_redirect( $entry_id, 'no-name' );
	}

	$volunteer_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_VOLUNTEER_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		)
	);

	if ( is_wp_error( $volunteer_id ) || ! $volunteer_id ) {
		gwc_vt_triage_redirect( $entry_id, 'failed' );
	}

	if ( '' !== $email && is_email( $email ) ) {
		update_post_meta( (int) $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, $email );
	}

	gwc_vt_attach_entry_to_volunteer( $entry_id, (int) $volunteer_id );

	gwc_vt_triage_redirect( $entry_id, 'created' );
}

/**
 * Attach an entry to a volunteer and clear the claim.
 *
 * Deliberately does NOT verify it. Matching answers "whose hours are these";
 * verifying answers "did this happen", and they are different questions asked
 * of different evidence. Collapsing them would mean a staff member attesting to
 * a shift as a side effect of tidying up a queue.
 *
 * @param int $entry_id     Entry post ID.
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_attach_entry_to_volunteer( int $entry_id, int $volunteer_id ): void {
	update_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );

	delete_post_meta( $entry_id, '_gwc_vt_claim_name' );
	delete_post_meta( $entry_id, '_gwc_vt_claim_email' );

	gwc_vt_retitle_entry( $entry_id );
	gwc_vt_refresh_totals( $volunteer_id );

	/**
	 * Fires after a self-logged entry has been attached to a volunteer.
	 *
	 * @param int $entry_id     Entry post ID.
	 * @param int $volunteer_id Volunteer post ID.
	 */
	do_action( 'gwc_vt_entry_attached', $entry_id, $volunteer_id );
}

/**
 * Back where they were, with a word about what happened.
 *
 * @param int    $entry_id Entry post ID.
 * @param string $result   What happened.
 */
function gwc_vt_triage_redirect( int $entry_id, string $result ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; only decides where to land.
	$from_list = isset( $_GET['returnto'] ) && 'list' === sanitize_key( wp_unslash( $_GET['returnto'] ) );

	$target = $from_list
		? add_query_arg(
			array(
				'post_type'    => GWC_VT_ENTRY_TYPE,
				'gwc_vt_state' => 'unmatched',
			),
			admin_url( 'edit.php' )
		)
		: (string) get_edit_post_link( $entry_id, 'url' );

	wp_safe_redirect( add_query_arg( 'gwc_vt_triage', $result, $target ) );
	exit;
}

/**
 * Say what the last triage action did.
 */
function gwc_vt_triage_result_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwc_vt_triage'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_triage'] ) ) : '';

	$messages = array(
		'attached'     => array( 'success', __( 'These hours are now on that volunteer’s record. They still need verifying.', 'groundwork-common-volunteer-tracker' ) ),
		'created'      => array( 'success', __( 'A volunteer record was created from the submission and these hours attached to it. They still need verifying.', 'groundwork-common-volunteer-tracker' ) ),
		'no-volunteer' => array( 'error', __( 'That volunteer no longer exists.', 'groundwork-common-volunteer-tracker' ) ),
		'no-name'      => array( 'error', __( 'This submission has no name on it, so there is nothing to create a record from. Choose a volunteer instead.', 'groundwork-common-volunteer-tracker' ) ),
		'failed'       => array( 'error', __( 'The volunteer record could not be created.', 'groundwork-common-volunteer-tracker' ) ),
	);

	if ( ! isset( $messages[ $result ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $result ][0] ),
		esc_html( $messages[ $result ][1] )
	);
}

/**
 * The one or two buttons that finish triage.
 *
 * @param int $entry_id Entry post ID.
 */
function gwc_vt_render_triage_actions( int $entry_id ): void {
	if ( (int) get_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, true ) > 0 ) {
		return;
	}

	$suggestion = gwc_vt_suggest_volunteer( $entry_id );
	?>
	<p class="gwcvt-triage-actions">
		<?php if ( $suggestion['volunteer_id'] > 0 ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( gwc_vt_triage_url( 'gwc_vt_attach_entry', $entry_id, $suggestion['volunteer_id'] ) ); ?>">
				<?php
				printf(
					/* translators: %s: a volunteer's name. */
					esc_html__( 'Attach to %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( get_the_title( $suggestion['volunteer_id'] ) )
				);
				?>
			</a>
			<span class="description">
				<?php
				echo esc_html(
					'email' === $suggestion['matched_on']
						? __( 'Their email address matches this volunteer exactly.', 'groundwork-common-volunteer-tracker' )
						: __( 'Their name matches this volunteer exactly. Check it is the same person.', 'groundwork-common-volunteer-tracker' )
				);
				?>
			</span>
		<?php else : ?>
			<a class="button button-primary" href="<?php echo esc_url( gwc_vt_triage_url( 'gwc_vt_create_volunteer_from_entry', $entry_id ) ); ?>">
				<?php esc_html_e( 'Create a volunteer from this', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<span class="description">
				<?php esc_html_e( 'Nobody on file matches this name or email. Or choose an existing volunteer below.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		<?php endif; ?>
	</p>
	<?php
}

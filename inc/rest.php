<?php
/**
 * The one REST route.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_REST_NAMESPACE = 'gwcvt/v1';

add_action( 'rest_api_init', 'gwcvt_register_rest_routes' );

/* ── Why there is a route here at all ────────────────────────────────────────
 * The sibling plugins in this family register none, and their stated reason is
 * that a dynamic field schema has no fixed REST shape to expose. That is an
 * argument about the AUTO-GENERATED post type routes, and it is right: every
 * post type in this plugin is show_in_rest => false, which is the single most
 * consequential privacy line here. Turning it on would publish volunteer names
 * and court referral data at /wp/v2/ to anybody the site lets read.
 * tests/integration/rest.php asserts those 404.
 *
 * It is not an argument against a purpose-built route. For the one lookup this
 * plugin needs, REST has strictly better guard rails than admin-ajax:
 * permission_callback is mandatory and WordPress complains when it is missing,
 * and 'args' gives declarative validation and sanitising. The admin-ajax
 * equivalent is a hand-written check_ajax_referer plus a hand-written
 * current_user_can, and the failure mode of forgetting either is an endpoint
 * that answers anybody.
 *
 * So: one route, read-only, and deliberately the narrowest thing that makes the
 * volunteer picker work.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the volunteer lookup.
 */
function gwcvt_register_rest_routes(): void {
	register_rest_route(
		GWCVT_REST_NAMESPACE,
		'/volunteers',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gwcvt_rest_find_volunteers',
			'permission_callback' => 'gwcvt_rest_can_search_volunteers',
			'args'                => array(
				'search' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'gwcvt_rest_validate_search',
					'description'       => __( 'Part of a volunteer’s name.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		)
	);
}

/**
 * Who may look a volunteer up.
 *
 * edit_posts, which is what it takes to create an hour entry in the first
 * place. Anybody who can log a shift has to be able to say whose it was, and
 * anybody who cannot has no reason to be enumerating the volunteer list.
 *
 * @return bool
 */
function gwcvt_rest_can_search_volunteers(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Refuse a search term too short to be one.
 *
 * Two characters. A one-character term matches most of the list, which is not a
 * search — it is an export of every volunteer's name, one keystroke deep.
 *
 * @param mixed $value The submitted term.
 * @return true|WP_Error
 */
function gwcvt_rest_validate_search( $value ) {
	if ( mb_strlen( trim( (string) $value ) ) < 2 ) {
		return new WP_Error(
			'gwcvt_search_too_short',
			__( 'Type at least two characters.', 'groundwork-common-volunteer-tracker' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Names and IDs, and nothing else.
 *
 * The response shape is deliberately two keys wide, and RestTest asserts the
 * exact key set rather than merely that id and label are present. This endpoint
 * sits one careless line away from returning the email address, the phone
 * number, or a case number — the volunteer post carries all three — and an
 * assertion that only fails when something is MISSING would not catch that.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function gwcvt_rest_find_volunteers( $request ) {
	$search = trim( (string) $request->get_param( 'search' ) );

	$ids = get_posts(
		array(
			'post_type'              => GWCVT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 20,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			's'                      => $search,
		)
	);

	$results = array();

	foreach ( (array) $ids as $id ) {
		$results[] = array(
			'id'    => (int) $id,
			'label' => (string) get_the_title( (int) $id ),
		);
	}

	return rest_ensure_response( $results );
}

<?php
/**
 * The three REST routes.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_REST_NAMESPACE = 'gwc-vt/v1';

add_action( 'rest_api_init', 'gwc_vt_register_rest_routes' );

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
 * and 'args' gives declarative validation and sanitizing. The admin-ajax
 * equivalent is a hand-written check_ajax_referer plus a hand-written
 * current_user_can, and the failure mode of forgetting either is an endpoint
 * that answers anybody.
 *
 * So: routes that are read-only, and deliberately the narrowest thing that makes
 * one screen work.
 *
 * ── The second one, and why it is not JavaScript ─────────────────────────────
 * /recurrence-preview answers "how many shifts would this make". It touches no
 * record at all — it is gwc_vt_recurrence_dates() over three form values, the
 * same call the save handler makes.
 *
 * That is the entire reason it exists. The obvious implementation of a live
 * preview is to count the dates in the browser, and it is wrong: the rule has a
 * count cap, a twelve-month horizon, a monthly pattern that skips a month with
 * no fifth Saturday, and a deliberate one-shift answer when the end date is
 * missing. A second implementation of that in JavaScript would agree on the day
 * it was written and drift afterwards, and the failure mode is a screen that
 * promises twenty-six shifts and a save that makes twenty.
 *
 * So the browser asks the same function the save will use, and gets back
 * finished sentences rather than numbers to assemble.
 *
 * ── The third one, and why it returns markup ─────────────────────────────────
 * /shift-panel is one shift rendered for the schedule's drawer. It answers with
 * HTML rather than with the shift's fields, for the same reason
 * /recurrence-preview answers with sentences: every string in that panel is
 * translated, several are sentences with a number in them, and assembling those
 * in the browser puts word order in JavaScript.
 *
 * The panel also carries forms, and those carry nonces. A nonce is minted for
 * one user and one action; rendering the form where the nonce is made is the
 * only version of this that does not involve shipping a token to the browser
 * and trusting it to be used for what it was issued for.
 *
 * It discloses a roster, which is names — so unlike the other two, this gate is
 * doing real work. edit_posts is what it takes to open the schedule screen the
 * drawer lives on and to read the same roster there.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register the routes.
 */
function gwc_vt_register_rest_routes(): void {
	register_rest_route(
		GWC_VT_REST_NAMESPACE,
		'/shift-panel',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gwc_vt_rest_shift_panel',
			'permission_callback' => 'gwc_vt_rest_can_open_shift_panel',
			'args'                => array(
				'shift' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'description'       => __( 'The shift to describe.', 'groundwork-common-volunteer-tracker' ),
				),
				'back'  => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
					'description'       => __( 'Which view a roster add should return to.', 'groundwork-common-volunteer-tracker' ),
				),
				'month' => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'The month the calendar was showing, as Y-m.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		)
	);

	register_rest_route(
		GWC_VT_REST_NAMESPACE,
		'/recurrence-preview',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gwc_vt_rest_recurrence_preview',
			'permission_callback' => 'gwc_vt_rest_can_preview_recurrence',
			'args'                => array(
				'start'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'The first occurrence, as Y-m-d.', 'groundwork-common-volunteer-tracker' ),
				),
				'pattern' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'description'       => __( 'A repeat pattern key.', 'groundwork-common-volunteer-tracker' ),
				),
				'until'   => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'The last date to consider, as Y-m-d.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		)
	);

	register_rest_route(
		GWC_VT_REST_NAMESPACE,
		'/volunteers',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gwc_vt_rest_find_volunteers',
			'permission_callback' => 'gwc_vt_rest_can_search_volunteers',
			'args'                => array(
				'search' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'gwc_vt_rest_validate_search',
					'description'       => __( 'Part of a volunteer’s name.', 'groundwork-common-volunteer-tracker' ),
				),
			),
		)
	);
}

/**
 * Who may open the drawer.
 *
 * Gated on edit_posts, which is what it takes to open the schedule screen the
 * drawer lives on. Unlike the other two routes this one discloses something —
 * the roster is names — so the gate is the point rather than a formality.
 *
 * @return bool
 */
function gwc_vt_rest_can_open_shift_panel(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * One shift, rendered.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function gwc_vt_rest_shift_panel( $request ) {
	$shift_id = (int) $request->get_param( 'shift' );

	/* A shift, and not something else with the same ID. Without this, any post
	 * ID would reach a renderer that reads meta keys off it and prints whatever
	 * it finds. */
	if ( $shift_id < 1 || GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		return new WP_Error(
			'gwc_vt_no_shift',
			__( 'That shift does not exist.', 'groundwork-common-volunteer-tracker' ),
			array( 'status' => 404 )
		);
	}

	$back = (string) $request->get_param( 'back' );
	$back = in_array( $back, array( 'month', 'list' ), true ) ? $back : '';

	$month = (string) $request->get_param( 'month' );
	$month = preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : '';

	ob_start();
	gwc_vt_render_shift_panel( $shift_id, $back, $month );

	return rest_ensure_response(
		array(
			'shift' => $shift_id,
			'html'  => (string) ob_get_clean(),
		)
	);
}

/**
 * Who may ask what a repeat would create.
 *
 * The capability that creates shifts, because that is the only thing the answer
 * is for and the save handler behind it refuses anybody else. It is a stricter
 * gate than the volunteer lookup's edit_posts, which is the right way round:
 * this is a question only somebody filling in the add-a-shift form has.
 *
 * The answer discloses nothing either way — no record is read, and the same
 * arithmetic is a calendar and a pocket calculator. The gate is here because an
 * unauthenticated endpoint that runs a loop for you is a thing you have to
 * think about, not because the output is sensitive.
 *
 * @return bool
 */
function gwc_vt_rest_can_preview_recurrence(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * What the run would create, as finished sentences.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function gwc_vt_rest_recurrence_preview( $request ) {
	return rest_ensure_response(
		gwc_vt_recurrence_preview(
			gwc_vt_sanitize_date( (string) $request->get_param( 'start' ) ),
			(string) $request->get_param( 'pattern' ),
			gwc_vt_sanitize_date( (string) $request->get_param( 'until' ) )
		)
	);
}

/**
 * Who may look a volunteer up.
 *
 * Gated on edit_posts, which is what it takes to create an hour entry in the first
 * place. Anybody who can log a shift has to be able to say whose it was, and
 * anybody who cannot has no reason to be enumerating the volunteer list.
 *
 * This is the gate on the ROUTE. It is deliberately not the whole answer: see
 * gwc_vt_rest_find_volunteers(), which decides per record whether this user may
 * see that one. edit_posts is a contributor-level capability, and a contributor
 * who may pick a volunteer from the published list has no business learning that
 * a private or unpublished record exists.
 *
 * @return bool
 */
function gwc_vt_rest_can_search_volunteers(): bool {
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
function gwc_vt_rest_validate_search( $value ) {
	if ( mb_strlen( trim( (string) $value ) ) < 2 ) {
		return new WP_Error(
			'gwc_vt_search_too_short',
			__( 'Type at least two characters.', 'groundwork-common-volunteer-tracker' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Names and IDs, and nothing else.
 *
 * The response shape is deliberately two keys wide, and tests/integration/rest.php
 * asserts the exact key set rather than merely that id and label are present.
 * This endpoint sits one careless line away from returning the email address,
 * the phone number, or a case number — the volunteer post carries all three —
 * and an assertion that only fails when something is MISSING would not catch
 * that. (This named a "RestTest" that had never been written; the integration
 * script now exists and does the work the sentence claimed.)
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function gwc_vt_rest_find_volunteers( $request ) {
	$search = trim( (string) $request->get_param( 'search' ) );

	/* Over-fetch, because the read_post filter below discards some. Five times
	 * the page size rather than a second query: on the site this is wrong for —
	 * one where most volunteer records are private and the caller may read none
	 * of them — the right answer is an empty list, and paging until it fills
	 * would just be a slower way to reach it. */
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 100,
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
		$id = (int) $id;

		/* Asked per record, and asked of core rather than answered here. The
		 * route's own gate is edit_posts; the four statuses above include three
		 * that edit_posts does not entitle anybody to read. read_post is the meta
		 * capability that knows the difference — published records to anybody who
		 * got this far, private ones to read_private_posts, somebody else's draft
		 * to edit_others_posts — and it stays right if a site remaps the volunteer
		 * type's capabilities through the gwc_vt_capabilities filter.
		 *
		 * Reported by the plugin directory review: without this, a contributor
		 * could enumerate the names and IDs of every private, draft and pending
		 * volunteer record on the site, two characters at a time. */
		if ( ! current_user_can( 'read_post', $id ) ) {
			continue;
		}

		$results[] = array(
			'id'    => $id,
			'label' => (string) get_the_title( $id ),
		);

		if ( count( $results ) >= 20 ) {
			break;
		}
	}

	return rest_ensure_response( $results );
}

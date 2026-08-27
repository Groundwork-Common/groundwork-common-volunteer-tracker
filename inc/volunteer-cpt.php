<?php
/**
 * The volunteer record: one person, however many shifts.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_VOLUNTEER_TYPE = 'gwc_vt_volunteer';

const GWC_VT_VOLUNTEER_EMAIL  = '_gwc_vt_email';
const GWC_VT_VOLUNTEER_PHONE  = '_gwc_vt_phone';
const GWC_VT_VOLUNTEER_TOTALS = '_gwc_vt_totals';
const GWC_VT_VOLUNTEER_HOLD   = '_gwc_vt_retention_hold';

/* ── Inactive: somebody who used to volunteer here ────────────────────────────
 * The gap this fills: the only two ways to end a relationship were anonymize
 * and delete, and both are answers to a PRIVACY question. Neither says the
 * ordinary thing, which is that a person stopped coming and their record should
 * stop being offered — while every hour they worked, and their name on the
 * letters that report those hours, stay exactly where they are.
 *
 * ── Why a status of our own rather than 'draft' ──────────────────────────────
 * Seven queries in this plugin already ask for
 * array( 'publish', 'draft', 'pending', 'private' ) — the REST picker, the
 * privacy exporter and eraser, the retention sweep, the triage suggester, the
 * schedule's person search and the dashboard's overdue count. An inactive
 * volunteer parked in 'draft' would keep turning up in every one of them, which
 * is the opposite of retiring. A name of our own is excluded from all seven by
 * default, so the safe behaviour is what happens when somebody forgets — and
 * the places that SHOULD still see an inactive person name this status
 * deliberately.
 *
 * 'draft' is also a core label. Renaming it would rename it on posts and pages
 * as well, for every plugin on the site.
 *
 * Under twenty characters because post_status is a varchar(20), which is the
 * same reason GWC_VT_CREDENTIAL_RETIRED is abbreviated. */
const GWC_VT_VOLUNTEER_INACTIVE = 'gwc_vt_vol_inactive';

/**
 * Every status a volunteer record that still belongs to somebody can be in.
 *
 * The list the seven queries above should have been sharing all along. A screen
 * that is about record-keeping — what happened, who it happened to, and what
 * the law says you must hand over — asks for this. A screen that is about work
 * still to come asks for 'publish' on its own.
 *
 * @return string[]
 */
function gwc_vt_volunteer_statuses(): array {
	return array( 'publish', 'draft', 'pending', 'private', GWC_VT_VOLUNTEER_INACTIVE );
}

/* ── What somebody has to complete, and for whom ─────────────────────────────
 * The one question both sides of a mandated placement ask constantly, and the
 * only one this plugin could not answer: how many are left.
 *
 * It belongs on the volunteer rather than on each shift, for the reason set out
 * below about a case number belonging to the assignment. Minutes, like every
 * other duration here.
 *
 * ── And the reason it never reaches the letter ───────────────────────────────
 * Everything else this plugin records is something the ORGANIZATION observed:
 * Jane worked three and a half hours, Marcus attested to it. This is not. It is
 * a fact about somebody else's document — a court order, a school's
 * requirement — which the organization may have seen only as a photograph and
 * may be reading wrong.
 *
 * Printing "120 ordered, 94 completed, 26 remaining" would be the organization
 * certifying the terms of an order back to the court that issued it. The court
 * knows what it ordered. And if the terms were modified on appeal while the
 * copy in the filing cabinet was not, the letter is now confidently wrong about
 * the one line its reader would actually check.
 *
 * So: an internal planning figure. It appears on this screen, on the volunteer
 * list, and nowhere in inc/letter.php or inc/render.php. LetterTest and
 * tests/integration/required.php both assert that.
 * ─────────────────────────────────────────────────────────────────────────── */
const GWC_VT_VOLUNTEER_REQUIRED    = '_gwc_vt_required_minutes';
const GWC_VT_VOLUNTEER_REQUIRED_BY = '_gwc_vt_required_by';

/* Who requires it. Free text and deliberately not a court-shaped field: schools
 * set service requirements, so do scouting groups, professional licences and
 * some employers' volunteering schemes. A field labeled "court" would be wrong
 * for most of the people it ends up describing, and would quietly assert
 * something about the rest. */
const GWC_VT_VOLUNTEER_REQUIRED_FOR = '_gwc_vt_required_for';

add_action( 'init', 'gwc_vt_register_volunteer_type' );
add_filter( 'manage_' . GWC_VT_VOLUNTEER_TYPE . '_posts_columns', 'gwc_vt_volunteer_columns' );
add_action( 'manage_' . GWC_VT_VOLUNTEER_TYPE . '_posts_custom_column', 'gwc_vt_volunteer_column', 10, 2 );

/**
 * Register the volunteer type.
 *
 * ── Why a separate record at all ─────────────────────────────────────────────
 * The alternative was storing a name on each entry and grouping by it, which is
 * simpler until the first letter. Forty entries typed "Jane Doe", "jane doe"
 * and "J. Doe" do not total, and the total is the whole product.
 *
 * Three things follow from having one record per person:
 *
 *   1. The letter's claim rests on a stable identity rather than on string
 *      matching that is right most of the time.
 *   2. Personal data is written in one place, so the privacy eraser and the
 *      retention sweep have exactly one thing to act on rather than a scan of
 *      every entry ever logged.
 *   3. A case number or referral agency belongs to the person's assignment, not
 *      to each three-hour shift, and would otherwise be copied onto forty rows.
 *
 * Not a taxonomy, which is the WordPress-native temptation. Terms are shared,
 * publicly queryable objects with their own archive URLs, and a volunteer's
 * name is not a taxonomy of the site.
 *
 * Not a WP user either. The volunteer never signs in — that is the entire point
 * of the anonymous self-log form — and creating an account for somebody
 * performing court-ordered service would hand them a login they did not ask for
 * and put their name in a user list that half a dozen plugins enumerate.
 */
function gwc_vt_register_volunteer_type(): void {
	register_post_status(
		GWC_VT_VOLUNTEER_INACTIVE,
		array(
			'label'                     => _x( 'Inactive', 'volunteer status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: how many volunteers are inactive. */
			'label_count'               => _nx_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'volunteer status', 'groundwork-common-volunteer-tracker' ),
		)
	);

	$labels = array(
		'name'               => _x( 'Volunteers', 'post type general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name'      => _x( 'Volunteer', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		'menu_name'          => _x( 'Volunteers', 'admin menu', 'groundwork-common-volunteer-tracker' ),
		'add_new'            => __( 'Add volunteer', 'groundwork-common-volunteer-tracker' ),
		'add_new_item'       => __( 'Add volunteer', 'groundwork-common-volunteer-tracker' ),
		'edit_item'          => __( 'Edit volunteer', 'groundwork-common-volunteer-tracker' ),
		'new_item'           => __( 'New volunteer', 'groundwork-common-volunteer-tracker' ),
		'search_items'       => __( 'Search volunteers', 'groundwork-common-volunteer-tracker' ),
		'not_found'          => __( 'No volunteers yet.', 'groundwork-common-volunteer-tracker' ),
		'not_found_in_trash' => __( 'No volunteers in the trash.', 'groundwork-common-volunteer-tracker' ),
		'all_items'          => __( 'Volunteers', 'groundwork-common-volunteer-tracker' ),
	);

	$args = array(
		'labels'              => $labels,
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => true,
		'show_in_menu'        => 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'exclude_from_search' => true,
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the volunteer post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_VOLUNTEER_TYPE, apply_filters( 'gwc_vt_volunteer_post_type_args', $args ) );
}

/**
 * The columns on the volunteer list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwc_vt_volunteer_columns( $columns ): array {
	$columns = (array) $columns;
	unset( $columns['date'] );

	return array_merge(
		$columns,
		array(
			'gwc_vt_verified' => __( 'Verified hours', 'groundwork-common-volunteer-tracker' ),
			'gwc_vt_pending'  => __( 'Awaiting verification', 'groundwork-common-volunteer-tracker' ),
			'gwc_vt_required' => __( 'Required', 'groundwork-common-volunteer-tracker' ),
			'gwc_vt_last'     => __( 'Last shift', 'groundwork-common-volunteer-tracker' ),
		)
	);
}

/**
 * Render one cell.
 *
 * Read from the cached rollup rather than recomputed. This is the screen the
 * cache exists for: without it, a list of twenty volunteers is twenty queries
 * before the page renders. The letter, whose correctness actually matters,
 * never reads this cache — see gwc_vt_volunteer_totals().
 *
 * @param string $column  Column key.
 * @param int    $post_id Volunteer post ID.
 */
function gwc_vt_volunteer_column( $column, $post_id ): void {
	$post_id = (int) $post_id;

	if ( 0 !== strpos( (string) $column, 'gwc_vt_' ) ) {
		return;
	}

	$totals = GWC_VT_Totals::from_array( get_post_meta( $post_id, GWC_VT_VOLUNTEER_TOTALS, true ) );

	switch ( $column ) {
		case 'gwc_vt_verified':
			echo esc_html( gwc_vt_format_hours( $totals->verified_minutes ) );
			break;

		case 'gwc_vt_pending':
			if ( $totals->pending_minutes > 0 ) {
				printf(
					'<strong>%s</strong>',
					esc_html( gwc_vt_format_hours( $totals->pending_minutes ) )
				);
				break;
			}
			echo '<span aria-hidden="true">—</span>';
			break;

		case 'gwc_vt_required':
			/* Blank for the great majority of volunteers, who are not working
			 * anything off. A column that said "none" on every row would be a
			 * column of noise on the screen a coordinator scans most. */
			if ( ! gwc_vt_has_requirement( $post_id ) ) {
				echo '<span aria-hidden="true">—</span>';
				break;
			}

			$progress = gwc_vt_requirement_progress( $post_id );

			printf(
				'<span class="gwcvt-badge gwcvt-badge--%1$s">%2$s</span>',
				esc_attr( $progress['met'] ? 'verified' : ( $progress['overdue'] ? 'cancelled' : 'waiting' ) ),
				esc_html( gwc_vt_requirement_label( $post_id ) )
			);

			$due = gwc_vt_requirement_deadline_label( $post_id );

			if ( '' !== $due ) {
				printf( '<span class="gwcvt-badge__detail">%s</span>', esc_html( $due ) );
			}
			break;

		case 'gwc_vt_last':
			echo esc_html( '' !== $totals->last ? $totals->last : '—' );
			break;
	}
}

<?php
/**
 * The volunteer record: one person, however many shifts.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_VOLUNTEER_TYPE = 'gwcvt_volunteer';

const GWCVT_VOLUNTEER_EMAIL  = '_gwcvt_email';
const GWCVT_VOLUNTEER_PHONE  = '_gwcvt_phone';
const GWCVT_VOLUNTEER_TOTALS = '_gwcvt_totals';
const GWCVT_VOLUNTEER_HOLD   = '_gwcvt_retention_hold';

/* ── What somebody has to complete, and for whom ─────────────────────────────
 * The one question both sides of a mandated placement ask constantly, and the
 * only one this plugin could not answer: how many are left.
 *
 * It belongs on the volunteer rather than on each shift, for the reason set out
 * below about a case number belonging to the assignment. Minutes, like every
 * other duration here.
 *
 * ── And the reason it never reaches the letter ───────────────────────────────
 * Everything else this plugin records is something the ORGANISATION observed:
 * Jane worked three and a half hours, Marcus attested to it. This is not. It is
 * a fact about somebody else's document — a court order, a school's
 * requirement — which the organisation may have seen only as a photograph and
 * may be reading wrong.
 *
 * Printing "120 ordered, 94 completed, 26 remaining" would be the organisation
 * certifying the terms of an order back to the court that issued it. The court
 * knows what it ordered. And if the terms were modified on appeal while the
 * copy in the filing cabinet was not, the letter is now confidently wrong about
 * the one line its reader would actually check.
 *
 * So: an internal planning figure. It appears on this screen, on the volunteer
 * list, and nowhere in inc/letter.php or inc/render.php. LetterTest and
 * tests/integration/required.php both assert that.
 * ─────────────────────────────────────────────────────────────────────────── */
const GWCVT_VOLUNTEER_REQUIRED    = '_gwcvt_required_minutes';
const GWCVT_VOLUNTEER_REQUIRED_BY = '_gwcvt_required_by';

/* Who requires it. Free text and deliberately not a court-shaped field: schools
 * set service requirements, so do scouting groups, professional licences and
 * some employers' volunteering schemes. A field labelled "court" would be wrong
 * for most of the people it ends up describing, and would quietly assert
 * something about the rest. */
const GWCVT_VOLUNTEER_REQUIRED_FOR = '_gwcvt_required_for';

add_action( 'init', 'gwcvt_register_volunteer_type' );
add_filter( 'manage_' . GWCVT_VOLUNTEER_TYPE . '_posts_columns', 'gwcvt_volunteer_columns' );
add_action( 'manage_' . GWCVT_VOLUNTEER_TYPE . '_posts_custom_column', 'gwcvt_volunteer_column', 10, 2 );

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
function gwcvt_register_volunteer_type(): void {
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
		'show_in_menu'        => 'edit.php?post_type=' . GWCVT_ENTRY_TYPE,
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
	register_post_type( GWCVT_VOLUNTEER_TYPE, apply_filters( 'gwcvt_volunteer_post_type_args', $args ) );
}

/**
 * The columns on the volunteer list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwcvt_volunteer_columns( $columns ): array {
	$columns = (array) $columns;
	unset( $columns['date'] );

	return array_merge(
		$columns,
		array(
			'gwcvt_verified' => __( 'Verified hours', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_pending'  => __( 'Awaiting verification', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_required' => __( 'Required', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_last'     => __( 'Last shift', 'groundwork-common-volunteer-tracker' ),
		)
	);
}

/**
 * Render one cell.
 *
 * Read from the cached rollup rather than recomputed. This is the screen the
 * cache exists for: without it, a list of twenty volunteers is twenty queries
 * before the page renders. The letter, whose correctness actually matters,
 * never reads this cache — see gwcvt_volunteer_totals().
 *
 * @param string $column  Column key.
 * @param int    $post_id Volunteer post ID.
 */
function gwcvt_volunteer_column( $column, $post_id ): void {
	$post_id = (int) $post_id;

	if ( 0 !== strpos( (string) $column, 'gwcvt_' ) ) {
		return;
	}

	$totals = GWCVT_Totals::from_array( get_post_meta( $post_id, GWCVT_VOLUNTEER_TOTALS, true ) );

	switch ( $column ) {
		case 'gwcvt_verified':
			echo esc_html( gwcvt_format_hours( $totals->verified_minutes ) );
			break;

		case 'gwcvt_pending':
			if ( $totals->pending_minutes > 0 ) {
				printf(
					'<strong>%s</strong>',
					esc_html( gwcvt_format_hours( $totals->pending_minutes ) )
				);
				break;
			}
			echo '<span aria-hidden="true">—</span>';
			break;

		case 'gwcvt_required':
			/* Blank for the great majority of volunteers, who are not working
			 * anything off. A column that said "none" on every row would be a
			 * column of noise on the screen a coordinator scans most. */
			if ( ! gwcvt_has_requirement( $post_id ) ) {
				echo '<span aria-hidden="true">—</span>';
				break;
			}

			$progress = gwcvt_requirement_progress( $post_id );

			printf(
				'<span class="gwcvt-badge gwcvt-badge--%1$s">%2$s</span>',
				esc_attr( $progress['met'] ? 'verified' : ( $progress['overdue'] ? 'cancelled' : 'waiting' ) ),
				esc_html( gwcvt_requirement_label( $post_id ) )
			);

			$due = gwcvt_requirement_deadline_label( $post_id );

			if ( '' !== $due ) {
				printf( '<span class="gwcvt-badge__detail">%s</span>', esc_html( $due ) );
			}
			break;

		case 'gwcvt_last':
			echo esc_html( '' !== $totals->last ? $totals->last : '—' );
			break;
	}
}

<?php
/**
 * The hour entry post type, and how it appears in the admin list.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_ENTRY_TYPE = 'gwcvt_entry';

/* Core entry meta. Named as constants because the query layer, the meta box,
 * the letter and the privacy eraser all read them, and a typo in a meta key is
 * silent — it reads as an empty value rather than as an error. */
const GWCVT_ENTRY_VOLUNTEER  = '_gwcvt_volunteer';
const GWCVT_ENTRY_DATE       = '_gwcvt_date';
const GWCVT_ENTRY_MINUTES    = '_gwcvt_minutes';
const GWCVT_ENTRY_ACTIVITY   = '_gwcvt_activity';
const GWCVT_ENTRY_SUPERVISOR = '_gwcvt_supervisor';
const GWCVT_ENTRY_SOURCE     = '_gwcvt_source';

/* The scheduled shift these hours were logged from, when they came from one.
 * Absent on every entry typed by hand, which is most of them.
 *
 * Metadata, and deliberately nothing the letter reads. What a letter prints is
 * the date, the duration, the activity, the supervisor and the attestation —
 * the facts the organisation observed. That a plan existed beforehand is not one
 * of them, and adding it to the printed document would invalidate every
 * reference code ever issued for the sake of a line no reader needs. */
const GWCVT_ENTRY_SHIFT = '_gwcvt_entry_shift';

/* The attestation. Absent means unverified — there is no 'false' to store and
 * no third state, which is what stops "verified" and "not verified yet" from
 * drifting apart from "the record exists".
 *
 * These live here with the rest of the entry's meta rather than in verify.php,
 * because the query layer has to read them to total anything and a constant
 * that moves house when its behaviour does is a constant nobody can find.
 * inc/verify.php owns the writing.
 *
 * GWCVT_ENTRY_VERIFIED_METHOD holds 'staff' and, in this version, nothing else.
 * It exists from the first release precisely so it never has to be backfilled:
 * gwcvt_entry_method() reads an absent value as 'staff', so every entry logged
 * before supervisor confirmation exists will still read correctly after it
 * ships. Read-time defaults, never a migration. */
const GWCVT_ENTRY_VERIFIED_AT     = '_gwcvt_verified_at';
const GWCVT_ENTRY_VERIFIED_BY     = '_gwcvt_verified_by';
const GWCVT_ENTRY_VERIFIED_METHOD = '_gwcvt_verified_method';

add_action( 'init', 'gwcvt_register_post_type' );
add_filter( 'manage_' . GWCVT_ENTRY_TYPE . '_posts_columns', 'gwcvt_entry_columns' );
add_action( 'manage_' . GWCVT_ENTRY_TYPE . '_posts_custom_column', 'gwcvt_entry_column', 10, 2 );
add_filter( 'manage_edit-' . GWCVT_ENTRY_TYPE . '_sortable_columns', 'gwcvt_entry_sortable_columns' );
add_filter( 'post_row_actions', 'gwcvt_entry_volunteer_row_action', 9, 2 );

/**
 * Register the hour entry type.
 *
 * ── Why none of this is public ───────────────────────────────────────────────
 * An hour entry records that a named person worked a shift, and in the
 * court-ordered case the surrounding context says why they were there. So:
 * not public, not in search, no archive, no permalink, no REST.
 *
 * show_in_rest is false rather than absent, and it is the single most
 * consequential line in the file. Turning it on would publish volunteer names
 * and every custom field an organisation has added — case numbers, referral
 * agencies — at /wp-json/wp/v2/gwcvt_entry to anybody the site lets read. There
 * IS one REST route in this plugin, in inc/rest.php, and it returns display
 * names to staff who already hold edit_posts and nothing else. The difference
 * between a purpose-built route and the auto-generated one is the whole point.
 * tests/integration/rest.php asserts the auto-generated one 404s.
 *
 * capability_type 'post' with map_meta_cap, so edit_posts and edit_post do the
 * work and a site's existing editorial roles already mean something sensible.
 * The two genuinely new decisions — attesting and issuing — are the only ones
 * that got custom capabilities. See inc/access.php.
 */
function gwcvt_register_post_type(): void {
	$labels = array(
		'name'               => _x( 'Volunteer Hours', 'post type general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name'      => _x( 'Hour Entry', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
		'menu_name'          => _x( 'Volunteer Hours', 'admin menu', 'groundwork-common-volunteer-tracker' ),
		'add_new'            => __( 'Log hours', 'groundwork-common-volunteer-tracker' ),
		'add_new_item'       => __( 'Log hours', 'groundwork-common-volunteer-tracker' ),
		'edit_item'          => __( 'Edit entry', 'groundwork-common-volunteer-tracker' ),
		'new_item'           => __( 'New entry', 'groundwork-common-volunteer-tracker' ),
		'view_item'          => __( 'View entry', 'groundwork-common-volunteer-tracker' ),
		'search_items'       => __( 'Search entries', 'groundwork-common-volunteer-tracker' ),
		'not_found'          => __( 'No hours logged yet.', 'groundwork-common-volunteer-tracker' ),
		'not_found_in_trash' => __( 'No entries in the trash.', 'groundwork-common-volunteer-tracker' ),
		'all_items'          => __( 'All hours', 'groundwork-common-volunteer-tracker' ),
	);

	$args = array(
		'labels'              => $labels,
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'menu_position'       => 26,
		'menu_icon'           => 'dashicons-clipboard',
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'exclude_from_search' => true,

		/* Supports nothing — not even 'title', which every other post type in
		 * this family declares. An entry's title is derived on save from the
		 * volunteer, the date and the duration (gwcvt_entry_title), so a title
		 * field on the editor would be a box whose contents are overwritten the
		 * moment somebody presses Update.
		 *
		 * Declaring no support is the way to take it off the screen.
		 * remove_meta_box( 'titlediv', … ) looks like the answer and is not: the
		 * classic editor renders the title directly rather than through the meta
		 * box system, so the call succeeds, returns nothing, and leaves the
		 * field exactly where it was.
		 *
		 * Dropping the declaration costs nothing elsewhere — post_title is a
		 * column on wp_posts, not a feature, so wp_update_post() still writes it
		 * and admin search still finds it.
		 *
		 * `false`, and emphatically not `array()`. register_post_type() reads an
		 * EMPTY array as "nothing was specified" and falls through to its default
		 * of title and editor, so array() adds back the very field it looks like
		 * it removes — plus the content editor for good measure. Only the literal
		 * false means none. */
		'supports'            => false,
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the hour entry post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWCVT_ENTRY_TYPE, apply_filters( 'gwcvt_post_type_args', $args ) );
}

/* ── The title ───────────────────────────────────────────────────────────────
 * Derived on save rather than typed. This departs from the location finder,
 * where the title is the meaningful name a user gives a place — but nobody
 * names a shift, and asking them to would produce forty entries called
 * "Saturday". A derived title makes the admin list scannable and admin search
 * work, both of which come free from post_title and cost a query each to
 * reproduce any other way.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Build an entry's title from what it records.
 *
 * @param int $entry_id Entry post ID.
 * @return string
 */
function gwcvt_entry_title( int $entry_id ): string {
	$volunteer_id = (int) get_post_meta( $entry_id, GWCVT_ENTRY_VOLUNTEER, true );
	$date         = (string) get_post_meta( $entry_id, GWCVT_ENTRY_DATE, true );
	$minutes      = (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true );

	$name = $volunteer_id > 0 ? get_the_title( $volunteer_id ) : '';

	if ( '' === $name ) {
		/* A self-logged entry nobody has attached to a volunteer yet. The name
		 * the submitter typed is a claim rather than an identity, and the list
		 * has to say so — otherwise triage cannot tell the two apart. */
		$claimed = (string) get_post_meta( $entry_id, '_gwcvt_claim_name', true );
		$name    = '' !== $claimed
			/* translators: %s: the name somebody typed into the public form. */
			? sprintf( __( '%s (unmatched)', 'groundwork-common-volunteer-tracker' ), $claimed )
			: __( 'Unassigned', 'groundwork-common-volunteer-tracker' );
	}

	return sprintf(
		/* translators: 1: volunteer name, 2: date, 3: duration. */
		__( '%1$s — %2$s — %3$s', 'groundwork-common-volunteer-tracker' ),
		$name,
		'' !== $date ? $date : __( 'no date', 'groundwork-common-volunteer-tracker' ),
		gwcvt_format_hours( $minutes )
	);
}

/* ── The list table ──────────────────────────────────────────────────────────
 * Columns via the manage_*_columns filters rather than a WP_List_Table
 * subclass, as everywhere else in this family. A subclass would mean
 * reimplementing search, paging, bulk actions and the trash view in order to
 * change four columns.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The columns on the hours list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwcvt_entry_columns( $columns ): array {
	$columns = (array) $columns;

	/* The title is rebuilt from the same three facts the dedicated columns
	 * show, so leaving it would print everything twice. Dropped, and the
	 * checkbox kept. */
	unset( $columns['title'], $columns['date'] );

	return array_merge(
		array_slice( $columns, 0, 1, true ),
		array(
			'gwcvt_volunteer'  => __( 'Volunteer', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_date'       => __( 'Date', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_hours'      => __( 'Hours', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_activity'   => __( 'Activity', 'groundwork-common-volunteer-tracker' ),
			'gwcvt_supervisor' => __( 'Supervisor', 'groundwork-common-volunteer-tracker' ),
		),
		array_slice( $columns, 1, null, true )
	);
}

/**
 * Render one cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Entry post ID.
 */
function gwcvt_entry_column( $column, $post_id ): void {
	$post_id = (int) $post_id;

	switch ( $column ) {
		case 'gwcvt_volunteer':
			/* ── This links to the SHIFT, not to the volunteer ────────────────
			 * Which looks wrong in a column headed "Volunteer", and is not.
			 *
			 * This is the list's primary column, so WordPress renders the row
			 * actions — Edit, Trash, Verify — directly underneath it, and every
			 * one of them acts on the shift. Linking the name to the volunteer
			 * put a link to one record immediately above a row of actions
			 * operating on a different one: click the name and you edit the
			 * person, click Edit two pixels below and you edit the shift.
			 *
			 * So the primary link goes where the row's own actions go, and
			 * reaching the volunteer is its own row action — see
			 * gwcvt_entry_volunteer_row_action().
			 * ─────────────────────────────────────────────────────────────── */
			$volunteer_id = (int) get_post_meta( $post_id, GWCVT_ENTRY_VOLUNTEER, true );
			$edit_entry   = (string) get_edit_post_link( $post_id );

			if ( $volunteer_id > 0 ) {
				printf(
					'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
					esc_url( $edit_entry ),
					esc_html( get_the_title( $volunteer_id ) )
				);
				break;
			}

			$claimed = (string) get_post_meta( $post_id, '_gwcvt_claim_name', true );
			printf(
				'<strong><a class="row-title" href="%1$s"><span class="gwcvt-unmatched">%2$s</span></a></strong>',
				esc_url( $edit_entry ),
				esc_html(
					'' !== $claimed
						/* translators: %s: the name somebody typed into the public form. */
						? sprintf( __( '%s — not yet matched', 'groundwork-common-volunteer-tracker' ), $claimed )
						: __( 'Not assigned', 'groundwork-common-volunteer-tracker' )
				)
			);
			break;

		case 'gwcvt_date':
			echo esc_html( (string) get_post_meta( $post_id, GWCVT_ENTRY_DATE, true ) );
			break;

		case 'gwcvt_hours':
			echo esc_html( gwcvt_format_hours( (int) get_post_meta( $post_id, GWCVT_ENTRY_MINUTES, true ) ) );
			break;

		case 'gwcvt_activity':
			echo esc_html( (string) get_post_meta( $post_id, GWCVT_ENTRY_ACTIVITY, true ) );
			break;

		case 'gwcvt_supervisor':
			echo esc_html( (string) get_post_meta( $post_id, GWCVT_ENTRY_SUPERVISOR, true ) );
			break;
	}
}

/**
 * Which columns can be sorted on.
 *
 * Date only. Sorting by volunteer would mean ordering by a post ID stored as a
 * string, which sorts by when the volunteer record was created rather than by
 * name — a sort that looks like it works and does not.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function gwcvt_entry_sortable_columns( $columns ): array {
	$columns              = (array) $columns;
	$columns['gwcvt_date'] = 'gwcvt_date';

	return $columns;
}

/**
 * A way back to the person, now that the name links to the shift.
 *
 * Inserted immediately after core's Edit rather than appended, and the
 * distinction is not cosmetic: appending puts it below Trash, which reads as
 * one more destructive-adjacent action rather than as the second of two "go
 * and look at something" links. Filter priority alone cannot do this — priority
 * orders the filters, not the entries within the array — so the array is
 * rebuilt around the key.
 *
 * @param array   $actions Existing row actions.
 * @param WP_Post $post    The row's post.
 * @return array
 */
function gwcvt_entry_volunteer_row_action( $actions, $post ): array {
	$actions = (array) $actions;

	if ( GWCVT_ENTRY_TYPE !== $post->post_type ) {
		return $actions;
	}

	$volunteer_id = (int) get_post_meta( (int) $post->ID, GWCVT_ENTRY_VOLUNTEER, true );

	if ( $volunteer_id < 1 || ! current_user_can( 'edit_post', $volunteer_id ) ) {
		return $actions;
	}

	$link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( (string) get_edit_post_link( $volunteer_id ) ),
		esc_html__( 'Volunteer record', 'groundwork-common-volunteer-tracker' )
	);

	if ( ! isset( $actions['edit'] ) ) {
		$actions['gwcvt_volunteer_record'] = $link;
		return $actions;
	}

	$rebuilt = array();

	foreach ( $actions as $key => $markup ) {
		$rebuilt[ $key ] = $markup;

		if ( 'edit' === $key ) {
			$rebuilt['gwcvt_volunteer_record'] = $link;
		}
	}

	return $rebuilt;
}

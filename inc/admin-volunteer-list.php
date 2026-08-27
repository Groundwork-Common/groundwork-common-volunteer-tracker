<?php
/**
 * One list, three views: Active, Inactive, Applied.
 *
 * ── Why this is a view and not a second screen ───────────────────────────────
 * Somebody who applies, is approved, volunteers for two years and then stops is
 * one person the whole way through. The menu used to present the first step of
 * that as an unrelated screen with its own layout, and the volunteer list as
 * another. Switching between them was a navigation, not a filter.
 *
 * So Applied is a view on the volunteer list, on the same screen, with the same
 * heading and the same strip of views above it. What changes when you pick it
 * is what the table holds and — deliberately — which columns it has, because
 * "Verified hours" and "Last shift" of somebody who has not been accepted yet
 * are four empty cells pretending to be information.
 *
 * ── What did NOT change, and must not ────────────────────────────────────────
 * The records are still two post types. Making "applied" a volunteer status was
 * measured rather than assumed: seven queries in this plugin already ask for
 * 'pending', so a stranger's typed name would land in the REST picker, the
 * roster picker, the triage picker, the dashboard counts and the privacy
 * exporter with no code change at all. See the block comment in
 * inc/application-cpt.php.
 *
 * Nothing here widens a query. gwc_vt_applied_view() switches the main query on
 * ONE admin screen to the application type when a view says so, and every
 * volunteer query everywhere else is untouched. The interface tells the truth
 * about the lifecycle; the tables do not have to.
 *
 * And the default view is still people. A claim somebody typed into a public
 * form does not appear beside records staff approved unless you ask for it.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The query var that picks a view. */
const GWC_VT_LIST_VIEW = 'gwc_vt_view';

/** Its value for the applications view. */
const GWC_VT_VIEW_APPLIED = 'applied';

/** And for volunteers who are still active. */
const GWC_VT_VIEW_ACTIVE = 'active';

add_filter( 'views_edit-' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_volunteer_views' );
add_action( 'pre_get_posts', 'gwc_vt_volunteer_list_query' );
add_filter( 'manage_' . GWC_VT_VOLUNTEER_TYPE . '_posts_columns', 'gwc_vt_volunteer_list_columns', 20 );
add_action( 'manage_' . GWC_VT_APPLICATION_TYPE . '_posts_custom_column', 'gwc_vt_application_column', 10, 2 );
add_filter( 'post_row_actions', 'gwc_vt_application_row_actions', 10, 2 );

/**
 * Which view the screen is showing.
 *
 * @return string One of the GWC_VT_VIEW_* values, or '' for the default.
 */
function gwc_vt_list_view(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table view; read-only, and core does not nonce its own.
	$view = isset( $_GET[ GWC_VT_LIST_VIEW ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_LIST_VIEW ] ) ) : '';

	return in_array( $view, array( GWC_VT_VIEW_APPLIED, GWC_VT_VIEW_ACTIVE ), true ) ? $view : '';
}

/**
 * Whether the applications view is the one being shown.
 *
 * @return bool
 */
function gwc_vt_applied_view(): bool {
	return GWC_VT_VIEW_APPLIED === gwc_vt_list_view();
}

/**
 * Point the list at applications when that view is picked.
 *
 * ── Scoped as tightly as it is possible to scope a pre_get_posts ─────────────
 * The admin, the main query, the volunteer screen, and a view that says so. A
 * pre_get_posts that misses any one of those is how a plugin changes somebody
 * else's list, and this one changes the post type being queried, which is the
 * version of that mistake with teeth.
 *
 * @param WP_Query $query The query.
 */
function gwc_vt_volunteer_list_query( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( GWC_VT_VOLUNTEER_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	foreach ( gwc_vt_volunteer_list_args( gwc_vt_list_view(), gwc_vt_can_see_records() ) as $key => $value ) {
		$query->set( $key, $value );
	}
}

/**
 * What a view does to the query, as arithmetic over two answers.
 *
 * ── Split out because the hook above cannot be tested ────────────────────────
 * It starts with is_admin(), which is false under WP-CLI, so an integration
 * script calling it gets an early return and a green result that proves
 * nothing. That is the shape of test this repo has been caught by before, so
 * the decision lives here where it can be asked directly, and the hook is left
 * with nothing in it but the guards.
 *
 * @param string $view       A GWC_VT_VIEW_* value, or '' for the default.
 * @param bool   $can_see    Whether this user may see other people's records.
 * @return array<string, string> Query vars to set; empty to leave it alone.
 */
function gwc_vt_volunteer_list_args( string $view, bool $can_see ): array {
	if ( GWC_VT_VIEW_ACTIVE === $view ) {
		return array( 'post_status' => 'publish' );
	}

	/* The capability is checked here rather than only on the view, because this
	 * is the branch that changes which post type is listed. Somebody who may
	 * not see other people's records does not get a list of strangers' names
	 * by editing a query string. */
	if ( GWC_VT_VIEW_APPLIED !== $view || ! $can_see ) {
		return array();
	}

	return array(
		'post_type'   => GWC_VT_APPLICATION_TYPE,
		'post_status' => 'pending',
		'orderby'     => 'date',

		/* Oldest first, which is the queue's whole point: somebody who applied
		 * three weeks ago and heard nothing is who it is for. */
		'order'       => 'ASC',
	);
}

/**
 * Columns that suit what the view is holding.
 *
 * Verified hours and Last shift of somebody nobody has accepted yet are four
 * empty cells pretending to be information. What an applicant has instead is
 * what they wrote and when they sent it.
 *
 * @param array<string, string> $columns What the volunteer list uses.
 * @return array<string, string>
 */
function gwc_vt_volunteer_list_columns( $columns ) {
	if ( ! is_array( $columns ) || ! gwc_vt_applied_view() ) {
		return $columns;
	}

	return array(
		'cb'                => $columns['cb'] ?? '',
		'title'             => __( 'Who', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_said'       => __( 'What they said', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_applied_on' => __( 'Applied', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * Render one cell of an application's row.
 *
 * Hooked on the APPLICATION type, not the volunteer one: core fires
 * manage_{$post->post_type}_posts_custom_column from the post it is drawing,
 * not from the screen it is drawing it on. Hooking the screen's type instead
 * produces a table of empty cells and no error anywhere.
 *
 * @param string $column      Column key.
 * @param int    $application Application post ID.
 */
function gwc_vt_application_column( $column, $application ): void {
	$application = (int) $application;

	if ( 'gwc_vt_said' === $column ) {
		$note = (string) get_post_meta( $application, GWC_VT_APPLICATION_NOTE, true );

		if ( '' === trim( $note ) ) {
			echo '<span class="description">'
				. esc_html__( 'Nothing else.', 'groundwork-common-volunteer-tracker' )
				. '</span>';

			return;
		}

		/* Trimmed here and whole on the screen the row opens. A paragraph
		 * somebody typed does not belong in a table cell, and the cell exists
		 * to say whether it is worth reading rather than to be the reading. */
		echo esc_html( wp_trim_words( $note, 18, '…' ) );

		return;
	}

	if ( 'gwc_vt_applied_on' === $column ) {
		$created = (string) get_post_meta( $application, GWC_VT_APPLICATION_CREATED, true );

		/* gwc_vt_local_date(), which is what the queue screen this row opens
		 * already uses on the same value — the meta holds a full timestamp, and
		 * gwc_vt_display_date() takes Y-m-d and hands anything else straight
		 * back, so it rendered "2026-08-06 11:06:23" into the cell. */
		echo esc_html(
			'' !== $created
				? gwc_vt_local_date( $created )
				: get_the_date( '', $application )
		);
	}
}

/**
 * Accept, discard, and read the whole thing.
 *
 * An application has no edit screen — the type is show_ui => false, so core
 * offers nothing at all on the row and everything here is ours. "Open" goes to
 * the queue screen, which holds the photograph, the whole of what they wrote
 * and any court-ordered service: the things a table cell cannot carry.
 *
 * @param array<string, string> $actions What core offers.
 * @param WP_Post               $post    The row.
 * @return array<string, string>
 */
function gwc_vt_application_row_actions( $actions, $post ) {
	if ( ! is_a( $post, 'WP_Post' ) || GWC_VT_APPLICATION_TYPE !== $post->post_type ) {
		return $actions;
	}

	if ( ! gwc_vt_can_see_records() ) {
		return array();
	}

	return array(
		'gwc_vt_accept'  => sprintf(
			'<a href="%s">%s</a>',
			esc_url( gwc_vt_application_action_url( 'gwc_vt_approve_application', (int) $post->ID ) ),
			esc_html__( 'Add as a volunteer', 'groundwork-common-volunteer-tracker' )
		),
		'gwc_vt_discard' => sprintf(
			'<a class="submitdelete" href="%s">%s</a>',
			esc_url( gwc_vt_application_action_url( 'gwc_vt_discard_application', (int) $post->ID ) ),
			esc_html__( 'Discard', 'groundwork-common-volunteer-tracker' )
		),
		'gwc_vt_open'    => sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'post_type' => GWC_VT_ENTRY_TYPE,
						'page'      => GWC_VT_APPLICATIONS_PAGE,
					),
					admin_url( 'edit.php' )
				) . '#gwcvt-application-' . (int) $post->ID
			),
			esc_html__( 'Open', 'groundwork-common-volunteer-tracker' )
		),
	);
}

/**
 * The address of one view of this list.
 *
 * @param string $view A GWC_VT_VIEW_* value, or '' for the default.
 * @return string
 */
function gwc_vt_volunteer_list_url( string $view = '' ): string {
	$args = array( 'post_type' => GWC_VT_VOLUNTEER_TYPE );

	if ( '' !== $view ) {
		$args[ GWC_VT_LIST_VIEW ] = $view;
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * The strip of views above the list.
 *
 * ── The core statuses carry no meaning here ──────────────────────────────────
 * WP_Posts_List_Table::get_views() offers one view per status, which is right
 * for posts, where a draft and a published post are different kinds of thing.
 * There is no such thing as a draft volunteer, and "Published (24)" beside
 * "All (24)" is a filter that never narrows anything.
 *
 * Removed from the strip rather than from the type. A record that somehow ends
 * up in one of these is still queried, still counted and still editable —
 * gwc_vt_volunteer_statuses() names all four deliberately — it simply has no
 * tab of its own. Hiding a filter is safe in a way that refusing a status would
 * not be: a record nothing can see is a record nobody can fix.
 *
 * Trash stays. Deleting a volunteer is real and reversible, and somebody has to
 * be able to find what they deleted.
 *
 * @param array<string, string> $views What WordPress offers.
 * @return array<string, string>
 */
function gwc_vt_volunteer_views( $views ) {
	if ( ! is_array( $views ) ) {
		return $views;
	}

	foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $noise ) {
		unset( $views[ $noise ] );
	}

	/* ── And "Mine", which is a claim nobody here can make ───────────────────
	 * Core offers it because every post has an author, and on a blog that
	 * author wrote the thing. A volunteer record has one because WordPress
	 * insists, and it means "the account that happened to type this in" —
	 * usually whoever was on the front desk that morning. Nobody owns a
	 * volunteer, and a view that sorts people by which member of staff entered
	 * them is answering a question no coordinator has. */
	unset( $views['mine'] );

	$view = gwc_vt_list_view();

	$views['gwc_vt_active'] = gwc_vt_volunteer_view_link(
		gwc_vt_volunteer_list_url( GWC_VT_VIEW_ACTIVE ),
		__( 'Active', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_volunteer_count( 'publish' ),
		GWC_VT_VIEW_ACTIVE === $view
	);

	$views['gwc_vt_applied'] = gwc_vt_volunteer_view_link(
		gwc_vt_volunteer_list_url( GWC_VT_VIEW_APPLIED ),
		__( 'Applied', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_pending_application_count(),
		GWC_VT_VIEW_APPLIED === $view
	);

	/* Read as the life it describes, whatever order core assembled them in:
	 * everybody, then the ones still coming, then the ones who stopped, then
	 * the ones asking to start, then the bin. Anything a site has added of its
	 * own keeps its place at the end rather than being dropped. */
	$order  = array( 'all', 'gwc_vt_active', GWC_VT_VOLUNTEER_INACTIVE, 'gwc_vt_applied', 'trash' );
	$sorted = array();

	foreach ( $order as $key ) {
		if ( isset( $views[ $key ] ) ) {
			$sorted[ $key ] = $views[ $key ];
			unset( $views[ $key ] );
		}
	}

	return array_merge( $sorted, $views );
}

/**
 * One view, in the shape core draws them.
 *
 * @param string $url     Where it goes.
 * @param string $label   What it says.
 * @param int    $count   How many are in it.
 * @param bool   $current Whether it is the one being shown.
 * @return string
 */
function gwc_vt_volunteer_view_link( string $url, string $label, int $count, bool $current ): string {
	return sprintf(
		'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
		esc_url( $url ),
		$current ? ' class="current" aria-current="page"' : '',
		esc_html( $label ),
		esc_html( number_format_i18n( $count ) )
	);
}

/**
 * How many volunteers are in one status.
 *
 * @param string $status A post status.
 * @return int
 */
function gwc_vt_volunteer_count( string $status ): int {
	$counts = wp_count_posts( GWC_VT_VOLUNTEER_TYPE );

	return (int) ( $counts->{$status} ?? 0 );
}

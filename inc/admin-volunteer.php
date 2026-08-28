<?php
/**
 * A volunteer's own record: what they did, and what we said about it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'gwc_vt_add_volunteer_history_boxes' );

add_action( 'restrict_manage_posts', 'gwc_vt_volunteer_filter_dropdown' );
add_action( 'pre_get_posts', 'gwc_vt_apply_volunteer_filter' );
add_filter( 'manage_edit-' . GWC_VT_VOLUNTEER_TYPE . '_sortable_columns', 'gwc_vt_volunteer_sortable_columns' );

/* ── Finding one person on the volunteer list ────────────────────────────────
 * The hours list has had a filter since verification shipped. The volunteer list
 * had columns and nothing else — no filter, no sortable column, no bulk action.
 *
 * Which mattered because the dashboard sends people here. Its worklist says "3
 * people are past their deadline — See who", and the link was a bare
 * edit.php?post_type=gwc_vt_volunteer: every volunteer the site has ever had, in
 * no particular order, with the Required column to be read down page by page.
 * The screen naming a number was the one screen that could not show you which
 * three.
 *
 * Overdue is not a meta comparison — it is "past the deadline AND still short",
 * and the second half is computed from verified hours. So that option filters by
 * ID using gwc_vt_overdue_requirement_ids(), which is the same function the
 * dashboard counted with. One definition, so the count and the list cannot
 * disagree.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWC_VT_VOLUNTEER_FILTER = 'gwc_vt_requirement';

/**
 * The states worth filtering the volunteer list by.
 *
 * A function rather than a const: a const is evaluated at include time, which
 * freezes these in English for the request.
 *
 * @return array<string, string>
 */
function gwc_vt_volunteer_filter_options(): array {
	return array(
		''        => __( 'Any volunteer', 'groundwork-common-volunteer-tracker' ),
		'has'     => __( 'Has hours to complete', 'groundwork-common-volunteer-tracker' ),
		'overdue' => __( 'Past their deadline', 'groundwork-common-volunteer-tracker' ),
		'none'    => __( 'No requirement', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * The filter, above the volunteer list.
 */
function gwc_vt_volunteer_filter_dropdown(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only and core does not nonce these.
	$current = isset( $_GET[ GWC_VT_VOLUNTEER_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_VOLUNTEER_FILTER ] ) ) : '';
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( GWC_VT_VOLUNTEER_FILTER ); ?>">
		<?php esc_html_e( 'Filter by what they have to complete', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="<?php echo esc_attr( GWC_VT_VOLUNTEER_FILTER ); ?>" id="<?php echo esc_attr( GWC_VT_VOLUNTEER_FILTER ); ?>">
		<?php foreach ( gwc_vt_volunteer_filter_options() as $value => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, $value ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * The query vars one filter state and sort ask for.
 *
 * Pure, and separate from the hook below, so the two rules that matter — that an
 * empty overdue set shows nobody rather than everybody, and that sorting by
 * deadline does not drop the volunteers who have none — can be asserted without
 * a request. Same split as gwc_vt_dashboard_items().
 *
 * @param string $state   One of '', 'has', 'overdue', 'none'.
 * @param string $orderby The requested orderby.
 * @param string $order   ASC or DESC.
 * @param int[]  $overdue The overdue volunteer IDs, when $state is 'overdue'.
 * @return array<string, mixed> Query vars to set.
 */
function gwc_vt_volunteer_query_vars( string $state, string $orderby = '', string $order = 'ASC', array $overdue = array() ): array {
	$vars = array();

	if ( 'overdue' === $state ) {
		/* array( 0 ) rather than an empty array: post__in with nothing in it is
		 * ignored by WP_Query, which would list every volunteer under a filter
		 * saying these are the overdue ones. */
		$vars['post__in'] = $overdue ? array_values( array_map( 'intval', $overdue ) ) : array( 0 );
	} elseif ( 'has' === $state ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering an admin list table by a meta value is what meta_query is for; the key is indexed and this runs on one screen a coordinator opens deliberately.
		$vars['meta_query'] = array(
			array(
				'key'     => GWC_VT_VOLUNTEER_REQUIRED,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
	} elseif ( 'none' === $state ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above, and NOT EXISTS has no non-meta equivalent: "volunteers with no requirement" cannot be expressed as a post field.
		$vars['meta_query'] = array(
			'relation' => 'OR',
			array(
				'key'     => GWC_VT_VOLUNTEER_REQUIRED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => GWC_VT_VOLUNTEER_REQUIRED,
				'value'   => 0,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		);
	}

	if ( 'gwc_vt_required' !== $orderby ) {
		return $vars;
	}

	$order = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';

	/* Ordering by a meta key uses an INNER JOIN, so every volunteer without a
	 * deadline would vanish from the list rather than sorting last. The
	 * EXISTS-or-NOT-EXISTS pair keeps them. Same rule, and the same reason, as
	 * the hours list's date ordering in inc/admin-verify.php.
	 *
	 * This replaces any meta_query the filter set above, which is why the two
	 * cannot be combined: a filter and this sort at once would need one clause
	 * array holding both, and the filter states that use meta_query are exactly
	 * the ones this sort makes redundant. */
	$vars['orderby'] = array(
		'gwc_vt_deadline' => $order,
		'title'           => 'ASC',
	);
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the EXISTS-or-NOT-EXISTS pair described directly above. Dropping it to satisfy the sniff would silently hide every volunteer without a deadline, which is the bug the comment exists to prevent.
	$vars['meta_query'] = array(
		'relation'        => 'OR',
		'gwc_vt_deadline' => array(
			'key'     => GWC_VT_VOLUNTEER_REQUIRED_BY,
			'compare' => 'EXISTS',
			'type'    => 'CHAR',
		),
		array(
			'key'     => GWC_VT_VOLUNTEER_REQUIRED_BY,
			'compare' => 'NOT EXISTS',
		),
	);

	return $vars;
}

/**
 * Apply the filter, and the deadline sort.
 *
 * @param WP_Query $query The query about to run.
 */
function gwc_vt_apply_volunteer_filter( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( GWC_VT_VOLUNTEER_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only and core does not nonce these.
	$state = isset( $_GET[ GWC_VT_VOLUNTEER_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_VOLUNTEER_FILTER ] ) ) : '';

	$vars = gwc_vt_volunteer_query_vars(
		$state,
		(string) $query->get( 'orderby' ),
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- WP_Query::get() returns '' for an unset key, not null, so ?? would keep the empty string.
		(string) ( $query->get( 'order' ) ?: 'ASC' ),
		'overdue' === $state ? gwc_vt_overdue_requirement_ids() : array()
	);

	foreach ( $vars as $key => $value ) {
		// Indexed keys, on a screen somebody explicitly asked to filter or sort.
		$query->set( $key, $value );
	}
}

/**
 * Which volunteer columns can be sorted.
 *
 * Only the deadline. Verified hours and the last shift are read from the cached
 * totals array, which is one meta row holding a structure — there is no column
 * for MySQL to order by, and offering a sort that silently did nothing would be
 * worse than not offering it.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function gwc_vt_volunteer_sortable_columns( $columns ): array {
	$columns = (array) $columns;

	$columns['gwc_vt_required'] = 'gwc_vt_required';

	return $columns;
}

/* ── Why this is on the volunteer and not only in the lists ──────────────────
 * The hours list answers "what happened last week" and the Letters screen
 * answers "who needs a letter". Neither answers the question somebody actually
 * arrives with, which is about one person: how many hours has this volunteer
 * done, when, and what have we already told anybody about them.
 *
 * Getting that today means filtering the hours list by name and then separately
 * scanning the issued-letter log — two screens and a mental join, performed
 * while somebody is on the phone.
 *
 * The hours panel is read-only: a shift is edited on the shift, and a second
 * write path into the numbers a letter is built from is exactly what this
 * plugin spends its time avoiding. The letters panel writes, but only ever the
 * intention — a draft is started and discarded there, and an issued letter is
 * still not editable by anybody. See inc/admin-volunteer-letters.php.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register both panels.
 */
function gwc_vt_add_volunteer_history_boxes(): void {
	add_meta_box(
		'gwc-vt-volunteer-hours',
		__( 'Hours logged', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_hours_box',
		GWC_VT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);

	if ( ! gwc_vt_letters_enabled() || ! current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		/* The letter log names documents sent to courts and schools. Somebody
		 * who may edit a volunteer record but not issue letters has no reason to
		 * see which ones went out — and neither does anybody at an organization
		 * that does not issue them. The log itself is untouched either way. */
		return;
	}

	add_meta_box(
		'gwc-vt-volunteer-letters',
		__( 'Verification letters', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_letters_box',
		GWC_VT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);
}

/**
 * Every shift this volunteer has logged.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_hours_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save this volunteer, then log their first shift.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	/* Drafts and pending included, unlike the letter. This screen is where staff
	 * triage, so a self-logged shift somebody has attached but not yet verified
	 * has to be visible here even though it would never reach a document. */
	$entry_ids = gwc_vt_entry_ids_for_volunteer(
		$volunteer_id,
		array( 'statuses' => array( 'publish', 'pending', 'draft' ) )
	);

	if ( ! $entry_ids ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No hours logged for this volunteer yet.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	// Newest first: the question is almost always about recent work.
	$rows = array();

	foreach ( $entry_ids as $entry_id ) {
		$entry_id = (int) $entry_id;

		$rows[] = array(
			'id'       => $entry_id,
			'date'     => (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true ),
			'minutes'  => (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true ),
			'activity' => (string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true ),
			'status'   => (string) get_post_status( $entry_id ),
			'verified' => gwc_vt_entry_is_verified( $entry_id ),
		);
	}

	usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

	?>
	<table class="widefat striped gwcvt-history">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Activity', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verified', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( '' !== $row['date'] ? $row['date'] : '—' ); ?></td>
					<td><?php echo esc_html( gwc_vt_format_hours( $row['minutes'] ) ); ?></td>
					<td><?php echo esc_html( $row['activity'] ); ?></td>
					<td>
						<?php if ( $row['verified'] ) : ?>
							<span class="gwcvt-badge gwcvt-badge--verified"><?php echo esc_html( gwc_vt_verification_label( 'verified' ) ); ?></span>
						<?php else : ?>
							<span class="gwcvt-badge gwcvt-badge--waiting">
								<?php
								echo esc_html(
									'pending' === $row['status']
										? gwc_vt_verification_label( 'pending' )
										: gwc_vt_verification_label( 'unverified' )
								);
								?>
							</span>
						<?php endif; ?>
					</td>
					<td class="gwcvt-history__action">
						<a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>">
							<?php esc_html_e( 'Open', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
}


<?php
/**
 * A volunteer's own record: what they did, and what we said about it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'gwcvt_add_volunteer_history_boxes' );

add_action( 'restrict_manage_posts', 'gwcvt_volunteer_filter_dropdown' );
add_action( 'pre_get_posts', 'gwcvt_apply_volunteer_filter' );
add_filter( 'manage_edit-' . GWCVT_VOLUNTEER_TYPE . '_sortable_columns', 'gwcvt_volunteer_sortable_columns' );

/* ── Finding one person on the volunteer list ────────────────────────────────
 * The hours list has had a filter since verification shipped. The volunteer list
 * had columns and nothing else — no filter, no sortable column, no bulk action.
 *
 * Which mattered because the dashboard sends people here. Its worklist says "3
 * people are past their deadline — See who", and the link was a bare
 * edit.php?post_type=gwcvt_volunteer: every volunteer the site has ever had, in
 * no particular order, with the Required column to be read down page by page.
 * The screen naming a number was the one screen that could not show you which
 * three.
 *
 * Overdue is not a meta comparison — it is "past the deadline AND still short",
 * and the second half is computed from verified hours. So that option filters by
 * ID using gwcvt_overdue_requirement_ids(), which is the same function the
 * dashboard counted with. One definition, so the count and the list cannot
 * disagree.
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCVT_VOLUNTEER_FILTER = 'gwcvt_requirement';

/**
 * The states worth filtering the volunteer list by.
 *
 * A function rather than a const: a const is evaluated at include time, which
 * freezes these in English for the request.
 *
 * @return array<string, string>
 */
function gwcvt_volunteer_filter_options(): array {
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
function gwcvt_volunteer_filter_dropdown(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWCVT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only and core does not nonce these.
	$current = isset( $_GET[ GWCVT_VOLUNTEER_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWCVT_VOLUNTEER_FILTER ] ) ) : '';
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( GWCVT_VOLUNTEER_FILTER ); ?>">
		<?php esc_html_e( 'Filter by what they have to complete', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="<?php echo esc_attr( GWCVT_VOLUNTEER_FILTER ); ?>" id="<?php echo esc_attr( GWCVT_VOLUNTEER_FILTER ); ?>">
		<?php foreach ( gwcvt_volunteer_filter_options() as $value => $label ) : ?>
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
 * a request. Same split as gwcvt_dashboard_items().
 *
 * @param string $state   One of '', 'has', 'overdue', 'none'.
 * @param string $orderby The requested orderby.
 * @param string $order   ASC or DESC.
 * @param int[]  $overdue The overdue volunteer IDs, when $state is 'overdue'.
 * @return array<string, mixed> Query vars to set.
 */
function gwcvt_volunteer_query_vars( string $state, string $orderby = '', string $order = 'ASC', array $overdue = array() ): array {
	$vars = array();

	if ( 'overdue' === $state ) {
		/* array( 0 ) rather than an empty array: post__in with nothing in it is
		 * ignored by WP_Query, which would list every volunteer under a filter
		 * saying these are the overdue ones. */
		$vars['post__in'] = $overdue ? array_values( array_map( 'intval', $overdue ) ) : array( 0 );
	} elseif ( 'has' === $state ) {
		$vars['meta_query'] = array(
			array(
				'key'     => GWCVT_VOLUNTEER_REQUIRED,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
	} elseif ( 'none' === $state ) {
		$vars['meta_query'] = array(
			'relation' => 'OR',
			array(
				'key'     => GWCVT_VOLUNTEER_REQUIRED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => GWCVT_VOLUNTEER_REQUIRED,
				'value'   => 0,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		);
	}

	if ( 'gwcvt_required' !== $orderby ) {
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
	$vars['orderby']    = array(
		'gwcvt_deadline' => $order,
		'title'          => 'ASC',
	);
	$vars['meta_query'] = array(
		'relation'       => 'OR',
		'gwcvt_deadline' => array(
			'key'     => GWCVT_VOLUNTEER_REQUIRED_BY,
			'compare' => 'EXISTS',
			'type'    => 'CHAR',
		),
		array(
			'key'     => GWCVT_VOLUNTEER_REQUIRED_BY,
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
function gwcvt_apply_volunteer_filter( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( GWCVT_VOLUNTEER_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only and core does not nonce these.
	$state = isset( $_GET[ GWCVT_VOLUNTEER_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWCVT_VOLUNTEER_FILTER ] ) ) : '';

	$vars = gwcvt_volunteer_query_vars(
		$state,
		(string) $query->get( 'orderby' ),
		(string) ( $query->get( 'order' ) ?: 'ASC' ),
		'overdue' === $state ? gwcvt_overdue_requirement_ids() : array()
	);

	foreach ( $vars as $key => $value ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed keys, on a screen somebody explicitly asked to filter or sort.
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
function gwcvt_volunteer_sortable_columns( $columns ): array {
	$columns = (array) $columns;

	$columns['gwcvt_required'] = 'gwcvt_required';

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
 * Both panels are read-only. A shift is edited on the shift, and an issued
 * letter is not editable at all; making either editable from here would mean a
 * second write path into records whose correctness is the product.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register both history panels.
 */
function gwcvt_add_volunteer_history_boxes(): void {
	add_meta_box(
		'gwcvt-volunteer-hours',
		__( 'Hours logged', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_volunteer_hours_box',
		GWCVT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);

	if ( ! current_user_can( gwcvt_cap( 'issue' ) ) ) {
		/* The letter log names documents sent to courts and schools. Somebody
		 * who may edit a volunteer record but not issue letters has no reason to
		 * see which ones went out. */
		return;
	}

	add_meta_box(
		'gwcvt-volunteer-letters',
		__( 'Letters issued', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_volunteer_letters_box',
		GWCVT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);
}

/**
 * Every shift this volunteer has logged.
 *
 * @param WP_Post $post The volunteer.
 */
function gwcvt_render_volunteer_hours_box( $post ): void {
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
	$entry_ids = gwcvt_entry_ids_for_volunteer(
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
			'date'     => (string) get_post_meta( $entry_id, GWCVT_ENTRY_DATE, true ),
			'minutes'  => (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true ),
			'activity' => (string) get_post_meta( $entry_id, GWCVT_ENTRY_ACTIVITY, true ),
			'status'   => (string) get_post_status( $entry_id ),
			'verified' => gwcvt_entry_is_verified( $entry_id ),
		);
	}

	usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

	$totals = gwcvt_volunteer_totals( $volunteer_id );
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
					<td><?php echo esc_html( gwcvt_format_hours( $row['minutes'] ) ); ?></td>
					<td><?php echo esc_html( $row['activity'] ); ?></td>
					<td>
						<?php if ( $row['verified'] ) : ?>
							<span class="gwcvt-badge gwcvt-badge--verified"><?php echo esc_html( gwcvt_verification_label( 'verified' ) ); ?></span>
						<?php else : ?>
							<span class="gwcvt-badge gwcvt-badge--waiting">
								<?php
								echo esc_html(
									'pending' === $row['status']
										? gwcvt_verification_label( 'pending' )
										: gwcvt_verification_label( 'unverified' )
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

	<p class="gwcvt-history__summary">
		<?php
		printf(
			/* translators: 1: verified hours, 2: unverified hours, 3: number of shifts. */
			esc_html__( '%1$s verified, %2$s awaiting verification, across %3$s.', 'groundwork-common-volunteer-tracker' ),
			'<strong>' . esc_html( gwcvt_format_hours( $totals->verified_minutes ) ) . '</strong>',
			'<strong>' . esc_html( gwcvt_format_hours( $totals->pending_minutes ) ) . '</strong>',
			'<strong>' . esc_html(
				sprintf(
					/* translators: %d: number of shifts. */
					_n( '%d shift', '%d shifts', count( $rows ), 'groundwork-common-volunteer-tracker' ),
					count( $rows )
				)
			) . '</strong>'
		);
		?>
	</p>

	<?php if ( current_user_can( gwcvt_cap( 'issue' ) ) && $totals->verified_minutes > 0 ) : ?>
		<p>
			<a class="button" href="<?php echo esc_url( gwcvt_letters_url( array( 'volunteer' => $volunteer_id ) ) ); ?>">
				<?php esc_html_e( 'Produce a letter for this volunteer', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Every letter issued about this volunteer.
 *
 * @param WP_Post $post The volunteer.
 */
function gwcvt_render_volunteer_letters_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		return;
	}

	$records = gwcvt_letters_for_volunteer( $volunteer_id );

	if ( ! $records ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No verification letter has been issued for this volunteer.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	// Newest first.
	usort( $records, static fn( array $a, array $b ): int => strcmp( $b['issued_at'], $a['issued_at'] ) );
	?>
	<table class="widefat striped gwcvt-history">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Issued', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Reference', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours stated', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'How', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $records as $record ) : ?>
				<tr>
					<td><?php echo esc_html( $record['issued_at'] ); ?></td>
					<td><code><?php echo esc_html( $record['reference'] ); ?></code></td>
					<td><?php echo esc_html( gwcvt_format_hours( $record['minutes'] ) ); ?></td>
					<td>
						<?php
						if ( 'email' === $record['medium'] ) {
							echo esc_html(
								$record['sent_ok']
									/* translators: %s: an email address. */
									? sprintf( __( 'Emailed to %s', 'groundwork-common-volunteer-tracker' ), $record['recipient'] )
									: __( 'Email failed', 'groundwork-common-volunteer-tracker' )
							);
						} else {
							esc_html_e( 'Printed', 'groundwork-common-volunteer-tracker' );
						}
						?>
					</td>
					<td class="gwcvt-history__action">
						<?php
						/* Straight to the checker with the reference already in
						 * the box. The common reason for looking at this table is
						 * that somebody has phoned about one of these. */
						?>
						<a href="<?php echo esc_url( gwcvt_letters_url( array( 'reference' => $record['reference'] ) ) ); ?>">
							<?php esc_html_e( 'Check it', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description">
		<?php esc_html_e( 'What each letter stated when it went out. Checking one compares it against the records as they stand now.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>
	<?php
}

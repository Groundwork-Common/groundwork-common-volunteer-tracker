<?php
/**
 * The schedule: what is coming up, and how full it is.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_schedule_menu', 13 );
add_action( 'admin_notices', 'gwc_vt_unreconciled_notice' );

/* ── What this screen is for ─────────────────────────────────────────────────
 * A coordinator does not open the schedule to read a list of posts. They open it
 * to answer one question — which of these is short of people, and how soon — and
 * then to go and do something about it.
 *
 * So the default view is forward-looking, ordered by when the shift is rather
 * than when it was typed in, and the fill figure is the loudest thing in the
 * row. "2 of 6" is the number that makes somebody pick up the phone; the shift's
 * title is not.
 *
 * It is one registered page with three views rather than three pages, because a
 * hidden submenu page — the usual way to get an editor screen for a type with no
 * admin UI — means registering under a parent and then removing it again, and
 * every version of that trick is a thing that breaks quietly when core changes.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Hang the schedule off the Volunteer Hours menu.
 */
function gwc_vt_register_schedule_menu(): void {
	if ( ! gwc_vt_shifts_enabled() ) {
		return;
	}

	add_submenu_page(
		GWC_VT_MENU_SLUG,
		__( 'Schedule', 'groundwork-common-volunteer-tracker' ),
		__( 'Schedule', 'groundwork-common-volunteer-tracker' ),
		'edit_posts',
		GWC_VT_SCHEDULE_PAGE,
		'gwc_vt_render_schedule_screen'
	);
}

/**
 * Is scheduling switched on?
 *
 * Off by default, like the public form, and for a related reason: a plugin that
 * grew a scheduling system in the sidebar because somebody upgraded it would be
 * presenting a coordinator with a screen they did not ask for, next to the hours
 * they did. Turning it on is a decision.
 *
 * @return bool
 */
function gwc_vt_shifts_enabled(): bool {
	return (bool) gwc_vt_setting( 'shifts_enabled' );
}

/**
 * The screen, in whichever of its three views was asked for.
 */
function gwc_vt_render_schedule_screen(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to manage the schedule.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between views.
	$event = isset( $_GET['gwc_vt_event'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_event'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

	if ( 'new' === $event ) {
		gwc_vt_render_event_editor( 0 );
		return;
	}

	$event_id = absint( $event );

	if ( $event_id > 0 && GWC_VT_EVENT_TYPE === get_post_type( $event_id ) ) {
		if ( 'roster' === $view ) {
			gwc_vt_render_event_roster( $event_id );
			return;
		}

		/* The two decisions that stop to ask. Each is a whole screen with one
		 * choice on it, because both need a reason typed and both decide whether
		 * people get an email — neither of which fits on a row. */
		if ( 'call-off' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the POST it leads to carries the nonce.
			$slot = isset( $_GET['slot'] ) ? absint( wp_unslash( $_GET['slot'] ) ) : 0;

			if ( gwc_vt_event_for_shift( $slot ) === $event_id ) {
				gwc_vt_render_call_off_slot( $slot );
				return;
			}
		}

		if ( 'drop-role' === $view ) {
			/* sanitize_text_field() is the outermost call, which is the order that
			 * matters: the value is unslashed, then URL-decoded, and only then
			 * sanitized, so nothing a decode could reintroduce escapes it. The
			 * sniff cannot follow rawurldecode() through the chain and reports the
			 * input as raw. Decoding after sanitizing would be the real bug, and
			 * is what this ordering avoids. */
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view switch, no state change; sanitized outermost, see above.
			$role = isset( $_GET['role'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['role'] ) ) ) : '';

			if ( '' !== $role ) {
				gwc_vt_render_drop_role( $event_id, $role );
				return;
			}
		}

		gwc_vt_render_event_editor( $event_id );
		return;
	}

	if ( 'events' === $view ) {
		gwc_vt_render_events_list();
		return;
	}

	if ( 'month' === $view ) {
		gwc_vt_render_schedule_month();
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between views.
	$wanted = isset( $_GET['shift'] ) ? sanitize_text_field( wp_unslash( $_GET['shift'] ) ) : '';

	if ( 'new' === $wanted ) {
		gwc_vt_render_shift_editor( 0 );
		return;
	}

	$shift_id = absint( $wanted );

	if ( $shift_id > 0 && GWC_VT_SHIFT_TYPE === get_post_type( $shift_id ) ) {
		gwc_vt_render_shift_editor( $shift_id );
		return;
	}

	gwc_vt_render_schedule_list();
}

/**
 * Everything coming up, soonest first.
 */
function gwc_vt_render_schedule_list(): void {
	$when = gwc_vt_schedule_when();
	$day  = gwc_vt_schedule_day();

	$from = 'past' === $when ? gmdate( 'Y-m-d', time() - ( 120 * DAY_IN_SECONDS ) ) : gwc_vt_today();
	$to   = 'past' === $when ? gwc_vt_today() : gmdate( 'Y-m-d', time() + ( 400 * DAY_IN_SECONDS ) );

	/* One day, when the calendar's "+2 more" sent somebody here. It overrides
	 * the half-of-the-calendar window rather than narrowing it: the day might be
	 * last March, and the past/future split has nothing to say about a date
	 * somebody named. */
	if ( '' !== $day ) {
		$from = $day;
		$to   = $day;
	}

	/* Standalone shifts only, because an event appears here as ONE row rather
	 * than as its six slots. Interleaving a festival's times among next
	 * Saturday's ordinary shifts buries both; leaving the event out entirely is
	 * a list that lies by omission. A summary row that links through is the
	 * middle, and gwc_vt_schedule_rows() below merges the two by date. */
	$shifts = gwc_vt_shifts_between(
		array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ),
			'limit'    => 200,
			'parent'   => 0,
		)
	);

	$events = gwc_vt_events_between(
		array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => array( 'publish', 'draft', GWC_VT_EVENT_CANCELLED ),
			'limit'    => 100,
		)
	);

	$rows = gwc_vt_schedule_rows( $shifts, $events );

	/* Narrowed before anything is counted, so the chips and the rows come from
	 * one array. The search narrows first and the chips count what survives it —
	 * "Short of people · 7" over two rows because the other five did not match
	 * the search is the same lie as counting the wrong thing. */
	$term   = gwc_vt_schedule_search();
	$rows   = gwc_vt_filter_schedule_rows( $rows, '', $term );
	$counts = gwc_vt_schedule_state_counts( $rows );
	$state  = gwc_vt_schedule_filter();
	$rows   = gwc_vt_filter_schedule_rows( $rows, $state, '' );

	if ( 'past' === $when ) {
		$rows = array_reverse( $rows );
	}

	/* After the reverse, so a run folds in the order it is about to be printed
	 * rather than in the order it was queried. Folding first and reversing after
	 * would put the last cancelled Saturday's date on a row summarising the six
	 * before it. */
	if ( ! gwc_vt_schedule_is_unfolded() ) {
		$rows = gwc_vt_fold_cancelled_repeats( $rows );
	}

	$base = add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_SCHEDULE_PAGE,
		),
		admin_url( 'edit.php' )
	);
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Schedule', 'groundwork-common-volunteer-tracker' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'shift', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add a shift', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'gwc_vt_event', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add an event', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwc_vt_schedule_notice(); ?>
		<?php gwc_vt_event_notice(); ?>

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( $base ); ?>" <?php echo 'upcoming' === $when ? 'class="current" aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Coming up', 'groundwork-common-volunteer-tracker' ); ?>
				</a> |
			</li>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'when', 'past', $base ) ); ?>" <?php echo 'past' === $when ? 'class="current" aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Already happened', 'groundwork-common-volunteer-tracker' ); ?>
				</a> |
			</li>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'events', $base ) ); ?>">
					<?php esc_html_e( 'Events', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</li>
		</ul>

		<?php gwc_vt_render_schedule_view_tabs( $base, 'list' ); ?>
		<?php gwc_vt_render_schedule_filters( $base, $counts, $state, $term, $when ); ?>

		<?php if ( '' !== $day ) : ?>
			<p class="gwcvt-schedule__day">
				<?php
				printf(
					/* translators: %s: a date. */
					esc_html__( 'One day only: %s.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_shift_date_label_from( $day ) )
				);
				?>
				<a href="<?php echo esc_url( remove_query_arg( 'gwc_vt_on' ) ); ?>">
					<?php esc_html_e( 'Show the whole schedule', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<table class="widefat striped gwcvt-schedule">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who is coming', 'groundwork-common-volunteer-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr>
						<td colspan="4">
							<?php
							if ( '' !== $state || '' !== $term ) {
								esc_html_e( 'Nothing on the schedule matches that.', 'groundwork-common-volunteer-tracker' );
							} else {
								echo 'past' === $when
									? esc_html__( 'Nothing in the last few months.', 'groundwork-common-volunteer-tracker' )
									: esc_html__( 'Nothing scheduled yet. Add a shift to get started.', 'groundwork-common-volunteer-tracker' );
							}
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php
				/* The frame the dashboard taught the coordinator — this week,
				 * next week, then by month — which the schedule was the only
				 * screen to drop. A <th scope="rowgroup"> rather than a styled
				 * <td>: this is a heading for the rows under it, and saying so
				 * is what makes the table still make sense read aloud. */
				$group = '';

				foreach ( $rows as $row ) :
					$heading = gwc_vt_schedule_group_label( (string) $row['date'], $when );

					if ( $heading !== $group ) :
						$group = $heading;
						?>
						<tr class="gwcvt-schedule__group">
							<th scope="rowgroup" colspan="4"><?php echo esc_html( $heading ); ?></th>
						</tr>
						<?php
					endif;

					if ( 'event' === $row['type'] ) {
						gwc_vt_render_event_summary_row( $row['id'] );
						continue;
					}

					gwc_vt_render_schedule_row( $row, $base );
				endforeach;
				?>
			</tbody>
		</table>
	</div>
	<?php
}


/**
 * The month calendar.
 */
function gwc_vt_render_schedule_month(): void {
	$month = gwc_vt_schedule_month();
	$today = gwc_vt_today();
	$weeks = gwc_vt_month_grid( $month, (int) get_option( 'start_of_week' ), $today );

	/* The window is the grid, not the month: the leading and trailing cells
	 * belong to the neighbouring months and a shift on one of them is still on
	 * the screen. Querying the month alone would draw those cells empty and lie
	 * about a Saturday that is right there. */
	$from = (string) $weeks[0][0]['date'];
	$last = $weeks[ count( $weeks ) - 1 ];
	$to   = (string) $last[ count( $last ) - 1 ]['date'];

	$shifts = gwc_vt_shifts_between(
		array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ),
			'limit'    => 200,
			'parent'   => 0,
		)
	);

	$events = gwc_vt_events_between(
		array(
			'from'     => $from,
			'to'       => $to,
			'statuses' => array( 'publish', 'draft', GWC_VT_EVENT_CANCELLED ),
			'limit'    => 100,
		)
	);

	/* The same rows the list builds, narrowed by the same filters — which is
	 * what makes a chip count and a row count the same number whichever view
	 * somebody is in. */
	$rows   = gwc_vt_schedule_rows( $shifts, $events );
	$term   = gwc_vt_schedule_search();
	$rows   = gwc_vt_filter_schedule_rows( $rows, '', $term );
	$counts = gwc_vt_schedule_state_counts( $rows );
	$state  = gwc_vt_schedule_filter();
	$rows   = gwc_vt_filter_schedule_rows( $rows, $state, '' );

	$by_day = array();

	foreach ( $rows as $row ) {
		$by_day[ (string) $row['date'] ][] = $row;
	}

	$base = add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_SCHEDULE_PAGE,
		),
		admin_url( 'edit.php' )
	);

	$weekdays = gwc_vt_weekday_initials( (int) get_option( 'start_of_week' ) );
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Schedule', 'groundwork-common-volunteer-tracker' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'shift', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add a shift', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'gwc_vt_event', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add an event', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwc_vt_schedule_notice(); ?>
		<?php gwc_vt_event_notice(); ?>

		<?php gwc_vt_render_schedule_view_tabs( $base, 'month' ); ?>
		<?php gwc_vt_render_schedule_filters( $base, $counts, $state, $term, 'upcoming', 'month' ); ?>

		<?php
		/* ‹ and › as plain links carrying everything else about the view. A
		 * month you navigated to with a filter on and lost the filter is one
		 * you have to set up again on arrival. */
		$carrier = add_query_arg( 'view', 'month', $base );

		if ( '' !== $state ) {
			$carrier = add_query_arg( 'gwc_vt_state', $state, $carrier );
		}

		if ( '' !== $term ) {
			$carrier = add_query_arg( 's', $term, $carrier );
		}

		/* The same query minus the view, for links that leave the calendar. A
		 * "+2 more" that kept view=month would land back on the month, which
		 * does not read gwc_vt_on — the cap would send somebody to the screen
		 * that could not show them what they clicked for. */
		$to_list = remove_query_arg( 'view', $carrier );

		$stamp = (int) strtotime( $month . '-01 00:00:00 UTC' );
		?>
		<div class="gwcvt-month__nav">
			<a
				class="gwcvt-month__step"
				href="<?php echo esc_url( add_query_arg( 'gwc_vt_month', gwc_vt_month_step( $month, -1 ), $carrier ) ); ?>"
				rel="prev"
			>
				<span aria-hidden="true">&lsaquo;</span>
				<span class="screen-reader-text"><?php esc_html_e( 'The month before', 'groundwork-common-volunteer-tracker' ); ?></span>
			</a>

			<strong class="gwcvt-month__name">
				<?php echo esc_html( (string) wp_date( 'F Y', $stamp, new DateTimeZone( 'UTC' ) ) ); ?>
			</strong>

			<a
				class="gwcvt-month__step"
				href="<?php echo esc_url( add_query_arg( 'gwc_vt_month', gwc_vt_month_step( $month, 1 ), $carrier ) ); ?>"
				rel="next"
			>
				<span aria-hidden="true">&rsaquo;</span>
				<span class="screen-reader-text"><?php esc_html_e( 'The month after', 'groundwork-common-volunteer-tracker' ); ?></span>
			</a>

			<?php if ( gwc_vt_schedule_month() !== gmdate( 'Y-m', (int) strtotime( $today . ' 00:00:00 UTC' ) ) ) : ?>
				<a class="gwcvt-month__today" href="<?php echo esc_url( $carrier ); ?>">
					<?php esc_html_e( 'Back to this month', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="gwcvt-month">
			<?php foreach ( $weekdays as $name ) : ?>
				<div class="gwcvt-month__weekday"><?php echo esc_html( $name ); ?></div>
			<?php endforeach; ?>

			<?php
			foreach ( $weeks as $week ) :
				foreach ( $week as $day ) :
					$date  = (string) $day['date'];
					$stack = (array) ( $by_day[ $date ] ?? array() );

					$classes = array( 'gwcvt-month__day' );

					if ( ! $day['in_month'] ) {
						$classes[] = 'gwcvt-month__day--outside';
					}
					?>
					<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<span class="gwcvt-month__number<?php echo $day['today'] ? ' gwcvt-month__number--today' : ''; ?>">
							<?php echo esc_html( (string) wp_date( 'j', (int) strtotime( $date . ' 00:00:00 UTC' ), new DateTimeZone( 'UTC' ) ) ); ?>
							<?php if ( $day['today'] ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( '(today)', 'groundwork-common-volunteer-tracker' ); ?></span>
							<?php endif; ?>
						</span>

						<?php
						/* One busy Saturday must not set the height of every row
						 * in the month, so a cell shows three and says how many
						 * it is holding back. The overflow link goes to the list
						 * filtered to that day rather than expanding in place:
						 * the list is the view that copes with a day like that,
						 * which is the whole reason it stayed. */
						$shown = array_slice( $stack, 0, GWC_VT_MONTH_CHIPS );
						$more  = count( $stack ) - count( $shown );

						foreach ( $shown as $row ) {
							gwc_vt_render_month_chip( $row, $base );
						}

						if ( $more > 0 ) :
							?>
							<a
								class="gwcvt-month__more"
								href="<?php echo esc_url( add_query_arg( 'gwc_vt_on', $date, $to_list ) ); ?>"
							>
								<?php
								printf(
									/* translators: %d: how many more shifts are on that day. */
									esc_html( _n( '+%d more', '+%d more', $more, 'groundwork-common-volunteer-tracker' ) ),
									(int) $more
								);
								?>
							</a>
							<?php
						endif;
						?>
					</div>
					<?php
				endforeach;
			endforeach;
			?>
		</div>

		<?php gwc_vt_render_month_legend(); ?>
	</div>
	<?php
}

/**
 * One row of the schedule, as a chip in a day cell.
 *
 * @param array  $row  One row from gwc_vt_schedule_rows().
 * @param string $base The screen's own URL.
 */
function gwc_vt_render_month_chip( array $row, string $base ): void {
	$id      = (int) $row['id'];
	$state   = (string) ( $row['state'] ?? 'ok' );
	$event   = 'event' === ( $row['type'] ?? '' );
	$classes = array( 'gwcvt-chip', 'gwcvt-chip--' . $state );

	if ( $event ) {
		/* On top of the state colour, not instead of it. The tint answers "is
		 * this one in trouble" and has to keep meaning that for an event whose
		 * times are short; the bar answers "does this open a day or a roster". */
		$classes[] = 'gwcvt-chip--event';
	}

	if ( $event ) {
		$what = (string) get_the_title( $id );
		$fill = gwc_vt_event_fill_summary( $id );
		$url  = gwc_vt_event_roster_url( $id );
	} else {
		$what = (string) get_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, true );
		$fill = gwc_vt_shift_fill_summary( $id, $state );
		$url  = add_query_arg( 'shift', $id, $base );
	}

	$what = '' !== trim( $what ) ? $what : __( 'Untitled', 'groundwork-common-volunteer-tracker' );
	?>
	<a
		class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
		href="<?php echo esc_url( $url ); ?>"
		title="<?php echo esc_attr( $what ); ?>"
	>
		<span class="gwcvt-chip__what"><?php echo esc_html( $what ); ?></span>
		<span class="gwcvt-chip__fill"><?php echo esc_html( $fill ); ?></span>
	</a>
	<?php
}

/**
 * What each colour means, in words.
 *
 * Not decoration. The tint answers "which Saturday is in trouble" at a glance
 * for people who can see it; this answers it for everybody else, and it is why
 * the plugin is allowed to use colour here at all.
 */
function gwc_vt_render_month_legend(): void {
	/* 'full' and 'logged' share a tint and share an entry, which is the one
	 * place the six states become five words. */
	$entries = array(
		'short'     => gwc_vt_shift_state_label( 'short' ),
		'ok'        => gwc_vt_shift_state_label( 'ok' ),
		'full'      => __( 'Full, or hours logged', 'groundwork-common-volunteer-tracker' ),
		'awaiting'  => gwc_vt_shift_state_label( 'awaiting' ),
		'cancelled' => gwc_vt_shift_state_label( 'cancelled' ),
	);
	?>
	<p class="gwcvt-month__legend">
		<?php foreach ( $entries as $state => $label ) : ?>
			<span>
				<span class="gwcvt-month__swatch gwcvt-month__swatch--<?php echo esc_attr( $state ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $label ); ?>
			</span>
		<?php endforeach; ?>
	</p>
	<?php
}

/**
 * One row of the schedule.
 *
 * Takes the row rather than an ID because the row already carries its state —
 * gwc_vt_schedule_rows() worked it out, the filter chips counted it, and
 * deriving it a second time here would both risk disagreeing with the chip
 * above and count every signup on the page twice. gwc_vt_shift_filled() is a
 * get_posts() over the roster with no memo behind it, and this screen draws two
 * hundred rows.
 *
 * @param array  $row  One row from gwc_vt_schedule_rows(), possibly folded.
 * @param string $base The screen's own URL.
 */
function gwc_vt_render_schedule_row( array $row, string $base ): void {
	$shift_id  = (int) $row['id'];
	$state     = (string) ( $row['state'] ?? gwc_vt_shift_state( $shift_id ) );
	$folded    = (int) ( $row['folded'] ?? 0 );
	$folded_to = (string) ( $row['folded_to'] ?? '' );

	$ended      = gwc_vt_shift_has_ended( $shift_id );
	$reconciled = gwc_vt_shift_is_reconciled( $shift_id );
	$filled     = gwc_vt_shift_filled( $shift_id );
	$edit_url   = add_query_arg( 'shift', $shift_id, $base );

	$cancelled = 'cancelled' === $state;
	$awaiting  = 'awaiting' === $state;

	/* Short of people, and still able to do something about it. The state
	 * already carries that second half — a shift that has ended is never
	 * 'short', because being short of people last Saturday is not something
	 * anybody can act on. */
	$understaffed = 'short' === $state;

	$classes = array( 'gwcvt-schedule__row' );

	/* Only the three that have a rule in admin.css. 'ok', 'full' and 'logged'
	 * are the ordinary row, which is what they have always looked like here —
	 * the tinted chip states belong to the calendar and the week strip. */
	if ( in_array( $state, array( 'cancelled', 'awaiting', 'short' ), true ) ) {
		$classes[] = 'gwcvt-schedule__row--' . $state;
	}
	?>
	<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<td>
			<strong><?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?></strong><br />
			<?php if ( $folded > 1 && '' !== $folded_to ) : ?>
				<span class="gwcvt-schedule__time">
					<?php
					printf(
						/* translators: %s: the last date of the run this row stands for. */
						esc_html__( 'through %s', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwc_vt_shift_date_label_from( $folded_to ) )
					);
					?>
				</span>
			<?php else : ?>
				<span class="gwcvt-schedule__time"><?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<a class="row-title" href="<?php echo esc_url( $edit_url ); ?>">
				<?php echo esc_html( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) ); ?>
			</a>

			<?php if ( $cancelled ) : ?>
				<span class="gwcvt-badge gwcvt-badge--cancelled"><?php esc_html_e( 'Canceled', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php elseif ( 'draft' === get_post_status( $shift_id ) ) : ?>
				<span class="gwcvt-badge"><?php esc_html_e( 'Not published', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php elseif ( $awaiting ) : ?>
				<a class="gwcvt-badge gwcvt-badge--waiting gwcvt-badge--action" href="<?php echo esc_url( gwc_vt_shift_log_url( $shift_id ) ); ?>">
					<?php esc_html_e( 'Hours not logged', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php elseif ( $reconciled ) : ?>
				<span class="gwcvt-badge gwcvt-badge--verified"><?php esc_html_e( 'Hours logged', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>

			<?php
			/* A folded run says what it stands for instead of what repeat it
			 * came from: "these six are all called off" is the news, and the
			 * pattern behind them is not what anybody is reading the line for.
			 *
			 * Deliberately NOT "one decision called off all six" — which is what
			 * the design drew, and which this plugin cannot honestly say.
			 * Cancelling six occurrences is six separate actions today; there is
			 * no series-level call-off to have been the one decision. */
			if ( $folded > 1 ) :
				?>
				<div class="gwcvt-schedule__repeat">
					<?php
					printf(
						/* translators: %d: how many adjacent cancelled occurrences are drawn as this one row. */
						esc_html( _n( 'Called off %d time in a row · folded here', 'Called off %d times in a row · folded here', $folded, 'groundwork-common-volunteer-tracker' ) ),
						(int) $folded
					);
					?>
				</div>
				<?php
			else :
				/* What repeat this came from, when it came from one. Under the
				 * activity rather than beside it, because it is about the row's
				 * relationship to the other rows rather than about this shift. */
				$repeat = gwc_vt_shift_repeat_note( $shift_id );

				if ( '' !== $repeat ) :
					?>
					<div class="gwcvt-schedule__repeat"><?php echo esc_html( $repeat ); ?></div>
					<?php
				endif;
			endif;
			?>

			<div class="row-actions">
				<span class="edit">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit and roster', 'groundwork-common-volunteer-tracker' ); ?></a>
				</span>
				<?php
				/* Folding hides rows, so the way back has to be on the row that
				 * hid them — and a plain link, because a coordinator who needs
				 * to reach the third of six cancelled Saturdays should not need
				 * JavaScript to get there. */
				if ( $folded > 1 ) :
					?>
					|
					<span class="view">
						<a href="<?php echo esc_url( gwc_vt_schedule_unfold_url( $base ) ); ?>">
							<?php esc_html_e( 'Show them separately', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</span>
				<?php endif; ?>
				<?php if ( $ended && ! $cancelled ) : ?>
					|
					<span class="gwcvt-log">
						<a href="<?php echo esc_url( gwc_vt_shift_log_url( $shift_id ) ); ?>">
							<?php
							echo $reconciled
								? esc_html__( 'Log more hours', 'groundwork-common-volunteer-tracker' )
								: esc_html__( 'Log the hours', 'groundwork-common-volunteer-tracker' );
							?>
						</a>
					</span>
				<?php endif; ?>
				<?php if ( $filled > 0 ) : ?>
					|
					<span class="view">
						<a href="<?php echo esc_url( gwc_vt_roster_print_url( $shift_id ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</span>
				<?php endif; ?>
			</div>
		</td>
		<td><?php echo esc_html( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ) ); ?></td>
		<td>
			<?php if ( $understaffed ) : ?>
				<strong class="gwcvt-schedule__short">
					<?php echo esc_html( gwc_vt_shift_fill_label( $shift_id ) ); ?>
				</strong>
				<span class="screen-reader-text"><?php esc_html_e( 'Short of people', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php else : ?>
				<?php echo esc_html( gwc_vt_shift_fill_label( $shift_id ) ); ?>
			<?php endif; ?>

			<?php
			$waiting = count( gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) ) );

			if ( $waiting > 0 ) :
				?>
				<br />
				<span class="description">
					<?php
					printf(
						/* translators: %d: how many people are on the waiting list. */
						esc_html( _n( '%d waiting', '%d waiting', $waiting, 'groundwork-common-volunteer-tracker' ) ),
						(int) $waiting
					);
					?>
				</span>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Say what the last action did.
 */
function gwc_vt_schedule_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwc_vt_shift_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_shift_result'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$count = isset( $_GET['gwc_vt_count'] ) ? absint( wp_unslash( $_GET['gwc_vt_count'] ) ) : 0;

	/* Refusals belong here and not in $messages below, because everything below
	 * reads as something having happened. Three handlers used to redirect a
	 * refusal with 'saved', so a delete that was declined because somebody had
	 * just signed up reported "Shift saved." for an operation that changed
	 * nothing — and the event screens got this right with 'has-roster' all
	 * along. A screen that reports success for a refusal is worse than one that
	 * says nothing: the coordinator leaves believing the shift is gone. */
	$errors = array(
		'bad-date'     => __( 'Give the shift a date it can happen on. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'bad-time'     => __( 'Give a start and an end time, with the end after the start. For a shift that runs past midnight, select “ends the next day”. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'no-dates'     => __( 'That repeat did not land on any dates. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'not-found'    => __( 'That shift no longer exists.', 'groundwork-common-volunteer-tracker' ),
		'has-roster'   => __( 'People have signed up, so this can be called off but not deleted.', 'groundwork-common-volunteer-tracker' ),
		'no-volunteer' => __( 'Choose somebody to add. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $errors[ $result ] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $result ] ) );
		return;
	}

	/* 'promoted' is here because gwc_vt_handle_signup_promote() redirects with it
	 * on either screen — gwc_vt_event_roster_redirect() for a slot on an event,
	 * gwc_vt_shift_redirect() for a standalone shift. Only the event map had it,
	 * so promoting somebody on a standalone shift fell through the lookup below
	 * and the page rendered with no confirmation at all. */
	$messages = array(
		'saved'     => __( 'Shift saved.', 'groundwork-common-volunteer-tracker' ),
		'cancelled' => __( 'Shift canceled. It stays on the schedule so everybody can see it was called off.', 'groundwork-common-volunteer-tracker' ),
		'deleted'   => __( 'Shift deleted.', 'groundwork-common-volunteer-tracker' ),
		'rostered'  => __( 'Added to the shift.', 'groundwork-common-volunteer-tracker' ),
		'removed'   => __( 'Taken off the shift.', 'groundwork-common-volunteer-tracker' ),
		'promoted'  => __( 'They have a place now.', 'groundwork-common-volunteer-tracker' ),
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$told = isset( $_GET['gwc_vt_told'] ) ? absint( wp_unslash( $_GET['gwc_vt_told'] ) ) : 0;

	if ( 'created' === $result ) {
		$message = sprintf(
			/* translators: %d: how many shifts were created. */
			_n( '%d shift added to the schedule.', '%d shifts added to the schedule.', $count, 'groundwork-common-volunteer-tracker' ),
			$count
		);
	} elseif ( isset( $messages[ $result ] ) ) {
		$message = $messages[ $result ];
	} else {
		return;
	}

	/* Said plainly, because email that left the site is the kind of thing
	 * somebody needs to know happened rather than discover from a reply. */
	if ( $told > 0 ) {
		$message .= ' ' . sprintf(
			/* translators: %d: how many people were emailed. */
			_n( '%d person was emailed.', '%d people were emailed.', $told, 'groundwork-common-volunteer-tracker' ),
			$told
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$capped = isset( $_GET['gwc_vt_capped'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_capped'] ) ) : '';

	/* A run that was truncated says so. A screen that quietly makes fewer shifts
	 * than somebody asked for is a screen that loses them a month of Saturdays,
	 * and they find out when nobody turns up.
	 *
	 * The two sentences live in inc/recurrence.php beside the constants they
	 * quote, because the preview on the add-a-shift form says the same thing
	 * before the save and the two must not drift. */
	$note = gwc_vt_recurrence_capped_note( $capped );

	if ( '' !== $note ) {
		$message .= ' ' . $note;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( '' !== $capped ? 'warning' : 'success' ),
		esc_html( $message )
	);
}

/* ── The shifts nobody has typed up ──────────────────────────────────────────
 * A shift that has happened and whose hours have never been logged is the one
 * failure mode this whole feature can introduce. Before the schedule existed, a
 * Saturday nobody typed up was a Saturday nobody had a record of, and the
 * absence was the only evidence. Now there IS a record saying six people were
 * expected — so an unreconciled shift is the plugin knowing something happened
 * and holding no hours for it, which is worse than not knowing, because a letter
 * produced in that state is quietly short.
 *
 * Hence a nag, in the shape of the triage notice next door: on the screen where
 * the work happens, saying plainly what is waiting, with the link that starts
 * it. Not on the schedule's own past view, which is already the queue.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Say plainly that hours are waiting to be typed up.
 */
function gwc_vt_unreconciled_notice(): void {
	if ( ! gwc_vt_shifts_enabled() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'edit-' . GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	$waiting = gwc_vt_unreconciled_shift_ids( 20 );

	if ( ! $waiting ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of shifts. */
				_n(
					'%d shift has happened and its hours have not been logged yet.',
					'%d shifts have happened and their hours have not been logged yet.',
					count( $waiting ),
					'groundwork-common-volunteer-tracker'
				),
				count( $waiting )
			)
		),
		esc_url( gwc_vt_shift_log_url( (int) $waiting[0] ) ),
		esc_html__( 'Log them now', 'groundwork-common-volunteer-tracker' )
	);
}


/* ── Events on the schedule ──────────────────────────────────────────────────
 * An event appears in the flat list as one row rather than as its six slots.
 * Interleaving a festival's times among next Saturday's ordinary shifts buries
 * both of them; leaving the event out entirely is a list that lies by omission.
 * A summary row that links through is the middle.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Merge standalone shifts and events into one list, ordered by when they are.
 *
 * Each row carries its state, worked out once here rather than by whichever
 * screen happens to be drawing it. The filter chips count these rows and the
 * list draws these rows, which is the only way a chip that says seven and a
 * list that shows six cannot happen.
 *
 * @param int[] $shifts Standalone shift post IDs.
 * @param int[] $events Event post IDs.
 * @return array<int, array{type: string, id: int, date: string, state: string}>
 */
function gwc_vt_schedule_rows( array $shifts, array $events ): array {
	$rows = array();

	foreach ( $shifts as $shift_id ) {
		$rows[] = array(
			'type'  => 'shift',
			'id'    => (int) $shift_id,
			'date'  => (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ),
			'state' => gwc_vt_shift_state( (int) $shift_id ),
		);
	}

	foreach ( $events as $event_id ) {
		$rows[] = array(
			'type'  => 'event',
			'id'    => (int) $event_id,
			'date'  => (string) get_post_meta( $event_id, GWC_VT_EVENT_DATE, true ),
			'state' => gwc_vt_event_state( (int) $event_id ),
		);
	}

	usort(
		$rows,
		static function ( array $a, array $b ) {
			$compared = strcmp( $a['date'], $b['date'] );

			return 0 !== $compared ? $compared : ( $a['id'] <=> $b['id'] );
		}
	);

	return $rows;
}

/* ── Folding a fortnight of called-off Saturdays into one line ───────────────
 * Cancelling a repeat means cancelling each of its occurrences, and each one is
 * a row. Six called-off "Holiday distribution" shifts put six struck-through
 * lines between the 16th and the 23rd, and everything a coordinator actually
 * has to do that fortnight sits underneath them.
 *
 * The rows are still there — nothing is filtered away, and a called-off shift is
 * an answer this organization owes whoever signed up for it. They are drawn as
 * one line with a count, and one link puts them back.
 *
 * CONSECUTIVE, and only consecutive. A cancelled Saturday in October and
 * another in December are two separate pieces of news, and a fold that reached
 * across the live shifts between them would be hiding one of them rather than
 * tidying it. Adjacency is measured in the ordered row list, which is the same
 * order the screen prints, so what folds is always what would have printed
 * together.
 *
 * A transform over the finished row list, deliberately: anything counted from
 * $rows before this runs — the filter chips in the redesign say "Called off ·
 * 6" — counts occurrences, which is what somebody asking that question means.
 * Folding is how many lines get drawn, not how many shifts there are.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Collapse runs of adjacent cancelled shifts from one repeat.
 *
 * @param array[] $rows As gwc_vt_schedule_rows() returns them.
 * @return array[] The same rows, with a 'folded' count on the survivors.
 */
function gwc_vt_fold_cancelled_repeats( array $rows ): array {
	$folded = array();
	$run    = array();
	$series = 0;

	/**
	 * Close the run in progress, if there is one worth folding.
	 */
	$flush = static function () use ( &$folded, &$run, &$series ): void {
		if ( count( $run ) > 1 ) {
			$first              = $run[0];
			$first['folded']    = count( $run );
			$first['folded_to'] = (string) $run[ count( $run ) - 1 ]['date'];
			$folded[]           = $first;
		} else {
			foreach ( $run as $one ) {
				$folded[] = $one;
			}
		}

		$run    = array();
		$series = 0;
	};

	foreach ( $rows as $row ) {
		$is_shift = 'shift' === ( $row['type'] ?? '' );
		$id       = (int) ( $row['id'] ?? 0 );

		$row_series = $is_shift && gwc_vt_shift_is_cancelled( $id )
			? (int) get_post_meta( $id, GWC_VT_SHIFT_SERIES, true )
			: 0;

		if ( $row_series < 1 ) {
			$flush();
			$folded[] = $row;
			continue;
		}

		if ( $row_series !== $series ) {
			$flush();
			$series = $row_series;
		}

		$run[] = $row;
	}

	$flush();

	return $folded;
}

/* ── Narrowing the schedule ──────────────────────────────────────────────────
 * "Which shift?" used to mean scrolling. Two plain GET parameters now answer
 * it: `gwc_vt_state` for the four states a coordinator hunts for, and `s` for a
 * word.
 *
 * Both are applied to the rows AFTER gwc_vt_schedule_rows() has built them and
 * BEFORE anything is counted or drawn, so the chip that says "Short of people ·
 * 7" and the seven rows underneath it come from one array. A count and the
 * screen it links to coming from one function is a rule this plugin already
 * has, and this is the same rule inside one screen.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The states the filter chips offer, in the order they appear. */
const GWC_VT_SCHEDULE_FILTERS = array( 'short', 'awaiting', 'full', 'cancelled' );

/* How many chips one day cell draws before it starts counting instead.
 * Three fits a 92px cell without the cell growing, and a month whose every
 * Saturday is four shifts tall is a month you have to scroll to read — which is
 * the thing a calendar is supposed to save you from. */
const GWC_VT_MONTH_CHIPS = 3;

/**
 * Which state the list is filtered to, or '' for all of them.
 *
 * @return string
 */
function gwc_vt_schedule_filter(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; narrows which rows are drawn.
	$wanted = isset( $_GET['gwc_vt_state'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_state'] ) ) : '';

	return in_array( $wanted, GWC_VT_SCHEDULE_FILTERS, true ) ? $wanted : '';
}

/**
 * One day, when the calendar sent somebody to see the rest of it.
 *
 * The month's cells cap at GWC_VT_MONTH_CHIPS and count the remainder; this is
 * where that count goes. The list is the view that copes with eleven shifts on
 * one Saturday, which is most of why it stayed.
 *
 * @return string Y-m-d, or ''.
 */
function gwc_vt_schedule_day(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; narrows which rows are drawn.
	$wanted = isset( $_GET['gwc_vt_on'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_on'] ) ) : '';

	return gwc_vt_sanitize_date( $wanted );
}

/**
 * What was typed into the find box.
 *
 * @return string
 */
function gwc_vt_schedule_search(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; narrows which rows are drawn.
	return isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
}

/**
 * The shift IDs somebody's name puts on the schedule.
 *
 * Two queries whatever the size of the schedule: find the volunteers whose name
 * matches, then find the signups pointing at them and read their parent. The
 * obvious implementation — walk every shift on the page and read its roster —
 * is a query per row, and the roster is the one thing on this screen that is
 * not already in the meta cache.
 *
 * Unattached signups are searched by the name somebody typed into the public
 * form, because "which shift is that person on" is the same question whether or
 * not anybody has matched them to a record yet.
 *
 * @param string $term What was typed.
 * @return int[] Shift post IDs, unordered and possibly empty.
 */
function gwc_vt_schedule_shift_ids_for_person( string $term ): array {
	if ( '' === $term ) {
		return array();
	}

	$volunteers = get_posts(
		array(
			'post_type'              => GWC_VT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the volunteers whose name matches one search term. 200 is a ceiling on how many people one word can put on the schedule, not a page; the query is ids-only with no_found_rows and both caches off.
			'posts_per_page'         => 200,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			's'                      => $term,
		)
	);

	$signups = array();

	if ( $volunteers ) {
		$signups = get_posts(
			array(
				'post_type'              => GWC_VT_SIGNUP_TYPE,
				'post_status'            => array( 'publish', GWC_VT_SIGNUP_WAITLIST ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the signups belonging to the volunteers one search matched, across the whole schedule. A bound rather than a page: the result is collapsed to a set of parent IDs, and a search that reached this many rows has already told the coordinator their term was too broad.
				'posts_per_page'         => 500,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the volunteer a signup points at is meta; there is no other way to ask which shifts somebody is on.
					array(
						'key'     => GWC_VT_SIGNUP_VOLUNTEER,
						'value'   => array_map( 'intval', (array) $volunteers ),
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/* And the ones nobody has matched to a record yet, by what they typed. */
	$claimed = get_posts(
		array(
			'post_type'              => GWC_VT_SIGNUP_TYPE,
			'post_status'            => array( 'publish', GWC_VT_SIGNUP_WAITLIST ),
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the unmatched signups whose typed name contains the search term. A bound rather than a page, for the reason the query above carries one: the result is collapsed to a set of parent IDs.
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above; the typed name is meta too.
				array(
					'key'     => GWC_VT_SIGNUP_CLAIM_NAME,
					'value'   => $term,
					'compare' => 'LIKE',
				),
			),
		)
	);

	$shift_ids = array();

	foreach ( array_merge( (array) $signups, (array) $claimed ) as $signup ) {
		$parent = (int) ( $signup->post_parent ?? 0 );

		if ( $parent > 0 ) {
			$shift_ids[ $parent ] = $parent;
		}
	}

	return array_values( $shift_ids );
}

/**
 * Narrow the rows to a state, a search term, or both.
 *
 * @param array[] $rows   As gwc_vt_schedule_rows() returns them.
 * @param string  $state  A key from GWC_VT_SCHEDULE_FILTERS, or ''.
 * @param string  $term   What was typed into the find box, or ''.
 * @return array[]
 */
function gwc_vt_filter_schedule_rows( array $rows, string $state, string $term ): array {
	if ( '' !== $state ) {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $state ): bool {
					return ( $row['state'] ?? '' ) === $state;
				}
			)
		);
	}

	if ( '' === $term ) {
		return $rows;
	}

	/* Worked out once for the whole list rather than per row — see the note on
	 * gwc_vt_schedule_shift_ids_for_person(). */
	$by_person = array_flip( gwc_vt_schedule_shift_ids_for_person( $term ) );

	return array_values(
		array_filter(
			$rows,
			static function ( array $row ) use ( $term, $by_person ): bool {
				$id = (int) ( $row['id'] ?? 0 );

				if ( isset( $by_person[ $id ] ) ) {
					return true;
				}

				/* An event has no roster of its own; somebody is on one of its
				 * times, and those are shifts with the event as their parent. */
				if ( 'event' === ( $row['type'] ?? '' ) ) {
					foreach ( gwc_vt_event_slot_ids( $id ) as $slot_id ) {
						if ( isset( $by_person[ (int) $slot_id ] ) ) {
							return true;
						}
					}

					return gwc_vt_schedule_row_matches_words( $row, $term );
				}

				return gwc_vt_schedule_row_matches_words( $row, $term );
			}
		)
	);
}

/**
 * Whether a row's own words contain the term.
 *
 * Activity and location for a shift, the title for an event. Case-insensitive
 * and anywhere in the string, which is what somebody typing three letters of
 * "warehouse" expects.
 *
 * @param array  $row  One row.
 * @param string $term What was typed.
 * @return bool
 */
function gwc_vt_schedule_row_matches_words( array $row, string $term ): bool {
	$id = (int) ( $row['id'] ?? 0 );

	$haystacks = 'event' === ( $row['type'] ?? '' )
		? array( (string) get_the_title( $id ), (string) get_post_meta( $id, GWC_VT_EVENT_LOCATION, true ) )
		: array(
			(string) get_post_meta( $id, GWC_VT_SHIFT_ACTIVITY, true ),
			(string) get_post_meta( $id, GWC_VT_SHIFT_LOCATION, true ),
		);

	foreach ( $haystacks as $haystack ) {
		if ( '' !== $haystack && false !== stripos( $haystack, $term ) ) {
			return true;
		}
	}

	return false;
}

/**
 * How many rows are in each state.
 *
 * Counted from the rows the screen is about to draw, which is the whole point:
 * a chip reading "Called off · 6" over five rows is the bug this plugin has a
 * rule about. The search narrows the rows before this runs, so the chips count
 * within a search rather than describing a list nobody is looking at.
 *
 * @param array[] $rows As gwc_vt_schedule_rows() returns them.
 * @return array<string, int>
 */
function gwc_vt_schedule_state_counts( array $rows ): array {
	$counts = array_fill_keys( GWC_VT_SCHEDULE_FILTERS, 0 );

	foreach ( $rows as $row ) {
		$state = (string) ( $row['state'] ?? '' );

		if ( isset( $counts[ $state ] ) ) {
			++$counts[ $state ];
		}
	}

	return $counts;
}

/**
 * Which group a date belongs to: this week, next week, or its month.
 *
 * The dashboard's fortnight, extended. gwc_vt_fortnight_bounds() gives the two
 * boundaries and respects the site's start_of_week, which is the site's
 * business and not this plugin's — a great many places outside Europe and North
 * America start theirs on Saturday.
 *
 * The past view gets month names throughout. "This week" reading backwards
 * would be a heading over Tuesday and Monday in that order, which is a frame
 * that makes the list harder to read rather than easier.
 *
 * @param string $date Y-m-d.
 * @param string $when 'past' or 'upcoming'.
 * @return string
 */
function gwc_vt_schedule_group_label( string $date, string $when ): string {
	$parsed = gwc_vt_recurrence_date( $date );

	if ( null === $parsed ) {
		return __( 'Undated', 'groundwork-common-volunteer-tracker' );
	}

	/* F Y rather than the site's date format: this is a month, not a day, and
	 * running a Y-m-d format string over it would print a heading reading
	 * "2026-08-01" above every shift in August. */
	$month = (string) wp_date( 'F Y', $parsed->getTimestamp(), new DateTimeZone( 'UTC' ) );

	if ( 'past' === $when ) {
		return $month;
	}

	$bounds = gwc_vt_fortnight_bounds( gwc_vt_today(), (int) get_option( 'start_of_week' ) );

	if ( $date <= $bounds['this_week'] ) {
		return __( 'This week', 'groundwork-common-volunteer-tracker' );
	}

	if ( $date <= $bounds['fortnight'] ) {
		return __( 'Next week', 'groundwork-common-volunteer-tracker' );
	}

	return $month;
}

/**
 * Month | List, as two links.
 *
 * A GET parameter rather than a stored preference, unlike the dashboard's
 * Week | List. This screen's views are already GET — `view=events`,
 * `when=past` — and the month view carries a month in the query besides, so a
 * remembered view and a navigated month would be two different mechanisms
 * deciding what one screen shows.
 *
 * @param string $base The screen's own URL.
 * @param string $view Which one is showing, 'month' or 'list'.
 */
function gwc_vt_render_schedule_view_tabs( string $base, string $view ): void {
	$options = array(
		'list'  => __( 'List', 'groundwork-common-volunteer-tracker' ),
		'month' => __( 'Month', 'groundwork-common-volunteer-tracker' ),
	);

	/* Switching views keeps the filter and the search, and nothing else. The
	 * month a calendar was on has no meaning in a list, and the past/future
	 * halves of the list have none in a calendar. */
	$keep = array();

	$state = gwc_vt_schedule_filter();
	$term  = gwc_vt_schedule_search();

	if ( '' !== $state ) {
		$keep['gwc_vt_state'] = $state;
	}

	if ( '' !== $term ) {
		$keep['s'] = $term;
	}

	$carrier = add_query_arg( $keep, $base );
	?>
	<span class="gwcvt-segmented gwcvt-schedule__views">
		<?php foreach ( $options as $key => $label ) : ?>
			<?php if ( $key === $view ) : ?>
				<span class="gwcvt-segmented__on" aria-current="true"><?php echo esc_html( $label ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( 'month' === $key ? add_query_arg( 'view', 'month', $carrier ) : $carrier ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endif; ?>
		<?php endforeach; ?>
	</span>
	<?php
}

/**
 * The filter chips and the find box.
 *
 * @param string             $base   The screen's own URL.
 * @param array<string, int> $counts How many rows are in each state.
 * @param string             $state  Which state is active, or ''.
 * @param string             $term   What is in the find box.
 * @param string             $when   'past' or 'upcoming'.
 * @param string             $view   'list' or 'month'.
 */
function gwc_vt_render_schedule_filters( string $base, array $counts, string $state, string $term, string $when, string $view = 'list' ): void {
	$labels = gwc_vt_shift_state_labels();

	/* Every link keeps the half of the query it is not about: switching a chip
	 * must not silently drop a search, and neither may throw you back to the
	 * upcoming view from the past one. */
	$keep = array();

	if ( 'month' === $view ) {
		$keep['view'] = 'month';

		/* The month being looked at, so clicking a chip narrows THIS month
		 * rather than sending somebody back to today. */
		$keep['gwc_vt_month'] = gwc_vt_schedule_month();
	} elseif ( 'past' === $when ) {
		$keep['when'] = 'past';
	}

	if ( '' !== $term ) {
		$keep['s'] = $term;
	}

	$carrier = add_query_arg( $keep, $base );
	?>
	<div class="gwcvt-schedule__filters">
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="gwcvt-schedule__find">
			<?php
			/* Every GET parameter that is not the search has to be re-posted as
			 * a hidden field, because a GET form replaces the query string
			 * rather than adding to it — a search from the past view would
			 * otherwise land on the upcoming one. */
			?>
			<input type="hidden" name="post_type" value="<?php echo esc_attr( GWC_VT_ENTRY_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( GWC_VT_SCHEDULE_PAGE ); ?>" />
			<?php if ( 'month' === $view ) : ?>
				<input type="hidden" name="view" value="month" />
				<input type="hidden" name="gwc_vt_month" value="<?php echo esc_attr( gwc_vt_schedule_month() ); ?>" />
			<?php elseif ( 'past' === $when ) : ?>
				<input type="hidden" name="when" value="past" />
			<?php endif; ?>
			<?php if ( '' !== $state ) : ?>
				<input type="hidden" name="gwc_vt_state" value="<?php echo esc_attr( $state ); ?>" />
			<?php endif; ?>

			<label class="screen-reader-text" for="gwcvt-schedule-search">
				<?php esc_html_e( 'Find a shift', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input
				type="search"
				id="gwcvt-schedule-search"
				name="s"
				value="<?php echo esc_attr( $term ); ?>"
				placeholder="<?php esc_attr_e( 'Find a shift — activity, place, or person', 'groundwork-common-volunteer-tracker' ); ?>"
			/>
			<button type="submit" class="button"><?php esc_html_e( 'Find', 'groundwork-common-volunteer-tracker' ); ?></button>

			<?php if ( '' !== $term ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( 's', $carrier ) ); ?>" class="gwcvt-schedule__clear">
					<?php esc_html_e( 'Clear', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<div class="gwcvt-schedule__chips">
			<?php
			foreach ( GWC_VT_SCHEDULE_FILTERS as $key ) :
				$count  = (int) ( $counts[ $key ] ?? 0 );
				$active = $key === $state;

				/* A chip with nothing behind it is not offered — except the one
				 * that is currently on, which has to stay clickable or there is
				 * no way back to the whole list. */
				if ( 0 === $count && ! $active ) {
					continue;
				}

				$url = $active
					? remove_query_arg( 'gwc_vt_state', $carrier )
					: add_query_arg( 'gwc_vt_state', $key, $carrier );
				?>
				<a
					href="<?php echo esc_url( $url ); ?>"
					class="gwcvt-chip-filter<?php echo $active ? ' gwcvt-chip-filter--on gwcvt-chip-filter--' . esc_attr( $key ) : ''; ?>"
					<?php echo $active ? 'aria-current="true"' : ''; ?>
				>
					<?php
					printf(
						/* translators: 1: the state, such as "Short of people". 2: how many. */
						esc_html__( '%1$s · %2$d', 'groundwork-common-volunteer-tracker' ),
						esc_html( (string) ( $labels[ $key ] ?? $key ) ),
						(int) $count
					);

					if ( $active ) {
						echo ' <span aria-hidden="true">&times;</span>';
						echo '<span class="screen-reader-text"> ' . esc_html__( '— clear this filter', 'groundwork-common-volunteer-tracker' ) . '</span>';
					}
					?>
				</a>
				<?php
			endforeach;
			?>
		</div>
	</div>
	<?php
}

/**
 * Whether the list should draw every cancelled occurrence separately.
 *
 * A plain GET parameter, so "show them separately" is a link rather than a
 * script, and so the unfolded view is somewhere a coordinator can be sent.
 *
 * @return bool
 */
function gwc_vt_schedule_is_unfolded(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; chooses how many rows to draw.
	return isset( $_GET['gwc_vt_unfold'] ) && '1' === $_GET['gwc_vt_unfold'];
}

/* ── A rota is a calendar-shaped question ────────────────────────────────────
 * The list answers "what is on" one row at a time. A month answers "which
 * Saturday is in trouble" without reading a single row, because the Saturday in
 * trouble is the red one — the same chips the dashboard's week strip draws, in
 * the shape a coordinator already has on the wall.
 *
 * Server-rendered HTML, all of it. A grid of anchors needs no script to draw
 * and none to use; only the drawer in #95 will.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A month as whole weeks of seven days.
 *
 * Pure calendar arithmetic in UTC, the same way inc/recurrence.php works and for
 * the same reason: these are dates, not instants, and a bare Y-m-d has the same
 * weekday everywhere.
 *
 * The grid opens on the site's own first day of the week, not on Sunday — the
 * weekday header rotates with it, because a calendar whose columns start on a
 * different day from the one on the wall is one somebody has to translate every
 * time they read it.
 *
 * Only as many weeks as the month needs, rather than a fixed six. A trailing
 * row of nothing but greyed-out numbers is a row of the screen spent saying
 * that August is over.
 *
 * @param string $month         Y-m. Anything unreadable falls back to this month.
 * @param int    $start_of_week 0 for Sunday through 6 for Saturday, as WordPress stores it.
 * @param string $today         Y-m-d, so the caller decides what "today" means.
 * @return array<int, array<int, array{date:string, in_month:bool, today:bool}>>
 */
function gwc_vt_month_grid( string $month, int $start_of_week, string $today ): array {
	$first = (int) strtotime( $month . '-01 00:00:00 UTC' );

	if ( ! $first ) {
		$first = (int) strtotime( gmdate( 'Y-m', gwc_vt_midnight_utc( $today ) ) . '-01 00:00:00 UTC' );
	}

	$start_of_week = ( ( $start_of_week % 7 ) + 7 ) % 7;
	$key           = gmdate( 'Y-m', $first );

	/* How far into its week the first of the month already is. That many cells
	 * of the previous month open the grid. */
	$leading = ( (int) gmdate( 'w', $first ) - $start_of_week + 7 ) % 7;
	$length  = (int) gmdate( 't', $first );
	$cells   = (int) ( ceil( ( $leading + $length ) / 7 ) * 7 );

	$opening = $first - ( $leading * DAY_IN_SECONDS );
	$weeks   = array();

	for ( $cell = 0; $cell < $cells; $cell++ ) {
		$stamp = $opening + ( $cell * DAY_IN_SECONDS );
		$date  = gmdate( 'Y-m-d', $stamp );

		$weeks[ (int) floor( $cell / 7 ) ][] = array(
			'date'     => $date,
			'in_month' => gmdate( 'Y-m', $stamp ) === $key,
			'today'    => $date === $today,
		);
	}

	return array_values( $weeks );
}

/**
 * The weekday names, rotated to start on the site's own first day.
 *
 * Short forms from WordPress's own translations rather than a table here, so a
 * German site gets Mo Di Mi and not a transliteration of Mon Tue Wed.
 *
 * @param int $start_of_week 0 for Sunday through 6 for Saturday.
 * @return string[] Seven names, in column order.
 */
function gwc_vt_weekday_initials( int $start_of_week ): array {
	global $wp_locale;

	$start_of_week = ( ( $start_of_week % 7 ) + 7 ) % 7;
	$names         = array();

	for ( $offset = 0; $offset < 7; $offset++ ) {
		$weekday = ( $start_of_week + $offset ) % 7;

		$names[] = $wp_locale instanceof WP_Locale
			? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $weekday ) )
			: gmdate( 'D', (int) strtotime( 'sunday +' . $weekday . ' days UTC' ) );
	}

	return $names;
}

/**
 * Which month the calendar is showing.
 *
 * @return string Y-m.
 */
function gwc_vt_schedule_month(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which month to draw.
	$wanted = isset( $_GET['gwc_vt_month'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_vt_month'] ) ) : '';

	if ( preg_match( '/^\d{4}-\d{2}$/', $wanted ) && (int) substr( $wanted, 5, 2 ) >= 1 && (int) substr( $wanted, 5, 2 ) <= 12 ) {
		return $wanted;
	}

	return gmdate( 'Y-m', gwc_vt_midnight_utc( gwc_vt_today() ) );
}

/**
 * Midnight UTC on a Y-m-d, or on the real today when it cannot be read.
 *
 * Spelled out rather than written as a short ternary: `strtotime( … ) ?: time()`
 * reads as "or now" and is a different thing, because strtotime() returns false
 * for an unreadable date and 0 is a date it can read perfectly well — the first
 * of January 1970. A month view that silently opened on 1970 because somebody
 * typed a bad date into the query string is the kind of bug that gets reported
 * as "the calendar is empty".
 *
 * @param string $date Y-m-d.
 * @return int
 */
function gwc_vt_midnight_utc( string $date ): int {
	$stamp = strtotime( $date . ' 00:00:00 UTC' );

	return false === $stamp ? time() : (int) $stamp;
}

/**
 * The month before or after this one.
 *
 * @param string $month Y-m.
 * @param int    $step  -1 or 1.
 * @return string Y-m.
 */
function gwc_vt_month_step( string $month, int $step ): string {
	$first = (int) strtotime( $month . '-01 00:00:00 UTC' );

	if ( ! $first ) {
		return $month;
	}

	/* Anchored to the first of the month before adding, so a 31-day month does
	 * not have PHP roll February over into March. */
	return gmdate( 'Y-m', (int) strtotime( gmdate( 'Y-m-01', $first ) . ' ' . ( $step > 0 ? '+' : '-' ) . '1 month UTC' ) );
}

/**
 * Which half of the calendar the list is showing.
 *
 * Read here rather than in two places, because the unfold link has to keep
 * somebody where they were: following "show them separately" from the past view
 * and landing on next month's schedule is a link that loses your place.
 *
 * @return string 'past' or 'upcoming'.
 */
function gwc_vt_schedule_when(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which half of the calendar to show.
	return isset( $_GET['when'] ) && 'past' === $_GET['when'] ? 'past' : 'upcoming';
}

/**
 * Where "show them separately" goes.
 *
 * @param string $base The screen's own URL.
 * @return string
 */
function gwc_vt_schedule_unfold_url( string $base ): string {
	$url = add_query_arg( 'gwc_vt_unfold', '1', $base );

	if ( 'past' === gwc_vt_schedule_when() ) {
		$url = add_query_arg( 'when', 'past', $url );
	}

	return $url;
}

/**
 * One event, as a row on the flat schedule.
 *
 * @param int $event_id Event post ID.
 */
function gwc_vt_render_event_summary_row( int $event_id ): void {
	$cancelled = gwc_vt_event_is_cancelled( $event_id );
	$short     = $cancelled ? array() : gwc_vt_event_short_slot_ids( $event_id );
	$unlogged  = $cancelled ? array() : gwc_vt_event_unlogged_slot_ids( $event_id );
	$slots     = gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft' ) );
	$roles     = count( gwc_vt_event_roles( $event_id, array( 'publish', 'draft' ) ) );

	$classes = array( 'gwcvt-schedule__row', 'gwcvt-schedule__row--event' );

	if ( $cancelled ) {
		$classes[] = 'gwcvt-schedule__row--cancelled';
	} elseif ( $short ) {
		$classes[] = 'gwcvt-schedule__row--understaffed';
	}
	?>
	<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<td>
			<strong><?php echo esc_html( gwc_vt_event_date_label( $event_id ) ); ?></strong>
			<div class="row-actions" style="left:auto">
				<?php echo esc_html( _x( 'Event', 'a row on the schedule', 'groundwork-common-volunteer-tracker' ) ); ?>
			</div>
		</td>
		<td>
			<a href="<?php echo esc_url( gwc_vt_event_edit_url( $event_id ) ); ?>"><strong><?php echo esc_html( gwc_vt_event_name( $event_id ) ); ?></strong></a>

			<?php if ( $unlogged ) : ?>
				<?php
				/* Links to the roster rather than to a log screen, because an
				 * event has several times and there is no one of them to send
				 * somebody to. The roster lists them with a log link each. */
				?>
				<a class="gwcvt-badge gwcvt-badge--waiting gwcvt-badge--action" href="<?php echo esc_url( gwc_vt_event_roster_url( $event_id ) ); ?>">
					<?php
					printf(
						esc_html(
							/* translators: %d: how many times still need their hours logged. */
							_n( '%d time needs its hours', '%d times need their hours', count( $unlogged ), 'groundwork-common-volunteer-tracker' )
						),
						(int) count( $unlogged )
					);
					?>
				</a>
			<?php endif; ?>

			<div class="row-actions">
				<span><a href="<?php echo esc_url( gwc_vt_event_roster_url( $event_id ) ); ?>"><?php esc_html_e( 'Roster', 'groundwork-common-volunteer-tracker' ); ?></a> | </span>
				<span><a href="<?php echo esc_url( gwc_vt_event_edit_url( $event_id ) ); ?>"><?php esc_html_e( 'Edit', 'groundwork-common-volunteer-tracker' ); ?></a></span>
			</div>
			<div class="row-actions" style="left:auto">
				<?php
				printf(
					esc_html(
						/* translators: 1: how many roles, 2: how many times. */
						_n( '%1$d role, %2$d time', '%1$d roles, %2$d times', $roles, 'groundwork-common-volunteer-tracker' )
					),
					(int) $roles,
					(int) count( $slots )
				);
				?>
			</div>
		</td>
		<td><?php echo esc_html( (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ) ); ?></td>
		<td>
			<?php if ( $cancelled ) : ?>
				<strong><?php esc_html_e( 'Called off', 'groundwork-common-volunteer-tracker' ); ?></strong>
			<?php else : ?>
				<strong><?php echo esc_html( gwc_vt_event_fill_label( $event_id ) ); ?></strong>
				<?php if ( $short ) : ?>
					<div class="row-actions" style="left:auto">
						<?php
						printf(
							esc_html(
								/* translators: %d: how many times are short of people. */
								_n( '%d time is short', '%d times are short', count( $short ), 'groundwork-common-volunteer-tracker' )
							),
							(int) count( $short )
						);
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Every event, soonest first.
 *
 * A coordinator opens this to answer one question — which of these is short of
 * people, and how soon — so the fill figure is the loudest thing in the row and
 * the event's name is not.
 */
function gwc_vt_render_events_list(): void {
	$base = add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_SCHEDULE_PAGE,
		),
		admin_url( 'edit.php' )
	);

	$events = gwc_vt_events_between(
		array(
			'from'     => gmdate( 'Y-m-d', time() - ( 120 * DAY_IN_SECONDS ) ),
			'to'       => gmdate( 'Y-m-d', time() + ( 400 * DAY_IN_SECONDS ) ),
			'statuses' => array( 'publish', 'draft', GWC_VT_EVENT_CANCELLED ),
			'limit'    => 100,
		)
	);
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'groundwork-common-volunteer-tracker' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'gwc_vt_event', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add an event', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwc_vt_event_notice(); ?>

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Coming up', 'groundwork-common-volunteer-tracker' ); ?></a> |
			</li>
			<li>
				<span class="current" aria-current="page"><?php esc_html_e( 'Events', 'groundwork-common-volunteer-tracker' ); ?></span>
			</li>
		</ul>

		<table class="widefat striped gwcvt-schedule">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Places filled', 'groundwork-common-volunteer-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $events ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No events yet. An event is one occasion with several roles — a festival, a meal service, a collection drive.', 'groundwork-common-volunteer-tracker' ); ?></td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $events as $event_id ) : ?>
					<?php gwc_vt_render_event_summary_row( (int) $event_id ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * What just happened to an event, if anything.
 */
function gwc_vt_event_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a word about a completed redirect; nothing acts on it.
	$result = isset( $_GET['gwc_vt_event_result'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_event_result'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$made = isset( $_GET['gwc_vt_made'] ) ? absint( wp_unslash( $_GET['gwc_vt_made'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$cancelled = isset( $_GET['gwc_vt_cancelled'] ) ? absint( wp_unslash( $_GET['gwc_vt_cancelled'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$deleted = isset( $_GET['gwc_vt_deleted'] ) ? absint( wp_unslash( $_GET['gwc_vt_deleted'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$told = isset( $_GET['gwc_vt_told'] ) ? absint( wp_unslash( $_GET['gwc_vt_told'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$slot = isset( $_GET['gwc_vt_slot'] ) ? absint( wp_unslash( $_GET['gwc_vt_slot'] ) ) : 0;

	$messages = array(
		'saved'           => __( 'Saved.', 'groundwork-common-volunteer-tracker' ),

		/* An action says what it did, in the past tense, naming the thing it did
		 * it to. That sentence is the whole reason these moved off the form: a
		 * deferred checkbox could only describe a future, and the screen it came
		 * back to looked identical whether or not it had happened. */
		'called-off-slot' => $slot > 0
			? sprintf(
				/* translators: %s: a role and a time. */
				__( '%s was called off. It stays on the schedule rather than being deleted.', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_slot_label( $slot )
			)
			: __( 'That time was called off.', 'groundwork-common-volunteer-tracker' ),
		'restored-slot'   => $slot > 0
			? sprintf(
				/* translators: %s: a role and a time. */
				__( '%s is back on.', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_slot_label( $slot )
			)
			: __( 'That time is back on.', 'groundwork-common-volunteer-tracker' ),
		'deleted-slot'    => __( 'That time was deleted. Nobody was on it.', 'groundwork-common-volunteer-tracker' ),
		'dropped-role'    => __( 'The role is gone.', 'groundwork-common-volunteer-tracker' ),
		'unknown-role'    => __( 'That role has no times left on it.', 'groundwork-common-volunteer-tracker' ),
		'copied'          => __( 'Copied. This is the new one, saved as a draft — check the dates before you publish it.', 'groundwork-common-volunteer-tracker' ),
		'called-off'      => __( 'The event was called off, and every time on it with it.', 'groundwork-common-volunteer-tracker' ),
		'deleted'         => __( 'The event was deleted.', 'groundwork-common-volunteer-tracker' ),
		'promoted'        => __( 'They have a place now.', 'groundwork-common-volunteer-tracker' ),
		'rostered'        => __( 'They are on the list.', 'groundwork-common-volunteer-tracker' ),
		'no-title'        => __( 'An event needs a name — it is what volunteers will recognize it by.', 'groundwork-common-volunteer-tracker' ),
		'no-role'         => __( 'A role with times under it needs a name. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'bad-time'        => __( 'One of the times could not be read. A time needs a date, a start and an end, and the end must come after the start unless it runs past midnight.', 'groundwork-common-volunteer-tracker' ),
		'has-roster'      => __( 'People have signed up, so this can be called off but not deleted.', 'groundwork-common-volunteer-tracker' ),
		'no-volunteer'    => __( 'Choose somebody to add. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
		'wrong-slot'      => __( 'That time is not on this event. Nothing was changed.', 'groundwork-common-volunteer-tracker' ),
		'bad-date'        => __( 'That date could not be read.', 'groundwork-common-volunteer-tracker' ),
		'unknown'         => __( 'That event no longer exists.', 'groundwork-common-volunteer-tracker' ),
		'failed'          => __( 'That could not be saved. Please try again.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( ! isset( $messages[ $result ] ) ) {
		return;
	}

	$errors = array( 'no-title', 'no-role', 'bad-time', 'has-roster', 'bad-date', 'unknown', 'unknown-role', 'failed', 'no-volunteer', 'wrong-slot' );
	$detail = array();

	if ( $made > 0 ) {
		$detail[] = sprintf(
			/* translators: %d: how many times were added. */
			_n( '%d time added.', '%d times added.', $made, 'groundwork-common-volunteer-tracker' ),
			$made
		);
	}

	if ( $cancelled > 0 ) {
		$detail[] = sprintf(
			/* translators: %d: how many times were canceled. */
			_n( '%d time canceled — it stays on the schedule so everybody can see it was called off.', '%d times canceled — they stay on the schedule so everybody can see they were called off.', $cancelled, 'groundwork-common-volunteer-tracker' ),
			$cancelled
		);
	}

	if ( $deleted > 0 ) {
		$detail[] = sprintf(
			/* translators: %d: how many empty times were deleted. */
			_n( '%d empty time deleted.', '%d empty times deleted.', $deleted, 'groundwork-common-volunteer-tracker' ),
			$deleted
		);
	}

	if ( $told > 0 ) {
		$detail[] = sprintf(
			/* translators: %d: how many people were emailed. */
			_n( '%d person was told.', '%d people were told.', $told, 'groundwork-common-volunteer-tracker' ),
			$told
		);
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( in_array( $result, $errors, true ) ? 'error' : 'success' ),
		esc_html( trim( $messages[ $result ] . ' ' . implode( ' ', $detail ) ) )
	);
}

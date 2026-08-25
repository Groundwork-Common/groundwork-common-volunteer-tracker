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
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which half of the calendar to show.
	$when = isset( $_GET['when'] ) && 'past' === $_GET['when'] ? 'past' : 'upcoming';

	$from = 'past' === $when ? gmdate( 'Y-m-d', time() - ( 120 * DAY_IN_SECONDS ) ) : gwc_vt_today();
	$to   = 'past' === $when ? gwc_vt_today() : gmdate( 'Y-m-d', time() + ( 400 * DAY_IN_SECONDS ) );

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

	if ( 'past' === $when ) {
		$rows = array_reverse( $rows );
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
							echo 'past' === $when
								? esc_html__( 'Nothing in the last few months.', 'groundwork-common-volunteer-tracker' )
								: esc_html__( 'Nothing scheduled yet. Add a shift to get started.', 'groundwork-common-volunteer-tracker' );
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $rows as $row ) : ?>
					<?php
					if ( 'event' === $row['type'] ) {
						gwc_vt_render_event_summary_row( $row['id'] );
						continue;
					}

					gwc_vt_render_schedule_row( $row['id'], $base );
					?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * One row of the schedule.
 *
 * @param int    $shift_id Shift post ID.
 * @param string $base     The screen's own URL.
 */
function gwc_vt_render_schedule_row( int $shift_id, string $base ): void {
	$cancelled    = gwc_vt_shift_is_cancelled( $shift_id );
	$ended        = gwc_vt_shift_has_ended( $shift_id );
	$reconciled   = gwc_vt_shift_is_reconciled( $shift_id );
	$filled       = gwc_vt_shift_filled( $shift_id );
	$understaffed = ! $cancelled && ! $ended && gwc_vt_shift_is_understaffed( $shift_id );
	$edit_url     = add_query_arg( 'shift', $shift_id, $base );

	/* Waiting to be typed up: it happened, somebody was on it, and no hours came
	 * out. Understaffed stops mattering once a shift is over — being short of
	 * people last Saturday is not something anybody can act on. */
	$awaiting = $ended && ! $cancelled && ! $reconciled && $filled > 0;

	$classes = array( 'gwcvt-schedule__row' );

	if ( $cancelled ) {
		$classes[] = 'gwcvt-schedule__row--cancelled';
	} elseif ( $awaiting ) {
		$classes[] = 'gwcvt-schedule__row--awaiting';
	} elseif ( $understaffed ) {
		$classes[] = 'gwcvt-schedule__row--short';
	}
	?>
	<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<td>
			<strong><?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?></strong><br />
			<span class="gwcvt-schedule__time"><?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?></span>
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

			<div class="row-actions">
				<span class="edit">
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit and roster', 'groundwork-common-volunteer-tracker' ); ?></a>
				</span>
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
 * @param int[] $shifts Standalone shift post IDs.
 * @param int[] $events Event post IDs.
 * @return array<int, array{type: string, id: int, date: string}>
 */
function gwc_vt_schedule_rows( array $shifts, array $events ): array {
	$rows = array();

	foreach ( $shifts as $shift_id ) {
		$rows[] = array(
			'type' => 'shift',
			'id'   => (int) $shift_id,
			'date' => (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ),
		);
	}

	foreach ( $events as $event_id ) {
		$rows[] = array(
			'type' => 'event',
			'id'   => (int) $event_id,
			'date' => (string) get_post_meta( $event_id, GWC_VT_EVENT_DATE, true ),
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

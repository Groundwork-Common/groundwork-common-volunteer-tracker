<?php
/**
 * The schedule: what is coming up, and how full it is.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwcvt_register_schedule_menu', 13 );
add_action( 'admin_notices', 'gwcvt_unreconciled_notice' );

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
function gwcvt_register_schedule_menu(): void {
	if ( ! gwcvt_shifts_enabled() ) {
		return;
	}

	add_submenu_page(
		GWCVT_MENU_SLUG,
		__( 'Schedule', 'groundwork-common-volunteer-tracker' ),
		__( 'Schedule', 'groundwork-common-volunteer-tracker' ),
		'edit_posts',
		GWCVT_SCHEDULE_PAGE,
		'gwcvt_render_schedule_screen'
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
function gwcvt_shifts_enabled(): bool {
	return (bool) gwcvt_setting( 'shifts_enabled' );
}

/**
 * The screen, in whichever of its three views was asked for.
 */
function gwcvt_render_schedule_screen(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to manage the schedule.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between views.
	$wanted = isset( $_GET['shift'] ) ? sanitize_text_field( wp_unslash( $_GET['shift'] ) ) : '';

	if ( 'new' === $wanted ) {
		gwcvt_render_shift_editor( 0 );
		return;
	}

	$shift_id = absint( $wanted );

	if ( $shift_id > 0 && GWCVT_SHIFT_TYPE === get_post_type( $shift_id ) ) {
		gwcvt_render_shift_editor( $shift_id );
		return;
	}

	gwcvt_render_schedule_list();
}

/**
 * Everything coming up, soonest first.
 */
function gwcvt_render_schedule_list(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks which half of the calendar to show.
	$when = isset( $_GET['when'] ) && 'past' === $_GET['when'] ? 'past' : 'upcoming';

	$shifts = 'past' === $when
		? array_reverse(
			gwcvt_shifts_between(
				array(
					'from'     => gmdate( 'Y-m-d', time() - ( 120 * DAY_IN_SECONDS ) ),
					'to'       => gwcvt_today(),
					'statuses' => array( 'publish', 'draft', GWCVT_SHIFT_CANCELLED ),
					'limit'    => 100,
				)
			)
		)
		: gwcvt_shifts_between(
			array(
				'from'     => gwcvt_today(),
				'to'       => gmdate( 'Y-m-d', time() + ( 400 * DAY_IN_SECONDS ) ),
				'statuses' => array( 'publish', 'draft', GWCVT_SHIFT_CANCELLED ),
				'limit'    => 200,
			)
		);

	$base = add_query_arg(
		array(
			'post_type' => GWCVT_ENTRY_TYPE,
			'page'      => GWCVT_SCHEDULE_PAGE,
		),
		admin_url( 'edit.php' )
	);
	?>
	<div class="wrap gwcvt-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Schedule', 'groundwork-common-volunteer-tracker' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'shift', 'new', $base ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add a shift', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
		<hr class="wp-header-end" />

		<?php gwcvt_schedule_notice(); ?>

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( $base ); ?>" <?php echo 'upcoming' === $when ? 'class="current" aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Coming up', 'groundwork-common-volunteer-tracker' ); ?>
				</a> |
			</li>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'when', 'past', $base ) ); ?>" <?php echo 'past' === $when ? 'class="current" aria-current="page"' : ''; ?>>
					<?php esc_html_e( 'Already happened', 'groundwork-common-volunteer-tracker' ); ?>
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
				<?php if ( ! $shifts ) : ?>
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

				<?php foreach ( $shifts as $shift_id ) : ?>
					<?php gwcvt_render_schedule_row( $shift_id, $base ); ?>
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
function gwcvt_render_schedule_row( int $shift_id, string $base ): void {
	$cancelled    = gwcvt_shift_is_cancelled( $shift_id );
	$ended        = gwcvt_shift_has_ended( $shift_id );
	$reconciled   = gwcvt_shift_is_reconciled( $shift_id );
	$filled       = gwcvt_shift_filled( $shift_id );
	$understaffed = ! $cancelled && ! $ended && gwcvt_shift_is_understaffed( $shift_id );
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
			<strong><?php echo esc_html( gwcvt_shift_date_label( $shift_id ) ); ?></strong><br />
			<span class="gwcvt-schedule__time"><?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?></span>
		</td>
		<td>
			<a class="row-title" href="<?php echo esc_url( $edit_url ); ?>">
				<?php echo esc_html( (string) get_post_meta( $shift_id, GWCVT_SHIFT_ACTIVITY, true ) ); ?>
			</a>

			<?php if ( $cancelled ) : ?>
				<span class="gwcvt-badge gwcvt-badge--cancelled"><?php esc_html_e( 'Cancelled', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php elseif ( 'draft' === get_post_status( $shift_id ) ) : ?>
				<span class="gwcvt-badge"><?php esc_html_e( 'Not published', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php elseif ( $awaiting ) : ?>
				<a class="gwcvt-badge gwcvt-badge--waiting gwcvt-badge--action" href="<?php echo esc_url( gwcvt_shift_log_url( $shift_id ) ); ?>">
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
						<a href="<?php echo esc_url( gwcvt_shift_log_url( $shift_id ) ); ?>">
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
						<a href="<?php echo esc_url( gwcvt_roster_print_url( $shift_id ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</span>
				<?php endif; ?>
			</div>
		</td>
		<td><?php echo esc_html( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) ); ?></td>
		<td>
			<?php if ( $understaffed ) : ?>
				<strong class="gwcvt-schedule__short">
					<?php echo esc_html( gwcvt_shift_fill_label( $shift_id ) ); ?>
				</strong>
				<span class="screen-reader-text"><?php esc_html_e( 'Short of people', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php else : ?>
				<?php echo esc_html( gwcvt_shift_fill_label( $shift_id ) ); ?>
			<?php endif; ?>

			<?php
			$waiting = count( gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WAITLIST ) ) );

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
function gwcvt_schedule_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwcvt_shift_result'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_shift_result'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$count = isset( $_GET['gwcvt_count'] ) ? absint( wp_unslash( $_GET['gwcvt_count'] ) ) : 0;

	$errors = array(
		'bad-date'  => __( 'Give the shift a date it can happen on. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'bad-time'  => __( 'Give a start and an end time, with the end after the start. For a shift that runs past midnight, tick “ends the next day”. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'no-dates'  => __( 'That repeat did not land on any dates. Nothing was saved.', 'groundwork-common-volunteer-tracker' ),
		'not-found' => __( 'That shift no longer exists.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $errors[ $result ] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $result ] ) );
		return;
	}

	$messages = array(
		'saved'     => __( 'Shift saved.', 'groundwork-common-volunteer-tracker' ),
		'cancelled' => __( 'Shift cancelled. It stays on the schedule so everybody can see it was called off.', 'groundwork-common-volunteer-tracker' ),
		'deleted'   => __( 'Shift deleted.', 'groundwork-common-volunteer-tracker' ),
		'rostered'  => __( 'Added to the shift.', 'groundwork-common-volunteer-tracker' ),
		'removed'   => __( 'Taken off the shift.', 'groundwork-common-volunteer-tracker' ),
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$told = isset( $_GET['gwcvt_told'] ) ? absint( wp_unslash( $_GET['gwcvt_told'] ) ) : 0;

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
	$capped = isset( $_GET['gwcvt_capped'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_capped'] ) ) : '';

	/* A run that was truncated says so. A screen that quietly makes fewer shifts
	 * than somebody asked for is a screen that loses them a month of Saturdays,
	 * and they find out when nobody turns up. */
	if ( 'count' === $capped ) {
		$message .= ' ' . sprintf(
			/* translators: %d: the maximum number of shifts one repeat can create. */
			__( 'That is the most one repeat can add at a time (%d). Add the rest by repeating from the last one.', 'groundwork-common-volunteer-tracker' ),
			GWCVT_RECURRENCE_MAX
		);
	} elseif ( 'horizon' === $capped ) {
		$message .= ' ' . sprintf(
			/* translators: %d: how many months ahead a repeat may reach. */
			__( 'Repeats reach %d months ahead at most, so the later dates were not added.', 'groundwork-common-volunteer-tracker' ),
			GWCVT_RECURRENCE_HORIZON_MONTHS
		);
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
function gwcvt_unreconciled_notice(): void {
	if ( ! gwcvt_shifts_enabled() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'edit-' . GWCVT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	$waiting = gwcvt_unreconciled_shift_ids( 20 );

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
		esc_url( gwcvt_shift_log_url( (int) $waiting[0] ) ),
		esc_html__( 'Log them now', 'groundwork-common-volunteer-tracker' )
	);
}


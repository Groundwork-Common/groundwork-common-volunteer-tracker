<?php
/**
 * Who is coming to an event, and the sheet that goes on the clipboard.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwcvt_event_roster_add', 'gwcvt_handle_event_roster_add' );
add_action( 'admin_post_gwcvt_signup_promote', 'gwcvt_handle_signup_promote' );
add_action( 'admin_post_gwcvt_event_roster_print', 'gwcvt_handle_event_roster_print' );

/* ── The one screen that shows names ─────────────────────────────────────────
 * The public grid shows counts and never names, and there is no setting that
 * changes it. This screen is the other side of that line: it is behind a
 * capability, a member of staff is looking at it, and doing their job needs the
 * names.
 *
 * Grouped role, then time, because that is the shape of the day rather than the
 * shape of the database.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Everybody on one event.
 *
 * @param int $event_id Event post ID.
 */
function gwcvt_render_event_roster( int $event_id ): void {
	$roles     = gwcvt_event_roles( $event_id, array( 'publish', 'draft', GWCVT_SHIFT_CANCELLED ) );
	$clashes   = gwcvt_event_clashes( $event_id );
	$capacity  = gwcvt_event_capacity( $event_id );
	$slot_list = gwcvt_event_slot_ids( $event_id, array( 'publish', 'draft' ) );
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwcvt_event_name( $event_id ) ); ?></h1>

		<p class="description">
			<?php
			echo esc_html( gwcvt_event_date_label( $event_id ) );

			$where = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_LOCATION, true ) );

			if ( '' !== $where ) {
				echo ' · ' . esc_html( $where );
			}

			echo ' · ';
			echo esc_html(
				null !== $capacity
					? gwcvt_event_fill_label( $event_id ) . ' ' . __( 'places filled', 'groundwork-common-volunteer-tracker' )
					: gwcvt_event_fill_label( $event_id )
			);
			?>
		</p>

		<p>
			<a class="button" href="<?php echo esc_url( gwcvt_event_edit_url( $event_id ) ); ?>">
				<?php esc_html_e( 'Edit the event', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<?php if ( $slot_list ) : ?>
				<a class="button" href="<?php echo esc_url( gwcvt_event_print_url( $event_id ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<span class="description"><?php esc_html_e( 'One sheet for the clipboard, split by role and time. Bring it back marked up and type it into Log a day.', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</p>

		<?php foreach ( $clashes as $clash ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: a person's name, 2: one slot, 3: another slot. */
						esc_html__( '%1$s is down for two times that overlap: %2$s and %3$s. Nothing has been blocked.', 'groundwork-common-volunteer-tracker' ),
						'<strong>' . esc_html( $clash['who'] ) . '</strong>',
						esc_html( gwcvt_slot_label( $clash['a'] ) ),
						esc_html( gwcvt_slot_label( $clash['b'] ) )
					);
					?>
				</p>
			</div>
		<?php endforeach; ?>

		<?php if ( ! $roles ) : ?>
			<p><?php esc_html_e( 'This event has no times on it yet.', 'groundwork-common-volunteer-tracker' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $roles as $role => $slot_ids ) : ?>
			<h2><?php echo esc_html( (string) $role ); ?></h2>

			<?php foreach ( $slot_ids as $shift_id ) : ?>
				<?php gwcvt_render_event_slot_roster( (int) $shift_id ); ?>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php if ( $slot_list ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Put somebody on', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gwcvt_event_roster_add" />
				<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
				<?php wp_nonce_field( 'gwcvt_event_roster_add_' . $event_id ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="gwcvt-event-roster-name"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></label></th>
							<td>
								<?php
								/* The volunteer picker, exactly as the shift roster uses it.
								 * The wrapper is a div and not a p: the results list is a ul,
								 * and a ul inside a p makes the parser close the paragraph
								 * and everything still open inside it. No error, valid-looking
								 * HTML, and a script that finds nothing. */
								?>
								<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
									<input
										type="text"
										id="gwcvt-event-roster-name"
										class="regular-text"
										autocomplete="off"
										role="combobox"
										aria-expanded="false"
										aria-autocomplete="list"
										aria-controls="gwcvt-event-roster-results"
										placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
									/>
									<input type="hidden" name="gwcvt_volunteer" value="0" />
									<ul id="gwcvt-event-roster-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
								</div>
								<p class="description">
									<?php esc_html_e( 'Somebody who is not on file yet needs a volunteer record first.', 'groundwork-common-volunteer-tracker' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="gwcvt-event-roster-slot"><?php esc_html_e( 'Which one', 'groundwork-common-volunteer-tracker' ); ?></label></th>
							<td>
								<select id="gwcvt-event-roster-slot" name="gwcvt_shift" required>
									<?php foreach ( $slot_list as $shift_id ) : ?>
										<option value="<?php echo esc_attr( (string) $shift_id ); ?>">
											<?php
											$short = gwcvt_shift_is_understaffed( (int) $shift_id )
												? sprintf(
													/* translators: %d: how many more people are needed. */
													__( 'needs %d more', 'groundwork-common-volunteer-tracker' ),
													max( 0, (int) get_post_meta( $shift_id, GWCVT_SHIFT_MIN, true ) - gwcvt_shift_filled( (int) $shift_id ) )
												)
												: gwcvt_shift_fill_label( (int) $shift_id );

											printf(
												'%s — %s',
												esc_html( gwcvt_slot_label( (int) $shift_id ) ),
												esc_html( $short )
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Over the maximum goes on the waiting list. Raise the maximum first if you mean to add them to the roster.', 'groundwork-common-volunteer-tracker' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Add them', 'groundwork-common-volunteer-tracker' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * One time, and who is on it.
 *
 * @param int $shift_id Shift post ID.
 */
function gwcvt_render_event_slot_roster( int $shift_id ): void {
	$roster    = gwcvt_shift_signup_ids( $shift_id );
	$waiting   = gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WAITLIST ) );
	$gone      = gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WITHDRAWN ) );
	$cancelled = gwcvt_shift_is_cancelled( $shift_id );
	$min       = (int) get_post_meta( $shift_id, GWCVT_SHIFT_MIN, true );
	$short     = max( 0, $min - count( $roster ) );
	?>
	<h3 style="margin-bottom:4px">
		<?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?>
		<span class="description" style="font-weight:400">
			<?php
			if ( $cancelled ) {
				esc_html_e( '— called off', 'groundwork-common-volunteer-tracker' );
			} else {
				echo esc_html( gwcvt_shift_fill_label( $shift_id ) );

				if ( $short > 0 ) {
					printf(
						/* translators: %d: how many more people are needed. */
						' · ' . esc_html__( 'short by %d', 'groundwork-common-volunteer-tracker' ),
						(int) $short
					);
				}
			}
			?>
		</span>
	</h3>

	<table class="widefat striped" style="margin-bottom:14px">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Signed up', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Standing', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $roster && ! $waiting && ! $gone ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'Nobody has signed up for this one.', 'groundwork-common-volunteer-tracker' ); ?></em></td></tr>
			<?php endif; ?>

			<?php foreach ( $roster as $signup_id ) : ?>
				<?php gwcvt_render_event_roster_row( (int) $signup_id, __( 'On the roster', 'groundwork-common-volunteer-tracker' ), false ); ?>
			<?php endforeach; ?>

			<?php foreach ( $waiting as $signup_id ) : ?>
				<?php gwcvt_render_event_roster_row( (int) $signup_id, __( 'Waiting list', 'groundwork-common-volunteer-tracker' ), true ); ?>
			<?php endforeach; ?>

			<?php foreach ( $gone as $signup_id ) : ?>
				<?php gwcvt_render_event_roster_row( (int) $signup_id, __( 'Withdrew', 'groundwork-common-volunteer-tracker' ), false ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * One person on one time.
 *
 * @param int    $signup_id  Signup post ID.
 * @param string $standing   What to call their status.
 * @param bool   $promotable Whether to offer the promote action.
 */
function gwcvt_render_event_roster_row( int $signup_id, string $standing, bool $promotable ): void {
	$volunteer_id = (int) get_post_meta( $signup_id, GWCVT_SIGNUP_VOLUNTEER, true );
	$email        = gwcvt_signup_email( $signup_id );
	$created      = (string) get_post_meta( $signup_id, GWCVT_SIGNUP_CREATED, true );
	?>
	<tr>
		<td>
			<?php if ( $volunteer_id > 0 ) : ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $volunteer_id ) ); ?>">
					<?php echo esc_html( gwcvt_signup_name( $signup_id ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( gwcvt_signup_name( $signup_id ) ); ?>
			<?php endif; ?>
		</td>
		<td><?php echo '' !== $email ? esc_html( $email ) : '<span class="description">—</span>'; ?></td>
		<td>
			<?php
			echo '' !== $created
				? esc_html( (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $created . ' UTC' ) ) )
				: '<span class="description">—</span>';
			?>
		</td>
		<td><?php echo esc_html( $standing ); ?></td>
		<td>
			<?php if ( $promotable ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="gwcvt_signup_promote" />
					<input type="hidden" name="gwcvt_signup" value="<?php echo esc_attr( (string) $signup_id ); ?>" />
					<?php wp_nonce_field( 'gwcvt_signup_promote_' . $signup_id ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Give them a place', 'groundwork-common-volunteer-tracker' ); ?></button>
				</form>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/* ── Overlaps ────────────────────────────────────────────────────────────────
 * Somebody on two times at once has double-booked themselves. It is flagged and
 * never blocked: staff can see the whole picture and may well know that Dana is
 * doing the kitchen until noon on purpose.
 *
 * Touching is not overlapping. Set-up 07:30-09:00 followed by greeting
 * 09:00-12:00 is a normal morning, and reporting it would train coordinators to
 * ignore the flag that matters.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The people on this event who are down for two times at once.
 *
 * @param int $event_id Event post ID.
 * @return array<int, array{who: string, a: int, b: int}>
 */
function gwcvt_event_clashes( int $event_id ): array {
	$by_person = array();

	foreach ( gwcvt_event_slot_ids( $event_id ) as $shift_id ) {
		foreach ( gwcvt_shift_signup_ids( $shift_id ) as $signup_id ) {
			$key = gwcvt_signup_person_key( (int) $signup_id );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $by_person[ $key ] ) ) {
				$by_person[ $key ] = array(
					'who'    => gwcvt_signup_name( (int) $signup_id ),
					'shifts' => array(),
				);
			}

			$by_person[ $key ]['shifts'][] = (int) $shift_id;
		}
	}

	$clashes = array();

	foreach ( $by_person as $person ) {
		$pair = gwcvt_first_overlapping_pair( $person['shifts'] );

		if ( $pair ) {
			$clashes[] = array(
				'who' => $person['who'],
				'a'   => $pair[0],
				'b'   => $pair[1],
			);
		}
	}

	return $clashes;
}

/* ── Handlers ────────────────────────────────────────────────────────────── */

/**
 * Put somebody on one of an event's times.
 */
function gwcvt_handle_event_roster_add(): void {
	gwcvt_require_shift_cap();

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_event_roster_add_' . $event_id );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$volunteer_id = absint( $posted['gwcvt_volunteer'] ?? 0 );
	$shift_id     = absint( $posted['gwcvt_shift'] ?? 0 );

	/* The slot has to belong to this event. Without the check, a crafted form
	 * could add somebody to any shift on the site through this nonce. */
	if ( $volunteer_id < 1 || gwcvt_event_for_shift( $shift_id ) !== $event_id ) {
		gwcvt_event_roster_redirect( $event_id, 'saved' );
	}

	gwcvt_add_signup(
		$shift_id,
		array(
			'volunteer_id' => $volunteer_id,
			'source'       => 'staff',
		)
	);

	gwcvt_event_roster_redirect( $event_id, 'rostered' );
}

/**
 * Give somebody on the waiting list a place.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * gwcvt_settle_signups() promotes automatically, but it only ever runs when
 * somebody is added or somebody withdraws. A coordinator who KNOWS a place is
 * soft — somebody rang and said they probably cannot make it, somebody has not
 * answered in three weeks — had no way to free it, and the person on the waiting
 * list sat there for a time that in practice had room.
 *
 * A human doing this has actual information, usually a phone call. That is the
 * alternative to asking volunteers to confirm they are still coming, which this
 * plugin refuses: an unclicked cancel link means nothing, but an unclicked
 * confirm link looks like an answer.
 *
 * Promoting past the maximum is allowed. Settling never demotes, so nothing will
 * bounce them back out again, and a coordinator who has decided to squeeze one
 * more person in has decided.
 */
function gwcvt_handle_signup_promote(): void {
	gwcvt_require_shift_cap();

	$signup_id = isset( $_POST['gwcvt_signup'] ) ? absint( wp_unslash( $_POST['gwcvt_signup'] ) ) : 0;

	check_admin_referer( 'gwcvt_signup_promote_' . $signup_id );

	if ( GWCVT_SIGNUP_TYPE !== get_post_type( $signup_id ) ) {
		gwcvt_event_roster_redirect( 0, 'unknown' );
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );
	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( GWCVT_SIGNUP_WAITLIST === get_post_status( $signup_id ) ) {
		wp_update_post(
			array(
				'ID'          => $signup_id,
				'post_status' => 'publish',
			)
		);

		/**
		 * Fires after somebody has been moved off the waiting list by hand.
		 *
		 * @param int $signup_id The signup.
		 * @param int $shift_id  The shift.
		 */
		do_action( 'gwcvt_signup_promoted', $signup_id, $shift_id );
	}

	if ( $event_id > 0 ) {
		gwcvt_event_roster_redirect( $event_id, 'promoted' );
	}

	gwcvt_shift_redirect( $shift_id, 'promoted' );
}

/**
 * Back to one event's roster.
 *
 * @param int    $event_id Event post ID, or 0 for the list.
 * @param string $result   What to say.
 */
function gwcvt_event_roster_redirect( int $event_id, string $result ): void {
	if ( $event_id < 1 ) {
		wp_safe_redirect( gwcvt_schedule_url( array( 'view' => 'events' ) ) );
		exit;
	}

	wp_safe_redirect(
		gwcvt_schedule_url(
			array(
				'gwcvt_event'        => $event_id,
				'view'               => 'roster',
				'gwcvt_event_result' => $result,
			)
		)
	);
	exit;
}

/* ── The sheet for the clipboard ─────────────────────────────────────────────
 * One document, split by role and time, rather than eight separate sheets for
 * one festival. The person running the day carries one thing.
 *
 * The waiting list is printed too, marked as such, because whoever is on the
 * gate needs to know who to call up when somebody does not turn up.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The print link for one event's roster.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function gwcvt_event_print_url( int $event_id ): string {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=gwcvt_event_roster_print&gwcvt_event=' . $event_id ),
		'gwcvt_event_roster_print_' . $event_id
	);
}

/**
 * Render an event's roster as a standalone document.
 */
function gwcvt_handle_event_roster_print(): void {
	gwcvt_require_shift_cap();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below against this value.
	$event_id = isset( $_GET['gwcvt_event'] ) ? absint( wp_unslash( $_GET['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_event_roster_print_' . $event_id );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		wp_die(
			esc_html__( 'That event no longer exists.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'Content-Type: text/html; charset=utf-8' );

	gwcvt_render_event_roster_document( $event_id );
	exit;
}

/**
 * The event roster document itself.
 *
 * @param int $event_id Event post ID.
 */
function gwcvt_render_event_roster_document( int $event_id ): void {
	$roles = gwcvt_event_roles( $event_id );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow, noarchive" />
	<title><?php echo esc_html( gwcvt_event_name( $event_id ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( GWCVT_URL . 'assets/css/letter.css' ); ?>" />
</head>
<body class="gwcvt-roster-print">
	<h1><?php echo esc_html( gwcvt_org_name() ); ?></h1>

	<h2><?php echo esc_html( gwcvt_event_name( $event_id ) ); ?></h2>

	<p>
		<strong><?php echo esc_html( gwcvt_event_date_label( $event_id ) ); ?></strong>
		<?php
		$location = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_LOCATION, true ) );

		if ( '' !== $location ) {
			echo '<br />' . esc_html( $location );
		}

		$supervisor = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_SUPERVISOR, true ) );

		if ( '' !== $supervisor ) {
			printf(
				'<br />%s',
				esc_html(
					sprintf(
						/* translators: %s: a staff member's name. */
						__( 'Supervised by %s', 'groundwork-common-volunteer-tracker' ),
						$supervisor
					)
				)
			);
		}
		?>
	</p>

	<?php foreach ( $roles as $role => $slot_ids ) : ?>
		<h3><?php echo esc_html( (string) $role ); ?></h3>

		<?php foreach ( $slot_ids as $shift_id ) : ?>
			<?php
			$shift_id = (int) $shift_id;
			$roster   = gwcvt_shift_signup_ids( $shift_id );
			$waiting  = gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WAITLIST ) );
			$where    = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) );
			?>
			<p>
				<strong><?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?></strong>
				<?php if ( '' !== $where && $where !== $location ) : ?>
					— <?php echo esc_html( $where ); ?>
				<?php endif; ?>
			</p>

			<table>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'In', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Out', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Initials', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $roster as $signup_id ) : ?>
						<tr>
							<td><?php echo esc_html( gwcvt_signup_name( (int) $signup_id ) ); ?></td>
							<td><?php echo esc_html( gwcvt_signup_email( (int) $signup_id ) ); ?></td>
							<td></td>
							<td></td>
							<td></td>
						</tr>
					<?php endforeach; ?>

					<?php foreach ( $waiting as $signup_id ) : ?>
						<tr>
							<td>
								<?php echo esc_html( gwcvt_signup_name( (int) $signup_id ) ); ?>
								<?php esc_html_e( '(waiting list)', 'groundwork-common-volunteer-tracker' ); ?>
							</td>
							<td><?php echo esc_html( gwcvt_signup_email( (int) $signup_id ) ); ?></td>
							<td></td>
							<td></td>
							<td></td>
						</tr>
					<?php endforeach; ?>

					<?php
					/* Blank rows for the people who turn up without having signed
					 * up, which on a Saturday is most of the interesting ones. */
					for ( $i = 0; $i < 4; $i++ ) :
						?>
						<tr><td></td><td></td><td></td><td></td><td></td></tr>
					<?php endfor; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	<?php endforeach; ?>

	<p><?php esc_html_e( 'Hours from this sheet still need typing up and verifying before they can appear on a letter.', 'groundwork-common-volunteer-tracker' ); ?></p>
</body>
</html>
	<?php
}

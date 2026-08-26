<?php
/**
 * Who is coming to an event, and the sheet that goes on the clipboard.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_event_roster_add', 'gwc_vt_handle_event_roster_add' );
add_action( 'admin_post_gwc_vt_signup_promote', 'gwc_vt_handle_signup_promote' );
add_action( 'admin_post_gwc_vt_event_roster_print', 'gwc_vt_handle_event_roster_print' );

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
function gwc_vt_render_event_roster( int $event_id ): void {
	$roles     = gwc_vt_event_roles( $event_id, array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ) );
	$clashes   = gwc_vt_event_clashes( $event_id );
	$capacity  = gwc_vt_event_capacity( $event_id );
	$slot_list = gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft' ) );

	/* Which time is open for logging. A GET parameter and not a script: the
	 * design draws this as an inline expansion, and a link that re-renders one
	 * section expanded is the same thing without a line of JavaScript — and it
	 * is a real URL, so "the screen with the two o'clock open" is somewhere a
	 * coordinator can be sent. */
	$open = gwc_vt_event_open_slot( $event_id );
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_event_name( $event_id ) ); ?></h1>

		<p class="description">
			<?php
			echo esc_html( gwc_vt_event_date_label( $event_id ) );

			$where = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ) );

			if ( '' !== $where ) {
				echo ' · ' . esc_html( $where );
			}
			?>
		</p>

		<?php
		/* What the last save did, said on the screen it came back to. Logging a
		 * time from here no longer lands on the quick-add screen, so its notice
		 * has to be here or "1 entry logged, 1 no-show" is reported to nobody. */
		gwc_vt_quick_add_notice();
		?>

		<?php gwc_vt_render_event_progress( $event_id, $capacity, $slot_list ); ?>

		<?php if ( $slot_list ) : ?>
			<p class="description">
				<?php esc_html_e( 'One sheet for the clipboard, split by role and time. Bring it back marked up, then use "Log the hours" beside each time — everybody who signed up is already listed and selected.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php endif; ?>

		<?php foreach ( $clashes as $clash ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: a person's name, 2: one slot, 3: another slot. */
						esc_html__( '%1$s is down for two times that overlap: %2$s and %3$s. Nothing has been blocked.', 'groundwork-common-volunteer-tracker' ),
						'<strong>' . esc_html( $clash['who'] ) . '</strong>',
						esc_html( gwc_vt_slot_label( $clash['a'] ) ),
						esc_html( gwc_vt_slot_label( $clash['b'] ) )
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
				<?php gwc_vt_render_event_slot_roster( (int) $shift_id, $event_id, (int) $shift_id === $open ); ?>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php if ( $slot_list ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Put somebody on', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gwc_vt_event_roster_add" />
				<input type="hidden" name="gwc_vt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
				<?php wp_nonce_field( 'gwc_vt_event_roster_add_' . $event_id ); ?>

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
									<input type="hidden" name="gwc_vt_volunteer" value="0" />
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
								<select id="gwcvt-event-roster-slot" name="gwc_vt_shift" required>
									<?php foreach ( $slot_list as $shift_id ) : ?>
										<option value="<?php echo esc_attr( (string) $shift_id ); ?>">
											<?php
											$short = gwc_vt_shift_is_understaffed( (int) $shift_id )
												? sprintf(
													/* translators: %d: how many more people are needed. */
													__( 'needs %d more', 'groundwork-common-volunteer-tracker' ),
													max( 0, (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true ) - gwc_vt_shift_filled( (int) $shift_id ) )
												)
												: gwc_vt_shift_fill_label( (int) $shift_id );

											printf(
												'%s — %s',
												esc_html( gwc_vt_slot_label( (int) $shift_id ) ),
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
 * Which of an event's times is open for logging.
 *
 * @param int $event_id Event post ID.
 * @return int A shift post ID, or 0.
 */
function gwc_vt_event_open_slot( int $event_id ): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; decides which section is drawn expanded.
	$wanted = isset( $_GET['gwc_vt_log'] ) ? absint( wp_unslash( $_GET['gwc_vt_log'] ) ) : 0;

	/* A time of THIS event, and not any shift whose ID somebody typed. Without
	 * the parentage check the roster would happily open a log form for a
	 * standalone Saturday under a festival's heading. */
	if ( $wanted > 0 && gwc_vt_event_for_shift( $wanted ) === $event_id ) {
		return $wanted;
	}

	return 0;
}

/**
 * The roster with one of its times open for logging.
 *
 * @param int $event_id Event post ID.
 * @param int $shift_id The time to open, or 0 to close them all.
 * @return string
 */
function gwc_vt_event_log_url( int $event_id, int $shift_id ): string {
	$url = gwc_vt_event_roster_url( $event_id );

	if ( $shift_id < 1 ) {
		return $url;
	}

	/* An anchor as well as the parameter, so a page of six times does not
	 * reload at the top with the open one below the fold. */
	return add_query_arg( 'gwc_vt_log', $shift_id, $url ) . '#gwcvt-time-' . $shift_id;
}

/* ── How far through the day this is ─────────────────────────────────────────
 * Two figures, and the second is the one the day turns on: how many of the
 * event's times have had their hours written up. A four-time event is four
 * separate acts of logging, and the only way to know where you are is to scroll
 * and count green badges.
 *
 * Both halves read GWC_VT_SHIFT_RECONCILED, which is also what each section's
 * own badge reads. A header saying "2 of 4" above four sections showing three
 * badges is the count-and-screen-disagree bug CLAUDE.md has a rule about, and
 * the only defence is that there is one source.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The progress card.
 *
 * @param int      $event_id  Event post ID.
 * @param int|null $capacity  How many places the event has, or null when a slot is uncapped.
 * @param int[]    $slot_list Its times.
 */
function gwc_vt_render_event_progress( int $event_id, ?int $capacity, array $slot_list ): void {
	$due    = 0;
	$logged = 0;

	foreach ( $slot_list as $shift_id ) {
		/* Only times that have finished are counted. A festival at ten in the
		 * morning has three times still to come, and "0 of 4 logged" would be
		 * reporting a backlog that does not exist yet. */
		if ( gwc_vt_shift_is_cancelled( (int) $shift_id ) || ! gwc_vt_shift_has_ended( (int) $shift_id ) ) {
			continue;
		}

		++$due;

		if ( gwc_vt_shift_is_reconciled( (int) $shift_id ) ) {
			++$logged;
		}
	}

	$outstanding = $due - $logged;
	?>
	<div class="gwcvt-event-progress gwcvt-event-progress--<?php echo esc_attr( $outstanding > 0 ? 'waiting' : 'clear' ); ?>">
		<span>
			<strong><?php echo esc_html( gwc_vt_event_fill_label( $event_id ) ); ?></strong>
			<?php
			echo null !== $capacity
				? ' ' . esc_html__( 'places filled', 'groundwork-common-volunteer-tracker' )
				: '';
			?>
		</span>

		<?php if ( $due > 0 ) : ?>
			<span>
				<?php
				/* One sentence, not a number beside a phrase. Split in two it was
				 * the bare string "%1$s of %2$s", which the letter's readiness
				 * line already uses for hours — same string, two unrelated
				 * meanings, and make-pot warned about the contradictory
				 * translator comments. A language that words a count of things
				 * differently from a count of hours could not tell them apart. */
				printf(
					wp_kses(
						/* translators: 1: how many of the day's times have had their hours written up, 2: how many times have finished. Both already formatted. */
						_n(
							'<strong>%1$s of %2$s</strong> time has its hours logged',
							'<strong>%1$s of %2$s</strong> times have their hours logged',
							$due,
							'groundwork-common-volunteer-tracker'
						),
						array( 'strong' => array() )
					),
					esc_html( number_format_i18n( $logged ) ),
					esc_html( number_format_i18n( $due ) )
				);
				?>
			</span>
		<?php endif; ?>

		<span class="gwcvt-event-progress__actions">
			<a class="button" href="<?php echo esc_url( gwc_vt_event_edit_url( $event_id ) ); ?>">
				<?php esc_html_e( 'Edit the event', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<?php if ( $slot_list ) : ?>
				<a class="button" href="<?php echo esc_url( gwc_vt_event_print_url( $event_id ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php endif; ?>
		</span>
	</div>
	<?php
}

/**
 * One role-time, its roster, and — when it is the open one — its log form.
 *
 * @param int  $shift_id Shift post ID.
 * @param int  $event_id The event it belongs to.
 * @param bool $open     Whether to draw the log form under it.
 */
function gwc_vt_render_event_slot_roster( int $shift_id, int $event_id = 0, bool $open = false ): void {
	$roster    = gwc_vt_shift_signup_ids( $shift_id );
	$waiting   = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) );
	$gone      = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WITHDRAWN ) );
	$cancelled = gwc_vt_shift_is_cancelled( $shift_id );
	$min       = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );
	$short     = max( 0, $min - count( $roster ) );

	/* A time that has finished can be turned into hours, exactly as a standalone
	 * shift can. It could not be reached from anywhere before: an event slot was
	 * counted by the unlogged-hours nag, and the only screens offering the
	 * pre-filled reconciliation listed standalone shifts. The instruction on the
	 * print sheet — type it into Log a day — meant retyping the roster by hand. */
	$ended      = ! $cancelled && gwc_vt_shift_has_ended( $shift_id );
	$reconciled = $ended && gwc_vt_shift_is_reconciled( $shift_id );
	?>
	<h3 id="gwcvt-time-<?php echo esc_attr( (string) $shift_id ); ?>" style="margin-bottom:4px">
		<?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?>
		<span class="description" style="font-weight:400">
			<?php
			if ( $cancelled ) {
				esc_html_e( '— called off', 'groundwork-common-volunteer-tracker' );
			} else {
				echo esc_html( gwc_vt_shift_fill_label( $shift_id ) );

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

		<?php
		/* Three states, and the third is the one that makes the day one screen:
		 * a time that is open for logging says so and offers the way out, rather
		 * than repeating the offer that got you here.
		 *
		 * The badge is a link either way, and where it goes is the fallback: on
		 * an event this opens the form below, and gwc_vt_shift_log_url() — the
		 * standalone screen this used to be the only route to — is still what
		 * every other screen links to. */
		if ( $ended ) :
			if ( $open ) :
				?>
				<a
					class="gwcvt-badge gwcvt-badge--action"
					style="font-weight:400"
					href="<?php echo esc_url( gwc_vt_event_log_url( $event_id, 0 ) ); ?>"
				>
					<?php esc_html_e( 'Ticking who came, below — close', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
				<?php
			else :
				?>
				<a
					class="gwcvt-badge <?php echo $reconciled ? 'gwcvt-badge--verified' : 'gwcvt-badge--waiting gwcvt-badge--action'; ?>"
					style="font-weight:400"
					href="<?php echo esc_url( $event_id > 0 ? gwc_vt_event_log_url( $event_id, $shift_id ) : gwc_vt_shift_log_url( $shift_id ) ); ?>"
				>
					<?php
					echo $reconciled
						? esc_html__( 'Hours logged ✓ — log more', 'groundwork-common-volunteer-tracker' )
						: esc_html__( 'Log the hours', 'groundwork-common-volunteer-tracker' );
					?>
				</a>
				<?php
			endif;
		endif;
		?>
	</h3>

	<?php
	/* The log form, under the time it belongs to. Same form, same handler, same
	 * rules — gwc_vt_render_shift_log_form() is the standalone screen's own
	 * form, moved rather than copied, so there is no second write path.
	 *
	 * Drawn instead of the roster table, not above it: the form already lists
	 * everybody who signed up, with a checkbox and their scheduled hours. Two
	 * lists of the same people on one screen is how somebody ticks the wrong
	 * one. */
	if ( $open ) :
		gwc_vt_render_shift_log_form( $shift_id, $event_id );
		return;
	endif;
	?>

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
				<?php gwc_vt_render_event_roster_row( (int) $signup_id, __( 'On the roster', 'groundwork-common-volunteer-tracker' ), false ); ?>
			<?php endforeach; ?>

			<?php foreach ( $waiting as $signup_id ) : ?>
				<?php gwc_vt_render_event_roster_row( (int) $signup_id, __( 'Waiting list', 'groundwork-common-volunteer-tracker' ), true ); ?>
			<?php endforeach; ?>

			<?php foreach ( $gone as $signup_id ) : ?>
				<?php gwc_vt_render_event_roster_row( (int) $signup_id, __( 'Withdrew', 'groundwork-common-volunteer-tracker' ), false ); ?>
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
function gwc_vt_render_event_roster_row( int $signup_id, string $standing, bool $promotable ): void {
	/* gwc_vt_local_date() rather than the conversion inlined here, and the
	 * emptiness test on what it returns rather than on the raw meta. This cell
	 * reimplemented the helper without its guard: a GWC_VT_SIGNUP_CREATED that
	 * is non-empty but unparseable — a partially anonymized record, a
	 * hand-edited meta row, a value from an older format — makes strtotime()
	 * return false, (int) false is a timestamp of 0, and the cell printed
	 * 1 January 1970 while the raw value stayed truthy enough that the em-dash
	 * branch was never reached. */
	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );
	$email        = gwc_vt_signup_email( $signup_id );
	$signed_up    = gwc_vt_local_date( (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CREATED, true ) );
	?>
	<tr>
		<td>
			<?php if ( $volunteer_id > 0 ) : ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $volunteer_id ) ); ?>">
					<?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?>
			<?php endif; ?>
		</td>
		<td><?php echo '' !== $email ? esc_html( $email ) : '<span class="description">—</span>'; ?></td>
		<td>
			<?php
			echo '' !== $signed_up
				? esc_html( $signed_up )
				: '<span class="description">—</span>';
			?>
		</td>
		<td><?php echo esc_html( $standing ); ?></td>
		<td>
			<?php if ( $promotable ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="gwc_vt_signup_promote" />
					<input type="hidden" name="gwc_vt_signup" value="<?php echo esc_attr( (string) $signup_id ); ?>" />
					<?php wp_nonce_field( 'gwc_vt_signup_promote_' . $signup_id ); ?>
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
function gwc_vt_event_clashes( int $event_id ): array {
	$by_person = array();

	foreach ( gwc_vt_event_slot_ids( $event_id ) as $shift_id ) {
		foreach ( gwc_vt_shift_signup_ids( $shift_id ) as $signup_id ) {
			$key = gwc_vt_signup_person_key( (int) $signup_id );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $by_person[ $key ] ) ) {
				$by_person[ $key ] = array(
					'who'    => gwc_vt_signup_name( (int) $signup_id ),
					'shifts' => array(),
				);
			}

			$by_person[ $key ]['shifts'][] = (int) $shift_id;
		}
	}

	$clashes = array();

	foreach ( $by_person as $person ) {
		$pair = gwc_vt_first_overlapping_pair( $person['shifts'] );

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
function gwc_vt_handle_event_roster_add(): void {
	gwc_vt_require_shift_cap();

	$event_id = isset( $_POST['gwc_vt_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_event_roster_add_' . $event_id );

	$posted = wp_unslash( $_POST );

	$volunteer_id = absint( $posted['gwc_vt_volunteer'] ?? 0 );
	$shift_id     = absint( $posted['gwc_vt_shift'] ?? 0 );

	/* Two refusals, said separately. They were one branch redirecting with
	 * 'saved', which rendered "Saved." for an add that did not happen — and
	 * "nobody was chosen" and "that time is not on this event" are different
	 * problems with different fixes. */
	if ( $volunteer_id < 1 ) {
		gwc_vt_event_roster_redirect( $event_id, 'no-volunteer' );
	}

	/* The slot has to belong to this event. Without the check, a crafted form
	 * could add somebody to any shift on the site through this nonce. Naming the
	 * mismatch tells an attacker nothing they did not already know, and tells a
	 * coordinator looking at a stale tab something useful. */
	if ( gwc_vt_event_for_shift( $shift_id ) !== $event_id ) {
		gwc_vt_event_roster_redirect( $event_id, 'wrong-slot' );
	}

	gwc_vt_add_signup(
		$shift_id,
		array(
			'volunteer_id' => $volunteer_id,
			'source'       => 'staff',
		)
	);

	gwc_vt_event_roster_redirect( $event_id, 'rostered' );
}

/**
 * Give somebody on the waiting list a place.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * gwc_vt_settle_signups() promotes automatically, but it only ever runs when
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
function gwc_vt_handle_signup_promote(): void {
	gwc_vt_require_shift_cap();

	$signup_id = isset( $_POST['gwc_vt_signup'] ) ? absint( wp_unslash( $_POST['gwc_vt_signup'] ) ) : 0;

	check_admin_referer( 'gwc_vt_signup_promote_' . $signup_id );

	if ( GWC_VT_SIGNUP_TYPE !== get_post_type( $signup_id ) ) {
		gwc_vt_event_roster_redirect( 0, 'unknown' );
	}

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );
	$event_id = gwc_vt_event_for_shift( $shift_id );

	if ( GWC_VT_SIGNUP_WAITLIST === get_post_status( $signup_id ) ) {
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
		do_action( 'gwc_vt_signup_promoted', $signup_id, $shift_id );
	}

	if ( $event_id > 0 ) {
		gwc_vt_event_roster_redirect( $event_id, 'promoted' );
	}

	gwc_vt_shift_redirect( $shift_id, 'promoted' );
}

/**
 * Back to one event's roster.
 *
 * @param int    $event_id Event post ID, or 0 for the list.
 * @param string $result   What to say.
 */
function gwc_vt_event_roster_redirect( int $event_id, string $result ): void {
	if ( $event_id < 1 ) {
		wp_safe_redirect( gwc_vt_schedule_url( array( 'view' => 'events' ) ) );
		exit;
	}

	wp_safe_redirect(
		gwc_vt_schedule_url(
			array(
				'gwc_vt_event'        => $event_id,
				'view'                => 'roster',
				'gwc_vt_event_result' => $result,
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
function gwc_vt_event_print_url( int $event_id ): string {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=gwc_vt_event_roster_print&gwc_vt_event=' . $event_id ),
		'gwc_vt_event_roster_print_' . $event_id
	);
}

/**
 * Render an event's roster as a standalone document.
 */
function gwc_vt_handle_event_roster_print(): void {
	gwc_vt_require_shift_cap();

	// Verified immediately below against this value.
	$event_id = isset( $_GET['gwc_vt_event'] ) ? absint( wp_unslash( $_GET['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_event_roster_print_' . $event_id );

	if ( GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		wp_die(
			esc_html__( 'That event no longer exists.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	gwc_vt_private_document_headers();

	gwc_vt_render_event_roster_document( $event_id );
	exit;
}

/**
 * The event roster document itself.
 *
 * @param int $event_id Event post ID.
 */
function gwc_vt_render_event_roster_document( int $event_id ): void {
	$roles = gwc_vt_event_roles( $event_id );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow, noarchive" />
	<title><?php echo esc_html( gwc_vt_event_name( $event_id ) ); ?></title>
	<?php gwc_vt_print_document_styles(); ?>
</head>
<body class="gwcvt-roster-print">
	<h1><?php echo esc_html( gwc_vt_org_name() ); ?></h1>

	<h2><?php echo esc_html( gwc_vt_event_name( $event_id ) ); ?></h2>

	<p>
		<strong><?php echo esc_html( gwc_vt_event_date_label( $event_id ) ); ?></strong>
		<?php
		$location = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ) );

		if ( '' !== $location ) {
			echo '<br />' . esc_html( $location );
		}

		$supervisor = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_SUPERVISOR, true ) );

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
			$roster   = gwc_vt_shift_signup_ids( $shift_id );
			$waiting  = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) );
			$where    = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ) );
			?>
			<p>
				<strong><?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?></strong>
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
							<td><?php echo esc_html( gwc_vt_signup_name( (int) $signup_id ) ); ?></td>
							<td><?php echo esc_html( gwc_vt_signup_email( (int) $signup_id ) ); ?></td>
							<td></td>
							<td></td>
							<td></td>
						</tr>
					<?php endforeach; ?>

					<?php foreach ( $waiting as $signup_id ) : ?>
						<tr>
							<td>
								<?php echo esc_html( gwc_vt_signup_name( (int) $signup_id ) ); ?>
								<?php esc_html_e( '(waiting list)', 'groundwork-common-volunteer-tracker' ); ?>
							</td>
							<td><?php echo esc_html( gwc_vt_signup_email( (int) $signup_id ) ); ?></td>
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

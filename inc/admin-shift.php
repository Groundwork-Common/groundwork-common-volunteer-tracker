<?php
/**
 * One shift: its details, who is coming, and calling it off.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_save_shift', 'gwc_vt_handle_save_shift' );
add_action( 'admin_post_gwc_vt_cancel_shift', 'gwc_vt_handle_cancel_shift' );
add_action( 'admin_post_gwc_vt_delete_shift', 'gwc_vt_handle_delete_shift' );
add_action( 'admin_post_gwc_vt_roster_add', 'gwc_vt_handle_roster_add' );
add_action( 'admin_post_gwc_vt_roster_remove', 'gwc_vt_handle_roster_remove' );
add_action( 'admin_post_gwc_vt_roster_print', 'gwc_vt_handle_roster_print' );

/* ── The shift screen ────────────────────────────────────────────────────────
 * Two things on one page, because they are two halves of the same job: what the
 * shift is, and who is coming to it. A coordinator on the phone with somebody
 * who wants to help on Saturday needs both in front of them, and a separate
 * roster screen would mean a round trip in the middle of the call.
 *
 * The repeat controls only appear when adding. Editing one occurrence edits that
 * occurrence — see the note in inc/recurrence.php on why the series is real rows
 * rather than a rule, and what that buys when somebody closes the Saturday after
 * Thanksgiving.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The add/edit screen for one shift.
 *
 * @param int $shift_id Shift post ID, or 0 for a new one.
 */
function gwc_vt_render_shift_editor( int $shift_id ): void {
	$is_new     = $shift_id < 1;
	$vocabulary = gwc_vt_activity_vocabulary();
	$locations  = gwc_vt_location_vocabulary();

	$date       = $is_new ? gwc_vt_today() : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );
	$start      = $is_new ? '09:00' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_START, true );
	$end        = $is_new ? '12:00' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_END, true );
	$overnight  = ! $is_new && get_post_meta( $shift_id, GWC_VT_SHIFT_OVERNIGHT, true );
	$activity   = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true );
	$supervisor = $is_new ? wp_get_current_user()->display_name : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_SUPERVISOR, true );
	$location   = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true );
	$notes      = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_NOTES, true );
	$min        = $is_new ? 0 : (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );
	$max        = $is_new ? 0 : (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );
	$published  = $is_new || 'publish' === get_post_status( $shift_id );
	$cancelled  = ! $is_new && gwc_vt_shift_is_cancelled( $shift_id );
	?>
	<div class="wrap gwcvt-wrap">
		<h1>
			<?php
			echo $is_new
				? esc_html__( 'Add a shift', 'groundwork-common-volunteer-tracker' )
				: esc_html( get_the_title( $shift_id ) );
			?>
		</h1>

		<p>
			<a href="<?php echo esc_url( gwc_vt_schedule_url() ); ?>">
				&larr; <?php esc_html_e( 'Back to the schedule', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>

		<?php gwc_vt_schedule_notice(); ?>

		<?php if ( $cancelled ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'This shift has been canceled.', 'groundwork-common-volunteer-tracker' ); ?></strong>
					<?php
					$reason = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_REASON, true );

					if ( '' !== $reason ) {
						echo ' ' . esc_html( $reason );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		/* ── The one thing to do next ───────────────────────────────────────
		 * Once a shift is over, editing it is almost never what anybody came
		 * here for — typing up who actually turned up is. So that gets a notice
		 * above the form rather than a row action below it. */
		if ( ! $is_new && ! $cancelled && gwc_vt_shift_has_ended( $shift_id ) ) :
			$logged = gwc_vt_shift_is_reconciled( $shift_id );
			?>
			<div class="notice notice-<?php echo $logged ? 'success' : 'warning'; ?> inline">
				<p>
					<?php if ( $logged ) : ?>
						<?php esc_html_e( 'The hours for this shift have been logged.', 'groundwork-common-volunteer-tracker' ); ?>
						<a href="<?php echo esc_url( gwc_vt_shift_log_url( $shift_id ) ); ?>">
							<?php esc_html_e( 'Log more hours', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					<?php else : ?>
						<strong><?php esc_html_e( 'This shift has happened and its hours have not been logged.', 'groundwork-common-volunteer-tracker' ); ?></strong>
						<a href="<?php echo esc_url( gwc_vt_shift_log_url( $shift_id ) ); ?>">
							<?php esc_html_e( 'Log the hours now', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-shift-form">
			<input type="hidden" name="action" value="gwc_vt_save_shift" />
			<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_save_shift_' . $shift_id ); ?>

			<?php
			/* ── One card, not three form-tables ─────────────────────────────
			 * The fields used to be three <table class="form-table"> sections
			 * under three headings — When, What and where, How many people —
			 * which put the date, the times and the capacity on three separate
			 * eye-lines with a heading between each. A coordinator adding a
			 * shift says all of that in one breath.
			 *
			 * So: one card, two columns, in the order somebody describes a
			 * shift out loud. The repeat and everything it creates sit below a
			 * rule, because they are a decision about how many of this shift to
			 * make rather than another of its attributes.
			 *
			 * The labels keep their explicit `for`, which the form-table's
			 * <th scope="row"> was doing structurally. A grid has no row header
			 * to lean on, so the association has to be stated — dropping it
			 * would leave a screen reader announcing six unlabelled inputs.
			 * ───────────────────────────────────────────────────────────── */
			?>
			<div class="gwcvt-shift-card">
				<div class="gwcvt-shift-grid">
					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-activity"><?php esc_html_e( 'What they will do', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="text" id="gwcvt-shift-activity" name="gwc_vt_activity" maxlength="200" value="<?php echo esc_attr( $activity ); ?>" <?php echo $vocabulary ? 'list="gwcvt-shift-activities"' : ''; ?> />
						<?php if ( $vocabulary ) : ?>
							<datalist id="gwcvt-shift-activities">
								<?php foreach ( $vocabulary as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Copied onto every hour entry when you log the shift, so it ends up on a letter.', 'groundwork-common-volunteer-tracker' ); ?></p>
					</div>

					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-location"><?php esc_html_e( 'Where', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="text" id="gwcvt-shift-location" name="gwc_vt_location" maxlength="200" value="<?php echo esc_attr( $location ); ?>" <?php echo $locations ? 'list="gwcvt-shift-locations"' : ''; ?> />
						<?php if ( $locations ) : ?>
							<datalist id="gwcvt-shift-locations">
								<?php foreach ( $locations as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>
					</div>

					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-date"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="date" id="gwcvt-shift-date" name="gwc_vt_date" required value="<?php echo esc_attr( $date ); ?>" />
						<p class="description">
							<?php esc_html_e( 'A shift is something that has not happened yet, so this one is allowed to be in the future — unlike an hour entry, which records a day somebody already worked.', 'groundwork-common-volunteer-tracker' ); ?>
						</p>
					</div>

					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-start"><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></label>
						<span class="gwcvt-shift-pair">
							<input type="time" id="gwcvt-shift-start" name="gwc_vt_start" required value="<?php echo esc_attr( $start ); ?>" />
							<label for="gwcvt-shift-end" class="gwcvt-shift-form__inline"><?php esc_html_e( 'to', 'groundwork-common-volunteer-tracker' ); ?></label>
							<input type="time" id="gwcvt-shift-end" name="gwc_vt_end" required value="<?php echo esc_attr( $end ); ?>" />
						</span>

						<label for="gwcvt-shift-overnight" class="gwcvt-shift-check">
							<input type="checkbox" id="gwcvt-shift-overnight" name="gwc_vt_overnight" value="1" <?php checked( (bool) $overnight ); ?> />
							<?php esc_html_e( 'ends the next day', 'groundwork-common-volunteer-tracker' ); ?>
						</label>

						<p class="description">
							<?php esc_html_e( 'An overnight shift at a shelter runs 22:00 to 06:00 with that checkbox selected. Without it, an end time before the start is treated as a typo and refused.', 'groundwork-common-volunteer-tracker' ); ?>
						</p>
					</div>

					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-min"><?php esc_html_e( 'We need at least', 'groundwork-common-volunteer-tracker' ); ?></label>
						<span class="gwcvt-shift-pair">
							<input type="number" id="gwcvt-shift-min" name="gwc_vt_min" class="small-text" min="0" max="<?php echo esc_attr( (string) GWC_VT_SHIFT_CAPACITY_MAX ); ?>" value="<?php echo esc_attr( (string) $min ); ?>" />
							<label for="gwcvt-shift-max" class="gwcvt-shift-form__inline"><?php esc_html_e( 'and have room for', 'groundwork-common-volunteer-tracker' ); ?></label>
							<input type="number" id="gwcvt-shift-max" name="gwc_vt_max" class="small-text" min="0" max="<?php echo esc_attr( (string) GWC_VT_SHIFT_CAPACITY_MAX ); ?>" value="<?php echo esc_attr( (string) $max ); ?>" />
						</span>
						<p class="description"><?php esc_html_e( 'Below the first, the shift is flagged on the schedule. Past the second, later signups go on a waiting list rather than being turned away. Zero means no minimum and no limit.', 'groundwork-common-volunteer-tracker' ); ?></p>
					</div>

					<div class="gwcvt-shift-field">
						<label for="gwcvt-shift-supervisor"><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></label>
						<input type="text" id="gwcvt-shift-supervisor" name="gwc_vt_supervisor" maxlength="100" value="<?php echo esc_attr( $supervisor ); ?>" />
					</div>
				</div>

				<div class="gwcvt-shift-field gwcvt-shift-field--wide">
					<label for="gwcvt-shift-notes"><?php esc_html_e( 'What to know', 'groundwork-common-volunteer-tracker' ); ?></label>
					<textarea id="gwcvt-shift-notes" name="gwc_vt_notes" class="large-text" rows="3" maxlength="1000"><?php echo esc_textarea( $notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Closed shoes, park around the back, ask for Dana at the desk. Shown to whoever signs up.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</div>

				<?php gwc_vt_render_shift_credentials_field( $shift_id ); ?>

				<?php if ( $is_new ) : ?>
					<hr class="gwcvt-shift-rule" />

					<div class="gwcvt-shift-grid">
						<div class="gwcvt-shift-field">
							<label for="gwcvt-shift-repeat"><?php esc_html_e( 'Repeats', 'groundwork-common-volunteer-tracker' ); ?></label>
							<select id="gwcvt-shift-repeat" name="gwc_vt_repeat">
								<?php foreach ( gwc_vt_recurrence_patterns() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="gwcvt-shift-field">
							<label for="gwcvt-shift-until"><?php esc_html_e( 'Until', 'groundwork-common-volunteer-tracker' ); ?></label>
							<input type="date" id="gwcvt-shift-until" name="gwc_vt_until" value="" />
							<p class="description">
								<?php
								printf(
									/* translators: 1: the maximum number of shifts, 2: how many months ahead. */
									esc_html__( 'Every repeat becomes a real shift you can edit or cancel on its own. Up to %1$d at a time, and up to %2$d months ahead.', 'groundwork-common-volunteer-tracker' ),
									(int) GWC_VT_RECURRENCE_MAX,
									(int) GWC_VT_RECURRENCE_HORIZON_MONTHS
								);
								?>
							</p>
						</div>
					</div>

					<?php
					/* Filled by assets/js/admin-shift-repeat.js from the REST
					 * route, which runs the same function the save runs. Empty
					 * and hidden on load: the pattern starts at "Just this
					 * once", so there is nothing to say until somebody picks a
					 * repeat.
					 *
					 * With the script absent this stays empty and the after-save
					 * report in gwc_vt_schedule_notice() is the answer, exactly
					 * as it was before — which is why the box is a container the
					 * server leaves blank rather than something the form depends
					 * on.
					 *
					 * aria-live, because the sentence changes under somebody who
					 * may be nowhere near it: the control they just used is the
					 * select, and the count is the consequence of using it. */
					?>
					<div
						class="gwcvt-repeat-preview"
						id="gwcvt-shift-preview"
						data-gwcvt-repeat-preview
						aria-live="polite"
						hidden
					></div>
				<?php else : ?>
					<?php
					/* An existing occurrence says which repeat it came from, and
					 * offers the screen that changes all of them. The repeat
					 * SELECT above is new-shift-only on purpose — changing the
					 * pattern of a repeat that already made twenty rows is a
					 * different operation from editing one of them, and it is
					 * not this form's job. Saving here still touches only this
					 * occurrence, which is what the sentence says. */
					$series_note = gwc_vt_shift_repeat_note( $shift_id );
					?>
					<?php if ( '' !== $series_note ) : ?>
						<hr class="gwcvt-shift-rule" />

						<div class="gwcvt-shift-field gwcvt-shift-field--wide">
							<p class="description">
								<?php
								printf(
									/* translators: %s: a description of the repeat, such as "Repeats weekly until August 30". */
									esc_html__( 'One occurrence of a repeat — %s. Saving here changes this one only.', 'groundwork-common-volunteer-tracker' ),
									esc_html( $series_note )
								);
								?>
								<a href="<?php echo esc_url( gwc_vt_repeat_url( $shift_id ) ); ?>">
									<?php esc_html_e( 'Change the whole repeat instead', 'groundwork-common-volunteer-tracker' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<hr class="gwcvt-shift-rule" />

				<div class="gwcvt-shift-field">
					<label for="gwcvt-shift-published" class="gwcvt-shift-check">
						<input type="checkbox" id="gwcvt-shift-published" name="gwc_vt_published" value="1" <?php checked( $published ); ?> <?php disabled( $cancelled ); ?> />
						<?php esc_html_e( 'Published — people can be put on it, and it can appear publicly', 'groundwork-common-volunteer-tracker' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Cleared, it is a draft only staff can see.', 'groundwork-common-volunteer-tracker' ); ?></p>
				</div>
			</div>

			<?php
			/* Only where it could matter: an existing shift, still to come, with
			 * somebody on it. On a new shift nobody has signed up yet, and on a
			 * past one there is nothing anybody can do with the news. */
			$rostered = $is_new ? 0 : count( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) );

			if ( $rostered > 0 && ! $cancelled && ! gwc_vt_shift_has_ended( $shift_id ) ) :
				?>
				<p>
					<label for="gwcvt-shift-notify">
						<input type="checkbox" id="gwcvt-shift-notify" name="gwc_vt_notify" value="1" checked="checked" />
						<?php
						printf(
							/* translators: %d: how many people are signed up. */
							esc_html( _n( 'If the date, time or place changes, email the %d person signed up', 'If the date, time or place changes, email the %d people signed up', $rostered, 'groundwork-common-volunteer-tracker' ) ),
							(int) $rostered
						);
						?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Nothing is sent for anything else — correcting the activity, the supervisor or the notes does not email anybody.', 'groundwork-common-volunteer-tracker' ); ?>
					</span>
				</p>
			<?php endif; ?>

			<?php
			/* An id on the new-shift button so the repeat preview can relabel it
			 * — "Add 20 shifts to the schedule" is the same sentence as the box,
			 * said on the control that does it. Its starting text is the same
			 * string gwc_vt_recurrence_preview() returns for a run that does not
			 * repeat, so the script has something to put back. */
			submit_button(
				$is_new
					? __( 'Add to the schedule', 'groundwork-common-volunteer-tracker' )
					: __( 'Save this shift', 'groundwork-common-volunteer-tracker' ),
				'primary',
				'submit',
				true,
				$is_new ? array( 'id' => 'gwcvt-shift-submit' ) : ''
			);
			?>
		</form>

		<?php
		if ( ! $is_new ) {
			gwc_vt_render_shift_roster( $shift_id );
			gwc_vt_render_shift_danger_zone( $shift_id );
		}
		?>
	</div>
	<?php
}


/* ── The drawer's panel ──────────────────────────────────────────────────────
 * One shift, rendered small enough to sit in a 360px rail beside the calendar.
 * Filling a short shift used to be a page load per glance: open the shift, add
 * a name, get sent back, find the next red Saturday, open that. The drawer
 * keeps the month underneath while somebody rings round.
 *
 * ── Server-rendered, and fetched as HTML ─────────────────────────────────────
 * The panel comes back from gwc-vt/v1/shift-panel as finished markup rather
 * than as JSON the script assembles. Two reasons, and the second is the one
 * that decided it:
 *
 *   Every string in here is translated, and several are sentences with a number
 *   in them. Assembling those in JavaScript puts word order in the browser,
 *   which is where translations go to die.
 *
 *   And the forms carry nonces. A nonce is minted for one user and one action;
 *   rendering the form where the nonce is made is the only version of this that
 *   does not involve shipping a token to the browser and hoping it is used for
 *   what it was issued for.
 *
 * Nothing here is a new write path. Adding somebody posts to the same
 * gwc_vt_roster_add handler the shift editor posts to; printing is the same
 * URL; calling it off is a LINK to the editor's own form, because that form
 * asks for a reason and offers to email the roster, and those questions are the
 * confirmation. A drawer is not a reason to lose one.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * One shift, as the drawer shows it.
 *
 * @param int    $shift_id Shift post ID.
 * @param string $back     Where a roster add should return to: 'month', 'list' or ''.
 * @param string $month    The month the calendar was on, Y-m, when it was on one.
 */
function gwc_vt_render_shift_panel( int $shift_id, string $back = '', string $month = '' ): void {
	$state     = gwc_vt_shift_state( $shift_id );
	$cancelled = 'cancelled' === $state;
	$ended     = gwc_vt_shift_has_ended( $shift_id );
	$activity  = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true );
	$where     = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ) );
	$max       = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );
	$filled    = gwc_vt_shift_filled( $shift_id );
	$edit_url  = gwc_vt_schedule_url( array( 'shift' => $shift_id ) );

	/* A hair of width even at zero, so an empty shift still reads as a meter
	 * rather than as a missing element — the same rule the dashboard's line
	 * follows. */
	$width = $max > 0 ? max( 2, (int) round( ( $filled / $max ) * 100 ) ) : 2;

	$roster  = gwc_vt_shift_signup_ids( $shift_id );
	$waiting = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) );
	$repeat  = gwc_vt_shift_repeat_note( $shift_id );
	?>
	<div class="gwcvt-drawer__head">
		<h2 class="gwcvt-drawer__title">
			<?php echo esc_html( '' !== $activity ? $activity : __( 'Untitled shift', 'groundwork-common-volunteer-tracker' ) ); ?>
		</h2>
		<button type="button" class="gwcvt-drawer__close" data-gwcvt-drawer-close>
			<span aria-hidden="true">&times;</span>
			<span class="screen-reader-text"><?php esc_html_e( 'Close', 'groundwork-common-volunteer-tracker' ); ?></span>
		</button>
	</div>

	<p class="gwcvt-drawer__when">
		<?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?><br />
		<?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?>
	</p>

	<?php if ( '' !== $where ) : ?>
		<p class="gwcvt-drawer__where"><?php echo esc_html( $where ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $repeat ) : ?>
		<?php
		/* What repeat this came from, and the two ways out of it.
		 *
		 * "Change the whole repeat" is a link to a confirmation screen and never
		 * an action, which is the whole reason it can sit beside "edit only this
		 * one" at all. The batch write is over twenty rosters; what makes it
		 * safe to offer here is that pressing it shows you what it would touch
		 * and how many people it would email, and changes nothing until you
		 * agree. Wiring it to a handler directly would be the silent-correction
		 * bug this plugin has a rule about, dressed as a convenience. */
		?>
		<div class="gwcvt-drawer__repeat">
			<?php echo esc_html( $repeat ); ?>
			<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit only this one', 'groundwork-common-volunteer-tracker' ); ?></a>
			<a href="<?php echo esc_url( gwc_vt_repeat_url( $shift_id ) ); ?>"><?php esc_html_e( 'Change the whole repeat', 'groundwork-common-volunteer-tracker' ); ?></a>
		</div>
	<?php endif; ?>

	<div class="gwcvt-drawer__fill gwcvt-shiftline--<?php echo esc_attr( 'short' === $state ? 'short' : 'full' ); ?>">
		<span class="gwcvt-meter"><span class="gwcvt-meter__bar" style="width: <?php echo esc_attr( (string) $width ); ?>%"></span></span>
		<span class="gwcvt-drawer__fill-label"><?php echo esc_html( gwc_vt_shift_fill_summary( $shift_id, $state ) ); ?></span>
	</div>

	<h3 class="gwcvt-drawer__heading"><?php esc_html_e( 'Who is coming', 'groundwork-common-volunteer-tracker' ); ?></h3>

	<ul class="gwcvt-drawer__roster">
		<?php if ( ! $roster && ! $waiting ) : ?>
			<li class="gwcvt-drawer__nobody"><?php esc_html_e( 'Nobody yet.', 'groundwork-common-volunteer-tracker' ); ?></li>
		<?php endif; ?>

		<?php foreach ( $roster as $signup_id ) : ?>
			<li>
				<span><?php echo esc_html( gwc_vt_signup_name( (int) $signup_id ) ); ?></span>
				<span class="gwcvt-drawer__standing"><?php esc_html_e( 'On the roster', 'groundwork-common-volunteer-tracker' ); ?></span>
			</li>
		<?php endforeach; ?>

		<?php foreach ( $waiting as $signup_id ) : ?>
			<li>
				<span><?php echo esc_html( gwc_vt_signup_name( (int) $signup_id ) ); ?></span>
				<?php
				/* The waiting list is the one standing that is also a question,
				 * so it links to the screen that can answer it rather than
				 * repeating the promote control in a rail. */
				?>
				<span class="gwcvt-drawer__standing">
					<a href="<?php echo esc_url( $edit_url . '#gwcvt-roster' ); ?>">
						<?php esc_html_e( 'Waiting list — promote?', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! $cancelled && ! $ended && current_user_can( 'edit_posts' ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-drawer__add">
			<input type="hidden" name="action" value="gwc_vt_roster_add" />
			<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_roster_add_' . $shift_id ); ?>

			<?php
			/* Where to come back to. Not a URL — a URL in a form field is an
			 * open redirect waiting to be found, and wp_safe_redirect() only
			 * catches the off-site half of that. Two known values the redirect
			 * rebuilds a schedule URL from instead. */
			?>
			<?php if ( '' !== $back ) : ?>
				<input type="hidden" name="gwc_vt_back" value="<?php echo esc_attr( $back ); ?>" />
				<?php if ( '' !== $month ) : ?>
					<input type="hidden" name="gwc_vt_back_month" value="<?php echo esc_attr( $month ); ?>" />
				<?php endif; ?>
			<?php endif; ?>

			<label class="screen-reader-text" for="gwcvt-drawer-name"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></label>
			<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
				<input
					type="text"
					id="gwcvt-drawer-name"
					autocomplete="off"
					role="combobox"
					aria-expanded="false"
					aria-autocomplete="list"
					aria-controls="gwcvt-drawer-results"
					placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
				/>
				<input type="hidden" name="gwc_vt_volunteer" value="0" />
				<ul id="gwcvt-drawer-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
			</div>

			<button type="submit" class="button"><?php esc_html_e( 'Add them', 'groundwork-common-volunteer-tracker' ); ?></button>
		</form>
	<?php endif; ?>

	<div class="gwcvt-drawer__actions">
		<?php if ( $ended && ! $cancelled ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( gwc_vt_shift_log_url( $shift_id ) ); ?>">
				<?php
				echo gwc_vt_shift_is_reconciled( $shift_id )
					? esc_html__( 'Log more hours', 'groundwork-common-volunteer-tracker' )
					: esc_html__( 'Log the hours', 'groundwork-common-volunteer-tracker' );
				?>
			</a>
		<?php endif; ?>

		<a class="button" href="<?php echo esc_url( $edit_url ); ?>">
			<?php esc_html_e( 'Edit and roster', 'groundwork-common-volunteer-tracker' ); ?>
		</a>

		<?php if ( $roster ) : ?>
			<a class="button" href="<?php echo esc_url( gwc_vt_roster_print_url( $shift_id ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( ! $cancelled && current_user_can( 'publish_posts' ) ) : ?>
			<?php
			/* A link to the editor's own call-off form, not a button that calls
			 * it off. That form asks why and offers to tell everybody signed up,
			 * and those two questions ARE the confirmation. */
			?>
			<a class="button gwcvt-drawer__danger" href="<?php echo esc_url( $edit_url . '#gwcvt-danger' ); ?>">
				<?php esc_html_e( 'Call it off…', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Who is coming, and the box for adding somebody.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_shift_roster( int $shift_id ): void {
	$roster  = gwc_vt_shift_signup_ids( $shift_id );
	$waiting = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) );
	$gone    = gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WITHDRAWN ) );
	?>
	<h2 id="gwcvt-roster"><?php esc_html_e( 'Who is coming', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<?php gwc_vt_render_roster_credential_notice( $shift_id ); ?>

	<table class="widefat striped gwcvt-roster">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contact', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Signed up', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Standing', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col" class="gwcvt-roster__actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $roster && ! $waiting && ! $gone ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nobody yet.', 'groundwork-common-volunteer-tracker' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $roster as $signup_id ) : ?>
				<?php gwc_vt_render_roster_row( $signup_id, __( 'On the roster', 'groundwork-common-volunteer-tracker' ), true ); ?>
			<?php endforeach; ?>

			<?php foreach ( $waiting as $signup_id ) : ?>
				<?php gwc_vt_render_roster_row( $signup_id, __( 'Waiting list', 'groundwork-common-volunteer-tracker' ), true ); ?>
			<?php endforeach; ?>

			<?php foreach ( $gone as $signup_id ) : ?>
				<?php gwc_vt_render_roster_row( $signup_id, __( 'Withdrew', 'groundwork-common-volunteer-tracker' ), false ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $roster ) : ?>
		<p>
			<a class="button" href="<?php echo esc_url( gwc_vt_roster_print_url( $shift_id ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Print the roster', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<span class="description"><?php esc_html_e( 'The sheet for the clipboard. Bring it back marked up and type it into Log a day.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Put somebody on this shift', 'groundwork-common-volunteer-tracker' ); ?></h3>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-roster-add">
		<input type="hidden" name="action" value="gwc_vt_roster_add" />
		<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
		<?php wp_nonce_field( 'gwc_vt_roster_add_' . $shift_id ); ?>

		<label class="screen-reader-text" for="gwcvt-roster-name"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></label>
		<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
			<input
				type="text"
				id="gwcvt-roster-name"
				class="regular-text"
				autocomplete="off"
				role="combobox"
				aria-expanded="false"
				aria-autocomplete="list"
				aria-controls="gwcvt-roster-results"
				placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
			/>
			<input type="hidden" name="gwc_vt_volunteer" value="0" />
			<ul id="gwcvt-roster-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
		</div>

		<?php submit_button( __( 'Add them', 'groundwork-common-volunteer-tracker' ), 'secondary', 'submit', false ); ?>

		<p class="description">
			<?php esc_html_e( 'Most signups at this size are a phone call. Somebody who is not on file yet needs a volunteer record first.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</form>
	<?php
}

/**
 * One person on the roster.
 *
 * @param int    $signup_id Signup post ID.
 * @param string $standing  What to call their status.
 * @param bool   $removable Whether to offer the remove action.
 */
function gwc_vt_render_roster_row( int $signup_id, string $standing, bool $removable ): void {
	/* The emptiness test is on what gwc_vt_local_date() returns, not on the raw
	 * meta. A GWC_VT_SIGNUP_CREATED that is non-empty but unparseable — a
	 * partially anonymized record, a hand-edited meta row, a value from an older
	 * format — makes the helper return '' while the raw value is still truthy,
	 * so testing the raw value rendered an empty cell where the em-dash belongs.
	 * The event roster had the same test against the raw meta with the helper
	 * inlined and unguarded, and printed 1 January 1970. */
	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );
	$email        = gwc_vt_signup_email( $signup_id );
	$signed_up    = gwc_vt_local_date( (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CREATED, true ) );
	?>
	<tr>
		<td>
			<?php if ( $volunteer_id > 0 ) : ?>
				<a href="<?php echo esc_url( get_edit_post_link( $volunteer_id ) ); ?>">
					<?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?>
			<?php endif; ?>
			<?php gwc_vt_render_roster_credential_flag( $signup_id, (int) wp_get_post_parent_id( $signup_id ) ); ?>
		</td>
		<td>
			<?php if ( '' !== $email ) : ?>
				<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
			<?php else : ?>
				<span aria-hidden="true">—</span>
			<?php endif; ?>
		</td>
		<td><?php echo esc_html( '' !== $signed_up ? $signed_up : '—' ); ?></td>
		<td><?php echo esc_html( $standing ); ?></td>
		<td class="gwcvt-roster__actions">
			<?php if ( $removable ) : ?>
				<a
					class="gwcvt-roster__remove"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gwc_vt_roster_remove&gwc_vt_signup=' . $signup_id ), 'gwc_vt_roster_remove_' . $signup_id ) ); ?>"
					aria-label="
					<?php
					/* translators: %s: a person's name. */
					echo esc_attr( sprintf( __( 'Take %s off this shift', 'groundwork-common-volunteer-tracker' ), gwc_vt_signup_name( $signup_id ) ) );
					?>
					"
				>
					<?php esc_html_e( 'Take off', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Cancelling and deleting, kept apart from everything above.
 *
 * ── Cancel and delete are not the same button ────────────────────────────────
 * Cancelling says the shift was called off, and everybody who signed up can see
 * that it was. Deleting says it never existed, which is only true of a shift
 * somebody typed by mistake.
 *
 * A shift with people on it is cancelled, never deleted, and the screen only
 * offers delete when the roster is empty. Once notices land, cancelling is also
 * what tells the six people not to drive across town.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_shift_danger_zone( int $shift_id ): void {
	$cancelled = gwc_vt_shift_is_cancelled( $shift_id );
	$rostered  = gwc_vt_shift_filled( $shift_id ) + count( gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) ) );

	if ( ! current_user_can( 'publish_posts' ) ) {
		return;
	}
	?>
	<h2 id="gwcvt-danger"><?php esc_html_e( 'Calling it off', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<?php if ( ! $cancelled ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-shift-cancel">
			<input type="hidden" name="action" value="gwc_vt_cancel_shift" />
			<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_cancel_shift_' . $shift_id ); ?>

			<p>
				<label for="gwcvt-cancel-reason"><?php esc_html_e( 'Why it was canceled', 'groundwork-common-volunteer-tracker' ); ?></label><br />
				<input type="text" id="gwcvt-cancel-reason" name="gwc_vt_reason" class="regular-text" maxlength="<?php echo esc_attr( (string) GWC_VT_SHIFT_REASON_MAX ); ?>" />
			</p>

			<?php if ( $rostered > 0 ) : ?>
				<p>
					<label for="gwcvt-cancel-notify">
						<input type="checkbox" id="gwcvt-cancel-notify" name="gwc_vt_notify" value="1" checked="checked" />
						<?php
						printf(
							/* translators: %d: how many people are signed up. */
							esc_html( _n( 'Email the %d person signed up', 'Email the %d people signed up', $rostered, 'groundwork-common-volunteer-tracker' ) ),
							(int) $rostered
						);
						?>
					</label>
					<?php
					/* Selected, and shown with a count, because the failure this
					 * prevents is somebody driving across town to a locked door.
					 * A checkbox rather than automatic, because a coordinator
					 * should know a mass email is about to leave — and because a
					 * shift cancelled a month out for reasons everyone already
					 * knows about does not need one. */
					?>
					<span class="description"><?php esc_html_e( 'Anybody with an address on file is told it is off, and why.', 'groundwork-common-volunteer-tracker' ); ?></span>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'The shift stays on the schedule marked as canceled, so it is clear it was called off rather than never planned.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php
			submit_button(
				__( 'Cancel this shift', 'groundwork-common-volunteer-tracker' ),
				'delete',
				'submit',
				false,
				array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Cancel this shift?', 'groundwork-common-volunteer-tracker' ) ) . ');' )
			);
			?>
		</form>
	<?php endif; ?>

	<?php if ( 0 === $rostered ) : ?>
		<p>
			<a
				class="gwcvt-shift-delete"
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gwc_vt_delete_shift&gwc_vt_shift=' . $shift_id ), 'gwc_vt_delete_shift_' . $shift_id ) ); ?>"
				onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this shift? Nobody is signed up, so nothing else is affected.', 'groundwork-common-volunteer-tracker' ) ) ); ?>);"
			>
				<?php esc_html_e( 'Delete this shift', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			<span class="description"><?php esc_html_e( 'Only offered while nobody is signed up. A shift people committed to is canceled, not erased.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</p>
	<?php endif; ?>
	<?php
}

/* ── Handlers ────────────────────────────────────────────────────────────────
 * Capability check first, then the nonce, as everywhere else in this plugin. A
 * nonce failure on a request somebody was never allowed to make is a 403 dressed
 * up as an expired page.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Create or update a shift, and materialise a repeat.
 */
function gwc_vt_handle_save_shift(): void {
	gwc_vt_require_shift_cap();

	$shift_id = isset( $_POST['gwc_vt_shift'] ) ? absint( wp_unslash( $_POST['gwc_vt_shift'] ) ) : 0;

	check_admin_referer( 'gwc_vt_save_shift_' . $shift_id );

	$posted = wp_unslash( $_POST );

	$date = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_date'] ?? '' ) ) );

	if ( '' === $date ) {
		gwc_vt_shift_redirect( $shift_id, 'bad-date' );
	}

	$start     = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $posted['gwc_vt_start'] ?? '' ) ) );
	$end       = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $posted['gwc_vt_end'] ?? '' ) ) );
	$overnight = ! empty( $posted['gwc_vt_overnight'] );

	if ( gwc_vt_shift_duration( $start, $end, $overnight ) < 1 ) {
		gwc_vt_shift_redirect( $shift_id, 'bad-time' );
	}

	$fields = array(
		GWC_VT_SHIFT_START      => $start,
		GWC_VT_SHIFT_END        => $end,
		GWC_VT_SHIFT_OVERNIGHT  => gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, $overnight ),
		GWC_VT_SHIFT_ACTIVITY   => mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_activity'] ?? '' ) ), 0, 200 ),
		GWC_VT_SHIFT_SUPERVISOR => mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_supervisor'] ?? '' ) ), 0, 100 ),
		GWC_VT_SHIFT_LOCATION   => mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_location'] ?? '' ) ), 0, 200 ),
		GWC_VT_SHIFT_NOTES      => mb_substr( sanitize_textarea_field( (string) ( $posted['gwc_vt_notes'] ?? '' ) ), 0, 1000 ),
		GWC_VT_SHIFT_MIN        => gwc_vt_shift_meta_value( GWC_VT_SHIFT_MIN, $posted['gwc_vt_min'] ?? 0 ),
		GWC_VT_SHIFT_MAX        => gwc_vt_shift_meta_value( GWC_VT_SHIFT_MAX, $posted['gwc_vt_max'] ?? 0 ),
	);

	/* Read here and written separately at both points below. NOT a member of
	 * $fields — see gwc_vt_posted_credential_ids() for why that would put the
	 * word "Array" in the database. */
	$credentials = gwc_vt_posted_credential_ids( $posted );

	$status = empty( $posted['gwc_vt_published'] ) ? 'draft' : 'publish';

	/* Editing an existing shift. One occurrence, whatever series it belongs to —
	 * see inc/recurrence.php on why every occurrence is its own row. */
	if ( $shift_id > 0 ) {
		if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
			gwc_vt_shift_redirect( 0, 'not-found' );
		}

		/* Read before anything is written, so the notice can quote what the shift
		 * used to be. A message that says only "the details have changed" makes
		 * the reader go and find the original to work out what — and what changed
		 * is usually what decides whether they can still come. */
		$was = gwc_vt_shift_snapshot( $shift_id );

		/* A cancelled shift keeps its status through an edit. Un-cancelling is not
		 * a checkbox — it would mean telling everybody it is back on, which is a
		 * decision, not a side effect of fixing a typo in the location. */
		if ( ! gwc_vt_shift_is_cancelled( $shift_id ) ) {
			wp_update_post(
				array(
					'ID'          => $shift_id,
					'post_status' => $status,
				)
			);
		}

		update_post_meta( $shift_id, GWC_VT_SHIFT_DATE, $date );

		foreach ( $fields as $key => $value ) {
			update_post_meta( $shift_id, $key, $value );
		}

		gwc_vt_set_shift_credentials( $shift_id, $credentials );

		gwc_vt_retitle_shift( $shift_id );

		/* ── Telling the roster ─────────────────────────────────────────────
		 * Only when something material moved, only when the shift is still to
		 * come, only when somebody is on it, and only when the coordinator left
		 * the checkbox selected. A mass mail must never be a side effect of correcting
		 * a typo in the supervisor's name. */
		$told = 0;

		if ( ! empty( $posted['gwc_vt_notify'] ) && gwc_vt_shift_moved( $shift_id, $was ) && ! gwc_vt_shift_has_ended( $shift_id ) ) {
			foreach ( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $signup_id ) {
				gwc_vt_queue_signup_mail( 'changed', (int) $signup_id, array( 'was' => $was ) );
				++$told;
			}
		}

		gwc_vt_shift_redirect( $shift_id, 'saved', $told > 0 ? array( 'gwc_vt_told' => $told ) : array() );
	}

	$pattern = sanitize_key( (string) ( $posted['gwc_vt_repeat'] ?? 'once' ) );
	$until   = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_until'] ?? '' ) ) );

	$occurrences = gwc_vt_recurrence_dates( $date, $pattern, $until );

	if ( ! $occurrences['dates'] ) {
		gwc_vt_shift_redirect( 0, 'no-dates' );
	}

	$series = 0;
	$made   = 0;
	$first  = 0;

	foreach ( $occurrences['dates'] as $occurrence ) {
		$new_id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SHIFT_TYPE,
				'post_status' => $status,
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			continue;
		}

		$new_id = (int) $new_id;

		update_post_meta( $new_id, GWC_VT_SHIFT_DATE, $occurrence );

		foreach ( $fields as $key => $value ) {
			update_post_meta( $new_id, $key, $value );
		}

		/* The first occurrence's own ID is the series. A generated identifier
		 * would be one more thing to keep unique; the row that started it is
		 * already unique and already means something when you see it. */
		if ( 0 === $series ) {
			$series = $new_id;
			$first  = $new_id;
		}

		update_post_meta( $new_id, GWC_VT_SHIFT_SERIES, $series );

		/* Inside the loop, and that is the whole point. $fields is copied to
		 * every occurrence; credentials are not in it, so writing them once
		 * after the loop would give a weekly series twenty Saturdays asking for
		 * nothing and one asking for the waiver. */
		gwc_vt_set_shift_credentials( $new_id, $credentials );

		gwc_vt_retitle_shift( $new_id );

		/**
		 * Fires after a shift has been put on the schedule.
		 *
		 * @param int $shift_id The new shift.
		 * @param int $series   The ID of the first shift in its series.
		 */
		do_action( 'gwc_vt_shift_created', $new_id, $series );

		++$made;
	}

	if ( 0 === $made ) {
		gwc_vt_shift_redirect( 0, 'no-dates' );
	}

	gwc_vt_shift_redirect(
		1 === $made ? $first : 0,
		'created',
		array(
			'gwc_vt_count'  => $made,
			'gwc_vt_capped' => $occurrences['capped'],
		)
	);
}

/**
 * Call a shift off.
 */
function gwc_vt_handle_cancel_shift(): void {
	gwc_vt_require_shift_cap( 'publish_posts' );

	$shift_id = isset( $_POST['gwc_vt_shift'] ) ? absint( wp_unslash( $_POST['gwc_vt_shift'] ) ) : 0;

	check_admin_referer( 'gwc_vt_cancel_shift_' . $shift_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		gwc_vt_shift_redirect( 0, 'not-found' );
	}

	$reason = mb_substr( sanitize_text_field( (string) wp_unslash( $_POST['gwc_vt_reason'] ?? '' ) ), 0, GWC_VT_SHIFT_REASON_MAX );

	/* gwc_vt_call_off_slot() rather than the same body written out again. It
	 * reads the roster before the status changes, writes the reason, mails
	 * whoever is on it and fires gwc_vt_shift_cancelled — and it returns the
	 * count this redirect wants. It also refuses a shift that is already
	 * cancelled, which this handler did not: the form is hidden once a shift is
	 * called off, but the handler is reachable on its own, and a second POST
	 * re-mailed the whole roster and overwrote the reason. */
	$told = gwc_vt_call_off_slot( $shift_id, $reason, ! empty( $_POST['gwc_vt_notify'] ) );

	gwc_vt_shift_redirect( $shift_id, 'cancelled', $told > 0 ? array( 'gwc_vt_told' => $told ) : array() );
}

/**
 * Delete a shift nobody signed up for.
 */
function gwc_vt_handle_delete_shift(): void {
	gwc_vt_require_shift_cap( 'publish_posts' );

	// Verified immediately below against this value.
	$shift_id = isset( $_GET['gwc_vt_shift'] ) ? absint( wp_unslash( $_GET['gwc_vt_shift'] ) ) : 0;

	check_admin_referer( 'gwc_vt_delete_shift_' . $shift_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		gwc_vt_shift_redirect( 0, 'not-found' );
	}

	/* Re-checked rather than trusted from the screen that offered the link. The
	 * roster can have gained somebody between the page rendering and the click,
	 * and deleting a shift out from under them is not recoverable by them. */
	if ( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) ) {
		gwc_vt_shift_redirect( $shift_id, 'has-roster' );
	}

	wp_delete_post( $shift_id, true );

	/* The settling lock is named after the shift, so a shift that goes away takes
	 * its lock with it. It would have expired on its own within half a minute,
	 * but a row named after a post that no longer exists is the kind of litter
	 * nobody ever goes looking for. */
	gwc_vt_release_signup_lock( $shift_id );

	gwc_vt_shift_redirect( 0, 'deleted' );
}

/**
 * Put a volunteer on a shift by hand.
 */
function gwc_vt_handle_roster_add(): void {
	gwc_vt_require_shift_cap();

	$shift_id = isset( $_POST['gwc_vt_shift'] ) ? absint( wp_unslash( $_POST['gwc_vt_shift'] ) ) : 0;

	check_admin_referer( 'gwc_vt_roster_add_' . $shift_id );

	$volunteer_id = absint( wp_unslash( $_POST['gwc_vt_volunteer'] ?? 0 ) );

	if ( $volunteer_id < 1 ) {
		gwc_vt_shift_redirect( $shift_id, 'no-volunteer' );
	}

	/* Asked before the write, and asked here rather than inside
	 * gwc_vt_add_signup() — the reasoning is in inc/credential-shifts.php. */
	$refused = gwc_vt_signup_credential_refusal( $volunteer_id, $shift_id );

	$reason = mb_substr(
		trim( sanitize_text_field( (string) wp_unslash( $_POST['gwc_vt_override_reason'] ?? '' ) ) ),
		0,
		GWC_VT_OVERRIDE_REASON_MAX
	);

	if ( $refused && '' === $reason ) {
		/* The names travel in the URL so the screen can say what is missing.
		 * A refusal that says only "they cannot be added" sends a coordinator
		 * to the volunteer's record to work out which of four things it was. */
		gwc_vt_shift_redirect(
			$shift_id,
			'credential-blocked',
			array(
				'gwc_vt_volunteer' => $volunteer_id,
				'gwc_vt_short'     => implode( ',', $refused ),
			)
		);
	}

	$signup_id = gwc_vt_add_signup(
		$shift_id,
		array(
			'volunteer_id' => $volunteer_id,
			'source'       => 'staff',
		)
	);

	/* The return value used to be discarded and 'rostered' reported regardless,
	 * so a refused add — a shift that had gone, a volunteer who was already on
	 * it — rendered "Added." over a roster that had not changed. */
	if ( $signup_id < 1 ) {
		gwc_vt_shift_redirect( $shift_id, 'not-rostered' );
	}

	if ( $refused ) {
		gwc_vt_record_override( $signup_id, get_current_user_id(), $reason );

		gwc_vt_shift_redirect( $shift_id, 'rostered-override' );
	}

	gwc_vt_shift_redirect( $shift_id, 'rostered' );
}

/**
 * Take somebody off a shift.
 */
function gwc_vt_handle_roster_remove(): void {
	gwc_vt_require_shift_cap();

	// Verified immediately below against this value.
	$signup_id = isset( $_GET['gwc_vt_signup'] ) ? absint( wp_unslash( $_GET['gwc_vt_signup'] ) ) : 0;

	check_admin_referer( 'gwc_vt_roster_remove_' . $signup_id );

	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	gwc_vt_withdraw_signup( $signup_id );

	gwc_vt_shift_redirect( $shift_id, 'removed' );
}

/* ── The printed roster ──────────────────────────────────────────────────────
 * The sheet that goes on the clipboard. Served from admin-post.php rather than a
 * front-end URL for the same reason the letter is: it is a list of names, email
 * addresses and phone numbers of volunteers, and a URL for that is a URL that
 * leaks. A session is required, and nothing about it is cacheable or indexable.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The link to a shift's printable roster.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_roster_print_url( int $shift_id ): string {
	return wp_nonce_url(
		admin_url( 'admin-post.php?action=gwc_vt_roster_print&gwc_vt_shift=' . $shift_id ),
		'gwc_vt_roster_print_' . $shift_id
	);
}

/**
 * Render the roster as a standalone document.
 */
function gwc_vt_handle_roster_print(): void {
	gwc_vt_require_shift_cap();

	// Verified immediately below against this value.
	$shift_id = isset( $_GET['gwc_vt_shift'] ) ? absint( wp_unslash( $_GET['gwc_vt_shift'] ) ) : 0;

	check_admin_referer( 'gwc_vt_roster_print_' . $shift_id );

	if ( GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		wp_die(
			esc_html__( 'That shift no longer exists.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Not found', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 404 )
		);
	}

	gwc_vt_private_document_headers();

	gwc_vt_render_roster_document( $shift_id );
	exit;
}

/**
 * The roster document itself.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_roster_document( int $shift_id ): void {
	$roster = gwc_vt_shift_signup_ids( $shift_id );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow, noarchive" />
	<title><?php echo esc_html( get_the_title( $shift_id ) ); ?></title>
	<?php gwc_vt_print_document_styles(); ?>
</head>
<body class="gwcvt-roster-print">
	<h1><?php echo esc_html( gwc_vt_org_name() ); ?></h1>

	<h2><?php echo esc_html( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) ); ?></h2>

	<p>
		<strong><?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?></strong>,
		<?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?>
		<?php
		$location = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true );

		if ( '' !== $location ) {
			echo '<br />' . esc_html( $location );
		}

		$supervisor = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_SUPERVISOR, true );

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
					<td><?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?></td>
					<td><?php echo esc_html( gwc_vt_signup_email( $signup_id ) ); ?></td>
					<td></td>
					<td></td>
					<td></td>
				</tr>
			<?php endforeach; ?>

			<?php
			/* Blank rows for the people who turn up without having signed up,
			 * which on a Saturday is most of the interesting ones. */
			for ( $i = 0; $i < 6; $i++ ) :
				?>
				<tr><td></td><td></td><td></td><td></td><td></td></tr>
			<?php endfor; ?>
		</tbody>
	</table>

	<p><?php esc_html_e( 'Hours from this sheet still need typing up and verifying before they can appear on a letter.', 'groundwork-common-volunteer-tracker' ); ?></p>
</body>
</html>
	<?php
}

/* ── Shared bits ─────────────────────────────────────────────────────────── */

/**
 * Stop here unless the current user may manage the schedule.
 *
 * A wp_die() rather than a return value, so a caller cannot forget to check what
 * came back. The same shape as gwc_vt_require_cap().
 *
 * @param string $capability Which capability to insist on.
 */
function gwc_vt_require_shift_cap( string $capability = 'edit_posts' ): void {
	if ( current_user_can( $capability ) ) {
		return;
	}

	wp_die(
		esc_html__( 'You do not have permission to manage the schedule.', 'groundwork-common-volunteer-tracker' ),
		esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
		array( 'response' => 403 )
	);
}

/**
 * Back to the schedule, or to one shift, with a word about what happened.
 *
 * @param int    $shift_id Shift to return to, or 0 for the list.
 * @param string $result   What to say.
 * @param array  $extra    Further query arguments.
 */
function gwc_vt_shift_redirect( int $shift_id, string $result, array $extra = array() ): void {
	$args = array_merge(
		array( 'gwc_vt_shift_result' => $result ),
		$extra
	);

	/* Somebody who added a name from the drawer wanted to stay on the calendar.
	 * Sending them to the shift editor instead is the page load the drawer
	 * exists to remove — they came to fill Saturday, not to look at it.
	 *
	 * Rebuilt from two known values rather than from a URL the form carried. A
	 * URL in a form field is an open redirect waiting to be found, and
	 * wp_safe_redirect() only catches the off-site half of that: it would
	 * happily send somebody to any admin screen this plugin never meant to
	 * name. */
	$back = isset( $_REQUEST['gwc_vt_back'] ) ? sanitize_key( wp_unslash( $_REQUEST['gwc_vt_back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- every caller has already checked its own nonce; this only decides which screen to land on.

	if ( in_array( $back, array( 'month', 'list' ), true ) ) {
		if ( 'month' === $back ) {
			$args['view'] = 'month';

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
			$month = isset( $_REQUEST['gwc_vt_back_month'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['gwc_vt_back_month'] ) ) : '';

			if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
				$args['gwc_vt_month'] = $month;
			}
		}

		/* And reopen the drawer on the shift they were working on, so the next
		 * name goes in the same box rather than after another two clicks. */
		if ( $shift_id > 0 ) {
			$args['gwc_vt_open'] = $shift_id;
		}

		foreach ( $args as $key => $value ) {
			if ( '' === $value ) {
				unset( $args[ $key ] );
			}
		}

		wp_safe_redirect( gwc_vt_schedule_url( $args ) );
		exit;
	}

	if ( $shift_id > 0 ) {
		$args['shift'] = $shift_id;
	}

	/* Dropped by hand rather than with array_filter( …, 'strlen' ): the count is
	 * an integer, and passing one to strlen() is deprecated in PHP 8.1 — which
	 * this plugin's own test suite would fail on, since it fails on deprecations. */
	foreach ( $args as $key => $value ) {
		if ( '' === $value ) {
			unset( $args[ $key ] );
		}
	}

	wp_safe_redirect( gwc_vt_schedule_url( $args ) );
	exit;
}

/**
 * The places a shift can happen, if the site has listed any.
 *
 * The same shape as gwc_vt_activity_vocabulary(): a newline-separated setting
 * that becomes a datalist, so typing is suggested rather than constrained.
 *
 * @return string[]
 */
function gwc_vt_location_vocabulary(): array {
	$raw = (string) gwc_vt_setting( 'shift_locations' );

	// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_split() returns false on failure, not null, so ?? would not catch it; spelling it out would run the split twice.
	$lines = array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ?: array() ), 'strlen' );

	return array_values( $lines );
}

/* ── What this shift asks people to hold ─────────────────────────────────────
 * Drawn only when the organization has defined something to ask for. A field
 * offering an empty list of credentials is a field that teaches a coordinator
 * this plugin has a section they can ignore.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The credentials field on the shift form.
 *
 * @param int $shift_id Shift post ID, 0 for a new one.
 */
function gwc_vt_render_shift_credentials_field( int $shift_id ): void {
	$live = gwc_vt_live_credential_ids();

	if ( ! $live ) {
		return;
	}

	$chosen = gwc_vt_shift_credential_ids( $shift_id );

	/* An event's own credentials, shown but not editable here. A slot that
	 * inherits the day's waiver must say so, or a coordinator looking at a
	 * kitchen role with one box selected reads it as the only thing asked for
	 * and is wrong. Editing them belongs on the event, which is where they were
	 * set. */
	$event_id  = $shift_id > 0 ? (int) wp_get_post_parent_id( $shift_id ) : 0;
	$inherited = $event_id > 0 && GWC_VT_EVENT_TYPE === get_post_type( $event_id )
		? gwc_vt_shift_credential_ids( $event_id )
		: array();
	?>
	<div class="gwcvt-shift-field gwcvt-shift-field--wide gwcvt-shift-credentials">
		<span class="gwcvt-shift-credentials__label"><?php esc_html_e( 'They have to hold', 'groundwork-common-volunteer-tracker' ); ?></span>

		<?php
		/* A fieldset, and a div around the boxes rather than a p — the wrapper
		 * rule this plugin has, and for the usual reason: a list inside a
		 * paragraph closes it, silently. */
		?>
		<div class="gwcvt-shift-credentials__list">
			<?php foreach ( $live as $credential_id ) : ?>
				<?php $credential = gwc_vt_credential( (int) $credential_id ); ?>
				<?php if ( ! $credential ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<?php $is_inherited = in_array( $credential['id'], $inherited, true ); ?>
				<label class="gwcvt-shift-credentials__item">
					<input
						type="checkbox"
						name="gwc_vt_credentials[]"
						value="<?php echo esc_attr( (string) $credential['id'] ); ?>"
						<?php checked( in_array( $credential['id'], $chosen, true ) ); ?>
						<?php disabled( $is_inherited ); ?>
					/>
					<?php echo esc_html( $credential['name'] ); ?>
					<?php if ( 'block' === $credential['mode'] ) : ?>
						<span class="gwcvt-badge gwcvt-badge--cancelled"><?php esc_html_e( 'stops signup', 'groundwork-common-volunteer-tracker' ); ?></span>
					<?php endif; ?>
					<?php if ( $is_inherited ) : ?>
						<span class="description"><?php esc_html_e( '— asked for by the whole event', 'groundwork-common-volunteer-tracker' ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>

		<p class="description">
			<?php esc_html_e( 'Leave everything clear and this shift asks for nothing. What is missing is flagged on the roster; only the ones marked “stops signup” actually turn anybody away.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</div>
	<?php
}

/* ── Saying somebody is short of something ───────────────────────────────────
 * Drawn beside a name on a roster, in the same place and shape as a
 * double-booking clash — and under the same restraint, which is the harder
 * half. gwc_vt_event_clashes() carries the reasoning in its own comment:
 * reporting a thing too freely trains coordinators to ignore the flag that
 * matters.
 *
 * So three things are deliberately NOT flagged.
 *
 * A shift that has already happened. There is nothing to be done about
 * Saturday's missing waiver on Monday, and a roster of past shifts covered in
 * amber is a screen nobody reads twice.
 *
 * A signup nobody has matched to a volunteer record. We do not know who they
 * are, and hard rule 4 forbids finding out by looking the address up. A row
 * saying "we cannot check this" against every unmatched name is noise; the
 * roster says it once, above the table, and only when it is true.
 *
 * A credential in report mode is drawn exactly as loudly as one in block mode.
 * The difference between them is what happens at signup, not how alarming the
 * fact is once somebody is on the roster — and dimming one of them would be
 * telling a coordinator that a missing waiver matters less than a missing class
 * because of a setting they chose for an unrelated reason.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * What one person on one roster is short of.
 *
 * Shared by both rosters. Lives here because this file owns the roster row it
 * is drawn into; inc/admin-event-roster.php calls it for the same reason it
 * calls this file's promote fallback.
 *
 * @param int $signup_id Signup post ID.
 * @param int $shift_id  The shift they are on.
 */
function gwc_vt_render_roster_credential_flag( int $signup_id, int $shift_id ): void {
	if ( gwc_vt_shift_has_ended( $shift_id ) ) {
		return;
	}

	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );

	if ( $volunteer_id < 1 ) {
		return;
	}

	/* An override first, because it changes what the row means. Somebody put on
	 * a shift over a blocking credential is still short of it — the flag would
	 * be true — but "missing a waiver" and "Dana put them on anyway, and here
	 * is why" are different facts, and showing the first alone reads as nobody
	 * having noticed. */
	$override = gwc_vt_signup_override( $signup_id );

	if ( '' !== $override['reason'] ) {
		$who = $override['by'] > 0 ? get_the_author_meta( 'display_name', $override['by'] ) : '';

		printf(
			'<span class="gwcvt-badge gwcvt-badge--cancelled gwcvt-roster__short">%s</span>',
			esc_html(
				'' !== $who
					? sprintf(
						/* translators: 1: a staff member's name. 2: the reason they gave. */
						__( 'Added by %1$s over a credential: %2$s', 'groundwork-common-volunteer-tracker' ),
						$who,
						$override['reason']
					)
					: sprintf(
						/* translators: %s: the reason given. */
						__( 'Added over a credential: %s', 'groundwork-common-volunteer-tracker' ),
						$override['reason']
					)
			)
		);

		return;
	}

	$missing = gwc_vt_missing_credentials( $volunteer_id, $shift_id );
	$short   = array_merge( $missing['block'], $missing['report'] );

	if ( ! $short ) {
		return;
	}

	printf(
		'<span class="gwcvt-badge gwcvt-badge--waiting gwcvt-roster__short">%s</span>',
		esc_html(
			sprintf(
				/* translators: %s: a list of credential names, already joined with commas. */
				_n(
					'No %s on file',
					'Not on file: %s',
					count( $short ),
					'groundwork-common-volunteer-tracker'
				),
				gwc_vt_credential_names( $short )
			)
		)
	);
}

/**
 * How many people on a roster cannot be checked at all.
 *
 * Said once above the table rather than on every row. Zero when the shift asks
 * for nothing, so a site not using credentials never sees the sentence.
 *
 * @param int $shift_id Shift post ID.
 * @return int
 */
function gwc_vt_roster_unmatched_count( int $shift_id ): int {
	if ( ! gwc_vt_shift_asks_for_credentials( $shift_id ) || gwc_vt_shift_has_ended( $shift_id ) ) {
		return 0;
	}

	$unmatched = 0;

	foreach ( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $signup_id ) {
		if ( (int) get_post_meta( (int) $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true ) < 1 ) {
			++$unmatched;
		}
	}

	return $unmatched;
}

/**
 * The sentence that count deserves.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_roster_credential_notice( int $shift_id ): void {
	$required = gwc_vt_required_credential_ids( $shift_id );

	if ( ! $required || gwc_vt_shift_has_ended( $shift_id ) ) {
		return;
	}

	printf(
		'<p class="description">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: a list of credential names, already joined with commas. */
				__( 'This shift asks for: %s', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_credential_names( $required )
			)
		)
	);

	$unmatched = gwc_vt_roster_unmatched_count( $shift_id );

	if ( $unmatched < 1 ) {
		return;
	}

	printf(
		'<p class="description">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: how many people, already formatted. */
				_n(
					'%s person here has not been matched to a volunteer record, so nothing can be checked for them.',
					'%s people here have not been matched to volunteer records, so nothing can be checked for them.',
					$unmatched,
					'groundwork-common-volunteer-tracker'
				),
				number_format_i18n( $unmatched )
			)
		)
	);
}

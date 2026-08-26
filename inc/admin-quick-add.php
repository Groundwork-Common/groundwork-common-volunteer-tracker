<?php
/**
 * Logging a whole day's shifts in one pass.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** How many volunteer rows the form starts with. */
const GWC_VT_QUICK_ADD_ROWS = 8;

/** How many blank rows sit under a shift's roster, for people who walked in. */
const GWC_VT_QUICK_ADD_WALK_IN_ROWS = 4;

add_action( 'admin_menu', 'gwc_vt_register_quick_add_menu', 12 );
add_action( 'admin_post_gwc_vt_quick_add', 'gwc_vt_handle_quick_add' );
add_action( 'all_admin_notices', 'gwc_vt_render_log_a_day_button' );

/* ── Why this screen exists ──────────────────────────────────────────────────
 * Logging Saturday meant six trips through post-new.php: load the editor, pick
 * a volunteer, type the same date, type the same activity, type the same
 * supervisor, save, wait, go back, repeat. Five of those six fields are
 * identical for every person who worked that shift, and the sixth is the only
 * thing that actually differs.
 *
 * This is the single most repeated task in the product and it was the slowest.
 * So: the things that are the same once at the top, the things that differ in a
 * list, one save.
 *
 * It does not replace the entry editor. A correction, a custom activity for one
 * person, anything with a claim attached — all of that belongs on the record
 * itself. This is for the common case, which is a coordinator with a paper
 * sign-in sheet in front of them typing it up.
 *
 * ── And what it became ──────────────────────────────────────────────────────
 * With ?gwc_vt_shift=<id> this same screen opens against a scheduled shift, and
 * the paper sign-in sheet is already filled in: the date, the activity and the
 * supervisor come off the shift, and there is one row per person who signed up
 * with the hours the shift was scheduled for. The coordinator clears whoever
 * did not turn up, trims whoever left early, adds the walk-ins, and saves once.
 *
 * That is the whole reason the schedule exists. Everything before this is a
 * plan; this is the step where a plan becomes a record, and it is deliberately a
 * step a person takes rather than something a scheduled task does at midnight —
 * see gwc_vt_log_shift_hours() for what that would cost.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The screen's title, said once.
 *
 * Registration needs it, and so does gwc_vt_restore_quick_add_title() below —
 * which exists precisely because the copy registration puts in the menu is no
 * longer there to be read back.
 *
 * @return string
 */
function gwc_vt_quick_add_page_title(): string {
	return __( 'Log a day’s shifts', 'groundwork-common-volunteer-tracker' );
}

/**
 * Add the screen under Volunteer Tracker.
 */
function gwc_vt_register_quick_add_menu(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_quick_add_page_title(),
		__( 'Log a day', 'groundwork-common-volunteer-tracker' ),
		'edit_posts',
		GWC_VT_QUICK_ADD_PAGE,
		'gwc_vt_render_quick_add_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_quick_add_title' );
	}
}

/**
 * Give the screen its title back.
 *
 * Core has no register of page titles. get_admin_page_title() finds one by
 * searching $submenu for the current slug and taking the entry's title off it.
 * gwc_vt_hide_menu_verbs() removes that entry, so the search comes back empty
 * and $title stays null — which cost this screen its <title>, its <h1> on any
 * screen that leans on the global, and, on PHP 8.1 and up, a deprecation
 * notice printed across the top of wp-admin from inside core's own
 * admin-header.php. The page still worked, which is what made it easy to miss.
 *
 * On `load-`, because admin-header.php reads $title and runs after it.
 */
function gwc_vt_restore_quick_add_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_quick_add_page_title();
}

/**
 * The screen's own URL, with no shift attached.
 *
 * @return string
 */
function gwc_vt_quick_add_url(): string {
	return add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_QUICK_ADD_PAGE,
		),
		admin_url( 'edit.php' )
	);
}

/* ── The button that replaced the menu entry ─────────────────────────────────
 * gwc_vt_hide_menu_verbs() takes Log a day off the submenu, so All hours has to
 * offer it instead. WordPress renders its own page-title-action there for the
 * post type — that is the "Log one shift" button, and it comes from the
 * add_new label in inc/cpt.php — but core has no hook between the <h1> and the
 * <hr class="wp-header-end"> that follows it, so a second button cannot be
 * printed into that spot from PHP.
 *
 * So it is printed into the notices area, which is the nearest hook that is
 * unambiguously ours to write to, and assets/js/admin-title-actions.js lifts it
 * up beside the heading. The script moves a link that is already on the screen
 * and already works; with JavaScript off the button sits above the heading
 * instead of beside it and does the same thing. That is the difference between
 * enhancement and carriage, and it is why the anchor is rendered here rather
 * than written by the script.
 *
 * Above, not below: all_admin_notices fires before <div class="wrap"> opens,
 * so the no-JS button lands outside the column the rest of the screen lines up
 * with — and core styles page-title-action as `.wrap .page-title-action`, so
 * outside a .wrap it is not a button at all, just an underlined link floating
 * over the heading. Hence the holder carrying core's own `wrap` class: it earns
 * both the gutter and the button back without this plugin copying out a single
 * one of core's declarations to drift from later.
 *
 * The alternative hooks are all worse. The only two that fire inside .wrap on
 * this screen are views_edit-<type>, which would make the button an <li> in the
 * "All (0)" list with a separating pipe after it, and restrict_manage_posts,
 * which would file a page action among the filter controls.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * "Log a day", on the All hours screen.
 */
function gwc_vt_render_log_a_day_button(): void {
	$screen = get_current_screen();

	if ( ! $screen || 'edit' !== $screen->base || GWC_VT_ENTRY_TYPE !== $screen->post_type ) {
		return;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	?>
	<div class="wrap gwcvt-title-actions" data-gwcvt-title-actions>
		<a href="<?php echo esc_url( gwc_vt_quick_add_url() ); ?>" class="page-title-action">
			<?php esc_html_e( 'Log a day', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
	</div>
	<?php
}

/**
 * The screen, against a shift or against a blank day.
 */
function gwc_vt_render_quick_add_screen(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to log hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; chooses which shift to log against.
	$shift_id = isset( $_GET['gwc_vt_shift'] ) ? absint( wp_unslash( $_GET['gwc_vt_shift'] ) ) : 0;

	if ( $shift_id > 0 && GWC_VT_SHIFT_TYPE === get_post_type( $shift_id ) ) {
		gwc_vt_render_shift_log_screen( $shift_id );
		return;
	}

	gwc_vt_render_blank_day_screen();
}

/**
 * The original screen: a date, an activity, a supervisor, and eight blank rows.
 */
function gwc_vt_render_blank_day_screen(): void {
	$vocabulary = gwc_vt_activity_vocabulary();
	$max_date   = gwc_vt_setting( 'allow_future_dates' ) ? '' : gwc_vt_today();
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Log a day’s shifts', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php gwc_vt_quick_add_notice(); ?>

		<p class="description gwcvt-quick-add__intro">
			<?php esc_html_e( 'For typing up a sign-in sheet. Everything the shift had in common goes at the top; add each volunteer and the hours they worked below. They arrive unverified, the same as any other entry.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-quick-add">
			<input type="hidden" name="action" value="gwc_vt_quick_add" />
			<?php wp_nonce_field( 'gwc_vt_quick_add' ); ?>

			<h2><?php esc_html_e( 'The shift', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-qa-date"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input
								type="date"
								id="gwcvt-qa-date"
								name="gwc_vt_date"
								required
								value="<?php echo esc_attr( gwc_vt_today() ); ?>"
								<?php echo '' !== $max_date ? 'max="' . esc_attr( $max_date ) . '"' : ''; ?>
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-qa-activity"><?php esc_html_e( 'What they did', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-qa-activity" name="gwc_vt_activity" class="regular-text" maxlength="200" <?php echo $vocabulary ? 'list="gwcvt-qa-activities"' : ''; ?> />
							<?php if ( $vocabulary ) : ?>
								<datalist id="gwcvt-qa-activities">
									<?php foreach ( $vocabulary as $option ) : ?>
										<option value="<?php echo esc_attr( $option ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Appears on every letter these hours reach.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-qa-supervisor"><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-qa-supervisor" name="gwc_vt_supervisor" class="regular-text" maxlength="100" value="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>" />
							<p class="description"><?php esc_html_e( 'The person who was there. Prefilled with your name — change it if it was somebody else.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Who worked it', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="widefat striped gwcvt-quick-add__people">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col" class="gwcvt-quick-add__hours"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody id="gwcvt-qa-rows">
					<?php for ( $i = 0; $i < GWC_VT_QUICK_ADD_ROWS; $i++ ) : ?>
						<?php gwc_vt_render_quick_add_row( $i ); ?>
					<?php endfor; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="gwcvt-qa-more"><?php esc_html_e( 'Add more rows', 'groundwork-common-volunteer-tracker' ); ?></button>
			</p>

			<p class="description">
				<?php esc_html_e( 'Leave a row empty to skip it. Hours accept 3.5, 3:30, 3h 30m or 210m.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php submit_button( __( 'Log these shifts', 'groundwork-common-volunteer-tracker' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * One volunteer row.
 *
 * @param int  $index    Row index.
 * @param bool $walk_in  Whether this row sits under a shift's roster, which has
 *                       a leading "Came" column the row has to line up with.
 */
function gwc_vt_render_quick_add_row( int $index, bool $walk_in = false ): void {
	?>
	<tr class="gwcvt-quick-add__row">
		<?php if ( $walk_in ) : ?>
			<?php
			/* No checkbox: a blank row is not somebody who failed to turn up,
					* it is nobody. Filling it in is the whole signal. */
			?>
			<td class="gwcvt-quick-add__came" aria-hidden="true"></td>
		<?php endif; ?>
		<td>
			<label class="screen-reader-text" for="gwcvt-qa-name-<?php echo esc_attr( (string) $index ); ?>">
				<?php
				printf(
					/* translators: %d: a row number. */
					esc_html__( 'Volunteer for row %d', 'groundwork-common-volunteer-tracker' ),
					(int) $index + 1
				);
				?>
			</label>
			<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
				<input
					type="text"
					id="gwcvt-qa-name-<?php echo esc_attr( (string) $index ); ?>"
					class="regular-text"
					autocomplete="off"
					role="combobox"
					aria-expanded="false"
					aria-autocomplete="list"
					aria-controls="gwcvt-qa-results-<?php echo esc_attr( (string) $index ); ?>"
					placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
				/>
				<input type="hidden" name="gwc_vt_volunteer[]" value="0" />
				<ul id="gwcvt-qa-results-<?php echo esc_attr( (string) $index ); ?>" class="gwcvt-picker__results" role="listbox" hidden></ul>
			</div>
		</td>
		<td class="gwcvt-quick-add__hours">
			<label class="screen-reader-text" for="gwcvt-qa-hours-<?php echo esc_attr( (string) $index ); ?>">
				<?php
				printf(
					/* translators: %d: a row number. */
					esc_html__( 'Hours for row %d', 'groundwork-common-volunteer-tracker' ),
					(int) $index + 1
				);
				?>
			</label>
			<input type="text" id="gwcvt-qa-hours-<?php echo esc_attr( (string) $index ); ?>" name="gwc_vt_hours[]" class="small-text" inputmode="decimal" value="" />
		</td>
	</tr>
	<?php
}

/* ── Logging a scheduled shift ───────────────────────────────────────────── */

/**
 * The roster, ready to become hours.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_shift_log_screen( int $shift_id ): void {
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Log the hours for this shift', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<p>
			<a href="<?php echo esc_url( gwc_vt_schedule_url( array( 'shift' => $shift_id ) ) ); ?>">
				&larr; <?php esc_html_e( 'Back to the shift', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>

		<?php gwc_vt_quick_add_notice(); ?>

		<h2><?php echo esc_html( get_the_title( $shift_id ) ); ?></h2>

		<?php gwc_vt_render_shift_log_form( $shift_id ); ?>
	</div>
	<?php
}

/* ── The form, without the screen around it ──────────────────────────────────
 * Split out so an event's roster can put it under the time it belongs to. The
 * day of a four-time event used to be eight screens: read the roster, go to the
 * log screen, come back, find the next time, and again. This is the same form,
 * the same handler and the same rules — relocated, not rewritten — so there is
 * no second write path to keep in step with the first.
 *
 * The guards come with it, and that is the point of moving the whole thing
 * rather than just the table: the refusal to log a shift that has not finished,
 * and the notice about a shift already logged, are the two things a coordinator
 * most needs on the screen where they are typing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The log-a-shift form.
 *
 * @param int $shift_id   Shift post ID.
 * @param int $back_event Event to return to after saving, or 0 for the usual place.
 */
function gwc_vt_render_shift_log_form( int $shift_id, int $back_event = 0 ): void {
	$vocabulary = gwc_vt_activity_vocabulary();
	$roster     = gwc_vt_shift_signup_ids( $shift_id );
	$ended      = gwc_vt_shift_has_ended( $shift_id );
	$logged_at  = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_RECONCILED, true );

	?>
		<?php
		/* ── Not yet ────────────────────────────────────────────────────────
		 * Refused rather than allowed-with-a-warning, and the reason is the one
		 * that runs through this whole plugin. An entry dated in the future is
		 * silently clamped to today by gwc_vt_save_entry(), so logging Saturday
		 * on Friday would write Friday's date onto hours worked on Saturday —
		 * and that date is printed on a document a court reads. Nobody would
		 * ever see the clamp happen. */
		if ( ! $ended ) :
			?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'This shift has not finished yet.', 'groundwork-common-volunteer-tracker' ); ?></strong>
					<?php esc_html_e( 'Hours can be logged once it has. Recording them early would date them the day you typed them rather than the day they were worked, and that date is what a letter prints.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			</div>
			<?php
			return;
		endif;
		?>

		<?php if ( '' !== $logged_at ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: a date. */
						esc_html__( 'Hours were already logged for this shift on %s. Anybody who has an entry already is shown below and cannot be logged twice; you can still add somebody who was missed.', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwc_vt_local_date( $logged_at ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-quick-add">
			<input type="hidden" name="action" value="gwc_vt_quick_add" />
			<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<input type="hidden" name="gwc_vt_date" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ) ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_quick_add' ); ?>

			<?php
			/* An event ID rather than a URL. A URL in a form field is an open
			 * redirect waiting to be found, and wp_safe_redirect() only catches
			 * the off-site half of it — the redirect rebuilds a roster URL from
			 * this instead. Same reasoning as the drawer's return in
			 * inc/admin-shift.php. */
			?>
			<?php if ( $back_event > 0 ) : ?>
				<input type="hidden" name="gwc_vt_back_event" value="<?php echo esc_attr( (string) $back_event ); ?>" />
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td>
							<strong><?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?></strong>,
							<?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?>
							<p class="description"><?php esc_html_e( 'The shift’s own date. Every entry below is dated that day.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-qa-activity"><?php esc_html_e( 'What they did', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-qa-activity" name="gwc_vt_activity" class="regular-text" maxlength="200" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) ); ?>" <?php echo $vocabulary ? 'list="gwcvt-qa-activities"' : ''; ?> />
							<?php if ( $vocabulary ) : ?>
								<datalist id="gwcvt-qa-activities">
									<?php foreach ( $vocabulary as $option ) : ?>
										<option value="<?php echo esc_attr( $option ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'From the shift. Change it if the day turned out differently — this is what appears on every letter these hours reach.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-qa-supervisor"><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-qa-supervisor" name="gwc_vt_supervisor" class="regular-text" maxlength="100" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_SUPERVISOR, true ) ); ?>" />
							<p class="description"><?php esc_html_e( 'From the shift. Change it if somebody else actually covered it.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Who turned up', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="widefat striped gwcvt-quick-add__people">
				<thead>
					<tr>
						<th scope="col" class="gwcvt-quick-add__came"><?php esc_html_e( 'Came', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></th>
						<th scope="col" class="gwcvt-quick-add__hours"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody id="gwcvt-qa-rows">
					<?php
					$index = 0;

					foreach ( $roster as $signup_id ) {
						gwc_vt_render_roster_log_row( $index, $signup_id, $shift_id, '' !== $logged_at );
						++$index;
					}

					for ( $i = 0; $i < GWC_VT_QUICK_ADD_WALK_IN_ROWS; $i++ ) {
						gwc_vt_render_quick_add_row( $index, true );
						++$index;
					}
					?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="gwcvt-qa-more"><?php esc_html_e( 'Add more rows', 'groundwork-common-volunteer-tracker' ); ?></button>
			</p>

			<p class="description">
				<?php esc_html_e( 'Clear the checkbox for anybody who did not turn up — nothing is recorded for them. The blank rows at the bottom are for people who came without signing up. Hours accept 3.5, 3:30, 3h 30m or 210m.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php submit_button( __( 'Log these hours', 'groundwork-common-volunteer-tracker' ) ); ?>
		</form>
	<?php
}

/**
 * One person from the roster.
 *
 * ── Selected the first time, and never again ───────────────────────────────────
 * On a shift nobody has logged yet, everybody starts selected: most people who
 * sign up turn up, and the coordinator should be marking the exceptions rather
 * than confirming the rule.
 *
 * On a shift that HAS been logged, everybody without an entry starts cleared,
 * and that difference is load-bearing. Somebody on the roster with no entry on a
 * logged shift is somebody already recorded as not having come. A coordinator
 * reopening the screen to add a walk-in they forgot would otherwise find that
 * person selected again with the scheduled hours filled in, and one press of Save
 * would credit them a shift they did not work — silently, onto a record a letter
 * is built from. Re-selecting has to be a decision, not a default.
 *
 * @param int  $index      Row index, which is how the checkbox finds its row.
 * @param int  $signup_id  Signup post ID.
 * @param int  $shift_id   Shift post ID.
 * @param bool $reconciled Whether this shift's hours have been logged before.
 */
function gwc_vt_render_roster_log_row( int $index, int $signup_id, int $shift_id, bool $reconciled = false ): void {
	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );
	$existing     = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_ENTRY, true );
	$already      = $existing > 0 && GWC_VT_ENTRY_TYPE === get_post_type( $existing );

	/* Recorded as not having come: the shift was logged and this row got no
	 * entry out of it. */
	$absent = $reconciled && ! $already;

	$suggestion = $volunteer_id > 0
		? array(
			'volunteer_id' => 0,
			'matched_on'   => '',
		)
		: gwc_vt_suggest_volunteer_for_signup( $signup_id );

	$suggested = (int) $suggestion['volunteer_id'];
	$hours     = gwc_vt_format_hours( gwc_vt_shift_minutes( $shift_id ) );
	$row_id    = 'gwcvt-qa-' . $index;
	?>
	<tr class="gwcvt-quick-add__row<?php echo $already ? ' gwcvt-quick-add__row--logged' : ''; ?>">
		<td class="gwcvt-quick-add__came">
			<?php if ( $already ) : ?>
				<span class="gwcvt-badge gwcvt-badge--verified"><?php esc_html_e( 'Logged', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php else : ?>
				<input type="checkbox" id="<?php echo esc_attr( $row_id . '-came' ); ?>" name="gwc_vt_attended[<?php echo esc_attr( (string) $index ); ?>]" value="1" <?php checked( ! $absent ); ?> />
				<label for="<?php echo esc_attr( $row_id . '-came' ); ?>" class="screen-reader-text">
					<?php
					printf(
						/* translators: %s: a person's name. */
						esc_html__( '%s turned up', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwc_vt_signup_name( $signup_id ) )
					);
					?>
				</label>
				<input type="hidden" name="gwc_vt_signup[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( (string) $signup_id ); ?>" />
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $volunteer_id > 0 || $already ) : ?>
				<strong><?php echo esc_html( gwc_vt_signup_name( $signup_id ) ); ?></strong>

				<?php
				/* An already-logged row posts nobody and no hours, so the handler
				 * reads it as an untouched row and passes over it in silence. It
				 * still posts BOTH fields, because gwc_vt_volunteer[] and
				 * gwc_vt_hours[] are positional and a row that skipped them would
				 * shift every row beneath it onto the wrong answer. */
				?>
				<input type="hidden" name="gwc_vt_volunteer[]" value="<?php echo esc_attr( $already ? '0' : (string) $volunteer_id ); ?>" />

				<?php if ( $already ) : ?>
					<div class="row-actions">
						<a href="<?php echo esc_url( (string) get_edit_post_link( $existing ) ); ?>">
							<?php esc_html_e( 'Open the entry', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</div>
				<?php elseif ( $absent ) : ?>
					<p class="description">
						<?php esc_html_e( 'Recorded as not having come. Select the checkbox only if that was wrong.', 'groundwork-common-volunteer-tracker' ); ?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( $row_id . '-name' ); ?>">
					<?php esc_html_e( 'Which volunteer this is', 'groundwork-common-volunteer-tracker' ); ?>
				</label>
				<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
					<input
						type="text"
						id="<?php echo esc_attr( $row_id . '-name' ); ?>"
						class="regular-text"
						autocomplete="off"
						role="combobox"
						aria-expanded="false"
						aria-autocomplete="list"
						aria-controls="<?php echo esc_attr( $row_id . '-results' ); ?>"
						value="<?php echo esc_attr( $suggested > 0 ? (string) get_the_title( $suggested ) : '' ); ?>"
						placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
					/>
					<input type="hidden" name="gwc_vt_volunteer[]" value="<?php echo esc_attr( (string) $suggested ); ?>" />
					<ul id="<?php echo esc_attr( $row_id . '-results' ); ?>" class="gwcvt-picker__results" role="listbox" hidden></ul>
				</div>

				<p class="description">
					<?php
					if ( $suggested > 0 ) {
						/* Named as a suggestion, never as a fact. Attaching somebody's
						 * hours to the wrong record is the failure this screen is one
						 * click away from, so the sentence says who typed what. */
						printf(
							/* translators: %s: the name somebody signed up under. */
							esc_html__( 'Signed up as %s — check this is the right person.', 'groundwork-common-volunteer-tracker' ),
							esc_html( (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true ) )
						);
					} else {
						printf(
							/* translators: %s: the name somebody signed up under. */
							esc_html__( 'Signed up as %s, and is not on file. Pick who they are, or add a volunteer record first.', 'groundwork-common-volunteer-tracker' ),
							esc_html( (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true ) )
						);
					}
					?>
				</p>
			<?php endif; ?>
		</td>
		<td class="gwcvt-quick-add__hours">
			<label class="screen-reader-text" for="<?php echo esc_attr( $row_id . '-hours' ); ?>">
				<?php
				printf(
					/* translators: %s: a person's name. */
					esc_html__( 'Hours for %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_signup_name( $signup_id ) )
				);
				?>
			</label>
			<?php if ( $already ) : ?>
				<?php echo esc_html( gwc_vt_format_hours( (int) get_post_meta( $existing, GWC_VT_ENTRY_MINUTES, true ) ) ); ?>
				<input type="hidden" name="gwc_vt_hours[]" value="" />
			<?php else : ?>
				<input type="text" id="<?php echo esc_attr( $row_id . '-hours' ); ?>" name="gwc_vt_hours[]" class="small-text" inputmode="decimal" value="<?php echo esc_attr( $hours ); ?>" />
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Create one entry per filled row.
 */
function gwc_vt_handle_quick_add(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to log hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'gwc_vt_quick_add' );

	$posted = wp_unslash( $_POST );

	$shift_id = absint( $posted['gwc_vt_shift'] ?? 0 );

	if ( $shift_id > 0 && GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
		$shift_id = 0;
	}

	/* The gate, re-checked here and not merely on the screen that offered the
	 * form. A future-dated entry is silently clamped to today by
	 * gwc_vt_save_entry(), so a posted-early reconciliation would write the wrong
	 * date onto a document a court reads, and nothing would show that it had. */
	if ( $shift_id > 0 && ! gwc_vt_shift_has_ended( $shift_id ) ) {
		gwc_vt_quick_add_redirect( 0, 0, 'not-ended', $shift_id );
	}

	$date = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_date'] ?? '' ) ) );

	if ( '' === $date || ( ! gwc_vt_setting( 'allow_future_dates' ) && $date > gwc_vt_today() ) ) {
		gwc_vt_quick_add_redirect( 0, 0, 'bad-date', $shift_id );
	}

	$activity   = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_activity'] ?? '' ) ), 0, 200 );
	$supervisor = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_supervisor'] ?? '' ) ), 0, 100 );

	$volunteers = array_map( 'absint', (array) ( $posted['gwc_vt_volunteer'] ?? array() ) );
	$hours      = array_map( 'strval', (array) ( $posted['gwc_vt_hours'] ?? array() ) );

	/* ── Why these two are keyed and the two above are not ───────────────────
	 * gwc_vt_volunteer[] and gwc_vt_hours[] are positional, and every row renders
	 * both, so their indexes line up with the rows on screen.
	 *
	 * A checkbox posts nothing at all when it is cleared, so gwc_vt_attended[]
	 * would arrive with its indexes closed up and every row after the first
	 * no-show would read somebody else's answer. Both of these therefore carry
	 * an explicit row index, and are read by lookup rather than by position.
	 *
	 * gwc_vt_signup[] is what tells the two kinds of row apart: a row that has
	 * one is somebody who signed up, and needs to be selected to count; a row without
	 * one is a walk-in, and counts if it was filled in. Without that, the walk-in
	 * rows at the bottom — which have no checkbox, because a blank row is not a
	 * no-show — would every one of them read as absent. */
	$signups  = array_map( 'absint', (array) ( $posted['gwc_vt_signup'] ?? array() ) );
	$attended = (array) ( $posted['gwc_vt_attended'] ?? array() );

	$made     = 0;
	$skipped  = 0;
	$no_shows = 0;
	$logged   = array();

	foreach ( $volunteers as $index => $volunteer_id ) {
		$typed     = trim( (string) ( $hours[ $index ] ?? '' ) );
		$signup_id = (int) ( $signups[ $index ] ?? 0 );

		/* Somebody who signed up and did not turn up. Counted so the coordinator
		 * is told, and recorded as nothing at all — no entry, and no stored flag
		 * saying they were absent. See the note on attendance in
		 * inc/signup-cpt.php about what a no-show file would be. */
		if ( $signup_id > 0 && empty( $attended[ $index ] ) ) {
			++$no_shows;
			continue;
		}

		// An untouched row. Not an error — the form ships more rows than most days need.
		if ( $volunteer_id < 1 && '' === $typed ) {
			continue;
		}

		/* Half a row IS an error, and a silent skip would mean somebody's hours
		 * quietly not recorded. Counted and reported. */
		if ( $volunteer_id < 1 || '' === $typed ) {
			++$skipped;
			continue;
		}

		if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
			++$skipped;
			continue;
		}

		$minutes = gwc_vt_parse_hours( $typed );

		if ( null === $minutes || $minutes < 1 ) {
			++$skipped;
			continue;
		}

		$entry_id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_ENTRY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $entry_id ) || ! $entry_id ) {
			++$skipped;
			continue;
		}

		$entry_id = (int) $entry_id;

		update_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
		update_post_meta( $entry_id, GWC_VT_ENTRY_DATE, $date );
		update_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, (int) $minutes );
		update_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, $activity );
		update_post_meta( $entry_id, GWC_VT_ENTRY_SUPERVISOR, $supervisor );
		update_post_meta( $entry_id, GWC_VT_ENTRY_SOURCE, 'staff' );

		if ( $shift_id > 0 ) {
			update_post_meta( $entry_id, GWC_VT_ENTRY_SHIFT, $shift_id );
		}

		if ( $signup_id > 0 ) {
			update_post_meta( $signup_id, GWC_VT_SIGNUP_ENTRY, $entry_id );

			/* Logging somebody's hours is the coordinator saying who they are, so
			 * a signup made by a stranger stops being unmatched here rather than
			 * staying on the roster as a claim forever. Matching, not attesting:
			 * the entry below still arrives unverified like every other. */
			if ( (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true ) < 1 ) {
				gwc_vt_attach_signup( $signup_id, $volunteer_id );
			}
		}

		gwc_vt_retitle_entry( $entry_id );
		gwc_vt_refresh_totals( $volunteer_id );

		/** This filter is documented in inc/meta-box.php */
		do_action( 'gwc_vt_entry_saved', $entry_id );

		$logged[] = $entry_id;
		++$made;
	}

	gwc_vt_forget_unverified_count();

	if ( $shift_id > 0 ) {
		/* Stamped even when nothing was logged. "Nobody came" is an answer, and a
		 * shift that keeps asking after somebody has answered it is a nag that
		 * teaches people to ignore nags. */
		update_post_meta( $shift_id, GWC_VT_SHIFT_RECONCILED, current_time( 'mysql', true ) );

		/**
		 * Fires after a shift's roster has been turned into hour entries.
		 *
		 * @param int   $shift_id The shift.
		 * @param int[] $logged   The entries created, which may be none.
		 */
		do_action( 'gwc_vt_shift_reconciled', $shift_id, $logged );
	}

	gwc_vt_quick_add_redirect(
		$made,
		$skipped,
		$made > 0 ? 'logged' : 'nothing',
		$shift_id,
		$no_shows
	);
}

/**
 * Back to the form with a count.
 *
 * @param int    $made     How many entries were created.
 * @param int    $skipped  How many rows were half-filled or unreadable.
 * @param string $result   What to say.
 * @param int    $shift_id The shift this was logged against, if any.
 * @param int    $no_shows How many people signed up and did not turn up.
 */
function gwc_vt_quick_add_redirect( int $made, int $skipped, string $result, int $shift_id = 0, int $no_shows = 0 ): void {
	$args = array(
		'page'           => GWC_VT_QUICK_ADD_PAGE,
		'post_type'      => GWC_VT_ENTRY_TYPE,
		'gwc_vt_qa'      => $result,
		'gwc_vt_made'    => $made,
		'gwc_vt_skipped' => $skipped,
	);

	if ( $shift_id > 0 ) {
		$args['gwc_vt_shift']    = $shift_id;
		$args['gwc_vt_no_shows'] = $no_shows;
	}

	/* Somebody logging a time from an event's roster wanted to stay there: the
	 * next time is on the same screen, and sending them to the standalone log
	 * page is the round trip that made a four-time event eight of them.
	 *
	 * Rebuilt from an ID rather than from a URL the form carried — see the note
	 * on the hidden field in gwc_vt_render_shift_log_form(). */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- gwc_vt_handle_quick_add() checked its nonce before this runs; this only decides which screen to land on.
	$back_event = isset( $_POST['gwc_vt_back_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_back_event'] ) ) : 0;

	if ( $back_event > 0 && GWC_VT_EVENT_TYPE === get_post_type( $back_event ) ) {
		unset( $args['page'], $args['gwc_vt_shift'] );

		wp_safe_redirect(
			add_query_arg(
				$args,
				gwc_vt_event_roster_url( $back_event )
			)
		);
		exit;
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
	exit;
}

/**
 * Say what happened.
 */
function gwc_vt_quick_add_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwc_vt_qa'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_qa'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$made = isset( $_GET['gwc_vt_made'] ) ? absint( wp_unslash( $_GET['gwc_vt_made'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwc_vt_skipped'] ) ? absint( wp_unslash( $_GET['gwc_vt_skipped'] ) ) : 0;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$no_shows = isset( $_GET['gwc_vt_no_shows'] ) ? absint( wp_unslash( $_GET['gwc_vt_no_shows'] ) ) : 0;

	if ( 'bad-date' === $result ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Give a date for the shift. Nothing was logged.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	if ( 'not-ended' === $result ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'That shift has not finished yet, so nothing was logged. Hours dated in the future are recorded against the day you typed them, and that date is what a letter prints.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	if ( 'nothing' === $result && $skipped < 1 ) {
		/* Against a shift, "nothing was logged" is often the correct outcome
		 * rather than a mistake — a Saturday where nobody came is a real
		 * Saturday, and the shift has still been dealt with. */
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			$no_shows > 0
				? esc_html(
					sprintf(
						/* translators: %d: how many people signed up. */
						_n(
							'Nobody turned up — %d person signed up and did not come. The shift is marked as dealt with and nothing was recorded against anybody.',
							'Nobody turned up — %d people signed up and did not come. The shift is marked as dealt with and nothing was recorded against anybody.',
							$no_shows,
							'groundwork-common-volunteer-tracker'
						),
						$no_shows
					)
				)
				: esc_html__( 'No rows were filled in, so nothing was logged.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	$message = sprintf(
		/* translators: %d: number of shifts. */
		_n( '%d shift logged. It is waiting to be verified.', '%d shifts logged. They are waiting to be verified.', $made, 'groundwork-common-volunteer-tracker' ),
		$made
	);

	if ( $no_shows > 0 ) {
		$message .= ' ' . sprintf(
			/* translators: %d: how many people did not turn up. */
			_n(
				'%d person who signed up did not come, and nothing was recorded for them.',
				'%d people who signed up did not come, and nothing was recorded for them.',
				$no_shows,
				'groundwork-common-volunteer-tracker'
			),
			$no_shows
		);
	}

	if ( $skipped > 0 ) {
		$message .= ' ' . sprintf(
			/* translators: %d: number of rows. */
			_n(
				'%d row was skipped — it needed both a volunteer and a readable number of hours.',
				'%d rows were skipped — each needed both a volunteer and a readable number of hours.',
				$skipped,
				'groundwork-common-volunteer-tracker'
			),
			$skipped
		);
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
		esc_attr( $skipped > 0 ? 'warning' : 'success' ),
		esc_html( $message ),
		esc_url(
			add_query_arg(
				array(
					'post_type'    => GWC_VT_ENTRY_TYPE,
					'gwc_vt_state' => 'unverified',
				),
				admin_url( 'edit.php' )
			)
		),
		esc_html__( 'Verify them now', 'groundwork-common-volunteer-tracker' )
	);
}

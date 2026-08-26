<?php
/**
 * Changing one thing across every occurrence of a repeat.
 *
 * ── Why this is a screen and not a checkbox ──────────────────────────────────
 * The time moves an hour for the summer; the supervisor changes; the location
 * moves to the annexe. Twenty occurrences, one decision — and doing it by hand
 * means opening twenty shifts.
 *
 * The obvious implementation is a "apply to the whole series" checkbox on the
 * shift editor. It is refused for two reasons. gwc_vt_handle_save_shift() is
 * what every other screen posts to, and a mode that multiplies its blast radius
 * by twenty makes it a handler nobody can reason about. And CLAUDE.md's rule
 * that a silent correction on save is a bug even when the correction is right
 * has no larger version than this: twenty rosters changed by one click that did
 * not say how many.
 *
 * So: its own page, its own nonce, its own handler, and a confirmation that
 * states what it is about to touch before it touches it.
 *
 * ── The four questions it has to answer ──────────────────────────────────────
 * Which occurrences — future-only by default, because somebody changing the
 * supervisor almost never means to rewrite last March.
 *
 * The cancelled ones — off by default. A cancellation is an answer this
 * organization gave somebody who had signed up, and rewriting its time
 * un-answers it while the notice that was sent stays sent.
 *
 * The ones with people on them — the notify decision is made ONCE, for the
 * batch, with the number of people it would reach stated on the screen. Twenty
 * shifts is potentially one email per person per shift, which is not a thing to
 * discover afterwards.
 *
 * Which fields — time, activity, location, supervisor, notes and places. Date
 * is not among them and cannot be: moving every occurrence by a day is a
 * different repeat, not an edit to this one.
 *
 * The arithmetic all lives in gwc_vt_repeat_targets() in inc/shifts.php, so the
 * number this screen states and the rows the handler writes come from one
 * function rather than from two that agree today.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'gwc_vt_register_repeat_screen', 14 );
add_action( 'admin_post_gwc_vt_save_repeat', 'gwc_vt_handle_save_repeat' );

/**
 * Register the confirmation screen, hidden from the menu.
 *
 * Hidden for the same reason the verify queue's verbs are: it is a screen you
 * arrive at from a shift, never one you go looking for. gwc_vt_hidden_menu_items()
 * takes it off the menu after registration.
 */
function gwc_vt_register_repeat_screen(): void {
	$hook = add_submenu_page(
		GWC_VT_MENU_SLUG,
		gwc_vt_repeat_page_title(),
		gwc_vt_repeat_page_title(),
		'edit_posts',
		GWC_VT_REPEAT_PAGE,
		'gwc_vt_render_repeat_screen'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'gwc_vt_restore_repeat_page_title' );
	}
}

/**
 * The screen's title.
 *
 * @return string
 */
function gwc_vt_repeat_page_title(): string {
	return __( 'Change the whole repeat', 'groundwork-common-volunteer-tracker' );
}

/**
 * Put the title back after the menu entry is removed.
 *
 * Get_admin_page_title() reads a page's title out of $submenu, and removing the
 * entry takes the title with it — leaving $title null and core's own
 * strip_tags( $title ) to deprecate on PHP 8.1. The same fix the other hidden
 * pages carry; see the longer note on gwc_vt_restore_quick_add_title().
 */
function gwc_vt_restore_repeat_page_title(): void {
	if ( ! empty( $GLOBALS['title'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is how core carries an admin page's title into admin-header.php, and there is no API for setting it; this writes it only for this plugin's own screen, and only when nothing else has.
	$GLOBALS['title'] = gwc_vt_repeat_page_title();
}

/**
 * The URL of this screen for one shift's repeat.
 *
 * @param int $shift_id Any occurrence of the repeat.
 * @return string
 */
function gwc_vt_repeat_url( int $shift_id ): string {
	return add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_REPEAT_PAGE,
			'shift'     => $shift_id,
		),
		admin_url( 'edit.php' )
	);
}

/**
 * Which shift this screen was opened from.
 *
 * @return int
 */
function gwc_vt_repeat_shift(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; nothing is written until the form below is submitted with its own nonce.
	return isset( $_GET['shift'] ) ? absint( wp_unslash( $_GET['shift'] ) ) : 0;
}

/**
 * The confirmation screen.
 */
function gwc_vt_render_repeat_screen(): void {
	$shift_id  = gwc_vt_repeat_shift();
	$series_id = gwc_vt_shift_series_id( $shift_id );
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php echo esc_html( gwc_vt_repeat_page_title() ); ?></h1>
		<?php
		if ( $series_id < 1 ) {
			/* A one-off has nothing to apply across, and saying so is better
			 * than an empty form that would work if only you had come from the
			 * right shift. */
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'That shift is not part of a repeat, so there is nothing to change across. Edit it on its own instead.', 'groundwork-common-volunteer-tracker' )
			);

			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( gwc_vt_schedule_url() ),
				esc_html__( 'Back to the schedule', 'groundwork-common-volunteer-tracker' )
			);

			echo '</div>';
			return;
		}

		gwc_vt_render_repeat_form( $shift_id, $series_id );
		?>
	</div>
	<?php
}

/**
 * What the batch will touch, and the form that runs it.
 *
 * @param int $shift_id  The occurrence this was opened from.
 * @param int $series_id The repeat's first occurrence.
 */
function gwc_vt_render_repeat_form( int $shift_id, int $series_id ): void {
	/* The default answer, stated before anything is chosen: future only,
	 * cancelled left alone. The screen recomputes on submit rather than trusting
	 * these numbers to have been carried in a hidden field — a stale count in a
	 * form field is a number that was true when the page loaded and is being
	 * reported as though it were true when the button was pressed. */
	$targets = gwc_vt_repeat_targets( $series_id );
	$all     = gwc_vt_shift_series_ids( $series_id );
	?>
	<p class="gwcvt-repeat__lead">
		<?php
		printf(
			/* translators: %s: a description of the repeat, such as "Repeats weekly until August 30". */
			esc_html__( 'This repeat: %s', 'groundwork-common-volunteer-tracker' ),
			esc_html( gwc_vt_shift_repeat_note( $shift_id ) )
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gwc_vt_save_repeat" />
		<input type="hidden" name="gwc_vt_shift" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
		<?php wp_nonce_field( 'gwc_vt_save_repeat_' . $series_id ); ?>

		<h2><?php esc_html_e( 'What to change', 'groundwork-common-volunteer-tracker' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Only the things you tick are written. Everything else on every occurrence is left exactly as it is — including anything somebody has already corrected by hand on one of them.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php gwc_vt_render_repeat_fields( $shift_id ); ?>

		<h2><?php esc_html_e( 'Which occurrences', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<div class="gwcvt-field">
			<label>
				<input type="checkbox" name="gwc_vt_past" value="1" />
				<?php
				printf(
					/* translators: %s: how many occurrences have already happened, already formatted. */
					esc_html( _n( 'Also change the %s occurrence that has already happened', 'Also change the %s occurrences that have already happened', count( $targets['past'] ), 'groundwork-common-volunteer-tracker' ) ),
					esc_html( number_format_i18n( count( $targets['past'] ) ) )
				);
				?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Off by default. Changing the supervisor almost never means rewriting last March — and hours already logged against those shifts keep the activity they were logged with either way.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<div class="gwcvt-field">
			<label>
				<input type="checkbox" name="gwc_vt_cancelled" value="1" />
				<?php
				printf(
					/* translators: %s: how many occurrences were called off, already formatted. */
					esc_html( _n( 'Also change the %s occurrence that was called off', 'Also change the %s occurrences that were called off', count( $targets['cancelled'] ), 'groundwork-common-volunteer-tracker' ) ),
					esc_html( number_format_i18n( count( $targets['cancelled'] ) ) )
				);
				?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Off by default, and worth leaving off. A cancellation is an answer you already gave whoever had signed up; changing its time now does not un-send that message, it just makes your records disagree with it.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<h2><?php esc_html_e( 'Telling people', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<div class="gwcvt-field">
			<label>
				<input type="checkbox" name="gwc_vt_notify" value="1" />
				<?php
				printf(
					/* translators: %s: how many people are on the rosters that would be written to, already formatted. */
					esc_html( _n( 'Email the %s person on these rosters', 'Email the %s people on these rosters', $targets['people'], 'groundwork-common-volunteer-tracker' ) ),
					esc_html( number_format_i18n( $targets['people'] ) )
				);
				?>
			</label>
			<p class="description">
				<?php esc_html_e( 'One decision for the whole batch. Only occurrences still to come are written to, only the ones where something they would care about actually moved, and somebody on three of these Saturdays gets three messages.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<h2><?php esc_html_e( 'What this will do', 'groundwork-common-volunteer-tracker' ); ?></h2>

		<?php gwc_vt_render_repeat_preview( $targets, count( $all ) ); ?>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Change these occurrences', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( gwc_vt_schedule_url( array( 'shift' => $shift_id ) ) ); ?>">
				<?php esc_html_e( 'Cancel — edit only this one', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	</form>
	<?php
}

/**
 * The fields a repeat may change, prefilled from the occurrence it was opened on.
 *
 * Prefilled rather than blank because the common edit is a correction to what is
 * already there — the location is right except it has moved to the annexe — and
 * a blank box beside a tick is an invitation to wipe a field across twenty rows.
 *
 * @param int $shift_id The occurrence this was opened from.
 */
function gwc_vt_render_repeat_fields( int $shift_id ): void {
	$start = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_START, true );
	$end   = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_END, true );
	?>
	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="time" />
			<strong><?php esc_html_e( 'The time', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<label>
				<?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?>
				<input type="time" name="gwc_vt_start" value="<?php echo esc_attr( $start ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'to', 'groundwork-common-volunteer-tracker' ); ?>
				<input type="time" name="gwc_vt_end" value="<?php echo esc_attr( $end ); ?>" />
			</label>
			<label>
				<input type="checkbox" name="gwc_vt_overnight" value="1" <?php checked( '1' === (string) get_post_meta( $shift_id, GWC_VT_SHIFT_OVERNIGHT, true ) ); ?> />
				<?php esc_html_e( 'ends the next day', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'The date of each occurrence never moves. Shifting every one by a day is a different repeat, not a change to this one.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</div>

	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="activity" />
			<strong><?php esc_html_e( 'What the work is', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<input type="text" class="regular-text" name="gwc_vt_activity" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) ); ?>" />
		</p>
		<p class="description">
			<?php esc_html_e( 'This is what appears on every letter the hours from these shifts reach. Hours already logged keep the wording they were logged with.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</div>

	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="location" />
			<strong><?php esc_html_e( 'Where it is', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<input type="text" class="regular-text" name="gwc_vt_location" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ) ); ?>" />
		</p>
	</div>

	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="supervisor" />
			<strong><?php esc_html_e( 'Who is supervising', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<input type="text" class="regular-text" name="gwc_vt_supervisor" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_SUPERVISOR, true ) ); ?>" />
		</p>
	</div>

	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="places" />
			<strong><?php esc_html_e( 'How many people', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<label>
				<?php esc_html_e( 'Need', 'groundwork-common-volunteer-tracker' ); ?>
				<input type="number" min="0" step="1" name="gwc_vt_min" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true ) ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'room for', 'groundwork-common-volunteer-tracker' ); ?>
				<input type="number" min="0" step="1" name="gwc_vt_max" value="<?php echo esc_attr( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true ) ); ?>" />
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Lowering the room for people does not take anybody off a roster who is already on one.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</div>

	<div class="gwcvt-field">
		<label>
			<input type="checkbox" name="gwc_vt_change[]" value="notes" />
			<strong><?php esc_html_e( 'Notes', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</label>
		<p>
			<textarea name="gwc_vt_notes" rows="3" class="large-text"><?php echo esc_textarea( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_NOTES, true ) ); ?></textarea>
		</p>
	</div>

	<?php
	/* Credentials across the whole repeat. This is the natural fix for a
	 * coordinator who set up a weekly series and then decided everybody on it
	 * needs the waiver — without it, that is twenty visits to twenty
	 * occurrences.
	 *
	 * Note what selecting the box with every credential clear does: it clears
	 * them across the repeat. That is deliberate and is why the description
	 * says so — a batch operation whose "empty" case silently means "do
	 * nothing" is one that cannot undo itself. */
	?>
	<?php if ( gwc_vt_live_credential_ids() ) : ?>
		<div class="gwcvt-field">
			<label>
				<input type="checkbox" name="gwc_vt_change[]" value="credentials" />
				<strong><?php esc_html_e( 'What they have to hold', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>

			<div class="gwcvt-shift-credentials__list">
				<?php foreach ( gwc_vt_live_credential_ids() as $gwc_vt_repeat_credential ) : ?>
					<?php $gwc_vt_repeat_cred = gwc_vt_credential( (int) $gwc_vt_repeat_credential ); ?>
					<?php if ( ! $gwc_vt_repeat_cred ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<label class="gwcvt-shift-credentials__item">
						<input
							type="checkbox"
							name="gwc_vt_credentials[]"
							value="<?php echo esc_attr( (string) $gwc_vt_repeat_cred['id'] ); ?>"
							<?php checked( in_array( $gwc_vt_repeat_cred['id'], gwc_vt_shift_credential_ids( $shift_id ), true ) ); ?>
						/>
						<?php echo esc_html( $gwc_vt_repeat_cred['name'] ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<p class="description">
				<?php esc_html_e( 'This replaces what each occurrence asks for rather than adding to it — so selecting the box with nothing chosen clears them across the whole repeat.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>
	<?php endif; ?>
	<?php
}

/**
 * How many this touches, how many it leaves, and why.
 *
 * The same shape as the recurrence cap notice: a number that happened and a
 * number that did not, each with its reason. A batch that reports only what it
 * changed leaves the reader to work out whether the rest were skipped on purpose.
 *
 * @param array $targets What gwc_vt_repeat_targets() returned.
 * @param int   $total   How many occurrences the repeat has in all.
 */
function gwc_vt_render_repeat_preview( array $targets, int $total ): void {
	$dates = array();

	foreach ( $targets['change'] as $shift_id ) {
		$dates[] = gwc_vt_shift_date_label( (int) $shift_id );
	}
	?>
	<div class="gwcvt-repeat__preview notice notice-info inline">
		<p>
			<strong>
				<?php
				printf(
					/* translators: 1: how many occurrences will change, 2: how many the repeat has in all. Both already formatted. */
					esc_html__( '%1$s of %2$s occurrences will change.', 'groundwork-common-volunteer-tracker' ),
					esc_html( number_format_i18n( count( $targets['change'] ) ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</strong>
			<?php if ( $targets['past'] || $targets['cancelled'] ) : ?>
				<?php esc_html_e( 'Left alone unless you tick the boxes above:', 'groundwork-common-volunteer-tracker' ); ?>
				<?php
				$why = array();

				if ( $targets['past'] ) {
					$why[] = sprintf(
						/* translators: %s: how many have already happened, already formatted. */
						_n( '%s has already happened', '%s have already happened', count( $targets['past'] ), 'groundwork-common-volunteer-tracker' ),
						number_format_i18n( count( $targets['past'] ) )
					);
				}

				if ( $targets['cancelled'] ) {
					$why[] = sprintf(
						/* translators: %s: how many were called off, already formatted. */
						_n( '%s was called off', '%s were called off', count( $targets['cancelled'] ), 'groundwork-common-volunteer-tracker' ),
						number_format_i18n( count( $targets['cancelled'] ) )
					);
				}

				echo esc_html( implode( __( ', and ', 'groundwork-common-volunteer-tracker' ), $why ) . '.' );
				?>
			<?php endif; ?>
		</p>

		<?php if ( $dates ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: a list of dates. */
					esc_html__( 'The dates: %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( implode( ', ', $dates ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Write the change across the repeat.
 */
function gwc_vt_handle_save_repeat(): void {
	gwc_vt_require_shift_cap();

	$shift_id  = isset( $_POST['gwc_vt_shift'] ) ? absint( wp_unslash( $_POST['gwc_vt_shift'] ) ) : 0;
	$series_id = gwc_vt_shift_series_id( $shift_id );

	/* The nonce carries the SERIES, not the occurrence, so one minted for one
	 * repeat cannot be replayed against another. Checked after the series is
	 * resolved for that reason. */
	check_admin_referer( 'gwc_vt_save_repeat_' . $series_id );

	if ( $series_id < 1 ) {
		gwc_vt_shift_redirect( $shift_id, 'not-a-repeat' );
	}

	$posted = wp_unslash( $_POST );

	$wanted = array_values(
		array_intersect(
			GWC_VT_REPEAT_FIELDS,
			array_map( 'sanitize_key', (array) ( $posted['gwc_vt_change'] ?? array() ) )
		)
	);

	if ( ! $wanted ) {
		gwc_vt_shift_redirect( $shift_id, 'nothing-chosen' );
	}

	$changes = gwc_vt_repeat_changes( $posted, $wanted );

	if ( '' !== $changes['error'] ) {
		gwc_vt_shift_redirect( $shift_id, $changes['error'] );
	}

	/* Recomputed here rather than trusted from the form. The screen stated these
	 * numbers when it loaded; a shift can have ended, or been called off, in the
	 * minutes since. Both halves call the same function, so the report below is
	 * about what actually happened. */
	$targets = gwc_vt_repeat_targets(
		$series_id,
		array(
			'past'      => ! empty( $posted['gwc_vt_past'] ),
			'cancelled' => ! empty( $posted['gwc_vt_cancelled'] ),
		)
	);

	$done = gwc_vt_apply_repeat_changes(
		$targets['change'],
		$changes['fields'],
		! empty( $posted['gwc_vt_notify'] ),
		$changes['credentials']
	);

	gwc_vt_shift_redirect(
		$shift_id,
		'repeat-saved',
		array(
			'gwc_vt_count'   => $done['changed'],
			'gwc_vt_skipped' => count( gwc_vt_shift_series_ids( $series_id ) ) - $done['changed'],
			'gwc_vt_told'    => $done['told'],
		)
	);
}

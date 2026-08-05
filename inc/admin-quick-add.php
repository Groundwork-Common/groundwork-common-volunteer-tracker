<?php
/**
 * Logging a whole day's shifts in one pass.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** How many volunteer rows the form starts with. */
const GWCVT_QUICK_ADD_ROWS = 8;

add_action( 'admin_menu', 'gwcvt_register_quick_add_menu', 12 );
add_action( 'admin_post_gwcvt_quick_add', 'gwcvt_handle_quick_add' );

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
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Add the screen under Volunteer Hours.
 */
function gwcvt_register_quick_add_menu(): void {
	add_submenu_page(
		GWCVT_MENU_SLUG,
		__( 'Log a day’s shifts', 'groundwork-common-volunteer-tracker' ),
		__( 'Log a day', 'groundwork-common-volunteer-tracker' ),
		'edit_posts',
		GWCVT_QUICK_ADD_PAGE,
		'gwcvt_render_quick_add_screen'
	);
}

/**
 * The form.
 */
function gwcvt_render_quick_add_screen(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to log hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$vocabulary = gwcvt_activity_vocabulary();
	$max_date   = gwcvt_setting( 'allow_future_dates' ) ? '' : gwcvt_today();
	?>
	<div class="wrap gwcvt-wrap">
		<h1><?php esc_html_e( 'Log a day’s shifts', 'groundwork-common-volunteer-tracker' ); ?></h1>

		<?php gwcvt_quick_add_notice(); ?>

		<p class="description gwcvt-quick-add__intro">
			<?php esc_html_e( 'For typing up a sign-in sheet. Everything the shift had in common goes at the top; add each volunteer and the hours they worked below. They arrive unverified, the same as any other entry.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gwcvt-quick-add">
			<input type="hidden" name="action" value="gwcvt_quick_add" />
			<?php wp_nonce_field( 'gwcvt_quick_add' ); ?>

			<h2><?php esc_html_e( 'The shift', 'groundwork-common-volunteer-tracker' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-qa-date"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input
								type="date"
								id="gwcvt-qa-date"
								name="gwcvt_date"
								required
								value="<?php echo esc_attr( gwcvt_today() ); ?>"
								<?php echo '' !== $max_date ? 'max="' . esc_attr( $max_date ) . '"' : ''; ?>
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-qa-activity"><?php esc_html_e( 'What they did', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-qa-activity" name="gwcvt_activity" class="regular-text" maxlength="200" <?php echo $vocabulary ? 'list="gwcvt-qa-activities"' : ''; ?> />
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
							<input type="text" id="gwcvt-qa-supervisor" name="gwcvt_supervisor" class="regular-text" maxlength="100" value="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>" />
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
					<?php for ( $i = 0; $i < GWCVT_QUICK_ADD_ROWS; $i++ ) : ?>
						<?php gwcvt_render_quick_add_row( $i ); ?>
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
 * @param int $index Row index.
 */
function gwcvt_render_quick_add_row( int $index ): void {
	?>
	<tr class="gwcvt-quick-add__row">
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
				<input type="hidden" name="gwcvt_volunteer[]" value="0" />
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
			<input type="text" id="gwcvt-qa-hours-<?php echo esc_attr( (string) $index ); ?>" name="gwcvt_hours[]" class="small-text" inputmode="decimal" value="" />
		</td>
	</tr>
	<?php
}

/**
 * Create one entry per filled row.
 */
function gwcvt_handle_quick_add(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to log hours.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'gwcvt_quick_add' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$date = gwcvt_sanitize_date( sanitize_text_field( (string) ( $posted['gwcvt_date'] ?? '' ) ) );

	if ( '' === $date || ( ! gwcvt_setting( 'allow_future_dates' ) && $date > gwcvt_today() ) ) {
		gwcvt_quick_add_redirect( 0, 0, 'bad-date' );
	}

	$activity   = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_activity'] ?? '' ) ), 0, 200 );
	$supervisor = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_supervisor'] ?? '' ) ), 0, 100 );

	$volunteers = array_map( 'absint', (array) ( $posted['gwcvt_volunteer'] ?? array() ) );
	$hours      = array_map( 'strval', (array) ( $posted['gwcvt_hours'] ?? array() ) );

	$made    = 0;
	$skipped = 0;

	foreach ( $volunteers as $index => $volunteer_id ) {
		$typed = trim( (string) ( $hours[ $index ] ?? '' ) );

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

		if ( GWCVT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
			++$skipped;
			continue;
		}

		$minutes = gwcvt_parse_hours( $typed );

		if ( null === $minutes || $minutes < 1 ) {
			++$skipped;
			continue;
		}

		$entry_id = wp_insert_post(
			array(
				'post_type'   => GWCVT_ENTRY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $entry_id ) || ! $entry_id ) {
			++$skipped;
			continue;
		}

		$entry_id = (int) $entry_id;

		update_post_meta( $entry_id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
		update_post_meta( $entry_id, GWCVT_ENTRY_DATE, $date );
		update_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, (int) $minutes );
		update_post_meta( $entry_id, GWCVT_ENTRY_ACTIVITY, $activity );
		update_post_meta( $entry_id, GWCVT_ENTRY_SUPERVISOR, $supervisor );
		update_post_meta( $entry_id, GWCVT_ENTRY_SOURCE, 'staff' );

		gwcvt_retitle_entry( $entry_id );
		gwcvt_refresh_totals( $volunteer_id );

		/** This filter is documented in inc/meta-box.php */
		do_action( 'gwcvt_entry_saved', $entry_id );

		++$made;
	}

	gwcvt_forget_unverified_count();

	gwcvt_quick_add_redirect( $made, $skipped, $made > 0 ? 'logged' : 'nothing' );
}

/**
 * Back to the form with a count.
 *
 * @param int    $made    How many entries were created.
 * @param int    $skipped How many rows were half-filled or unreadable.
 * @param string $result  What to say.
 */
function gwcvt_quick_add_redirect( int $made, int $skipped, string $result ): void {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => GWCVT_QUICK_ADD_PAGE,
				'post_type'    => GWCVT_ENTRY_TYPE,
				'gwcvt_qa'     => $result,
				'gwcvt_made'   => $made,
				'gwcvt_skipped' => $skipped,
			),
			admin_url( 'edit.php' )
		)
	);
	exit;
}

/**
 * Say what happened.
 */
function gwcvt_quick_add_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$result = isset( $_GET['gwcvt_qa'] ) ? sanitize_key( wp_unslash( $_GET['gwcvt_qa'] ) ) : '';

	if ( '' === $result ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$made = isset( $_GET['gwcvt_made'] ) ? absint( wp_unslash( $_GET['gwcvt_made'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$skipped = isset( $_GET['gwcvt_skipped'] ) ? absint( wp_unslash( $_GET['gwcvt_skipped'] ) ) : 0;

	if ( 'bad-date' === $result ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Give a date for the shift. Nothing was logged.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	if ( 'nothing' === $result && $skipped < 1 ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'No rows were filled in, so nothing was logged.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	$message = sprintf(
		/* translators: %d: number of shifts. */
		_n( '%d shift logged. It is waiting to be verified.', '%d shifts logged. They are waiting to be verified.', $made, 'groundwork-common-volunteer-tracker' ),
		$made
	);

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
		esc_url( add_query_arg( array( 'post_type' => GWCVT_ENTRY_TYPE, 'gwcvt_state' => 'unverified' ), admin_url( 'edit.php' ) ) ),
		esc_html__( 'Verify them now', 'groundwork-common-volunteer-tracker' )
	);
}

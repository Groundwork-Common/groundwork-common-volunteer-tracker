<?php
/**
 * The entry and volunteer edit screens.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'gwcvt_add_meta_boxes' );
add_action( 'save_post_' . GWCVT_ENTRY_TYPE, 'gwcvt_save_entry', 10, 2 );
add_action( 'save_post_' . GWCVT_VOLUNTEER_TYPE, 'gwcvt_save_volunteer', 10, 2 );
add_action( 'admin_notices', 'gwcvt_entry_saved_notice' );

/* ── Why every field wrapper here is a div ───────────────────────────────────
 * The obvious markup for a labelled field is a <p>, and it is what wp-admin's
 * own older screens use. It cannot be used here, and the reason is worth
 * recording because the failure is completely silent.
 *
 * The volunteer picker's results list is a <ul>. A <ul> inside a <p> makes the
 * HTML parser close the paragraph — and with it every element still open inside
 * it, including the wrapper the picker's script looks for its parts in. The
 * markup goes in nested and comes out with the list as a SIBLING of the field.
 * No error, no warning, valid-looking HTML, and a script that quietly finds
 * nothing and attaches no handlers.
 *
 * So: divs. It also means the fields can be laid out with flex rather than
 * fighting a paragraph's margins.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register both meta boxes.
 *
 * The entry's title field is kept off the screen by the post type declaring no
 * 'title' support, not from here — see the note in inc/cpt.php for why
 * remove_meta_box( 'titlediv', … ) does not do it.
 */
function gwcvt_add_meta_boxes(): void {
	add_meta_box(
		'gwcvt-entry',
		__( 'Shift', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_entry_meta_box',
		GWCVT_ENTRY_TYPE,
		'normal',
		'high'
	);

	add_meta_box(
		'gwcvt-volunteer',
		__( 'Contact details', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_volunteer_meta_box',
		GWCVT_VOLUNTEER_TYPE,
		'normal',
		'high'
	);
}

/**
 * The shift form.
 *
 * @param WP_Post $post The entry being edited.
 */
function gwcvt_render_entry_meta_box( $post ): void {
	$entry_id = (int) $post->ID;

	$volunteer_id = (int) get_post_meta( $entry_id, GWCVT_ENTRY_VOLUNTEER, true );
	$date         = (string) get_post_meta( $entry_id, GWCVT_ENTRY_DATE, true );
	$minutes      = (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true );
	$activity     = (string) get_post_meta( $entry_id, GWCVT_ENTRY_ACTIVITY, true );
	$supervisor   = (string) get_post_meta( $entry_id, GWCVT_ENTRY_SUPERVISOR, true );
	$claim_name   = (string) get_post_meta( $entry_id, '_gwcvt_claim_name', true );
	$claim_email  = (string) get_post_meta( $entry_id, '_gwcvt_claim_email', true );

	/* A new entry defaults to today. The overwhelmingly common case is somebody
	 * logging a shift that just finished, and an empty date field is a required
	 * field they have to fill in to record the most likely value.
	 *
	 * Tested on the status, not on the ID. post-new.php creates an auto-draft
	 * before rendering, so a new entry HAS a post ID by the time this runs and
	 * `! $entry_id` is never true — which is how this first shipped with the
	 * field silently blank. */
	if ( '' === $date && 'auto-draft' === get_post_status( $entry_id ) ) {
		$date = gwcvt_today();
	}

	wp_nonce_field( 'gwcvt_save_entry', 'gwcvt_entry_nonce' );

	$max_date   = gwcvt_setting( 'allow_future_dates' ) ? '' : gwcvt_today();
	$vocabulary = gwcvt_activity_vocabulary();
	$increment  = gwcvt_hour_increment();
	?>
	<div class="gwcvt-fields">

		<?php if ( '' !== $claim_name || '' !== $claim_email ) : ?>
			<div class="notice notice-warning inline gwcvt-claim">
				<p><strong><?php esc_html_e( 'Logged from the public form and not yet matched to a volunteer.', 'groundwork-common-volunteer-tracker' ); ?></strong></p>
				<p>
					<?php
					printf(
						/* translators: 1: the name typed into the public form, 2: the email address typed into it. */
						esc_html__( 'Submitted as %1$s (%2$s). These are claims made by whoever filled in the form — check them before choosing a volunteer.', 'groundwork-common-volunteer-tracker' ),
						'<strong>' . esc_html( '' !== $claim_name ? $claim_name : __( 'no name given', 'groundwork-common-volunteer-tracker' ) ) . '</strong>',
						'<strong>' . esc_html( '' !== $claim_email ? $claim_email : __( 'no email given', 'groundwork-common-volunteer-tracker' ) ) . '</strong>'
					);
					?>
				</p>
				<?php gwcvt_render_triage_actions( $entry_id ); ?>
			</div>
		<?php endif; ?>

		<div class="gwcvt-field">
			<label for="gwcvt-volunteer-search">
				<strong><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<?php
			/* An autocomplete rather than a <select>. A select is fine at twenty
			 * volunteers and unusable at four hundred, and the organisations this
			 * is built for get to four hundred faster than they expect. The
			 * lookup is a REST route — see inc/rest.php. */
			?>
			<div class="gwcvt-picker" data-gwcvt-picker data-gwcvt-empty="<?php esc_attr_e( 'No volunteer of that name', 'groundwork-common-volunteer-tracker' ); ?>">
				<input
					type="text"
					id="gwcvt-volunteer-search"
					class="regular-text"
					autocomplete="off"
					role="combobox"
					aria-expanded="false"
					aria-autocomplete="list"
					aria-controls="gwcvt-volunteer-results"
					placeholder="<?php esc_attr_e( 'Start typing a name…', 'groundwork-common-volunteer-tracker' ); ?>"
					value="<?php echo esc_attr( $volunteer_id > 0 ? get_the_title( $volunteer_id ) : '' ); ?>"
				/>
				<input type="hidden" name="gwcvt_volunteer" id="gwcvt-volunteer-id" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
				<ul id="gwcvt-volunteer-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
			</div>
			<span class="description">
				<?php
				printf(
					/* translators: %s: a link to the new-volunteer screen. */
					esc_html__( 'No record yet? %s first.', 'groundwork-common-volunteer-tracker' ),
					'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . GWCVT_VOLUNTEER_TYPE ) ) . '">'
						. esc_html__( 'Add the volunteer', 'groundwork-common-volunteer-tracker' )
						. '</a>'
				);
				?>
			</span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-date">
				<strong><?php esc_html_e( 'Date of the shift', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<?php
			/* required on both this and the hours field below. The save still
			 * copes with neither being given — a browser that skips validation,
			 * a Quick Edit, a programmatic write — and now says so afterwards.
			 * This is the cheaper half: catching it before the round trip. */
			?>
			<input
				type="date"
				id="gwcvt-date"
				name="gwcvt_date"
				required
				value="<?php echo esc_attr( $date ); ?>"
				<?php echo '' !== $max_date ? 'max="' . esc_attr( $max_date ) . '"' : ''; ?>
			/>
			<?php if ( '' !== $max_date ) : ?>
				<span class="description"><?php esc_html_e( 'Future dates are switched off in Settings → Logging.', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-hours">
				<strong><?php esc_html_e( 'Hours worked', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="text"
				id="gwcvt-hours"
				name="gwcvt_hours"
				class="small-text"
				inputmode="decimal"
				required
				value="<?php echo esc_attr( $minutes > 0 ? gwcvt_format_hours( $minutes ) : '' ); ?>"
			/>
			<span class="description">
				<?php
				esc_html_e( 'Accepts 3.5, 3:30, 3h 30m or 210m.', 'groundwork-common-volunteer-tracker' );

				if ( $increment > 0 ) {
					echo ' ';
					printf(
						/* translators: %d: a number of minutes. */
						esc_html__( 'Rounded to the nearest %d minutes.', 'groundwork-common-volunteer-tracker' ),
						(int) $increment
					);
				}
				?>
			</span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-activity">
				<strong><?php esc_html_e( 'What they did', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="text"
				id="gwcvt-activity"
				name="gwcvt_activity"
				class="regular-text"
				maxlength="200"
				<?php echo $vocabulary ? 'list="gwcvt-activities"' : ''; ?>
				value="<?php echo esc_attr( $activity ); ?>"
			/>
			<?php if ( $vocabulary ) : ?>
				<datalist id="gwcvt-activities">
					<?php foreach ( $vocabulary as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>
			<span class="description"><?php esc_html_e( 'Appears on the verification letter.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-supervisor">
				<strong><?php esc_html_e( 'Supervised by', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="text"
				id="gwcvt-supervisor"
				name="gwcvt_supervisor"
				class="regular-text"
				maxlength="100"
				value="<?php echo esc_attr( $supervisor ); ?>"
			/>
			<span class="description"><?php esc_html_e( 'The person who was there. Not necessarily whoever verifies the entry.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>
	</div>
	<?php
}

/**
 * The activity vocabulary, if the site has defined one.
 *
 * @return string[]
 */
function gwcvt_activity_vocabulary(): array {
	$raw = (string) gwcvt_setting( 'activities' );

	if ( '' === trim( $raw ) ) {
		return array();
	}

	$lines = preg_split( '/\R/', $raw );
	$lines = is_array( $lines ) ? $lines : array();

	return array_values( array_filter( array_map( 'trim', $lines ), static fn( $line ) => '' !== $line ) );
}

/**
 * Save a shift.
 *
 * @param int     $post_id Entry post ID.
 * @param WP_Post $post    The post.
 */
function gwcvt_save_entry( $post_id, $post ): void {
	$post_id = (int) $post_id;

	if ( ! gwcvt_should_save( $post_id, 'gwcvt_entry_nonce', 'gwcvt_save_entry' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by gwcvt_should_save() directly above.
	$posted = wp_unslash( $_POST );

	/* Everything this save quietly corrected, reported afterwards by
	 * gwcvt_entry_saved_notice(). Three things used to be fixed up in silence
	 * here, and the only trace was a derived title reading "… — 0". The screen
	 * that logs a day's shifts has always said what it skipped and why; the
	 * screen most hours are typed into said nothing. */
	$problems = array();

	$volunteer_id = isset( $posted['gwcvt_volunteer'] ) ? absint( $posted['gwcvt_volunteer'] ) : 0;

	/* A volunteer ID that does not name a volunteer is dropped rather than
	 * stored. Otherwise a stale or hand-edited value leaves an entry pointing at
	 * a page, an attachment, or nothing — and the letter would silently omit
	 * those hours with no indication anywhere that it had. */
	if ( $volunteer_id > 0 && GWCVT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		$volunteer_id = 0;
		$problems[]   = 'volunteer';
	}

	$date = isset( $posted['gwcvt_date'] ) ? gwcvt_sanitize_date( (string) $posted['gwcvt_date'] ) : '';

	if ( '' !== $date && ! gwcvt_setting( 'allow_future_dates' ) && $date > gwcvt_today() ) {
		$date       = gwcvt_today();
		$problems[] = 'future-date';
	}

	$minutes = isset( $posted['gwcvt_hours'] ) ? gwcvt_parse_hours( (string) $posted['gwcvt_hours'] ) : null;

	if ( null === $minutes ) {
		$problems[] = 'hours';
	} else {
		/* Rounding is to the nearest and never up, and it is the right default —
		 * but it changes the figure a letter prints, and it did so without ever
		 * saying it had. Somebody typing 3:07 and reading back 3.0 should be told
		 * which of the two is on the record. */
		$typed = isset( $posted['gwcvt_hours'] ) ? gwcvt_parse_hours( (string) $posted['gwcvt_hours'], false ) : null;

		if ( null !== $typed && $typed !== $minutes ) {
			$problems[] = 'rounded';
		}
	}

	gwcvt_stash_entry_problems( $post_id, $problems );

	update_post_meta( $post_id, GWCVT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $post_id, GWCVT_ENTRY_DATE, $date );
	update_post_meta( $post_id, GWCVT_ENTRY_MINUTES, (int) ( $minutes ?? 0 ) );
	update_post_meta(
		$post_id,
		GWCVT_ENTRY_ACTIVITY,
		mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_activity'] ?? '' ) ), 0, 200 )
	);
	update_post_meta(
		$post_id,
		GWCVT_ENTRY_SUPERVISOR,
		mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_supervisor'] ?? '' ) ), 0, 100 )
	);

	if ( '' === (string) get_post_meta( $post_id, GWCVT_ENTRY_SOURCE, true ) ) {
		update_post_meta( $post_id, GWCVT_ENTRY_SOURCE, 'staff' );
	}

	/* Matching a self-logged entry to a volunteer is what clears the claim. The
	 * claimed name and email were never an identity, and leaving them on a
	 * matched record means two names on one entry and a privacy eraser that has
	 * to know about both. */
	if ( $volunteer_id > 0 ) {
		delete_post_meta( $post_id, '_gwcvt_claim_name' );
		delete_post_meta( $post_id, '_gwcvt_claim_email' );
	}

	gwcvt_retitle_entry( $post_id );

	if ( $volunteer_id > 0 ) {
		gwcvt_refresh_totals( $volunteer_id );
	}

	/**
	 * Fires after an hour entry has been saved from the admin.
	 *
	 * @param int $post_id Entry post ID.
	 */
	do_action( 'gwcvt_entry_saved', $post_id );
}

/* ── Saying what was corrected ───────────────────────────────────────────────
 * A transient rather than a query argument, for the same reason the schedule
 * screen uses one for its truncation note: the entry editor's redirect is
 * WordPress's, not ours, and adding to it means filtering redirect_post_location
 * to smuggle state through a URL a user can then bookmark and re-trigger.
 *
 * Keyed by entry AND by user, because two coordinators editing the same shift
 * are two different stories, and short-lived because a message about a save is
 * worthless by the time anybody could see it stale.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Remember what a save had to fix, for the redirect that follows it.
 *
 * @param int      $entry_id Entry post ID.
 * @param string[] $problems Problem keys.
 */
function gwcvt_stash_entry_problems( int $entry_id, array $problems ): void {
	$key = 'gwcvt_entry_saved_' . $entry_id . '_' . get_current_user_id();

	if ( ! $problems ) {
		delete_transient( $key );
		return;
	}

	set_transient( $key, array_values( array_unique( $problems ) ), 2 * MINUTE_IN_SECONDS );
}

/**
 * What to say about one thing a save corrected.
 *
 * A function and not a const array: a const is evaluated at include time, which
 * freezes these in English for the request.
 *
 * @return array<string, string>
 */
function gwcvt_entry_problem_messages(): array {
	return array(
		'hours'       => __( 'This shift was saved with no hours on it, because what was typed could not be read as a duration. Hours can be written as 3.5, 3:30, 3h 30m or 210m — a bare number means hours, so anything longer than a single day is refused rather than recorded.', 'groundwork-common-volunteer-tracker' ),
		'future-date' => __( 'The date was in the future, so it was changed to today. Hours dated ahead would be dated the day they were typed rather than the day they were worked, and that date is what a letter prints. Future dates can be allowed under Settings → Logging.', 'groundwork-common-volunteer-tracker' ),
		'volunteer'   => __( 'The volunteer could not be attached, so these hours are on nobody\'s record and will not appear on any letter. Choose somebody with the picker and save again.', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * Report what the last save corrected.
 */
function gwcvt_entry_saved_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || GWCVT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	$entry_id = (int) get_the_ID();

	if ( $entry_id < 1 ) {
		return;
	}

	$key      = 'gwcvt_entry_saved_' . $entry_id . '_' . get_current_user_id();
	$problems = get_transient( $key );

	if ( ! is_array( $problems ) || ! $problems ) {
		return;
	}

	delete_transient( $key );

	$messages = gwcvt_entry_problem_messages();

	/* A warning and not an error: the entry did save, and calling it an error
	 * invites somebody to assume it did not and type it a second time. */
	echo '<div class="notice notice-warning is-dismissible">';

	foreach ( $problems as $problem ) {
		$problem = (string) $problem;

		/* Built here rather than in the table above, because it has to read back
		 * the figure that was actually stored. */
		if ( 'rounded' === $problem ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: a duration as the site formats them, 2: a number of minutes. */
						__( 'Recorded as %1$s. Hours are rounded to the nearest %2$d minutes — to the nearest, never up, so the organization never credits time nobody worked. The increment is on the Logging tab.', 'groundwork-common-volunteer-tracker' ),
						gwcvt_format_hours( (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true ) ),
						(int) gwcvt_setting( 'hour_increment' )
					)
				)
			);
			continue;
		}

		if ( isset( $messages[ $problem ] ) ) {
			printf( '<p>%s</p>', esc_html( $messages[ $problem ] ) );
		}
	}

	echo '</div>';
}

/**
 * Write the derived title, without recursing.
 *
 * @param int $entry_id Entry post ID.
 */
function gwcvt_retitle_entry( int $entry_id ): void {
	$title = gwcvt_entry_title( $entry_id );

	if ( get_post_field( 'post_title', $entry_id ) === $title ) {
		return;
	}

	/* wp_update_post() fires save_post again, and this function is called from
	 * a save_post handler. Unhooking around the write is the standard remedy
	 * and is cheaper than a static re-entry guard, which would also have to be
	 * reset for WP-CLI runs that save several posts in one process. */
	remove_action( 'save_post_' . GWCVT_ENTRY_TYPE, 'gwcvt_save_entry', 10 );

	wp_update_post(
		array(
			'ID'         => $entry_id,
			'post_title' => $title,
		)
	);

	add_action( 'save_post_' . GWCVT_ENTRY_TYPE, 'gwcvt_save_entry', 10, 2 );
}

/**
 * The volunteer's contact details.
 *
 * @param WP_Post $post The volunteer being edited.
 */
function gwcvt_render_volunteer_meta_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	$email = (string) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_EMAIL, true );
	$phone = (string) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_PHONE, true );

	wp_nonce_field( 'gwcvt_save_volunteer', 'gwcvt_volunteer_nonce' );

	$totals = $volunteer_id > 0 ? gwcvt_volunteer_totals( $volunteer_id ) : new GWCVT_Totals();
	?>
	<div class="gwcvt-fields">
		<div class="gwcvt-field">
			<label for="gwcvt-email">
				<strong><?php esc_html_e( 'Email', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input type="email" id="gwcvt-email" name="gwcvt_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" />
			<span class="description"><?php esc_html_e( 'Where a verification letter is sent, and how the privacy tools find this record.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-phone">
				<strong><?php esc_html_e( 'Phone', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input type="text" id="gwcvt-phone" name="gwcvt_phone" class="regular-text" maxlength="40" value="<?php echo esc_attr( $phone ); ?>" />
			<span class="description"><?php esc_html_e( 'For your own use — ringing round when a shift is short. It is never printed on a letter and never shown publicly.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<?php
		/* The retention hold. Courts do sometimes require an organisation to
		 * keep a record longer than its own policy, and a sweep that could not
		 * be overridden per person would make the retention setting unusable for
		 * exactly the organisations this plugin is for. It also blocks a privacy
		 * erasure request, which is why the reason is worth recording — the
		 * administrator handling that request has to explain the refusal. */
		?>
		<div class="gwcvt-field gwcvt-hold">
			<label>
				<input type="checkbox" name="gwcvt_hold" value="1" <?php checked( (bool) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_HOLD, true ) ); ?> />
				<strong><?php esc_html_e( 'Keep this record regardless of the retention policy', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<?php
			/* A visible label, not a screen-reader-only one. The placeholder used
			 * to be the only cue for a sighted user, and a placeholder disappears
			 * the moment somebody types — leaving a filled-in box with nothing
			 * saying what it holds, on the field an administrator has to read
			 * back when refusing an erasure request. */
			?>
			<label for="gwcvt-hold-reason">
				<?php esc_html_e( 'Why it is held', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input
				type="text"
				id="gwcvt-hold-reason"
				name="gwcvt_hold_reason"
				class="regular-text"
				maxlength="200"
				placeholder="<?php esc_attr_e( 'Why — e.g. court order, open case', 'groundwork-common-volunteer-tracker' ); ?>"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_HOLD_REASON, true ) ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'Also blocks an erasure request from WordPress’s privacy tools. The reason is shown to whoever handles that request, so they can explain the refusal.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>

		<?php
		/* ── What they have to complete ──────────────────────────────────────
		 * Most volunteers have nothing here and it stays empty for them. For
		 * the ones who do, this is the question they and their coordinator ask
		 * every single week, and until now the answer lived on a sticky note.
		 *
		 * It is never printed on a letter. See the note on the constants in
		 * inc/volunteer-cpt.php for why that is not a detail. */
		$required = gwcvt_required_minutes( $volunteer_id );
		?>
		<div class="gwcvt-field gwcvt-required">
			<label for="gwcvt-required">
				<strong><?php esc_html_e( 'Hours they have to complete', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="text"
				id="gwcvt-required"
				name="gwcvt_required"
				class="small-text"
				inputmode="decimal"
				value="<?php echo esc_attr( $required > 0 ? gwcvt_format_hours( $required, 'decimal' ) : '' ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. 40', 'groundwork-common-volunteer-tracker' ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'For somebody working off court-ordered or school-required service. Leave empty for everybody else. This is for your own planning and never appears on a letter — how many hours were ordered is a fact about somebody else’s document, not about anything you observed.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-required-by">
				<strong><?php esc_html_e( 'By when', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="date"
				id="gwcvt-required-by"
				name="gwcvt_required_by"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_REQUIRED_BY, true ) ); ?>"
			/>
			<span class="description"><?php esc_html_e( 'Optional. Shown as a countdown on their record and on the volunteer list.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-required-for">
				<strong><?php esc_html_e( 'Who requires it', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input
				type="text"
				id="gwcvt-required-for"
				name="gwcvt_required_for"
				class="regular-text"
				maxlength="200"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWCVT_VOLUNTEER_REQUIRED_FOR, true ) ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. a court, a school, a scouting group', 'groundwork-common-volunteer-tracker' ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'For your own records, so you know which programme this person is here under. Like the rest of this section it never reaches a letter — what a court asked for is a fact about the court\'s document, not about anything you observed.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</div>

		<?php if ( gwcvt_has_requirement( $volunteer_id ) ) : ?>
			<?php $progress = gwcvt_requirement_progress( $volunteer_id ); ?>
			<p class="gwcvt-summary gwcvt-progress<?php echo $progress['overdue'] ? ' gwcvt-progress--overdue' : ''; ?>">
				<strong><?php echo esc_html( gwcvt_requirement_label( $volunteer_id ) ); ?></strong>

				<?php if ( ! $progress['met'] ) : ?>
					—
					<?php
					printf(
						/* translators: %s: a number of hours, already formatted. */
						esc_html__( '%s to go', 'groundwork-common-volunteer-tracker' ),
						esc_html( gwcvt_format_hours( $progress['remaining'] ) )
					);
					?>

					<?php
					/* Named separately rather than counted in. Somebody four hours
					 * short with six unverified is a ten-second problem, and one a
					 * coordinator can only see if the two numbers stay apart. */
					if ( $progress['pending'] > 0 ) :
						?>
						<span class="gwcvt-badge__detail">
							<?php
							printf(
								/* translators: %s: a number of hours, already formatted. */
								esc_html__( '%s more is logged but not verified yet, so it does not count towards this.', 'groundwork-common-volunteer-tracker' ),
								esc_html( gwcvt_format_hours( $progress['pending'] ) )
							);
							?>
						</span>
					<?php endif; ?>

					<?php $due = gwcvt_requirement_deadline_label( $volunteer_id ); ?>
					<?php if ( '' !== $due ) : ?>
						<span class="gwcvt-badge__detail"><?php echo esc_html( $due ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! $totals->is_empty() ) : ?>
			<p class="gwcvt-summary">
				<?php
				printf(
					/* translators: 1: verified hours, 2: unverified hours, 3: number of shifts. */
					esc_html__( '%1$s verified, %2$s awaiting verification, across %3$s.', 'groundwork-common-volunteer-tracker' ),
					'<strong>' . esc_html( gwcvt_format_hours( $totals->verified_minutes ) ) . '</strong>',
					'<strong>' . esc_html( gwcvt_format_hours( $totals->pending_minutes ) ) . '</strong>',
					'<strong>' . esc_html(
						sprintf(
							/* translators: %d: number of shifts. */
							_n( '%d shift', '%d shifts', $totals->entries, 'groundwork-common-volunteer-tracker' ),
							$totals->entries
						)
					) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Save a volunteer.
 *
 * @param int     $post_id Volunteer post ID.
 * @param WP_Post $post    The post.
 */
function gwcvt_save_volunteer( $post_id, $post ): void {
	$post_id = (int) $post_id;

	if ( ! gwcvt_should_save( $post_id, 'gwcvt_volunteer_nonce', 'gwcvt_save_volunteer' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by gwcvt_should_save() directly above.
	$posted = wp_unslash( $_POST );

	$email = sanitize_email( (string) ( $posted['gwcvt_email'] ?? '' ) );

	update_post_meta( $post_id, GWCVT_VOLUNTEER_EMAIL, is_email( $email ) ? $email : '' );
	update_post_meta(
		$post_id,
		GWCVT_VOLUNTEER_PHONE,
		mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_phone'] ?? '' ) ), 0, 40 )
	);

	/* Zero means "nothing recorded" and is stored by deleting rather than by
	 * writing 0, so gwcvt_has_requirement() has one thing to ask and a record
	 * that never had a requirement is indistinguishable from one whose
	 * requirement was cleared. */
	$required = gwcvt_parse_required( (string) ( $posted['gwcvt_required'] ?? '' ) );

	if ( $required > 0 ) {
		update_post_meta( $post_id, GWCVT_VOLUNTEER_REQUIRED, $required );
		update_post_meta(
			$post_id,
			GWCVT_VOLUNTEER_REQUIRED_BY,
			gwcvt_sanitize_date( sanitize_text_field( (string) ( $posted['gwcvt_required_by'] ?? '' ) ) )
		);
		update_post_meta(
			$post_id,
			GWCVT_VOLUNTEER_REQUIRED_FOR,
			mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_required_for'] ?? '' ) ), 0, 200 )
		);
	} else {
		delete_post_meta( $post_id, GWCVT_VOLUNTEER_REQUIRED );
		delete_post_meta( $post_id, GWCVT_VOLUNTEER_REQUIRED_BY );
		delete_post_meta( $post_id, GWCVT_VOLUNTEER_REQUIRED_FOR );
	}

	if ( isset( $posted['gwcvt_hold'] ) ) {
		update_post_meta( $post_id, GWCVT_VOLUNTEER_HOLD, 1 );
		update_post_meta(
			$post_id,
			GWCVT_VOLUNTEER_HOLD_REASON,
			mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_hold_reason'] ?? '' ) ), 0, 200 )
		);
	} else {
		delete_post_meta( $post_id, GWCVT_VOLUNTEER_HOLD );
		delete_post_meta( $post_id, GWCVT_VOLUNTEER_HOLD_REASON );
	}
}

/**
 * The three reasons a save_post handler should do nothing.
 *
 * One function rather than the same six lines in both handlers, because the
 * failure mode of getting this wrong is either a capability check that is
 * missing from one of them or an autosave that wipes every field on the post.
 *
 * @param int    $post_id      Post being saved.
 * @param string $nonce_field  The $_POST key holding the nonce.
 * @param string $nonce_action The action the nonce was created for.
 * @return bool
 */
function gwcvt_should_save( int $post_id, string $nonce_field, string $nonce_action ): bool {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return false;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return false;
	}

	/* Capability before nonce, house rule. A failed nonce on a request the user
	 * was never entitled to make is the less interesting of the two facts. */
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce check.
	$nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';

	/* Absent rather than invalid means this save did not come from our form —
	 * a quick edit, another plugin, wp_update_post() in a migration. Returning
	 * false leaves the existing meta alone, where wp_die() would break saves
	 * this plugin has no business breaking. */
	return '' !== $nonce && wp_verify_nonce( $nonce, $nonce_action );
}

/**
 * A date, or ''.
 *
 * Uses checkdate() rather than a regex alone: '2026-02-31' matches the shape and is
 * not a day, and an entry dated on a day that does not exist is one that sorts
 * into a range it is not in.
 *
 * @param string $raw Posted value.
 * @return string Y-m-d, or ''.
 */
function gwcvt_sanitize_date( string $raw ): string {
	$raw = trim( $raw );

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
		return '';
	}

	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $raw : '';
}

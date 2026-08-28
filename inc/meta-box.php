<?php
/**
 * The entry and volunteer edit screens.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'gwc_vt_add_meta_boxes', 10, 2 );
add_action( 'save_post_' . GWC_VT_ENTRY_TYPE, 'gwc_vt_save_entry', 10, 2 );
add_action( 'save_post_' . GWC_VT_VOLUNTEER_TYPE, 'gwc_vt_save_volunteer', 10, 2 );
add_action( 'admin_notices', 'gwc_vt_entry_saved_notice' );
add_action( 'admin_notices', 'gwc_vt_photo_saved_notice' );

/* Core's post form carries no enctype of its own, so a file input on it posts
 * the filename and no bytes — silently, with $_FILES empty and the save looking
 * like it worked. post_edit_form_tag is the only hook that can add it. */
add_action( 'post_edit_form_tag', 'gwc_vt_volunteer_form_enctype' );

/* ── Why every field wrapper here is a div ───────────────────────────────────
 * The obvious markup for a labeled field is a <p>, and it is what wp-admin's
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
 *
 * @param string       $post_type The post type being edited.
 * @param WP_Post|null $post      The post, which the retention panel needs in
 *                               order to ask whether this one is held.
 */
function gwc_vt_add_meta_boxes( $post_type = '', $post = null ): void {
	add_meta_box(
		'gwc-vt-entry',
		__( 'Shift', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_entry_meta_box',
		GWC_VT_ENTRY_TYPE,
		'normal',
		'high'
	);

	add_meta_box(
		'gwc-vt-volunteer',
		__( 'Contact details', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_meta_box',
		GWC_VT_VOLUNTEER_TYPE,
		'normal',
		'high'
	);

	/* Under Contact details rather than above it: most volunteers have nothing
	 * here, and the name and the email are what somebody opened the record
	 * for. */
	add_meta_box(
		'gwc-vt-volunteer-required',
		__( 'Required service', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_required_box',
		GWC_VT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);

	/* On the right under Status, which is the column about what this record IS
	 * rather than what is in it: active or inactive, and kept or not. 'low'
	 * against Status's 'core' is what puts it underneath.
	 *
	 * Only where there is a policy to be held against. This panel exempts a
	 * record from the retention sweep, and on a site that purges nothing — which
	 * is every site until somebody sets a period, because retention_months
	 * defaults to 0 — it is a panel about a rule the organization does not have,
	 * on every volunteer.
	 *
	 * Except on a record that already carries a hold. Somebody who turned the
	 * policy off after holding a person would otherwise have a flag set on that
	 * record with nothing on the screen to show it or clear it, which is the
	 * silent-state failure this plugin has a rule about. The panel says so when
	 * it draws in that state. */
	if ( gwc_vt_retention_panel_applies( $post instanceof WP_Post ? (int) $post->ID : 0 ) ) {
		add_meta_box(
			'gwc-vt-volunteer-retention',
			__( 'Retention', 'groundwork-common-volunteer-tracker' ),
			'gwc_vt_render_volunteer_retention_box',
			GWC_VT_VOLUNTEER_TYPE,
			'side',
			'low'
		);
	}
}

/**
 * Whether a volunteer's record should carry the retention panel.
 *
 * @param int $volunteer_id Volunteer post ID, or 0 on a record with no id yet.
 * @return bool
 */
function gwc_vt_retention_panel_applies( int $volunteer_id ): bool {
	if ( (int) gwc_vt_setting( 'retention_months' ) > 0 ) {
		return true;
	}

	return $volunteer_id > 0 && gwc_vt_retention_held( $volunteer_id );
}

/**
 * The shift form.
 *
 * @param WP_Post $post The entry being edited.
 */
function gwc_vt_render_entry_meta_box( $post ): void {
	$entry_id = (int) $post->ID;

	$volunteer_id = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_VOLUNTEER, true );

	/* ── Arriving from somebody's record with them already filled in ─────────
	 * "Log hours" on a volunteer lands here, and the one thing that screen
	 * knows and this one would otherwise ask for is who it is about. Read only
	 * on a new entry: on an existing one the meta is the answer, and a query
	 * string that could overrule it would be a way to reassign somebody's hours
	 * by editing a URL.
	 *
	 * Checked to be a volunteer before it is believed, because it arrives in a
	 * URL — an ID naming something else would put a shift against a shift. */
	if ( $volunteer_id < 1 && 'auto-draft' === get_post_status( $entry_id ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefills a field on a blank form; nothing is written until the post is saved, which is nonced by core.
		$arriving = isset( $_GET['gwc_vt_for'] ) ? absint( wp_unslash( $_GET['gwc_vt_for'] ) ) : 0;

		if ( $arriving > 0 && GWC_VT_VOLUNTEER_TYPE === get_post_type( $arriving ) ) {
			$volunteer_id = $arriving;
		}
	}
	$date        = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true );
	$minutes     = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );
	$activity    = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true );
	$supervisor  = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_SUPERVISOR, true );
	$claim_name  = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_CLAIM_NAME, true );
	$claim_email = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_CLAIM_EMAIL, true );

	/* A new entry defaults to today. The overwhelmingly common case is somebody
	 * logging a shift that just finished, and an empty date field is a required
	 * field they have to fill in to record the most likely value.
	 *
	 * Tested on the status, not on the ID. post-new.php creates an auto-draft
	 * before rendering, so a new entry HAS a post ID by the time this runs and
	 * `! $entry_id` is never true — which is how this first shipped with the
	 * field silently blank. */
	if ( '' === $date && 'auto-draft' === get_post_status( $entry_id ) ) {
		$date = gwc_vt_today();
	}

	wp_nonce_field( 'gwc_vt_save_entry', 'gwc_vt_entry_nonce' );

	$max_date   = gwc_vt_setting( 'allow_future_dates' ) ? '' : gwc_vt_today();
	$vocabulary = gwc_vt_activity_vocabulary();
	$increment  = gwc_vt_hour_increment();
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
				<?php gwc_vt_render_triage_actions( $entry_id ); ?>
			</div>
		<?php endif; ?>

		<div class="gwcvt-field">
			<label for="gwcvt-volunteer-search">
				<strong><?php esc_html_e( 'Volunteer', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<?php
			/* An autocomplete rather than a <select>. A select is fine at twenty
			 * volunteers and unusable at four hundred, and the organizations this
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
				<input type="hidden" name="gwc_vt_volunteer" id="gwcvt-volunteer-id" value="<?php echo esc_attr( (string) $volunteer_id ); ?>" />
				<ul id="gwcvt-volunteer-results" class="gwcvt-picker__results" role="listbox" hidden></ul>
			</div>
			<span class="description">
				<?php
				printf(
					/* translators: %s: a link to the new-volunteer screen. */
					esc_html__( 'No record yet? %s first.', 'groundwork-common-volunteer-tracker' ),
					'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) ) . '">'
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
				name="gwc_vt_date"
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
				name="gwc_vt_hours"
				class="small-text"
				inputmode="decimal"
				required
				value="<?php echo esc_attr( $minutes > 0 ? gwc_vt_format_hours( $minutes ) : '' ); ?>"
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
				name="gwc_vt_activity"
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
				name="gwc_vt_supervisor"
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
function gwc_vt_activity_vocabulary(): array {
	$raw = (string) gwc_vt_setting( 'activities' );

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
function gwc_vt_save_entry( $post_id, $post ): void {
	$post_id = (int) $post_id;

	if ( ! gwc_vt_should_save( $post_id, 'gwc_vt_entry_nonce', 'gwc_vt_save_entry' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by gwc_vt_should_save() directly above.
	$posted = wp_unslash( $_POST );

	/* Everything this save quietly corrected, reported afterwards by
	 * gwc_vt_entry_saved_notice(). Three things used to be fixed up in silence
	 * here, and the only trace was a derived title reading "… — 0". The screen
	 * that logs a day's shifts has always said what it skipped and why; the
	 * screen most hours are typed into said nothing. */
	$problems = array();

	$volunteer_id = isset( $posted['gwc_vt_volunteer'] ) ? absint( $posted['gwc_vt_volunteer'] ) : 0;

	/* A volunteer ID that does not name a volunteer is dropped rather than
	 * stored. Otherwise a stale or hand-edited value leaves an entry pointing at
	 * a page, an attachment, or nothing — and the letter would silently omit
	 * those hours with no indication anywhere that it had. */
	if ( $volunteer_id > 0 && GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		$volunteer_id = 0;
		$problems[]   = 'volunteer';
	}

	$date = isset( $posted['gwc_vt_date'] ) ? gwc_vt_sanitize_date( (string) $posted['gwc_vt_date'] ) : '';

	if ( '' !== $date && ! gwc_vt_setting( 'allow_future_dates' ) && $date > gwc_vt_today() ) {
		$date       = gwc_vt_today();
		$problems[] = 'future-date';
	}

	$minutes = isset( $posted['gwc_vt_hours'] ) ? gwc_vt_parse_hours( (string) $posted['gwc_vt_hours'] ) : null;

	if ( null === $minutes ) {
		$problems[] = 'hours';
	} else {
		/* Rounding is to the nearest and never up, and it is the right default —
		 * but it changes the figure a letter prints, and it did so without ever
		 * saying it had. Somebody typing 3:07 and reading back 3.0 should be told
		 * which of the two is on the record. */
		$typed = isset( $posted['gwc_vt_hours'] ) ? gwc_vt_parse_hours( (string) $posted['gwc_vt_hours'], false ) : null;

		if ( null !== $typed && $typed !== $minutes ) {
			$problems[] = 'rounded';
		}
	}

	gwc_vt_stash_entry_problems( $post_id, $problems );

	update_post_meta( $post_id, GWC_VT_ENTRY_VOLUNTEER, (string) $volunteer_id );
	update_post_meta( $post_id, GWC_VT_ENTRY_DATE, $date );
	update_post_meta( $post_id, GWC_VT_ENTRY_MINUTES, (int) ( $minutes ?? 0 ) );
	update_post_meta(
		$post_id,
		GWC_VT_ENTRY_ACTIVITY,
		mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_activity'] ?? '' ) ), 0, 200 )
	);
	update_post_meta(
		$post_id,
		GWC_VT_ENTRY_SUPERVISOR,
		mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_supervisor'] ?? '' ) ), 0, 100 )
	);

	if ( '' === (string) get_post_meta( $post_id, GWC_VT_ENTRY_SOURCE, true ) ) {
		update_post_meta( $post_id, GWC_VT_ENTRY_SOURCE, 'staff' );
	}

	/* Matching a self-logged entry to a volunteer is what clears the claim. The
	 * claimed name and email were never an identity, and leaving them on a
	 * matched record means two names on one entry and a privacy eraser that has
	 * to know about both. */
	if ( $volunteer_id > 0 ) {
		gwc_vt_clear_entry_claims( $post_id );
	}

	gwc_vt_retitle_entry( $post_id );

	if ( $volunteer_id > 0 ) {
		gwc_vt_refresh_totals( $volunteer_id );
	}

	/**
	 * Fires after an hour entry has been saved from the admin.
	 *
	 * @param int $post_id Entry post ID.
	 */
	do_action( 'gwc_vt_entry_saved', $post_id );
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
function gwc_vt_stash_entry_problems( int $entry_id, array $problems ): void {
	$key = 'gwc_vt_entry_saved_' . $entry_id . '_' . get_current_user_id();

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
function gwc_vt_entry_problem_messages(): array {
	return array(
		'hours'       => __( 'This shift was saved with no hours on it, because what was typed could not be read as a duration. Hours can be written as 3.5, 3:30, 3h 30m or 210m — a bare number means hours, so anything longer than a single day is refused rather than recorded.', 'groundwork-common-volunteer-tracker' ),
		'future-date' => __( 'The date was in the future, so it was changed to today. Hours dated ahead would be dated the day they were typed rather than the day they were worked, and that date is what a letter prints. Future dates can be allowed under Settings → Logging.', 'groundwork-common-volunteer-tracker' ),
		'volunteer'   => __( 'The volunteer could not be attached, so these hours are on nobody\'s record and will not appear on any letter. Choose somebody with the picker and save again.', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * Report what the last save corrected.
 */
function gwc_vt_entry_saved_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || GWC_VT_ENTRY_TYPE !== $screen->id ) {
		return;
	}

	$entry_id = (int) get_the_ID();

	if ( $entry_id < 1 ) {
		return;
	}

	$key      = 'gwc_vt_entry_saved_' . $entry_id . '_' . get_current_user_id();
	$problems = get_transient( $key );

	if ( ! is_array( $problems ) || ! $problems ) {
		return;
	}

	delete_transient( $key );

	$messages = gwc_vt_entry_problem_messages();

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
						gwc_vt_format_hours( (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true ) ),
						(int) gwc_vt_setting( 'hour_increment' )
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
function gwc_vt_retitle_entry( int $entry_id ): void {
	$title = gwc_vt_entry_title( $entry_id );

	if ( get_post_field( 'post_title', $entry_id ) === $title ) {
		return;
	}

	/* wp_update_post() fires save_post again, and this function is called from
	 * a save_post handler. Unhooking around the write is the standard remedy
	 * and is cheaper than a static re-entry guard, which would also have to be
	 * reset for WP-CLI runs that save several posts in one process. */
	remove_action( 'save_post_' . GWC_VT_ENTRY_TYPE, 'gwc_vt_save_entry', 10 );

	wp_update_post(
		array(
			'ID'         => $entry_id,
			'post_title' => $title,
		)
	);

	add_action( 'save_post_' . GWC_VT_ENTRY_TYPE, 'gwc_vt_save_entry', 10, 2 );
}

/**
 * The volunteer's contact details.
 *
 * @param WP_Post $post The volunteer being edited.
 */
function gwc_vt_render_volunteer_meta_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	$email = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );
	$phone = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHONE, true );

	wp_nonce_field( 'gwc_vt_save_volunteer', 'gwc_vt_volunteer_nonce' );

	?>
	<div class="gwcvt-fields">
		<div class="gwcvt-field">
			<label for="gwcvt-email">
				<strong><?php esc_html_e( 'Email', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input type="email" id="gwcvt-email" name="gwc_vt_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" />
			<span class="description"><?php esc_html_e( 'Where a verification letter is sent, and how the privacy tools find this record.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<div class="gwcvt-field">
			<label for="gwcvt-phone">
				<strong><?php esc_html_e( 'Phone', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
			<input type="text" id="gwcvt-phone" name="gwc_vt_phone" class="regular-text" maxlength="40" value="<?php echo esc_attr( $phone ); ?>" />
			<span class="description"><?php esc_html_e( 'For your own use — calling around when a shift is short. It is never printed on a letter and never shown publicly.', 'groundwork-common-volunteer-tracker' ); ?></span>
		</div>

		<?php gwc_vt_render_volunteer_photo_field( $volunteer_id ); ?>

		<?php
		/* The retention hold moved to a panel of its own, on the right under
		 * Status — gwc_vt_render_volunteer_retention_box() below. It is not
		 * contact information, and it was the one thing in this box that was
		 * about the record rather than about reaching the person. */
		?>


	</div>
	<?php
}

/**
 * Court-ordered or school-required service, on its own.
 *
 * ── Why it is not in Contact details ─────────────────────────────────────────
 * It is not contact information, and it is the most sensitive thing on the
 * screen: what a court ordered is a fact about somebody else's document, and
 * inc/volunteer-cpt.php argues at length for keeping it off every outward
 * surface. Sitting it under a heading about phone numbers made it look like one
 * more field about the person rather than the one field about their obligation.
 *
 * Most volunteers have nothing here. Giving it a panel means it can be
 * collapsed and stay collapsed on a site where nobody is working off a
 * requirement, which the Contact details box could not offer while it was
 * buried inside one.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_required_box( $post ): void {
	if ( ! is_a( $post, 'WP_Post' ) || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return;
	}

	$volunteer_id = (int) $post->ID;
	?>
	<?php
	/* ── One sentence, not three fields ──────────────────────────────────────
	 * This was three labelled inputs, each under its own heading, each with a
	 * paragraph of explanation — nearly two hundred words to record three
	 * values, on a panel most volunteers never use at all.
	 *
	 * The three values make one sentence in English and always did: complete
	 * this many hours, by this date, for these people. Written that way the
	 * labels are unnecessary, because the sentence around each box says what
	 * goes in it, and two of the three paragraphs turn out to have been saying
	 * the same thing — that none of this reaches a letter. That is said once,
	 * underneath, because it is the invariant this panel exists to protect and
	 * not a caption for any one field.
	 *
	 * The labels are still there for anybody not reading the sentence: a screen
	 * reader gets "Hours they have to complete", not "edit text". A sentence
	 * with holes in it is only self-explanatory if you can see it. */
	$required = gwc_vt_required_minutes( $volunteer_id );
	?>
	<div class="gwcvt-fields">
		<p class="gwcvt-required-line">
			<?php esc_html_e( 'Complete', 'groundwork-common-volunteer-tracker' ); ?>

			<label class="screen-reader-text" for="gwcvt-required">
				<?php esc_html_e( 'Hours they have to complete', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input
				type="text"
				id="gwcvt-required"
				name="gwc_vt_required"
				class="small-text"
				inputmode="decimal"
				value="<?php echo esc_attr( $required > 0 ? gwc_vt_format_hours( $required, 'decimal' ) : '' ); ?>"
				placeholder="<?php esc_attr_e( '40', 'groundwork-common-volunteer-tracker' ); ?>"
			/>

			<?php esc_html_e( 'hours by', 'groundwork-common-volunteer-tracker' ); ?>

			<label class="screen-reader-text" for="gwcvt-required-by">
				<?php esc_html_e( 'The date they have to complete them by', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input
				type="date"
				id="gwcvt-required-by"
				name="gwc_vt_required_by"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_BY, true ) ); ?>"
			/>

			<?php esc_html_e( 'for', 'groundwork-common-volunteer-tracker' ); ?>

			<label class="screen-reader-text" for="gwcvt-required-for">
				<?php esc_html_e( 'Who requires it', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
			<input
				type="text"
				id="gwcvt-required-for"
				name="gwc_vt_required_for"
				class="regular-text"
				maxlength="200"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_FOR, true ) ); ?>"
				placeholder="<?php esc_attr_e( 'a court, a school, a scouting group', 'groundwork-common-volunteer-tracker' ); ?>"
			/>
		</p>

		<p class="description">
			<?php esc_html_e( 'For court-ordered or school-required service only. None of it ever reaches a letter.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

	<?php
	/* ── No progress line here ───────────────────────────────────────────────
	 * There was one: "0 of 30 — 30 to go", the unverified hours that do not
	 * count toward it, and the deadline. Three facts under a panel whose job is
	 * to record what somebody has to do, on a screen that already carries their
	 * hours in the panel above.
	 *
	 * It is not lost. The volunteer LIST prints the same label in its own
	 * column, which is where somebody scans across people and where a deadline
	 * is actually acted on, and the dashboard counts the overdue from the same
	 * function. This was the third place saying it and the least useful of the
	 * three: you are looking at one person, and you are here because you are
	 * typing, not counting. */
	?>
	</div>
	<?php
}

/**
 * Let the volunteer form carry a file.
 *
 * Only on this post type. Adding it everywhere would change the form tag on
 * every editor on the site for a field that only exists on one of them.
 *
 * @param WP_Post|null $post The post being edited.
 */
function gwc_vt_volunteer_form_enctype( $post = null ): void {
	if ( ! $post instanceof WP_Post || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return;
	}

	echo ' enctype="multipart/form-data"';
}

/**
 * Say when a photo was refused.
 *
 * Its own notice rather than a line in the field, because the save redirects —
 * the rendered field is a fresh page load that has forgotten the attempt. And a
 * refusal has to be said out loud: the rest of the record saved fine, so the
 * screen otherwise reports success for an upload that did not happen, which is
 * the "silent correction on save" this plugin has a rule about.
 */
function gwc_vt_photo_saved_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; picks a sentence after a redirect.
	$slug = isset( $_GET['gwc_vt_photo'] ) ? sanitize_key( wp_unslash( $_GET['gwc_vt_photo'] ) ) : '';

	if ( '' === $slug ) {
		return;
	}

	if ( 'saved' === $slug || 'removed' === $slug ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				'saved' === $slug
					? __( 'Photo saved.', 'groundwork-common-volunteer-tracker' )
					: __( 'Photo removed.', 'groundwork-common-volunteer-tracker' )
			)
		);

		return;
	}

	$message = gwc_vt_photo_error( $slug );

	if ( '' !== $message ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}

/**
 * The photograph, and what it is for.
 *
 * Rendered inside the contact-details box rather than as a box of its own: it is
 * one more way of telling two records apart, which is what the rest of that box
 * is for, and a panel by itself would give it a prominence the feature does not
 * want.
 *
 * @param int $volunteer_id Volunteer post ID.
 */
function gwc_vt_render_volunteer_photo_field( int $volunteer_id ): void {
	$url = gwc_vt_photo_url( $volunteer_id );
	?>
	<div class="gwcvt-field gwcvt-photo">
		<strong><?php esc_html_e( 'Photo', 'groundwork-common-volunteer-tracker' ); ?></strong>

		<?php if ( '' !== $url ) : ?>
			<p class="gwcvt-photo__current">
				<?php
				/* alt is empty on purpose. The picture is decoration beside a
				 * record whose title is the person's name — a screen reader
				 * announcing "photograph of Marcus Delacroix" beside a heading
				 * already reading Marcus Delacroix says it twice, and there is
				 * nothing else useful to say about a face. */
				?>
				<img src="<?php echo esc_url( $url ); ?>" alt="" width="120" height="120" class="gwcvt-photo__image" />
			</p>

			<label class="gwcvt-photo__remove">
				<input type="checkbox" name="gwc_vt_photo_remove" value="1" />
				<?php esc_html_e( 'Remove this photo when I save', 'groundwork-common-volunteer-tracker' ); ?>
			</label>
		<?php endif; ?>

		<label class="screen-reader-text" for="gwcvt-photo">
			<?php esc_html_e( 'Choose a photo', 'groundwork-common-volunteer-tracker' ); ?>
		</label>
		<input type="file" id="gwcvt-photo" name="gwc_vt_photo" accept="image/jpeg,image/png,image/webp" />

		<span class="description">
			<?php
			printf(
				/* translators: %s: a file size, such as "8 MB". */
				esc_html__( 'For telling two records apart at the desk. JPEG, PNG or WebP, up to %s.', 'groundwork-common-volunteer-tracker' ),
				esc_html( size_format( GWC_VT_PHOTO_MAX_BYTES ) )
			);
			?>
		</span>

		<span class="description">
			<?php esc_html_e( 'It is kept out of the Media Library, is never given a public address, and is never printed on a letter. Only somebody who can open this record can see it. Location data the camera recorded is discarded.', 'groundwork-common-volunteer-tracker' ); ?>
		</span>
	</div>

	<?php
}

/**
 * Take the photo off the volunteer save, and say what happened to it.
 *
 * Split from gwc_vt_save_volunteer() because it is the only part of that save
 * that can fail in a way the person needs telling about. Everything else there
 * either writes or clears a scalar; this one decodes a file somebody chose on
 * their phone, and "nothing appeared and nobody said why" is the outcome to
 * avoid.
 *
 * The result rides back on a query argument rather than a transient. A
 * transient keyed on the user would be read by whichever tab got there first,
 * and somebody saving two volunteers in two tabs is not a rare thing to do on
 * the screen where you tidy up records.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param array $posted       Unslashed POST.
 */
function gwc_vt_save_volunteer_photo( int $volunteer_id, array $posted ): void {
	if ( ! empty( $posted['gwc_vt_photo_remove'] ) ) {
		gwc_vt_delete_photo( $volunteer_id );
		gwc_vt_photo_result( 'removed' );

		return;
	}

	/* $_FILES and not $posted: file uploads never appear in $_POST, and a
	 * wp_unslash()ed copy of it would not have them either.
	 *
	 * Narrowed to the four keys that get used, each cast to what it is meant to
	 * be, rather than passed through whole. The downstream checks are the ones
	 * that matter — gwc_vt_store_photo() calls is_uploaded_file(),
	 * re-reads the bytes and decodes the image before believing any of this —
	 * but handing an unfiltered superglobal to another function is how a later
	 * caller ends up trusting a key nobody validated. */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce was verified by gwc_vt_should_save() in the caller; the array is never used as it stands, only the four keys unpacked and cast immediately below.
	$raw = isset( $_FILES['gwc_vt_photo'] ) ? (array) $_FILES['gwc_vt_photo'] : array();

	$file = array(
		/* Not sanitize_text_field(): this is a filesystem path PHP wrote, and
		 * is_uploaded_file() is what decides whether it is legitimate. Cleaning
		 * it would corrupt a valid temp path on a host whose temp directory has
		 * a character sanitize_text_field() strips. */
		'tmp_name' => isset( $raw['tmp_name'] ) ? (string) $raw['tmp_name'] : '',
		'error'    => isset( $raw['error'] ) ? (int) $raw['error'] : UPLOAD_ERR_NO_FILE,
		'size'     => isset( $raw['size'] ) ? (int) $raw['size'] : 0,
		'name'     => isset( $raw['name'] ) ? sanitize_file_name( (string) $raw['name'] ) : '',
	);

	if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
		/* Saving the record without touching the photo field is the ordinary
		 * case and says nothing. Notably it does NOT clear the photo — an empty
		 * file input is what every save of every other field looks like. */
		return;
	}

	$result = gwc_vt_store_photo( $volunteer_id, $file );

	gwc_vt_photo_result( '' === $result ? 'saved' : $result );
}

/**
 * Carry one word about the photo through the save redirect.
 *
 * @param string $slug What to report.
 */
function gwc_vt_photo_result( string $slug ): void {
	add_filter(
		'redirect_post_location',
		static function ( $location ) use ( $slug ) {
			return add_query_arg( 'gwc_vt_photo', $slug, (string) $location );
		}
	);
}

/**
 * Save a volunteer.
 *
 * @param int     $post_id Volunteer post ID.
 * @param WP_Post $post    The post.
 */
function gwc_vt_save_volunteer( $post_id, $post ): void {
	$post_id = (int) $post_id;

	if ( ! gwc_vt_should_save( $post_id, 'gwc_vt_volunteer_nonce', 'gwc_vt_save_volunteer' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by gwc_vt_should_save() directly above.
	$posted = wp_unslash( $_POST );

	$email = sanitize_email( (string) ( $posted['gwc_vt_email'] ?? '' ) );

	update_post_meta( $post_id, GWC_VT_VOLUNTEER_EMAIL, is_email( $email ) ? $email : '' );
	update_post_meta(
		$post_id,
		GWC_VT_VOLUNTEER_PHONE,
		mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_phone'] ?? '' ) ), 0, 40 )
	);

	gwc_vt_save_volunteer_photo( $post_id, $posted );

	/* Zero means "nothing recorded" and is stored by deleting rather than by
	 * writing 0, so gwc_vt_has_requirement() has one thing to ask and a record
	 * that never had a requirement is indistinguishable from one whose
	 * requirement was cleared. */
	$required = gwc_vt_parse_required( (string) ( $posted['gwc_vt_required'] ?? '' ) );

	if ( $required > 0 ) {
		update_post_meta( $post_id, GWC_VT_VOLUNTEER_REQUIRED, $required );
		update_post_meta(
			$post_id,
			GWC_VT_VOLUNTEER_REQUIRED_BY,
			gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_required_by'] ?? '' ) ) )
		);
		update_post_meta(
			$post_id,
			GWC_VT_VOLUNTEER_REQUIRED_FOR,
			mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_required_for'] ?? '' ) ), 0, 200 )
		);
	} else {
		delete_post_meta( $post_id, GWC_VT_VOLUNTEER_REQUIRED );
		delete_post_meta( $post_id, GWC_VT_VOLUNTEER_REQUIRED_BY );
		delete_post_meta( $post_id, GWC_VT_VOLUNTEER_REQUIRED_FOR );
	}

	if ( isset( $posted['gwc_vt_hold'] ) ) {
		update_post_meta( $post_id, GWC_VT_VOLUNTEER_HOLD, 1 );
		update_post_meta(
			$post_id,
			GWC_VT_VOLUNTEER_HOLD_REASON,
			mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_hold_reason'] ?? '' ) ), 0, 200 )
		);
	} else {
		delete_post_meta( $post_id, GWC_VT_VOLUNTEER_HOLD );
		delete_post_meta( $post_id, GWC_VT_VOLUNTEER_HOLD_REASON );
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
function gwc_vt_should_save( int $post_id, string $nonce_field, string $nonce_action ): bool {
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
function gwc_vt_sanitize_date( string $raw ): string {
	$raw = trim( $raw );

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
		return '';
	}

	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $raw : '';
}

/**
 * Whether this record survives the retention sweep.
 *
 * ── Why it is not in Contact details ─────────────────────────────────────────
 * It was, and it is not contact information: everything else in that box is a
 * way of reaching somebody, and this is a decision about the record itself. It
 * also has consequences the other fields do not — it blocks a privacy erasure
 * request, and the reason typed here is read back to whoever refuses one.
 *
 * On the right, under Status, because that is the column about what this record
 * IS rather than what is in it: active or inactive, and kept or not.
 *
 * ── Why an override exists at all ────────────────────────────────────────────
 * Courts do sometimes require an organization to keep a record longer than its
 * own policy, and a sweep that could not be overridden per person would make
 * the retention setting unusable for exactly the organizations this is for.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_retention_box( $post ): void {
	if ( ! is_a( $post, 'WP_Post' ) || GWC_VT_VOLUNTEER_TYPE !== $post->post_type ) {
		return;
	}

	$volunteer_id = (int) $post->ID;
	?>
	<div class="gwcvt-fields gwcvt-hold">
		<p>
			<label>
				<input type="checkbox" name="gwc_vt_hold" value="1" <?php checked( (bool) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_HOLD, true ) ); ?> />
				<strong><?php esc_html_e( 'Keep regardless of the retention policy', 'groundwork-common-volunteer-tracker' ); ?></strong>
			</label>
		</p>

		<p>
			<?php
			/* A visible label, not a screen-reader-only one. The placeholder used
			 * to be the only cue for a sighted user, and a placeholder disappears
			 * the moment somebody types — leaving a filled-in box with nothing
			 * saying what it holds, on the field an administrator has to read
			 * back when refusing an erasure request. */
			?>
			<label for="gwcvt-hold-reason"><?php esc_html_e( 'Why it is held', 'groundwork-common-volunteer-tracker' ); ?></label><br />
			<input
				type="text"
				id="gwcvt-hold-reason"
				name="gwc_vt_hold_reason"
				class="widefat"
				maxlength="200"
				placeholder="<?php esc_attr_e( 'e.g. court order, open case', 'groundwork-common-volunteer-tracker' ); ?>"
				value="<?php echo esc_attr( (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_HOLD_REASON, true ) ); ?>"
			/>
		</p>

		<p class="description">
			<?php esc_html_e( 'Also blocks an erasure request from WordPress’s privacy tools. The reason is shown to whoever handles that request.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<?php
		/* The panel is only here at all because this record is held — the
		 * organization purges nothing, so there is no sweep for the hold to
		 * exempt it from. Said out loud, because a checkbox doing nothing is
		 * worse than no checkbox. */
		?>
		<?php if ( (int) gwc_vt_setting( 'retention_months' ) < 1 ) : ?>
			<p class="description">
				<?php esc_html_e( 'Nothing is being purged at the moment, so this changes nothing until a retention period is set.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

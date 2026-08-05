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
			<input
				type="date"
				id="gwcvt-date"
				name="gwcvt_date"
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

	$volunteer_id = isset( $posted['gwcvt_volunteer'] ) ? absint( $posted['gwcvt_volunteer'] ) : 0;

	/* A volunteer ID that does not name a volunteer is dropped rather than
	 * stored. Otherwise a stale or hand-edited value leaves an entry pointing at
	 * a page, an attachment, or nothing — and the letter would silently omit
	 * those hours with no indication anywhere that it had. */
	if ( $volunteer_id > 0 && GWCVT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		$volunteer_id = 0;
	}

	$date = isset( $posted['gwcvt_date'] ) ? gwcvt_sanitize_date( (string) $posted['gwcvt_date'] ) : '';

	if ( '' !== $date && ! gwcvt_setting( 'allow_future_dates' ) && $date > gwcvt_today() ) {
		$date = gwcvt_today();
	}

	$minutes = isset( $posted['gwcvt_hours'] ) ? gwcvt_parse_hours( (string) $posted['gwcvt_hours'] ) : null;

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
			<label for="gwcvt-hold-reason" class="screen-reader-text">
				<?php esc_html_e( 'Why this record is held', 'groundwork-common-volunteer-tracker' ); ?>
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
 * checkdate() rather than a regex alone: '2026-02-31' matches the shape and is
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

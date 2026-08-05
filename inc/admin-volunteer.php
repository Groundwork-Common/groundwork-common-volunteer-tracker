<?php
/**
 * A volunteer's own record: what they did, and what we said about it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'gwcvt_add_volunteer_history_boxes' );

/* ── Why this is on the volunteer and not only in the lists ──────────────────
 * The hours list answers "what happened last week" and the Letters screen
 * answers "who needs a letter". Neither answers the question somebody actually
 * arrives with, which is about one person: how many hours has this volunteer
 * done, when, and what have we already told anybody about them.
 *
 * Getting that today means filtering the hours list by name and then separately
 * scanning the issued-letter log — two screens and a mental join, performed
 * while somebody is on the phone.
 *
 * Both panels are read-only. A shift is edited on the shift, and an issued
 * letter is not editable at all; making either editable from here would mean a
 * second write path into records whose correctness is the product.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Register both history panels.
 */
function gwcvt_add_volunteer_history_boxes(): void {
	add_meta_box(
		'gwcvt-volunteer-hours',
		__( 'Hours logged', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_volunteer_hours_box',
		GWCVT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);

	if ( ! current_user_can( gwcvt_cap( 'issue' ) ) ) {
		/* The letter log names documents sent to courts and schools. Somebody
		 * who may edit a volunteer record but not issue letters has no reason to
		 * see which ones went out. */
		return;
	}

	add_meta_box(
		'gwcvt-volunteer-letters',
		__( 'Letters issued', 'groundwork-common-volunteer-tracker' ),
		'gwcvt_render_volunteer_letters_box',
		GWCVT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);
}

/**
 * Every shift this volunteer has logged.
 *
 * @param WP_Post $post The volunteer.
 */
function gwcvt_render_volunteer_hours_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save this volunteer, then log their first shift.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	/* Drafts and pending included, unlike the letter. This screen is where staff
	 * triage, so a self-logged shift somebody has attached but not yet verified
	 * has to be visible here even though it would never reach a document. */
	$entry_ids = gwcvt_entry_ids_for_volunteer(
		$volunteer_id,
		array( 'statuses' => array( 'publish', 'pending', 'draft' ) )
	);

	if ( ! $entry_ids ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No hours logged for this volunteer yet.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	// Newest first: the question is almost always about recent work.
	$rows = array();

	foreach ( $entry_ids as $entry_id ) {
		$entry_id = (int) $entry_id;

		$rows[] = array(
			'id'       => $entry_id,
			'date'     => (string) get_post_meta( $entry_id, GWCVT_ENTRY_DATE, true ),
			'minutes'  => (int) get_post_meta( $entry_id, GWCVT_ENTRY_MINUTES, true ),
			'activity' => (string) get_post_meta( $entry_id, GWCVT_ENTRY_ACTIVITY, true ),
			'status'   => (string) get_post_status( $entry_id ),
			'verified' => gwcvt_entry_is_verified( $entry_id ),
		);
	}

	usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

	$totals = gwcvt_volunteer_totals( $volunteer_id );
	?>
	<table class="widefat striped gwcvt-history">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Activity', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verified', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( '' !== $row['date'] ? $row['date'] : '—' ); ?></td>
					<td><?php echo esc_html( gwcvt_format_hours( $row['minutes'] ) ); ?></td>
					<td><?php echo esc_html( $row['activity'] ); ?></td>
					<td>
						<?php if ( $row['verified'] ) : ?>
							<span class="gwcvt-badge gwcvt-badge--verified"><?php echo esc_html( gwcvt_verification_label( 'verified' ) ); ?></span>
						<?php else : ?>
							<span class="gwcvt-badge gwcvt-badge--waiting">
								<?php
								echo esc_html(
									'pending' === $row['status']
										? gwcvt_verification_label( 'pending' )
										: gwcvt_verification_label( 'unverified' )
								);
								?>
							</span>
						<?php endif; ?>
					</td>
					<td class="gwcvt-history__action">
						<a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>">
							<?php esc_html_e( 'Open', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="gwcvt-history__summary">
		<?php
		printf(
			/* translators: 1: verified hours, 2: unverified hours, 3: number of shifts. */
			esc_html__( '%1$s verified, %2$s awaiting verification, across %3$s.', 'groundwork-common-volunteer-tracker' ),
			'<strong>' . esc_html( gwcvt_format_hours( $totals->verified_minutes ) ) . '</strong>',
			'<strong>' . esc_html( gwcvt_format_hours( $totals->pending_minutes ) ) . '</strong>',
			'<strong>' . esc_html(
				sprintf(
					/* translators: %d: number of shifts. */
					_n( '%d shift', '%d shifts', count( $rows ), 'groundwork-common-volunteer-tracker' ),
					count( $rows )
				)
			) . '</strong>'
		);
		?>
	</p>

	<?php if ( current_user_can( gwcvt_cap( 'issue' ) ) && $totals->verified_minutes > 0 ) : ?>
		<p>
			<a class="button" href="<?php echo esc_url( gwcvt_letters_url( array( 'volunteer' => $volunteer_id ) ) ); ?>">
				<?php esc_html_e( 'Produce a letter for this volunteer', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Every letter issued about this volunteer.
 *
 * @param WP_Post $post The volunteer.
 */
function gwcvt_render_volunteer_letters_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		return;
	}

	$records = gwcvt_letters_for_volunteer( $volunteer_id );

	if ( ! $records ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No verification letter has been issued for this volunteer.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	// Newest first.
	usort( $records, static fn( array $a, array $b ): int => strcmp( $b['issued_at'], $a['issued_at'] ) );
	?>
	<table class="widefat striped gwcvt-history">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Issued', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Reference', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Hours stated', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'How', 'groundwork-common-volunteer-tracker' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'groundwork-common-volunteer-tracker' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $records as $record ) : ?>
				<tr>
					<td><?php echo esc_html( $record['issued_at'] ); ?></td>
					<td><code><?php echo esc_html( $record['reference'] ); ?></code></td>
					<td><?php echo esc_html( gwcvt_format_hours( $record['minutes'] ) ); ?></td>
					<td>
						<?php
						if ( 'email' === $record['medium'] ) {
							echo esc_html(
								$record['sent_ok']
									/* translators: %s: an email address. */
									? sprintf( __( 'Emailed to %s', 'groundwork-common-volunteer-tracker' ), $record['recipient'] )
									: __( 'Email failed', 'groundwork-common-volunteer-tracker' )
							);
						} else {
							esc_html_e( 'Printed', 'groundwork-common-volunteer-tracker' );
						}
						?>
					</td>
					<td class="gwcvt-history__action">
						<?php
						/* Straight to the checker with the reference already in
						 * the box. The common reason for looking at this table is
						 * that somebody has phoned about one of these. */
						?>
						<a href="<?php echo esc_url( gwcvt_letters_url( array( 'reference' => $record['reference'] ) ) ); ?>">
							<?php esc_html_e( 'Check it', 'groundwork-common-volunteer-tracker' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description">
		<?php esc_html_e( 'What each letter stated when it went out. Checking one compares it against the records as they stand now.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>
	<?php
}

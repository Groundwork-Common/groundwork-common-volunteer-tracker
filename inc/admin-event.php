<?php
/**
 * One event: what it is, who you need, and calling it off.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwcvt_save_event', 'gwcvt_handle_save_event' );
add_action( 'admin_post_gwcvt_cancel_event', 'gwcvt_handle_cancel_event' );
add_action( 'admin_post_gwcvt_delete_event', 'gwcvt_handle_delete_event' );
add_action( 'admin_post_gwcvt_copy_event', 'gwcvt_handle_copy_event' );

/** How many blank roles a fresh grid offers. */
const GWCVT_EVENT_BLANK_ROLES = 3;

/** How many blank times each role offers. */
const GWCVT_EVENT_BLANK_SLOTS = 3;

/* ── The grid is role-major, and that is the whole design ────────────────────
 * A role is named once and its times hang underneath it. The name is then copied
 * to every shift that role creates, so "Greeter" and "greeter" cannot both exist
 * inside one event — which is what makes a role taxonomy unnecessary. The same
 * goes for the role's supervisor, location and notes.
 *
 * ── Why it works with no JavaScript ─────────────────────────────────────────
 * There is no build step, so the grid cannot depend on a script to add a row.
 * Instead it renders blank rows the way inc/admin-quick-add.php renders eight
 * blank ones: every role gets spare times, and the grid gets spare roles. A save
 * ignores anything still blank. Somebody building a twelve-role festival saves
 * twice, which is a smaller cost than a screen that does nothing when a script
 * fails to load.
 *
 * Field names carry EXPLICIT indexes — gwcvt_roles[0][slots][2][date] — never a
 * positional []. An unticked checkbox posts nothing at all, so a positional
 * array arrives with its indexes closed up and every row after the first gap
 * reads its neighbour's answer. That is the bug tests/integration/reconcile.php
 * exists to catch on the attendance boxes, and it is the same bug here.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The add/edit screen for one event.
 *
 * @param int $event_id Event post ID, or 0 for a new one.
 */
function gwcvt_render_event_editor( int $event_id ): void {
	$is_new = $event_id < 1;

	$title       = $is_new ? '' : (string) get_the_title( $event_id );
	$description = $is_new ? '' : (string) get_post_meta( $event_id, GWCVT_EVENT_DESCRIPTION, true );
	$location    = $is_new ? '' : (string) get_post_meta( $event_id, GWCVT_EVENT_LOCATION, true );
	$supervisor  = $is_new ? wp_get_current_user()->display_name : (string) get_post_meta( $event_id, GWCVT_EVENT_SUPERVISOR, true );
	$published   = ! $is_new && 'publish' === get_post_status( $event_id );
	$cancelled   = ! $is_new && gwcvt_event_is_cancelled( $event_id );

	$roles = $is_new
		? array()
		: gwcvt_event_roles( $event_id, array( 'publish', 'draft', GWCVT_SHIFT_CANCELLED ) );

	$vocabulary = gwcvt_activity_vocabulary();
	$locations  = gwcvt_location_vocabulary();
	?>
	<div class="wrap gwcvt-wrap">
		<h1>
			<?php
			echo $is_new
				? esc_html__( 'Add an event', 'groundwork-common-volunteer-tracker' )
				: esc_html( gwcvt_event_name( $event_id ) );
			?>
		</h1>

		<?php if ( $cancelled ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'This event was called off.', 'groundwork-common-volunteer-tracker' ); ?></strong>
					<?php
					$reason = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_REASON, true ) );

					if ( '' !== $reason ) {
						echo ' ' . esc_html( $reason );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwcvt_save_event" />
			<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwcvt_save_event_' . $event_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-event-title"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-event-title" name="gwcvt_title" class="regular-text" maxlength="200" required value="<?php echo esc_attr( $title ); ?>" />
							<p class="description"><?php esc_html_e( 'What volunteers will recognise it by. "Fall Festival", not "Event 3".', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-event-description"><?php esc_html_e( 'What it is', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<textarea id="gwcvt-event-description" name="gwcvt_description" class="large-text" rows="3" maxlength="2000"><?php echo esc_textarea( $description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown above the times on the event\'s page.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td>
							<?php
							$when = $is_new ? '' : gwcvt_event_date_label( $event_id );
							?>
							<p style="margin:0">
								<strong><?php echo '' !== $when ? esc_html( $when ) : esc_html__( 'Nothing scheduled yet.', 'groundwork-common-volunteer-tracker' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Taken from the times below. There is nothing to fill in here — a second date field is a second answer, and it disagrees with the first the moment somebody moves one time.', 'groundwork-common-volunteer-tracker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-event-location"><?php esc_html_e( 'Where', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-event-location" name="gwcvt_location" class="regular-text" maxlength="200" value="<?php echo esc_attr( $location ); ?>" <?php echo $locations ? 'list="gwcvt-event-locations"' : ''; ?> />
							<?php if ( $locations ) : ?>
								<datalist id="gwcvt-event-locations">
									<?php foreach ( $locations as $place ) : ?>
										<option value="<?php echo esc_attr( $place ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'A role can override this. Most will not.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-event-supervisor"><?php esc_html_e( 'Supervisor', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-event-supervisor" name="gwcvt_supervisor" class="regular-text" maxlength="100" value="<?php echo esc_attr( $supervisor ); ?>" />
							<p class="description"><?php esc_html_e( 'Inherited by any role that does not name its own.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visible to the public', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gwcvt_published" value="1" <?php checked( $published || $is_new ); ?> />
								<?php esc_html_e( 'Published', 'groundwork-common-volunteer-tracker' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'A draft takes no signups, and its times cannot be reached by guessing their address.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<hr />

			<h2><?php esc_html_e( 'Who you need', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'A role is named once and its times hang underneath it. The name is printed on volunteers\' verification letters — name it as work.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php if ( $vocabulary ) : ?>
				<datalist id="gwcvt-event-roles">
					<?php foreach ( $vocabulary as $word ) : ?>
						<option value="<?php echo esc_attr( $word ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>

			<?php
			$index = 0;

			foreach ( $roles as $role => $slot_ids ) {
				gwcvt_render_event_role_block( $index, (string) $role, $slot_ids, $vocabulary, $locations, $event_id );
				++$index;
			}

			for ( $blank = 0; $blank < GWCVT_EVENT_BLANK_ROLES; $blank++ ) {
				gwcvt_render_event_role_block( $index, '', array(), $vocabulary, $locations, $event_id );
				++$index;
			}
			?>

			<?php if ( ! $is_new ) : ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="gwcvt-event-reason"><?php esc_html_e( 'Why, if you are removing a time', 'groundwork-common-volunteer-tracker' ); ?></label></th>
							<td>
								<input type="text" id="gwcvt-event-reason" name="gwcvt_reason" class="regular-text" maxlength="300" />
								<p class="description"><?php esc_html_e( 'Shown to anybody who was on it, if you tell them below.', 'groundwork-common-volunteer-tracker' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Telling people', 'groundwork-common-volunteer-tracker' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="gwcvt_notify" value="1" checked />
									<?php esc_html_e( 'Email anybody who was on a time this save cancels', 'groundwork-common-volunteer-tracker' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Only cancellations send anything. Renaming a role or widening a maximum tells nobody.', 'groundwork-common-volunteer-tracker' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

			<?php submit_button( $is_new ? __( 'Create the event', 'groundwork-common-volunteer-tracker' ) : __( 'Save the event', 'groundwork-common-volunteer-tracker' ) ); ?>
		</form>

		<?php
		if ( ! $is_new ) {
			gwcvt_render_event_danger_zone( $event_id );
		}
		?>
	</div>
	<?php
}

/**
 * One role and its times.
 *
 * @param int      $index      Which role block this is, for the field names.
 * @param string   $role       The role's name, or '' for a blank block.
 * @param int[]    $slot_ids   Existing shifts in this role.
 * @param string[] $vocabulary Activity suggestions.
 * @param string[] $locations  Location suggestions.
 * @param int      $event_id   Event post ID, or 0.
 */
function gwcvt_render_event_role_block( int $index, string $role, array $slot_ids, array $vocabulary, array $locations, int $event_id ): void {
	$field = 'gwcvt_roles[' . $index . ']';
	$id    = 'gwcvt-role-' . $index;

	/* Role-level values are read off the first slot. They were written to every
	 * slot in the role on the last save, so any of them answers — and the first
	 * is the one a coordinator sees at the top of the group. */
	$first      = $slot_ids ? (int) $slot_ids[0] : 0;
	$supervisor = $first > 0 ? (string) get_post_meta( $first, GWCVT_SHIFT_SUPERVISOR, true ) : '';
	$location   = $first > 0 ? (string) get_post_meta( $first, GWCVT_SHIFT_LOCATION, true ) : '';
	$notes      = $first > 0 ? (string) get_post_meta( $first, GWCVT_SHIFT_NOTES, true ) : '';
	?>
	<div class="gwcvt-role-block" style="background:#fff;border:1px solid #c3c4c7;margin:0 0 14px;padding:12px 14px">
		<div>
			<label for="<?php echo esc_attr( $id ); ?>"><strong><?php esc_html_e( 'Role', 'groundwork-common-volunteer-tracker' ); ?></strong></label><br />
			<input
				type="text"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $field ); ?>[name]"
				class="regular-text"
				maxlength="200"
				value="<?php echo esc_attr( $role ); ?>"
				placeholder="<?php esc_attr_e( 'What they will be doing', 'groundwork-common-volunteer-tracker' ); ?>"
				<?php echo $vocabulary ? 'list="gwcvt-event-roles"' : ''; ?>
			/>
		</div>

		<div style="margin-top:10px">
			<label for="<?php echo esc_attr( $id ); ?>-sup"><?php esc_html_e( 'Supervisor for this role', 'groundwork-common-volunteer-tracker' ); ?></label><br />
			<input type="text" id="<?php echo esc_attr( $id ); ?>-sup" name="<?php echo esc_attr( $field ); ?>[supervisor]" class="regular-text" maxlength="100" value="<?php echo esc_attr( $supervisor ); ?>" />
		</div>

		<div style="margin-top:10px">
			<label for="<?php echo esc_attr( $id ); ?>-loc"><?php esc_html_e( 'Where, if not the event\'s address', 'groundwork-common-volunteer-tracker' ); ?></label><br />
			<input type="text" id="<?php echo esc_attr( $id ); ?>-loc" name="<?php echo esc_attr( $field ); ?>[location]" class="regular-text" maxlength="200" value="<?php echo esc_attr( $location ); ?>" <?php echo $locations ? 'list="gwcvt-event-locations"' : ''; ?> />
		</div>

		<div style="margin-top:10px">
			<label for="<?php echo esc_attr( $id ); ?>-notes"><?php esc_html_e( 'What to know', 'groundwork-common-volunteer-tracker' ); ?></label><br />
			<textarea id="<?php echo esc_attr( $id ); ?>-notes" name="<?php echo esc_attr( $field ); ?>[notes]" class="large-text" rows="2" maxlength="1000"><?php echo esc_textarea( $notes ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Closed shoes, park round the back, ask for Dana at the desk. Shown to whoever signs up, and repeated in their confirmation, their reminder and their calendar entry. Applies to every time below.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
		</div>

		<table class="widefat striped" style="margin-top:12px">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ends next day', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Need', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Most', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Signed up', 'groundwork-common-volunteer-tracker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Remove', 'groundwork-common-volunteer-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$slot_index = 0;

				foreach ( $slot_ids as $slot_id ) {
					gwcvt_render_event_slot_row( $index, $slot_index, (int) $slot_id );
					++$slot_index;
				}

				for ( $blank = 0; $blank < GWCVT_EVENT_BLANK_SLOTS; $blank++ ) {
					gwcvt_render_event_slot_row( $index, $slot_index, 0 );
					++$slot_index;
				}
				?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * One time within a role.
 *
 * @param int $role_index Which role block.
 * @param int $slot_index Which row within it.
 * @param int $shift_id   Existing shift, or 0 for a blank row.
 */
function gwcvt_render_event_slot_row( int $role_index, int $slot_index, int $shift_id ): void {
	$field = 'gwcvt_roles[' . $role_index . '][slots][' . $slot_index . ']';
	$id    = 'gwcvt-slot-' . $role_index . '-' . $slot_index;

	$is_new    = $shift_id < 1;
	$date      = $is_new ? '' : (string) get_post_meta( $shift_id, GWCVT_SHIFT_DATE, true );
	$start     = $is_new ? '' : (string) get_post_meta( $shift_id, GWCVT_SHIFT_START, true );
	$end       = $is_new ? '' : (string) get_post_meta( $shift_id, GWCVT_SHIFT_END, true );
	$overnight = ! $is_new && get_post_meta( $shift_id, GWCVT_SHIFT_OVERNIGHT, true );
	$min       = $is_new ? '' : (string) get_post_meta( $shift_id, GWCVT_SHIFT_MIN, true );
	$max       = $is_new ? '' : (string) get_post_meta( $shift_id, GWCVT_SHIFT_MAX, true );

	$filled    = $is_new ? 0 : gwcvt_shift_filled( $shift_id );
	$waiting   = $is_new ? 0 : count( gwcvt_shift_signup_ids( $shift_id, array( GWCVT_SIGNUP_WAITLIST ) ) );
	$cancelled = ! $is_new && gwcvt_shift_is_cancelled( $shift_id );
	?>
	<tr>
		<td>
			<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( (string) $shift_id ); ?>" />
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-date"><?php esc_html_e( 'Date', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $id ); ?>-date" name="<?php echo esc_attr( $field ); ?>[date]" value="<?php echo esc_attr( $date ); ?>" />
		</td>
		<td>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-start"><?php esc_html_e( 'From', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="time" id="<?php echo esc_attr( $id ); ?>-start" name="<?php echo esc_attr( $field ); ?>[start]" value="<?php echo esc_attr( $start ); ?>" />
		</td>
		<td>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-end"><?php esc_html_e( 'To', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="time" id="<?php echo esc_attr( $id ); ?>-end" name="<?php echo esc_attr( $field ); ?>[end]" value="<?php echo esc_attr( $end ); ?>" />
		</td>
		<td>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[overnight]" value="1" <?php checked( (bool) $overnight ); ?> />
				<span class="screen-reader-text"><?php esc_html_e( 'Ends the next day', 'groundwork-common-volunteer-tracker' ); ?></span>
			</label>
		</td>
		<td>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-min"><?php esc_html_e( 'How many you need', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>-min" name="<?php echo esc_attr( $field ); ?>[min]" min="0" max="500" style="width:5em" value="<?php echo esc_attr( $min ); ?>" />
		</td>
		<td>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-max"><?php esc_html_e( 'How many it takes', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>-max" name="<?php echo esc_attr( $field ); ?>[max]" min="0" max="500" style="width:5em" value="<?php echo esc_attr( $max ); ?>" />
		</td>
		<td>
			<?php
			if ( $is_new ) {
				echo '<span class="description">' . esc_html__( 'New', 'groundwork-common-volunteer-tracker' ) . '</span>';
			} elseif ( $cancelled ) {
				echo '<span class="description">' . esc_html__( 'Cancelled', 'groundwork-common-volunteer-tracker' ) . '</span>';
			} else {
				echo esc_html( gwcvt_shift_fill_label( $shift_id ) );

				if ( $waiting > 0 ) {
					printf(
						'<br /><span class="description">%s</span>',
						esc_html(
							sprintf(
								/* translators: %d: how many people are on the waiting list. */
								_n( '%d waiting', '%d waiting', $waiting, 'groundwork-common-volunteer-tracker' ),
								$waiting
							)
						)
					);
				}
			}
			?>
		</td>
		<td>
			<?php if ( ! $is_new ) : ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[remove]" value="1" />
					<?php
					/* The consequence is spelled out on the row, computed here rather
					 * than by a script, because the cancel-versus-delete rule is
					 * invisible in a grid unless the grid says which is about to
					 * happen. A time with people on it is CANCELLED — it stays on the
					 * schedule so everybody can see it was called off. Deleting says
					 * it never existed, which is only true of a typo. */
					if ( $filled > 0 || $waiting > 0 ) {
						echo '<span class="description">' . esc_html__( 'Cancels it — people are on it', 'groundwork-common-volunteer-tracker' ) . '</span>';
					} else {
						echo '<span class="description">' . esc_html__( 'Deletes it — nobody is on it', 'groundwork-common-volunteer-tracker' ) . '</span>';
					}
					?>
				</label>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Calling an event off, and deleting one nobody joined.
 *
 * @param int $event_id Event post ID.
 */
function gwcvt_render_event_danger_zone( int $event_id ): void {
	$filled    = gwcvt_event_filled( $event_id );
	$cancelled = gwcvt_event_is_cancelled( $event_id );
	?>
	<hr />
	<h2><?php esc_html_e( 'Other things you can do', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px">
		<input type="hidden" name="action" value="gwcvt_copy_event" />
		<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
		<?php wp_nonce_field( 'gwcvt_copy_event_' . $event_id ); ?>

		<label for="gwcvt-copy-date"><?php esc_html_e( 'Run it again on', 'groundwork-common-volunteer-tracker' ); ?></label>
		<input type="date" id="gwcvt-copy-date" name="gwcvt_copy_date" required />
		<?php submit_button( __( 'Copy this event', 'groundwork-common-volunteer-tracker' ), 'secondary', 'submit', false ); ?>
		<p class="description">
			<?php esc_html_e( 'The same roles, times and numbers against a new date, saved as a draft with nobody on it. Events are copied rather than repeated — a repeat rule needs an exception list the first time somebody moves one time.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
	</form>

	<?php if ( ! $cancelled ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px">
			<input type="hidden" name="action" value="gwcvt_cancel_event" />
			<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwcvt_cancel_event_' . $event_id ); ?>

			<label for="gwcvt-event-cancel-reason"><?php esc_html_e( 'Call the whole thing off, because', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="text" id="gwcvt-event-cancel-reason" name="gwcvt_reason" class="regular-text" maxlength="300" />

			<p>
				<label>
					<input type="checkbox" name="gwcvt_notify" value="1" checked />
					<?php
					printf(
						esc_html(
							/* translators: %d: how many people are on the event. */
							_n( 'Tell the %d person who signed up', 'Tell the %d people who signed up', $filled, 'groundwork-common-volunteer-tracker' )
						),
						(int) $filled
					);
					?>
				</label>
			</p>

			<?php submit_button( __( 'Call it off', 'groundwork-common-volunteer-tracker' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<?php if ( 0 === $filled ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwcvt_delete_event" />
			<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwcvt_delete_event_' . $event_id ); ?>

			<?php submit_button( __( 'Delete it', 'groundwork-common-volunteer-tracker' ), 'delete', 'submit', false ); ?>
			<span class="description">
				<?php esc_html_e( 'Only offered while nobody has signed up. Once somebody has, it can be called off but not deleted.', 'groundwork-common-volunteer-tracker' ); ?>
			</span>
		</form>
	<?php endif; ?>
	<?php
}

/* ── Saving the grid ─────────────────────────────────────────────────────────
 * One pass over the posted roles. Within a role, each row is either an existing
 * shift to update, an existing shift to remove, or a blank row to ignore.
 *
 * A removal is CANCELLED when anybody is on the time and DELETED when nobody is.
 * The rule that a shift with a roster is cancelled and never deleted does not
 * lapse because the removal arrived from a grid rather than from a button — a
 * cancelled time stays on the schedule so everyone can see it was called off,
 * where a deleted one says it never existed, which is only true of a typo.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Create or update an event and everything under it.
 */
function gwcvt_handle_save_event(): void {
	gwcvt_require_shift_cap();

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_save_event_' . $event_id );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$title = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_title'] ?? '' ) ), 0, 200 );

	if ( '' === $title ) {
		gwcvt_event_redirect( $event_id, 'no-title' );
	}

	$published = ! empty( $posted['gwcvt_published'] );
	$notify    = ! empty( $posted['gwcvt_notify'] );
	$reason    = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_reason'] ?? '' ) ), 0, 300 );

	if ( $event_id > 0 && GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	/* A cancelled event that is saved stays cancelled. Un-cancelling is its own
	 * decision with its own button, not a side effect of correcting a typo. */
	$status = $published ? 'publish' : 'draft';

	if ( $event_id > 0 && gwcvt_event_is_cancelled( $event_id ) ) {
		$status = GWCVT_EVENT_CANCELLED;
	}

	if ( $event_id < 1 ) {
		$event_id = wp_insert_post(
			array(
				'post_type'   => GWCVT_EVENT_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		if ( is_wp_error( $event_id ) || ! $event_id ) {
			gwcvt_event_redirect( 0, 'failed' );
		}

		$event_id = (int) $event_id;
	} else {
		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	update_post_meta( $event_id, GWCVT_EVENT_DESCRIPTION, mb_substr( sanitize_textarea_field( (string) ( $posted['gwcvt_description'] ?? '' ) ), 0, 2000 ) );
	update_post_meta( $event_id, GWCVT_EVENT_LOCATION, mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_location'] ?? '' ) ), 0, 200 ) );
	update_post_meta( $event_id, GWCVT_EVENT_SUPERVISOR, mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_supervisor'] ?? '' ) ), 0, 100 ) );

	$tally = gwcvt_save_event_grid(
		$event_id,
		(array) ( $posted['gwcvt_roles'] ?? array() ),
		array(
			'status'   => $published ? 'publish' : 'draft',
			'notify'   => $notify,
			'reason'   => $reason,
			'location' => (string) get_post_meta( $event_id, GWCVT_EVENT_LOCATION, true ),
			'super'    => (string) get_post_meta( $event_id, GWCVT_EVENT_SUPERVISOR, true ),
		)
	);

	if ( '' !== $tally['error'] ) {
		gwcvt_event_redirect( $event_id, $tally['error'] );
	}

	gwcvt_event_refresh_dates( $event_id );

	/**
	 * Fires after an event and its grid have been saved.
	 *
	 * @param int   $event_id The event.
	 * @param array $tally    made, updated, cancelled, deleted, told.
	 */
	do_action( 'gwcvt_event_slots_saved', $event_id, $tally );

	gwcvt_event_redirect(
		$event_id,
		'saved',
		array(
			'gwcvt_made'      => $tally['made'],
			'gwcvt_cancelled' => $tally['cancelled'],
			'gwcvt_deleted'   => $tally['deleted'],
			'gwcvt_told'      => $tally['told'],
		)
	);
}

/**
 * Walk the posted roles and make the slots agree with them.
 *
 * @param int   $event_id Event post ID.
 * @param array $roles    The posted gwcvt_roles structure.
 * @param array $context  status, notify, reason, location, super.
 * @return array made, updated, cancelled, deleted, told, error.
 */
function gwcvt_save_event_grid( int $event_id, array $roles, array $context ): array {
	$tally = array(
		'made'      => 0,
		'updated'   => 0,
		'cancelled' => 0,
		'deleted'   => 0,
		'told'      => 0,
		'error'     => '',
	);

	$slot_status = (string) $context['status'];

	foreach ( $roles as $role ) {
		if ( ! is_array( $role ) ) {
			continue;
		}

		$name  = mb_substr( sanitize_text_field( (string) ( $role['name'] ?? '' ) ), 0, 200 );
		$slots = (array) ( $role['slots'] ?? array() );

		/* Everything a role passes down, entered once. The most specific
		 * non-empty value wins and nothing appends — a chain where one inherited
		 * field appends while the others replace is a rule nobody remembers. */
		$supervisor = mb_substr( sanitize_text_field( (string) ( $role['supervisor'] ?? '' ) ), 0, 100 );
		$location   = mb_substr( sanitize_text_field( (string) ( $role['location'] ?? '' ) ), 0, 200 );
		$notes      = mb_substr( sanitize_textarea_field( (string) ( $role['notes'] ?? '' ) ), 0, 1000 );

		$supervisor = '' !== $supervisor ? $supervisor : (string) $context['super'];
		$location   = '' !== $location ? $location : (string) $context['location'];

		$existing = array();

		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && (int) ( $slot['id'] ?? 0 ) > 0 ) {
				$existing[] = (int) $slot['id'];
			}
		}

		/* A role whose name has been emptied but which still has times under it
		 * is refused rather than guessed at. Silently keeping the old name hides
		 * a rename that did not happen; silently dropping the times loses a
		 * roster. Neither is something to do on somebody's behalf. */
		if ( '' === $name && $existing ) {
			$tally['error'] = 'no-role';
			return $tally;
		}

		if ( '' === $name ) {
			continue;
		}

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$shift_id  = (int) ( $slot['id'] ?? 0 );
			$date      = gwcvt_sanitize_date( sanitize_text_field( (string) ( $slot['date'] ?? '' ) ) );
			$start     = gwcvt_sanitize_time( sanitize_text_field( (string) ( $slot['start'] ?? '' ) ) );
			$end       = gwcvt_sanitize_time( sanitize_text_field( (string) ( $slot['end'] ?? '' ) ) );
			$overnight = ! empty( $slot['overnight'] );
			$remove    = ! empty( $slot['remove'] );

			if ( $shift_id > 0 && GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
				continue;
			}

			/* Guard against a posted ID that belongs to somebody else's event, or
			 * to a standalone shift. Without it, a crafted form could cancel any
			 * shift on the site. */
			if ( $shift_id > 0 && gwcvt_event_for_shift( $shift_id ) !== $event_id ) {
				continue;
			}

			if ( $shift_id > 0 && $remove ) {
				$tally = gwcvt_remove_event_slot( $shift_id, $context, $tally );
				continue;
			}

			/* A blank row. Not an error — the grid always offers spares. */
			if ( '' === $date && '' === $start && '' === $end && $shift_id < 1 ) {
				continue;
			}

			if ( '' === $date || gwcvt_shift_duration( $start, $end, $overnight ) < 1 ) {
				$tally['error'] = 'bad-time';
				return $tally;
			}

			$fields = array(
				GWCVT_SHIFT_DATE       => $date,
				GWCVT_SHIFT_START      => $start,
				GWCVT_SHIFT_END        => $end,
				GWCVT_SHIFT_OVERNIGHT  => $overnight ? 1 : 0,
				GWCVT_SHIFT_ACTIVITY   => $name,
				GWCVT_SHIFT_SUPERVISOR => $supervisor,
				GWCVT_SHIFT_LOCATION   => $location,
				GWCVT_SHIFT_NOTES      => $notes,
				GWCVT_SHIFT_MIN        => min( 500, absint( $slot['min'] ?? 0 ) ),
				GWCVT_SHIFT_MAX        => min( 500, absint( $slot['max'] ?? 0 ) ),
			);

			if ( $shift_id < 1 ) {
				$shift_id = wp_insert_post(
					array(
						'post_type'   => GWCVT_SHIFT_TYPE,
						'post_parent' => $event_id,
						'post_status' => $slot_status,
						'post_title'  => 'tmp',
					)
				);

				if ( is_wp_error( $shift_id ) || ! $shift_id ) {
					continue;
				}

				$shift_id = (int) $shift_id;
				++$tally['made'];

				foreach ( $fields as $key => $value ) {
					update_post_meta( $shift_id, $key, $value );
				}

				gwcvt_retitle_shift( $shift_id );

				/** This filter is documented in inc/admin-shift.php */
				do_action( 'gwcvt_shift_created', $shift_id, 0 );
				continue;
			}

			/* An existing time. Its status follows the event's, except that a
			 * cancelled time stays cancelled — bringing one back is a decision,
			 * not something that happens because somebody widened a maximum. */
			$was = array(
				'date'     => (string) get_post_meta( $shift_id, GWCVT_SHIFT_DATE, true ),
				'start'    => (string) get_post_meta( $shift_id, GWCVT_SHIFT_START, true ),
				'end'      => (string) get_post_meta( $shift_id, GWCVT_SHIFT_END, true ),
				'next_day' => (string) get_post_meta( $shift_id, GWCVT_SHIFT_OVERNIGHT, true ),
				'location' => (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ),
			);

			foreach ( $fields as $key => $value ) {
				update_post_meta( $shift_id, $key, $value );
			}

			if ( ! gwcvt_shift_is_cancelled( $shift_id ) && get_post_status( $shift_id ) !== $slot_status ) {
				wp_update_post(
					array(
						'ID'          => $shift_id,
						'post_status' => $slot_status,
					)
				);
			}

			gwcvt_retitle_shift( $shift_id );
			++$tally['updated'];

			/* Only a MOVE mails anybody, and only when asked. Renaming a role,
			 * correcting a supervisor or widening a maximum tells nobody —
			 * mailing thirty people about a spelling fix is how an organisation
			 * teaches its volunteers to ignore its email. */
			if ( $context['notify'] && gwcvt_shift_moved( $shift_id, $was ) && ! gwcvt_shift_has_ended( $shift_id ) ) {
				foreach ( gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) as $signup_id ) {
					gwcvt_queue_signup_mail( 'changed', (int) $signup_id, array( 'was' => $was ) );
					++$tally['told'];
				}
			}
		}
	}

	return $tally;
}

/**
 * Take one time off the grid: cancelled when anybody is on it, deleted when not.
 *
 * @param int   $shift_id Shift post ID.
 * @param array $context  notify, reason.
 * @param array $tally    Running counts.
 * @return array The tally.
 */
function gwcvt_remove_event_slot( int $shift_id, array $context, array $tally ): array {
	$roster = gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) );

	if ( ! $roster ) {
		wp_delete_post( $shift_id, true );
		++$tally['deleted'];

		return $tally;
	}

	if ( gwcvt_shift_is_cancelled( $shift_id ) ) {
		return $tally;
	}

	wp_update_post(
		array(
			'ID'          => $shift_id,
			'post_status' => GWCVT_SHIFT_CANCELLED,
		)
	);

	update_post_meta( $shift_id, GWCVT_SHIFT_REASON, (string) $context['reason'] );
	++$tally['cancelled'];

	if ( $context['notify'] ) {
		foreach ( $roster as $signup_id ) {
			gwcvt_queue_signup_mail( 'cancelled', (int) $signup_id, array( 'reason' => (string) $context['reason'] ) );
			++$tally['told'];
		}
	}

	/** This action is documented in inc/admin-shift.php */
	do_action( 'gwcvt_shift_cancelled', $shift_id, (string) $context['reason'], $roster );

	return $tally;
}

/**
 * Back to the schedule, or to one event, with a word about what happened.
 *
 * @param int    $event_id Event to return to, or 0 for the list.
 * @param string $result   What to say.
 * @param array  $extra    Further query arguments.
 */
function gwcvt_event_redirect( int $event_id, string $result, array $extra = array() ): void {
	$args = array_merge( array( 'gwcvt_event_result' => $result ), $extra );

	if ( $event_id > 0 ) {
		$args['gwcvt_event'] = $event_id;
	} else {
		$args['view'] = 'events';
	}

	/* Dropped by hand rather than with array_filter( …, 'strlen' ): the counts
	 * are integers, and passing one to strlen() is deprecated in PHP 8.1 — which
	 * this plugin's own suite would fail on, since it fails on deprecations. */
	foreach ( $args as $key => $value ) {
		if ( '' === $value || 0 === $value ) {
			unset( $args[ $key ] );
		}
	}

	wp_safe_redirect( gwcvt_schedule_url( $args ) );
	exit;
}

/* ── Calling it off, deleting it, running it again ───────────────────────── */

/**
 * Call an event off, and every time under it.
 */
function gwcvt_handle_cancel_event(): void {
	gwcvt_require_shift_cap( 'publish_posts' );

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_cancel_event_' . $event_id );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$reason = mb_substr( sanitize_text_field( (string) ( $posted['gwcvt_reason'] ?? '' ) ), 0, 300 );
	$notify = ! empty( $posted['gwcvt_notify'] );
	$told   = 0;

	foreach ( gwcvt_event_slot_ids( $event_id, array( 'publish', 'draft' ) ) as $shift_id ) {
		$roster = gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) );

		wp_update_post(
			array(
				'ID'          => $shift_id,
				'post_status' => GWCVT_SHIFT_CANCELLED,
			)
		);

		update_post_meta( $shift_id, GWCVT_SHIFT_REASON, $reason );

		if ( $notify ) {
			foreach ( $roster as $signup_id ) {
				gwcvt_queue_signup_mail( 'cancelled', (int) $signup_id, array( 'reason' => $reason ) );
				++$told;
			}
		}

		/** This action is documented in inc/admin-shift.php */
		do_action( 'gwcvt_shift_cancelled', $shift_id, $reason, $roster );
	}

	wp_update_post(
		array(
			'ID'          => $event_id,
			'post_status' => GWCVT_EVENT_CANCELLED,
		)
	);

	update_post_meta( $event_id, GWCVT_EVENT_REASON, $reason );

	/**
	 * Fires after an event has been called off.
	 *
	 * @param int    $event_id The event.
	 * @param string $reason   Why, as the coordinator typed it.
	 * @param int    $told     How many people were mailed.
	 */
	do_action( 'gwcvt_event_cancelled', $event_id, $reason, $told );

	gwcvt_event_redirect( $event_id, 'called-off', array( 'gwcvt_told' => $told ) );
}

/**
 * Delete an event nobody joined.
 */
function gwcvt_handle_delete_event(): void {
	gwcvt_require_shift_cap( 'delete_posts' );

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_delete_event_' . $event_id );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	/* Checked here as well as hidden on the screen. The screen is not the only
	 * thing that can post to this, and "it never existed" is only ever true of a
	 * typo — an event people signed up for is called off, not deleted. */
	if ( gwcvt_event_filled( $event_id ) > 0 ) {
		gwcvt_event_redirect( $event_id, 'has-roster' );
	}

	foreach ( gwcvt_event_slot_ids( $event_id, array( 'publish', 'draft', GWCVT_SHIFT_CANCELLED ) ) as $shift_id ) {
		wp_delete_post( $shift_id, true );
	}

	wp_delete_post( $event_id, true );

	gwcvt_event_redirect( 0, 'deleted' );
}

/**
 * Run the same event again on another date.
 *
 * Every slot moves by the same number of days, so a two-day event stays two
 * days and the gap between a set-up time and the shift it prepares for survives.
 *
 * The copy is a DRAFT. An event that went live because somebody clicked Copy is
 * a public page nobody has read.
 */
function gwcvt_handle_copy_event(): void {
	gwcvt_require_shift_cap();

	$event_id = isset( $_POST['gwcvt_event'] ) ? absint( wp_unslash( $_POST['gwcvt_event'] ) ) : 0;

	check_admin_referer( 'gwcvt_copy_event_' . $event_id );

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwcvt_event_redirect( 0, 'unknown' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified directly above.
	$posted = wp_unslash( $_POST );

	$wanted = gwcvt_sanitize_date( sanitize_text_field( (string) ( $posted['gwcvt_copy_date'] ?? '' ) ) );
	$first  = (string) get_post_meta( $event_id, GWCVT_EVENT_DATE, true );

	$to   = gwcvt_recurrence_date( $wanted );
	$from = gwcvt_recurrence_date( $first );

	if ( null === $to || null === $from ) {
		gwcvt_event_redirect( $event_id, 'bad-date' );
	}

	$offset = (int) $from->diff( $to )->format( '%r%a' );

	$copy_id = wp_insert_post(
		array(
			'post_type'   => GWCVT_EVENT_TYPE,
			'post_status' => 'draft',
			'post_title'  => (string) get_the_title( $event_id ),
		)
	);

	if ( is_wp_error( $copy_id ) || ! $copy_id ) {
		gwcvt_event_redirect( $event_id, 'failed' );
	}

	$copy_id = (int) $copy_id;

	foreach ( array( GWCVT_EVENT_DESCRIPTION, GWCVT_EVENT_LOCATION, GWCVT_EVENT_SUPERVISOR ) as $key ) {
		update_post_meta( $copy_id, $key, (string) get_post_meta( $event_id, $key, true ) );
	}

	/* Cancelled times are not copied. They are a record of what happened to THIS
	 * event, and carrying them into the next one would schedule a Sunday that
	 * was called off last time and has never been discussed since. */
	foreach ( gwcvt_event_slot_ids( $event_id, array( 'publish', 'draft' ) ) as $shift_id ) {
		$date = gwcvt_recurrence_date( (string) get_post_meta( $shift_id, GWCVT_SHIFT_DATE, true ) );

		if ( null === $date ) {
			continue;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => GWCVT_SHIFT_TYPE,
				'post_parent' => $copy_id,
				'post_status' => 'draft',
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			continue;
		}

		$new_id = (int) $new_id;

		update_post_meta( $new_id, GWCVT_SHIFT_DATE, $date->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' days' )->format( 'Y-m-d' ) );

		foreach ( array( GWCVT_SHIFT_START, GWCVT_SHIFT_END, GWCVT_SHIFT_OVERNIGHT, GWCVT_SHIFT_ACTIVITY, GWCVT_SHIFT_SUPERVISOR, GWCVT_SHIFT_LOCATION, GWCVT_SHIFT_NOTES, GWCVT_SHIFT_MIN, GWCVT_SHIFT_MAX ) as $key ) {
			update_post_meta( $new_id, $key, (string) get_post_meta( $shift_id, $key, true ) );
		}

		gwcvt_retitle_shift( $new_id );

		/** This action is documented in inc/admin-shift.php */
		do_action( 'gwcvt_shift_created', $new_id, 0 );
	}

	gwcvt_event_refresh_dates( $copy_id );

	/**
	 * Fires after an event has been copied to a new date.
	 *
	 * @param int $copy_id  The new draft event.
	 * @param int $event_id The event it was copied from.
	 */
	do_action( 'gwcvt_event_created', $copy_id, $event_id );

	gwcvt_event_redirect( $copy_id, 'copied' );
}

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

/** How many blank roles the grid offers. One — see the note below. */
const GWCVT_EVENT_BLANK_ROLES = 1;

/** How many blank times each role offers. */
const GWCVT_EVENT_BLANK_SLOTS = 1;

/* ── The grid is role-major, and that is the whole design ────────────────────
 * A role is named once and its times hang underneath it. The name is then copied
 * to every shift that role creates, so "Greeter" and "greeter" cannot both exist
 * inside one event — which is what makes a role taxonomy unnecessary. The same
 * goes for the role's supervisor, location and notes.
 *
 * ── One spare, and a script that makes more ─────────────────────────────────
 * There is no build step, so the grid must not DEPEND on a script to add a row.
 * It offers exactly one blank role and one blank time per role, and a save
 * ignores anything still blank — so with JavaScript off you can still build any
 * grid, one save at a time.
 *
 * assets/js/admin-event-grid.js then adds the "add another" buttons, cloning a
 * row the way assets/js/admin-quick-add.js already does on the log-a-day screen.
 * Enhancement, never carriage.
 *
 * The first draft of this rendered three spare roles of three spare times each,
 * borrowing the quick-add screen's convention of eight blank rows. That reads
 * fine as eight rows of one table — it looks like a sign-in sheet waiting to be
 * filled in. As three bordered cards of four labelled fields apiece it is a wall
 * of nine empty inputs in front of the one thing you came to type. The
 * convention did not survive the change in weight.
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
								<?php esc_html_e( 'Taken from the times below.', 'groundwork-common-volunteer-tracker' ); ?>
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
							<p class="description"><?php esc_html_e( 'A draft takes no signups. Publishing does not give the event an address — it is seen on the page you put it on.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Where volunteers see this', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td><?php gwcvt_render_event_visibility( $event_id, $is_new, $published ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr />

			<h2><?php esc_html_e( 'Who you need', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Role names are printed on volunteers\' verification letters.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>

			<?php if ( $vocabulary ) : ?>
				<datalist id="gwcvt-event-roles">
					<?php foreach ( $vocabulary as $word ) : ?>
						<option value="<?php echo esc_attr( $word ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>

			<div id="gwcvt-event-grid">
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

				<p>
					<button type="button" class="button" data-gwcvt-add-role>
						<?php esc_html_e( '+ Add another role', 'groundwork-common-volunteer-tracker' ); ?>
					</button>
					<span class="description">
						<?php esc_html_e( 'Or save, and a fresh blank one appears.', 'groundwork-common-volunteer-tracker' ); ?>
					</span>
				</p>
			</div>

			<?php if ( ! $is_new ) : ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Telling people', 'groundwork-common-volunteer-tracker' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="gwcvt_notify" value="1" checked />
									<?php esc_html_e( 'Email anybody affected if this save moves a time', 'groundwork-common-volunteer-tracker' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Only a move counts: the date, the times, or the address. Renaming a role or changing a number tells nobody.', 'groundwork-common-volunteer-tracker' ); ?>
								</p>
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

/* ── Where an event actually appears ─────────────────────────────────────────
 * An event has no address of its own. gwcvt_event has public => false, so the
 * only way anybody sees one is that a staff member put the Volunteer Event block
 * or the [volunteer_event] shortcode on an ordinary page.
 *
 * Nothing used to say so, and the Published checkbox beside this row implied the
 * opposite — so the reasonable reading of "Published" was "it is now live
 * somewhere", and the coordinator went looking for a link that does not exist.
 *
 * Three separate things have to be true before a volunteer can reach it, and
 * they fail independently, so each is reported on its own rather than collapsed
 * into one "not visible" sentence somebody would have to go and diagnose.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Say where this event can be seen, or what is stopping it.
 *
 * @param int  $event_id  Event post ID, 0 for an unsaved one.
 * @param bool $is_new    Whether this event has never been saved.
 * @param bool $published Whether it is published.
 */
function gwcvt_render_event_visibility( int $event_id, bool $is_new, bool $published ): void {
	if ( $is_new || $event_id < 1 ) {
		?>
		<p class="description">
			<?php esc_html_e( 'Create the event first. Then put it on a page, and this will name it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<?php
		return;
	}

	$blockers = array();

	if ( ! gwcvt_setting( 'shifts_enabled' ) ) {
		$blockers[] = __( 'Planning shifts is switched off, so nothing public is running.', 'groundwork-common-volunteer-tracker' );
	} elseif ( ! gwcvt_setting( 'signup_enabled' ) ) {
		$blockers[] = __( 'Signing up from your site is switched off, so nobody can sign up.', 'groundwork-common-volunteer-tracker' );
	} elseif ( (int) gwcvt_setting( 'schedule_page' ) < 1 ) {
		/* Non-obvious and worth stating plainly: gwcvt_signups_open() gates every
		 * public signup on the shifts page being pinned, including an event's,
		 * even though the event lives on a different page entirely. */
		$blockers[] = __( 'No shifts page is pinned. Every public signup goes through it, including this event\'s.', 'groundwork-common-volunteer-tracker' );
	}

	$page_id = gwcvt_event_page_id( $event_id );

	if ( $page_id > 0 ) {
		printf(
			'<p style="margin:0 0 4px"><strong>%1$s</strong> <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p>',
			esc_html__( 'On this page:', 'groundwork-common-volunteer-tracker' ),
			esc_url( (string) get_permalink( $page_id ) ),
			esc_html( (string) get_the_title( $page_id ) )
		);
	} else {
		?>
		<p style="margin:0 0 4px">
			<strong><?php esc_html_e( 'No page shows this event yet.', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</p>
		<p class="description" style="margin:0 0 4px">
			<?php esc_html_e( 'Add the Volunteer Event block to a page and pick this event, or paste this into one:', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<p style="margin:0 0 4px">
			<code>[volunteer_event id="<?php echo esc_html( (string) $event_id ); ?>"]</code>
		</p>
		<?php
	}

	if ( ! $published ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'It is a draft, so the page shows nothing until you publish it.', 'groundwork-common-volunteer-tracker' )
		);
	}

	foreach ( $blockers as $blocker ) {
		printf( '<p class="description">%s</p>', esc_html( $blocker ) );
	}

	/* Only when a SETTING is in the way. A draft is fixed by the checkbox above,
	 * and pointing that person at the settings screen would send them to look for
	 * a switch that is not there. */
	if ( $blockers ) {
		printf(
			'<p class="description"><a href="%1$s">%2$s</a></p>',
			esc_url( gwcvt_settings_url( 'shifts' ) ),
			esc_html__( 'Open the Shifts settings', 'groundwork-common-volunteer-tracker' )
		);
	}
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
	<div class="gwcvt-role-block" data-gwcvt-role="<?php echo esc_attr( (string) $index ); ?>" style="background:#fff;border:1px solid #c3c4c7;margin:0 0 14px;padding:12px 14px">
		<?php
		/* How much of this role is still live, worked out before the head is
		 * drawn because the head is where the coordinator decides to remove it. */
		$live     = 0;
		$occupied = 0;

		foreach ( $slot_ids as $one ) {
			if ( gwcvt_shift_is_cancelled( (int) $one ) ) {
				continue;
			}

			++$live;

			if ( gwcvt_shift_signup_ids( (int) $one, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) ) {
				++$occupied;
			}
		}
		?>

		<?php
		/* ── The role's name, and the control that removes the lot ───────────
		 * Level with the name and hard right, because that is where somebody
		 * looks for it. It was first put in with the other role fields, four
		 * rows down between "Where" and "What to know", where it was rendered
		 * and invisible — which is the same as not being there. */
		?>
		<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
			<div style="flex:1 1 280px">
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

			<?php if ( $live > 0 ) : ?>
				<div style="flex:0 1 330px;text-align:right">
					<a class="button button-small" style="color:#b32d2e;border-color:#b32d2e" href="<?php echo esc_url( gwcvt_drop_role_url( $event_id, $role ) ); ?>">
						<?php esc_html_e( 'Remove this whole role', 'groundwork-common-volunteer-tracker' ); ?>
					</a>
					<p class="description" style="margin:4px 0 0">
						<?php
						printf(
							esc_html(
								/* translators: %d: how many times the role has. */
								_n( 'It has %d time on it.', 'It has %d times on it.', $live, 'groundwork-common-volunteer-tracker' )
							),
							(int) $live
						);

						echo ' ';
						esc_html_e( 'You will be shown what goes before anything happens.', 'groundwork-common-volunteer-tracker' );
						?>
					</p>
				</div>
			<?php elseif ( $slot_ids ) : ?>
				<?php
				/* Every time in this role has been called off. There is nothing
				 * to remove — a cancelled time is kept on purpose, because
				 * people signed up for it and "this was called off" is an answer
				 * the organisation owes them. Said out loud rather than left as
				 * an empty corner, because an absent control and a control that
				 * has not loaded look identical. */
				?>
				<div style="flex:0 1 330px;text-align:right">
					<p class="description" style="margin:0">
						<?php esc_html_e( 'Every time in this role has been called off. Put one back on below if you need it again.', 'groundwork-common-volunteer-tracker' ); ?>
					</p>
				</div>
			<?php endif; ?>
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
			<textarea id="<?php echo esc_attr( $id ); ?>-notes" name="<?php echo esc_attr( $field ); ?>[notes]" class="large-text" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'Closed shoes, park round the back, ask for Dana at the desk.', 'groundwork-common-volunteer-tracker' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Shown to whoever signs up, and in their confirmation, reminder and calendar entry. Applies to every time in this role.', 'groundwork-common-volunteer-tracker' ); ?>
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

		<p>
			<button type="button" class="button button-small" data-gwcvt-add-time>
				<?php esc_html_e( '+ Add another time', 'groundwork-common-volunteer-tracker' ); ?>
			</button>
		</p>
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

	/* ── A cancelled time has to look cancelled ──────────────────────────────
	 * This row used to come back from a cancellation looking exactly like every
	 * other one: editable times, and a Remove box still offering to cancel a
	 * thing that was already cancelled. The only difference was the word
	 * "Cancelled" in the last column but one.
	 *
	 * So a coordinator ticked Remove, pressed Save, saw an unchanged row and
	 * concluded it had not worked — when it had. That is worse than a feature
	 * that fails, because the state is real and nothing on the screen agrees.
	 *
	 * Now it is struck through, greyed, carries its reason, and offers the one
	 * action that makes sense on it: bringing it back. Its values ride along in
	 * hidden fields so a save round-trips them untouched. */
	if ( $cancelled ) {
		gwcvt_render_cancelled_slot_row( $field, $id, $shift_id, $date, $start, $end, $min, $max, (bool) $overnight );
		return;
	}
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
		<td data-gwcvt-fill>
			<?php
			if ( $is_new ) {
				echo '<span class="description">' . esc_html__( 'New', 'groundwork-common-volunteer-tracker' ) . '</span>';
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
		<td data-gwcvt-remove>
			<?php
			/* ── Actions, not fields ─────────────────────────────────────────
			 * These used to be a checkbox that took effect on Save, and that is
			 * the whole reason cancelling a time appeared not to work: the form
			 * re-rendered and a cancelled row looked exactly like a live one.
			 * An action reports what it did; a deferred field has to predict it.
			 *
			 * Calling a time off stops to ask, because it needs a reason typed
			 * and decides whether people get an email. Deleting an empty one is
			 * a nonced link that happens at once, exactly as taking somebody off
			 * a roster does. */
			if ( $is_new ) {
				echo '<span class="description">&mdash;</span>';
			} elseif ( $filled > 0 || $waiting > 0 ) {
				printf(
					'<a href="%1$s" style="color:#b32d2e">%2$s</a>',
					esc_url( gwcvt_call_off_slot_url( $shift_id ) ),
					esc_html__( 'Call it off', 'groundwork-common-volunteer-tracker' )
				);
			} else {
				printf(
					'<a href="%1$s" style="color:#b32d2e">%2$s</a>',
					esc_url( gwcvt_slot_action_url( 'gwcvt_delete_slot', $shift_id ) ),
					esc_html__( 'Delete', 'groundwork-common-volunteer-tracker' )
				);
			}
			?>
		</td>
	</tr>
	<?php
}

/**
 * A time that has been called off.
 *
 * Its values ride in hidden fields rather than inputs, so a save round-trips
 * them unchanged and nothing about a cancelled time can be edited by accident.
 *
 * @param string $field     Field name prefix.
 * @param string $id        Element id prefix.
 * @param int    $shift_id  Shift post ID.
 * @param string $date      Y-m-d.
 * @param string $start     H:i.
 * @param string $end       H:i.
 * @param string $min       How many were needed.
 * @param string $max       How many it took.
 * @param bool   $overnight Whether it ran past midnight.
 */
function gwcvt_render_cancelled_slot_row( string $field, string $id, int $shift_id, string $date, string $start, string $end, string $min, string $max, bool $overnight ): void {
	$reason  = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_REASON, true ) );
	$roster  = count( gwcvt_shift_signup_ids( $shift_id, array( 'publish', GWCVT_SIGNUP_WAITLIST ) ) );
	$carried = array(
		'id'        => (string) $shift_id,
		'date'      => $date,
		'start'     => $start,
		'end'       => $end,
		'min'       => $min,
		'max'       => $max,
		'overnight' => $overnight ? '1' : '',
	);
	?>
	<tr class="gwcvt-slot--cancelled" style="background:#fcf6e9;color:#8c8f94">
		<td colspan="6">
			<?php foreach ( $carried as $key => $value ) : ?>
				<?php if ( '' !== $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $field . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>

			<s><?php echo esc_html( gwcvt_shift_date_label( $shift_id ) . ' · ' . gwcvt_shift_time_label( $shift_id ) ); ?></s>
			<strong style="color:#8a2424;margin-left:8px"><?php esc_html_e( 'Called off', 'groundwork-common-volunteer-tracker' ); ?></strong>

			<?php if ( '' !== $reason ) : ?>
				<span class="description" style="margin-left:8px"><?php echo esc_html( $reason ); ?></span>
			<?php endif; ?>
		</td>
		<td data-gwcvt-fill>
			<?php
			if ( $roster > 0 ) {
				printf(
					esc_html(
						/* translators: %d: how many people were on the time when it was called off. */
						_n( '%d person was on it', '%d people were on it', $roster, 'groundwork-common-volunteer-tracker' )
					),
					(int) $roster
				);
			} else {
				echo '<span class="description">—</span>';
			}
			?>
		</td>
		<td data-gwcvt-remove>
			<a href="<?php echo esc_url( gwcvt_slot_action_url( 'gwcvt_restore_slot', $shift_id ) ); ?>">
				<?php esc_html_e( 'Put it back on', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
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
			<?php esc_html_e( 'The same roles, times and numbers against a new date, saved as a draft with nobody on it.', 'groundwork-common-volunteer-tracker' ); ?>
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
 * @param array $context  status, notify, location, super.
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

			if ( $shift_id > 0 && GWCVT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
				continue;
			}

			/* Guard against a posted ID that belongs to somebody else's event, or
			 * to a standalone shift. Without it, a crafted form could cancel any
			 * shift on the site. */
			if ( $shift_id > 0 && gwcvt_event_for_shift( $shift_id ) !== $event_id ) {
				continue;
			}

			/* A cancelled time is never touched by a save. Calling one off and
			 * putting it back are actions of their own — see
			 * inc/admin-event-actions.php — and its values arrive here in hidden
			 * fields so that this is a no-op rather than a silent re-publish. */
			if ( $shift_id > 0 && gwcvt_shift_is_cancelled( $shift_id ) ) {
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

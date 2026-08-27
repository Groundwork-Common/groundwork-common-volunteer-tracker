<?php
/**
 * One event: what it is, who you need, and calling it off.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwc_vt_save_event', 'gwc_vt_handle_save_event' );
add_action( 'admin_post_gwc_vt_cancel_event', 'gwc_vt_handle_cancel_event' );
add_action( 'admin_post_gwc_vt_delete_event', 'gwc_vt_handle_delete_event' );
add_action( 'admin_post_gwc_vt_copy_event', 'gwc_vt_handle_copy_event' );

/** How many blank roles the grid offers. One — see the note below. */
const GWC_VT_EVENT_BLANK_ROLES = 1;

/** How many blank times each role offers. */
const GWC_VT_EVENT_BLANK_SLOTS = 1;

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
 * filled in. As three bordered cards of four labeled fields apiece it is a wall
 * of nine empty inputs in front of the one thing you came to type. The
 * convention did not survive the change in weight.
 *
 * Field names carry EXPLICIT indexes — gwc_vt_roles[0][slots][2][date] — never a
 * positional []. A cleared checkbox posts nothing at all, so a positional
 * array arrives with its indexes closed up and every row after the first gap
 * reads its neighbor's answer. That is the bug tests/integration/reconcile.php
 * exists to catch on the attendance boxes, and it is the same bug here.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The add/edit screen for one event.
 *
 * @param int    $event_id Event post ID, or 0 for a new one.
 * @param string $missing  What the URL asked for and this event has not got.
 */
function gwc_vt_render_event_editor( int $event_id, string $missing = '' ): void {
	$is_new = $event_id < 1;

	$title       = $is_new ? '' : (string) get_the_title( $event_id );
	$description = $is_new ? '' : (string) get_post_meta( $event_id, GWC_VT_EVENT_DESCRIPTION, true );
	$location    = $is_new ? '' : (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true );
	$supervisor  = $is_new ? wp_get_current_user()->display_name : (string) get_post_meta( $event_id, GWC_VT_EVENT_SUPERVISOR, true );
	$published   = ! $is_new && 'publish' === get_post_status( $event_id );
	$cancelled   = ! $is_new && gwc_vt_event_is_cancelled( $event_id );

	$roles = $is_new
		? array()
		: gwc_vt_event_roles( $event_id, array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ) );

	$vocabulary = gwc_vt_activity_vocabulary();
	$locations  = gwc_vt_location_vocabulary();
	?>
	<div class="wrap gwcvt-wrap">
		<h1>
			<?php
			echo $is_new
				? esc_html__( 'Add an event', 'groundwork-common-volunteer-tracker' )
				: esc_html( gwc_vt_event_name( $event_id ) );
			?>
		</h1>

		<?php gwc_vt_render_schedule_back( gwc_vt_schedule_url(), __( 'Back to the schedule', 'groundwork-common-volunteer-tracker' ) ); ?>
		<hr class="wp-header-end" />

		<?php
		/* Both, and not only the event's own. gwc_vt_event_redirect() lands here
		 * with the result of every save, cancel and duplicate — and until this
		 * was here, nothing on the screen printed it: the message was in the URL
		 * and the screen said nothing at all. */
		?>
		<?php gwc_vt_render_schedule_missing( $missing ); ?>
		<?php gwc_vt_schedule_notice(); ?>
		<?php gwc_vt_event_notice(); ?>

		<?php if ( $cancelled ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'This event was called off.', 'groundwork-common-volunteer-tracker' ); ?></strong>
					<?php
					$reason = trim( (string) get_post_meta( $event_id, GWC_VT_EVENT_REASON, true ) );

					if ( '' !== $reason ) {
						echo ' ' . esc_html( $reason );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gwc_vt_save_event" />
			<input type="hidden" name="gwc_vt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_save_event_' . $event_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="gwcvt-event-title"><?php esc_html_e( 'Name', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<input type="text" id="gwcvt-event-title" name="gwc_vt_title" class="regular-text" maxlength="200" required value="<?php echo esc_attr( $title ); ?>" />
							<p class="description"><?php esc_html_e( 'What volunteers will recognize it by. "Fall Festival", not "Event 3".', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gwcvt-event-description"><?php esc_html_e( 'What it is', 'groundwork-common-volunteer-tracker' ); ?></label></th>
						<td>
							<textarea id="gwcvt-event-description" name="gwc_vt_description" class="large-text" rows="3" maxlength="2000"><?php echo esc_textarea( $description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown above the times on the event\'s page.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td>
							<?php
							$when = $is_new ? '' : gwc_vt_event_date_label( $event_id );
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
							<input type="text" id="gwcvt-event-location" name="gwc_vt_location" class="regular-text" maxlength="200" value="<?php echo esc_attr( $location ); ?>" <?php echo $locations ? 'list="gwcvt-event-locations"' : ''; ?> />
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
							<input type="text" id="gwcvt-event-supervisor" name="gwc_vt_supervisor" class="regular-text" maxlength="100" value="<?php echo esc_attr( $supervisor ); ?>" />
							<p class="description"><?php esc_html_e( 'Inherited by any role that does not name its own.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<?php if ( gwc_vt_live_credential_ids() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Everybody has to hold', 'groundwork-common-volunteer-tracker' ); ?></th>
							<td>
								<div class="gwcvt-shift-credentials__list">
									<?php foreach ( gwc_vt_live_credential_ids() as $gwc_vt_event_credential ) : ?>
										<?php $gwc_vt_event_cred = gwc_vt_credential( (int) $gwc_vt_event_credential ); ?>
										<?php if ( ! $gwc_vt_event_cred ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<label class="gwcvt-shift-credentials__item">
											<input
												type="checkbox"
												name="gwc_vt_credentials[]"
												value="<?php echo esc_attr( (string) $gwc_vt_event_cred['id'] ); ?>"
												<?php checked( in_array( $gwc_vt_event_cred['id'], gwc_vt_shift_credential_ids( $event_id ), true ) ); ?>
											/>
											<?php echo esc_html( $gwc_vt_event_cred['name'] ); ?>
											<?php if ( 'block' === $gwc_vt_event_cred['mode'] ) : ?>
												<span class="gwcvt-badge gwcvt-badge--cancelled"><?php esc_html_e( 'stops signup', 'groundwork-common-volunteer-tracker' ); ?></span>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>
								<?php
								/* "Added to" rather than "inherited by", and the difference is
								 * the one place this feature departs from how the rest of an
								 * event's fields work. Location and supervisor are inherited:
								 * a role naming its own replaces the event's. Credentials add,
								 * because the real case is a waiver for the whole day plus a
								 * food handler card on the kitchen role, and replacing would
								 * silently drop the waiver from the one role most likely to
								 * need it. */
								?>
								<p class="description"><?php esc_html_e( 'Added to whatever each role asks for, rather than replacing it — so a waiver here plus a food handler card on the kitchen role means the kitchen needs both.', 'groundwork-common-volunteer-tracker' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visible to the public', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gwc_vt_published" value="1" <?php checked( $published || $is_new ); ?> />
								<?php esc_html_e( 'Published', 'groundwork-common-volunteer-tracker' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'A draft takes no signups. Publishing does not give the event an address — it is seen on the page you put it on.', 'groundwork-common-volunteer-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Where volunteers see this', 'groundwork-common-volunteer-tracker' ); ?></th>
						<td><?php gwc_vt_render_event_visibility( $event_id, $is_new, $published ); ?></td>
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
					gwc_vt_render_event_role_block( $index, (string) $role, $slot_ids, $vocabulary, $locations, $event_id );
					++$index;
				}

				for ( $blank = 0; $blank < GWC_VT_EVENT_BLANK_ROLES; $blank++ ) {
					gwc_vt_render_event_role_block( $index, '', array(), $vocabulary, $locations, $event_id );
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
									<input type="checkbox" name="gwc_vt_notify" value="1" checked />
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
			gwc_vt_render_event_danger_zone( $event_id );
		}
		?>
	</div>
	<?php
}

/* ── Where an event actually appears ─────────────────────────────────────────
 * An event has no address of its own. gwc_vt_event has public => false, so the
 * only way anybody sees one is that a staff member put the Volunteer Event block
 * or the [gwc_vt_event_grid] shortcode on an ordinary page.
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
function gwc_vt_render_event_visibility( int $event_id, bool $is_new, bool $published ): void {
	if ( $is_new || $event_id < 1 ) {
		?>
		<p class="description">
			<?php esc_html_e( 'Create the event first. Then put it on a page or a post, and this will name it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<?php
		return;
	}

	$blockers = array();

	if ( ! gwc_vt_setting( 'shifts_enabled' ) ) {
		$blockers[] = __( 'Planning shifts is switched off, so nothing public is running.', 'groundwork-common-volunteer-tracker' );
	} elseif ( ! gwc_vt_setting( 'signup_enabled' ) ) {
		$blockers[] = __( 'Signing up from your site is switched off, so nobody can sign up.', 'groundwork-common-volunteer-tracker' );
	} elseif ( (int) gwc_vt_setting( 'schedule_page' ) < 1 ) {
		/* Non-obvious and worth stating plainly: gwc_vt_signups_open() gates every
		 * public signup on the shifts page being pinned, including an event's,
		 * even though the event lives on a different page entirely. */
		$blockers[] = __( 'No shifts page is pinned. Every public signup goes through it, including this event\'s.', 'groundwork-common-volunteer-tracker' );
	}

	$page_id = gwc_vt_event_page_id( $event_id );

	if ( $page_id > 0 ) {
		printf(
			'<p style="margin:0 0 4px"><strong>%1$s</strong> <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p>',
			esc_html__( 'Shown on:', 'groundwork-common-volunteer-tracker' ),
			esc_url( (string) get_permalink( $page_id ) ),
			esc_html( (string) get_the_title( $page_id ) )
		);
	} else {
		?>
		<p style="margin:0 0 4px">
			<strong><?php esc_html_e( 'Nothing on your site shows this event yet.', 'groundwork-common-volunteer-tracker' ); ?></strong>
		</p>
		<p class="description" style="margin:0 0 4px">
			<?php esc_html_e( 'Add the Volunteer Event block to a page or a post and pick this event, or paste this into one:', 'groundwork-common-volunteer-tracker' ); ?>
		</p>
		<p style="margin:0 0 4px">
			<code>[gwc_vt_event_grid id="<?php echo esc_html( (string) $event_id ); ?>"]</code>
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
			esc_url( gwc_vt_settings_url( 'shifts' ) ),
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
function gwc_vt_render_event_role_block( int $index, string $role, array $slot_ids, array $vocabulary, array $locations, int $event_id ): void {
	$field = 'gwc_vt_roles[' . $index . ']';
	$id    = 'gwcvt-role-' . $index;

	/* Role-level values are read off the first slot. They were written to every
	 * slot in the role on the last save, so any of them answers — and the first
	 * is the one a coordinator sees at the top of the group. */
	$first      = $slot_ids ? (int) $slot_ids[0] : 0;
	$supervisor = $first > 0 ? (string) get_post_meta( $first, GWC_VT_SHIFT_SUPERVISOR, true ) : '';
	$location   = $first > 0 ? (string) get_post_meta( $first, GWC_VT_SHIFT_LOCATION, true ) : '';
	$notes      = $first > 0 ? (string) get_post_meta( $first, GWC_VT_SHIFT_NOTES, true ) : '';
	?>
	<div class="gwcvt-role-block" data-gwcvt-role="<?php echo esc_attr( (string) $index ); ?>" style="background:#fff;border:1px solid #c3c4c7;margin:0 0 14px;padding:12px 14px">
		<?php
		/* How much of this role is still live, worked out before the head is
		 * drawn because the head is where the coordinator decides to remove it. */
		$live     = 0;
		$occupied = 0;

		foreach ( $slot_ids as $one ) {
			if ( gwc_vt_shift_is_cancelled( (int) $one ) ) {
				continue;
			}

			++$live;

			if ( gwc_vt_shift_signup_ids( (int) $one, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) ) {
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
					<a class="button button-small" style="color:#b32d2e;border-color:#b32d2e" href="<?php echo esc_url( gwc_vt_drop_role_url( $event_id, $role ) ); ?>">
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
				 * the organization owes them. Said out loud rather than left as
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
			<textarea id="<?php echo esc_attr( $id ); ?>-notes" name="<?php echo esc_attr( $field ); ?>[notes]" class="large-text" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'Closed shoes, park around the back, ask for Dana at the desk.', 'groundwork-common-volunteer-tracker' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea>
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
					gwc_vt_render_event_slot_row( $index, $slot_index, (int) $slot_id );
					++$slot_index;
				}

				for ( $blank = 0; $blank < GWC_VT_EVENT_BLANK_SLOTS; $blank++ ) {
					gwc_vt_render_event_slot_row( $index, $slot_index, 0 );
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
function gwc_vt_render_event_slot_row( int $role_index, int $slot_index, int $shift_id ): void {
	$field = 'gwc_vt_roles[' . $role_index . '][slots][' . $slot_index . ']';
	$id    = 'gwcvt-slot-' . $role_index . '-' . $slot_index;

	$is_new    = $shift_id < 1;
	$date      = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );
	$start     = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_START, true );
	$end       = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_END, true );
	$overnight = ! $is_new && get_post_meta( $shift_id, GWC_VT_SHIFT_OVERNIGHT, true );
	$min       = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );
	$max       = $is_new ? '' : (string) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );

	$filled    = $is_new ? 0 : gwc_vt_shift_filled( $shift_id );
	$waiting   = $is_new ? 0 : count( gwc_vt_shift_signup_ids( $shift_id, array( GWC_VT_SIGNUP_WAITLIST ) ) );
	$cancelled = ! $is_new && gwc_vt_shift_is_cancelled( $shift_id );

	/* ── A cancelled time has to look cancelled ──────────────────────────────
	 * This row used to come back from a cancellation looking exactly like every
	 * other one: editable times, and a Remove box still offering to cancel a
	 * thing that was already cancelled. The only difference was the word
	 * "Cancelled" in the last column but one.
	 *
	 * So a coordinator selected Remove, pressed Save, saw an unchanged row and
	 * concluded it had not worked — when it had. That is worse than a feature
	 * that fails, because the state is real and nothing on the screen agrees.
	 *
	 * Now it is struck through, greyed, carries its reason, and offers the one
	 * action that makes sense on it: bringing it back. Its values ride along in
	 * hidden fields so a save round-trips them untouched. */
	if ( $cancelled ) {
		gwc_vt_render_cancelled_slot_row( $field, $id, $shift_id, $date, $start, $end, $min, $max, (bool) $overnight );
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
			<input type="number" id="<?php echo esc_attr( $id ); ?>-min" name="<?php echo esc_attr( $field ); ?>[min]" min="0" max="<?php echo esc_attr( (string) GWC_VT_SHIFT_CAPACITY_MAX ); ?>" style="width:5em" value="<?php echo esc_attr( $min ); ?>" />
		</td>
		<td>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-max"><?php esc_html_e( 'How many it takes', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>-max" name="<?php echo esc_attr( $field ); ?>[max]" min="0" max="<?php echo esc_attr( (string) GWC_VT_SHIFT_CAPACITY_MAX ); ?>" style="width:5em" value="<?php echo esc_attr( $max ); ?>" />
		</td>
		<td data-gwcvt-fill>
			<?php
			if ( $is_new ) {
				echo '<span class="description">' . esc_html__( 'New', 'groundwork-common-volunteer-tracker' ) . '</span>';
			} else {
				echo esc_html( gwc_vt_shift_fill_label( $shift_id ) );

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
					esc_url( gwc_vt_call_off_slot_url( $shift_id ) ),
					esc_html__( 'Call it off', 'groundwork-common-volunteer-tracker' )
				);
			} else {
				printf(
					'<a href="%1$s" style="color:#b32d2e">%2$s</a>',
					esc_url( gwc_vt_slot_action_url( 'gwc_vt_delete_slot', $shift_id ) ),
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
function gwc_vt_render_cancelled_slot_row( string $field, string $id, int $shift_id, string $date, string $start, string $end, string $min, string $max, bool $overnight ): void {
	$reason  = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_REASON, true ) );
	$roster  = count( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) );
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

			<s><?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) . ' · ' . gwc_vt_shift_time_label( $shift_id ) ); ?></s>
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
			<a href="<?php echo esc_url( gwc_vt_slot_action_url( 'gwc_vt_restore_slot', $shift_id ) ); ?>">
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
function gwc_vt_render_event_danger_zone( int $event_id ): void {
	$filled    = gwc_vt_event_filled( $event_id );
	$cancelled = gwc_vt_event_is_cancelled( $event_id );
	?>
	<hr />
	<h2><?php esc_html_e( 'Other things you can do', 'groundwork-common-volunteer-tracker' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px">
		<input type="hidden" name="action" value="gwc_vt_copy_event" />
		<input type="hidden" name="gwc_vt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
		<?php wp_nonce_field( 'gwc_vt_copy_event_' . $event_id ); ?>

		<label for="gwcvt-copy-date"><?php esc_html_e( 'Run it again on', 'groundwork-common-volunteer-tracker' ); ?></label>
		<input type="date" id="gwcvt-copy-date" name="gwc_vt_copy_date" required />

		<label for="gwcvt-event-repeat"><?php esc_html_e( 'and then', 'groundwork-common-volunteer-tracker' ); ?></label>
		<select id="gwcvt-event-repeat" name="gwc_vt_repeat">
			<?php foreach ( gwc_vt_recurrence_patterns() as $gwc_vt_ev_key => $gwc_vt_ev_label ) : ?>
				<option value="<?php echo esc_attr( $gwc_vt_ev_key ); ?>"><?php echo esc_html( $gwc_vt_ev_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="gwcvt-event-until"><?php esc_html_e( 'until', 'groundwork-common-volunteer-tracker' ); ?></label>
		<input type="date" id="gwcvt-event-until" name="gwc_vt_until" value="" />

		<?php submit_button( __( 'Copy this event', 'groundwork-common-volunteer-tracker' ), 'secondary', 'submit', false ); ?>

		<p class="description">
			<?php esc_html_e( 'The same roles, times, numbers and credentials against a new date, saved as a draft with nobody on it.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<p class="description">
			<?php
			printf(
				/* translators: %d: the most copies one run can make. */
				esc_html__( 'Leave the pattern on “Just this once” for a single copy. On a pattern, every run is a whole event of its own — call off October and November is untouched. Up to %d at a time.', 'groundwork-common-volunteer-tracker' ),
				(int) GWC_VT_EVENT_REPEAT_MAX
			);
			?>
		</p>
	</form>

	<?php if ( ! $cancelled ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px">
			<input type="hidden" name="action" value="gwc_vt_cancel_event" />
			<input type="hidden" name="gwc_vt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_cancel_event_' . $event_id ); ?>

			<label for="gwcvt-event-cancel-reason"><?php esc_html_e( 'Call the whole thing off, because', 'groundwork-common-volunteer-tracker' ); ?></label>
			<input type="text" id="gwcvt-event-cancel-reason" name="gwc_vt_reason" class="regular-text" maxlength="<?php echo esc_attr( (string) GWC_VT_SHIFT_REASON_MAX ); ?>" />

			<p>
				<label>
					<input type="checkbox" name="gwc_vt_notify" value="1" checked />
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
			<input type="hidden" name="action" value="gwc_vt_delete_event" />
			<input type="hidden" name="gwc_vt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<?php wp_nonce_field( 'gwc_vt_delete_event_' . $event_id ); ?>

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
function gwc_vt_handle_save_event(): void {
	gwc_vt_require_shift_cap();

	$event_id = isset( $_POST['gwc_vt_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_save_event_' . $event_id );

	$posted = wp_unslash( $_POST );

	$title = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_title'] ?? '' ) ), 0, 200 );

	if ( '' === $title ) {
		gwc_vt_event_redirect( $event_id, 'no-title' );
	}

	$published = ! empty( $posted['gwc_vt_published'] );
	$notify    = ! empty( $posted['gwc_vt_notify'] );

	if ( $event_id > 0 && GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwc_vt_event_redirect( 0, 'unknown' );
	}

	/* A cancelled event that is saved stays cancelled. Un-cancelling is its own
	 * decision with its own button, not a side effect of correcting a typo. */
	$status = $published ? 'publish' : 'draft';

	if ( $event_id > 0 && gwc_vt_event_is_cancelled( $event_id ) ) {
		$status = GWC_VT_EVENT_CANCELLED;
	}

	if ( $event_id < 1 ) {
		$event_id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_EVENT_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		if ( is_wp_error( $event_id ) || ! $event_id ) {
			gwc_vt_event_redirect( 0, 'failed' );
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

	update_post_meta( $event_id, GWC_VT_EVENT_DESCRIPTION, mb_substr( sanitize_textarea_field( (string) ( $posted['gwc_vt_description'] ?? '' ) ), 0, 2000 ) );
	update_post_meta( $event_id, GWC_VT_EVENT_LOCATION, mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_location'] ?? '' ) ), 0, 200 ) );
	update_post_meta( $event_id, GWC_VT_EVENT_SUPERVISOR, mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_supervisor'] ?? '' ) ), 0, 100 ) );

	/* On the event itself, not copied down to its slots. The union happens at
	 * read time in gwc_vt_required_credential_ids(), so adding the day's waiver
	 * after the grid exists reaches every slot without rewriting any of them —
	 * and removing it later removes it from all of them, which copying could
	 * not undo. */
	gwc_vt_set_shift_credentials( $event_id, gwc_vt_posted_credential_ids( $posted ) );

	$tally = gwc_vt_save_event_grid(
		$event_id,
		(array) ( $posted['gwc_vt_roles'] ?? array() ),
		array(
			'status'   => $published ? 'publish' : 'draft',
			'notify'   => $notify,
			'location' => (string) get_post_meta( $event_id, GWC_VT_EVENT_LOCATION, true ),
			'super'    => (string) get_post_meta( $event_id, GWC_VT_EVENT_SUPERVISOR, true ),
		)
	);

	if ( '' !== $tally['error'] ) {
		gwc_vt_event_redirect( $event_id, $tally['error'] );
	}

	gwc_vt_event_refresh_dates( $event_id );

	/**
	 * Fires after an event and its grid have been saved.
	 *
	 * @param int   $event_id The event.
	 * @param array $tally    made, updated, cancelled, deleted, told.
	 */
	do_action( 'gwc_vt_event_slots_saved', $event_id, $tally );

	gwc_vt_event_redirect(
		$event_id,
		'saved',
		array(
			'gwc_vt_made'      => $tally['made'],
			'gwc_vt_cancelled' => $tally['cancelled'],
			'gwc_vt_deleted'   => $tally['deleted'],
			'gwc_vt_told'      => $tally['told'],
		)
	);
}

/**
 * Walk the posted roles and make the slots agree with them.
 *
 * @param int   $event_id Event post ID.
 * @param array $roles    The posted gwc_vt_roles structure.
 * @param array $context  status, notify, location, super.
 * @return array made, updated, cancelled, deleted, told, error.
 */
function gwc_vt_save_event_grid( int $event_id, array $roles, array $context ): array {
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
			$date      = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $slot['date'] ?? '' ) ) );
			$start     = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $slot['start'] ?? '' ) ) );
			$end       = gwc_vt_sanitize_time( sanitize_text_field( (string) ( $slot['end'] ?? '' ) ) );
			$overnight = ! empty( $slot['overnight'] );

			if ( $shift_id > 0 && GWC_VT_SHIFT_TYPE !== get_post_type( $shift_id ) ) {
				continue;
			}

			/* Guard against a posted ID that belongs to somebody else's event, or
			 * to a standalone shift. Without it, a crafted form could cancel any
			 * shift on the site. */
			if ( $shift_id > 0 && gwc_vt_event_for_shift( $shift_id ) !== $event_id ) {
				continue;
			}

			/* A cancelled time is never touched by a save. Calling one off and
			 * putting it back are actions of their own — see
			 * inc/admin-event-actions.php — and its values arrive here in hidden
			 * fields so that this is a no-op rather than a silent re-publish. */
			if ( $shift_id > 0 && gwc_vt_shift_is_cancelled( $shift_id ) ) {
				continue;
			}

			/* A blank row. Not an error — the grid always offers spares. */
			if ( '' === $date && '' === $start && '' === $end && $shift_id < 1 ) {
				continue;
			}

			if ( '' === $date || gwc_vt_shift_duration( $start, $end, $overnight ) < 1 ) {
				$tally['error'] = 'bad-time';
				return $tally;
			}

			$fields = array(
				GWC_VT_SHIFT_DATE       => $date,
				GWC_VT_SHIFT_START      => $start,
				GWC_VT_SHIFT_END        => $end,
				GWC_VT_SHIFT_OVERNIGHT  => gwc_vt_shift_meta_value( GWC_VT_SHIFT_OVERNIGHT, $overnight ),
				GWC_VT_SHIFT_ACTIVITY   => $name,
				GWC_VT_SHIFT_SUPERVISOR => $supervisor,
				GWC_VT_SHIFT_LOCATION   => $location,
				GWC_VT_SHIFT_NOTES      => $notes,
				GWC_VT_SHIFT_MIN        => gwc_vt_shift_meta_value( GWC_VT_SHIFT_MIN, $slot['min'] ?? 0 ),
				GWC_VT_SHIFT_MAX        => gwc_vt_shift_meta_value( GWC_VT_SHIFT_MAX, $slot['max'] ?? 0 ),
			);

			if ( $shift_id < 1 ) {
				$shift_id = wp_insert_post(
					array(
						'post_type'   => GWC_VT_SHIFT_TYPE,
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

				gwc_vt_retitle_shift( $shift_id );

				/** This filter is documented in inc/admin-shift.php */
				do_action( 'gwc_vt_shift_created', $shift_id, 0 );
				continue;
			}

			/* An existing time. Its status follows the event's, except that a
			 * cancelled time stays cancelled — bringing one back is a decision,
			 * not something that happens because somebody widened a maximum. */
			$was = gwc_vt_shift_snapshot( $shift_id );

			foreach ( $fields as $key => $value ) {
				update_post_meta( $shift_id, $key, $value );
			}

			if ( ! gwc_vt_shift_is_cancelled( $shift_id ) && get_post_status( $shift_id ) !== $slot_status ) {
				wp_update_post(
					array(
						'ID'          => $shift_id,
						'post_status' => $slot_status,
					)
				);
			}

			gwc_vt_retitle_shift( $shift_id );
			++$tally['updated'];

			/* Only a MOVE mails anybody, and only when asked. Renaming a role,
			 * correcting a supervisor or widening a maximum tells nobody —
			 * mailing thirty people about a spelling fix is how an organization
			 * teaches its volunteers to ignore its email. */
			if ( $context['notify'] && gwc_vt_shift_moved( $shift_id, $was ) && ! gwc_vt_shift_has_ended( $shift_id ) ) {
				foreach ( gwc_vt_shift_signup_ids( $shift_id, array( 'publish', GWC_VT_SIGNUP_WAITLIST ) ) as $signup_id ) {
					gwc_vt_queue_signup_mail( 'changed', (int) $signup_id, array( 'was' => $was ) );
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
function gwc_vt_event_redirect( int $event_id, string $result, array $extra = array() ): void {
	$args = array_merge( array( 'gwc_vt_event_result' => $result ), $extra );

	if ( $event_id > 0 ) {
		$args['gwc_vt_event'] = $event_id;
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

	wp_safe_redirect( gwc_vt_schedule_url( $args ) );
	exit;
}

/* ── Calling it off, deleting it, running it again ───────────────────────── */

/**
 * Call an event off, and every time under it.
 */
function gwc_vt_handle_cancel_event(): void {
	gwc_vt_require_shift_cap( 'publish_posts' );

	$event_id = isset( $_POST['gwc_vt_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_cancel_event_' . $event_id );

	if ( GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwc_vt_event_redirect( 0, 'unknown' );
	}

	$posted = wp_unslash( $_POST );

	$reason = mb_substr( sanitize_text_field( (string) ( $posted['gwc_vt_reason'] ?? '' ) ), 0, GWC_VT_SHIFT_REASON_MAX );
	$notify = ! empty( $posted['gwc_vt_notify'] );
	$told   = 0;

	/* gwc_vt_call_off_slot() rather than the same body written out again — see
	 * its docblock in inc/admin-event-actions.php. This loop avoided the
	 * double-send its sibling on the shift screen had, but only by accident:
	 * gwc_vt_event_slot_ids() filters cancelled slots out by status, so the
	 * guard was never reached rather than never needed. */
	foreach ( gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft' ) ) as $shift_id ) {
		$told += gwc_vt_call_off_slot( (int) $shift_id, $reason, $notify );
	}

	wp_update_post(
		array(
			'ID'          => $event_id,
			'post_status' => GWC_VT_EVENT_CANCELLED,
		)
	);

	update_post_meta( $event_id, GWC_VT_EVENT_REASON, $reason );

	/**
	 * Fires after an event has been called off.
	 *
	 * @param int    $event_id The event.
	 * @param string $reason   Why, as the coordinator typed it.
	 * @param int    $told     How many people were mailed.
	 */
	do_action( 'gwc_vt_event_cancelled', $event_id, $reason, $told );

	gwc_vt_event_redirect( $event_id, 'called-off', array( 'gwc_vt_told' => $told ) );
}

/**
 * Delete an event nobody joined.
 */
function gwc_vt_handle_delete_event(): void {
	gwc_vt_require_shift_cap( 'delete_posts' );

	$event_id = isset( $_POST['gwc_vt_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_delete_event_' . $event_id );

	if ( GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwc_vt_event_redirect( 0, 'unknown' );
	}

	/* Checked here as well as hidden on the screen. The screen is not the only
	 * thing that can post to this, and "it never existed" is only ever true of a
	 * typo — an event people signed up for is called off, not deleted. */
	if ( gwc_vt_event_filled( $event_id ) > 0 ) {
		gwc_vt_event_redirect( $event_id, 'has-roster' );
	}

	foreach ( gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft', GWC_VT_SHIFT_CANCELLED ) ) as $shift_id ) {
		wp_delete_post( $shift_id, true );
	}

	wp_delete_post( $event_id, true );

	gwc_vt_event_redirect( 0, 'deleted' );
}

/** No single run may make more copies of one event than this. */
const GWC_VT_EVENT_REPEAT_MAX = 24;

/**
 * Copy one event onto a date, whole.
 *
 * Every slot moves by the same number of days, so a two-day event stays two days
 * and the gap between a set-up time and the shift it prepares for survives.
 *
 * The copy is a DRAFT. An event that went live because somebody clicked Copy is
 * a public page nobody has read.
 *
 * ── What it carries, and the one thing it used to drop ───────────────────────
 * The event's own three fields, every slot that has not been called off, and —
 * since this — the credentials. Both kinds: an event's own, which every role
 * under it inherits, and each slot's own. They are one meta row each, written
 * only by gwc_vt_set_shift_credentials(), so a loop over gwc_vt_shift_meta_keys()
 * walks straight past them: a copied festival silently stopped asking for the
 * food handler card, and repeating one would have done it twelve times.
 *
 * @param int $event_id The event to copy.
 * @param int $offset   How many days later the copy runs. May be negative.
 * @param int $series   The run this copy belongs to, or 0 for a lone copy.
 * @return int The new event's ID, or 0.
 */
function gwc_vt_duplicate_event( int $event_id, int $offset, int $series = 0 ): int {
	$copy_id = wp_insert_post(
		array(
			'post_type'   => GWC_VT_EVENT_TYPE,
			'post_status' => 'draft',
			'post_title'  => (string) get_the_title( $event_id ),
		)
	);

	if ( is_wp_error( $copy_id ) || ! $copy_id ) {
		return 0;
	}

	$copy_id = (int) $copy_id;

	foreach ( array( GWC_VT_EVENT_DESCRIPTION, GWC_VT_EVENT_LOCATION, GWC_VT_EVENT_SUPERVISOR ) as $key ) {
		update_post_meta( $copy_id, $key, (string) get_post_meta( $event_id, $key, true ) );
	}

	/* What the whole day asks for, which every role under it is added to. */
	gwc_vt_set_shift_credentials( $copy_id, gwc_vt_shift_credential_ids( $event_id ) );

	if ( $series > 0 ) {
		update_post_meta( $copy_id, GWC_VT_EVENT_SERIES, $series );
	}

	/* Cancelled times are not copied. They are a record of what happened to THIS
	 * event, and carrying them into the next one would schedule a Sunday that
	 * was called off last time and has never been discussed since. */
	foreach ( gwc_vt_event_slot_ids( $event_id, array( 'publish', 'draft' ) ) as $shift_id ) {
		$date = gwc_vt_recurrence_date( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true ) );

		if ( null === $date ) {
			continue;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => GWC_VT_SHIFT_TYPE,
				'post_parent' => $copy_id,
				'post_status' => 'draft',
				'post_title'  => 'tmp',
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			continue;
		}

		$new_id = (int) $new_id;

		update_post_meta( $new_id, GWC_VT_SHIFT_DATE, $date->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' days' )->format( 'Y-m-d' ) );

		/* The shared field set and the shared normaliser, so a copied slot is
		 * stored in the same shape a saved one is. This loop was a bare key list
		 * casting everything to (string), which gave a copied event's capacities
		 * "4" where every other write path stores 4. GWC_VT_SHIFT_DATE is above
		 * rather than in the loop because a copy is offset onto new dates. */
		foreach ( gwc_vt_shift_meta_keys() as $key ) {
			if ( GWC_VT_SHIFT_DATE === $key ) {
				continue;
			}

			update_post_meta( $new_id, $key, gwc_vt_shift_meta_value( $key, get_post_meta( $shift_id, $key, true ) ) );
		}

		/* Inside the loop, for the reason gwc_vt_handle_save_shift() gives about
		 * a series: written once afterwards, one role would ask for the waiver
		 * and the other five for nothing. */
		gwc_vt_set_shift_credentials( $new_id, gwc_vt_shift_credential_ids( $shift_id ) );

		gwc_vt_retitle_shift( $new_id );

		/** This action is documented in inc/admin-shift.php */
		do_action( 'gwc_vt_shift_created', $new_id, 0 );
	}

	gwc_vt_event_refresh_dates( $copy_id );

	/**
	 * Fires after an event has been copied to a new date.
	 *
	 * @param int $copy_id  The new draft event.
	 * @param int $event_id The event it was copied from.
	 */
	do_action( 'gwc_vt_event_created', $copy_id, $event_id );

	return $copy_id;
}

/**
 * Run the same event again — once, or on a pattern.
 *
 * ── Why an event repeats at all ──────────────────────────────────────────────
 * A monthly meal service was twelve events typed in twelve times, because the
 * repeat lived on the shift and an event is not a shift. The data model always
 * allowed it — inc/event-cpt.php says a series and a container "compose" — and
 * nothing in the interface let anybody do it, which is a claim about the schema
 * rather than about the product.
 *
 * Materialised, like everything else here: each run is a real event with its own
 * roles, roster and cancellation, and calling off October leaves November alone.
 *
 * ── Why the copies are drafts and empty ──────────────────────────────────────
 * Unchanged from the single copy, and more so at twelve: nobody is carried over,
 * and nothing goes live until somebody has read it.
 *
 * The cap is this file's own rather than gwc_vt_recurrence_dates()'s 200,
 * because each copy is a whole grid — a festival with twenty times repeated
 * weekly for a year is four thousand posts from one button.
 */
function gwc_vt_handle_copy_event(): void {
	gwc_vt_require_shift_cap();

	$event_id = isset( $_POST['gwc_vt_event'] ) ? absint( wp_unslash( $_POST['gwc_vt_event'] ) ) : 0;

	check_admin_referer( 'gwc_vt_copy_event_' . $event_id );

	if ( GWC_VT_EVENT_TYPE !== get_post_type( $event_id ) ) {
		gwc_vt_event_redirect( 0, 'unknown' );
	}

	$posted = wp_unslash( $_POST );

	$wanted  = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_copy_date'] ?? '' ) ) );
	$pattern = sanitize_key( (string) ( $posted['gwc_vt_repeat'] ?? 'once' ) );
	$until   = gwc_vt_sanitize_date( sanitize_text_field( (string) ( $posted['gwc_vt_until'] ?? '' ) ) );
	$first   = (string) get_post_meta( $event_id, GWC_VT_EVENT_DATE, true );

	$to   = gwc_vt_recurrence_date( $wanted );
	$from = gwc_vt_recurrence_date( $first );

	if ( null === $to || null === $from ) {
		gwc_vt_event_redirect( $event_id, 'bad-date' );
	}

	if ( ! isset( gwc_vt_recurrence_patterns()[ $pattern ] ) ) {
		$pattern = 'once';
	}

	/* One copy, or a run of them from the date somebody named. The dates come
	 * from the same function the shift editor uses, so "every other week" means
	 * the same thing on both screens. */
	$dates = array( $wanted );

	if ( 'once' !== $pattern ) {
		$occurrences = gwc_vt_recurrence_dates( $wanted, $pattern, $until );
		$dates       = $occurrences['dates'];
	}

	if ( ! $dates ) {
		gwc_vt_event_redirect( $event_id, 'no-dates' );
	}

	$capped = count( $dates ) > GWC_VT_EVENT_REPEAT_MAX;
	$dates  = array_slice( $dates, 0, GWC_VT_EVENT_REPEAT_MAX );

	/* The event this was run from joins the run rather than standing outside it:
	 * "one of twelve" is true of the first one too, and a row that said it of
	 * eleven and not of the twelfth would be describing the copying rather than
	 * the arrangement. An event that already belongs to a run keeps its own. */
	$series = (int) get_post_meta( $event_id, GWC_VT_EVENT_SERIES, true );

	if ( 'once' !== $pattern && $series < 1 ) {
		$series = $event_id;
		update_post_meta( $event_id, GWC_VT_EVENT_SERIES, $series );
	}

	$made    = 0;
	$last_id = 0;

	foreach ( $dates as $date ) {
		$moment = gwc_vt_recurrence_date( $date );

		if ( null === $moment ) {
			continue;
		}

		$copy_id = gwc_vt_duplicate_event(
			$event_id,
			(int) $from->diff( $moment )->format( '%r%a' ),
			'once' === $pattern ? 0 : $series
		);

		if ( $copy_id < 1 ) {
			continue;
		}

		$last_id = $copy_id;
		++$made;
	}

	if ( 0 === $made ) {
		gwc_vt_event_redirect( $event_id, 'failed' );
	}

	if ( 'once' === $pattern ) {
		gwc_vt_event_redirect( $last_id, 'copied' );
	}

	/* Back to the event it was run from, not to the last copy: twelve drafts
	 * were just made and the twelfth is no more the answer than the third. */
	gwc_vt_event_redirect(
		$event_id,
		$capped ? 'repeated-capped' : 'repeated',
		array( 'gwc_vt_made' => $made )
	);
}

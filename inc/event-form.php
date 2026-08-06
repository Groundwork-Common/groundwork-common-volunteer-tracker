<?php
/**
 * What a visitor sees on an event: the grid, and the one form under it.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* Both fire for any page write, and both only bump a number — see the box
 * comment above gwcvt_event_page_generation(). */
add_action( 'save_post', 'gwcvt_maybe_flush_event_page_cache', 10, 2 );
add_action( 'deleted_post', 'gwcvt_maybe_flush_event_page_cache', 10, 2 );

/* ── Counts, never names. Still. ─────────────────────────────────────────────
 * Everything in the box comment at the top of inc/signup-form.php applies here
 * unchanged, and this is where it is most tempting to break. The products this
 * grid resembles publish their rosters by design — that is half of what they are
 * for. On a site running a court-ordered service programme, the roster for
 * Saturday is a list of people working one off.
 *
 * A place count says nothing about anybody. A first name says everything about
 * one person. There is no setting for this and there must not be one.
 *
 * ── Checkboxes, not radios, and still one form and one nonce ────────────────
 * The whole structural difference between this and the shift list. A person can
 * take several slots in one submission, so the handler loops — and every rule
 * about what it may say back is in inc/signup-handler.php, because that is where
 * saying the wrong thing would be a disclosure.
 *
 * Field names carry the shift ID: gwcvt_slots[9001]. Never a positional array —
 * an unticked checkbox posts nothing at all, so a positional one arrives with
 * its indexes closed up.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * One event's public page: the grid, or a booking to manage.
 *
 * @param int $event_id Event post ID.
 * @return string Empty when signing up is switched off or the event is not
 *                open, so a page that still has the block on it renders nothing
 *                rather than something broken.
 */
function gwcvt_render_event_grid( int $event_id ): string {
	if ( ! gwcvt_signups_open() ) {
		return '';
	}

	$manage = gwcvt_render_signup_manage();

	if ( '' !== $manage ) {
		return $manage;
	}

	if ( GWCVT_EVENT_TYPE !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
		return '';
	}

	$result = (string) ( $GLOBALS['gwcvt_signup_result'] ?? '' );
	$picked = array_map( 'intval', (array) ( $GLOBALS['gwcvt_signup_picked'] ?? array() ) );
	$clash  = array_map( 'intval', (array) ( $GLOBALS['gwcvt_signup_clash'] ?? array() ) );
	$roles  = gwcvt_event_visible_roles( $event_id );
	$code   = (string) gwcvt_setting( 'signup_code' );

	$description = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_DESCRIPTION, true ) );
	$where       = trim( (string) get_post_meta( $event_id, GWCVT_EVENT_LOCATION, true ) );

	ob_start();
	?>
	<div class="gwcvt-shifts gwcvt-event">
		<h2 class="gwcvt-event__name"><?php echo esc_html( gwcvt_event_name( $event_id ) ); ?></h2>

		<p class="gwcvt-event__when">
			<?php echo esc_html( gwcvt_event_date_label( $event_id ) ); ?>
			<?php if ( '' !== $where ) : ?>
				· <?php echo esc_html( $where ); ?>
			<?php endif; ?>
		</p>

		<?php if ( '' !== $description ) : ?>
			<div class="gwcvt-event__about"><?php echo wp_kses_post( wpautop( esc_html( $description ) ) ); ?></div>
		<?php endif; ?>

		<?php if ( '' !== $result ) : ?>
			<p class="gwcvt-shifts__message" role="status"><?php echo esc_html( gwcvt_signup_message( $result ) ); ?></p>
		<?php endif; ?>

		<?php if ( 'clash' === $result && 2 === count( $clash ) ) : ?>
			<p class="gwcvt-shifts__message gwcvt-shifts__message--clash">
				<?php
				printf(
					/* translators: 1: one slot, 2: another slot. */
					esc_html__( 'You have picked %1$s and %2$s, and they overlap.', 'groundwork-common-volunteer-tracker' ),
					'<strong>' . esc_html( gwcvt_slot_label( $clash[0] ) ) . '</strong>',
					'<strong>' . esc_html( gwcvt_slot_label( $clash[1] ) ) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( ! $roles ) : ?>
			<p class="gwcvt-shifts__empty">
				<?php esc_html_e( 'There is nothing to sign up for on this one just now. Please check back, or get in touch.', 'groundwork-common-volunteer-tracker' ); ?>
			</p>
			</div>
			<?php
			return (string) ob_get_clean();
		endif;
		?>

		<form method="post" class="gwcvt-shifts__form">
			<?php wp_nonce_field( 'gwcvt_signup', 'gwcvt_signup_nonce' ); ?>
			<input type="hidden" name="gwcvt_t" value="<?php echo esc_attr( gwcvt_form_stamp() ); ?>" />
			<input type="hidden" name="gwcvt_event" value="<?php echo esc_attr( (string) $event_id ); ?>" />

			<p class="gwcvt-event__ask"><?php esc_html_e( 'Choose the times you can help.', 'groundwork-common-volunteer-tracker' ); ?></p>
			<p class="gwcvt-shifts__help"><?php esc_html_e( 'You can pick more than one.', 'groundwork-common-volunteer-tracker' ); ?></p>

			<?php foreach ( $roles as $role => $slot_ids ) : ?>
				<fieldset class="gwcvt-shifts__list">
					<legend><?php echo esc_html( (string) $role ); ?></legend>

					<?php foreach ( $slot_ids as $shift_id ) : ?>
						<?php gwcvt_render_event_slot_choice( (int) $shift_id, $picked, $clash ); ?>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>

			<?php if ( 'clash' === $result ) : ?>
				<div class="gwcvt-shifts__field gwcvt-shifts__confirm">
					<label>
						<input type="checkbox" name="gwcvt_clash_ok" value="1" />
						<?php esc_html_e( 'I know — I am doing both, and I will move between them.', 'groundwork-common-volunteer-tracker' ); ?>
					</label>
				</div>
			<?php endif; ?>

			<div class="gwcvt-shifts__field">
				<label for="gwcvt-signup-name"><?php esc_html_e( 'Your name', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-signup-name" name="gwcvt_name" required maxlength="100" autocomplete="name" value="<?php echo esc_attr( (string) ( $GLOBALS['gwcvt_signup_name'] ?? '' ) ); ?>" />
			</div>

			<div class="gwcvt-shifts__field">
				<label for="gwcvt-signup-email"><?php esc_html_e( 'Your email address', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="email" id="gwcvt-signup-email" name="gwcvt_email" required maxlength="200" autocomplete="email" value="<?php echo esc_attr( (string) ( $GLOBALS['gwcvt_signup_email'] ?? '' ) ); ?>" />
				<p class="gwcvt-shifts__help">
					<?php esc_html_e( 'So we can send you the details and a link to cancel if you need to. It does not create an account.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			</div>

			<?php if ( '' !== $code ) : ?>
				<div class="gwcvt-shifts__field">
					<label for="gwcvt-signup-code"><?php esc_html_e( 'The code you were given', 'groundwork-common-volunteer-tracker' ); ?></label>
					<input type="text" id="gwcvt-signup-code" name="gwcvt_code" maxlength="50" autocomplete="off" />
				</div>
			<?php endif; ?>

			<?php
			/* The honeypot. A real text input in an off-screen wrapper rather than
			 * type="hidden" or an inline display:none, both of which the scripts
			 * worth stopping already skip. */
			?>
			<div class="gwcvt-shifts__hp" aria-hidden="true">
				<label for="gwcvt-signup-website"><?php esc_html_e( 'Leave this field empty', 'groundwork-common-volunteer-tracker' ); ?></label>
				<input type="text" id="gwcvt-signup-website" name="gwcvt_website" tabindex="-1" autocomplete="off" />
			</div>

			<p>
				<button type="submit" name="gwcvt_event_submit" value="1" class="gwcvt-shifts__button">
					<?php esc_html_e( 'Sign me up', 'groundwork-common-volunteer-tracker' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * One slot, as something a visitor can tick.
 *
 * @param int   $shift_id Shift post ID.
 * @param int[] $picked   Slots the visitor had already ticked, for a re-render.
 * @param int[] $clash    The two slots that clash, if any.
 */
function gwcvt_render_event_slot_choice( int $shift_id, array $picked, array $clash = array() ): void {
	$spots    = gwcvt_shift_spots_left( $shift_id );
	$full     = null !== $spots && $spots < 1;
	$notes    = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_NOTES, true ) );
	$where    = trim( (string) get_post_meta( $shift_id, GWCVT_SHIFT_LOCATION, true ) );
	$row_id   = 'gwcvt-slot-' . $shift_id;
	$clashing = in_array( $shift_id, $clash, true );

	$classes = array( 'gwcvt-shift' );

	if ( $full ) {
		$classes[] = 'gwcvt-shift--full';
	}

	if ( $clashing ) {
		$classes[] = 'gwcvt-shift--clash';
	}
	?>
	<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<input
			type="checkbox"
			id="<?php echo esc_attr( $row_id ); ?>"
			name="gwcvt_slots[<?php echo esc_attr( (string) $shift_id ); ?>]"
			value="1"
			<?php checked( in_array( $shift_id, $picked, true ) ); ?>
		/>
		<label for="<?php echo esc_attr( $row_id ); ?>">
			<span class="gwcvt-shift__when"><?php echo esc_html( gwcvt_shift_time_label( $shift_id ) ); ?></span>

			<?php if ( gwcvt_event_is_multi_day( gwcvt_event_for_shift( $shift_id ) ) ) : ?>
				<span class="gwcvt-shift__day"><?php echo esc_html( gwcvt_shift_date_label( $shift_id ) ); ?></span>
			<?php endif; ?>

			<?php if ( '' !== $where ) : ?>
				<span class="gwcvt-shift__where"><?php echo esc_html( $where ); ?></span>
			<?php endif; ?>

			<span class="gwcvt-shift__places">
				<?php
				/* A count, and never a name. */
				if ( $full ) {
					esc_html_e( 'Full — you can join the waiting list', 'groundwork-common-volunteer-tracker' );
				} elseif ( null !== $spots ) {
					printf(
						/* translators: %d: how many places are left. */
						esc_html( _n( '%d place left', '%d places left', $spots, 'groundwork-common-volunteer-tracker' ) ),
						(int) $spots
					);
				} else {
					esc_html_e( 'Places available', 'groundwork-common-volunteer-tracker' );
				}
				?>
			</span>

			<?php if ( '' !== $notes ) : ?>
				<span class="gwcvt-shift__notes"><?php echo esc_html( $notes ); ?></span>
			<?php endif; ?>
		</label>
	</div>
	<?php
}

/**
 * The roles on an event that a visitor may see, each in time order.
 *
 * @param int $event_id Event post ID.
 * @return array<string, int[]>
 */
function gwcvt_event_visible_roles( int $event_id ): array {
	$roles = array();

	foreach ( gwcvt_event_roles( $event_id ) as $role => $slot_ids ) {
		$visible = array();

		foreach ( $slot_ids as $shift_id ) {
			if ( gwcvt_shift_is_signup_visible( (int) $shift_id ) ) {
				$visible[] = (int) $shift_id;
			}
		}

		if ( $visible ) {
			$roles[ $role ] = $visible;
		}
	}

	/**
	 * The slots shown on an event's public grid, keyed by role.
	 *
	 * Anything removed here is also refused by the handler, because both read
	 * gwcvt_shift_is_signup_visible().
	 *
	 * @param array<string, int[]> $roles    Role name => shift post IDs.
	 * @param int                  $event_id The event.
	 */
	return (array) apply_filters( 'gwcvt_event_visible_slots', $roles, $event_id );
}

/**
 * Where somebody managing one slot can go to see the rest.
 *
 * An event slot returns the page its grid is on; a standalone shift returns the
 * pinned schedule page. Empty when there is nowhere to send them, and every
 * caller checks — a link to the front page is worse than no link.
 *
 * ── Why an event's page has to be searched for ───────────────────────────────
 * An event is not publicly queryable and has no permalink, on purpose: a
 * permalink would publish a location and the shape of an organisation's calendar
 * to anybody who asked. So the grid lives wherever the site put the block, and
 * finding that page means asking which page contains it.
 *
 * The answer is cached for an hour rather than computed per request. It changes
 * when somebody edits a page, which is rarely, and the alternative is a
 * post-content search on a path a volunteer reaches from an email.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwcvt_signup_return_url( int $signup_id ): string {
	$shift_id = (int) get_post_field( 'post_parent', $signup_id );
	$event_id = gwcvt_event_for_shift( $shift_id );

	if ( $event_id < 1 ) {
		$page = (int) gwcvt_setting( 'schedule_page' );

		return $page > 0 ? (string) get_permalink( $page ) : '';
	}

	$page = gwcvt_event_page_id( $event_id );

	return $page > 0 ? (string) get_permalink( $page ) : '';
}

/* ── Finding the page an event is on ─────────────────────────────────────────
 * An event has no URL of its own — the post type is public => false, and the
 * grid is placed on an ordinary page by the block or the shortcode. So the only
 * way to answer "where do volunteers see this" is to go looking for the
 * placement, which is what this does.
 *
 * Two markers, because there are two ways to place one and they look nothing
 * alike in post_content:
 *
 *   [volunteer_event id="12"]
 *   <!-- wp:groundwork-common-volunteer-tracker/event-grid {"eventId":12} /-->
 *
 * Both are searched for, and both are then matched on the ID with a real
 * pattern rather than a substring test. A bare strpos() for "12" is true of a
 * page holding event 1, event 2, event 120, or the year 2012 in the prose above
 * the block — which meant a cancellation link could point at the wrong
 * occasion's page.
 *
 * The result is cached, and the key carries a generation number that any page
 * edit bumps. Without that, the sequence somebody actually performs — build the
 * event, discover no page shows it, make the page — left the answer wrong for
 * up to an hour, which is exactly the hour they are testing it in.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The current generation of the event-page lookup cache.
 *
 * @return int
 */
function gwcvt_event_page_generation(): int {
	return max( 1, (int) get_option( 'gwcvt_event_page_gen', 1 ) );
}

/**
 * Invalidate every cached event-page answer.
 *
 * Bumping one number rather than deleting N transients, because the placement
 * that just changed may be the one being REMOVED — in which case the event it
 * used to name is not derivable from the content we are now looking at.
 */
function gwcvt_flush_event_page_cache(): void {
	update_option( 'gwcvt_event_page_gen', gwcvt_event_page_generation() + 1, false );
}

/**
 * Bump the generation when a page that could hold a grid is written.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post.
 */
function gwcvt_maybe_flush_event_page_cache( $post_id, $post = null ): void {
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	gwcvt_flush_event_page_cache();
}

/**
 * Does this content place the grid for a particular event?
 *
 * Kept separate so it can be asserted without a database.
 *
 * @param string $content  Post content.
 * @param int    $event_id Event post ID.
 * @return bool
 */
function gwcvt_content_places_event( string $content, int $event_id ): bool {
	if ( $event_id < 1 || '' === $content ) {
		return false;
	}

	/* The shortcode, with the id quoted, single-quoted or bare. \b on the closing
	 * side so id="1" does not answer for event 12. */
	if ( preg_match_all( '/\[volunteer_event\b[^\]]*/', $content, $tags ) ) {
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bid\s*=\s*["\']?(\d+)/', $tag, $m ) && (int) $m[1] === $event_id ) {
				return true;
			}
		}
	}

	// The block's serialised attribute.
	if ( preg_match_all( '/"eventId"\s*:\s*(\d+)/', $content, $ids ) ) {
		foreach ( $ids[1] as $id ) {
			if ( (int) $id === $event_id ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * The page an event's grid is placed on, or 0.
 *
 * @param int $event_id Event post ID.
 * @return int
 */
function gwcvt_event_page_id( int $event_id ): int {
	if ( $event_id < 1 ) {
		return 0;
	}

	$key    = 'gwcvt_event_page_' . gwcvt_event_page_generation() . '_' . $event_id;
	$cached = get_transient( $key );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	$found = 0;

	/* Two searches rather than one. The shortcode contains the literal
	 * "volunteer_event"; the block contains "volunteer-tracker/event-grid" and
	 * no such string, so a single search on the first marker silently never
	 * returned a block-placed page — which is the placement the editor
	 * recommends. */
	$page_ids = array();

	foreach ( array( '[volunteer_event', 'volunteer-tracker/event-grid' ) as $marker ) {
		$page_ids = array_merge(
			$page_ids,
			(array) get_posts(
				array(
					'post_type'              => 'page',
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					's'                      => $marker,
				)
			)
		);
	}

	foreach ( array_unique( array_map( 'intval', $page_ids ) ) as $page_id ) {
		if ( gwcvt_content_places_event( (string) get_post_field( 'post_content', $page_id ), $event_id ) ) {
			$found = (int) $page_id;
			break;
		}
	}

	set_transient( $key, $found, HOUR_IN_SECONDS );

	return $found;
}

<?php
/**
 * The screen somebody lands on.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_dashboard_menu', 9 );
add_action( 'admin_init', 'gwc_vt_handle_dashboard_view_toggle' );

/* ── Week or list, remembered ────────────────────────────────────────────────
 * The strip reads at a glance when an organization runs a handful of shifts a
 * week. It stops working the moment somebody runs six a day: seven columns of
 * stacked chips is a wall. The list holds up under that and is what somebody
 * printing or reading carefully wants anyway.
 *
 * Neither is right for everybody, so it is a choice, and a choice somebody
 * makes once. Stored per user in user meta — one coordinator preferring the
 * list should not decide it for their colleagues — exactly as the colophon's
 * collapse is, and toggled the same way: a nonced link handled server-side,
 * then a redirect that strips the argument back off so a refresh does not
 * re-fire it. Both views are rendered by PHP; there is no script here.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Where the choice is kept. */
const GWC_VT_DASHBOARD_VIEW_META = 'gwc_vt_dashboard_view';

/**
 * Which view the current user last chose.
 *
 * The strip is the default because it is the one that answers "how is next
 * Saturday" without reading anything.
 *
 * @return string 'week' or 'list'.
 */
function gwc_vt_dashboard_view(): string {
	$stored = (string) get_user_meta( get_current_user_id(), GWC_VT_DASHBOARD_VIEW_META, true );

	return 'list' === $stored ? 'list' : 'week';
}

/**
 * Store the choice, then send the browser back where it was.
 */
function gwc_vt_handle_dashboard_view_toggle(): void {
	if ( ! isset( $_GET['gwc_vt_view'] ) ) {
		return;
	}

	if ( ! gwc_vt_can_see_records() ) {
		return;
	}

	check_admin_referer( 'gwc_vt_dashboard_view' );

	$wanted = sanitize_key( wp_unslash( $_GET['gwc_vt_view'] ) );

	if ( 'list' === $wanted ) {
		update_user_meta( get_current_user_id(), GWC_VT_DASHBOARD_VIEW_META, 'list' );
	} else {
		delete_user_meta( get_current_user_id(), GWC_VT_DASHBOARD_VIEW_META );
	}

	wp_safe_redirect( remove_query_arg( array( 'gwc_vt_view', '_wpnonce' ) ) );
	exit;
}

/**
 * The link that switches to a view, nonced.
 *
 * @param string $view 'week' or 'list'.
 * @return string
 */
function gwc_vt_dashboard_view_url( string $view ): string {
	return wp_nonce_url( add_query_arg( 'gwc_vt_view', $view ), 'gwc_vt_dashboard_view' );
}

/* ── The page does two things ────────────────────────────────────────────────
 * Left: what is true — the fortnight ahead, and the year's one figure. Right: a
 * narrower rail of what somebody might do about it.
 *
 * A coordinator arrives either wanting to be told or wanting to do something,
 * and the screen should not make them work out which half they are in. Reading
 * across answers "how are we"; reading down the rail answers "what now".
 *
 * This used to be four panels in two even columns — the worklist widest because
 * it was the urgent part, and an index of every screen in the plugin down the
 * side. The index went when the menu became six nouns and started carrying the
 * same information; what survived it is the verbs, which the menu deliberately
 * does not list. See gwc_vt_dashboard_actions().
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Hang the dashboard off the Volunteer Tracker menu.
 *
 * Priority 9, before every other screen registers, so it is first in the list
 * even before gwc_vt_order_menu() has its say.
 */
function gwc_vt_register_dashboard_menu(): void {
	add_submenu_page(
		GWC_VT_MENU_SLUG,
		__( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ),
		__( 'Dashboard', 'groundwork-common-volunteer-tracker' ),
		gwc_vt_records_cap(),
		GWC_VT_DASHBOARD_PAGE,
		'gwc_vt_render_dashboard'
	);
}

/**
 * The dashboard.
 */
function gwc_vt_render_dashboard(): void {
	if ( ! gwc_vt_can_see_records() ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$items = gwc_vt_dashboard_items( gwc_vt_dashboard_counts() );

	/* ── Information on the left, action on the right ────────────────────────
	 * The screen used to be four panels in two even columns, in no particular
	 * relation to each other. It is now a split: what is true — the fortnight
	 * and the year — on the left, and what somebody might do about it in a
	 * narrower rail on the right.
	 *
	 * The rail reads downwards in the order the work arrives. The verbs first,
	 * because "add a shift" is what somebody arrives wanting on most days.
	 * Then what is waiting, which is the thing that changes. Then the reference
	 * checker, which is not about this organization's week at all — it is here
	 * because a phone call about a letter interrupts whatever you were doing,
	 * and this is the screen you were on.
	 *
	 * The masthead above this — org name, today's date, a 2px rule — is gone.
	 * The date is on every clock in the building and the org name is on the
	 * letterhead settings; between them they were a strip of chrome above the
	 * only two numbers on the page anybody reads.
	 * ─────────────────────────────────────────────────────────────────────── */
	?>
	<div class="wrap gwcvt-wrap gwcvt-dash">

		<h1 class="wp-heading-inline"><?php esc_html_e( 'Groundwork Common Volunteer Tracker', 'groundwork-common-volunteer-tracker' ); ?></h1>
		<hr class="wp-header-end" />

		<div class="gwcvt-split">

			<div class="gwcvt-split__main">
				<?php gwc_vt_render_dashboard_upcoming(); ?>
				<?php gwc_vt_render_dashboard_year(); ?>
			</div>

			<div class="gwcvt-split__rail">
				<?php gwc_vt_render_dashboard_actions(); ?>
				<?php gwc_vt_render_dashboard_worklist( $items ); ?>
				<?php gwc_vt_render_dashboard_reference(); ?>
			</div>

		</div>
	</div>
	<?php
}

/* ── Checking a reference, where the phone gets answered ─────────────────────
 * The checker lives here, and only here. Whoever picks up the phone is not
 * necessarily whoever issues letters, and asking them to find a screen they
 * have never opened — to answer a question that takes ten seconds — is how a
 * caller gets told somebody will ring them back.
 *
 * The capability is unchanged by the move: GWC_VT_CAP_OPEN_LETTERS, which
 * inc/access.php maps to EITHER verifying or issuing, precisely so the front
 * desk can answer without the right to sign. Anybody who could reach the old
 * box can reach this one — both screens hang off a parent menu that needs
 * edit_posts to appear at all.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The dashboard, with a reference already in the checker's box.
 *
 * The issued-letter table on a volunteer's record links here: the usual reason
 * for reading that table is that somebody has phoned about one of the rows, and
 * the checker takes its code from the query string because it is a GET form.
 *
 * @param string $reference The code to prefill.
 * @return string
 */
function gwc_vt_dashboard_reference_url( string $reference ): string {
	return add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_DASHBOARD_PAGE,
			'reference' => $reference,
		),
		admin_url( 'edit.php' )
	);
}

/**
 * The reference checker, in the rail.
 */
function gwc_vt_render_dashboard_reference(): void {
	if ( ! gwc_vt_letters_enabled() || ! current_user_can( GWC_VT_CAP_OPEN_LETTERS ) ) {
		return;
	}

	?>
	<section class="gwcvt-section">
		<?php gwc_vt_render_reference_checker( GWC_VT_DASHBOARD_PAGE ); ?>
	</section>
	<?php
}

/**
 * What is waiting.
 *
 * @param array $items From gwc_vt_dashboard_items().
 */
function gwc_vt_render_dashboard_worklist( array $items ): void {
	/* ── One line per queue ──────────────────────────────────────────────────
	 * The rules are untouched and are the reason this panel is worth having:
	 * every line is a queue, they are ordered by what is lost if they wait,
	 * nobody's name appears, and a queue at zero is not drawn at all. See
	 * gwc_vt_dashboard_items().
	 *
	 * What changed is the width. In a rail there is no room for the sentence
	 * explaining why each queue matters, so it moves into the row's title
	 * attribute — still there for anybody who wants it, no longer competing
	 * with the count for the two seconds somebody spends on this panel.
	 *
	 * A title attribute is a poor place for anything load-bearing, which is why
	 * nothing load-bearing is in it: the count, the task and the destination are
	 * all still on the row.
	 * ─────────────────────────────────────────────────────────────────────── */
	?>
	<section class="gwcvt-section">
		<div class="gwcvt-section__head">
			<h2><?php esc_html_e( 'Needs you', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<div class="gwcvt-card gwcvt-docket">
			<?php if ( ! $items && ! gwc_vt_has_any_records() ) : ?>
				<?php
				/* Nothing waiting because nothing has started. Telling this person
				 * that everything logged has been verified is true of an empty
				 * database and no use to anybody. */
				gwc_vt_render_dashboard_start_here();
				?>
			<?php elseif ( ! $items ) : ?>
				<?php
				/* One line, and no invented figures to fill the space. A screen
				 * that reports "0 waiting" five times over is one people stop
				 * reading — and then the line that says Saturday has three of
				 * eight gets skimmed along with it. */
				?>
				<p class="gwcvt-docket__clear">
					<?php esc_html_e( 'Nothing is waiting. Everything logged has been verified, everything that has happened has been written up, and no shift this week is short of people.', 'groundwork-common-volunteer-tracker' ); ?>
				</p>
			<?php endif; ?>

			<?php foreach ( $items as $item ) : ?>
				<a
					class="gwcvt-docket__row gwcvt-docket__row--<?php echo esc_attr( $item['severity'] ); ?>"
					href="<?php echo esc_url( gwc_vt_dashboard_item_url( (string) $item['key'] ) ); ?>"
					title="<?php echo esc_attr( (string) $item['why'] ); ?>"
				>
					<span class="gwcvt-docket__stripe" aria-hidden="true"></span>
					<span class="gwcvt-docket__count"><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?></span>
					<span class="gwcvt-docket__what"><?php echo esc_html( (string) $item['what'] ); ?></span>
					<span class="gwcvt-docket__go" aria-hidden="true">&rarr;</span>
					<span class="screen-reader-text"><?php echo esc_html( (string) $item['action'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/* ── The first five minutes ──────────────────────────────────────────────────
 * Shown only while the site genuinely has nothing in it, and gone for good the
 * moment it does. Not a wizard and not dismissible: there is nothing to dismiss
 * once a single volunteer exists, and a wizard would be a fifth screen to
 * maintain for a sequence that is four links.
 *
 * Two of the four report whether they are done, because those are the two whose
 * being undone is invisible later — an unset letterhead prints a website's title
 * on a court's letter, and an undecided retention policy is the one thing this
 * plugin will nag about forever.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * What to do first, on a site with nothing in it yet.
 */
function gwc_vt_render_dashboard_start_here(): void {
	$steps = array();

	if ( gwc_vt_letters_enabled() && current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		$steps[] = array(
			'url'  => gwc_vt_settings_url( 'letter' ),
			'what' => __( 'Tell the letter who you are', 'groundwork-common-volunteer-tracker' ),
			'why'  => __( 'Your organization\'s name, the address, and who signs. Left empty, a letter goes out headed with this website\'s title.', 'groundwork-common-volunteer-tracker' ),
			'done' => ! gwc_vt_letterhead_gaps(),
		);
	}

	$steps[] = array(
		'url'  => admin_url( 'post-new.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
		'what' => __( 'Add your first volunteer', 'groundwork-common-volunteer-tracker' ),
		'why'  => __( 'A record of a person, not a WordPress account. Nobody signs in.', 'groundwork-common-volunteer-tracker' ),
		'done' => false,
	);

	$steps[] = array(
		'url'  => admin_url( 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
		'what' => __( 'Log a shift they worked', 'groundwork-common-volunteer-tracker' ),
		'why'  => __( 'One occasion of work. A member of staff then verifies it, and verified hours are what a letter reports.', 'groundwork-common-volunteer-tracker' ),
		'done' => false,
	);

	if ( current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		$steps[] = array(
			'url'  => gwc_vt_settings_url( 'logging' ),
			'what' => __( 'Say whether you issue verification letters', 'groundwork-common-volunteer-tracker' ),
			'why'  => __( 'They are on, which is what most organizations want. If yours records hours but never writes to a court or a school, turning them off takes a whole screen and its settings out of your way.', 'groundwork-common-volunteer-tracker' ),
			'done' => gwc_vt_letters_decided(),
		);

		$steps[] = array(
			'url'  => gwc_vt_settings_url( 'privacy' ),
			'what' => __( 'Decide how long records are kept', 'groundwork-common-volunteer-tracker' ),
			'why'  => __( 'Keeping them indefinitely is a perfectly good answer. Not having decided is not, which is why this one asks until you do.', 'groundwork-common-volunteer-tracker' ),
			'done' => (bool) gwc_vt_setting( 'retention_decided' ),
		);
	}
	?>
	<p class="gwcvt-docket__clear">
		<strong><?php esc_html_e( 'Nothing here yet.', 'groundwork-common-volunteer-tracker' ); ?></strong>
		<?php esc_html_e( 'This screen fills up on its own once hours are being logged. Until then, here is the order most organizations start in.', 'groundwork-common-volunteer-tracker' ); ?>
	</p>

	<?php
	/* The same compressed row the worklist uses, for the same reason: this
	 * panel is in a rail now, and the explaining sentence goes in the title
	 * attribute rather than on a second line. */
	?>
	<?php foreach ( $steps as $step ) : ?>
		<a
			class="gwcvt-docket__row gwcvt-docket__row--<?php echo $step['done'] ? 'done' : 'waiting'; ?>"
			href="<?php echo esc_url( (string) $step['url'] ); ?>"
			title="<?php echo esc_attr( (string) $step['why'] ); ?>"
		>
			<span class="gwcvt-docket__stripe" aria-hidden="true"></span>
			<span class="gwcvt-docket__count" aria-hidden="true"><?php echo $step['done'] ? '&#10003;' : '&middot;'; ?></span>
			<span class="gwcvt-docket__what"><?php echo esc_html( (string) $step['what'] ); ?></span>
			<span class="gwcvt-docket__go" aria-hidden="true">&rarr;</span>
			<span class="screen-reader-text">
				<?php
				echo $step['done']
					? esc_html__( 'Done', 'groundwork-common-volunteer-tracker' )
					: esc_html__( 'Start', 'groundwork-common-volunteer-tracker' );
				?>
			</span>
		</a>
	<?php endforeach; ?>
	<?php
}

/**
 * The next fortnight, split at the end of this week.
 */
function gwc_vt_render_dashboard_upcoming(): void {
	if ( ! gwc_vt_shifts_enabled() ) {
		return;
	}

	$today  = gwc_vt_today();
	$bounds = gwc_vt_fortnight_bounds( $today, (int) get_option( 'start_of_week' ) );

	$shifts = gwc_vt_shifts_between(
		array(
			'from'  => $today,
			'to'    => $bounds['fortnight'],
			'limit' => 60,
		)
	);

	if ( ! $shifts ) {
		return;
	}

	$weeks = array(
		'this' => array(),
		'next' => array(),
	);

	foreach ( $shifts as $shift_id ) {
		$date = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );

		$weeks[ $date <= $bounds['this_week'] ? 'this' : 'next' ][] = (int) $shift_id;
	}

	$titles = gwc_vt_fortnight_titles();
	$view   = gwc_vt_dashboard_view();
	?>
	<section class="gwcvt-section">
		<div class="gwcvt-section__head">
			<h2><?php esc_html_e( 'Coming up', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<?php gwc_vt_render_dashboard_view_toggle( $view ); ?>
		</div>

		<?php if ( 'list' === $view ) : ?>
			<div class="gwcvt-card">
				<?php foreach ( array_filter( $weeks ) as $when => $ids ) : ?>
					<?php /* array_filter drops an empty half rather than heading it and leaving it blank: a heading over nothing reads as a fault in the screen. */ ?>
					<h3 class="gwcvt-shiftweek"><?php echo esc_html( $titles[ $when ] ); ?></h3>

					<?php foreach ( $ids as $shift_id ) : ?>
						<?php gwc_vt_render_dashboard_shiftline( (int) $shift_id ); ?>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<?php gwc_vt_render_dashboard_week_strip( $shifts, $today ); ?>
		<?php endif; ?>

		<?php
		/* Outside the panel, not in it. Inside, it sat under the last line of
		 * next week and read as belonging to next week. It is the way out of
		 * the whole fortnight. */
		?>
		<p class="gwcvt-dash__more">
			<a href="<?php echo esc_url( gwc_vt_schedule_url() ); ?>"><?php esc_html_e( 'Open the full calendar', 'groundwork-common-volunteer-tracker' ); ?> &rarr;</a>
		</p>
	</section>
	<?php
}

/**
 * The two halves of the fortnight, named.
 *
 * @return array<string, string>
 */
function gwc_vt_fortnight_titles(): array {
	return array(
		'this' => __( 'This week', 'groundwork-common-volunteer-tracker' ),
		'next' => __( 'Next week', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * Week | List, as two links.
 *
 * @param string $view Which one is showing.
 */
function gwc_vt_render_dashboard_view_toggle( string $view ): void {
	$options = array(
		'week' => __( 'Week', 'groundwork-common-volunteer-tracker' ),
		'list' => __( 'List', 'groundwork-common-volunteer-tracker' ),
	);
	?>
	<span class="gwcvt-segmented">
		<?php foreach ( $options as $key => $label ) : ?>
			<?php if ( $key === $view ) : ?>
				<span class="gwcvt-segmented__on" aria-current="true"><?php echo esc_html( $label ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( gwc_vt_dashboard_view_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
	</span>
	<?php
}

/* ── The fortnight as a calendar ─────────────────────────────────────────────
 * Two rows of seven, drawn in the language the month view uses: one chip per
 * shift, coloured by gwc_vt_shift_state(), carrying its fill in words.
 *
 * The chips are the whole point. A list answers "what is on" one row at a time;
 * a strip answers "which day is in trouble" without reading anything, because
 * the day that is in trouble is the red one. The words are on every chip
 * regardless — the colour is reinforcement, and never the only signal.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The week strip.
 *
 * @param int[]  $shifts Shift post IDs in the fortnight, soonest first.
 * @param string $today  Y-m-d.
 */
function gwc_vt_render_dashboard_week_strip( array $shifts, string $today ): void {
	$by_day = array();

	foreach ( $shifts as $shift_id ) {
		$date = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_DATE, true );

		if ( '' !== $date ) {
			$by_day[ $date ][] = (int) $shift_id;
		}
	}

	$weeks  = gwc_vt_fortnight_grid( $today, (int) get_option( 'start_of_week' ) );
	$titles = gwc_vt_fortnight_titles();
	?>
	<div class="gwcvt-card gwcvt-strip">
		<?php foreach ( $weeks as $week ) : ?>
			<h3 class="gwcvt-shiftweek"><?php echo esc_html( (string) ( $titles[ $week['title_key'] ] ?? '' ) ); ?></h3>

			<div class="gwcvt-strip__week">
				<?php foreach ( $week['days'] as $day ) : ?>
					<?php
					$date  = (string) $day['date'];
					$stamp = (int) strtotime( $date . ' 00:00:00 UTC' );

					$classes = array( 'gwcvt-strip__day' );

					if ( $day['past'] ) {
						$classes[] = 'gwcvt-strip__day--past';
					}
					?>
					<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<span class="gwcvt-strip__label<?php echo $day['today'] ? ' gwcvt-strip__label--today' : ''; ?>">
							<?php
							/* The weekday, then the day of the month. Not the
							 * site's date format: that is written for a date on
							 * its own and would print the month and the year in
							 * every one of fourteen cells. */
							echo esc_html( (string) wp_date( 'D j', $stamp, new DateTimeZone( 'UTC' ) ) );
							?>
							<?php if ( $day['today'] ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( '(today)', 'groundwork-common-volunteer-tracker' ); ?></span>
							<?php endif; ?>
						</span>

						<?php foreach ( (array) ( $by_day[ $date ] ?? array() ) as $shift_id ) : ?>
							<?php gwc_vt_render_dashboard_chip( (int) $shift_id ); ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * One shift, as a chip in a day cell.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_dashboard_chip( int $shift_id ): void {
	$state    = gwc_vt_shift_state( $shift_id );
	$activity = (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true );
	$activity = '' !== $activity ? $activity : __( 'Untitled shift', 'groundwork-common-volunteer-tracker' );
	?>
	<a
		class="gwcvt-chip gwcvt-chip--<?php echo esc_attr( $state ); ?>"
		href="<?php echo esc_url( gwc_vt_schedule_url( array( 'shift' => $shift_id ) ) ); ?>"
		title="<?php echo esc_attr( $activity ); ?>"
	>
		<?php
		/* The full name in the title attribute, because line one is one line and
		 * ellipsises: a chip that wrapped would set the height of every cell in
		 * its week. */
		?>
		<span class="gwcvt-chip__what"><?php echo esc_html( $activity ); ?></span>
		<span class="gwcvt-chip__fill"><?php echo esc_html( gwc_vt_shift_fill_summary( $shift_id, $state ) ); ?></span>
	</a>
	<?php
}

/**
 * One shift, as a line.
 *
 * @param int $shift_id Shift post ID.
 */
function gwc_vt_render_dashboard_shiftline( int $shift_id ): void {
	$max    = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MAX, true );
	$filled = gwc_vt_shift_filled( $shift_id );

	/* The decision comes from gwc_vt_shift_state_from(), so this line and the
	 * schedule row cannot disagree about what red means — see the block comment
	 * above gwc_vt_shift_state() in inc/shifts.php. Facts passed in rather than
	 * asking by ID for the reason the schedule row does it: $filled is already
	 * in hand here, and gwc_vt_shift_filled() is a query.
	 *
	 * 'logged' joins 'full' on the green meter: both mean there is nothing left
	 * to do about this shift's roster. */
	$state = gwc_vt_shift_state_from(
		array(
			'cancelled'    => gwc_vt_shift_is_cancelled( $shift_id ),
			'ended'        => gwc_vt_shift_has_ended( $shift_id ),
			'reconciled'   => gwc_vt_shift_is_reconciled( $shift_id ),
			'understaffed' => gwc_vt_shift_is_understaffed( $shift_id ),
			'filled'       => $filled,
			'max'          => $max,
		)
	);

	$short = 'short' === $state;
	$full  = in_array( $state, array( 'full', 'logged' ), true );

	/* A hair of width even at zero, so an empty shift still reads as a meter
	 * rather than as a missing element. */
	$width = $max > 0 ? max( 2, (int) round( ( $filled / $max ) * 100 ) ) : 2;

	$class  = $short ? ' gwcvt-shiftline--short' : ( $full ? ' gwcvt-shiftline--full' : '' );
	$starts = gwc_vt_shift_starts( $shift_id );
	$where  = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_LOCATION, true ) );
	?>
	<div class="gwcvt-shiftline<?php echo esc_attr( $class ); ?>">
		<span class="gwcvt-shiftline__when">
			<span class="gwcvt-shiftline__date">
				<?php if ( null !== $starts ) : ?>
					<?php
					/* The weekday, ahead of the date the site formats. In a list
					 * this short "Saturday" is what people navigate by — nobody
					 * plans a rota by the fourteenth. */
					?>
					<span class="gwcvt-shiftline__day"><?php echo esc_html( (string) wp_date( 'D', $starts->getTimestamp() ) ); ?></span>
				<?php endif; ?>
				<?php echo esc_html( gwc_vt_shift_date_label( $shift_id ) ); ?>
			</span>
			<span class="gwcvt-shiftline__time"><?php echo esc_html( gwc_vt_shift_time_label( $shift_id ) ); ?></span>
		</span>
		<span class="gwcvt-shiftline__what">
			<a href="<?php echo esc_url( gwc_vt_schedule_url( array( 'shift' => $shift_id ) ) ); ?>">
				<?php echo esc_html( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) ); ?>
			</a>
			<?php if ( '' !== $where ) : ?>
				<span class="gwcvt-shiftline__where"><?php echo esc_html( $where ); ?></span>
			<?php endif; ?>
		</span>
		<span class="gwcvt-shiftline__fill">
			<span class="gwcvt-meter"><span class="gwcvt-meter__bar" style="width: <?php echo esc_attr( (string) $width ); ?>%"></span></span>
			<?php
			/* "3 of 8 · needs 6". The number is not enough on its own — it does
			 * not say whether three is a problem — and colour does not say it
			 * either, for anybody who cannot see it. Built by
			 * gwc_vt_shift_fill_summary() rather than here, because the week
			 * strip's chips print the same sentence and this line and those
			 * chips are the same fortnight seen twice. */
			?>
			<span class="gwcvt-shiftline__count"><?php echo esc_html( gwc_vt_shift_fill_summary( $shift_id, $state ) ); ?></span>
		</span>
	</div>
	<?php
}

/**
 * The one figure that leaves the building.
 */
function gwc_vt_render_dashboard_year(): void {
	$from   = gwc_vt_reporting_year_start();
	$to     = gwc_vt_today();
	$totals = gwc_vt_org_totals( $from, $to );
	?>
	<section>
		<div class="gwcvt-section__head">
			<h2><?php esc_html_e( 'This year', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<div class="gwcvt-card gwcvt-year">
			<p class="gwcvt-year__figure">
				<?php echo esc_html( gwc_vt_format_hours( (int) $totals['verified'] ) ); ?>
				<span class="gwcvt-year__unit"><?php esc_html_e( 'verified hours', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>

			<dl class="gwcvt-year__rows">
				<div><dt><?php esc_html_e( 'Awaiting verification', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( gwc_vt_format_hours( (int) $totals['pending'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Volunteers with hours', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $totals['volunteers'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Shifts recorded', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $totals['entries'] ) ); ?></dd></div>
			</dl>
		</div>
	</section>
	<?php
}

/* ── The verbs ───────────────────────────────────────────────────────────────
 * What is left of "Where to next", which used to be five groups of links to
 * every screen in the plugin. With a six-noun menu in the sidebar, most of that
 * was the sidebar written out again in the middle of the page.
 *
 * What the sidebar does NOT carry is the verbs: adding a shift, adding an
 * event, adding a volunteer. Those left the menu in #89 precisely because they
 * are actions rather than places, and this is where they went — a plain list,
 * no headings, no explanatory sentences, in the order somebody reaches for
 * them.
 *
 * Filtered to what the person looking at it can actually reach, by the same
 * rules the map used. A link that fails when clicked is worse than an absent
 * one: the first teaches somebody the screen is broken, and the second teaches
 * them nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The verbs, already filtered by capability.
 *
 * @return array<int, array{label:string, url:string}>
 */
function gwc_vt_dashboard_actions(): array {
	$actions = array();

	if ( gwc_vt_shifts_enabled() && gwc_vt_can_see_records() ) {
		$actions[] = array(
			'label' => __( 'Add New Shift', 'groundwork-common-volunteer-tracker' ),
			'url'   => gwc_vt_schedule_url( array( 'shift' => 'new' ) ),
		);

		$actions[] = array(
			'label' => __( 'Add New Event', 'groundwork-common-volunteer-tracker' ),
			'url'   => gwc_vt_schedule_url( array( 'gwc_vt_event' => 'new' ) ),
		);
	}

	if ( gwc_vt_can_see_records() ) {
		$actions[] = array(
			'label' => __( 'Log a day’s sign-in sheet', 'groundwork-common-volunteer-tracker' ),
			'url'   => gwc_vt_quick_add_url(),
		);

		$actions[] = array(
			'label' => __( 'Add New Volunteer', 'groundwork-common-volunteer-tracker' ),
			'url'   => admin_url( 'post-new.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
		);

		$actions[] = array(
			'label' => __( 'Find somebody’s record', 'groundwork-common-volunteer-tracker' ),
			'url'   => admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
		);
	}

	/* Not an ordinary verb, and deliberately last: it is the one thing here
	 * somebody does because a person asked them to rather than because the week
	 * needs it. export_others_personal_data is core's own capability for it. */
	if ( current_user_can( 'export_others_personal_data' ) ) {
		$actions[] = array(
			'label' => __( 'Export or erase a record', 'groundwork-common-volunteer-tracker' ),
			'url'   => admin_url( 'export-personal-data.php' ),
		);
	}

	/**
	 * The dashboard's quick actions, already filtered by capability.
	 *
	 * Replaces gwc_vt_dashboard_map, which described a panel that no longer
	 * exists: with the verbs on the menu and the nouns in the sidebar, the map
	 * was mostly the sidebar written out twice.
	 *
	 * @param array $actions Label-and-url pairs, in the order they appear.
	 */
	return (array) apply_filters( 'gwc_vt_dashboard_actions', $actions );
}

/**
 * Render them.
 */
function gwc_vt_render_dashboard_actions(): void {
	$actions = gwc_vt_dashboard_actions();

	if ( ! $actions ) {
		return;
	}
	?>
	<section class="gwcvt-section">
		<div class="gwcvt-section__head">
			<h2><?php esc_html_e( 'Quick actions', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<nav class="gwcvt-card gwcvt-actions" aria-label="<?php esc_attr_e( 'Quick actions', 'groundwork-common-volunteer-tracker' ); ?>">
			<ul class="gwcvt-actions__list">
				<?php foreach ( $actions as $action ) : ?>
					<li><a href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) $action['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</section>
	<?php
}

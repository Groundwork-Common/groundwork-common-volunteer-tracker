<?php
/**
 * The screen somebody lands on.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'gwc_vt_register_dashboard_menu', 9 );

/* ── The page does two things ────────────────────────────────────────────────
 * Above: what is true right now and what is owed. Below and to the side: every
 * way out of here.
 *
 * A coordinator arrives either wanting to be told or wanting to go somewhere,
 * and the screen should not make them work out which half they are in. The
 * worklist is the widest thing on it because the worklist is the urgent part;
 * the map is an index down the side because an index is reference, and it reads
 * straight down.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Hang the dashboard off the Volunteer Hours menu.
 *
 * Priority 9, before every other screen registers, so it is first in the list
 * even before gwc_vt_order_menu() has its say.
 */
function gwc_vt_register_dashboard_menu(): void {
	add_submenu_page(
		GWC_VT_MENU_SLUG,
		__( 'Volunteer Hours', 'groundwork-common-volunteer-tracker' ),
		__( 'Dashboard', 'groundwork-common-volunteer-tracker' ),
		'edit_posts',
		GWC_VT_DASHBOARD_PAGE,
		'gwc_vt_render_dashboard'
	);
}

/**
 * The dashboard.
 */
function gwc_vt_render_dashboard(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to see this.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$items = gwc_vt_dashboard_items( gwc_vt_dashboard_counts() );
	?>
	<div class="wrap gwcvt-wrap gwcvt-dash">

		<header class="gwcvt-dash__masthead">
			<div>
				<h1><?php esc_html_e( 'Volunteer Hours', 'groundwork-common-volunteer-tracker' ); ?></h1>
				<span class="gwcvt-dash__org"><?php echo esc_html( gwc_vt_org_name() ); ?></span>
			</div>
			<span class="gwcvt-dash__date">
				<?php echo esc_html( wp_date( (string) get_option( 'date_format' ) ?: 'j F Y' ) ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- an empty date_format must fall back too, which ?? does not do; spelling it out would call get_option() twice. ?>
			</span>
		</header>

		<div class="gwcvt-dash__columns">

			<div class="gwcvt-dash__column">
				<?php gwc_vt_render_dashboard_worklist( $items ); ?>
				<?php gwc_vt_render_dashboard_upcoming(); ?>
			</div>

			<div class="gwcvt-dash__column">
				<?php gwc_vt_render_dashboard_year(); ?>
				<?php gwc_vt_render_dashboard_map(); ?>
			</div>

		</div>
	</div>
	<?php
}

/**
 * What is waiting.
 *
 * @param array $items From gwc_vt_dashboard_items().
 */
function gwc_vt_render_dashboard_worklist( array $items ): void {
	?>
	<section>
		<div class="gwcvt-dash__head">
			<h2><?php esc_html_e( 'Needs you', 'groundwork-common-volunteer-tracker' ); ?></h2>
			<?php if ( $items ) : ?>
				<span class="gwcvt-dash__aside"><?php esc_html_e( 'Ordered by what goes wrong if it waits', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="gwcvt-dash__panel gwcvt-docket">
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
				<a class="gwcvt-docket__row gwcvt-docket__row--<?php echo esc_attr( $item['severity'] ); ?>" href="<?php echo esc_url( gwc_vt_dashboard_item_url( (string) $item['key'] ) ); ?>">
					<span class="gwcvt-docket__stripe" aria-hidden="true"></span>
					<span class="gwcvt-docket__count"><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?></span>
					<span class="gwcvt-docket__body">
						<span class="gwcvt-docket__what"><?php echo esc_html( (string) $item['what'] ); ?></span>
						<span class="gwcvt-docket__why"><?php echo esc_html( (string) $item['why'] ); ?></span>
					</span>
					<span class="gwcvt-docket__go"><?php echo esc_html( (string) $item['action'] ); ?></span>
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

	<?php foreach ( $steps as $step ) : ?>
		<a class="gwcvt-docket__row gwcvt-docket__row--<?php echo $step['done'] ? 'done' : 'waiting'; ?>" href="<?php echo esc_url( (string) $step['url'] ); ?>">
			<span class="gwcvt-docket__stripe" aria-hidden="true"></span>
			<span class="gwcvt-docket__count" aria-hidden="true"><?php echo $step['done'] ? '&#10003;' : '&middot;'; ?></span>
			<span class="gwcvt-docket__body">
				<span class="gwcvt-docket__what"><?php echo esc_html( (string) $step['what'] ); ?></span>
				<span class="gwcvt-docket__why"><?php echo esc_html( (string) $step['why'] ); ?></span>
			</span>
			<span class="gwcvt-docket__go">
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

	$titles = array(
		'this' => __( 'This week', 'groundwork-common-volunteer-tracker' ),
		'next' => __( 'Next week', 'groundwork-common-volunteer-tracker' ),
	);
	?>
	<section>
		<div class="gwcvt-dash__head">
			<h2><?php esc_html_e( 'Coming up', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<div class="gwcvt-dash__panel">
			<?php foreach ( array_filter( $weeks ) as $when => $ids ) : ?>
				<?php /* array_filter drops an empty half rather than heading it and leaving it blank: a heading over nothing reads as a fault in the screen. */ ?>
				<h3 class="gwcvt-shiftweek"><?php echo esc_html( $titles[ $when ] ); ?></h3>

				<?php foreach ( $ids as $shift_id ) : ?>
					<?php gwc_vt_render_dashboard_shiftline( (int) $shift_id ); ?>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>

		<?php
		/* Outside the panel, not in it. Inside, it sat under the last line of
		 * next week and read as belonging to next week. It is the way out of
		 * the whole fortnight. */
		?>
		<p class="gwcvt-dash__more">
			<a href="<?php echo esc_url( gwc_vt_schedule_url() ); ?>"><?php esc_html_e( 'Open the schedule', 'groundwork-common-volunteer-tracker' ); ?></a>
		</p>
	</section>
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
	$short  = gwc_vt_shift_is_understaffed( $shift_id );
	$full   = $max > 0 && $filled >= $max;

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
			<span class="gwcvt-shiftline__count">
				<?php
				echo esc_html( gwc_vt_shift_fill_label( $shift_id ) );

				$min = (int) get_post_meta( $shift_id, GWC_VT_SHIFT_MIN, true );

				/* The number is not enough on its own: "3 of 8" does not say
				 * whether three is a problem. Color does not say it either, for
				 * anybody who cannot see it. */
				if ( $short && $min > 0 ) {
					printf(
						/* translators: %d: how many people the shift needs. */
						' · ' . esc_html__( 'needs %d', 'groundwork-common-volunteer-tracker' ),
						(int) $min
					);
				} elseif ( $full ) {
					echo ' · ' . esc_html__( 'full', 'groundwork-common-volunteer-tracker' );
				}
				?>
			</span>
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
		<div class="gwcvt-dash__head">
			<h2><?php esc_html_e( 'This year', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<div class="gwcvt-dash__panel gwcvt-year">
			<p class="gwcvt-year__figure">
				<?php echo esc_html( gwc_vt_format_hours( (int) $totals['verified'] ) ); ?>
				<span class="gwcvt-year__unit"><?php esc_html_e( 'verified hours', 'groundwork-common-volunteer-tracker' ); ?></span>
			</p>

			<dl class="gwcvt-year__rows">
				<div><dt><?php esc_html_e( 'Awaiting verification', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( gwc_vt_format_hours( (int) $totals['pending'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Volunteers with hours', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $totals['volunteers'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Shifts recorded', 'groundwork-common-volunteer-tracker' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $totals['entries'] ) ); ?></dd></div>
			</dl>

			<p class="gwcvt-year__note">
				<?php
				printf(
					/* translators: %s: a date. */
					esc_html__( 'Since %s. Verified hours only — the rest is counted separately because nobody has attested to it yet.', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_local_date( $from . ' 00:00:00' ) )
				);
				?>
			</p>
		</div>
	</section>
	<?php
}

/* ── The map ────────────────────────────────────────────────────────────────
 * Every way out of this page, grouped by what it is about.
 *
 * Filtered to what the person looking at it can actually reach: an editor
 * without gwc_vt_issue_letters gets no Letters group at all rather than three
 * links that will refuse them. A link that fails when clicked is worse than an
 * absent one, because the first teaches somebody the screen is broken and the
 * second teaches them nothing.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The map's groups and links.
 *
 * @return array<int, array{title:string, links:array<int, array{label:string, url:string}>}>
 */
function gwc_vt_dashboard_map(): array {
	$groups = array();

	if ( gwc_vt_shifts_enabled() && current_user_can( 'edit_posts' ) ) {
		$groups[] = array(
			'title' => __( 'Shifts', 'groundwork-common-volunteer-tracker' ),
			'links' => array(
				array(
					'label' => __( 'Add a shift', 'groundwork-common-volunteer-tracker' ),
					'url'   => gwc_vt_schedule_url( array( 'shift' => 'new' ) ),
				),
				array(
					'label' => __( 'Open the schedule', 'groundwork-common-volunteer-tracker' ),
					'url'   => gwc_vt_schedule_url(),
				),
				array(
					'label' => __( 'Shifts already run', 'groundwork-common-volunteer-tracker' ),
					'url'   => gwc_vt_schedule_url( array( 'when' => 'past' ) ),
				),
			),
		);
	}

	if ( current_user_can( 'edit_posts' ) ) {
		$groups[] = array(
			'title' => __( 'Hours', 'groundwork-common-volunteer-tracker' ),
			'links' => array(
				array(
					'label' => __( 'Log a day', 'groundwork-common-volunteer-tracker' ),
					'url'   => add_query_arg(
						array(
							'post_type' => GWC_VT_ENTRY_TYPE,
							'page'      => GWC_VT_QUICK_ADD_PAGE,
						),
						admin_url( 'edit.php' )
					),
				),
				array(
					'label' => __( 'Log a single shift', 'groundwork-common-volunteer-tracker' ),
					'url'   => admin_url( 'post-new.php?post_type=' . GWC_VT_ENTRY_TYPE ),
				),
				array(
					'label' => __( 'All hours', 'groundwork-common-volunteer-tracker' ),
					'url'   => admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE ),
				),
			),
		);

		$groups[] = array(
			'title' => __( 'Volunteers', 'groundwork-common-volunteer-tracker' ),
			'links' => array(
				array(
					'label' => __( 'Add a volunteer', 'groundwork-common-volunteer-tracker' ),
					'url'   => admin_url( 'post-new.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
				),
				array(
					'label' => __( 'Find somebody’s record', 'groundwork-common-volunteer-tracker' ),
					'url'   => admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ),
				),
			),
		);
	}

	if ( gwc_vt_letters_enabled() && current_user_can( gwc_vt_cap( 'issue' ) ) ) {
		$groups[] = array(
			'title' => __( 'Letters', 'groundwork-common-volunteer-tracker' ),
			'links' => array(
				array(
					'label' => __( 'Produce a letter', 'groundwork-common-volunteer-tracker' ),
					'url'   => add_query_arg(
						array(
							'post_type' => GWC_VT_ENTRY_TYPE,
							'page'      => GWC_VT_LETTERS_PAGE,
						),
						admin_url( 'edit.php' )
					),
				),
				array(
					'label' => __( 'Check a reference', 'groundwork-common-volunteer-tracker' ),
					'url'   => add_query_arg(
						array(
							'post_type' => GWC_VT_ENTRY_TYPE,
							'page'      => GWC_VT_LETTERS_PAGE,
						),
						admin_url( 'edit.php' )
					) . '#gwcvt-reference',
				),
			),
		);
	}

	$setup = array();

	if ( current_user_can( gwc_vt_cap( 'manage' ) ) ) {
		$setup[] = array(
			'label' => __( 'Settings', 'groundwork-common-volunteer-tracker' ),
			'url'   => add_query_arg(
				array(
					'post_type' => GWC_VT_ENTRY_TYPE,
					'page'      => GWC_VT_SETTINGS_PAGE,
				),
				admin_url( 'edit.php' )
			),
		);
	}

	if ( current_user_can( 'export_others_personal_data' ) ) {
		$setup[] = array(
			'label' => __( 'Export or erase a record', 'groundwork-common-volunteer-tracker' ),
			'url'   => admin_url( 'export-personal-data.php' ),
		);
	}

	if ( $setup ) {
		$groups[] = array(
			'title' => __( 'Setting up', 'groundwork-common-volunteer-tracker' ),
			'links' => $setup,
		);
	}

	/**
	 * The dashboard's map of everywhere else.
	 *
	 * @param array $groups Groups of links, already filtered by capability.
	 */
	return (array) apply_filters( 'gwc_vt_dashboard_map', $groups );
}

/**
 * Render it.
 */
function gwc_vt_render_dashboard_map(): void {
	$groups = gwc_vt_dashboard_map();

	if ( ! $groups ) {
		return;
	}
	?>
	<section>
		<div class="gwcvt-dash__head">
			<h2><?php esc_html_e( 'Where to next', 'groundwork-common-volunteer-tracker' ); ?></h2>
		</div>

		<nav class="gwcvt-dash__panel gwcvt-map" aria-label="<?php esc_attr_e( 'Everywhere else in Volunteer Hours', 'groundwork-common-volunteer-tracker' ); ?>">
			<?php foreach ( $groups as $group ) : ?>
				<div class="gwcvt-map__group">
					<h3><?php echo esc_html( (string) $group['title'] ); ?></h3>
					<ul class="gwcvt-map__links">
						<?php foreach ( (array) $group['links'] as $link ) : ?>
							<li><a href="<?php echo esc_url( (string) $link['url'] ); ?>"><?php echo esc_html( (string) $link['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</nav>
	</section>
	<?php
}

<?php
/**
 * The plugin's line on WordPress's own dashboard.
 *
 * A small window onto inc/admin-dashboard.php, and deliberately a window rather
 * than a second version of it. Everything here is read from
 * gwc_vt_dashboard_items() and gwc_vt_shifts_between(); nothing decides for
 * itself what is waiting, because two definitions of "what needs doing" is how
 * a screen and the number above it start disagreeing.
 *
 * ── Why this is not the tracker's dashboard again, only smaller ──────────────
 * The tracker's own dashboard is a screen somebody chose to open. This one
 * loads whether or not anybody wanted it, beside four other plugins' widgets,
 * for somebody who may have come to write a post. So it answers one question —
 * is there anything I have to do about volunteers today — and sends everything
 * else to the screen built for it.
 *
 * What that buys, and what it costs, is set out under the three rules below.
 *
 * ── 1. Who sees it ──────────────────────────────────────────────────────────
 * gwc_vt_cap( 'verify' ), not `edit_posts`.
 *
 * The tracker's own screen uses `edit_posts`, which includes contributors, and
 * that is right for a screen somebody navigated to: it says what is waiting and
 * they can see it is not their job. Here the calculation is different in both
 * directions. A contributor cannot act on most of these lines, so the widget
 * would be furniture — and the counts are about volunteers on a court-ordered
 * service program, on a page every contributor lands on after logging in.
 * Narrower is right on both counts.
 *
 * ── 2. What it costs ────────────────────────────────────────────────────────
 * gwc_vt_dashboard_counts() runs seven counts, two of which walk posts. On the
 * tracker's own screen that is paid once by somebody who asked. Here it would
 * be paid by every user on every visit to wp-admin's front page, which is not a
 * cost this plugin gets to impose on a site that installed it for the letters.
 *
 * So the counts are cached, briefly. GWC_VT_WIDGET_TTL is short on purpose:
 * this plugin has a rule that a count and the screen it links to must agree,
 * and a cache is the one honest way to break it. Two minutes is short enough
 * that a stale line survives about as long as it takes to read it, and a
 * refresh always tells the truth — which is a different thing entirely from the
 * count and the screen being derived differently, which is what that rule is
 * actually about.
 *
 * ── 3. What it will not say ─────────────────────────────────────────────────
 * It names no volunteer. DashboardTest already asserts the overdue line points
 * at the list rather than naming anybody, and the reasoning is stronger here:
 * this is a screen shared with every other plugin on the site, and a name
 * beside "past their deadline" is a court-order disclosure on the front page of
 * wp-admin.
 *
 * It also makes no prediction. There is no "at risk" line, and that is not an
 * omission — gwc_vt_requirement_progress() states the refusal in its own
 * comment: overdue is a fact, on-track is a guess, and being told you are
 * behind by software that guessed is worse than not being told. A widget is
 * exactly where that temptation lands, so the refusal is repeated here.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'gwc_vt_register_dashboard_widget' );

/** How long the widget's counts are cached for. */
const GWC_VT_WIDGET_TTL = 2 * MINUTE_IN_SECONDS;

/** Where the cached counts live. */
const GWC_VT_WIDGET_KEY = 'gwc_vt_widget_counts';

/**
 * How many DAYS with anything on them the week block covers.
 *
 * Days rather than shifts, and the difference showed up the moment this was
 * looked at on real data: a busy Saturday with three shifts on it filled a
 * three-shift limit by itself, so Wednesday never appeared and the block
 * silently became "today" rather than "this week". Counting days is what makes
 * the heading true.
 */
const GWC_VT_WIDGET_DAYS = 2;

/**
 * And how many shifts one of those days may list.
 *
 * A ceiling, not a page. An event's times are ordinary shifts hanging off it by
 * post_parent, so a festival with twelve slots is twelve rows on one date — and
 * a widget that renders twelve rows is one somebody collapses and never opens
 * again. What is over the cap is counted rather than dropped silently.
 */
const GWC_VT_WIDGET_PER_DAY = 3;

/** How many worklist lines get a row of their own before the rest collapse. */
const GWC_VT_WIDGET_LINES = 3;

/**
 * Whether this user gets the widget at all.
 *
 * @return bool
 */
function gwc_vt_can_see_dashboard_widget(): bool {
	return current_user_can( gwc_vt_cap( 'verify' ) );
}

/**
 * Add it.
 */
function gwc_vt_register_dashboard_widget(): void {
	if ( ! gwc_vt_can_see_dashboard_widget() ) {
		return;
	}

	wp_add_dashboard_widget(
		'gwc_vt_dashboard_widget',
		__( 'Volunteer Tracker', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_dashboard_widget'
	);
}

/**
 * The counts, cached.
 *
 * @param bool $fresh Skip the cache.
 * @return array<string, int>
 */
function gwc_vt_widget_counts( bool $fresh = false ): array {
	if ( ! $fresh ) {
		$cached = get_transient( GWC_VT_WIDGET_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$counts = gwc_vt_dashboard_counts();

	set_transient( GWC_VT_WIDGET_KEY, $counts, GWC_VT_WIDGET_TTL );

	return $counts;
}

/**
 * Throw the cached counts away.
 *
 * Not hooked to anything. It exists so that a site with a plugin of its own, or
 * a test, can make the widget tell the truth immediately — and so that the TTL
 * above is the only thing anybody has to reason about.
 */
function gwc_vt_forget_widget_counts(): void {
	delete_transient( GWC_VT_WIDGET_KEY );
}

/**
 * The widget.
 */
function gwc_vt_render_dashboard_widget(): void {
	if ( ! gwc_vt_can_see_dashboard_widget() ) {
		return;
	}

	$items = gwc_vt_dashboard_items( gwc_vt_widget_counts() );

	gwc_vt_render_widget_week();
	gwc_vt_render_widget_worklist( $items );
	gwc_vt_render_widget_footer( $items );
}

/**
 * What is coming, named rather than counted.
 *
 * The single most useful thing on here, and the reason the widget is not just
 * the worklist in a smaller box. "2 shifts need people" makes a coordinator
 * click to find out which; "Sat 29 Aug — 3 of 8" is the answer.
 */
function gwc_vt_render_widget_week(): void {
	if ( ! gwc_vt_shifts_enabled() ) {
		return;
	}

	$today = gwc_vt_today();

	$days = gwc_vt_widget_days(
		gwc_vt_shifts_between(
			array(
				'from'  => $today,
				'to'    => gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ),
				'limit' => 60,
			)
		)
	);

	if ( ! $days ) {
		return;
	}
	?>
	<p class="gwcvt-widget__heading"><?php esc_html_e( 'This week', 'groundwork-common-volunteer-tracker' ); ?></p>

	<?php foreach ( $days as $date => $shifts ) : ?>
		<p class="gwcvt-widget__when"><?php echo esc_html( gwc_vt_shift_date_label_from( (string) $date ) ); ?></p>

		<ul class="gwcvt-widget__week">
			<?php foreach ( array_slice( $shifts, 0, GWC_VT_WIDGET_PER_DAY ) as $shift_id ) : ?>
				<?php $shift_id = (int) $shift_id; ?>
				<li>
					<span class="gwcvt-widget__what"><?php echo esc_html( gwc_vt_widget_shift_name( $shift_id ) ); ?></span>
					<span class="gwcvt-widget__fill"><?php echo esc_html( gwc_vt_shift_fill_label( $shift_id ) ); ?></span>
				</li>
			<?php endforeach; ?>

			<?php if ( count( $shifts ) > GWC_VT_WIDGET_PER_DAY ) : ?>
				<li class="gwcvt-widget__more">
					<?php
					$over = count( $shifts ) - GWC_VT_WIDGET_PER_DAY;

					printf(
						/* translators: %s: how many more shifts that day has, already formatted. */
						esc_html( _n( 'and %s more that day', 'and %s more that day', $over, 'groundwork-common-volunteer-tracker' ) ),
						esc_html( number_format_i18n( $over ) )
					);
					?>
				</li>
			<?php endif; ?>
		</ul>
	<?php endforeach; ?>
	<?php
}

/**
 * The next few days that have anything on them, each with its shifts.
 *
 * Pure, and separate from the rendering, so the rule that matters — that a busy
 * day cannot crowd out the next one — can be asserted without a database.
 *
 * Days with nothing on them are not keys here at all. "The next two days with
 * shifts" is the question a coordinator is asking; a Tuesday with nothing on it
 * is not an answer worth a heading.
 *
 * @param int[] $shift_ids Shifts, already in date order.
 * @param int   $days      How many days with anything on them to keep.
 * @return array<string, int[]> Y-m-d => shift IDs, in the order they occur.
 */
function gwc_vt_widget_days( array $shift_ids, int $days = GWC_VT_WIDGET_DAYS ): array {
	$by_day = array();

	foreach ( $shift_ids as $shift_id ) {
		$date = (string) get_post_meta( (int) $shift_id, GWC_VT_SHIFT_DATE, true );

		if ( '' === $date ) {
			continue;
		}

		/* Every shift on the day is kept, not just the ones inside the cap —
		 * the renderer needs the real total to say how many it did not show. */
		$by_day[ $date ][] = (int) $shift_id;

		if ( count( $by_day ) > $days ) {
			/* One day past the limit means the previous ones are complete.
			 * Dropping it here rather than slicing at the end keeps the loop
			 * from walking sixty shifts to build days nobody will see. */
			array_pop( $by_day );
			break;
		}
	}

	return $by_day;
}

/**
 * What a shift is called, on one line.
 *
 * The activity if it has one, and the post title if it does not — never an
 * empty cell, because a row with a date and a fill and nothing between them
 * reads as a rendering fault rather than as a shift nobody named.
 *
 * @param int $shift_id Shift post ID.
 * @return string
 */
function gwc_vt_widget_shift_name( int $shift_id ): string {
	$activity = trim( (string) get_post_meta( $shift_id, GWC_VT_SHIFT_ACTIVITY, true ) );

	if ( '' !== $activity ) {
		return $activity;
	}

	$event_id = (int) wp_get_post_parent_id( $shift_id );

	if ( $event_id > 0 && GWC_VT_EVENT_TYPE === get_post_type( $event_id ) ) {
		return (string) get_the_title( $event_id );
	}

	return __( 'A shift', 'groundwork-common-volunteer-tracker' );
}

/**
 * What is waiting.
 *
 * The first few lines get a row each; whatever is left becomes one sentence.
 * A widget that renders seven rows is a widget somebody collapses and never
 * opens again, and the order is already "what is lost if it waits" — so the
 * things that fall below the fold are, by construction, the ones that keep.
 *
 * @param array $items From gwc_vt_dashboard_items().
 */
function gwc_vt_render_widget_worklist( array $items ): void {
	if ( ! $items ) {
		printf(
			'<p class="gwcvt-widget__clear">%s</p>',
			esc_html__( 'Nothing is waiting.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	$shown = array_slice( $items, 0, GWC_VT_WIDGET_LINES );
	$rest  = array_slice( $items, GWC_VT_WIDGET_LINES );
	?>
	<p class="gwcvt-widget__heading"><?php esc_html_e( 'Needs you', 'groundwork-common-volunteer-tracker' ); ?></p>

	<ul class="gwcvt-widget__list">
		<?php foreach ( $shown as $item ) : ?>
			<li class="gwcvt-widget__item gwcvt-widget__item--<?php echo esc_attr( $item['severity'] ); ?>">
				<span class="gwcvt-widget__count"><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?></span>
				<span class="gwcvt-widget__job">
					<a href="<?php echo esc_url( gwc_vt_dashboard_item_url( $item['key'] ) ); ?>"><?php echo esc_html( $item['what'] ); ?></a>
					<span class="gwcvt-widget__why"><?php echo esc_html( $item['why'] ); ?></span>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $rest ) : ?>
		<p class="gwcvt-widget__rest">
			<?php esc_html_e( 'When you have a minute:', 'groundwork-common-volunteer-tracker' ); ?>
			<?php
			$links = array();

			foreach ( $rest as $item ) {
				$links[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( gwc_vt_dashboard_item_url( $item['key'] ) ),
					esc_html( gwc_vt_widget_short_label( $item ) )
				);
			}

			/* Joined with a middot rather than a comma. These are separate
			 * errands, not a list of one thing. */
			echo wp_kses( implode( ' &middot; ', $links ), array( 'a' => array( 'href' => array() ) ) );
			?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * One collapsed errand, in as few words as it takes.
 *
 * Its own sentence rather than the worklist's, which is written to be read on
 * its own line with a number beside it. "6 shifts to verify" is that line's
 * content in the shape a run-on takes.
 *
 * @param array $item One item from gwc_vt_dashboard_items().
 * @return string
 */
function gwc_vt_widget_short_label( array $item ): string {
	/* Fragments, not sentences. Each lands in a run-on line reading "When you
	 * have a minute: 6 shifts to verify · 2 to match to a volunteer", which is
	 * why none of them ends in a full stop and why the count is inside the
	 * string rather than beside it — the number does not come first in every
	 * language. */
	$labels = array(
		// translators: %s is a count, already formatted.
		'unreconciled' => _n_noop( '%s shift to write up', '%s shifts to write up', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'understaffed' => _n_noop( '%s shift short of people', '%s shifts short of people', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'offers'       => _n_noop( '%s offer to answer', '%s offers to answer', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'overdue'      => _n_noop( '%s past a deadline', '%s past a deadline', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'lapsed'       => _n_noop( '%s lapsed credential', '%s lapsed credentials', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'unverified'   => _n_noop( '%s shift to verify', '%s shifts to verify', 'groundwork-common-volunteer-tracker' ),
		// translators: %s is a count, already formatted.
		'unmatched'    => _n_noop( '%s to match to a volunteer', '%s to match to a volunteer', 'groundwork-common-volunteer-tracker' ),
	);

	$key   = (string) $item['key'];
	$count = (int) $item['count'];

	if ( ! isset( $labels[ $key ] ) ) {
		/* A line this file has never heard of. Falls back to the worklist's own
		 * wording rather than to nothing — a new queue added to
		 * gwc_vt_dashboard_items() should appear here looking slightly wrong,
		 * not silently vanish. */
		return $item['what'];
	}

	return sprintf(
		translate_nooped_plural( $labels[ $key ], $count, 'groundwork-common-volunteer-tracker' ),
		number_format_i18n( $count )
	);
}

/**
 * The year, and the way out.
 *
 * @param array $items From gwc_vt_dashboard_items(), to know whether anything
 *                     above it said "nothing is waiting".
 */
function gwc_vt_render_widget_footer( array $items ): void {
	$year = gwc_vt_org_totals( gmdate( 'Y' ) . '-01-01', gmdate( 'Y' ) . '-12-31' );
	?>
	<p class="gwcvt-widget__foot">
		<span>
			<?php
			printf(
				/* translators: %s: a duration, already formatted — "418" or "418h 30m" depending on the site's setting, so the sentence must not add a unit of its own. */
				esc_html__( '%s verified this year', 'groundwork-common-volunteer-tracker' ),
				esc_html( gwc_vt_format_hours( (int) $year['verified'] ) )
			);
			?>
		</span>
		<a href="<?php echo esc_url( gwc_vt_widget_dashboard_url() ); ?>">
			<?php esc_html_e( 'Open the tracker', 'groundwork-common-volunteer-tracker' ); ?>
		</a>
	</p>
	<?php

	unset( $items );
}

/**
 * The tracker's own dashboard.
 *
 * Local rather than shared. inc/admin-dashboard.php builds this URL inline in
 * the two places it needs it, and extracting a helper there to serve one caller
 * here would be touching a screen this change has no other business in.
 *
 * @return string
 */
function gwc_vt_widget_dashboard_url(): string {
	return add_query_arg(
		array(
			'post_type' => GWC_VT_ENTRY_TYPE,
			'page'      => GWC_VT_DASHBOARD_PAGE,
		),
		admin_url( 'edit.php' )
	);
}

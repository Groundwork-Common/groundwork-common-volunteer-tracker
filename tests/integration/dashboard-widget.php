<?php
/**
 * The widget on WordPress's own dashboard.
 *
 * ── Why this needs a database ────────────────────────────────────────────────
 * Every claim is about what the widget draws from real posts: that it renders
 * at all, that it names shifts rather than counting them, that it caches, that
 * it shows nobody's name, and that it disappears entirely for somebody who
 * cannot act on it.
 *
 * ── The two that matter most ────────────────────────────────────────────────
 * §4 renders with warnings promoted to exceptions. A renderer reading a
 * variable somebody left behind in a refactor has been fatal on a real page in
 * this plugin before, while the whole suite stayed green.
 *
 * §5 asserts no volunteer's name appears anywhere in the markup. This is a
 * screen shared with every other plugin on the site, and a name beside "past
 * their deadline" is a court-order disclosure on the front page of wp-admin.
 *
 * Run under wp-env:
 *
 *   bin/wpenv run cli -- wp eval-file \
 *     wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/dashboard-widget.php
 *
 * @package VolunteerTracker
 */

/* $GLOBALS explicitly — wp eval-file runs this inside a function. */
$GLOBALS['gwc_vt_failures'] = 0;
$GLOBALS['gwc_vt_dw_made']  = array();
$GLOBALS['gwc_vt_dw_opts']  = get_option( GWC_VT_SETTINGS_OPTION, array() );

/**
 * Assert, tersely.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Whether it passed.
 * @param string $got   Optional. What was actually seen.
 */
function gwc_vt_dw_check( string $label, bool $ok, string $got = '' ): void {
	if ( ! $ok ) {
		++$GLOBALS['gwc_vt_failures'];
	}

	echo $ok ? 'PASS  ' : 'FAIL  ', $label, '' !== $got ? '  [' . $got . ']' : '', "\n";
}

/**
 * Draw the widget and give back its markup.
 *
 * @return string
 */
function gwc_vt_dw_render(): string {
	ob_start();
	gwc_vt_render_dashboard_widget();

	return (string) ob_get_clean();
}

wp_set_current_user( 1 );
gwc_vt_forget_widget_counts();

/* The week block draws nothing when the schedule is switched off, which is
 * correct and is not what this file is about. Set it explicitly rather than
 * inheriting whatever the last script or the last seed left behind — a test
 * whose subject depends on ambient settings passes and fails for reasons that
 * have nothing to do with the code it names. */
update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge( $GLOBALS['gwc_vt_dw_opts'], array( 'shifts_enabled' => 1 ) )
);
gwc_vt_settings_cache( null, true );

echo "\n── 1. It draws ──────────────────────────────────────────────────\n";

$gwc_vt_dw_vol = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_VOLUNTEER_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzdw Marisol Quintanilla',
	)
);

$GLOBALS['gwc_vt_dw_made'][] = $gwc_vt_dw_vol;

$gwc_vt_dw_shift = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzdw shift',
	)
);

update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +2 days' ) ) );
update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_START, '09:00' );
update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_END, '12:00' );
update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_ACTIVITY, 'Zzdw sorting the delivery' );
update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_MIN, 4 );
update_post_meta( $gwc_vt_dw_shift, GWC_VT_SHIFT_MAX, 6 );

$GLOBALS['gwc_vt_dw_made'][] = $gwc_vt_dw_shift;

gwc_vt_forget_widget_counts();

$gwc_vt_dw_html = gwc_vt_dw_render();

gwc_vt_dw_check(
	'the widget renders something',
	'' !== trim( $gwc_vt_dw_html ),
	'' === trim( $gwc_vt_dw_html ) ? 'drew nothing' : strlen( $gwc_vt_dw_html ) . ' bytes'
);

gwc_vt_dw_check(
	'it links to the tracker',
	false !== strpos( $gwc_vt_dw_html, GWC_VT_DASHBOARD_PAGE ),
	false !== strpos( $gwc_vt_dw_html, GWC_VT_DASHBOARD_PAGE ) ? '' : 'no way through to the full screen'
);

echo "\n── 2. It names the shift rather than counting it ────────────────\n";

/* The whole reason this is not the worklist in a smaller box. "2 shifts need
 * people" makes a coordinator click to find out which.
 *
 * Asserted against whatever gwc_vt_shifts_between() actually returns rather
 * than against the shift this script made. The widget shows the SOONEST few,
 * and a database with anything else in it — a seed, another script's fixture —
 * can push a shift two days out past them. Naming the fixture and hoping it
 * lands in the top three is a test that passes on an empty database and fails
 * on a real one, which is the wrong way round. */
$gwc_vt_dw_days = gwc_vt_widget_days(
	gwc_vt_shifts_between(
		array(
			'from'  => gwc_vt_today(),
			'to'    => gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +7 days' ) ),
			'limit' => 60,
		)
	)
);

$gwc_vt_dw_soonest = array();

foreach ( $gwc_vt_dw_days as $gwc_vt_dw_day ) {
	$gwc_vt_dw_soonest = array_merge( $gwc_vt_dw_soonest, array_slice( $gwc_vt_dw_day, 0, GWC_VT_WIDGET_PER_DAY ) );
}

gwc_vt_dw_check(
	'there is a shift this week to name',
	array() !== $gwc_vt_dw_soonest,
	array() !== $gwc_vt_dw_soonest ? '' : 'nothing upcoming, so the week block has nothing to prove'
);

$gwc_vt_dw_unnamed = 0;
$gwc_vt_dw_unfilled = 0;

foreach ( $gwc_vt_dw_soonest as $gwc_vt_dw_one ) {
	if ( false === strpos( $gwc_vt_dw_html, gwc_vt_widget_shift_name( (int) $gwc_vt_dw_one ) ) ) {
		++$gwc_vt_dw_unnamed;
	}

	if ( false === strpos( $gwc_vt_dw_html, gwc_vt_shift_fill_label( (int) $gwc_vt_dw_one ) ) ) {
		++$gwc_vt_dw_unfilled;
	}
}

gwc_vt_dw_check(
	'every shift it shows is named, not counted',
	0 === $gwc_vt_dw_unnamed,
	$gwc_vt_dw_unnamed . ' of ' . count( $gwc_vt_dw_soonest ) . ' shown without a name'
);

gwc_vt_dw_check(
	'and each says how full it is',
	0 === $gwc_vt_dw_unfilled,
	$gwc_vt_dw_unfilled . ' of ' . count( $gwc_vt_dw_soonest ) . ' shown without a fill'
);

/* ── What this file can and cannot prove about the grouping ──────────────────
 * It cannot prove the two-days rule. Every expectation here is derived from
 * gwc_vt_widget_days(), which is the function that would be wrong — so a
 * sabotage of it moves the expectation with the result and the check passes.
 * Both sabotages of that function did exactly that, and were caught only by
 * DashboardTest, which asserts concrete dates against a fixture it controls.
 *
 * That rule lives there. What THIS file can prove is the relationship between
 * the two halves: that the renderer draws every day the grouper returned, and
 * no more. Stated rather than left implicit, because a check that looks
 * stronger than it is, is worse than one that admits its scope.
 * ─────────────────────────────────────────────────────────────────────────── */

gwc_vt_dw_check(
	'the renderer draws a heading for every day the grouper returned',
	substr_count( $gwc_vt_dw_html, 'gwcvt-widget__when' ) === count( $gwc_vt_dw_days ),
	substr_count( $gwc_vt_dw_html, 'gwcvt-widget__when' ) . ' headings for ' . count( $gwc_vt_dw_days ) . ' day(s)'
);

$gwc_vt_dw_missing_date = 0;

foreach ( array_keys( $gwc_vt_dw_days ) as $gwc_vt_dw_date ) {
	if ( false === strpos( $gwc_vt_dw_html, gwc_vt_shift_date_label_from( (string) $gwc_vt_dw_date ) ) ) {
		++$gwc_vt_dw_missing_date;
	}
}

gwc_vt_dw_check(
	'and each of those days is named',
	0 === $gwc_vt_dw_missing_date,
	$gwc_vt_dw_missing_date . ' day(s) grouped but not drawn'
);

/* ── Every shift it shows is a way through to that shift ─────────────────────
 * A coordinator who has just read "3 of 8" wants the roster, and the widget is
 * on a screen they did not navigate to — so the row has to be the way there or
 * it is a notification rather than a tool.
 *
 * Two destinations, and the distinction is the point: an event's time goes to
 * the EVENT's roster, because a slot has no screen of its own and the rest of
 * the day would be invisible from one.
 * ─────────────────────────────────────────────────────────────────────────── */

$gwc_vt_dw_unlinked = 0;

foreach ( $gwc_vt_dw_soonest as $gwc_vt_dw_one ) {
	$gwc_vt_dw_url = gwc_vt_widget_shift_url( (int) $gwc_vt_dw_one );

	if ( '' === $gwc_vt_dw_url || false === strpos( $gwc_vt_dw_html, esc_url( $gwc_vt_dw_url ) ) ) {
		++$gwc_vt_dw_unlinked;
	}
}

gwc_vt_dw_check(
	'every shift it shows links somewhere',
	0 === $gwc_vt_dw_unlinked,
	$gwc_vt_dw_unlinked . ' of ' . count( $gwc_vt_dw_soonest ) . ' shown without a link'
);

/* A slot's link is the event's roster, not the slot. Built here rather than
 * asserted against a fixture, because the seeded event is in the past and the
 * week block will not show it — what is being checked is the routing rule. */
$gwc_vt_dw_event = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_EVENT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzdw meal service',
	)
);

$GLOBALS['gwc_vt_dw_made'][] = $gwc_vt_dw_event;

$gwc_vt_dw_slot = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_SHIFT_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzdw slot',
		'post_parent' => $gwc_vt_dw_event,
	)
);

update_post_meta( $gwc_vt_dw_slot, GWC_VT_SHIFT_DATE, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' +1 day' ) ) );
update_post_meta( $gwc_vt_dw_slot, GWC_VT_SHIFT_START, '09:00' );
update_post_meta( $gwc_vt_dw_slot, GWC_VT_SHIFT_END, '12:00' );

$GLOBALS['gwc_vt_dw_made'][] = $gwc_vt_dw_slot;

gwc_vt_dw_check(
	'an event’s time links to the event’s roster',
	gwc_vt_widget_shift_url( $gwc_vt_dw_slot ) === gwc_vt_event_roster_url( $gwc_vt_dw_event ),
	gwc_vt_widget_shift_url( $gwc_vt_dw_slot )
);

gwc_vt_dw_check(
	'and a standalone shift links to its own screen',
	gwc_vt_widget_shift_url( $gwc_vt_dw_shift ) === gwc_vt_schedule_url( array( 'shift' => $gwc_vt_dw_shift ) ),
	gwc_vt_widget_shift_url( $gwc_vt_dw_shift )
);

gwc_vt_dw_check(
	'the two are not the same place',
	gwc_vt_widget_shift_url( $gwc_vt_dw_slot ) !== gwc_vt_widget_shift_url( $gwc_vt_dw_shift )
);

gwc_vt_dw_check(
	'never more days than the widget promises',
	count( $gwc_vt_dw_days ) <= GWC_VT_WIDGET_DAYS,
	count( $gwc_vt_dw_days ) . ' day(s)'
);

/* And it really is the setting doing that, not an accident of the data. */
update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge( $GLOBALS['gwc_vt_dw_opts'], array( 'shifts_enabled' => 0 ) )
);
gwc_vt_settings_cache( null, true );
gwc_vt_forget_widget_counts();

gwc_vt_dw_check(
	'a site not using the schedule gets no week block',
	0 === substr_count( gwc_vt_dw_render(), 'gwcvt-widget__when' ),
	substr_count( gwc_vt_dw_render(), 'gwcvt-widget__when' ) . ' shift rows on a site with the schedule off'
);

update_option(
	GWC_VT_SETTINGS_OPTION,
	array_merge( $GLOBALS['gwc_vt_dw_opts'], array( 'shifts_enabled' => 1 ) )
);
gwc_vt_settings_cache( null, true );
gwc_vt_forget_widget_counts();

echo "\n── 3. The counts are cached, briefly ────────────────────────────\n";

gwc_vt_forget_widget_counts();

$gwc_vt_dw_first = gwc_vt_widget_counts();

gwc_vt_dw_check(
	'the counts land in a transient',
	is_array( get_transient( GWC_VT_WIDGET_KEY ) ),
	is_array( get_transient( GWC_VT_WIDGET_KEY ) ) ? '' : 'nothing was cached, so every dashboard load pays for seven counts'
);

/* Written straight into the cache. If the widget re-derived rather than reading
 * it, this value could not come back out. */
set_transient( GWC_VT_WIDGET_KEY, array_merge( $gwc_vt_dw_first, array( 'unverified' => 4242 ) ), GWC_VT_WIDGET_TTL );

gwc_vt_dw_check(
	'and are read back rather than recomputed',
	4242 === gwc_vt_widget_counts()['unverified'],
	(string) gwc_vt_widget_counts()['unverified']
);

gwc_vt_dw_check(
	'asking for fresh ones bypasses it',
	4242 !== gwc_vt_widget_counts( true )['unverified'],
	(string) gwc_vt_widget_counts( true )['unverified']
);

gwc_vt_forget_widget_counts();

gwc_vt_dw_check(
	'and forgetting them clears it',
	false === get_transient( GWC_VT_WIDGET_KEY )
);

echo "\n── 4. It draws with warnings promoted to exceptions ─────────────\n";

set_error_handler(
	static function ( $gwc_vt_dw_no, $gwc_vt_dw_str, $gwc_vt_dw_file, $gwc_vt_dw_line ) {
		throw new ErrorException( $gwc_vt_dw_str, 0, $gwc_vt_dw_no, $gwc_vt_dw_file, $gwc_vt_dw_line );
	},
	E_ALL
);

try {
	$gwc_vt_dw_html = gwc_vt_dw_render();

	gwc_vt_dw_check( 'no notice, no warning, no undefined anything', true, strlen( $gwc_vt_dw_html ) . ' bytes' );
} catch ( Throwable $gwc_vt_dw_e ) {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	gwc_vt_dw_check(
		'no notice, no warning, no undefined anything',
		false,
		$gwc_vt_dw_e->getMessage() . ' at ' . basename( $gwc_vt_dw_e->getFile() ) . ':' . $gwc_vt_dw_e->getLine()
	);
}

restore_error_handler();

/* The wrapper rule, which has cost an afternoon before: a <ul> inside a <p>
 * makes the parser close the paragraph and everything still open in it. */
gwc_vt_dw_check(
	'it puts no list inside a paragraph',
	! preg_match( '~<p[^>]*>(?:(?!</p>).)*<ul~s', $gwc_vt_dw_html )
);

echo "\n── 5. It names nobody ───────────────────────────────────────────\n";

/* Give the volunteer every state that could tempt a renderer into naming
 * somebody: a court-ordered requirement, past its deadline, and hours nobody
 * has verified. */
update_post_meta( $gwc_vt_dw_vol, GWC_VT_VOLUNTEER_REQUIRED, 40 * 60 );
update_post_meta( $gwc_vt_dw_vol, GWC_VT_VOLUNTEER_REQUIRED_BY, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' -10 days' ) ) );
update_post_meta( $gwc_vt_dw_vol, GWC_VT_VOLUNTEER_REQUIRED_FOR, 'Zzdw County Municipal Court' );

$gwc_vt_dw_entry = (int) wp_insert_post(
	array(
		'post_type'   => GWC_VT_ENTRY_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Zzdw entry',
	)
);

update_post_meta( $gwc_vt_dw_entry, GWC_VT_ENTRY_VOLUNTEER, (string) $gwc_vt_dw_vol );
update_post_meta( $gwc_vt_dw_entry, GWC_VT_ENTRY_DATE, gmdate( 'Y-m-d', strtotime( gwc_vt_today() . ' -3 days' ) ) );
update_post_meta( $gwc_vt_dw_entry, GWC_VT_ENTRY_MINUTES, 180 );

$GLOBALS['gwc_vt_dw_made'][] = $gwc_vt_dw_entry;

gwc_vt_forget_widget_counts();

$gwc_vt_dw_html = gwc_vt_dw_render();

gwc_vt_dw_check(
	'no volunteer is named',
	false === stripos( $gwc_vt_dw_html, 'Marisol' ),
	false === stripos( $gwc_vt_dw_html, 'Marisol' ) ? '' : 'a volunteer was named on a screen every plugin shares'
);

gwc_vt_dw_check(
	'and no court is named',
	false === stripos( $gwc_vt_dw_html, 'Municipal Court' ),
	false === stripos( $gwc_vt_dw_html, 'Municipal Court' ) ? '' : 'a court order was disclosed on the front page of wp-admin'
);

/* The refusal gwc_vt_requirement_progress() states in its own comment: overdue
 * is a fact, on-track is a guess. A widget is exactly where that temptation
 * lands, so the words a prediction would need are asserted absent. */
foreach ( array( 'on track', 'at risk', 'likely', 'won’t make', 'behind schedule' ) as $gwc_vt_dw_guess ) {
	gwc_vt_dw_check(
		'it does not predict: "' . $gwc_vt_dw_guess . '"',
		false === stripos( $gwc_vt_dw_html, $gwc_vt_dw_guess )
	);
}

echo "\n── 6. Who gets it at all ────────────────────────────────────────\n";

$gwc_vt_dw_contributor = wp_insert_user(
	array(
		'user_login' => 'zzdw_contributor',
		'user_pass'  => wp_generate_password( 20, true ),
		'role'       => 'contributor',
	)
);

if ( ! is_wp_error( $gwc_vt_dw_contributor ) ) {
	wp_set_current_user( (int) $gwc_vt_dw_contributor );

	gwc_vt_dw_check(
		'a contributor does not get the widget',
		! gwc_vt_can_see_dashboard_widget(),
		gwc_vt_can_see_dashboard_widget() ? 'somebody who cannot act on any of these lines was shown all of them' : ''
	);

	gwc_vt_dw_check(
		'and it draws nothing even if called directly',
		'' === trim( gwc_vt_dw_render() ),
		'' === trim( gwc_vt_dw_render() ) ? '' : 'the renderer does not check the capability itself'
	);

	wp_set_current_user( 1 );

	gwc_vt_dw_check(
		'an administrator does',
		gwc_vt_can_see_dashboard_widget()
	);

	/* And the case the link guard is actually for, which is NOT a contributor:
	 * contributors have edit_posts, so the schedule opens for them fine. The
	 * guard exists for somebody an administrator granted the verify capability
	 * to WITHOUT edit_posts — a subscriber, say — who can therefore read the
	 * widget and cannot open anything it points at. Built here rather than
	 * assumed, because assuming is how the first version of this check tested
	 * the wrong user and passed for the wrong reason. */
	$gwc_vt_dw_reader = wp_insert_user(
		array(
			'user_login' => 'zzdw_reader',
			'user_pass'  => wp_generate_password( 20, true ),
			'role'       => 'subscriber',
		)
	);

	if ( ! is_wp_error( $gwc_vt_dw_reader ) ) {
		$gwc_vt_dw_who = new WP_User( (int) $gwc_vt_dw_reader );
		$gwc_vt_dw_who->add_cap( GWC_VT_CAP_VERIFY );

		wp_set_current_user( (int) $gwc_vt_dw_reader );

		gwc_vt_dw_check(
			'that reader does get the widget',
			gwc_vt_can_see_dashboard_widget(),
			gwc_vt_can_see_dashboard_widget() ? '' : 'the fixture did not produce the case the guard is for'
		);

		gwc_vt_dw_check(
			'but cannot open the schedule',
			! current_user_can( 'edit_posts' )
		);

		gwc_vt_dw_check(
			'so the row is a name rather than a link to a 403',
			'' === gwc_vt_widget_shift_url( $gwc_vt_dw_shift ),
			gwc_vt_widget_shift_url( $gwc_vt_dw_shift )
		);

		ob_start();
		gwc_vt_render_dashboard_widget();
		$gwc_vt_dw_reader_html = (string) ob_get_clean();

		gwc_vt_dw_check(
			'and the widget still draws for them',
			'' !== trim( $gwc_vt_dw_reader_html ),
			'' !== trim( $gwc_vt_dw_reader_html ) ? '' : 'drew nothing'
		);

		wp_set_current_user( 1 );
		wp_delete_user( (int) $gwc_vt_dw_reader );
	}

	wp_set_current_user( 1 );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( (int) $gwc_vt_dw_contributor );
}

echo "\n── Clean up ─────────────────────────────────────────────────────\n";

gwc_vt_forget_widget_counts();

update_option( GWC_VT_SETTINGS_OPTION, $GLOBALS['gwc_vt_dw_opts'] );
gwc_vt_settings_cache( null, true );

foreach ( get_posts(
	array(
		'post_type'   => array( GWC_VT_VOLUNTEER_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_ENTRY_TYPE ),
		'post_status' => array_values( get_post_stati() ),
		'numberposts' => -1,
		's'           => 'Zzdw',
	)
) as $gwc_vt_dw_stray ) {
	$GLOBALS['gwc_vt_dw_made'][] = (int) $gwc_vt_dw_stray->ID;
}

foreach ( array_unique( $GLOBALS['gwc_vt_dw_made'] ) as $gwc_vt_dw_id ) {
	wp_delete_post( (int) $gwc_vt_dw_id, true );
}

echo "\n", ( 0 === $GLOBALS['gwc_vt_failures'] ? "ALL PASS\n" : $GLOBALS['gwc_vt_failures'] . " CHECK(S) FAILED\n" );

if ( $GLOBALS['gwc_vt_failures'] > 0 ) {
	exit( 1 );
}

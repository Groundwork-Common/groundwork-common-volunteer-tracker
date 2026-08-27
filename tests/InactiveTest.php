<?php
/**
 * Which surfaces an inactive volunteer disappears from, and which they do not.
 *
 * The whole feature is one status plus a decision, taken seven times, about
 * whether a given query is asking a question about work still to come or about
 * what already happened. That decision is what these tests hold: the status
 * itself is four lines and cannot really be wrong.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class InactiveTest extends TestCase {

	protected function setUp(): void {
		gwc_vt_test_reset();
	}

	/**
	 * Every file that queries volunteers, and the statuses it asks for.
	 *
	 * Read out of the source, because the failure this catches is somebody
	 * adding a query and copying the four-status literal that was right before
	 * this status existed.
	 *
	 * @return array<string, string[]>
	 */
	private function volunteer_queries(): array {
		$found = array();

		foreach ( (array) glob( GWC_VT_DIR . 'inc/*.php' ) as $file ) {
			$source = (string) file_get_contents( (string) $file );

			/* The assignment itself, not the words anywhere near it. A comment
			 * saying which of the two a query deliberately does NOT use names
			 * the function, and a looser search counts that comment as a query
			 * and reports the opposite of the truth. */
			preg_match_all(
				"/'post_status'\s*=>\s*(gwc_vt_volunteer_statuses\(\)|array\( 'publish', 'draft', 'pending', 'private' \))/",
				$source,
				$hits,
				PREG_OFFSET_CAPTURE
			);

			foreach ( $hits[1] as $hit ) {
				$window = substr( $source, max( 0, (int) $hit[1] - 700 ), 1400 );

				if ( false === strpos( $window, 'GWC_VT_VOLUNTEER_TYPE' ) ) {
					continue;
				}

				$found[ basename( (string) $file ) ][] = (string) $hit[0];
			}
		}

		return $found;
	}

	/**
	 * The parse found something, so the assertions below mean something.
	 */
	public function test_the_volunteer_queries_were_found(): void {
		$queries = $this->volunteer_queries();

		/* Four files decide this statically. The fifth, inc/rest.php, decides it
		 * per request and is covered on its own below. */
		$this->assertSame(
			array( 'admin-schedule.php', 'admin-triage.php', 'dashboard.php', 'privacy.php' ),
			array_keys( $queries ),
			'the set of files that query volunteers by status changed'
		);
	}

	/**
	 * The picker takes it as a parameter, and says no unless asked.
	 *
	 * One route feeds four pickers and they are not asking the same question. A
	 * roster offering somebody who has left puts them on a shift; a letter that
	 * cannot name them makes the record unfinishable. The default is the roster
	 * answer, because that is the direction where being wrong does damage.
	 */
	public function test_the_rest_picker_excludes_inactive_unless_asked(): void {
		$source = (string) file_get_contents( GWC_VT_DIR . 'inc/rest.php' );

		$this->assertStringContainsString(
			"'inactive'",
			$source,
			'the volunteers route stopped offering inactive as a parameter'
		);

		$this->assertStringContainsString(
			"'default'     => false",
			$source,
			'the volunteers route now offers inactive volunteers to every picker, including the rosters'
		);

		$this->assertStringContainsString(
			"\$request['inactive'] ? gwc_vt_volunteer_statuses()",
			$source,
			'the volunteers route no longer branches on the parameter'
		);
	}

	/**
	 * And exactly one picker asks for them: producing a letter.
	 */
	public function test_only_the_letter_picker_asks_for_inactive(): void {
		$asking = array();

		foreach ( (array) glob( GWC_VT_DIR . 'inc/*.php' ) as $file ) {
			if ( false !== strpos( (string) file_get_contents( (string) $file ), 'data-gwcvt-inactive' ) ) {
				$asking[] = basename( (string) $file );
			}
		}

		$this->assertSame( array( 'admin-letters.php' ), $asking );
	}

	/**
	 * An inactive volunteer is still a person the law can ask about.
	 *
	 * The retention sweep and the email lookup behind the exporter and the
	 * eraser all have to see them. A record the sweep cannot see is one it can
	 * never purge, which turns retiring into a way of accidentally keeping
	 * somebody's data forever.
	 */
	public function test_the_privacy_surfaces_see_inactive_volunteers(): void {
		$queries = $this->volunteer_queries();

		$this->assertNotEmpty( $queries['privacy.php'] ?? array(), 'privacy.php stopped querying volunteers' );

		foreach ( (array) ( $queries['privacy.php'] ?? array() ) as $shape ) {
			$this->assertSame(
				'gwc_vt_volunteer_statuses()',
				$shape,
				'a volunteer query in privacy.php cannot see inactive records, so the retention sweep and the eraser will both miss them'
			);
		}
	}

	/**
	 * Hours arrive after somebody leaves, and a letter is asked for later still.
	 */
	public function test_the_record_keeping_surfaces_see_them(): void {
		$queries = $this->volunteer_queries();

		foreach ( array( 'admin-triage.php', 'admin-schedule.php' ) as $file ) {
			$this->assertNotEmpty( $queries[ $file ] ?? array(), $file . ' stopped querying volunteers' );

			foreach ( (array) $queries[ $file ] as $shape ) {
				$this->assertSame(
					'gwc_vt_volunteer_statuses()',
					$shape,
					$file . ' cannot see inactive volunteers, so work they did before leaving cannot be attributed to them'
				);
			}
		}
	}

	/**
	 * And the one that must not.
	 *
	 * A nag about court-ordered hours, aimed at somebody who has stopped
	 * volunteering, is a worklist line nobody can ever clear.
	 */
	public function test_the_overdue_nag_does_not(): void {
		$queries = $this->volunteer_queries();

		$this->assertNotEmpty( $queries['dashboard.php'] ?? array(), 'dashboard.php stopped querying volunteers' );

		foreach ( (array) $queries['dashboard.php'] as $shape ) {
			$this->assertNotSame(
				'gwc_vt_volunteer_statuses()',
				$shape,
				'the overdue count now includes people who have left, and they can never come off it'
			);
		}
	}

	/* ── The views above the volunteer list ──────────────────────────────────
	 * One view per status is right for posts, where a draft and a published
	 * post are different kinds of thing. There is no such thing as a draft
	 * volunteer, so "Published (24)" beside "All (24)" is a filter that never
	 * narrows anything.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * What core would offer on this screen, before the filter sees it.
	 *
	 * @return array<string, string>
	 */
	private function core_views(): array {
		return array(
			'all'                      => '<a>All (24)</a>',
			'mine'                     => '<a>Mine (2)</a>',
			'publish'                  => '<a>Published (21)</a>',
			'future'                   => '<a>Scheduled (0)</a>',
			'draft'                    => '<a>Draft (1)</a>',
			'pending'                  => '<a>Pending (0)</a>',
			'private'                  => '<a>Private (0)</a>',
			'trash'                    => '<a>Trash (1)</a>',
			GWC_VT_VOLUNTEER_INACTIVE   => '<a>Inactive (3)</a>',
		);
	}

	public function test_the_core_status_views_are_suppressed(): void {
		$views = gwc_vt_volunteer_views( $this->core_views() );

		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $noise ) {
			$this->assertArrayNotHasKey( $noise, $views, $noise . ' is a filter that never narrows this list' );
		}
	}

	/**
	 * Trash is not noise. Deleting a volunteer is real and reversible, and
	 * somebody has to be able to find what they deleted.
	 */
	public function test_trash_survives(): void {
		$this->assertArrayHasKey( 'trash', gwc_vt_volunteer_views( $this->core_views() ) );
	}

	/**
	 * And what is left reads as the life it describes.
	 */
	public function test_the_strip_reads_as_a_lifecycle(): void {
		$this->assertSame(
			array( 'all', 'gwc_vt_active', GWC_VT_VOLUNTEER_INACTIVE, 'gwc_vt_applied', 'trash' ),
			array_keys( gwc_vt_volunteer_views( $this->core_views() ) ),
			'everybody, then the ones still coming, then the ones who stopped, then the ones asking to start, then the bin'
		);

		$this->assertArrayNotHasKey(
			'mine',
			gwc_vt_volunteer_views( $this->core_views() ),
			'nobody owns a volunteer; the author is whoever typed them in'
		);
	}

	/* ── Applied is a view of this screen, not a screen of its own ───────────
	 * Somebody who applies, is approved, volunteers and then stops is one
	 * person the whole way through. What is asserted here is that picking the
	 * view changes what the SAME list holds — and that it changes nothing
	 * anywhere else, because the records are still two post types and seven
	 * queries already include 'pending'.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_applied_view_points_at_this_same_screen(): void {
		$views = gwc_vt_volunteer_views( $this->core_views() );

		$this->assertStringContainsString(
			'post_type=' . GWC_VT_VOLUNTEER_TYPE,
			$views['gwc_vt_applied'],
			'Applied navigated away instead of filtering the list'
		);

		$this->assertStringContainsString( GWC_VT_LIST_VIEW . '=' . GWC_VT_VIEW_APPLIED, $views['gwc_vt_applied'] );
	}

	public function test_active_is_a_view_of_the_same_screen_too(): void {
		$views = gwc_vt_volunteer_views( $this->core_views() );

		$this->assertStringContainsString( 'post_type=' . GWC_VT_VOLUNTEER_TYPE, $views['gwc_vt_active'] );
		$this->assertStringContainsString( GWC_VT_LIST_VIEW . '=' . GWC_VT_VIEW_ACTIVE, $views['gwc_vt_active'] );
	}

	/**
	 * The columns follow what the view is holding.
	 *
	 * "Verified hours" and "Last shift" of somebody nobody has accepted yet are
	 * empty cells pretending to be information.
	 */
	public function test_the_applied_view_swaps_the_columns(): void {
		$people = array(
			'cb'              => '',
			'title'           => 'Title',
			'gwc_vt_verified' => 'Verified hours',
			'gwc_vt_last'     => 'Last shift',
		);

		$this->assertSame( $people, gwc_vt_volunteer_list_columns( $people ), 'the default view must keep its own columns' );

		$_GET[ GWC_VT_LIST_VIEW ] = GWC_VT_VIEW_APPLIED;

		$applied = gwc_vt_volunteer_list_columns( $people );

		$this->assertSame(
			array( 'cb', 'title', 'gwc_vt_said', 'gwc_vt_applied_on' ),
			array_keys( $applied )
		);

		$this->assertArrayNotHasKey( 'gwc_vt_verified', $applied );

		unset( $_GET[ GWC_VT_LIST_VIEW ] );
	}

	/**
	 * A view name this plugin does not know is not a view.
	 *
	 * The value reaches a query that changes which post type is listed, so
	 * anything not on the list has to mean "the default".
	 */
	public function test_an_unknown_view_is_ignored(): void {
		$_GET[ GWC_VT_LIST_VIEW ] = 'something-else';

		$this->assertSame( '', gwc_vt_list_view() );
		$this->assertFalse( gwc_vt_applied_view() );

		unset( $_GET[ GWC_VT_LIST_VIEW ] );
	}

	/* ── What a view does to the query ───────────────────────────────────────
	 * Asked of gwc_vt_volunteer_list_args() rather than of the hook, because
	 * the hook starts with is_admin() and would hand back an early return and a
	 * green result that proves nothing.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_the_default_view_changes_nothing(): void {
		$this->assertSame( array(), gwc_vt_volunteer_list_args( '', true ) );
	}

	public function test_the_active_view_narrows_to_published_volunteers(): void {
		$this->assertSame(
			array( 'post_status' => 'publish' ),
			gwc_vt_volunteer_list_args( GWC_VT_VIEW_ACTIVE, true )
		);
	}

	/**
	 * The one branch that changes which post type is listed.
	 */
	public function test_the_applied_view_lists_applications_oldest_first(): void {
		$args = gwc_vt_volunteer_list_args( GWC_VT_VIEW_APPLIED, true );

		$this->assertSame( GWC_VT_APPLICATION_TYPE, $args['post_type'] );
		$this->assertSame( 'pending', $args['post_status'] );
		$this->assertSame( 'ASC', $args['order'], 'the queue is for the person who has waited longest' );
	}

	/**
	 * And it is gated, because a query string is not a permission.
	 *
	 * An application is a stranger's name, email and phone number. Somebody who
	 * may not see other people's records must not get a list of them by typing
	 * a view into the address bar.
	 */
	public function test_the_applied_view_is_refused_without_the_capability(): void {
		$this->assertSame(
			array(),
			gwc_vt_volunteer_list_args( GWC_VT_VIEW_APPLIED, false ),
			'the applications view is reachable by anybody who can open the volunteer list'
		);
	}

	/**
	 * Active is not gated the same way, and does not need to be: it narrows a
	 * list of volunteers to a subset of the same list, and WordPress has
	 * already decided who may see that.
	 */
	public function test_the_active_view_needs_no_extra_capability(): void {
		$this->assertSame(
			array( 'post_status' => 'publish' ),
			gwc_vt_volunteer_list_args( GWC_VT_VIEW_ACTIVE, false )
		);
	}

	/**
	 * No two features may claim the same query var.
	 *
	 * This one is not hypothetical. The list views first used 'gwc_vt_view',
	 * which the dashboard's list-and-grid toggle already owned — and owns with
	 * a nonce on it, so every view link reached that handler, failed its
	 * check_admin_referer() and died with "The link you followed has expired."
	 * on a link that never needed a nonce. Nothing failed until somebody
	 * clicked one, because a query var collision is invisible to every test
	 * that does not make the request.
	 *
	 * Read out of the source, because the names are what collide.
	 */
	public function test_no_other_feature_claims_this_query_var(): void {
		$others = array();

		foreach ( (array) glob( GWC_VT_DIR . 'inc/*.php' ) as $file ) {
			if ( 'admin-volunteer-list.php' === basename( (string) $file ) ) {
				continue;
			}

			$source = (string) file_get_contents( (string) $file );

			/* Both spellings, because a collision does not care which one the
			 * other feature used: a literal in its $_GET[], or the name written
			 * anywhere it builds a link. */
			if ( false !== strpos( $source, "'" . GWC_VT_LIST_VIEW . "'" ) ) {
				$others[] = basename( (string) $file );
			}
		}

		$this->assertSame(
			array(),
			$others,
			'another file uses this query var, and whichever of them checks a nonce will kill the other one\'s links'
		);
	}

	/* ── Quick Edit and Bulk Edit are off this list ──────────────────────────
	 * What they offer is a post's furniture — slug, date, author, password,
	 * status — none of which means anything about a person, while the fields
	 * that ARE theirs are meta neither editor can touch.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * A volunteer row, as core would hand it over.
	 *
	 * @return array<string, string>
	 */
	private function core_row_actions(): array {
		return array(
			'edit'                 => '<a>Edit</a>',
			'inline hide-if-no-js' => '<button>Quick Edit</button>',
			'trash'                => '<a>Trash</a>',
		);
	}

	public function test_quick_edit_is_off_a_volunteer_row(): void {
		$actions = gwc_vt_no_quick_edit(
			$this->core_row_actions(),
			new WP_Post(
				array(
					'ID'        => 1,
					'post_type' => GWC_VT_VOLUNTEER_TYPE,
				)
			)
		);

		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $actions );
		$this->assertArrayHasKey( 'edit', $actions, 'opening the record is how you edit a volunteer' );
		$this->assertArrayHasKey( 'trash', $actions );
	}

	/**
	 * And only off this post type. The filter runs on every list in wp-admin.
	 */
	public function test_quick_edit_survives_on_everything_else(): void {
		$this->assertArrayHasKey(
			'inline hide-if-no-js',
			gwc_vt_no_quick_edit( $this->core_row_actions(), new WP_Post( array( 'post_type' => 'post' ) ) ),
			'a filter on post_row_actions sees every list in wp-admin, not just ours'
		);
	}

	/**
	 * And off every one of this plugin's types, not only the one it started on.
	 *
	 * A list rather than a loop over gwc_vt_post_types(): reading the expectation
	 * out of the function under test is how a type quietly dropped from that list
	 * would still pass. Adding a type means adding it here, deliberately.
	 */
	public function test_quick_edit_is_off_every_type_this_plugin_registers(): void {
		$types = array(
			GWC_VT_ENTRY_TYPE,
			GWC_VT_VOLUNTEER_TYPE,
			GWC_VT_SHIFT_TYPE,
			GWC_VT_SIGNUP_TYPE,
			GWC_VT_EVENT_TYPE,
			GWC_VT_LETTER_TYPE,
			GWC_VT_APPLICATION_TYPE,
			GWC_VT_CREDENTIAL_TYPE,
			GWC_VT_RECORD_TYPE,
		);

		foreach ( $types as $type ) {
			$this->assertArrayNotHasKey(
				'inline hide-if-no-js',
				gwc_vt_no_quick_edit( $this->core_row_actions(), new WP_Post( array( 'post_type' => $type ) ) ),
				$type . ' still offers Quick Edit'
			);
		}

		$this->assertSame(
			$types,
			gwc_vt_post_types(),
			'a type this plugin registers is a type these two suppressions have to cover'
		);
	}

	public function test_bulk_edit_is_off_the_dropdown_but_trash_is_not(): void {
		$actions = gwc_vt_no_bulk_edit(
			array(
				'edit'  => 'Edit',
				'trash' => 'Move to Trash',
			)
		);

		$this->assertArrayNotHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'trash', $actions, 'trashing several people at once is a real thing to want' );
	}

	/**
	 * Removing the link does not remove the endpoint.
	 *
	 * wp-admin/admin-ajax.php lists 'inline-save' among the actions it answers,
	 * whether or not anything links to it, so the server-side guard is what
	 * keeps the data right and this is only what makes the interface honest.
	 */
	public function test_the_status_guard_is_still_in_place(): void {
		$this->assertTrue(
			function_exists( 'gwc_vt_keep_volunteer_status' ),
			'quick edit is gone from the UI, but wp_ajax_inline_save still answers'
		);
	}

	/* ── How long they have been at it ───────────────────────────────────────
	 * Measured from their first logged shift rather than from the day somebody
	 * typed the record in, because those are different facts and only one of
	 * them is about volunteering.
	 * ─────────────────────────────────────────────────────────────────────── */

	/**
	 * @param string $first Earliest entry date.
	 * @param string $last  Latest entry date.
	 * @return GWC_VT_Totals
	 */
	private function totals( string $first = '', string $last = '' ): GWC_VT_Totals {
		return new GWC_VT_Totals( 600, 0, 2, $first, $last );
	}

	/**
	 * @return WP_Post
	 */
	private function volunteer(): WP_Post {
		return new WP_Post(
			array(
				'ID'        => 1,
				'post_type' => GWC_VT_VOLUNTEER_TYPE,
				'post_date' => '2024-03-01 09:00:00',
			)
		);
	}

	/**
	 * Somebody with no hours has no tenure to report, and says so rather than
	 * claiming the day a coordinator typed them in was the day they started.
	 */
	public function test_no_hours_reports_when_the_record_was_added(): void {
		$said = gwc_vt_volunteer_tenure( $this->volunteer(), $this->totals(), false );

		$this->assertStringContainsString( 'Added', $said );
		$this->assertStringNotContainsString( 'since', $said );
	}

	public function test_an_active_volunteer_is_measured_from_their_first_shift(): void {
		$said = gwc_vt_volunteer_tenure( $this->volunteer(), $this->totals( '2023-03-11', '2026-01-04' ), false );

		$this->assertStringContainsString( 'since', $said );
		$this->assertStringNotContainsString( 'Added', $said, 'the record date is not when they started volunteering' );
	}

	/**
	 * "Volunteering since 2019" about somebody who left in 2021 is not true.
	 */
	public function test_an_inactive_volunteer_gets_a_span_with_two_ends(): void {
		$said = gwc_vt_volunteer_tenure( $this->volunteer(), $this->totals( '2019-04-02', '2021-06-30' ), true );

		$this->assertStringContainsString( 'to', $said );
		$this->assertStringNotContainsString( 'since', $said );
	}

	/**
	 * A first shift dated in the future is a typo somebody will fix, and
	 * "volunteering for -3 days" is not the way to tell them.
	 */
	public function test_a_future_first_shift_reports_no_duration(): void {
		$ahead = gmdate( 'Y-m-d', time() + ( 40 * DAY_IN_SECONDS ) );

		$said = gwc_vt_volunteer_tenure( $this->volunteer(), $this->totals( $ahead, $ahead ), false );

		$this->assertStringContainsString( 'since', $said );
		$this->assertStringNotContainsString( '—', $said, 'no duration is claimed for a date that has not happened' );
	}

	/**
	 * A view a site added of its own is kept, at the end rather than dropped.
	 */
	public function test_somebody_elses_view_is_kept(): void {
		$views = $this->core_views();

		$views['acme_lapsed'] = '<a>Lapsed (4)</a>';

		$this->assertArrayHasKey( 'acme_lapsed', gwc_vt_volunteer_views( $views ) );
	}

	/**
	 * Suppressing a filter is not the same as refusing a status. A record that
	 * somehow ends up as a draft is still queried, counted and editable — it
	 * simply has no tab of its own.
	 */
	public function test_hiding_the_view_does_not_narrow_the_queries(): void {
		$this->assertContains( 'draft', gwc_vt_volunteer_statuses() );
		$this->assertContains( 'pending', gwc_vt_volunteer_statuses() );
	}

	/**
	 * The status list is the four that were there plus inactive, and nothing
	 * else — a status silently dropped from it disappears from six queries.
	 */
	public function test_the_status_list_is_what_it_was_plus_inactive(): void {
		$this->assertSame(
			array( 'publish', 'draft', 'pending', 'private', GWC_VT_VOLUNTEER_INACTIVE ),
			gwc_vt_volunteer_statuses()
		);
	}

	/**
	 * post_status is a varchar(20). A longer name is silently truncated on
	 * write and then matches nothing on read, which looks like the feature
	 * simply not working.
	 */
	public function test_the_status_name_fits_the_column(): void {
		$this->assertLessThanOrEqual( 20, strlen( GWC_VT_VOLUNTEER_INACTIVE ) );
	}

	/**
	 * Not 'draft', and the reason is the six queries that already name it.
	 */
	public function test_inactive_is_not_a_core_status(): void {
		$this->assertNotContains(
			GWC_VT_VOLUNTEER_INACTIVE,
			array( 'publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'future', 'inherit' ),
			'a core status is included by the four-status literal these queries already use, and its label is global'
		);
	}
}

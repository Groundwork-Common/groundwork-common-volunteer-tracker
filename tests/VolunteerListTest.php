<?php
/**
 * Filtering and sorting the volunteer list.
 *
 * ── Why this is worth a test file ────────────────────────────────────────────
 * The dashboard's worklist says "3 people are past their deadline — See who",
 * and until now that link was a bare edit.php?post_type=gwcvt_volunteer: every
 * volunteer the site has ever had, in no order. The screen naming a number was
 * the one screen that could not show you which three.
 *
 * Two rules make the fix safe rather than merely present, and both fail
 * silently if they are wrong:
 *
 *   - An empty overdue set must list nobody. WP_Query ignores an empty
 *     post__in, which would list EVERYONE under a filter announcing the
 *     opposite — the worst possible answer to "who is overdue".
 *   - Sorting by deadline must not drop the volunteers who have none. Ordering
 *     by a meta key is an INNER JOIN; without the EXISTS-or-NOT-EXISTS pair,
 *     everybody without a deadline vanishes from the list instead of sorting
 *     last. That trap is named in CLAUDE.md because it has already cost time.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class VolunteerListTest extends TestCase {

	protected function setUp(): void {
		gwcvt_test_reset();
	}

	/* ── The overdue filter ──────────────────────────────────────────────── */

	public function test_overdue_lists_exactly_the_ids_it_was_given(): void {
		$vars = gwcvt_volunteer_query_vars( 'overdue', '', 'ASC', array( 7, 9, 12 ) );

		$this->assertSame( array( 7, 9, 12 ), $vars['post__in'] );
	}

	public function test_NOBODY_OVERDUE_LISTS_NOBODY(): void {
		$vars = gwcvt_volunteer_query_vars( 'overdue', '', 'ASC', array() );

		/* Not array(): WP_Query drops an empty post__in and would then list every
		 * volunteer on the site under a filter saying these are the overdue
		 * ones. */
		$this->assertSame( array( 0 ), $vars['post__in'] );
		$this->assertNotSame( array(), $vars['post__in'] );
	}

	/* ── The other two states ────────────────────────────────────────────── */

	public function test_has_a_requirement_asks_for_a_positive_figure(): void {
		$vars = gwcvt_volunteer_query_vars( 'has' );

		$this->assertSame( GWCVT_VOLUNTEER_REQUIRED, $vars['meta_query'][0]['key'] );
		$this->assertSame( '>', $vars['meta_query'][0]['compare'] );
		$this->assertArrayNotHasKey( 'post__in', $vars );
	}

	/* "No requirement" has to cover both never-set and set-to-zero, or a record
	 * whose requirement was cleared disappears from both halves of the filter. */
	public function test_no_requirement_covers_unset_and_zero(): void {
		$vars = gwcvt_volunteer_query_vars( 'none' );

		$this->assertSame( 'OR', $vars['meta_query']['relation'] );
		$this->assertSame( 'NOT EXISTS', $vars['meta_query'][0]['compare'] );
		$this->assertSame( '<=', $vars['meta_query'][1]['compare'] );
	}

	public function test_no_filter_asks_for_nothing(): void {
		$this->assertSame( array(), gwcvt_volunteer_query_vars( '' ) );
	}

	public function test_an_unknown_state_is_ignored_rather_than_guessed(): void {
		$this->assertSame( array(), gwcvt_volunteer_query_vars( 'made-up' ) );
	}

	/* ── The deadline sort ───────────────────────────────────────────────── */

	public function test_SORTING_BY_DEADLINE_KEEPS_VOLUNTEERS_WITHOUT_ONE(): void {
		$vars = gwcvt_volunteer_query_vars( '', 'gwcvt_required', 'ASC' );

		$this->assertSame( 'OR', $vars['meta_query']['relation'] );

		$compares = array(
			$vars['meta_query']['gwcvt_deadline']['compare'],
			$vars['meta_query'][0]['compare'],
		);

		sort( $compares );

		// Both halves present, or the INNER JOIN silently drops rows.
		$this->assertSame( array( 'EXISTS', 'NOT EXISTS' ), $compares );
	}

	public function test_the_sort_is_by_the_named_clause_not_the_bare_key(): void {
		$vars = gwcvt_volunteer_query_vars( '', 'gwcvt_required', 'DESC' );

		$this->assertSame( 'DESC', $vars['orderby']['gwcvt_deadline'] );

		// A stable second key, so two identical deadlines do not shuffle.
		$this->assertSame( 'ASC', $vars['orderby']['title'] );
	}

	public function test_a_bad_order_falls_back_to_ascending(): void {
		$vars = gwcvt_volunteer_query_vars( '', 'gwcvt_required', 'sideways' );

		$this->assertSame( 'ASC', $vars['orderby']['gwcvt_deadline'] );
	}

	public function test_another_column_does_not_get_the_deadline_sort(): void {
		$vars = gwcvt_volunteer_query_vars( '', 'title', 'ASC' );

		$this->assertArrayNotHasKey( 'orderby', $vars );
	}

	/* Sorting is asked for on top of a filter, and the sort's meta_query is the
	 * one that survives — asserted so that nobody "fixes" it into a silent
	 * combination that drops rows. */
	public function test_the_sort_wins_over_a_filters_meta_query(): void {
		$vars = gwcvt_volunteer_query_vars( 'has', 'gwcvt_required', 'ASC' );

		$this->assertSame( 'OR', $vars['meta_query']['relation'] );
		$this->assertArrayHasKey( 'gwcvt_deadline', $vars['meta_query'] );
	}

	/* ── The filter's own options ────────────────────────────────────────── */

	public function test_every_offered_state_does_something_except_the_default(): void {
		$options = gwcvt_volunteer_filter_options();

		$this->assertArrayHasKey( '', $options );

		foreach ( array_keys( $options ) as $state ) {
			if ( '' === $state ) {
				continue;
			}

			$this->assertNotSame(
				array(),
				gwcvt_volunteer_query_vars( (string) $state, '', 'ASC', array( 1 ) ),
				'The "' . $state . '" option is offered but filters nothing.'
			);
		}
	}

	public function test_the_sortable_column_is_registered(): void {
		$this->assertArrayHasKey( 'gwcvt_required', gwcvt_volunteer_sortable_columns( array() ) );
	}
}

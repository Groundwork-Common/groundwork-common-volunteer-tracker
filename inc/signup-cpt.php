<?php
/**
 * The signup: one person on one shift.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_SIGNUP_TYPE = 'gwc_vt_signup';

/* Over the shift's maximum. Recorded rather than refused — see the note on
 * capacity in inc/signups.php. */
const GWC_VT_SIGNUP_WAITLIST = 'gwc_vt_waitlist';

/* The volunteer backed out, or a coordinator took them off. Kept rather than
 * trashed: "two people dropped" is something the coordinator needs to see when
 * they look at Saturday, and a trashed post is invisible on the way to being
 * deleted by core's own cleanup. */
const GWC_VT_SIGNUP_WITHDRAWN = 'gwc_vt_withdrawn';

/* Signup meta.
 *
 * These keys are deliberately NOT the entry's. '_gwc_vt_signup_volunteer' rather
 * than '_gwc_vt_volunteer', and the difference is load-bearing: inc/entries.php
 * invalidates a volunteer's cached totals from meta writes, matching on the key
 * name, so a signup sharing the entry's key would dirty and recompute the
 * rollup every time somebody signed up for something they had not yet worked. */
const GWC_VT_SIGNUP_VOLUNTEER   = '_gwc_vt_signup_volunteer';
const GWC_VT_SIGNUP_CLAIM_NAME  = '_gwc_vt_signup_claim_name';
const GWC_VT_SIGNUP_CLAIM_EMAIL = '_gwc_vt_signup_claim_email';
const GWC_VT_SIGNUP_SOURCE      = '_gwc_vt_signup_source';

/* When it was made, GMT. Shown to the coordinator as "signed up". */
const GWC_VT_SIGNUP_CREATED = '_gwc_vt_signup_created';

/* How many times this person has been put on this shift. Bumped every time the
 * signup is made again after a withdrawal, and part of the cancellation token —
 * which is what retires the link in the first email.
 *
 * A counter rather than the created timestamp, which is what this was first
 * written as. current_time( 'mysql' ) has one-second resolution, so withdrawing
 * and signing up again inside the same second left the old link working. That
 * is a double-submit away rather than a theoretical race, and tests/integration
 * /signups.php caught it. A token's lifetime should not depend on how fast
 * somebody clicks. */
const GWC_VT_SIGNUP_REVISION = '_gwc_vt_signup_revision';

/* Absent means the reminder has not gone out. Same shape as the attestation
 * timestamp, and it is what makes the reminder pass idempotent: a missed cron
 * run sends late rather than never or twice. */
const GWC_VT_SIGNUP_REMINDED = '_gwc_vt_signup_reminded_at';

/* The hour entry reconciliation produced, if any. Its absence on a reconciled
 * shift is how a no-show is derived — there is no stored no-show flag, because a
 * record of who does not turn up is a behavior file on people working off court
 * orders, and this plugin has no business keeping one. */
const GWC_VT_SIGNUP_ENTRY = '_gwc_vt_signup_entry';

add_action( 'init', 'gwc_vt_register_signup_type' );

/**
 * Register the signup type and its two statuses.
 *
 * ── Why a post rather than an array on the shift ─────────────────────────────
 * A list of names in one meta row is smaller and is wrong three ways. Two people
 * signing up at once read the same array and write it back, and one of them
 * vanishes — and capacity is precisely where that race lands. "Which shifts is
 * Jane on" becomes a scan of every shift rather than a query. And a signup has
 * facts of its own: when it was made, whether the reminder went, whether it was
 * withdrawn, which hour entry it became.
 *
 * post_parent is the shift, so the roster is a query with no join table, and
 * nothing dangles when a shift is deleted.
 *
 * ── Why the volunteer may be nobody ──────────────────────────────────────────
 * A signup from the public form stores the name and email as CLAIMS with the
 * volunteer left at '0', exactly as the self-log form does, and a human attaches
 * them later. The form never looks anybody up, so there is no code path whose
 * behavior depends on whether a person already exists, and therefore no oracle
 * to build one out of. See the box comment in inc/self-log.php.
 */
function gwc_vt_register_signup_type(): void {
	register_post_status(
		GWC_VT_SIGNUP_WAITLIST,
		array(
			'label'                     => _x( 'Waiting list', 'signup status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of signups on the waiting list. */
			'label_count'               => _n_noop( 'Waiting list <span class="count">(%s)</span>', 'Waiting list <span class="count">(%s)</span>', 'groundwork-common-volunteer-tracker' ),
		)
	);

	register_post_status(
		GWC_VT_SIGNUP_WITHDRAWN,
		array(
			'label'                     => _x( 'Withdrawn', 'signup status', 'groundwork-common-volunteer-tracker' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of withdrawn signups. */
			'label_count'               => _n_noop( 'Withdrawn <span class="count">(%s)</span>', 'Withdrawn <span class="count">(%s)</span>', 'groundwork-common-volunteer-tracker' ),
		)
	);

	$labels = array(
		'name'          => _x( 'Signups', 'post type general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name' => _x( 'Signup', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
	);

	$args = array(
		'labels'              => $labels,
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_nav_menus'   => false,
		'show_in_rest'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'exclude_from_search' => true,
		'supports'            => false,
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
	);

	/**
	 * Arguments for the signup post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	register_post_type( GWC_VT_SIGNUP_TYPE, apply_filters( 'gwc_vt_signup_post_type_args', $args ) );
}

/**
 * Who a signup is for, as a string to show a coordinator.
 *
 * An attached signup reads its name from the volunteer record, so correcting a
 * spelling in one place corrects it everywhere. An unattached one can only
 * report what the person typed, and says so.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_signup_name( int $signup_id ): string {
	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );

	if ( $volunteer_id > 0 ) {
		return (string) get_the_title( $volunteer_id );
	}

	$claimed = trim( (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true ) );

	if ( '' === $claimed ) {
		return __( 'Someone', 'groundwork-common-volunteer-tracker' );
	}

	return sprintf(
		/* translators: %s: the name somebody typed into the public form. */
		__( '%s (unmatched)', 'groundwork-common-volunteer-tracker' ),
		$claimed
	);
}

/**
 * The address to write to, if there is one.
 *
 * The volunteer record wins over the claim: a coordinator who corrected a typo
 * on the record should not have a reminder go to the address with the typo.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_signup_email( int $signup_id ): string {
	$volunteer_id = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );

	if ( $volunteer_id > 0 ) {
		$email = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true );

		if ( '' !== $email ) {
			return $email;
		}
	}

	return (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_EMAIL, true );
}

/**
 * Write a signup's derived title.
 *
 * @param int $signup_id Signup post ID.
 */
function gwc_vt_retitle_signup( int $signup_id ): void {
	$shift_id = (int) get_post_field( 'post_parent', $signup_id );

	wp_update_post(
		array(
			'ID'         => $signup_id,
			'post_title' => sprintf(
				/* translators: 1: a person's name, 2: a shift. */
				_x( '%1$s — %2$s', 'a signup, as its derived title', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_signup_name( $signup_id ),
				$shift_id > 0 ? get_the_title( $shift_id ) : __( 'a shift', 'groundwork-common-volunteer-tracker' )
			),
		)
	);
}

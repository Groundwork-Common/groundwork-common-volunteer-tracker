<?php
/**
 * A letter somebody means to send, before anybody has sent it.
 *
 * ── Why a draft is a record and not a screen ─────────────────────────────────
 * Producing a letter used to be a screen you went to, chose a person on, chose
 * dates on, and acted from in one sitting. Everything about it was in the URL
 * and nothing survived closing the tab. That is fine for the coordinator who
 * does it in one go, and it is the wrong shape for the way the job actually
 * arrives: a court asks in March, the hours are short until April, and the
 * person who will send it is not the person who was asked.
 *
 * So the intention is a record on the volunteer, and the letter is produced
 * from it whenever somebody is ready.
 *
 * ── What it holds, which is deliberately almost nothing ──────────────────────
 * The period, and that is all. Not the hours, not the shift list, not the
 * reference — because those are answers, and the whole of this plugin's
 * argument about letters is that the answers are computed from the entries at
 * the moment the letter is produced. A draft that stored 18h30m would be a
 * letter that disagrees with the record the day somebody verifies another
 * shift, and it would disagree silently.
 *
 * A draft is therefore a QUESTION — "this person, this period" — and every
 * screen that shows one recomputes the answer as it draws.
 *
 * ── Why a child of the volunteer ─────────────────────────────────────────────
 * The same reasoning as gwc_vt_cred_record, and the same consequence: a draft
 * is about a person, so it dies with them. Anonymizing or deleting a volunteer
 * takes their drafts, because "a letter was going to be written about somebody"
 * is a statement about that person and nothing in the organization's own record
 * needs it once they are gone.
 *
 * That is the opposite of the issued-letter log, which holds figures, a
 * reference and no name, and outlives the volunteer on purpose. The two look
 * alike and are opposites: one is an intention about a person, the other is a
 * receipt of the organization's own conduct.
 *
 * ── And why it goes away when the letter is issued ───────────────────────────
 * Issuing writes the log record, which holds the volunteer, the period, the
 * minutes and the reference — everything the draft held and more. Keeping the
 * draft as well would be two rows describing one letter, and the screen would
 * have to decide which of them is true the day they disagree.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** The post type. */
const GWC_VT_DRAFT_TYPE = 'gwc_vt_letter_draft';

/** The period this letter is meant to cover. Either may be empty. */
const GWC_VT_DRAFT_FROM = '_gwc_vt_draft_from';
const GWC_VT_DRAFT_TO   = '_gwc_vt_draft_to';

/** Who asked for it, and when — provenance, the same as a credential record. */
const GWC_VT_DRAFT_BY = '_gwc_vt_draft_by';

add_action( 'init', 'gwc_vt_register_letter_draft_type' );

/**
 * Register the type.
 *
 * Not public, not queryable, not in REST, no admin UI of its own — the family
 * rule, and here it is stricter than usual: a draft names a person and the fact
 * that somebody is being asked to prove their service. It is seen in one place,
 * the box on the volunteer's own record.
 */
function gwc_vt_register_letter_draft_type(): void {
	register_post_type(
		GWC_VT_DRAFT_TYPE,
		array(
			'labels'              => array(
				'name'          => _x( 'Letter drafts', 'post type general name', 'groundwork-common-volunteer-tracker' ),
				'singular_name' => _x( 'Letter draft', 'post type singular name', 'groundwork-common-volunteer-tracker' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'hierarchical'        => false,
			'capability_type'     => 'post',
			'supports'            => array( 'title' ),
		)
	);
}

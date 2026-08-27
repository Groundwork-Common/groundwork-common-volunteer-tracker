<?php
/**
 * What the screen says after you save one of this plugin's records.
 *
 * ── Why "Post updated." was appearing at all ─────────────────────────────────
 * post_updated_messages is keyed by post type and core only ships the keys it
 * knows about. A type that adds no entry of its own falls through to the 'post'
 * one, so saving a person said "Post updated." — a sentence about a kind of
 * thing this plugin does not have.
 *
 * ── Built from each type's own labels, not from a list here ──────────────────
 * The noun comes from singular_name, so a type that is renamed says the new
 * name without anybody remembering this file, and a type that gains an editor
 * later is covered by having one. A hand-written list would be a second place
 * for the names to live and a second place for them to go stale.
 *
 * ── No "View" links ─────────────────────────────────────────────────────────
 * Core's messages carry them, and every one of them would 404 here: every post
 * type in this plugin is public => false, deliberately and permanently — hard
 * rule 2 is about the weaker version of the same point. So the messages say
 * what happened and stop.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'post_updated_messages', 'gwc_vt_post_updated_messages' );
add_filter( 'bulk_post_updated_messages', 'gwc_vt_bulk_post_updated_messages', 10, 2 );

/**
 * The plugin's post types that somebody can actually open in an editor.
 *
 * The other seven are show_ui => false and have no screen to say anything on.
 * Asked of WordPress rather than listed, for the reason above.
 *
 * @return WP_Post_Type[] Keyed by post type name.
 */
function gwc_vt_editable_types(): array {
	$types = array();

	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		if ( 0 === strpos( (string) $type->name, 'gwc_vt_' ) && $type->show_ui ) {
			$types[ (string) $type->name ] = $type;
		}
	}

	return $types;
}

/**
 * Say the name of the thing that was saved.
 *
 * @param array<string, array<int, string|false>> $messages What core offers.
 * @return array<string, array<int, string|false>>
 */
function gwc_vt_post_updated_messages( $messages ) {
	if ( ! is_array( $messages ) ) {
		return $messages;
	}

	foreach ( gwc_vt_editable_types() as $name => $type ) {
		$noun = (string) $type->labels->singular_name;

		/* translators: %s: the name of a kind of record, such as "Volunteer". */
		$updated = sprintf( __( '%s updated.', 'groundwork-common-volunteer-tracker' ), $noun );

		/* translators: %s: the name of a kind of record, such as "Volunteer". */
		$saved = sprintf( __( '%s saved.', 'groundwork-common-volunteer-tracker' ), $noun );

		$messages[ $name ] = array(
			0  => '',
			1  => $updated,
			2  => __( 'Custom field updated.', 'groundwork-common-volunteer-tracker' ),
			3  => __( 'Custom field deleted.', 'groundwork-common-volunteer-tracker' ),
			4  => $updated,

			/* Core hands this one a revision id in the query string, and says
			 * false when there is not one so that nothing is printed. */
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading core's own redirect argument to pick a sentence.
			5  => isset( $_GET['revision'] )
				? sprintf(
					/* translators: 1: the name of a kind of record. 2: a date and time. */
					__( '%1$s restored to the revision from %2$s.', 'groundwork-common-volunteer-tracker' ),
					$noun,
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
					wp_post_revision_title( (int) $_GET['revision'], false )
				)
				: false,

			/* "Published" is core's word for a post going live. Nothing here
			 * goes live — these types are all public => false — so pressing the
			 * button on a new record has added it, not published it. */
			6  => sprintf(
				/* translators: %s: the name of a kind of record, such as "Volunteer". */
				__( '%s added.', 'groundwork-common-volunteer-tracker' ),
				$noun
			),
			7  => $saved,
			8  => $saved,

			/* Nor scheduled: there is no future date on which one of these
			 * appears anywhere. If a record somehow carries a future date, the
			 * honest thing to say is that it was saved. */
			9  => $saved,
			10 => $updated,
		);
	}

	return $messages;
}

/**
 * And the same for the list table, where several are dealt with at once.
 *
 * Bulk trash is the only bulk action left on the volunteer list, but core's
 * wording covers locking and deleting too and any of them can appear.
 *
 * @param array<string, string> $messages What core offers.
 * @param array<string, int>    $counts   How many of each thing happened.
 * @return array<string, string>
 */
function gwc_vt_bulk_post_updated_messages( $messages, $counts ) {
	if ( ! is_array( $messages ) ) {
		return $messages;
	}

	$counts = (array) $counts;

	foreach ( gwc_vt_editable_types() as $name => $type ) {
		$one  = (string) $type->labels->singular_name;
		$many = (string) $type->labels->name;

		/* _n() rather than picking between two sentences by hand: the count
		 * decides the noun here, but a language may need the rest of the
		 * sentence to change too, and only _n() gives a translator both forms
		 * of it. */
		$messages[ $name ] = array(
			'updated'   => gwc_vt_bulk_sentence(
				(int) ( $counts['updated'] ?? 0 ),
				/* translators: 1: how many. 2: the name of the records, such as "Volunteers". */
				_n_noop( '%1$s %2$s updated.', '%1$s %2$s updated.', 'groundwork-common-volunteer-tracker' ),
				$one,
				$many
			),
			'locked'    => gwc_vt_bulk_sentence(
				(int) ( $counts['locked'] ?? 0 ),
				/* translators: 1: how many. 2: the name of the records. */
				_n_noop( '%1$s %2$s not updated, somebody is editing it.', '%1$s %2$s not updated, somebody is editing them.', 'groundwork-common-volunteer-tracker' ),
				$one,
				$many
			),
			'deleted'   => gwc_vt_bulk_sentence(
				(int) ( $counts['deleted'] ?? 0 ),
				/* translators: 1: how many. 2: the name of the records. */
				_n_noop( '%1$s %2$s permanently deleted.', '%1$s %2$s permanently deleted.', 'groundwork-common-volunteer-tracker' ),
				$one,
				$many
			),
			'trashed'   => gwc_vt_bulk_sentence(
				(int) ( $counts['trashed'] ?? 0 ),
				/* translators: 1: how many. 2: the name of the records. */
				_n_noop( '%1$s %2$s moved to the trash.', '%1$s %2$s moved to the trash.', 'groundwork-common-volunteer-tracker' ),
				$one,
				$many
			),
			'untrashed' => gwc_vt_bulk_sentence(
				(int) ( $counts['untrashed'] ?? 0 ),
				/* translators: 1: how many. 2: the name of the records. */
				_n_noop( '%1$s %2$s restored from the trash.', '%1$s %2$s restored from the trash.', 'groundwork-common-volunteer-tracker' ),
				$one,
				$many
			),
		);
	}

	return $messages;
}

/**
 * One bulk sentence, with the right noun for the count.
 *
 * The number is formatted for the locale rather than printed raw, which is the
 * one thing core's own bulk messages do that is easy to lose when replacing
 * them.
 *
 * @param int    $count    How many.
 * @param array  $sentence A _n_noop() pair.
 * @param string $one      The record's name, singular.
 * @param string $many     The record's name, plural.
 * @return string
 */
function gwc_vt_bulk_sentence( int $count, array $sentence, string $one, string $many ): string {
	return sprintf(
		translate_nooped_plural( $sentence, $count, 'groundwork-common-volunteer-tracker' ),
		number_format_i18n( $count ),
		1 === $count ? $one : $many
	);
}

<?php
/**
 * Assembling a verification letter, and the code that makes it checkable.
 *
 * No output lives here. inc/render.php turns one of these into a document.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the letter for one volunteer over one period.
 *
 * ── Recomputed every time, never read from the rollup cache ──────────────────
 * inc/entries.php keeps a cached total on each volunteer, and this deliberately
 * ignores it. A letter is produced perhaps twice a year per volunteer and its
 * correctness is the entire product; two queries is not a price worth thinking
 * about. A cached number that is subtly stale is precisely the failure this
 * plugin cannot have, because the person holding the letter has no way to know.
 *
 * If somebody later "optimizes" this to use gwc_vt_volunteer_totals(), that is
 * the bug.
 *
 * @param int   $volunteer_id Volunteer post ID.
 * @param array $args         Optional. 'from' and 'to' as Y-m-d.
 * @return GWC_VT_Letter|null Null if there is no such volunteer.
 */
function gwc_vt_build_letter( int $volunteer_id, array $args = array() ) {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return null;
	}

	$from = (string) ( $args['from'] ?? '' );
	$to   = (string) ( $args['to'] ?? '' );

	$include_unverified = array_key_exists( 'include_unverified', $args )
		? (bool) $args['include_unverified']
		: (bool) gwc_vt_setting( 'letter_include_unverified' );

	$entry_ids = gwc_vt_entry_ids_for_volunteer(
		$volunteer_id,
		array(
			'from'     => $from,
			'to'       => $to,
			/* Published only. A pending entry is one nobody has accepted yet —
			 * self-logged and untriaged — and putting it on a letter would mean
			 * the organization reporting a claim it has not looked at. */
			'statuses' => array( 'publish' ),
		)
	);

	$rows       = array();
	$verified   = 0;
	$unverified = 0;

	foreach ( $entry_ids as $entry_id ) {
		$entry_id    = (int) $entry_id;
		$is_verified = gwc_vt_entry_is_verified( $entry_id );
		$minutes     = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );

		if ( $is_verified ) {
			$verified += $minutes;
		} else {
			/* Counted separately and never folded into the figure above, even
			 * when it is shown. The letter's claim is about attested hours; an
			 * unattested one appears marked as such or not at all. */
			$unverified += $minutes;

			if ( ! $include_unverified ) {
				continue;
			}
		}

		$rows[] = new GWC_VT_Letter_Entry(
			(string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true ),
			$minutes,
			(string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true ),
			(string) get_post_meta( $entry_id, GWC_VT_ENTRY_SUPERVISOR, true ),
			$is_verified,
			$is_verified ? gwc_vt_attestation_line( $entry_id ) : ''
		);
	}

	usort( $rows, static fn( GWC_VT_Letter_Entry $a, GWC_VT_Letter_Entry $b ): int => strcmp( $a->date, $b->date ) );

	$letter = new GWC_VT_Letter(
		$volunteer_id,
		(string) get_the_title( $volunteer_id ),
		$from,
		$to,
		$rows,
		$verified,
		$include_unverified ? $unverified : 0,
		$include_unverified,
		gwc_vt_letter_reference( $volunteer_id, $from, $to, $verified, $rows ),
		time()
	);

	/**
	 * The assembled letter, before it is rendered.
	 *
	 * @param GWC_VT_Letter $letter       The letter.
	 * @param int          $volunteer_id Volunteer post ID.
	 */
	return apply_filters( 'gwc_vt_letter_model', $letter, $volunteer_id );
}

/* ── The reference code ──────────────────────────────────────────────────────
 * What it is: PREFIX-<volunteer id>-<YYYYMMDD>-<8 hex>, where the hex is the
 * first eight characters of an HMAC over the facts the letter asserts —
 * volunteer, period, attested minutes, number of shifts listed.
 *
 * What it proves: that this document matches the organization's records as they
 * stand. Regenerating the same letter over unchanged records produces the same
 * code; a letter whose hours were edited in a word processor produces a
 * different one, and the verifier screen will say so.
 *
 * What it does NOT prove, and what the letter says out loud next to it: that
 * the hours were worked. The plugin cannot know that. It knows what was
 * recorded and who attested to it.
 *
 * Keyed with wp_salt() so the code cannot be forged by anybody without database
 * access — which also means codes do not survive a salt rotation. That is the
 * right trade: an unforgeable code that occasionally has to be re-issued beats
 * a checksum anyone can compute. The verifier reports a mismatch rather than
 * claiming forgery, so the failure mode is a phone call, not an accusation.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every fact the letter states about one shift.
 *
 * ── Why the digest covers the rows and not just the total ────────────────────
 * The first version hashed the volunteer, the range, the total minutes and the
 * number of shifts. That detects a letter whose TOTAL was altered, and nothing
 * else. It does not detect:
 *
 *   two shifts swapped, 3 h and 4 h becoming 4 h and 3 h — same total, same
 *   count, same code;
 *   a date moved within the range;
 *   an activity rewritten;
 *   a supervisor's name changed;
 *   a shift quietly unverified and another verified in its place.
 *
 * Every one of those is a change to what the document says, and every one used
 * to come back "matches our current records". A verifier that only checks the
 * total is a verifier that vouches for a letter somebody edited.
 *
 * So the fingerprint is every field the letter prints, per row, in the order it
 * prints them. The cost is that correcting a typo in an activity description
 * makes previously-issued letters report as changed — which is correct. The
 * document did change, and the screen shows both versions so a human can see
 * that it was a typo and not an alteration.
 *
 * @param GWC_VT_Letter_Entry[] $rows The shifts, in the order the letter lists them.
 * @return array
 */
function gwc_vt_letter_fingerprint( array $rows ): array {
	$fingerprint = array();

	foreach ( $rows as $row ) {
		$fingerprint[] = array(
			$row->date,
			$row->minutes,
			$row->activity,
			$row->supervisor,
			$row->verified ? 1 : 0,
		);
	}

	return $fingerprint;
}

/**
 * Mint a reference code.
 *
 * @param int                   $volunteer_id     Volunteer post ID.
 * @param string                $from             Y-m-d or ''.
 * @param string                $to               Y-m-d or ''.
 * @param int                   $verified_minutes Attested minutes.
 * @param GWC_VT_Letter_Entry[] $rows             The shifts the letter lists.
 * @return string
 */
function gwc_vt_letter_reference( int $volunteer_id, string $from, string $to, int $verified_minutes, array $rows ): string {
	$canonical = wp_json_encode(
		array(
			'volunteer' => $volunteer_id,
			'from'      => $from,
			'to'        => $to,
			'minutes'   => $verified_minutes,
			'entries'   => count( $rows ),
			'rows'      => gwc_vt_letter_fingerprint( $rows ),
		)
	);

	$digest = substr( hash_hmac( 'sha256', (string) $canonical, wp_salt( 'gwc_vt_letter' ) ), 0, 8 );

	$prefix = trim( (string) gwc_vt_setting( 'reference_prefix' ) );
	$prefix = '' !== $prefix ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ) . '-' : '';

	$code = sprintf(
		'%s%d-%s-%s',
		$prefix,
		$volunteer_id,
		gmdate( 'Ymd' ),
		strtoupper( $digest )
	);

	/**
	 * A letter's reference code.
	 *
	 * @param string $code         The code.
	 * @param int    $volunteer_id Volunteer post ID.
	 */
	return (string) apply_filters( 'gwc_vt_letter_reference', $code, $volunteer_id );
}

/**
 * Check a reference somebody has read out over the phone.
 *
 * Looks the code up in the issued-letter log, then recomputes from the records
 * as they stand now and compares. Three answers, and the wording of each
 * matters as much as the logic:
 *
 *   match    the document still matches the organization's records
 *   changed  the letter was issued, and the records have moved since
 *   unknown  no letter with that reference was ever issued from this site
 *
 * "changed" is deliberately not called "invalid" or "forged". Hours get
 * corrected, an entry gets verified after the letter went out, a duplicate is
 * removed — all of which are ordinary and none of which mean anybody did
 * anything wrong. The screen shows both figures and lets a human decide.
 *
 * @param string $code The reference code.
 * @return array{status:string, letter:array, current:array}
 */
function gwc_vt_verify_reference( string $code ): array {
	$code   = strtoupper( trim( $code ) );
	$record = gwc_vt_find_letter_record( $code );

	if ( ! $record ) {
		return array(
			'status'  => 'unknown',
			'letter'  => array(),
			'current' => array(),
			'rebuilt' => null,
		);
	}

	/* ── Recomputed from the records as they stand NOW ───────────────────────
	 * The figures on the log record are what the letter SAID. Recomputing the
	 * digest from those would compare the letter against itself and answer
	 * "matches" every single time, for every letter, no matter what happened to
	 * the hours afterwards — a verifier that always says yes, which is worse
	 * than no verifier at all because it actively vouches.
	 *
	 * What makes the answer mean anything is rebuilding the letter from the
	 * current entries over the same period and seeing whether it still produces
	 * the same digest. tests/integration/letter.php edits a record and asserts
	 * the code stops matching; that test is the only reason this is right.
	 * ─────────────────────────────────────────────────────────────────────── */
	$current = gwc_vt_build_letter(
		$record['volunteer_id'],
		array(
			'from' => $record['from'],
			'to'   => $record['to'],
		)
	);

	if ( ! $current instanceof GWC_VT_Letter ) {
		/* The volunteer has been purged or deleted. The letter was certainly
		 * issued — the log says so — but there is nothing left to check it
		 * against, and saying "matches" would be a claim about records that no
		 * longer exist. */
		return array(
			'status'  => 'changed',
			'letter'  => $record,
			'current' => array(),
			'rebuilt' => null,
		);
	}

	$expected = gwc_vt_letter_reference(
		$record['volunteer_id'],
		$record['from'],
		$record['to'],
		$current->verified_minutes,
		$current->entries
	);

	/* Compared on the digest alone. The code embeds the day it was issued, so a
	 * letter from last March can never recompute to a byte-identical code
	 * today — comparing whole codes would report every letter older than a day
	 * as changed. The digest is what carries the facts. */
	$status = gwc_vt_reference_digest( $expected ) === gwc_vt_reference_digest( $code ) ? 'match' : 'changed';

	return array(
		'status'  => $status,
		'letter'  => $record,
		'current' => array(
			'minutes' => $current->verified_minutes,
			'entries' => $current->entry_count(),
		),
		/* The rebuilt letter itself, so the screen can render it in full rather
		 * than summarizing it. A summary answers "do the totals agree"; the
		 * document answers "is this the same document", which is the question
		 * somebody holding a printed copy is actually asking. */
		'rebuilt' => $current,
	);
}

/**
 * The trailing digest of a reference code.
 *
 * @param string $code A reference code.
 * @return string
 */
function gwc_vt_reference_digest( string $code ): string {
	$parts = explode( '-', strtoupper( trim( $code ) ) );

	return (string) end( $parts );
}

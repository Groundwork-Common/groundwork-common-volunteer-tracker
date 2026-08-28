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
 * @param array $args         Optional. 'from' and 'to' as Y-m-d; 'entry_ids' to
 *                            build from named entries rather than from
 *                            everything the period matches; and
 *                            'verified_as_of', a GMT datetime, to count only
 *                            what had been attested to by then.
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

	/* Normally the period decides which entries are on the letter. An issued
	 * letter being rebuilt for a delivery names them instead, so that a print a
	 * week after issue is the letter that was issued rather than whatever the
	 * period matches by then. See gwc_vt_rebuild_issued_letter(). */
	$entry_ids = isset( $args['entry_ids'] )
		? array_map( 'intval', (array) $args['entry_ids'] )
		: gwc_vt_entry_ids_for_volunteer(
			$volunteer_id,
			array(
				'from'     => $from,
				'to'       => $to,
				/* Published only. A pending entry is one nobody has accepted yet
				 * — self-logged and untriaged — and putting it on a letter would
				 * mean the organization reporting a claim it has not looked at. */
				'statuses' => array( 'publish' ),
			)
		);

	/* ── As it stood at a fixed moment ───────────────────────────────────────
	 * A draft locks the answer to "who had we attested to by then", so that a
	 * letter states what the person who drafted it saw. Without it a draft made
	 * in March and issued in April silently states April's figures — which is
	 * the behaviour this replaced.
	 *
	 * A GMT datetime, compared against the entry's own GWC_VT_ENTRY_VERIFIED_AT,
	 * which is written in the same format by gwc_vt_verify_entry().
	 * ─────────────────────────────────────────────────────────────────────── */
	$as_of = (string) ( $args['verified_as_of'] ?? '' );

	$rows       = array();
	$used       = array();
	$verified   = 0;
	$unverified = 0;

	foreach ( $entry_ids as $entry_id ) {
		$entry_id = (int) $entry_id;

		/* Only when the caller named them: a period query cannot return an ID
		 * that is not an entry, but a stored list from a letter issued months
		 * ago certainly can. Skipping it here is what makes the rebuilt letter
		 * disagree with its own reference, which is the answer the delivery
		 * screen needs — see gwc_vt_rebuild_issued_letter(). */
		if ( isset( $args['entry_ids'] ) && GWC_VT_ENTRY_TYPE !== get_post_type( $entry_id ) ) {
			continue;
		}

		$is_verified = gwc_vt_entry_is_verified( $entry_id );

		if ( $is_verified && '' !== $as_of ) {
			$when = (string) get_post_meta( $entry_id, GWC_VT_ENTRY_VERIFIED_AT, true );

			/* An attestation with no time on it predates every draft there can
			 * be, because a draft cannot be older than the feature that records
			 * one. Counted as verified rather than dropped: the alternative
			 * silently takes real hours off a letter. */
			if ( '' !== $when && $when > $as_of ) {
				$is_verified = false;
			}
		}
		$minutes = (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true );

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

		$used[] = $entry_id;

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

	/* Which entries this was built from, so an issued letter can be rebuilt out
	 * of exactly the same ones rather than out of whatever the period matches
	 * later. Set after construction rather than through the constructor: every
	 * other caller of this class builds one by hand, and a tenth argument they
	 * do not have would break all of them for a value only the builder knows.
	 *
	 * IDs only, and that is the whole point — see the note beside
	 * GWC_VT_LETTER_ENTRY_IDS in inc/letter-cpt.php. */
	$letter->entry_ids      = array_map( 'intval', $used );
	$letter->verified_as_of = $as_of;
	$letter->addressee      = (string) ( $args['addressee'] ?? '' );
	$letter->matter         = (string) ( $args['matter'] ?? '' );

	/**
	 * The assembled letter, before it is rendered.
	 *
	 * @param GWC_VT_Letter $letter       The letter.
	 * @param int          $volunteer_id Volunteer post ID.
	 */
	return apply_filters( 'gwc_vt_letter_model', $letter, $volunteer_id );
}

/**
 * The date of the earliest shift a letter lists.
 *
 * The start of what a letter with no dates on it actually covers. Both the
 * document and the box on the volunteer's record name it — the document in its
 * period sentence, the box in its Period column — and they have to agree, so
 * they ask the same function rather than each running the same loop.
 *
 * @param GWC_VT_Letter $letter The letter.
 * @return string Y-m-d, or '' when it lists nothing.
 */
function gwc_vt_letter_earliest_date( GWC_VT_Letter $letter ): string {
	$earliest = '';

	foreach ( $letter->entries as $entry ) {
		if ( '' === $earliest || $entry->date < $earliest ) {
			$earliest = $entry->date;
		}
	}

	return $earliest;
}

/**
 * Rebuild the letter an issued record describes.
 *
 * ── What this is for, and the one thing it must not do ───────────────────────
 * Issuing and delivering are two acts now, so printing or emailing a letter
 * happens after its reference was minted — sometimes days after. The document
 * that goes out has to be the document that was issued, or the reference on it
 * digests facts the page does not state.
 *
 * So it is rebuilt from the entries the letter named, not from the period. The
 * period would pick up a shift verified since, and the page would quietly state
 * a bigger number under a code that says otherwise.
 *
 * What it must not do is *assert* that the rebuild is faithful. An entry can be
 * edited or deleted between issue and delivery, and then the honest answer is
 * that this is no longer the letter that was issued. That is exactly the
 * question gwc_vt_verify_reference() already answers, so this returns the
 * letter and leaves the judgement to the caller, which compares the digests.
 *
 * @param array $record From gwc_vt_letter_record().
 * @return GWC_VT_Letter|null Null when the volunteer is gone.
 */
function gwc_vt_rebuild_issued_letter( array $record ) {
	$volunteer_id = (int) ( $record['volunteer_id'] ?? 0 );
	$entry_ids    = (array) ( $record['entry_ids'] ?? array() );

	$args = array(
		'from'           => (string) ( $record['from'] ?? '' ),
		'to'             => (string) ( $record['to'] ?? '' ),
		'verified_as_of' => (string) ( $record['as_of'] ?? '' ),
		'addressee'      => (string) ( $record['addressee'] ?? '' ),
		'matter'         => (string) ( $record['matter'] ?? '' ),
	);

	/* A letter issued before the log stored entry IDs has none, and there is
	 * nothing to rebuild from but the period — which is what that letter was
	 * built from at the time, because issuing and printing were one act. */
	if ( $entry_ids ) {
		$args['entry_ids'] = $entry_ids;
	}

	return gwc_vt_build_letter( $volunteer_id, $args );
}

/**
 * Put the issued letter's own identity back onto a rebuild.
 *
 * ── Why this is separate, and must stay separate ─────────────────────────────
 * A rebuild comes out of gwc_vt_build_letter() carrying a freshly minted
 * reference and an issued_at of now, because that function has no idea it is
 * reproducing something rather than producing it. Both have to be replaced with
 * what the log says before the document is rendered, or a letter printed a week
 * after issue is dated the day it was printed and states a period ending that
 * day — while the log, the box and the letter the volunteer is holding all say
 * otherwise. Two documents, one reference, different dates.
 *
 * It is not done inside gwc_vt_rebuild_issued_letter() because
 * gwc_vt_rebuild_is_faithful() compares the rebuild's own reference against the
 * record's. Stamping first would compare the record against itself and answer
 * "faithful" every time, for every letter, however far the records had moved —
 * the same "verifier that always says yes" this plugin warns about beside
 * gwc_vt_verify_reference(), and worse here, because this one gates whether a
 * document may be sent to a court.
 *
 * So: rebuild, check, and only then stamp. The date is safe to stamp either
 * way — the digest does not cover it, which is precisely why the drift was
 * invisible until somebody asked which date it was.
 *
 * @param array         $record From gwc_vt_letter_record().
 * @param GWC_VT_Letter $letter The rebuild, already checked.
 */
function gwc_vt_stamp_issued_letter( array $record, GWC_VT_Letter $letter ): void {
	$letter->reference = (string) ( $record['reference'] ?? '' );

	$issued = (int) strtotime( (string) ( $record['issued_at_gmt'] ?? '' ) . ' GMT' );

	if ( $issued > 0 ) {
		$letter->issued_at = $issued;
	}
}

/**
 * Whether a rebuilt letter is still the one that was issued.
 *
 * Compared on the digest for the same reason gwc_vt_verify_reference() does:
 * the code embeds the day it was issued, so a letter from March can never
 * recompute to a byte-identical code today.
 *
 * @param array         $record From gwc_vt_letter_record().
 * @param GWC_VT_Letter $letter The rebuild.
 * @return bool
 */
function gwc_vt_rebuild_is_faithful( array $record, GWC_VT_Letter $letter ): bool {
	return gwc_vt_reference_digest( $letter->reference )
		=== gwc_vt_reference_digest( (string) ( $record['reference'] ?? '' ) );
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
 * access. That is the right trade: an unforgeable code beats a checksum anyone
 * can compute. The verifier reports a mismatch rather than claiming forgery, so
 * the failure mode is a phone call, not an accusation.
 *
 * ── Which secret this actually uses, and what rotating one does ──────────────
 * 'gwc_vt_letter' is not one of the four schemes wp_salt() knows, and the
 * branch an unknown scheme takes is worth stating plainly, because a letter
 * carries its code for years and the guess is easy to get backwards.
 *
 * An unknown scheme never reads AUTH_KEY, LOGGED_IN_KEY, NONCE_SALT or any of
 * their siblings. It takes SECRET_KEY if wp-config.php defines it, and
 * otherwise the 'secret_key' site option — a 64-character value WordPress
 * generates once and stores in the database. The salt half is then derived from
 * that key. Since the wordpress.org generator does not emit SECRET_KEY, the
 * option is what nearly every site ends up using.
 *
 * So rotating the eight constants in wp-config.php, which is the thing an
 * administrator is told to do after a breach and the thing that logs everybody
 * out, does NOT invalidate letters already in the post. Codes break when the
 * 'secret_key' option goes: a restore from a database that predates it, or a
 * migration onto a fresh install. Rarer than a salt rotation, and worth knowing
 * which is which when somebody rings to check a code and it does not match.
 *
 * Two properties this keeps either way: the code is a truncated HMAC, so it
 * discloses nothing about the key it was made with, and the key is a secret of
 * this site alone rather than anything the auth cookies are signed with.
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
 * What every reference code starts with.
 *
 * Its own function because two places need it and they must agree: the code a
 * letter is stamped with, and the example on the screen where somebody types
 * one back in. An example in the wrong shape is worse than none — it is read as
 * the shape, and the person retypes a code that was already right.
 *
 * Letters and numbers only, upper case, with the separator, or an empty string
 * when the site has set no prefix.
 *
 * @return string
 */
function gwc_vt_reference_prefix(): string {
	$prefix = trim( (string) gwc_vt_setting( 'reference_prefix' ) );

	return '' !== $prefix ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ) . '-' : '';
}

/**
 * A code in the shape this site issues them in.
 *
 * Built from the site's own prefix rather than written out, so the example on
 * screen matches the codes on the letters this organization sends. The rest is
 * plainly an example: a volunteer number, the day it was issued, and the digest
 * — the three parts somebody is reading off the page in front of them.
 *
 * @return string
 */
function gwc_vt_reference_example(): string {
	return gwc_vt_reference_prefix() . '1042-20260415-9A591C8E';
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

	$code = sprintf(
		'%s%d-%s-%s',
		gwc_vt_reference_prefix(),
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
	/* Rebuilt from the entries, over the same period, AS OF the moment this
	 * letter's figures were fixed to — which is what makes the answer mean
	 * something rather than being either circular or noisy.
	 *
	 * Not from the letter's own stored figures: that compares the letter against
	 * itself and answers "matches" every time, for every letter, which is worse
	 * than no verifier because it actively vouches.
	 *
	 * And not as things stand today either, which was the other failure and the
	 * quieter one. Every letter about somebody still volunteering reported
	 * "changed" as soon as their next shift was attested to — a warning firing
	 * on the ordinary case, which teaches whoever answers the phone to wave all
	 * of them through, including the one that matters.
	 *
	 * As of the fixed moment, a shift verified since is simply not part of the
	 * question. A shift the letter LISTS being edited still is, and still
	 * reports changed. tests/integration/letter.php edits a record and asserts
	 * the code stops matching; that test is the only reason this is right. */
	$current = gwc_vt_build_letter(
		$record['volunteer_id'],
		array(
			'from'           => $record['from'],
			'to'             => $record['to'],
			'verified_as_of' => (string) ( $record['as_of'] ?? '' ),
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

<?php
/**
 * The verification letter, as a value.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything a verification letter states, and nothing about how it looks.
 *
 * ── Why this one is an object, above all the others ──────────────────────────
 * This is the structure the whole plugin exists to produce. Fifteen values flow
 * from the query layer into the renderer, into the email, and into the audit
 * log, and if any of them is wrong the document is wrong in the hands of
 * somebody who cannot tell.
 *
 * As an array, "did minutes mean verified minutes or all minutes" is answered
 * by remembering a key name. As typed properties it is answered by the
 * property, and getting it wrong is a TypeError on the line that made the
 * mistake rather than a number on a letter a court is reading.
 *
 * Constructed in exactly one place — gwc_vt_build_letter() — so there is a
 * single path from records to document, and LetterTest asserts that no other
 * caller invokes the constructor.
 */
class GWC_VT_Letter {
 // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotSnakeCaseClassName -- WP core's own convention for class names.

	/**
	 * The volunteer this is about.
	 *
	 * @var int
	 */
	public int $volunteer_id = 0;

	/**
	 * Their name, as the letter prints it.
	 *
	 * @var string
	 */
	public string $volunteer_name = '';

	/**
	 * Start of the period covered, Y-m-d. Empty means "from the beginning".
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * End of the period covered, Y-m-d. Empty means "to today".
	 *
	 * @var string
	 */
	public string $to = '';

	/**
	 * The shifts, oldest first.
	 *
	 * @var GWC_VT_Letter_Entry[]
	 */
	public array $entries = array();

	/**
	 * Minutes a staff member has attested to. This is the figure the letter claims.
	 *
	 * @var int
	 */
	public int $verified_minutes = 0;

	/**
	 * Minutes shown but not attested to. Never added to the figure above.
	 *
	 * @var int
	 */
	public int $unverified_minutes = 0;

	/**
	 * Whether unattested shifts appear at all.
	 *
	 * @var bool
	 */
	public bool $includes_unverified = false;

	/**
	 * The reference code.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * When this letter was produced, as a Unix timestamp.
	 *
	 * @var int
	 */
	public int $issued_at = 0;

	/**
	 * Who the letter is addressed to, and what matter it concerns.
	 *
	 * Both optional and both blank by default. Addressing, not assertion: they
	 * say which correspondence this is, and nothing about the person or about
	 * what anybody required of them. That distinction is why a case number is
	 * allowed here while "40 hours ordered" is not — see the note beside them in
	 * inc/letter-draft-cpt.php.
	 *
	 * Deliberately outside the reference digest. A letter sent to two officers
	 * about one matter states the same service and is the same document; making
	 * the addressee part of the code would produce two codes for one set of
	 * facts, and the code is a statement about the facts.
	 *
	 * @var string
	 */
	public string $addressee = '';

	/**
	 * The matter it concerns — a case number, usually.
	 *
	 * @var string
	 */
	public string $matter = '';

	/**
	 * The moment the attestations on this letter were counted as of.
	 *
	 * A GMT datetime, or '' for a letter built from the record as it stands. Set
	 * by gwc_vt_build_letter() from its arguments, like entry_ids, and for the
	 * same reason: only the builder knows it.
	 *
	 * @var string
	 */
	public string $verified_as_of = '';

	/**
	 * The entry IDs this letter was built from.
	 *
	 * Not a constructor argument: it is set by gwc_vt_build_letter() after
	 * construction, and every letter built by hand — every fixture, every test —
	 * leaves it empty, which is correct, because those were built from nothing.
	 *
	 * @var int[]
	 */
	public array $entry_ids = array();

	/**
	 * Build the letter model.
	 *
	 * @param int    $volunteer_id        Volunteer post ID.
	 * @param string $volunteer_name      Display name.
	 * @param string $from                Y-m-d or ''.
	 * @param string $to                  Y-m-d or ''.
	 * @param array  $entries             GWC_VT_Letter_Entry objects.
	 * @param int    $verified_minutes    Attested minutes.
	 * @param int    $unverified_minutes  Unattested minutes shown.
	 * @param bool   $includes_unverified Whether unattested shifts are listed.
	 * @param string $reference           Reference code.
	 * @param int    $issued_at           Unix timestamp.
	 *
	 * @throws InvalidArgumentException If $entries holds anything that is not a
	 *                                  GWC_VT_Letter_Entry. The letter is the one
	 *                                  output here that has to be exact, so a
	 *                                  wrong-typed row fails loudly at
	 *                                  construction rather than rendering blank.
	 */
	public function __construct(
		int $volunteer_id,
		string $volunteer_name,
		string $from,
		string $to,
		array $entries,
		int $verified_minutes,
		int $unverified_minutes,
		bool $includes_unverified,
		string $reference,
		int $issued_at
	) {
		$this->volunteer_id        = $volunteer_id;
		$this->volunteer_name      = $volunteer_name;
		$this->from                = $from;
		$this->to                  = $to;
		$this->verified_minutes    = $verified_minutes;
		$this->unverified_minutes  = $unverified_minutes;
		$this->includes_unverified = $includes_unverified;
		$this->reference           = $reference;
		$this->issued_at           = $issued_at;

		/* Every element is type-checked rather than the array being taken on
		 * trust. A stray array in here would render as the word "Array" in a
		 * table cell on a document somebody hands to a probation officer. */
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof GWC_VT_Letter_Entry ) {
				throw new InvalidArgumentException( 'A letter entry must be a GWC_VT_Letter_Entry.' );
			}

			$this->entries[] = $entry;
		}
	}

	/**
	 * How many shifts are listed.
	 *
	 * @return int
	 */
	public function entry_count(): int {
		return count( $this->entries );
	}

	/**
	 * How many of them are attested.
	 *
	 * @return int
	 */
	public function verified_count(): int {
		$count = 0;

		foreach ( $this->entries as $entry ) {
			if ( $entry->verified ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Is there anything to report?
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->entry_count();
	}

	/**
	 * Has anything unattested been listed?
	 *
	 * @return bool
	 */
	public function has_unverified(): bool {
		return $this->verified_count() < $this->entry_count();
	}
}

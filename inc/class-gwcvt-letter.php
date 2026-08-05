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
 * Constructed in exactly one place — gwcvt_build_letter() — so there is a
 * single path from records to document, and LetterTest asserts that no other
 * caller invokes the constructor.
 */
class GWCVT_Letter { // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotSnakeCaseClassName -- WP core's own convention for class names.

	/** @var int The volunteer this is about. */
	public int $volunteer_id = 0;

	/** @var string Their name, as the letter prints it. */
	public string $volunteer_name = '';

	/** @var string Start of the period covered, Y-m-d. Empty means "from the beginning". */
	public string $from = '';

	/** @var string End of the period covered, Y-m-d. Empty means "to today". */
	public string $to = '';

	/** @var GWCVT_Letter_Entry[] The shifts, oldest first. */
	public array $entries = array();

	/** @var int Minutes a staff member has attested to. This is the figure the letter claims. */
	public int $verified_minutes = 0;

	/** @var int Minutes shown but not attested to. Never added to the figure above. */
	public int $unverified_minutes = 0;

	/** @var bool Whether unattested shifts appear at all. */
	public bool $includes_unverified = false;

	/** @var string The reference code. */
	public string $reference = '';

	/** @var int When this letter was produced, as a Unix timestamp. */
	public int $issued_at = 0;

	/**
	 * @param int    $volunteer_id        Volunteer post ID.
	 * @param string $volunteer_name      Display name.
	 * @param string $from                Y-m-d or ''.
	 * @param string $to                  Y-m-d or ''.
	 * @param array  $entries             GWCVT_Letter_Entry objects.
	 * @param int    $verified_minutes    Attested minutes.
	 * @param int    $unverified_minutes  Unattested minutes shown.
	 * @param bool   $includes_unverified Whether unattested shifts are listed.
	 * @param string $reference           Reference code.
	 * @param int    $issued_at           Unix timestamp.
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
			if ( ! $entry instanceof GWCVT_Letter_Entry ) {
				throw new InvalidArgumentException( 'A letter entry must be a GWCVT_Letter_Entry.' );
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

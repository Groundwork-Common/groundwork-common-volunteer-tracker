<?php
/**
 * A volunteer's hours, added up.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Why this one is an object ───────────────────────────────────────────────
 * The sibling plugins in this family have no classes at all, and that rule is
 * kept here for everything that is stored: the field schema, a field
 * definition, the settings — all arrays, because they are serialised into an
 * option and wrapping them would mean hydrating and dehydrating on every read
 * for no safety the defaults-merge does not already give.
 *
 * The line this plugin draws is: objects for computed values, arrays for
 * persisted config.
 *
 * A totals figure is computed. It flows from the query layer into a list-table
 * column, into the Letters screen's picker, and into the letter itself, and at
 * every step somebody has to know whether 'minutes' meant verified minutes or
 * all minutes. As an array that question is answered by remembering a key name.
 * As typed properties it is answered by the property, and getting it wrong is a
 * TypeError rather than a letter with the wrong number on it.
 *
 * PHP 7.4 is the floor, so this is a plain class with typed properties and an
 * explicit constructor — no promotion, no readonly, no enums. That is enough.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A volunteer's hours, added up.
 */
class GWC_VT_Totals {
 // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotSnakeCaseClassName -- WP core's own convention for class names is StudlyCaps with underscores.

	/**
	 * Minutes a staff member has attested to.
	 *
	 * @var int
	 */
	public int $verified_minutes = 0;

	/**
	 * Minutes logged but not yet attested to.
	 *
	 * @var int
	 */
	public int $pending_minutes = 0;

	/**
	 * How many entries went into this.
	 *
	 * @var int
	 */
	public int $entries = 0;

	/**
	 * Date of the earliest entry, Y-m-d, or '' if there are none.
	 *
	 * @var string
	 */
	public string $first = '';

	/**
	 * Date of the latest entry, Y-m-d, or '' if there are none.
	 *
	 * @var string
	 */
	public string $last = '';

	/**
	 * When this was computed, as a Unix timestamp.
	 *
	 * @var int
	 */
	public int $computed_at = 0;

	/**
	 * Build a totals figure.
	 *
	 * @param int    $verified_minutes Attested minutes.
	 * @param int    $pending_minutes  Unattested minutes.
	 * @param int    $entries          Number of entries.
	 * @param string $first            Earliest entry date, Y-m-d.
	 * @param string $last             Latest entry date, Y-m-d.
	 * @param int    $computed_at      Unix timestamp.
	 */
	public function __construct(
		int $verified_minutes = 0,
		int $pending_minutes = 0,
		int $entries = 0,
		string $first = '',
		string $last = '',
		int $computed_at = 0
	) {
		$this->verified_minutes = $verified_minutes;
		$this->pending_minutes  = $pending_minutes;
		$this->entries          = $entries;
		$this->first            = $first;
		$this->last             = $last;
		$this->computed_at      = $computed_at;
	}

	/**
	 * Everything logged, attested or not.
	 *
	 * Deliberately a method rather than a seventh stored property. A stored
	 * total is a third number that can disagree with the two it is derived
	 * from, and the one place that would show up is a letter.
	 *
	 * @return int
	 */
	public function total_minutes(): int {
		return $this->verified_minutes + $this->pending_minutes;
	}

	/**
	 * Is there anything here at all?
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->entries;
	}

	/**
	 * For storing in post meta.
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return array(
			'verified_minutes' => $this->verified_minutes,
			'pending_minutes'  => $this->pending_minutes,
			'entries'          => $this->entries,
			'first'            => $this->first,
			'last'             => $this->last,
			'computed_at'      => $this->computed_at,
		);
	}

	/**
	 * Rebuild from what was stored.
	 *
	 * Everything is cast rather than trusted. This value comes back out of post
	 * meta, which means it has been through serialisation, possibly a database
	 * migration, and possibly somebody's import script — and a string where an
	 * int belongs would otherwise be a TypeError on a page load rather than on
	 * the line that wrote it.
	 *
	 * @param mixed $stored Whatever came out of post meta.
	 * @return self
	 */
	public static function from_array( $stored ): self {
		$stored = is_array( $stored ) ? $stored : array();

		return new self(
			(int) ( $stored['verified_minutes'] ?? 0 ),
			(int) ( $stored['pending_minutes'] ?? 0 ),
			(int) ( $stored['entries'] ?? 0 ),
			(string) ( $stored['first'] ?? '' ),
			(string) ( $stored['last'] ?? '' ),
			(int) ( $stored['computed_at'] ?? 0 )
		);
	}
}

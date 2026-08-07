<?php
/**
 * One line of a verification letter.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single shift, as the letter states it.
 *
 * Built once by gwcvt_build_letter() and read by the renderer. Typed
 * properties rather than an array because these values are what a court reads:
 * a date in the minutes field or a duration in the activity field would render
 * without complaint and be wrong on a document somebody is relying on.
 *
 * See the box comment in inc/class-gwcvt-totals.php for the rule about which
 * things in this plugin are objects and which stay arrays.
 */
class GWCVT_Letter_Entry {
 // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotSnakeCaseClassName -- WP core's own convention for class names.

	/**
	 * Date of the shift, Y-m-d.
	 *
	 * @var string
	 */
	public string $date = '';

	/**
	 * Duration in minutes. Never a float — see inc/settings.php.
	 *
	 * @var int
	 */
	public int $minutes = 0;

	/**
	 * What was done.
	 *
	 * @var string
	 */
	public string $activity = '';

	/**
	 * Who supervised it.
	 *
	 * @var string
	 */
	public string $supervisor = '';

	/**
	 * Whether a staff member has attested to it.
	 *
	 * @var bool
	 */
	public bool $verified = false;

	/**
	 * The attestation, spelled out. Empty when unverified.
	 *
	 * @var string
	 */
	public string $attestation = '';

	/**
	 * Build one line of a letter.
	 *
	 * @param string $date        Y-m-d.
	 * @param int    $minutes     Duration in minutes.
	 * @param string $activity    What was done.
	 * @param string $supervisor  Who supervised.
	 * @param bool   $verified    Whether it is attested.
	 * @param string $attestation The attestation line.
	 */
	public function __construct(
		string $date,
		int $minutes,
		string $activity = '',
		string $supervisor = '',
		bool $verified = false,
		string $attestation = ''
	) {
		$this->date        = $date;
		$this->minutes     = $minutes;
		$this->activity    = $activity;
		$this->supervisor  = $supervisor;
		$this->verified    = $verified;
		$this->attestation = $attestation;
	}
}

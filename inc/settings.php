<?php
/**
 * Plugin settings, and the two functions everything else measures time with.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWCVT_SETTINGS_OPTION = 'gwcvt_settings';

/* ── Minutes, not hours ──────────────────────────────────────────────────────
 * Every duration in this plugin is an integer number of minutes. Three and a
 * half hours is 210. Nothing anywhere stores 3.5.
 *
 * This is not fussiness. Floats do not sum exactly, and the sum is the entire
 * product: a letter that reads "42.30000000000001 hours" is not a rounding
 * curiosity a court will overlook, it is the moment the reader stops believing
 * the document. Integers also sort correctly in a meta query, which floats
 * stored as strings do not.
 *
 * So there are exactly two functions that convert, both here, and every other
 * file calls them rather than doing arithmetic of its own.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The longest a single entry may be, in minutes. See gwcvt_parse_hours(). */
const GWCVT_MAX_ENTRY_MINUTES = 1440;

/**
 * Every setting, with the value a fresh install behaves as.
 *
 * Several default to '' or 0 meaning "derive it" rather than to a concrete
 * value. That is deliberate: the site's name and admin email are already
 * recorded in WordPress, and copying them into our option at activation would
 * fork them the first time somebody changed the original.
 *
 * @return array
 */
function gwcvt_setting_defaults(): array {
	return array(

		/* ── The letter ──────────────────────────────────────────────────── */

		/* Empty means "use the site title". Same for the contact line and the
		 * admin email — see the note above about forking values WordPress
		 * already holds. */
		'org_name'                  => '',
		'org_address'               => '',
		'org_contact'               => '',
		'org_logo'                  => 0,

		/* Who signs. Empty is honest: the letter renders a ruled line with no
		 * name under it, which is visibly unfinished, which is the correct
		 * prompt to go and fill this in before sending one to a court. */
		'signatory_name'            => '',
		'signatory_title'           => '',

		/* Empty means "use the built-in wording" for all three. The disclaimer
		 * and the reference note additionally cannot be *saved* empty — see
		 * gwcvt_disclaimer() and the box comment in inc/i18n.php. */
		'letter_intro'              => '',
		'letter_disclaimer'         => '',
		'letter_reference_note'     => '',

		/* Prefixed onto every reference code. Empty gives a bare code, which is
		 * fine; orgs issuing letters for more than one programme tend to want
		 * one. */
		'reference_prefix'          => '',

		/* On. A total with no itemisation is a number the reader has to take on
		 * faith, and taking it on faith is exactly what this document is trying
		 * not to require. */
		'letter_itemize'            => true,

		/* Off. An unverified entry is one no staff member has attested to, and
		 * putting it on a letter by default would mean the plugin vouching for
		 * something nobody vouched for. Turned on, unverified entries appear
		 * marked as such and are excluded from the verified total — they are
		 * never silently folded in. */
		'letter_include_unverified' => false,

		'email_subject'             => '',
		'email_intro'               => '',

		/* ── Logging ─────────────────────────────────────────────────────── */

		/* Round every entry to the nearest quarter hour. This is what paper
		 * timesheets do, it is what supervisors estimate to anyway, and it stops
		 * a letter reading "41.9833 hours". Zero switches rounding off. */
		'hour_increment'            => 15,
		'hour_format'               => 'decimal',

		/* Off. An hour entry dated next Tuesday is a typo far more often than it
		 * is a plan, and on a document a court reads it is the kind of error
		 * that discredits the whole record. */
		'allow_future_dates'        => false,

		/* Empty means the activity field is free text. A site that wants a fixed
		 * vocabulary sets one; shipping one would mean guessing whether this
		 * install is a food bank or a thrift store. */
		'activities'                => '',

		/* ── The self-log form ───────────────────────────────────────────── */

		/* Off, and this is the single most important default in the file. On,
		 * this plugin accepts personal information from anonymous visitors. That
		 * should never happen because somebody installed a plugin — only because
		 * somebody decided it should. */
		'self_log_enabled'          => false,

		/* Pinned by ID rather than by slug, so renaming the page cannot quietly
		 * break the form's dispatch. */
		'self_log_page'             => 0,

		/* A shared code the form asks for, if set. Empty by default. Not
		 * security — it is the difference between a form the world can post to
		 * and one only people who were handed a card at the front desk can. */
		'self_log_code'             => '',

		/* ── The schedule ────────────────────────────────────────────────── */

		/* Off. Scheduling is a second product surface — a menu item, a set of
		 * screens, and eventually mail leaving the site — and none of that should
		 * appear because somebody updated a plugin they installed to log hours. An
		 * organisation that takes signups on the phone and a clipboard is not
		 * doing it wrong, and this stays out of their way until they say so. */
		'shifts_enabled'            => false,

		/* Empty means the location field is free text, exactly as 'activities'
		 * above does for what the work is. Shipping a list would mean guessing
		 * whether this install has one warehouse or eleven pantries. */
		'shift_locations'           => '',

		/* How long before a shift starts signups close, in hours. Zero means they
		 * stay open until it begins — which is right for a food bank where
		 * somebody turning up an hour early is a good day, and wrong for anything
		 * that has to print a list the night before. */
		'signup_cutoff_hours'       => 0,

		/* ── Signing up from the front end ────────────────────────────────────
		 * A second switch rather than a mode of the first, because "we plan
		 * shifts internally" and "strangers can put their name on one" are
		 * different decisions with different consequences, and an organisation
		 * that wants the first should not have to accept the second to get it.
		 *
		 * Off, for the same reason self_log_enabled is off: with this on, the
		 * site accepts a name and an email address from anonymous visitors. That
		 * should never begin because somebody updated a plugin. */
		'signup_enabled'            => false,

		/* Pinned by ID, so renaming the page cannot quietly break the handler.
		 * Enabled without a page pinned does not count as enabled — see
		 * gwcvt_signups_open(). */
		'schedule_page'             => 0,

		/* A shared code, if the site wants one. Not a security control; it is the
		 * difference between a form the whole internet can post to and one only
		 * people who were handed a card will bother with. */
		'signup_code'               => '',

		/* How far ahead the public list looks. Long enough to plan around, short
		 * enough that a year of Saturdays is not a wall of text. */
		'signup_horizon_days'       => 60,

		/* ── Reminders and the digest ─────────────────────────────────────────
		 * Both off, and both are mail this site would send on a schedule with
		 * nobody watching. The confirmation in 0.10.0 is not a setting because a
		 * signup without one is broken; these two are useful rather than
		 * structural, so they are a decision.
		 *
		 * A reminder is the single biggest lever anybody has on no-shows, and
		 * two days is the interval that leaves time to find a replacement. */
		'reminder_enabled'          => false,
		'reminder_lead_hours'       => 48,

		/* One message a day to one person, and only when there is something to
		 * say. Empty means the site's admin address — the same "derive it rather
		 * than fork it" rule as org_contact above. */
		'digest_enabled'            => false,
		'digest_recipient'          => '',

		/* ── Privacy ─────────────────────────────────────────────────────── */

		/* Zero means keep indefinitely, and it stays zero until somebody
		 * chooses. Deleting by default destroys the record a volunteer needs for
		 * a court date they have not reached yet; keeping by default hoards
		 * personal data. The resolution is not a cleverer default — it is
		 * 'retention_decided' below, and a notice that does not go away until
		 * the question has actually been answered. */
		'retention_months'          => 0,
		'retention_action'          => 'anonymize',
		'retention_anchor'          => 'last_entry',

		/* Set when the Privacy tab is saved — including when it is saved with a
		 * retention period of zero. "Keep everything forever" is a legitimate
		 * answer; never having considered the question is not. */
		'retention_decided'         => false,

		/* ── Advanced ────────────────────────────────────────────────────── */

		// Empty means "use what WordPress would have used anyway".
		'from_name'                 => '',
		'from_email'                => '',
	);
}

/**
 * The per-request settings memo.
 *
 * Its own function rather than a static inside gwcvt_setting(), because a
 * writer needs a way to invalidate a reader's cache and PHP has no way to reach
 * another function's static variable. Without this, a script that calls
 * update_option() and then reads gwcvt_setting() in the same request — a
 * migration, WP-CLI, another plugin — would silently see the value from before
 * the write.
 *
 * @param array|null $set   Value to store.
 * @param bool       $clear Forget the cached value.
 * @return array|null
 */
function gwcvt_settings_cache( ?array $set = null, bool $clear = false ): ?array {
	static $cache = null;
	if ( $clear ) {
		$cache = null;
		return null;
	}
	if ( null !== $set ) {
		$cache = $set;
	}
	return $cache;
}

add_action( 'update_option_' . GWCVT_SETTINGS_OPTION, 'gwcvt_reset_settings_cache' );
add_action( 'add_option_' . GWCVT_SETTINGS_OPTION, 'gwcvt_reset_settings_cache' );

/**
 * Clear the settings memo. Hooked to both add_option_* and update_option_* —
 * WordPress fires the former only on an option's first write and the latter on
 * every write after, so a site's very first Settings save needs the same
 * invalidation as every one after it.
 */
function gwcvt_reset_settings_cache(): void {
	gwcvt_settings_cache( null, true );
}

/**
 * Read one setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function gwcvt_setting( string $key ) {
	$settings = gwcvt_settings_cache();
	if ( null === $settings ) {
		$stored   = get_option( GWCVT_SETTINGS_OPTION );
		$settings = gwcvt_settings_cache(
			array_merge( gwcvt_setting_defaults(), is_array( $stored ) ? $stored : array() )
		);
	}
	return $settings[ $key ] ?? null;
}

/**
 * The organisation's name as the letter should print it.
 *
 * @return string
 */
function gwcvt_org_name(): string {
	$name = trim( (string) gwcvt_setting( 'org_name' ) );
	return '' !== $name ? $name : (string) get_bloginfo( 'name' );
}

/**
 * The disclaimer, which is never empty.
 *
 * The setting is fully editable and the built-in wording is only a default —
 * but a letter with no disclaimer on it is a letter that has quietly started
 * implying the plugin certified something. So an empty stored value reads as
 * "use the default" rather than as "print nothing", and the Letter tab refuses
 * to save one. Both halves are needed: without this fallback, a value emptied
 * by a direct option write or a bad import would strip it silently.
 *
 * @return string
 */
function gwcvt_disclaimer(): string {
	$text = trim( (string) gwcvt_setting( 'letter_disclaimer' ) );
	return '' !== $text ? $text : gwcvt_default_disclaimer();
}

/**
 * The note explaining what the reference code proves. Never empty, same reason.
 *
 * @return string
 */
function gwcvt_reference_note(): string {
	$text = trim( (string) gwcvt_setting( 'letter_reference_note' ) );
	return '' !== $text ? $text : gwcvt_default_reference_note();
}

/**
 * The site's timezone.
 *
 * Wrapped so that every date decision in the plugin goes through one place. An
 * hour entry is a calendar date with no instant attached — a shift on 4 March
 * is on 4 March whatever the server thinks the time is — so "today" has to mean
 * today where the organisation is, not today in UTC.
 *
 * @return DateTimeZone
 */
function gwcvt_timezone(): DateTimeZone {
	return wp_timezone();
}

/**
 * Today's date in the site's timezone, as Y-m-d.
 *
 * @return string
 */
function gwcvt_today(): string {
	return (string) current_time( 'Y-m-d' );
}

/**
 * The rounding increment, in minutes. Zero means no rounding.
 *
 * @return int
 */
function gwcvt_hour_increment(): int {
	$minutes = (int) gwcvt_setting( 'hour_increment' );

	/**
	 * The increment every logged duration is rounded to, in minutes.
	 *
	 * @param int $minutes Increment in minutes; 0 disables rounding.
	 */
	$minutes = (int) apply_filters( 'gwcvt_hour_increment', $minutes );

	return max( 0, $minutes );
}

/* ── Parsing what a human typed ──────────────────────────────────────────────
 * Volunteer coordinators do not type a canonical format, and telling them off
 * with a validation error is a worse product than accepting the five things
 * they actually type. So: 3.5, 3:30, "3h 30m", 210m, and a bare 3.
 *
 * The one deliberate refusal is a large bare number. "210" means two hundred
 * and ten HOURS by the rule that a bare number is hours, which is a shift
 * nobody worked — it is somebody typing minutes into a field expecting minutes.
 * Rather than silently record a quarter of a year, anything over
 * GWCVT_MAX_ENTRY_MINUTES is refused so the form can say so and the person can
 * write 210m. An entry is one shift; a shift is not longer than a day.
 *
 * Rejected alternative: guessing that a bare number above some threshold "must
 * be" minutes. It works right up until the volunteer who really did log 30
 * hours over a weekend retreat, and then it is wrong in a way nobody can see.
 * Refusing and asking is the only honest option.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Parse a typed duration into minutes.
 *
 * @param string $raw What the user typed.
 * @return int|null Minutes, or null if it could not be read as a duration.
 */
function gwcvt_parse_hours( string $raw ): ?int {
	$value = strtolower( trim( $raw ) );
	$value = str_replace( ',', '', $value );

	if ( '' === $value ) {
		return null;
	}

	$minutes = null;

	if ( preg_match( '/^(\d+):([0-5]?\d)$/', $value, $m ) ) {
		// 3:30
		$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];

	} elseif ( preg_match( '/^(?:(\d+(?:\.\d+)?)\s*h(?:(?:ou)?rs?)?)?\s*(?:(\d+(?:\.\d+)?)\s*m(?:in(?:ute)?s?)?)?$/', $value, $m ) && ( '' !== ( $m[1] ?? '' ) || '' !== ( $m[2] ?? '' ) ) ) {
		// 3h 30m, 3h, 30m, 210m
		$minutes = ( (float) ( $m[1] ?? 0 ) * 60 ) + (float) ( $m[2] ?? 0 );

	} elseif ( preg_match( '/^\d*\.?\d+$/', $value ) ) {
		// A bare number is hours. 3, 3.5, .5
		$minutes = (float) $value * 60;
	}

	if ( null === $minutes ) {
		return null;
	}

	$minutes = (int) round( $minutes );

	if ( $minutes < 0 || $minutes > GWCVT_MAX_ENTRY_MINUTES ) {
		return null;
	}

	return gwcvt_round_minutes( $minutes );
}

/**
 * Round to the configured increment, to the nearest rather than up.
 *
 * Nearest, because rounding up is the organisation systematically crediting
 * hours nobody worked, and on this document that is the one direction of error
 * that matters.
 *
 * @param int $minutes Minutes.
 * @return int
 */
function gwcvt_round_minutes( int $minutes ): int {
	$increment = gwcvt_hour_increment();

	if ( $increment < 1 ) {
		return $minutes;
	}

	return (int) ( round( $minutes / $increment ) * $increment );
}

/**
 * Format minutes for display.
 *
 * @param int         $minutes Minutes.
 * @param string|null $format  'decimal', 'hm', or null for the site's setting.
 * @return string
 */
function gwcvt_format_hours( int $minutes, ?string $format = null ): string {
	$format = $format ?? (string) gwcvt_setting( 'hour_format' );

	if ( 'hm' === $format ) {
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;

		if ( 0 === $hours ) {
			/* translators: %s: a number of minutes. */
			return sprintf( __( '%sm', 'groundwork-common-volunteer-tracker' ), number_format_i18n( $rest ) );
		}

		if ( 0 === $rest ) {
			/* translators: %s: a number of hours. */
			return sprintf( __( '%sh', 'groundwork-common-volunteer-tracker' ), number_format_i18n( $hours ) );
		}

		/* translators: 1: a number of hours, 2: a number of minutes. */
		return sprintf( __( '%1$sh %2$sm', 'groundwork-common-volunteer-tracker' ), number_format_i18n( $hours ), number_format_i18n( $rest ) );
	}

	/* Decimal, trimmed. 210 reads as "3.5" and 180 as "3" — a letter that says
	 * "3.00 hours" looks machine-generated in a way that invites doubt. */
	$decimal = round( $minutes / 60, 2 );

	if ( (float) (int) $decimal === $decimal ) {
		$places = 0;
	} elseif ( round( $decimal, 1 ) === $decimal ) {
		$places = 1;
	} else {
		$places = 2;
	}

	return number_format_i18n( $decimal, $places );
}

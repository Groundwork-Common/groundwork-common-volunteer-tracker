<?php
/**
 * Translated lookup tables, and the sentence the whole plugin rests on.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Why these are functions and not constants ───────────────────────────────
 * Every table in this file is a function with a static memo rather than a
 * `const` array, and the reason is load order rather than taste.
 *
 * A `const` is evaluated when the file is included, which for a plugin is
 * `plugins_loaded` at the latest — before `init`, and therefore before the
 * translation for the current request has been loaded. The strings would be
 * frozen in English for the life of the request, and the bug would be invisible
 * on an English site and total on every other one. Since 6.7 WordPress says so
 * out loud, with a _doing_it_wrong() notice for a __() call this early.
 *
 * A function defers the __() call to the first read, which is always inside a
 * render path and always after `init`. The static memo means the tables are
 * still built once per request.
 *
 * There is no load_plugin_textdomain() call anywhere in this plugin, and that
 * is also deliberate. WordPress has loaded translations for directory-hosted
 * plugins by itself since 4.6; calling it explicitly forces the .mo read on
 * every request including the ones that never render a string of ours.
 * wp_set_script_translations() in inc/enqueue.php is a different thing and is
 * still needed.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The states an hour entry can be in, as a human should see them named.
 *
 * "Unverified" rather than "Rejected" or "Invalid": an entry nobody has attested
 * to yet is not wrong, it is merely unattested, and staff triaging a queue of
 * them should not be reading an accusation. The distinction matters on the
 * letter too, where an unverified entry is shown and marked rather than deleted.
 *
 * @return array<string, string>
 */
function gwc_vt_verification_labels(): array {
	static $labels = null;
	if ( null !== $labels ) {
		return $labels;
	}

	$labels = array(
		'verified'   => __( 'Verified', 'groundwork-common-volunteer-tracker' ),
		'unverified' => __( 'Not yet verified', 'groundwork-common-volunteer-tracker' ),
		'pending'    => __( 'Awaiting staff review', 'groundwork-common-volunteer-tracker' ),
	);

	return $labels;
}

/**
 * One verification state, named.
 *
 * @param string $state State key.
 * @return string
 */
function gwc_vt_verification_label( string $state ): string {
	$labels = gwc_vt_verification_labels();
	return $labels[ $state ] ?? $labels['unverified'];
}

/**
 * How hours can be displayed.
 *
 * Two formats because two audiences read them. A court or a school wants a
 * decimal total it can compare against a number in an order — "42.5 hours". A
 * volunteer coordinator reading a shift list wants "3h 30m", because that is
 * what the shift was.
 *
 * @return array<string, string>
 */
function gwc_vt_hour_format_labels(): array {
	static $labels = null;
	if ( null !== $labels ) {
		return $labels;
	}

	$labels = array(
		'decimal' => __( 'Decimal hours (3.5)', 'groundwork-common-volunteer-tracker' ),
		'hm'      => __( 'Hours and minutes (3h 30m)', 'groundwork-common-volunteer-tracker' ),
	);

	return $labels;
}

/**
 * What the plugin says about itself on every letter it produces.
 *
 * ── Read the box comment at the top of the main plugin file before editing ──
 *
 * This is the sentence that keeps the whole feature honest, and it is why the
 * setting behind it cannot be saved empty: gwc_vt_disclaimer() falls back here
 * when the stored value sanitizes to ''. An organization's counsel may well need
 * different wording — that is what the setting is for — but "no disclaimer at
 * all" is not a wording choice, it is the plugin quietly starting to imply that
 * it certified something.
 *
 * Three claims, in this order, and each is load-bearing:
 *
 *   1. Where the numbers came from — the organization's own records.
 *   2. Who is answerable for them — the organization, explicitly, not us.
 *   3. Who to ask — a named contact.
 *
 * What is deliberately absent: any statement that the hours were worked. The
 * plugin cannot know that. It knows what was recorded and who attested to it,
 * and saying more would be the exact overclaim this plugin exists to avoid.
 *
 * ── And what this deliberately stops saying ──────────────────────────────────
 * It used to quote the reference code, three lines above the same code printed
 * on its own; and the note beside that code said the letter was "not a
 * certification by any outside body", which is the sentence this paragraph
 * already spends a clause on. Two facts, each stated twice, in one footer.
 *
 * The split is now: this paragraph says what the DOCUMENT is and who is
 * answerable for it, and the note beside the code says what the CODE is for.
 * Neither repeats the other, and the one that must never be emptied is this one.
 *
 * The timestamp stays even though the letterhead carries the date. Two letters
 * issued on one day over one set of shifts produce the same reference — the
 * digest covers the facts, not the hour — so the time is the only thing on the
 * page telling them apart.
 *
 * Placeholders are substituted by gwc_vt_render_letter(): {org}, {contact},
 * {timestamp}, {timezone}, {reference}.
 *
 * @return string
 */
function gwc_vt_default_disclaimer(): string {
	static $text = null;
	if ( null !== $text ) {
		return $text;
	}

	$text = __(
		'This letter was generated from volunteer hour records kept by {org}, who are the authoritative record-keeper for these hours. It reports those records and is not an independent certification that the hours were worked. Questions should be directed to {contact}. Generated {timestamp} ({timezone}).',
		'groundwork-common-volunteer-tracker'
	);

	return $text;
}

/**
 * What the reference code on a letter does and does not prove.
 *
 * Printed alongside the code itself, and its whole job is to make the code
 * actionable — without a sentence here a reader reasonably assumes a code means
 * the document was issued by some authority, and has nothing to do with it.
 * With it, the code means the narrower and true thing and comes with the one
 * instruction that uses it: quote this, to them.
 *
 * It no longer says the letter is not a certification. The disclaimer directly
 * above spends a clause on exactly that, and a footer that makes the same point
 * twice reads as a document arguing with itself.
 *
 * @return string
 */
function gwc_vt_default_reference_note(): string {
	static $text = null;
	if ( null !== $text ) {
		return $text;
	}

	$text = __(
		'Quote this reference when you contact {org} and they can confirm the letter against the records it was generated from.',
		'groundwork-common-volunteer-tracker'
	);

	return $text;
}

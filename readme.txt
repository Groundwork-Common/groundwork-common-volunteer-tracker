=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, community service, nonprofit, timesheet
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept.

== Description ==

Plenty of nonprofits host people working off court-ordered or school-required community service alongside their regular volunteers. At the end of it, that person needs a letter — on the organization's letterhead, saying how many hours they worked and when.

Right now that is a paper form in a drawer, or a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date.

This plugin keeps the hours, lets staff attest to them, and prints the letter.

= What it does =

* Records volunteer hours as individual shifts — who, when, how long, doing what, supervised by whom.
* Lets a staff member with the right permission mark a shift verified. Who attested and when is recorded and appears on the letter.
* Produces a verification letter for any volunteer over any date range, itemized, on your letterhead, ready to print or email.
* Gives every letter a reference code you can read back to anyone who phones to check it — and a screen that tells you whether it still matches your records.
* Logs every letter issued, printing included.
* Optionally lets volunteers send in their own hours from a page on your site. Off until you switch it on; everything sent arrives unverified and waits for staff.
* Lets you decide how long records are kept, and supports WordPress's own Export and Erase Personal Data tools.

= What it deliberately does not do =

It does not certify anything. Nobody at Groundwork Common watched anybody sweep a floor, so the letter says plainly that **your organization** is the authoritative record-keeper and that the document reports your records rather than independently certifying them. There is no seal, no rendered signature, and no affidavit language — the signature block is a ruled line for a real person to sign.

That disclaimer is editable, because your counsel may want different wording. It cannot be emptied.

= Privacy =

Volunteer records here are more sensitive than most plugin data — in the mandated-service case they reveal that a named person is working off a court order. So none of it is public, none of it is searchable, and none of it is exposed on the REST API. Retention is a decision you make on the Privacy tab; the plugin will not quietly delete records for you, and it will not quietly keep them forever without asking either.

== Frequently Asked Questions ==

= Do volunteers need a WordPress login? =

No. Staff enter hours from the admin. There is an optional front-end form volunteers can log their own hours through, and it is switched off until you switch it on. Anything sent through it arrives unverified and attached to nobody — a staff member matches it to a volunteer and checks it before it counts towards anything.

= Can supervisors sign off without an account? =

Not yet. In this version a staff member with the right permission attests to hours, which is how the paper forms this replaces already work. Emailed supervisor confirmation is planned.

= Does it produce a PDF? =

It produces a letter styled for print — use your browser's Print to PDF. Bundling a PDF library would add megabytes of third-party code to a plugin that otherwise has no dependencies at all.

== Screenshots ==

1. The hours list, filtered to shifts nobody has verified yet.
2. Entering a shift, with the volunteer picker and the verify button.
3. A verification letter, ready to print.
4. Checking a reference code somebody has phoned in about.

== Changelog ==

= 0.7.0 =
* The "Not yet verified" badge is now the verify button — click it and the shift is attested, without hunting for a hover menu.
* The hours list opens in shift-date order, newest first, instead of the order records happened to be created in.
* Submissions from the public form now suggest who they belong to: one click to attach them to a matching volunteer, or to create a record from what was submitted.
* A notice on the hours list says how many people are waiting to be matched, and a new "Awaiting matching" filter shows just those.
* New "Log a day" screen for typing up a sign-in sheet: the date, activity and supervisor once, then everybody who worked it.

= 0.6.1 =
* Fixed: on the hours list, a volunteer's name opened the volunteer while the Edit link directly beneath it opened the shift. The name now opens the shift, matching every action in that row, and a "Volunteer record" link goes to the person.

= 0.6.0 =
* A volunteer's record now shows every shift they have logged and every letter issued for them, with a link to check any of those references.
* Checking a reference shows the whole letter as your records would produce it today, so you can compare it against the copy somebody was sent.
* Reference codes now cover every detail the letter prints, not just the total. A swapped pair of shifts, a rewritten activity, a moved date or a changed supervisor is now detected — previously all four reported as matching.

= 0.5.0 =
* An optional public form volunteers can send their own hours through — off until you switch it on, and pinned to one page.
* Everything sent arrives unverified, attached to nobody, and waits for a staff member to match and check it.
* Honeypot, timing checks, and rate limiting by address and email. An optional shared code you can hand out at the front desk.
* Available as the Volunteer Hours Form block or the [volunteer_hours_form] shortcode.

= 0.4.0 =
* Retention: choose how long volunteer records are kept, and whether old ones are anonymized or deleted. Defaults to keeping everything, and asks you to decide.
* Per-volunteer retention holds, for when a court requires a record kept longer than your policy.
* Full support for WordPress's Export and Erase Personal Data tools, including naming any verification letters affected by an erasure.
* uninstall.php: deleting the plugin removes nothing unless you explicitly arm it, and even then only its own settings.

= 0.3.0 =
* Verification letters: print-styled HTML on your letterhead, or emailed to the volunteer.
* Every letter is itemised, naming who verified each shift and when, and carries a reference code.
* "Check a reference" answers a phone call in ten seconds: matches, records have changed since, or never issued here.
* Every letter issued is logged — printing included.
* The disclaimer naming your organization as the authoritative record-keeper is editable, and cannot be emptied.
* A Letter settings tab: letterhead, signatory, all the wording with placeholders, and an optional covering note for the email.
* A Logging settings tab: rounding increment, how hours display, whether future dates are allowed, and activity suggestions.

= 0.2.0 =
* Staff verification: verify a shift from its row, from the entry itself, or in bulk. Who verified it and when are recorded and will appear on the letter.
* Filter the hours list by verification state, and an unverified count beside the menu item.

= 0.1.0 =
* Hour entries and volunteer records, with a volunteer picker that searches as you type.
* Per-volunteer totals on the volunteer list, split into verified and awaiting verification.
* Durations accept 3.5, 3:30, 3h 30m or 210m, and round to the nearest quarter hour.
* Settings screen with Letter, Logging and Privacy tabs — the tabs themselves land in later releases.

== Upgrade Notice ==

= 0.7.0 =
Everyday improvements for whoever does the volunteer coordinating. No data changes.

= 0.6.1 =
A small but confusing fix to the hours list. No data changes.

= 0.6.0 =
Reference codes now cover the whole letter rather than just the total, so codes issued before this release will report as changed. Re-issue any letter you need a live reference for.

= 0.5.0 =
Adds an optional public hour-logging form. It stays off until you enable it under Settings → Logging.

= 0.4.0 =
Adds retention and privacy tooling. If you already have volunteer records, visit Volunteer Hours → Settings → Privacy and decide how long to keep them.

= 0.3.0 =
The verification letter, which is what this plugin is for. Worth trying end to end on a real volunteer record before relying on it.

= 0.2.0 =
Adds staff verification. Still a development release — the verification letter itself lands in 0.3.0.

= 0.1.0 =
First development release. Not yet ready for production use.

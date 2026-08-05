=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, community service, nonprofit, timesheet
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
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
* Gives every letter a reference code you can read back to anyone who phones to check it.

= What it deliberately does not do =

It does not certify anything. Nobody at Groundwork Common watched anybody sweep a floor, so the letter says plainly that **your organization** is the authoritative record-keeper and that the document reports your records rather than independently certifying them. There is no seal, no rendered signature, and no affidavit language — the signature block is a ruled line for a real person to sign.

That disclaimer is editable, because your counsel may want different wording. It cannot be emptied.

= Privacy =

Volunteer records here are more sensitive than most plugin data — in the mandated-service case they reveal that a named person is working off a court order. So none of it is public, none of it is searchable, and none of it is exposed on the REST API. Retention is a decision you make on the Privacy tab; the plugin will not quietly delete records for you, and it will not quietly keep them forever without asking either.

== Frequently Asked Questions ==

= Do volunteers need a WordPress login? =

No. Staff enter hours from the admin. There is an optional front-end form volunteers can log their own hours through, and it is switched off until you switch it on.

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

= 0.3.0 =
* Verification letters: print-styled HTML on your letterhead, or emailed to the volunteer.
* Every letter is itemised, naming who verified each shift and when, and carries a reference code.
* "Check a reference" answers a phone call in ten seconds: matches, records have changed since, or never issued here.
* Every letter issued is logged — printing included.
* The disclaimer naming your organization as the authoritative record-keeper is editable, and cannot be emptied.

= 0.2.0 =
* Staff verification: verify a shift from its row, from the entry itself, or in bulk. Who verified it and when are recorded and will appear on the letter.
* Filter the hours list by verification state, and an unverified count beside the menu item.

= 0.1.0 =
* Hour entries and volunteer records, with a volunteer picker that searches as you type.
* Per-volunteer totals on the volunteer list, split into verified and awaiting verification.
* Durations accept 3.5, 3:30, 3h 30m or 210m, and round to the nearest quarter hour.
* Settings screen with Letter, Logging and Privacy tabs — the tabs themselves land in later releases.

== Upgrade Notice ==

= 0.3.0 =
The verification letter, which is what this plugin is for. Worth trying end to end on a real volunteer record before relying on it.

= 0.2.0 =
Adds staff verification. Still a development release — the verification letter itself lands in 0.3.0.

= 0.1.0 =
First development release. Not yet ready for production use.

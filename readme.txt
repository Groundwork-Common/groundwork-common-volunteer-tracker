=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, community service, nonprofit, timesheet
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.12.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept.

== Description ==

Plenty of nonprofits host people working off court-ordered or school-required community service alongside their regular volunteers. At the end of it, that person needs a letter — on the organization's letterhead, saying how many hours they worked and when.

Right now that is a paper form in a drawer, or a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date.

This plugin keeps the hours, lets staff attest to them, and prints the letter.

= What it does =

* Plans shifts ahead of time — when, where, what the work is, how many people you need and how many you have room for. Repeat one weekly, fortnightly, monthly or every weekday, and every occurrence is a real shift you can edit or cancel on its own.
* Optionally lets people sign up for shifts from a page on your site, with no account. They get a confirmation, a calendar link and a way to cancel. The public list shows how many places are left, never who is coming.
* Optionally reminds them before the shift, tells them if it moves or is called off, and sends you a daily summary of what is short of people and what still needs its hours logged.
* Puts volunteers on a shift, keeps a waiting list once it is full, and prints the roster for the clipboard.
* Turns that roster into hours once the shift is over — everybody who signed up, already ticked, with the scheduled hours filled in. Untick the no-shows, add the walk-ins, save once.
* Records volunteer hours as individual shifts — who, when, how long, doing what, supervised by whom.
* Lets a staff member with the right permission mark a shift verified. Who attested and when is recorded and appears on the letter.
* Produces a verification letter for any volunteer over any date range, itemized, on your letterhead, ready to print or email.
* Gives every letter a reference code you can read back to anyone who phones to check it — and a screen that tells you whether it still matches your records.
* Logs every letter issued, printing included.
* Optionally lets volunteers send in their own hours from a page on your site. Off until you switch it on; everything sent arrives unverified and waits for staff.
* Tracks how many hours somebody working off court-ordered or school-required service still has to complete, and by when. For your planning only — it never appears on a letter.
* Lets you decide how long records are kept, and supports WordPress's own Export and Erase Personal Data tools.

= What it deliberately does not do =

It does not certify anything. Nobody at Groundwork Common watched anybody sweep a floor, so the letter says plainly that **your organization** is the authoritative record-keeper and that the document reports your records rather than independently certifying them. There is no seal, no rendered signature, and no affidavit language — the signature block is a ruled line for a real person to sign.

That disclaimer is editable, because your counsel may want different wording. It cannot be emptied.

= Privacy =

Volunteer records here are more sensitive than most plugin data — in the mandated-service case they reveal that a named person is working off a court order. So none of it is public, none of it is searchable, and none of it is exposed on the REST API. Retention is a decision you make on the Privacy tab; the plugin will not quietly delete records for you, and it will not quietly keep them forever without asking either.

== Frequently Asked Questions ==

= Do volunteers need a WordPress login? =

No. Staff enter hours from the admin. There is an optional front-end form volunteers can log their own hours through, and it is switched off until you switch it on. Anything sent through it arrives unverified and attached to nobody — a staff member matches it to a volunteer and checks it before it counts towards anything.

= Can volunteers sign up for shifts themselves? =

Yes, if you switch it on under Settings → Shifts. They pick a shift, give a name and an email address, and get a confirmation with the details and a link to cancel. No account is created and no login is needed.

You can still put people on shifts yourself, which is how a lot of signups at this size arrive — somebody rings up.

= Does the public page show who has signed up? =

No, and there is no setting that makes it. Visitors see what each shift is and how many places are left. On a site running a court-ordered service programme, a roster is a list of people working one off, and publishing it is not something the plugin will help you do by accident.

= Is a scheduled shift the same as logged hours? =

No, and deliberately not. A scheduled shift is a plan; hours are a record of what somebody actually did. Nobody accrues hours by signing up for a Saturday and not turning up, because turning a roster into hours is something a person does afterwards — and those hours then have to be verified like any others. It is the one place in this plugin where a shortcut would end up on a document a court reads.

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

= 0.12.1 =
* The Volunteer Hours menu is in the order you work in — the hours, then the schedule, then volunteers, then letters. Settings is last, where it belongs; it had drifted into the middle as screens were added over several releases.

= 0.12.0 =
* Record how many hours somebody has to complete, by when, and who requires it — for people working off court-ordered or school-required service. Their record and the volunteer list show how far along they are and how long they have left.
* Only verified hours count towards it. Hours still waiting to be verified are shown next to it rather than added in, so "four short with six unverified" is visible as the ten-second problem it is.
* None of it ever appears on a letter, and there is no setting that puts it there. How many hours a court ordered is a fact about the court's document, not about anything your organization observed — and stating its terms back to the court is not something this plugin will help you do.
* Anonymizing a record removes the requirement along with the name. The hours stay; "120 hours required by a named court" does not stop identifying an event just because the name above it is gone.

= 0.11.1 =
* Help tabs on the Schedule screen, on a single shift, and on Log a day when it is opened from one — what a shift is and is not, why hours cannot be logged before it has ended, and why the public page never shows who is coming.
* A Shifts tab in the settings help, covering the two switches and what the plugin will and will not send by itself.
* Fixed: the privacy help listed what an export contains and had not been updated for shift signups.

= 0.11.0 =
* Reminders. One message per person per shift, a configurable time before it starts — the single biggest thing you can do about people forgetting. Sent once, never twice, and never to somebody on the waiting list who does not have a place.
* Cancelling a shift can email everybody signed up, with the reason. A tick box showing the count, so a mass email is never a surprise.
* Moving a shift's date, time or place can email them too, with the new details and what it used to be. Nothing is sent for anything else — correcting the activity, the supervisor or the notes emails nobody.
* An optional daily summary for you: shifts in the next week short of people, and shifts that have happened without their hours being logged. Nothing is sent on a day with nothing to say.
* A reminder or confirmation carries a cancel link only if you have a public shifts page. Without one you get no link rather than one pointing at your home page.
* The two scheduled jobs exist only while shifts are switched on, and are removed when you switch them off.

= 0.10.0 =
* People can now sign up for shifts from your own site — the Volunteer Shifts block, or the [volunteer_shifts] shortcode. Off until you switch it on, and pinned to one page.
* The public list shows what each shift is, where, when, what to bring, and how many places are left. It never shows who else is coming, and there is no setting that makes it.
* No account, ever. A signup records a name and an email address as a claim; a staff member matches it to a volunteer record exactly as with the hours form.
* A confirmation email with the details, a link to add the shift to a calendar, and a link to cancel. No login needed for either.
* Cancelling is a button on a page, never a link that acts on its own — mail clients and security scanners follow links, and one that cancelled a place would eventually be followed by a spam filter.
* Full shifts take signups onto a waiting list rather than turning people away, and a place that frees up is filled straight away.
* The same defences as the hours form: honeypot, timing checks, an optional shared code, and one shared rate limit across both forms. A successful signup, a bot, and a rate-limited attempt all get the same answer.
* Signups are covered by WordPress's Export and Erase Personal Data tools — including a signup from somebody who never became a volunteer, which nothing else in the plugin would have found.
* Retention now clears old signups from people who never became volunteers. The place on the shift is kept; the name and address are not.

= 0.9.0 =
* Log a shift's hours straight from its roster: everybody who signed up, already ticked, with the hours the shift was scheduled for. Untick whoever did not turn up, trim whoever left early, add the people who walked in, and save once.
* A shift's date, activity and supervisor come across automatically, so nothing is retyped.
* Somebody who signed up but is not on file yet is suggested from what they typed — picking who they are logs their hours and matches their signup in the same click.
* Nothing at all is recorded for a no-show. There is no absence flag anywhere in the plugin.
* Hours cannot be logged until a shift has actually finished. Recording them early would date them the day you typed them rather than the day they were worked, and that date is what a letter prints.
* Reopening a shift you have already logged shows who has an entry and will not record them twice, so you can add somebody who was missed.
* A notice on the hours list when shifts have happened and their hours have not been logged, and a "Hours not logged" flag on the schedule.
* Hours logged from a shift are ordinary hours: unverified until a staff member attests to them, and identical on a letter to hours typed by hand.

= 0.8.0 =
* New Schedule screen: plan shifts ahead of time, with a date, a start and end time, a location, and how many people you need and can take.
* Repeat a shift daily, every weekday, weekly, fortnightly or monthly on the same weekday. Every occurrence becomes a real shift you can edit or cancel by itself — closing the Saturday after Thanksgiving does not disturb the rest.
* Overnight shifts, for organizations that run them.
* Put volunteers on a shift by hand, using the same picker as everywhere else. Once a shift is full, later signups go on a waiting list rather than being turned away, and a place that frees up is filled straight away.
* A printable roster with contact details and blank In/Out columns — the sheet that goes on the clipboard.
* Cancel a shift with a reason. It stays on the schedule marked as cancelled rather than disappearing, so it is clear it was called off.
* Shifts short of the number of people you said you needed are flagged on the schedule.
* Off until you switch it on, under Settings → Shifts. Nothing about scheduling touches hours: a scheduled shift is a plan, and hours are still logged after the fact and still have to be verified.
= 0.7.1 =
* Your logo can go on the letterhead. Kept small, with the organization name always printed as text underneath so the letter reads correctly when an email program refuses to load images.
* Help tabs on every screen — what verifying actually means, what the reference code does and does not prove, and what happens to records over time.
* Fixed: in emailed letters, the address, contact and logo lines could pick up the organization name's styling. The logo was the visible casualty, arriving as bold text with no size limit.

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

= 0.12.1 =
Reorders the admin menu. Nothing else changes.

= 0.12.0 =
Adds hours-required tracking for mandated volunteers. Nothing changes for anybody without a requirement recorded, and letters and reference codes are unaffected.

= 0.11.1 =
Help text for the scheduling screens. No functional changes.

= 0.11.0 =
Adds shift reminders, cancellation and change notices, and an optional daily summary. All of it is off until you enable it under Settings → Shifts. Nothing changes for hours, letters or reference codes.

= 0.10.0 =
Adds public shift signup and the first email this plugin sends by itself. Both stay off until you enable them under Settings → Shifts. Nothing changes for hours, letters or reference codes.

= 0.9.0 =
Turns a shift's roster into hours in one pass. Nothing changes for hours you have already logged, and letters and reference codes are unaffected.

= 0.8.0 =
Adds shift scheduling and rosters. It stays off until you enable it under Settings → Shifts, and it changes nothing about hours you have already logged or letters you have already issued.
= 0.7.1 =
Adds a logo to the letterhead and help tabs throughout. Fixes styling in emailed letters.

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

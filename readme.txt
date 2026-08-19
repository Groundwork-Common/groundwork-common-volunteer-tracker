=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, community service, nonprofit, timesheet
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept.

== Description ==

Plenty of nonprofits host people working off court-ordered or school-required community service alongside their regular volunteers. At the end of it, that person needs a letter — on the organization's letterhead, saying how many hours they worked and when.

Right now that is a paper form in a drawer, or a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date.

This plugin keeps the hours, lets staff attest to them, and prints the letter.

= What it does =

* A dashboard showing what needs doing, what is coming up, what the year adds up to, and where to go next.
* Plans shifts ahead of time — when, where, what the work is, how many people you need and how many you have room for. Repeat one weekly, fortnightly, monthly or every weekday, and every occurrence is a real shift you can edit or cancel on its own.
* Optionally lets people sign up for shifts from a page on your site, with no account. They get a confirmation, a calendar link and a way to cancel. The public list shows how many places are left, never who is coming.
* Optionally reminds them before the shift, tells them if it moves or is called off, and sends you a daily summary of what is short of people and what still needs its hours logged.
* Puts volunteers on a shift, keeps a waiting list once it is full, and prints the roster for the clipboard.
* Turns that roster into hours once the shift is over — everybody who signed up, already ticked, with the scheduled hours filled in. Untick the no-shows, add the walk-ins, save once.
* Records volunteer hours as individual shifts — who, when, how long, doing what, supervised by whom. Type 3.5, 3:30, 3h 30m or 210m; whichever you use, the figure stored is rounded to an increment you choose — always to the nearest, never up, and the screen tells you when it has.
* Runs a day as an event when one occasion has several roles at several times — a festival, a meal service, a collection drive. Volunteers pick more than one time in a single go and get one email listing all of them.
* Lets a staff member with the right permission mark a shift verified. Who attested and when is recorded and appears on the letter.
* Produces a verification letter for any volunteer over any date range, itemized, on your letterhead, ready to print or email.
* Gives every letter a reference code you can read back to anyone who phones to check it — and a screen that tells you whether it still matches your records.
* Logs every letter issued, printing included.
* Optionally lets volunteers send in their own hours from a page on your site. Off until you switch it on; everything sent arrives unverified and waits for staff.
* Tracks how many hours somebody working off court-ordered or school-required service still has to complete, and by when. For your planning only — it never appears on a letter.
* Lets you decide how long records are kept, and supports WordPress's own Export and Erase Personal Data tools.
* Lets you choose which roles can verify hours and which can issue letters — they are frequently different people, and they are separate permissions.

= What it deliberately does not do =

It does not certify anything. Nobody at Groundwork Common watched anybody sweep a floor, so the letter says plainly that **your organization** is the authoritative record-keeper and that the document reports your records rather than independently certifying them. There is no seal, no rendered signature, and no affidavit language — the signature block is a ruled line for a real person to sign.

That disclaimer is editable, because your counsel may want different wording. It cannot be emptied.

= Privacy =

Volunteer records here are more sensitive than most plugin data — in the mandated-service case they reveal that a named person is working off a court order. So none of it is public, none of it is searchable, and none of it is exposed on the REST API. Retention is a decision you make on the Privacy tab; the plugin will not quietly delete records for you, and it will not quietly keep them forever without asking either.

== Installation ==

1. Install through **Plugins → Add New**, or upload the zip under **Plugins → Add New → Upload Plugin**.
2. Activate it. A **Volunteer Hours** menu appears.
3. Open **Volunteer Hours → Settings → Letter** and fill in the organization name, the contact a court or school should phone about a letter, and who signs. Each of those falls back to something reasonable on its own — the site title, the administrator's email address, an unlabelled line — and together those fallbacks produce a letter headed with your website's title over your webmaster's address. The letter screen warns you before you print one, but it is quicker to fill them in now.
4. On the **Permissions** tab, choose which roles may verify hours and which may issue letters. They are frequently different people, which is why they are separate.
5. On the **Privacy** tab, decide how long records are kept. The plugin will not choose for you, and it will not quietly keep them forever either.

Then log a shift under **Volunteer Hours → Log hours**, verify it, and produce a letter from **Letters**. Doing that once on a made-up volunteer, before you rely on it, is worth the five minutes.

Nothing here is public until you say so. The shift signup form and the volunteer self-log form are both switched off on a new install — they live under **Settings → Shifts** and **Settings → Logging**.

= If you copy this site =

Do this before the copy runs, not after. A staging site, or a restored backup, carries your real volunteers' email addresses and a working cron — so it will send them shift reminders, waiting-list promotions and cancellation notices, from a site nobody thinks of as live. Add one line to `wp-config.php` on any copy:

`define( 'GWC_VT_MAIL_MODE', 'off' );`

That sends nothing at all. To see what would have gone out instead, use `'trap'` and name an address to receive it — the FAQ below has the detail. Neither constant is needed on your live site: unset means normal delivery.

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

= How do volunteers reach an event? =

You put it on a page. An event has no web address of its own, and publishing it does not give it one — add the **Volunteer Event** block to a page and pick the event, or paste the `[gwc_vt_event_grid]` shortcode with the event's id into one. The event editor tells you which page currently shows it, and says so when none does.

An event's times never appear on the general shifts page. That page lists shifts you scheduled on their own; an event is shown whole, on its own page.

= What happens if I delete the plugin? =

Nothing is removed. Every volunteer record, logged shift, scheduled shift, signup and issued letter stays exactly where it is, and so do the permissions added to your roles. Deactivating does the same.

That is deliberate — losing somebody's court-ordered service history because a plugin was toggled off is not a risk worth taking — but it does mean deleting the plugin is not a way to remove somebody's data. Use the retention policy, or WordPress's Erase Personal Data tool, before you delete anything. If you want the plugin's own settings cleaned up on deletion, there is a tick box for that on the Privacy tab; even then it deletes no records.

= I have a staging copy of my site. Will it email my volunteers? =

Yes, unless you stop it — and this is the most important thing to know about running this plugin. A copy restored from a backup has the real names and the real email addresses in it, and left running it will send reminders, confirmations and verification letters to those people about court-ordered service.

Put one of these in `wp-config.php` **on the copy**:

`define( 'GWC_VT_MAIL_MODE', 'off' );`

That sends nothing at all. Or, to see what would have gone out:

`define( 'GWC_VT_MAIL_MODE', 'trap' );`
`define( 'GWC_VT_MAIL_ALLOW', 'you@example.com' );`

Every message then goes to that address instead, with the site's name in the subject line and a note saying who it was really addressed to. Neither constant is needed on your live site — unset means normal delivery.

== Screenshots ==

1. The dashboard: what needs doing, ordered by what is lost if it waits.
2. The hours list, filtered to shifts nobody has verified yet.
3. Entering a shift, with the volunteer picker and the verify button.
4. A verification letter, ready to print.
5. Checking a reference code somebody has phoned in about.
6. The schedule: what is coming up, how full each shift is, and a repeat that has been called off.
7. Building an event: one occasion, its roles, and the times under each.
8. The roster for an event, split by role and time, ready for the clipboard.

== Changelog ==

= 1.0.0 =
The first release on WordPress.org.

* **The three shortcodes were renamed** to carry the plugin's own prefix, and the old names are gone rather than kept as aliases — an unprefixed name registered globally is the collision this rename exists to avoid, so leaving one behind would defeat it. `[volunteer_hours_form]` → `[gwc_vt_hours_form]`, `[volunteer_shifts]` → `[gwc_vt_shift_list]`, `[volunteer_event]` → `[gwc_vt_event_grid]`. Each now matches the block it is the shortcode for. **If you used a development build, update any page carrying one.** The blocks are unaffected and need no change.
* **Every global name the plugin registers now carries the `gwc_vt_` prefix**, matching its sibling plugins — functions, constants, hooks, post types, meta keys, options, the settings form, the stylesheet handles and the one REST route (`gwc-vt/v1`). This is invisible on a fresh install. It is listed because the action and filter names in the developer documentation all moved with it, and because records written by a development build use the old post types and will not be seen by this version. CSS class names deliberately keep their shorter form — they are page markup, not registered names.
* The plugin and author links now point at the canonical host rather than the bare domain that redirects to it.

Otherwise nothing about the plugin's behaviour changed between 0.15.0 and this. The entries below are the development history, kept rather than tidied away, because a plugin whose whole purpose is to show its working should be able to show its own.

= 0.15.0 =
* A **Permissions** tab. Choose which roles can verify hours and which can issue letters, on a screen, instead of needing a separate plugin or a line of PHP to tell them apart.
* Checking a reference somebody has phoned in about no longer requires permission to issue letters. Answering the phone and signing a letter were the same key; they are not the same job.
* Fixed: an event placed with the **Volunteer Event block** was never matched to the page it was on, so its cancellation links arrived empty. The block is the placement we recommend, and it was the one that did not work.
* The event editor now tells you which page an event is visible on, and says plainly when no page shows it. Publishing an event does not give it a web address, and the wording implied it did.
* Removing a time from an event, or a whole role, could be counted as "hours not logged" on the dashboard while no screen offered anywhere to log them. Each time on an event roster now has its own **Log the hours** link, and the event's row says how many are waiting.
* The letter now writes every date the way your site writes dates. It used to print "August 6, 2026" at the top and "2026-03-04" in the table below.
* A warning before you print a letter that has no organization name, no contact and no signatory — each falls back to something reasonable, and together they produce a letter headed with your website's title over your webmaster's address.
* The shift form now says what it corrected. Hours it could not read, a date moved back from the future, a volunteer that could not be attached: all three used to happen in silence.
* It also says when rounding changed the figure, rather than only explaining rounding in a setting nobody has opened.
* Withdrawing verification in bulk now asks first, and says that verifying again records a new name and date rather than restoring the old one.
* A first-run dashboard. A brand-new site used to be told that everything logged had been verified and no shift was short of people, which is true of an empty database and no use to anybody.
* The volunteer list has a filter and a sortable deadline, and the dashboard's "past their deadline" line now links to those people rather than to everybody.
* The hours form no longer accepts submissions into nowhere when a second copy is placed on a page it is not pinned to. It says which page to use.
* A **Removing this plugin** section on the Privacy tab, saying exactly what deleting it does and does not remove. It removes no records — which is deliberate, and was written down nowhere anybody would find it.
* The staging-mail guard is documented at last, in the readme and in the help tabs. `GWC_VT_MAIL_MODE` is what stops a restored copy of your site emailing your real volunteers.
* Help tabs for events, and for running a copy of your site.
* Fixed: strings in the block editor could never be translated.
* Labels and help text for the fields that had none, and the hours form's hints are now read out by screen readers.
* Screenshots, an icon and a banner, so this page shows the plugin rather than a list of captions with nothing behind them.
* The demo fixture now builds an event, which it had never done — so the newest feature was the one thing that could not be photographed.

= 0.14.0 =
* Events: one occasion with several roles, each offered at several times, each with its own numbers. A festival, a meal service, a collection drive.
* Build the day as a grid — name a role once and hang its times underneath it. The role's supervisor, address and "what to know" are typed once and carried down to every time in it.
* Volunteers pick more than one time in a single go, and get one email listing all of them, each with its own calendar link and its own cancel link.
* The reminder lists the whole day rather than sending one message per time, and says plainly that dropping one leaves the others alone.
* Ticking two times that overlap asks whether you meant it, rather than quietly booking you twice.
* Removing a time from the grid cancels it when people are on it and deletes it only when nobody is — and the row tells you which before you save.
* Print one sheet for the clipboard, split by role and time, with the waiting list on it.
* Give somebody on the waiting list a place by hand, for when you know the person who has not answered is not coming.
* Copy an event onto a new date, roles and times and all, as a draft.

= 0.13.0 =
* A dashboard: the screen you land on. What is waiting for you, the next few shifts, what the year adds up to, and a map of everywhere else.
* The worklist is ordered by what is lost if it waits, not by which number is biggest — unlogged hours first, then shifts still short of people, then the things that keep.
* A queue with nothing in it does not appear. On a quiet day the whole list is one line saying so.
* Nobody is named on it. Every line is a count and a link; the names are on the screen you go to.
* This year's verified hours, for your Form 990 and your grant reports. From 1 January unless a developer sets otherwise.
* The map only offers what you can actually reach — somebody who cannot issue letters is not shown a Letters section that would refuse them.

= 0.12.2 =
* The Volunteer Hours menu now reads forwards: Schedule, Volunteers, All hours, Log a day, Log hours, Letters, Settings.

= 0.12.1 =
* The Volunteer Hours menu is in a deliberate order rather than the order screens happened to be added in. Settings is last, where it belongs; it had drifted into the middle over several releases.

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
* People can now sign up for shifts from your own site — the Volunteer Shifts block, or the [gwc_vt_shift_list] shortcode. Off until you switch it on, and pinned to one page.
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
* Available as the Volunteer Hours Form block or the [gwc_vt_hours_form] shortcode.

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

= 1.0.0 =
The first public release. If you ran a development build, the three shortcodes were renamed to carry the plugin's prefix and the old names no longer work — update any page using one. The blocks are unaffected.

= 0.15.0 =
Fixes events placed with the block never finding their page, and gives event hours somewhere to be logged. Adds a Permissions tab. Existing records, letters and reference codes are untouched.

= 0.14.0 =
Adds events — one occasion with several roles at several times. Existing shifts, signups and letters are untouched.

= 0.13.0 =
Adds a dashboard as the first screen under Volunteer Hours. Nothing else changes.

= 0.12.2 =
Adjusts the admin menu order. Nothing else changes.

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

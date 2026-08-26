=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, volunteer scheduling, community service, nonprofit
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plan volunteer shifts, take signups, log the hours, and produce a verification letter for a court or a school.

== Description ==

Nonprofits that host mandated community service run two jobs that are really one: getting people onto Saturday's shift, and proving afterwards what they did. This plugin does both — the schedule, the signups and the roster on one side, the attested hours and the verification letter on the other.

Somebody working off court-ordered or school-required service needs a letter at the end of it, on the organization's letterhead, saying how many hours they worked and when. Right now that is a paper form in a drawer, or a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date.

The two halves meet when the shift is over: the roster becomes the hours, everybody who signed up already selected and the scheduled times filled in. Clear the no-shows, add the walk-ins, save once. Nobody types Saturday twice.

= What it does =

* A dashboard showing the fortnight ahead as a week strip or a list, what needs doing, what the year adds up to, and a panel for checking a letter reference when somebody phones about one.
* Plans shifts ahead of time — when, where, what the work is, how many people you need and how many you have room for. Repeat one weekly, fortnightly, monthly or every weekday, and every occurrence is a real shift you can edit or cancel on its own.
* Optionally lets people sign up for shifts from a page on your site, with no account. They get a confirmation, a calendar link and a way to cancel. The public list shows how many places are left, never who is coming.
* Optionally reminds them before the shift, tells them if it moves or is called off, and sends you a daily summary of what is short of people and what still needs its hours logged.
* Puts volunteers on a shift, keeps a waiting list once it is full, and prints the roster for the clipboard.
* Turns that roster into hours once the shift is over — everybody who signed up, already selected, with the scheduled hours filled in. Clear the no-shows, add the walk-ins, save once.
* Records volunteer hours as individual shifts — who, when, how long, doing what, supervised by whom. Type 3.5, 3:30, 3h 30m or 210m; whichever you use, the figure stored is rounded to an increment you choose — always to the nearest, never up, and the screen tells you when it has.
* Runs a day as an event when one occasion has several roles at several times — a festival, a meal service, a collection drive. Volunteers pick more than one time in a single go and get one email listing all of them.
* Tracks credentials — a training course, a signed waiver, a background check — with their own renewal interval. A shift or a whole event can ask for one; whoever is short of it is flagged on the roster, and the ones you mark as blocking stop a signup instead. See at a glance who holds each one and whose has lapsed.
* Optionally lets volunteers sign in with a link emailed to them, so they can see their own hours and shifts without an account, a password or a role. Off until you switch it on.
* Puts what needs doing on your WordPress dashboard as well as its own, so you see it without going looking.
* Lets a staff member with the right permission mark a shift verified. Who attested and when is recorded and appears on the letter.
* Produces a verification letter for any volunteer over any date range, itemized, on your letterhead, ready to print or email.
* Gives every letter a reference code you can read back to anyone who phones to check it — and a panel on the dashboard that tells you whether it still matches your records.
* Logs every letter issued, printing included.
* Optionally lets volunteers send in their own hours from a page on your site. Off until you switch it on; everything sent arrives unverified and waits for staff.
* Optionally lets people offer to volunteer from a page on your site. Off until you switch it on. Nothing they send becomes a volunteer record — it waits in a queue for a staff member to accept or discard, so nobody is added to your list by a stranger or a script.
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
2. Activate it. A **Volunteer Tracker** menu appears.
3. Open **Volunteer Tracker → Settings → Letter** and fill in the organization name, the contact a court or school should phone about a letter, and who signs. Each of those falls back to something reasonable on its own — the site title, the administrator's email address, an unlabeled line — and together those fallbacks produce a letter headed with your website's title over your webmaster's address. The letter screen warns you before you print one, but it is quicker to fill them in now.
4. On the **Permissions** tab, choose which roles may verify hours and which may issue letters. They are frequently different people, which is why they are separate.
5. On the **Privacy** tab, decide how long records are kept. The plugin will not choose for you, and it will not quietly keep them forever either.

Then open **Volunteer Tracker → All hours** and press **Log one shift**, verify what you logged, and produce a letter from the volunteer's own record. Doing that once on a made-up volunteer, before you rely on it, is worth the five minutes.

Nothing here is public until you say so. The shift signup form, the volunteer self-log form and the offer-to-volunteer form are all switched off on a new install — they live under **Settings → Shifts** and **Settings → Logging**.

= If you copy this site =

Do this before the copy runs, not after. A staging site, or a restored backup, carries your real volunteers' email addresses and a working cron — so it will send them shift reminders, waiting-list promotions and cancellation notices, from a site nobody thinks of as live. Add one line to `wp-config.php` on any copy:

`define( 'GWC_VT_MAIL_MODE', 'off' );`

That sends nothing at all. To see what would have gone out instead, use `'trap'` and name an address to receive it — the FAQ below has the detail. Neither constant is needed on your live site: unset means normal delivery.

== Frequently Asked Questions ==

= Do volunteers need a WordPress login? =

No. Staff enter hours from the admin. There is an optional front-end form volunteers can log their own hours through, and it is switched off until you switch it on. Anything sent through it arrives unverified and attached to nobody — a staff member matches it to a volunteer and checks it before it counts toward anything.

= Can volunteers sign up for shifts themselves? =

Yes, if you switch it on under Settings → Shifts. They pick a shift, give a name and an email address, and get a confirmation with the details and a link to cancel. No account is created and no login is needed.

You can still put people on shifts yourself, which is how a lot of signups at this size arrive — somebody rings up.

= Does the public page show who has signed up? =

No, and there is no setting that makes it. Visitors see what each shift is and how many places are left. On a site running a court-ordered service program, a roster is a list of people working one off, and publishing it is not something the plugin will help you do by accident.

= Is a scheduled shift the same as logged hours? =

No, and deliberately not. A scheduled shift is a plan; hours are a record of what somebody actually did. Nobody accrues hours by signing up for a Saturday and not turning up, because turning a roster into hours is something a person does afterwards — and those hours then have to be verified like any others. It is the one place in this plugin where a shortcut would end up on a document a court reads.

= Can supervisors sign off without an account? =

Not yet. A staff member with the right permission attests to hours, which is how the paper forms this replaces already work.

The piece that was missing has since been built for volunteers: somebody can prove they control an email address by clicking a link sent to it, and act as themselves without an account. Extending the same thing to a supervisor signing off a shift is the next step rather than a new idea.

= What is a credential, and how is it different from required hours? =

They are unrelated, and the plugin keeps the words apart. **Required hours** are what a court or a school ordered somebody to complete. A **credential** is something a volunteer has to hold before doing certain work — a child safety class, a signed liability waiver, a food handler card.

You define each one once, with how often it needs renewing, and record who holds it on their own record. Expiry is worked out from the date it was granted, so changing an interval re-dates everybody rather than leaving old dates behind.

= What happens if somebody has not got a credential a shift asks for? =

That is a choice you make per credential. Most should **report**: the person is flagged on the roster so a coordinator can see it and decide. A credential set to **block** turns a signup away instead, and is for the things nobody may work without.

A staff member can still put somebody on a blocking shift, but only by giving a reason, which is recorded with their name and shown on the roster from then on. A block you can click past without a trace is not a block.

Nothing is ever removed from a roster automatically. Somebody already accepted for a shift stays accepted; the roster tells you, and you decide.

= Can volunteers see their own hours? =

Yes, if you switch sign-in on under Settings. They give their email address, get a link, and clicking it shows their verified hours, what is still waiting to be verified, and the shifts they are down for.

It is not an account: there is no password, no user is created, no role is granted, and it expires. What a court or school required of them is deliberately not shown — that is a fact about somebody else's document, and it stays off every outward-facing screen.

= Does it produce a PDF? =

It produces a letter styled for print — use your browser's Print to PDF. Bundling a PDF library would add megabytes of third-party code to a plugin that otherwise has no dependencies at all.

= How do volunteers reach an event? =

You put it on a page. An event has no web address of its own, and publishing it does not give it one — add the **Volunteer Event** block to a page and pick the event, or paste the `[gwc_vt_event_grid]` shortcode with the event's id into one. The event editor tells you which page currently shows it, and says so when none does.

An event's times never appear on the general shifts page. That page lists shifts you scheduled on their own; an event is shown whole, on its own page.

= What happens if I delete the plugin? =

Nothing is removed. Every volunteer record, logged shift, scheduled shift, signup and issued letter stays exactly where it is, and so do the permissions added to your roles. Deactivating does the same.

That is deliberate — losing somebody's court-ordered service history because a plugin was toggled off is not a risk worth taking — but it does mean deleting the plugin is not a way to remove somebody's data. Use the retention policy, or WordPress's Erase Personal Data tool, before you delete anything. If you want the plugin's own settings cleaned up on deletion, there is a checkbox for that on the Privacy tab; even then it deletes no records.

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
* **If you translated a development build, re-run your `.po` against the new `.pot`.** The screens, emails and letter were brought into American English, so a number of strings no longer match.

Everything else here is a development build. Nothing numbered below 1.0.0 was ever published, and neither was anything numbered above it — work carried on in the repository while this first release sat waiting for review, and those numbers were never releases anybody could install. This page therefore starts and stays at the first release rather than reciting a version history no reader had. What the plugin does is described above; how it came to do it is in the repository.

== Upgrade Notice ==

= 1.0.0 =
The first public release. If you ran a development build, the three shortcodes were renamed to carry the plugin's prefix and the old names no longer work — update any page using one. The blocks are unaffected.

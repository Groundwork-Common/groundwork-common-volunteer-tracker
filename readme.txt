=== Groundwork Common Volunteer Tracker ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: volunteer, volunteer hours, volunteer scheduling, community service, nonprofit
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plan volunteer shifts, take signups, log the hours, and produce a verification letter for a court or a school.

== Description ==

Nonprofits that host mandated community service run two jobs that are really one: getting people onto Saturday's shift, and proving afterwards what they did. This plugin does both — the schedule, the signups and the roster on one side, the attested hours and the verification letter on the other.

Somebody working off court-ordered or school-required service needs a letter at the end of it, on the organization's letterhead, saying how many hours they worked and when. Right now that is a paper form in a drawer, or a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date.

The two halves meet when the shift is over: the roster becomes the hours, everybody who signed up already selected and the scheduled times filled in. Clear the no-shows, add the walk-ins, save once. Nobody types Saturday twice.

= What it does =

* A dashboard showing what needs doing, what is coming up, what the year adds up to, and where to go next.
* Plans shifts ahead of time — when, where, what the work is, how many people you need and how many you have room for. Repeat one weekly, fortnightly, monthly or every weekday, and every occurrence is a real shift you can edit or cancel on its own.
* Optionally lets people sign up for shifts from a page on your site, with no account. They get a confirmation, a calendar link and a way to cancel. The public list shows how many places are left, never who is coming.
* Optionally reminds them before the shift, tells them if it moves or is called off, and sends you a daily summary of what is short of people and what still needs its hours logged.
* Puts volunteers on a shift, keeps a waiting list once it is full, and prints the roster for the clipboard.
* Turns that roster into hours once the shift is over — everybody who signed up, already selected, with the scheduled hours filled in. Clear the no-shows, add the walk-ins, save once.
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
3. Open **Volunteer Hours → Settings → Letter** and fill in the organization name, the contact a court or school should phone about a letter, and who signs. Each of those falls back to something reasonable on its own — the site title, the administrator's email address, an unlabeled line — and together those fallbacks produce a letter headed with your website's title over your webmaster's address. The letter screen warns you before you print one, but it is quicker to fill them in now.
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

No. Staff enter hours from the admin. There is an optional front-end form volunteers can log their own hours through, and it is switched off until you switch it on. Anything sent through it arrives unverified and attached to nobody — a staff member matches it to a volunteer and checks it before it counts toward anything.

= Can volunteers sign up for shifts themselves? =

Yes, if you switch it on under Settings → Shifts. They pick a shift, give a name and an email address, and get a confirmation with the details and a link to cancel. No account is created and no login is needed.

You can still put people on shifts yourself, which is how a lot of signups at this size arrive — somebody rings up.

= Does the public page show who has signed up? =

No, and there is no setting that makes it. Visitors see what each shift is and how many places are left. On a site running a court-ordered service program, a roster is a list of people working one off, and publishing it is not something the plugin will help you do by accident.

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

= 1.1.1 =

* **Times on the public pages and in email now say which timezone they are in.** A shift read "1:00 pm" and the calendar file attached to the same message carried a UTC instant, so a volunteer whose phone was on another timezone saw two different times for one shift with nothing to say which was right. Lists say it once at the top; a list running across a daylight-saving change says it on each row instead, because such a list genuinely has two answers. The calendar file is unchanged — it was correct already.
* **A signup that comes back with an error keeps what you typed.** Your name, your email address and the shift you picked all survive, so a mistyped address costs you one correction instead of scrolling back past the whole list and starting again. On a phone that list can be three screens long. Nothing is kept back for a code, where a site uses one — that stays blank on purpose.
* **The error says which field it was.** It used to say "Please choose a shift and give your name and email address" whatever you had actually missed, and it could not tell a missing address apart from a mistyped one. Each is now its own sentence, the field itself is marked as the one that failed, and screen readers are told which. Choosing no shift used to report that the shift was no longer taking signups, which was a sentence about a shift nobody had picked.
* **The note explaining why we ask for your address is now read out with the field**, rather than sitting beside it where a screen reader never reached it on the way in.

= 1.1.0 =
Two things you can decide for yourself: whether this site issues verification letters at all, and where an event's signup grid lives.

* **You can turn the verification letter off.** If your organization records volunteer hours but never writes to a court or a school, clear **Issue verification letters** on Settings → Logging. The Letters screen goes away, so does the Letter settings tab and its seventeen fields, and so do the letter actions on a volunteer's record and the letter links on the dashboard. Nothing is deleted: every letter already issued, the log of what went out and every letter setting stay exactly where they are, and selecting it again finds them unchanged. It is on unless you clear it, so nothing changes for anybody who updates.
* **You are asked once which of those you want.** Letters stay switched on either way — the question exists so that an organization which records hours but never writes to a court or a school finds out the switch is there, instead of carrying a whole screen of settings it will never use. Answering it makes the prompt go away for good, and a site that has already issued a letter is never asked at all.
* **An event's signup grid now works on a post, not only a page.** If you write a post about an event — the story, the photos, the why — and put the signup on that same post, the grid always rendered, but the plugin could not find it: the confirmation and cancellation emails linked nowhere, and the event editor said no page showed an event that was published and visible. Any public post type with an editor works now, including one your theme or another plugin added. If you put the same event in more than one place, links back to it use whichever you published first.

= 1.0.2 =
Ten fixes. Dates that printed as the wrong day, screens that reported success for something they had refused, and one path that could email a whole roster twice. Nothing you have already recorded changes.

* **An event's dates now print as the days they were stored as.** On any site west of UTC — which is every site in the Americas — an event stored as October 12th displayed as October 11th: on the public signup grid, in the confirmation email, in the reminder email and in the event picker in the block editor. A one-day event, a multi-day span and the day an event was picked from the editor were all affected. Nothing about the stored dates was wrong, so no event needs re-entering; the display was reading them in a timezone they never had.
* **A signup date the plugin cannot read now shows a dash instead of a date in 1970.** On the event roster, a "signed up" date that had been anonymized, hand-edited or written by an older version rendered as January 1st 1970. On the shift roster the same value rendered as an empty cell. Both now show the dash that means "not recorded".
* **Canceling a shift that is already canceled no longer emails everybody a second time.** The cancel button is hidden once a shift is called off, but the address behind it could still be reached — and doing so mailed the whole roster again and replaced the reason you had typed with whatever was sent the second time. It now does nothing, as it always did on the event screens.
* **A canceling reason typed on the shift screen is kept in full.** It was cut off at 200 characters there while both event screens kept 300, so the same explanation survived or was truncated depending on which button you used. Everywhere keeps 300 now. Nothing already saved is changed.
* **Moving a slot from the event grid now tells volunteers what it moved from.** The email said the slot had changed and then never said what it used to be — the "It was previously..." line was missing, and only on that one screen. Moving the same slot from the shift screen always included it.
* **The year's "shifts recorded" figure now counts every entry.** An entry logged with no time on it — zero minutes — was left out of that one count while being included everywhere else, so the number under the year's hours could disagree with a volunteer's own record of the same entries. It adds nothing to either hours figure; it is only counted.
* **Promoting somebody off the waiting list on a shift now confirms it.** On an event it always said "They have a place now." On a standalone shift the page came back with no message at all, so the only way to tell whether it had worked was to read the roster again.
* **A delete that gets refused no longer reports "Shift saved."** Deleting a shift is refused if somebody signed up between the page loading and the click — correctly — but it then said the shift had been saved, for an operation that changed nothing. It now says that people have signed up, so the shift can be canceled but not deleted, which is what the event screens have always said.
* **Two roster adds that add nobody now say why.** Submitting the add-to-shift form without choosing anybody, and adding to a time that is not on the event you are looking at, both reported success. They now say what went wrong.
* **Copying an event now stores its slots exactly as saving one does.** A copied slot with no minimum or maximum set stored an empty value where a saved slot stores 0. Nothing visible changed, and existing copies are left alone.

= 1.0.1 =
Wording only. Nothing about what the plugin does has changed.

* **Every word the plugin shows you is now American English.** It was written in British English throughout — "organisation", "colour", "programme", "behaviour", "recognise", "anonymise", "defence", "towards" — and that reached the screens, the emails, the letter and this page. Around 250 words changed across the plugin, its documentation and its translation template.
* **A tick box is now a checkbox, and you select and clear it rather than tick and untick it.** That is what both Windows and macOS call the control and what people expect to read beside one. The instructions that used to say "Untick anybody who did not turn up" now say "Clear the checkbox for anybody who did not turn up".
* **"Cancelled" is now "Canceled" wherever you can see it** — the shift and event status labels, the schedule, the cancellation email and its subject line. Only the display text changed. The `gwc_vt_cancelled` and `gwc_vt_ev_cancelled` post statuses, the `gwc_vt_shift_cancelled` and `gwc_vt_event_cancelled` actions, the `_gwc_vt_shift_cancelled_reason` and `_gwc_vt_event_cancelled_reason` meta keys and the `gwcvt-*--cancelled` CSS classes all keep the spelling they were registered with. A post status is written into every row that carries it, so renaming one would leave every already-cancelled shift and event holding a status this plugin no longer recognizes.
* **The translation template was regenerated.** Every changed string is a new entry, so a translation of one of them no longer matches and falls back to the English. Nothing shipped with a translation, so nothing is lost — but a site carrying its own `.po` should re-run it against the new `.pot`.

= 1.0.0 =
The first release on WordPress.org.

* **The three shortcodes were renamed** to carry the plugin's own prefix, and the old names are gone rather than kept as aliases — an unprefixed name registered globally is the collision this rename exists to avoid, so leaving one behind would defeat it. `[volunteer_hours_form]` → `[gwc_vt_hours_form]`, `[volunteer_shifts]` → `[gwc_vt_shift_list]`, `[volunteer_event]` → `[gwc_vt_event_grid]`. Each now matches the block it is the shortcode for. **If you used a development build, update any page carrying one.** The blocks are unaffected and need no change.
* **Every global name the plugin registers now carries the `gwc_vt_` prefix**, matching its sibling plugins — functions, constants, hooks, post types, meta keys, options, the settings form, the stylesheet handles and the one REST route (`gwc-vt/v1`). This is invisible on a fresh install. It is listed because the action and filter names in the developer documentation all moved with it, and because records written by a development build use the old post types and will not be seen by this version. CSS class names deliberately keep their shorter form — they are page markup, not registered names.
* The plugin and author links now point at the canonical host rather than the bare domain that redirects to it.

Otherwise nothing about the plugin's behavior changed between the last development build and this. Everything numbered below 1.0.0 was a development build — never published here, and not a version anybody can be upgrading from, which is why this page starts at the first release rather than at the first commit.

== Upgrade Notice ==

= 1.1.0 =
Adds a switch that turns the verification letter off, for organizations that record hours but never write to a court or a school. Letters stay on unless you clear them, though you may be asked once which you want. An event signup grid can also live on a post now, not only a page.

= 1.0.2 =
West of UTC, event dates displayed one day early on the public grid and in both emails, so volunteers were told the wrong day. Also stops a canceled shift emailing its roster twice, and screens reporting success for what they refused. Nothing recorded changes.

= 1.0.1 =
Wording only. The screens, emails and letter now read in American English, a tick box is called a checkbox, and "Cancelled" is spelled "Canceled". If you translated this plugin, re-run your `.po` against the new `.pot` — the changed strings no longer match.

= 1.0.0 =
The first public release. If you ran a development build, the three shortcodes were renamed to carry the plugin's prefix and the old names no longer work — update any page using one. The blocks are unaffected.

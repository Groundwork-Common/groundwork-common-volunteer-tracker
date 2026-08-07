# Groundwork Common Volunteer Tracker

Plan volunteer shifts, log the hours worked, have staff attest to them, and produce a verification letter a court or a school will accept.

## Why it works that way

Plenty of nonprofits — food banks, thrift stores, shelters — host people working off court-ordered or school-required community service alongside their regular volunteers. At the end of it that person needs a letter saying how many hours they worked and when, on the organization's letterhead.

Today that is a paper form in a drawer, a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date. The volunteer-management platforms that do this properly are priced for organizations with a volunteer director. The best free WordPress option covers signup and scheduling and stops exactly where this plugin used to start.

For four releases the answer to that was to stay on our own side of the line: hours in, verification letter out, and scheduling is somebody else's product. What changed the argument is that the line runs through the middle of one job. A coordinator running both types Saturday twice — once into a signup tool and again into a sign-in sheet — and the evidence was already in this codebase, because "Log a day" is a roster screen with no roster to start from.

So the job is the whole loop, and the part still nobody else has built is the second half of it: **sign up, show up, hours recorded, hours verified, letter out.** Scheduling here exists to feed the hours; it is not a scheduling product with hours bolted on, and every decision below follows from that ordering.

**The constraint that shapes everything else is what the plugin is allowed to claim.** Its output is a document somebody hands to a probation officer. A seal, the word "certified", a rendered signature, language borrowed from an affidavit — each is a few minutes of work, each makes the letter look more official, and each is the plugin asserting an authority it does not have. Nobody at Groundwork Common watched anybody sweep a warehouse floor.

What the letter does instead is report what the organization recorded, say plainly that the *organization* is the authoritative record-keeper, itemize the hours with the staff member who attested to each one and when, timestamp it, and give it a reference code that can be checked. Those are facts a reader can verify. A seal is a picture of one.

## Requirements

WordPress 6.3, PHP 7.4. No build step, no Composer, no npm for anything that ships.

The PHP 7.4 floor is tested, not assumed. PHPUnit 11 needs PHP 8.2, so the unit suite *cannot* run on 7.4 — which means without a deliberate job the compatibility claim in the plugin header would be verified by nobody. The Tests workflow runs the integration scripts against a real 7.4 site, and parses every shipping file with 7.4's own parser. To check it yourself:

```bash
npx @wordpress/env start --config=.wp-env.php74.json
```

## Things that are deliberate

- **Everything on the dashboard is a queue.** That is the test a line has to pass to appear: dealing with it makes it go away. It is what separates a worklist from a status board, and it is why there is no panel listing who is working off a requirement — no amount of work makes a list of who is under a court order shorter. What appears instead is the one line that *is* a queue: somebody has passed their deadline, go and look.
- **The dashboard names nobody.** Every line is a count and a link; the names live on the screen the link goes to, which is somewhere a person has gone deliberately. The overdue line is the one it would be tempting to expand, and it is the one that must not be. `DashboardTest` asserts the exact field set of a worklist line, so adding anywhere to put a name fails the build.
- **The worklist is ordered by what is lost if it waits,** not by which number is biggest. Eight shifts to verify is a bigger number than one unlogged shift and a smaller problem: verification keeps, and hours nobody typed up get further from anybody's memory every week. The order is fixed rather than derived, and asserted.
- **A queue at zero does not appear.** The same rule as the daily summary. A screen reporting "none waiting" five times over is one people stop reading, and then the line that says Saturday is short gets skimmed with it.
- **The map offers only what the person can reach.** Somebody without `gwcvt_issue_letters` gets no Letters group rather than three links that will refuse them — a link that fails when clicked teaches somebody the screen is broken; an absent one teaches them nothing.
- **Every worklist line names a job, verb first.** "Write up a shift that has already happened", not "a shift has happened and its hours are not logged". A description makes the reader translate a state into a task before they can decide anything; the state belongs on the second line, which is there to answer "and if I leave it?".
- **Coming up runs a fortnight, split at the end of this week.** The two halves answer different questions — this week is still fixable by ringing round, next week is only worth knowing about — and fourteen undifferentiated rows answer neither. Where the week breaks is the site's business, so it comes from `start_of_week`; a great many places outside Europe and North America answer Saturday or Sunday.
- **The reporting year is a filter, not a setting.** Most organizations report on the calendar year. The ones that do not know exactly when theirs begins and have somebody who can add a line to a theme — where a setting would put a question on the Settings screen that almost nobody needs, and answering it wrongly would quietly misstate a figure that goes to a funder.
- **What somebody was required to do never reaches the letter.** Everything else this plugin records is something the organization observed. How many hours a court ordered is a fact about the court's own document — one the organization may have seen as a photograph and may be reading wrong. Printing "120 ordered, 94 completed" would be the organization certifying the terms of an order back to the court that issued it, and if those terms were modified on appeal while the filing-cabinet copy was not, the letter is confidently wrong about the one line its reader checks. `RequiredTest` asserts no letter file reads the meta key; `tests/integration/required.php` asserts that raising the requirement from 40 hours to 500 produces a byte-identical letter and the same reference code.
- **Only verified hours count towards a requirement.** Those are the only ones the organization stands behind, and the only ones a letter states a total for. Unverified hours are reported beside it rather than folded in — somebody four hours short with six unverified is a ten-second problem, and only visible if the two numbers stay apart.
- **A deadline is reported, never predicted.** Overdue means the date has passed and the hours are not there. There is no "on track" indicator, because that needs a rate this plugin has no business inventing, and being told you are behind by software that guessed is worse than not being told.
- **Anonymizing removes the requirement along with the name.** The hours survive because they are the organization's own service record and identify nobody. "120 hours required by 15 November for Franklin County Municipal Court" says a real person was under a real order, names it and dates it — and it does not stop being a disclosure because the name above it has gone.
- **A scheduled shift and a logged hour are different nouns, and never the same record.** A shift is a plan — Saturday nine to twelve, food sorting, we need six. An hour entry is a claim about the past that a staff member has attested to. If a signup could become an hour by sitting on the calendar until the date passed, somebody who never turned up would accrue hours towards a document a probation officer reads, and nobody would find out until they read it. So they are separate post types, and turning a roster into hours is an explicit act by a person who was there.
- **A shift cannot be logged until it has ended.** `gwcvt_save_entry()` silently clamps a future date to today, so logging Saturday on Friday would date Saturday's hours to Friday — on the document a court reads, with nothing on screen to say it happened. Refused rather than warned about, in the screen *and* in the handler, because the screen is not the only thing that can post to it.
- **Reconciling does not verify.** Three questions, three answers, three people's jobs: matching says whose hours these are, logging says the shift has been written up, verifying says it happened. Hours that arrive from a roster are unverified like every other kind, and a letter cannot tell the two apart.
- **The "came" checkboxes carry an explicit row index.** An unticked checkbox posts nothing at all, so a positional `gwcvt_attended[]` would arrive with its indexes closed up and every row after the first no-show would read somebody else's answer — silently crediting hours to the wrong person. `tests/integration/reconcile.php` posts a roster with a gap in the middle for exactly this.
- **Nothing records that somebody did not turn up.** A no-show is derived: a logged shift, a place on the roster, and no entry. A stored absence flag would be a behaviour file on people working off court orders, kept by a plugin with no business keeping one.
- **Reopening a logged shift cannot double anybody.** Rows that already have an entry render without a tick box and post nobody, so a coordinator can come back to add the walk-in they forgot without silently doubling everybody else's hours.
- **The public shift list shows counts and never names, and there is no setting that changes it.** A visitor may see that Saturday exists, what the work is, and that three places are left. On a site running a court-ordered service programme, who is volunteering Saturday is a list of people working one off. A place count says nothing about anybody; a first name says everything about one person.
- **Signing up is a second switch, not a mode of the first.** "We plan shifts internally" and "strangers can put their name on one" are different decisions with different consequences, and an organization that wants the first should not have to accept the second to get it. Both, plus a pinned page, before anything is accepted.
- **Nothing mutates on GET.** The cancellation link in a confirmation email lands on a page with a button. Mail clients prefetch links, security appliances follow them to see where they go, and link previews fetch them for a thumbnail — a GET that withdrew a place would eventually be withdrawn by a spam filter, and the volunteer would find out by not being expected.
- **The reminder pass is idempotent, which is what makes it safe unattended.** A signup carries the time its reminder went and absence means it has not; the query only looks at signups with no such timestamp. A skipped cron run — a quiet site with no visitors to trigger wp-cron — sends late rather than never, and a run that fires twice sends nothing the second time. The timestamp is written *before* the send, because `wp_mail()` returning false does not mean nothing was delivered, and the failure worth avoiding is an hourly pass reminding the same person every hour forever.
- **Hourly, not daily.** The lead time is in hours, and a daily pass would send a "two days before" reminder anywhere from 24 to 48 hours out depending on when the site's cron happened to fire.
- **Nobody on the waiting list is reminded.** They do not have a place, and telling them to turn up on Saturday would be telling them something untrue.
- **A shift that already started is never reminded about.** A message about a shift that began an hour ago is worse than none: it tells somebody who forgot that they have already let people down.
- **Mailing the roster is a tick box, never a side effect.** Cancelling or moving a shift shows the count and asks. And only a *move* counts — the date, the times, the overnight flag, the place. Rewording the activity, correcting the supervisor, expanding the notes or changing the capacity emails nobody, because mailing thirty people about a spelling fix is how an organization teaches its volunteers to ignore its email.
- **A message with nowhere to point carries no link.** A site can run shifts and reminders without ever pinning a public page — the coordinator takes names on the phone. `gwcvt_signup_manage_url()` returns empty there and every caller checks, because "click here to cancel" pointing at the front page is worse than no link: the volunteer clicks it, finds nothing, and assumes they have cancelled.
- **The daily summary is silent on a quiet day.** One that arrives every morning saying "nothing to report" gets a filter rule inside a fortnight — and then the one that says "Saturday has two of six" gets filtered with it.
- **The two scheduled events exist only while shifts are on.** Unlike the retention sweep, which applies to every install, these have nothing to do on a site that never turns scheduling on — and an hourly event pointing at a function that returns immediately is still a permanent row in every cron listing that site's owner ever looks at. Unscheduling when the setting goes off matters as much as scheduling when it comes on.
- **The confirmation email is not a setting.** A signup with no confirmation leaves somebody with no record of what they agreed to, no note of where to go, and no way out except telephoning during office hours. That is not a lighter-weight version of the feature, it is a broken one. It is sent on `shutdown` rather than inline, because `wp_mail()` over SMTP can take seconds and a signup that visibly takes four while a honeypot hit takes a quarter of one has told the sender which they triggered.
- **The calendar file is a link, not an attachment,** and carries UTC instants rather than a hand-written `VTIMEZONE`. A transcription of somebody else's timezone database goes stale silently and is wrong exactly when the clocks change; a UTC instant is computed once from the same wall time everything else uses. `METHOD:PUBLISH`, never `REQUEST` — the latter makes a client offer Accept and Decline buttons that RSVP to an address nothing is listening on.
- **A signup nobody matched is still somebody's personal data.** Every privacy path in the plugin used to start from a volunteer record, which would never have found a name typed into the public form by a person who then never turned up. The exporter, the eraser and the retention sweep all reach those directly now — the place on the shift survives, the name and address do not.
- **Scheduling is off until you switch it on**, like the public form and for a related reason. A menu item, a set of screens and eventually mail leaving the site should not appear because somebody updated a plugin they installed to log hours. An organization taking signups on a clipboard is not doing it wrong.
- **A shift stores wall-clock time, never an instant.** The Saturday nine o'clock shift is at nine o'clock in March and at nine o'clock in November; storing a timestamp and adding a week across a clock change moves it to ten. Instants are derived at read time through `gwcvt_timezone()`, and recurrence is calendar arithmetic on dates that never touches a clock at all. `ShiftTest` asserts both sides of a real daylight-saving boundary.
- **Recurrence creates real shifts, not a rule to be evaluated later.** "Every Saturday through December" is twenty-odd rows. The moment somebody closes the Saturday after Thanksgiving, a stored rule needs an exception list — and then every query has to reconcile the two, a signup has to attach to something that is not a row, and "which shifts is Jane on" stops being a query. The cost is a cap, which is *reported* rather than silently applied: a screen that quietly makes fewer shifts than you asked for loses you a month of Saturdays, and you find out when nobody turns up.
- **Capacity is advisory.** A signup over the maximum goes on a waiting list rather than being refused. Somebody working off a court order with a deadline should not be bounced by a number a coordinator typed in March; a coordinator who can see the list can have a conversation, whereas a refusal is a dead end the volunteer cannot appeal. Settling runs under an `add_option()` lock — false on an existing key is an atomic test-and-set — and a failed lock settles anyway, because overfilling by one is a conversation and stranding somebody on a waiting list for a shift with room is not.
- **Settling never demotes.** Lowering a shift's maximum below the number already on it makes the shift smaller; it does not un-invite the four people who were already coming.
- **A withdrawal is kept, not deleted.** "Two people dropped" is something the coordinator needs to see when they look at Saturday. A trashed post is invisible on its way to being deleted by core's own cleanup.
- **Attendance is derived, never stored.** Attended means an hour entry exists; a no-show is a reconciled shift with a roster place and no entry. A stored no-show flag would be a behaviour record on people working off court orders, and this plugin has no business keeping one.
- **The cancellation token is a counter, not a timestamp.** It was the created time first, and `current_time( 'mysql' )` has one-second resolution — so withdrawing and signing up again inside the same second produced an identical digest and left the link in the first email live. A double-submit is enough to hit that. `tests/integration/signups.php` caught it; `SignupTest` now pins it. How long a capability URL stays valid must not depend on how fast somebody clicks.
- **A shift with people on it is cancelled, never deleted,** and the screen only offers delete while the roster is empty. Cancelling says it was called off and everybody can see that it was; deleting says it never existed, which is only true of a typo.
- **Signup meta keys are deliberately not the entry's** — `_gwcvt_signup_volunteer`, not `_gwcvt_volunteer`. The totals cache invalidates from meta writes by key name, so a shared key would dirty and recompute a volunteer's rollup every time they signed up for something they had not yet worked.
- **Durations are integer minutes, never float hours.** Three and a half hours is `210`. Floats do not sum exactly, and a letter reading "42.30000000000001 hours" is not a rounding curiosity a court will overlook — it is the moment the reader stops believing the document. `HoursTest` sums a thousand entries both ways to keep the argument honest.
- **A bare number over 24 hours is refused rather than guessed at.** `210` means 210 *hours* by the rule that a bare number is hours, which is somebody typing minutes into the wrong field. Guessing "that must have meant minutes" works right up until the volunteer who really did log 30 hours over a weekend retreat.
- **Rounding is to the nearest increment, never up.** Rounding up is the organization systematically crediting hours nobody worked, which on this document is the one direction of error that matters.
- **The disclaimer is editable but cannot be emptied.** An organization's counsel may need particular wording; "no disclaimer" is not a wording choice, it is the plugin quietly starting to imply it certified something. An empty stored value reads as "use the default".
- **Capabilities are granted on `init`, not on activation.** An activation hook runs once, and a site that loses them to a security plugin rebuilding roles or a restore from an old backup would need a deactivate/reactivate cycle to get them back.
- **A capability set to `false` is left alone; one removed entirely is restored.** That is the isset() check in `gwcvt_grant_capabilities()`, and it is the only way to tell a deliberate revocation from a loss. See the note on the function.
- **The admin menu reads forwards, and is reordered once at the end rather than registered in order.** Schedule, Volunteers, All hours, Log a day, Log hours, Letters, Settings — what is coming, who is coming, what they did, writing it up, what gets produced. Left alone it came out as All hours, Log hours, Volunteers, Settings, Letters, Log a day, Schedule, with Settings fourth in the middle of the working screens, because one file registers at the default priority and the screens added in later releases registered at 11, 12 and 13. Nobody chose that; it is the order the files load in. `add_submenu_page()`'s position argument looks like the fix and is not: positions are uncoordinated integers between plugins, WordPress ignores them for post type submenus, and two items claiming a slot resolve by float-key collision. Rewriting the array once, at priority 99, is the only method that says what it means.
- **The colophon is collapsible, never dismissible,** and stores *when* it was collapsed rather than *that* it was — so thirty days falls out of a comparison instead of needing a scheduled event to clear a flag.
- **No `load_plugin_textdomain()` call.** WordPress has loaded translations for directory-hosted plugins by itself since 4.6; calling it explicitly forces the `.mo` read on every request. `wp_set_script_translations()` is a different thing and is still needed.
- **Translated lookup tables are functions with a static memo, never `const`.** A `const` is evaluated at include time, before the request's translation is loaded — invisible on an English site and total on every other one, and `_doing_it_wrong()` about it since 6.7.
- **The letter is recomputed from entries every time, never from the cached rollup.** A letter is produced twice a year per volunteer and its correctness is the whole product; a cached figure that is subtly stale is the one failure this plugin cannot have, because the person holding the letter cannot tell.
- **The reference digest covers every field the letter prints, not just the total.** Hashing only the volunteer, range, total and count detects a letter whose *total* was altered and nothing else — two shifts swapped so the sum is unchanged, a rewritten activity, a moved date, a changed supervisor all came back "matches". A verifier that only checks the total vouches for a letter somebody edited.
- **Checking a reference renders the whole letter, not a summary.** A summary answers "do the totals agree"; the question somebody holding a printed copy is asking is "is this the same document". The digest can say *that* something differs — only the document can show *what*. It is rebuilt from current records rather than a stored copy, and rendering it never logs an issuance.
- **The reference verifier recomputes from current records, not from the stored figures.** Comparing a letter against its own log entry would answer "matches" every time — a verifier that always says yes is worse than none, because it actively vouches. It reports `changed` rather than `invalid`: hours get corrected and shifts get verified after a letter goes out, and none of that is anybody's fault.
- **The public form is off until you switch it on**, and switching it on without pinning it to a page doesn't count. A plugin that started accepting personal information from strangers because it was installed would be doing something nobody asked for.
- **The form never looks anybody up.** The handler doesn't ask whether the submitted email belongs to an existing volunteer, so there's no code path whose behaviour depends on that answer — and therefore no oracle to build one from. Name and email are stored as *claims* on a pending entry; a human attaches them later. An anonymous form can't create an identity record, only a row somebody must triage.
- **Accepted, honeypotted and rate-limited are byte-identical.** `SelfLogTest` asserts it literally. If they diverge, the form starts answering questions about who's been submitting — and on a site running a court-ordered service programme, that question is whether a named person is working one off.
- **The rate limiter hashes its keys and prunes on write.** It's a record of who submitted and when, written by anonymous traffic; in the clear it'd be a log of email addresses sitting in an option. It keys on `REMOTE_ADDR` only — `X-Forwarded-For` is spoofable by exactly the person being limited.
- **Retention defaults to keeping everything, and nags until you decide.** A plugin that deleted records on a schedule it chose would eventually destroy the six weeks of Saturdays somebody needs for a court date they haven't reached. But defaulting to "keep forever" quietly hoards personal data. The resolution isn't a cleverer default — it's a non-dismissible notice on the plugin's own screens that goes away once the Privacy tab is saved, *including* saved as "keep indefinitely". A legitimate answer; never having considered it isn't.
- **Anonymizing keeps the hours.** Grant reporting and a Form 990 need the service totals; they don't need the name. Deleting outright throws away the organization's own statistics to solve a problem removing the identity already solved. The claimed name/email on self-logged shifts are cleared too — they live on the *entry*, not the volunteer.
- **The eraser names the letter references it affected.** Silently destroying the record behind a document a court is holding is exactly the failure this plugin can't have; whoever handles the request has to know a reference may now be un-checkable.
- **The issued-letter log survives a purge.** A letter having been issued is a fact about the organization's own conduct. It holds a reference, an ID and a date — no name.
- **Retention reads the cached rollup; the letter refuses to.** Same data, different question: a cache a few hours stale can't change whether a record is over two years old, but it could change what a court reads.
- **Uninstall deletes nothing unless armed,** and even armed removes only options — never posts, never post meta, never the capabilities. The arming is a tick box on the Privacy tab under **Removing this plugin**, and the same section says plainly what deletion does and does not remove — because the policy being right is not much use if the only place it is written down is `uninstall.php`. Both failures were reachable in silence: an organization that deleted the plugin believing the records went with it, and one obliged to remove those records with no route short of WP-CLI.
- **A copy of this site must not email the real people on it.** `GWCVT_MAIL_MODE` set to `off` sends nothing; set to `trap`, with `GWCVT_MAIL_ALLOW` naming an address, redirects every message there with the site's host in the subject. Both go in `wp-config.php` **on the copy**, because the copy is what you control when you make one; unset means normal delivery, so production needs no configuration. Trap mode with no valid address sends nothing rather than falling through to the real recipient — falling through would defeat the setting at precisely the moment somebody was relying on it. This is the single most consequential operational fact about running this plugin, and it is documented in `readme.txt`, in the Privacy help tab and here, rather than only in `inc/emails.php`.
- **Printing is logged, not just emailing.** A printed letter has left the building just as much as an emailed one, and a log that only knew about email would answer "no letter was issued" about a letter somebody is holding.
- **There is no theme-overridable letter template, on purpose.** All the prose an organization is likely to change — intro, disclaimer, reference note, letterhead, signatory, email subject and covering note — is a setting on the Letter tab, with `{org} {name} {hours} {shifts} {period} {contact} {reference} {timestamp} {timezone}` placeholders. The document's furniture (headings, column headers, row labels) is filterable via `gwcvt_letter_strings`. What there is *not* is a template file a theme can own — because a theme that owns the markup is a theme that can delete the disclaimer, and the disclaimer not being deletable is the one structural promise this plugin makes about its own output.
- **The email's covering note sits outside the letter.** A short "here is your letter" message is reasonable to want; putting it *inside* the document would mean the emailed and printed letters were no longer the same document.
- **The emailed and printed letters come from one template.** Two templates drift, and the day they have drifted is the day a court receives a letter that differs from the organization's copy. The email's CSS is inlined by a hand-written ~20-rule map, which is only possible because the intro and disclaimer are sanitized to plain text rather than `wp_kses_post()`.

- **An event is a container over shifts, never a new kind of slot.** A SignUp Genius slot is *(a role) × (a time window) × (how many people)*, and a `gwcvt_shift` is already exactly that — so `gwcvt_event` sits above shifts by `post_parent` and every slot stays a shift, every signup stays a signup. Waiting lists and the settling lock, reminders and their idempotency flag, cancellation tokens, calendar files, the privacy exporter, the retention sweep, no-show derivation and reconciliation into hours all keep working with no new code. A parallel slot type would need every one of those written twice, and the one that would hurt is reconciliation: two paths by which hours reach a court letter is two places for hours to reach a court letter *wrongly*, and the second would be the one nobody has been staring at for four releases.
- **`gwcvt_shifts_between()`'s `parent` argument defaults to everything, and must stay that way.** The understaffed and unreconciled queries both run through it. Filter parented shifts out by default and the reconciliation nag silently stops covering events — hours nobody typed up, on the number a letter is built from, with nothing on any screen to say so. Exactly two callers opt *in* to `parent => 0`, and both show events separately instead.
- **A post status must fit twenty characters.** `wp_posts.post_status` is `varchar(20)`. A longer one is not an error, is not truncated and is not warned about — `wp_insert_post()` sanitises what it cannot store and the row keeps the status it already had. `gwcvt_event_cancelled` was twenty-one, so calling an event off reported success while the event stayed published and went on taking signups. `tests/integration/events.php` asserts a cancelled event reads back as cancelled, and asserts every registered status fits.
- **The grid is role-major.** A role is named once and its times hang underneath it, so "Greeter" and "greeter" cannot both exist inside one event — which is what makes a role taxonomy unnecessary. Everything a role passes down — supervisor, location, what-to-know — follows one rule: the most specific non-empty value wins, and **nothing appends**. A chain where one inherited field appends while the others replace is a rule nobody remembers. A genuine one-off note goes in the single-shift editor, which an event slot already has.
- **The grid works with no JavaScript**, by rendering blank rows the way the quick-add screen renders eight of them. There is no build step, so a grid that needed a script to add a row would be a screen that does nothing when the script fails to load. Field names carry explicit indexes — an unticked checkbox posts nothing at all, so a positional array arrives with its indexes closed up and every row after the first gap reads its neighbour's answer.
- **Removing a time from the grid cancels it when anybody is on it and deletes it only when nobody is,** and the row says which before you save. The rule that a shift with a roster is cancelled rather than deleted does not lapse because the removal arrived from a grid instead of a button. Removing a whole role decides that per time rather than for the role: the busy Saturday stays on the schedule, called off, and the three empty ones go.
- **The screen says what a control does; the code comment says why.** Help text on this plugin's screens drifted into arguing with the reader — a date field that read *"there is nothing to fill in here, a second date field is a second answer and it disagrees with the first the moment somebody moves one time"*, a picker that explained which form's job a claim was. Every one of those sentences is a design rationale, and the person who needs a design rationale is the next developer, reading the file. The coordinator wants to schedule Saturday. So: on screen, what this control does and what will happen; in the box comment, why it works that way. Worked examples belong in the field's placeholder, not in a paragraph beside it.
- **Lifecycle is an action, never a field on the form.** Calling a time off, putting it back, deleting an empty one and dropping a role are each a button with their own nonce that happen at once and report what they did — the shape `inc/admin-shift.php` already used. They began as checkboxes on the grid's one Save, and every UX defect this feature shipped was a symptom of that: the tell was copy reading *"all 3 of its times go **when you save**"*, a sentence that only has to exist because the action is deferred. An immediate action does not predict a future, and the screen it returns to cannot look identical to the one it left.
- **The two that cost somebody something stop to ask.** Calling off a time people are on, and dropping a whole role, each get a screen with one decision on it — because both need a reason typed and both decide whether people get an email, and neither fits on a row. Putting a time back on and deleting one nobody is on happen on a nonced link, exactly as taking somebody off a roster does. A mutating GET is fine here and is not fine on the public side: the rule against it is about links that arrive in email, where a mail client's prefetch would follow them.
- **A cancelled time has to look cancelled.** It first came back from a cancellation looking exactly like every other row — editable times, and a Remove box still offering to cancel a thing already cancelled — with the word "Cancelled" in one column the only difference. So a coordinator ticked the box, saved, saw an unchanged row and concluded it had not worked, when it had. That is worse than a feature that fails, because the state is real and nothing on the screen agrees with it. The row is now struck through, carries its reason, cannot be edited, and offers the one action that makes sense on it.
- **Tests that call the function under the form cannot see that class of bug.** `tests/integration/events.php` drove `gwcvt_save_event_grid()` and passed throughout. `tests/integration/event-editing.php` drives `gwcvt_handle_save_event()` through `$_POST` the way the browser fills it, and asserts what the *next screen says* as well as what the database holds.
- **Emptying a role's name while it still has times is refused, not guessed at.** Keeping the old name hides a rename that did not happen; dropping the times loses a roster. Neither is a decision to make on somebody's behalf.
- **The event grid shows counts and never names,** exactly as the shift list does. This is where the plugin deliberately diverges from the products it resembles, which publish their rosters by design.
- **A multi-slot signup returns the same message as a single one, with no count.** The leak is not the digit — it is a digit that reports *what the write did*. Signing up is idempotent and the form never checks whose address it was given, so "3 added" against "2 added, 1 you were already on" tells a stranger which slots somebody else was already on. A count of what was *ticked* would in fact be safe; the rule bans all of them because "no digits" is one assertion a test can hold, and "only digits from the request" is a rule the next friendly copy edit will break.
- **An overlap is warned about inside one submission and only flagged across them.** Inside a submission both slots are in the POST, so nothing is looked up. Across submissions the check would need a query keyed on an email address whose answer changes the page — and *"its answer changes nothing the visitor can see"* is the single clause `gwcvt_find_signup()`'s licence rests on. It is a warning rather than a refusal, because ducking out of the kitchen at noon to greet is a real thing people do; and it runs **before** the honeypot branch, so the same POST gets the same answer either way and the difference cannot be used to detect the honeypot. Touching is not overlapping.
- **One confirmation per submission, carrying one cancel link and one calendar link per slot.** That is what keeps a token scoped to a single slot — widening one to everything an address holds would mean a forwarded email disclosing the lot — while still answering "I can only drop the Sunday".
- **The reminder lists every slot inside the window, and the invariant is not one flag per email.** It is: *a slot's flag is set if and only if that slot was named in a message that was sent.* Mark every slot you are about to mention, before sending, then send once. Name one without marking it and it goes twice; mark one without naming it and it is **never** reminded about — silently, which is why the integration test asserts it directly.
- **Nobody is asked to confirm they are still coming.** An unclicked cancel link means nothing; an unclicked confirm link looks like an answer, and every reading of that answer is bad — a place dropped because somebody did not read an email, or a sorted list of people who do not reply, kept about a population working off court orders. Prefetching makes it worse rather than merely awkward: a scanner that follows the link confirms everybody, and nothing tells the real ones apart. What the waiting list gets instead is a coordinator who can promote somebody by hand, on actual information rather than an inference from silence.
- **Staff put somebody on a slot by picking a volunteer record, never by typing a name and an address.** A typed one would manufacture an untriaged claim, which is the public form's job. A staff add over the maximum lands on the waiting list like any other — capacity has one authority and it is not whoever is on the phone.
- **Events are copied, not repeated.** Crossing recurrence with the grid multiplies every exception case — a rule per role per time, an exception list per occurrence, and a signup that has to attach to something that is not a row. The copy is a draft, because an event that went live because somebody clicked Copy is a public page nobody has read.

### Two departures from the sibling plugins

Both are argued at length in the plan and in the code; the short version:

- **A few plain typed classes exist**, for computed in-memory values only — the letter model and its rows, and the totals object. Persisted config (the field schema, field definitions) stays an array, exactly as in the siblings. The rule is *objects for computed values, arrays for persisted config*, and it exists because the letter model is the one structure whose correctness is the entire product. There is still no Composer, no autoloader, and no build step.
- **There is one REST route**, `gwcvt/v1/volunteers`, for the entry screen's volunteer picker. Every post type stays `show_in_rest => false`. The siblings' "no REST" rule is really an argument against *auto-generated* CPT routes, which is right and is kept; for a purpose-built lookup, `permission_callback` and an `args` schema are better guard rails than a hand-rolled `check_ajax_referer` that fails open when forgotten.

## Hooks

Every hook in the plugin is in this table. If you add one, add its row.

| Hook | Type | Purpose |
| --- | --- | --- |
| `gwcvt_capabilities` | filter | Remap the capabilities gating verification, letter issuance and settings. |
| `gwcvt_default_cap_roles` | filter | Which roles are granted the plugin's capabilities when first seen. |
| `gwcvt_hour_increment` | filter | The increment every logged duration is rounded to, in minutes. |
| `gwcvt_post_type_args` | filter | `register_post_type()` arguments for hour entries. |
| `gwcvt_volunteer_post_type_args` | filter | `register_post_type()` arguments for volunteers. |
| `gwcvt_admin_tabs` | filter | The settings screen's tabs, keyed by slug. |
| `gwcvt_menu_order` | filter | The order of the Volunteer Hours submenu, by slug. |
| `gwcvt_dashboard_items` | filter | The dashboard's worklist, after empty queues are dropped. |
| `gwcvt_dashboard_map` | filter | The dashboard's map of everywhere else, already filtered by capability. |
| `gwcvt_reporting_year_start` | filter | The first day of the reporting year. |
| `gwcvt_entry_saved` | action | After an hour entry is saved from the admin. |
| `gwcvt_settings_screen_loaded` | action | When the settings screen loads, before output. |
| `gwcvt_render_tab_<slug>` | action | Render the body of one settings tab. |
| `gwcvt_attestation_methods` | filter | The ways an entry can be attested to, keyed by slug. |
| `gwcvt_entry_verified` | action | After an entry is attested to. |
| `gwcvt_entry_unverified` | action | After an attestation is withdrawn. |
| `gwcvt_letter_post_type_args` | filter | `register_post_type()` arguments for the issued-letter log. |
| `gwcvt_letter_model` | filter | The assembled letter, before it is rendered. |
| `gwcvt_letter_reference` | filter | A letter's reference code. |
| `gwcvt_email` | filter | A message (to, subject, body, headers) before it is sent. |
| `gwcvt_letter_issued` | action | After a letter is produced and logged, for print as well as email. |
| `gwcvt_letter_strings` | filter | The letter's fixed wording — headings, column headers, row labels. |
| `gwcvt_settings_fields` | filter | The settable settings, keyed by name. |
| `gwcvt_settings_saved` | action | After the settings have been saved. |
| `gwcvt_retention_due` | filter | Whether a volunteer's record is due for purging. |
| `gwcvt_before_purge` | action | Before a record is anonymized or deleted — last chance to export it. |
| `gwcvt_purged` | action | After a record has been purged. |
| `gwcvt_rate_limits` | filter | The public form's rate limits, keyed by scope. |
| `gwcvt_self_log_received` | action | After the public form has recorded a submission. |
| `gwcvt_entry_attached` | action | After a self-logged entry is attached to a volunteer. |
| `gwcvt_shift_post_type_args` | filter | `register_post_type()` arguments for scheduled shifts. |
| `gwcvt_signup_post_type_args` | filter | `register_post_type()` arguments for signups. |
| `gwcvt_shift_created` | action | After a shift is put on the schedule, once per occurrence of a repeat. |
| `gwcvt_shift_cancelled` | action | After a shift is called off, carrying the reason and the roster it had. |
| `gwcvt_shift_reconciled` | action | After a shift's roster is turned into hour entries, carrying the entries made. |
| `gwcvt_schedule_visible_shifts` | filter | The shifts shown on the public list; anything removed is also refused by the handler. |
| `gwcvt_ics_event` | filter | A shift's calendar-file lines, before they are folded. |
| `gwcvt_signup_received` | action | After somebody is put on a shift. |
| `gwcvt_signup_withdrawn` | action | After somebody comes off a shift. |
| `gwcvt_signup_attached` | action | After a signup is matched to a volunteer record. |
| `gwcvt_signup_promoted` | action | After somebody is moved off the waiting list by hand. |
| `gwcvt_event_post_type_args` | filter | `register_post_type()` arguments for events. |
| `gwcvt_event_created` | action | After an event is copied to a new date, carrying the event it came from. |
| `gwcvt_event_cancelled` | action | After an event is called off, carrying the reason and how many people were told. |
| `gwcvt_event_slots_saved` | action | After a grid save, carrying slots made, updated, cancelled and deleted. |
| `gwcvt_event_visible_slots` | filter | The slots shown on an event's public grid, keyed by role. |
| `gwcvt_event_signup_limit` | filter | The most slots one submission may take. |
| `gwcvt_event_role_dropped` | action | After a whole role is dropped from an event, carrying what was cancelled, deleted and told. |

## Tests

PHPUnit 11, run from a downloaded phar. There is no Composer dependency.

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar && php phpunit-11.phar
```

`tests/bootstrap.php` stubs the WordPress surface the pure logic touches — no database, no WordPress checkout. Its `add_filter`/`apply_filters` are real and priority-ordered, because several of the plugin's registries are built by self-registering filters and a no-op would leave them empty under test while every assertion still passed.

Anything that genuinely needs WordPress runs under wp-env:

```bash
npx @wordpress/env start
```

Then the integration scripts, each of which creates and removes its own fixtures:

```bash
npx @wordpress/env run cli -- wp eval-file wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/letter.php
```

## Continuous integration

`.github/workflows/test.yml` runs four jobs on every push and pull request:

| Job | What it answers |
| --- | --- |
| `lint` | Does every shipping file parse on PHP 7.4 — the version the header promises? |
| `unit` | Is the logic right? PHPUnit 11 on 8.2, 8.3, 8.4. |
| `integration` | Does it actually run? The six scripts under wp-env, on **7.4 and 8.3** — the two ends where the interesting failures are. |
| `version` | Do the header, the constant, `Stable tag` and the changelog still agree? |

## Demo data

```bash
npx @wordpress/env run cli -- wp eval-file wp-content/plugins/groundwork-common-volunteer-tracker/tests/seed.php
```

Builds Riverbend Food Bank: six volunteers covering the states worth looking at — one working off a court order with 29.75 verified hours and a letter already on file, one with hours still waiting, one brand new with nothing verified, one with no email so their letter can only be printed, one dormant since 2023 and due under the retention policy, and one dormant but held back by an open case. Plus two self-logged submissions nobody has matched yet.

Re-runnable: it removes what a previous run created, and only that — everything it makes is tagged, so a record you added by hand survives. It refuses to run unless `WP_ENVIRONMENT_TYPE` is `local` or `development`, because it writes settings and deletes records.

Every name in it is invented and every address is on `example.test`. That is deliberate: this plugin's demo data is people's names beside a number of volunteer hours, and for two of them beside the fact that they are working off a court order. The same rule applies to screenshots — see `.wordpress-org/README.md`.

## The beta site

<https://wp.beta.poo6op.com> is a shared demo and beta install carrying **all three** Groundwork Common plugins — this one, the location finder and the post portal — seeded as one organisation rather than three unrelated demos. It is where a change is looked at in a browser on real hosting before it is merged.

```bash
bin/deploy-staging.sh              # deploy the working tree, then activate
bin/deploy-staging.sh --dry-run    # show what would change, send nothing
```

It deploys **whatever is checked out right now**, branch and uncommitted edits included. That is the point — waiting for `main` would defeat the purpose. Nothing in the script reads git state.

Once per machine, tell it where the site is. The target is **not** in the repository and is not going into it: an SSH user and host are not a credential, but together they name a valid account on a specific public host — the half of a break-in that is normally the work. These repos are private, and "private today" is a weaker promise than "never committed", because the setting reverses in one click and history outlives it. Note too that `.distignore` keeps this script out of the release *zip* and does nothing about the *repository* — two different exposures, and only one of them was covered. So the target lives in one file outside every repo, shared by all three plugins because it is one beta site:

```bash
mkdir -p ~/.config/groundwork-common && cat > ~/.config/groundwork-common/beta.env <<'CONF'
SSH_HOST=user@host.dreamhost.com
DEST_ROOT=wp.beta.example.com
SITE_URL=https://wp.beta.example.com
CONF
```

Without it the script stops and prints exactly that, rather than falling back to a default. Set `GWC_BETA_ENV` to keep the file elsewhere.

What gets sent is `.distignore`, the same file `wp dist-archive` reads, so what lands on the beta site is what a user would install and there is no second exclusion list to fall out of step with the first. `tests/` is therefore *not* deployed; to reseed, copy `tests/seed.php` up and run it by absolute path:

```bash
wp eval-file ~/beta-seeds/gwcvt-seed.php
```

Three things about that host are worth knowing before working on it:

- **Production shares the login.** `groundworkcommon.com` — a live nonprofit site — is in the same home directory on the same SSH user. A wrong destination path does not fail, it succeeds against production. The script refuses to run unless it finds a beta-only mu-plugin at the target, so a typo stops the run instead of redecorating a live site.
- **`WP_ENVIRONMENT_TYPE` is `development`, not `staging`.** The seed scripts refuse to run outside `local` and `development`, and seeding is the whole point of the box. Everything else keying off `wp_get_environment_type()` relaxes there too, which is exactly why no real record may ever live on it.
- **No mail leaves it.** An mu-plugin intercepts `wp_mail()` at `pre_wp_mail` and stores the message instead of sending it; read what would have gone out under **Tools → Trapped mail**. This plugin sends on cron — shift reminders, waiting-list promotions — against invented data, and an invented address is only safe until somebody seeds a real one out of habit. The hook is `pre_wp_mail` rather than `phpmailer_init` deliberately: the latter can only redirect a send, not stop it, and a PHPMailer failure would make `wp_mail()` return false, which this plugin reads as "not sent" and retries on every cron pass.

WP-CLI on that shared host takes roughly thirty seconds per invocation, because it bootstraps WordPress each time. Batch work into one `wp eval-file` rather than chaining several `wp` calls.

## Still to come

Milestones, in order: the data model and hour logging, staff verification, the letter and its reference verifier, the optional front-end self-log form, retention with WordPress's privacy exporter and eraser, and the schedule with its rosters.

Scheduling arrived in four parts and is complete: 0.8.0 built the schedule and its rosters, 0.9.0 closed the loop by turning a roster into hours, 0.10.0 opened it to the public, and 0.11.0 added the reminders and notices that make it work unattended. The whole loop now runs — sign up, get reminded, show up, hours recorded, hours verified, letter out.

0.12.0 added what a mandated volunteer still has to complete — how many hours, by when, and for whom — on their record and on the volunteer list, and nowhere near a letter.

0.14.0 added **events** — one occasion with several roles at several times, built as a container over shifts so that nothing underneath them changed. The prototype the screens were drawn against is kept on the branch that built them, at `.design/events-ux-prototype.html`.

0.15.0 was a pass over the whole thing from a user's seat rather than a feature milestone, and most of what it found were gaps *between* screens rather than screens that were wrong: an event that could not be reached because it has no URL and nothing said so, event hours counted by a nag no screen could act on, three letterhead fallbacks that compound into a court letter headed with a website's title, a self-log form that submitted into nothing when placed on the wrong page, and two capabilities the code argues must be separable with no way to separate them. The Permissions tab, the first-run dashboard, the "Removing this plugin" section and the documented mail guard all come from that pass.

Next: **screenshots.** `readme.txt` advertises eight and `.wordpress-org/` holds none — no icon either, which is the first thing a stranger sees. `tests/seed.php` builds every state worth photographing, and the shooting guide in `.wordpress-org/README.md` is current.

Then a decision rather than a feature: the field schema from the original plan — `schema.php`, the Fields tab, the "Court-ordered service" preset — was never built, and `GWCVT_SCHEMA_VERSION` still has no migration runner behind it. Hours-required covered part of what that preset was for, so it is worth settling whether the rest is still wanted before building anything else that might have belonged there.

Emailed supervisor confirmation — a link a shift supervisor clicks, with no account — is still the first thing after 1.0.

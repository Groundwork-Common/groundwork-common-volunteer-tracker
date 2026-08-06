# Events: one occasion, several roles, several times

A plan for SignUp Genius–style events, against the plugin as it stands at 0.13.0.

## The decision this whole plan turns on

An event is a **container over shifts**. It is not a new kind of slot, and there is
no third post type between the event and the person.

The reason is arithmetic. A SignUp Genius slot is *(a role) × (a time window) ×
(how many people)*. A `gwcvt_shift` is already *(an activity) × (a date and a time
window) × (a minimum and a maximum)*. They are the same record. What a coordinator
calls "Greeter, 9–12, we need 3" this plugin already stores.

So:

```
gwcvt_event  ──post_parent──▶  gwcvt_shift  ──post_parent──▶  gwcvt_signup
"Fall Festival,               "Greeter, 12 Oct,              "someone, one slot"
 12 October"                   09:00–12:00, max 3"
```

Everything below the event is untouched machinery.

### What refusing a third type buys

Every one of these keeps working with no new code, because an event slot *is* a
shift and an event signup *is* a signup:

- Waiting lists and the `add_option()` settling lock.
- The reminder pass and its idempotency flag.
- Cancellation tokens, and the revision counter that retires a stale one.
- The calendar file.
- **Reconciliation into hour entries** — the path that reaches a court letter.
- No-show derivation by absence, with nothing stored.
- The privacy exporter, the eraser, and the orphan-signup retention sweep.
- Cancelled-shift and moved-shift notices.

A parallel `gwcvt_event_slot` type would need all of that written twice. The one
that would hurt is reconciliation: two ways for hours to arrive at a letter is two
places for hours to arrive at a letter wrongly, and the second one would be the new
one nobody has been staring at for four releases.

### What it costs

`gwcvt_shifts_between()` currently returns every shift regardless of parent, and
every caller assumes a flat list. Three of those callers want different answers now,
and getting the default wrong is the first trap below.

## Data model

### `gwcvt_event`, a new CPT

Registered exactly like `gwcvt_shift`: `public => false`, `show_ui => false`,
`show_in_rest => false` (hard rule 2 — an event carries a location and a
description, and its children carry names), `has_archive => false`,
`supports => false`, `capability_type => 'post'`, filtered through
`gwcvt_event_post_type_args`.

Title is typed here rather than derived. Unlike a shift, an event has a name a human
chose — "Fall Festival", "Thanksgiving Meal Service" — and that name is the thing
volunteers recognise. It is the one place in this plugin where a title is real.

Meta:

| Key | Holds |
| --- | --- |
| `_gwcvt_event_date` | First day, `Y-m-d`. Derived from the earliest slot on save; stored so the event can be ordered by meta the way shifts are. |
| `_gwcvt_event_end_date` | Last day, `Y-m-d`. Same derivation. Equal to the first for a one-day event. |
| `_gwcvt_event_location` | Where. Slots may override; most will not. |
| `_gwcvt_event_description` | What it is, sanitised to plain text. Shown to the public above the grid. |
| `_gwcvt_event_supervisor` | Default supervisor, inherited by slots that do not set one. |
| `_gwcvt_event_page` | Optional page ID the grid is pinned to. See "Public surface". |

A cancelled status, `gwcvt_event_cancelled`, mirroring `gwcvt_shift_cancelled` and
for the same reason: people signed up, and "this was called off" is an answer the
organisation owes them.

The dates are **derived, not typed**. A coordinator who builds a grid has already
said when every slot is; asking again is a second source of truth that will
disagree with the first the moment somebody moves one slot.

### The link: `post_parent`, not a meta key

Shift's `post_parent` is currently unused. Using it for the event mirrors
signup → shift exactly, needs no join, and needs no new key.

It does **not** collide with `GWCVT_SHIFT_SERIES`, and that is worth being explicit
about because both are groupings. A series is "this shift, repeated" — the same role
at the same time on twenty Saturdays. An event is "these different roles, one
occasion." They are orthogonal, they compose, and forcing them through one field
would make a recurring festival impossible to represent.

`wp_delete_post()`'s child-reparenting only fires for hierarchical types and only
across the same post type, so a non-hierarchical `gwcvt_event` will not have its
slots silently reparented. Deleting an event with slots is refused anyway — see
below.

### Roles are the activity string, and stay free text

`GWCVT_SHIFT_ACTIVITY` is the role. No taxonomy in v1.

The obvious objection is that free text diverges: "Greeter" and "greeter" become two
columns in the grid. The fix is not a taxonomy, it is the editor's shape — the grid
builder is **role-major**. You name a role once and add time slots underneath it, and
the role string is copied to every shift that role creates. The string is entered
once per role, so it cannot diverge within an event.

Cross-event consistency is worth less than it looks and costs a migration, a
governance question and a UI. A `<datalist>` of activities already exists
(`gwcvt_activity_vocabulary()`, used by the quick-add screen) and gets most of it.

### "What to know" is role-level too

`GWCVT_SHIFT_NOTES` already exists on every shift, is already labelled *"What to
know"* in the single-shift editor, and already reaches four places: the public list,
the calendar file's `DESCRIPTION`, the confirmation and the reminder. It is the
what-to-bring channel, not decoration.

In a grid it belongs on the **role**, beside the role's name and supervisor, and is
copied to every shift that role creates. Near enough every note anybody writes on a
grid — *hot work and closed shoes, some lifting, ring the bell at the north gate* —
is a fact about the work rather than about a particular hour. A 1000-character
textarea also cannot live in a table row somebody is reading eight of.

The consequence is that the note shows on every time in that role, and that is the
correct default. For a genuine one-off, the grid links each row to the single-shift
editor that already exists — an event slot *is* a shift, and that editor already has
the box. That escape hatch is what lets the grid avoid a second notes field, and with
it the question of whether a slot's note replaces the role's or is appended to it. A
chain where one inherited field appends while the supervisor and the location replace
is a rule nobody will remember.

**One consequence that must be said out loud in the editor.** The activity flows
shift → reconcile screen → `GWCVT_ENTRY_ACTIVITY` → the letter, and it is inside the
reference digest. A role called "Trash Duty" is a line in a document a judge reads.
The editor's help text should say that role names are printed on verification
letters, so they get named as work rather than as jokes.

## Admin

### The event editor

One screen, following `inc/admin-shift.php`'s pattern (a view of the schedule
screen, not a registered menu page — the same argument about hidden submenus
applies). Two halves:

**The event.** Name, description, dates, location, default supervisor, published or
draft.

**The grid, role-major.**

```
Greeter                                    [remove role]
   09:00–12:00   needs 2   max 3           [remove slot]
   12:00–15:00   needs 2   max 3           [remove slot]
   [add a time]

Kitchen                                    [remove role]
   08:00–14:00   needs 4   max 6           [remove slot]
   [add a time]

[add a role]
```

Each row is one shift. Save creates missing shifts, updates changed ones, and — the
important part — **cancels rather than deletes any slot that has people on it.** The
existing rule ("a shift with people on it is cancelled, never deleted") is not
suspended because the removal came from a grid instead of a button. An empty slot is
deleted; a slot with a roster is set to `gwcvt_shift_cancelled` and stays visible,
struck through, with its reason. The screen says which happened before it happens.

No JavaScript requirement. Add-a-role and add-a-time can be plain submits that
re-render the form with an extra blank row, exactly as the quick-add screen renders
eight blank rows. Progressive enhancement is welcome; dependence is not — there is no
build step (hard rule 8) and the block editor's hand-written ES5 is the ceiling.

**Copying an existing event** is the answer to "we do this every month", and it is a
much smaller feature than crossing recurrence with the grid. One button, one new
event, the same roles and times against a new date, nobody signed up.

### The schedule screen

Add a fourth view: **Events**, listing events forward from today with a fill summary
("14 of 22 places filled, 3 roles short"). An event row expands to its slots, which
render with the existing `gwcvt_render_schedule_row()`.

The existing flat schedule list should **group** event slots under their event rather
than hide them — a coordinator scanning next Saturday needs to see that the festival
has two of six greeters, and a flat list that omits it is a list that lies by
omission.

### Putting somebody on by hand

This already exists for shifts and needs one change for events. `gwcvt_render_shift_roster()`
carries a **"Put somebody on this shift"** form — the REST-backed volunteer picker,
then Add them — and `gwcvt_handle_roster_add()` calls `gwcvt_add_signup()` with
`source => 'staff'`. It refuses a `volunteer_id` of 0, and the help text says why:
*"Most signups at this size are a phone call. Somebody who is not on file yet needs a
volunteer record first."*

That constraint is right and stays. A staff member typing a name and an address would
be manufacturing an untriaged claim, which is the public form's job and the triage
screen's problem; the coordinator's job is to say *who this is*.

An event needs the same form plus a slot, because there are now several. Order it
volunteer first, then slot — a coordinator doing this is on the phone to somebody they
already know, hunting for a time that suits — and show each slot's fill in the picker
so they can steer the call ("kitchen still needs four"). A staff add over the maximum
lands on the waiting list exactly as a public one does, and the screen says so rather
than silently overfilling: capacity has one authority, and it is not whoever is on the
phone. A coordinator who means it raises the maximum.

**"Print the roster" needs an event form too.** Today it is one clipboard sheet per
shift — the sheet that comes back marked up and gets typed into Log a day. Eight
separate sheets for one festival is wrong; it wants one document, split by role and
time, with a signature column per slot.

### Overlaps: warned inside one submission, flagged across them

Somebody signing up for "Greeter 9–12" and "Kitchen 8–2" has double-booked
themselves.

**This is not the same kind of conflict as being over capacity, and the first draft of
this plan wrongly treated it as one.** Over the maximum is the organisation's
constraint colliding with somebody who wants to come, and refusing punishes the
volunteer for a number a coordinator typed in March. An overlap is the volunteer's own
physical impossibility. It is not a want at all — it is nearly always a misclick on a
grid, and it fails in the direction that hurts the organisation: the slot reads as
filled, the coordinator stops recruiting for it, and nobody turns up. A
phantom-filled slot is worse than an empty one.

So the question is only whether it can be detected, and that splits.

**Inside one submission — detectable, and worth doing.** Both slots are in the POST.
Nothing is looked up and the sender learns only what they already ticked, so it is
safe under hard rule 3 by construction.

It is a **warning, not a refusal.** Overlapping slots are occasionally deliberate —
ducking out of the kitchen at noon to greet is a thing that happens at real events —
and a hard block forbids something a coordinator would happily allow. So the form
re-renders with every tick preserved, names the two slots that clash, and offers one
confirmation. That catches the misclick without forbidding the intent.

Run the check on the parsed request **before** the honeypot branch, so that every path
gives the same answer to the same POST and the message cannot be used to detect the
honeypot.

Touching is not overlapping: 07:30–09:00 followed by 09:00–12:00 must not warn.
Strict comparison, no tolerance — a fuzzy one needs a setting, and this does not
deserve a setting.

**Across submissions — closed, not merely hard.** Asking whether the address just
typed already holds an overlapping slot means querying prior signups by email and then
*changing what the visitor sees* based on the answer. That is the neighbour attack in
a new dress: type somebody's address, tick Kitchen 08:00–14:00, and "that clashes with
something you are already down for" reports that they hold something overlapping those
hours.

`gwcvt_find_signup()` is permitted its email-scoped lookup for one stated reason —
*"its answer changes nothing the visitor can see."* A cross-submission clash warning
breaks that clause, which is the clause the whole lookup rests on.

So a clash with an earlier submission is a **coordinator-side flag on the roster**, and
nothing is blocked there either: staff can see the whole picture, and may well know
that Dana is doing the kitchen until noon on purpose.

## The public surface

### One page per event, one form, checkboxes

The existing public list is radios — one shift, one nonce, no JavaScript. An event
grid is the same form with **checkboxes**, so one person can take several slots in
one submission. Still one form and still one nonce.

Rendering rules, unchanged from `inc/signup-form.php`'s box comment and not
negotiable: **counts, never names.** This is where events diverge hardest from
SignUp Genius, which publishes the roster by design. On a site running a
court-ordered service programme the roster for Saturday is a list of people working
one off. The grid shows "1 place left" and "Full — you can join the waiting list",
and there is no setting that changes it.

### The response strings do not change

This is the single most dangerous part of the feature, and it is worth being precise
about why, because the imprecise version of the rule is easy to argue away.

`gwcvt_add_signup()` is idempotent — a second signup for the same slot by the same
address refreshes the first rather than making another — and the form never checks
whether that address belongs to anybody. So a stranger can type somebody else's
address and tick three boxes. Three new signups, two new and one refresh, or three
refreshes: **if the response tells those apart, they have learned which of those
slots that person was already on**, without ever being told a name. On a site running
court-ordered service that is a disclosure about a named person, obtained from a
public form by somebody who supplied nothing but a guess at an email address.

So the leak is a count of *what the write did*. A count of what was *ticked* reports
only what the sender already posted, and a honeypot hit or a rate-limited attempt can
produce that one too without touching the database — so it does not, on its own,
break the byte-identical rule in hard rule 3.

**The rule is still "no number", and that is a judgement rather than a necessity.**
The invariant is only cheap to hold in its blunt form. "This response contains no
digits" is one assertion, the shape `SelfLogTest` already uses. "This response may
contain a digit, but only one derived from the request and never from what the write
returned" holds until the first person who decides it would be friendlier to mention
you were already down for the kitchen — and that change arrives in review looking
like a copy edit.

So: **the existing message table is closed.** Every slot accepted returns the
existing `accepted` string, unchanged. Any slot unavailable returns the existing
`unavailable` string. No counts, no enumeration of which ones, no new keys except
`too-many` below. The confirmation email — which only that address receives — is
where the detail goes.

### A cap on slots per submission

Selecting the whole grid is one human act but many writes. Cap it (20 is generous
for any real event) and refuse over it with a distinct message. That message is safe
because it depends only on what the visitor posted, not on anything the site knows.

The rate limiter counts the submission once. It is one person pressing one button.

### Where the grid lives

Two options, and I would ship the first:

1. **A block/shortcode that takes an event ID**, dropped on a page the site already
   controls. This matches how everything else in the plugin reaches the front end
   (`schedule_page` is a pinned page ID), keeps `publicly_queryable => false`, and
   means no rewrite rules and no permalink surface for a post type holding a
   location.
2. A public permalink for the event type. Cheaper for the coordinator, but it turns
   on `publicly_queryable`, which is a lever this plugin has never pulled and which
   would need its own argument.

Either way `gwcvt_signup_dispatch()`'s `is_page()` gate has to widen to "the schedule
page **or** a page carrying an event grid", and it must stay a gate — a handler that
runs where the form is not rendered accepts posts to a feature the site has not put
anywhere.

## Email

### One confirmation per submission, not one per slot

Four slots must not be four emails. `gwcvt_queue_signup_confirmation()` already defers
to `shutdown`; the queue groups by *(claimed email, event)* and sends one message
listing every slot from that submission.

Each listed slot carries **its own cancel link and its own calendar link.** That is
what makes per-signup tokens sufficient: "cancel just the Sunday one" is answered by
having four links rather than by inventing a token that spans slots.

### One token still means one slot

The manage panel shows the slot its token authorises and no others. Widening it to
"everything this address signed up for" would mean a lookup keyed on email — the
exact shape hard rule 4 forbids on the form. The panel is behind a token so it is not
literally that oracle, but a forwarded confirmation would then disclose everything
that address ever booked, and the email already carries a link per slot. There is no
need worth the blast radius.

### The reminder lists the slots, and each one declines on its own

Today's reminder says "you are down to volunteer on Saturday". For one shift that is
exact. For somebody holding three places at a festival it is ambiguous in the worst
possible way: the decline link drops *one* signup, and the message does not say which.

So an event reminder is one message per person per event, listing every slot they
hold that is inside the reminder window, ordered by start time, each with its own
"I cannot make this one" link. The wording has to be explicit that declining takes
them off that slot and leaves the rest, for the same reason the manage panel says so.

**This reverses an earlier deferral in this plan, and the reason is worth keeping.**
The objection was that grouping breaks the one-to-one between a send and
`_gwcvt_signup_reminded_at`, which is what makes the hourly pass safe unattended.
That objection was aimed at the wrong invariant. The one that matters is not
*one flag per email* — it is:

> **A slot's flag is set if and only if that slot was named in a message that was
> sent.** Mark every slot you are about to mention, before sending, then send once.

Which gives the rule for slots further out: **mention only slots inside the reminder
window, and mark exactly the slots you mentioned.** A multi-day event's Sunday slots
are not named in Saturday's reminder and keep their own flags, so they get their own
message when they come due. Naming a slot without marking it sends it twice; marking
one without naming it means it is never reminded about at all — and that second one
is silent, which makes it the one to write a test for.

The failure mode is unchanged in kind and larger in blast radius: the timestamp is
written before the send, because `wp_mail()` returning false does not mean nothing was
delivered, so a process that dies mid-pass loses three reminders instead of one. That
is the same trade the single-slot pass already makes, and the alternative — an hourly
job that reminds the same person every hour forever — is still worse.

## Privacy and retention

Nothing new to build, and that is the payoff for refusing a third type:

- An event holds a name, a description, a location and dates. No personal data.
- Every signup is still a `gwcvt_signup`, so `gwcvt_signups_by_claim_email()`,
  `gwcvt_signup_ids_for_volunteer()`, `gwcvt_clear_signup_claims()` and
  `gwcvt_sweep_orphan_signups()` reach event signups with no change — the sweep
  queries by post type and status and never looks at the parent.
- The exporter's per-signup item names its shift; it will name an event slot the same
  way. Worth having it say which event, so an export reads as an occasion rather
  than as four unrelated Saturdays.

`tests/integration/privacy.php` should grow a case that erases someone who signed up
for three slots of one event, to hold this true rather than assume it.

## Considered and refused: asking volunteers to confirm

Every product this resembles has a "please confirm you are still coming" button in
its reminder. This one will not, and the reasoning is recorded here because it is the
kind of decision somebody reasonably re-opens in six months.

**What exists already is remind-and-decline.** The reminder carries the manage link
and says "if you cannot make it, please use that link to let us know — there is still
time for us to ask somebody else." The lever is there. What is absent is a positive
response and any record of one.

**Adding confirmation converts silence from meaningless into meaningful, and every
reading of it is bad.**

- *Silence means still coming* — then the button changes no decision and all it has
  added is a second thing to ignore.
- *Silence means dropped* — a place removed because somebody did not read an email.
  For a person working off a court order against a deadline that is precisely the
  "dead end the volunteer has no way to appeal" that advisory capacity exists to
  avoid.
- *Silence is shown to the coordinator as "3 unconfirmed"* — that is a sorted list of
  people who do not answer their email, on a screen, about a population working off
  court orders. It is a behaviour file with extra steps, and this plugin already
  refuses to store no-shows for that exact reason.

**And the GET problem makes confirming structurally worse than cancelling, not
merely equally awkward.** Mail clients prefetch links and security appliances follow
them, which is why cancelling is a POST behind a page with a button. Apply the same
constraint to confirming and there are two outcomes: a prefetched link confirms
everybody, so the signal is worthless and there is no way to tell which confirmations
were real; or a button-gated confirm gets the response rate that implies, and the
"unconfirmed" pile fills with people who are coming.

The asymmetry is the whole argument:

> An unclicked cancel link means nothing. An unclicked confirm link looks like an
> answer.

A feature whose commonest outcome is a *wrong* signal is worse than not having it,
because the coordinator starts discounting the real signups too.

**What events genuinely change, and what answers it.** The grid actively encourages
over-commitment — ticking five boxes three weeks out is much easier than committing
to five separate Saturdays. That is a real risk this feature introduces. It is
answered by the confirmation email showing the whole day at the moment of signing up,
which catches the enthusiasm while it is still fresh, and by the reminder naming each
slot separately so declining one is easy and obvious. Not by a button whose silence
has to be interpreted.

**The gap actually worth closing** is that waitlist promotion only ever fires on an
active withdrawal. A coordinator who knows a place is soft cannot free it. That wants
a promote action on the roster — human judgement, no email round-trip, no ambiguity.

**The other confirmation is a different feature and is still wanted.** README's
*Still to come* names emailed supervisor confirmation — a link a shift supervisor
clicks, with no account — as the first thing after 1.0. That is post-event
attestation, it feeds the letter, and events raise its value: one festival is eight
slots a coordinator currently writes up by hand from memory. It lives under the same
GET constraint, and its failure mode is safe — an unclicked supervisor link just
means somebody reconciles manually, which is what happens today.

## Traps

1. **`gwcvt_shifts_between()` must keep returning event slots by default.** The
   understaffed query and the unreconciled query both run through it. Filter parented
   shifts out there and the reconciliation nag silently stops covering events — and
   the failure mode is hours nobody typed up, on the record a letter is built from.
   Add an optional `parent` argument, default "everything", and opt *in* to
   `parent => 0` from the two places that want a flat standalone list
   (`gwcvt_public_shift_ids()` and the schedule's flat view). Never opt out by default.
2. **`gwcvt_public_shift_ids()` must exclude parented shifts**, or every festival slot
   appears loose on the generic signup page with no idea what it belongs to. It has a
   filter (`gwcvt_schedule_visible_shifts`) but the default should be right.
3. **Checkbox arrays need explicit indexes**, or the same failure the "came"
   checkboxes already hit: an unticked box posts nothing, so a positional array
   arrives with its indexes closed up. Post `gwcvt_slots[<shift_id>]` keyed by ID, not
   `gwcvt_slots[]`.
4. **Every field wrapper in the grid editor is a `<div>`.** The grid is a table and a
   list; a `<p>` wrapper makes the parser close the paragraph and produces
   valid-looking HTML that no script can find.
5. **Role labels and grid headers are functions with a static memo, never `const`.**
6. **`gwcvt_shift_moved()` should not fire on regrouping.** Moving a slot from one role
   to another, or an event's description changing, must not mail the roster. Only the
   existing list — date, times, overnight, location — counts as a move.
7. **Deleting an event with slots that have rosters is refused**, the same way deleting
   a shift with people on it is. Cancel the event, which cancels its slots and offers
   to tell everyone.
8. **`show_in_rest => false` on `gwcvt_event`.** An event's children carry names.

## New ledger entries

For README's **Things that are deliberate** — the rule is that new invariants go there:

- An event is a container over shifts, never a new kind of slot. Every slot is a
  `gwcvt_shift` and every signup is a `gwcvt_signup`, so reminders, waiting lists,
  cancellation tokens, calendar files, the privacy exporter, the retention sweep and
  reconciliation into hours all work unchanged. A parallel slot type would mean a
  second path by which hours reach a court letter, and it would be the path nobody
  has been watching.
- An event's dates are derived from its slots, never typed. The grid has already said
  when everything is; a second field is a second source of truth that disagrees the
  first time somebody moves one slot.
- Roles are the shift's activity, and the grid builder is role-major so the string is
  entered once. The activity is printed on the verification letter and is inside the
  reference digest — a role name is text a judge reads.
- Everything a role inherits — supervisor, location, what to know — is entered once
  on the role and copied to its shifts, and the most specific non-empty value wins.
  Nothing appends. A one-off note for a single time is made in the single-shift
  editor, which an event slot already has, rather than by growing a second box in the
  grid whose relationship to the first has to be explained.
- The event grid shows counts and never names, exactly as the shift list does. This is
  where the plugin deliberately diverges from the products it resembles, which publish
  their rosters.
- A multi-slot signup returns the same message as a single one, with no count. The
  leak is not the digit, it is a digit that reports **what the write did** — because
  signing up is idempotent and the form never checks whose address it was given, so
  "3 added" against "2 added, 1 you were already on" tells a stranger which slots
  somebody else was already on. A count of what was ticked would in fact be safe; the
  rule bans all of them because "no digits" is one assertion a test can hold, and
  "only digits from the request" is a rule the next friendly copy edit will break.
- One confirmation email per submission, carrying one cancel link and one calendar
  link per slot. That is what keeps a token scoped to a single slot while still
  letting somebody drop just the Sunday.
- An event reminder lists every slot the person holds that is inside the window, each
  declining on its own. The invariant that keeps the hourly pass idempotent is not one
  flag per email — it is **a slot's flag is set if and only if that slot was named in
  a message that was sent.** Name a slot without marking it and it is reminded twice;
  mark one without naming it and it is never reminded at all, silently.
- **Nobody is asked to confirm they are still coming.** An unclicked cancel link means
  nothing; an unclicked confirm link looks like an answer, and every reading of that
  answer is bad — a place dropped because somebody did not read an email, or a sorted
  list of people who do not reply, kept about a population working off court orders.
  Prefetching makes it worse rather than merely awkward: a scanner that follows the
  link confirms everybody, and nothing distinguishes those from the real ones.
- **An overlap is warned about inside one submission and only flagged across them**,
  and the reason is not squeamishness about refusing people — it is that the
  cross-submission check requires asking what an email address already holds and then
  varying the page by the answer, which is the one thing `gwcvt_find_signup()`'s
  licence forbids. Within a submission nothing is looked up, so the warning is free.
  A double-booking is unlike being over capacity: it is a misclick rather than a want,
  and it leaves a slot reading as filled that nobody will turn up to.
- **Staff put somebody on a slot by picking a volunteer record, never by typing a name
  and address.** A typed one would be an untriaged claim, which is the public form's
  job; the coordinator's job is to say who this is. And a staff add over the maximum
  lands on the waiting list like any other, because capacity has one authority and it
  is not whoever is on the phone.
- Removing a slot that has people on it cancels it. The rule that a shift with a
  roster is cancelled and never deleted does not lapse because the removal came from
  a grid.
- Events do not recur; they are copied. Crossing recurrence with the grid multiplies
  every exception case, and "duplicate this event against a new date" answers the
  same need in one button.
- An event grid never appears on the generic public shift list, and event slots never
  disappear from the understaffed and unreconciled queries. Getting the second one
  wrong is hours nobody types up.

## New hooks

Every one needs a row in the README table (hard rule 6):

| Hook | Kind | What |
| --- | --- | --- |
| `gwcvt_event_post_type_args` | filter | `register_post_type()` arguments for events. |
| `gwcvt_event_created` | action | After an event and its grid are first saved. |
| `gwcvt_event_cancelled` | action | After an event is called off, carrying the reason and its slots. |
| `gwcvt_event_slots_saved` | action | After a grid save, carrying slots made, updated and cancelled. |
| `gwcvt_event_visible_slots` | filter | The slots shown on an event's public grid. |
| `gwcvt_event_signup_limit` | filter | How many slots one submission may take. |

## Tests

**Unit** (`tests/EventTest.php`): grid save creates one shift per role×time; a
second save with a time removed cancels a slot that has a roster and deletes one
that does not; event dates derive from the earliest and latest slot; overlap
detection; the slot cap.

**Unit** (extend `tests/SignupFormTest.php`): a multi-slot accept, a partial
unavailable, a honeypot hit and a rate-limited attempt produce messages from the
existing closed set, asserted byte for byte.

**Integration** (`tests/integration/events.php`, added to the six the CI runs on 7.4
and 8.3): build an event with three roles across two times; sign up for three slots
in one submission; assert one queued confirmation carrying three cancel links; fill a
slot past its maximum and assert the waiting list; cancel one slot and assert the
other two survive; reconcile each slot separately into hour entries; run the privacy
exporter and eraser against that address and assert all three signups are found and
stripped.

Remember `$GLOBALS[...]` explicitly in the integration script — `wp eval-file` runs
the file inside a function, and a top-level assignment that a helper's `global` does
not reach is how a script prints ALL PASS under a list of failures.

## Build order

Each step ends somewhere shippable.

1. `inc/event-cpt.php` — the type, the cancelled status, the meta constants, the
   derived-date helpers. Required after `shift-cpt.php`, before `shifts.php`.
2. `inc/events.php` — reading an event: its slots, its fill, its dates, its state.
   Plus the `parent` argument on `gwcvt_shifts_between()` and the two opt-ins.
3. `inc/admin-event.php` — the editor and the grid save. Coordinator-usable with no
   public surface at all, which is a legitimate way to run it.
4. Events view on the schedule screen, and grouping in the flat list.
5. `inc/event-form.php` — the public grid, and the multi-slot path through
   `gwcvt_signup_dispatch()`. Nothing here without step 6 in the same release.
6. Grouped confirmation email.
7. Copy-an-event.

Version bumps in all four places together — header, `GWCVT_VERSION`, `readme.txt`
stable tag, changelog — or `VersionTest` and the deploy gate fail. New files must
pass `php -l` under 7.4: typed properties and arrow functions are fine, union types,
constructor promotion, `match`, enums and `?->` are not.

## Not in v1, deliberately

- **A role taxonomy.** Role-major entry solves divergence within an event; the
  datalist covers most of the rest. A taxonomy is a migration and a governance
  question.
- **Recurring events.** Copy, not recur. Named above.
- **Grouped reminders.** The per-signup flag is what makes the hourly pass safe.
- **A public permalink for events.** A pinned page keeps `publicly_queryable` false.
- **Waitlist promotion across slots.** "Bumped from Greeter, offered Kitchen" is a
  coordinator's judgement, not a rule.

## One housekeeping note

`CLAUDE.md` says "31 files in `inc/`". There are 44. The count is stale before this
feature adds four more; worth dropping the number rather than maintaining it.

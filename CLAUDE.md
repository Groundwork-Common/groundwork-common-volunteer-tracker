# Working in this repository

A WordPress plugin: log volunteer hours, have staff attest to them, and produce a
verification letter a court or a school will accept — from the person who earned
it. Built for the nonprofits who host mandated service and currently do it on
paper.

Read `README.md`, especially **Things that are deliberate**. That section is the
design-decision ledger, and new invariants belong in it.

## The constraint the plugin is built around

The letter is a **record of what the organization observed**, not a certificate.
This has been asked for and refused:

- **No seal, no rendered signature, no "certified", no affidavit language.**
- **The disclaimer cannot be emptied.** The signature block is a ruled line, not
  an image.
- **No theme-overridable letter template** — a theme that owns the markup is a
  theme that can delete the disclaimer.
- **The letter is never built from the cached rollup.** Recompute from entries
  every time. Retention may read the cache; the letter may not.

## The shape of the code

Procedural PHP, prefix `gwc_vt_`, one file per concern in `inc/`, required from the bootstrap
with `function_exists()` guards in a documented order. Several are genuine
load-time dependencies — **do not reorder without reading the block comments.**

**Three classes, and the rule that allows them** (`inc/class-gwc-vt-totals.php`):

> Objects for computed values, arrays for persisted config.

`GWC_VT_Totals`, `GWC_VT_Letter_Entry`, `GWC_VT_Letter`. Plain PHP 7.4 — typed
properties, explicit constructor, no promotion, no readonly, no enums. Guarded on
`class_exists()`.

**No build step.** Each `blocks/*/edit.js` is hand-written ES5 against
`wp.element`, and its `edit.asset.php` is hand-written to match. Keep them in
step: a missing dependency there is a block that throws in the editor on a site
whose script loading order differs from the one it was written on.

**What is actually in here.** The plugin is larger than the hours-and-letters
tool this file used to describe, and the later half is easy to miss when scanning
`inc/`:

- **Hours and the letter** — `cpt.php`, `entries.php`, `verify.php`, `letter.php`,
  `render.php`, `letter-cpt.php`. The original product.
- **The schedule** — `shift-cpt.php`, `shifts.php`, `recurrence.php`,
  `signup-cpt.php`, `signups.php`, plus the public `signup-form.php` /
  `signup-handler.php`, `ics.php`, and two cron passes in `schedule-cron.php`
  driving the messages in `schedule-emails.php`.
- **Events** — `event-cpt.php`, `events.php`, `event-form.php`, and the admin
  trio `admin-event.php` / `admin-event-actions.php` / `admin-event-roster.php`.
  An event is a *container over shifts by `post_parent`*: every slot is an
  ordinary `gwc_vt_shift`, which is why waiting lists, reminders, rosters and
  reconciliation all work on one unchanged.
- **Credentials** — `credential-cpt.php` (both post types), `credentials.php` (the
  queries, plus the two functions that write), `credential-shifts.php` (what a
  shift asks for, and who is short of it), `admin-credentials.php` (defining
  them) and `admin-volunteer-credentials.php` (recording who holds what, and the
  volunteer-list filter). **Never the word "requirement"** — that means service
  hours here, and `CredentialTest` asserts the separation over the source.
  A shift's credentials are **one meta row each** and are written only by
  `gwc_vt_set_shift_credentials()`; putting them in the save handler's `$fields`
  array stores the literal string `Array`, because `gwc_vt_shift_meta_value()`
  casts anything it does not recognise.
  The block lives in `gwc_vt_signup_credential_refusal()`, called by the four
  handlers where a human puts a person on a shift — **never inside
  `gwc_vt_add_signup()`**, which the reconciler and every fixture also call, and
  **never** on `gwc_vt_settle_signups()` or `gwc_vt_handle_signup_promote()`,
  which act on somebody already accepted and would fail silently on cron.
- **The dashboard** — `dashboard.php` (counts, pure) and `admin-dashboard.php`
  (the screen). Split so the worklist's ordering can be asserted without a
  database. `admin-dashboard-widget.php` puts a window onto it on **WordPress's
  own** dashboard: narrower capability, cached counts, its own small stylesheet,
  and it never names a volunteer. Adding a worklist line means adding it to
  `DashboardTest`'s fixture — a guard there reads the keys out of the source and
  fails if you do not.
- **Three blocks**, not one: `hours-form`, `shift-list`, `event-grid`.

**An event has no URL.** `gwc_vt_event` is `public => false`, so it is only ever
seen on a page somebody placed the block or `[gwc_vt_event_grid]` on.
`gwc_vt_event_page_id()` finds that page by searching content for either marker —
two searches, because the shortcode and the block share no substring, and a
single one silently never matched block placements.

## Verifying a change

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar
php phpunit-11.phar                    # needs PHP >= 8.2 locally
php phpunit-11.phar --filter VersionTest
```

`phpunit.xml.dist` sets `failOnWarning`, `failOnDeprecation` **and**
`failOnPhpunitDeprecation`.

```bash
npx @wordpress/env start
for f in tests/integration/*.php; do
  npx @wordpress/env run cli -- wp eval-file \
    "wp-content/plugins/groundwork-common-volunteer-tracker/$f"
done

npx @wordpress/env start --config=.wp-env.php74.json   # the 7.4 floor
npx @wordpress/env run cli -- wp eval-file \
  wp-content/plugins/groundwork-common-volunteer-tracker/tests/seed.php
```

**CI here is local.** `.github/workflows/test.yml` still holds every job — but
its `push` and `pull_request` triggers were removed, so it fires only when
somebody starts it from the Actions tab. Nothing checks a branch or a pull
request for you; run the commands above before you push. What that workflow runs
when you do start it: `php -l` on
every shipping file under 7.4; the unit suite on 8.2/8.3/8.4; **every** script in
`tests/integration/` — the workflow globs the directory, so a new one is picked
up without being listed anywhere — on **both** 7.4 and 8.3, asserting the
container's PHP version actually matches; and `VersionTest` as its own job so the
failure is legible.

Each integration script prints `ALL PASS` or a count of failures, so a run is
checked by grepping for the former rather than by reading the output.

**A new integration script must end with the same two lines the other twenty
do** — the `ALL PASS`-or-count summary, then `exit( 1 )` when
`$GLOBALS['gwc_vt_failures'] > 0`. The job requires both signals and they must
agree: a zero exit code *and* `ALL PASS` in the output. Seven scripts once had
the summary and no `exit( 1 )`, so they announced their failures and returned
zero, and the job passed them. `exit( 1 )` is safe beside a
`register_shutdown_function` restore — PHP runs shutdown functions on `exit()`.

The coding standard runs, and the `phpcs:ignore` annotations throughout are
checked by it rather than decorative:

```bash
composer install
composer lint          # phpcs against phpcs.xml.dist
composer lint:fix      # only what phpcbf can fix — read the warning below first
composer compat        # PHPCompatibilityWP against the 7.4 floor
```

Never add a `phpcs:ignore` without a `--` reason after it. There are 103, and
**every one of them has been checked to actually suppress something** — a sweep
neutralises each in turn and re-lints, and a stale one is deleted rather than
left. Two rules follow:

- **A stale ignore is armed, not harmless.** It suppresses nothing today, so it
  reads as safe. Move a nonce check or drop it in a refactor and the warning that
  would have caught you is silenced by a comment somebody wrote years earlier for
  a different reason. Forty were removed for this; twenty-three of those sat on
  nonce lines.
- **Check the sniff code is real.** Three annotations named
  `WordPress.DB.SlowDBQuery.slow_meta_query`, which does not exist — the code is
  `slow_db_query_meta_query`. A misspelled code silently matches nothing, and
  nothing tells you.

**Regenerate the translation template as part of the bump**, after the five
places move and not before — `make-pot` reads the version out of the plugin
header, so doing it first stamps the old number:

```bash
npx @wordpress/env run cli -- wp i18n make-pot \
  wp-content/plugins/groundwork-common-volunteer-tracker \
  wp-content/plugins/groundwork-common-volunteer-tracker/languages/groundwork-common-volunteer-tracker.pot \
  --exclude=tests,vendor,node_modules,bin,.claude
```

**Then check the slug it wrote.** `make-pot` derives `Report-Msgid-Bugs-To` and
`X-Domain` from the name of the **directory** it was pointed at, not from the
plugin header — so generating from a git worktree ships a template naming a
support forum that does not exist, looking perfectly well-formed while it does
it. That was corrected by hand at three consecutive releases before anybody
wrote it down. `VersionTest` now fails on both that and a template left
un-regenerated, so this is a step you are reminded about rather than one you
have to remember.

**From a worktree, fix the mount rather than the file.** `.wp-env.json` says
`"plugins": [ "." ]`, and wp-env mounts that under the directory's **basename**
— inside a worktree, something like `plugin-dry-review-5ae647`, which is exactly
the name `make-pot` then stamps into both headers. Correcting the output by hand
afterwards is what went wrong three times. Put this in `.wp-env.override.json`,
which is gitignored, and the container gets the real slug so the generated file
is right the first time:

```json
{
	"port": 8971,
	"testsPort": 8972,
	"plugins": [],
	"mappings": {
		"wp-content/plugins/groundwork-common-volunteer-tracker": "."
	}
}
```

Pick ports nothing else is on while you are there — the override is also where
they are pinned, so a fresh worktree collides with whatever is already using
wp-env's default 8888. And keep any scratch config **out of the repository
root**: `.distignore` names `.wp-env.override.json` and `.wp-env.php74.json`
literally, so a `.wp-env.php74.local.json` sitting beside them is not excluded
and rsyncs straight into the release payload.

**Before a release, also run the directory's own scanner** — phpcs is not the
whole of what a reviewer runs. Plugin Check reads the readme's headers, the file
types in the zip, the trademark rules, and the `ABSPATH` guard on every PHP
file. Run it against the **release payload**, never the working tree, or it
spends the run objecting to `vendor/` and `tests/`; the recipe is under "The
directory's own checker" in README.md. It caught three unguarded
`blocks/*/edit.asset.php` files that phpcs is not looking for.

The ruleset is `WordPress`, not `WordPress-Extra` plus `WordPress-Docs`. They
look equivalent and are not: `WordPress.DB.SlowDBQuery` and the security sniff
`WordPress.Security.ValidatedSanitizedInput` live only in the former. Do not
"tidy" it into the pair — that silently stops a security check from running.

**Composer here is a linter and nothing else.** No runtime dependency, no
autoloader, and `composer.json`, `composer.lock`, `phpcs.xml.dist` and `vendor/`
are all in `.distignore`, so none of it reaches the zip. PHPUnit stays a fetched
phar rather than a Composer dependency — that is the workflow above, and it
works. See hard rule 8, which is narrower than it used to be.

**Do not run `composer lint:fix` expecting it to be safe on comments.** It is
safe *now*, because `phpcs.xml.dist` sets
`Squiz.Commenting.BlockComment.NoNewLine` to severity 0. That sniff wants a block
comment's text to start on the line after the opener, this codebase heads ~400
of them on the opening line, and the sniff is auto-fixable. Letting phpcbf
"correct" them leaves a bare `/*` above each and a heading stripped of its
` * ` — which then violates `CloserSameLine`, which is **not** auto-fixable. One
run turns 144 findings into 566 and destroys the comment style. The long version
is in `phpcs.xml.dist`; the location finder overrules the same sniff for the same
reason. Do not re-enable it.

Ports are pinned in `.wp-env.override.json`, which is gitignored — a fresh clone
gets wp-env defaults.

## The beta site

<https://wp.beta.poo6op.com> is a shared demo install carrying **all three**
Groundwork Common plugins, seeded as one organization. `bin/deploy-staging.sh`
rsyncs the working tree there — current branch and uncommitted edits included, by
design — using `.distignore` as the manifest, then activates. `--dry-run` first if
in doubt. README.md has the full account; four things matter before touching it:

- **The target is not in the repo, and must not be put there.** The script reads
  `SSH_HOST`, `DEST_ROOT` and `SITE_URL` from
  `~/.config/groundwork-common/beta.env` (override with `GWC_BETA_ENV`) and stops
  with instructions if that file is missing. An SSH user and host are not a
  credential, but together they name a valid account on a public host — the half
  of a break-in that is usually the work. Do not "simplify" this back to a
  literal: these repos being private is a setting that reverses in one click, and
  `.distignore` covers the release zip, not the repository.

- **Production shares the SSH login.** `groundworkcommon.com`, a live nonprofit
  site, is in the same home directory on the same user. A wrong destination path
  does not fail, it succeeds against production. The script guards this by
  refusing to run unless it finds a beta-only mu-plugin at the target — do not
  remove that check to make a one-off deploy easier.
- **`WP_ENVIRONMENT_TYPE` is `development`, not `staging`,** because the seed
  scripts refuse anything else. So every other `wp_get_environment_type()` guard
  relaxes there too, and no real record may ever live on that host.
- **Nothing sends mail.** `wp_mail()` is intercepted at `pre_wp_mail` and stored;
  read it under Tools → Trapped mail. Not `phpmailer_init` — see the trap below.

WP-CLI on that host costs ~30s per invocation. Batch into one `wp eval-file`
rather than chaining `wp` calls, or a routine step blows a two-minute timeout.

`tests/` is in `.distignore`, so the seed is **not** deployed with the plugin.
Copy it up and run it by absolute path: `wp eval-file ~/beta-seeds/gwcvt-seed.php`.

## Traps that have already cost time

- **Rollup invalidation must not hang off `save_post`.** It fires *during*
  `wp_insert_post()`, before any caller has written the entry's meta; and
  `deleted_post` fires *after* the meta is gone. The second is worse — it is
  wrong in the direction of over-reporting, on the number a letter is built from.
- **Every meta-box field wrapper is a `<div>`, never a `<p>`.** The volunteer
  picker's results list is a `<ul>`, and a `<ul>` inside a `<p>` makes the parser
  close the paragraph and everything still open inside it. No error, valid-looking
  HTML, and a script that finds nothing and attaches no handlers.
- **Translated tables are functions, never `const`.** A `const` is evaluated at
  include time, freezing the strings in English for the request — invisible on an
  English site and total on every other one.
- **`meta_query` beside an `orderby` meta key is not redundant.** Ordering by meta
  uses an INNER JOIN, so an entry missing that key vanishes silently. The
  EXISTS-or-NOT-EXISTS pair keeps those rows, sorted last.
- **Capabilities use `isset()`, not truthiness.** `isset()` is true for "an
  administrator decided no" and false for "this role never heard of it". Getting
  it wrong means staff silently cannot verify hours after a migration, with
  nothing in the interface to explain why.
- **Integration scripts must use `$GLOBALS[...]` explicitly.** `wp eval-file` runs
  the file inside a function, so a top-level assignment is a local while `global`
  in a helper reaches the real one. The counter increments one and the summary
  reads the other, and the script prints ALL PASS under a list of failures. That
  happened.
- **Help tabs are added on `load-`, not in the renderer** — from the renderer they
  silently do not appear.
- **Reference codes are keyed with `wp_salt()`, so they cannot be forged without
  database access** — but not with any authentication constant. This entry used
  to say codes "do not survive a salt rotation", and that is wrong in the
  direction that matters. `wp_salt()` given a scheme it does not know
  (`gwc_vt_letter`, `gwc_vt_signup`, `gwc_vt_form`) never touches `AUTH_KEY` and
  friends: it takes `SECRET_KEY` if defined, and otherwise the `secret_key` site
  option that WordPress generates once and stores. The wordpress.org generator
  does not emit `SECRET_KEY`, so the option is the usual answer, and rotating
  wp-config's eight constants leaves every issued code valid. What breaks a code
  is losing that option — a fresh install, or a restore predating it.
- **A count and the screen it links to must come from one function.** The
  dashboard counted overdue volunteers with `gwc_vt_overdue_requirement_ids()` and
  linked to an unfiltered list; the unlogged-hours nag counted event slots with
  `gwc_vt_shifts_between()` and linked to a view passing `parent => 0`, which
  excludes them. Both said a number and then showed something else. Where a
  screen acts on a count, it filters by the same function that produced it.
- **A page-content search must match every way a thing can be placed.** Searching
  for `[gwc_vt_event_grid` finds the shortcode and never the block, which
  serializes as `wp:groundwork-common-volunteer-tracker/event-grid`. Since the
  shortcode was prefixed in 1.0.0 the two do share the tail `event-grid`, but
  neither marker contains the other, so one search still misses one placement —
  and searching the shared tail alone would match any page that writes the words.
  And match an ID with a pattern: a bare `strpos` for `12` is true of a page
  holding event 1, event 2, event 120, or the year 2012 in the prose above it.
- **A fallback that is right on its own can be wrong in a group.** The org name
  falls back to the site title, the contact to `admin_email`, the signatory to
  the word "Signature" — each defensible, and together a court letter headed with
  a website's title over a webmaster's address. Where several fallbacks can
  compound, say so on the screen before the output leaves.
- **A silent correction on save is a bug even when the correction is right.**
  Unreadable hours became 0, a future date was clamped, a bad volunteer ID was
  dropped — all correct, all invisible, on the numbers a letter prints.
- Screenshot captions are matched to files **by number alone**. Reordering the
  captions without renaming the files silently mislabels every one.
- **Trapping mail belongs on `pre_wp_mail`, never `phpmailer_init`.** The latter
  is the usual place to redirect mail and it cannot stop a send — only change
  where it goes. If PHPMailer then throws, because the host has no MTA or rejects
  the From address, `wp_mail()` returns false. This plugin reads that return to
  decide a reminder was not delivered, so it retries the same reminder on every
  cron pass. `pre_wp_mail` short-circuits the function and returns true, which is
  the answer production would have given.

## Hard rules

1. **Durations are integer minutes.** Never a float, never "hours" as a number.
   `HoursTest` sums a thousand entries both ways to prove it.
2. **Never flip a post type to `show_in_rest => true`.** That publishes volunteer
   names and court-referral status at `/wp/v2/`.
3. **Accepted, honeypotted and rate-limited responses must stay byte-identical.**
   `SelfLogTest` asserts it literally. Diverging turns the form into an oracle for
   "is this named person working off a court order."
4. **The self-log form never looks anybody up.** No code path may branch on
   whether the submitted email matches an existing volunteer.
5. **Uninstall deletes no posts and no post meta**, armed or not. Deactivation
   does not remove capabilities.
6. **Add every new hook to the README table.** The rule is stated there: if you
   add one, add its row.
7. **Bump the version in all five places together** — the plugin header,
   `GWC_VT_VERSION`, `readme.txt`'s Stable tag, its `== Changelog ==` entry and
   its `== Upgrade Notice ==` entry — or `VersionTest` and the deploy gate fail.
   This rule said four for a long time and omitted the upgrade notice, which
   `test_the_upgrade_notice_has_an_entry_for_this_version()` has always asserted:
   following the rule exactly as written failed the build.
8. **Nothing may stand between the source and the shipped plugin.** No build
   step, no npm, no transpiler, no autoloader: every file that runs on a user's
   site is a file in this repository, byte for byte. That is what makes
   `blocks/*/edit.js` hand-written ES5 and `edit.asset.php` hand-written to
   match. This rule used to read "do not add Composer" and was narrowed when the
   coding standard was wired into CI — Composer is permitted **for development
   tooling only**, installs no runtime dependency, and every file it touches is
   in `.distignore`. If a change would make Composer, npm or a build step
   necessary to produce the zip, it is out of scope for this plugin.
9. **Every new block editor script needs `wp_set_script_translations()`.**
   Registration is in `inc/block.php`; a handle missing from that loop has its
   strings extracted into the POT and rendered in English forever.
10. **Deleting the plugin removes no records, armed or not.** The checkbox on the
    Privacy tab arms the removal of *options only*. Uninstall never touches a
    post, its meta, or a capability — and the Privacy tab says so, because a
    policy nobody can find is one people are surprised by.

## Vocabulary

**entry** a `gwc_vt_entry` post, one occasion of work · **volunteer** a
`gwc_vt_volunteer` post; never a WP user, and has no account — see **pass** ·
**pass** a short-lived, mailbox-proved capability to act as one volunteer on the
public pages. Not an account: no password, no WP user, no role, and it expires ·
**attest / verify**
staff confirming an entry · **letter** the produced document · **issued-letter
log** the record that a letter went out; survives purges, holds no name ·
**reference code** a salted digest over every printed field, checkable by phone ·
**rollup** cached totals · **self-log** the optional anonymous front-end form ·
**triage** attaching a self-logged entry to a volunteer · **hold** a per-volunteer
block on the retention sweep · **anonymize vs delete** — anonymizing keeps the
hours · **increment** rounding granularity, nearest and never up ·
**credential** a thing a volunteer must *hold* — a class, a waiver, a check;
never a "requirement", which is court-ordered hours · **record** one grant of one
credential to one volunteer, a child post; expiry is derived from it, never
stored · **retire** stop asking for a credential without destroying the records
of who held it.

## Where work is tracked

GitHub Issues on this repo. Run `gh issue list` before starting.

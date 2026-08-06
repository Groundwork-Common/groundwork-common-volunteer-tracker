# Working in this repository

A WordPress plugin: log volunteer hours, have staff attest to them, and produce a
verification letter a court or a school will accept — from the person who earned
it. Built for the nonprofits who host mandated service and currently do it on
paper.

Read `README.md`, especially **Things that are deliberate**. That section is the
design-decision ledger, and new invariants belong in it.

## The constraint the plugin is built around

The letter is a **record of what the organisation observed**, not a certificate.
This has been asked for and refused:

- **No seal, no rendered signature, no "certified", no affidavit language.**
- **The disclaimer cannot be emptied.** The signature block is a ruled line, not
  an image.
- **No theme-overridable letter template** — a theme that owns the markup is a
  theme that can delete the disclaimer.
- **The letter is never built from the cached rollup.** Recompute from entries
  every time. Retention may read the cache; the letter may not.

## The shape of the code

Procedural PHP, prefix `gwcvt_`, 31 files in `inc/`, required from the bootstrap
with `function_exists()` guards in a documented order. Several are genuine
load-time dependencies — **do not reorder without reading the block comments.**

**Three classes, and the rule that allows them** (`inc/class-gwcvt-totals.php`):

> Objects for computed values, arrays for persisted config.

`GWCVT_Totals`, `GWCVT_Letter_Entry`, `GWCVT_Letter`. Plain PHP 7.4 — typed
properties, explicit constructor, no promotion, no readonly, no enums. Guarded on
`class_exists()`.

**No build step.** `blocks/hours-form/edit.js` is hand-written ES5 against
`wp.element`, and `edit.asset.php` is hand-written to match. Keep them in step: a
missing dependency there is a block that throws in the editor on a site whose
script loading order differs from the one it was written on.

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
for s in caps entries verify letter privacy self-log; do
  npx @wordpress/env run cli -- wp eval-file \
    wp-content/plugins/groundwork-common-volunteer-tracker/tests/integration/$s.php
done

npx @wordpress/env start --config=.wp-env.php74.json   # the 7.4 floor
npx @wordpress/env run cli -- wp eval-file \
  wp-content/plugins/groundwork-common-volunteer-tracker/tests/seed.php
```

**CI is real here** and runs on every push to `main` and every PR: `php -l` on
every shipping file under 7.4; the unit suite on 8.2/8.3/8.4; the six integration
scripts on **both** 7.4 and 8.3, asserting the container's PHP version actually
matches; and `VersionTest` as its own job so the failure is legible.

There is no phpcs ruleset and no Composer in this repo, despite the
`phpcs:ignore` annotations throughout. They are documentation for a run that does
not happen here. Never add one without a `--` reason.

Ports are pinned in `.wp-env.override.json`, which is gitignored — a fresh clone
gets wp-env defaults.

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
- **Reference codes do not survive a salt rotation, on purpose.** They are keyed
  with `wp_salt()` so they cannot be forged without database access.
- Screenshot captions are matched to files **by number alone**. Reordering the
  captions without renaming the files silently mislabels every one.

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
7. **Bump the version in all four places together** — header, `GWCVT_VERSION`,
   `readme.txt` Stable tag, changelog — or `VersionTest` and the deploy gate fail.
8. Do not add Composer, npm, or a build step.

## Vocabulary

**entry** a `gwcvt_entry` post, one occasion of work · **volunteer** a
`gwcvt_volunteer` post; never a WP user, never signs in · **attest / verify**
staff confirming an entry · **letter** the produced document · **issued-letter
log** the record that a letter went out; survives purges, holds no name ·
**reference code** a salted digest over every printed field, checkable by phone ·
**rollup** cached totals · **self-log** the optional anonymous front-end form ·
**triage** attaching a self-logged entry to a volunteer · **hold** a per-volunteer
block on the retention sweep · **anonymize vs delete** — anonymizing keeps the
hours · **increment** rounding granularity, nearest and never up.

## Where work is tracked

GitHub Issues on this repo. Run `gh issue list` before starting.

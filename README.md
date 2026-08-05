# Groundwork Common Volunteer Tracker

Log volunteer hours, have staff attest to them, and produce a verification letter a court or a school will accept.

## Why it works that way

Plenty of nonprofits — food banks, thrift stores, shelters — host people working off court-ordered or school-required community service alongside their regular volunteers. At the end of it that person needs a letter saying how many hours they worked and when, on the organization's letterhead.

Today that is a paper form in a drawer, a Word template somebody edits by hand, or a staff member reconstructing six weeks of Saturdays from memory two days before a court date. The volunteer-management platforms that do this properly are priced for organizations with a volunteer director. The best free WordPress option covers signup and scheduling and stops there.

So the plugin's whole job is the part nobody has built: hours in, verification letter out.

**The constraint that shapes everything else is what the plugin is allowed to claim.** Its output is a document somebody hands to a probation officer. A seal, the word "certified", a rendered signature, language borrowed from an affidavit — each is a few minutes of work, each makes the letter look more official, and each is the plugin asserting an authority it does not have. Nobody at Groundwork Common watched anybody sweep a warehouse floor.

What the letter does instead is report what the organization recorded, say plainly that the *organization* is the authoritative record-keeper, itemize the hours with the staff member who attested to each one and when, timestamp it, and give it a reference code that can be checked. Those are facts a reader can verify. A seal is a picture of one.

## Requirements

WordPress 6.3, PHP 7.4. No build step, no Composer, no npm for anything that ships.

The PHP 7.4 floor is tested, not assumed. PHPUnit 11 needs PHP 8.2, so the unit suite *cannot* run on 7.4 — which means without a deliberate job the compatibility claim in the plugin header would be verified by nobody. The Tests workflow runs the integration scripts against a real 7.4 site, and parses every shipping file with 7.4's own parser. To check it yourself:

```bash
npx @wordpress/env start --config=.wp-env.php74.json
```

## Things that are deliberate

- **Durations are integer minutes, never float hours.** Three and a half hours is `210`. Floats do not sum exactly, and a letter reading "42.30000000000001 hours" is not a rounding curiosity a court will overlook — it is the moment the reader stops believing the document. `HoursTest` sums a thousand entries both ways to keep the argument honest.
- **A bare number over 24 hours is refused rather than guessed at.** `210` means 210 *hours* by the rule that a bare number is hours, which is somebody typing minutes into the wrong field. Guessing "that must have meant minutes" works right up until the volunteer who really did log 30 hours over a weekend retreat.
- **Rounding is to the nearest increment, never up.** Rounding up is the organization systematically crediting hours nobody worked, which on this document is the one direction of error that matters.
- **The disclaimer is editable but cannot be emptied.** An organization's counsel may need particular wording; "no disclaimer" is not a wording choice, it is the plugin quietly starting to imply it certified something. An empty stored value reads as "use the default".
- **Capabilities are granted on `init`, not on activation.** An activation hook runs once, and a site that loses them to a security plugin rebuilding roles or a restore from an old backup would need a deactivate/reactivate cycle to get them back.
- **A capability set to `false` is left alone; one removed entirely is restored.** That is the isset() check in `gwcvt_grant_capabilities()`, and it is the only way to tell a deliberate revocation from a loss. See the note on the function.
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
- **Uninstall deletes nothing unless armed,** and even armed removes only options — never posts, never post meta, never the capabilities.
- **Printing is logged, not just emailing.** A printed letter has left the building just as much as an emailed one, and a log that only knew about email would answer "no letter was issued" about a letter somebody is holding.
- **There is no theme-overridable letter template, on purpose.** All the prose an organization is likely to change — intro, disclaimer, reference note, letterhead, signatory, email subject and covering note — is a setting on the Letter tab, with `{org} {name} {hours} {shifts} {period} {contact} {reference} {timestamp} {timezone}` placeholders. The document's furniture (headings, column headers, row labels) is filterable via `gwcvt_letter_strings`. What there is *not* is a template file a theme can own — because a theme that owns the markup is a theme that can delete the disclaimer, and the disclaimer not being deletable is the one structural promise this plugin makes about its own output.
- **The email's covering note sits outside the letter.** A short "here is your letter" message is reasonable to want; putting it *inside* the document would mean the emailed and printed letters were no longer the same document.
- **The emailed and printed letters come from one template.** Two templates drift, and the day they have drifted is the day a court receives a letter that differs from the organization's copy. The email's CSS is inlined by a hand-written ~20-rule map, which is only possible because the intro and disclaimer are sanitized to plain text rather than `wp_kses_post()`.

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

## Still to come

Milestones, in order: the data model and hour logging, staff verification, the letter and its reference verifier, the optional front-end self-log form, and retention with WordPress's privacy exporter and eraser. Emailed supervisor confirmation — a link a shift supervisor clicks, with no account — is the first thing after 1.0.

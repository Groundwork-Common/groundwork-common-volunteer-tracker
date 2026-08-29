# The browser suite

```bash
npm install && npx playwright install chromium   # once
bin/wpenv start                                  # prints the port it pinned
npm run test:e2e
```

Everything else is optional:

```bash
npm run test:e2e -- verify              # one file
npm run test:e2e -- -g "stale nonce"    # one test
npm run test:e2e:headed                 # watch it happen
npm run test:e2e:ui                     # Playwright's own runner
npm run test:e2e:report                 # the HTML report from the last run
```

Environment:

| Variable | Default | What it is for |
| --- | --- | --- |
| `GWC_VT_E2E_URL` | `http://localhost:8898` | The site to drive. Must match what `bin/wpenv start` prints. |
| `GWC_VT_E2E_NO_SEED` | unset | Skip the reseed in global setup while iterating. A red run under this flag is not evidence of anything until it has been reproduced without it. |
| `GWC_VT_E2E_WORKERS` | `1` | See below. |
| `GWC_VT_E2E_RETRIES` | `0` | See below. |

## The three questions, and which one this answers

This repository already asks two:

- **Is the logic right?** — the PHPUnit suite, against a stubbed WordPress.
- **Does it actually run?** — `tests/integration/`, against a real database and
  a real `WP_REST_Server` under wp-env.

Neither has a browser, so neither can see:

- a sheet whose fields no longer name the form they belong to, so pressing
  **Log it** posts an empty form and the screen says nothing;
- a block whose hand-written `edit.asset.php` has drifted from its `edit.js`, so
  the editor draws a red panel on a site whose script order differs from the one
  it was written on;
- a nonced link that carries the wrong entry, or a return URL that lands
  nowhere;
- a public form whose accepted and honeypotted responses stopped being
  byte-identical, which is hard rule 3 and is the difference between a form and
  an oracle for "is this named person working off a court order";
- `onclick="return confirm(...)"` on a destructive control, which no test
  without a browser can press.

That is the third question — *does a person get through it* — and it is what
this suite is for.

## How a run is arranged

`tests/e2e/support/global-setup.js` does four things, in an order chosen so that
each failure is one sentence rather than forty timeouts:

1. Checks something is answering at the configured address, and that its
   `home_url` is that address. This machine runs several wp-env stacks; driving
   a sibling plugin's WordPress fails in confusing ways.
2. Checks the site is running **this worktree's** copy of the plugin. See below.
3. Rebuilds the fixture and installs the mail trap.
4. Signs the administrator in once, into `tests/e2e/.state/admin.json`.

Then each spec file resets again in its own `beforeAll`, so no file can be upset
by the one before it.

### The reset purges, and the seed does not

`tests/seed.php` removes what a previous *seed* run created, by its mark. That
is the right scope for a demo fixture and the wrong one for a test suite: a
development environment also holds whatever the integration scripts, a manual
afternoon and an earlier version of this suite left behind, none of it marked.

Unmarked leftovers are not harmless. They sit in the verify queue, they are
counted on the dashboard, and they push the seeded records onto page two of a
list table — which is exactly how the first run of `verify.spec.js` failed. So
`op: 'seed'` in `tests/e2e/support/api.php` deletes every post of this plugin's
types first, and every page carrying its block or shortcode markup.

### Per file, not per test

A reset is a seed and a WP-CLI round trip. Per test, the suite would spend more
time seeding than testing. Per file, each file is independent, and the tests
inside it are ordered by the file that owns them.

The consequence has to be stated plainly, because it has already cost time:
**tests within one file share a site.** Two tests that want the same volunteer
in different states use different volunteers — the seed has six, in six states,
for exactly this. Two tests that destroy what they act on write their own
(`shifts.spec.js` and `events.spec.js` both do).

## Rules the specs are written under

**One worker.** There is one WordPress behind this suite and most of what is
under test is a write. Two workers means one spec's settings change landing in
the middle of another spec's page load, and a failure that reproduces one run in
five.

**No retries.** A retry that turns a red run green is a flake being hidden.

**The back door arranges and inspects; it never acts.**
`tests/e2e/support/api.php` can tell you what the database holds, what mail
left, and which capability a role has. It must never do the thing under test —
an operation that verified an entry by writing its meta would let a spec pass
while `gwc_vt_handle_verify_entry()` was broken, and the spec would still be
called "verifying an entry".

**Lists come from the source, never from a list here.**
`tests/e2e/support/source.js` reads every `admin_post_` action, screen slug,
shortcode, block, REST route and post type out of `inc/` and `blocks/`. This is
the same trick the integration job plays by globbing `tests/integration/` rather
than naming its files, and for the same reason: a hand-kept list stops being
complete the first time somebody adds something and does not think of it.

**`coverage.spec.js` is what makes "every path" checkable.** It fails when a
surface in the source is named by no spec. Adding a forty-sixth `admin_post_`
handler without a browser test is a red run.

## When another worktree takes the environment

One wp-env project serves every worktree of this plugin — that is what
`bin/wpenv` is for, and it is why `start` from a sibling branch remounts the
site underneath whatever is running. Two sessions on two branches will take it
from each other, silently.

What that looks like from in here is not "the environment changed":

- files stop existing part way through a run (`api.php does not exist`), or
- worse, they do not, and a spec fails on an assertion that is perfectly true of
  somebody else's branch.

Both happened while this suite was being written; the wrapper's half of it is
tracked as issue #214. So global setup hashes
`groundwork-common-volunteer-tracker.php` and asks the container for the hash of
the one it loaded, and every spec file's reset asks again — the fingerprint
rides along in the seed's reply, so it costs nothing. A mismatch stops the run
with one sentence instead of nineteen mystery failures.

To take it back:

```bash
bin/wpenv stop && bin/wpenv start
```

`start` on its own is not always enough. wp-env reuses the cached compose file
under `~/.wp-env/`, which already names the sibling's path, and returns "Done"
in three seconds having changed nothing.

To stop sharing altogether, give this worktree its own instance — four more
containers and a database, and nobody can take it:

```bash
WP_ENV_HOME="$HOME/.wp-env-$(basename "$PWD")" npx @wordpress/env start
```

Then point the suite at whatever port that prints, with `GWC_VT_E2E_URL`.

## Traps already paid for

- **Row actions are parked at `left: -9999em`, not hidden.** The link is in the
  DOM, passes a visibility check, and cannot be clicked; Playwright says
  "element is outside of the viewport", which reads like a scrolling problem and
  is not one. `force: true` does not help. Use `admin.rowAction()`, which hovers
  the row first.
- **Playwright dismisses dialogs by default.** For
  `onclick="return confirm(...)"` that means the handler returns false and the
  form never submits: the click succeeds, nothing navigates, and the assertion
  reports that the shift was not cancelled — which is true, and reads as a bug
  in the plugin. The `page` fixture accepts every dialog and records its message
  in the `dialogs` fixture.
- **The public forms treat anything faster than three seconds as a machine**,
  and answer it exactly as they answer a real submission. A spec that filled a
  form at browser speed would be testing the bot path while believing it was
  testing the accepted one — and would pass, because the two responses are
  identical by design. Every public spec waits.
- **Links in email are HTML, and WordPress writes `&` as `&#038;`.** A regex
  that sweeps up to the next space carries the entity into the token, and the
  page answers "that link is no longer valid" — the right answer to the wrong
  question. Take the `href` and decode it.
- **REST needs `X-WP-Nonce`, not just cookies.** `page.request.get()` on a
  plugin route answers 401 however signed in the browser is, which reads like a
  broken permission callback. Drive `wp.apiFetch` from inside an admin page
  instead; its middleware adds the header, and that is the request the browser
  actually makes.
- **wp-admin asks gravatar.com and api.wordpress.org for things on nearly every
  screen**, and both are on the critical path of `page.goto()`'s `load` event.
  The `page` fixture aborts every request that is not to the site under test.
- **A new directory under a running wp-env mount is not visible in the
  container** until it is remounted. Nothing here depends on that any more —
  arguments to `api.php` travel as one base64 argument rather than through a
  file — but it is why.

## What is currently red, and why

`capabilities.spec.js` has one failing test, and the failure is a finding rather
than a broken test — it is tracked as issue #213. A contributor can open
`edit.php?post_type=gwc_vt_volunteer` and `edit.php?post_type=gwc_vt_entry` and
read every volunteer's name, hours and court-ordered total.

The design notes say the list tables are safe on their own, because WordPress
filters them by author for anybody without `edit_others_posts`. Current core
does not: it restricts what a contributor may **edit**, not what `edit.php`
**lists**. The same site shows a contributor the administrator's "Hello world!"
in the ordinary Posts list, which is the quickest way to confirm it is core's
behaviour rather than this plugin's.

The plugin's own custom screens are gated correctly — each asks
`gwc_vt_can_see_records()`. The leak is at the two screens nobody gated, because
nobody thought they needed gating.

The test is left failing rather than skipped, marked `test.fail()`, or softened
into an assertion that passes. A green suite over a real disclosure is what this
repository's notes call verification that proves nothing.

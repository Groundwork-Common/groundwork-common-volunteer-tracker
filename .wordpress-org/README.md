# WordPress.org page assets

Nothing in this folder ships to anybody who installs the plugin. It is copied
to the SVN `assets/` directory by `.github/workflows/deploy.yml` and renders the
plugin's page on wordpress.org — which is why a large banner costs installers
nothing, and why `.distignore` excludes this folder from the release zip.

## What goes here

| File | Size | Notes |
| --- | --- | --- |
| `icon-256x256.png` | 256×256 | Shown in search results and the installer. |
| `icon-128x128.png` | 128×128 | Fallback for older screens. |
| `banner-1544x500.png` | 1544×500 | The retina banner at the top of the page. |
| `banner-772x250.png` | 772×250 | Its non-retina twin. Required — some contexts never load the large one. |
| `screenshot-1.png` … | any | Numbered from 1, in the order the captions appear in `readme.txt`. |

## Two things that are easy to get wrong

**The page does not exist until the first SVN commit.** Publishing assets before
the code means a live page listing screenshot captions with nothing behind them.
Land both together.

**Captions are matched to files by number alone.** `readme.txt` has a
`== Screenshots ==` list, and its first line describes `screenshot-1.png`
whatever that file happens to contain. Reordering the captions without renaming
the files silently mislabels every screenshot, and nothing anywhere warns you.

The captions currently expected, in order:

1. The dashboard: what needs doing, ordered by what is lost if it waits.
2. The hours list, filtered to shifts nobody has verified yet.
3. Entering a shift, with the volunteer picker and the verify button.
4. A verification letter, ready to print.
5. Checking a reference code somebody has phoned in about.
6. The schedule: what is coming up, how full each shift is, and a repeat that has been called off.
7. Building an event: one occasion, its roles, and the times under each.
8. The roster for an event, split by role and time, ready for the clipboard.

Caption 6 was rewritten for 1.0.0 rather than the screenshot re-shot, and this
is worth knowing before somebody "fixes" it back. It used to promise "an event
and the ordinary shifts around it", and there is no event in the frame: the
seed's event falls on 4 August, the **Coming up** tab lists from today forward,
and so what `screenshot-6.png` actually shows is ordinary shifts plus a repeat
that has been called off. The caption now describes that. If the event view
deserves a shot of its own, take it from the **Events** tab as a new file —
do not re-point caption 6 at it without opening `screenshot-6.png` first.

The eight screenshots exist as of 0.15.0, shot against `tests/seed.php` at
1280px on a 2× display, so each file is 2560×1800. They are capped at that
height on purpose: the event editor's full page is over seven thousand pixels
tall, which scaled into a plugin page is a grey smear.

## The icon and the banners

`assets-source.html` in this folder draws all four. Open it in a browser and
screenshot the three elements — `#icon`, `#banner-large`, `#banner-small` — at
their natural size; the 128px icon is the same drawing with the override at the
foot of that file applied. No build step and nothing to install, which is why it
is a single static file rather than a script.

**The design, so it can be remade rather than guessed at.** Everything is taken
from the letter's own stylesheet — Georgia, ink `#1a1a1a`, a `#999` rule — plus
the Groundwork Common gold `#ffc857` from the wordmark, used once.

The icon is a sheet of paper with a ruled line at its foot. At 128px an itemised
table is unreadable, so what is drawn is the shape somebody recognises: a page, a
few lines of type, and the line a person signs on. That line is the one element
this plugin refuses to replace with a picture of a signature, which makes it the
right thing to build a mark from.

The banners are typographic for the same reason. A banner showing photographed
volunteers would be advertising somebody else's afternoon; what this plugin makes
is a document, so the document is what is on it — quiet enough behind the
sentence to stay texture.

None of this is precious. It is a first pass by somebody who is not a designer,
and replacing it costs nothing but the four files.

## Taking the screenshots

**Never shoot real data.** Every screenshot of this plugin shows a person's name
next to a number of volunteer hours, and in the court-ordered case the
surrounding interface implies why they are there. A screenshot of real data on a
public plugin page is a disclosure that cannot be taken back — deleting the file
does not delete it from whatever scraped it first.

There is a fixture for exactly this. It builds Riverbend Food Bank, whose six
volunteers are all invented and whose addresses are all on `example.test`:

```bash
npx @wordpress/env start
npx @wordpress/env run cli -- wp eval-file \
  wp-content/plugins/groundwork-common-volunteer-tracker/tests/seed.php
```

Then, at 1280px wide, with the browser chrome out of frame:

| File | Where | What should be on screen |
| --- | --- | --- |
| `screenshot-1.png` | Volunteer Hours → Dashboard | The worklist with several lines on it, so the ordering is visible. Not an empty one, and not the first-run "Nothing here yet" panel. |
| `screenshot-2.png` | Volunteer Hours → All hours, filtered to **Not yet verified** | The queue: several shifts awaiting a staff member, including the two self-logged rows reading "— not yet matched". |
| `screenshot-3.png` | Volunteer Hours → Log hours | The shift form with the volunteer picker open on "Mar", showing Marcus Delacroix. |
| `screenshot-4.png` | The print view for Marcus Delacroix | The letter itself: letterhead, the itemized table, the signature block and the disclaimer. Scroll so the disclaimer is visible — it is the point. |
| `screenshot-5.png` | Volunteer Hours → Letters, with a reference pasted in | The checker answering "This letter matches our current records." |
| `screenshot-6.png` | Volunteer Hours → Schedule, **Coming up** | Several shifts with their fill counts, including one full with somebody waiting, and a cancelled repeat struck through. |
| `screenshot-7.png` | An event, in the editor | The role-major grid: a role named once with its times under it, and the "Where volunteers see this" row filled in. |
| `screenshot-8.png` | An event → Roster | Grouped role then time, with a "Log the hours" link on a time that has passed. |

Two things to check before saving: the admin bar shows no real site name, and
the Riverbend letterhead — not your own organization's — is on the letter.

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

1. The hours list, filtered to shifts nobody has verified yet.
2. Entering a shift, with the volunteer picker and the verify button.
3. A verification letter, ready to print.
4. Checking a reference code somebody has phoned in about.

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
| `screenshot-1.png` | Volunteer Hours → All hours, filtered to **Not yet verified** | The queue: several shifts awaiting a staff member, including the two self-logged rows reading "— not yet matched". |
| `screenshot-2.png` | Volunteer Hours → Log hours | The shift form with the volunteer picker open on "Mar", showing Marcus Delacroix. |
| `screenshot-3.png` | The print view for Marcus Delacroix | The letter itself: letterhead, the itemized table, the signature block and the disclaimer. Scroll so the disclaimer is visible — it is the point. |
| `screenshot-4.png` | Volunteer Hours → Letters, with a reference pasted in | The checker answering "This letter matches our current records." |

Two things to check before saving: the admin bar shows no real site name, and
the Riverbend letterhead — not your own organization's — is on the letter.

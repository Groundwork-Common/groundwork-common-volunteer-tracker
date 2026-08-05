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

**Use invented names.** Every screenshot of this plugin shows a person's name
next to a number of volunteer hours, and in the court-ordered case the
surrounding interface implies why they are there. A screenshot of real data on a
public plugin page is a disclosure that cannot be taken back — deleting the file
does not delete it from the mirrors that scraped it.

Seed a demo site with obviously fictional volunteers before shooting, take the
shots at 1280px wide, and check the browser chrome is out of frame and no real
site name is visible in the admin bar.

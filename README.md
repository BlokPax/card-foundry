# Card Foundry — Wonders of the First

A control plane for building/exporting **Wonders of the First** TCG cards through Adobe InDesign. A Laravel + [NativePHP](https://nativephp.com) (Electron) desktop app for designers, with a fully headless CLI for agents and automation.

```
GUI / CLI  ──►  cards:export (artisan)  ──►  bridge HTTP :3000  ──►  WebSocket :3001  ──►  UXP plugin in InDesign  ──►  app.doScript(JSX + payload)
```

Both front doors share one engine: the GUI simply launches `cards:export` as a child process, so anything the app does is reproducible from a terminal.

## Requirements

- PHP **8.3+** (Herd users: the `php84` binary — the default `php` here is 8.2)
- Node.js 18+ (developers only — for building assets; the app and bridge need no local Node)
- Adobe InDesign

A **pinned copy of the [indesign-uxp-server](https://github.com/theloniuser/indesign-uxp-server) bridge ships with the app** in `resources/bridge`, and runs on the app's own Electron binary (`ELECTRON_RUN_AS_NODE`) — end users need no Node install (see `VERSION.md` there for the pinned commit and local patches). The app starts/stops the bridge process itself, and can install the InDesign plugin too: `php artisan bridge:install-plugin` (or the app's **Install InDesign plugin** button) installs the bundled `.ccx` via Creative Cloud — no UXP Developer Tool needed. Then open InDesign and enable it once under Window → Plugins → InDesign Bridge.

## Setup

```sh
composer install
npm install && npm run build
php artisan migrate
php artisan bridge:install     # only fetches the bridge's npm deps if they're missing
```

Run the desktop app:

```sh
php artisan native:serve
```

Or use it from the browser during development (`herd open` / `php artisan serve`) — the pickers become text inputs.

## Using the app

1. Open InDesign (with the UXP plugin enabled — first time only: see above).
2. Open the app. The status pill top-right shows bridge/InDesign connectivity; the app installs and starts the bridge process for you.
3. Choose the **merge file** (.csv/.xlsx), the **build kit** (the `_WoTF InDesign Build Template` folder — the template defaults to its master), the **destination**, and one or more **artwork directories** — searched in order, first match wins (set art first, then `Celebrities/_exports/_CMYK`).
4. The merge file is parsed and linted immediately — missing art (across all art dirs), duplicate card codes, unknown orbitals (e.g. the "Sulfera" misspelling), merge-breaking headers, unbalanced `BEGINITAL`/`ENDITAL`, malformed `*LB*`.
5. Pick **All cards**, a **Selection**, or a **Single card**, choose a treatment (Base Paper, Classic Foil, Formless Foil, OCM, Stonefoil, Dragon Scale) and proof/production, and press **Build**. The **Build pipeline** panel shows exactly which wotfLib stages run, in order.

## Set manifests (`foundry.json`)

Each set can carry a manifest next to its files in Dropbox — a **base** config plus named **profiles** that can override *any* option (art dirs, format, treatment, proof/white-plate, even the pipeline). All paths are relative to the manifest, so the same file works on every machine syncing the tree:

```json
{
    "version": 1,
    "set": "Trial by Combat",
    "base": { "kit": "../_WoTF InDesign Build Template", "merge": "TBC Handoff.csv",
              "artDirs": ["_exports/CMYK", "../Celebrities/_exports/_CMYK"] },
    "profiles": {
        "print":   {},
        "preview": { "artDirs": ["_exports/RGB"], "export": { "format": "png", "ppi": 300 }, "proof": true }
    }
}
```

Layering: app defaults ← base ← profile ← whatever you set in the UI/CLI for that run. In the app, pick the manifest and a profile — every field prefills but stays editable — and **Save** writes your current settings back to the profile (or a new one, or the base); **Create manifest** bootstraps a foundry.json from the current inputs. Export format (PDF/PNG + ppi) and the white plate toggle are always per-run choices in the interface.

### v0.3.0 manifest keys

Everything below layers the same way (base ← profile ← UI/CLI) and is optional:

```json
{
    "base": {
        "merge": "https://docs.google.com/spreadsheets/d/…/edit#gid=0",
        "whiteDirs": ["_exports/WHITE"],
        "treatmentLayers": {
            "OCM":          { "layer": "OCM Frame", "serialColumn": "OCM with First Edition Stamp" },
            "Holo Rainbow": "Holo Frame"
        },
        "serialize": false,
        "serialization": { "pad": 2, "column": "Max Count" },
        "keywords": {
            "known": ["ON PLAY", "SURGE", "FLYING"],
            "bare": ["FLYING", "IMMORTAL"],
            "styleDefault": "Ability Text",
            "styleWhite": "Ability Text - white",
            "styleBare": true
        },
        "export": { "format": "pdf", "bleed": 3, "cropMarks": true,
                    "registrationMarks": true, "combine": true, "preset": "Printer_Cards" }
    }
}
```

- **`merge` as a Google Sheet URL** — link the sheet instead of exporting CSVs by hand. Share it as *Anyone with the link → Viewer*; the app downloads the tab named by `gid=` through the CSV export endpoint (cached briefly in the UI, always fresh for a build).
- **`whiteDirs`** — folders holding per-card white-ink plates, hunted by card ref exactly like `artDirs` (first match wins). The resolved file rides into the build (`whitePlate` stage `art` opt, or the `@white` pipeline placeholder); a missing plate warns but doesn't fail the card.
- **`treatmentLayers`** — the treatment → template-layer table (the app defaults in `config/wotf.php` remain the fallback). Merged key-by-key, so you can restyle one treatment or add a brand-new one per set. The object form adds `serialColumn`: the merge column carrying that treatment's edition size.
- **`serialize` / `serialization`** — with `serialize` on, every card exports once *per numbered copy* (`… [OCM] 03of25.pdf`), sized by the treatment's `serialColumn` (or the global `serialization.column`). The padded copy number reaches the pipeline as the `@serial` placeholder → `applyCardData`'s `numerator` opt; cards without a count build once, unnumbered.
- **`keywords`** — the ability-keyword styling config, per set instead of hardcoded in `wotfLib.jsxinc`. `known` rewrites wotfLib's `KNOWN_KEYWORDS` table before the pipeline runs (drives the "unknown keyword" warnings); the styles + `styleBare` feed the `abilityKeywords` stage, and `known`/`bare` also ride along as `knownKeywords`/`bareKeywords` opts for wotfLib versions that read them (the bare *list* needs that wotfLib support).
- **`export` options** — `bleed` (`true` = document bleed, a number = mm on all edges), `cropMarks` (cut lines), `registrationMarks`, and `combine` (also merge the run's PDFs into **one document per treatment**, one card per page, alongside the per-card files). A named `preset` wins over the marks options — you'll get a warning in the run log if both are set.

## Headless CLI

```sh
# with a manifest: everything comes from the set, flags override
php artisan cards:export --manifest=".../Trial by Combat/foundry.json" --profile=preview --dest=./out --cards=TBC331
php artisan cards:export --manifest=… --profile=print --dest=./out --all --format=png   # flag beats profile

# without a manifest (art dirs are searched in order — set art first, Celebrities second)
php artisan cards:export --merge="TBC Data Merge.csv" \
  --art=".../Trial by Combat/_exports/CMYK" --art=".../Celebrities/_exports/_CMYK" \
  --kit=".../_WoTF InDesign Build Template" --dest=./out --all

# one card + treatment (match by @Image code, Card Code, or Name)
php artisan cards:export … --cards=TBC331 --treatment="Classic Foil"

# several cards; production (keeps the white-ink plate; default builds are NO-WHITE proofs)
php artisan cards:export … --cards=TBC331,TBC060,TBC197 --production

# linked Google Sheet as the merge (shared "Anyone with the link")
php artisan cards:export --merge="https://docs.google.com/spreadsheets/d/…/edit#gid=0" --art=… --kit=… --dest=./out --all

# white-layer plates, serialized copies, marks, single combined PDF
php artisan cards:export … --white=".../_exports/WHITE" --serialize --combine --crop-marks --registration-marks --bleed=3

# lint only / print the pipeline
php artisan cards:export --merge=… --art=… --kit=… --dest=… --dry-run
php artisan cards:export --show-plan
```

Also: `bridge:install`, `bridge:start`, `bridge:stop`, `bridge:status`. Exit code is non-zero when anything fails, so it composes cleanly in scripts. Lint errors block a run unless `--force`; only errors affecting the selected cards count.

## How a card is built

Every card builds **independently** — one bad card fails alone, and runs are resumable. Per card the app:

1. Writes a one-row CSV: UTF-8 without BOM, typographer's quotes (ports `fix_csv_for_indesign.py`), and every `@`-image column resolved to an **absolute path** via the ordered art directories.
2. Copies the template, attaches the CSV as the Data Merge source, shows exactly one treatment layer, and merges — all scripted through the bridge.
3. Runs the kit's `wotfLib.jsxinc` stages (`WOTF.steps.*`) in the order defined in `config/wotf.php` (`cards:export --show-plan` prints it): the 8 cleanup steps, then feather → treatment frame → chrome → art placement → serials/stamp → autograph → set name → white plate (hidden for proofs).
4. Exports the PDF (`…NO-WHITE.pdf` for proofs) and closes without saving. Each stage's warnings are captured in the run log.

The pipeline is data, not code — reorder or re-option stages in `config/wotf.php`.

## Configuration (`config/wotf.php` / `.env`)

| env | default | purpose |
|---|---|---|
| `WOTF_BRIDGE_URL` | `http://127.0.0.1:3000` | bridge HTTP endpoint |
| `WOTF_BRIDGE_PATH` | `resources/bridge` (vendored) | use a different bridge checkout |
| `WOTF_KIT_PATH` | — | default build-kit folder (contains `wotfLib.jsxinc`, `Links/`, `builds/`) |
| `WOTF_NODE_BINARY` | `node` | node used to run the bridge |
| `WOTF_CARD_TIMEOUT` | `300` | seconds allowed per card (also raises the bridge's own script timeout) |

## Logs

| File (under `storage/logs/`) | Contents |
|---|---|
| `commands.log` | every `cards:*` / `bridge:*` invocation — args, exit code, duration, PID |
| `runs.log` | every card-build log line (`[run N] ✓ Card (CODE)`, stage warnings) — tail this to debug builds |
| `bridge.log` | the bridge node server's stdout/stderr |
| `laravel.log` | application errors |

Run history (per-card status, errors, timings) also lives in the `runs` / `run_items` tables; prepared per-card CSVs are kept under `storage/app/runs/{id}/`.

## Tests

```sh
php artisan test
```

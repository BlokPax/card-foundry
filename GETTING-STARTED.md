# Getting Started with Card Foundry

Card Foundry is a Laravel + NativePHP (Electron) desktop app that builds Wonders of the First TCG cards through Adobe InDesign, with a headless `cards:export` CLI underneath.

You need PHP 8.3+ (Herd users: use the `php84` binary — the default `php` is 8.2), Node.js 18+ for building assets, and Adobe InDesign installed.

Install dependencies with `composer install` and `npm install && npm run build`, then run `php artisan migrate`.

Run `php artisan bridge:install` to fetch the bundled InDesign bridge's dependencies, and `php artisan bridge:install-plugin` to install the UXP plugin via Creative Cloud.

Open InDesign and enable the plugin once under **Window → Plugins → InDesign Bridge**.

Launch the desktop app with `php artisan native:serve` — the status pill in the top-right shows bridge/InDesign connectivity, and the app starts and stops the bridge process for you.

Point the app at a merge file (.csv/.xlsx), the `_WoTF InDesign Build Template` build kit, a destination folder, and one or more artwork directories (searched in order, first match wins) — or simply pick a set's `foundry.json` manifest and a profile to prefill everything.

The merge file is parsed and linted immediately for missing art, duplicate card codes, unknown orbitals, and malformed markup; lint errors block a build unless you force it.

Select all cards, a subset, or a single card, choose a treatment (Base Paper, Classic Foil, Formless Foil, OCM, Stonefoil, or Dragon Scale) and proof or production, then press **Build** (⌘B) to export each card's PDF one at a time.

Everything the app does is reproducible from a terminal — e.g. `php artisan cards:export --manifest=".../foundry.json" --profile=print --dest=./out --all` — and `storage/logs/runs.log` is the first place to look when a build misbehaves.

<?php

namespace App\Livewire;

use App\Export\ExportPlanner;
use App\InDesign\BridgeClient;
use App\InDesign\BridgeSupervisor;
use App\Merge\MergeFileParser;
use App\Merge\MergeLinter;
use App\Models\Run;
use App\Support\Settings;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Native\Laravel\Facades\ChildProcess;
use Native\Laravel\Dialog;
use Throwable;

#[Layout('components.layouts.app')]
class ControlPlane extends Component
{
    // ── Navigation ──────────────────────────────────────────────────────
    public string $tab = 'cards'; // cards | activity

    public bool $showAdvanced = false;

    public bool $showHealth = false;

    public string $flagFilter = ''; // '' = off | 'any' | a lint rule slug

    // Grouped set-health summaries, keyed by lint rule slug: [label, "{n}" template].
    public const RULE_SUMMARIES = [
        'missing-column' => '{n} required column(s) missing',
        'empty-header' => 'Empty column header',
        'illegal-header' => "{n} column name(s) with ':' or '/'",
        'reserved-header' => '{n} reserved column name(s)',
        'duplicate-column' => '{n} duplicate column(s)',
        'duplicate-code' => '{n} cards share a card code',
        'duplicate-image' => '{n} cards share artwork',
        'art-suffix-fallback' => '{n} cards using a different artwork format than the merge names',
        'denominator' => '{n} cards numbered out of set',
        'unknown-orbital' => '{n} cards with an unknown orbital',
        'missing-name' => '{n} cards missing a name',
        'missing-image-ref' => '{n} cards with no @Image',
        'missing-type' => '{n} cards missing a type',
        'bad-cost' => '{n} cards with an odd cost',
        'unbalanced-italics' => '{n} cards with unbalanced BEGINITAL',
        'bad-linebreak' => '{n} cards with a bad *LB* marker',
        'art-not-found' => '{n} cards missing artwork',
        'art-ambiguous' => '{n} cards with ambiguous artwork',
    ];

    // ── Manifest ────────────────────────────────────────────────────────
    public string $manifestPath = '';

    public string $setName = '';

    /** @var list<string> */
    public array $profileNames = [];

    public string $profile = ''; // '' = the Default profile (base config)

    public string $defaultProfileName = 'Default';

    /** Editable name of the profile currently open in the setup view. */
    public string $editProfileName = 'Default';

    public string $newProfileName = '';

    public bool $showProfileModal = false;

    public bool $showInfoModal = false;

    public bool $showCreateWizard = false;

    public int $createStep = 1;

    /** Base-config effective values, for "Inherits:" notes and diff-saving. */
    public array $inherited = [];

    /** @var list<string> manifest keys the selected profile itself defines */
    public array $profileOwnKeys = [];

    /** Cover image shown on the set index — a data URI (new) or file path (legacy). */
    public string $coverPath = '';

    public bool $showCoverEditor = false;

    // ── Inputs ──────────────────────────────────────────────────────────
    public string $mergePath = '';

    /** @var list<string> searched in order — first match wins */
    public array $artDirs = [];

    public string $kitPath = '';

    public string $templatePath = '';

    public string $destDir = '';

    /** Browser-mode text field for adding an art directory. */
    public string $newArtDir = '';

    /** @var list<string> white-ink layer art, hunted like artDirs */
    public array $whiteDirs = [];

    /** Browser-mode text field for adding a white-layer directory. */
    public string $newWhiteDir = '';

    // ── Selection ───────────────────────────────────────────────────────
    public string $mode = 'all'; // all | selected | single

    /** @var list<string> @Image refs */
    public array $selected = [];

    /** Header-checkbox state over the acting set: none | partial | all. Derived in render(). */
    public string $selectionState = 'none';

    /**
     * True right after the header checkbox inverted a partial selection —
     * the next click on it then clears instead of inverting again. Any other
     * change to the selection or the filter restarts the cycle.
     */
    public bool $selectionInverted = false;

    public string $singleCard = '';

    /** @var list<string> one build per card per treatment */
    public array $treatments = ['Base Paper'];

    public bool $proof = true;

    public string $format = 'pdf';

    /** Nullable so the field can be erased mid-edit; builds require a value. */
    public ?int $ppi = 300;

    // ── PDF options (v0.3.0) ────────────────────────────────────────────
    public bool $cropMarks = false;

    public bool $registrationMarks = false;

    /** Document bleed on PDF exports (numeric bleeds live in the manifest). */
    public bool $bleed = false;

    /** Also combine the run's PDFs into one document per treatment. */
    public bool $combine = false;

    /** One export per numbered copy, edition sizes from the merge file. */
    public bool $serialize = false;

    /** @var list<string> treatments the current set knows (manifest-extendable) */
    public array $treatmentOptions = [];

    public string $search = '';

    /** How many card rows are rendered; grows as the user scrolls. */
    public int $visibleLimit = 100;

    public bool $overrideLint = false;

    public bool $showPipeline = false;

    // ── Merge state ─────────────────────────────────────────────────────
    public array $summary = [];

    public array $issues = [];

    /** @var list<array{ref:string,name:string,type:string,code:string,hasArt:bool}> */
    public array $cards = [];

    public ?string $parseError = null;

    // ── Runtime ─────────────────────────────────────────────────────────
    public ?int $runId = null;

    public bool $activityAllSets = false;

    public bool $native = false;

    public array $bridge = ['installed' => false, 'up' => false, 'indesign' => null, 'detail' => null];

    public ?string $flash = null;

    public string $flashLevel = 'warn'; // warn | success | error

    // ── Preferences (⌘,) ────────────────────────────────────────────────
    public bool $showPreferences = false;

    /**
     * Editing set configurations. On for the trusted few; switched off on
     * an operator's machine, the whole config surface goes read-only while
     * per-run tweaks (format, treatment, selection) keep working.
     */
    public bool $editorMode = true;

    /** The signed-in Google identity ('' = not connected). */
    public string $googleEmail = '';

    private function notify(string $message, string $level = 'warn'): void
    {
        $this->flash = $message;
        $this->flashLevel = $level;
    }

    public function mount(): void
    {
        $this->native = (bool) config('nativephp-internal.running');

        foreach (['mergePath', 'kitPath', 'templatePath', 'destDir', 'manifestPath', 'profile', 'format'] as $key) {
            $this->{$key} = (string) Settings::get("paths.{$key}", $this->{$key});
        }

        $this->treatmentOptions = array_keys(config('wotf.treatments'));
        $this->treatments = $this->validTreatments((array) Settings::get('paths.treatments', $this->treatments));

        $this->artDirs = (array) Settings::get('paths.artDirs', []);
        $this->whiteDirs = (array) Settings::get('paths.whiteDirs', []);
        $this->proof = (bool) Settings::get('paths.proof', true);
        $this->ppi = ((int) Settings::get('paths.ppi', 300)) ?: 300; // erased last session → default

        foreach (['cropMarks', 'registrationMarks', 'bleed', 'combine', 'serialize'] as $flag) {
            $this->{$flag} = (bool) Settings::get("paths.{$flag}", false);
        }

        $this->editorMode = (bool) Settings::get('prefs.editorMode', true);
        $this->refreshGoogleStatus();

        // The manifest on disk is authoritative at startup: the profile the
        // dropdown shows must be the profile actually applied — the file may
        // have been edited (or a profile deleted) since last session, so the
        // remembered field values can't be trusted over it.
        $manifestApplied = false;
        if ($this->manifestPath !== '' && is_file($this->manifestPath)) {
            $this->applyManifest();
            $manifestApplied = true;
        } else {
            $this->manifestPath = '';
            $this->profile = '';
        }

        if ($this->kitPath === '' && config('wotf.kit_path')) {
            $this->kitPath = (string) config('wotf.kit_path');
        }

        $this->showAdvanced = $this->manifestPath === '';

        $this->refreshBridge(ping: false);

        if (! $manifestApplied && $this->mergePath !== '') {
            $this->parseMerge();
        }
    }

    // ── Native pickers ──────────────────────────────────────────────────
    public function pick(string $target): void
    {
        if (! $this->native) {
            return; // browser mode uses the text inputs
        }

        $dialog = Dialog::new();

        try {
            $dialog->asSheet();
        } catch (Throwable) {
            // no current window reference — a floating dialog still works
        }

        $current = match ($target) {
            'artDirs' => end($this->artDirs) ?: '',
            'whiteDirs' => end($this->whiteDirs) ?: '',
            default => $this->{$target},
        };
        if ($current !== '') {
            $dialog->defaultPath(is_dir($current) ? $current : dirname($current));
        }

        $path = match ($target) {
            'manifestPath' => $dialog->title('Choose the set manifest (foundry.json)')->files()->filter('Manifest', ['json'])->open(),
            'coverPath' => $dialog->title('Choose a cover image for the set index')->files()->filter('Image', ['png', 'jpg', 'jpeg', 'gif', 'webp'])->open(),
            'artDirs' => $dialog->title('Add an artwork directory')->folders()->open(),
            'whiteDirs' => $dialog->title('Add a white-layer directory')->folders()->open(),
            'setFolder' => $dialog->title("Choose the set's folder — it will hold the config, run history, and usually the art")->folders()->open(),
            'destDir' => $dialog->title('Choose the output directory')->folders()->open(),
            'kitPath' => $dialog->title('Choose the build-kit folder (contains wotfLib.jsxinc)')->folders()->open(),
            'mergePath' => $dialog->title('Choose the merge file')->files()->filter('Merge file', ['csv', 'xlsx'])->open(),
            'templatePath' => $dialog->title('Choose the InDesign template')->files()->filter('InDesign', ['indd', 'indt'])->open(),
            default => null,
        };

        if (! is_string($path) || $path === '') {
            return;
        }

        if ($target === 'artDirs') {
            $this->addArtDir($path);
        } elseif ($target === 'whiteDirs') {
            $this->addWhiteDir($path);
        } else {
            $this->{$target} = $path;
            $this->updated($target);
        }
    }

    // ── Manifest ────────────────────────────────────────────────────────
    private function loadManifestMeta(): ?\App\Manifest\SetManifest
    {
        try {
            $manifest = (new \App\Manifest\ManifestLoader)->load($this->manifestPath);
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');
            $this->setName = '';
            $this->profileNames = [];

            return null;
        }

        $this->setName = $manifest->set;
        $this->profileNames = $manifest->profileNames();
        $this->defaultProfileName = $manifest->defaultProfileName;
        $this->coverPath = $manifest->cover() ?? '';

        if ($this->profile !== '' && ! in_array($this->profile, $this->profileNames, true)) {
            $this->profile = '';
        }

        $this->editProfileName = $this->profile !== '' ? $this->profile : $this->defaultProfileName;

        try {
            $base = $manifest->effective(null);
            $this->inherited = [
                'mergePath' => $base->mergePath,
                'kitPath' => $base->kitPath,
                'templatePath' => $base->templatePath,
                'artDirs' => $base->artDirs,
                'whiteDirs' => $base->whiteDirs,
                'coverPath' => $manifest->cover() ?? '',
                'treatments' => $base->treatments,
                'format' => $base->format,
                'ppi' => (int) ($base->exportOptions['ppi'] ?? 300),
                'proof' => $base->proof,
                'cropMarks' => (bool) ($base->exportOptions['cropMarks'] ?? false),
                'registrationMarks' => (bool) ($base->exportOptions['registrationMarks'] ?? false),
                'bleed' => (bool) ($base->exportOptions['bleed'] ?? false),
                'combine' => (bool) ($base->exportOptions['combine'] ?? false),
                'serialize' => $base->serialize,
            ];
        } catch (Throwable) {
            $this->inherited = [];
        }

        $this->profileOwnKeys = $this->profile !== ''
            ? array_keys($manifest->profiles[$this->profile] ?? [])
            : [];

        $this->rememberSet($manifest);
        $this->manifestSeen = $this->manifestStamp();

        return $manifest;
    }

    // ── External edits to foundry.json ──────────────────────────────────

    /** Content hash of the manifest as last read; null when none/missing. */
    public ?string $manifestSeen = null;

    public bool $showManifestChanged = false;

    private function manifestStamp(): ?string
    {
        return $this->manifestPath !== '' && is_file($this->manifestPath)
            ? (md5_file($this->manifestPath) ?: null)
            : null;
    }

    /**
     * Called when the app window regains focus: if foundry.json changed on
     * disk since we last read it, offer a reload. Renderless when quiet —
     * focus events are frequent and the component is large.
     */
    public function checkManifestFreshness(): void
    {
        if ($this->manifestPath === '' || $this->showManifestChanged) {
            $this->skipRender();

            return;
        }

        $sheetReloaded = $this->refreshStaleSheet();

        if ($this->manifestStamp() === $this->manifestSeen) {
            if (! $this->receivedUpdates && ! $sheetReloaded) {
                $this->skipRender();
            }

            return;
        }

        $this->showManifestChanged = true;
    }

    /**
     * Focus-time hash check on a linked sheet, throttled to one download
     * per 5 minutes: re-fetch, compare the content hash, and reload the
     * card list only when the sheet actually changed.
     */
    private function refreshStaleSheet(): bool
    {
        if (! \App\Merge\GoogleSheetFetcher::isSheetUrl($this->mergePath)) {
            return false;
        }

        $fetcher = new \App\Merge\GoogleSheetFetcher;
        $cacheDir = $this->mergeCacheDir();
        $meta = $fetcher->meta($this->mergePath, $cacheDir);

        if ($meta !== null && time() - (int) $meta['fetchedAt'] < 300) {
            return false;
        }

        try {
            $fetcher->localize($this->mergePath, cacheDir: $cacheDir);
        } catch (Throwable) {
            return false; // offline — the cached copy stays in charge
        }

        $after = $fetcher->meta($this->mergePath, $cacheDir)['sha256'] ?? null;

        if ($after === ($meta['sha256'] ?? null)) {
            return false;
        }

        $this->parseMerge();
        $this->notify('Sheet changed — '.count($this->cards).' cards reloaded.', 'success');

        return true;
    }

    /** Re-read foundry.json and re-apply the active profile. */
    public function reloadManifest(): void
    {
        $this->showManifestChanged = false;

        if ($this->manifestPath === '') {
            return;
        }

        $this->applyManifest();
        $this->notify('Set file reloaded from disk.', 'success');
    }

    /** Keep working with the in-app state; don't re-prompt for this change. */
    public function ackManifestChange(): void
    {
        $this->showManifestChanged = false;
        $this->manifestSeen = $this->manifestStamp();
    }

    private function rememberSet(\App\Manifest\SetManifest $manifest): void
    {
        $recents = (array) Settings::get('recentSets', []);

        $recents[$this->manifestPath] = [
            'set' => $manifest->set,
            'cover' => $manifest->cover(),
            'cards' => $recents[$this->manifestPath]['cards'] ?? null,
            'profiles' => count($manifest->profileNames()) + 1, // + the Default profile
            'lastOpened' => now()->toIso8601String(),
        ];

        Settings::put('recentSets', $recents);
    }

    /**
     * Open a set from the index.
     */
    public function openSet(string $path): void
    {
        if (! is_file($path)) {
            $this->notify("That set's manifest is gone: {$path}", 'error');
            $this->forgetSet($path);

            return;
        }

        $this->manifestPath = $path;
        $this->updated('manifestPath');
        $this->tab = 'cards';
    }

    public function forgetSet(string $path): void
    {
        $recents = (array) Settings::get('recentSets', []);
        unset($recents[$path]);
        Settings::put('recentSets', $recents);
    }

    /**
     * Apply the manifest's effective config (base + selected profile) to the
     * UI fields. Every field stays editable afterwards — edits are overrides.
     */
    public function applyManifest(): void
    {
        $manifest = $this->loadManifestMeta();
        if ($manifest === null) {
            return;
        }

        try {
            $config = $manifest->effective($this->profile !== '' ? $this->profile : null);
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->mergePath = $config->mergePath;
        $this->kitPath = $config->kitPath;
        $this->templatePath = $config->templatePath;
        $this->artDirs = $config->artDirs;
        $this->whiteDirs = $config->whiteDirs;
        $this->treatmentOptions = array_keys($config->treatmentConfig) ?: array_keys(config('wotf.treatments'));
        $this->treatments = $this->validTreatments($config->treatments) ?: $this->treatments;
        $this->proof = $config->proof;
        $this->format = in_array($config->format, ['pdf', 'png'], true) ? $config->format : 'pdf';
        $this->ppi = ((int) ($config->exportOptions['ppi'] ?? $this->ppi)) ?: null;
        $this->cropMarks = (bool) ($config->exportOptions['cropMarks'] ?? false);
        $this->registrationMarks = (bool) ($config->exportOptions['registrationMarks'] ?? false);
        $this->bleed = (bool) ($config->exportOptions['bleed'] ?? false);
        $this->combine = (bool) ($config->exportOptions['combine'] ?? false);
        $this->serialize = $config->serialize;

        foreach (['mergePath', 'kitPath', 'templatePath', 'format'] as $key) {
            Settings::put("paths.{$key}", $this->{$key});
        }
        Settings::put('paths.treatments', $this->treatments);
        Settings::put('paths.artDirs', $this->artDirs);
        Settings::put('paths.whiteDirs', $this->whiteDirs);
        Settings::put('paths.proof', $this->proof);
        Settings::put('paths.ppi', $this->ppi);
        foreach (['cropMarks', 'registrationMarks', 'bleed', 'combine', 'serialize'] as $flag) {
            Settings::put("paths.{$flag}", $this->{$flag});
        }

        // Opening a set pulls a linked sheet fresh (within a 5-minute
        // window); a failed download quietly keeps the cached copy.
        if (\App\Merge\GoogleSheetFetcher::isSheetUrl($this->mergePath)) {
            try {
                (new \App\Merge\GoogleSheetFetcher)->localize($this->mergePath, maxAgeSeconds: 300, cacheDir: $this->mergeCacheDir());
            } catch (Throwable) {
                // parseMerge falls back to the cache — or surfaces the error
                // itself when there is none.
            }
        }

        $this->parseMerge();
    }

    /**
     * Write the current UI values into the manifest — the selected profile,
     * a new profile (newProfileName), or the base when no profile is chosen.
     */

    /** The chosen home for a NEW set — the folder that receives .foundry/. */
    public string $setFolder = '';

    /**
     * The folder a new set would naturally live in: beside a local merge
     * file, else the first art directory. A linked sheet infers nothing.
     */
    private function defaultSetFolder(): string
    {
        if ($this->mergePath !== '' && ! \App\Merge\GoogleSheetFetcher::isUrl($this->mergePath)) {
            return dirname($this->mergePath);
        }

        return $this->artDirs[0] ?? '';
    }

    /**
     * Save the current state as a NEW set. The user chooses the SET FOLDER
     * — the directory that is the project: it receives .foundry/ (config,
     * versions, run history) and usually holds the art — and the config is
     * written inside it. Never a raw "save config.json" dialog.
     */
    public function saveManifestAs(): bool
    {
        $folder = rtrim(trim($this->setFolder), '/');

        if ($folder === '') {
            $folder = $this->defaultSetFolder();
        }

        // No folder chosen or inferable: ask (natively, with a real picker).
        if ($folder === '' && $this->native) {
            $dialog = Dialog::new()->title("Choose the set's folder — it will hold the config, run history, and usually the art");

            try {
                $dialog->asSheet();
            } catch (Throwable) {
                // no current window reference — a floating dialog still works
            }

            $picked = $dialog->folders()->open();

            if (! is_string($picked) || $picked === '') {
                return false; // cancelled
            }

            $folder = rtrim($picked, '/');
        }

        if ($folder === '') {
            $this->notify("Choose the set's folder first — everything about the set (config, run history) lives there.");

            return false;
        }

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true)) {
            $this->notify("Cannot create the set folder: {$folder}", 'error');

            return false;
        }

        $config = \App\Manifest\FoundryLayout::configPathFor($folder);

        if (is_file($config) || is_file($folder.'/'.\App\Manifest\FoundryLayout::LEGACY)) {
            $this->notify('That folder already holds a set — open it from the Sets screen instead.', 'error');

            return false;
        }

        $this->setFolder = $folder;

        if (! $this->persistManifest($config)) {
            return false;
        }

        $this->setFolder = ''; // don't leak into the next "save as"

        return true;
    }

    public function saveToManifest(): void
    {
        if ($this->manifestPath === '' && $this->mergePath === '') {
            $this->notify('Choose a merge file first — the manifest is created next to it.');

            return;
        }

        if ($this->manifestPath === '' && \App\Merge\GoogleSheetFetcher::isUrl($this->mergePath)) {
            // A linked sheet has no folder to sit next to.
            $this->saveManifestAs();

            return;
        }

        $this->persistManifest($this->manifestPath !== ''
            ? $this->manifestPath
            : \App\Manifest\FoundryLayout::configPathFor(dirname($this->mergePath)));
    }

    /**
     * The manifest-shaped values of the current UI state.
     */
    private function manifestValues(): array
    {
        return [
            'merge' => $this->mergePath,
            'kit' => rtrim($this->kitPath, '/'),
            'template' => $this->templatePath,
            'artDirs' => array_map(fn ($dir) => rtrim($dir, '/'), $this->artDirs),
            'whiteDirs' => array_map(fn ($dir) => rtrim($dir, '/'), $this->whiteDirs),
            'treatment' => $this->treatments,
            'proof' => $this->proof,
            'serialize' => $this->serialize,
            // The flags are written explicitly (true AND false) — saves merge
            // into the existing manifest, so an omitted false could never
            // switch an option off again.
            'export' => array_filter([
                'format' => $this->format,
                'ppi' => $this->format === 'png' ? $this->ppi : null,
            ]) + [
                'cropMarks' => $this->cropMarks,
                'registrationMarks' => $this->registrationMarks,
                'bleed' => $this->bleed,
                'combine' => $this->combine,
            ],
        ];
    }

    /**
     * Only the values that differ from the base config — profiles store
     * overrides, not snapshots, so un-changed fields keep inheriting.
     */
    private function profileOverrideValues(): array
    {
        $values = $this->manifestValues();

        if ($this->inherited === []) {
            return $values;
        }

        $base = [
            'merge' => $this->inherited['mergePath'] ?? '',
            'kit' => rtrim((string) ($this->inherited['kitPath'] ?? ''), '/'),
            'template' => $this->inherited['templatePath'] ?? '',
            'artDirs' => $this->inherited['artDirs'] ?? [],
            'whiteDirs' => $this->inherited['whiteDirs'] ?? [],
            'treatment' => $this->inherited['treatments'] ?? [],
            'proof' => $this->inherited['proof'] ?? true,
            'serialize' => $this->inherited['serialize'] ?? false,
            'cover' => $this->inherited['coverPath'] ?? '',
            'export' => array_filter([
                'format' => $this->inherited['format'] ?? 'pdf',
                'ppi' => ($this->inherited['format'] ?? 'pdf') === 'png' ? ($this->inherited['ppi'] ?? 300) : null,
            ]) + [
                'cropMarks' => $this->inherited['cropMarks'] ?? false,
                'registrationMarks' => $this->inherited['registrationMarks'] ?? false,
                'bleed' => $this->inherited['bleed'] ?? false,
                'combine' => $this->inherited['combine'] ?? false,
            ],
        ];

        return array_filter(
            $values,
            fn ($value, $key) => $value != ($base[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function persistManifest(string $path): bool
    {
        if (! $this->editorMode) {
            $this->notify('Editing set configurations is turned off on this machine — enable it in Preferences (⌘,).');

            return false;
        }

        // Compare-before-write: if the file changed on disk since we read
        // it (another user, another machine), don't clobber — surface the
        // reload prompt instead. Every save also lands in versions/, so
        // even a race that slips through loses nothing.
        if ($path === $this->manifestPath && $this->manifestSeen !== null
            && is_file($path) && $this->manifestStamp() !== $this->manifestSeen) {
            $this->showManifestChanged = true;
            $this->notify('The set file changed on disk since you opened it — review before saving.', 'error');

            return false;
        }

        // Saving a legacy foundry.json moves it into the .foundry/ layout
        // first. Paths keep resolving against the set folder, so the
        // config's relative paths survive the move untouched.
        if (is_file($path) && ! \App\Manifest\FoundryLayout::isFoundryConfig($path)
            && basename($path) === \App\Manifest\FoundryLayout::LEGACY) {
            $migrated = \App\Manifest\FoundryLayout::configPathFor(dirname($path));

            @mkdir(dirname($migrated), 0755, true);
            if (@rename($path, $migrated)) {
                if ($this->manifestPath === $path) {
                    $this->manifestPath = $migrated;
                    Settings::put('paths.manifestPath', $migrated);
                }

                $recents = (array) Settings::get('recentSets', []);
                if (isset($recents[$path])) {
                    $recents[$migrated] = $recents[$path];
                    unset($recents[$path]);
                    Settings::put('recentSets', $recents);
                }

                $path = $migrated;
                $this->notify('Set config moved into .foundry/ — same folder, tidier home.', 'success');
            }
        }

        $target = trim($this->newProfileName) !== '' ? trim($this->newProfileName) : $this->profile;
        $values = $this->manifestValues();
        $creatingNamed = trim($this->newProfileName) !== '';

        try {
            if (! is_file($path)) {
                (new \App\Manifest\ManifestWriter)->create(
                    $path,
                    $this->setName !== ''
                        ? $this->setName
                        : (($this->summary['set'] ?? '') ?: basename(dirname($path))),
                    $values + ['cover' => $this->coverPath],
                );
                $this->notify('Manifest created: '.$path, 'success');
            } elseif ($target === '') {
                (new \App\Manifest\ManifestWriter)->saveBase($path, $values + ['cover' => $this->coverPath]);
                $this->notify('Saved to the manifest\'s base config.', 'success');
            } else {
                (new \App\Manifest\ManifestWriter)->saveProfile($path, $target, $this->profileOverrideValues(), replace: true);
                // The cover is a set-level property — it always lives on base.
                if ($this->coverPath !== (string) ($this->inherited['coverPath'] ?? '')) {
                    (new \App\Manifest\ManifestWriter)->saveBase($path, ['cover' => $this->coverPath]);
                }
                $this->notify("Saved to profile \"{$target}\" (".count($this->profileOverrideValues()).' override(s)).', 'success');
            }

            if ($target !== '') {
                $this->profile = $target;
                Settings::put('paths.profile', $target);
            }

            // The set's own name is editable in the setup panel too.
            if (trim($this->setName) !== '') {
                (new \App\Manifest\ManifestWriter)->setSetName($path, trim($this->setName));
            }

            $this->manifestPath = $path;
            Settings::put('paths.manifestPath', $path);
            $this->showAdvanced = false;
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return false;
        }

        if (! $creatingNamed) {
            $this->applyProfileRename($path);
        }

        $this->newProfileName = '';
        $this->loadManifestMeta();

        return true;
    }

    /**
     * The setup view lets the user rename the profile they're editing —
     * the Default profile's label lives in the manifest too.
     */
    private function applyProfileRename(string $path): void
    {
        $new = trim($this->editProfileName);

        if ($new === '' || ! is_file($path)) {
            return;
        }

        try {
            if ($this->profile === '') {
                if ($new !== $this->defaultProfileName) {
                    (new \App\Manifest\ManifestWriter)->setDefaultProfileName($path, $new);
                }
            } elseif ($new !== $this->profile) {
                (new \App\Manifest\ManifestWriter)->renameProfile($path, $this->profile, $new);
                $this->profile = $new;
                Settings::put('paths.profile', $new);
            }
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');
        }
    }

    public function addArtDir(?string $path = null): void
    {
        $path = rtrim(trim($path ?? $this->newArtDir), '/');
        $this->newArtDir = '';

        if ($path === '' || in_array($path, $this->artDirs, true)) {
            return;
        }

        $this->artDirs[] = $path;
        $this->artDirsChanged();
    }

    public function addWhiteDir(?string $path = null): void
    {
        $path = rtrim(trim($path ?? $this->newWhiteDir), '/');
        $this->newWhiteDir = '';

        if ($path === '' || in_array($path, $this->whiteDirs, true)) {
            return;
        }

        $this->whiteDirs[] = $path;
        Settings::put('paths.whiteDirs', $this->whiteDirs);
    }

    public function removeWhiteDir(int $index): void
    {
        unset($this->whiteDirs[$index]);
        $this->whiteDirs = array_values($this->whiteDirs);
        Settings::put('paths.whiteDirs', $this->whiteDirs);
    }

    public function moveWhiteDir(int $index, int $direction): void
    {
        $swap = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->whiteDirs[$index], $this->whiteDirs[$swap])) {
            return;
        }

        [$this->whiteDirs[$index], $this->whiteDirs[$swap]] = [$this->whiteDirs[$swap], $this->whiteDirs[$index]];
        Settings::put('paths.whiteDirs', $this->whiteDirs);
    }

    public function removeArtDir(int $index): void
    {
        unset($this->artDirs[$index]);
        $this->artDirs = array_values($this->artDirs);
        $this->artDirsChanged();
    }

    public function moveArtDir(int $index, int $direction): void
    {
        $swap = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->artDirs[$index], $this->artDirs[$swap])) {
            return;
        }

        [$this->artDirs[$index], $this->artDirs[$swap]] = [$this->artDirs[$swap], $this->artDirs[$index]];
        $this->artDirsChanged();
    }

    private function artDirsChanged(): void
    {
        Settings::put('paths.artDirs', $this->artDirs);
        $this->parseMerge();
    }

    public function clearPath(string $target): void
    {
        if (! in_array($target, ['mergePath', 'kitPath', 'templatePath', 'destDir', 'manifestPath', 'coverPath'], true)) {
            return;
        }

        // With a manifest open, clearing an input means "stop overriding" —
        // it falls back to the inherited base value.
        $inherited = $target !== 'manifestPath' ? (string) ($this->inherited[$target] ?? '') : '';
        $this->{$target} = $this->manifestPath !== '' ? $inherited : '';
        $this->updated($target);
    }

    public function updated(string $property): void
    {
        $this->receivedUpdates = true;

        // The selection is edited client-side and synced back as a plain
        // property update ($mode: nothing selected means everything).
        if ($property === 'selected') {
            $this->mode = $this->selected === [] ? 'all' : 'selected';
        }

        // A hand-typed relative path (browser mode) resolves against the
        // set's folder — URLs (a linked Google Sheet) are left alone.
        if (in_array($property, ['mergePath', 'kitPath', 'templatePath'], true)
            && $this->manifestPath !== ''
            && $this->{$property} !== ''
            && ! str_starts_with($this->{$property}, '/')
            && ! \App\Merge\GoogleSheetFetcher::isUrl($this->{$property})) {
            $this->{$property} = \App\Manifest\FoundryLayout::baseDirFor($this->manifestPath).'/'.$this->{$property};
        }

        if (in_array($property, ['mergePath', 'kitPath', 'templatePath', 'destDir', 'manifestPath', 'format'], true)) {
            Settings::put("paths.{$property}", $this->{$property});
        }

        if ($property === 'proof') {
            Settings::put('paths.proof', $this->proof);
        }

        if ($property === 'ppi') {
            Settings::put('paths.ppi', $this->ppi);
        }

        if (in_array($property, ['cropMarks', 'registrationMarks', 'bleed', 'combine', 'serialize'], true)) {
            Settings::put("paths.{$property}", $this->{$property});
        }

        if ($property === 'editorMode') {
            Settings::put('prefs.editorMode', $this->editorMode);
        }

        if ($property === 'manifestPath') {
            // Dropping/typing the set FOLDER opens its config (.foundry/config.json,
            // or the legacy foundry.json beside the set files).
            if ($this->manifestPath !== '' && is_dir($this->manifestPath)) {
                $discovered = \App\Manifest\FoundryLayout::discover($this->manifestPath);
                if ($discovered !== null) {
                    $this->manifestPath = $discovered;
                    Settings::put('paths.manifestPath', $discovered);
                } else {
                    $this->notify('No set config found in that folder (.foundry/config.json or foundry.json).');
                    $this->manifestPath = '';
                }
            }

            $this->showAdvanced = $this->manifestPath === '';
            if ($this->manifestPath !== '') {
                $this->applyManifest();
            } else {
                $this->setName = '';
                $this->profileNames = [];
                $this->profile = '';
            }
        }

        if ($property === 'profile' && $this->manifestPath !== '') {
            if ($this->profile === '__new__') {
                $this->profile = (string) Settings::get('paths.profile', '');
                if ($this->profile === '__new__' || ! in_array($this->profile, $this->profileNames, true)) {
                    $this->profile = '';
                }
                $this->openProfileModal();

                return;
            }

            Settings::put('paths.profile', $this->profile);
            $this->applyManifest();
        }

        // A chosen kit provides the master template by default.
        if ($property === 'kitPath' && $this->kitPath !== '' && $this->templatePath === '') {
            $candidate = rtrim($this->kitPath, '/').'/'.config('wotf.kit_template');
            if (is_file($candidate)) {
                $this->templatePath = $candidate;
                Settings::put('paths.templatePath', $candidate);
            }
        }

        if ($property === 'mergePath') {
            $this->parseMerge();
        }

        if ($property === 'search') {
            $this->visibleLimit = 100;
            $this->selectionInverted = false; // new acting set, fresh invert cycle
        }
    }

    // ── Merge parsing + linting ─────────────────────────────────────────
    public function parseMerge(): void
    {
        $this->summary = [];
        $this->issues = [];
        $this->cards = [];
        $this->parseError = null;

        if ($this->mergePath === '') {
            return;
        }

        $linter = new MergeLinter(config('wotf.art_extensions'));

        try {
            // A linked Google Sheet parses cache-first: any local copy wins,
            // because this runs on many UI interactions. Fresh data comes
            // from opening the set, the refresh button, or building.
            $localMerge = (new \App\Merge\GoogleSheetFetcher)->localize(
                $this->mergePath,
                maxAgeSeconds: PHP_INT_MAX,
                cacheDir: $this->mergeCacheDir(),
            );
            $merge = (new MergeFileParser)->parse($localMerge);
        } catch (Throwable $e) {
            $this->parseError = $e->getMessage();

            return;
        }

        $artDirs = array_values(array_filter($this->artDirs, is_dir(...)));
        $resolver = $artDirs !== []
            ? new \App\Merge\ArtResolver($artDirs, config('wotf.art_extensions'))
            : null;

        $this->summary = [
            'set' => $merge->setName(),
            'cards' => $merge->count(),
            'columns' => count($merge->headers),
            'file' => basename($merge->path),
        ];

        $this->issues = array_map(fn ($i) => $i->toArray(), $linter->lint($merge, $artDirs ?: null));

        foreach ($merge->records as $record) {
            $art = ($resolver !== null && $record->imageRef() !== '')
                ? $resolver->resolve($record->imageRef())
                : null;

            $this->cards[] = [
                'ref' => $record->imageRef() !== '' ? $record->imageRef() : $record->cardCode(),
                'name' => $record->name(),
                'type' => $record->type(),
                'code' => $record->cardCode(),
                'hasArt' => $resolver === null || $art !== null,
                // resolved, but to a different format than the merge names
                'artFallback' => $art !== null && $resolver->usedSuffixFallback($record->imageRef(), $art),
            ];
        }

        $refs = array_column($this->cards, 'ref');
        $this->selected = array_values(array_intersect($this->selected, $refs));
        if (! in_array($this->singleCard, $refs, true)) {
            $this->singleCard = '';
        }
        $this->syncMode();
        $this->flagFilter = '';
        $this->showHealth = collect($this->issues)->contains(fn ($i) => $i['severity'] === 'error');

        // Keep the set index's card count current.
        if ($this->manifestPath !== '') {
            $recents = (array) Settings::get('recentSets', []);
            if (isset($recents[$this->manifestPath])) {
                $recents[$this->manifestPath]['cards'] = count($this->cards);
                Settings::put('recentSets', $recents);
            }
        }
    }

    /**
     * The set's .foundry/cache — sheet downloads sync with the set; runs
     * without a set fall back to app storage.
     */
    private function mergeCacheDir(): ?string
    {
        return $this->manifestPath !== ''
            ? \App\Manifest\FoundryLayout::foundryDirFor($this->manifestPath).'/cache'
            : null;
    }

    /**
     * Re-download a linked sheet and report whether anything changed —
     * the deliberate "pull the latest data" action.
     */
    public function refreshMerge(): void
    {
        if (! \App\Merge\GoogleSheetFetcher::isSheetUrl($this->mergePath)) {
            return;
        }

        $fetcher = new \App\Merge\GoogleSheetFetcher;
        $before = $fetcher->meta($this->mergePath, $this->mergeCacheDir())['sha256'] ?? null;

        try {
            $fetcher->localize($this->mergePath, cacheDir: $this->mergeCacheDir());
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $after = $fetcher->meta($this->mergePath, $this->mergeCacheDir())['sha256'] ?? null;

        if ($before === $after) {
            $this->notify('Sheet unchanged — the local copy is current.', 'success');

            return;
        }

        $this->parseMerge();
        $this->notify('Sheet updated — '.count($this->cards).' cards reloaded.', 'success');
    }

    /** True while the card list is narrowed by a search or a flag filter. */
    public function filterActive(): bool
    {
        return trim($this->search) !== '' || $this->flagFilter !== '';
    }

    /**
     * @param  list<string>  $refs
     * @return list<string> the given refs that are currently selected
     */
    private function chosenWithin(array $refs): array
    {
        return array_values(array_intersect($refs, $this->selected));
    }

    /**
     * Header-checkbox state over the acting set: none | partial | all.
     */
    private function selectionStateFor(array $refs): string
    {
        $chosen = count($this->chosenWithin($refs));

        return match (true) {
            $refs === [] || $chosen === 0 => 'none',
            $chosen === count($refs) => 'all',
            default => 'partial',
        };
    }

    /**
     * The selection model has one rule: nothing selected means everything.
     * $mode is derived, never user-set. Selection edits happen client-side
     * (resources/js/selection.js) and arrive as plain property updates.
     */
    private function syncMode(): void
    {
        $this->mode = $this->selected === [] ? 'all' : 'selected';
        $this->selectionInverted = false;
    }

    /**
     * @return list<string> the given treatments that the current set knows
     */
    private function validTreatments(array $treatments): array
    {
        return array_values(array_filter(
            $treatments,
            fn ($t) => is_string($t) && in_array($t, $this->treatmentOptions, true),
        ));
    }

    public function toggleTreatment(string $treatment): void
    {
        if (! in_array($treatment, $this->treatmentOptions, true)) {
            return;
        }

        if (in_array($treatment, $this->treatments, true)) {
            // Never allow zero treatments — the last one stays.
            if (count($this->treatments) > 1) {
                $this->treatments = array_values(array_diff($this->treatments, [$treatment]));
            }
        } else {
            // Keep declared order so subfolders sort predictably.
            $chosen = [...$this->treatments, $treatment];
            $this->treatments = array_values(array_filter(
                $this->treatmentOptions,
                fn ($t) => in_array($t, $chosen, true),
            ));
        }

        Settings::put('paths.treatments', $this->treatments);
    }

    // ── Create-set wizard ───────────────────────────────────────────────
    public function startCreateSet(): void
    {
        // A new set is a context switch: leave the current one behind
        // (it stays one click away on the Sets screen).
        $this->manifestPath = '';
        $this->setName = '';
        $this->profile = '';
        $this->profileNames = [];
        $this->profileOwnKeys = [];
        $this->inherited = [];
        $this->coverPath = '';
        $this->mergePath = '';
        $this->templatePath = '';
        $this->artDirs = [];
        $this->summary = [];
        $this->issues = [];
        $this->cards = [];
        $this->parseError = null;
        $this->selected = [];
        $this->syncMode();

        $this->setFolder = '';
        $this->createStep = 1;
        $this->showCreateWizard = true;
    }

    public function createSetContinue(): void
    {
        if (! is_file($this->mergePath) && ! \App\Merge\GoogleSheetFetcher::isSheetUrl($this->mergePath)) {
            $this->notify('Choose the merge file first — it defines the set.');

            return;
        }

        // The merge file usually knows the set's name — prefill it.
        if (trim($this->setName) === '') {
            $this->setName = (string) ($this->summary['set'] ?? '');
        }

        // …and a local merge file implies the set's folder.
        if (trim($this->setFolder) === '') {
            $this->setFolder = $this->defaultSetFolder();
        }

        $this->createStep = 2;
    }

    public function createSetFinish(): void
    {
        if (trim($this->setName) === '') {
            $this->notify('Give the set a name.');

            return;
        }

        if ($this->saveManifestAs()) {
            $this->showCreateWizard = false;
            $this->tab = 'cards';
        }
    }

    /**
     * The headless equivalent of the current UI state, for "Copy CLI".
     * With a manifest, only values that differ from it become flags.
     */
    public function cliCommand(): string
    {
        $parts = ['php artisan cards:export'];

        if ($this->manifestPath !== '') {
            $parts[] = '--manifest='.escapeshellarg($this->manifestPath);
            if ($this->profile !== '') {
                $parts[] = '--profile='.escapeshellarg($this->profile);
            }

            $differs = fn (string $field) => ($this->inherited[$field] ?? null) != $this->{$field};

            if ($differs('mergePath')) {
                $parts[] = '--merge='.escapeshellarg($this->mergePath);
            }
            if ($differs('kitPath')) {
                $parts[] = '--kit='.escapeshellarg($this->kitPath);
            }
            if ($differs('templatePath')) {
                $parts[] = '--template='.escapeshellarg($this->templatePath);
            }
            if ($differs('artDirs')) {
                foreach ($this->artDirs as $dir) {
                    $parts[] = '--art='.escapeshellarg($dir);
                }
            }
            if ($differs('whiteDirs')) {
                foreach ($this->whiteDirs as $dir) {
                    $parts[] = '--white='.escapeshellarg($dir);
                }
            }
            if ($differs('treatments')) {
                foreach ($this->treatments as $treatment) {
                    $parts[] = '--treatment='.escapeshellarg($treatment);
                }
            }
            if ($differs('format')) {
                $parts[] = '--format='.$this->format;
            }
            if ($differs('serialize')) {
                $parts[] = $this->serialize ? '--serialize' : '--no-serialize';
            }
            foreach (['cropMarks' => '--crop-marks', 'registrationMarks' => '--registration-marks', 'combine' => '--combine'] as $flag => $arg) {
                if ($differs($flag) && $this->{$flag}) {
                    $parts[] = $arg;
                }
            }
            if ($differs('bleed') && $this->bleed) {
                $parts[] = '--bleed=doc';
            }
        } else {
            $parts[] = '--merge='.escapeshellarg($this->mergePath ?: '…');
            $parts[] = '--kit='.escapeshellarg($this->kitPath ?: '…');
            if ($this->templatePath !== '') {
                $parts[] = '--template='.escapeshellarg($this->templatePath);
            }
            foreach ($this->artDirs as $dir) {
                $parts[] = '--art='.escapeshellarg($dir);
            }
            foreach ($this->whiteDirs as $dir) {
                $parts[] = '--white='.escapeshellarg($dir);
            }
            foreach ($this->treatments as $treatment) {
                $parts[] = '--treatment='.escapeshellarg($treatment);
            }
            $parts[] = '--format='.$this->format;
            if ($this->serialize) {
                $parts[] = '--serialize';
            }
            foreach (['cropMarks' => '--crop-marks', 'registrationMarks' => '--registration-marks', 'combine' => '--combine'] as $flag => $arg) {
                if ($this->{$flag}) {
                    $parts[] = $arg;
                }
            }
            if ($this->bleed) {
                $parts[] = '--bleed=doc';
            }
        }

        if (! $this->proof) {
            $parts[] = '--production';
        }

        $parts[] = '--dest='.escapeshellarg($this->destDir ?: '…');
        $parts[] = '--all';

        return implode(' ', $parts);
    }

    public bool $showLogsModal = false;

    /** Absolute path of the log file open in the logs modal. */
    public ?string $logFile = null;

    public function openLogsModal(): void
    {
        $files = $this->logFiles();
        $this->logFile = $files[0]['path'] ?? null;
        $this->showLogsModal = true;
    }

    /**
     * Every log file this app writes, wherever it writes it (the dev app
     * splits between project storage and app-data storage).
     *
     * @return list<array{path: string, name: string, size: int, mtime: int}>
     */
    public function logFiles(): array
    {
        $dirs = array_unique(array_filter([
            storage_path('logs'),
            dirname((string) config('logging.channels.commands.path')),
            dirname((string) config('logging.channels.runs.path')),
        ], is_dir(...)));

        $files = [];
        foreach ($dirs as $dir) {
            foreach (glob($dir.'/*.log') ?: [] as $file) {
                $files[$file] = [
                    'path' => $file,
                    'name' => basename($file),
                    'size' => (int) @filesize($file),
                    'mtime' => (int) @filemtime($file),
                ];
            }
        }

        return array_values(collect($files)->sortByDesc('mtime')->all());
    }

    /**
     * The last lines of the selected log — only files from our own list
     * are readable through here.
     *
     * @return list<string>
     */
    public function logLines(int $count = 25): array
    {
        if ($this->logFile === null
            || ! in_array($this->logFile, array_column($this->logFiles(), 'path'), true)
            || ! is_file($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];

        return array_slice($lines, -$count);
    }

    /** @Image ref of the card whose issues are open in the detail modal. */
    public ?string $detailRef = null;

    /** @Image ref of the card whose matched artwork is being previewed. */
    public ?string $artPreviewRef = null;

    public function openArtPreview(string $ref): void
    {
        $this->artPreviewRef = $ref;
    }

    /**
     * The matched artwork file for the previewed card, resolved on demand.
     */
    private function artPreview(): ?array
    {
        if ($this->artPreviewRef === null) {
            return null;
        }

        $dirs = array_values(array_filter($this->artDirs, is_dir(...)));
        $path = $dirs !== []
            ? (new \App\Merge\ArtResolver($dirs, config('wotf.art_extensions')))->resolve($this->artPreviewRef)
            : null;

        if ($path === null) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $resolver = new \App\Merge\ArtResolver($dirs, config('wotf.art_extensions'));

        return [
            'ref' => $this->artPreviewRef,
            'path' => $path,
            'displayable' => in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true),
            'extension' => $extension,
            'fallback' => $resolver->usedSuffixFallback($this->artPreviewRef, $path),
        ];
    }

    /**
     * Everything the card-detail modal needs: the card, its lint issues,
     * and — for artwork problems — exactly what was searched where.
     */
    private function cardDetail(): ?array
    {
        if ($this->detailRef === null) {
            return null;
        }

        $index = null;
        foreach ($this->cards as $i => $card) {
            if ($card['ref'] === $this->detailRef) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $card = $this->cards[$index];
        $row = $index + 1;
        $issues = array_values(array_filter($this->issues, fn ($issue) => $issue['row'] === $row));

        $extensions = config('wotf.art_extensions');
        $candidates = [$card['ref'], ...array_map(fn ($ext) => $card['ref'].'.'.$ext, $extensions)];

        return [
            'card' => $card,
            'row' => $row,
            'issues' => $issues,
            'artMissing' => ! $card['hasArt'],
            'candidates' => $candidates,
            'artDirs' => array_map(fn ($dir) => [
                'path' => $dir,
                'exists' => is_dir($dir),
            ], $this->artDirs),
        ];
    }

    public function openCardDetail(string $ref): void
    {
        $this->detailRef = $ref;
    }

    /**
     * Prev/next from either modal, walking the visible (filtered) list.
     * Each card gets the modal that fits it: matched artwork → preview,
     * missing artwork → the detail view.
     */
    public function navigateCard(int $direction): void
    {
        $current = $this->artPreviewRef ?? $this->detailRef;

        if ($current === null) {
            return;
        }

        $refs = $this->navRefs($current);
        $index = array_search($current, $refs, true);
        $target = $refs[$index + ($direction <=> 0)] ?? null;

        if ($index === false || $target === null) {
            return;
        }

        $card = collect($this->cards)->firstWhere('ref', $target);

        if ($card['hasArt'] ?? false) {
            $this->artPreviewRef = $target;
            $this->detailRef = null;
        } else {
            $this->detailRef = $target;
            $this->artPreviewRef = null;
        }
    }

    /**
     * The list the modals navigate: the filtered cards — or every card,
     * when the open one isn't in the current filter.
     *
     * @return list<string>
     */
    private function navRefs(string $current): array
    {
        $refs = array_column($this->filteredCards(), 'ref');

        return in_array($current, $refs, true) ? $refs : array_column($this->cards, 'ref');
    }

    /**
     * "Card X of Y" plus prev/next availability for the open modal.
     *
     * @return ?array{pos:int,total:int,hasPrev:bool,hasNext:bool}
     */
    private function cardNav(): ?array
    {
        $current = $this->artPreviewRef ?? $this->detailRef;

        if ($current === null) {
            return null;
        }

        $refs = $this->navRefs($current);
        $index = array_search($current, $refs, true);

        if ($index === false) {
            return null;
        }

        return [
            'pos' => $index + 1,
            'total' => count($refs),
            'hasPrev' => $index > 0,
            'hasNext' => $index < count($refs) - 1,
        ];
    }

    /**
     * Reveal a folder (or a file's folder) in Finder.
     */
    public function openInFinder(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            $this->notify("That folder doesn't exist: {$path}");

            return;
        }

        // Folders open directly; files get revealed (selected) in their folder.
        $open = (string) config('wotf.open_binary', '/usr/bin/open');
        \Illuminate\Support\Facades\Process::timeout(15)->run(
            is_dir($path) ? [$open, $path] : [$open, '-R', $path],
        );
    }

    /**
     * The manifest-relative form of a path when it can travel with the set,
     * or null when it's machine-specific.
     */
    public function portableDisplay(string $path): ?string
    {
        if ($this->manifestPath === '' || $path === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        return \App\Manifest\Portability::relativize($path, \App\Manifest\FoundryLayout::baseDirFor($this->manifestPath));
    }

    /**
     * A file/folder dropped from Finder onto one of the path rows.
     */
    public function setPathFromDrop(string $field, string $path): void
    {
        $path = rtrim($path, '/');

        $ok = match ($field) {
            'artDirs', 'whiteDirs', 'kitPath', 'destDir' => is_dir($path),
            'mergePath', 'templatePath' => is_file($path),
            default => false,
        };

        if (! $ok) {
            $this->notify(match ($field) {
                'artDirs' => 'Drop a folder to add it as an art directory.',
                'whiteDirs' => 'Drop a folder to add it as a white-layer directory.',
                'kitPath' => 'Drop the build-kit folder here.',
                'destDir' => 'Drop a folder to use as the destination.',
                'mergePath' => 'Drop a .csv or .xlsx merge file here.',
                'templatePath' => 'Drop an .indd template file here.',
                default => 'That can\'t be dropped here.',
            });

            return;
        }

        if ($field === 'artDirs') {
            $this->addArtDir($path);

            return;
        }

        if ($field === 'whiteDirs') {
            $this->addWhiteDir($path);

            return;
        }

        $this->{$field} = $path;
        $this->updated($field);
    }

    // ── Preferences ─────────────────────────────────────────────────────
    public function openPreferences(): void
    {
        $this->refreshGoogleStatus();
        $this->showPreferences = true;
    }

    public function refreshGoogleStatus(): void
    {
        $this->googleEmail = (string) (\App\Google\GoogleAuth::email() ?? '');
    }

    /**
     * Launch the loopback OAuth flow in a child process; the browser does
     * the rest, and the panel's poll picks up the connected identity.
     */
    public function connectGoogle(): void
    {
        if (! \App\Google\GoogleAuth::configured()) {
            $this->notify('Google sign-in is not configured — set GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET first.', 'error');

            return;
        }

        $this->launchArtisan(['google:connect'], 'wotf-google-connect');
        $this->notify('Finish signing in to Google in your browser…', 'success');
    }

    public function disconnectGoogle(): void
    {
        \App\Google\GoogleAuth::disconnect();
        $this->refreshGoogleStatus();
        $this->notify('Google account disconnected.', 'success');
    }

    // ── Google Sheet picker ─────────────────────────────────────────────

    /**
     * Open the Google Picker over the signed-in account's Drive — the
     * browser-side widget does the listing (the only verification-free way
     * to browse a user's sheets) and reports the pick back to sheetPicked().
     */
    public function openSheetPicker(): void
    {
        if (! \App\Google\GoogleAuth::connected()) {
            $this->notify('Connect your Google account first — Preferences (⌘,) → Google account. The picker browses sheets as you.');

            return;
        }

        if (! \App\Google\GoogleAuth::hasScope('https://www.googleapis.com/auth/drive.file')) {
            $this->notify('Your Google connection predates the sheet picker — disconnect and reconnect in Preferences (⌘,) to grant it.');

            return;
        }

        if (($apiKey = (string) config('services.google.api_key')) === '') {
            $this->notify('The sheet picker needs GOOGLE_API_KEY (an API key with the Google Picker API enabled).', 'error');

            return;
        }

        try {
            $token = (new \App\Google\GoogleAuth)->accessToken();
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->dispatch('open-google-picker',
            token: (string) $token,
            apiKey: $apiKey,
            appId: (string) config('services.google.app_id'),
        );
    }

    /** Sets-screen entry: a fresh set whose merge is a picked sheet. */
    public function startCreateFromSheet(): void
    {
        $this->startCreateSet();
        $this->openSheetPicker();
    }

    #[\Livewire\Attributes\On('sheet-picked')]
    public function sheetPicked(string $id, string $name = ''): void
    {
        if ($id === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return;
        }

        $this->mergePath = "https://docs.google.com/spreadsheets/d/{$id}/edit";
        $this->updated('mergePath');

        if ($this->parseError === null) {
            $this->notify('Linked Google Sheet'.($name !== '' ? " “{$name}”" : '').' — '.count($this->cards).' cards.', 'success');
        }
    }

    /**
     * The info modal's exit ramp: close it and open the editor.
     */
    public function editFromInfo(): void
    {
        $this->showInfoModal = false;
        $this->showAdvanced = true;
    }

    /**
     * Close the editor, discarding unsaved edits when a set is loaded.
     */
    public function cancelEdit(): void
    {
        if ($this->manifestPath !== '') {
            $this->applyManifest();
        }

        $this->showAdvanced = false;
    }

    public function openProfileModal(): void
    {
        if ($this->manifestPath === '') {
            $this->notify('Open (or save) a set first — profiles live inside a set manifest.');

            return;
        }

        $this->newProfileName = '';
        $this->showProfileModal = true;
    }

    /**
     * Save the current settings into a NEW named profile on the open set,
     * activate it, and collapse the manual-setup panel.
     */
    public function saveAsProfile(): void
    {
        if (trim($this->newProfileName) === '' || $this->manifestPath === '') {
            return;
        }

        if ($this->persistManifest($this->manifestPath)) {
            $this->showProfileModal = false;
            $this->showAdvanced = true; // land in the setup view to configure it
        }
    }

    /**
     * Delete the profile being edited. The Default profile is the set's base
     * config — every other profile inherits from it — so it stays.
     */
    public function deleteProfile(): void
    {
        if (! $this->editorMode) {
            $this->notify('Editing set configurations is turned off on this machine — enable it in Preferences (⌘,).');

            return;
        }

        if ($this->manifestPath === '' || $this->profile === '') {
            $this->notify("The {$this->defaultProfileName} profile is the set's base config — it can't be deleted.");

            return;
        }

        $name = $this->profile;

        try {
            (new \App\Manifest\ManifestWriter)->deleteProfile($this->manifestPath, $name);
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->profile = '';
        Settings::put('paths.profile', '');
        $this->loadManifestMeta();
        $this->applyManifest();
        $this->notify("Profile \"{$name}\" deleted — back on {$this->defaultProfileName}.", 'success');
    }

    public function setDisplayName(): string
    {
        // The stored (user-editable) name wins; the merge file's Set Name
        // column is the fallback for sets that haven't been named yet.
        return $this->setName
            ?: (($this->summary['set'] ?? '')
                ?: ($this->manifestPath !== '' ? basename(dirname($this->manifestPath)) : ''));
    }

    /**
     * Refs of cards flagged by lint — all rules, or one rule slug.
     *
     * @return list<string>
     */
    public function flaggedRefs(string $rule = ''): array
    {
        return collect($this->issues)
            ->when($rule !== '' && $rule !== 'any', fn ($c) => $c->where('rule', $rule))
            ->pluck('row')
            ->filter()
            ->unique()
            ->map(fn ($row) => $this->cards[$row - 1]['ref'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public function filterByRule(string $rule): void
    {
        $this->flagFilter = $this->flagFilter === $rule ? '' : $rule;
        $this->search = '';
        $this->visibleLimit = 100;
        $this->selectionInverted = false; // new acting set, fresh invert cycle
    }

    public function loadMore(): void
    {
        $this->visibleLimit += 100;
    }

    /**
     * The cards the browser is showing — every card, narrowed by the flag
     * filter and then the search box. Uncapped; the view slices it.
     *
     * @return list<array{ref:string,name:string,type:string,code:string,hasArt:bool,artFallback:bool}>
     */
    private function filteredCards(): array
    {
        $filtered = $this->cards;

        if ($this->flagFilter !== '') {
            $flagged = array_flip($this->flaggedRefs($this->flagFilter));
            $filtered = array_values(array_filter($filtered, fn ($c) => isset($flagged[$c['ref']])));
        }

        $needle = mb_strtolower(trim($this->search));
        if ($needle !== '') {
            $filtered = array_values(array_filter(
                $filtered,
                fn ($c) => str_contains(mb_strtolower($c['name'].' '.$c['type'].' '.$c['code'].' '.$c['ref']), $needle),
            ));
        }

        return $filtered;
    }

    // ── Bridge ──────────────────────────────────────────────────────────
    public function refreshBridge(bool $ping = true): void
    {
        $supervisor = app(BridgeSupervisor::class);
        $client = app(BridgeClient::class);

        $this->bridge['installed'] = $supervisor->isInstalled();
        $this->bridge['up'] = $supervisor->isRunning();

        // While a run is executing, don't ping — the bridge processes scripts
        // serially, so a ping would queue behind a card build. The run itself
        // is proof of the connection.
        if ($ping && $this->runId !== null
            && Run::where('id', $this->runId)->whereIn('status', ['pending', 'running'])->exists()) {
            $this->bridge['indesign'] = true;

            return;
        }

        if ($ping && $this->bridge['up']) {
            // Status checks get a short leash — a healthy round-trip is
            // near-instant, and anything slower shouldn't hold up the UI
            // (interactions queue behind an in-flight check).
            $result = $client->ping(3);
            $this->bridge['indesign'] = $result->ok;
            $this->bridge['detail'] = $result->ok ? (string) $result->data : $result->error;
        } elseif (! $this->bridge['up']) {
            $this->bridge['indesign'] = null;
            $this->bridge['detail'] = null;
        }
    }

    /**
     * The pill's click handler. Identical work to refreshBridge(), but under
     * its own name so the pill can carry two distinct wire:loading targets:
     * a click always spins, while a background poll spins only when the
     * bridge isn't healthy.
     */
    public function manualRefreshBridge(): void
    {
        $this->refreshBridge();
    }

    /** Set whenever any property update arrives in the current request. */
    private bool $receivedUpdates = false;

    /**
     * The 5s background check. Same work as refreshBridge(), but when
     * nothing changed it skips re-rendering — polling this (large) component
     * every 5s would otherwise re-send and re-morph the whole page, and any
     * click landing mid-poll queues behind that. A poll that happened to
     * carry deferred property updates (e.g. a selection sync) still renders,
     * so the page reflects them.
     */
    public function pollBridge(): void
    {
        $before = $this->bridge;

        $this->refreshBridge();

        if ($this->bridge === $before && ! $this->receivedUpdates) {
            $this->skipRender();
        }
    }

    public function installBridge(): void
    {
        $this->launchArtisan(['bridge:install'], 'wotf-bridge-install');
        $this->notify('Installing the bridge in the background — refresh status in a moment.');
    }

    public bool $showSetupWizard = false;

    public ?string $wizardNote = null;

    public function openSetupWizard(): void
    {
        $this->wizardNote = null;
        $this->showSetupWizard = true;
        $this->refreshBridge();
    }

    public function wizardOpenInDesign(): void
    {
        $this->wizardNote = app(BridgeSupervisor::class)->openInDesign()
            ? 'Opening InDesign…'
            : "Couldn't find InDesign in /Applications — install it from Creative Cloud first.";
    }

    public function installPlugin(): void
    {
        [$ok, $message] = app(BridgeSupervisor::class)->installPlugin();

        if ($this->showSetupWizard) {
            $this->wizardNote = $message;
        } else {
            $this->notify($message);
        }

        if ($ok) {
            $this->refreshBridge();
        }
    }

    public function revealPlugin(): void
    {
        $path = app(BridgeSupervisor::class)->pluginPath();

        if ($this->native) {
            \Native\Laravel\Facades\Shell::showInFolder($path.'/manifest.json');
        } else {
            $this->notify("UXP plugin folder: {$path} — load it into InDesign once via Adobe UXP Developer Tool.");
        }
    }

    public function startBridge(): void
    {
        try {
            app(BridgeSupervisor::class)->ensureRunning();
            $this->flash = null;
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');
        }

        $this->refreshBridge();
    }

    // ── Run ─────────────────────────────────────────────────────────────
    /** image_ref whose log lines the run panel is narrowed to (null = all). */
    public ?string $activityFocusRef = null;

    /** Toggle the run-panel log filter onto one card's lines. */
    public function focusRunItem(string $ref): void
    {
        $this->activityFocusRef = $this->activityFocusRef === $ref ? null : $ref;
    }

    public function go(): void
    {
        $this->flash = null;
        $this->activityFocusRef = null;

        if ($this->format === 'png' && ! $this->ppi) {
            $this->notify('Set a PNG resolution (PPI) before building.');

            return;
        }

        $artDirs = array_map(fn ($dir) => rtrim($dir, '/'), $this->artDirs);

        foreach ([
            'merge file' => is_file($this->mergePath) || \App\Merge\GoogleSheetFetcher::isSheetUrl($this->mergePath),
            'art directory (add at least one)' => $artDirs !== [],
            'build kit (folder containing wotfLib.jsxinc)' => is_file(rtrim($this->kitPath, '/').'/'.config('wotf.kit_library')),
            'template' => is_file($this->templatePath),
            'destination directory' => is_dir($this->destDir),
        ] as $label => $ok) {
            if (! $ok) {
                $this->notify("Set a valid {$label} before building.");

                return;
            }
        }

        foreach ($artDirs as $dir) {
            if (! is_dir($dir)) {
                $this->notify("Art directory does not exist: {$dir}");

                return;
            }
        }

        $refs = match ($this->mode) {
            'all' => [],
            'selected' => $this->selected,
            'single' => $this->singleCard !== '' ? [$this->singleCard] : [],
        };

        if ($this->mode !== 'all' && $refs === []) {
            $this->notify($this->mode === 'single' ? 'Choose a card to build.' : 'Select at least one card.');

            return;
        }

        if ($this->blockingLintErrors($refs) && ! $this->overrideLint) {
            $this->notify('Lint errors affect the cards you chose. Fix them, or tick "build anyway".');

            return;
        }

        if ($this->bridge['indesign'] !== true) {
            $this->notify("InDesign isn't connected — builds need a live connection. Use \"Set up InDesign…\" in the top bar.");

            return;
        }

        try {
            // Builds always fetch a linked sheet fresh — no stale cache.
            $merge = (new MergeFileParser)->parse(
                (new \App\Merge\GoogleSheetFetcher)->localize($this->mergePath, cacheDir: $this->mergeCacheDir()),
            );
            $run = app(ExportPlanner::class)->plan(
                $merge,
                $this->buildConfig($artDirs),
                rtrim($this->destDir, '/'),
                $refs,
                $this->manifestPath ?: null,
            );
        } catch (Throwable $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->runId = $run->id;
        $this->tab = 'activity';
        $this->launchArtisan(['cards:export', "--run-id={$run->id}"], "wotf-export-{$run->id}");
    }

    public function viewRun(int $id): void
    {
        $this->runId = $id;
    }

    /**
     * The UI fields are the truth for a run; a loaded manifest contributes
     * only what the UI cannot edit (the pipeline) plus the set identity.
     *
     * @param  list<string>  $artDirs
     */
    private function buildConfig(array $artDirs): \App\Manifest\BuildConfig
    {
        $pdfOptions = $this->format === 'pdf' ? [
            'cropMarks' => $this->cropMarks,
            'registrationMarks' => $this->registrationMarks,
            'bleed' => $this->bleed,
            'combine' => $this->combine,
        ] : [];

        $whiteDirs = array_map(fn ($dir) => rtrim($dir, '/'), $this->whiteDirs);

        $overrides = [
            'merge' => $this->mergePath,
            'kit' => rtrim($this->kitPath, '/'),
            'template' => $this->templatePath,
            'artDirs' => $artDirs,
            'whiteDirs' => $whiteDirs,
            'treatment' => $this->treatments,
            'proof' => $this->proof,
            'serialize' => $this->serialize,
            'export' => array_filter([
                'format' => $this->format,
                'ppi' => $this->format === 'png' ? $this->ppi : null,
            ]) + $pdfOptions,
        ];

        if ($this->manifestPath !== '' && is_file($this->manifestPath)) {
            return (new \App\Manifest\ManifestLoader)
                ->load($this->manifestPath)
                ->effective($this->profile !== '' ? $this->profile : null, $overrides);
        }

        return new \App\Manifest\BuildConfig(
            set: $this->setName,
            profile: null,
            mergePath: $this->mergePath,
            kitPath: rtrim($this->kitPath, '/'),
            templatePath: $this->templatePath,
            artDirs: $artDirs,
            treatments: $this->treatments,
            proof: $this->proof,
            format: $this->format,
            exportOptions: ($this->format === 'png' ? ['ppi' => $this->ppi] : []) + $pdfOptions,
            pipeline: null,
            whiteDirs: $whiteDirs,
            serialize: $this->serialize,
        );
    }

    public function dismissRun(): void
    {
        $this->runId = null;
    }

    public function hasLintErrors(): bool
    {
        return collect($this->issues)->contains(fn ($i) => $i['severity'] === 'error');
    }

    /**
     * Errors on the file itself or on the specific rows being built.
     *
     * @param  list<string>  $refs  empty = all cards
     */
    private function blockingLintErrors(array $refs): bool
    {
        $rows = null;
        if ($refs !== []) {
            $rows = [];
            foreach ($this->cards as $index => $card) {
                if (in_array($card['ref'], $refs, true)) {
                    $rows[] = $index + 1; // records are row-ordered
                }
            }
        }

        return collect($this->issues)->contains(fn ($i) => $i['severity'] === 'error'
            && ($rows === null || $i['row'] === null || in_array($i['row'], $rows, true)));
    }

    private function launchArtisan(array $args, string $alias): void
    {
        if ($this->native) {
            // The Electron runtime hands children DB_CONNECTION=nativephp without
            // the flag that registers that connection — point the child straight
            // at the same sqlite file with the plain driver instead.
            ChildProcess::artisan($args, $alias, env: [
                'NATIVEPHP_RUNNING' => 'false',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => config('database.connections.nativephp.database')
                    ?? database_path('nativephp.sqlite'),
            ]);

            return;
        }

        // Browser/dev mode: detach a CLI process so it survives this request.
        $php = PHP_BINARY;
        if (str_contains($php, 'fpm')) {
            $candidate = str_replace('-fpm', '', $php);
            $php = is_file($candidate) ? $candidate : 'php';
        }

        $command = implode(' ', array_map(escapeshellarg(...), [$php, base_path('artisan'), ...$args]));
        $log = escapeshellarg(storage_path("logs/{$alias}.log"));
        shell_exec("nohup {$command} >> {$log} 2>&1 < /dev/null &");
    }

    public function render()
    {
        $activeRun = $this->runId ? Run::withCount([
            'items as done_count' => fn ($q) => $q->where('status', 'done'),
            'items as failed_count' => fn ($q) => $q->where('status', 'failed'),
        ])->find($this->runId) : null;

        $filtered = $this->filteredCards();

        $actingRefs = array_column($this->filterActive() ? $filtered : $this->cards, 'ref');
        $this->selectionState = $this->selectionStateFor($actingRefs);

        $issueGroups = collect($this->issues)
            ->filter(fn ($i) => $i['row'] !== null)
            ->groupBy(fn ($i) => $i['rule'] ?: $i['severity'].':'.($i['column'] ?? 'file'))
            ->map(fn ($group, $rule) => [
                'severity' => $group->contains(fn ($i) => $i['severity'] === 'error') ? 'error' : 'warning',
                'count' => $group->count(),
                'summary' => str_replace('{n}', (string) $group->count(), self::RULE_SUMMARIES[$rule] ?? $group->first()['message']),
            ])
            ->sortBy([['severity', 'asc'], ['count', 'desc']]);

        return view('livewire.control-plane', [
            'activeRun' => $activeRun,
            'runItems' => $activeRun?->items()->orderBy('id')->get() ?? collect(),
            'recentRuns' => Run::withCount([
                'items as done_count' => fn ($q) => $q->where('status', 'done'),
                'items as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])
                ->when($this->manifestPath !== '' && ! $this->activityAllSets,
                    fn ($q) => $q->where('manifest_path', $this->manifestPath))
                ->latest()->take(10)->get(),
            'filteredCards' => array_slice($filtered, 0, max(100, $this->visibleLimit)),
            'filteredTotal' => count($filtered),
            'filteredRefs' => array_column($filtered, 'ref'),
            'cardNames' => array_column($this->cards, 'name', 'ref'),
            'issueGroups' => $issueGroups,
            'fileLevelIssues' => array_values(array_filter($this->issues, fn ($i) => $i['row'] === null)),
            'currentItemName' => $activeRun?->items()->where('status', 'running')->value('name'),
            'pipeline' => config('wotf.pipeline'),
            'recentSets' => collect((array) Settings::get('recentSets', []))->sortByDesc('lastOpened'),
            'cardDetail' => $this->cardDetail(),
            'artPreview' => $this->artPreview(),
            'cardNav' => $this->cardNav(),
            'logFiles' => $this->showLogsModal ? $this->logFiles() : [],
            'logLines' => $this->showLogsModal ? $this->logLines() : [],
            'setup' => $this->showSetupWizard ? [
                'app' => ($supervisor = app(BridgeSupervisor::class))->indesignAppPath(),
                'running' => $supervisor->indesignRunning(),
                'plugin' => $supervisor->pluginInstalled(),
            ] : null,
        ]);
    }
}

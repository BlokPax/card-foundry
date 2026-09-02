@php
    $errorCount = collect($issues)->where('severity', 'error')->count();
    $warningCount = count($issues) - $errorCount;
    $runActive = $activeRun && ! $activeRun->isFinished();
    $buildCount = $selected === [] ? count($cards) : count($selected);
    $setTitle = $this->setDisplayName();
    $setInitials = collect(preg_split('/\s+/', trim($setTitle)) ?: [])
        ->filter(fn ($word) => preg_match('/[\p{L}\p{N}]/u', $word))
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->take(3)
        ->implode('');

    // A cover value is a data URI (embedded) or a file path (legacy).
    $coverSrc = function (?string $cover): ?string {
        if (! $cover) {
            return null;
        }
        if (str_starts_with($cover, 'data:')) {
            return $cover;
        }

        return is_file($cover)
            ? route('local-image', ['p' => rtrim(strtr(base64_encode($cover), '+/', '-_'), '=')])
            : null;
    };

    [$healthTone, $healthCopy] = $parseError
        ? ['pill-danger', "Can't read the file"]
        : ($errorCount
            ? ['pill-danger', $errorCount.' error'.($errorCount === 1 ? '' : 's')]
            : ($warningCount
                ? ['pill-warn', $warningCount.' warning'.($warningCount === 1 ? '' : 's')]
                : ['pill-ok', 'Ready']));
@endphp

@php
    $modalOpen = $showLogsModal || $showInfoModal || $showProfileModal || $showCoverEditor
        || $showCreateWizard || $showSetupWizard || $showManifestChanged || $showPreferences || $detailRef !== null || $artPreviewRef !== null;
@endphp
<div class="min-h-screen" @if ($runActive) wire:poll.2s @endif
     x-data x-on:keydown.window.meta.b.prevent="$wire.go()"
     x-on:keydown.window="if (($event.metaKey || $event.ctrlKey) && $event.key === ',') { $event.preventDefault(); $wire.openPreferences(); }"
     x-on:focus.window="$wire.checkManifestFreshness()">

    @if ($modalOpen)
        <style>body { overflow: hidden; }</style>
    @endif

    {{-- ── App bar ────────────────────────────────────────────────────── --}}
    <header class="sticky top-0 z-30 border-b border-line bg-surface/85 backdrop-blur-md">
        <div class="mx-auto flex h-14 w-full max-w-[1220px] items-center gap-5 px-6">

            <div class="flex shrink-0 items-center gap-2">
                <x-logo size="size-6" />
                <p class="text-[15px] font-semibold tracking-tight text-fg">
                    Card <span class="text-accent">Foundry</span>
                </p>
            </div>

            <nav class="flex h-14 items-stretch gap-1">
                @foreach (['sets' => 'Sets', 'cards' => 'Cards', 'activity' => 'Activity'] as $key => $label)
                    <button wire:click="$set('tab','{{ $key }}')"
                            class="relative flex items-center gap-2 px-3 text-sm font-medium transition
                                   {{ $tab === $key ? 'text-fg' : 'text-fg-subtle hover:text-fg' }}">
                        {{ $label }}
                        @if ($tab === $key)
                            <span class="absolute inset-x-2.5 bottom-0 h-0.5 rounded-full bg-accent"></span>
                        @endif
                        @if ($key === 'activity' && $activeRun)
                            @if ($runActive)
                                <span class="text-[11px] font-semibold text-accent tabular-nums">
                                    {{ $activeRun->done_count + $activeRun->failed_count }}/{{ $activeRun->total }}
                                </span>
                            @elseif ($activeRun->failed_count)
                                <flux:badge color="red" size="sm">{{ $activeRun->failed_count }}</flux:badge>
                            @endif
                        @endif
                    </button>
                @endforeach
            </nav>

            <div class="flex-1"></div>

            @php
                $bridgeState = ! $bridge['installed'] ? 'missing' : (! $bridge['up'] ? 'down' : ($bridge['indesign'] === false ? 'noid' : ($bridge['indesign'] ? 'ok' : 'up')));
                [$dot, $bridgeLabel] = match ($bridgeState) {
                    'missing' => ['bg-fg-subtle', 'Bridge not installed'],
                    'down' => ['bg-danger', 'Bridge offline'],
                    'up' => ['bg-fg-subtle', 'Bridge up — checking InDesign…'],
                    'noid' => ['bg-warn', 'InDesign not connected'],
                    'ok' => ['bg-ok', 'InDesign connected'],
                };
            @endphp

            <button wire:click="manualRefreshBridge" wire:poll.5s="pollBridge" title="{{ $bridge['detail'] ?? 'Checked every 5s — click to re-check now' }}"
                class="flex shrink-0 items-center gap-2.5 rounded-full border border-line bg-panel py-1.5 pr-3.5 pl-3 text-xs font-medium text-fg-muted shadow-[var(--elev-raised)] transition hover:border-line-strong hover:text-fg">
                {{-- fixed-size indicator: dot at rest, spinner while checking.
                     Debounced client-side — each visual state holds ≥1500ms.

                     Two hidden sentinels drive it, and the spinner shows while
                     either is visible. The manual one is always present, so a
                     click always spins. The poll one exists only while the
                     bridge is unhealthy — once it's green the 5s check runs
                     silently and the green dot stays put. --}}
                <span class="flex size-2 shrink-0 items-center justify-center" x-data="bridgeIndicator">
                    <span x-show="shown === 'dot'" class="inline-block size-2 rounded-full {{ $dot }}"></span>
                    <span x-show="shown === 'spin'" x-cloak class="inline-block size-2 animate-spin rounded-full border-[1.5px] border-fg-subtle border-t-transparent"></span>
                    <span data-bridge-sentinel wire:loading wire:target="manualRefreshBridge" style="display: none"></span>
                    @if ($bridgeState !== 'ok')
                        <span data-bridge-sentinel wire:key="bridge-poll-sentinel" wire:loading wire:target="pollBridge" style="display: none"></span>
                    @endif
                </span>
                {{ $bridgeLabel }}
            </button>

            @if (! $bridge['installed'])
                <flux:button size="sm" wire:click="installBridge">Install bridge</flux:button>
            @elseif (! $bridge['up'])
                <flux:button size="sm" wire:click="startBridge" wire:loading.attr="disabled" wire:target="startBridge">
                    <span wire:loading.remove wire:target="startBridge">Start bridge</span>
                    <span wire:loading wire:target="startBridge">Starting…</span>
                </flux:button>
            @elseif ($bridge['indesign'] === false)
                <flux:button size="sm" wire:click="openSetupWizard" title="Step-by-step InDesign plugin setup">Set up InDesign…</flux:button>
            @endif

            <flux:tooltip content="App logs">
                <button wire:click="openLogsModal" aria-haspopup="dialog" aria-label="View app logs" class="ghost-icon">
                    <flux:icon name="document-text" variant="micro" />
                </button>
            </flux:tooltip>

            <flux:tooltip content="Preferences (⌘,)">
                <button wire:click="openPreferences" aria-haspopup="dialog" aria-label="Preferences" class="ghost-icon">
                    <flux:icon name="cog-6-tooth" variant="micro" />
                </button>
            </flux:tooltip>

            <div x-data class="seg gap-0.5 p-1" role="group" aria-label="Appearance">
                <button type="button" title="Light appearance" aria-label="Light appearance"
                    x-on:click="$flux.appearance = 'light'" class="appearance-item"
                    x-bind:class="{ 'appearance-item-active': $flux.appearance === 'light' }">
                    <flux:icon name="sun" variant="micro" />
                </button>
                <button type="button" title="Dark appearance" aria-label="Dark appearance"
                    x-on:click="$flux.appearance = 'dark'" class="appearance-item"
                    x-bind:class="{ 'appearance-item-active': $flux.appearance === 'dark' }">
                    <flux:icon name="moon" variant="micro" />
                </button>
                <button type="button" title="Match system appearance" aria-label="Match system appearance"
                    x-on:click="$flux.appearance = 'system'" class="appearance-item"
                    x-bind:class="{ 'appearance-item-active': $flux.appearance === 'system' }">
                    <flux:icon name="computer-desktop" variant="micro" />
                </button>
            </div>
        </div>

        {{-- run progress rail — always present so it never shifts layout --}}
        <div class="h-0.5 w-full bg-transparent">
            @if ($runActive)
                <div class="h-full bg-gradient-to-r from-accent-from to-accent-to motion-safe:transition-[width] motion-safe:duration-500"
                     style="width: {{ $activeRun->progressPercent() }}%"></div>
            @endif
        </div>
    </header>

    @if ($flash)
        @php
            [$flashClasses, $flashIcon] = match ($flashLevel) {
                'success' => ['border-ok/40 bg-ok-bg text-ok-fg', 'check-circle'],
                'error' => ['border-danger-fg/40 bg-danger-bg text-danger-fg', 'x-circle'],
                default => ['border-warn-fg/35 bg-warn-bg text-warn-fg', 'exclamation-triangle'],
            };
        @endphp
        <div class="mx-auto mt-4 w-full max-w-[1220px] px-6">
            <div class="flex items-center justify-between gap-4 rounded-xl border px-4 py-3 text-sm {{ $flashClasses }}">
                <span class="flex min-w-0 items-center gap-2">
                    <flux:icon :name="$flashIcon" variant="mini" class="shrink-0" />
                    <span class="min-w-0">{{ $flash }}</span>
                </span>
                <button wire:click="$set('flash', null)" class="shrink-0 opacity-70 transition hover:opacity-100" aria-label="Dismiss">✕</button>
            </div>
        </div>
    @endif

    {{-- ═══ SETS TAB — the index ════════════════════════════════════════ --}}
    <div @class(['hidden' => $tab !== 'sets'])>
        <div class="mx-auto w-full max-w-[1220px] px-6 pt-5 pb-10">
            @if ($recentSets->isEmpty())
                <div class="panel flex flex-col items-center justify-center gap-3 px-6 py-24 text-center">
                    <div class="empty-tile"><flux:icon name="rectangle-stack" class="size-7" /></div>
                    <p class="text-[15px] font-semibold text-fg">No sets yet</p>
                    <p class="max-w-[24rem] text-[13px] leading-relaxed text-fg-muted">Open a set manifest once and it will live here for one-click access.</p>
                    <div class="mt-1 flex gap-2">
                        <flux:button wire:click="pick('manifestPath')">Open a set…</flux:button>
                        <flux:button variant="subtle" wire:click="startCreateSet">Create a set…</flux:button>
                        <flux:button variant="subtle" wire:click="startCreateFromSheet" title="Pick a merge sheet from your Google Drive">From Google Sheet…</flux:button>
                    </div>
                </div>
            @else
                <div class="grid grid-cols-2 gap-5 md:grid-cols-3 lg:grid-cols-4">
                    @foreach ($recentSets as $path => $entry)
                        <div class="group relative">
                            <button wire:click="openSet({{ json_encode($path) }})"
                                    class="panel block w-full overflow-hidden text-left transition hover:-translate-y-0.5 hover:shadow-[var(--elev-go)]
                                           {{ $manifestPath === $path ? 'ring-2 ring-accent' : '' }}">
                                <div class="aspect-[5/3] w-full overflow-hidden bg-surface-3">
                                    @if ($coverSrc($entry['cover'] ?? null))
                                        <img src="{{ $coverSrc($entry['cover']) }}"
                                             alt="" class="size-full object-cover transition duration-300 group-hover:scale-[1.03]" />
                                    @else
                                        <div class="flex size-full items-center justify-center bg-gradient-to-br from-accent-bg to-surface-3">
                                            <span class="text-2xl font-bold tracking-wider text-accent/70">
                                                {{ collect(preg_split('/\s+/', trim($entry['set'] ?? '?')) ?: [])->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(3)->implode('') ?: '?' }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                                <div class="p-3.5">
                                    <p class="truncate text-[13.5px] font-semibold text-fg">{{ $entry['set'] ?? basename(dirname($path)) }}</p>
                                    <p class="mt-0.5 text-[11.5px] text-fg-subtle tabular-nums">
                                        {{ $entry['cards'] ? $entry['cards'].' cards' : '—' }}
                                        · {{ ($entry['profiles'] ?? 0) }} {{ str('profile')->plural($entry['profiles'] ?? 0) }}
                                        · {{ \Illuminate\Support\Carbon::parse($entry['lastOpened'])->diffForHumans(short: true) }}
                                    </p>
                                </div>
                            </button>
                            <button wire:click="forgetSet({{ json_encode($path) }})"
                                    class="absolute top-2 right-2 rounded-full bg-surface/80 p-1.5 text-fg-subtle opacity-0 backdrop-blur transition group-hover:opacity-100 hover:text-danger-fg"
                                    title="Remove from this list (the set itself is untouched)">✕</button>
                        </div>
                    @endforeach

                    {{-- open / create --}}
                    <div class="flex min-h-[180px] flex-col gap-3">
                        <button wire:click="pick('manifestPath')"
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-line text-fg-subtle transition hover:border-line-strong hover:text-fg">
                            <flux:icon name="folder-open" class="size-5" />
                            <span class="text-[13px] font-medium">Open a set…</span>
                        </button>
                        <button wire:click="startCreateSet"
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-accent-line text-accent transition hover:border-accent hover:brightness-110">
                            <flux:icon name="plus" class="size-5" />
                            <span class="text-[13px] font-medium">Create a set…</span>
                        </button>
                        <button wire:click="startCreateFromSheet" title="Pick a merge sheet from your Google Drive"
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-line text-fg-subtle transition hover:border-line-strong hover:text-fg">
                            <flux:icon name="table-cells" class="size-5" />
                            <span class="text-[13px] font-medium">From Google Sheet…</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ CARDS TAB ═══════════════════════════════════════════════════ --}}
    <div @class(['hidden' => $tab !== 'cards'])>
        <div class="mx-auto w-full max-w-[1220px] px-6 pt-5 pb-10">

            {{-- ── (A) SET header — unboxed, in the negative space ────── --}}
            <div class="flex flex-wrap items-center gap-3 px-1 py-1">
                <button wire:click="$set('tab', 'sets')" title="Switch set" class="shrink-0 transition hover:opacity-80">
                    @if ($coverSrc($coverPath))
                        <img src="{{ $coverSrc($coverPath) }}"
                             alt="" class="size-10 rounded-xl border border-line object-cover" />
                    @elseif ($setInitials !== '')
                        <span class="flex size-10 items-center justify-center rounded-xl border border-accent-line bg-accent-bg text-[11px] font-bold tracking-wide text-accent">
                            {{ $setInitials }}
                        </span>
                    @else
                        <span class="flex size-10 items-center justify-center rounded-xl border border-accent-line bg-accent-bg text-accent">
                            <flux:icon name="bookmark" variant="mini" />
                        </span>
                    @endif
                </button>

                <div class="flex min-w-0 flex-1 items-baseline gap-2.5">
                    <h1 class="truncate text-[17px] font-semibold tracking-[-0.01em] text-fg" title="{{ $manifestPath }}">
                        {{ $setTitle ?: 'No set open' }}
                    </h1>
                    @if ($cards)
                        <span class="shrink-0 text-[13px] text-fg-subtle tabular-nums">{{ count($cards) }} cards</span>
                    @endif
                </div>

                @if ($cards || $parseError)
                    <button wire:click="$toggle('showHealth')" class="pill {{ $healthTone }}">{{ $healthCopy }}</button>
                @endif

                @if ($manifestPath)
                    <flux:select size="sm" wire:model.live="profile" class="w-40 shrink-0">
                        <flux:select.option value="">{{ $defaultProfileName }}</flux:select.option>
                        @foreach ($profileNames as $name)
                            <flux:select.option value="{{ $name }}">{{ $name }}</flux:select.option>
                        @endforeach
                        <flux:select.option value="__new__">＋ New profile…</flux:select.option>
                    </flux:select>
                @endif

                <div role="group" aria-label="Set configuration" class="flex shrink-0 items-center gap-0.5 border-l border-line pl-2">
                    @if ($manifestPath)
                        <flux:tooltip content="Reload foundry.json from disk">
                            <button wire:click="reloadManifest" aria-label="Reload the set file from disk" class="ghost-icon">
                                <flux:icon name="arrow-path" variant="micro" />
                            </button>
                        </flux:tooltip>
                    @endif
                    <flux:tooltip content="Resolved settings & pipeline">
                        <button wire:click="$set('showInfoModal', true)" aria-haspopup="dialog"
                                aria-label="Show resolved settings and pipeline" class="ghost-icon">
                            <x-icon-info class="size-4" />
                        </button>
                    </flux:tooltip>
                    <flux:tooltip content="Edit setup">
                        <button wire:click="$toggle('showAdvanced')" aria-expanded="{{ $showAdvanced ? 'true' : 'false' }}"
                                aria-label="Edit set setup" class="ghost-icon">
                            <flux:icon name="pencil" variant="solid" class="size-4" />
                        </button>
                    </flux:tooltip>
                </div>
            </div>

            {{-- ── (A expanded) manual setup — its own panel when open ── --}}
            <div @class(['hidden' => ! $showAdvanced, 'panel mt-3 p-4' => true])>
                    <div class="mb-3 flex items-center gap-3 {{ $manifestPath ? '' : 'border-b border-line pb-3' }}">
                        <span class="field-label">Set name</span>
                        <div class="w-56 shrink-0">
                            <flux:input size="sm" wire:model.blur="setName" placeholder="e.g. Trial by Combat" />
                        </div>
                        <span class="min-w-0 truncate text-[11px] text-fg-subtle">Shown in the header and on the Sets screen. Saved with the set.</span>
                    </div>
                    @if ($manifestPath)
                        {{-- The header's profile dropdown decides which profile
                             this editor is showing; here it's just renamable. --}}
                        <div class="mb-3 flex items-center gap-3 border-b border-line pb-3">
                            <span class="field-label">Profile name</span>
                            <div class="w-56 shrink-0">
                                <flux:input size="sm" wire:model.blur="editProfileName" title="Renamed when you Save" />
                            </div>
                            <span class="min-w-0 truncate text-[11px] text-fg-subtle">
                                {{ $profile === '' ? "This is the set's base config — other profiles inherit from it." : 'Overrides the '.$defaultProfileName.' profile.' }}
                            </span>
                            @if ($profile !== '')
                                {{-- deletable: only named profiles — the base config is the Default --}}
                                <span x-data="{ arming: false }" class="ml-auto flex shrink-0 items-center">
                                    <button x-show="! arming" x-on:click="arming = true; setTimeout(() => arming = false, 4000)"
                                            class="ghost-icon hover:!bg-danger-bg hover:!text-danger-fg" title="Delete this profile">
                                        <flux:icon name="trash" variant="micro" />
                                    </button>
                                    <flux:button x-show="arming" x-cloak size="xs" variant="danger" wire:click="deleteProfile">
                                        Delete “{{ $profile }}” — confirm?
                                    </flux:button>
                                </span>
                            @endif
                        </div>
                    @endif
                    <div class="max-h-[420px] space-y-1 overflow-y-auto pr-1">

                        @unless ($native)
                            <div class="row-compact">
                                <span class="field-label">Set manifest</span>
                                <flux:input size="sm" wire:model.blur="manifestPath" placeholder="/path/to/foundry.json" class="flex-1 font-mono" />
                            </div>
                        @endunless

                        @foreach ([
                            'mergePath' => ['Merge file', 'The .csv / .xlsx defining every card'],
                            'kitPath' => ['Build kit', 'Folder containing wotfLib.jsxinc'],
                            'templatePath' => ['Template', 'Defaults to the kit\'s master template'],
                        ] as $field => [$label, $hint])
                            @php
                                $value = $this->{$field};
                                $inheritedValue = (string) ($inherited[$field] ?? '');
                                $isInherit = $manifestPath !== '' && $profile !== '' && $inheritedValue !== '' && $value === $inheritedValue;
                                $isOverride = $manifestPath !== '' && $profile !== '' && $inheritedValue !== '' && $value !== $inheritedValue;
                                $portable = $value !== '' ? $this->portableDisplay($value) : null;
                            @endphp
                            <div class="row-compact group !h-auto py-2"
                                 x-data="{ over: false }"
                                 x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                                 x-on:drop.prevent="over = false; const p = droppedPaths($event)[0]; if (p) $wire.setPathFromDrop('{{ $field }}', p)"
                                 x-bind:class="over ? 'ring-2 ring-accent ring-inset' : ''">
                                <span class="field-label">{{ $label }}</span>
                                <span class="min-w-0 flex-1">
                                    @if ($native)
                                        <span class="flex min-w-0 items-center gap-1.5 {{ $isInherit ? 'opacity-65' : '' }}">
                                            @if ($value && \App\Merge\GoogleSheetFetcher::isUrl($value))
                                                <span class="truncate font-mono text-[11.5px] text-fg" title="{{ $value }}">{{ $value }}</span>
                                                <span class="shrink-0 rounded bg-accent-bg px-1.5 py-0.5 text-[10px] font-semibold text-accent"
                                                      title="Linked Google Sheet — downloaded into the set's .foundry/cache">Sheet</span>
                                            @elseif ($value)
                                                <x-path :path="$value" :display="$portable" />
                                                <button wire:click="openInFinder({{ json_encode($value) }})" class="ghost-icon shrink-0" title="Show in Finder">
                                                    <flux:icon name="folder" variant="micro" />
                                                </button>
                                                @if ($manifestPath !== '')
                                                    <span class="inline-block size-2 shrink-0 rounded-full {{ $portable !== null ? 'bg-ok' : 'bg-warn' }}"
                                                          title="{{ $portable !== null ? 'Portable — stored relative to the set; works on every machine syncing this folder' : 'This machine only — outside the set\'s folder tree' }}"></span>
                                                @endif
                                            @else
                                                <span class="text-[11.5px] text-fg-subtle italic" title="{{ $hint }}">Not set</span>
                                            @endif
                                        </span>
                                    @else
                                        <flux:input size="sm" wire:model.blur="{{ $field }}" placeholder="{{ $hint }}" class="font-mono {{ $isInherit ? 'opacity-65' : '' }}" />
                                    @endif
                                    @if ($isInherit)
                                        <span class="mt-0.5 block text-[10.5px] text-fg-subtle">Inherits from base — change to override for this profile</span>
                                    @elseif ($isOverride)
                                        <span class="mt-0.5 block text-[10.5px] text-accent">Overrides base <span class="text-fg-subtle">({{ basename($inheritedValue) }})</span> — clear ✕ to inherit again</span>
                                    @endif
                                </span>
                                <span class="flex shrink-0 items-center gap-1 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                    @if ($field === 'mergePath' && \App\Merge\GoogleSheetFetcher::isSheetUrl($value))
                                        <flux:button size="xs" variant="subtle" wire:click="refreshMerge" title="Re-download the linked sheet">
                                            <flux:icon name="arrow-path" variant="micro" />
                                        </flux:button>
                                    @endif
                                    @if ($native)
                                        <flux:button size="xs" variant="subtle" wire:click="pick('{{ $field }}')">Choose…</flux:button>
                                    @endif
                                    @if ($value)
                                        <flux:button size="xs" variant="subtle" wire:click="clearPath('{{ $field }}')" title="{{ $isOverride ? 'Inherit from base again' : 'Clear' }}">✕</flux:button>
                                    @endif
                                </span>
                            </div>
                        @endforeach

                        <div class="row-compact !h-auto !items-start py-2.5"
                             x-data="{ over: false }"
                             x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                             x-on:drop.prevent="over = false; droppedPaths($event).forEach((p) => $wire.setPathFromDrop('artDirs', p))"
                             x-bind:class="over ? 'ring-2 ring-accent ring-inset' : ''">
                            <span class="field-label pt-1">Art folders</span>
                            <ul class="min-w-0 flex-1 space-y-1">
                                @foreach ($artDirs as $index => $dir)
                                    @php $dirPortable = $this->portableDisplay($dir); @endphp
                                    <li class="group/dir flex items-center gap-2">
                                        <span class="w-3 shrink-0 text-center font-mono text-[10px] text-fg-subtle">{{ $index + 1 }}</span>
                                        <span class="flex min-w-0 flex-1 items-center gap-1.5">
                                            <x-path :path="$dir" :display="$dirPortable" class="{{ is_dir($dir) ? '' : '!border-danger-fg/40 !bg-danger-bg' }}" />
                                            <button wire:click="openInFinder({{ json_encode($dir) }})" class="ghost-icon shrink-0" title="Show in Finder">
                                                <flux:icon name="folder" variant="micro" />
                                            </button>
                                            @if ($manifestPath !== '')
                                                <span class="inline-block size-2 shrink-0 rounded-full {{ $dirPortable !== null ? 'bg-ok' : 'bg-warn' }}"
                                                      title="{{ $dirPortable !== null ? 'Portable — stored relative to the set; works on every machine syncing this folder' : 'This machine only — outside the set\'s folder tree' }}"></span>
                                            @endif
                                        </span>
                                        <span class="flex shrink-0 gap-0.5 opacity-0 transition group-hover/dir:opacity-100">
                                            <button wire:click="moveArtDir({{ $index }}, -1)" @disabled($index === 0) class="icon-btn" title="Search earlier"><flux:icon name="chevron-up" variant="micro" /></button>
                                            <button wire:click="moveArtDir({{ $index }}, 1)" @disabled($index === count($artDirs) - 1) class="icon-btn" title="Search later"><flux:icon name="chevron-down" variant="micro" /></button>
                                            <button wire:click="removeArtDir({{ $index }})" class="icon-btn hover:!text-danger-fg" title="Remove">✕</button>
                                        </span>
                                    </li>
                                @endforeach
                                @unless ($native)
                                    <li class="flex items-center gap-2">
                                        <span class="w-3"></span>
                                        <flux:input size="sm" wire:model="newArtDir" wire:keydown.enter="addArtDir" placeholder="/path/to/art-folder" class="flex-1 font-mono" />
                                        <flux:button size="xs" variant="subtle" wire:click="addArtDir">Add</flux:button>
                                    </li>
                                @endunless
                                <li class="pt-0.5 text-[11px] text-fg-subtle">Searched top to bottom — first match wins.</li>
                            </ul>
                            @if ($native)
                                <flux:button size="xs" variant="subtle" wire:click="pick('artDirs')">Add…</flux:button>
                            @endif
                        </div>

                        {{-- white-ink layer art, hunted by card ref like art --}}
                        <div class="row-compact !h-auto !items-start py-2.5"
                             x-data="{ over: false }"
                             x-on:dragover.prevent="over = true" x-on:dragleave="over = false"
                             x-on:drop.prevent="over = false; droppedPaths($event).forEach((p) => $wire.setPathFromDrop('whiteDirs', p))"
                             x-bind:class="over ? 'ring-2 ring-accent ring-inset' : ''">
                            <span class="field-label pt-1">White layers</span>
                            <ul class="min-w-0 flex-1 space-y-1">
                                @foreach ($whiteDirs as $index => $dir)
                                    @php $dirPortable = $this->portableDisplay($dir); @endphp
                                    <li class="group/dir flex items-center gap-2">
                                        <span class="w-3 shrink-0 text-center font-mono text-[10px] text-fg-subtle">{{ $index + 1 }}</span>
                                        <span class="flex min-w-0 flex-1 items-center gap-1.5">
                                            <x-path :path="$dir" :display="$dirPortable" class="{{ is_dir($dir) ? '' : '!border-danger-fg/40 !bg-danger-bg' }}" />
                                            <button wire:click="openInFinder({{ json_encode($dir) }})" class="ghost-icon shrink-0" title="Show in Finder">
                                                <flux:icon name="folder" variant="micro" />
                                            </button>
                                            @if ($manifestPath !== '')
                                                <span class="inline-block size-2 shrink-0 rounded-full {{ $dirPortable !== null ? 'bg-ok' : 'bg-warn' }}"
                                                      title="{{ $dirPortable !== null ? 'Portable — stored relative to the set; works on every machine syncing this folder' : 'This machine only — outside the set\'s folder tree' }}"></span>
                                            @endif
                                        </span>
                                        <span class="flex shrink-0 gap-0.5 opacity-0 transition group-hover/dir:opacity-100">
                                            <button wire:click="moveWhiteDir({{ $index }}, -1)" @disabled($index === 0) class="icon-btn" title="Search earlier"><flux:icon name="chevron-up" variant="micro" /></button>
                                            <button wire:click="moveWhiteDir({{ $index }}, 1)" @disabled($index === count($whiteDirs) - 1) class="icon-btn" title="Search later"><flux:icon name="chevron-down" variant="micro" /></button>
                                            <button wire:click="removeWhiteDir({{ $index }})" class="icon-btn hover:!text-danger-fg" title="Remove">✕</button>
                                        </span>
                                    </li>
                                @endforeach
                                @unless ($native)
                                    <li class="flex items-center gap-2">
                                        <span class="w-3"></span>
                                        <flux:input size="sm" wire:model="newWhiteDir" wire:keydown.enter="addWhiteDir" placeholder="/path/to/white-folder" class="flex-1 font-mono" />
                                        <flux:button size="xs" variant="subtle" wire:click="addWhiteDir">Add</flux:button>
                                    </li>
                                @endunless
                                <li class="pt-0.5 text-[11px] text-fg-subtle">Optional — per-card white-ink plates, hunted by card ref like artwork.</li>
                            </ul>
                            @if ($native)
                                <flux:button size="xs" variant="subtle" wire:click="pick('whiteDirs')">Add…</flux:button>
                            @endif
                        </div>

                        {{-- cover image for the set index — per-set, embedded on save --}}
                        <div class="row-compact group !h-auto !items-start py-2.5">
                            <span class="field-label pt-1">Cover image</span>
                            <div class="min-w-0 flex-1">
                                <button type="button" wire:click="$set('showCoverEditor', true)"
                                        x-data x-on:dragover.prevent x-on:drop.prevent="
                                            const f = $event.dataTransfer.files[0];
                                            if (f && f.type.startsWith('image/')) {
                                                window.__coverDroppedFile = f;
                                                $wire.set('showCoverEditor', true);
                                            }"
                                        class="group/cover relative block size-16 overflow-hidden rounded-xl border border-dashed border-line-strong transition hover:border-accent"
                                        title="{{ $coverPath ? 'Click to re-crop or replace — or drop a new image here' : 'Click to choose a cover — or drop an image here' }}">
                                    @if ($coverSrc($coverPath))
                                        <img src="{{ $coverSrc($coverPath) }}" alt="" class="size-full object-cover" />
                                        <span class="absolute inset-0 flex items-center justify-center bg-surface/60 opacity-0 backdrop-blur-[1px] transition group-hover/cover:opacity-100">
                                            <flux:icon name="pencil" variant="mini" class="text-fg" />
                                        </span>
                                    @else
                                        <span class="flex size-full items-center justify-center text-fg-subtle">
                                            <flux:icon name="photo" variant="mini" />
                                        </span>
                                    @endif
                                </button>
                                <p class="mt-1 text-[10.5px] text-fg-subtle">
                                    Shown on the Sets screen. Applies to the whole set.
                                    @if ($manifestPath !== '' && $coverPath !== (string) ($inherited['coverPath'] ?? ''))
                                        <span class="text-accent">Unsaved — applied when you Save.</span>
                                    @endif
                                </p>
                            </div>
                            <span class="flex shrink-0 items-center gap-1 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                @if ($coverPath)
                                    <flux:button size="xs" variant="subtle" wire:click="$set('coverPath', '')" title="Remove cover">✕</flux:button>
                                @endif
                            </span>
                        </div>

                    </div>

                    {{-- manifest footer strip --}}
                    <div class="mt-3 flex items-center gap-1.5 border-t border-line pt-3">
                        <button x-data
                            x-on:click="navigator.clipboard?.writeText($el.dataset.cmd)"
                            data-cmd="{{ $this->cliCommand() }}"
                            class="icon-btn text-[11px]" title="Copy the CLI command for these settings">Copy CLI</button>
                        @if ($manifestPath)
                            <flux:button size="xs" variant="subtle" wire:click="applyManifest" title="Re-apply the manifest, discarding manual edits">Discard edits</flux:button>
                        @endif

                        @if ($manifestPath)
                            {{-- the loaded set file: click the token to copy, folder to reveal --}}
                            <div class="flex min-w-0 flex-1 items-center justify-center gap-1 px-3">
                                <x-path :path="$manifestPath" class="!max-w-[26rem]" />
                                <button wire:click="openInFinder({{ json_encode($manifestPath) }})" class="ghost-icon shrink-0" title="Show foundry.json in Finder">
                                    <flux:icon name="folder" variant="micro" />
                                </button>
                            </div>
                        @else
                            <div class="flex-1"></div>
                        @endif

                        <flux:button size="sm" variant="subtle" wire:click="cancelEdit" title="Close without saving{{ $manifestPath ? ' — unsaved edits are discarded' : '' }}">Cancel</flux:button>
                        <flux:button size="sm" variant="subtle" wire:click="saveManifestAs" title="Choose where to save a new foundry.json">Save as New Set…</flux:button>
                        @if ($manifestPath)
                            <flux:button size="sm" variant="subtle" wire:click="openProfileModal" title="Save the current settings as a new profile on this set">Save as Profile…</flux:button>
                            <flux:button size="sm" variant="filled" wire:click="saveToManifest" title="Save into {{ $profile !== '' ? 'profile &quot;'.$profile.'&quot;' : 'the base config' }}">Save</flux:button>
                        @endif
                    </div>
            </div>

            {{-- ── (B) grid ───────────────────────────────────────────── --}}
            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_336px]">
                <div class="min-w-0 space-y-4">

                    {{-- (C) set health strip --}}
                    <div @class(['hidden' => ! $showHealth || (! $issues && ! $fileLevelIssues), 'panel p-4' => true])>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="panel-title">Set health</h2>
                            @if ($summary)
                                <span class="text-[11.5px] text-fg-subtle tabular-nums">{{ $summary['cards'] }} cards · {{ $summary['columns'] }} columns</span>
                            @endif
                        </div>

                        @foreach ($fileLevelIssues as $issue)
                            <p class="mb-2 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-fg">{{ $issue['message'] }}</p>
                        @endforeach

                        <ul class="space-y-1">
                            @foreach ($issueGroups as $rule => $group)
                                <li>
                                    <button wire:click="filterByRule('{{ $rule }}')"
                                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition hover:bg-surface-2
                                                   {{ $flagFilter === $rule ? 'bg-surface-2 ring-1 ring-line-strong' : '' }}">
                                        <span class="{{ $group['severity'] === 'error' ? 'text-danger-fg' : 'text-warn-fg' }}">
                                            <flux:icon name="{{ $group['severity'] === 'error' ? 'x-circle' : 'exclamation-triangle' }}" variant="micro" />
                                        </span>
                                        <span class="flex-1 text-[13px] text-fg">{{ $group['summary'] }}</span>
                                        <span class="text-[11.5px] text-fg-subtle tabular-nums">{{ $group['count'] }}</span>
                                        <flux:icon name="arrow-right" variant="micro" class="text-fg-subtle" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- (D) card list. Selection runs client-side in Alpine so
                         every click lands instantly; the entangled properties
                         sync back to the server in the background. --}}
                    <div class="panel flex min-h-[520px] flex-col overflow-hidden"
                         x-data="cardSelection({ selected: $wire.entangle('selected'), inverted: $wire.entangle('selectionInverted') })">
                        @if (! $manifestPath && ! $mergePath)
                            {{-- first run --}}
                            <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-20 text-center">
                                <div class="empty-tile"><flux:icon name="folder-plus" class="size-7" /></div>
                                <p class="text-[15px] font-semibold text-fg">Open a card set</p>
                                <p class="max-w-[22rem] text-[13px] leading-relaxed text-fg-muted">A set file tells Card Foundry where your cards, artwork and template live.</p>
                                <flux:button class="mt-1" wire:click="pick('manifestPath')">Choose a set…</flux:button>
                                <button wire:click="$toggle('showAdvanced')" class="text-[11.5px] text-fg-subtle transition hover:text-fg">
                                    No set file yet? <span class="underline">Set it up manually</span>
                                </button>
                            </div>
                        @elseif ($parseError)
                            <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-20 text-center">
                                <div class="empty-tile !border-danger-fg/30 !bg-danger-bg !text-danger-fg"><flux:icon name="exclamation-triangle" class="size-7" /></div>
                                <p class="text-[15px] font-semibold text-fg">Can't read this file</p>
                                <p class="max-w-[26rem] text-[13px] leading-relaxed text-fg-muted">{{ $parseError }}</p>
                                <flux:button class="mt-1" wire:click="pick('manifestPath')">Choose a different set…</flux:button>
                            </div>
                        @elseif (! $cards)
                            <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-20 text-center">
                                <div class="empty-tile"><flux:icon name="arrow-path" class="size-7" /></div>
                                <p class="text-[15px] font-semibold text-fg">Reading the merge file…</p>
                            </div>
                        @else
                            {{-- list header --}}
                            <div class="flex items-center gap-3 border-b border-line px-4 py-3">
                                <div class="min-w-0 flex-1">
                                    <flux:input size="sm" icon="magnifying-glass" clearable
                                                placeholder="Search {{ count($cards) }} cards…"
                                                wire:model.live.debounce.300ms="search" />
                                </div>
                                @if ($issues)
                                    <button wire:click="filterByRule('any')"
                                            class="pill shrink-0 {{ $flagFilter ? '!border-accent !bg-accent !text-panel' : '' }}">
                                        <flux:icon name="funnel" variant="micro" />
                                        {{ $flagFilter ? 'Flagged only · on ✕' : 'Flagged only' }}
                                    </button>
                                @endif
                            </div>

                            {{-- selection bar --}}
                            @php
                                $filterActive = $search !== '' || $flagFilter !== '';
                                $buildBlocked = $bridge['indesign'] !== true || ($errorCount > 0 && ! $overrideLint);
                                // The header checkbox acts on what's on screen: the filtered
                                // set while a filter is on, every card otherwise.
                                $actingTotal = $filterActive ? $filteredTotal : count($cards);
                                $actingChosen = count(array_intersect($filterActive ? $filteredRefs : array_column($cards, 'ref'), $selected));
                                $chosenElsewhere = count($selected) - $actingChosen;
                            @endphp
                            <div class="flex h-10 items-center gap-3 border-b border-line bg-surface-2 px-4 text-xs">
                                @if ($cards !== [])
                                    {{-- Re-keyed whenever the filtered set changes, so the fresh
                                         element's x-init hands the new acting refs to Alpine. --}}
                                    <span hidden wire:key="acting-{{ md5(json_encode($filteredRefs)) }}"
                                          data-acting="{{ json_encode($filteredRefs) }}" x-init="refreshActing($el)"></span>
                                    <button x-on:click="cycle()"
                                            :title="checkboxLabel" :aria-label="checkboxLabel"
                                            class="flex size-4 shrink-0 items-center justify-center rounded border transition"
                                            :class="state === 'none' ? 'border-line-strong' : 'border-accent bg-accent text-panel'">
                                        <svg x-show="state === 'all'" x-cloak class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.5L5 9l4.5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <svg x-show="state === 'partial'" x-cloak class="size-3" viewBox="0 0 12 12" fill="none"><path d="M3 6h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    </button>
                                @endif
                                @if ($selected === [])
                                    @if ($filterActive)
                                        <span class="font-medium text-fg tabular-nums">Showing {{ $filteredTotal }} of {{ count($cards) }} cards</span>
                                        <span class="text-fg-subtle">— {{ $flagFilter !== '' ? 'flagged only' : 'matching your search' }}</span>
                                    @elseif ($buildBlocked)
                                        <span class="font-medium text-fg-muted tabular-nums">Showing {{ $filteredTotal }} of {{ count($cards) }} cards</span>
                                        <span class="text-fg-subtle">— {{ $bridge['indesign'] !== true ? 'connect InDesign to build' : 'fix the errors (or tick "build anyway") to build' }}</span>
                                    @else
                                        <span class="font-medium text-fg-muted">Building all {{ count($cards) }} cards</span>
                                        <span class="text-fg-subtle">— tick any card to narrow it down</span>
                                    @endif
                                @else
                                    <span class="font-medium text-fg tabular-nums">{{ $actingChosen }} of {{ $actingTotal }}{{ $filterActive ? ' matching' : '' }} selected</span>
                                    @if ($chosenElsewhere > 0)
                                        <span class="text-fg-subtle tabular-nums">— {{ $chosenElsewhere }} more selected elsewhere</span>
                                    @endif
                                    <div class="flex-1"></div>
                                    <flux:button size="xs" variant="subtle" x-on:click="clearAll()">Clear</flux:button>
                                @endif
                            </div>

                            {{-- rows --}}
                            <div class="min-h-0 flex-1 overflow-y-auto">
                                @if ($filteredTotal === 0)
                                    <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                                        @if ($flagFilter !== '')
                                            <div class="empty-tile !border-ok/30 !bg-ok-bg !text-ok-fg"><flux:icon name="check-circle" class="size-7" /></div>
                                            <p class="text-[15px] font-semibold text-fg">Nothing flagged here</p>
                                            <flux:button size="sm" variant="subtle" wire:click="filterByRule('{{ $flagFilter }}')">Show all cards</flux:button>
                                        @else
                                            <div class="empty-tile"><flux:icon name="magnifying-glass" class="size-7" /></div>
                                            <p class="text-[15px] font-semibold text-fg">No cards match “{{ $search }}”</p>
                                            <flux:button size="sm" variant="subtle" wire:click="$set('search', '')">Clear search</flux:button>
                                        @endif
                                    </div>
                                @else
                                    <ul class="divide-y divide-line">
                                        @foreach ($filteredCards as $card)
                                            <li wire:key="card-{{ $card['ref'] }}">
                                                <button x-on:click="toggle('{{ $card['ref'] }}')"
                                                        class="flex w-full items-center gap-3 border-l-2 py-2 pr-4 pl-3.5 text-left transition hover:bg-surface-2"
                                                        :class="chosen('{{ $card['ref'] }}') ? 'border-l-accent bg-accent-bg' : 'border-l-transparent'">
                                                    <span class="flex size-4 shrink-0 items-center justify-center rounded border transition"
                                                          :class="chosen('{{ $card['ref'] }}') ? 'border-accent bg-accent text-panel' : 'border-line-strong'">
                                                        <svg x-show="chosen('{{ $card['ref'] }}')" x-cloak class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.5L5 9l4.5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block truncate text-[13.5px] font-medium text-fg">{{ $card['name'] }}</span>
                                                        <span class="block text-[11.5px] text-fg-subtle">{{ $card['type'] }}{{ $card['type'] && $card['code'] ? ' · ' : '' }}{{ $card['code'] }}</span>
                                                    </span>
                                                    @unless ($card['hasArt'])
                                                        <span role="button" tabindex="0" wire:click.stop="openCardDetail('{{ $card['ref'] }}')"
                                                              wire:keydown.enter.stop="openCardDetail('{{ $card['ref'] }}')"
                                                              title="See what was searched, and where"
                                                              class="pill pill-warn shrink-0 cursor-pointer">artwork not found</span>
                                                    @endunless
                                                    <span class="shrink-0 font-mono text-[11px] text-fg-subtle">{{ $card['ref'] }}</span>
                                                    @if ($card['hasArt'])
                                                        @if ($card['artFallback'] ?? false)
                                                            <span role="button" tabindex="0" wire:click.stop="openArtPreview('{{ $card['ref'] }}')"
                                                                  wire:keydown.enter.stop="openArtPreview('{{ $card['ref'] }}')"
                                                                  class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-warn-fg opacity-60 transition hover:bg-warn-bg hover:opacity-100"
                                                                  title="Artwork found in a different format than the merge names — preview it">
                                                                <flux:icon name="exclamation-triangle" variant="micro" />
                                                            </span>
                                                        @else
                                                            <span role="button" tabindex="0" wire:click.stop="openArtPreview('{{ $card['ref'] }}')"
                                                                  wire:keydown.enter.stop="openArtPreview('{{ $card['ref'] }}')"
                                                                  class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-fg-subtle opacity-35 transition hover:bg-accent hover:text-panel hover:opacity-100"
                                                                  title="Preview the matched artwork">
                                                                <flux:icon name="eye" variant="micro" />
                                                            </span>
                                                        @endif
                                                    @else
                                                        <span role="button" tabindex="0" wire:click.stop="openCardDetail('{{ $card['ref'] }}')"
                                                              wire:keydown.enter.stop="openCardDetail('{{ $card['ref'] }}')"
                                                              class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-danger-fg opacity-45 transition hover:bg-danger-bg hover:opacity-100"
                                                              title="Artwork not found — see what was searched, and where">
                                                            <flux:icon name="question-mark-circle" variant="micro" />
                                                        </span>
                                                    @endif
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($filteredTotal > count($filteredCards))
                                        {{-- infinite scroll: loads the next 100 as the sentinel nears view --}}
                                        <div wire:key="load-more-{{ count($filteredCards) }}" x-data
                                             x-init="new IntersectionObserver(
                                                    (entries) => entries.forEach((e) => e.isIntersecting && $wire.loadMore()),
                                                    { root: $el.closest('.overflow-y-auto'), rootMargin: '300px' }
                                                ).observe($el)"
                                             class="flex items-center justify-center gap-2 py-3 text-xs text-fg-subtle">
                                            <span class="size-1.5 animate-pulse rounded-full bg-accent"></span>
                                            Loading more… <span class="tabular-nums">{{ count($filteredCards) }} of {{ $filteredTotal }}</span>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- (E) export dock --}}
                <aside class="lg:sticky lg:top-[4.5rem] lg:self-start">
                    <div class="panel flex flex-col gap-4 p-4 {{ $cards ? '' : 'pointer-events-none opacity-60' }}">
                        <h2 class="panel-title">Export</h2>

                        <div>
                            <span class="field-label mb-1.5 block !w-auto">Format</span>
                            <div class="flex items-center gap-2">
                                <div class="seg flex-1">
                                    @foreach (['pdf' => 'PDF', 'png' => 'PNG'] as $value => $label)
                                        <button wire:click="$set('format','{{ $value }}')"
                                                class="seg-item flex-1 text-center {{ $format === $value ? 'seg-item-active' : '' }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                                <div class="w-[72px] shrink-0">
                                    <flux:input size="sm" type="number" wire:model.live.debounce.500ms="ppi"
                                                :disabled="$format !== 'png'" placeholder="PPI" title="PNG resolution (ppi)" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <span class="field-label mb-1.5 block !w-auto">Treatments <span class="font-normal text-fg-subtle">— each gets its own folder</span></span>
                            <div class="grid grid-cols-2 gap-1">
                                @foreach ($treatmentOptions as $option)
                                    @php $on = in_array($option, $treatments, true); @endphp
                                    <button wire:click="toggleTreatment('{{ $option }}')"
                                            class="flex items-center gap-2 rounded-lg border px-2 py-1.5 text-left text-[11.5px] font-medium transition
                                                   {{ $on ? 'border-accent-line bg-accent-bg text-accent' : 'border-line text-fg-muted hover:border-line-strong hover:text-fg' }}">
                                        <span class="flex size-3.5 shrink-0 items-center justify-center rounded border {{ $on ? 'border-accent bg-accent text-panel' : 'border-line-strong' }}">
                                            @if ($on)<svg class="size-2.5" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.5L5 9l4.5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif
                                        </span>
                                        <span class="truncate">{{ $option }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg bg-surface-2 p-2.5">
                            <flux:checkbox wire:model.live="proof" class="mt-0.5" />
                            <span class="min-w-0">
                                <span class="block text-xs font-medium text-fg">Proof copy</span>
                                <span class="block text-[11px] leading-snug text-fg-subtle">Hides the white ink plate. Turn off for production print.</span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg bg-surface-2 p-2.5">
                            <flux:checkbox wire:model.live="serialize" class="mt-0.5" />
                            <span class="min-w-0">
                                <span class="block text-xs font-medium text-fg">Serialized copies</span>
                                <span class="block text-[11px] leading-snug text-fg-subtle">One export per numbered copy — edition sizes come from the merge file.</span>
                            </span>
                        </label>

                        @if ($format === 'pdf')
                            <div>
                                <span class="field-label mb-1.5 block !w-auto">PDF options</span>
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1.5 rounded-lg bg-surface-2 p-2.5">
                                    @foreach ([
                                        'cropMarks' => ['Cut lines', 'Crop marks at the trim'],
                                        'registrationMarks' => ['Registration', 'Registration marks'],
                                        'bleed' => ['Bleed', "The document's bleed area"],
                                        'combine' => ['Single PDF', 'Also combine every card into one document per treatment'],
                                    ] as $flag => [$label, $hint])
                                        <label class="flex cursor-pointer items-center gap-2" title="{{ $hint }}">
                                            <flux:checkbox wire:model.live="{{ $flag }}" />
                                            <span class="text-[11.5px] font-medium text-fg">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="border-t border-line"></div>

                        <div class="row-compact !h-9 !px-0">
                            <span class="field-label !w-16">Save to</span>
                            @if ($native)
                                <button wire:click="pick('destDir')"
                                        class="flex min-w-0 flex-1 rounded-md text-left transition hover:brightness-110"
                                        title="{{ $destDir ?: 'Choose an output folder' }}">
                                    @if ($destDir)
                                        <x-path :path="$destDir" />
                                    @else
                                        <span class="text-[11.5px] text-fg-subtle italic">Choose folder…</span>
                                    @endif
                                </button>
                            @else
                                <flux:input size="sm" wire:model.blur="destDir" placeholder="/path/to/output" class="flex-1 font-mono" />
                            @endif
                        </div>

                        <div class="border-t border-line"></div>

                        @if ($runActive)
                            <div class="rounded-xl border border-accent-line bg-accent-bg p-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold text-accent">Run #{{ $activeRun->id }}</span>
                                    <span class="text-xs text-accent tabular-nums">{{ $activeRun->done_count + $activeRun->failed_count }}/{{ $activeRun->total }}</span>
                                </div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-3">
                                    <div class="h-full rounded-full bg-gradient-to-r from-accent-from to-accent-to motion-safe:transition-[width] motion-safe:duration-500"
                                         style="width: {{ $activeRun->progressPercent() }}%"></div>
                                </div>
                                <p class="mt-2 truncate text-[11.5px] text-fg-muted">{{ $currentItemName ?? 'Waiting for InDesign…' }}</p>
                                @if ($activeRun->failed_count)
                                    <p class="mt-1 text-[11.5px] text-danger-fg tabular-nums">{{ $activeRun->failed_count }} failed</p>
                                @endif
                                <flux:button size="xs" variant="subtle" class="mt-2 w-full" wire:click="$set('tab','activity')">Watch log</flux:button>
                            </div>
                        @else
                            {{-- scope shown only when it's NOT the implicit everything --}}
                            @if ($selected !== [])
                                <div>
                                    <p class="text-[13px] font-medium text-fg tabular-nums">
                                        {{ count($selected) === count($cards)
                                            ? 'All '.count($cards).' cards selected'
                                            : count($selected).' '.str('card')->plural(count($selected)).' selected' }}
                                    </p>
                                    <ul class="mt-1.5 space-y-0.5">
                                        @foreach (array_slice($selected, 0, 4) as $ref)
                                            <li class="truncate text-[11.5px] text-fg-subtle">{{ $cardNames[$ref] ?? $ref }}</li>
                                        @endforeach
                                        @if (count($selected) > 4)
                                            <li class="text-[11.5px] text-fg-subtle">+ {{ count($selected) - 4 }} more</li>
                                        @endif
                                    </ul>
                                </div>
                            @endif

                            @if ($bridgeState !== 'ok')
                                <div class="flex items-center justify-between gap-2 rounded-lg bg-warn-bg px-2.5 py-2">
                                    <span class="text-[11.5px] font-medium text-warn-fg">
                                        {{ $bridgeState === 'up' ? 'Checking InDesign…' : 'InDesign not connected' }}
                                    </span>
                                    <flux:button size="xs" variant="subtle" wire:click="openSetupWizard">Fix…</flux:button>
                                </div>
                            @endif

                            @if ($this->hasLintErrors())
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-danger-fg/30 bg-danger-bg p-2.5">
                                    <flux:checkbox wire:model.live="overrideLint" class="mt-0.5" />
                                    <span class="text-[11.5px] leading-snug text-danger-fg">This set has errors. Build anyway.</span>
                                </label>
                            @endif

                            <div x-data="{ confirming: false }">
                                <button
                                    x-on:click="
                                        if ({{ $buildCount }} > 50 && ! confirming) {
                                            confirming = true; setTimeout(() => confirming = false, 4000);
                                        } else { confirming = false; $wire.go(); }
                                    "
                                    wire:loading.attr="disabled" wire:target="go"
                                    @disabled(! $cards || $buildCount === 0 || $bridge['indesign'] !== true || ($format === 'png' && ! $ppi))
                                    title="{{ $bridge['indesign'] !== true ? 'Building needs a live InDesign connection' : ($format === 'png' && ! $ppi ? 'Set a PNG resolution (PPI) first' : '') }}"
                                    class="btn-go w-full justify-center">
                                    <flux:icon name="sparkles" variant="mini" />
                                    <span wire:loading.remove wire:target="go">
                                        <span x-show="! confirming">Build {{ $buildCount }} {{ str('card')->plural($buildCount) }}</span>
                                        <span x-show="confirming" x-cloak>Build all {{ $buildCount }} — confirm?</span>
                                    </span>
                                    <span wire:loading wire:target="go">Starting…</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </aside>
            </div>
        </div>
    </div>

    {{-- ═══ ACTIVITY TAB ════════════════════════════════════════════════ --}}
    <div @class(['hidden' => $tab !== 'activity'])>
        <div class="mx-auto w-full max-w-[1220px] space-y-5 px-6 pt-5 pb-10">

            @if ($activeRun)
                <div class="panel p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="panel-title">
                            Run #{{ $activeRun->id }}
                            @if ($activeRun->set_name)<span class="text-fg-muted normal-case">· {{ $activeRun->set_name }}</span>@endif
                            @if ($activeRun->profile)<span class="text-fg-subtle normal-case">({{ $activeRun->profile }})</span>@endif
                            <span class="ml-2 normal-case
                                {{ $activeRun->status === 'completed' ? 'text-ok-fg' : '' }}
                                {{ $activeRun->status === 'failed' ? 'text-danger-fg' : '' }}
                                {{ in_array($activeRun->status, ['pending', 'running']) ? 'text-accent' : '' }}">
                                {{ ucfirst($activeRun->status) }}
                            </span>
                        </h2>
                        <div class="flex items-center gap-3 text-xs font-medium text-fg-muted">
                            <span>{{ $activeRun->treatment }} · {{ strtoupper($activeRun->format ?? 'pdf') }}{{ $activeRun->proof ? ' · NO-WHITE' : '' }}</span>
                            <span class="tabular-nums">{{ $activeRun->done_count }}/{{ $activeRun->total }} done</span>
                            @if ($activeRun->failed_count) <span class="text-danger-fg tabular-nums">{{ $activeRun->failed_count }} failed</span> @endif
                            @if ($activeRun->isFinished())
                                <flux:button size="xs" variant="subtle" wire:click="dismissRun">Dismiss</flux:button>
                            @endif
                        </div>
                    </div>

                    <div class="mb-4 h-1.5 overflow-hidden rounded-full bg-surface-3">
                        <div class="h-full rounded-full bg-gradient-to-r from-accent-from to-accent-to motion-safe:transition-[width] motion-safe:duration-500" style="width: {{ $activeRun->progressPercent() }}%"></div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <ul class="max-h-72 space-y-0.5 overflow-y-auto pr-1 text-xs">
                            @foreach ($runItems as $item)
                                @php
                                    $warned = $item->status === 'done' && $item->error;
                                    $focused = $activityFocusRef === $item->image_ref;
                                @endphp
                                <li wire:key="run-item-{{ $item->id }}"
                                    wire:click="focusRunItem({{ json_encode($item->image_ref) }})"
                                    role="button" tabindex="0"
                                    title="{{ $focused ? 'Show the full log again' : 'Filter the log to this card' }}"
                                    class="cursor-pointer rounded-md py-0.5 pr-1 transition hover:bg-surface-2 {{ $focused ? 'bg-accent-bg' : '' }}">
                                    <div class="flex items-center gap-2">
                                        <span class="{{ match (true) {
                                            $warned => 'text-warn-fg',
                                            $item->status === 'done' => 'text-ok-fg',
                                            $item->status === 'failed' => 'text-danger-fg',
                                            $item->status === 'running' => 'text-accent animate-pulse',
                                            default => 'text-fg-subtle',
                                        } }}">
                                            {{ match (true) { $warned => '⚠', $item->status === 'done' => '✓', $item->status === 'failed' => '✕', $item->status === 'running' => '◌', default => '·' } }}
                                        </span>
                                        <span class="truncate text-fg-muted">{{ $item->name }}</span>
                                        @if ($item->payload['treatment'] ?? false)
                                            <span class="shrink-0 rounded bg-surface-3 px-1 py-px text-[9.5px] text-fg-subtle">{{ $item->payload['treatment'] }}</span>
                                        @endif
                                        <span class="ml-auto shrink-0 font-mono text-[10px] text-fg-subtle">{{ $item->image_ref }}</span>
                                    </div>
                                    @if ($item->error)
                                        {{-- the per-line why: red = failed, orchid = exported with warnings --}}
                                        <ul class="space-y-0.5 pl-5 text-[10.5px] leading-snug {{ $item->status === 'failed' ? 'text-danger-fg' : 'text-warn-fg' }}">
                                            @foreach (explode(' | ', $item->error) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex min-w-0 flex-col gap-1.5">
                            @if ($activityFocusRef)
                                <div class="flex items-center gap-2 text-[11px] text-fg-subtle">
                                    <span>Log filtered to <span class="font-mono text-fg-muted">{{ $activityFocusRef }}</span></span>
                                    <button wire:click="focusRunItem({{ json_encode($activityFocusRef) }})"
                                            class="text-fg-subtle underline underline-offset-2 transition hover:text-fg">show all</button>
                                </div>
                            @endif
                            @php
                                $logText = $activeRun->log ?? 'Waiting for the runner to pick up the job…';
                                if ($activityFocusRef !== null && $activeRun->log) {
                                    $logText = implode("\n", array_values(array_filter(
                                        explode("\n", $activeRun->log),
                                        fn ($line) => str_contains($line, $activityFocusRef),
                                    ))) ?: "No log lines for {$activityFocusRef} yet.";
                                }
                            @endphp
                            <pre class="well max-h-72 flex-1 overflow-y-auto p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap text-fg-muted">{{ $logText }}</pre>
                        </div>
                    </div>

                    @if ($activeRun->error)
                        <p class="mt-3 rounded-lg bg-danger-bg px-3 py-2 text-xs text-danger-fg">{{ $activeRun->error }}</p>
                    @endif
                </div>
            @endif

            <div class="panel p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="panel-title">Recent runs{{ $manifestPath !== '' && ! $activityAllSets ? ' · '.$setTitle : '' }}</h2>
                    @if ($manifestPath !== '')
                        <button wire:click="$toggle('activityAllSets')" class="pill {{ $activityAllSets ? 'pill-on' : '' }}">
                            {{ $activityAllSets ? 'All sets' : 'This set only' }}
                        </button>
                    @endif
                </div>

                @if ($recentRuns->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
                        <div class="empty-tile"><flux:icon name="clock" class="size-7" /></div>
                        <p class="text-[15px] font-semibold text-fg">No builds yet</p>
                        <p class="text-[13px] text-fg-muted">Pick your cards and hit Build.</p>
                        <flux:button size="sm" variant="subtle" wire:click="$set('tab','cards')">Go to cards</flux:button>
                    </div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($recentRuns as $run)
                            <li>
                                <button wire:click="viewRun({{ $run->id }})"
                                    class="flex w-full items-center gap-3 px-2 py-2.5 text-left text-sm transition hover:bg-surface-2 {{ $runId === $run->id ? 'bg-accent-bg' : '' }}">
                                    <span class="{{ match ($run->status) {
                                        'completed' => 'text-ok-fg',
                                        'failed' => 'text-danger-fg',
                                        default => 'text-accent animate-pulse',
                                    } }}">
                                        {{ match ($run->status) { 'completed' => '✓', 'failed' => '✕', default => '◌' } }}
                                    </span>
                                    <span class="w-12 font-mono text-xs text-fg-subtle">#{{ $run->id }}</span>
                                    <span class="min-w-0 flex-1 truncate text-fg">
                                        {{ $run->set_name ?: basename($run->merge_path) }}
                                        <span class="text-fg-subtle">· {{ $run->treatment }} · {{ strtoupper($run->format ?? 'pdf') }}{{ $run->profile ? ' · '.$run->profile : '' }}</span>
                                    </span>
                                    <span class="text-xs text-fg-muted tabular-nums">{{ $run->done_count }}/{{ $run->total }}@if ($run->failed_count) <span class="text-danger-fg">({{ $run->failed_count }} ✗)</span> @endif</span>
                                    <span class="text-xs whitespace-nowrap text-fg-subtle">{{ $run->created_at->diffForHumans(short: true) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- ── "What will be built" — resolved settings + pipeline ────────── --}}
    @if ($showInfoModal)
        @php
            $overridden = fn (string $field) => $manifestPath !== '' && $profile !== ''
                && array_key_exists($field, $inherited) && $this->{$field} != $inherited[$field];
            $infoRows = [
                'Source' => [
                    ['Merge file', $mergePath, $overridden('mergePath')],
                    ['Build kit', $kitPath, $overridden('kitPath')],
                    ['Template', $templatePath, $overridden('templatePath')],
                    ['Art folders', implode("\n", $artDirs), $overridden('artDirs')],
                    ['Cover image', str_starts_with($coverPath, 'data:') ? 'Embedded image (300×300)' : $coverPath, false],
                ],
                'Output' => [
                    ['Treatments', implode(', ', $treatments), $overridden('treatments')],
                    ['Format', strtoupper($format).($format === 'png' && $ppi ? " · {$ppi} ppi" : ''), $overridden('format') || $overridden('ppi')],
                    ['White plate', $proof ? 'Hidden (proof)' : 'Kept (production)', $overridden('proof')],
                    ['Destination', $destDir, false],
                ],
            ];
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('showInfoModal', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showInfoModal', false)"></div>
            <div class="panel relative flex max-h-[82vh] w-full max-w-2xl flex-col p-5">
                <h2 class="text-[15px] font-semibold text-fg">What will be built</h2>
                <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">
                    {{ $setTitle }} · profile <span class="font-medium text-fg">{{ $profile !== '' ? $profile : $defaultProfileName }}</span> — the settings a build uses right now.
                </p>

                <div class="mt-4 min-h-0 flex-1 space-y-5 overflow-y-auto pr-1">
                    @foreach ($infoRows as $section => $rows)
                        <div>
                            <h3 class="panel-title mb-2">{{ $section }}</h3>
                            <div class="well divide-y divide-line">
                                @foreach ($rows as [$label, $value, $isOverride])
                                    <div class="flex items-start gap-3 px-3 py-2" @if ($isOverride) title="Overrides the Default profile" @endif>
                                        <span class="field-label pt-0.5">{{ $label }}</span>
                                        <span class="min-w-0 flex-1 text-[12px] break-words whitespace-pre-line {{ $value ? 'text-fg' : 'text-fg-subtle italic' }} {{ str_contains($label, 'file') || str_contains($label, 'kit') || str_contains($label, 'Template') || str_contains($label, 'folders') || str_contains($label, 'Destination') || str_contains($label, 'Cover') ? 'font-mono text-[11px]' : '' }}">{{ $value ?: 'Not set' }}</span>
                                        @if ($isOverride)
                                            <flux:badge size="sm">profile</flux:badge>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <h3 class="panel-title mb-2">Pipeline</h3>
                        <details class="well px-3 py-2">
                            <summary class="cursor-pointer text-[12.5px] text-fg-muted">{{ count($pipeline) + 1 }} stages per card</summary>
                            <ol class="mt-2 space-y-1 pb-1 text-[12.5px]">
                                <li class="flex gap-2 text-fg-subtle"><span class="w-6 shrink-0 text-right font-mono">—</span>copy template → attach card CSV → show treatment layer → Data Merge</li>
                                @foreach ($pipeline as $stage)
                                    <li class="flex gap-2">
                                        <span class="w-6 shrink-0 text-right font-mono text-fg-subtle">{{ $loop->iteration }}</span>
                                        <span class="text-fg">{{ $stage['label'] ?? $stage['step'] }}</span>
                                        <span class="ml-auto font-mono text-[10.5px] text-fg-subtle">{{ $stage['step'] }}</span>
                                    </li>
                                @endforeach
                                <li class="flex gap-2">
                                    <span class="w-6 shrink-0 text-right font-mono text-fg-subtle">{{ count($pipeline) + 1 }}</span>
                                    <span class="text-fg">White plate {{ $proof ? 'hidden (proof)' : 'kept (production)' }}</span>
                                    <span class="ml-auto font-mono text-[10.5px] text-fg-subtle">whitePlate</span>
                                </li>
                                <li class="flex gap-2 text-fg-subtle"><span class="w-6 shrink-0 text-right font-mono">—</span>export {{ strtoupper($format) }} → close without saving</li>
                            </ol>
                        </details>
                    </div>
                </div>

                <div class="mt-4 flex justify-between">
                    <flux:button size="sm" variant="subtle" wire:click="editFromInfo">
                        Edit these settings
                    </flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="$set('showInfoModal', false)">Close</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Create-set wizard ──────────────────────────────────────────── --}}
    @if ($showCreateWizard)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('showCreateWizard', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showCreateWizard', false)"></div>
            <div class="panel relative w-full max-w-lg p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold text-fg">Create a set</h2>
                    <span class="text-[11px] text-fg-subtle tabular-nums">Step {{ $createStep }} of 2</span>
                </div>

                @if ($createStep === 1)
                    <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">Point Card Foundry at the set's files. Only the merge file is required — the rest can come later.</p>

                    <div class="mt-4 space-y-1">
                        @foreach ([
                            'mergePath' => ['Merge file *', 'The .csv / .xlsx defining every card'],
                            'kitPath' => ['Build kit', 'Folder containing wotfLib.jsxinc'],
                            'templatePath' => ['Template', 'Defaults to the kit\'s master template'],
                        ] as $field => [$label, $hint])
                            <div class="row-compact group">
                                <span class="field-label">{{ $label }}</span>
                                @if ($native)
                                    <span class="flex min-w-0 flex-1">
                                        @if ($this->{$field} && \App\Merge\GoogleSheetFetcher::isUrl($this->{$field}))
                                            <span class="truncate font-mono text-[11.5px] text-fg" title="{{ $this->{$field} }}">{{ $this->{$field} }}</span>
                                        @elseif ($this->{$field})
                                            <x-path :path="$this->{$field}" />
                                        @else
                                            <span class="text-[11.5px] text-fg-subtle italic">{{ $hint }}</span>
                                        @endif
                                    </span>
                                    @if ($field === 'mergePath')
                                        <flux:button size="xs" variant="subtle" wire:click="openSheetPicker" title="Pick a sheet from your Google Drive">Google Sheets…</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="subtle" wire:click="pick('{{ $field }}')">Choose…</flux:button>
                                @else
                                    <span class="flex min-w-0 flex-1 items-center gap-1.5">
                                        <flux:input size="sm" wire:model.blur="{{ $field }}" placeholder="{{ $hint }}" class="flex-1 font-mono" />
                                        @if ($field === 'mergePath')
                                            <flux:button size="xs" variant="subtle" wire:click="openSheetPicker" title="Pick a sheet from your Google Drive">Sheets…</flux:button>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        @endforeach

                        <div class="row-compact !h-auto !items-start py-2.5">
                            <span class="field-label pt-1">Art folders</span>
                            <ul class="min-w-0 flex-1 space-y-1">
                                @foreach ($artDirs as $index => $dir)
                                    <li class="flex items-center gap-2">
                                        <span class="min-w-0 flex-1"><x-path :path="$dir" /></span>
                                        <button wire:click="removeArtDir({{ $index }})" class="icon-btn">✕</button>
                                    </li>
                                @endforeach
                                @unless ($native)
                                    <li class="flex items-center gap-2">
                                        <flux:input size="sm" wire:model="newArtDir" wire:keydown.enter="addArtDir" placeholder="/path/to/art-folder" class="flex-1 font-mono" />
                                        <flux:button size="xs" variant="subtle" wire:click="addArtDir">Add</flux:button>
                                    </li>
                                @endunless
                            </ul>
                            @if ($native)
                                <flux:button size="xs" variant="subtle" wire:click="pick('artDirs')">Add…</flux:button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <flux:button size="sm" variant="subtle" wire:click="$set('showCreateWizard', false)">Cancel</flux:button>
                        <flux:button size="sm" variant="filled" wire:click="createSetContinue"
                            :disabled="! is_file($mergePath) && ! \App\Merge\GoogleSheetFetcher::isSheetUrl($mergePath)">Continue</flux:button>
                    </div>
                @else
                    <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">Name the set and choose its folder — everything about the set lives there.</p>

                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="field-label mb-1.5 block !w-auto">Set name</span>
                            <flux:input size="sm" wire:model.live.debounce.150ms="setName" placeholder="e.g. Trial by Combat" />
                        </label>

                        <div class="block">
                            <span class="field-label mb-1.5 block !w-auto">Set folder</span>
                            <div class="flex items-center gap-1.5">
                                @if ($native)
                                    <span class="flex min-w-0 flex-1">
                                        @if ($setFolder)
                                            <x-path :path="$setFolder" />
                                        @else
                                            <span class="text-[11.5px] text-fg-subtle italic">Where this set lives — usually its art folder in Dropbox</span>
                                        @endif
                                    </span>
                                    <flux:button size="xs" variant="subtle" wire:click="pick('setFolder')">Choose…</flux:button>
                                @else
                                    <flux:input size="sm" wire:model.blur="setFolder" placeholder="/path/to/the set's folder" class="flex-1 font-mono" />
                                @endif
                            </div>
                            <span class="mt-1 block text-[10.5px] leading-snug text-fg-subtle">
                                The folder is the project: it gets the config and run history (in a hidden
                                .foundry folder) and is where relative paths anchor. A folder that doesn't
                                exist yet is created.
                            </span>
                        </div>

                        <div class="row-compact group !h-auto !items-start !px-0 py-1">
                            <span class="field-label pt-1">Cover image</span>
                            <div class="min-w-0 flex-1">
                                <button type="button" wire:click="$set('showCoverEditor', true)"
                                        x-data x-on:dragover.prevent x-on:drop.prevent="
                                            const f = $event.dataTransfer.files[0];
                                            if (f && f.type.startsWith('image/')) {
                                                window.__coverDroppedFile = f;
                                                $wire.set('showCoverEditor', true);
                                            }"
                                        class="group/cover relative block size-16 overflow-hidden rounded-xl border border-dashed border-line-strong transition hover:border-accent"
                                        title="Optional — click to choose, or drop an image here">
                                    @if ($coverSrc($coverPath))
                                        <img src="{{ $coverSrc($coverPath) }}" alt="" class="size-full object-cover" />
                                    @else
                                        <span class="flex size-full items-center justify-center text-fg-subtle">
                                            <flux:icon name="photo" variant="mini" />
                                        </span>
                                    @endif
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-between gap-2">
                        <flux:button size="sm" variant="subtle" wire:click="$set('createStep', 1)">Back</flux:button>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="subtle" wire:click="$set('showCreateWizard', false)">Cancel</flux:button>
                            <flux:button size="sm" variant="filled" wire:click="createSetFinish" :disabled="trim($setName) === ''">Save set…</flux:button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Logs viewer ────────────────────────────────────────────────── --}}
    @if ($showLogsModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('showLogsModal', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showLogsModal', false)"></div>
            <div class="panel relative flex max-h-[82vh] w-full max-w-3xl flex-col p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-[15px] font-semibold text-fg">App logs</h2>
                    @if ($logFile)
                        <flux:button size="xs" variant="subtle" wire:click="openInFinder({{ json_encode(dirname($logFile)) }})">Show in Finder</flux:button>
                    @endif
                </div>

                @php
                    $dupNames = collect($logFiles)->countBy('name')->filter(fn ($n) => $n > 1)->keys()->all();
                    $logLabel = fn ($file) => in_array($file['name'], $dupNames, true)
                        ? $file['name'].' · '.(str_contains($file['path'], 'Application Support') ? 'app' : 'project')
                        : $file['name'];
                @endphp
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    @forelse ($logFiles as $file)
                        <button wire:click="$set('logFile', {{ json_encode($file['path']) }})"
                                class="pill {{ $logFile === $file['path'] ? '!border-accent !bg-accent !text-panel' : '' }}"
                                title="{{ $file['path'] }} · {{ number_format($file['size'] / 1024, 1) }} KB">
                            {{ $logLabel($file) }}
                        </button>
                    @empty
                        <span class="text-xs text-fg-subtle italic">No log files yet.</span>
                    @endforelse
                </div>

                <pre class="well mt-3 h-[420px] shrink-0 overflow-y-auto p-3 font-mono text-[11px] leading-relaxed break-all whitespace-pre-wrap text-fg-muted">@forelse ($logLines as $line){{ $line }}
@empty{{ $logFile ? 'This log is empty.' : 'Pick a log above.' }}@endforelse</pre>

                <div class="mt-3 flex items-center justify-between">
                    <span class="text-[11px] text-fg-subtle">Last {{ count($logLines) }} lines @if ($logFile)of <span class="font-mono">{{ basename($logFile) }}</span>@endif</span>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="subtle" wire:click="$refresh">Refresh</flux:button>
                        <flux:button size="sm" variant="subtle" wire:click="$set('showLogsModal', false)">Close</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Preferences (⌘,) ───────────────────────────────────────────── --}}
    @if ($showPreferences)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-6 pt-[12vh]"
             x-data x-on:keydown.escape.window="$wire.set('showPreferences', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showPreferences', false)"></div>
            <div class="panel relative w-full max-w-md p-0" wire:poll.3s="refreshGoogleStatus">
                <div class="flex items-center justify-center border-b border-line px-5 py-3">
                    <h2 class="text-[14px] font-semibold text-fg">Preferences</h2>
                    <button wire:click="$set('showPreferences', false)" aria-label="Close preferences"
                            class="ghost-icon absolute right-3">✕</button>
                </div>

                <div class="flex flex-col gap-5 p-5">
                    <div>
                        <span class="field-label mb-1.5 block !w-auto">Editing</span>
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg bg-surface-2 p-2.5">
                            <flux:checkbox wire:model.live="editorMode" class="mt-0.5" />
                            <span class="min-w-0">
                                <span class="block text-xs font-medium text-fg">Allow editing set configurations</span>
                                <span class="block text-[11px] leading-snug text-fg-subtle">
                                    Off, this machine can build cards and tweak per-run options, but never
                                    saves into a set's config — protection against accidental changes that
                                    would affect everyone syncing the set.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <span class="field-label mb-1.5 block !w-auto">Google account</span>
                        <div class="rounded-lg bg-surface-2 p-2.5">
                            @if ($googleEmail !== '')
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block truncate text-xs font-medium text-fg">{{ $googleEmail }}</span>
                                        <span class="block text-[11px] text-fg-subtle">Sheets are read as this account — its share access decides what opens.</span>
                                    </span>
                                    <flux:button size="xs" variant="subtle" wire:click="disconnectGoogle">Disconnect</flux:button>
                                </div>
                            @else
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block text-xs font-medium text-fg">Not connected</span>
                                        <span class="block text-[11px] leading-snug text-fg-subtle">
                                            Sign in to read protected merge sheets as yourself — the sheet's own
                                            sharing controls who has access. Without it, only link-shared sheets work.
                                        </span>
                                    </span>
                                    <flux:button size="xs" wire:click="connectGoogle">Connect…</flux:button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-line pt-3 text-[11px] text-fg-subtle">
                        <span>Card Foundry {{ \App\Manifest\FoundryLayout::appVersion() }}</span>
                        <span>Set format v{{ \App\Manifest\FoundryLayout::FORMAT_VERSION }} · you appear as “{{ \App\Manifest\FoundryLayout::user() }}”</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Artwork preview ────────────────────────────────────────────── --}}
    @if ($artPreview !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('artPreviewRef', null)"
             x-on:keydown.arrow-left.window="$wire.navigateCard(-1)"
             x-on:keydown.arrow-right.window="$wire.navigateCard(1)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('artPreviewRef', null)"></div>
            <div class="panel relative flex max-h-[85vh] w-full max-w-xl flex-col p-5">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="min-w-0 truncate text-[15px] font-semibold text-fg">Matched artwork · <span class="font-mono text-[13px]">{{ $artPreview['ref'] }}</span></h2>
                    @if ($cardNav)
                        <span class="shrink-0 text-[11.5px] text-fg-subtle tabular-nums">Card {{ $cardNav['pos'] }} of {{ $cardNav['total'] }}</span>
                    @endif
                </div>

                <div class="mt-3 flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-xl border border-line bg-surface-2">
                    @if ($artPreview['displayable'])
                        <img src="{{ route('local-image', ['p' => rtrim(strtr(base64_encode($artPreview['path']), '+/', '-_'), '=')]) }}"
                             alt="" class="max-h-[55vh] max-w-full object-contain" />
                    @else
                        <div class="flex flex-col items-center gap-2 py-14 text-center text-fg-subtle">
                            <flux:icon name="photo" class="size-8" />
                            <span class="text-[12.5px]">.{{ $artPreview['extension'] }} files can't be previewed here —<br />open it in Finder to view.</span>
                        </div>
                    @endif
                </div>

                @if ($artPreview['fallback'] ?? false)
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-warn-bg px-2.5 py-2 text-[11.5px] text-warn-fg">
                        <flux:icon name="exclamation-triangle" variant="micro" class="shrink-0" />
                        <span>The merge names <span class="font-mono">{{ $artPreview['ref'] }}</span> — this file is
                            <span class="font-mono">{{ basename($artPreview['path']) }}</span> (different format). Check before print exports.</span>
                    </div>
                @endif

                <div class="mt-3 flex min-w-0 items-center">
                    <x-path :path="$artPreview['path']" />
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <flux:button size="sm" variant="subtle" wire:click="openInFinder({{ json_encode($artPreview['path']) }})">Show in Finder</flux:button>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="subtle" wire:click="navigateCard(-1)" :disabled="! ($cardNav['hasPrev'] ?? false)" title="Previous card (←)" icon="chevron-left" />
                        <flux:button size="sm" variant="subtle" wire:click="navigateCard(1)" :disabled="! ($cardNav['hasNext'] ?? false)" title="Next card (→)" icon="chevron-right" />
                        <flux:button size="sm" variant="subtle" wire:click="$set('artPreviewRef', null)">Close</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Set file changed on disk ───────────────────────────────────── --}}
    @if ($showManifestChanged)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.ackManifestChange()">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="ackManifestChange"></div>
            <div class="panel relative w-full max-w-md p-5">
                <h2 class="text-[15px] font-semibold text-fg">Set file changed on disk</h2>
                <p class="mt-2 text-[12.5px] leading-relaxed text-fg-muted">
                    <span class="font-mono text-[11.5px]">{{ basename($manifestPath) }}</span> was modified outside
                    Card Foundry — maybe by hand, or by a sync from another machine. Reload it to pick up the
                    changes, or keep working with what's on screen.
                </p>
                <div class="mt-3 flex min-w-0 items-center">
                    <x-path :path="$manifestPath" />
                </div>
                <div class="mt-4 flex items-center justify-end gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="ackManifestChange">Keep as is</flux:button>
                    <flux:button size="sm" variant="filled" wire:click="reloadManifest">Reload set file</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Card issue detail ──────────────────────────────────────────── --}}
    @if ($cardDetail !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('detailRef', null)"
             x-on:keydown.arrow-left.window="$wire.navigateCard(-1)"
             x-on:keydown.arrow-right.window="$wire.navigateCard(1)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('detailRef', null)"></div>
            <div class="panel relative flex max-h-[80vh] w-full max-w-lg flex-col p-5">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="min-w-0 truncate text-[15px] font-semibold text-fg">{{ $cardDetail['card']['name'] }}</h2>
                    @if ($cardNav)
                        <span class="shrink-0 text-[11.5px] text-fg-subtle tabular-nums">Card {{ $cardNav['pos'] }} of {{ $cardNav['total'] }}</span>
                    @endif
                </div>
                <p class="mt-0.5 text-[12px] text-fg-subtle">
                    {{ $cardDetail['card']['type'] }}{{ $cardDetail['card']['type'] && $cardDetail['card']['code'] ? ' · ' : '' }}{{ $cardDetail['card']['code'] }}
                    · row {{ $cardDetail['row'] }} · <span class="font-mono">{{ $cardDetail['card']['ref'] }}</span>
                </p>

                <div class="mt-4 min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                    @if ($cardDetail['issues'])
                        <div>
                            <h3 class="panel-title mb-2">Issues on this card</h3>
                            <ul class="space-y-1.5">
                                @foreach ($cardDetail['issues'] as $issue)
                                    <li class="flex gap-2 rounded-lg px-2.5 py-1.5 text-xs {{ $issue['severity'] === 'error' ? 'bg-danger-bg text-danger-fg' : 'bg-warn-bg text-warn-fg' }}">
                                        <span class="shrink-0 font-semibold">{{ $issue['severity'] === 'error' ? '✕' : '⚠' }}</span>
                                        <span>{{ $issue['message'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($cardDetail['artMissing'])
                        <div>
                            <h3 class="panel-title mb-2">Artwork search</h3>
                            <p class="text-[12px] text-fg-muted">
                                Looked for
                                <span class="font-mono text-[11px] text-fg">{{ $cardDetail['candidates'][0] }}</span>
                                (then <span class="font-mono text-[11px]">{{ $cardDetail['card']['ref'] }}.{{ implode(' / .', config('wotf.art_extensions')) }}</span>)
                                in each of these folders, top to bottom:
                            </p>

                            <ul class="mt-2 space-y-1.5">
                                @forelse ($cardDetail['artDirs'] as $index => $dir)
                                    <li class="flex items-center gap-2">
                                        <span class="w-3 shrink-0 text-center font-mono text-[10px] text-fg-subtle">{{ $index + 1 }}</span>
                                        <span class="{{ $dir['exists'] ? 'text-danger-fg' : 'text-fg-subtle' }} shrink-0" title="{{ $dir['exists'] ? 'Folder exists — file not in it' : 'Folder does not exist' }}">
                                            {{ $dir['exists'] ? '✕' : '?' }}
                                        </span>
                                        <span class="flex min-w-0 flex-1"><x-path :path="$dir['path']" /></span>
                                        @if ($dir['exists'])
                                            <flux:button size="xs" variant="subtle" wire:click="openInFinder({{ json_encode($dir['path']) }})">Open in Finder</flux:button>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-xs text-fg-subtle italic">No art folders are configured — add one in Edit setup.</li>
                                @endforelse
                            </ul>

                            <p class="mt-2 text-[11px] text-fg-subtle">
                                ✕ = folder searched, file not there · ? = folder missing on this machine
                            </p>
                        </div>
                    @endif
                </div>

                <div class="mt-4 flex items-center justify-end gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="navigateCard(-1)" :disabled="! ($cardNav['hasPrev'] ?? false)" title="Previous card (←)" icon="chevron-left" />
                    <flux:button size="sm" variant="subtle" wire:click="navigateCard(1)" :disabled="! ($cardNav['hasNext'] ?? false)" title="Next card (→)" icon="chevron-right" />
                    <flux:button size="sm" variant="subtle" wire:click="$set('detailRef', null)">Close</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Cover crop editor ──────────────────────────────────────────── --}}
    @if ($showCoverEditor)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data="{
                img: null, zoom: 1, minZoom: 0.05, maxZoom: 6, ox: 0, oy: 0,
                view: 320, dragging: false, lx: 0, ly: 0,
                init() {
                    if (window.__coverDroppedFile) { this.readFile(window.__coverDroppedFile); window.__coverDroppedFile = null; }
                    else if (this.$refs.existing?.value) { this.load(this.$refs.existing.value); }
                },
                readFile(f) { const r = new FileReader(); r.onload = () => this.load(r.result); r.readAsDataURL(f); },
                pick(e) { const f = e.target.files[0]; if (f) this.readFile(f); e.target.value = ''; },
                drop(e) { const f = e.dataTransfer.files[0]; if (f && f.type.startsWith('image/')) this.readFile(f); },
                load(url) {
                    const i = new Image();
                    i.onload = () => {
                        this.img = i;
                        this.minZoom = this.view / Math.min(i.width, i.height);
                        this.zoom = this.minZoom;
                        this.ox = (i.width * this.zoom - this.view) / 2;
                        this.oy = (i.height * this.zoom - this.view) / 2;
                        this.draw();
                    };
                    i.src = url;
                },
                clamp() {
                    this.zoom = Math.min(this.maxZoom, Math.max(this.minZoom, this.zoom));
                    this.ox = Math.min(Math.max(this.ox, 0), this.img.width * this.zoom - this.view);
                    this.oy = Math.min(Math.max(this.oy, 0), this.img.height * this.zoom - this.view);
                },
                draw() {
                    if (! this.img) return;
                    const ctx = this.$refs.canvas.getContext('2d');
                    ctx.clearRect(0, 0, this.view, this.view);
                    ctx.drawImage(this.img,
                        this.ox / this.zoom, this.oy / this.zoom, this.view / this.zoom, this.view / this.zoom,
                        0, 0, this.view, this.view);
                },
                setZoom(z) {
                    if (! this.img) return;
                    const prev = this.zoom;
                    this.zoom = parseFloat(z);
                    this.ox = (this.ox + this.view / 2) * (this.zoom / prev) - this.view / 2;
                    this.oy = (this.oy + this.view / 2) * (this.zoom / prev) - this.view / 2;
                    this.clamp(); this.draw();
                },
                wheel(e) { this.setZoom(this.zoom * (e.deltaY < 0 ? 1.08 : 0.93)); },
                down(e) { this.dragging = true; this.lx = e.clientX; this.ly = e.clientY; },
                move(e) {
                    if (! this.dragging || ! this.img) return;
                    this.ox -= e.clientX - this.lx; this.oy -= e.clientY - this.ly;
                    this.lx = e.clientX; this.ly = e.clientY;
                    this.clamp(); this.draw();
                },
                apply() {
                    if (! this.img) return;
                    const out = document.createElement('canvas');
                    out.width = out.height = 300;
                    out.getContext('2d').drawImage(this.img,
                        this.ox / this.zoom, this.oy / this.zoom, this.view / this.zoom, this.view / this.zoom,
                        0, 0, 300, 300);
                    $wire.set('coverPath', out.toDataURL('image/jpeg', 0.85));
                    $wire.set('showCoverEditor', false);
                },
             }"
             x-on:keydown.escape.window="$wire.set('showCoverEditor', false)"
             x-on:mouseup.window="dragging = false">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showCoverEditor', false)"></div>
            <input type="hidden" x-ref="existing" value="{{ $coverSrc($coverPath) }}" />

            <div class="panel relative w-full max-w-sm p-5">
                <h2 class="text-[15px] font-semibold text-fg">Set cover</h2>
                <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">
                    Drag to position, scroll or slide to zoom. The square becomes the set's icon — embedded in the set file, so it works on every machine.
                </p>

                <div class="mt-4 flex flex-col items-center gap-3">
                    <div class="relative overflow-hidden rounded-xl border border-line bg-surface-2"
                         x-on:dragover.prevent x-on:drop.prevent="drop($event)">
                        <canvas x-ref="canvas" width="320" height="320"
                                class="block touch-none select-none"
                                :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
                                x-on:mousedown.prevent="down($event)"
                                x-on:mousemove="move($event)"
                                x-on:wheel.prevent="wheel($event)"></canvas>
                        <div x-show="! img" class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-center text-fg-subtle">
                            <flux:icon name="photo" class="size-8" />
                            <span class="text-[12px]">Drop an image here<br />or choose one below</span>
                        </div>
                    </div>

                    <div class="flex w-full items-center gap-3" x-show="img">
                        <flux:icon name="magnifying-glass-minus" variant="micro" class="shrink-0 text-fg-subtle" />
                        <input type="range" class="w-full accent-(--color-accent)"
                               :min="minZoom" :max="maxZoom" step="0.01"
                               :value="zoom" x-on:input="setZoom($event.target.value)" />
                        <flux:icon name="magnifying-glass-plus" variant="micro" class="shrink-0 text-fg-subtle" />
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <label class="cursor-pointer">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-fg-muted transition hover:border-line-strong hover:text-fg">
                            <flux:icon name="folder-open" variant="micro" /> Browse…
                        </span>
                        <input type="file" accept="image/*" class="hidden" x-on:change="pick($event)" />
                    </label>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="subtle" wire:click="$set('showCoverEditor', false)">Cancel</flux:button>
                        <button class="btn-go !px-4 !py-1.5 !text-xs" x-on:click="apply()" x-bind:disabled="! img">Use this crop</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── InDesign setup wizard ──────────────────────────────────────── --}}
    @if ($showSetupWizard && $setup !== null)
        @php
            $steps = [
                [
                    'done' => $setup['app'] !== null,
                    'label' => 'Adobe InDesign is installed',
                    'detail' => $setup['app'] ? basename($setup['app'], '.app') : 'Install InDesign from the Creative Cloud app, then come back.',
                    'action' => null,
                ],
                [
                    'done' => $bridge['up'],
                    'label' => 'Bridge server is running',
                    'detail' => $bridge['up'] ? null : 'Card Foundry runs this for you.',
                    'action' => $bridge['up'] ? null : ['startBridge', 'Start'],
                ],
                [
                    'done' => $setup['plugin'] === true,
                    'label' => 'InDesign Bridge plugin is installed',
                    'detail' => $setup['plugin'] === null ? 'Can\'t auto-detect — if you\'ve installed it before, skip this step.' : null,
                    'action' => $setup['plugin'] === true ? null : ['installPlugin', 'Install plugin'],
                ],
                [
                    'done' => $setup['running'],
                    'label' => 'InDesign is open',
                    'detail' => null,
                    'action' => $setup['running'] ? null : ['wizardOpenInDesign', 'Open InDesign'],
                ],
                [
                    'done' => $bridge['indesign'] === true,
                    'label' => 'Plugin panel is enabled',
                    'detail' => $bridge['indesign'] === true ? null : 'In InDesign: Window → Plugins → InDesign Bridge. This checks automatically.',
                    'action' => null,
                ],
            ];
            $allDone = collect($steps)->every(fn ($s) => $s['done']);
        @endphp

        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('showSetupWizard', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showSetupWizard', false)"></div>
            <div class="panel relative w-full max-w-md p-5" wire:poll.4s="refreshBridge">
                <h2 class="text-[15px] font-semibold text-fg">Set up InDesign</h2>
                <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">
                    Card Foundry drives InDesign through a small plugin. Work down this list — each step checks itself off.
                </p>

                <ol class="mt-4 space-y-1">
                    @foreach ($steps as $index => $step)
                        <li class="flex items-start gap-3 rounded-lg px-2.5 py-2 {{ $step['done'] ? '' : 'bg-surface-2' }}">
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold
                                {{ $step['done'] ? 'bg-ok-bg text-ok-fg' : 'border border-line-strong text-fg-subtle' }}">
                                {{ $step['done'] ? '✓' : $index + 1 }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13px] font-medium {{ $step['done'] ? 'text-fg-muted' : 'text-fg' }}">{{ $step['label'] }}</span>
                                @if ($step['detail'])
                                    <span class="block text-[11.5px] leading-snug text-fg-subtle">{{ $step['detail'] }}</span>
                                @endif
                            </span>
                            @if ($step['action'])
                                <flux:button size="xs" variant="filled" wire:click="{{ $step['action'][0] }}"
                                    wire:loading.attr="disabled" wire:target="{{ $step['action'][0] }}">
                                    {{ $step['action'][1] }}
                                </flux:button>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if ($wizardNote)
                    <p class="mt-3 rounded-lg bg-surface-2 px-3 py-2 text-[11.5px] leading-snug text-fg-muted">{{ $wizardNote }}</p>
                @endif

                <div class="mt-4 flex items-center justify-between">
                    @if ($allDone)
                        <span class="text-[12.5px] font-medium text-ok-fg">✓ All set — InDesign is connected.</span>
                        <flux:button size="sm" variant="filled" wire:click="$set('showSetupWizard', false)">Done</flux:button>
                    @else
                        <span class="text-[11.5px] text-fg-subtle">Checking automatically…</span>
                        <flux:button size="sm" variant="subtle" wire:click="$set('showSetupWizard', false)">Close</flux:button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ── Save-as-profile modal ──────────────────────────────────────── --}}
    @if ($showProfileModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-6"
             x-data x-on:keydown.escape.window="$wire.set('showProfileModal', false)">
            <div class="absolute inset-0 bg-surface/70 backdrop-blur-sm" wire:click="$set('showProfileModal', false)"></div>
            <div class="panel relative w-full max-w-sm p-5" x-init="$nextTick(() => $refs.profileName?.focus())">
                <h2 class="text-[15px] font-semibold text-fg">Save as new profile</h2>
                <p class="mt-1 text-[12.5px] leading-relaxed text-fg-muted">
                    Saves the current settings as a named profile on
                    <span class="font-medium text-fg">{{ $setTitle }}</span> and switches to it.
                </p>

                <div class="mt-4">
                    <span class="field-label mb-1.5 block !w-auto">Profile name</span>
                    <flux:input size="sm" x-ref="profileName" wire:model.live.debounce.150ms="newProfileName"
                                wire:keydown.enter="saveAsProfile" placeholder="e.g. preview, print, social" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="$set('showProfileModal', false)">Cancel</flux:button>
                    <flux:button size="sm" variant="filled" wire:click="saveAsProfile" :disabled="trim($newProfileName) === ''">
                        Save profile
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>

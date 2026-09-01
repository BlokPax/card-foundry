<?php

return [

    /*
    |--------------------------------------------------------------------------
    | InDesign UXP bridge
    |--------------------------------------------------------------------------
    | The HTTP endpoint of the indesign-uxp-server bridge (bridge/server.js).
    | The bridge relays code over a WebSocket (port 3001) to the UXP plugin
    | loaded inside InDesign.
    */

    'bridge_url' => env('WOTF_BRIDGE_URL', 'http://127.0.0.1:3000'),

    // A pinned copy of the bridge ships with the app in resources/bridge
    // (see resources/bridge/VERSION.md). Set this to use a different checkout.
    'bridge_path' => env('WOTF_BRIDGE_PATH'),

    // Runtime for the bridge server. The default 'node' means "figure it
    // out": prefer the app's own Electron binary run as Node
    // (ELECTRON_RUN_AS_NODE=1) so distributed builds need no Node install.
    'node_binary' => env('WOTF_NODE_BINARY', 'node'),

    // Explicit Electron binary override; auto-detected when null.
    'electron_binary' => env('WOTF_ELECTRON_BINARY'),

    // Adobe's Unified Plugin Installer Agent — installs the bundled .ccx
    // UXP plugin into InDesign without the UXP Developer Tool.
    'upia_path' => env('WOTF_UPIA_PATH', '/Library/Application Support/Adobe/Adobe Desktop Common/RemoteComponents/UPI/UnifiedPluginInstallerAgent/UnifiedPluginInstallerAgent.app/Contents/MacOS/UnifiedPluginInstallerAgent'),

    // Fallback opener: hands the .ccx to Creative Cloud Desktop (double-click flow).
    'open_binary' => env('WOTF_OPEN_BINARY', '/usr/bin/open'),

    // Seconds allowed per card build (open + merge + pipeline + export).
    // Also raises the bridge's own script timeout via BRIDGE_TIMEOUT_MS.
    'card_timeout' => (int) env('WOTF_CARD_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Build kit
    |--------------------------------------------------------------------------
    | The "_WoTF InDesign Build Template" folder: wotfLib.jsxinc, Links/
    | (frame art, feathers, KO mask), and builds/_MASTER_BUILD_TEMPLATE.indd.
    */

    'kit_path' => env('WOTF_KIT_PATH'),

    'kit_library' => 'wotfLib.jsxinc',

    'kit_template' => 'builds/_MASTER_BUILD_TEMPLATE.indd',

    // Art files are matched as {dir}/{@Image} or {dir}/{@Image}.{ext},
    // searching the run's art directories in order (first match wins).
    'art_extensions' => ['psd', 'psb', 'tif', 'tiff', 'png', 'jpg', 'jpeg', 'ai', 'eps', 'pdf'],

    /*
    |--------------------------------------------------------------------------
    | Treatments
    |--------------------------------------------------------------------------
    | Treatment key (as wotfLib's TREATMENTS table spells it) => the template
    | layer made visible for the build. Exactly one is visible per card.
    */

    'treatments' => [
        'Base Paper' => 'Paper Frame',
        'Classic Foil' => 'Foil Frame',
        'Formless Foil' => 'NON OCM Frame',
        'OCM' => 'OCM Frame',
        'Stonefoil' => 'Stone Foil',
        'Dragon Scale' => 'Dragon Foil',
    ],

    /*
    |--------------------------------------------------------------------------
    | Build pipeline
    |--------------------------------------------------------------------------
    | Every wotfLib step run against the merged document, in order. The order
    | is load-bearing (documented in the kit's README): text rewrites first,
    | both orbital steps before anything could touch the ORBITAL - DELETE ME
    | layer, power box fill after parents, overset expansion last among the
    | cleanup steps, and the feather before the treatment frame (frame
    | placement reads feather visibility to pick story-token art).
    |
    | Opts placeholders resolved per run: @csv, @treatment, @links.
    | The whitePlate step is appended automatically (hidden for proofs).
    */

    'pipeline' => [
        ['step' => 'lbAndItalics', 'label' => 'Italics + line breaks', 'opts' => []],
        ['step' => 'typeLineSpacing', 'label' => 'Type line spacing', 'opts' => []],
        ['step' => 'abilityKeywords', 'label' => 'Ability keywords', 'opts' => []],
        ['step' => 'powerOrbitalColors', 'label' => 'Power orbital colours', 'opts' => []],
        ['step' => 'orbitalParents', 'label' => 'Orbital parent pages', 'opts' => []],
        ['step' => 'powerBoxFill', 'label' => 'Wonder power box fill', 'opts' => []],
        ['step' => 'frauncesFonts', 'label' => 'Fraunces font repair', 'opts' => []],
        ['step' => 'expandOverset', 'label' => 'Expand overset frames', 'opts' => []],
        ['step' => 'storyTokenFeather', 'label' => 'Story-token feather', 'opts' => ['treatment' => '@treatment', 'links' => '@links']],
        ['step' => 'applyTreatmentFrame', 'label' => 'Treatment frame', 'opts' => ['treatment' => '@treatment', 'links' => '@links']],
        ['step' => 'treatmentChrome', 'label' => 'Treatment chrome', 'opts' => ['treatment' => '@treatment']],
        ['step' => 'placeArtInBox', 'label' => 'Place art in box', 'opts' => []],
        ['step' => 'applyCardData', 'label' => 'Serials + first-edition stamp', 'opts' => ['csvPath' => '@csv', 'treatment' => '@treatment', 'numerator' => '@serial']],
        ['step' => 'autographLine', 'label' => 'Autograph line', 'opts' => ['csvPath' => '@csv']],
        ['step' => 'setName', 'label' => 'Set name footer', 'opts' => ['csvPath' => '@csv']],
    ],

];

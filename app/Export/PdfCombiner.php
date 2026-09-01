<?php

namespace App\Export;

use App\InDesign\BridgeSupervisor;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Merges the per-card PDFs of a run into one document (one card per page).
 *
 * The heavy lifting happens in a bundled pdf-lib script
 * (resources/pdf/combine.bundle.cjs) executed on the same runtime the bridge
 * uses — the app's own Electron binary run as Node — so distributed builds
 * need no Node install.
 */
class PdfCombiner
{
    public function __construct(private readonly ?BridgeSupervisor $supervisor = null) {}

    /**
     * @param  list<string>  $files  per-card PDFs, in page order
     */
    public function combine(array $files, string $output): void
    {
        if ($files === []) {
            throw new RuntimeException('No PDFs to combine.');
        }

        $script = base_path('resources/pdf/combine.bundle.cjs');

        if (! is_file($script)) {
            throw new RuntimeException("PDF combine script missing: {$script}");
        }

        [$node, $env] = ($this->supervisor ?? app(BridgeSupervisor::class))->nodeCommand();

        $result = Process::timeout(300)
            ->env($env)
            ->input(json_encode(['files' => array_values($files), 'output' => $output], JSON_THROW_ON_ERROR))
            ->run([$node, $script]);

        if ($result->failed() || ! is_file($output)) {
            throw new RuntimeException(
                'PDF combine failed: '.(trim($result->errorOutput()) ?: trim($result->output()) ?: 'no output written'),
            );
        }
    }
}

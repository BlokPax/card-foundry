<?php

namespace Tests\Unit;

use App\Merge\ArtResolver;
use PHPUnit\Framework\TestCase;

class ArtResolverTest extends TestCase
{
    private string $setDir;

    private string $celebDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/wotf-art-'.uniqid();
        mkdir($this->setDir = $base.'-set');
        mkdir($this->celebDir = $base.'-celebs');
    }

    protected function tearDown(): void
    {
        foreach ([$this->setDir, $this->celebDir] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function resolver(): ArtResolver
    {
        return new ArtResolver([$this->setDir, $this->celebDir], ['psd', 'jpg', 'png']);
    }

    public function test_resolves_by_code_with_extension_probing(): void
    {
        touch($this->setDir.'/TBC331.jpg');

        $this->assertSame($this->setDir.'/TBC331.jpg', $this->resolver()->resolve('TBC331'));
    }

    public function test_resolves_exact_filename_including_celebrity_naming(): void
    {
        touch($this->celebDir.'/Alyssa Milano - TBC - 01.jpg');

        $this->assertSame(
            $this->celebDir.'/Alyssa Milano - TBC - 01.jpg',
            $this->resolver()->resolve('Alyssa Milano - TBC - 01.jpg'),
        );
    }

    public function test_earlier_directory_wins(): void
    {
        touch($this->setDir.'/TBC331.jpg');
        touch($this->celebDir.'/TBC331.jpg');

        $this->assertSame($this->setDir.'/TBC331.jpg', $this->resolver()->resolve('TBC331'));
    }

    public function test_falls_through_to_later_directories(): void
    {
        touch($this->celebDir.'/Chuck Liddell - TBC - 02.jpg');

        $this->assertSame(
            $this->celebDir.'/Chuck Liddell - TBC - 02.jpg',
            $this->resolver()->resolve('Chuck Liddell - TBC - 02.jpg'),
        );
    }

    public function test_returns_null_when_missing_everywhere(): void
    {
        $this->assertNull($this->resolver()->resolve('TBC999'));
    }

    public function test_reports_all_matches_for_ambiguity_checks(): void
    {
        touch($this->setDir.'/TBC331.jpg');
        touch($this->celebDir.'/TBC331.psd');

        $this->assertCount(2, $this->resolver()->resolveAll('TBC331'));
    }

    // ── Suffixed refs vs differently-exported folders ────────────────────
    // The merge says "TBC404.jpg" (the CMYK exports) but an RGB profile's
    // folder holds "TBC404.png". Exact suffix wins; the same stem with
    // another known image extension is the fallback.

    public function test_suffixed_ref_falls_back_to_the_same_stem_with_another_extension(): void
    {
        touch($this->setDir.'/TBC404.png');

        $this->assertSame($this->setDir.'/TBC404.png', $this->resolver()->resolve('TBC404.jpg'));
    }

    public function test_exact_suffix_wins_over_stem_alternates(): void
    {
        touch($this->setDir.'/TBC404.jpg');
        touch($this->setDir.'/TBC404.png');

        $this->assertSame($this->setDir.'/TBC404.jpg', $this->resolver()->resolve('TBC404.jpg'));
    }

    public function test_stem_fallback_respects_directory_precedence(): void
    {
        // An exact match in a later directory still loses to a stem
        // alternate in an earlier one — first directory wins, period.
        touch($this->setDir.'/TBC404.png');
        touch($this->celebDir.'/TBC404.jpg');

        $this->assertSame($this->setDir.'/TBC404.png', $this->resolver()->resolve('TBC404.jpg'));
    }

    public function test_refs_without_a_known_image_extension_are_not_stem_swapped(): void
    {
        // "Chuck Liddell - TBC - 02" ends in "02", not an image extension —
        // probing appends extensions but never rewrites the ref itself.
        touch($this->setDir.'/Chuck Liddell - TBC.png');

        $this->assertNull($this->resolver()->resolve('Chuck Liddell - TBC - 02'));
    }
}

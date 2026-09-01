<?php

namespace Tests\Feature;

use App\Support\Settings;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    public function test_round_trips_values(): void
    {
        Settings::put('paths.mergePath', '/some/file.csv');
        Settings::put('paths.artDirs', ['/a', '/b']);

        $this->assertSame('/some/file.csv', Settings::get('paths.mergePath'));
        $this->assertSame(['/a', '/b'], Settings::get('paths.artDirs'));
        $this->assertSame('fallback', Settings::get('missing', 'fallback'));
    }

    public function test_does_not_require_a_database(): void
    {
        config(['database.default' => 'nonexistent-connection']);

        Settings::put('paths.destDir', '/out');

        $this->assertSame('/out', Settings::get('paths.destDir'));
    }
}

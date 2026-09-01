<?php

namespace App\Support;

/**
 * File-backed key/value settings (storage/app/settings.json).
 *
 * Deliberately not a database table: the NativePHP dev database is recreated
 * on schema changes and lives per-install, and losing the user's remembered
 * paths every time the schema moves is exactly what this avoids. In the
 * packaged app the storage path resolves into app-data, so values survive
 * relaunches and updates.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $memory = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $all = static::all();
        $all[$key] = $value;

        static::$memory = $all;

        if (app()->runningUnitTests()) {
            return;
        }

        $path = static::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Atomic write so a crash mid-save can't corrupt the file.
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $path);
        }
    }

    public static function forget(string $key): void
    {
        static::put($key, null);
    }

    /**
     * Reset the in-memory cache (used between tests).
     */
    public static function flush(): void
    {
        static::$memory = null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        if (static::$memory !== null) {
            return static::$memory;
        }

        if (app()->runningUnitTests()) {
            return static::$memory = [];
        }

        $raw = @file_get_contents(static::path());
        $decoded = $raw === false ? null : json_decode($raw, true);

        return static::$memory = is_array($decoded) ? $decoded : [];
    }

    private static function path(): string
    {
        return storage_path('app/settings.json');
    }
}

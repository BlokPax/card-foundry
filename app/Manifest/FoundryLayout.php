<?php

namespace App\Manifest;

/**
 * The .foundry/ set layout: everything Card Foundry keeps about a set lives
 * in a dot-folder inside the set's own directory, synced with it.
 *
 *   <Set>/.foundry/config.json    the live config (the only mutable file)
 *   <Set>/.foundry/versions/      every saved config, immutable, forever
 *   <Set>/.foundry/runs/          one immutable record per build run
 *   <Set>/.foundry/cache/         downloaded merge sheets (hash-monitored)
 *   <Set>/.foundry/scripts/       optional JSX overrides (frozen/custom kits)
 *
 * Relative paths in config.json always resolve against the SET folder — the
 * parent of .foundry — so a config moved from a legacy foundry.json keeps
 * every path intact, and assets above the set ("../shared/master.indd")
 * climb exactly as before. A bare <Set>/foundry.json remains readable as
 * the legacy layout.
 *
 * Versions and run records are write-once files named
 * {stamp}-{user}-{suffix}: UTC stamps without colons (Windows-safe), the
 * writing user for attribution, and a content hash / random suffix so two
 * machines can never fight over one filename. config.json is the only file
 * with any concurrent-write risk, guarded by compare-before-write in the UI.
 */
final class FoundryLayout
{
    public const DIR = '.foundry';

    public const CONFIG = 'config.json';

    public const LEGACY = 'foundry.json';

    /**
     * The config.json schema this app reads AND writes. Files stamped with
     * a higher number were written by a newer app: they still load for
     * viewing/building, but writes are refused so an old app can't damage
     * a set it doesn't fully understand. Files stamped lower are migration
     * candidates for future versions. (1 = the pre-stamp era.)
     */
    public const FORMAT_VERSION = 2;

    /** The run-record (runs/<id>/run.json) schema. */
    public const RUN_FORMAT_VERSION = 1;

    /** The human-readable app version, stamped beside every formatVersion. */
    public static function appVersion(): string
    {
        return (string) (config('nativephp.version') ?: 'dev');
    }

    /**
     * The config file for a set reference: a set folder (preferring
     * .foundry/config.json, falling back to legacy foundry.json), or a
     * config file path given directly. Null when a folder holds neither.
     */
    public static function discover(string $path): ?string
    {
        $path = rtrim($path, '/');

        if (! is_dir($path)) {
            return $path === '' ? null : $path;
        }

        foreach ([$path.'/'.self::DIR.'/'.self::CONFIG, $path.'/'.self::LEGACY] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** True when the path is a config.json inside a .foundry folder. */
    public static function isFoundryConfig(string $configPath): bool
    {
        return basename(dirname($configPath)) === self::DIR;
    }

    /**
     * The set folder a config's relative paths resolve against.
     */
    public static function baseDirFor(string $configPath): string
    {
        return self::isFoundryConfig($configPath)
            ? dirname($configPath, 2)
            : dirname($configPath);
    }

    /**
     * The set's .foundry directory — for a legacy foundry.json this is the
     * sibling .foundry, so versions/runs land in one place either way.
     */
    public static function foundryDirFor(string $configPath): string
    {
        return self::baseDirFor($configPath).'/'.self::DIR;
    }

    /** Where a migrated legacy config belongs. */
    public static function configPathFor(string $setDir): string
    {
        return rtrim($setDir, '/').'/'.self::DIR.'/'.self::CONFIG;
    }

    private static string $lastStamp = '';

    /**
     * UTC timestamp with no colons — safe in filenames on every platform.
     * Millisecond precision, and strictly increasing within a process, so
     * rapid saves always sort chronologically.
     */
    public static function stamp(): string
    {
        do {
            $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THisv').'Z';

            if ($stamp <= self::$lastStamp) {
                usleep(1000);
            }
        } while ($stamp <= self::$lastStamp);

        return self::$lastStamp = $stamp;
    }

    /** The local user, slugged for filenames. */
    public static function user(): string
    {
        $user = getenv('USER') ?: get_current_user();

        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $user), '-') ?: 'user';
    }
}

<?php

namespace App\Manifest;

/**
 * Writes UI/CLI state back into a foundry.json — either updating one
 * profile's overrides or creating a fresh manifest. Absolute paths are
 * relativized against the manifest's own folder wherever possible so the
 * file works on every machine syncing the same Dropbox tree.
 */
final class ManifestWriter
{
    private const PATH_KEYS = ['merge', 'kit', 'template', 'cover'];

    /**
     * @param  array<string,mixed>  $values
     */
    /**
     * @param  bool  $replace  true = the profile becomes exactly $values
     *                         (dropping keys that reverted to inherited);
     *                         false = merge into the existing profile.
     */
    public function saveProfile(string $path, string $profile, array $values, bool $replace = false): void
    {
        $data = $this->read($path);

        $relativized = $this->relativize($values, FoundryLayout::baseDirFor($path));
        $data['profiles'][$profile] = $replace
            ? $relativized
            : array_replace((array) ($data['profiles'][$profile] ?? []), $relativized);

        $this->write($path, $data);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function saveBase(string $path, array $values): void
    {
        $data = $this->read($path);

        $data['base'] = array_replace((array) ($data['base'] ?? []), $this->relativize($values, FoundryLayout::baseDirFor($path)));

        $this->write($path, $data);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,array<string,mixed>>  $profiles
     */
    public function create(string $path, string $set, array $base, array $profiles = []): void
    {
        $dir = FoundryLayout::baseDirFor($path);

        $this->write($path, [
            'version' => 1,
            'set' => $set,
            'base' => $this->relativize($base, $dir),
            'profiles' => array_map(fn ($profile) => $this->relativize((array) $profile, $dir), $profiles),
        ]);
    }

    /**
     * Remove a named profile. The base config (the Default profile) isn't in
     * the profiles map, so it can never be deleted this way.
     */
    public function deleteProfile(string $path, string $name): void
    {
        $data = $this->read($path);

        if (! array_key_exists($name, (array) ($data['profiles'] ?? []))) {
            throw new ManifestException("No profile \"{$name}\" to delete in {$path}.");
        }

        unset($data['profiles'][$name]);
        $this->write($path, $data);
    }

    /**
     * Rename the set itself.
     */
    public function setSetName(string $path, string $name): void
    {
        $data = $this->read($path);
        $data['set'] = $name;
        $this->write($path, $data);
    }

    /**
     * The base config is the set's "Default" profile — this names it.
     */
    public function setDefaultProfileName(string $path, string $name): void
    {
        $data = $this->read($path);
        $data['defaultProfileName'] = $name;
        $this->write($path, $data);
    }

    public function renameProfile(string $path, string $old, string $new): void
    {
        $data = $this->read($path);

        if (! array_key_exists($old, (array) ($data['profiles'] ?? []))) {
            throw new ManifestException("No profile \"{$old}\" to rename in {$path}.");
        }

        if ($old === $new) {
            return;
        }

        if (array_key_exists($new, $data['profiles'])) {
            throw new ManifestException("A profile named \"{$new}\" already exists in {$path}.");
        }

        // Preserve declaration order while swapping the key.
        $profiles = [];
        foreach ($data['profiles'] as $key => $value) {
            $profiles[$key === $old ? $new : $key] = $value;
        }
        $data['profiles'] = $profiles;

        $this->write($path, $data);
    }

    /**
     * @return array<string,mixed>
     */
    private function read(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
            throw new ManifestException("Cannot update manifest — not readable JSON: {$path}");
        }

        return $data;
    }

    private function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Atomic replace: a crash (or Dropbox syncing mid-write) never
        // leaves a half-written config.
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $json) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new ManifestException("Cannot write manifest: {$path}");
        }

        $this->recordVersion($path, $json);
    }

    /**
     * Every save lands an immutable copy in <set>/.foundry/versions/ —
     * the complete history; config.json is just "current". Identical
     * content is not re-recorded.
     */
    private function recordVersion(string $path, string $json): void
    {
        $dir = FoundryLayout::foundryDirFor($path).'/versions';

        $versions = glob($dir.'/config-*.json') ?: [];
        sort($versions);
        $latest = end($versions);

        $hash = md5($json);
        if ($latest !== false && md5_file($latest) === $hash) {
            return;
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $name = sprintf('config-%s-%s-%s.json', FoundryLayout::stamp(), FoundryLayout::user(), substr($hash, 0, 8));
        @file_put_contents($dir.'/'.$name, $json);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function relativize(array $values, string $baseDir): array
    {
        foreach (self::PATH_KEYS as $key) {
            if (is_string($values[$key] ?? null)) {
                $values[$key] = $this->relativePath($values[$key], $baseDir);
            }
        }

        foreach (['artDirs', 'whiteDirs'] as $listKey) {
            if (is_array($values[$listKey] ?? null)) {
                $values[$listKey] = array_values(array_map(
                    fn ($dir) => is_string($dir) ? $this->relativePath($dir, $baseDir) : $dir,
                    $values[$listKey],
                ));
            }
        }

        return $values;
    }

    private function relativePath(string $path, string $baseDir): string
    {
        // Non-portable paths (or data URIs) stay exactly as given.
        return Portability::relativize($path, $baseDir) ?? $path;
    }
}

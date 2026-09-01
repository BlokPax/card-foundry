<?php

namespace App\Manifest;

final class ManifestLoader
{
    public function load(string $path): SetManifest
    {
        if (! is_file($path)) {
            throw new ManifestException("Manifest not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new ManifestException("Manifest is not valid JSON: {$path} — ".json_last_error_msg());
        }

        if (! is_string($data['set'] ?? null) || $data['set'] === '') {
            throw new ManifestException("Manifest {$path} is missing a \"set\" name.");
        }

        if (! is_array($data['base'] ?? null)) {
            throw new ManifestException("Manifest {$path} is missing its \"base\" config.");
        }

        $profiles = $data['profiles'] ?? [];
        if (! is_array($profiles)) {
            throw new ManifestException("Manifest {$path}: \"profiles\" must be an object.");
        }

        foreach ($profiles as $name => $profile) {
            if (! is_array($profile)) {
                throw new ManifestException("Manifest {$path}: profile \"{$name}\" must be an object.");
            }
        }

        $defaultName = $data['defaultProfileName'] ?? 'Default';

        return new SetManifest(
            $path,
            $data['set'],
            $data['base'],
            $profiles,
            is_string($defaultName) && trim($defaultName) !== '' ? $defaultName : 'Default',
        );
    }
}

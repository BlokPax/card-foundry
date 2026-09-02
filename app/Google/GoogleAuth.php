<?php

namespace App\Google;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Per-user Google sign-in (OAuth installed-app flow with PKCE).
 *
 * The point of user identity over a service account: the sheet's own share
 * list controls access. Card Foundry acts AS the signed-in person — an
 * org member, or an invited outside collaborator (the contract printer) —
 * so "who can read the merge data" is managed in Google's sharing UI, not
 * by who happens to have the app.
 *
 * Setup (once, in Google Cloud console): an OAuth client of type
 * "Desktop app"; put its id/secret in GOOGLE_OAUTH_CLIENT_ID /
 * GOOGLE_OAUTH_CLIENT_SECRET. (A desktop-app client secret is not
 * confidential by Google's own definition — PKCE carries the security.)
 * Until the app passes verification for the spreadsheets.readonly scope,
 * add each user — including external collaborators — as a test user on
 * the OAuth consent screen.
 *
 * Tokens live in the app's Settings store (per-install app data):
 * google.refreshToken / accessToken / expiresAt / email.
 */
final class GoogleAuth
{
    // drive.file (non-sensitive) powers the Google Picker: the app can
    // browse-and-be-granted exactly the files the user picks, nothing else.
    public const SCOPES = 'openid email https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/drive.file';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public static function configured(): bool
    {
        return (string) config('services.google.client_id') !== '';
    }

    public static function connected(): bool
    {
        return (string) Settings::get('google.refreshToken', '') !== '';
    }

    public static function email(): ?string
    {
        return self::connected() ? (Settings::get('google.email') ?: null) : null;
    }

    public static function disconnect(): void
    {
        if (($token = (string) Settings::get('google.refreshToken', '')) !== '') {
            // Best effort — a failed revoke still forgets the tokens locally.
            try {
                Http::timeout(10)->asForm()->post(self::REVOKE_URL, ['token' => $token]);
            } catch (\Throwable) {
            }
        }

        foreach (['google.refreshToken', 'google.accessToken', 'google.expiresAt', 'google.email', 'google.scopes'] as $key) {
            Settings::forget($key);
        }
    }

    /**
     * The consent URL for the loopback flow.
     */
    public function authUrl(string $redirectUri, string $state, string $verifier): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',    // we need a refresh token
            'prompt' => 'consent',         // …every time someone reconnects
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Trade the redirect's code for tokens, resolve the identity, store
     * both. Returns the signed-in email.
     */
    public function exchangeCode(string $code, string $redirectUri, string $verifier): string
    {
        $response = Http::timeout(30)->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('Google sign-in failed: '.($response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}"));
        }

        $accessToken = (string) $response->json('access_token');

        $email = (string) Http::timeout(15)->withToken($accessToken)->get(self::USERINFO_URL)->json('email');

        Settings::put('google.refreshToken', (string) $response->json('refresh_token'));
        Settings::put('google.accessToken', $accessToken);
        Settings::put('google.expiresAt', time() + (int) $response->json('expires_in', 3600));
        Settings::put('google.email', $email);
        Settings::put('google.scopes', (string) $response->json('scope', self::SCOPES));

        return $email;
    }

    /**
     * Whether the stored connection was granted a scope. Connections from
     * before scope tracking report false — reconnecting refreshes them.
     */
    public static function hasScope(string $scope): bool
    {
        return self::connected()
            && in_array($scope, explode(' ', (string) Settings::get('google.scopes', '')), true);
    }

    /**
     * A currently-valid access token for the signed-in user, refreshing
     * when needed — or null when nobody is connected.
     */
    public function accessToken(): ?string
    {
        if (! self::connected()) {
            return null;
        }

        $access = (string) Settings::get('google.accessToken', '');
        $expiresAt = (int) Settings::get('google.expiresAt', 0);

        if ($access !== '' && $expiresAt > time() + 60) {
            return $access;
        }

        $response = Http::timeout(30)->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) Settings::get('google.refreshToken'),
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException(
                'Your Google sign-in has expired or was revoked — reconnect in Preferences (⌘,).',
            );
        }

        $access = (string) $response->json('access_token');
        Settings::put('google.accessToken', $access);
        Settings::put('google.expiresAt', time() + (int) $response->json('expires_in', 3600));

        // Google occasionally rotates the refresh token too.
        if (($rotated = (string) $response->json('refresh_token', '')) !== '') {
            Settings::put('google.refreshToken', $rotated);
        }

        return $access;
    }
}

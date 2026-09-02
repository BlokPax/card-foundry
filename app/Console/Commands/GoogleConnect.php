<?php

namespace App\Console\Commands;

use App\Google\GoogleAuth;
use Illuminate\Console\Command;

/**
 * The loopback half of the installed-app OAuth flow: open the consent
 * page in the user's browser, catch the redirect on 127.0.0.1, trade the
 * code for tokens. The GUI launches this as a child process behind the
 * Preferences panel's "Connect" button; it also works standalone for
 * CLI-only users.
 */
class GoogleConnect extends Command
{
    protected $signature = 'google:connect
        {--timeout=180 : Seconds to wait for the browser sign-in}
        {--no-open : Print the sign-in URL instead of opening a browser}';

    protected $description = 'Sign in to Google so protected merge sheets can be read as you.';

    public function handle(): int
    {
        if (! GoogleAuth::configured()) {
            $this->error('Google sign-in is not configured — set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET (an OAuth "Desktop app" client).');

            return self::FAILURE;
        }

        $port = (int) config('services.google.redirect_port', 8734);
        $redirectUri = "http://127.0.0.1:{$port}/";
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));

        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

        if ($server === false) {
            $this->error("Cannot listen on 127.0.0.1:{$port} ({$error}) — is another sign-in already in progress?");

            return self::FAILURE;
        }

        $auth = new GoogleAuth;
        $url = $auth->authUrl($redirectUri, $state, $verifier);

        if ($this->option('no-open')) {
            $this->line('Open this URL to sign in:');
            $this->line($url);
        } else {
            $this->components->info('Opening Google sign-in in your browser…');
            @shell_exec(escapeshellarg((string) config('wotf.open_binary', '/usr/bin/open')).' '.escapeshellarg($url).' > /dev/null 2>&1');
            $this->line('If nothing opened, use this URL:');
            $this->line($url);
        }

        try {
            $params = $this->waitForRedirect($server, (int) $this->option('timeout'));
        } finally {
            fclose($server);
        }

        if ($params === null) {
            $this->error('Timed out waiting for the sign-in to finish.');

            return self::FAILURE;
        }

        if (($params['state'] ?? '') !== $state) {
            $this->error('Sign-in rejected: the redirect did not match this session (state mismatch).');

            return self::FAILURE;
        }

        if (isset($params['error']) || ! isset($params['code'])) {
            $this->error('Sign-in was not completed: '.($params['error'] ?? 'no code returned').'.');

            return self::FAILURE;
        }

        try {
            $email = $auth->exchangeCode($params['code'], $redirectUri, $verifier);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connected to Google as {$email}.");

        return self::SUCCESS;
    }

    /**
     * Accept one HTTP request on the loopback socket and hand back its
     * query parameters (null on timeout).
     *
     * @return array<string,string>|null
     */
    private function waitForRedirect($server, int $timeout): ?array
    {
        $deadline = time() + max(30, $timeout);

        while (time() < $deadline) {
            $read = [$server];
            $write = $except = [];

            if (@stream_select($read, $write, $except, 1) < 1) {
                continue;
            }

            $client = @stream_socket_accept($server, 1);
            if ($client === false) {
                continue;
            }

            $requestLine = (string) fgets($client, 8192);
            $params = self::parseRedirect($requestLine);

            $done = isset($params['code']) || isset($params['error']);
            $message = $done
                ? 'You\'re connected — you can close this tab and return to Card Foundry.'
                : 'Waiting for Google… you can close this tab.';

            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: close\r\n\r\n"
                .'<!doctype html><meta charset="utf-8"><title>Card Foundry</title>'
                .'<body style="font-family:-apple-system,sans-serif;display:grid;place-items:center;height:100vh;margin:0">'
                ."<p style=\"font-size:15px;color:#333\">{$message}</p>");
            fclose($client);

            // Browsers also ask for /favicon.ico — keep listening until the
            // request that actually carries the OAuth result.
            if ($done || isset($params['state'])) {
                return $params;
            }
        }

        return null;
    }

    /**
     * Query parameters of an HTTP request line ("GET /?code=…&state=… HTTP/1.1").
     *
     * @return array<string,string>
     */
    public static function parseRedirect(string $requestLine): array
    {
        if (! preg_match('/^GET\s+(\S+)/', $requestLine, $m)) {
            return [];
        }

        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $params);

        return array_map(strval(...), $params);
    }
}

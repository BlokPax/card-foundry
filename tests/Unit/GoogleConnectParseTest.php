<?php

namespace Tests\Unit;

use App\Console\Commands\GoogleConnect;
use PHPUnit\Framework\TestCase;

class GoogleConnectParseTest extends TestCase
{
    public function test_parses_the_oauth_redirect_request_line(): void
    {
        $params = GoogleConnect::parseRedirect("GET /?state=abc&code=4%2F0AbCdE&scope=email HTTP/1.1\r\n");

        $this->assertSame('abc', $params['state']);
        $this->assertSame('4/0AbCdE', $params['code']);
    }

    public function test_parses_a_denied_redirect(): void
    {
        $params = GoogleConnect::parseRedirect("GET /?error=access_denied&state=abc HTTP/1.1\r\n");

        $this->assertSame('access_denied', $params['error']);
        $this->assertArrayNotHasKey('code', $params);
    }

    public function test_ignores_favicon_noise(): void
    {
        $this->assertSame([], GoogleConnect::parseRedirect("GET /favicon.ico HTTP/1.1\r\n"));
        $this->assertSame([], GoogleConnect::parseRedirect(''));
    }
}

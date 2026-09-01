<?php

namespace Tests\Unit;

use App\InDesign\JsxPayloadBuilder;
use PHPUnit\Framework\TestCase;

class JsxPayloadBuilderTest extends TestCase
{
    private JsxPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new JsxPayloadBuilder;
    }

    public function test_injects_payload_as_global_before_jsx_source(): void
    {
        $code = $this->builder->build('alert(WOTF_PAYLOAD.card.name);', ['card' => ['name' => 'Ashbound']]);

        // The generated UXP wrapper carries the JSX (payload prefix + source) as a JSON string literal.
        $this->assertStringContainsString('WOTF_PAYLOAD', $code);
        $this->assertStringContainsString('Ashbound', $code);
        $this->assertStringContainsString('doScript', $code);
    }

    public function test_payload_prefix_comes_before_source_in_embedded_jsx(): void
    {
        $code = $this->builder->build('/*SOURCE*/', ['k' => 'v']);

        $this->assertLessThan(
            strpos($code, 'SOURCE'),
            strpos($code, 'WOTF_PAYLOAD'),
        );
    }

    public function test_escapes_quotes_and_newlines_safely(): void
    {
        $jsx = "var s = \"multi\nline\";";
        $code = $this->builder->build($jsx, ['quote' => 'He said "go"']);

        // Raw newline inside the JSX must be escaped in the embedded JSON string literal.
        $this->assertStringNotContainsString("var s = \"multi\nline\";", $code);

        // The embedded literal must round-trip back to the original JSX + payload.
        $this->assertSame(1, preg_match('/const jsx = (".*");/s', $code, $m));
        $decoded = json_decode($m[1]);
        $this->assertStringContainsString($jsx, $decoded);
        $this->assertStringContainsString('He said \"go\"', $decoded);
    }

    public function test_wrapper_returns_a_result(): void
    {
        $code = $this->builder->build('x();', []);

        $this->assertStringContainsString('return', $code);
    }
}

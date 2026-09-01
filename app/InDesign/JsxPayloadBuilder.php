<?php

namespace App\InDesign;

/**
 * Wraps an ExtendScript (.jsx) card-builder script in the UXP JavaScript the
 * indesign-uxp-server bridge executes. The bridge runs UXP JS with `app` in
 * scope; the JSX itself is handed to InDesign's classic engine via
 * app.doScript(), with the card payload injected as a WOTF_PAYLOAD global.
 */
final class JsxPayloadBuilder
{
    public function build(string $jsxSource, array $payload): string
    {
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $jsx = "var WOTF_PAYLOAD = {$payloadJson};\n".$jsxSource;

        // Embed the full JSX as a JS string literal inside the UXP wrapper.
        $jsxLiteral = json_encode($jsx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<JS
        const { ScriptLanguage } = require('indesign');
        const jsx = {$jsxLiteral};
        const result = app.doScript(jsx, ScriptLanguage.JAVASCRIPT);
        return result === undefined ? 'ok' : result;
        JS;
    }
}

# Vendored indesign-uxp-server

- Upstream: https://github.com/theloniuser/indesign-uxp-server
- Pinned commit: 67cfe9f387c7b7af572f43c2629bbb2cd3d05b74 (vendored 2026-08-31)
- Contents: `bridge/` (HTTP↔WebSocket relay, run with node) and `plugin/` (the UXP
  plugin to load into InDesign once, via Adobe UXP Developer Tool). The rest of the
  upstream repo (the MCP server) is not used by this app.

## Local patches
- `bridge/server.js`: `TIMEOUT_MS` now reads the `BRIDGE_TIMEOUT_MS` env var
  (upstream hard-codes 30s; card builds can take longer). The supervisor passes
  it from `config('wotf.card_timeout')`.

## Updating
Re-clone upstream, copy `bridge/` and `plugin/` over this directory, re-apply the
patch above, update the pinned commit here, and re-run `php artisan test`.

## Bundled plugin package
- `plugin/com.ads.indesign-bridge_ID.ccx` — the same UXP plugin packaged for
  install via Creative Cloud (`php artisan bridge:install-plugin`, or the app's
  "Install InDesign plugin" button, or double-click). MUST be rebuilt whenever
  `plugin/` changes — the plugin and `bridge/server.js` speak a private
  protocol and must stay in version lockstep.

## Runtime bundle
- `bridge/server.bundle.js` — esbuild single-file bundle of server.js + deps
  (express/uuid/ws). The app prefers it, and packaged builds REQUIRE it (the
  packager strips nested node_modules). Regenerate after any bridge change:
  `cd bridge && npx esbuild server.js --bundle --platform=node --target=node18
  --external:bufferutil --external:utf-8-validate --outfile=server.bundle.js`

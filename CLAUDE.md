# CLAUDE.md

WordPress plugin: connect a Starknet wallet, tokenize posts as `mip-erc721` IP assets.

## Commands

```
npm install
npm run build      # esbuild bundle assets/src -> assets/dist (commit the output)
npm test           # vitest
phpunit -c phpunit.xml.dist   # requires WP_TESTS_DIR (WP core test lib)
```

## Architecture

- PHP holds the Medialane API key (`wp_options`) and forwards allowlisted calls
  to `medialane-backend` via `includes/class-rest-proxy.php` — the key never
  reaches the browser.
- JS (`assets/src`, bundled to `assets/dist`) handles wallet connect/signing
  (`starknet` + `get-starknet-core`) and calls the WP REST proxy, never the
  backend directly.
- One `mip-erc721` collection per site, auto-created on first wallet connect
  (`assets/src/settings.js`), resolved via `GET /v1/collections?owner=` and
  persisted to `wp_options` through `/settings/collection`.
- Post state (`none|minting|minted|error`) lives in post meta, written only by
  PHP (`includes/class-post-meta.php`) via the `/posts/{id}/minting`,
  `/posts/{id}/minted`, and `/posts/{id}/error` REST routes — JS never writes
  post meta directly.
- The mint sequence itself (upload metadata → create mint intent → execute →
  mark minted) is shared between the per-post metabox and the Posts-list bulk
  action via `assets/src/mint-flow.js`'s `tokenizeOne()` — both callers only
  connect the wallet and gather post data differently, then delegate.
- All admin screens that enqueue plugin JS share one localized global name,
  `medialaneData` (see `wp_localize_script` calls in `class-settings.php`,
  `class-metabox.php`, `class-bulk-action.php`) — safe because each only
  enqueues on its own mutually exclusive admin screen (settings page vs. post
  editor vs. Posts list).

## Common pitfalls

- Never commit a raw `fetch` to the `medialane-backend` URL from JS — always go
  through `/wp-json/medialane/v1/*` so the API key stays server-side.
- `assets/dist/*.js` is checked in (no build step on activation) — run
  `npm run build` and commit the output after any `assets/src` change.

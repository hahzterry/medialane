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

- PHP holds the Medialane API key (`Settings::get_api_key()` — a
  `MEDIALANE_API_KEY` `wp-config.php` constant if defined, else `wp_options`)
  and forwards allowlisted calls to `medialane-backend` via
  `includes/class-rest-proxy.php` — the key never reaches the browser. Every
  backend-forwarding route binds its target path via a closure argument to
  `forward_json()`, never a client-suppliable REST param.
- JS (`assets/src`, bundled to `assets/dist`) handles wallet connect/signing
  (`starknet` + `get-starknet-core`) and calls the WP REST proxy, never the
  backend directly. `get-starknet-core` v4's `StarknetWindowObject` only
  exposes a raw `request()` method, not an `.account` — `wallet.js`'s
  `connectWallet()` bridges it into a real signing `Account` via starknet.js's
  `WalletAccount.connect()`.
- Minting is gas-sponsored via `medialane-backend`'s paymaster
  (`/v1/paymaster/invoke/{build,execute}`, proxied at
  `/medialane/v1/paymaster/invoke/{build,execute}`): the caller still signs
  with their own wallet (`wallet.js`'s `signTypedData()`, wrapping
  `WalletAccount.signMessage`), but the network fee is covered by Medialane's
  paymaster, not the signer.
- One `mip-erc721` collection per site, auto-created on first wallet connect
  (`assets/src/settings.js`), resolved via `GET /v1/collections?owner=` and
  persisted to `wp_options` through `/settings/collection`.
- Post state (`none|minting|minted|error`) lives in post meta, written only by
  PHP (`includes/class-post-meta.php`) via the `/posts/{id}/minting`,
  `/posts/{id}/minted`, and `/posts/{id}/error` REST routes — JS never writes
  post meta directly.
- The mint sequence is split across two `assets/src/mint-flow.js` functions,
  shared by the per-post metabox and the Posts-list bulk action:
  `prepareMint()` uploads metadata and builds one post's mint calls (no chain
  write yet), and `executeMintBatch()` takes one or more `prepareMint()`
  results, signs and executes their combined calls as **one** sponsored
  transaction, then marks every post in the batch minted with the shared tx
  hash (or errored, together, if the transaction fails — it's one on-chain
  transaction, so the batch succeeds or fails as a unit). The bulk action caps
  batches at 25 posts, chunking larger selections into sequential batches.
- Tokenize routes are gated on a dedicated `medialane_tokenize_posts`
  capability (`Settings::CAP_TOKENIZE`), not `manage_options` — granted to
  Administrator on activation, optionally to Editor via a settings-page
  toggle. `RestProxy::check_tokenize_permission()` additionally requires
  `edit_post` on any route that targets a specific post id. Settings/collection
  management routes stay `manage_options`-only via
  `check_admin_permission()`.
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
- `phpunit -c phpunit.xml.dist` needs `WP_TESTS_DIR` pointed at a real WP core
  test-library checkout (`bin/install-wp-tests.sh` sets one up) — WP core's
  own `wp-tests-config-sample.php` hardcodes `ABSPATH` to
  `dirname(__FILE__) . '/src/'`, so WP core has to live inside
  `$WP_TESTS_DIR/src`, not a separate directory.
- A REST `permission_callback` returning bare `false` becomes **403**, not
  401, for any logged-in user — WordPress's `rest_authorization_required_code()`
  reserves 401 for anonymous requests only. Easy to get backwards in tests.

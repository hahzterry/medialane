# CLAUDE.md

WordPress plugin. Connects a Starknet wallet and tokenizes posts as `mip-erc721` IP assets.

## Commands

```
npm install
npm run build      # esbuild bundle assets/src -> assets/dist (commit the output)
npm test           # vitest
phpunit -c phpunit.xml.dist   # requires WP_TESTS_DIR (WP core test lib)
```

## Architecture

- PHP holds the Medialane API key. `Settings::get_api_key()` reads a
  `MEDIALANE_API_KEY` `wp-config.php` constant if defined, or falls back to
  `wp_options`. `includes/class-rest-proxy.php` forwards allowlisted calls to
  `medialane-backend` with that key attached; the key stays on the server.
  Every backend-forwarding route binds its target path via a closure argument
  to `forward_json()`, so the path is fixed in code rather than taken from a
  client-suppliable REST param.
- JS (`assets/src`, bundled to `assets/dist`) handles wallet connect and
  signing (`starknet` + `get-starknet-core`) and calls the WP REST proxy. It
  talks to the backend only through that proxy. `get-starknet-core` v4's
  `StarknetWindowObject` exposes a raw `request()` method rather than an
  `.account`, so `wallet.js`'s `connectWallet()` bridges it into a signing
  `Account` via starknet.js's `WalletAccount.connect()`.
- Minting goes through `medialane-backend`'s paymaster
  (`/v1/paymaster/invoke/{build,execute}`, proxied at
  `/medialane/v1/paymaster/invoke/{build,execute}`). The caller signs with
  their own wallet, using `wallet.js`'s `signTypedData()`, which wraps
  `WalletAccount.signMessage`. The paymaster covers the network fee.
- One `mip-erc721` collection exists per site, created on first wallet
  connect (`assets/src/settings.js`), resolved via `GET /v1/collections?owner=`
  and persisted to `wp_options` through `/settings/collection`.
- Post state (`none|minting|minted|error`) lives in post meta, written only
  by PHP (`includes/class-post-meta.php`) through the `/posts/{id}/minting`,
  `/posts/{id}/minted`, and `/posts/{id}/error` REST routes. JS reads this
  state but writes it only through those routes.
- The mint sequence is split across two functions in `assets/src/mint-flow.js`,
  shared by the per-post metabox and the Posts-list bulk action.
  `prepareMint()` uploads metadata and builds one post's mint calls, without
  touching the chain or post meta. `executeMintBatch()` takes one or more
  `prepareMint()` results, signs and executes their combined calls as a
  single transaction, then marks every post in the batch minted with the
  shared tx hash. If the transaction fails, every post in that batch is
  marked errored, since one on-chain transaction succeeds or fails as a
  unit. The bulk action caps batches at 25 posts, sending larger selections
  as sequential batches.
- `prepareMint()` mints to a wallet tied to the post author's registered
  WordPress email, not to the connected wallet's own address. It generates
  a one-time interim keypair (`wallet.js`'s `generateInterimKeypair()`,
  never persisted), uses it to sign that wallet's deployment typed data
  (`signDeploymentWithInterimKey()`), and hands the signed deployment to
  `medialane-backend`'s business-provisioning endpoint
  (`/v1/business/provisioning`, proxied at `/medialane/v1/business/provisioning`)
  along with `recipientScheme: "email"` and the author's address. The
  backend deploys the wallet on first use and reuses it on later posts from
  the same author. The mint intent's `owner` stays the connected wallet's
  address, since that's what the chain checks for collection ownership;
  only `recipient` becomes the author's provisioned wallet.
- Tokenize routes check a dedicated `medialane_tokenize_posts` capability
  (`Settings::CAP_TOKENIZE`) rather than `manage_options`. It's granted to
  Administrator on activation, and can be granted to Editor through a
  settings-page toggle. `RestProxy::check_tokenize_permission()` also
  requires `edit_post` on any route that targets a specific post id.
  Settings and collection-management routes check `manage_options` through
  `check_admin_permission()`.
- Every admin screen that enqueues plugin JS localizes the same global name,
  `medialaneData` (see the `wp_localize_script` calls in `class-settings.php`,
  `class-metabox.php`, and `class-bulk-action.php`). This works because each
  screen enqueues its own script on its own admin page: settings page, post
  editor, or Posts list.
- After a batch mint confirms, `mintedTokenIdsFromReceipt()` in `wallet.js`
  reads each entry's real on-chain token id off the confirmed receipt's
  `Transfer(from=0)` events, in call order, and `executeMintBatch()` saves
  one per post. Post meta held an empty token id before this existed.
- `includes/class-asset-badge.php` appends a public "IP Protected &
  Tokenized" card to a minted post's own single-post page (`the_content`
  filter, gated to `is_singular('post') && in_the_loop() && is_main_query()`),
  linking to that token's asset page on `medialane.io`. It reads straight
  from post meta (`PostMeta::get_contract()`/`get_token_id()`/`get_license()`)
  — no new backend call, since everything it needs was already saved at mint
  time.
- `BulkAction::render_pending_notice()` shows an admin notice on the Posts
  list screen when published posts exist that haven't been tokenized yet,
  pointing at the same bulk action. It shares `get_pending_posts()` with
  `enqueue()` rather than querying twice.

## Common pitfalls

- Route a raw `fetch` to the `medialane-backend` URL from JS through
  `/wp-json/medialane/v1/*` instead, so the API key stays server-side.
- `assets/dist/*.js` is checked in. There's no build step on activation.
  Run `npm run build` and commit the output after any `assets/src` change.
- `phpunit -c phpunit.xml.dist` needs `WP_TESTS_DIR` pointed at a real WP
  core test-library checkout. `bin/install-wp-tests.sh` sets one up. WP
  core's own `wp-tests-config-sample.php` hardcodes `ABSPATH` to
  `dirname(__FILE__) . '/src/'`, so WP core has to live inside
  `$WP_TESTS_DIR/src`.
- A REST `permission_callback` returning bare `false` becomes 403 for any
  logged-in user. WordPress's `rest_authorization_required_code()` reserves
  401 for anonymous requests only. Easy to get backwards in tests.

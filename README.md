# Medialane for WordPress

Tokenize WordPress posts as on-chain IP assets on [Medialane](https://medialane.io), a
Starknet-based protocol for programmable intellectual property. Connect a Starknet
wallet, attach license terms, and mint any post. Your keys stay in your own wallet
the whole time.

Status: the JS suite (`npm test`) and the PHP suite (`phpunit`) both pass, verified
against a live WP core test install. The plugin has not yet been activated on a live
WordPress site with a real wallet extension. See [Development](#development) before
relying on it in production.

## What it does

- Connect a Starknet wallet (Ready, Braavos) from the WordPress admin.
- On first connect, auto-creates one `mip-erc721` collection for the site.
- From any post's editor, or in bulk from the Posts list, mint the post as an
  IP asset: title, body/excerpt, and featured image are pinned to IPFS, and a
  license (All Rights Reserved / CC BY-SA / custom terms) is attached in the
  token metadata.
- Tokenizing several posts at once from the Posts list mints them together.
  One signature covers up to 25 posts. Larger selections are chunked into
  sequential batches of 25.
- Mint status (`not minted` / `minting` / `minted` / `error`) is tracked per
  post, along with the transaction hash and a block-explorer link once confirmed.
- Administrators can tokenize by default. Turning on **Let Editors tokenize
  their own posts** (Settings → Medialane) grants a dedicated
  `medialane_tokenize_posts` capability to the Editor role, scoped to posts
  they can already edit.

## How it works

Every mint is signed by your own connected wallet in the browser. The plugin
itself has no way to sign on your behalf.

- **PHP** (`includes/`) stores your Medialane API key server-side, in
  `wp_options` or in a `MEDIALANE_API_KEY` constant in `wp-config.php`, and
  exposes a small WordPress REST proxy (`/wp-json/medialane/v1/*`) that
  forwards allowlisted requests to the Medialane backend with that key
  attached. The key stays on the server. Each route checks the
  `medialane_tokenize_posts` capability for tokenize actions, `manage_options`
  for settings, and an `edit_post` check on any route that targets a specific
  post.
- **JavaScript** (`assets/src/`, bundled to `assets/dist/`) runs in the admin
  UI. It connects the wallet, calls the WordPress proxy to upload metadata and
  build a mint transaction, then asks the wallet to sign it. It talks to the
  proxy only, never to the Medialane backend directly.
- **Post meta** (`_medialane_status`, `_medialane_tx_hash`, `_medialane_contract`,
  `_medialane_token_id`, `_medialane_license`) is written only by PHP. This
  keeps mint state tied to a value the server actually recorded.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Starknet wallet browser extension (Ready or Braavos)
- A Medialane API key ([portal.medialane.io/account](https://portal.medialane.io/account))

## Installation

1. Download or clone this repository.
2. `npm install && npm run build` to produce `assets/dist/`.
3. Zip the plugin directory and upload it via **Plugins → Add New → Upload Plugin**,
   or place the directory in `wp-content/plugins/`.
4. Activate the plugin.
5. Go to **Settings → Medialane**, paste your API key, and click **Connect Wallet**.
6. Open any post and use the **Medialane** panel in the editor sidebar to tokenize it.

## Development

```bash
npm install
npm run build      # bundles assets/src -> assets/dist with esbuild
npm test           # runs the JS test suite (vitest)
phpunit -c phpunit.xml.dist   # runs the PHP test suite (requires the WP core test library)
```

To run `phpunit` locally, `WP_TESTS_DIR` needs to point at a real WordPress core
test-library checkout. `bin/install-wp-tests.sh <db-name> <db-user> <db-pass>
[db-host] [wp-version]` sets one up. It needs `svn` and a reachable MySQL server.
`.github/workflows/ci.yml` runs the JS suite and the PHP suite on every push and PR.

This code has been checked against the real routes it depends on in
[`medialane-backend`](https://github.com/medialane-io/medialane-backend), and
against the equivalent production mint flow in
[`medialane-starknet`](https://github.com/medialane-io/medialane-starknet). Both
`npm test` and `phpunit` pass against a real WP core test install. What remains
unverified is a live activation: the actual wallet-extension connect and sign
flow in a real browser hasn't been exercised end to end. If you set one up,
please open issues for anything that doesn't work as documented.

See [`CLAUDE.md`](CLAUDE.md) for a closer look at the internal architecture.

## Security

- Your Medialane API key is stored in `wp_options` and read only server-side,
  by the PHP REST proxy.
- Your wallet's private key stays in your browser. The plugin only ever
  requests a signature for a transaction it shows you first.
- All uploads and mint transactions go through your own site's REST proxy,
  which forwards to the Medialane backend under your own API key.

Found a security issue? Please report it privately rather than opening a
public issue. See the Medialane project for current contact details.

## License

GPL-2.0-or-later. See [`readme.txt`](readme.txt).

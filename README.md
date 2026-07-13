# Medialane for WordPress

Tokenize WordPress posts as on-chain IP assets on [Medialane](https://medialane.io), a
Starknet-based protocol for programmable intellectual property. Connect a Starknet
wallet, attach license terms, and mint any post — no custody, no server-side keys,
no gas fees paid by Medialane.

Status: **early / unreleased**. This plugin has not yet been run against a live
WordPress installation — see [Development](#development) before relying on it.

## What it does

- Connect a Starknet wallet (Ready, Braavos) from the WordPress admin.
- On first connect, auto-creates one `mip-erc721` collection for the site.
- From any post's editor, or in bulk from the Posts list, mint the post as an
  IP asset: title, body/excerpt, and featured image are pinned to IPFS, and a
  license (All Rights Reserved / CC BY-SA / custom terms) is attached in the
  token metadata.
- Mint status (`not minted` / `minting` / `minted` / `error`) is tracked per
  post, with the transaction hash and a block-explorer link once confirmed.

## How it works

The plugin never holds a private key and never signs a transaction on your
behalf — every mint is signed by your own connected wallet in the browser.

- **PHP** (`includes/`) stores your Medialane API key server-side (`wp_options`)
  and exposes a small WordPress REST proxy (`/wp-json/medialane/v1/*`) that
  forwards allowlisted requests to the Medialane backend with that key attached.
  The key never reaches the browser.
- **JavaScript** (`assets/src/`, bundled to `assets/dist/`) runs in the admin
  UI: it connects the wallet, calls the WordPress proxy (never the Medialane
  backend directly) to upload metadata and build a mint transaction, then asks
  the wallet to sign and submit it.
- **Post meta** (`_medialane_status`, `_medialane_tx_hash`, `_medialane_contract`,
  `_medialane_token_id`, `_medialane_license`) is written only by PHP, never
  directly by JavaScript, so mint state always reflects a value the server
  actually recorded.

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

Static review has checked this code against the real routes it depends on in
[`medialane-backend`](https://github.com/medialane-io/medialane-backend) and
against the equivalent production mint flow in
[`medialane-starknet`](https://github.com/medialane-io/medialane-starknet), but
it has **not yet been executed** — `npm test` and `phpunit` have not been run,
and the plugin has not been activated on a real WordPress site. If you're
setting up a dev environment to test it, please open issues for anything that
doesn't work as documented.

See [`CLAUDE.md`](CLAUDE.md) for a closer look at the internal architecture.

## Security

- Your Medialane API key is stored in `wp_options` and only ever read
  server-side by the PHP REST proxy.
- Your wallet's private key never leaves your browser; the plugin only ever
  requests a signature for a transaction it shows you first.
- All uploads and mint transactions go through your own site's REST proxy,
  which forwards to the Medialane backend under your own API key — nothing is
  routed through a third party.

Found a security issue? Please report it privately rather than opening a
public issue — see the Medialane project for current contact details.

## License

GPL-2.0-or-later — see [`readme.txt`](readme.txt).

=== Medialane ===
Contributors: medialane
Tags: nft, ip, wallet, starknet, ipfs
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tokenize WordPress posts as Medialane IP assets (mip-erc721). Connect a Starknet wallet and keep your keys with you.

== Description ==

Connect a Starknet wallet (Ready or Braavos), attach license terms, and mint any post into an on-chain IP asset. Post content and featured image are pinned to IPFS. Metadata is written on-chain via a per-site mip-erc721 collection, created automatically the first time you connect.

Administrators can tokenize posts by default. Turn on **Let Editors tokenize their own posts** in Settings → Medialane to let your Editors mint posts they can edit, scoped to their own access.

Tokenizing several posts at once from the Posts list mints them together. One signature covers up to 25 posts at a time.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin.
2. Activate.
3. Go to Settings → Medialane, paste a Medialane API key (from portal.medialane.io/account), and click Connect Wallet.
4. Open any post and use the Medialane metabox to tokenize it.

== Frequently Asked Questions ==

= Does Medialane ever hold my wallet's private key? =

No. The plugin only asks your connected wallet extension (Ready or Braavos) to sign a transaction it shows you first. Your key stays in your browser.

= Can my Editors tokenize posts without being an Administrator? =

Yes, once you turn on **Let Editors tokenize their own posts** in Settings → Medialane. An Editor with that setting on can tokenize posts they have edit access to.

= Where is my Medialane API key stored? =

In your site's `wp_options` table, read only by the server-side REST proxy. If you'd rather keep it out of the database, define a `MEDIALANE_API_KEY` constant in `wp-config.php`. When set, it overrides the database value.

== Changelog ==

= 0.2.0 =
* Bulk-tokenizing from the Posts list now signs one transaction for the whole batch, up to 25 posts per batch.
* Added a `medialane_tokenize_posts` capability, grantable to the Editor role.
* Added a `MEDIALANE_API_KEY` `wp-config.php` constant as an alternative to storing the key in the database.

= 0.1.0 =
* Initial release.

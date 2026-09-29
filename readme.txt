=== Tokenize, Protect & License Your Content ===
Contributors: medialane
Tags: ip, licensing, nft, wallet, starknet
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your posts into protected, licensed IP assets with Medialane. Prove authorship, set clear licensing terms, and keep control of how your content is used.

== Description ==

Every tokenized post gets a permanent, public record of who wrote it and when, and a license that says what other people are allowed to do with it. Readers can verify both for themselves, independent of this site staying online.

Choose a license from Creative Commons presets or reserve all rights, along with a policy on AI training use. These terms are set automatically when a post is tokenized and are readable by other platforms, not just this one.

Only the site admin connects a wallet, once, in Settings. Authors and editors are identified by their WordPress email and never need a wallet themselves.

Posts can be organized into separate collections by section, author, or archive, with categories routed to a collection automatically. Tokenizing several posts at once from the Posts list signs one transaction for the whole batch, up to 25 posts at a time.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin.
2. Activate.
3. Go to Settings → Tokenize & Protect, paste an API key (from portal.medialane.io/account), and save it.
4. Connect the wallet that will manage tokenization for this site.
5. Open any post and use the Tokenize & Protect metabox, or select several posts from the Posts list and use the bulk action.

== Frequently Asked Questions ==

= Does this plugin ever hold my wallet's private key? =

No. The plugin only asks your connected wallet extension (Ready or Braavos) to sign a transaction it shows you first. Your key stays in your browser.

= Do my authors need a wallet? =

No. Authors are identified by their registered WordPress email. A wallet tied to that email is set up automatically the first time one of their posts is tokenized.

= Where is my API key stored? =

In your site's `wp_options` table, read only by the server-side REST proxy. If you'd rather keep it out of the database, define a `TOKENIZE_CONTENT_API_KEY` constant in `wp-config.php`. When set, it overrides the database value.

== Changelog ==

= 0.2.0 =
* Programmable licensing: choose from Creative Commons presets or reserve all rights, with an AI training policy, applied on-chain at tokenization.
* Tokenized assets go to a wallet tied to the post author's WordPress email, not the connected admin wallet.
* Support for multiple collections, with WordPress categories routed to a specific collection.
* A public badge on tokenized posts linking to their on-chain record.
* Bulk-tokenizing from the Posts list signs one transaction for the whole batch, up to 25 posts per batch.

= 0.1.0 =
* Initial release.

# Medialane WordPress Plugin Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the five enhancements identified during the 2026-09-28 security/quality audit of `medialane-wordpress`: CI, an API-key config override, per-editor tokenize permissions, gasless (sponsored) minting, and batched bulk-action minting.

**Architecture:** Five independent task groups, ordered so each later group can lean on an earlier one (CI first so every subsequent group is verified in CI; the capability system before the two mint-flow changes that call `check_permission()`; sponsored minting before batching, since batching reuses its build/execute split). Each group produces working, independently-shippable software — merge them one at a time, don't wait for all five.

**Tech Stack:** WordPress REST API (PHP 7.4+), `starknet` v10 / `get-starknet-core` v4 (JS, esbuild-bundled), vitest, phpunit + `WP_UnitTestCase`, GitHub Actions.

**Spec:** This document — there is no separate spec doc; each task group's rationale is inline, drawn from the audit findings in this session's conversation history.

## Global Constraints

- PHP: `Requires PHP: 7.4` (readme.txt) — no PHP 8-only syntax (union types, `readonly` props, etc.).
- JS: `assets/dist/*.js` must be rebuilt (`npm run build`) and committed after every `assets/src` change — this plugin has no build-on-activation step (see `CLAUDE.md`).
- Every REST route stays under the `medialane/v1` namespace and keeps the `check_permission`-style `permission_callback` pattern already established in `includes/class-rest-proxy.php`.
- Every new backend-forwarding route must bind its backend path via a closure argument, never a client-suppliable REST param (the exact bug fixed earlier this session in `forward_json`).
- Follow existing test conventions exactly: PHP tests extend `WP_UnitTestCase` in `tests/php/`; JS tests use `vitest` in `tests/js/`, importing from `assets/src/`.

---

## File Structure

| File | Change |
|---|---|
| `.github/workflows/ci.yml` | New — runs JS build+test and PHP phpunit on every push/PR |
| `bin/install-wp-tests.sh` | New — standard WP core test-library installer, used by CI |
| `includes/class-settings.php` | Modify `get_api_key()` to prefer a `MEDIALANE_API_KEY` constant; add `medialane_tokenize_posts` capability to the Administrator role on activation |
| `medialane-wordpress.php` | Add `register_activation_hook` wiring the new capability grant |
| `includes/class-rest-proxy.php` | Replace the flat `manage_options` check with a capability- and post-ownership-aware one for tokenize-shaped routes; add two new forwarding routes for sponsored mint |
| `includes/class-post-meta.php` | No change (already fine) |
| `assets/src/api.js` | Add `buildSponsoredInvoke`/`executeSponsoredInvoke` calls |
| `assets/src/wallet.js` | Add `signTypedData(account, typedData)` helper on top of `WalletAccount.signMessage` |
| `assets/src/mint-flow.js` | Split `tokenizeOne` into `prepareMint` (upload + build calls, no chain write) and `executeMintBatch` (sign + sponsor + execute + mark, works for 1..N posts) |
| `assets/src/metabox.js` | Update to call `prepareMint` + `executeMintBatch([one entry])` |
| `assets/src/bulk-action.js` | Update to call `prepareMint` for every selected post, then one `executeMintBatch` call for all of them |
| `tests/php/test-settings.php` | New — covers the API-key override and the capability grant |
| `tests/php/test-rest-proxy.php` | Extend — covers the new permission logic and the two new routes |
| `tests/js/mint-flow.test.js` | Extend — covers `prepareMint`/`executeMintBatch` |
| `tests/js/wallet.test.js` | Extend — covers `signTypedData` |

---

## Task Group A: Continuous Integration

**Why:** The audit found `assets/dist/*.js` had never been committed and `wallet.js` had an import that never matched any published version of its dependency — both would have been caught immediately by CI running the existing `npm run build` / `npm test` / `phpunit` on every push. Nothing here is new test-writing; it's wiring up what already exists to run automatically.

### Task A1: WP core test-library installer script

**Files:**
- Create: `bin/install-wp-tests.sh`

**Interfaces:**
- Produces: a shell script `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]` that populates `$WP_TESTS_DIR` (`/tmp/wordpress-tests-lib` by default) and a WP core checkout, matching what `tests/php/bootstrap.php` already expects via the `WP_TESTS_DIR` env var.

- [ ] **Step 1: Write the script**

```bash
#!/usr/bin/env bash
set -eo pipefail

DB_NAME=${1-medialane_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	curl -s "$1" > "$2"
}

if [ "$WP_VERSION" = "latest" ]; then
	WP_TESTS_TAG="trunk"
else
	WP_TESTS_TAG="tags/$WP_VERSION"
fi

mkdir -p "$WP_TESTS_DIR"
svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"

mkdir -p "$WP_CORE_DIR"
svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/src/" "$WP_CORE_DIR"

mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x bin/install-wp-tests.sh`

- [ ] **Step 3: Commit**

```bash
git add bin/install-wp-tests.sh
git commit -m "chore: add the standard WP core test-library installer for CI"
```

### Task A2: GitHub Actions workflow

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `bin/install-wp-tests.sh` from Task A1; `npm run build`, `npm test`, `phpunit -c phpunit.xml.dist` — all pre-existing scripts.

- [ ] **Step 1: Write the workflow**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  js:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - run: npm run build
      - run: npm test

  php:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: medialane_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "7.4"
          tools: phpunit:9
      - run: sudo apt-get update && sudo apt-get install -y subversion
      - run: bash bin/install-wp-tests.sh medialane_test root root 127.0.0.1 latest
      - env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib
        run: phpunit -c phpunit.xml.dist
```

- [ ] **Step 2: Verify locally as much as possible**

Run: `node build/esbuild.config.mjs && npm test` — this is the subset Task A2 can verify without a live GitHub Actions runner or a local WP test DB; the `php` job itself can only be confirmed once pushed (no PHP/MySQL runtime is assumed to exist on every contributor's machine, which is the whole reason this job exists).

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run npm build/test and phpunit on every push and PR"
```

- [ ] **Step 4: Push and confirm the workflow goes green in the GitHub Actions tab**

This step has no local equivalent — it's the first real end-to-end proof the `php` job works at all.

---

## Task Group B: `wp-config.php` constant override for the API key

**Why:** `Settings::get_api_key()` only ever reads `wp_options`, which stores the key in the database in plaintext. Security-conscious ops teams commonly prefer secrets to live in `wp-config.php` (outside version-controlled DB exports/backups) via a defined constant, following the same pattern WordPress core itself uses for `DB_PASSWORD` etc. This is additive — sites that don't define the constant keep working exactly as before.

### Task B1: Constant override with a DB fallback

**Files:**
- Modify: `includes/class-settings.php:58-60` (`get_api_key()`)
- Test: `tests/php/test-settings.php` (new)

**Interfaces:**
- Produces: `Settings::get_api_key(): string` — unchanged signature, new precedence (constant wins over option).

- [ ] **Step 1: Write the failing test**

```php
<?php

use Medialane\Settings;

class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Settings::OPTION_API_KEY );
		parent::tear_down();
	}

	public function test_get_api_key_reads_the_option_when_no_constant_is_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		$this->assertSame( 'from-the-database', Settings::get_api_key() );
	}

	public function test_get_api_key_prefers_the_constant_when_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		if ( ! defined( 'MEDIALANE_API_KEY' ) ) {
			define( 'MEDIALANE_API_KEY', 'from-wp-config' );
		}
		$this->assertSame( 'from-wp-config', Settings::get_api_key() );
	}
}
```

- [ ] **Step 2: Run test to verify the second case fails**

Run: `phpunit -c phpunit.xml.dist --filter test_get_api_key_prefers_the_constant_when_defined`
Expected: FAIL — `get_api_key()` currently always returns the option value, so it returns `'from-the-database'` instead of `'from-wp-config'`.

- [ ] **Step 3: Implement**

```php
public static function get_api_key(): string {
	if ( defined( 'MEDIALANE_API_KEY' ) && MEDIALANE_API_KEY ) {
		return (string) MEDIALANE_API_KEY;
	}
	return (string) get_option( self::OPTION_API_KEY, '' );
}
```

- [ ] **Step 4: Run both tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Settings`
Expected: PASS (2 tests)

- [ ] **Step 5: Note the option in the settings page so admins discover it**

In `includes/class-settings.php`'s `render_settings_page()`, right after the existing "Generate an API key..." paragraph, add:

```php
<?php if ( defined( 'MEDIALANE_API_KEY' ) && MEDIALANE_API_KEY ) : ?>
	<p><em><?php esc_html_e( 'Set from wp-config.php — the field below is ignored while the MEDIALANE_API_KEY constant is defined.', 'medialane' ); ?></em></p>
<?php endif; ?>
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-settings.php tests/php/test-settings.php
git commit -m "feat: allow the API key to be set via a MEDIALANE_API_KEY wp-config.php constant"
```

---

## Task Group C: Per-editor tokenize capability

**Why:** Every REST route today requires `manage_options` (Administrator only). A magazine with multiple editors/authors would want each of them able to tokenize their own posts without full site-admin rights. WordPress's own capability system is the standard extension point for this.

### Task C1: Register and grant the capability on activation

**Files:**
- Modify: `medialane-wordpress.php` (add `register_activation_hook`)
- Modify: `includes/class-settings.php` (add `grant_default_capability()`)
- Test: `tests/php/test-settings.php` (extend)

**Interfaces:**
- Produces: `Settings::CAP_TOKENIZE = 'medialane_tokenize_posts'`; `Settings::grant_default_capability(): void` — idempotent, adds the cap to the `administrator` role only (existing Editors/Authors are opted in explicitly per-site via a filter, Task C2).

- [ ] **Step 1: Write the failing test**

```php
public function test_grant_default_capability_adds_it_to_administrator() {
	$admin_role = get_role( 'administrator' );
	$admin_role->remove_cap( Settings::CAP_TOKENIZE );
	$this->assertFalse( $admin_role->has_cap( Settings::CAP_TOKENIZE ) );

	Settings::grant_default_capability();

	$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
}

public function test_grant_default_capability_is_idempotent() {
	Settings::grant_default_capability();
	Settings::grant_default_capability();
	$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `phpunit -c phpunit.xml.dist --filter test_grant_default_capability_adds_it_to_administrator`
Expected: FAIL with an undefined-method error (`grant_default_capability` doesn't exist yet).

- [ ] **Step 3: Implement**

In `includes/class-settings.php`, add near the other `const` declarations:

```php
const CAP_TOKENIZE = 'medialane_tokenize_posts';
```

Add a new method:

```php
public static function grant_default_capability() {
	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( self::CAP_TOKENIZE ) ) {
		$role->add_cap( self::CAP_TOKENIZE );
	}
}
```

In `medialane-wordpress.php`, after the existing `require_once` block:

```php
register_activation_hook( __FILE__, array( 'Medialane\\Settings', 'grant_default_capability' ) );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Settings`
Expected: PASS (4 tests total in this file so far)

- [ ] **Step 5: Commit**

```bash
git add medialane-wordpress.php includes/class-settings.php tests/php/test-settings.php
git commit -m "feat: register a medialane_tokenize_posts capability, granted to Administrator on activation"
```

### Task C2: Let site owners grant the capability to other roles

**Files:**
- Modify: `includes/class-settings.php` (`render_settings_page()`)
- Test: `tests/php/test-settings.php` (extend)

**Interfaces:**
- Produces: `Settings::save_editor_access( bool $enabled ): void` and `Settings::editor_access_enabled(): bool`, backed by a new option `OPTION_EDITOR_ACCESS = 'medialane_editor_access'`. When enabled, the `editor` role also gets `CAP_TOKENIZE`; when disabled, it's removed (Administrator always keeps it regardless).

- [ ] **Step 1: Write the failing test**

```php
public function test_save_editor_access_grants_and_revokes_the_editor_role() {
	Settings::save_editor_access( true );
	$this->assertTrue( get_role( 'editor' )->has_cap( Settings::CAP_TOKENIZE ) );
	$this->assertTrue( Settings::editor_access_enabled() );

	Settings::save_editor_access( false );
	$this->assertFalse( get_role( 'editor' )->has_cap( Settings::CAP_TOKENIZE ) );
	$this->assertFalse( Settings::editor_access_enabled() );
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `phpunit -c phpunit.xml.dist --filter test_save_editor_access_grants_and_revokes_the_editor_role`
Expected: FAIL — `save_editor_access`/`editor_access_enabled` don't exist yet.

- [ ] **Step 3: Implement**

```php
const OPTION_EDITOR_ACCESS = 'medialane_editor_access';

public static function editor_access_enabled(): bool {
	return (bool) get_option( self::OPTION_EDITOR_ACCESS, false );
}

public static function save_editor_access( bool $enabled ) {
	update_option( self::OPTION_EDITOR_ACCESS, $enabled );
	$role = get_role( 'editor' );
	if ( ! $role ) {
		return;
	}
	if ( $enabled ) {
		$role->add_cap( self::CAP_TOKENIZE );
	} else {
		$role->remove_cap( self::CAP_TOKENIZE );
	}
}
```

Register the setting in `register_settings()`:

```php
register_setting( 'medialane', self::OPTION_EDITOR_ACCESS, array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
```

Add a checkbox to `render_settings_page()`'s `<table class="form-table">`, right after the content-scope row:

```php
<tr>
	<th><?php esc_html_e( 'Let Editors tokenize their own posts', 'medialane' ); ?></th>
	<td>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_EDITOR_ACCESS ); ?>" value="1" <?php checked( self::editor_access_enabled() ); ?> />
			<?php esc_html_e( 'Editors can tokenize posts they can edit; Administrators can always tokenize any post.', 'medialane' ); ?>
		</label>
	</td>
</tr>
```

Because `register_setting`'s own save path (`options.php`) doesn't call `save_editor_access()` directly, hook the role sync to the option's update action in `register()`:

```php
add_action( 'update_option_' . self::OPTION_EDITOR_ACCESS, function ( $old, $new ) {
	self::save_editor_access( (bool) $new );
}, 10, 2 );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Settings`
Expected: PASS (5 tests total)

- [ ] **Step 5: Commit**

```bash
git add includes/class-settings.php tests/php/test-settings.php
git commit -m "feat: let site owners grant the tokenize capability to the Editor role"
```

### Task C3: Use the capability, with per-post ownership, in the REST proxy

**Files:**
- Modify: `includes/class-rest-proxy.php` (`check_permission` and its callers)
- Test: `tests/php/test-rest-proxy.php` (extend)

**Interfaces:**
- Consumes: `Settings::CAP_TOKENIZE` from Task C1.
- Produces: `RestProxy::check_permission( \WP_REST_Request $request ): bool` (now takes the request, was previously zero-arg) and `RestProxy::check_tokenize_permission( \WP_REST_Request $request ): bool`. Settings/collection-management routes (`/settings/collection`, `/collections`) stay `manage_options`-only via a renamed `check_admin_permission()`; the tokenize-shaped routes (`/metadata/*`, `/intents/*`, `/collections/sync-tx`, `/posts/{id}/*`) switch to the new, capability-plus-ownership check.

- [ ] **Step 1: Write the failing tests**

```php
public function test_editor_without_the_capability_is_rejected() {
	wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

	$request = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
	$response = $this->server->dispatch( $request );

	$this->assertSame( 401, $response->get_status() );
}

public function test_editor_with_the_capability_can_reach_tokenize_routes() {
	$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );
	wp_set_current_user( $user_id );
	update_option( Settings::OPTION_API_KEY, 'test-key' );

	add_filter( 'pre_http_request', function () {
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
	} );

	$request = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
	$request->set_body( wp_json_encode( array() ) );
	$response = $this->server->dispatch( $request );

	$this->assertSame( 200, $response->get_status() );
}

public function test_editor_with_the_capability_cannot_mark_a_post_they_cannot_edit() {
	$owner_id  = $this->factory->user->create( array( 'role' => 'author' ) );
	$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );
	$post_id = $this->factory->post->create( array( 'post_author' => $owner_id ) );

	// Authors can only edit their own posts by default — this editor still can
	// edit any post, so switch the scenario to a role that genuinely can't:
	wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
	get_role( 'subscriber' )->add_cap( Settings::CAP_TOKENIZE );

	$request = new WP_REST_Request( 'POST', "/medialane/v1/posts/{$post_id}/minting" );
	$response = $this->server->dispatch( $request );

	$this->assertSame( 403, $response->get_status() );
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `phpunit -c phpunit.xml.dist --filter Test_Rest_Proxy`
Expected: the first passes already (401 is the current behavior for any non-`manage_options` user), the second and third FAIL — everything is still gated on `manage_options` so the editor gets 401 instead of 200/403.

- [ ] **Step 3: Implement**

Replace `check_permission()` in `includes/class-rest-proxy.php`:

```php
public static function check_admin_permission(): bool {
	return current_user_can( 'manage_options' );
}

public static function check_tokenize_permission( \WP_REST_Request $request ): bool {
	if ( ! current_user_can( Settings::CAP_TOKENIZE ) ) {
		return false;
	}
	$post_id = (int) $request->get_param( 'id' );
	if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
		return new \WP_Error( 'medialane_forbidden', __( "You can't tokenize a post you don't have edit access to.", 'medialane' ), array( 'status' => 403 ) );
	}
	return true;
}
```

`check_permission()` returning a `WP_Error` from a `permission_callback` is supported by the REST API and is how the 403-with-a-message case is produced instead of a bare `false` (which always becomes a generic 401).

Update `register_routes()`: change `permission_callback` to `array( __CLASS__, 'check_admin_permission' )` for `/settings/collection` and `/collections` only; change it to `array( __CLASS__, 'check_tokenize_permission' )` for every other route (`/metadata/upload`, `/metadata/upload-file`, `/intents/create-collection`, `/intents/mint`, `/collections/sync-tx`, `/tokens/(?P<contract>...)/(?P<tokenId>...)`, `/posts/(?P<id>\d+)/minting`, `/posts/(?P<id>\d+)/minted`, `/posts/(?P<id>\d+)/error`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Rest_Proxy`
Expected: PASS (6 tests total in this file)

- [ ] **Step 5: Commit**

```bash
git add includes/class-rest-proxy.php tests/php/test-rest-proxy.php
git commit -m "feat: gate tokenize routes on a dedicated capability plus per-post edit access"
```

---

## Task Group D: Gasless (sponsored) minting

**Why:** Every mint today costs the connected wallet real STRK gas and requires a signature popup with a fee shown. `medialane-backend`'s `/v1/paymaster/invoke/{build,execute}` already sponsors gas for an allowlisted set of entrypoints that includes exactly `mint` and `create_collection` (`ALLOWED_PAYMASTER_ENTRYPOINTS` in `medialane-backend/src/api/routes/paymaster.ts`) — the same mechanism `medialane-io` already uses in production. This plumbs the WordPress plugin into it. The signature popup stays (the owner still has to prove they authorized the mint); the gas cost and the "pay gas" framing in the wallet UI go away.

### Task D1: REST proxy routes for the paymaster

**Files:**
- Modify: `includes/class-rest-proxy.php`
- Test: `tests/php/test-rest-proxy.php` (extend)

**Interfaces:**
- Produces: `POST /medialane/v1/paymaster/invoke/build` → forwards to `/v1/paymaster/invoke/build`; `POST /medialane/v1/paymaster/invoke/execute` → forwards to `/v1/paymaster/invoke/execute`. Both use `check_tokenize_permission` (Task C3) and the existing `forward_json( $request, $backend_path )` — no new PHP logic beyond two more route registrations, following the exact closure-binding pattern already used for `/intents/mint` etc.

- [ ] **Step 1: Write the failing test**

```php
public function test_paymaster_routes_forward_to_the_fixed_backend_path() {
	wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	update_option( Settings::OPTION_API_KEY, 'test-key' );

	$captured_urls = array();
	add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
		$captured_urls[] = $url;
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
	}, 10, 3 );

	$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/paymaster/invoke/build' ) );
	$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/paymaster/invoke/execute' ) );

	$this->assertStringEndsWith( '/v1/paymaster/invoke/build', $captured_urls[0] );
	$this->assertStringEndsWith( '/v1/paymaster/invoke/execute', $captured_urls[1] );
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `phpunit -c phpunit.xml.dist --filter test_paymaster_routes_forward_to_the_fixed_backend_path`
Expected: FAIL — the routes don't exist yet, so `dispatch()` returns 404 and nothing is captured.

- [ ] **Step 3: Implement**

Add to `register_routes()` in `includes/class-rest-proxy.php`, alongside the other `forward_json`-backed routes:

```php
register_rest_route( self::NAMESPACE, '/paymaster/invoke/build', array(
	'methods'             => 'POST',
	'callback'            => function ( \WP_REST_Request $request ) {
		return self::forward_json( $request, '/v1/paymaster/invoke/build' );
	},
	'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
) );
register_rest_route( self::NAMESPACE, '/paymaster/invoke/execute', array(
	'methods'             => 'POST',
	'callback'            => function ( \WP_REST_Request $request ) {
		return self::forward_json( $request, '/v1/paymaster/invoke/execute' );
	},
	'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
) );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `phpunit -c phpunit.xml.dist --filter Test_Rest_Proxy`
Expected: PASS (7 tests total)

- [ ] **Step 5: Commit**

```bash
git add includes/class-rest-proxy.php tests/php/test-rest-proxy.php
git commit -m "feat: add REST proxy routes for the paymaster invoke build/execute endpoints"
```

### Task D2: `signTypedData` on the wallet

**Files:**
- Modify: `assets/src/wallet.js`
- Test: `tests/js/wallet.test.js` (extend)

**Interfaces:**
- Consumes: nothing new — `account` is already a `WalletAccount` instance (Task-independent; this plugin already constructs one in `connectWallet`).
- Produces: `signTypedData(account, typedData): Promise<string[]>` — signs via `account.signMessage(typedData)` (documented on `WalletAccount`, confirmed present in the installed `starknet@10.8.0` type defs) and normalizes the result to a hex-string array via `stark.signatureToHexArray`, matching the `signature: string[]` shape `medialane-backend`'s paymaster routes expect (already covered by that backend's own tests, e.g. `signature: ["0x1"]` in `paymaster.test.ts`).

- [ ] **Step 1: Write the failing test**

Add to `tests/js/wallet.test.js`, alongside the existing `vi.mock("starknet", ...)`:

```js
vi.mock("starknet", () => ({
  RpcProvider: class {},
  WalletAccount: { connect: (...args) => walletAccountConnect(...args) },
  stark: { signatureToHexArray: (sig) => sig },
}));
```

```js
describe("signTypedData", () => {
  it("signs the typed data and normalizes the signature to a hex array", async () => {
    const signMessage = vi.fn().mockResolvedValue(["0x1", "0x2"]);
    const account = { signMessage };
    const typedData = { domain: {}, message: {} };

    const signature = await signTypedData(account, typedData);

    expect(signMessage).toHaveBeenCalledWith(typedData);
    expect(signature).toEqual(["0x1", "0x2"]);
  });
});
```

Add `signTypedData` to the `import` line pulling from `../../assets/src/wallet.js`.

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- wallet`
Expected: FAIL — `signTypedData` is not exported.

- [ ] **Step 3: Implement**

In `assets/src/wallet.js`, change the `starknet` import and add the function:

```js
import { RpcProvider, WalletAccount, stark } from "starknet";
```

```js
export async function signTypedData(account, typedData) {
  const signature = await account.signMessage(typedData);
  return stark.signatureToHexArray(signature);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- wallet`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add assets/src/wallet.js tests/js/wallet.test.js
git commit -m "feat: add signTypedData, wrapping WalletAccount.signMessage for the paymaster flow"
```

### Task D3: Sponsored-invoke API calls

**Files:**
- Modify: `assets/src/api.js`
- Test: `tests/js/api.test.js` (extend)

**Interfaces:**
- Produces: `buildSponsoredInvoke({ userAddress, calls }): Promise<{ data: { typedData } }>` and `executeSponsoredInvoke({ userAddress, typedData, signature, calls }): Promise<{ data: { transactionHash } }>`, both via the shared `request()` helper, matching every other export in this file.

- [ ] **Step 1: Write the failing test**

```js
it("buildSponsoredInvoke posts to /paymaster/invoke/build", async () => {
  global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { typedData: {} } }) });
  await buildSponsoredInvoke({ userAddress: "0xabc", calls: [] });
  expect(global.fetch).toHaveBeenCalledWith(
    expect.stringContaining("/paymaster/invoke/build"),
    expect.objectContaining({ method: "POST" }),
  );
});

it("executeSponsoredInvoke posts to /paymaster/invoke/execute", async () => {
  global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { transactionHash: "0x1" } }) });
  const body = await executeSponsoredInvoke({ userAddress: "0xabc", typedData: {}, signature: ["0x1"], calls: [] });
  expect(global.fetch).toHaveBeenCalledWith(
    expect.stringContaining("/paymaster/invoke/execute"),
    expect.objectContaining({ method: "POST" }),
  );
  expect(body.data.transactionHash).toBe("0x1");
});
```

Add `buildSponsoredInvoke, executeSponsoredInvoke` to the existing import from `../../assets/src/api.js` at the top of `tests/js/api.test.js`.

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- api`
Expected: FAIL — neither function exists.

- [ ] **Step 3: Implement**

Add to `assets/src/api.js`:

```js
export function buildSponsoredInvoke(params) {
  return request("/paymaster/invoke/build", { method: "POST", body: JSON.stringify(params) });
}

export function executeSponsoredInvoke(params) {
  return request("/paymaster/invoke/execute", { method: "POST", body: JSON.stringify(params) });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- api`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add assets/src/api.js tests/js/api.test.js
git commit -m "feat: add buildSponsoredInvoke/executeSponsoredInvoke to api.js"
```

### Task D4: Split `tokenizeOne` into prepare + execute, sponsored by default

**Files:**
- Modify: `assets/src/mint-flow.js`
- Test: `tests/js/mint-flow.test.js` (extend, and update existing tests to the new shape)

**Interfaces:**
- Consumes: `signTypedData` (Task D2), `buildSponsoredInvoke`/`executeSponsoredInvoke` (Task D3).
- Produces:
  - `prepareMint({ postId, title, body, image, license, address, collectionContract }): Promise<{ postId, license, calls: Call[] }>` — uploads metadata and builds the mint intent's calls, does **not** touch the chain or post meta yet.
  - `executeMintBatch({ restUrl, nonce, account, address, collectionContract, entries }): Promise<Array<{ postId, txHash } | { postId, error }>>` — takes one or more `prepareMint` results, marks each `minting`, gets one sponsored typed-data build for the combined calls, signs once, executes once, then marks each `minted` with the shared `txHash` (or `error` if the whole batch failed — a batch is all-or-nothing on-chain, so a failure applies to every post in it).
  - `tokenizeOne(...)` (the old all-in-one function) is removed; both `metabox.js` and `bulk-action.js` now call `prepareMint` then `executeMintBatch` (Tasks D5/E1).

- [ ] **Step 1: Write the failing tests**

Replace the existing `tests/js/mint-flow.test.js` content (check its current test names with `cat tests/js/mint-flow.test.js` first, since Task D4 replaces the function those tests cover) with:

```js
import { describe, it, expect, vi } from "vitest";
import { prepareMint, executeMintBatch } from "../../assets/src/mint-flow.js";

vi.mock("../../assets/src/api.js", () => ({
  uploadJson: vi.fn().mockResolvedValue({ data: { url: "ipfs://meta" } }),
  createMintIntent: vi.fn().mockResolvedValue({ data: { calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] } }),
  buildSponsoredInvoke: vi.fn().mockResolvedValue({ data: { typedData: { message: { calls: [] } } } }),
  executeSponsoredInvoke: vi.fn().mockResolvedValue({ data: { transactionHash: "0xtx" } }),
}));
vi.mock("../../assets/src/wallet.js", () => ({
  signTypedData: vi.fn().mockResolvedValue(["0x1", "0x2"]),
}));

describe("prepareMint", () => {
  it("uploads metadata and returns the mint calls without touching the chain", async () => {
    const result = await prepareMint({
      postId: 42, title: "A Post", body: "Body", image: "", license: "CC BY-SA", address: "0xowner",
      collectionContract: "0xcol",
    });
    expect(result.postId).toBe(42);
    expect(result.calls).toHaveLength(1);
  });
});

describe("executeMintBatch", () => {
  it("signs and executes once for the whole batch, then marks every post minted with the shared tx hash", async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    const entries = [
      { postId: 1, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
      { postId: 2, license: "CC BY-SA", calls: [{ contractAddress: "0xc", entrypoint: "mint", calldata: [] }] },
    ];
    const results = await executeMintBatch({
      restUrl: "/wp-json/medialane/v1", nonce: "abc", account: {}, address: "0xowner",
      collectionContract: "0xcol", entries,
    });
    expect(results).toEqual([
      { postId: 1, txHash: "0xtx" },
      { postId: 2, txHash: "0xtx" },
    ]);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- mint-flow`
Expected: FAIL — `prepareMint`/`executeMintBatch` don't exist; the old `tokenizeOne`/`markMinting` etc. tests (if any remain) will also fail to import.

- [ ] **Step 3: Implement**

Replace `assets/src/mint-flow.js` in full:

```js
import { signTypedData } from "./wallet.js";
import { uploadJson, createMintIntent, buildSponsoredInvoke, executeSponsoredInvoke } from "./api.js";

async function postJson(restUrl, nonce, path, body) {
  await fetch(`${restUrl}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
    body: JSON.stringify(body),
  });
}

function markMinting(restUrl, nonce, postId) {
  return postJson(restUrl, nonce, `/posts/${postId}/minting`, {});
}

function markMinted(restUrl, nonce, postId, data) {
  return postJson(restUrl, nonce, `/posts/${postId}/minted`, data);
}

function markError(restUrl, nonce, postId, message) {
  return postJson(restUrl, nonce, `/posts/${postId}/error`, { message });
}

/**
 * Uploads metadata and builds this post's mint calls. Does not touch the
 * chain or post meta — callers batch these together before executing.
 */
export async function prepareMint({ postId, title, body, image, license, address, collectionContract }) {
  const metaRes = await uploadJson({ name: title, description: body, image: image || undefined, license });
  const intentRes = await createMintIntent({
    owner: address,
    collectionId: collectionContract,
    recipient: address,
    tokenUri: metaRes.data.url,
    royaltyBps: 0,
  });
  return { postId, license, calls: intentRes.data.calls };
}

/**
 * Signs and executes one sponsored transaction covering every entry's calls,
 * then marks each post minted with the shared tx hash (or errored, if the
 * whole batch failed — it's one on-chain transaction, so it succeeds or
 * fails together).
 */
export async function executeMintBatch({ restUrl, nonce, account, address, collectionContract, entries }) {
  if (!collectionContract) {
    throw new Error("No collection configured. Connect a wallet in Medialane Settings first.");
  }
  await Promise.all(entries.map((e) => markMinting(restUrl, nonce, e.postId)));

  const calls = entries.flatMap((e) => e.calls);
  try {
    const buildRes = await buildSponsoredInvoke({ userAddress: address, calls });
    const signature = await signTypedData(account, buildRes.data.typedData);
    const execRes = await executeSponsoredInvoke({ userAddress: address, typedData: buildRes.data.typedData, signature, calls });
    const txHash = execRes.data.transactionHash;

    await Promise.all(entries.map((e) =>
      markMinted(restUrl, nonce, e.postId, { tokenId: "", txHash, contract: collectionContract, license: e.license })
    ));
    return entries.map((e) => ({ postId: e.postId, txHash }));
  } catch (err) {
    const message = err.message || "Something went wrong";
    await Promise.all(entries.map((e) => markError(restUrl, nonce, e.postId, message)));
    return entries.map((e) => ({ postId: e.postId, error: message }));
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- mint-flow`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add assets/src/mint-flow.js tests/js/mint-flow.test.js
git commit -m "feat: split tokenizeOne into prepareMint + executeMintBatch, sponsored by default"
```

### Task D5: Wire `metabox.js` to the new two-step flow

**Files:**
- Modify: `assets/src/metabox.js`
- Test: `tests/js/mint.test.js` (update to match)

**Interfaces:**
- Consumes: `prepareMint`, `executeMintBatch` (Task D4).

- [ ] **Step 1: Update the failing test's expectations**

Read `tests/js/mint.test.js` first (`cat tests/js/mint.test.js`) to see its current mocks, then update whatever it asserts was called (`tokenizeOne`) to instead assert `prepareMint` followed by `executeMintBatch` were both called once, with the single post's data.

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- mint.test`
Expected: FAIL against the still-old `metabox.js` calling the now-removed `tokenizeOne`.

- [ ] **Step 3: Implement**

In `assets/src/metabox.js`, change the import and `tokenizePost`:

```js
import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch, markError } from "./mint-flow.js";
```

```js
export async function tokenizePost(postId) {
  const data = window.medialaneData;

  const licenseSelect = document.getElementById("medialane-license");
  const licenseCustom = document.getElementById("medialane-license-custom");
  const license = licenseSelect.value === "Custom" ? licenseCustom.value : licenseSelect.value;

  const { address, account } = await connectWallet();
  const body = data.contentScope === "full" ? data.postContent : data.postExcerpt;

  const entry = await prepareMint({
    postId, title: data.postTitle, body, image: data.featuredImageUrl, license, address,
    collectionContract: data.collectionContract,
  });
  const [result] = await executeMintBatch({
    restUrl: data.restUrl, nonce: data.nonce, account, address,
    collectionContract: data.collectionContract, entries: [entry],
  });
  if (result.error) {
    throw new Error(result.error);
  }
  return result.txHash;
}
```

`markError` no longer needs importing into the `DOMContentLoaded` handler's `catch` block — `executeMintBatch` already calls it internally on failure — so remove that call from the handler, keeping only the UI reset and `alert(...)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- mint.test`
Expected: PASS

- [ ] **Step 5: Rebuild and commit**

```bash
npm run build
git add assets/src/metabox.js tests/js/mint.test.js assets/dist
git commit -m "feat: wire the per-post metabox to the sponsored prepareMint/executeMintBatch flow"
```

---

## Task Group E: Batch the bulk action into one multicall

**Why:** `bulk-action.js` today calls the old per-post mint sequence in a loop, so tokenizing 50 posts meant 50 separate signature popups (and, before Task Group D, 50 separate gas payments). `executeMintBatch` (Task D4) already accepts multiple entries and executes them as one transaction — bulk-action just needs to call `prepareMint` for every selected post first, then hand the whole list to one `executeMintBatch` call.

### Task E1: Rewrite `tokenizeBulk` around the batch flow

**Files:**
- Modify: `assets/src/bulk-action.js`
- Test: `tests/js/bulk-action.test.js` (update)

**Interfaces:**
- Consumes: `prepareMint`, `executeMintBatch` (Task D4).

- [ ] **Step 1: Update the failing test's expectations**

Read `tests/js/bulk-action.test.js` first, then update it to assert: `prepareMint` is called once per selected post, and `executeMintBatch` is called exactly once with an `entries` array of that length (not once per post).

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- bulk-action`
Expected: FAIL against the still-old per-post loop.

- [ ] **Step 3: Implement**

Replace `tokenizeBulk` in `assets/src/bulk-action.js`:

```js
import { connectWallet } from "./wallet.js";
import { prepareMint, executeMintBatch } from "./mint-flow.js";

export async function tokenizeBulk(postIds, onProgress) {
  const data = window.medialaneData;
  if (!data.collectionContract) {
    throw new Error("No collection configured.");
  }
  const { address, account } = await connectWallet();

  const entries = [];
  for (const postId of postIds) {
    const post = data.posts[postId];
    if (!post) continue;
    onProgress && onProgress(postId, "preparing");
    const body = data.contentScope === "full" ? post.content : post.excerpt;
    entries.push(await prepareMint({
      postId, title: post.title, body, image: post.image, license: "All Rights Reserved", address,
      collectionContract: data.collectionContract,
    }));
  }

  entries.forEach((e) => onProgress && onProgress(e.postId, "minting"));
  const results = await executeMintBatch({
    restUrl: data.restUrl, nonce: data.nonce, account, address,
    collectionContract: data.collectionContract, entries,
  });
  results.forEach((r) => onProgress && onProgress(r.postId, r.error ? "error" : "minted", r.error));
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- bulk-action`
Expected: PASS

- [ ] **Step 5: Rebuild and commit**

```bash
npm run build
git add assets/src/bulk-action.js tests/js/bulk-action.test.js assets/dist
git commit -m "feat: batch bulk-action tokenizing into one sponsored multicall instead of N signatures"
```

### Task E2: Cap the batch size

**Files:**
- Modify: `assets/src/bulk-action.js`
- Test: `tests/js/bulk-action.test.js` (extend)

**Interfaces:**
- Why a separate task: `executeMintBatch` submits every entry's calls as one transaction — an unbounded batch risks hitting a block gas/step limit for a large selection. Cap it and chunk into multiple sequential batches instead of one unbounded call.

- [ ] **Step 1: Write the failing test**

```js
it("splits more than 25 posts into multiple batches", async () => {
  const postIds = Array.from({ length: 30 }, (_, i) => String(i + 1));
  window.medialaneData.posts = Object.fromEntries(
    postIds.map((id) => [id, { title: "t", excerpt: "e", content: "c", image: "" }])
  );
  const executeMintBatchCalls = [];
  vi.doMock("../../assets/src/mint-flow.js", () => ({
    prepareMint: vi.fn(async ({ postId }) => ({ postId, license: "x", calls: [] })),
    executeMintBatch: vi.fn(async ({ entries }) => {
      executeMintBatchCalls.push(entries.length);
      return entries.map((e) => ({ postId: e.postId, txHash: "0xtx" }));
    }),
  }));
  const { tokenizeBulk } = await import("../../assets/src/bulk-action.js");
  await tokenizeBulk(postIds, () => {});
  expect(executeMintBatchCalls).toEqual([25, 5]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npm test -- bulk-action`
Expected: FAIL — the current implementation calls `executeMintBatch` once with all 30 entries, so `executeMintBatchCalls` is `[30]`.

- [ ] **Step 3: Implement**

In `assets/src/bulk-action.js`, add a constant and chunk the `executeMintBatch` call:

```js
const MAX_BATCH_SIZE = 25;

function chunk(array, size) {
  const out = [];
  for (let i = 0; i < array.length; i += size) out.push(array.slice(i, i + size));
  return out;
}
```

Replace the single `executeMintBatch` call with a loop over chunks:

```js
  for (const group of chunk(entries, MAX_BATCH_SIZE)) {
    group.forEach((e) => onProgress && onProgress(e.postId, "minting"));
    const results = await executeMintBatch({
      restUrl: data.restUrl, nonce: data.nonce, account, address,
      collectionContract: data.collectionContract, entries: group,
    });
    results.forEach((r) => onProgress && onProgress(r.postId, r.error ? "error" : "minted", r.error));
  }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- bulk-action`
Expected: PASS

- [ ] **Step 5: Rebuild and commit**

```bash
npm run build
git add assets/src/bulk-action.js tests/js/bulk-action.test.js assets/dist
git commit -m "feat: cap sponsored bulk-mint batches at 25 posts, chunking larger selections"
```

---

## Final Verification

- [ ] Run the complete JS suite: `npm run build && npm test` — expect all files passing, zero failures.
- [ ] Run the complete PHP suite: `WP_TESTS_DIR=/tmp/wordpress-tests-lib phpunit -c phpunit.xml.dist` — expect all files passing, zero failures (requires Task A1's script to have been run once locally, or run this step in CI via Task A2).
- [ ] Confirm the GitHub Actions workflow (Task A2) is green on the branch/PR containing all of the above.
- [ ] Manually smoke-test on a real WordPress install with a real Ready/Braavos extension, since no task above exercises the real wallet-extension or real `medialane-backend` paymaster over the network:
  - Connect wallet as an Administrator, create a collection, tokenize one post (Task Groups B/D/single-post path).
  - Grant Editor access (Task C2), sign in as an Editor, confirm they can tokenize their own post but get a clear error on someone else's.
  - Select 3+ posts in the Posts list, run the bulk action, confirm one signature prompt covers all of them and each post ends up `minted` with the same tx hash.
  - Define `MEDIALANE_API_KEY` in `wp-config.php` (Task B1), confirm the settings-page API key field is described as overridden and the key from `wp-config.php` is what's actually used.

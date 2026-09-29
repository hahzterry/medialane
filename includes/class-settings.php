<?php

namespace Medialane;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION_API_KEY    = 'medialane_api_key';
	const OPTION_WALLET     = 'medialane_wallet_address';
	const OPTION_COLLECTION = 'medialane_collection_contract';
	const OPTION_COLLECTION_LABELS = 'medialane_collection_labels';
	const OPTION_CATEGORY_MAP = 'medialane_category_collections';
	const OPTION_LICENSE_DEFAULT = 'medialane_license_default';
	const OPTION_AI_POLICY_DEFAULT = 'medialane_ai_policy_default';
	const OPTION_CONTENT_SCOPE   = 'medialane_content_scope'; // 'excerpt' | 'full'
	const CAP_TOKENIZE = 'medialane_tokenize_posts';

	const LICENSE_PRESETS = array(
		'CC BY-SA', 'CC BY', 'CC BY-NC', 'CC BY-ND', 'CC BY-NC-SA', 'CC BY-NC-ND',
		'CC0', 'MIT', 'Apache 2.0', 'All Rights Reserved', 'Custom',
	);
	const AI_POLICIES = array( 'Allowed', 'Training Only', 'Not Allowed' );

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( string $hook ) {
		if ( 'settings_page_medialane-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'medialane-settings', MEDIALANE_PLUGIN_URL . 'assets/dist/settings.js', array(), MEDIALANE_PLUGIN_VERSION, true );
		wp_localize_script( 'medialane-settings', 'medialaneData', array(
			'restUrl'            => esc_url_raw( rest_url( 'medialane/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'siteName'           => get_bloginfo( 'name' ),
			'collectionContract' => self::get_collection_contract(),
		) );
	}

	public static function add_settings_page() {
		add_options_page(
			__( 'Tokenize, Protect & License Your Content', 'medialane' ),
			__( 'Tokenize & Protect', 'medialane' ),
			'manage_options',
			'medialane-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'medialane', self::OPTION_API_KEY, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'medialane', self::OPTION_LICENSE_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'medialane', self::OPTION_AI_POLICY_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'medialane', self::OPTION_CONTENT_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function grant_default_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_TOKENIZE ) ) {
			$role->add_cap( self::CAP_TOKENIZE );
		}
	}

	public static function sanitize_address( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^0x[0-9a-f]+$/', $value ) ? $value : '';
	}

	public static function get_api_key(): string {
		if ( defined( 'MEDIALANE_API_KEY' ) && MEDIALANE_API_KEY ) {
			return (string) MEDIALANE_API_KEY;
		}
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	public static function get_wallet_address(): string {
		return (string) get_option( self::OPTION_WALLET, '' );
	}

	public static function save_wallet_address( string $address ) {
		update_option( self::OPTION_WALLET, self::sanitize_address( $address ) );
	}

	public static function get_collection_contract(): string {
		return (string) get_option( self::OPTION_COLLECTION, '' );
	}

	public static function save_collection_contract( string $address ) {
		update_option( self::OPTION_COLLECTION, self::sanitize_address( $address ) );
	}

	public static function get_default_collection(): string {
		return self::get_collection_contract();
	}

	public static function set_default_collection( string $address ) {
		self::save_collection_contract( $address );
	}

	// Labels are the only thing about a collection this plugin is entitled to
	// remember locally — a name has no on-chain meaning. Whether a collection
	// actually exists is never decided from this option; see
	// fetch_live_collections().
	public static function get_collection_labels(): array {
		$labels = get_option( self::OPTION_COLLECTION_LABELS, array() );
		return is_array( $labels ) ? $labels : array();
	}

	public static function save_collection_label( string $contract, string $label ) {
		$contract = self::sanitize_address( $contract );
		$label    = sanitize_text_field( $label );
		$labels   = self::get_collection_labels();

		$labels[ $contract ] = $label;
		update_option( self::OPTION_COLLECTION_LABELS, $labels );

		if ( ! self::get_default_collection() ) {
			self::set_default_collection( $contract );
		}
	}

	// The chain, via medialane-backend's indexer, is the only authority on
	// which collections actually exist for this site's wallet. This never
	// reads from OPTION_COLLECTION_LABELS to decide existence — only to
	// attach a friendly name to a contract the backend already confirmed.
	public static function fetch_live_collections(): array {
		$key   = self::get_api_key();
		$owner = self::get_wallet_address();
		if ( ! $key || ! $owner ) {
			return array();
		}

		$response = wp_remote_get( MEDIALANE_BACKEND_URL . '/v1/collections?owner=' . rawurlencode( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return array();
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : array();

		$labels      = self::get_collection_labels();
		$collections = array();
		foreach ( $items as $item ) {
			if ( empty( $item['contractAddress'] ) ) {
				continue;
			}
			$contract      = (string) $item['contractAddress'];
			$collections[] = array(
				'contract' => $contract,
				'label'    => isset( $labels[ $contract ] ) ? $labels[ $contract ] : $contract,
			);
		}
		return $collections;
	}

	public static function get_category_map(): array {
		$map = get_option( self::OPTION_CATEGORY_MAP, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function save_category_map( array $map ) {
		$sanitized = array();
		foreach ( $map as $category_id => $contract ) {
			$contract = self::sanitize_address( (string) $contract );
			if ( $contract ) {
				$sanitized[ (int) $category_id ] = $contract;
			}
		}
		update_option( self::OPTION_CATEGORY_MAP, $sanitized );
	}

	// $live_collections must come from fetch_live_collections() — a category
	// mapping or stored default pointing at a contract that isn't in that
	// list (deleted, renamed, never real) is never trusted.
	public static function resolve_collection_for_post( int $post_id, array $live_collections ): string {
		$valid = wp_list_pluck( $live_collections, 'contract' );

		$map = self::get_category_map();
		if ( $map ) {
			$categories = get_the_category( $post_id );
			foreach ( $categories as $category ) {
				if ( isset( $map[ $category->term_id ] ) && in_array( $map[ $category->term_id ], $valid, true ) ) {
					return $map[ $category->term_id ];
				}
			}
		}

		$default = self::get_default_collection();
		if ( in_array( $default, $valid, true ) ) {
			return $default;
		}

		return $valid ? $valid[0] : '';
	}

	public static function get_content_scope(): string {
		$scope = get_option( self::OPTION_CONTENT_SCOPE, 'excerpt' );
		return in_array( $scope, array( 'excerpt', 'full' ), true ) ? $scope : 'excerpt';
	}

	public static function get_license_default(): string {
		$license = get_option( self::OPTION_LICENSE_DEFAULT, '' );
		return $license ? (string) $license : 'All Rights Reserved';
	}

	public static function get_ai_policy_default(): string {
		$policy = get_option( self::OPTION_AI_POLICY_DEFAULT, '' );
		return $policy ? (string) $policy : 'Allowed';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$api_key_set       = (bool) self::get_api_key();
		$wallet_connected  = (bool) self::get_wallet_address();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tokenize, Protect & License Your Content', 'medialane' ); ?></h1>
			<p style="max-width:640px;font-size:14px;">
				<?php esc_html_e( 'This plugin turns your posts into protected IP assets through Medialane. Each tokenized post carries a permanent, checkable record of who wrote it, plus a clear license that says what other people are allowed to do with it.', 'medialane' ); ?>
			</p>

			<div class="card" style="max-width:640px;margin:16px 0;padding:1px 20px 20px;">
				<h2><?php esc_html_e( 'What you get', 'medialane' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;">
					<li><?php esc_html_e( 'Proof of authorship. A permanent, public record of who wrote a post and when, independent of this site staying online.', 'medialane' ); ?></li>
					<li><?php esc_html_e( 'Real licensing terms. Choose a Creative Commons preset or reserve all rights, plus a policy on AI training use, applied automatically when a post is tokenized.', 'medialane' ); ?></li>
					<li><?php esc_html_e( 'No wallet needed for your writers. Only the site admin connects one, once. Authors are identified by their WordPress email and never have to do anything themselves.', 'medialane' ); ?></li>
					<li><?php esc_html_e( 'A public badge on every tokenized post, linking to its permanent record so readers can verify it themselves.', 'medialane' ); ?></li>
				</ul>
				<h2><?php esc_html_e( 'How it works', 'medialane' ); ?></h2>
				<ol style="padding-left:20px;">
					<li><?php esc_html_e( 'Add your Medialane API key below.', 'medialane' ); ?></li>
					<li><?php esc_html_e( 'Connect the wallet that will manage tokenization for this site.', 'medialane' ); ?></li>
					<li><?php esc_html_e( 'Tokenize a post from its editor screen, or several at once from the Posts list.', 'medialane' ); ?></li>
				</ol>
			</div>

			<h2><?php esc_html_e( 'API Key', 'medialane' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to Medialane account console */
					esc_html__( 'Generate an API key at %s, then paste it below.', 'medialane' ),
					'<a href="https://portal.medialane.io/account" target="_blank" rel="noopener noreferrer">portal.medialane.io/account</a>'
				);
				?>
			</p>
			<?php if ( defined( 'MEDIALANE_API_KEY' ) && MEDIALANE_API_KEY ) : ?>
				<p><em><?php esc_html_e( 'Set from wp-config.php — the field below is ignored while the MEDIALANE_API_KEY constant is defined.', 'medialane' ); ?></em></p>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'medialane' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="medialane_api_key"><?php esc_html_e( 'API Key', 'medialane' ); ?></label></th>
						<td><input type="password" id="medialane_api_key" name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" value="<?php echo esc_attr( self::get_api_key() ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save API Key', 'medialane' ) ); ?>
			</form>

			<?php if ( ! $api_key_set ) : ?>
				<p class="description"><?php esc_html_e( 'Add your API key and save it to continue setup.', 'medialane' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<h2><?php esc_html_e( 'Wallet', 'medialane' ); ?></h2>
			<p><strong><?php esc_html_e( 'Wallet:', 'medialane' ); ?></strong> <span id="medialane-wallet-status"><?php echo esc_html( self::get_wallet_address() ? self::get_wallet_address() : __( 'Not connected', 'medialane' ) ); ?></span></p>
			<p><button type="button" id="medialane-connect-wallet" class="button"><?php esc_html_e( 'Connect Wallet', 'medialane' ); ?></button></p>

			<?php if ( ! $wallet_connected ) : ?>
				<p class="description"><?php esc_html_e( 'Connect the wallet that will sign every tokenize action on this site to continue setup.', 'medialane' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<p><?php echo wp_kses_post( sprintf(
				/* translators: %s: link to the Posts list */
				__( 'Setup is complete. Head to %s to tokenize a post.', 'medialane' ),
				'<a href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Posts', 'medialane' ) . '</a>'
			) ); ?></p>

			<h2><?php esc_html_e( 'Tokenization defaults', 'medialane' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'medialane' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="medialane_content_scope"><?php esc_html_e( 'Post content to tokenize', 'medialane' ); ?></label></th>
						<td>
							<select id="medialane_content_scope" name="<?php echo esc_attr( self::OPTION_CONTENT_SCOPE ); ?>">
								<option value="excerpt" <?php selected( self::get_content_scope(), 'excerpt' ); ?>><?php esc_html_e( 'Excerpt only', 'medialane' ); ?></option>
								<option value="full" <?php selected( self::get_content_scope(), 'full' ); ?>><?php esc_html_e( 'Full post body', 'medialane' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="medialane_license_default"><?php esc_html_e( 'Default license', 'medialane' ); ?></label></th>
						<td>
							<select id="medialane_license_default" name="<?php echo esc_attr( self::OPTION_LICENSE_DEFAULT ); ?>">
								<?php foreach ( self::LICENSE_PRESETS as $preset ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( self::get_license_default(), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used to tokenize posts in bulk, and pre-selected when tokenizing a single post.', 'medialane' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="medialane_ai_policy_default"><?php esc_html_e( 'Default AI policy', 'medialane' ); ?></label></th>
						<td>
							<select id="medialane_ai_policy_default" name="<?php echo esc_attr( self::OPTION_AI_POLICY_DEFAULT ); ?>">
								<?php foreach ( self::AI_POLICIES as $policy ) : ?>
									<option value="<?php echo esc_attr( $policy ); ?>" <?php selected( self::get_ai_policy_default(), $policy ); ?>><?php echo esc_html( $policy ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Whether AI systems may use tokenized content for training. Recorded on-chain as part of the license, alongside commercial-use and remix terms.', 'medialane' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php $collections = self::fetch_live_collections(); ?>
			<?php if ( $collections ) : ?>
				<h2><?php esc_html_e( 'Collections', 'medialane' ); ?></h2>
				<table class="widefat" style="max-width:640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'medialane' ); ?></th>
							<th><?php esc_html_e( 'Contract', 'medialane' ); ?></th>
							<th><?php esc_html_e( 'Default', 'medialane' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $collections as $collection ) : ?>
							<tr>
								<td><?php echo esc_html( $collection['label'] ); ?></td>
								<td><code><?php echo esc_html( $collection['contract'] ); ?></code></td>
								<td><?php echo $collection['contract'] === self::get_default_collection() ? esc_html__( 'Yes', 'medialane' ) : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<h2><?php esc_html_e( 'Collections', 'medialane' ); ?></h2>
				<p class="description"><?php esc_html_e( 'No collections found for this wallet yet. Create one below.', 'medialane' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Create a new collection', 'medialane' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A separate collection to mint into — useful for organizing tokenized posts by section, author, or archive. The first collection you create becomes the default.', 'medialane' ); ?></p>
			<p>
				<input type="text" id="medialane-new-collection-label" placeholder="<?php esc_attr_e( 'Collection name, e.g. Politics', 'medialane' ); ?>" class="regular-text" />
				<button type="button" id="medialane-create-collection" class="button"><?php esc_html_e( 'Create Collection', 'medialane' ); ?></button>
			</p>

			<?php if ( count( $collections ) > 1 ) : ?>
				<h2><?php esc_html_e( 'Route categories to collections', 'medialane' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Posts in a mapped category are tokenized into that collection instead of the default. Bulk tokenization and the per-post editor both use this.', 'medialane' ); ?></p>
				<?php $category_map = self::get_category_map(); ?>
				<table class="widefat" style="max-width:640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'medialane' ); ?></th>
							<th><?php esc_html_e( 'Collection', 'medialane' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) : ?>
							<tr>
								<td><?php echo esc_html( $category->name ); ?></td>
								<td>
									<select class="medialane-category-collection-select" data-category-id="<?php echo esc_attr( $category->term_id ); ?>">
										<option value=""><?php esc_html_e( 'Use default collection', 'medialane' ); ?></option>
										<?php foreach ( $collections as $collection ) : ?>
											<option value="<?php echo esc_attr( $collection['contract'] ); ?>" <?php selected( isset( $category_map[ $category->term_id ] ) ? $category_map[ $category->term_id ] : '', $collection['contract'] ); ?>><?php echo esc_html( $collection['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" id="medialane-save-category-map" class="button button-primary"><?php esc_html_e( 'Save Mapping', 'medialane' ); ?></button></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

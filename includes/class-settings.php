<?php

namespace Medialane;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION_API_KEY    = 'medialane_api_key';
	const OPTION_WALLET     = 'medialane_wallet_address';
	const OPTION_COLLECTION = 'medialane_collection_contract';
	const OPTION_LICENSE_DEFAULT = 'medialane_license_default';
	const OPTION_CONTENT_SCOPE   = 'medialane_content_scope'; // 'excerpt' | 'full'

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
			__( 'Medialane', 'medialane' ),
			__( 'Medialane', 'medialane' ),
			'manage_options',
			'medialane-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'medialane', self::OPTION_API_KEY, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'medialane', self::OPTION_WALLET, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_address' ) ) );
		register_setting( 'medialane', self::OPTION_COLLECTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_address' ) ) );
		register_setting( 'medialane', self::OPTION_LICENSE_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'medialane', self::OPTION_CONTENT_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
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

	public static function get_collection_contract(): string {
		return (string) get_option( self::OPTION_COLLECTION, '' );
	}

	public static function save_collection_contract( string $address ) {
		update_option( self::OPTION_COLLECTION, self::sanitize_address( $address ) );
	}

	public static function get_content_scope(): string {
		$scope = get_option( self::OPTION_CONTENT_SCOPE, 'excerpt' );
		return in_array( $scope, array( 'excerpt', 'full' ), true ) ? $scope : 'excerpt';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Medialane Settings', 'medialane' ); ?></h1>
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
					<tr>
						<th><label for="medialane_content_scope"><?php esc_html_e( 'Post content to tokenize', 'medialane' ); ?></label></th>
						<td>
							<select id="medialane_content_scope" name="<?php echo esc_attr( self::OPTION_CONTENT_SCOPE ); ?>">
								<option value="excerpt" <?php selected( self::get_content_scope(), 'excerpt' ); ?>><?php esc_html_e( 'Excerpt only', 'medialane' ); ?></option>
								<option value="full" <?php selected( self::get_content_scope(), 'full' ); ?>><?php esc_html_e( 'Full post body', 'medialane' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<p><strong><?php esc_html_e( 'Wallet:', 'medialane' ); ?></strong> <span id="medialane-wallet-status"><?php echo esc_html( self::get_wallet_address() ? self::get_wallet_address() : __( 'Not connected', 'medialane' ) ); ?></span></p>
				<p><button type="button" id="medialane-connect-wallet" class="button"><?php esc_html_e( 'Connect Wallet', 'medialane' ); ?></button></p>
				<input type="hidden" id="medialane_wallet_address" name="<?php echo esc_attr( self::OPTION_WALLET ); ?>" value="<?php echo esc_attr( self::get_wallet_address() ); ?>" />
				<?php submit_button(); ?>
			</form>
			<?php if ( self::get_collection_contract() ) : ?>
				<p><?php esc_html_e( 'Collection contract:', 'medialane' ); ?> <code><?php echo esc_html( self::get_collection_contract() ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

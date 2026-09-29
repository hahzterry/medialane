<?php

namespace Medialane;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Metabox {

	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add() {
		add_meta_box( 'medialane-mint', __( 'Medialane', 'medialane' ), array( __CLASS__, 'render' ), 'post', 'side', 'default' );
	}

	public static function render( \WP_Post $post ) {
		$status = PostMeta::get_status( $post->ID );
		?>
		<div id="medialane-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="medialane-metabox-body">
				<?php if ( PostMeta::STATUS_MINTED === $status ) : ?>
					<p><?php esc_html_e( 'Minted', 'medialane' ); ?></p>
					<p><a href="https://voyager.online/tx/<?php echo esc_attr( get_post_meta( $post->ID, PostMeta::KEY_TX_HASH, true ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View transaction', 'medialane' ); ?></a></p>
				<?php else : ?>
					<?php $collections = Settings::get_collections(); ?>
					<?php if ( count( $collections ) > 1 ) : ?>
						<p>
							<label for="medialane-collection"><?php esc_html_e( 'Collection', 'medialane' ); ?></label>
							<select id="medialane-collection">
								<?php foreach ( $collections as $collection ) : ?>
									<option value="<?php echo esc_attr( $collection['contract'] ); ?>" <?php selected( Settings::resolve_collection_for_post( $post->ID ), $collection['contract'] ); ?>><?php echo esc_html( $collection['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>
					<p>
						<label for="medialane-license"><?php esc_html_e( 'License', 'medialane' ); ?></label>
						<select id="medialane-license">
							<?php foreach ( Settings::LICENSE_PRESETS as $preset ) : ?>
								<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( Settings::get_license_default(), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><textarea id="medialane-license-custom" placeholder="<?php esc_attr_e( 'Custom license terms (used if License = Custom)', 'medialane' ); ?>" style="display:none;width:100%;"></textarea></p>
					<button type="button" class="button button-primary" id="medialane-tokenize-btn"><?php esc_html_e( 'Tokenize Post', 'medialane' ); ?></button>
					<?php if ( PostMeta::STATUS_ERROR === $status ) : ?>
						<p class="medialane-error" style="color:#b32d2e;"><?php echo esc_html( PostMeta::get_error( $post->ID ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function enqueue( string $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		global $post;
		wp_enqueue_script( 'medialane-metabox', MEDIALANE_PLUGIN_URL . 'assets/dist/metabox.js', array(), MEDIALANE_PLUGIN_VERSION, true );
		wp_localize_script( 'medialane-metabox', 'medialaneData', array(
			'restUrl'            => esc_url_raw( rest_url( 'medialane/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'walletAddress'      => Settings::get_wallet_address(),
			'collectionContract' => $post ? Settings::resolve_collection_for_post( $post->ID ) : Settings::get_default_collection(),
			'contentScope'       => Settings::get_content_scope(),
			'aiPolicyDefault'    => Settings::get_ai_policy_default(),
			'postId'             => $post ? $post->ID : 0,
			'postTitle'          => $post ? get_the_title( $post ) : '',
			'postExcerpt'        => $post ? get_the_excerpt( $post ) : '',
			'postContent'        => $post ? $post->post_content : '',
			'featuredImageUrl'   => $post && has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'large' ) : '',
			'authorEmail'        => $post ? get_the_author_meta( 'user_email', $post->post_author ) : '',
		) );
	}
}

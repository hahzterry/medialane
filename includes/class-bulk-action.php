<?php

namespace Medialane;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BulkAction {

	public static function register() {
		add_filter( 'bulk_actions-edit-post', array( __CLASS__, 'add_bulk_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_bulk_action( array $actions ): array {
		$actions['medialane_tokenize'] = __( 'Tokenize with Medialane', 'medialane' );
		return $actions;
	}

	public static function enqueue( string $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$posts = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 200, 'post_status' => 'publish' ) );
		$summaries = array();
		foreach ( $posts as $p ) {
			if ( PostMeta::STATUS_MINTED === PostMeta::get_status( $p->ID ) ) {
				continue;
			}
			$summaries[ $p->ID ] = array(
				'title'   => get_the_title( $p ),
				'excerpt' => get_the_excerpt( $p ),
				'content' => $p->post_content,
				'image'   => has_post_thumbnail( $p ) ? get_the_post_thumbnail_url( $p, 'large' ) : '',
			);
		}
		wp_enqueue_script( 'medialane-bulk-action', MEDIALANE_PLUGIN_URL . 'assets/dist/bulk-action.js', array(), MEDIALANE_PLUGIN_VERSION, true );
		wp_localize_script( 'medialane-bulk-action', 'medialaneBulkData', array(
			'restUrl'            => esc_url_raw( rest_url( 'medialane/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'collectionContract' => Settings::get_collection_contract(),
			'contentScope'       => Settings::get_content_scope(),
			'posts'              => $summaries,
		) );
	}
}

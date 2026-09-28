<?php

namespace Medialane;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestProxy {

	const NAMESPACE = 'medialane/v1';

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/metadata/upload', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/metadata/upload' );
			},
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/metadata/upload-file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_file' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/create-collection', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/intents/create-collection' );
			},
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/mint', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/intents/mint' );
			},
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/collections/sync-tx', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/collections/sync-tx' );
			},
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/tokens/(?P<contract>[a-zA-Z0-9x]+)/(?P<tokenId>[a-zA-Z0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_get_token' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/settings/collection', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_collection' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/collections', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_list_collections' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/minting', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_minting' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/minted', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_minted' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/error', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_error' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
	}

	public static function save_minting( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::set_minting( $post_id );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_minted( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::save_minted( $post_id, array(
			'token_id' => (string) $request->get_param( 'tokenId' ),
			'tx_hash'  => (string) $request->get_param( 'txHash' ),
			'contract' => (string) $request->get_param( 'contract' ),
			'license'  => (string) $request->get_param( 'license' ),
		) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_error( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::save_error( $post_id, (string) $request->get_param( 'message' ) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_collection( \WP_REST_Request $request ) {
		$address = (string) $request->get_param( 'contract' );
		Settings::save_collection_contract( $address );
		return rest_ensure_response( array( 'contract' => Settings::get_collection_contract() ) );
	}

	public static function forward_list_collections( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$owner = (string) $request->get_param( 'owner' );
		$response = wp_remote_get( MEDIALANE_BACKEND_URL . '/v1/collections?owner=' . rawurlencode( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private static function api_key_or_error() {
		$key = Settings::get_api_key();
		if ( ! $key ) {
			return new \WP_Error( 'medialane_no_api_key', __( 'No Medialane API key configured.', 'medialane' ), array( 'status' => 400 ) );
		}
		return $key;
	}

	public static function forward_json( \WP_REST_Request $request, string $backend_path ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$response = wp_remote_post( MEDIALANE_BACKEND_URL . $backend_path, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => $key,
			),
			'body'    => $request->get_body(),
			'timeout' => 30,
		) );
		return self::relay( $response );
	}

	public static function forward_file( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'medialane_no_file', __( 'No file provided.', 'medialane' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];
		// Sanitize the filename before it goes into a hand-built multipart header —
		// it originates from the browser's File.name and could otherwise carry
		// quotes/CRLF into the request we send upstream.
		$filename = sanitize_file_name( $file['name'] );
		$content_type = preg_match( '#^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$#', $file['type'] ) ? $file['type'] : 'application/octet-stream';
		$boundary = wp_generate_password( 24, false );
		$body = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
			. "Content-Type: {$content_type}\r\n\r\n"
			. file_get_contents( $file['tmp_name'] ) . "\r\n"
			. "--{$boundary}--\r\n";
		$response = wp_remote_post( MEDIALANE_BACKEND_URL . '/v1/metadata/upload-file', array(
			'headers' => array(
				'Content-Type' => "multipart/form-data; boundary={$boundary}",
				'x-api-key'    => $key,
			),
			'body'    => $body,
			'timeout' => 60,
		) );
		return self::relay( $response );
	}

	public static function forward_get_token( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$contract = $request->get_param( 'contract' );
		$token_id = $request->get_param( 'tokenId' );
		$response = wp_remote_get( MEDIALANE_BACKEND_URL . "/v1/tokens/{$contract}/{$token_id}", array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	private static function relay( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'medialane_upstream_error', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : __( 'Medialane backend request failed.', 'medialane' );
			return new \WP_Error( 'medialane_backend_error', $message, array( 'status' => $status ) );
		}
		return rest_ensure_response( $body );
	}
}

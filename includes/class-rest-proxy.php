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
			'callback'            => array( __CLASS__, 'forward_json' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array( 'backend_path' => array( 'default' => '/v1/metadata/upload' ) ),
		) );
		register_rest_route( self::NAMESPACE, '/metadata/upload-file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_file' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/create-collection', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_json' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array( 'backend_path' => array( 'default' => '/v1/intents/create-collection' ) ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/mint', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_json' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array( 'backend_path' => array( 'default' => '/v1/intents/mint' ) ),
		) );
		register_rest_route( self::NAMESPACE, '/collections/sync-tx', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_json' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array( 'backend_path' => array( 'default' => '/v1/collections/sync-tx' ) ),
		) );
		register_rest_route( self::NAMESPACE, '/tokens/(?P<contract>[a-zA-Z0-9x]+)/(?P<tokenId>[a-zA-Z0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_get_token' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
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

	public static function forward_json( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$path = $request->get_param( 'backend_path' );
		$response = wp_remote_post( MEDIALANE_BACKEND_URL . $path, array(
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
		$boundary = wp_generate_password( 24, false );
		$body = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$file['name']}\"\r\n"
			. "Content-Type: {$file['type']}\r\n\r\n"
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

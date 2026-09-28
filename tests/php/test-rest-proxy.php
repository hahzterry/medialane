<?php

use Medialane\Settings;

class Test_Rest_Proxy extends WP_UnitTestCase {

	protected $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_returns_error_without_api_key() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION_API_KEY );

		$request  = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
		$request->set_body( wp_json_encode( array() ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_rejects_non_admin() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$request  = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_client_supplied_backend_path_cannot_redirect_the_forwarded_request() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_url = null;
		$intercept    = function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'ok' => true ) ),
			);
		};
		add_filter( 'pre_http_request', $intercept, 10, 3 );

		$request = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
		$request->set_body( wp_json_encode( array( 'backend_path' => '/v1/paymaster/deploy/execute' ) ) );
		$this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $intercept, 10 );

		$this->assertStringEndsWith( '/v1/intents/mint', $captured_url );
	}
}

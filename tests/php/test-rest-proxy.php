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
}

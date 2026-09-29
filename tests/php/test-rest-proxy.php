<?php

use Medialane\Settings;

class Test_Rest_Proxy extends WP_UnitTestCase {

	protected $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		// Normally granted by register_activation_hook on plugin activation;
		// the test suite never activates the plugin, so grant it explicitly.
		Settings::grant_default_capability();
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

		// WP's own rest_authorization_required_code() returns 403 for a
		// logged-in-but-unauthorized user and reserves 401 for anonymous ones.
		$this->assertSame( 403, $response->get_status() );
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

	public function test_editor_without_the_capability_is_rejected() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$request  = new WP_REST_Request( 'POST', '/medialane/v1/intents/mint' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
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

	public function test_a_user_with_the_capability_cannot_mark_a_post_they_cannot_edit() {
		$post_id = $this->factory->post->create();

		// Subscribers have no edit_posts capability at all by default, so this
		// exercises the per-post ownership check independent of the tokenize
		// capability itself.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		get_role( 'subscriber' )->add_cap( Settings::CAP_TOKENIZE );

		$request  = new WP_REST_Request( 'POST', "/medialane/v1/posts/{$post_id}/minting" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

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

	public function test_deploy_build_and_provisioning_routes_forward_to_the_fixed_backend_path() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_urls = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
			$captured_urls[] = $url;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
		}, 10, 3 );

		$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/paymaster/deploy/build' ) );
		$this->server->dispatch( new WP_REST_Request( 'POST', '/medialane/v1/business/provisioning' ) );

		$this->assertStringEndsWith( '/v1/paymaster/deploy/build', $captured_urls[0] );
		$this->assertStringEndsWith( '/v1/business/provisioning', $captured_urls[1] );
	}
}

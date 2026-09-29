<?php

use Medialane\BulkAction;
use Medialane\PostMeta;
use Medialane\Settings;

class Test_Bulk_Action extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Settings::grant_default_capability();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-post' );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_no_notice_when_every_published_post_is_already_minted() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA' ) );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	public function test_notice_counts_published_posts_that_are_not_yet_minted() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		$minted = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::save_minted( $minted, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA' ) );
		$this->factory->post->create( array( 'post_status' => 'draft' ) );

		$output = $this->capture_notice();

		$this->assertStringContainsString( '2', $output );
		$this->assertStringContainsString( 'Tokenize with Medialane', $output );
	}

	public function test_no_notice_outside_the_posts_list_screen() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		set_current_screen( 'dashboard' );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	public function test_no_notice_for_a_user_without_the_tokenize_capability() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	private function capture_notice(): string {
		ob_start();
		BulkAction::render_pending_notice();
		return trim( (string) ob_get_clean() );
	}
}

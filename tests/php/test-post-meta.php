<?php

use Medialane\PostMeta;

class Test_Post_Meta extends WP_UnitTestCase {

	public function test_default_status_is_none() {
		$post_id = $this->factory->post->create();
		$this->assertSame( PostMeta::STATUS_NONE, PostMeta::get_status( $post_id ) );
	}

	public function test_save_minted_sets_status_and_fields() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42',
			'tx_hash'  => '0xabc',
			'contract' => '0xdef',
			'license'  => 'All Rights Reserved',
		) );

		$this->assertSame( PostMeta::STATUS_MINTED, PostMeta::get_status( $post_id ) );
		$this->assertSame( '42', get_post_meta( $post_id, PostMeta::KEY_TOKEN_ID, true ) );
		$this->assertSame( '0xabc', get_post_meta( $post_id, PostMeta::KEY_TX_HASH, true ) );
	}

	public function test_save_error_sets_status_and_message() {
		$post_id = $this->factory->post->create();
		PostMeta::save_error( $post_id, 'Transaction reverted' );

		$this->assertSame( PostMeta::STATUS_ERROR, PostMeta::get_status( $post_id ) );
		$this->assertSame( 'Transaction reverted', PostMeta::get_error( $post_id ) );
	}
}

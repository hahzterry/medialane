<?php

use Medialane\Settings;

class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Settings::OPTION_API_KEY );
		parent::tear_down();
	}

	public function test_get_api_key_reads_the_option_when_no_constant_is_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		$this->assertSame( 'from-the-database', Settings::get_api_key() );
	}

	public function test_get_api_key_prefers_the_constant_when_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		if ( ! defined( 'MEDIALANE_API_KEY' ) ) {
			define( 'MEDIALANE_API_KEY', 'from-wp-config' );
		}
		$this->assertSame( 'from-wp-config', Settings::get_api_key() );
	}

	public function test_grant_default_capability_adds_it_to_administrator() {
		$admin_role = get_role( 'administrator' );
		$admin_role->remove_cap( Settings::CAP_TOKENIZE );
		$this->assertFalse( $admin_role->has_cap( Settings::CAP_TOKENIZE ) );

		Settings::grant_default_capability();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
	}

	public function test_grant_default_capability_is_idempotent() {
		Settings::grant_default_capability();
		Settings::grant_default_capability();
		$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
	}

	public function test_get_license_default_falls_back_to_all_rights_reserved() {
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
		$this->assertSame( 'All Rights Reserved', Settings::get_license_default() );
	}

	public function test_get_license_default_reads_the_saved_option() {
		update_option( Settings::OPTION_LICENSE_DEFAULT, 'CC BY-SA' );
		$this->assertSame( 'CC BY-SA', Settings::get_license_default() );
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
	}

	public function test_get_ai_policy_default_falls_back_to_allowed() {
		delete_option( Settings::OPTION_AI_POLICY_DEFAULT );
		$this->assertSame( 'Allowed', Settings::get_ai_policy_default() );
	}

	public function test_get_ai_policy_default_reads_the_saved_option() {
		update_option( Settings::OPTION_AI_POLICY_DEFAULT, 'Not Allowed' );
		$this->assertSame( 'Not Allowed', Settings::get_ai_policy_default() );
		delete_option( Settings::OPTION_AI_POLICY_DEFAULT );
	}

	public function test_add_collection_appends_it_to_the_list_and_makes_it_the_default_when_none_exists() {
		delete_option( Settings::OPTION_COLLECTIONS );
		delete_option( Settings::OPTION_COLLECTION );

		Settings::add_collection( '0xaaa', 'General' );

		$this->assertSame( array( array( 'contract' => '0xaaa', 'label' => 'General' ) ), Settings::get_collections() );
		$this->assertSame( '0xaaa', Settings::get_default_collection() );
	}

	public function test_add_collection_does_not_change_the_default_once_one_is_already_set() {
		delete_option( Settings::OPTION_COLLECTIONS );
		Settings::add_collection( '0xaaa', 'General' );
		Settings::add_collection( '0xbbb', 'News' );

		$this->assertSame( '0xaaa', Settings::get_default_collection() );
		$this->assertCount( 2, Settings::get_collections() );
	}

	public function test_add_collection_deduplicates_by_contract() {
		delete_option( Settings::OPTION_COLLECTIONS );
		Settings::add_collection( '0xaaa', 'General' );
		Settings::add_collection( '0xaaa', 'General (renamed)' );

		$collections = Settings::get_collections();
		$this->assertCount( 1, $collections );
		$this->assertSame( 'General (renamed)', $collections[0]['label'] );
	}

	public function test_resolve_collection_for_post_uses_the_category_map_when_present() {
		delete_option( Settings::OPTION_COLLECTIONS );
		delete_option( Settings::OPTION_COLLECTION );
		delete_option( Settings::OPTION_CATEGORY_MAP );

		Settings::add_collection( '0xdefa01', 'Default' );
		Settings::add_collection( '0xbbb22', 'News' );

		$news_cat_id = $this->factory->category->create( array( 'name' => 'News' ) );
		$post_id     = $this->factory->post->create( array( 'post_category' => array( $news_cat_id ) ) );

		Settings::save_category_map( array( $news_cat_id => '0xbbb22' ) );

		$this->assertSame( '0xbbb22', Settings::resolve_collection_for_post( $post_id ) );
	}

	public function test_resolve_collection_for_post_falls_back_to_the_default_collection() {
		delete_option( Settings::OPTION_COLLECTIONS );
		delete_option( Settings::OPTION_COLLECTION );
		delete_option( Settings::OPTION_CATEGORY_MAP );

		Settings::add_collection( '0xdefa01', 'Default' );
		$post_id = $this->factory->post->create();

		$this->assertSame( '0xdefa01', Settings::resolve_collection_for_post( $post_id ) );
	}
}

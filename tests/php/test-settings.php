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
}

<?php

// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid

class VIP_Go__Core__Default_VIP_Jetpack_Version extends WP_UnitTestCase {
	public function test__vip_default_jetpack_version() {
		global $wp_version;
		$saved_wp_version = $wp_version;

		$latest = '12.3';

		$versions_map = [
			// WordPress version => Jetpack version
			'5.8.6' => '10.9',
			'5.9'   => '11.4',
			'5.9.5' => '11.4',
			'6.0'   => '12.0',
			'6.1'   => $latest,
			'6.1.1' => $latest,
		];

		foreach ( $versions_map as $wordpress_version => $jetpack_version ) {
			$wp_version = $wordpress_version;
			$this->assertEquals( vip_default_jetpack_version(), $jetpack_version );
		}

		// Reset back to original value.
		$wp_version = $saved_wp_version;
	}
}

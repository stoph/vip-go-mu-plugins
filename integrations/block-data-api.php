<?php

namespace Automattic\VIP\Integrations;

/**
 * Loads VIP Block Data REST API.
 *
 * @private
 */
class BlockDataApi extends Integration {

	/**
	 * The version of the Block Data API plugin to load, that's set to the latest version.
	 * This should be higher than the lowestVersion set in https://github.com/Automattic/vip-go-mu-plugins-ext/blob/trunk/config.json#L63
	 * 
	 * @var string
	 */
	protected string $version = '1.0';

	/**
	 * Applies hooks to load Block Data API plugin.
	 *
	 * @private
	 */
	public function load( array $config ): void {
		// Wait until plugins_loaded to give precedence to the plugin in the customer repo
		add_action( 'plugins_loaded', function() {
			// Do not load plugin if already loaded by customer code
			if ( defined( 'VIP_BLOCK_DATA_API_LOADED' ) ) {
				return;
			}

			// Load the version of the plugin that should be set to the latest version, otherwise if it's not found deactivate the integration
			$load_path = WPMU_PLUGIN_DIR . '/vip-integrations/vip-block-data-api-' . $this->version . '/vip-block-data-api.php';
			if ( file_exists( $load_path ) ) {
				require_once $load_path;
			} else {
				$this->is_active = false;
			}
		} );
	}
}

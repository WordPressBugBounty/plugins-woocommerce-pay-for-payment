<?php

namespace Pay4Payment;

/**
 * Paths and URLs anchored to the plugin's main file.
 *
 * The main file is injected once (DI container definition in
 * woocommerce-payforpayment.php) so that no other class has to know
 * where on disk the plugin lives.
 */
class PluginUtils {

	/**
	 * Absolute path of the plugin's main file.
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Plugin basename, i.e. `directory/main-file.php`.
	 *
	 * This is the identifier WordPress uses for a plugin in hook names such as
	 * `plugin_action_links_{basename}`.
	 *
	 * @return string
	 */
	public function get_plugin_basename(): string {
		return plugin_basename( $this->plugin_file );
	}

	/**
	 * Absolute filesystem path of a file inside the plugin directory.
	 *
	 * @param string $file Path relative to the plugin root, e.g. `build/settings.js`.
	 * @return string
	 */
	public function get_plugin_path( string $file ): string {
		return wp_normalize_path( plugin_dir_path( $this->plugin_file ) . $file );
	}

	/**
	 * Public URL of a file inside the plugin directory.
	 *
	 * @param string $file Path relative to the plugin root, e.g. `build/settings.js`.
	 * @return string
	 */
	public function get_plugin_url( string $file ): string {
		return plugins_url( $file, $this->plugin_file );
	}

}

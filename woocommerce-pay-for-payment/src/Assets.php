<?php

namespace Pay4Payment;

use Pay4Payment\Helpers\Cast;

/**
 * Enqueues the plugin's built assets.
 *
 * Callers name a bundle by its plugin-relative path (`build/settings.js`) and
 * a handle; the public URL, the script dependencies and the cache-busting
 * version all come from the `*.asset.php` sidecar that `@wordpress/scripts`
 * writes next to each bundle, so none of it has to be repeated by hand and
 * none of it goes stale on a rebuild.
 *
 * Call this from `admin_enqueue_scripts` / `wp_enqueue_scripts`, as WordPress
 * requires of any enqueue.
 */
class Assets {

	/**
	 * Plugin utils
	 * @var PluginUtils
	 */
	private $utils;

	/**
	 * Constructor.
	 *
	 * @param PluginUtils $utils Plugin utils.
	 */
	public function __construct( PluginUtils $utils ) {
		$this->utils = $utils;
	}

	/**
	 * Register and enqueue a built asset.
	 *
	 * @param string $file Path relative to the plugin root, e.g. `build/settings.js`.
	 * @param string $handle Script/style handle.
	 * @param bool $in_footer Print the script in the footer. Scripts only.
	 * @param array<string, mixed> $variables Globals to print before the script, as name => value. Scripts only.
	 * @return void
	 */
	public function enqueue( string $file, string $handle, bool $in_footer = false, array $variables = array() ): void {

		$src  = $this->utils->get_plugin_url( $file );
		$meta = $this->read_sidecar( $file );

		if ( str_ends_with( $file, '.css' ) ) {
			// Only the version carries over. The sidecar lists SCRIPT handles
			// (`wp-element`, …), and handing those to wp_enqueue_style() would
			// send WordPress looking for stylesheets that do not exist and drop
			// the enqueue.
			wp_enqueue_style( $handle, $src, array(), $meta['version'] );
			return;
		}

		wp_enqueue_script( $handle, $src, $meta['dependencies'], $meta['version'], $in_footer );

		// Has to follow the enqueue: wp_set_script_translations() silently does
		// nothing for a handle that is not registered yet. A bundle that imports
		// @wordpress/i18n carries `wp-i18n` here; one that does not has no
		// strings to load a catalogue for.
		if ( in_array( 'wp-i18n', $meta['dependencies'], true ) ) {
			wp_set_script_translations( $handle, 'woocommerce-pay-for-payment', $this->utils->get_plugin_path( 'languages' ) );
		}

		foreach ( $variables as $name => $value ) {
			wp_add_inline_script( $handle, 'var ' . $name . ' = ' . wp_json_encode( $value ) . ';', 'before' );
		}
	}

	/**
	 * Read the build metadata emitted next to a bundle.
	 *
	 * A missing sidecar is not an error: it is what a hand-written asset or a
	 * stale build looks like, and the plugin version is a good enough cache
	 * buster in that case.
	 *
	 * @param string $file Path relative to the plugin root.
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private function read_sidecar( string $file ): array {

		$dot     = strrpos( $file, '.' );
		$sidecar = $this->utils->get_plugin_path( ( false === $dot ? $file : substr( $file, 0, $dot ) ) . '.asset.php' );

		/** @var mixed $meta */
		$meta = is_readable( $sidecar ) ? require $sidecar : array();

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$dependencies = $meta['dependencies'] ?? array();
		$version      = $meta['version'] ?? null;

		return array(
			// A malformed sidecar must not reach wp_enqueue_script(), which
			// would take a non-string handle straight into a fatal. The outer
			// array_values() is for PHPStan only — Cast::to_string_list()
			// already re-indexes; the return shape below needs a list, and the
			// analyser does not treat that method's @return as certain.
			'dependencies' => array_values( Cast::to_string_list( (array) $dependencies ) ),
			'version'      => is_string( $version ) ? $version : PAY4PAYMENT_VERSION,
		);
	}

}

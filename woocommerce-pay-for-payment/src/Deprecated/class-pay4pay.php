<?php

use Pay4Payment\Helpers\WooCommerce;

/**
 * Pay4Pay
 *
 * Cleaned up original class-pay4pay.php - only show deprecations and call new methods, hopefully it will fix potential the compatibility issues.
 *
 * @package	Pay4Pay
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- v2's exact class name on purpose: this shim exists so third-party code that still calls Pay4Pay:: gets a deprecation notice instead of a fatal.
class Pay4Pay {

	/**
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) )
			self::$_instance = new self();
		return self::$_instance;
	}

	private function __construct() {

		// WordPress' own deprecation path rather than a bare trigger_error: it
		// honours WP_DEBUG, so a live store is not flooded with E_USER_DEPRECATED
		// by third-party code that still constructs this shim, and it fires
		// `deprecated_function_run` for anything listening.
		\_deprecated_function( __CLASS__ . '::__construct', '3.0.0' );

	}

	/**
	 * @return \WC_Payment_Gateway|false
	 */
	public function get_current_gateway() {

		\_doing_it_wrong( 'get_current_gateway', 'This method has been deprecated and will be removed soon. Use Pay4Payment\\Helpers\\WooCommerce::get_current_gateway() instead.', '3.0.0' );
		$wc_helper = new WooCommerce();
		return $wc_helper->get_current_gateway();


	}

	/**
	 * @return array<string, string>
	 */
	public function get_woocommerce_tax_classes() {

		\_doing_it_wrong( 'get_woocommerce_tax_classes', 'This method has been deprecated and will be removed soon. Use your own code instead.', '3.0.0' );
		$tax_class_options = array('inherit' => __('Payment fee tax class based on cart items', 'woocommerce-pay-for-payment')) +  \wc_get_product_tax_class_options(); // Since 3.0

		return $tax_class_options;
	}
}

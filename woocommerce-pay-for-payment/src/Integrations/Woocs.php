<?php

namespace Pay4Payment\Integrations;

/**
 * FOX – Currency Switcher for WooCommerce (WOOCS) integration.
 *
 * Ported from v2 (Pay4Pay_WOOCS).
 *
 * @see https://wordpress.org/plugins/woocommerce-currency-switcher/
 */
class Woocs extends AbstractMultiCurrency {

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return class_exists( 'WOOCS' );
	}

	/**
	 * @inheritDoc
	 */
	public function convert( $fee ) {
		/** @var \WOOCS $WOOCS */
		global $WOOCS;

		return $WOOCS->woocs_exchange_value( $fee );
	}

}

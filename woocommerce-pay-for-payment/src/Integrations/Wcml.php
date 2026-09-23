<?php

namespace Pay4Payment\Integrations;

use Pay4Payment\Helpers\Cast;

/**
 * WooCommerce Multilingual & Multicurrency (WCML) integration.
 *
 * Converts the configured fee amounts to the customer's currency.
 * Ported from v2 (Pay4Pay_WCML).
 *
 * @see https://wordpress.org/plugins/woocommerce-multilingual/
 */
class Wcml extends AbstractMultiCurrency {

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return defined( 'WCML_VERSION' );
	}

	/**
	 * @inheritDoc
	 */
	public function convert( $fee ) {
		return Cast::to_float( apply_filters( 'wcml_raw_price_amount', $fee ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WCML's own hook, invoked here rather than defined.
	}

}

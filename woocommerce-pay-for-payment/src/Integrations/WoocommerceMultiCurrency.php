<?php

namespace Pay4Payment\Integrations;

/**
 * WooCommerce Multi-currency (TIV.NET / WOOMC) integration.
 *
 * Ported from v2 (Pay4Pay_WooCommerce_MultiCurrency).
 *
 * @see https://woocommerce.com/products/multi-currency/
 */
class WoocommerceMultiCurrency extends AbstractMultiCurrency {

	/**
	 * Memoized WOOMC price controller — the filters fire several times per
	 * cart recalculation and the controller graph is expensive to build.
	 * @var \WOOMC\Price\Controller|null
	 */
	private $price_controller;

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return defined( 'WOOCOMMERCE_MULTICURRENCY_VERSION' ) && version_compare( WOOCOMMERCE_MULTICURRENCY_VERSION, '2.5.0', '>=' );
	}

	/**
	 * @inheritDoc
	 */
	public function convert( $fee ) {
		return $this->price_controller()->convert( $fee );
	}

	/**
	 * Build the WOOMC price controller once.
	 *
	 * @return \WOOMC\Price\Controller
	 */
	private function price_controller() {
		if ( null === $this->price_controller ) {
			$rate_storage     = new \WOOMC\Rate\Storage();
			$price_rounder    = new \WOOMC\Price\Rounder();
			$price_calculator = new \WOOMC\Price\Calculator( $rate_storage, $price_rounder );

			$this->price_controller = new \WOOMC\Price\Controller( $price_calculator, new \WOOMC\Currency\Detector() );
		}

		return $this->price_controller;
	}

}

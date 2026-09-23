<?php

namespace Pay4Payment\Integrations;

/**
 * Base for multi-currency integrations that convert the configured fee
 * amounts (fixed, minimum, maximum) via the v2-compatible
 * `woocommerce_pay4pay_charges_*` filters.
 */
abstract class AbstractMultiCurrency implements IntegrationInterface {

	/**
	 * @inheritDoc
	 */
	public function setup() {
		$this->register_charge_filters();
	}

	/**
	 * Register the conversion filter on all charge amounts.
	 *
	 * @return void
	 */
	final protected function register_charge_filters() {
		add_filter( 'woocommerce_pay4pay_charges_fixed', array( $this, 'convert' ) );
		add_filter( 'woocommerce_pay4pay_charges_minimum', array( $this, 'convert' ) );
		add_filter( 'woocommerce_pay4pay_charges_maximum', array( $this, 'convert' ) );
	}

	/**
	 * Return the gateway fee converted by the exchange rate.
	 *
	 * @param float $fee The gateway fee.
	 * @return float
	 */
	abstract public function convert( $fee );

}

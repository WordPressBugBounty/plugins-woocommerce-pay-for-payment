<?php

namespace Pay4Payment\Integrations;

/**
 * WooCommerce Price Based on Country (PBoC) integration.
 *
 * Converts the configured fee amounts using the active pricing zone.
 * Ported from v2 (Pay4Pay_Price_Based_Country).
 *
 * @see https://wordpress.org/plugins/woocommerce-product-price-based-on-countries/
 */
class PriceBasedCountry extends AbstractMultiCurrency {

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return function_exists( 'wcpbc' ) && version_compare( wcpbc()->version, '1.8', '>=' );
	}

	/**
	 * @inheritDoc
	 *
	 * PBoC fires its pricing-init action from `woocommerce_init` — inside
	 * WooCommerce's own `init:0` callback, which is registered at include
	 * time and so runs before `Integrations::load()`, itself hooked to
	 * `init:0` only during `plugins_loaded`. On a frontend request the action
	 * has therefore already fired by the time this runs, and it never fires
	 * again, so subscribing alone left the conversion filters off for the
	 * whole request: the fee was charged at its raw base-currency amount in
	 * the customer's zone currency (#152). Register straight away in that
	 * case, and keep the subscription for the paths where the action is still
	 * to come (PBoC's own `init:9999` ajax fallback).
	 */
	public function setup() {
		if ( did_action( 'wc_price_based_country_frontend_princing_init' ) ) {
			$this->frontend_pricing_init();
			return;
		}

		add_action( 'wc_price_based_country_frontend_princing_init', array( $this, 'frontend_pricing_init' ) );
	}

	/**
	 * Register the conversion filters once PBoC frontend pricing is initialized.
	 *
	 * @return void
	 */
	public function frontend_pricing_init() {
		$this->register_charge_filters();
	}

	/**
	 * @inheritDoc
	 */
	public function convert( $fee ) {
		$zone = wcpbc_the_zone();

		// The filters are registered from PBoC's own frontend-pricing init,
		// so a zone is always resolved by then; stay a no-op if it is not.
		return $zone ? $zone->get_exchange_rate_price( $fee ) : $fee;
	}

}

<?php

namespace Pay4Payment;

use Pay4Payment\Integrations\IntegrationInterface;
use Pay4Payment\Integrations\PriceBasedCountry;
use Pay4Payment\Integrations\Wcml;
use Pay4Payment\Integrations\WooMultiCurrency;
use Pay4Payment\Integrations\WoocommerceMultiCurrency;
use Pay4Payment\Integrations\Woocs;
use Pay4Payment\Integrations\Wpml;
use Pay4Payment\Interfaces\ComponentInterface;

/**
 * Loads 3rd-party plugin integrations: multi-currency conversion of the
 * configured fee amounts (via the `woocommerce_pay4pay_charges_*` filters)
 * and WPML string registration.
 */
class Integrations implements ComponentInterface {

	/**
	 * Registered integrations.
	 * @var IntegrationInterface[]
	 */
	private $integrations;

	/**
	 * Constructor.
	 *
	 * @param IntegrationInterface[]|null $integrations Integrations override (used in tests).
	 */
	public function __construct( ?array $integrations = null ) {
		$this->integrations = $integrations ?? array(
			new Wcml(),
			new PriceBasedCountry(),
			new WooMultiCurrency(),
			new WoocommerceMultiCurrency(),
			new Woocs(),
			new Wpml(),
		);
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		// Defer the detection until all plugins have loaded their constants.
		add_action( 'init', array( $this, 'load' ), 0 );
	}

	/**
	 * Register hooks of all active integrations.
	 * @return void
	 */
	public function load() {
		foreach ( $this->integrations as $integration ) {
			if ( $integration->is_active() ) {
				$integration->setup();
			}
		}
	}

}

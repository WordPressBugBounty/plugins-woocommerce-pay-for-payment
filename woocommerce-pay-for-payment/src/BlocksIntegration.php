<?php

namespace Pay4Payment;

use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;

/**
 * Class BlocksIntegration
 *
 * Server half of blocks-checkout (Store API) support. The blocks client
 * pushes the chosen payment method via `extensionCartUpdate({ namespace:
 * 'pay4payment', data: { payment_method } })`; WooCommerce's
 * CartExtensionsSchema then invokes our registered update callback and
 * recalculates totals in the same request. All this class does is validate
 * the pushed gateway id and write it into the WC session, so the existing
 * `woocommerce_cart_calculate_fees` fee engine (Frontend::) picks it up via
 * Helpers\WooCommerce::get_current_gateway() exactly as it does for classic
 * checkout.
 */
class BlocksIntegration implements ComponentInterface {

	/**
	 * Store API extension namespace. Must match the blocks-checkout client
	 * constant.
	 */
	const STORE_API_NAMESPACE = 'pay4payment';

	/**
	 * Assets
	 * @var Assets
	 */
	private $asset;

	/**
	 * WooCommerce helpers (the WC-runtime adapter)
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Constructor.
	 * @param Assets $asset Assets.
	 * @param WooCommerce $helpers_wc WooCommerce helpers.
	 */
	public function __construct(Assets $asset, WooCommerce $helpers_wc) {
		$this->asset = $asset;
		$this->helpers_wc = $helpers_wc;
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_update_callback' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_script' ) );

	}

	/**
	 * Register the Store API extension update callback for the
	 * `pay4payment` namespace.
	 *
	 * @return void
	 */
	public function register_update_callback() {

		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return; // WC too old / blocks package missing — degrade silently.
		}

		woocommerce_store_api_register_update_callback( array(
			'namespace' => self::STORE_API_NAMESPACE,
			'callback'  => array( $this, 'sync_payment_method' ),
		) );

	}

	/**
	 * Validate the payment method pushed from the blocks checkout and, if
	 * it matches a registered gateway, store it as the chosen payment
	 * method in the WC session.
	 *
	 * Validates against `payment_gateways()` (all registered gateways, not
	 * just the "available" ones) — deliberately matching
	 * Helpers\WooCommerce::get_current_gateway()'s Stripe zero-total quirk
	 * avoidance.
	 *
	 * @param array|mixed $data Request `data` from extensionCartUpdate.
	 * @return void
	 */
	public function sync_payment_method( $data ) {

		$method = is_array( $data ) && isset( $data['payment_method'] ) && is_string( $data['payment_method'] )
			? $data['payment_method']
			: '';

		if ( '' === $method ) {
			return;
		}

		$gateways = $this->helpers_wc->payment_gateways();

		if ( ! isset( $gateways[ $method ] ) ) {
			return; // Never store arbitrary strings in session.
		}

		$this->helpers_wc->set_chosen_payment_method( $method );

	}

	/**
	 * Enqueue the blocks-checkout client script that pushes the chosen
	 * payment method to the server via extensionCartUpdate.
	 *
	 * Guarded by `has_block( 'woocommerce/checkout' )`: this misses the
	 * checkout block when it is nested inside a block-theme template part
	 * rather than placed directly on the page — documented graceful
	 * degradation (see readme FAQ/QA), not a bug.
	 *
	 * @return void
	 */
	public function enqueue_checkout_script() {

		if ( ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		$this->asset->enqueue( 'build/blocks-checkout.js', 'pay4payment-blocks-checkout', true, array(
			'pay4paymentBlocksCheckout' => array(
				'chosenPaymentMethod' => $this->helpers_wc->get_chosen_payment_method(),
			),
		) );

	}

}

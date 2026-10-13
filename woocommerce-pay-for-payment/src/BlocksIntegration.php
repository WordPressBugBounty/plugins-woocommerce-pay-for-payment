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
 * recalculates totals in the same request. That callback validates the
 * pushed gateway id and writes it into the WC session, so the existing
 * `woocommerce_cart_calculate_fees` fee engine (Frontend::) picks it up via
 * Helpers\WooCommerce::get_current_gateway() exactly as it does for classic
 * checkout.
 *
 * That push is a courtesy of the client, not a guarantee: the place-order
 * request (`POST wc/store/v1/checkout`) carries its own `payment_method`,
 * and core only writes it into the session AFTER the cart fees — and the
 * draft order built from them — are computed. A shopper who skips the push
 * and posts a fee-bearing gateway straight to the checkout route would get
 * an order for that gateway without its fee (Patchstack VDP, CVSS 5.3). So
 * the checkout request's gateway is also read server-side, before the
 * route runs ({@see capture_checkout_payment_method()}), and pinned for
 * the request through `Helpers\WooCommerce::set_request_payment_method()`,
 * which `get_chosen_payment_method()` consults ahead of the session. Fee
 * calculation then follows the gateway the order is paid with, whatever
 * the client did or did not send first. Every WooCommerce the plugin
 * supports dispatches through the same WordPress filter, so this does not
 * depend on the Store API's hook set or its internal ordering.
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
		add_filter( 'rest_request_before_callbacks', array( $this, 'capture_checkout_payment_method' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'release_checkout_payment_method' ), 10, 1 );

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

		$method = $this->registered_gateway_id( is_array( $data ) ? $data : array() );

		if ( '' === $method ) {
			return;
		}

		$this->helpers_wc->set_chosen_payment_method( $method );

	}

	/**
	 * `rest_request_before_callbacks`: on a Store API checkout request
	 * (`POST`/`PUT`/`PATCH wc/store/v{n}/checkout` — the place-order route,
	 * not `/checkout/{ID}`, whose order already carries its gateway and is
	 * `OrderFeeSync`'s), pin the request's `payment_method` for the rest of
	 * the request (class docblock). Validated against the registered
	 * gateways, as the cart-extension push is; an unknown id stays out and
	 * core rejects it with its own 400 further down the route. The response
	 * passes through untouched: an observer on the filter, never an answer.
	 *
	 * @param mixed $response Result to send to the client; null while unset.
	 * @param mixed $handler Route handler (unused).
	 * @param mixed $request The request being dispatched.
	 * @return mixed The response, unchanged.
	 */
	public function capture_checkout_payment_method( $response, $handler, $request ) {

		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}

		// Case-insensitive, as WordPress matches routes (`WP_REST_Server::match_request_to_handler()`):
		// `/wc/store/v1/Checkout` reaches the same handler and must pin the same.
		if ( 1 !== preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#i', $request->get_route() ) ) {
			return $response;
		}

		$method = $this->registered_gateway_id( $request->get_params() );

		if ( '' !== $method ) {
			$this->helpers_wc->set_request_payment_method( $method );
		}

		return $response;

	}

	/**
	 * `rest_request_after_callbacks`: the route has run, the pin has done
	 * its job — clear it. One HTTP request is one route, except inside
	 * `wc/store/v1/batch`, where sub-requests share the PHP process and a
	 * checkout sub-request's gateway must not become a later cart
	 * sub-request's. Response passed through untouched.
	 *
	 * @param mixed $response Result to send to the client.
	 * @return mixed The response, unchanged.
	 */
	public function release_checkout_payment_method( $response ) {

		$this->helpers_wc->set_request_payment_method( '' );

		return $response;

	}

	/**
	 * The `payment_method` of a payload, when it names a registered
	 * gateway; '' otherwise (absent, not a string, or unknown — never an
	 * arbitrary string, whichever store it is bound for).
	 *
	 * @param array<mixed> $data Payload (cart-extension `data`, or the checkout request's params).
	 * @return string
	 */
	private function registered_gateway_id( array $data ): string {

		$method = isset( $data['payment_method'] ) && is_string( $data['payment_method'] )
			? $data['payment_method']
			: '';

		if ( '' === $method ) {
			return '';
		}

		$gateways = $this->helpers_wc->payment_gateways();

		return isset( $gateways[ $method ] ) ? $method : '';

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

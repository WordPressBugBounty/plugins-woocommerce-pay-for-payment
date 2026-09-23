<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\PickerOptions;
use Pay4Payment\Interfaces\ComponentInterface;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only REST source for the conditions builder's pickers (CONTEXT.md →
 * Condition options).
 *
 * `GET pay4payment/v1/condition-options` answers with the bounded lists in
 * one payload — coupons (capped), shipping methods (zone-labelled),
 * shipping zones, shipping classes, countries + states, user roles,
 * product categories —
 * one round-trip for a settings page that already batches its loads.
 * `GET pay4payment/v1/condition-products?search=…|include=…` is the
 * unbounded one: products are searched on demand and `include=` re-hydrates
 * labels for ids a saved rule already stores, so it never renders as bare
 * numbers. Every entry is a `{value, label}` pair whose `value` is exactly
 * what `Pro\ConditionEvaluator` matches on — the wire format is the
 * evaluator's, never the label.
 *
 * A thin shaper: every store read is a builder on `Helpers\PickerOptions`.
 * Cheap by construction rather than cached: bounded lists only
 * (WooCommerce's country/state tables are in-memory arrays, categories
 * and classes are taxonomy reads, coupons capped), products never
 * enumerated — and WordPress sends no-cache headers on authenticated
 * REST reads anyway. Ships in both builds (inert without the Pro UI),
 * which keeps the free build's `Admin` free of a stripped-file dependency.
 */
class ConditionOptionsApi implements ComponentInterface {

	/**
	 * Coupon list cap. Deliberately no coupon search endpoint: the code
	 * IS the value a rule stores (with `*` wildcards), so the list is a
	 * convenience and the rules builder (#112) must accept typed codes —
	 * a store with thousands of coupons loses nothing but the dropdown.
	 */
	public const MAX_COUPONS = 100;

	/**
	 * Product search hits cap.
	 */
	public const MAX_PRODUCTS = 20;

	/**
	 * REST namespace — the settings route's (`RestApi::ROUTE_NAMESPACE`).
	 */
	private const ROUTE_NAMESPACE = RestApi::ROUTE_NAMESPACE;

	/**
	 * The picker option builders
	 * @var PickerOptions
	 */
	private $pickers;

	/**
	 * Constructor.
	 *
	 * @param PickerOptions $pickers The picker option builders.
	 */
	public function __construct( PickerOptions $pickers ) {
		$this->pickers = $pickers;
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the two read routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, '/condition-options', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_condition_options' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( self::ROUTE_NAMESPACE, '/condition-products', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_condition_products' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'search'  => array(
					'description'       => 'Product search term.',
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'include' => array(
					'description'       => 'Product ids to resolve labels for — a comma-separated list or an array.',
					'type'              => 'array',
					'items'             => array( 'type' => 'integer' ),
					'default'           => array(),
					// Core's list parser: a comma string or an array → distinct positive ints.
					'sanitize_callback' => 'wp_parse_id_list',
				),
			),
		) );
	}

	/**
	 * Whether the current user may read store data for the settings UI.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The bounded picker lists, one payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_condition_options() {
		return new WP_REST_Response( array(
			'coupons'          => $this->pickers->coupon_options( self::MAX_COUPONS ),
			'shipping_methods' => $this->pickers->shipping_method_options(),
			'shipping_zones'   => $this->pickers->shipping_zone_options(),
			'shipping_classes' => $this->pickers->shipping_class_options(),
			'countries'        => $this->pickers->country_options(),
			'roles'            => $this->pickers->user_role_options(),
			'categories'       => $this->pickers->product_category_options(),
		) );
	}

	/**
	 * Product options: `?include=` re-hydrates stored ids and wins,
	 * `?search=` runs a capped term search; neither is an empty list. The
	 * route's `wp_parse_id_list` parses the parameter into a list; the
	 * builder's id normaliser (`positive_ints()`, shared with the search
	 * hits) is what decides which entries are ids.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_condition_products( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->pickers->product_options(
			trim( Cast::to_string( $request->get_param( 'search' ) ) ),
			(array) $request->get_param( 'include' ),
			self::MAX_PRODUCTS
		) );
	}
}

<?php

namespace Pay4Payment\Helpers;

/**
 * The single seam to the `WC()` global.
 *
 * The WooCommerce singleton serves several properties (`payment_gateways`
 * among them) lazily via `__get()` with no matching `__isset()`, so
 * `empty()`/`isset()` on them silently lie (they short-circuit to "not
 * set" even though a direct read returns a real object) — see
 * `13ab421` for the bug this caused. Every accessor here is therefore
 * written in boolean context (`if ( $thing )`, never `empty()`/`isset()`
 * on a WC() magic property) and returns a safe scalar/array fallback
 * instead of null, so callers never need to know about this quirk.
 *
 * No other module may call `WC()` directly.
 */
class WooCommerce {

	/**
	 * The class-costs bucket for basket shares WooCommerce's negative-fee
	 * distribution weighs but never taxes (core's own bucket name) — the
	 * consumer recognises it by its empty rates, never by this name.
	 */
	private const NON_TAXABLE_BUCKET = 'non-taxable';

	/**
	 * Resolved shipping zone ids per shipping-method instance id, for the request.
	 * @var array<int, int|null>
	 */
	private $zone_ids = array();

	/**
	 * The gateway a Store API checkout request POSTed, '' outside one —
	 * read ahead of the session by {@see get_chosen_payment_method()}. Why:
	 * `BlocksIntegration` class docblock. Instance state, so it relies on
	 * the container handing `BlocksIntegration` and `Frontend` the one
	 * shared adapter (PHP-DI autowire entries are shared by default).
	 * @var string
	 */
	private $request_payment_method = '';

	/**
	 * The WC() singleton, or null when WooCommerce is not (yet) loaded.
	 *
	 * @return \WooCommerce|null
	 */
	private function wc() {
		return function_exists( 'WC' ) ? WC() : null;
	}

	/**
	 * The gateways registry, or null when WooCommerce (or the registry) is
	 * not loaded.
	 *
	 * Property-form read (`WC()->payment_gateways`), magic-safe: guarded
	 * by boolean context, never empty()/isset() — the one place that touches
	 * that property, so the quirk is handled once.
	 *
	 * @return \WC_Payment_Gateways|null
	 */
	private function gateways_registry() {
		$wc = $this->wc();
		return $wc && $wc->payment_gateways ? $wc->payment_gateways : null;
	}

	/**
	 * All registered payment gateways, id-keyed.
	 *
	 * @return \WC_Payment_Gateway[]
	 */
	public function payment_gateways(): array {
		$registry = $this->gateways_registry();
		return $registry ? $registry->payment_gateways() : array();
	}

	/**
	 * All registered payment gateway ids.
	 *
	 * @return string[]
	 */
	public function gateway_ids(): array {
		$registry = $this->gateways_registry();
		return $registry ? $registry->get_payment_gateway_ids() : array();
	}

	/**
	 * The chosen shipping methods from the WC session, one entry per
	 * shipping package in package order. WooCommerce stores `false` for a
	 * package that has no chosen method (no rates); that comes back as ''
	 * so callers always get strings.
	 *
	 * @return string[]
	 */
	public function chosen_shipping_methods(): array {
		$methods = $this->session_get( 'chosen_shipping_methods' );
		if ( ! is_array( $methods ) ) {
			return array();
		}

		return array_map(
			static function ( $method ) {
				return is_string( $method ) ? $method : '';
			},
			$methods
		);
	}

	/**
	 * The chosen shipping of the cart — the first package's
	 * "method:instance" string and its bare method id, plus the bare method
	 * id of *every* package: the facts the guard chain reads. The COD rule
	 * and tier conditions look at the first package only (v2 parity), the
	 * free-shipping disable rule at all of them (v2 `preg_grep`'d the whole
	 * list — issue #145). `chosen`/`method` are '' and `methods` empty when
	 * nothing is chosen. The one cart-side home of the shipping wire-format
	 * parse (`Pro\OrderSnapshot::chosen_shipping()` is the order-side twin
	 * with the same shape).
	 *
	 * @return array{chosen: string, method: string, methods: string[]}
	 */
	public function chosen_cart_shipping(): array {
		$methods = array_values( $this->chosen_shipping_methods() );
		$bare = array_map( array( self::class, 'shipping_method_id' ), $methods );

		return array(
			'chosen' => $methods[0] ?? '',
			'method' => $bare[0] ?? '',
			'methods' => $bare,
		);
	}

	/**
	 * The bare method id of one chosen "method:instance" string — the parse
	 * itself, so the order-side twin and the test double of this adapter
	 * share it instead of each keeping a copy.
	 *
	 * @param string $chosen Chosen shipping "method:instance" string.
	 * @return string
	 */
	public static function shipping_method_id( string $chosen ): string {
		$parts = explode( ':', $chosen );

		return (string) array_shift( $parts );
	}

	/**
	 * The instance id of one chosen "method:instance" string — the twin of
	 * {@see shipping_method_id()}, so the two halves of the wire format are
	 * parsed in one place; 0 when the string names no instance (a non-zone
	 * method, nothing chosen). Unlike the method half, which the order-side
	 * twin and this adapter's test double also parse, the instance half is
	 * only ever asked for here, by `shipping_zone_id()`.
	 *
	 * @param string $chosen Chosen shipping "method:instance" string.
	 * @return int
	 */
	private static function shipping_instance_id( string $chosen ): int {
		$parts = explode( ':', $chosen );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}

	/**
	 * The chosen payment method id for this request: the WC session value,
	 * behind a filter for flows where the session cannot know the method —
	 * express wallet sheets fetch their totals through Store API requests
	 * that never touch the session, so the express integration resolves the
	 * gateway per request instead (#115). One seam feeding both consumers:
	 * `get_current_gateway()` (which gateway's fee is calculated) and the
	 * display-controls chosen-method signal (so hide-on-cart never withholds
	 * the fee from an express sheet).
	 *
	 * @return string
	 */
	public function get_chosen_payment_method(): string {
		$method = '' !== $this->request_payment_method
			? $this->request_payment_method
			: $this->session_get( 'chosen_payment_method' );

		/**
		 * Filters the chosen payment method id for the current request.
		 *
		 * @since 3.0.0
		 * @param string $method Gateway id — the one a Store API checkout request POSTed, else the WC session's, '' when none.
		 */
		return Cast::to_string( apply_filters( 'pay4payment_chosen_payment_method', is_string( $method ) ? $method : '' ) );
	}

	/**
	 * Pin the gateway a Store API checkout request POSTed for the rest of
	 * this request ({@see get_chosen_payment_method()} reads it ahead of
	 * the session). Request-scoped on purpose: the session is core's to
	 * write, and nothing pinned here outlives the request. The caller
	 * validates the id against the registered gateways.
	 *
	 * @param string $id Gateway id, '' to clear.
	 * @return void
	 */
	public function set_request_payment_method( string $id ): void {
		$this->request_payment_method = $id;
	}

	/**
	 * Store the chosen payment method id in the WC session.
	 *
	 * @param string $id Gateway id.
	 * @return void
	 */
	public function set_chosen_payment_method( string $id ): void {
		$wc = $this->wc();
		$session = $wc ? $wc->session : null;
		if ( $session ) {
			$session->set( 'chosen_payment_method', $id );
		}
	}

	/**
	 * Role slugs of the current user; empty for guests.
	 *
	 * WP (not WC) data, but reading the runtime is this adapter's job —
	 * the fee modules stay pure.
	 *
	 * @return string[]
	 */
	public function current_user_roles(): array {
		return $this->roles_of( wp_get_current_user() );
	}

	/**
	 * Role slugs of a user object (`WP_User` or whatever a lookup returned);
	 * empty when it has none.
	 *
	 * @param mixed $user User object, or false/null.
	 * @return string[]
	 */
	private function roles_of( $user ): array {
		$roles = is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		$slugs = array();
		foreach ( $roles as $role ) {
			if ( is_scalar( $role ) ) {
				$slugs[] = (string) $role;
			}
		}

		return $slugs;
	}

	/**
	 * Id of the shipping zone serving a chosen shipping method
	 * ("method_id:instance_id"), or null when it cannot be resolved
	 * (nothing chosen, a non-zone method, or the zone lookup failing).
	 *
	 * @param string $chosen_shipping Chosen shipping "method:instance" string.
	 * @return int|null
	 */
	public function shipping_zone_id( string $chosen_shipping ): ?int {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return null;
		}

		$instance_id = self::shipping_instance_id( $chosen_shipping );
		if ( ! $instance_id ) {
			return null;
		}

		// The zone lookup is an uncached query; the order-pay hint asks once
		// per gateway for the same instance.
		if ( ! array_key_exists( $instance_id, $this->zone_ids ) ) {
			$zone = \WC_Shipping_Zones::get_zone_by( 'instance_id', $instance_id );
			$this->zone_ids[ $instance_id ] = $zone instanceof \WC_Shipping_Zone ? (int) $zone->get_id() : null;
		}

		return $this->zone_ids[ $instance_id ];
	}

	/**
	 * Whether the current customer is VAT-exempt.
	 *
	 * @return bool
	 */
	public function is_vat_exempt(): bool {
		$customer = $this->customer();
		return $customer ? (bool) $customer->is_vat_exempt() : false;
	}

	/**
	 * The current customer's destination — country and state codes from the
	 * shipping address when one is set, from the billing address otherwise
	 * (never a shipping state glued to a billing country); both '' for a
	 * fresh guest session.
	 *
	 * @return array{country: string, state: string}
	 */
	public function customer_destination(): array {
		$customer = $this->customer();
		if ( ! $customer ) {
			return self::destination_of( '', '', '', '' );
		}
		return self::destination_of(
			(string) $customer->get_shipping_country(),
			(string) $customer->get_shipping_state(),
			(string) $customer->get_billing_country(),
			(string) $customer->get_billing_state()
		);
	}

	/**
	 * An order's destination, by the same rule as `customer_destination()`.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{country: string, state: string}
	 */
	public function order_destination( $order ): array {
		return self::destination_of(
			(string) $order->get_shipping_country(),
			(string) $order->get_shipping_state(),
			(string) $order->get_billing_country(),
			(string) $order->get_billing_state()
		);
	}

	/**
	 * The one destination rule: the shipping address when it names a
	 * country, the billing address otherwise — country and state together.
	 *
	 * @param string $shipping_country Shipping country code.
	 * @param string $shipping_state Shipping state code.
	 * @param string $billing_country Billing country code.
	 * @param string $billing_state Billing state code.
	 * @return array{country: string, state: string}
	 */
	private static function destination_of( string $shipping_country, string $shipping_state, string $billing_country, string $billing_state ): array {
		return '' !== $shipping_country
			? array( 'country' => $shipping_country, 'state' => $shipping_state )
			: array( 'country' => $billing_country, 'state' => $billing_state );
	}

	/**
	 * The session customer, or null when WooCommerce (or its customer
	 * object) is not loaded. Property-form read, magic-safe.
	 *
	 * @return \WC_Customer|null
	 */
	private function customer() {
		$wc = $this->wc();
		return $wc && $wc->customer ? $wc->customer : null;
	}

	/**
	 * The live cart, or null when WooCommerce (or its cart) is not loaded.
	 * Property-form read, magic-safe.
	 *
	 * @return \WC_Cart|null
	 */
	public function cart() {
		$wc = $this->wc();
		return $wc && $wc->cart ? $wc->cart : null;
	}

	/**
	 * Whether this request is the cart page, or a request made *by* it —
	 * the display seam's "the shopper is looking at the cart" fact.
	 *
	 * Two reads, because the two cart implementations answer in different
	 * places. The classic cart renders its totals in the page request, so
	 * `is_cart()` settles it. The blocks cart renders them from Store API
	 * responses, which carry no page context at all; there the referer is
	 * the only thing left that says which page asked, so a same-host
	 * referer pointing at the cart page counts.
	 *
	 * The referer decides on Store API **cart** routes and nowhere else,
	 * and that restriction is a security boundary rather than tidiness.
	 * Suppressing the fee is not a display-only act: `Frontend::integrate()`
	 * answers a suppression by not calling `add_fee()` at all, so a request
	 * that both builds an order and believes it is the cart would build one
	 * with no fee on it. `wc/store/v1/checkout` is exactly such a request —
	 * a REST POST that runs `calculate_totals()` — and a shopper can put any
	 * referer on it. Cart routes cannot create an order, so letting only
	 * those ask keeps a forged referer to what it looks like: a question
	 * about which page is being drawn.
	 *
	 * The referer is read from the request header, deliberately, rather than
	 * through `wp_get_referer()` — that helper prefers a `_wp_http_referer`
	 * *request parameter* over the header, which would let the claim be
	 * attached to any URL as a query string.
	 * `wp_validate_redirect()` supplies the same-host guarantee the helper
	 * would otherwise have given.
	 *
	 * A page request is never asked: it already knows what page it is, and a
	 * shopper reaches the checkout by clicking through from the cart, so the
	 * checkout's own page request carries a cart referer too.
	 *
	 * @return bool
	 */
	public function is_cart_page(): bool {

		// A request that is building an order is never "the cart", whatever
		// URL it was posted to. WooCommerce runs the classic checkout's
		// `wc-ajax=checkout` on `template_redirect` of the posted URL, so a
		// form posted to the cart page's URL has `is_cart()` true while
		// `process_checkout()` computes the totals the order is created
		// from — and a suppression there is an order without its fee (the
		// Store API checkout route is kept out below for the same reason).
		// Core defines the constant on every classic order-building path
		// (`WC_AJAX::checkout()`, `WC_Form_Handler::checkout_action()`,
		// `WC_Checkout::process_checkout()`) before the totals run.
		if ( self::is_processing_checkout() ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( ! self::is_store_api_cart_route() || ! function_exists( 'wc_get_cart_url' ) ) {
			return false;
		}

		$referer = wp_validate_redirect( $this->request_header( 'Referer' ), '' );

		if ( ! is_string( $referer ) || '' === $referer ) {
			return false;
		}

		return self::same_path( $referer, wc_get_cart_url() );

	}

	/**
	 * Whether the classic checkout is processing an order in this request
	 * (`WOOCOMMERCE_CHECKOUT`, which core defines before it computes the
	 * totals the order is created from).
	 *
	 * @return bool
	 */
	private static function is_processing_checkout(): bool {
		return defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT;
	}

	/**
	 * Whether this request is being served by one of the Store API's cart
	 * routes — `wc/store/v{n}/cart` and its sub-routes, which is how the
	 * blocks cart reads and mutates its totals.
	 *
	 * Deliberately not "is a REST request": the whole point of the test is
	 * that the route cannot create an order. `wc/store/v1/checkout` and
	 * `wc/store/v1/batch` sit outside the `cart/` prefix and so never
	 * qualify, which is what keeps a forged referer away from an
	 * order-building request ({@see is_cart_page()}).
	 *
	 * @return bool
	 */
	private static function is_store_api_cart_route(): bool {

		if ( ! self::is_rest_request() ) {
			return false;
		}

		// `$GLOBALS['wp']` is whatever the request bootstrapped; narrowed
		// step by step because a route we cannot read is not a cart route.
		$wp = $GLOBALS['wp'] ?? null;

		if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return false;
		}

		$route = $wp->query_vars['rest_route'] ?? '';

		if ( ! is_string( $route ) ) {
			return false;
		}

		return 1 === preg_match( '#^/?wc/store/v\d+/cart(?:/|$)#', $route );

	}

	/**
	 * Whether WordPress is serving a REST request. `wp_is_serving_rest_request()`
	 * is the supported answer but arrived in WP 6.5, above this plugin's 6.2
	 * floor; the `REST_REQUEST` constant it wraps is what the older releases
	 * offer, and says the same thing.
	 *
	 * @return bool
	 */
	private static function is_rest_request(): bool {

		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return (bool) wp_is_serving_rest_request();
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;

	}

	/**
	 * Whether two URLs address the same path, trailing slash and query
	 * string aside — enough to recognise the cart page behind a Store API
	 * request, without pretending to be a URL router.
	 *
	 * @param string $url URL.
	 * @param string $other URL to compare against.
	 * @return bool
	 */
	private static function same_path( string $url, string $other ): bool {

		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$other_path = untrailingslashit( (string) wp_parse_url( $other, PHP_URL_PATH ) );

		return '' !== $other_path && $path === $other_path;

	}

	/**
	 * Tax rates applying to the session customer for a tax class — what
	 * WooCommerce will tax a cart fee of that class with. Empty when taxes
	 * are off.
	 *
	 * @param string $tax_class Tax class ('' = standard).
	 * @return array<int|string, mixed> `WC_Tax::get_rates()` shape (rate id => rate row).
	 */
	public function cart_tax_rates( string $tax_class ): array {
		if ( ! $this->taxes_usable() ) {
			return array();
		}

		return \WC_Tax::get_rates( $tax_class );
	}

	/**
	 * Whether tax lookups can run at all: WooCommerce's tax machinery is
	 * loaded and the store has taxes enabled — the one guard every rate
	 * accessor shares, and the settings screen's own signal for whether to
	 * show its tax fields (`calcTaxes` on the settings GET, #187 — reading
	 * this from the adapter instead of WooCommerce's REST settings endpoint
	 * means a blocked/erroring core route can no longer hide the fields on
	 * a taxed store).
	 *
	 * @return bool
	 */
	public function taxes_usable(): bool {
		return class_exists( '\WC_Tax' ) && function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
	}

	/**
	 * The payment gateways currently available to the customer, id-keyed —
	 * what the order-pay page lists and what `WC_Form_Handler::pay_action()`
	 * validates the posted method against.
	 *
	 * @return \WC_Payment_Gateway[]
	 */
	public function available_payment_gateways(): array {
		$registry = $this->gateways_registry();
		return $registry ? $registry->get_available_payment_gateways() : array();
	}

	/**
	 * The payment method id posted by the order-pay form, or ''.
	 *
	 * Read here because `woocommerce_before_pay_action` fires BEFORE
	 * WooCommerce writes the posted method onto the order — the order's
	 * own `get_payment_method()` still names the previous gateway at that
	 * point. WooCommerce has already verified the `woocommerce-pay` nonce
	 * when that action fires; a caller outside it gets the raw posted value
	 * and must validate it against the available gateways itself.
	 *
	 * @return string
	 */
	public function posted_payment_method(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Form_Handler::pay_action() verified the woocommerce-pay nonce before firing woocommerce_before_pay_action; callers validate the id against the available gateways.
		return isset( $_POST['payment_method'] ) && is_string( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
	}

	/**
	 * The id of the cart session's own Store API draft/retry order
	 * (`store_api_draft_order` — the order the Store API checkout POST
	 * creates, and reuses while it stays pending/failed with an unchanged
	 * cart); 0 when the session holds none.
	 *
	 * @return int
	 */
	public function store_api_draft_order_id(): int {
		$order_id = $this->session_get( 'store_api_draft_order' );
		return is_numeric( $order_id ) ? (int) $order_id : 0;
	}

	/**
	 * An HTTP request header, sanitized; '' when absent. Request reads are
	 * this adapter's job (like {@see posted_payment_method()}), so
	 * components stay testable without touching superglobals. The value is
	 * untrusted input — a caller acting on it must verify it (nonce,
	 * allow-list), exactly as with any request field.
	 *
	 * @param string $name Header name as sent (e.g. `X-WCSTRIPE-EXPRESS-CHECKOUT`).
	 * @return string
	 */
	public function request_header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] )
			? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) )
			: '';
	}

	/**
	 * Recent orders paid with a gateway, newest first — the fee watchdog's
	 * sample. `wc_get_orders()` goes through the active order data store,
	 * so the query is HPOS-compatible by construction (never raw postmeta).
	 * Which statuses and creation flows count is the caller's policy; this
	 * is only the query.
	 *
	 * @param string $gateway_id Gateway id (`payment_method` on the order).
	 * @param int $since_timestamp Only orders created at/after this Unix timestamp.
	 * @param string[] $statuses Order statuses to include (`wc-` prefixed).
	 * @param string[] $created_via Creation flows to include (`checkout`, `store-api`, …).
	 * @param int $limit Maximum number of orders.
	 * @return \WC_Order[]
	 */
	public function recent_gateway_orders( string $gateway_id, int $since_timestamp, array $statuses, array $created_via, int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$found = wc_get_orders( array(
			'type'           => 'shop_order',
			'payment_method' => $gateway_id,
			'status'         => $statuses,
			'created_via'    => $created_via,
			'date_created'   => '>=' . $since_timestamp,
			'limit'          => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'return'         => 'objects',
		) );

		$orders = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			if ( $order instanceof \WC_Order ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	/**
	 * Keep a recurring background action scheduled: Action Scheduler when
	 * it is loaded (WooCommerce bundles it, so in practice always), a
	 * WP-Cron daily event otherwise. Idempotent — an already-scheduled
	 * hook is left alone. Which hook, how often and when to ask is the
	 * caller's policy; this is only the scheduling runtime.
	 *
	 * @param string $hook Action hook name.
	 * @param string $group Action Scheduler group.
	 * @param int $first_run_timestamp When the first run may happen.
	 * @param int $interval_seconds Interval between runs (the WP-Cron fallback rounds it to the core `daily` schedule).
	 * @return void
	 */
	public function ensure_recurring_action( string $hook, string $group, int $first_run_timestamp, int $interval_seconds ): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, array(), $group ) ) {
				as_schedule_recurring_action( $first_run_timestamp, $interval_seconds, $hook, array(), $group, true );
			}
			return;
		}

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( $first_run_timestamp, 'daily', $hook );
		}
	}

	/**
	 * Remove a recurring background action, wherever
	 * {@see ensure_recurring_action()} put it.
	 *
	 * @param string $hook Action hook name.
	 * @param string $group Action Scheduler group.
	 * @return void
	 */
	public function unschedule_recurring_action( string $hook, string $group ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), $group );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Role slugs of a user; empty for guests and unknown ids.
	 *
	 * @param int $user_id User id (0 for guests).
	 * @return string[]
	 */
	public function user_roles( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		return $this->roles_of( get_userdata( $user_id ) );
	}

	/**
	 * Whether the store displays cart totals including tax — the setting
	 * behind `WC_Cart::get_displayed_subtotal()`.
	 *
	 * @return bool
	 */
	public function tax_display_cart_includes_tax(): bool {
		return 'incl' === get_option( 'woocommerce_tax_display_cart' );
	}

	/**
	 * Whether an order was placed by a VAT-exempt customer. Checkout (classic
	 * and Store API) records the customer's flag as the `is_vat_exempt` order
	 * meta; an order without it (admin-created) counts as not exempt.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function order_is_vat_exempt( $order ): bool {
		// Core's own filterable verdict (`WC_Abstract_Order::calculate_taxes()`
		// consults the same filter before taxing), so the exemption a plugin
		// grants there also reaches the fee hints. The hook name is
		// WooCommerce's, not ours — we apply it to READ core's verdict.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return (bool) apply_filters( 'woocommerce_order_is_vat_exempt', 'yes' === $order->get_meta( 'is_vat_exempt' ), $order );
	}

	/**
	 * Tax rates applying to an order for a tax class, resolved from the
	 * order's own taxable location (WooCommerce's rule: billing, shipping
	 * or shop base per "calculate tax based on", local pickup included) —
	 * not from the session customer, who on the order-pay page may be a
	 * guest following a payment link. The same location `calculate_totals()`
	 * taxes the order with on submit.
	 *
	 * @param \WC_Order $order Order.
	 * @param string $tax_class Tax class ('' = standard).
	 * @return array<int|string, mixed> `WC_Tax::find_rates()` shape (rate id => rate row); empty when taxes are off.
	 */
	public function order_tax_rates( $order, string $tax_class ): array {
		if ( ! $this->taxes_usable() ) {
			return array();
		}

		return \WC_Tax::find_rates( array_merge( $order->get_taxable_location(), array( 'tax_class' => $tax_class ) ) );
	}

	/**
	 * The order's basket cost and tax rates per tax class — the facts
	 * WooCommerce's negative-fee tax distribution runs on
	 * (`WC_Order_Item_Fee::get_tax_class_costs()`, mirrored here so a
	 * disclosure surface can price a would-be negative fee exactly as the
	 * charge will; #133): non-negative `line_item`/`fee`/`shipping` totals,
	 * non-taxable items in the untaxed `non-taxable` bucket, `inherit`
	 * mapped to the first of the order's item tax classes.
	 *
	 * @param \WC_Order $order Order.
	 * @param array<int, int> $exclude_item_ids Item ids to leave out of the pool
	 *   (the plugin's own lines — removed or replaced before the charge recalculates).
	 * @return array<string, array{cost: float, rates: array<int|string, mixed>}>
	 */
	public function order_tax_class_costs( $order, array $exclude_item_ids = array() ): array {
		if ( ! $this->taxes_usable() ) {
			return array();
		}

		$item_tax_classes = (array) $order->get_items_tax_classes();
		$costs            = array();

		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			// The concrete item types of the requested groups — they all
			// carry totals and tax props (the base class does not).
			if ( ! $item instanceof \WC_Order_Item_Product && ! $item instanceof \WC_Order_Item_Fee && ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			if ( in_array( (int) $item_id, $exclude_item_ids, true ) ) {
				continue;
			}
			$total = Cast::to_float( $item->get_total() );
			if ( 0 > $total ) {
				continue;
			}

			if ( 'taxable' !== $item->get_tax_status() ) {
				$tax_class = self::NON_TAXABLE_BUCKET;
			} elseif ( 'inherit' === $item->get_tax_class() ) {
				$tax_class = Cast::to_string( reset( $item_tax_classes ) );
			} else {
				$tax_class = Cast::to_string( $item->get_tax_class() );
			}

			$costs[ $tax_class ] = ( $costs[ $tax_class ] ?? 0.0 ) + $total;
		}

		return $this->with_class_rates( $costs, fn( string $tax_class ) => $this->order_tax_rates( $order, $tax_class ) );
	}

	/**
	 * The cart's basket cost and tax rates per tax class — the cart twin
	 * of {@see order_tax_class_costs()}, mirroring
	 * `WC_Cart_Totals::get_tax_class_costs()`: discounted line totals per
	 * product tax class (non-taxable products in the untaxed bucket),
	 * existing non-negative cart fees per their class, and the shipping
	 * total under the store's shipping tax class (`inherit` mapped to the
	 * first item class).
	 *
	 * Known approximation (#133): the fee pool includes the plugin's own
	 * fee for the *currently selected* gateway, which at charge time —
	 * with the previewed gateway chosen — is replaced by the previewed
	 * fee. A proportion-only skew, visible in cents only on baskets that
	 * mix tax classes; accepted over fuzzy self-recognition of cart fees.
	 *
	 * @return array<string, array{cost: float, rates: array<int|string, mixed>}>
	 */
	public function cart_tax_class_costs(): array {
		$cart = $this->cart();
		if ( ! $cart || ! $this->taxes_usable() ) {
			return array();
		}

		$item_tax_classes = array();
		$costs            = array();

		foreach ( (array) $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}
			$product = $cart_item['data'];
			$total   = Cast::to_float( $cart_item['line_total'] ?? 0 );
			if ( 0 > $total ) {
				continue;
			}

			// Core plucks the class of EVERY item for the `inherit`
			// resolution, taxable or not.
			$item_tax_classes[] = Cast::to_string( $product->get_tax_class() );

			$tax_class = 'taxable' !== $product->get_tax_status()
				? self::NON_TAXABLE_BUCKET
				: Cast::to_string( $product->get_tax_class() );

			$costs[ $tax_class ] = ( $costs[ $tax_class ] ?? 0.0 ) + $total;
		}

		foreach ( (array) $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) ) {
				continue;
			}
			$fee_data = get_object_vars( $fee );
			$total    = Cast::to_float( $fee_data['amount'] ?? 0 );
			if ( 0 > $total ) {
				continue;
			}
			if ( empty( $fee_data['taxable'] ) ) {
				$tax_class = self::NON_TAXABLE_BUCKET;
			} else {
				$tax_class = Cast::to_string( $fee_data['tax_class'] ?? '' );
				if ( 'inherit' === $tax_class ) {
					$tax_class = Cast::to_string( reset( $item_tax_classes ) );
				}
			}
			$costs[ $tax_class ] = ( $costs[ $tax_class ] ?? 0.0 ) + $total;
		}

		$shipping_total = Cast::to_float( $cart->get_shipping_total() );
		if ( 0 < $shipping_total ) {
			$shipping_class = Cast::to_string( get_option( 'woocommerce_shipping_tax_class', 'inherit' ) );
			if ( 'inherit' === $shipping_class ) {
				$shipping_class = Cast::to_string( reset( $item_tax_classes ) );
			}
			$costs[ $shipping_class ] = ( $costs[ $shipping_class ] ?? 0.0 ) + $shipping_total;
		}

		return $this->with_class_rates( $costs, fn( string $tax_class ) => $this->cart_tax_rates( $tax_class ) );
	}

	/**
	 * Attach each class's tax rates to its cost, dropping empty classes —
	 * the shared tail of the two class-costs builders. The `non-taxable`
	 * bucket keeps empty rates: it weighs the distribution's denominator
	 * but is never taxed.
	 *
	 * @param array<string, float> $costs Cost per tax class.
	 * @param callable(string): array<int|string, mixed> $rates_for Rates lookup.
	 * @return array<string, array{cost: float, rates: array<int|string, mixed>}>
	 */
	private function with_class_rates( array $costs, callable $rates_for ): array {
		$result = array();
		foreach ( $costs as $tax_class => $cost ) {
			if ( 0.0 === $cost ) {
				continue;
			}
			$result[ $tax_class ] = array(
				'cost'  => $cost,
				'rates' => self::NON_TAXABLE_BUCKET === $tax_class ? array() : $rates_for( (string) $tax_class ),
			);
		}

		return $result;
	}

	/**
	 * The store's shipping classes (`WP_Term` objects), empty when
	 * WooCommerce (or its shipping controller) is not loaded.
	 *
	 * @return array<int|string, mixed>
	 */
	public function shipping_classes(): array {
		$wc = $this->wc();
		$shipping = $wc ? $wc->shipping() : null;

		return $shipping ? (array) $shipping->get_shipping_classes() : array();
	}

	/**
	 * WooCommerce's countries/states tables, or null when WooCommerce (or
	 * the object) is not loaded. Property-form read, magic-safe.
	 *
	 * @return \WC_Countries|null
	 */
	public function countries() {
		$wc = $this->wc();
		return $wc && $wc->countries ? $wc->countries : null;
	}

	/**
	 * Read a key from the WC session.
	 *
	 * @param string $key Session key.
	 * @return mixed
	 */
	private function session_get( string $key ) {
		$wc = $this->wc();
		$session = $wc ? $wc->session : null;
		return $session ? $session->get( $key ) : null;
	}

	/**
	 * Get current gateway.
	 *
	 * The Stripe for woocommerce plugin considers itself unavailable if cart
	 * total is below 50 ct. At this point the cart total is not yet calculated
	 * and equals zero resulting in s4wc being unavaliable. We use
	 * `payment_gateways()` (backed by
	 * `WC()->payment_gateways->payment_gateways()`) in favor of
	 * `WC()->payment_gateways->get_available_payment_gateways()`
	 *
	 * @since 1.0.0
	 * @return \WC_Payment_Gateway|false The gateway, or false when none is registered.
	 */
	public function get_current_gateway() {
		$available_gateways = $this->payment_gateways();

		$current_gateway = null;
		$default_gateway = get_option( 'woocommerce_default_gateway' );
		if ( ! is_string( $default_gateway ) ) {
			$default_gateway = '';
		}

		if ( $available_gateways ) {

			$chosen_payment_method = $this->get_chosen_payment_method();

			// Chosen Method
			if ( $chosen_payment_method && isset( $available_gateways[ $chosen_payment_method ] ) ) {
				$current_gateway = $available_gateways[ $chosen_payment_method ];
			} elseif ( isset( $available_gateways[ $default_gateway ] ) ) {
				$current_gateway = $available_gateways[ $default_gateway ];
			} else {
				$current_gateway = current( $available_gateways );
			}
		}
		return $current_gateway ?? false;
	}

}

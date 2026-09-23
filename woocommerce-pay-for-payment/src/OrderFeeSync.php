<?php

namespace Pay4Payment;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Fees\Fee;
use Pay4Payment\Fees\FeeLinePlanner;
use Pay4Payment\Fees\FeeProductInterface;
use Pay4Payment\Fees\OrderFeeCalculatorInterface;
use Pay4Payment\Fees\OrderFeeLines;
use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Premium;

/**
 * Off-checkout fee enforcement: keeps the plugin's fee line on an order in
 * step with the gateway the order is paid with, on the flows where
 * `woocommerce_cart_calculate_fees` never runs (upstream gap
 * woocommerce/woocommerce#17794).
 *
 * Free and Pro share one handler and split on what it may do:
 * - **Free — never overcharge.** On the order-pay page, when the customer
 *   picks another gateway, the previous gateway's fee line (the one
 *   checkout created) is removed. A customer retrying with cash on delivery
 *   must not pay PayPal's fee.
 * - **Pro — never undercharge.** Where the per-surface policy allows
 *   (`OrderFeeCalculatorInterface::may_charge()` — licence + mode on the
 *   off-checkout surfaces, licence alone on the checkout draft), the
 *   chosen gateway's fee is added on order-pay, admin-created orders are
 *   synced on save/Recalculate, and (product mode) checkout orders' fee
 *   line can be converted to the product line.
 *
 * The same split covers the Store API checkout POST (#115): the POST
 * computes cart fees before the POSTed gateway reaches the session, so the
 * draft order can carry a stale gateway's fee — always, on express Pay-Now
 * flows, whose wallet gateway never enters the session. Free removes that
 * stale line; Pro corrects it to the actual gateway
 * (`may_charge( SOURCE_CHECKOUT )`, licence-only — a checkout surface,
 * not gated by the off-checkout mode), before the gateway authorizes.
 * The same action's order-pay route (`/checkout/{ID}`, #131) follows the
 * order-pay rules instead — see {@see sync_store_api_checkout_order()}.
 *
 * The Pro half arrives through the `OrderFeeCalculatorInterface` and
 * `FeeProductInterface` seams (CONTEXT.md → Pro seams); the free build
 * binds their null implementations, so this handler can only ever remove
 * lines there. Licensing is the seams' business, not this class's.
 *
 * Order-pay reads the POSTED gateway: `woocommerce_before_pay_action` fires
 * before WooCommerce writes it onto the order. `woocommerce_after_pay_action`
 * is unusable (after `process_payment()`, never reached on success).
 */
class OrderFeeSync implements ComponentInterface {

	/**
	 * Order meta: the gateway the sync last materialized a line for. Lets the
	 * admin path tell "the admin removed our line on purpose" (same gateway,
	 * line gone → stay gone) from "the gateway changed" (re-add).
	 */
	const META_SYNCED_GATEWAY = '_pay4payment_fee_synced_gateway';

	/**
	 * `woocommerce_process_shop_order_meta` priority: after
	 * `WC_Meta_Box_Order_Data::save` (40) has written the payment-method
	 * dropdown onto the order.
	 */
	const ADMIN_SAVE_PRIORITY = 60;

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * WC-runtime adapter
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Fee lines on orders
	 * @var OrderFeeLines
	 */
	private $lines;

	/**
	 * What the order-pay page says about a fee that a method switch would
	 * remove, computed on the pay-form hook and printed above the button.
	 * '' when there is nothing to say (see {@see prepare_stale_fee_notice()}).
	 * @var string
	 */
	private $stale_fee_notice = '';

	/**
	 * Pure planner
	 * @var FeeLinePlanner
	 */
	private $planner;

	/**
	 * Order-side fee calculator (Pro; the free build's never yields a fee)
	 * @var OrderFeeCalculatorInterface
	 */
	private $calculator;

	/**
	 * Fee product for product mode (Pro; the free build has none)
	 * @var FeeProductInterface
	 */
	private $product;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param WooCommerce $helpers_wc WC-runtime adapter.
	 * @param OrderFeeLines $lines Fee lines on orders.
	 * @param FeeLinePlanner $planner Planner.
	 * @param OrderFeeCalculatorInterface $calculator Order-side fee calculator.
	 * @param FeeProductInterface $product Fee product for product mode.
	 */
	public function __construct( Settings $settings, WooCommerce $helpers_wc, OrderFeeLines $lines, FeeLinePlanner $planner, OrderFeeCalculatorInterface $calculator, FeeProductInterface $product ) {
		$this->settings = $settings;
		$this->helpers_wc = $helpers_wc;
		$this->lines = $lines;
		$this->planner = $planner;
		$this->calculator = $calculator;
		$this->product = $product;
	}

	/**
	 * Setup. Every surface registers unconditionally; the Pro gate is
	 * checked at run time (the `pay4payment_is_premium` filter may flip
	 * per request).
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'woocommerce_before_pay_action', [ $this, 'sync_order_pay' ], 10, 1 );
		add_action( 'before_woocommerce_pay_form', [ $this, 'prepare_stale_fee_notice' ], 10, 3 );
		add_action( 'woocommerce_pay_order_before_submit', [ $this, 'render_stale_fee_notice' ], 10, 0 );
		add_action( 'woocommerce_saved_order_items', [ $this, 'sync_saved_order_items' ], 10, 1 );
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'sync_admin_order' ], self::ADMIN_SAVE_PRIORITY, 1 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'convert_checkout_order' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'convert_checkout_order' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'sync_store_api_checkout_order' ], 10, 1 );
	}

	/**
	 * The order-pay page shows the order as it stands — fee line included —
	 * but this class removes that line the moment the customer submits with
	 * a different method. Left unsaid, the page quotes one price and charges
	 * another: lower, which is nobody's loss, but still a number the customer
	 * was told and then not charged.
	 *
	 * Pro says it per method and live (`Pro\OrderPayFeeHint`,
	 * `Pro\OrderPayLiveTotals` — each option carries its own fee and total),
	 * so this stands in only where those are absent. It states the same fact
	 * once, statically, with no JavaScript: what the fee belongs to, and what
	 * the order comes to without it.
	 *
	 * @param mixed $order The order being paid.
	 * @param mixed $order_button_text Pay button text (unused).
	 * @param mixed $available_gateways Gateways offered, id-keyed.
	 * @return void
	 */
	public function prepare_stale_fee_notice( $order, $order_button_text = '', $available_gateways = [] ) {
		$this->stale_fee_notice = '';

		if ( Premium::is_premium() ) {
			return;
		}
		if ( ! $order instanceof \WC_Order || ! is_array( $available_gateways ) ) {
			return;
		}
		// One method on offer is one method the customer can pay with: the
		// fee cannot go anywhere, so there is nothing to warn about.
		if ( count( $available_gateways ) < 2 ) {
			return;
		}

		$lines = $this->lines->find( $order );
		if ( [] === $lines ) {
			return;
		}

		$fee_total = 0.0;
		$titles = [];
		foreach ( $lines as $line ) {
			$item = $line['item'];
			// Both kinds carry a total; the base class the finder is typed
			// against does not promise one, so ask the two that do.
			if ( ! $item instanceof \WC_Order_Item_Fee && ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$fee_total += Cast::to_float( $item->get_total() ) + Cast::to_float( $item->get_total_tax() );
			$titles[] = Cast::to_string( $item->get_name() );
		}

		if ( $fee_total <= 0.0 ) {
			return;
		}

		$price_args = [ 'currency' => $order->get_currency() ];

		$this->stale_fee_notice = sprintf(
			/* translators: 1: fee line title(s), 2: payment method the fee belongs to, 3: order total once the fee is removed. */
			__( '“%1$s” applies to %2$s. Pay with another method and it is removed — your total is then %3$s.', 'woocommerce-pay-for-payment' ),
			implode( ', ', $titles ),
			Cast::to_string( $order->get_payment_method_title() ),
			wc_price( Cast::to_float( $order->get_total() ) - $fee_total, $price_args )
		);
	}

	/**
	 * `woocommerce_pay_order_before_submit`: print what
	 * {@see prepare_stale_fee_notice()} worked out, right above the pay
	 * button — the last thing read before the price is agreed to.
	 *
	 * @return void
	 */
	public function render_stale_fee_notice() {
		if ( '' === $this->stale_fee_notice ) {
			return;
		}

		echo '<p class="woocommerce-info pay4payment-order-pay-stale-fee">' . wp_kses_post( $this->stale_fee_notice ) . '</p>';
	}

	/**
	 * Store API checkout (blocks + express Pay-Now, #115): the POST computes
	 * cart fees before the POSTed `payment_method` reaches the session, so
	 * the draft order can carry the stale session gateway's fee — or none,
	 * on express flows, where the wallet gateway never enters the session at
	 * all. This hook fires right after core wrote the POSTed method onto the
	 * order and into the session, before `…checkout_order_processed` (so
	 * product-mode conversion sees the corrected line) and before
	 * `process_payment()` (so the gateway authorizes the corrected total).
	 *
	 * Two surfaces share the action, split on the session's draft id (the
	 * id core stores as `store_api_draft_order` before the action fires):
	 *
	 * - The cart session's own draft order (the id match, which also covers
	 *   a retry — the POST reuses a pending/failed order while the cart is
	 *   unchanged, so a declined payment retried with another gateway is
	 *   corrected too) is a *checkout* surface: `SOURCE_CHECKOUT`, gated by
	 *   the licence alone, deliberately independent of the off-checkout
	 *   mode.
	 * - Any other payable order is the Store API order-pay route
	 *   (`/checkout/{ID}` — express buttons on the order-pay page, #131),
	 *   which bypasses `woocommerce_before_pay_action`: the same rules as
	 *   the classic order-pay submit apply (`SOURCE_ORDER_PAY`, licence +
	 *   mode). The route validated the POSTed gateway and wrote it onto
	 *   the order before this action, so the order's method is the one to
	 *   sync against.
	 *
	 * On both: free removes the stale gateway's line (never overcharge);
	 * the actual gateway's fee is added through the seam (`may_charge()`).
	 * A line the target gateway already has stays untouched.
	 *
	 * @param mixed $order The order the Store API checkout POST is processing.
	 * @return void
	 */
	public function sync_store_api_checkout_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = $order->get_id();
		if ( 0 === $order_id ) {
			return;
		}

		$gateway_id = Cast::to_string( $order->get_payment_method() );
		if ( '' === $gateway_id ) {
			return;
		}

		if ( $order_id === $this->helpers_wc->store_api_draft_order_id() ) {
			$this->sync( $order, $gateway_id, OrderFeeLines::SOURCE_CHECKOUT, false );
			return;
		}

		if ( ! $order->needs_payment() ) {
			return;
		}

		$this->sync( $order, $gateway_id, OrderFeeLines::SOURCE_ORDER_PAY, false );
	}

	/**
	 * Order-pay page, on submit: remove other gateways' plugin fee lines
	 * (free) and add the posted gateway's fee (Pro, mode on). A line the
	 * posted gateway already has is left untouched — the amount the
	 * customer already accepted.
	 *
	 * @param mixed $order The order being paid.
	 * @return void
	 */
	public function sync_order_pay( $order ) {
		if ( ! $order instanceof \WC_Order || ! $order->needs_payment() ) {
			return;
		}

		$gateway_id = $this->helpers_wc->posted_payment_method();
		if ( '' === $gateway_id ) {
			return;
		}
		$available = $this->helpers_wc->available_payment_gateways();
		if ( ! isset( $available[ $gateway_id ] ) ) {
			return; // WooCommerce rejects the method right after this hook.
		}

		$this->sync( $order, $gateway_id, OrderFeeLines::SOURCE_ORDER_PAY, false );
	}

	/**
	 * `woocommerce_saved_order_items` (Recalculate, add/remove items): sync
	 * the admin order — except inside the full "Update" save, where the
	 * items meta box fires this before the payment-method dropdown has been
	 * written onto the order; the `woocommerce_process_shop_order_meta`
	 * handler covers that request with the final gateway.
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public function sync_saved_order_items( $order_id ) {
		if ( doing_action( 'woocommerce_process_shop_order_meta' ) ) {
			return;
		}

		$this->sync_admin_order( $order_id );
	}

	/**
	 * Admin order edit (save / Recalculate): keep the line in step with the
	 * order's payment method. Admin-created orders only — checkout orders
	 * keep their checkout-computed line (no session-less multi-currency
	 * recompute); order-pay handles their gateway switches. A line the
	 * admin deleted for the current gateway stays deleted ("waived").
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public function sync_admin_order( $order_id ) {
		if ( ! $this->calculator->may_charge( OrderFeeLines::SOURCE_ADMIN ) ) {
			return;
		}

		$order = wc_get_order( Cast::to_int( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'admin' !== $order->get_created_via() ) {
			return;
		}
		if ( $order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
			return;
		}

		$this->sync(
			$order,
			Cast::to_string( $order->get_payment_method() ),
			OrderFeeLines::SOURCE_ADMIN,
			true,
			Cast::to_string( $order->get_meta( self::META_SYNCED_GATEWAY ) )
		);
	}

	/**
	 * On the product line: right after checkout created the order, replace
	 * the plugin's fee line(s) with lines on the managed product — same
	 * amount, title and taxes, so totals are identical and the cart never saw
	 * a product. Pro only: the free build and an unlicensed Pro store have no
	 * fee product (the seam says null).
	 *
	 * Not conditional on anything else: the line type is the whole choice
	 * (#236). It used to need a second opt-in, which is what made the setting
	 * read as a chain — an export that skips fee lines wants the fee on every
	 * order, not on the ones that came from the admin.
	 *
	 * @param mixed $order The order checkout just created.
	 * @return void
	 */
	public function convert_checkout_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( ! $this->settings->writes_product_line() ) {
			return;
		}

		$fee_items = [];
		foreach ( $this->lines->find( $order ) as $line ) {
			if ( $line['item'] instanceof \WC_Order_Item_Fee ) {
				$fee_items[] = $line['item'];
			}
		}
		if ( [] === $fee_items ) {
			return;
		}

		$product = $this->product->get();
		if ( ! $product ) {
			return;
		}

		$converted = 0;
		foreach ( $fee_items as $item ) {
			if ( $this->lines->convert_to_product_line( $order, $item, $product ) ) {
				++$converted;
			}
		}

		if ( $converted > 0 ) {
			$order->save();
		}
	}

	/**
	 * Plan and apply the line changes for a target gateway.
	 *
	 * @param \WC_Order $order Order.
	 * @param string $gateway_id Target gateway ('' = none → every plugin line is stale).
	 * @param string $source One of the OrderFeeLines::SOURCE_* constants.
	 * @param bool $refresh Whether an existing line of the target follows the recomputed fee.
	 * @param string $synced_gateway The gateway the sync last wrote a line for (admin back-off), '' when unknown.
	 * @return void
	 */
	private function sync( $order, string $gateway_id, string $source, bool $refresh, string $synced_gateway = '' ) {

		/**
		 * Filters whether the off-checkout fee sync runs for an order.
		 *
		 * The veto seam for integrations (e.g. orders a subscription or
		 * deposit plugin manages itself).
		 *
		 * @since 3.0.0
		 * @param bool $sync Whether to sync.
		 * @param \WC_Order $order The order.
		 * @param string $gateway_id The gateway the order is (about to be) paid with.
		 * @param string $source `order_pay` (the classic submit and the Store API `/checkout/{ID}` route alike, #131), `admin` or `checkout` (the Store API draft order, #115).
		 */
		if ( ! apply_filters( 'pay4payment_order_fee_sync', true, $order, $gateway_id, $source ) ) {
			return;
		}

		$existing = $this->lines->find( $order );

		// The fee is consulted only where the plan could use it
		// (`FeeLinePlanner::needs_fee()`) — the order-side pipeline
		// (snapshot, tier selection, zone query) is skipped on the common
		// "gateway unchanged" case. Per-surface add policy is the seam's
		// (`may_charge()`), not this class's.
		$fee = $this->planner->needs_fee( $existing, $gateway_id, $refresh ) && $this->calculator->may_charge( $source )
			? $this->calculator->calculate( $order, $gateway_id )
			: null;
		$ops = $this->planner->plan( $existing, $gateway_id, $fee, $refresh, $synced_gateway );

		$by_id = [];
		foreach ( $existing as $line ) {
			$by_id[ $line['item_id'] ] = $line;
		}

		$notes = [];
		$changed = false;

		if ( [] !== $ops['remove'] ) {
			foreach ( $ops['remove'] as $item_id ) {
				if ( isset( $by_id[ $item_id ] ) ) {
					/* translators: %s: fee line title. */
					$notes[] = sprintf( __( '"%s" removed', 'woocommerce-pay-for-payment' ), Cast::to_string( $by_id[ $item_id ]['item']->get_name() ) );
				}
			}
			$this->lines->remove_lines( $order, $ops['remove'] );
			$changed = true;
		}

		if ( $ops['update'] && isset( $by_id[ $ops['update']['item_id'] ] ) ) {
			if ( $this->lines->update_line( $by_id[ $ops['update']['item_id'] ]['item'], $ops['update']['fee'] ) ) {
				/* translators: %s: fee line title. */
				$notes[] = sprintf( __( '"%s" updated', 'woocommerce-pay-for-payment' ), $ops['update']['fee']->title );
				$changed = true;
			}
		}

		if ( $ops['add'] ) {
			// A checkout draft gets what checkout creates — a fee line; the
			// product-mode conversion runs later, on `…order_processed`.
			$product = OrderFeeLines::SOURCE_CHECKOUT === $source ? null : $this->product_for_line_type();
			$this->lines->add_line( $order, $ops['add']['fee'], $ops['add']['gateway_id'], $source, $product );
			/* translators: %s: fee line title. */
			$notes[] = sprintf( __( '"%s" added', 'woocommerce-pay-for-payment' ), $ops['add']['fee']->title );
			$changed = true;
		}

		// Remember what we materialized (admin back-off). A waived gateway
		// keeps its mark while it stays the target, so the waiver persists.
		// Only `sync_admin_order()` reads the mark, and only on admin-created
		// orders, but the order-pay surface writes it too: the customer may
		// switch gateway on the pay link of an admin-created order, and the
		// waiver of the line that switch materialized needs the new gateway
		// remembered. The Store API checkout surface never touches an
		// admin-created order, so it skips the mark, which used to cost
		// every blocks checkout order a meta row and an extra save (#266).
		$marker_changed = false;
		if ( OrderFeeLines::SOURCE_CHECKOUT !== $source ) {
			$has_line = $ops['add'] || null !== $ops['kept'];
			$synced_after = $has_line || ( '' !== $gateway_id && $synced_gateway === $gateway_id ) ? $gateway_id : '';
			$marker_changed = $synced_after !== $synced_gateway;
			if ( $marker_changed ) {
				if ( '' === $synced_after ) {
					$order->delete_meta_data( self::META_SYNCED_GATEWAY );
				} else {
					$order->update_meta_data( self::META_SYNCED_GATEWAY, $synced_after );
				}
			}
		}

		if ( $changed ) {
			$order->calculate_totals();
			$order->add_order_note( sprintf(
				/* translators: 1: where the sync ran (order-pay page / admin / checkout), 2: what changed. */
				__( 'Payment fee synced (%1$s): %2$s.', 'woocommerce-pay-for-payment' ),
				$this->source_label( $source ),
				implode( '; ', $notes )
			) );
		} elseif ( $marker_changed ) {
			$order->save();
		}
	}

	/**
	 * The order-note wording for the surface a sync ran on.
	 *
	 * @param string $source One of the OrderFeeLines::SOURCE_* constants.
	 * @return string
	 */
	private function source_label( string $source ): string {
		if ( OrderFeeLines::SOURCE_ADMIN === $source ) {
			return __( 'admin order edit', 'woocommerce-pay-for-payment' );
		}
		if ( OrderFeeLines::SOURCE_CHECKOUT === $source ) {
			return __( 'blocks checkout', 'woocommerce-pay-for-payment' );
		}

		return __( 'order-pay page', 'woocommerce-pay-for-payment' );
	}

	/**
	 * The managed product when the store writes product lines, null otherwise.
	 *
	 * @return \WC_Product|null
	 */
	private function product_for_line_type() {
		if ( ! $this->settings->writes_product_line() ) {
			return null;
		}

		return $this->product->get();
	}
}

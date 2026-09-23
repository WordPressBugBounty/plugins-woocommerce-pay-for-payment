<?php

namespace Pay4Payment;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Fees\CartFeeVisibilityInterface;
use Pay4Payment\Fees\CartSnapshot;
use Pay4Payment\Fees\FeeEngine;
use Pay4Payment\Fees\FeeRun;
use Pay4Payment\Fees\Guards;
use Pay4Payment\Fees\OrderFeeLines;
use Pay4Payment\Fees\SelectedTier;
use Pay4Payment\Fees\TierConditionsInterface;
use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;

class Frontend implements ComponentInterface
{

	/**
	 * Payment-method change handler attached to `wc-checkout`.
	 *
	 * `$fragment_refresh` is WooCommerce's cart-fragments refresh config; the
	 * `typeof` guard is v2 behaviour and is kept deliberately — the variable is
	 * absent unless cart fragments are active.
	 */
	private const UPDATE_CHECKOUT_JS = <<<'JS'
		jQuery(document).ready(function($) {
			$(document.body).on('change', 'input[name="payment_method"]', function() {
				$('body').trigger('update_checkout');
				if (typeof $fragment_refresh !== 'undefined') {
					$.ajax($fragment_refresh);
				}
			});
		});
		JS;

	/**
	 * The fee line added to the cart in this request (title + gateway id),
	 * so the order fee item checkout creates from it can be tagged; null
	 * until `integrate()` added one.
	 * @var array{title: string, gateway_id: string}|null
	 */
	private $added_fee;

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * WooCommerce helpers
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Fee engine
	 * @var FeeEngine
	 */
	private $fee_engine;

	/**
	 * Tier selection — the free↔Pro seam of the guard chain (free: tier 0
	 * unconditionally; Pro: first-match-wins across the tiers).
	 * @var TierConditionsInterface
	 */
	private $tier_conditions;

	/**
	 * Cart-fee visibility — the display-controls seam (free: always show;
	 * Pro: hide-on-cart until a payment method is chosen).
	 * @var CartFeeVisibilityInterface
	 */
	private $cart_fee_visibility;

	/**
	 * Constructor.
	 * @param Settings $settings Settings.
	 * @param WooCommerce $helpers_wc WooCommerce helpers.
	 * @param FeeEngine $fee_engine Fee engine.
	 * @param TierConditionsInterface $tier_conditions Tier selection (free: tier 0, Pro: first matching tier).
	 * @param CartFeeVisibilityInterface $cart_fee_visibility Cart-fee visibility (free: always show; Pro: hide-on-cart).
	 */
	public function __construct(Settings $settings, WooCommerce $helpers_wc, FeeEngine $fee_engine, TierConditionsInterface $tier_conditions, CartFeeVisibilityInterface $cart_fee_visibility)	{
		$this->settings = $settings;
		$this->helpers_wc = $helpers_wc;
		$this->fee_engine = $fee_engine;
		$this->tier_conditions = $tier_conditions;
		$this->cart_fee_visibility = $cart_fee_visibility;
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup()
	{

		add_action('wp_enqueue_scripts', array($this, 'enqueue_update_checkout_script'), 20);
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'integrate' ), ( PHP_INT_MAX - 1 ), 1 );
		// Fires for classic and blocks checkout alike (the Store API calls
		// create_order_fee_lines() too): tag our fee line on the order.
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_order_fee_item' ), 10, 4 );

	}

	/**
	 * Tag the order fee item checkout created from our cart fee with the
	 * gateway id + source meta (`OrderFeeLines`), so the off-checkout sync
	 * can recognise it later (e.g. remove it when the customer switches
	 * gateway on the order-pay page).
	 *
	 * @param mixed $item The WC_Order_Item_Fee being created.
	 * @param mixed $fee_key Cart fee key.
	 * @param mixed $fee The cart fee object.
	 * @param mixed $order The order.
	 * @return void
	 */
	public function tag_order_fee_item( $item, $fee_key, $fee, $order ) {
		if ( null === $this->added_fee || ! $item instanceof \WC_Order_Item || ! is_object( $fee ) ) {
			return;
		}
		if ( ! isset( $fee->name ) || Cast::to_string( $fee->name ) !== $this->added_fee['title'] ) {
			return;
		}

		OrderFeeLines::tag( $item, $this->added_fee['gateway_id'], OrderFeeLines::SOURCE_CHECKOUT );
	}

	/**
	 * Refresh the fee line when the payment method changes (classic checkout).
	 *
	 * WC core (≥10.8) does not trigger `update_checkout` on payment-method
	 * change, so without this the fee line goes stale until something else
	 * refreshes the order review.
	 *
	 * Carried by WooCommerce's own `wc-checkout` handle rather than printed
	 * into `woocommerce_review_order_after_submit`. That hook fires inside the
	 * review-order fragment, which is re-rendered on every `update_checkout`
	 * response — so the old inline block was re-executed, and its delegated
	 * handler re-bound, on every refresh. Riding the enqueued handle binds it
	 * once per page load; delegation on `document.body` still survives each
	 * fragment replacement.
	 *
	 * `wc-checkout` is enqueued only on the classic checkout, which is exactly
	 * the scope this belongs to — BlocksIntegration covers the blocks checkout.
	 *
	 * Gated on the handle being REGISTERED, not already enqueued (#176):
	 * WooCommerce registers `wc-checkout` unconditionally early and enqueues
	 * it later, only where it is actually needed, so checking 'enqueued' at
	 * this hook's priority 20 missed a custom or multi-step checkout plugin
	 * that enqueues it any later — the fee refresh went stale until some
	 * other field happened to trigger `update_checkout`. Inline data attached
	 * to a registered handle prints if and only if that handle is eventually
	 * enqueued and printed, so 'registered' is strictly more robust here and
	 * costs nothing extra when the handle never gets enqueued.
	 *
	 * @return void
	 */
	public function enqueue_update_checkout_script()
	{

		if (! wp_script_is('wc-checkout', 'registered')) {
			return;
		}

		wp_add_inline_script('wc-checkout', self::UPDATE_CHECKOUT_JS);

	}

	/**
	 * Integrate fee calculation.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function integrate( $cart ) {

		// Nothing to pay (empty, or fully discounted with free shipping).
		// The cheap pre-gate: resolving the current gateway asks WooCommerce
		// for every registered one, which an empty cart should not pay for.
		// `FeeRun` asserts the same guard for all three fee paths.
		if ($this->is_nothing_to_pay($cart)) {
			return;
		}

		// Load current gateway and bail if none is found.
		$gateway = $this->helpers_wc->get_current_gateway();
		if ( ! $gateway ) {
			return;
		}
		$gateway_id = (string) $gateway->id;

		// Check if is extra charge enabled for current gateway.
		if (! $this->settings->is_extra_charge_enabled_for($gateway_id)) {
			return;
		}

		// Everything from here to the fee is `Fees\FeeRun`, the one copy of
		// the chain the order path and the per-gateway preview run too
		// (CONTEXT.md → Guard chain) — only the facts are this path's own,
		// and they are the live cart's.
		$run = new FeeRun(
			goods_after_discounts: (float) $cart->get_cart_contents_total(),
			shipping_total: (float) $cart->shipping_total,
			// No chosen shipping method (no matching zone, virtual-only cart) is
			// NOT a bail-out: v2 charged the fee in that situation (issue #55).
			shipping: $this->helpers_wc->chosen_cart_shipping(),
			snapshot: static fn ( bool $with_items ): CartSnapshot => CartSnapshot::from_cart( $cart, $with_items ),
			select_tier: fn ( string $chosen ): ?SelectedTier => $this->tier_conditions->select_cart_tier( $gateway_id, $cart, $chosen ),
			is_vat_exempt: fn (): bool => $this->helpers_wc->is_vat_exempt()
		);

		$fee = $run->resolve(
			$this->fee_engine,
			$gateway,
			$this->settings->get_method_settings( $gateway_id ),
			$this->settings->get_extra_charge( $gateway_id ),
			// v2 fired the settings filter before the COD shipping guard, and
			// the cart path still does (CONTEXT.md → Guard chain).
			legacy_filter_first: true
		);

		if ( is_null( $fee ) ) {
			return;
		}

		// Presentation only, calculation untouched: the display-controls
		// seam may withhold the computed fee from the cart (Pro
		// hide-on-cart, until a payment method is chosen); asked with a
		// real fee in hand, so a recorded suppression always has a fee
		// behind it. The free build never suppresses. Cart-only by
		// construction — the order path and the previews run the same
		// `FeeRun` and can never be suppressed.
		if ( $this->cart_fee_visibility->suppress_cart_fee() ) {
			return;
		}

		$cart->add_fee( $fee->title,
			$fee->cost,
			$fee->taxable,
			$fee->tax_class
		);
		$this->added_fee = array(
			'title' => $fee->title,
			'gateway_id' => $gateway_id,
		);

	}

	/**
	 * `Guards::is_nothing_to_pay()` on the live cart — `get_cart_contents_total()`
	 * is WooCommerce's goods total after discounts, computed before the fees
	 * hook fires.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return boolean
	 */
	public function is_nothing_to_pay($cart) {
		return Guards::is_nothing_to_pay( (float) $cart->get_cart_contents_total(), (float) $cart->shipping_total );
	}

}

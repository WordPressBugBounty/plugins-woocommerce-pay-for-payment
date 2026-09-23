<?php

namespace Pay4Payment\Fees;

/**
 * One run of the fee pipeline: everything between a resolved, fee-enabled
 * gateway and the `Fee` it charges for one basket — the guard chain, the v2
 * settings filter, the tier-selection seam and the engine call, in the order
 * every fee path runs them (CONTEXT.md → Guard chain).
 *
 * The three surfaces — the cart (`Frontend::integrate()`), the order
 * (`Pro\OrderFeeCalculator::calculate()`) and the per-gateway preview
 * (`Pro\GatewayFeePreview::calculate()`) — held that sequence in three
 * hand-kept copies until #188. The predicates were already shared (`Guards`,
 * `LegacyGatewaySettings`); the *sequencing* was not, so "preview == charge
 * == off-checkout charge" rested on discipline, and a guard added to one copy
 * could silently miss the other two (#144 had to be threaded through all
 * three by hand). Here it rests on construction: a guard added below applies
 * to everything that charges.
 *
 * What differs between the surfaces is only where the facts come from, so the
 * facts arrive as constructor arguments — the two totals and the chosen
 * shipping outright, and three callables for the facts that must not be read
 * on a path that bails before needing them, because they are expensive or
 * because reading them fires third-party filters: the snapshot (per-item
 * reads fire product filters), the tier selection (the match context reads
 * terms and products per cart line) and the VAT exemption (the order side
 * consults WooCommerce's own `woocommerce_order_is_vat_exempt`).
 *
 * Pure by refusal, like `FeeEngine` and `Guards`: no `WC()` runtime, no
 * session, no `Settings` reads, no side effects. Which gateway is charged and
 * whether the merchant enabled a fee for it are settled before this class is
 * reached; `WC_Cart::add_fee()` and the display-controls seam stay the cart
 * path's own business, so an order or a preview can never be suppressed.
 *
 * Shared code: `Pay4Payment\Pro` is named nowhere here (the free build strips
 * that folder); Pro behaviour arrives as the `TierConditionsInterface` seam's
 * answer inside the `select_tier` callable — `docs/agents/freemius.md`.
 */
final class FeeRun {

	/**
	 * Goods total after discounts, ex tax — the cart's own
	 * `get_cart_contents_total()`, the order's subtotal minus its discount.
	 * @var float
	 */
	private float $goods_after_discounts;

	/**
	 * Shipping total, the same figure both guards that read it get.
	 * @var float
	 */
	private float $shipping_total;

	/**
	 * The chosen shipping triple both paths supply in the same shape
	 * (`Helpers\WooCommerce::chosen_cart_shipping()` and its order-side twin
	 * `Pro\OrderSnapshot::chosen_shipping()`): the first package's
	 * "method:instance" and its bare method, plus every package's bare
	 * method. How much of it each rule reads is v2 parity, not an oversight
	 * (CONTEXT.md → Guard chain).
	 * @var array{chosen: string, method: string, methods: string[]}
	 */
	private array $shipping;

	/**
	 * Builds the basket snapshot the engine consumes, given whether the
	 * engine will read the items (`FeeContext::needs_cart_items()`).
	 * @var callable(bool): CartSnapshot
	 */
	private $snapshot;

	/**
	 * Asks the tier seam which tier applies, given the chosen shipping.
	 * @var callable(string): (SelectedTier|null)
	 */
	private $select_tier;

	/**
	 * The customer's VAT exemption — the cart's session customer, the
	 * order's own flag.
	 * @var callable(): bool
	 */
	private $is_vat_exempt;

	/**
	 * The tax rates per tax class the inclusive-tax deflation divides by –
	 * null for the engine's default, the session customer's
	 * (`WC_Tax::get_rates()`); the order path supplies the order's own
	 * taxable location (`FeeContext`, #266).
	 * @var callable(string): array<int|string, mixed>|null
	 */
	private $tax_rates;

	/**
	 * Constructor.
	 *
	 * @param float $goods_after_discounts Goods total after discounts (ex tax).
	 * @param float $shipping_total Shipping total.
	 * @param array{chosen: string, method: string, methods: string[]} $shipping Chosen shipping.
	 * @param callable(bool): CartSnapshot $snapshot Builds the snapshot on demand.
	 * @param callable(string): (SelectedTier|null) $select_tier Asks the tier seam on demand.
	 * @param callable(): bool $is_vat_exempt Reads the VAT exemption on demand.
	 * @param callable(string): array<int|string, mixed>|null $tax_rates Tax rates per tax class; the engine's default when null.
	 */
	public function __construct(
		float $goods_after_discounts,
		float $shipping_total,
		array $shipping,
		callable $snapshot,
		callable $select_tier,
		callable $is_vat_exempt,
		?callable $tax_rates = null
	) {
		$this->goods_after_discounts = $goods_after_discounts;
		$this->shipping_total = $shipping_total;
		$this->shipping = $shipping;
		$this->snapshot = $snapshot;
		$this->select_tier = $select_tier;
		$this->is_vat_exempt = $is_vat_exempt;
		$this->tax_rates = $tax_rates;
	}

	/**
	 * The fee this gateway charges for these facts, or null when any link of
	 * the chain calls it off.
	 *
	 * The settings arrive as *stored* values and are read exactly once by the
	 * caller: `apply()` and `reselect()` both see the one array, which lets
	 * the no-filter case settle on identity instead of walking every key.
	 *
	 * @param FeeEngine $engine The fee engine.
	 * @param \WC_Payment_Gateway $gateway The gateway being charged for — already resolved and known to have the fee enabled.
	 * @param array<int|string, mixed> $stored_method Stored canonical MethodSettings.
	 * @param array<int|string, mixed> $stored_tier Stored canonical FeeTier (the free tier).
	 * @param bool $legacy_filter_first Whether the v2 settings filter fires
	 *   BEFORE the COD shipping guard. The cart path says true — v2 resolved
	 *   the filtered settings first, and a snippet that runs on every
	 *   calculation still does. The order and preview paths say false: they
	 *   let the COD guard bail first, so a gateway that could not be used for
	 *   the chosen shipping never asks a snippet about a fee nobody can be
	 *   charged. The verdict is identical either way — COD's
	 *   `enable_for_methods` is read from the gateway, never from the
	 *   filtered array (CONTEXT.md → Legacy gateway settings) — so only a
	 *   side-effecting snippet can tell the two apart, and each path keeps
	 *   the order it has always had.
	 * @return Fee|null
	 */
	public function resolve(
		FeeEngine $engine,
		\WC_Payment_Gateway $gateway,
		array $stored_method,
		array $stored_tier,
		bool $legacy_filter_first = false
	): ?Fee {

		// Nothing to pay (empty, or fully discounted with free shipping).
		if ( Guards::is_nothing_to_pay( $this->goods_after_discounts, $this->shipping_total ) ) {
			return null;
		}

		// Bail if COD is restricted to shipping methods the current one isn't
		// among — before the filter on every path but the cart's.
		if ( ! $legacy_filter_first && $this->is_cod_unavailable( $gateway ) ) {
			return null;
		}

		// The v2 `woocommerce_pay4pay_get_current_gateway_settings` filter,
		// fired here so a snippet still shapes everything downstream of it —
		// the disable rules, the tier that gets selected, the tax fields and
		// the amounts — exactly as it did in v2 (CONTEXT.md → Legacy gateway
		// settings). Inert, and nearly free, when nothing is listening.
		$effective = LegacyGatewaySettings::apply( $gateway, $stored_method, $stored_tier );

		// The snippet called the fee off.
		if ( null === $effective ) {
			return null;
		}

		// The cart path's COD guard, in v2's position: after the filter.
		if ( $legacy_filter_first && $this->is_cod_unavailable( $gateway ) ) {
			return null;
		}

		// Bail if calculation is disabled — over the *effective* rules, which
		// the filter may have rewritten. 3rd-party opt-outs are handled by the
		// `woocommerce_pay4pay_apply` filters in FeeEngine::calculate().
		$method_settings = $effective['method'];
		if ( Guards::is_calculation_disabled( (array) ( $method_settings['disable_on'] ?? array() ), $this->shipping['methods'], $this->shipping_total ) ) {
			return null;
		}

		// Which tier applies — through the `TierConditionsInterface` seam
		// (CONTEXT.md → Tier selection): the free tier when it has charges in
		// the free build; in Pro, the first tier in array order that has
		// charges and whose conditions match, behind the Premium gate. Last in
		// the guard chain — building the match context reads terms/products per
		// basket line, so every tier-independent bail-out runs first.
		$selected = ( $this->select_tier )( $this->shipping['chosen'] );

		// The legacy filter's charge overrides land on the free tier, after
		// the seam has had its say about which tier applies.
		$selected = LegacyGatewaySettings::reselect( $selected, $stored_tier, $effective['tier'] );

		// No tier → no fee.
		if ( null === $selected ) {
			return null;
		}

		// Order lines refuse `inherit`; the engine resolves it for taxable fees.
		$context = FeeContext::for_gateway( $gateway, $method_settings, ( $this->is_vat_exempt )(), $this->tax_rates );

		return $engine->calculate( $selected, ( $this->snapshot )( $context->needs_cart_items() ), $context );

	}

	/**
	 * COD's `enable_for_methods` restricts the gateway to specific shipping
	 * methods; when the chosen one isn't among them the gateway isn't offered,
	 * so no fee applies. With no chosen shipping method the restriction is
	 * skipped entirely — v2 parity (issue #55).
	 *
	 * The one place that asks whether a gateway *is* COD: only that gateway
	 * carries the restriction, so every other one is spared the option read,
	 * and the id itself is `Guards::COD_GATEWAY_ID`. Read from the gateway
	 * object rather than from the filtered settings, deliberately — CONTEXT.md
	 * → Legacy gateway settings.
	 *
	 * @param \WC_Payment_Gateway $gateway The gateway being charged for.
	 * @return bool
	 */
	private function is_cod_unavailable( \WC_Payment_Gateway $gateway ): bool {

		$gateway_id = (string) $gateway->id;

		$enable_for_methods = Guards::COD_GATEWAY_ID === $gateway_id
			? (array) $gateway->get_option( 'enable_for_methods', [] )
			: [];

		return Guards::is_cod_unavailable_for_shipping(
			$gateway_id,
			$enable_for_methods,
			$this->shipping['chosen'],
			$this->shipping['method']
		);

	}
}

<?php

namespace Pay4Payment\Fees;

/**
 * Which tier of a gateway's `extra_charge` applies — the free↔Pro seam of
 * the guard chain, and the one free↔Pro boundary for tier logic
 * (CONTEXT.md → Tier selection, Guard chain, Premium gate, Pro seams).
 *
 * Tier selection is where the builds part ways. The free build binds
 * `NullTierConditions`: the free tier (`Settings::get_extra_charge()`,
 * tier 0) when it has charges, its conditions ignored, the tiers behind it
 * never read — the documented downgrade semantics. The Pro build binds
 * `Pro\TierConditions`: behind the Premium gate it walks
 * `Settings::get_tiers()` in array order and selects the first tier that
 * both has charges and whose conditions match; without a licence it
 * delegates to the free implementation, so a lapsed store charges exactly
 * what free charges.
 *
 * Both answer with a `SelectedTier` — the tier plus its index in the
 * gateway's ordered tier list, so the engine can address the tier from
 * the outside (per-tier WPML string names, the tier-identity argument on
 * the frozen filter chain) — or null. The per-tier zero-charge guard
 * lives inside the selection, so the caller's chain is: tier-independent
 * bail-outs, then the seam, then the engine. A null means "no fee", never
 * "fall back to tier 0". The cart side serves `Frontend::integrate()`,
 * the order side the off-checkout calculation (`Pro\OrderFeeCalculator`).
 * The free implementation answers the order side too — not for the free
 * build, where nothing asks it (`NullOrderFeeCalculator` never
 * calculates), but so the unlicensed Pro build can hand both sides to the
 * one free rule and the seam stays a single contract.
 */
interface TierConditionsInterface {

	/**
	 * The tier the gateway charges for the cart being calculated, or null
	 * when no tier applies.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param \WC_Cart $cart The cart being calculated.
	 * @param string $chosen_shipping Chosen shipping "method:instance", '' when none is chosen.
	 * @return SelectedTier|null The tier with its index, or null.
	 */
	public function select_cart_tier( string $gateway_id, $cart, string $chosen_shipping ): ?SelectedTier;

	/**
	 * The tier the gateway charges for an existing order (off-checkout:
	 * order-pay page, admin orders), or null when no tier applies.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param \WC_Order $order The order.
	 * @param string $chosen_shipping Chosen shipping "method:instance", '' when none.
	 * @return SelectedTier|null The tier with its index, or null.
	 */
	public function select_order_tier( string $gateway_id, $order, string $chosen_shipping ): ?SelectedTier;
}

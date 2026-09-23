<?php

namespace Pay4Payment\Fees;

/**
 * The fee a gateway charges for an existing order — the order-side twin of
 * the checkout calculation, consumed by the off-checkout fee sync
 * (CONTEXT.md → Off-checkout fee sync, Pro seams).
 *
 * Charging fees off-checkout is a Pro feature. The free build binds
 * `NullOrderFeeCalculator`: never enabled, never a fee — the sync can then
 * only remove stale lines (free never overcharges). The Pro build binds
 * `Pro\OrderFeeCalculator`, the guard chain + engine on an order (Pro never
 * undercharges), which also carries the licence check.
 */
interface OrderFeeCalculatorInterface {

	/**
	 * Whether fees may be charged off-checkout at all. The sync adds or
	 * refreshes lines only when this holds; the order-pay hint shows them
	 * only then — one predicate, so "hint == charge" cannot drift.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Whether a fee line may be *added* on the given sync surface — the one
	 * per-surface policy home, so the sync never branches on surfaces
	 * itself. Free: never, anywhere. Pro: `OrderFeeLines::SOURCE_CHECKOUT`
	 * (the Store-API draft — express Pay-Now and the blocks stale-session
	 * fast-switch, #115) needs the licence alone, deliberately independent
	 * of the off-checkout mode — it is a checkout flow; every other surface
	 * is `is_enabled()` (licence + mode).
	 *
	 * @param string $source One of the `OrderFeeLines::SOURCE_*` constants.
	 * @return bool
	 */
	public function may_charge( string $source ): bool;

	/**
	 * The fee the gateway charges for the order, or null when none applies.
	 *
	 * @param \WC_Order $order Order.
	 * @param string $gateway_id Gateway id.
	 * @return Fee|null
	 */
	public function calculate( $order, string $gateway_id ): ?Fee;
}

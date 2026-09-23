<?php

namespace Pay4Payment\Fees;

/**
 * The free build's order-side calculator: off-checkout fees are a Pro
 * feature, so it is never enabled and never yields a fee. With it bound
 * (`Bindings::SEAMS`) the off-checkout sync only ever removes another
 * gateway's stale line — free never overcharges, and never adds.
 */
final class NullOrderFeeCalculator implements OrderFeeCalculatorInterface {

	/**
	 * Never — charging off-checkout is Pro.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return false;
	}

	/**
	 * Never, on any surface — express/checkout-order enforcement is Pro too
	 * (#115). The sync can still remove another gateway's stale line on the
	 * created order: free never overcharges, and never adds.
	 *
	 * @param string $source Sync surface (unused).
	 * @return bool
	 */
	public function may_charge( string $source ): bool {
		return false;
	}

	/**
	 * No fee, ever.
	 *
	 * @param \WC_Order $order Order (unused).
	 * @param string $gateway_id Gateway id (unused).
	 * @return Fee|null
	 */
	public function calculate( $order, string $gateway_id ): ?Fee {
		return null;
	}
}

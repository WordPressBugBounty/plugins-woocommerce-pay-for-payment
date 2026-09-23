<?php

namespace Pay4Payment\Fees;

/**
 * Pure planner for the off-checkout fee sync: the plugin's existing fee
 * lines on an order + the gateway the customer/admin chose + the fee that
 * gateway charges (if any) in, add/update/remove operations out. The
 * applier (`OrderFeeSync` via `OrderFeeLines`) executes the ops against
 * the `WC_Order`.
 *
 * Invariants the plan guarantees:
 * - at most one plugin fee line per order, and only for the target gateway
 *   ("never two fees" — a customer retrying with cash on delivery must not
 *   pay PayPal's fee);
 * - without `$refresh`, a line the target gateway already has is left
 *   untouched (the amount the customer already accepted on order-pay);
 *   with `$refresh` (admin Recalculate) it follows the recomputed fee, and
 *   is removed when the fee no longer applies;
 * - a gateway the admin waived (they deleted the line the sync wrote for
 *   it) gets no new line until the gateway changes — stale lines of other
 *   gateways still go.
 */
final class FeeLinePlanner {

	/**
	 * Whether {@see plan()} could use a computed fee for these inputs —
	 * false exactly when the invariant "without `$refresh`, a line the
	 * target gateway already has is left untouched" makes the fee
	 * irrelevant. The applier asks this before computing the fee, so the
	 * rule for when the fee matters lives here, next to the plan it feeds.
	 *
	 * @param array<int, array{gateway_id: string}> $existing The plugin's fee lines on the order, as `OrderFeeLines::find()` returns them.
	 * @param string $target_gateway Gateway the order is (about to be) paid with.
	 * @param bool $refresh Whether an existing line of the target follows the recomputed fee.
	 * @return bool
	 */
	public function needs_fee( array $existing, string $target_gateway, bool $refresh ): bool {
		return $refresh || [] === OrderFeeLines::lines_for( $existing, $target_gateway );
	}

	/**
	 * Plan the line changes.
	 *
	 * @param array<int, array<string, mixed>> $existing The plugin's fee lines on the order,
	 *   as `OrderFeeLines::find()` returns them (at least `item_id` and `gateway_id`).
	 * @param string $target_gateway Gateway the order is (about to be) paid with; '' when none.
	 * @param Fee|null $fee The fee the target gateway charges, or null when none is due
	 *   (free build, mode off, guard chain bailed, zero fee).
	 * @param bool $refresh Whether an existing line of the target gateway follows the
	 *   recomputed fee (true) or is left untouched (false).
	 * @param string $waived_gateway Gateway whose line the admin removed on purpose; no line is
	 *   added for it while it stays the target.
	 * @return array{remove: int[], add: ?array{gateway_id: string, fee: Fee}, update: ?array{item_id: int, fee: Fee}, kept: ?int}
	 *   `kept` is the id of the target gateway's line that survives the plan (untouched or
	 *   updated), null when the order ends up without one.
	 */
	public function plan( array $existing, string $target_gateway, ?Fee $fee, bool $refresh, string $waived_gateway = '' ): array {
		$ops = [
			'remove' => [],
			'add' => null,
			'update' => null,
			'kept' => null,
		];

		foreach ( $existing as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['item_id'] ) || ! is_numeric( $line['item_id'] ) ) {
				continue;
			}

			$item_id = (int) $line['item_id'];
			$gateway_id = isset( $line['gateway_id'] ) && is_scalar( $line['gateway_id'] ) ? (string) $line['gateway_id'] : '';

			if ( '' !== $target_gateway && null === $ops['kept'] && $gateway_id === $target_gateway ) {
				$ops['kept'] = $item_id;
				continue;
			}

			// Stale: another gateway's fee, a duplicate, or no gateway at all.
			$ops['remove'][] = $item_id;
		}

		if ( null !== $ops['kept'] ) {
			if ( $refresh ) {
				if ( $fee ) {
					$ops['update'] = [
						'item_id' => $ops['kept'],
						'fee' => $fee,
					];
				} else {
					$ops['remove'][] = $ops['kept'];
					$ops['kept'] = null;
				}
			}
		} elseif ( $fee && '' !== $target_gateway && $waived_gateway !== $target_gateway ) {
			$ops['add'] = [
				'gateway_id' => $target_gateway,
				'fee' => $fee,
			];
		}

		return $ops;
	}
}

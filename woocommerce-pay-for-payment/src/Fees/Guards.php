<?php

namespace Pay4Payment\Fees;

/**
 * The guard chain's pure predicates, shared by the cart path
 * (`Frontend::integrate()`) and the order path (`Pro\OrderFeeCalculator`)
 * so a basket bails out on identical facts whether it is a live cart at
 * checkout or an order on the order-pay page / in the admin.
 *
 * Pure by refusal, like FeeEngine: every fact arrives as an argument;
 * nothing here reads `WC()`, Settings or the request. The callers keep
 * the order of the chain and the cheap-first discipline.
 */
final class Guards {

	/**
	 * The one gateway whose own WooCommerce settings restrict where it may be
	 * used — COD's `enable_for_methods`, the fact the shipping rule below
	 * reads. Named once, here beside that rule, so the id is not spelled out
	 * again on each fee path (#188): the only other site that asks is
	 * `FeeRun::is_cod_unavailable()`, which uses it to spare every other
	 * gateway the option read.
	 */
	public const COD_GATEWAY_ID = 'cod';

	/**
	 * Nothing to pay: no goods left to pay for after discounts and no
	 * shipping. Covers the empty basket and the 100 % discounted one with
	 * free shipping alike (PRD story 40: a customer never pays a fee on a
	 * free order). This completes v2's own guard from 2.0.11/2.0.12 — "don't
	 * add the fee when order amount is 0" — which tested the pre-discount
	 * subtotal and so only ever caught free products, never a discounted
	 * cart. Paid shipping on free goods is still something to pay, so the
	 * fee applies there.
	 *
	 * @param float $goods_after_discounts Goods total after discounts (ex tax).
	 * @param float $shipping_total Shipping total.
	 * @return bool
	 */
	public static function is_nothing_to_pay( float $goods_after_discounts, float $shipping_total ): bool {
		return $goods_after_discounts <= 0.0 && $shipping_total <= 0.0;
	}

	/**
	 * Whether a tier has anything to charge (a fixed or a percentage part).
	 *
	 * @param array<int|string, mixed> $tier Canonical FeeTier.
	 * @return bool
	 */
	public static function tier_has_charges( array $tier ): bool {
		return ! empty( $tier['charges_fixed'] ) || ! empty( $tier['charges_percentage_value'] );
	}

	/**
	 * COD's `enable_for_methods` restricts the gateway to specific shipping
	 * methods; when the chosen one isn't among them the gateway isn't
	 * offered, so no fee applies. With no chosen shipping method the
	 * restriction is skipped entirely — v2 parity (issue #55).
	 *
	 * @param string $gateway_id Gateway id.
	 * @param array<int|string, mixed> $enable_for_methods COD's `enable_for_methods` option.
	 * @param string $chosen_shipping Chosen shipping "method:instance" (or '' when none).
	 * @param string $chosen_method The method part of $chosen_shipping.
	 * @return bool
	 */
	public static function is_cod_unavailable_for_shipping( string $gateway_id, array $enable_for_methods, string $chosen_shipping, string $chosen_method ): bool {
		if ( self::COD_GATEWAY_ID !== $gateway_id || '' === $chosen_shipping ) {
			return false;
		}

		// Loose in_array on purpose: byte-identical with the pre-extraction
		// Frontend check (WC stores the option as a list of strings).
		return ! empty( $enable_for_methods )
			&& ! in_array( $chosen_shipping, $enable_for_methods ) // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			&& ! in_array( $chosen_method, $enable_for_methods ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
	}

	/**
	 * The method's `disable_on` rules: no fee with a free-shipping method
	 * and/or with zero shipping cost.
	 *
	 * Every chosen method is inspected, not just the first package's: v2
	 * `preg_grep`'d a `^free_shipping` pattern over the whole chosen-methods
	 * list, so one free-shipping package in a split shipment called the fee
	 * off for the basket (issue #145). Single-package carts — the large
	 * majority — see one entry and behave as before. The prefix match is v2's
	 * too: it catches `free_shipping` and anything named after it.
	 *
	 * @param array<int|string, mixed> $disable_on MethodSettings `disable_on`.
	 * @param string[] $shipping_methods Chosen shipping method ids (without instance), one per package; empty when none.
	 * @param float $shipping_total Shipping total.
	 * @return bool
	 */
	public static function is_calculation_disabled( array $disable_on, array $shipping_methods, float $shipping_total ): bool {
		if ( in_array( 'free_shipping', $disable_on, true ) ) {
			foreach ( $shipping_methods as $method ) {
				if ( str_starts_with( $method, 'free_shipping' ) ) {
					return true;
				}
			}
		}

		return in_array( 'zero_shipping', $disable_on, true ) && $shipping_total <= 0;
	}
}

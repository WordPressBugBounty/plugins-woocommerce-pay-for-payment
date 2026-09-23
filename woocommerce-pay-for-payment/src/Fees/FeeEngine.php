<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Helpers\Cast;
use Pay4Payment\Integrations\Wpml;

/**
 * Tier-parametric payment-fee engine.
 *
 * Owns the full v2-parity fee math, ported byte-identically from
 * `Frontend::calculate_pay4payment()`: percentage-base composition,
 * min/max caps, title placeholders, WPML lookups, VAT-exempt override,
 * inclusive-tax deflation, `inherit` tax-class resolution and rounding.
 *
 * The frozen `woocommerce_pay4pay_*` filter chain fires inside this
 * module, mid-computation, with v2 names, signatures and intermediates —
 * it is the engine's extension interface, and raw stored values reach the
 * filters uncast (coercion happens on filter output, via `Cast` — the same
 * result as v2's casts for every scalar; multi-currency parity). Since
 * multiple tiers can fire the same chain (#122), every filter carries the
 * selected tier's index as an ADDITIVE trailing argument — v2 callbacks,
 * registered with fewer accepted args, are unaffected — and the WPML
 * lookups use the per-tier string names (`Integrations\Wpml`; tier 0 keeps
 * the v2 names, so pre-#122 translations keep working).
 *
 * Pure by refusal: no `WC()` runtime, no session, no `Settings` reads, no
 * side effects, and never a `tier[0]` assumption — the tier argument is
 * the sole tier authority (`Settings::get_extra_charge()` stays the only
 * PHP tier[0] site, reached from the free tier selection,
 * `Fees\NullTierConditions`; which tier arrives here is the
 * `TierConditionsInterface` seam's decision). The guard chain and
 * `WC_Cart::add_fee()` remain the caller's job.
 */
final class FeeEngine {

	/**
	 * Compute the payment fee for one tier.
	 *
	 * @param SelectedTier $selected The tier with its index — one canonical
	 *   FeeTier (`item_title`, `charges_fixed`, `charges_percentage_value`,
	 *   `charges_percentage_minimum/maximum`, `charges_percentage_include[]`;
	 *   sparse arrays tolerated, unknown Pro keys ignored) plus its position
	 *   in the gateway's tier list, which keys the WPML string names and the
	 *   filter chain's tier-identity argument.
	 * @param CartSnapshot $cart Cart facts.
	 * @param FeeContext $context Gateway + method tax settings + VAT flag.
	 * @return Fee|null The fee, or null when the
	 *   `woocommerce_pay4pay_apply`/`_applyfor_{id}` chain vetoes
	 *   (including an unvetoed zero net cost). Never throws.
	 */
	public function calculate(SelectedTier $selected, CartSnapshot $cart, FeeContext $context): ?Fee {

		$tier = $selected->tier;
		$tier_index = $selected->index;
		$gateway = $context->gateway;
		$include = (array) ($tier['charges_percentage_include'] ?? []);

		$taxable       = $context->taxable;
		$include_taxes = $context->include_taxes;
		$tax_class     = $context->tax_class;

		$cost = Cast::to_float( apply_filters( 'woocommerce_pay4pay_charges_fixed', $tier['charges_fixed'] ?? 0, $gateway, $tier_index ) );

		$calculation_base = 0;
		$percent = Cast::to_float( apply_filters( 'woocommerce_pay4pay_charges_percentage', $tier['charges_percentage_value'] ?? 0, $gateway, $tier_index ) );

		if ( $percent ) {

			$calculation_base = $cart->subtotal_ex_tax;

			if ( in_array( 'shipping', $include, true ) ) {
				$calculation_base += $cart->shipping_total;
			}

			if ( in_array( 'fees', $include, true ) ) {
				$calculation_base += $cart->fee_total;
			}

			if ( in_array( 'coupons', $include, true ) ) {
				$calculation_base -= $cart->discount;
			}

			if ( in_array( 'taxes', $include, true ) ) {
				$calculation_base += $cart->tax_total;
				if ( in_array( 'shipping', $include, true ) ) {
					$calculation_base += $cart->shipping_tax_total;
				}
			}

			$cost += $calculation_base * ( $percent / 100 );

		}

		$do_apply = $cost != 0;
		$do_apply = apply_filters( 'woocommerce_pay4pay_apply', $do_apply, $cost, $calculation_base, $gateway, $tier_index );
		$do_apply = apply_filters( "woocommerce_pay4pay_applyfor_{$gateway->id}", $do_apply, $cost, $calculation_base, $gateway, $tier_index );

		if ( ! $do_apply ) {
			return null;
		}

		// The coercion is load-bearing, not cosmetic: both branches are `mixed`
		// to the analyser and to PHP. `wpml_translate_single_string` is a
		// third-party filter (WPML's) that this plugin does not control, and
		// `$gateway->title` is whatever the gateway set. A filter returning null
		// or an array used to reach str_contains() below and fatal on a live
		// checkout; coercing makes it defined behaviour instead.
		$fee_title = Cast::to_string( ! empty( $tier['item_title'] )
			? apply_filters( 'wpml_translate_single_string', $tier['item_title'], Wpml::CONTEXT, Wpml::item_title_string_name( (string) $gateway->id, $tier_index ) ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, invoked here rather than defined.
			: $gateway->title );

		// Placeholder replacements are skipped for the common case of a plain
		// title — wc_price() and the WPML lookup are not free on this hot path.
		$has_placeholders = str_contains( $fee_title, '[' );

		if ( $has_placeholders ) {
			$fee_title = str_replace(
				array( '[FIXED_AMOUNT]', '[PERCENT_AMOUNT]', '[CART_TOTAL]' ),
				array(
					wp_strip_all_tags( wc_price( Cast::to_float( apply_filters( 'wpml_translate_single_string', $tier['charges_fixed'] ?? 0, Wpml::CONTEXT, Wpml::charges_fixed_string_name( (string) $gateway->id, $tier_index ) ) ) ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, invoked here rather than defined.
					(string) Cast::to_float( $tier['charges_percentage_value'] ?? 0 ),
					wp_strip_all_tags( wc_price( $calculation_base ) ),
				),
				$fee_title
			);
		}

		// Apply min + max caps before tax calculation. Negative values stay
		// intact so the plugin can also be used to apply discounts.
		if ( ! empty( $tier['charges_percentage_value'] ) ) {

			$min_cost = ! empty( $tier['charges_percentage_minimum'] )
				? Cast::to_float( apply_filters( 'woocommerce_pay4pay_charges_minimum', $tier['charges_percentage_minimum'], $gateway, $tier_index ) )
				: -INF;
			$max_cost = ! empty( $tier['charges_percentage_maximum'] )
				? Cast::to_float( apply_filters( 'woocommerce_pay4pay_charges_maximum', $tier['charges_percentage_maximum'], $gateway, $tier_index ) )
				: INF;

			$cost = max( $min_cost, $cost );
			$cost = min( $max_cost, $cost );

			if ( $has_placeholders ) {
				$fee_title = str_replace(
					array( '[MINIMUM_AMOUNT]', '[MAXIMUM_AMOUNT]' ),
					array(
						wp_strip_all_tags( wc_price( $min_cost ) ),
						wp_strip_all_tags( wc_price( $max_cost ) ),
					),
					$fee_title
				);
			}

		}

		// A taxable fee never leaves with the literal `inherit`: WooCommerce
		// knows no such class — `WC_Cart_Fees::add_fee()` falls back to the
		// standard class (so a tax-exclusive amount was taxed at the standard
		// rate instead of the highest cart-item class, #99) and order lines
		// refuse the slug. Resolved whenever taxable — exclusive and inclusive
		// path alike — to the item class with the highest rate; the standard
		// class when the basket has nothing to inherit from.
		if ( $taxable && 'inherit' === $tax_class ) {
			$tax_class = $this->resolve_inherit_tax_class( $cart->items );
		}

		// A WooCommerce fee is always ex taxes — subtract the tax share here,
		// WC adds it back on top later. Whose rates: the context's – the
		// session customer's at checkout, the order's own location off it.
		if ( $taxable && $include_taxes ) {

			$tax_rates = $context->tax_rates( $tax_class );

			$factor = 1;
			foreach ( $tax_rates as $rate ) {
				// Rate rows are WooCommerce's (`WC_Tax::get_rates()` /
				// `find_rates()` shape) and pass through its own filters on
				// the way, so a row is coerced like a filter result, not trusted.
				$factor += ( is_array( $rate ) ? Cast::to_float( $rate['rate'] ?? 0 ) : 0.0 ) / 100;
			}
			$cost /= $factor;

		}

		/**
		 * VAT-exempt customers never pay fee taxes.
		 * https://woocommerce.com/document/class-reference/#section-5
		 *
		 * Applied AFTER the deflation above, not before it. A fee entered as
		 * tax-inclusive names a gross price, so the net the store keeps is the
		 * deflated amount whoever is buying: exempting the customer removes
		 * the tax, it does not hand the store the tax as extra margin. Running
		 * the exemption first skipped the deflation and charged the exempt
		 * customer the whole gross as net — 12.10 where everyone else paid
		 * 10.00 + 2.10 — which is also the opposite of what WooCommerce does
		 * with tax-inclusive PRODUCT prices on the same order, where an exempt
		 * customer pays the ex-tax price (#282).
		 *
		 * v2 exempted first. This is a deliberate parity break: a store
		 * charging tax-inclusive fees now bills VAT-exempt customers the net
		 * amount rather than the gross.
		 *
		 * The `inherit` resolution above is likewise keyed off the CONFIGURED
		 * flag, so an exempt customer's fee still leaves with a real tax class
		 * instead of the literal `inherit` WooCommerce cannot read.
		 */
		if ( $context->is_vat_exempt ) {
			$taxable = false;
		}

		$cost = Cast::to_float( apply_filters( "woocommerce_pay4pay_{$gateway->id}_amount", $cost, $calculation_base, $gateway, $taxable, $include_taxes, $tax_class, $tier_index ) );
		$cost = round( $cost, \wc_get_rounding_precision() );

		return new Fee( $fee_title, $cost, $taxable, $tax_class, $calculation_base );

	}

	/**
	 * Resolve the `inherit` tax class to the tax class of the cart item
	 * with the highest tax rate (see v2 issue #65); the standard class
	 * when there are no items.
	 *
	 * @param array<int, array{line_tax: float, line_total: float, tax_class: string}> $items Cart item triples (line_tax, line_total, tax_class).
	 * @return string
	 */
	private function resolve_inherit_tax_class( array $items ): string {

		$tax_class    = '';
		$highest_rate = 0;

		foreach ( $items as $item ) {

			if ( ! $item['line_tax'] || ! $item['line_total'] ) {
				$item_rate = 0;
			} else {
				$item_rate = $item['line_tax'] / $item['line_total'];
			}

			if ( $item_rate >= $highest_rate ) {
				$highest_rate = $item_rate;
				$tax_class = $item['tax_class'];
			}
		}

		return $tax_class;

	}
}

<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Helpers\Cast;

/**
 * The v2 `woocommerce_pay4pay_get_current_gateway_settings` filter, kept
 * working against the v3 schema.
 *
 * v2 resolved one flat array per calculation — the gateway's own WC settings
 * merged with the fourteen `pay4pay_*` keys — filtered it, and read the fee
 * configuration back out of whatever the filter returned
 * (`class-pay4pay.php:312-319`). Shops use it for the rules the settings
 * screen has never covered ("no fee above 100", "half the fee for wholesale
 * customers"), and it is live API: v2.2.2 restored it after it had been
 * broken since 2.1.0.
 *
 * v3 has no such flat array — the method-scoped fields, `disable_on` and the
 * charge tiers are separate reads — so this module rebuilds v2's shape, fires
 * the same filter with the same signature, and folds the result back into the
 * canonical shapes the guards and the engine already understand. Callers get
 * settings they can use without knowing the filter exists.
 *
 * No `WC()` runtime, no session, no `Settings` reads and no side effects: the
 * configuration arrives as arguments, and `Settings` is named only for its
 * canonical defaults, as `FeeContext` does. The one thing it does read is the
 * hook registry — which is the whole point of it.
 *
 * Fired from all three fee paths — `Frontend::integrate()`,
 * `Pro\OrderFeeCalculator::calculate()` and `Pro\GatewayFeePreview::calculate()`
 * — so the charge, the off-checkout charge and the preview a shopper compares
 * gateways by can never disagree about what a snippet said. Its deliberate
 * limits — overrides land on the free tier, the merchant's on/off switch stays
 * authoritative, and COD's `enable_for_methods` is read from the gateway
 * rather than from the filtered array — are in `CONTEXT.md` → Legacy gateway
 * settings.
 */
final class LegacyGatewaySettings {

	/**
	 * The v2 filter name, frozen.
	 */
	public const FILTER = 'woocommerce_pay4pay_get_current_gateway_settings';

	/**
	 * Fire the v2 filter over a v2-shaped view of the effective settings and
	 * fold the result back.
	 *
	 * Skipped entirely when nothing is listening, so an unfiltered store pays
	 * only a `has_filter()` check on the fee path.
	 *
	 * Null means the snippet called the fee off. v2 assigned the filter's
	 * return inside the `if` that wrapped the whole calculation
	 * (`class-pay4pay.php:113`), so returning `false` — the stock WordPress
	 * way to say no — skipped it entirely, and so did `null` or `[]`. Anything
	 * truthy that is not an array is a snippet accident rather than an
	 * instruction (v2 fataled on it); the configuration is left alone.
	 *
	 * @param object $gateway The gateway object, forwarded to the filter verbatim as v2 did.
	 * @param array<int|string, mixed> $method Canonical MethodSettings.
	 * @param array<int|string, mixed> $tier Canonical FeeTier (the free tier).
	 * @return array{method: array<int|string, mixed>, tier: array<int|string, mixed>}|null
	 */
	public static function apply( $gateway, array $method, array $tier ): ?array {

		if ( has_filter( self::FILTER ) ) {

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the frozen v2 name, `woocommerce_pay4pay_get_current_gateway_settings`; naming it once keeps the fire and the has_filter() check from drifting.
			$filtered = apply_filters( self::FILTER, self::to_v2_shape( $gateway, $method, $tier ), $gateway );

			if ( ! $filtered ) {
				return null;
			}

			if ( is_array( $filtered ) ) {
				return array(
					'method' => self::method_from_v2_shape( $filtered, $method ),
					'tier' => self::tier_from_v2_shape( $filtered, $tier ),
				);
			}
		}

		return array(
			'method' => $method,
			'tier' => $tier,
		);

	}

	/**
	 * Fold the filter's charge overrides into the tier selection.
	 *
	 * The seam selects over the *stored* tiers, which is what conditions and
	 * ordering are about; this applies the filter's answer afterwards, and
	 * only ever to the free tier — the one fee v2 had.
	 *
	 * Three cases, all of them v2 behaviour: a filter that changed the free
	 * tier's amounts re-prices the selection; a filter that zeroed them calls
	 * the fee off (v2's `if ( $fixed || $percentage )` read the *filtered*
	 * values); and a filter that supplied amounts where the merchant stored
	 * none charges the fee, which is how a store whose fee lives entirely in
	 * code works. That last case is deliberately limited to a free tier with
	 * no conditions on it — a Pro tier whose conditions did not match was not
	 * selected on purpose, and code that never knew about conditions should
	 * not overrule them.
	 *
	 * @param SelectedTier|null $selected What the seam selected.
	 * @param array<int|string, mixed> $stored_tier The stored free tier.
	 * @param array<int|string, mixed> $effective_tier The free tier after the filter.
	 * @return SelectedTier|null
	 */
	public static function reselect( ?SelectedTier $selected, array $stored_tier, array $effective_tier ): ?SelectedTier {

		// Nothing listening, or a filter that returned what it was given: the
		// seam's answer stands untouched. Checked on the data rather than on
		// a flag, so "a filter ran but changed nothing" costs nothing either.
		// Compared in one normal form, because `tier_from_v2_shape()` rebuilds
		// the tier even from an unchanged array (the percentage base in the
		// checkbox map's order, the title as a string) and a plain `===`
		// rarely held once a listener existed.
		if ( self::comparable( $effective_tier ) === self::comparable( $stored_tier ) ) {
			return $selected;
		}

		if ( null !== $selected && 0 !== $selected->index ) {
			return $selected;
		}

		if ( null === $selected ) {
			$conjured = ! Guards::tier_has_charges( $stored_tier )
				&& Guards::tier_has_charges( $effective_tier )
				&& empty( $stored_tier['conditions'] );

			return $conjured ? new SelectedTier( 0, $effective_tier ) : null;
		}

		return Guards::tier_has_charges( $effective_tier )
			? new SelectedTier( 0, $effective_tier )
			: null;

	}

	/**
	 * A tier in the one form `reselect()` compares: completed against the
	 * canonical defaults, the title a string, the percentage base in its
	 * canonical order with only known values – what a round trip through
	 * the v2 shape leaves of an unchanged tier – and the keys sorted.
	 *
	 * @param array<int|string, mixed> $tier Canonical FeeTier, possibly sparse.
	 * @return array<int|string, mixed>
	 */
	private static function comparable( array $tier ): array {
		$tier = Settings::complete_tier( $tier );
		$tier['item_title'] = Cast::to_string( $tier['item_title'] );
		$tier['charges_percentage_include'] = array_values( array_intersect(
			Settings::PERCENTAGE_INCLUDE_OPTIONS,
			Cast::to_string_list( (array) $tier['charges_percentage_include'] )
		) );
		ksort( $tier );

		return $tier;
	}

	/**
	 * `Settings::DEFAULTS_METHODS` in v2's key vocabulary — the fourteen
	 * `pay4pay_*` keys as a store with nothing configured would have had
	 * them.
	 *
	 * The migration completes a sparse v2 array against this instead of
	 * re-typing the same yes/no answers per key, so the canonical defaults
	 * and the migration cannot drift apart about what an absent v2 key meant
	 * (#161 — two hand-kept copies of that fact are how #147 happened). This
	 * class already owns the canonical→v2 translation for the runtime filter;
	 * running it over empty inputs is that same translation applied to
	 * nothing but the defaults.
	 *
	 * No gateway, so the result carries the `pay4pay_*` keys alone — the
	 * migration reads only those.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function default_v2_settings(): array {
		return self::to_v2_shape( null, array(), array() );
	}

	/**
	 * The array v2 handed the filter: the gateway's own settings with the
	 * `pay4pay_*` keys on top.
	 *
	 * v2 wrote `$gateway->settings + $defaults`, so the gateway's stored
	 * values won. Here the v3 configuration wins instead — a store upgraded
	 * from v2 still carries its old `pay4pay_*` keys in the gateway option
	 * (the migration is non-destructive by design), and those are stale the
	 * moment the merchant edits anything in the v3 screen. Everything else
	 * the gateway holds — `title`, `enable_for_methods`, … — rides along as
	 * v2 provided it.
	 *
	 * @param object|null $gateway Gateway object, or null for the `pay4pay_*` keys alone (see `default_v2_settings()`) — the body tolerates anything that is not an object carrying a `settings` array.
	 * @param array<int|string, mixed> $method Canonical MethodSettings.
	 * @param array<int|string, mixed> $tier Canonical FeeTier.
	 * @return array<int|string, mixed>
	 */
	private static function to_v2_shape( $gateway, array $method, array $tier ): array {

		// Complete sparse inputs from the canonical defaults, the way the
		// engine's context does, so a snippet is shown the configuration the
		// fee is actually calculated from rather than a hand-written guess.
		$tier = Settings::complete_tier( $tier );
		$method = Settings::complete_method( $method );

		$include = (array) $tier['charges_percentage_include'];
		$disable_on = (array) $method['disable_on'];

		$pay4pay = array(
			'pay4pay_item_title' => Cast::to_string( $tier['item_title'] ),
			'pay4pay_charges_fixed' => $tier['charges_fixed'],
			'pay4pay_charges_percentage' => $tier['charges_percentage_value'],
			'pay4pay_charges_minimum' => $tier['charges_percentage_minimum'],
			'pay4pay_charges_maximum' => $tier['charges_percentage_maximum'],
			'pay4pay_disable_on_free_shipping' => self::to_yes_no( in_array( 'free_shipping', $disable_on, true ) ),
			'pay4pay_disable_on_zero_shipping' => self::to_yes_no( in_array( 'zero_shipping', $disable_on, true ) ),
			'pay4pay_taxes' => self::to_yes_no( ! empty( $method['taxes'] ) ),
			'pay4pay_includes_taxes' => self::to_yes_no( ! empty( $method['includes_taxes'] ) ),
			'pay4pay_tax_class' => Cast::to_string( $method['tax_class'] ),
			'pay4pay_enable_extra_fees' => self::to_yes_no( in_array( 'fees', $include, true ) ),
			'pay4pay_include_shipping' => self::to_yes_no( in_array( 'shipping', $include, true ) ),
			'pay4pay_include_coupons' => self::to_yes_no( in_array( 'coupons', $include, true ) ),
			'pay4pay_include_cart_taxes' => self::to_yes_no( in_array( 'taxes', $include, true ) ),
		);

		$gateway_settings = ( is_object( $gateway ) && isset( $gateway->settings ) && is_array( $gateway->settings ) )
			? $gateway->settings
			: array();

		return array_merge( $gateway_settings, $pay4pay );

	}

	/**
	 * Read the method-scoped fields back out of a filtered v2 array.
	 *
	 * A key the filter dropped keeps its current value — v2 read whatever was
	 * in the array, but a snippet that returns a partial array meant to change
	 * that one key, not to reset the rest.
	 *
	 * @param array<int|string, mixed> $filtered The filtered v2-shaped array.
	 * @param array<int|string, mixed> $method Canonical MethodSettings.
	 * @return array<int|string, mixed>
	 */
	private static function method_from_v2_shape( array $filtered, array $method ): array {

		if ( array_key_exists( 'pay4pay_taxes', $filtered ) ) {
			$method['taxes'] = self::is_yes( $filtered['pay4pay_taxes'] );
		}

		if ( array_key_exists( 'pay4pay_includes_taxes', $filtered ) ) {
			$method['includes_taxes'] = self::is_yes( $filtered['pay4pay_includes_taxes'] );
		}

		if ( array_key_exists( 'pay4pay_tax_class', $filtered ) ) {
			$method['tax_class'] = Cast::to_string( $filtered['pay4pay_tax_class'] );
		}

		$method['disable_on'] = self::membership_from_v2_shape(
			$filtered,
			array(
				'pay4pay_disable_on_free_shipping' => 'free_shipping',
				'pay4pay_disable_on_zero_shipping' => 'zero_shipping',
			),
			(array) ( $method['disable_on'] ?? array() )
		);

		return $method;

	}

	/**
	 * Read the charge fields back out of a filtered v2 array.
	 *
	 * Amounts are folded back uncast: the engine passes raw stored values to
	 * the frozen `woocommerce_pay4pay_charges_*` filters and coerces on their
	 * output (multi-currency parity, CONTEXT.md → Fee engine), and a value
	 * this filter supplied is no different.
	 *
	 * @param array<int|string, mixed> $filtered The filtered v2-shaped array.
	 * @param array<int|string, mixed> $tier Canonical FeeTier.
	 * @return array<int|string, mixed>
	 */
	private static function tier_from_v2_shape( array $filtered, array $tier ): array {

		if ( array_key_exists( 'pay4pay_item_title', $filtered ) ) {
			$tier['item_title'] = Cast::to_string( $filtered['pay4pay_item_title'] );
		}

		$amounts = array(
			'pay4pay_charges_fixed' => 'charges_fixed',
			'pay4pay_charges_percentage' => 'charges_percentage_value',
			'pay4pay_charges_minimum' => 'charges_percentage_minimum',
			'pay4pay_charges_maximum' => 'charges_percentage_maximum',
		);

		foreach ( $amounts as $v2_key => $canonical_key ) {
			if ( array_key_exists( $v2_key, $filtered ) ) {
				$tier[ $canonical_key ] = $filtered[ $v2_key ];
			}
		}

		$tier['charges_percentage_include'] = self::membership_from_v2_shape(
			$filtered,
			array(
				'pay4pay_enable_extra_fees' => 'fees',
				'pay4pay_include_coupons' => 'coupons',
				'pay4pay_include_shipping' => 'shipping',
				'pay4pay_include_cart_taxes' => 'taxes',
			),
			(array) ( $tier['charges_percentage_include'] ?? array() )
		);

		return $tier;

	}

	/**
	 * Rebuild one of the canonical membership lists (`disable_on`,
	 * `charges_percentage_include`) from the v2 checkboxes that describe it.
	 *
	 * A checkbox the filter returned decides; one it dropped keeps whatever
	 * the stored list said. Order follows the map, not the filter's array, so
	 * the result is stable whatever a snippet hands back.
	 *
	 * @param array<int|string, mixed> $filtered The filtered v2-shaped array.
	 * @param array<string, string> $checkboxes v2 checkbox key => canonical list value.
	 * @param array<int|string, mixed> $stored The stored canonical list.
	 * @return array<int, string>
	 */
	private static function membership_from_v2_shape( array $filtered, array $checkboxes, array $stored ): array {

		$list = array();

		foreach ( $checkboxes as $v2_key => $canonical_value ) {
			$on = array_key_exists( $v2_key, $filtered )
				? self::is_yes( $filtered[ $v2_key ] )
				: in_array( $canonical_value, $stored, true );

			if ( $on ) {
				$list[] = $canonical_value;
			}
		}

		return $list;

	}

	/**
	 * v2 stored every checkbox as the string 'yes' or 'no'.
	 *
	 * @param bool $value Whether the option is on.
	 * @return string
	 */
	private static function to_yes_no( bool $value ): string {
		return $value ? 'yes' : 'no';
	}

	/**
	 * v2 tested checkboxes with `'yes' == $value`. Booleans are accepted too,
	 * because a snippet written against this reconstruction may well return
	 * one.
	 *
	 * @param mixed $value Value from the filtered array.
	 * @return bool
	 */
	private static function is_yes( $value ): bool {
		return is_string( $value ) ? 'yes' === strtolower( $value ) : (bool) $value;
	}

}

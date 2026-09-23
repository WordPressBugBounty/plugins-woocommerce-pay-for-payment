<?php

namespace Pay4Payment\Integrations;

/**
 * WPML String Translation integration.
 *
 * Registers the translatable strings whenever payment method settings are
 * saved — through the REST API, the migration, or any future write path
 * (all of them go through Settings::update_method_settings()). One string
 * pair per tier: tier 0 keeps the v2 names (`{method_id} - item title` /
 * `- charges fixed`), so translations made under v2 keep working; tiers
 * 1..n get indexed names (`{method_id} - tier {n} - item title`). The
 * name builders below are the single vocabulary — `Fees\FeeEngine` reads
 * the strings back per tier via `wpml_translate_single_string` with the
 * same names, so registration and lookup cannot drift apart.
 */
class Wpml implements IntegrationInterface {

	/**
	 * The WPML string-translation context every string registers under —
	 * the v2 value, frozen with the translations that exist under it.
	 * @var string
	 */
	public const CONTEXT = 'woocommerce-pay-for-payment';

	/**
	 * The WPML string name of a tier's `item_title`. Tier 0 keeps the v2
	 * name so existing translations survive; later tiers are indexed.
	 *
	 * @param string $method_id Payment method ID.
	 * @param int $tier_index Tier index in the ordered tier list.
	 * @return string
	 */
	public static function item_title_string_name( string $method_id, int $tier_index ): string {
		return 0 === $tier_index
			? $method_id . ' - item title'
			: $method_id . ' - tier ' . $tier_index . ' - item title';
	}

	/**
	 * The WPML string name of a tier's `charges_fixed` (translatable so a
	 * multi-currency setup can localize the `[FIXED_AMOUNT]` placeholder).
	 * Tier 0 keeps the v2 name; later tiers are indexed.
	 *
	 * @param string $method_id Payment method ID.
	 * @param int $tier_index Tier index in the ordered tier list.
	 * @return string
	 */
	public static function charges_fixed_string_name( string $method_id, int $tier_index ): string {
		return 0 === $tier_index
			? $method_id . ' - charges fixed'
			: $method_id . ' - tier ' . $tier_index . ' - charges fixed';
	}

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * @inheritDoc
	 */
	public function setup() {
		add_action( 'pay4payment_method_settings_saved', array( $this, 'register_strings' ), 10, 2 );
	}

	/**
	 * Register the translatable strings of a payment method — one pair per
	 * tier, under the per-tier names. A method with nothing stored still
	 * registers the (empty) tier-0 pair, as v2 did.
	 *
	 * @param string $method_id Payment method ID.
	 * @param array<int|string, mixed> $data Saved method settings (canonical schema).
	 * @return void
	 */
	public function register_strings( $method_id, array $data ) {
		$tiers = isset( $data['extra_charge'] ) && is_array( $data['extra_charge'] ) ? array_values( $data['extra_charge'] ) : [];
		if ( [] === $tiers ) {
			$tiers = [ [] ];
		}

		foreach ( $tiers as $index => $tier ) {
			if ( ! is_array( $tier ) ) {
				$tier = [];
			}
			do_action( 'wpml_register_single_string', self::CONTEXT, self::item_title_string_name( $method_id, $index ), $tier['item_title'] ?? '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, invoked here rather than defined.
			do_action( 'wpml_register_single_string', self::CONTEXT, self::charges_fixed_string_name( $method_id, $index ), $tier['charges_fixed'] ?? 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, invoked here rather than defined.
		}
	}

}

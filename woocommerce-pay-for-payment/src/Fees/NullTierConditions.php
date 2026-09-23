<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Admin\Settings;

/**
 * The free build's tier selection: the free tier, unconditionally. Free
 * charges tier 0 (`Settings::get_extra_charge()`, the single PHP tier[0]
 * site) whenever it has charges — its conditions are never evaluated and
 * the tiers behind it are never read, so a conditional or multi-tier
 * configuration behaves on free exactly as it did pre-Pro (CONTEXT.md →
 * Downgrade semantics). Bound in `Bindings::SEAMS`; the Pro build replaces
 * it with `Pro\TierConditions`, which delegates back here without a
 * licence so that "lapsed == free" holds by construction.
 */
final class NullTierConditions implements TierConditionsInterface {

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The free tier when it has charges — the cart is not read.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param \WC_Cart $cart The cart (unused).
	 * @param string $chosen_shipping Chosen shipping (unused).
	 * @return SelectedTier|null
	 */
	public function select_cart_tier( string $gateway_id, $cart, string $chosen_shipping ): ?SelectedTier {
		return $this->free_tier( $gateway_id );
	}

	/**
	 * The free tier when it has charges — the order is not read.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param \WC_Order $order The order (unused).
	 * @param string $chosen_shipping Chosen shipping (unused).
	 * @return SelectedTier|null
	 */
	public function select_order_tier( string $gateway_id, $order, string $chosen_shipping ): ?SelectedTier {
		return $this->free_tier( $gateway_id );
	}

	/**
	 * Tier 0 when it has anything to charge; null otherwise — never a
	 * later tier.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return SelectedTier|null
	 */
	private function free_tier( string $gateway_id ): ?SelectedTier {
		$tier = $this->settings->get_extra_charge( $gateway_id );
		return Guards::tier_has_charges( $tier ) ? new SelectedTier( 0, $tier ) : null;
	}
}

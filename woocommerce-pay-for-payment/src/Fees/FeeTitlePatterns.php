<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\WooCommerce;

/**
 * Builds the title patterns `OrderFeeLines::find()` uses to recognise the
 * plugin's untagged fee lines (orders placed before tagging existed): one
 * regex per stored tier title of every managed gateway, plus the gateway
 * title itself where a tier has no title (the engine falls back to it).
 *
 * Built on first need and then once per request — settings and gateway
 * titles do not change underneath an order sync. First need means the
 * first *untagged* fee line, which an order this plugin version wrote
 * never has, so the common order costs no regex compilation at all.
 */
class FeeTitlePatterns {

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * WC-runtime adapter
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Built patterns, memoised for the request.
	 * @var array<string, string[]>|null
	 */
	private $patterns;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param WooCommerce $helpers_wc WC-runtime adapter.
	 */
	public function __construct( Settings $settings, WooCommerce $helpers_wc ) {
		$this->settings = $settings;
		$this->helpers_wc = $helpers_wc;
	}

	/**
	 * Regexes per managed gateway id.
	 *
	 * @return array<string, string[]>
	 */
	public function build(): array {
		if ( null !== $this->patterns ) {
			return $this->patterns;
		}

		$gateways = $this->helpers_wc->payment_gateways();
		$patterns = [];

		foreach ( $this->settings->enabled_methods() as $gateway_id ) {
			// Through the tier accessor, not the raw option (#184): it owns
			// what a corrupt list and a corrupt entry mean, so every tier
			// here is an array carrying the canonical keys and only the
			// title's own type is this loop's business.
			$tiers = $this->settings->get_tiers( $gateway_id );
			$list = [];
			$needs_gateway_title = [] === $tiers;

			foreach ( $tiers as $tier ) {
				$title = is_string( $tier['item_title'] ) ? trim( $tier['item_title'] ) : '';
				if ( '' !== $title ) {
					$list[] = OrderFeeLines::title_pattern( $title );
				} else {
					$needs_gateway_title = true;
				}
			}

			if ( $needs_gateway_title ) {
				$gateway = $gateways[ $gateway_id ] ?? null;
				$title = $gateway ? trim( Cast::to_string( $gateway->title ) ) : '';
				if ( '' !== $title ) {
					$list[] = OrderFeeLines::title_pattern( $title );
				}
			}

			if ( [] !== $list ) {
				$patterns[ $gateway_id ] = array_values( array_unique( $list ) );
			}
		}

		$this->patterns = $patterns;

		return $patterns;
	}
}

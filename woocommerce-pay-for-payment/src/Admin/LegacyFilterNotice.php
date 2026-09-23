<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Fees\LegacyGatewaySettings;
use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Migration;

/**
 * Tells a merchant when their own code is trying to charge a fee the
 * settings screen has switched off.
 *
 * The v2 `woocommerce_pay4pay_get_current_gateway_settings` filter can supply
 * the amounts a store never stored, but the per-method on/off switch is
 * checked first and stays the merchant's decision — so a snippet aimed at a
 * method that is off does nothing at all, silently. The migration covers the
 * stores that carried such a snippet through the upgrade
 * (`Migration::migrate()`); this covers the rest: a snippet written or
 * activated afterwards, or aimed at a gateway the store never configured
 * under v2.
 *
 * Shown only on the plugin's own settings tab, where the checkbox it asks for
 * is one click away — so it is actionable where it appears and disappears the
 * moment it is acted on, rather than being another dismissible banner.
 */
class LegacyFilterNotice implements ComponentInterface {

	/**
	 * Settings.
	 * @var Settings
	 */
	private $settings;

	/**
	 * WC-runtime adapter.
	 * @var WooCommerce
	 */
	private $helpers_wc;

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
	 * Setup.
	 * @return void
	 */
	public function setup() {
		if ( ! $this->settings->is_current_tab() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Render the notice when there is something to say.
	 * @return void
	 */
	public function render_notice() {

		$gateways = $this->unreachable_gateways();
		if ( ! $gateways ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: filter name, 2: comma-separated payment method names. */
					__( 'Code on this site filters %1$s for payment methods whose fee is switched off here, so it cannot charge anything: %2$s. Switch a method on — leaving its amounts at zero — to let your code set the fee.', 'woocommerce-pay-for-payment' ),
					LegacyGatewaySettings::FILTER,
					implode( ', ', $gateways )
				)
			)
		);

	}

	/**
	 * Payment methods a snippet is presumably aiming at and cannot reach:
	 * the filter has a listener, the gateway carries v2 `pay4pay_*` keys (so
	 * this store did configure it once), and its fee is switched off.
	 *
	 * @return array<int, string> Gateway titles.
	 */
	private function unreachable_gateways(): array {

		if ( ! has_filter( LegacyGatewaySettings::FILTER ) ) {
			return array();
		}

		$enabled = $this->settings->enabled_methods();
		$unreachable = array();

		foreach ( $this->helpers_wc->payment_gateways() as $gateway ) {

			if ( in_array( (string) $gateway->id, $enabled, true ) ) {
				continue;
			}

			if ( ! $this->has_v2_keys( (string) $gateway->id ) ) {
				continue;
			}

			$unreachable[] = (string) $gateway->get_title();

		}

		return $unreachable;

	}

	/**
	 * Whether a gateway's own settings still carry v2's `pay4pay_*` keys.
	 *
	 * Shared with `Migration`, via `Migration::get_v2_settings()` (#174):
	 * that is the one place that already knows the standard
	 * `woocommerce_{id}_settings` option name, the fallback name some
	 * 3rd-party gateways use, and the Eurobank id alias — reimplementing
	 * only the first here is what let this notice silently miss the other
	 * two.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return bool
	 */
	private function has_v2_keys( string $gateway_id ): bool {
		return array() !== Migration::get_v2_settings( $gateway_id );
	}

}

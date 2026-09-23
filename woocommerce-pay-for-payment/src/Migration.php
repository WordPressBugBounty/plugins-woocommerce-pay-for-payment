<?php

namespace Pay4Payment;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Fees\LegacyGatewaySettings;
use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;

/**
 * One-way, idempotent migration of v2 settings to the v3 storage schema.
 *
 * v2 stored everything as `pay4pay_*` keys inside each gateway's
 * `woocommerce_{gateway_id}_settings` option. v3 stores `pay4pay_general`
 * (enabled methods) and `pay4pay_payment_method_{gateway_id}`.
 *
 * The migration is non-destructive: v2 keys are left in place so a
 * downgrade back to v2 keeps working.
 */
class Migration implements ComponentInterface {

	/**
	 * Settings (storage layer).
	 * @var Settings
	 */
	private $settings;

	/**
	 * WooCommerce helpers (the WC-runtime adapter).
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Constructor.
	 * @param Settings $settings Settings.
	 * @param WooCommerce $helpers_wc WooCommerce helpers.
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
		// Plugin updates do not fire the activation hook, so check on init.
		add_action( 'init', array( $this, 'maybe_migrate' ), 20 );
	}

	/**
	 * Run the migration once per plugin version.
	 *
	 * The version is stamped only when the migration actually got to look at
	 * the store's gateways. The plugin boots even with WooCommerce inactive
	 * (`pay4payment_check_wc_version()` passes when `WC_VERSION` is
	 * undefined), and stamping on such a request left a v2 store that was
	 * updated during maintenance with no fees and an empty settings screen
	 * for good — the migration never ran again (#142).
	 *
	 * @return void
	 */
	public function maybe_migrate() {

		if ( get_option( Settings::OPTION_VERSION ) === PAY4PAYMENT_VERSION ) {
			return;
		}

		$this->normalize_general_shape();

		if ( ! $this->migrate() ) {
			return;
		}

		update_option( Settings::OPTION_VERSION, PAY4PAYMENT_VERSION );

	}

	/**
	 * Rewrite a general row still in the pre-#236 shape, once.
	 *
	 * `Settings::upgrade_general_shape()` already reads such a row correctly,
	 * so this changes no behaviour — what it changes is how long that has to
	 * stay true. Without it the old key survives until somebody saves the
	 * settings screen, which a store configured once and left alone never
	 * does, and the read-time translation would have to be carried for good.
	 *
	 * Deliberately outside {@see migrate()}: that bails when WooCommerce is
	 * not loaded, and this row has nothing to do with gateways. It runs on
	 * the same version-bump trigger, which is what makes it a one-time cost
	 * rather than a per-request one.
	 *
	 * @return void
	 */
	private function normalize_general_shape(): void {

		$general = get_option( Settings::OPTION_GENERAL );

		if ( ! is_array( $general ) || ! array_key_exists( Settings::LEGACY_OFF_CHECKOUT_FEE_MODE, $general ) ) {
			return;
		}

		$upgraded = Settings::upgrade_general_shape( $general );
		foreach ( Settings::LEGACY_GENERAL_KEYS as $legacy_key ) {
			unset( $upgraded[ $legacy_key ] );
		}

		update_option( Settings::OPTION_GENERAL, $upgraded );
	}

	/**
	 * Migrate v2 settings of all installed gateways to the v3 schema.
	 *
	 * A gateway that already has a `pay4pay_payment_method_{id}` option is
	 * skipped whole: v3 owns its configuration from then on, so neither its
	 * settings nor its place in the enabled-methods list is touched again.
	 * Re-running is therefore safe in both directions — it never overwrites a
	 * v3 configuration, and never resurrects a fee the merchant switched off
	 * (#141; the v2 keys stay in place by design, so "had a v2 charge" is
	 * true forever and cannot be the trigger).
	 *
	 * A gateway that is NOT registered on this request is left unmarked, so a
	 * later run still picks it up — that is what eventually migrates a
	 * gateway whose own plugin was inactive at update time.
	 *
	 * @return bool Whether the store's gateways could be examined at all.
	 */
	public function migrate(): bool {

		// The WC-runtime adapter already guards `payment_gateways` against
		// WooCommerce not being loaded / its magic-`__get()`-no-`__isset()`
		// quirk (see Helpers\WooCommerce); an empty array here means
		// either is true, and there is nothing to migrate.
		$gateways = $this->helpers_wc->payment_gateways();
		if ( ! $gateways ) {
			return false;
		}

		$general = get_option( Settings::OPTION_GENERAL, Settings::DEFAULTS_GENERAL );
		if ( ! is_array( $general ) ) {
			$general = Settings::DEFAULTS_GENERAL;
		}
		$methods = isset( $general['methods'] ) && is_array( $general['methods'] ) ? $general['methods'] : array();
		$methods_changed = false;

		// What this run carries over, for the notice that tells the merchant
		// (#186). Separate from $methods_changed: a gateway can be migrated
		// into an enabled-methods list that already names it.
		$migrated = array();

		// What a store that never touched the fee fields would migrate to —
		// v2's own defaults, run through the same mapping. The yardstick for
		// "did the merchant configure anything here?" below.
		$untouched = $this->map_settings( LegacyGatewaySettings::default_v2_settings() );

		foreach ( $gateways as $gateway ) {

			$v2 = self::get_v2_settings( $gateway->id );
			if ( empty( $v2 ) ) {
				continue;
			}

			// Absent keys take the v2 default, from the one place that knows
			// what those are (#161), before anything reads them — the skip
			// check below and `map_settings()` alike. Union, not a per-key
			// `??`, because that is the operator v2 itself applied its
			// defaults with (`Pay4Payment::get_current_gateway_settings()`:
			// `$settings = $current_gateway->settings + $defaults`), so a key
			// stored as null stays null here exactly as it did there.
			$v2 += LegacyGatewaySettings::default_v2_settings();

			$mapped = $this->map_settings( $v2 );

			// Skip only a gateway the merchant never configured — one whose
			// `pay4pay_*` keys are still, field for field, what v2 would have
			// given a store that left the fee fields alone. v2's settings tab
			// wrote those keys for any gateway whose settings page was ever
			// saved, so without this the migration would fill the v3 screen
			// with methods nobody ever set a fee on.
			//
			// A configuration that *charges* nothing but carries a title, caps,
			// tax handling or a disable rule IS migrated (#153, decided
			// 2026-08-27). v2 charged nothing and v3 charges nothing either — a
			// tier with no charges selects nothing — but the merchant's
			// preferences survive, so putting the amount back later is one field
			// rather than a rebuild from v3's defaults.
			//
			// One field is normalised before the comparison: v2 rendered the Item
			// Title input pre-filled with the gateway's own title
			// (`$form_fields['pay4pay_item_title']['default'] = $gateway->title`
			// in `class-pay4pay-admin.php`), and WooCommerce posts a rendered
			// field back on *any* save of that gateway's settings page. So a
			// gateway whose fee section was never opened still stores its own
			// title — which means the same as storing none, since the engine
			// falls back to the gateway title either way. Counted as configured
			// it would migrate practically every gateway in a real store.
			// Normalised for the decision only: `$mapped`, which is what gets
			// stored, keeps whatever v2 had.
			//
			// The filter exception is #144's: a store whose amounts live entirely
			// in a `..._get_current_gateway_settings()` snippet stores zeroes and
			// looks untouched here, and the fee path checks the enabled-methods
			// list before the filter gets a say — so skipping would drop exactly
			// the gateway that snippet charges for.
			$considered = $v2;
			$gateway_title = Cast::to_string( $gateway->title );
			if ( '' !== $gateway_title && $gateway_title === Cast::to_string( $v2['pay4pay_item_title'] ) ) {
				$considered['pay4pay_item_title'] = '';
			}

			if ( $this->map_settings( $considered ) == $untouched && ! has_filter( LegacyGatewaySettings::FILTER ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				continue;
			}

			// Already migrated (or configured in v3): hands off, both the
			// stored settings and the enabled-methods list.
			if ( false !== get_option( Settings::OPTION_PREFIX_METHOD . $gateway->id ) ) {
				continue;
			}

			$this->settings->update_method_settings( $gateway->id, $mapped );

			// The title as v2 stored it, not `get_title()`: this runs on
			// `init`, and the raw property is what the skip decision above
			// already reads. A gateway with no title at all is named by its id
			// — the notice must stay nameable either way.
			$migrated[ (string) $gateway->id ] = '' !== $gateway_title ? $gateway_title : (string) $gateway->id;

			if ( ! in_array( $gateway->id, $methods, true ) ) {
				$methods[] = $gateway->id;
				$methods_changed = true;
			}

		}

		if ( $methods_changed ) {
			$general['methods'] = $methods;
			$this->settings->update_general( $general );
		}

		if ( $migrated ) {
			$this->record_migrated( $migrated );
		}

		return true;

	}

	/**
	 * Hand the migrated methods to `Admin\UpgradeNotice`.
	 *
	 * Merged into whatever is already waiting rather than replacing it: a
	 * gateway whose own plugin was inactive at update time is migrated by a
	 * later run, and if the merchant has not dismissed the notice yet, that
	 * run must not erase the names from the first one. A dismissal deletes the
	 * row, so a migration after it starts a fresh notice naming only what that
	 * run carried over.
	 *
	 * Not autoloaded: the row exists on a v2 upgrade only, and only until the
	 * merchant clicks it away.
	 *
	 * @param array<string, string> $migrated Gateway id => title.
	 * @return void
	 */
	private function record_migrated( array $migrated ): void {

		$pending = get_option( Settings::OPTION_MIGRATED, array() );

		update_option(
			Settings::OPTION_MIGRATED,
			array_merge( is_array( $pending ) ? $pending : array(), $migrated ),
			false
		);

	}

	/**
	 * Read a gateway's v2 settings.
	 *
	 * Returns the gateway settings only when they contain at least one
	 * `pay4pay_*` key, otherwise an empty array.
	 *
	 * Public and static: this is also the shared lookup behind
	 * `Admin\LegacyFilterNotice::has_v2_keys()` (#174) — that notice was
	 * re-implementing only the standard option-name half of this and missing
	 * both the fallback option name and the Eurobank id alias, so it silently
	 * missed exactly the gateways it was meant to name. One lookup, called
	 * from both, so the two can no longer drift apart.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return array<int|string, mixed>
	 */
	public static function get_v2_settings( string $gateway_id ): array {

		// v2 parity: Eurobank gateway stores its settings under a different id.
		if ( 'wc_eurobank_gateway' === $gateway_id ) {
			$gateway_id = 'eurobank_gateway';
		}

		// Default WooCommerce gateways use this option name format.
		$options = get_option( 'woocommerce_' . $gateway_id . '_settings' );

		// Fallback option name format used by some 3rd-party gateways.
		if ( false === $options ) {
			$options = get_option( $gateway_id . '_settings' );
		}

		if ( ! is_array( $options ) ) {
			return array();
		}

		foreach ( array_keys( $options ) as $key ) {
			if ( str_starts_with( (string) $key, 'pay4pay_' ) ) {
				return $options;
			}
		}

		return array();

	}

	/**
	 * Map v2 `pay4pay_*` keys to the v3 payment method schema.
	 *
	 * The key names are shared with `Fees\LegacyGatewaySettings`, which maps
	 * the same fourteen in both directions at runtime for the v2 settings
	 * filter — deliberately a separate mapping, because every policy around
	 * the names differs (one-way and once here, round-trip per calculation
	 * there; complete-from-defaults here, keep-current-value there; cast
	 * here, uncast there for multi-currency parity).
	 *
	 * What the two do share is the *defaults*: `migrate()` completes the array
	 * with `LegacyGatewaySettings::default_v2_settings()` before calling this,
	 * so the migrated configuration calculates the same fees as v2 did and the
	 * fallbacks cannot drift from `Settings::DEFAULTS_METHODS` (#161). Every
	 * key is therefore present here, and read without a `??` — the same way
	 * `to_v2_shape()` reads its own completed arrays.
	 *
	 * @param array<int|string, mixed> $v2 Gateway settings, already completed with `LegacyGatewaySettings::default_v2_settings()` by the caller.
	 * @return array<string, mixed> Canonical method array.
	 */
	private function map_settings( array $v2 ): array {

		$include = array();
		if ( 'yes' === $v2['pay4pay_enable_extra_fees'] ) {
			$include[] = 'fees';
		}
		if ( 'yes' === $v2['pay4pay_include_coupons'] ) {
			$include[] = 'coupons';
		}
		if ( 'yes' === $v2['pay4pay_include_shipping'] ) {
			$include[] = 'shipping';
		}
		if ( 'yes' === $v2['pay4pay_include_cart_taxes'] ) {
			$include[] = 'taxes';
		}

		$disable_on = array();
		if ( 'yes' === $v2['pay4pay_disable_on_free_shipping'] ) {
			$disable_on[] = 'free_shipping';
		}
		if ( 'yes' === $v2['pay4pay_disable_on_zero_shipping'] ) {
			$disable_on[] = 'zero_shipping';
		}

		return array(
			'extra_charge' => array(
				array(
					'item_title' => Cast::to_string( $v2['pay4pay_item_title'] ),
					'charges_fixed' => Cast::to_float( $v2['pay4pay_charges_fixed'] ),
					'charges_percentage_value' => Cast::to_float( $v2['pay4pay_charges_percentage'] ),
					'charges_percentage_minimum' => Cast::to_float( $v2['pay4pay_charges_minimum'] ),
					'charges_percentage_maximum' => Cast::to_float( $v2['pay4pay_charges_maximum'] ),
					'charges_percentage_include' => $include,
				),
			),
			'taxes' => 'yes' === $v2['pay4pay_taxes'],
			'includes_taxes' => 'yes' === $v2['pay4pay_includes_taxes'],
			'tax_class' => Cast::to_string( $v2['pay4pay_tax_class'] ),
			'disable_on' => $disable_on,
		);

	}

}

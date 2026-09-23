<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Helpers\PickerOptions;
use Pay4Payment\Helpers\WooCommerce;
use Pay4Payment\Interfaces\ComponentInterface;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * The settings REST route — a thin adapter at the wire seam.
 *
 * Shape knowledge lives in SettingsSerializer (canonical↔wire, merge
 * invariant) and SettingsValidator (trust boundary); this class only wires
 * permission → serialize → validate → store.
 */
class RestApi implements ComponentInterface {

	/**
	 * The plugin's REST namespace — the one copy every route registration
	 * (settings, condition options, order-pay preview) reads.
	 */
	public const ROUTE_NAMESPACE = 'pay4payment/v1';

	/**
	 * Gateway ids WooCommerce Payments registers as inert placeholders for a
	 * not-yet-connected gateway (e.g. `pre_install_woocommerce_payments`) —
	 * never a real payment method, so the gateway list omits them (#187).
	 */
	private const PRE_INSTALL_GATEWAY_PREFIX = 'pre_install_';

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * WooCommerce helpers (the WC-runtime adapter)
	 * @var WooCommerce
	 */
	private $helpers_wc;

	/**
	 * Canonical↔wire serializer
	 * @var SettingsSerializer
	 */
	private $serializer;

	/**
	 * The picker option builders (the tax-class select's options)
	 * @var PickerOptions
	 */
	private $pickers;

	/**
	 * RestApi constructor.
	 * @param Settings $settings
	 * @param WooCommerce $helpers_wc
	 * @param SettingsSerializer $serializer
	 * @param PickerOptions $pickers
	 */
	public function __construct(Settings $settings, WooCommerce $helpers_wc, SettingsSerializer $serializer, PickerOptions $pickers) {
		$this->settings = $settings;
		$this->helpers_wc = $helpers_wc;
		$this->serializer = $serializer;
		$this->pickers = $pickers;
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register REST API routes.
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(self::ROUTE_NAMESPACE, '/settings', [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'get_settings'],
				'permission_callback' => [$this, 'check_permission'],
			],
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => [$this, 'update_settings'],
				'permission_callback' => [$this, 'check_permission'],
			],
		]);
	}

	/**
	 * Check if user has permission to access settings.
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can('manage_woocommerce');
	}

	/**
	 * Get settings.
	 *
	 * The wire payload mirrors the canonical schema per gateway (nested
	 * `extra_charge` tiers plus the method-level fields once) inside the
	 * `{methods, paymentMethods}` envelope, and carries read-only store data
	 * (tax classes, whether taxes are enabled, the gateway list) alongside it.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$payload = $this->settings_payload();

		// Read-only store data the settings app renders with, not settings —
		// hence assembled here rather than in the serializer, which owns the
		// canonical↔wire mapping and nothing else. Sending it with the
		// settings is what lets the tax-class select speak canonical values
		// by construction, and drops REST round-trips from page load (#154,
		// #187): a blocked/erroring core route can no longer silently hide
		// the tax fields or the gateway list.
		$payload['taxClasses'] = $this->pickers->tax_class_options();
		$payload['calcTaxes'] = $this->helpers_wc->taxes_usable();
		$payload['paymentGateways'] = $this->gateway_options();

		return new WP_REST_Response($payload);
	}

	/**
	 * Update settings.
	 *
	 * Each gateway's payload is merged over its stored settings by the
	 * serializer first (title defaults, enum sanitization, merge invariant),
	 * then the merged canonical result — the state that would be stored — is
	 * validated. Nothing is saved unless every gateway validates.
	 *
	 * A successful save answers with the persisted settings in the same shape
	 * GET returns them (see `settings_payload()`), so the settings app takes
	 * its post-save state from the store rather than predicting it.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings(WP_REST_Request $request) {
		$params = $request->get_params();

		if (!isset($params['methods']) || !isset($params['paymentMethods'])) {
			return new WP_Error('invalid_params', __('Invalid parameters', 'woocommerce-pay-for-payment'), ['status' => 400]);
		}

		$gateways = $this->helpers_wc->payment_gateways();

		// The Pro multi-tier editor sends its tier lists as complete and
		// ordered (reorders/removals included) and flags that here; the
		// free UI never sends the flag and keeps the merge semantics.
		$replace_tiers = !empty($params['replaceTiers']);

		$canonical = [];
		foreach ((array) $params['paymentMethods'] as $method_id => $incoming) {
			// Only a registered gateway's settings are written: the key names
			// the option (`pay4pay_payment_method_{id}`), so an unregistered
			// one is ignored rather than stored under whatever it says. What
			// the store already holds for it is left untouched – a gateway
			// whose plugin is deactivated for the moment keeps its fee.
			if (!is_array($incoming) || !isset($gateways[$method_id])) {
				continue;
			}

			$canonical[$method_id] = $this->serializer->from_wire_method(
				$incoming,
				$this->stored_method($method_id),
				$gateways[$method_id]->get_title(),
				$replace_tiers
			);
		}

		// Store-level keys merge over the stored general array the same way
		// (merge invariant), and validate together with the methods. Over
		// the row as stored, like the methods – not the defaults-merged,
		// filtered read (`get_general()`), which would persist a value a
		// `pay4pay_settings_general` filter forces at runtime.
		$general = $this->serializer->from_wire_general($params, $this->settings->stored_general());

		$validation_result = SettingsValidator::validate([
			'general' => $general,
			'payment_methods' => $canonical,
		]);

		if (!$validation_result['is_valid']) {
			return new WP_Error(
				'validation_failed',
				__('Validation failed. Please check your input and try again.', 'woocommerce-pay-for-payment'),
				[
					'status' => 400,
					'errors' => $validation_result['errors'],
				]
			);
		}

		$this->settings->update_general($general);

		foreach ($canonical as $method_id => $method_settings) {
			$this->settings->update_method_settings($method_id, $method_settings);
		}

		// The answer is the stored state read back — not the payload that
		// came in: titles healed, enums sanitized, tiers merged, every known
		// gateway present. The settings app adopts it verbatim, which is what
		// lets the healing rules live here alone instead of being predicted
		// client-side too (#193).
		return new WP_REST_Response($this->settings_payload());
	}

	/**
	 * The stored settings as the wire sees them — the one read path behind
	 * both directions of the route: GET answers with it, and POST answers
	 * with it again after storing, so a save cannot report a shape a reload
	 * would contradict.
	 *
	 * Settings only. The read-only store data GET adds on top (tax classes,
	 * the gateway list, whether taxes are on) is not part of what a save
	 * changes, so the echo leaves it out.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_payload(): array {
		$methods = $this->settings->enabled_methods();

		// Every known gateway's stored settings ride along — not only the
		// fee-enabled ones — so re-enabling a method edits its stored tiers
		// instead of operating blind over them (and the Pro replace-tiers
		// save cannot silently drop what the UI never saw).
		$ids = array_unique(array_merge($methods, $this->helpers_wc->gateway_ids()));

		$per_method = [];
		foreach ($ids as $method_id) {
			$per_method[$method_id] = $this->stored_method($method_id);
		}

		return $this->serializer->to_wire($methods, $per_method, $this->settings->get_general());
	}

	/**
	 * Read a gateway's stored option array (raw, unknown keys included).
	 *
	 * @param string $method_id Gateway id.
	 * @return array<int|string, mixed>
	 */
	private function stored_method($method_id): array {
		$stored = get_option(Settings::OPTION_PREFIX_METHOD . $method_id, []);
		return is_array($stored) ? $stored : [];
	}

	/**
	 * The registered payment gateways as the settings screen renders them:
	 * id, display title and WooCommerce's own enabled/disabled state —
	 * `paymentMethods` (the canonical fee settings, keyed the same way)
	 * carries none of those, they are WC gateway-registry facts, not fee
	 * settings, so they ride as their own list rather than blur into it.
	 * WooCommerce Payments' not-yet-connected placeholder entries are
	 * dropped; nothing else in the registry is filtered.
	 *
	 * Replaces the settings app's former `GET /wc/v3/payment_gateways`
	 * request (#187); its client-side `pre_install_` filtering moves here.
	 *
	 * @return array<int, array{id: string, title: string, enabled: bool}>
	 */
	private function gateway_options(): array {
		$options = [];
		foreach ($this->helpers_wc->payment_gateways() as $id => $gateway) {
			$id = (string) $id;
			if (str_starts_with($id, self::PRE_INSTALL_GATEWAY_PREFIX)) {
				continue;
			}

			$options[] = [
				'id' => $id,
				'title' => (string) $gateway->get_title(),
				'enabled' => 'yes' === $gateway->enabled,
			];
		}

		return $options;
	}
}

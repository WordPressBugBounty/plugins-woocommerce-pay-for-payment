<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Helpers\Cast;
use Pay4Payment\Interfaces\ComponentInterface;

/**
 * Class Settings
 *
 * Option storage plus the WooCommerce settings tab that hosts the React
 * settings app (rendered into #wc-pay4payment-settings, saved via the
 * pay4payment/v1 REST API).
 *
 * It also declares the NAMES of the rows its consumers own — the migration
 * marker, the plan-review pair, and the two options whose components live in
 * `src/Pro/` — so that `Uninstall`, which is shared code and may not name a
 * Pro class, can delete every row the plugin writes. Names only: removing
 * them is `Uninstall`'s job, not the storage layer's (#164).
 */
class Settings implements ComponentInterface {

	const TAB = 'pay4payment';

	/**
	 * The Freemius screens this tab can show in place of the fee settings,
	 * as `&section=` values (#230).
	 *
	 * WooCommerce reads `section` on every settings tab but only renders
	 * section links for tabs that register them; this one registers none, so
	 * the query var is free — it survives a page load, gives each screen a
	 * URL the merchant can bookmark and the back button can return to, and
	 * needs no client-side router.
	 *
	 * The last two are one screen: the SDK's pricing page comes up on the free
	 * trial instead of the purchase when the request carries `trial=true`, so
	 * that arg rides along on the trial section's URL. They are still two
	 * sections rather than one, because the header has two tabs to tell apart
	 * and the section is what says which one you are on.
	 */
	const SECTION_ACCOUNT = 'account';

	const SECTION_CONNECT = 'connect';

	const SECTION_PRICING = 'pricing';

	const SECTION_TRIAL = 'trial';

	/**
	 * Set when the migration notice is dismissed and the store has still not
	 * connected, and cleared when the opt-in notice that follows is dismissed
	 * in turn.
	 *
	 * The two notices are deliberately sequential rather than one banner with
	 * three asks in it: a merchant who has just been told their settings moved
	 * is deciding about their settings, and an opt-in question in the same
	 * paragraph is competing with that. `OPTION_MIGRATED` cannot carry this on
	 * its own because dismissing the first notice deletes it, taking the fact
	 * that this store migrated at all with it.
	 */
	const OPTION_OPTIN_ASK = 'pay4payment_optin_ask';

	const OPTION_GENERAL = 'pay4pay_general';

	const OPTION_PREFIX_METHOD = 'pay4pay_payment_method_';

	/**
	 * The fee watchdog's state option. The component that owns it lives in
	 * `src/Pro/` (`Pro\FeeWatchdog::OPTION_STATE` aliases this constant —
	 * Pro→shared is the allowed dependency direction), but uninstall
	 * cleanup is shared code and must not name the Pro class, so the name
	 * itself is defined here.
	 */
	const OPTION_WATCHDOG = 'pay4payment_watchdog';

	/**
	 * The migration's "has run" marker (`Migration` reads and writes it).
	 * Named here so `Uninstall` can delete it without the storage layer
	 * reaching back to its own consumer – the layering half of the reason
	 * the watchdog option is named here too. Leaving it behind made
	 * reinstalling the same version skip the migration with every v3 option
	 * already deleted, i.e. an unconfigured store (#142).
	 */
	const OPTION_VERSION = 'pay4payment_version';

	/**
	 * What the v2→v3 migration carried over, `gateway_id => title`, waiting to
	 * be told to the merchant (`Migration` writes it, `Admin\UpgradeNotice`
	 * reads it and deletes it on dismissal – #186). Absent on a fresh install
	 * and after the notice has been dismissed, which is what makes the notice
	 * one-time. Named here for the same layering reason as the rows around it.
	 */
	const OPTION_MIGRATED = 'pay4payment_migrated';

	/**
	 * The plan-review prompt's two rows (`PlanReview` reads and writes
	 * them). Named here for the same layering reason as the two above:
	 * `Uninstall` must be able to delete every row the plugin writes without
	 * the storage layer reaching back to its own consumers (#149).
	 */
	const OPTION_LAST_PREMIUM = 'pay4payment_last_premium';

	const OPTION_NEEDS_REVIEW = 'pay4payment_needs_review';

	/**
	 * Id of the managed "Payment fee" product. Its component lives in
	 * `src/Pro/` (`Pro\ManagedFeeProduct::OPTION_PRODUCT_ID` aliases this),
	 * so — exactly like the watchdog — the name is defined here for cleanup
	 * to reach without naming the Pro class (#149).
	 */
	const OPTION_FEE_PRODUCT = 'pay4payment_fee_product_id';

	/**
	 * v2 wrote one option per settings field while rendering its tab
	 * (`woocommerce_{gateway}_pay4pay_{field}`, `class-pay4pay-settings-tab.php`),
	 * and one more when the merchant dismissed its footer review ask. v3
	 * reads neither, and both survived a delete until #149.
	 *
	 * The `LIKE` pattern `Uninstall` builds from this is escaped before use:
	 * `_` is a single-character wildcard in `LIKE`, so an unescaped one would
	 * reach far wider than the rows meant here. In particular it must not
	 * reach `woocommerce_{gateway}_settings`, the gateway's own option, which
	 * uninstall spares on purpose — and does not, because the pattern needs a
	 * literal `_pay4pay_` in the option NAME, not merely `pay4pay_*` keys
	 * inside its value.
	 */
	const OPTION_LEGACY_FIELD_INFIX = '_pay4pay_';

	const OPTION_LEGACY_FOOTER_RATED = 'woocommerce_pay4pay_admin_footer_text_rated';

	/**
	 * Allowed values of the `charges_percentage_include` tier field.
	 */
	const PERCENTAGE_INCLUDE_OPTIONS = [ 'fees', 'coupons', 'shipping', 'taxes' ];

	/**
	 * Allowed values of the `disable_on` field.
	 */
	const DISABLE_ON_OPTIONS = [ 'free_shipping', 'zero_shipping' ];

	/**
	 * How the plugin writes a fee onto an order it touches: `fee` = a fee
	 * line (`WC_Order_Item_Fee`, what checkout itself creates); `product` = a
	 * line item on the managed fee product, for ERP/invoicing exports that
	 * skip fee lines.
	 *
	 * The shape of the line, not which orders get one — that is
	 * `off_checkout_fees`. The two were one tri-state option until #236, and
	 * merging them made the ERP case unreachable: a merchant who wanted
	 * product lines on their checkout orders had to switch on off-checkout
	 * charging to get them.
	 *
	 * The product line applies wherever this plugin writes a fee, checkout
	 * orders included — it is one choice, not a choice plus an opt-in.
	 */
	const FEE_LINE_TYPE_FEE = 'fee';
	const FEE_LINE_TYPE_PRODUCT = 'product';
	const FEE_LINE_TYPES = [ self::FEE_LINE_TYPE_FEE, self::FEE_LINE_TYPE_PRODUCT ];

	/**
	 * Keys retired by #236, still met on stores configured before it and
	 * translated once on read ({@see upgrade_general_shape()}). Never written.
	 *
	 * `off_checkout_fee_mode` said both whether to charge off-checkout and
	 * what shape the line took. `product_line_on_checkout` asked separately
	 * whether checkout's own fee line was converted — a second decision on
	 * top of the first, which is one more than the question deserves: a store
	 * that wants product lines wants them on its orders, not on some of them.
	 * Choosing the product line now converts checkout orders by itself.
	 */
	const LEGACY_OFF_CHECKOUT_FEE_MODE = 'off_checkout_fee_mode';

	const LEGACY_PRODUCT_LINE_ON_CHECKOUT = 'product_line_on_checkout';

	/**
	 * Every key #236 retired, for the two places that drop them.
	 */
	const LEGACY_GENERAL_KEYS = [ self::LEGACY_OFF_CHECKOUT_FEE_MODE, self::LEGACY_PRODUCT_LINE_ON_CHECKOUT ];

	const DEFAULTS_GENERAL = [
		'methods' => [],
		// Off-checkout fee enforcement (Pro, store-wide): charge the fee on
		// the flows where cart fees never run — the order-pay page and
		// admin-created orders.
		'off_checkout_fees' => false,
		'fee_line_type' => self::FEE_LINE_TYPE_FEE,
		// Display control (Pro, store-wide): suppress the fee on the cart
		// until the customer has explicitly chosen a payment method.
		'hide_fee_on_cart' => false,
		// Display control (Pro, store-wide): preview each method's fee beside
		// its name at checkout. Per method until #268, and store-wide since:
		// the question it answers — "may a shopper compare methods before
		// choosing?" — is one a store answers once, not once per gateway, and
		// a per-method switch made the screen ask it as many times as the
		// store had methods. Deliberately on, as the per-method default was:
		// comparing is the reason the feature exists, and a Pro store that
		// does not want it now says so once. Inert on free, where nothing
		// renders a preview (`Pro\DisplayControls` is stripped from that
		// build and re-checks the plan anyway). Mirrored by
		// `DEFAULT_GENERAL_SETTINGS.showFeeInDescription` in
		// `utils/generalSettings.ts`.
		'show_fee_in_description' => true,
	];

	/**
	 * The starting point for a fee configured fresh in v3, and the answer to
	 * "what does an absent key mean?" for every consumer that completes a
	 * sparse stored array (CONTEXT.md → Canonical schema lists them).
	 *
	 * Deliberately v2's own runtime defaults (`Pay4Payment::get_default_settings()`
	 * in the 2.2.2 source), so identical inputs produce an identical fee in
	 * both versions and this constant cannot disagree with `Migration`, which
	 * reads absent v2 keys the same way (#147). Three of them were briefly
	 * different in v3 and each changed the money silently: `includes_taxes`
	 * false added tax on top of the entered amount instead of containing it
	 * in it, an empty percentage base computed the percentage on a smaller
	 * base on a taxed store, and `inherit` taxed the fee at the highest rate
	 * in the cart rather than the standard rate. The merchant most likely to
	 * be bitten is the one re-creating by hand a gateway the migration
	 * skipped for having no charges in v2.
	 *
	 * `taxes` stays false — that is v2's default too (`pay4pay_taxes` = 'no'),
	 * so the two tax fields below only take effect once it is switched on.
	 */
	const DEFAULTS_METHODS = [
		'extra_charge' => [
			[
				'item_title' => '',
				'charges_fixed' => 0,
				'charges_percentage_value' => 0,
				'charges_percentage_minimum' => 0,
				'charges_percentage_maximum' => 0,
				// v2's `pay4pay_include_cart_taxes` defaulted to 'yes'; its
				// other three base checkboxes defaulted to 'no'.
				'charges_percentage_include' => [ 'taxes' ],
				'conditions' => [],
			]
		],
		'taxes' => false,
		'includes_taxes' => true,
		// '' is WooCommerce's own name for the standard rate — v2 stored
		// exactly this. Not 'inherit', which is this plugin's extra option.
		'tax_class' => '',
		'disable_on' => [],
		// `show_fee_in_description` lived here until #268 and is store-wide
		// now (`DEFAULTS_GENERAL`). Deliberately not migrated: the default
		// was on, so all but the stores that switched a method's preview off
		// keep exactly what they had, and the key is simply no longer read.
		// A stored row that still carries it is left alone — the serializer
		// passes unknown method keys through, so nothing has to rewrite
		// every row to retire one field.
	];

	/**
	 * Complete a sparse tier against the canonical default tier – the one
	 * answer to "what does an absent tier key mean?" (CONTEXT.md → Canonical
	 * schema). Every reader that completes a stored tier calls this
	 * (`get_tiers()`, the serializer, the v2 settings filter's view), so
	 * the answer cannot drift between them the way two hand-kept copies of
	 * it did (#147, #175). Keys the tier carries win; unknown keys survive.
	 *
	 * @param array<int|string, mixed> $tier Sparse tier.
	 * @return array<int|string, mixed> The tier with every canonical key present.
	 */
	public static function complete_tier(array $tier): array {
		return array_merge(self::DEFAULTS_METHODS['extra_charge'][0], $tier);
	}

	/**
	 * Complete a sparse method array against the canonical method defaults
	 * – the method-level twin of {@see complete_tier()}. The tier list is
	 * taken as it is (the default list only when the key is absent); its
	 * entries are completed by the readers that walk it.
	 *
	 * @param array<int|string, mixed> $method Sparse method settings.
	 * @return array<int|string, mixed> The method array with every canonical key present.
	 */
	public static function complete_method(array $method): array {
		return array_merge(self::DEFAULTS_METHODS, $method);
	}

	/**
	 * Settings, keyed by tab (`general` or a payment method id) — each a
	 * defaults-merged option array.
	 * @var array<string, array<int|string, mixed>>
	 */
	private $settings = [];

	/**
	 * Completed tier lists by method id — the per-request memo
	 * `get_tiers()` needs for the same reason the array above is one: every
	 * fee path asks for a gateway's tiers (the cart calculation, the
	 * per-gateway previews, the order-pay hint once per displayed gateway),
	 * and completing the list against the canonical default tier is work
	 * that repeats the answer, never changes it. Filled lazily, dropped
	 * wherever the settings it is derived from are (re)written.
	 * @var array<string, array<int, array<int|string, mixed>>>
	 */
	private $tiers = [];

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB, array( $this, 'render_settings_root' ) );
	}

	/**
	 * Register the settings tab in WooCommerce settings.
	 *
	 * @param array<string, string> $tabs Settings tabs.
	 * @return array<string, string>
	 */
	public function add_settings_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Pay for Payment', 'woocommerce-pay-for-payment' );
		return $tabs;
	}

	/**
	 * Render the React app root element on the settings tab.
	 *
	 * The mount point lands inside WooCommerce's own `<form id="mainform">`,
	 * whose settings script arms `window.onbeforeunload` on the first `change`
	 * or `input` event from any field on the page — ours included. That flag is
	 * cleared only by submitting WC's form, and this app saves over REST, so
	 * the browser kept warning "Leave site?" long after a successful save (#280).
	 *
	 * `wc-settings-prevent-change-event` is WooCommerce's own opt-out for
	 * exactly this. It is tested with `.has( event.target )`, so the class
	 * belongs on an ANCESTOR of the fields, not on the fields themselves —
	 * putting it here covers every control the app renders, now and later.
	 * The app's own guard (`hooks/useUnsavedChangesWarning.ts`) stays in
	 * charge, and it does disarm on save.
	 *
	 * @return void
	 */
	public function render_settings_root() {
		// The React app brings its own save button.
		$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own global, read by its settings screen.

		echo '<div id="wc-pay4payment-settings" class="wc-settings-prevent-change-event"></div>';
	}

	/**
	 * Returns setting value.
	 * @param string $key Key.
	 * @param string $tab Type of settings to load.
	 * @return mixed
	 */
	public function get($key, $tab = 'general') {

		if (!isset($this->settings[$tab])) {
			$this->load_settings_tabs($tab);
		}

		if (isset($this->settings[$tab][$key])) {
			return $this->settings[$tab][$key];
		}

		return null;

	}

	/**
	 * Ids of the payment methods the fee is enabled for — the `methods` key
	 * of the general settings, reduced to its string entries (gateway ids are
	 * strings; a corrupt value or non-string entries are ignored).
	 *
	 * @return string[]
	 */
	public function enabled_methods(): array {
		$methods = $this->get('methods');
		return is_array($methods) ? Cast::to_string_list($methods) : [];
	}

	/**
	 * Whether the merchant switched a fee on for this gateway — the check
	 * each of the three fee paths (cart, order, per-gateway preview) runs
	 * before it computes anything, spelled once here instead of three times
	 * as a membership test over the list above.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return bool
	 */
	public function is_extra_charge_enabled_for(string $gateway_id): bool {
		return in_array($gateway_id, $this->enabled_methods(), true);
	}

	/**
	 * The whole defaults-merged general (store-level) settings array — the
	 * accessor the wire boundary uses to emit and merge global keys.
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_general(): array {
		if (!isset($this->settings['general'])) {
			$this->load_settings_tabs('general');
		}
		return $this->settings['general'];
	}

	/**
	 * The general (store-level) option row as stored – no defaults merged,
	 * no `pay4pay_settings_general` filter – read in today's shape
	 * ({@see upgrade_general_shape()}). The per-method twin of
	 * `RestApi::stored_method()`: what a save merges the wire over, so
	 * that a value a filter forces at runtime is never written back as if
	 * the merchant had chosen it. Reads keep going through
	 * {@see get_general()}.
	 *
	 * @return array<int|string, mixed>
	 */
	public function stored_general(): array {
		$stored = get_option(self::OPTION_GENERAL, []);
		return self::upgrade_general_shape(is_array($stored) ? $stored : []);
	}

	/**
	 * Whether a value is a known fee line type — the one predicate the
	 * accessor, the serializer and the validator share.
	 *
	 * @phpstan-assert-if-true string $type
	 * @param mixed $type Candidate value.
	 * @return bool
	 */
	public static function is_fee_line_type($type): bool {
		return is_string($type) && in_array($type, self::FEE_LINE_TYPES, true);
	}

	/**
	 * Whether the fee is charged on the flows WooCommerce leaves alone: the
	 * order-pay page and admin-created orders (Pro, store-wide).
	 *
	 * @return bool
	 */
	public function off_checkout_fees(): bool {
		return (bool) $this->get('off_checkout_fees');
	}

	/**
	 * How a fee this plugin writes is shaped (see the FEE_LINE_TYPE_*
	 * constants); anything unrecognised reads as a fee line, the shape
	 * WooCommerce itself uses, so a corrupt option cannot conjure product
	 * lines onto orders.
	 *
	 * @return string
	 */
	public function fee_line_type(): string {
		$type = $this->get('fee_line_type');
		return self::is_fee_line_type($type) ? $type : self::FEE_LINE_TYPE_FEE;
	}

	/**
	 * Whether this store's fees are written as product lines — everywhere the
	 * plugin writes one, which includes converting the fee line checkout
	 * created.
	 *
	 * One question, one answer. It used to be two (the line type, then a
	 * separate opt-in for checkout orders), and the second never had a real
	 * audience: the product line exists so an export that skips fee lines
	 * finds the fee, and an export does not care which orders came from where.
	 *
	 * Deliberately independent of {@see off_checkout_fees()} — that says
	 * *which orders are charged*, this says *what the charge looks like*.
	 * They were one setting until #236.
	 *
	 * @return bool
	 */
	public function writes_product_line(): bool {
		return self::FEE_LINE_TYPE_PRODUCT === $this->fee_line_type();
	}

	/**
	 * Whether the fee is hidden on the cart until the customer has chosen a
	 * payment method (Pro display control, store-wide).
	 *
	 * @return bool
	 */
	public function hide_fee_on_cart(): bool {
		return (bool) $this->get('hide_fee_on_cart');
	}

	/**
	 * Whether every method previews its fee in its gateway description at
	 * checkout (Pro display control, store-wide since #268 — it took a
	 * method id until then).
	 *
	 * @return bool
	 */
	public function show_fee_in_description(): bool {
		return (bool) $this->get('show_fee_in_description');
	}

	/**
	 * Returns the free tier (`extra_charge[0]`) of the given method — the
	 * single PHP accessor for it, and what the free code path charges
	 * (CONTEXT.md → Free tier): entry 0 of `get_tiers()`, so the free tier
	 * and the Pro selection's first candidate are always the same tier.
	 * Falls back to the canonical default tier when nothing is stored.
	 * @param string $method_id Method ID.
	 * @return array<int|string, mixed>
	 */
	public function get_extra_charge($method_id) {
		return $this->get_tiers((string) $method_id)[0] ?? self::DEFAULTS_METHODS['extra_charge'][0];
	}

	/**
	 * Returns the ordered tier list (`extra_charge`) of the given method.
	 * Array order is evaluation order for the tier selection (CONTEXT.md →
	 * Tier selection); the free code path reads entry 0 only, through
	 * `get_extra_charge()`. An entry that is not an array (corrupt data the
	 * serializer never writes) keeps its slot as the inert default tier —
	 * nothing to charge, so never selected — so positions, and tier 0, stay
	 * what every reader sees; nothing usable yields an empty list.
	 *
	 * Every array entry is completed against the canonical default tier
	 * (`Settings::DEFAULTS_METHODS['extra_charge'][0]`) — the same "what
	 * does an absent key mean?" answer every other sparse-array reader
	 * uses (CONTEXT.md → Canonical schema). A tier stored with only some
	 * keys — a direct option write, e.g. a deployment script or WP-CLI —
	 * must reach `FeeEngine` exactly as complete as one the serializer
	 * wrote, or a key it never mentions (`charges_percentage_include`,
	 * whose absence means "taxes are in the percentage base", #147) reads
	 * as empty instead of defaulted (#175). A read accessor: what gets
	 * stored, and the wire payload the serializer builds from it, are both
	 * untouched by this.
	 *
	 * Memoised per method for the request, like the settings array it is
	 * built from: the completion is pure, so repeating it can only ever
	 * cost.
	 *
	 * @param string $method_id Method ID.
	 * @return array<int, array<int|string, mixed>> Tiers in evaluation order.
	 */
	public function get_tiers(string $method_id): array {
		if (isset($this->tiers[$method_id])) {
			return $this->tiers[$method_id];
		}

		$extra_charge = $this->get('extra_charge', $method_id);
		$tiers = is_array($extra_charge)
			? array_values(array_map(
				static fn($tier) => is_array($tier)
					? self::complete_tier($tier)
					: self::DEFAULTS_METHODS['extra_charge'][0],
				$extra_charge
			))
			: [];

		$this->tiers[$method_id] = $tiers;

		return $tiers;
	}

	/**
	 * Returns the full defaults-merged canonical settings of one payment
	 * method — the single accessor for the whole method shape, so callers
	 * don't re-enumerate the canonical keys.
	 *
	 * @param string $method_id Method ID.
	 * @return array<int|string, mixed>
	 */
	public function get_method_settings(string $method_id): array {
		if (!isset($this->settings[$method_id])) {
			$this->load_settings_tabs($method_id);
		}
		return $this->settings[$method_id];
	}

	/**
	 * Persist the general settings.
	 *
	 * @param array<int|string, mixed> $general General settings (canonical schema).
	 * @return void
	 */
	public function update_general(array $general) {
		update_option(self::OPTION_GENERAL, $general);
		$this->settings['general'] = array_merge(self::DEFAULTS_GENERAL, $general);
	}

	/**
	 * Persist the settings of one payment method.
	 *
	 * Owns the option naming and keeps the in-request cache fresh.
	 *
	 * @param string $method_id Method ID.
	 * @param array<int|string, mixed> $data Method settings (canonical schema).
	 * @return void
	 */
	public function update_method_settings(string $method_id, array $data) {
		update_option(self::OPTION_PREFIX_METHOD . $method_id, $data);
		$this->settings[$method_id] = self::complete_method($data);
		unset($this->tiers[$method_id]);

		/**
		 * Fires after the settings of a payment method have been saved.
		 *
		 * @since 3.0.0
		 * @param string $method_id Payment method ID.
		 * @param array $data Saved settings.
		 */
		do_action('pay4payment_method_settings_saved', $method_id, $data);
	}

	/**
	 * Read a general array written before #236 in today's shape.
	 *
	 * `off_checkout_fee_mode` answered two questions at once — whether to
	 * charge off-checkout, and how the line is shaped — and is now two keys.
	 * The translation is exact in both directions its old values allowed:
	 * `off` charged nothing, `fee` and `product` both charged, and named the
	 * shape while doing it.
	 *
	 * On read, not only in the migration, because a read can happen before a
	 * migration has had its chance — WooCommerce inactive on this request
	 * (`Migration::migrate()` bails), a row written after the version was
	 * stamped — and the translation is a pure function of what is stored, so
	 * running it every time costs one `array_key_exists()` and cannot be
	 * wrong. `Migration::normalize_general_shape()` is what eventually
	 * rewrites the row so this stops being needed, and a save from the
	 * settings screen drops the old key too
	 * ({@see SettingsSerializer::from_wire_general()}).
	 *
	 * @param array<int|string, mixed> $general Stored general settings.
	 * @return array<int|string, mixed>
	 */
	public static function upgrade_general_shape(array $general): array {
		if (array_key_exists('off_checkout_fees', $general) || !array_key_exists(self::LEGACY_OFF_CHECKOUT_FEE_MODE, $general)) {
			return $general;
		}

		$mode = $general[self::LEGACY_OFF_CHECKOUT_FEE_MODE];

		$general['off_checkout_fees'] = self::FEE_LINE_TYPE_FEE === $mode || self::FEE_LINE_TYPE_PRODUCT === $mode;
		$general['fee_line_type'] = self::FEE_LINE_TYPE_PRODUCT === $mode ? self::FEE_LINE_TYPE_PRODUCT : self::FEE_LINE_TYPE_FEE;

		// The old opt-in is not carried over: on the product line every order
		// is converted now, and a store that had it switched off gets the
		// conversion it did not ask for — deliberate, since the alternative
		// is keeping a second question alive for one legacy combination.
		return $general;
	}

	/**
	 * Loads settings from DB.
	 *
	 * A stored option that is not an array (corrupt or foreign data) counts
	 * as unset, and a filter returning a non-array is ignored — either way
	 * the defaults-merged array is what gets cached.
	 *
	 * @param string $tab Type of settings to load.
	 * @return void
	 */
	private function load_settings_tabs($tab = 'general') {

		if ($tab === 'general') {
			$stored = get_option(self::OPTION_GENERAL, self::DEFAULTS_GENERAL);
			$settings = array_merge(self::DEFAULTS_GENERAL, self::upgrade_general_shape(is_array($stored) ? $stored : []));
		} else {
			$stored = get_option(self::OPTION_PREFIX_METHOD . $tab, self::DEFAULTS_METHODS);
			$settings = self::complete_method(is_array($stored) ? $stored : []);
		}

		/**
         * Allow to filter settings before loading them.
         *
         * @since 3.0.0
         * @param array $values General settings.
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed with pay4pay_; the sniff cannot see through the concatenation.
        $filtered = apply_filters('pay4pay_settings_' . $tab, $settings);

		$this->settings[$tab] = is_array($filtered) ? $filtered : $settings;
		// Tiers are derived from this array, so they are dropped wherever it
		// is written — here and in `update_method_settings()`.
		unset($this->tiers[$tab]);

	}

	/**
	 * URL of the plugin's settings tab — the one construction site for it
	 * (the admin notices link here).
	 *
	 * @return string
	 */
	public static function settings_url( string $section = '' ): string {
		$url = admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB );

		return '' === $section ? $url : $url . '&section=' . rawurlencode( $section );
	}

	/**
	 * The `&section=` this request asked for — '' for the fee settings
	 * themselves.
	 *
	 * Says nothing about whether that section can be shown; that is
	 * `Admin`'s question, because the answer depends on the Freemius state
	 * (`Premium::account_url()` / `optin_url()`), not on the URL.
	 *
	 * @return string
	 */
	public function current_section(): string {
		// Read-only screen routing, like `is_current_tab()` — WooCommerce has
		// already dispatched on this value and nothing is written here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_REQUEST['section'] ) && is_string( $_REQUEST['section'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_REQUEST['section'] ) )
			: '';
	}


	/**
	 * Checks if current tab is the same as settings tab.
	 * @return bool
	 */
	public function is_current_tab(): bool {
		// Read-only tab routing on an admin screen — WooCommerce itself has
		// already dispatched on this value; nothing is written here, so there is
		// no form submission to nonce-verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_REQUEST['tab'] ) && is_string( $_REQUEST['tab'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) : '';

		return self::TAB === $current_tab;
	}

}

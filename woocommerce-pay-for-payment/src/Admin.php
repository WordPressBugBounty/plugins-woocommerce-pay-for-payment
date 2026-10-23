<?php

namespace Pay4Payment;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Interfaces\ComponentInterface;

class Admin implements ComponentInterface {

	/**
	 * Settings
	 * @var Settings
	 */
	private $settings;

	/**
	 * Plugin utils
	 * @var PluginUtils
	 */
	private $utils;

	/**
	 * Assets
	 * @var Assets
	 */
	private $asset;

	/**
	 * Plan review prompt
	 * @var PlanReview
	 */
	private $plan_review;

	/**
	 * The Freemius screens this store has, resolved once per request
	 * ({@see freemius_urls()}); null until first asked.
	 * @var array<string, string>|null
	 */
	private $freemius_urls = null;

	/**
	 * Constructor.
	 * @param Settings $settings Settings.
	 * @param PluginUtils $utils Plugin utils.
	 * @param Assets $asset Assets.
	 * @param PlanReview $plan_review Plan review prompt.
	 */
	public function __construct(Settings $settings, PluginUtils $utils, Assets $asset, PlanReview $plan_review) {
		$this->settings = $settings;
		$this->utils = $utils;
		$this->asset = $asset;
		$this->plan_review = $plan_review;
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {

		add_filter( 'plugin_action_links_' . $this->utils->get_plugin_basename(), [ $this, 'add_action_links' ] );

		// Settings::setup() is called by Plugin::init().
		if ($this->settings->is_current_tab()) {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			// WooCommerce adds its own rating request and version to the footer of every
			// WC settings screen — on the free build, ask for this plugin's review
			// instead; on a premium build leave WooCommerce's line alone.
			add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ], PHP_INT_MAX );
			add_filter( 'update_footer', [ $this, 'update_footer' ], PHP_INT_MAX );

			if ( '' !== $this->freemius_section() ) {
				// `admin_enqueue_scripts` is the last hook that can still
				// enqueue, and the Account screen's load step must run before
				// the render one — see Premium::prepare_account_panel().
				add_action( 'admin_enqueue_scripts', [ $this, 'prepare_freemius_panel' ], 5 );
				// After `</form>`: the panel brings its own forms, which a
				// nested position would break (Premium::render_account_panel()).
				add_action( 'woocommerce_after_settings_' . Settings::TAB, [ $this, 'render_freemius_panel' ] );
				add_filter( 'admin_body_class', [ $this, 'freemius_panel_body_class' ] );
			}
		}
	}

	/**
	 * This tab's URL for each Freemius screen this store actually has, keyed
	 * by its `&section=` (#230) — the one place that decides which of them
	 * exist, so that the tabs offered and the sections that will render can
	 * never disagree. A screen with no entry here has no tab and no section.
	 *
	 * Asked several times per request (the hooks in `setup()`, the render, the
	 * app's own tab URLs), and the answer cannot change within one: the
	 * Freemius state it reads is loaded once by the SDK, and the actions that
	 * do change it redirect. So it is resolved once.
	 *
	 * @return array<string, string> Section => URL, in tab order.
	 */
	private function freemius_urls(): array {
		if ( null === $this->freemius_urls ) {
			$this->freemius_urls = $this->resolve_freemius_urls();
		}

		return $this->freemius_urls;
	}

	/**
	 * Work out that map. Split from the memo above so the capability check
	 * below is the first thing either has to pass.
	 *
	 * @return array<string, string>
	 */
	private function resolve_freemius_urls(): array {
		/*
		 * These are Freemius' own admin pages, and the SDK registers every one
		 * of them for `manage_options` — but it leaves the enforcing to
		 * WordPress' admin-menu gate, which only runs when the page is loaded
		 * through the menu. Rendering them here goes around the menu, onto a
		 * tab WooCommerce opens to `manage_woocommerce` — Shop manager, by
		 * default. Without this check that role would read the licence key and
		 * the connected account's email off the Account screen, and be handed
		 * the store's checkout by the pricing one.
		 *
		 * Refusing here rather than at the render is what makes it one check:
		 * no URL means no tab, no section, and so no panel and no load step.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}

		$urls = [];

		if ( '' !== Premium::account_url() ) {
			$urls[ Settings::SECTION_ACCOUNT ] = Settings::settings_url( Settings::SECTION_ACCOUNT );
		}

		if ( '' !== Premium::optin_url() ) {
			$urls[ Settings::SECTION_CONNECT ] = Settings::settings_url( Settings::SECTION_CONNECT );
		}

		// The campaign tags and the launch coupon ride along on these two
		// exactly as they do on the upgrade button, because the screen behind
		// them is the same pricing screen and reads them from the request.
		if ( Premium::offers_upgrade() ) {
			$urls[ Settings::SECTION_PRICING ] = (string) add_query_arg(
				Premium::upgrade_args( Premium::UTM_MEDIUM_SETTINGS ),
				Settings::settings_url( Settings::SECTION_PRICING )
			);
		}

		if ( Premium::offers_trial() ) {
			// `trial=true` is the SDK's own arg — the one thing that separates
			// the free-trial screen from the purchase one.
			$urls[ Settings::SECTION_TRIAL ] = (string) add_query_arg(
				array_merge( Premium::upgrade_args( Premium::UTM_MEDIUM_SETTINGS ), [ 'trial' => 'true' ] ),
				Settings::settings_url( Settings::SECTION_TRIAL )
			);
		}

		return $urls;
	}

	/**
	 * Which Freemius screen this request is showing in place of the fee
	 * settings, or '' for the settings themselves (#230).
	 *
	 * A section is only "current" when there is a screen behind it, and having
	 * a tab URL is what "there is a screen" means — so a hand-typed or stale
	 * `&section=` (an Account tab on a store that never connected, a
	 * bookmarked Upgrade from before the purchase) falls back to the settings
	 * rather than rendering an empty panel.
	 *
	 * @return string One of the `Settings::SECTION_*` values, or ''.
	 */
	private function freemius_section(): string {
		$section = $this->settings->current_section();

		return isset( $this->freemius_urls()[ $section ] ) ? $section : '';
	}

	/**
	 * Mark the screen so the stylesheet can close up WooCommerce's form,
	 * which in this mode holds only the plugin header. Runs on
	 * `admin_body_class`.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function freemius_panel_body_class( $classes ) {
		// WordPress hands this over with a trailing space; trim first so the
		// result is a single-spaced list either way.
		return trim( (string) $classes ) . ' p4p-fs-section';
	}

	/**
	 * Load step of the Freemius panel. Runs on `admin_enqueue_scripts`.
	 * @return void
	 */
	public function prepare_freemius_panel() {
		if ( Settings::SECTION_ACCOUNT === $this->freemius_section() ) {
			Premium::prepare_account_panel();
		}
	}

	/**
	 * Render step of the Freemius panel. Runs on
	 * `woocommerce_after_settings_{tab}`, outside WooCommerce's form.
	 * @return void
	 */
	public function render_freemius_panel() {
		$section = $this->freemius_section();

		echo '<div class="wc-pay4payment-fs-panel">';

		if ( Settings::SECTION_ACCOUNT === $section ) {
			Premium::render_account_panel();
		} elseif ( Settings::SECTION_CONNECT === $section ) {
			Premium::render_connect_panel();
		} elseif ( Settings::SECTION_PRICING === $section || Settings::SECTION_TRIAL === $section ) {
			// One screen for both tabs — the `trial=true` on the trial tab's
			// URL is what the SDK reads to tell them apart.
			Premium::render_pricing_panel();
		}

		echo '</div>';
	}

	/**
	 * Enqueue the React settings app. Runs on `admin_enqueue_scripts`.
	 * @return void
	 */
	public function enqueue_assets() {

		$this->asset->enqueue( 'build/settings.css', 'pay4payment-settings' );
		// In the footer, not the header: the bundle depends on `wc-settings`,
		// and WooCommerce requires those in the footer. It moved the script
		// there anyway and warned about it in the console on every load of
		// this screen (#285); asking for the footer says the same thing
		// without the warning.
		//
		// `pay4paymentAdmin` is the plan state the settings app reads
		// (`utils/premium.ts`): Pro controls render live, free renders them
		// locked. The PHP gate (Premium::is_premium) stays authoritative. It
		// is printed with `wp_add_inline_script( …, 'before' )`, so it stays
		// immediately ahead of the bundle wherever the bundle ends up.
		$this->asset->enqueue( 'build/settings.js', 'pay4payment-settings', true, [
			'pay4paymentAdmin' => array_merge(
				[
					'isPremium'    => Premium::is_premium(),
					'upgradeUrl'   => Premium::upgrade_url( Premium::UTM_MEDIUM_SETTINGS ),
					'upgradeOffer' => Premium::upgrade_offer(),
					'needsReview'  => $this->plan_review->needs_review(),
					'reviewAckUrl' => $this->plan_review->ack_url(),
					// Freemius contact link for a licensed site, '' otherwise —
					// the settings app falls back to the wp.org forum link.
					'supportUrl'   => Premium::support_url(),
					// The channel's docs and feature-request board, '' where
					// payforpayment.com hosts them (the app's own links).
					'docsUrl'      => Premium::documentation_url(),
					'suggestUrl'   => Premium::feature_request_url(),
					'namesPlan'    => Premium::names_the_plan(),
				],
				$this->freemius_tab_urls()
			),
		] );
	}

	/**
	 * The Freemius screens the settings header offers as tabs, as *this tab's*
	 * own section URLs rather than the SDK's page URLs — the screens render
	 * inline, under the same header (`render_freemius_panel()`, #230). Each is
	 * '' when its screen does not apply, and the header offers only the ones
	 * it is given: Account and the opt-in are mutually exclusive by
	 * construction (a site either has connected or is still being asked), and
	 * Upgrade and Free trial appear beside Account for as long as there is
	 * something left to buy.
	 *
	 * `freemiusSection` says which of them this request is showing, so the app
	 * marks that tab current and stands the fee form down — the panel below is
	 * the content.
	 *
	 * @return array<string, string>
	 */
	private function freemius_tab_urls(): array {
		$urls = $this->freemius_urls();

		return [
			'settingsUrl' => Settings::settings_url(),
			'accountUrl'  => $urls[ Settings::SECTION_ACCOUNT ] ?? '',
			'optinUrl'    => $urls[ Settings::SECTION_CONNECT ] ?? '',
			'pricingUrl'  => $urls[ Settings::SECTION_PRICING ] ?? '',
			'trialUrl'    => $urls[ Settings::SECTION_TRIAL ] ?? '',
			'freemiusSection' => $this->freemius_section(),
		];
	}

	/**
	 * The admin footer of the settings tab: the free build asks for a wp.org
	 * review — its users installed from there (#72, the why lives on
	 * {@see Premium::is_premium_build()}). A premium build leaves WooCommerce's
	 * own footer line untouched: Freemius asks its customers for reviews by
	 * post-purchase email, the WooCommerce.com marketplace's UX guidelines
	 * want no branding or self-promotion in the admin, and a "thank you"
	 * credit was the one branded surface left (decided 2026-09-18).
	 *
	 * @param string $text Footer text.
	 * @return string
	 */
	public function admin_footer_text( $text ) {
		if ( Premium::is_premium_build() ) {
			return $text;
		}

		$plugin_name = '<strong>Pay for Payment for WooCommerce</strong>';

		return sprintf(
			/* translators: 1: plugin name, 2: five-star rating link. */
			__( 'If you like %1$s please leave us a %2$s rating. A huge thanks in advance!', 'woocommerce-pay-for-payment' ),
			$plugin_name,
			// The reviews base URL also lives in assets/js/constants.ts
			// (PLUGIN_LINKS.reviews) — keep the two in step.
			'<a href="https://wordpress.org/support/plugin/woocommerce-pay-for-payment/reviews/?rate=5#new-post" target="_blank" rel="noopener noreferrer">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
		);
	}

	/**
	 * Show the plugin version instead of the WooCommerce one on the settings tab.
	 *
	 * @param string $text Footer version text.
	 * @return string
	 */
	public function update_footer( $text ) {
		/* translators: %s: plugin version. */
		return sprintf( __( 'Version %s', 'woocommerce-pay-for-payment' ), PAY4PAYMENT_VERSION );
	}

	/**
	 * Add action links.
	 * @param array<string, string> $links Plugin action links.
	 * @return array<string, string>
	 */
	public function add_action_links( $links ) {

		$before = [
			'settings' => sprintf( '<a href="%s">%s</a>', esc_url( Settings::settings_url() ), __('Settings', 'woocommerce-pay-for-payment') ),
		];

		return array_merge( $before, $links );
	}

}

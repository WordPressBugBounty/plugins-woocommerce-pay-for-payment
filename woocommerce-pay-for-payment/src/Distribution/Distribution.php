<?php

namespace Pay4Payment\Distribution;

/**
 * The channel this copy of the plugin was distributed through, and the one
 * place shared code learns anything channel-specific from.
 *
 * The plugin ships through more than one channel (docs/agents/distribution.md):
 * Freemius (the wp.org free build and the Pro build, both carrying the
 * Freemius SDK) and the WooCommerce.com marketplace (the Pro build with no
 * SDK at all — the marketplace does not accept one). `Premium` is the only
 * consumer; it asks this interface the licensing questions and where the
 * commercial screens are, and never the SDK.
 *
 * The rule this interface exists for: **no SDK call may reach code that can
 * run in a build without the SDK.** A guarded call — `function_exists(
 * 'pay4payment_fs' ) && pay4payment_fs()->…` — is the trap: it does not
 * error in the SDK-less build, it just quietly answers false, and a paying
 * customer gets the free feature set. So the SDK is asked in exactly one
 * class (`Pay4Payment\Freemius\FreemiusDistribution`), that class lives in
 * a folder the marketplace build strips, and this interface is what
 * everything else sees. `DistributionContractTest` enforces the boundary.
 *
 * Every URL method returns '' when there is no page to send anyone to;
 * callers render nothing rather than a link that leads nowhere.
 */
interface Distribution {

	/**
	 * Whether the premium *code* is running — the build flavor, licence
	 * ignored. False only on the free build Freemius generates.
	 *
	 * @return bool
	 */
	public function is_premium_code(): bool;

	/**
	 * Whether this site may use the premium code: a features-enabled
	 * licence or an active trial on Freemius; always on the marketplace,
	 * where the purchase happened before the download.
	 *
	 * @return bool
	 */
	public function can_use_premium_code(): bool;

	/**
	 * The raw upgrade/checkout URL, or '' when there is nothing to buy
	 * here. `Premium::upgrade_url()` adds the campaign tags.
	 *
	 * @return string
	 */
	public function upgrade_url(): string;

	/**
	 * Where a paying customer gets support, or '' when the channel has no
	 * such page (callers fall back to the public support page).
	 *
	 * @return string
	 */
	public function contact_url(): string;

	/**
	 * The channel's own documentation, or '' when the plugin's site
	 * documents it (callers then link payforpayment.com).
	 *
	 * @return string
	 */
	public function documentation_url(): string;

	/**
	 * Where the channel collects feature requests, or '' when the plugin's
	 * site does (callers then link payforpayment.com).
	 *
	 * @return string
	 */
	public function feature_request_url(): string;

	/**
	 * Whether the settings header names the plan ("Pro") beside the
	 * plugin's name: only where a lesser plan exists to tell it from.
	 *
	 * @return bool
	 */
	public function names_the_plan(): bool;

	/**
	 * The account screen for a connected site, or '' when there is none.
	 *
	 * @return string
	 */
	public function account_url(): string;

	/**
	 * Where a site that has not connected yet goes to opt in, or '' when
	 * the channel never asks.
	 *
	 * @return string
	 */
	public function optin_url(): string;

	/**
	 * The `page=` slug of the channel's own opt-in admin page, or '' when
	 * the channel has no such page. Only
	 * {@see \Pay4Payment\Admin\OptinPageFallback} asks, to recognise a
	 * request for that page once it has stopped being registered.
	 *
	 * @return string
	 */
	public function optin_page_slug(): string;

	/**
	 * The channel's own half of "does this store still have something to
	 * buy": a paid plan exists, the store is not paying, not in a paid
	 * trial, not white-labeled. `Premium::offers_upgrade()` adds the
	 * plugin's half (never while Pro runs).
	 *
	 * @return bool
	 */
	public function offers_upgrade(): bool;

	/**
	 * Whether a free trial is still on offer: the product has a trial plan
	 * this store has not used, and the channel's trial switch is on.
	 *
	 * @return bool
	 */
	public function offers_trial(): bool;

	/**
	 * Load step of the account screen rendered on the settings tab (#230);
	 * a no-op where there is no such screen.
	 *
	 * @return void
	 */
	public function prepare_account_panel(): void;

	/**
	 * Render the account screen inline on the settings tab (#230).
	 *
	 * @return void
	 */
	public function render_account_panel(): void;

	/**
	 * Render the opt-in screen inline on the settings tab (#230).
	 *
	 * @return void
	 */
	public function render_connect_panel(): void;

	/**
	 * Render the pricing screen (and, once a plan is chosen, the checkout)
	 * inline on the settings tab (#230).
	 *
	 * @return void
	 */
	public function render_pricing_panel(): void;
}

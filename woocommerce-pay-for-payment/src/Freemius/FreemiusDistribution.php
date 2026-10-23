<?php

namespace Pay4Payment\Freemius;

use Pay4Payment\Distribution\Distribution;

/**
 * The Freemius channel — the wp.org free build and the Pro build sold
 * through Freemius — and **the only class under `src/` that talks to the
 * SDK** (docs/agents/distribution.md).
 *
 * `src/Freemius/` is the folder the marketplace build strips, the way the
 * free build strips `src/Pro/`: the namespace is the boundary a reader and
 * `DistributionContractTest` can see. Nothing outside this folder may name
 * `pay4payment_fs()`, a `Pay4Payment\Freemius\` class or an SDK symbol —
 * `distribution/freemius.php`, the bootstrap that defines the accessor and
 * constructs this class, is the one file outside `src/` that may.
 *
 * Each method is the raw SDK fact; the policy (campaign tags, "never sell
 * Pro to a store running it", the capability check on the screens) stays
 * in `Premium`, so it applies to every channel.
 */
final class FreemiusDistribution implements Distribution {

	public function is_premium_code(): bool {
		return (bool) pay4payment_fs()->is_premium();
	}

	public function can_use_premium_code(): bool {
		return (bool) pay4payment_fs()->can_use_premium_code();
	}

	/**
	 * '' until a paid plan exists: `has_paid_plan()` reads this plugin's
	 * own `has_paid_plans` flag, and the pricing page crashes without a
	 * plan behind it (#206 has the two ways the URL can still lead nowhere).
	 *
	 * @return string
	 */
	public function upgrade_url(): string {
		$fs = pay4payment_fs();

		if ( ! $fs->has_paid_plan() ) {
			return '';
		}

		return (string) $fs->get_upgrade_url();
	}

	public function contact_url(): string {
		return (string) pay4payment_fs()->contact_url();
	}

	/**
	 * payforpayment.com documents both plans; the settings app links it.
	 *
	 * @return string
	 */
	public function documentation_url(): string {
		return '';
	}

	/**
	 * payforpayment.com/suggest/ takes requests from either plan.
	 *
	 * @return string
	 */
	public function feature_request_url(): string {
		return '';
	}

	/**
	 * Free and Pro share a name on this channel, so the header says which
	 * one is running.
	 *
	 * @return bool
	 */
	public function names_the_plan(): bool {
		return true;
	}

	/**
	 * The SDK registers the Account page only once the site is connected
	 * (`add_submenu_items()` gates it on `is_registered()`), and this
	 * plugin has no menu of its own to hang it off — so the question is
	 * whether the page exists at all, not whether the site is licensed: a
	 * connected free site has an account page too.
	 *
	 * @return string
	 */
	public function account_url(): string {
		$fs = pay4payment_fs();

		if ( ! $fs->is_on() || ! $fs->is_registered() ) {
			return '';
		}

		return (string) $fs->get_account_url();
	}

	/**
	 * Two refusals: Freemius off (no API behind the link), already
	 * connected (nothing left to ask). Declining is deliberately not one —
	 * this is the route, and it stays open for a store that skipped the
	 * prompt ({@see \Pay4Payment\Premium::optin_url()}).
	 *
	 * @return string
	 */
	public function optin_url(): string {
		$fs = pay4payment_fs();

		if ( ! $fs->is_on() || $fs->is_registered() ) {
			return '';
		}

		return (string) $fs->get_activation_url();
	}

	/**
	 * The product slug the SDK builds its activation-mode admin page
	 * around (`admin.php?page=<slug>`) — read from the SDK rather than
	 * repeated, so a slug renamed in the dashboard follows.
	 *
	 * @return string
	 */
	public function optin_page_slug(): string {
		return (string) pay4payment_fs()->get_slug();
	}

	/**
	 * The SDK is on (with it off the link goes nowhere), plus Freemius' own
	 * conditions from the account screen (`templates/account.php`,
	 * `$show_upgrade`): a paid plan exists, the store is not already paying,
	 * it is not in a paid trial, and the site is not white-labeled (an
	 * agency-managed store hides the commercial UI from its client).
	 *
	 * @return bool
	 */
	public function offers_upgrade(): bool {
		$fs = pay4payment_fs();

		return $fs->is_on()
			&& ! $fs->is_whitelabeled()
			&& $fs->has_paid_plan()
			&& ! $fs->is_paying()
			&& ! $fs->is_paid_trial();
	}

	public function offers_trial(): bool {
		$fs = pay4payment_fs();

		return (bool) $fs->apply_filters( 'show_trial', true )
			&& $fs->has_trial_plan()
			&& ! $fs->is_trial_utilized();
	}

	/**
	 * The four screen methods call the SDK's own page callbacks off their
	 * own pages (`_account_page_load()`, `_account_page_render()`,
	 * `_connect_page_render()`, `_pricing_page_render()`). There is no
	 * public API for rendering them elsewhere — the supported tabs
	 * integration wants the product to own an admin menu, and this one
	 * deliberately doesn't — so re-check them after an SDK update. The
	 * constraints on the caller (outside `#mainform`, our own capability
	 * check) are documented on `Premium`.
	 */
	public function prepare_account_panel(): void {
		pay4payment_fs()->_account_page_load();
	}

	public function render_account_panel(): void {
		pay4payment_fs()->_account_page_render();
	}

	public function render_connect_panel(): void {
		pay4payment_fs()->_connect_page_render();
	}

	public function render_pricing_panel(): void {
		pay4payment_fs()->_pricing_page_render();
	}
}

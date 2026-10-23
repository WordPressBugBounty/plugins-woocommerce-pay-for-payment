<?php

namespace Pay4Payment;

use Pay4Payment\Distribution\Distribution;

/**
 * The single free↔Pro gate.
 *
 * The verdict comes from the channel this copy was distributed through
 * ({@see Distribution}, set once by the bootstrap — `distribution/freemius.php`
 * or `distribution/marketplace.php`), and it needs both halves:
 *
 * - `is_premium_code()` — the premium code version is running. Freemius
 *   writes `'is_premium' => false` into the free build it generates from
 *   this codebase, so the free build never unlocks Pro — a licence
 *   activated there points the merchant at the Pro download instead.
 * - `can_use_premium_code()` — the site holds a features-enabled licence
 *   or is in an active trial (Freemius), or bought the plugin before
 *   downloading it (the WooCommerce.com marketplace, where this is always
 *   true).
 *
 * The `pay4payment_is_premium` filter lets tests and development stores
 * flip the verdict either way; it receives the channel's verdict as its
 * default.
 *
 * No other module may ask the channel (or anything else) whether Pro is
 * active — changing the gate must stay a change to this method only. And
 * no module, this one included, may ask the Freemius SDK directly: the
 * only class that does is `Freemius\FreemiusDistribution`, which the
 * marketplace build does not ship (docs/agents/distribution.md).
 * {@see is_premium_build()} is the one sibling predicate: the
 * distribution-channel question (which code flavor shipped), asked by
 * surfaces keyed on where the plugin came from rather than on features.
 */
final class Premium {

	/**
	 * Campaign tagging for every outbound commercial link (#201).
	 *
	 * Free → Pro conversion is only measurable if traffic that started
	 * inside the plugin is distinguishable from everything else, so the
	 * tags are applied at {@see upgrade_url()} rather than at each call
	 * site — a future consumer inherits them by construction.
	 *
	 * `utm_medium` is the one the caller supplies, and the one that earns
	 * its keep: it separates "went looking in the settings" from "clicked
	 * the upgrade notice". One constant per surface, so the set of surfaces
	 * is readable in one place instead of spelled out at each call.
	 */
	public const UTM_SOURCE = 'plugin';
	public const UTM_CAMPAIGN = 'pay4payment';
	public const UTM_MEDIUM_SETTINGS = 'settings';
	public const UTM_MEDIUM_UPGRADE_NOTICE = 'upgrade-notice';

	/**
	 * The channel this copy came through, set by the bootstrap.
	 *
	 * @var Distribution|null
	 */
	private static $distribution = null;

	/**
	 * Tell the gate which channel this copy was distributed through. Called
	 * once, by the bootstrap file the build ships (`distribution/*.php`),
	 * before anything asks.
	 *
	 * @param Distribution $distribution The channel.
	 * @return void
	 */
	public static function set_distribution( Distribution $distribution ): void {
		self::$distribution = $distribution;
	}

	/**
	 * The channel every question below is put to.
	 *
	 * Throws rather than falling back: a build whose bootstrap forgot to set
	 * the channel must fail on the first request, loudly, not quietly
	 * behave as free for every customer — the silent failure the whole
	 * distribution seam exists to rule out.
	 *
	 * @return Distribution
	 * @throws \LogicException When no bootstrap has set the channel.
	 */
	public static function distribution(): Distribution {
		if ( null === self::$distribution ) {
			throw new \LogicException( 'Pay for Payment: no distribution channel set — the bootstrap (distribution/*.php) must call Premium::set_distribution() before anything asks the gate.' );
		}

		return self::$distribution;
	}

	/**
	 * Whether Pro features are active.
	 *
	 * @return bool
	 */
	public static function is_premium(): bool {
		$channel = self::distribution();

		/**
		 * Filters whether Pro features are active.
		 *
		 * @since 3.0.0
		 * @param bool $is_premium Whether Pro features are active — the channel's
		 *                         verdict by default (see the class docblock).
		 */
		return (bool) apply_filters(
			'pay4payment_is_premium',
			$channel->is_premium_code() && $channel->can_use_premium_code()
		);
	}

	/**
	 * Whether the PREMIUM BUILD is running — the code flavor alone,
	 * licence state ignored. Freemius writes `'is_premium' => false` into
	 * the free build it generates, so this separates the distribution
	 * channel ("installed from wordpress.org" vs "bought") from the feature
	 * verdict: an unlicensed Pro install is still a Freemius customer — the
	 * wrong audience for a wp.org review ask (#72). The marketplace build
	 * is a premium build too. Deliberately not filterable: the
	 * `pay4payment_is_premium` filter flips features, never where the
	 * plugin came from.
	 *
	 * @return bool
	 */
	public static function is_premium_build(): bool {
		return self::distribution()->is_premium_code();
	}

	/**
	 * The upgrade/checkout URL, or '' when unavailable.
	 *
	 * Every returned URL carries the campaign tags (#201), the caller naming
	 * the admin surface it is linking from; and while the launch offer runs
	 * the coupon rides along too, so the code the panel shows is already
	 * applied when checkout opens. Both ride the same mechanism: the SDK's
	 * pricing template merges the query args it receives into the config it
	 * hands the pricing app. An empty URL stays empty rather than becoming a
	 * bare query string — and the marketplace build's URL is always empty,
	 * because the marketplace forbids checkout links to the vendor's site.
	 *
	 * Two ways a Freemius URL can still lead nowhere, neither of which this
	 * guard catches — both tracked in #206:
	 *
	 * - `has_paid_plan()` reads the `has_paid_plans` flag from this plugin's
	 *   own SDK config, never the store's plan data, so it is always true
	 *   here. A product whose paid plan the Freemius API doesn't hand out
	 *   still yields a URL, and that page renders blank ("e.pricing is not
	 *   iterable"). Only the dashboard can fix that.
	 * - In Freemius activation mode (a free install that has answered
	 *   neither "Allow & Continue" nor "Skip") the SDK doesn't register the
	 *   pricing page at all, so the link 404s. Returning '' there is
	 *   tempting and wrong on its own: `Admin\UpgradeNotice` gates its Pro
	 *   paragraph on this being non-empty, and that notice fires once per
	 *   migrated store — suppressing the link would silently cost the pitch
	 *   instead. Worth doing only together with a fallback URL that works.
	 *
	 * @param string $medium The admin surface the link is shown on, for
	 *                       `utm_medium` — one of the `UTM_MEDIUM_*`
	 *                       constants. Deliberately required: a new upgrade
	 *                       link that doesn't say where it lives is a hole
	 *                       in the conversion funnel, not a default.
	 * @return string
	 */
	public static function upgrade_url( string $medium ): string {
		$url = self::distribution()->upgrade_url();

		if ( '' === $url ) {
			return '';
		}

		return (string) add_query_arg( self::upgrade_args( $medium ), $url );
	}

	/**
	 * The query args every link into the pricing screen carries — the campaign
	 * tags, and the launch coupon while it runs. {@see upgrade_url()} is this
	 * plus the channel's own URL.
	 *
	 * Split out because the settings tab links to a pricing screen it renders
	 * itself (`&section=pricing`, #230) rather than to the SDK's page, and
	 * that link has to carry the same things: a merchant who reads "50% off
	 * with UPGRADE50" in the panel must not meet full price at checkout for
	 * having used the tab instead of the button.
	 *
	 * @param string $medium The admin surface the link is shown on — see
	 *                       {@see upgrade_url()}.
	 * @return array<string, string>
	 */
	public static function upgrade_args( string $medium ): array {
		$args = [
			'utm_source' => self::UTM_SOURCE,
			'utm_medium' => rawurlencode( $medium ),
			'utm_campaign' => self::UTM_CAMPAIGN,
		];

		$offer = self::upgrade_offer();
		if ( null !== $offer ) {
			$args['coupon'] = rawurlencode( $offer['code'] );
		}

		return $args;
	}

	/**
	 * The channel's contact/support URL for a licensed site, or '' otherwise.
	 *
	 * wp.org's support forum (readme.txt FAQ) is for the free plugin;
	 * commercial support is off-topic there, so a licensed site is routed to
	 * the channel's own desk instead — Freemius' contact form, or
	 * WooCommerce.com's ticket form on the marketplace build. Support is a
	 * plan entitlement, not a code-flavor question — unlike
	 * {@see is_premium_build()} (the review-ask sibling, #72, which
	 * deliberately checks the build alone, to keep an unlicensed Freemius
	 * customer off the wp.org review ask), this checks the full licence
	 * verdict, so an unlicensed Pro build gets no contact link either.
	 * Callers fall back to the public support page when this is empty.
	 *
	 * @return string
	 */
	public static function support_url(): string {
		if ( ! self::is_premium() ) {
			return '';
		}

		return self::distribution()->contact_url();
	}

	/**
	 * The channel's documentation, or '' for the plugin's own site. Not a
	 * plan entitlement: whoever installed this copy reads the docs of the
	 * channel it came from.
	 *
	 * @return string
	 */
	public static function documentation_url(): string {
		return self::distribution()->documentation_url();
	}

	/**
	 * Where the channel collects feature requests, or '' for the plugin's
	 * own site.
	 *
	 * @return string
	 */
	public static function feature_request_url(): string {
		return self::distribution()->feature_request_url();
	}

	/**
	 * Whether the settings header names the plan beside the plugin's name.
	 * The header asks this together with the licence verdict: a plan is
	 * named only when Pro runs and the channel sells more than one.
	 *
	 * @return bool
	 */
	public static function names_the_plan(): bool {
		return self::distribution()->names_the_plan();
	}

	/**
	 * The account screen for a connected site, or '' when there is no such
	 * screen to link to.
	 *
	 * On Freemius the SDK registers that page only once the site is
	 * connected, and this plugin has no admin menu of its own — Freemius'
	 * pages are hidden subpages, linked from the plugins list. So the
	 * settings screen cannot assume the page exists: it asks for a URL and
	 * shows its Account tab only when it gets one, rather than offering a
	 * tab that 404s (#230). The marketplace build has no account screen of
	 * ours at all — WooCommerce.com holds the subscription.
	 *
	 * Deliberately the registration question, not the licence one: a
	 * connected free site has an account page too — that is where it manages
	 * the connection and, if it ever buys, its licence.
	 *
	 * @return string
	 */
	public static function account_url(): string {
		return self::distribution()->account_url();
	}

	/**
	 * Whether this store still has something to buy.
	 *
	 * Two questions, and both have to say yes.
	 *
	 * The first is this plugin's: {@see is_premium()}. While Pro is running —
	 * by licence, by trial, or because the filter says so — the settings
	 * screen makes no upsell anywhere (the Pro rail and the header nudge both
	 * stand down), and a screen that shows a PRO badge must not also be
	 * selling Pro. A store that decides to buy during a trial still has the
	 * Upgrade button inside the account panel.
	 *
	 * The second is the channel's ({@see Distribution::offers_upgrade()}) —
	 * on Freemius, the conditions of the account screen this tab row sits
	 * above (`templates/account.php`, `$show_upgrade`), so the row carries
	 * the tabs that screen would have carried on its own (#230). It is not
	 * redundant with the first — the filter can turn Pro *off* on a paying
	 * store, and that store still has nothing to buy.
	 *
	 * Connecting to Freemius is deliberately NOT one of the conditions, though
	 * it used to be. The reasoning was that an unconnected store should be
	 * asked to opt in first, with {@see optin_url()}'s Connect tab as the door.
	 * That chain has a hole: `optin_url()` returns '' for an anonymous store,
	 * so a store that skipped the opt-in — or was never shown it — got neither
	 * a Connect tab nor an Upgrade one, and had no route to buying anything
	 * from this screen at all. It is a dead end in exactly the place it costs
	 * the most: most of the free base never opts in, and the v2 upgrade path
	 * does not even show the full opt-in screen (see {@see optin_url()}).
	 *
	 * Nothing about buying needs an account first — Freemius' checkout collects
	 * identity itself, and the pricing screen renders for an anonymous store.
	 * The SDK being on (`is_on()`, the first of the channel's conditions in
	 * {@see \Pay4Payment\Freemius\FreemiusDistribution::offers_upgrade()})
	 * replaces the account check: it is the part that actually mattered,
	 * because with the SDK off the link goes nowhere.
	 *
	 * @return bool
	 */
	public static function offers_upgrade(): bool {
		if ( self::is_premium() ) {
			return false;
		}

		return self::distribution()->offers_upgrade();
	}

	/**
	 * Whether a free trial is still on offer: {@see offers_upgrade()} plus the
	 * channel's own conditions — the product has a trial plan, this store
	 * has not used it yet, and the `show_trial` switch its screens honour.
	 *
	 * @return bool
	 */
	public static function offers_trial(): bool {
		return self::offers_upgrade() && self::distribution()->offers_trial();
	}

	/**
	 * Where a store that has not connected to Freemius yet goes to opt in, or
	 * '' when there is nothing to ask for.
	 *
	 * Why this exists at all: a store upgrading from 2.x is never *offered*
	 * the full opt-in screen. WordPress has not fired the activation hook on
	 * updates since 3.1, so the SDK sets `is_plugin_new_install` to false and
	 * falls back to a sticky admin notice that appears on the second page view
	 * and can be missed. With thousands of v2 installs that is the difference
	 * between hearing from that base and not, so {@see \Pay4Payment\Admin\UpgradeNotice}
	 * carries the ask in the one banner those stores are already shown.
	 *
	 * The two refusals (Freemius off, already connected) are the channel's —
	 * {@see \Pay4Payment\Freemius\FreemiusDistribution::optin_url()}. The
	 * marketplace never asks.
	 *
	 * Declining is NOT one of them, though it used to be. This is the ROUTE,
	 * and a store must be able to opt in whenever it decides to — including
	 * one that skipped the prompt, and one that was never shown it at all
	 * (the v2 upgrade path, as the paragraph above describes). Closing the
	 * route permanently on a single "not now" leaves those stores with no way
	 * back, and a Connect tab sitting quietly in a tab row is a door, not a
	 * question: nobody is asked anything by its existence.
	 *
	 * @return string Opt-in URL, or '' when there is nothing to connect to.
	 */
	public static function optin_url(): string {
		return self::distribution()->optin_url();
	}

	/**
	 * The product slug the SDK builds its own admin page around — the `page=`
	 * of `admin.php?page=…` while the store is in activation mode.
	 *
	 * Only {@see \Pay4Payment\Admin\OptinPageFallback} needs it, to recognise
	 * a request for a page that has stopped existing. The answer is the
	 * channel's ({@see Distribution::optin_page_slug()}): the SDK's slug on
	 * Freemius, '' on the marketplace, which has no opt-in page to rescue.
	 *
	 * @return string
	 */
	public static function optin_page_slug(): string {
		return self::distribution()->optin_page_slug();
	}


	/**
	 * Prepare the Account screen for rendering on this plugin's own settings
	 * tab: everything the SDK's Account page does on load — its stylesheet,
	 * a licence sync when one is due, and the handling of the `fs_action`
	 * edits its own forms submit.
	 *
	 * Must run no later than `admin_enqueue_scripts`, and always paired with
	 * {@see render_account_panel()} — rendering without it gives an unstyled
	 * panel whose Edit forms silently do nothing.
	 *
	 * @return void
	 */
	public static function prepare_account_panel(): void {
		self::distribution()->prepare_account_panel();
	}

	/**
	 * Render the Freemius Account screen inline (#230).
	 *
	 * This and {@see render_connect_panel()} are the SDK's own page-render
	 * callbacks, called off their own pages. Two things follow, and both are
	 * the caller's problem:
	 *
	 * - **The markup contains `<form>` elements**, so it must be echoed
	 *   outside WooCommerce's `#mainform` — the HTML parser drops a nested
	 *   form and its buttons would submit the settings screen instead. On
	 *   this tab that means `woocommerce_after_settings_{tab}`, which fires
	 *   after `</form>`.
	 * - **Its own links and forms point back at the SDK's pages**
	 *   (`admin.php?page=…-account`). Editing a name, syncing or changing a
	 *   licence therefore leaves this tab and finishes there. That is a
	 *   deliberate stopping point, not an oversight: rewriting those targets
	 *   means rewriting rendered HTML, and the pages they lead to work.
	 *
	 * Both templates are internal to the SDK — there is no public API for
	 * showing them elsewhere (the one the SDK does support, its tabs
	 * integration, needs the product to own an admin menu, and this one
	 * deliberately doesn't). Re-check them when the SDK is updated. On the
	 * marketplace build there is no screen, and nothing is rendered — the
	 * tab that leads here is never offered ({@see account_url()}).
	 *
	 * @return void
	 */
	public static function render_account_panel(): void {
		self::distribution()->render_account_panel();
	}

	/**
	 * Render the Freemius opt-in screen inline — the same arrangement, and
	 * the same caveats, as {@see render_account_panel()}.
	 *
	 * Needs no `prepare` twin: `templates/connect.php` enqueues its own
	 * assets and inlines its critical CSS.
	 *
	 * @return void
	 */
	public static function render_connect_panel(): void {
		self::distribution()->render_connect_panel();
	}

	/**
	 * Render the Freemius pricing screen inline — and, once a plan is chosen,
	 * the checkout in the same place: the SDK's render callback switches to
	 * its checkout template on `&checkout=true`, which the pricing app appends
	 * to whatever URL it was opened on, so the purchase finishes on this tab
	 * rather than on a page of Freemius' own.
	 *
	 * The same caveats as {@see render_account_panel()}, plus one of its own:
	 * what the pricing app is told comes partly from the request
	 * (`templates/pricing.php` merges `$_GET` into the config it hands the
	 * app), which is how `&trial=true` turns this into the free-trial screen —
	 * and why the tabs that lead here carry the campaign tags and the coupon
	 * ({@see upgrade_args()}). Once a purchase completes the SDK sends the
	 * browser to its own account page to sync the licence.
	 *
	 * @return void
	 */
	public static function render_pricing_panel(): void {
		self::distribution()->render_pricing_panel();
	}

	/**
	 * The launch upgrade offer shown in the free settings panel, or null when
	 * it is not currently running.
	 *
	 * The promo window is a filterable end date (`pay4payment_upgrade_offer_until`,
	 * a `Y-m-d` string; empty disables it). This lets the launch coupon hide
	 * itself once the window closes — without a plugin release — and keeps the
	 * copy (code, discount) server-controlled.
	 *
	 * @return array{code:string,discount:string}|null
	 */
	public static function upgrade_offer(): ?array {
		/**
		 * Filter the launch upgrade-offer end date (`Y-m-d`). Empty disables it.
		 *
		 * @since 3.0.0
		 * @param string $until Last day the offer is shown (store local time).
		 */
		$until = (string) apply_filters( 'pay4payment_upgrade_offer_until', '2026-10-31' );

		if ( '' === $until || current_time( 'Y-m-d' ) > $until ) {
			return null;
		}

		return [
			/**
			 * Filter the launch-offer coupon code.
			 *
			 * @since 3.0.0
			 * @param string $code Coupon code shown in the panel.
			 */
			'code'     => (string) apply_filters( 'pay4payment_upgrade_offer_code', 'UPGRADE50' ),
			/**
			 * Filter the launch-offer discount label.
			 *
			 * @since 3.0.0
			 * @param string $discount Human-readable discount, e.g. "50%".
			 */
			'discount' => (string) apply_filters( 'pay4payment_upgrade_offer_discount', '50%' ),
		];
	}
}

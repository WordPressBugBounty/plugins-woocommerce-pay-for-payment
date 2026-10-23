<?php

namespace Pay4Payment\Distribution;

/**
 * The WooCommerce.com marketplace channel: the Pro build with no Freemius
 * SDK (docs/agents/distribution.md).
 *
 * Every answer is a constant, because the marketplace settled every
 * question before the download: the purchase is done (so the premium code
 * is always usable), the licence and updates are WooCommerce.com's
 * (`woocommerce.com` connects the store itself — there is no account,
 * opt-in or pricing screen of ours to render), and the marketplace forbids
 * upsell and checkout links to the vendor's own site, so no upgrade URL
 * ever leaves here. Support goes through WooCommerce.com's own ticket form,
 * which is where marketplace customers are told to go.
 *
 * `Premium::is_premium()` still runs the verdict through the
 * `pay4payment_is_premium` filter, so a development store can flip the
 * marketplace build to free the same way it flips the Freemius one.
 */
final class MarketplaceDistribution implements Distribution {

	/**
	 * Where a marketplace customer opens a support ticket — WooCommerce.com
	 * routes it to the vendor. Not payforpayment.com: the marketplace wants
	 * its customers supported through its own desk, and the settings app
	 * would otherwise fall back to the public support page.
	 */
	public const SUPPORT_URL = 'https://woocommerce.com/my-account/contact-support/';

	/**
	 * The product's documentation page on WooCommerce.com. Both URLs below
	 * follow the listing slug (`pay-for-payment`, docs/marketplace/listing.md)
	 * and exist only once the product is deployed; check them then.
	 */
	public const DOCUMENTATION_URL = 'https://woocommerce.com/document/pay-for-payment/';

	/**
	 * The product's feature-request board on WooCommerce.com.
	 */
	public const FEATURE_REQUEST_URL = 'https://woocommerce.com/feature-requests/pay-for-payment/';

	public function is_premium_code(): bool {
		return true;
	}

	public function can_use_premium_code(): bool {
		return true;
	}

	public function upgrade_url(): string {
		return '';
	}

	public function contact_url(): string {
		return self::SUPPORT_URL;
	}

	public function documentation_url(): string {
		return self::DOCUMENTATION_URL;
	}

	public function feature_request_url(): string {
		return self::FEATURE_REQUEST_URL;
	}

	/**
	 * The marketplace sells one product, with no free plan beside it, so
	 * "Pro" would name a difference the buyer never sees.
	 *
	 * @return bool
	 */
	public function names_the_plan(): bool {
		return false;
	}

	public function account_url(): string {
		return '';
	}

	public function optin_url(): string {
		return '';
	}

	/**
	 * No opt-in page of ours exists, so there is nothing to rescue: an
	 * empty slug never matches a request (`OptinPageFallback`).
	 *
	 * @return string
	 */
	public function optin_page_slug(): string {
		return '';
	}

	public function offers_upgrade(): bool {
		return false;
	}

	public function offers_trial(): bool {
		return false;
	}

	public function prepare_account_panel(): void {
	}

	public function render_account_panel(): void {
	}

	public function render_connect_panel(): void {
	}

	public function render_pricing_panel(): void {
	}
}

<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Premium;

/**
 * Keeps the opt-in URL from turning into a 403 once the store leaves
 * activation mode.
 *
 * Freemius registers `admin.php?page=<slug>` only while `is_activation_mode()`
 * holds — `override_plugin_menu_with_activation()` is called from
 * `add_menu_action()` behind exactly that check. The moment a store connects
 * or skips, the page stops being registered, and WordPress answers that URL
 * with "Sorry, you are not allowed to access this page."
 * (`user_can_access_admin_page()` in `wp-admin/includes/menu.php`). A merchant
 * meets it coming back to a bookmark, a browser-history entry, or a link from
 * a release that handed out that URL — and the message says they lack a
 * permission, which is not what happened and not something they can act on.
 *
 * **Why this hook and not a fallback menu page.** Registering the same slug
 * ourselves would race the SDK for the requests where it *is* in activation
 * mode: two `add_submenu_page()` calls for one slug, last callback wins, and
 * the one thing this must never do is replace the real opt-in screen.
 * `admin_page_access_denied` cannot collide by construction — it fires only
 * when nothing registered the page. It runs from `menu.php`, after every
 * `admin_menu` callback (the SDK's included, at `WP_FS__LOWEST_PRIORITY`) and
 * before any output, so redirecting from here is both correct and safe.
 *
 * The destination is the settings screen's Connect tab, which renders the same
 * opt-in form ({@see \Pay4Payment\Admin::render_freemius_panel()}). On a store
 * that has since connected there is no Connect tab, and `&section=connect`
 * falls back to the fee settings on its own ({@see \Pay4Payment\Admin} —
 * a section is only current when there is a screen behind it), so this needs
 * no second case: the merchant lands on the plugin either way.
 */
class OptinPageFallback implements ComponentInterface {

	use AdminPostActions;

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_page_access_denied', array( $this, 'rescue_optin_page' ) );
	}

	/**
	 * Send a request for the vanished opt-in page to the Connect tab.
	 * @return void
	 */
	public function rescue_optin_page() {

		if ( ! $this->is_optin_page_request() ) {
			return;
		}

		/*
		 * The SDK's opt-in page asks for `manage_options`, and so does the
		 * Connect tab (`Admin::resolve_freemius_urls()`). Anyone below that
		 * was refused on their own merits rather than by the missing
		 * registration, so WordPress' 403 is the right answer for them and
		 * this stands aside.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->finish_request( Settings::settings_url( Settings::SECTION_CONNECT ) );

	}

	/**
	 * Whether this request asked for the SDK's own opt-in page.
	 *
	 * @return bool
	 */
	private function is_optin_page_request(): bool {
		// Read-only screen routing: which page WordPress was asked for, on a
		// request it has already refused. Nothing is written here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

		return '' !== $page && $page === Premium::optin_page_slug();
	}
}

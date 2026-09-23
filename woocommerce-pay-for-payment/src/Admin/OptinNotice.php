<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Premium;

/**
 * The opt-in ask, shown to a migrated store once the migration notice has been
 * dealt with.
 *
 * It used to be a paragraph inside {@see UpgradeNotice}. That banner was
 * already carrying two things a merchant has to decide about — their settings
 * moved, and Pro exists — and a third ask in the same box made it long enough
 * that the reassurance stopped reading as the point of it. Asking one thing at
 * a time also puts them in the order they matter: settings first, then Pro,
 * then this.
 *
 * Shown only where the question makes sense:
 *
 * - A MIGRATED store. `Settings::OPTION_OPTIN_ASK` is written by
 *   {@see UpgradeNotice::handle_dismiss()}, so it exists only on a store that
 *   saw the migration notice and closed it. A fresh install never gets this
 *   banner — it meets Freemius' own activation screen instead, which is the
 *   standard flow and is not worth duplicating.
 * - A store that has NOT connected. {@see Premium::optin_url()} is empty once
 *   it has, so connecting makes this disappear on its own without needing to
 *   be dismissed.
 *
 * One-time: dismissal deletes the row that makes it render.
 */
class OptinNotice implements ComponentInterface {

	use AdminPostActions;

	/**
	 * Nonce/admin-post action name for the dismissal.
	 */
	const ACTION_DISMISS = 'pay4payment_optin_dismiss';

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the notice, when there is a store to render it to.
	 * @return void
	 */
	public function render_notice() {

		// `manage_options`, not `manage_woocommerce`: the Connect tab the
		// button leads to is refused below that (`Admin`'s tab URLs), so a
		// shop manager would be sent to a screen that is not there.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_option( Settings::OPTION_OPTIN_ASK ) ) {
			return;
		}

		// Asked and answered by connecting: no dismissal needed, and no stale
		// banner on a store that opted in from the settings screen instead.
		if ( '' === Premium::optin_url() ) {
			return;
		}

		// The button goes to the settings screen's Connect tab, never to
		// `optin_url()` itself. The SDK builds that URL from the product's own
		// admin page, and this plugin deliberately has none, so it comes back
		// as a bare `plugins.php`: the button reloaded the page it was on and
		// the banner reappeared.
		//
		// The heading names the product because the notice sits on the Plugins
		// screen right after an update, among everyone else's notices. The
		// name is not translated, so the heading needs no new string.
		$this->render_action_notice(
			'p4p-optin-notice',
			'Pay for Payment: ' . __( 'One more thing.', 'woocommerce-pay-for-payment' ),
			__( 'Sharing basic usage data tells me which gateways and settings people actually use, and it is how you get security and update notices.', 'woocommerce-pay-for-payment' ),
			'',
			Settings::settings_url( Settings::SECTION_CONNECT ),
			__( 'Opt in', 'woocommerce-pay-for-payment' ),
			$this->action_url( self::ACTION_DISMISS ),
			__( 'No thanks', 'woocommerce-pay-for-payment' ),
			'info'
		);

	}

	/**
	 * Clear the ask — it has now been answered, one way or the other.
	 * @return void
	 */
	public function handle_dismiss() {

		$this->authorize_action( self::ACTION_DISMISS );

		delete_option( Settings::OPTION_OPTIN_ASK );

		$this->finish_request();

	}
}

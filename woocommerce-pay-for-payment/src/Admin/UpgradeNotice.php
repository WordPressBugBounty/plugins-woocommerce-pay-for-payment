<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Helpers\Cast;
use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Premium;

/**
 * The first thing a store upgrading from 2.x is told: its fee settings came
 * across, and there is now a Pro version.
 *
 * The opt-in ask used to ride along here as a third paragraph and now follows
 * as its own notice ({@see OptinNotice}), handed over when this one is
 * dismissed. Three asks in one banner made it long enough that the settings
 * reassurance — the part the merchant actually needs — stopped reading as the
 * point of it.
 *
 * PRD story 3 asked for a post-migration summary naming the migrated payment
 * methods. It is merged here with the 3.0 announcement (decided 2026-08-27
 * with #186) rather than shipped as a second banner — one interruption
 * carrying three related facts beats three, and the audience is identical.
 *
 * Shown only to a store the migration actually carried something over for
 * (`Settings::OPTION_MIGRATED`, written by the migration run that did it). A fresh
 * install has no settings to report and nothing to be reassured about, and
 * meets Pro through the settings screen's own upgrade rail instead.
 *
 * The Pro line is the one part that is conditional: it appears only for a
 * merchant who could act on it — no licence, and a checkout to send them to
 * ({@see Premium::upgrade_url()} is empty until the Freemius plans exist, and
 * a dead "upgrade" link is worse than none). Both questions are asked of
 * `Premium`, the single gate; nothing here talks to Freemius.
 *
 * One-time: dismissal deletes the row that makes it render, so it cannot come
 * back — until a later migration run carries another gateway over, which is a
 * new fact and deserves saying.
 */
class UpgradeNotice implements ComponentInterface {

	use AdminPostActions;

	/**
	 * Nonce/admin-post action name for the dismissal.
	 */
	const ACTION_DISMISS = 'pay4payment_upgrade_dismiss';

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the notice while a migration is still unannounced.
	 * @return void
	 */
	public function render_notice() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$methods = $this->migrated_methods();
		if ( ! $methods ) {
			return;
		}

		$this->render_action_notice(
			'p4p-upgrade-notice',
			__( 'Welcome to the new Pay for Payment.', 'woocommerce-pay-for-payment' ),
			sprintf(
				/* translators: %s: comma-separated payment method names. */
				__( 'Your fee settings for %s were carried over automatically, so there is nothing to reconfigure, but you might want to double-check.', 'woocommerce-pay-for-payment' ),
				implode( ', ', $methods )
			),
			'',
			Settings::settings_url(),
			// Deliberately the label the settings link already carries
			// elsewhere: the same words, and no ninth locale-wide string.
			__( 'Go to settings', 'woocommerce-pay-for-payment' ),
			$this->action_url( self::ACTION_DISMISS ),
			__( 'Dismiss this notice', 'woocommerce-pay-for-payment' ),
			'info',
			$this->pro_footnote()
		);

	}

	/**
	 * Clear the migration report — the notice has now been seen.
	 * @return void
	 */
	public function handle_dismiss() {

		$this->authorize_action( self::ACTION_DISMISS );

		delete_option( Settings::OPTION_MIGRATED );

		// Hand the opt-in ask to the notice that follows, now that this one is
		// out of the way. Only when there is something to ask: a store that
		// already connected gets no second banner and no stray option row.
		if ( '' !== Premium::optin_url() ) {
			update_option( Settings::OPTION_OPTIN_ASK, '1', false );
		}

		$this->finish_request();

	}

	/**
	 * The payment methods the migration carried over, by name.
	 *
	 * Titles as they were at migration time (the watchdog's findings are
	 * stored the same way): a gateway whose plugin has since been switched off
	 * is no longer nameable from the store, and its fee settings are exactly
	 * the ones the merchant may be wondering about.
	 *
	 * @return array<int, string>
	 */
	private function migrated_methods(): array {

		$stored = get_option( Settings::OPTION_MIGRATED, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$names = array();

		foreach ( $stored as $gateway_id => $title ) {
			$name = Cast::to_string( $title );
			$name = '' !== $name ? $name : Cast::to_string( $gateway_id );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;

	}

	/**
	 * The closing line about Pro, or '' when this merchant cannot act on it —
	 * a licensed store (nothing to sell them) or a product whose plans are not
	 * live yet, where `Premium::upgrade_url()` is empty and any link would be
	 * dead. The launch offer is a further layer: past its window the line
	 * still stands, only without the discount.
	 *
	 * @return string HTML, escaped here.
	 */
	private function pro_footnote(): string {

		if ( Premium::is_premium() ) {
			return '';
		}

		$url = Premium::upgrade_url( Premium::UTM_MEDIUM_UPGRADE_NOTICE );
		if ( '' === $url ) {
			return '';
		}

		$lines = array(
			__( 'P.S. I’ve built the features people asked for most: fee rules by cart total, country or shipping method, several fee tiers per payment method, and the fee as a product line for invoicing. They live in Pro, and buying it is what pays for the work on the free plugin — thank you for considering it.', 'woocommerce-pay-for-payment' ),
		);

		$offer = Premium::upgrade_offer();
		if ( null !== $offer ) {
			$lines[] = sprintf(
				/* translators: 1: discount, e.g. "50%"; 2: coupon code. */
				__( 'While the launch offer lasts it is %1$s off with the code %2$s.', 'woocommerce-pay-for-payment' ),
				$offer['discount'],
				$offer['code']
			);
		}

		return sprintf(
			'%s <a href="%s">%s</a>',
			esc_html( implode( ' ', $lines ) ),
			esc_url( $url ),
			esc_html__( 'Take a look at Pro', 'woocommerce-pay-for-payment' )
		);

	}

}

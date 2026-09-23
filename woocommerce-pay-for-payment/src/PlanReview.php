<?php

namespace Pay4Payment;

use Pay4Payment\Admin\AdminPostActions;
use Pay4Payment\Admin\Settings;
use Pay4Payment\Interfaces\ComponentInterface;

/**
 * "Welcome back to Pro" review prompt for the Pro/trial → free → Pro cycle.
 *
 * The plugin preserves stored Pro configuration across a lapse and
 * reactivates it on the return to Pro (honoring the merchant's explicit
 * prior choice — the same contract as the "N tiers stored, they stay safe"
 * message). To make sure nothing unexpected reaches checkout after a gap,
 * a store returning to Pro *with preserved Pro-only config* is prompted to
 * review its fee settings — once, cleared by a single acknowledgment.
 *
 * The trigger watches the Premium state across admin loads (behind the
 * `Premium` seam, not Freemius license hooks). A first-ever Pro activation
 * shows nothing: there is nothing preserved to review.
 *
 * This is a general (always-loaded) component, not a Pro one — it must keep
 * tracking the plan state while the store is on free to detect the return.
 */
class PlanReview implements ComponentInterface {

	use AdminPostActions;

	/**
	 * Nonce/admin-post action name for the acknowledgment.
	 */
	const ACTION_REVIEWED = 'pay4payment_review_ack';

	/**
	 * Settings (storage layer).
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Setup.
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_init', array( $this, 'detect' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_REVIEWED, array( $this, 'handle_reviewed' ) );
	}

	/**
	 * Track the Premium state and flag a return-to-Pro that carries
	 * preserved Pro-only config.
	 *
	 * The pure transition rule (false → true with config present) lives in
	 * should_flag(); this method wires it to the stored state and the clock.
	 *
	 * @return void
	 */
	public function detect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$was = get_option( Settings::OPTION_LAST_PREMIUM, '' );
		$now = Premium::is_premium() ? '1' : '0';

		if ( self::should_flag( '' === $was ? null : ( '1' === $was ), '1' === $now, array( $this, 'has_pro_config' ) ) ) {
			update_option( Settings::OPTION_NEEDS_REVIEW, '1', false );
		}

		if ( $was !== $now ) {
			update_option( Settings::OPTION_LAST_PREMIUM, $now );
		}
	}

	/**
	 * The pure "should we flag a review?" decision.
	 *
	 * Only a genuine false → true transition with preserved Pro config
	 * flags. A first-ever run (`$was === null`) records state without
	 * flagging, so activating this feature on an existing Pro store is
	 * silent; a first-ever activation (`$was === false`, but no config yet)
	 * has nothing to review. `$has_config` is evaluated lazily — only on the
	 * transition — so the config probe and its per-method scan never run on
	 * the steady-state path.
	 *
	 * @param bool|null $was Prior Premium state, or null when never recorded.
	 * @param bool $now Current Premium state.
	 * @param callable $has_config Returns whether Pro-only config is stored.
	 * @return bool
	 */
	public static function should_flag( ?bool $was, bool $now, callable $has_config ): bool {
		if ( false !== $was || ! $now ) {
			return false;
		}

		return (bool) $has_config();
	}

	/**
	 * Whether the store holds Pro-only fee configuration worth a review:
	 * the store-wide off-checkout fee mode or cart-fee hiding being on,
	 * more than one fee tier on any method, any tier conditions, or a
	 * method previewing its fee in the gateway description.
	 *
	 * Only *enabled* methods are scanned: tiers stored on a method the store
	 * no longer offers cannot reach a customer, so there is nothing to
	 * review.
	 *
	 * @return bool
	 */
	public function has_pro_config(): bool {
		// Off-checkout charging, or the product line — either is a Pro choice
		// the merchant made about how orders carry the fee (#236).
		if ( $this->settings->off_checkout_fees() || $this->settings->writes_product_line() ) {
			return true;
		}

		if ( $this->settings->hide_fee_on_cart() ) {
			return true;
		}

		// `show_fee_in_description` is deliberately NOT consulted. It is on by
		// default and, since #268, store-wide: every store would answer yes,
		// so the prompt would fire for all of them and the scan below would
		// never be reached. The prompt is for Pro configuration the merchant
		// chose, and a default nobody touched is not that. (Per method it had
		// the same flaw, reached only once every preview was switched off —
		// CONTEXT.md recorded it as accepted; store-wide it would be total,
		// so the check goes instead of the scan.)
		foreach ( $this->settings->enabled_methods() as $method_id ) {
			// The ordered tier list, corrupt entries already tolerated —
			// `Settings::get_tiers()` owns that, so this stops re-deciding
			// what a stored `extra_charge` means.
			$tiers = $this->settings->get_tiers( $method_id );

			if ( count( $tiers ) > 1 ) {
				return true;
			}

			foreach ( $tiers as $tier ) {
				if ( ! empty( $tier['conditions'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether the return-to-Pro review is currently pending — the flag the
	 * settings app reads to show its in-page marker.
	 *
	 * @return bool
	 */
	public function needs_review(): bool {
		return Premium::is_premium() && (bool) get_option( Settings::OPTION_NEEDS_REVIEW );
	}

	/**
	 * Nonce-protected acknowledgment URL, shared by the admin notice and the
	 * in-settings marker so either surface clears the same flag.
	 *
	 * @return string
	 */
	public function ack_url(): string {
		return $this->action_url( self::ACTION_REVIEWED );
	}

	/**
	 * Render the return-to-Pro review notice across wp-admin — except on the
	 * Pay for Payment tab, which carries its own in-app banner, so the two
	 * prompts never stack on the same screen.
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->needs_review() ) {
			return;
		}

		if ( $this->settings->is_current_tab() ) {
			return;
		}

		$this->render_action_notice(
			'p4p-review-notice',
			__( 'Welcome back to Pay for Payment Pro!', 'woocommerce-pay-for-payment' ),
			__( 'You already have Pro fee rules saved — extra tiers, conditional fees and store-wide fee options — and they\'re live again now that Pro is active. Take a quick look so nothing unexpected shows up at checkout or on new orders.', 'woocommerce-pay-for-payment' ),
			'',
			Settings::settings_url(),
			__( 'Review my fee settings', 'woocommerce-pay-for-payment' ),
			$this->ack_url(),
			__( 'Yes, I\'ve reviewed — hide this notice', 'woocommerce-pay-for-payment' )
		);
	}

	/**
	 * Clear the review flag — a single acknowledgment retires both the
	 * admin notice and the in-settings marker.
	 * @return void
	 */
	public function handle_reviewed() {
		$this->authorize_action( self::ACTION_REVIEWED );

		delete_option( Settings::OPTION_NEEDS_REVIEW );

		$this->finish_request();
	}
}

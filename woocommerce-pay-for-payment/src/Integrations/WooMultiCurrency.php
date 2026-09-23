<?php

namespace Pay4Payment\Integrations;

/**
 * CURCY – Multi Currency for WooCommerce (VillaTheme) integration,
 * free and premium variants.
 *
 * Ported from v2 (Pay4Pay_Woo_Multi_Currency).
 *
 * @see https://wordpress.org/plugins/woo-multi-currency/
 */
class WooMultiCurrency extends AbstractMultiCurrency {

	/**
	 * Marks an order that has already been told the fee was skipped, so a
	 * merchant saving the same order repeatedly collects one note, not one
	 * per save.
	 */
	const META_BLIND_NOTICE = '_pay4payment_curcy_admin_skip_noticed';

	/**
	 * @inheritDoc
	 */
	public function is_active(): bool {
		return ( defined( 'WOOMULTI_CURRENCY_F_VERSION' ) && version_compare( WOOMULTI_CURRENCY_F_VERSION, '2.1.5', '>=' ) )
			|| ( defined( 'WOOMULTI_CURRENCY_VERSION' ) && version_compare( WOOMULTI_CURRENCY_VERSION, '2.1.9', '>=' ) );
	}

	/**
	 * @inheritDoc
	 */
	public function setup() {
		parent::setup();

		add_filter( 'pay4payment_order_fee_sync', array( $this, 'veto_unconvertible_admin_order' ), 10, 4 );
	}

	/**
	 * @inheritDoc
	 */
	public function convert( $fee ) {
		return wmc_get_price( $fee );
	}

	/**
	 * Refuse the admin-order fee sync when CURCY will not convert the amount.
	 *
	 * `wmc_get_price()` returns the amount untouched whenever
	 * `is_admin() && ! wp_doing_ajax()` — the guard is the first thing in the
	 * function, before any currency handling, so passing a currency code does
	 * not get past it. CURCY is entitled to that: it treats wp-admin as
	 * base-currency territory.
	 *
	 * The plugin is not, because `OrderFeeSync::sync_admin_order()` runs on
	 * `woocommerce_process_shop_order_meta` — squarely inside that guard —
	 * and writes what it gets onto an order that has its own currency. The
	 * configured amount is in the store's base currency, so an order in
	 * another currency is charged the base number as if it were the order's:
	 * a fee of 100 CZK becomes 100 € rather than 4 €. The error is always in
	 * the overcharging direction, and nothing on the order says so.
	 *
	 * So no fee is written at all. A missing fee is visible on the order and
	 * the merchant can add one by hand; a plausible wrong number is neither.
	 * This is the same fail-closed stance the condition evaluator takes when
	 * it cannot answer a rule.
	 *
	 * Only this surface. The checkout and order-pay paths run on the front
	 * end where CURCY converts normally, and the watchdog's cron is not
	 * `is_admin()` either — none of them are affected, and none are vetoed.
	 *
	 * Fixing it properly means converting against the *order's* currency
	 * rather than the ambient session's, the way the order path already
	 * substitutes the order's tax rates and VAT-exempt flag for the session
	 * customer's. That needs the order threaded through to the integrations,
	 * which crosses the free/Pro seam — tracked in #316.
	 *
	 * @param bool $sync Whether to sync.
	 * @param mixed $order The order.
	 * @param string $gateway_id The gateway the order is (about to be) paid with.
	 * @param string $source `order_pay`, `admin` or `checkout`.
	 * @return bool
	 */
	public function veto_unconvertible_admin_order( $sync, $order, $gateway_id, $source ) {
		if ( ! $sync || 'admin' !== $source ) {
			return $sync;
		}
		if ( ! is_admin() || wp_doing_ajax() ) {
			return $sync;
		}
		if ( ! $order instanceof \WC_Order ) {
			return $sync;
		}

		// An order already in the base currency needs no conversion, so
		// CURCY declining to convert costs nothing and the fee is correct.
		$currency = $order->get_currency();
		if ( '' === $currency || $currency === get_option( 'woocommerce_currency' ) ) {
			return $sync;
		}

		if ( ! $order->get_meta( self::META_BLIND_NOTICE ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: the order's currency code, e.g. EUR. */
					__( 'No payment fee was added: the active multi-currency plugin does not convert amounts in the admin, and this order is in %s rather than the shop currency. Add the fee by hand if it is due.', 'woocommerce-pay-for-payment' ),
					$currency
				)
			);
			$order->update_meta_data( self::META_BLIND_NOTICE, 'yes' );
			$order->save();
		}

		return false;
	}

}

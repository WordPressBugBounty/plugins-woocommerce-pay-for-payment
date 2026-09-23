<?php

namespace Pay4Payment;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Helpers\Cast;

/**
 * Everything a "delete plugin" has to remove — the counterpart of
 * `Migration`, which owns the install/upgrade side of the same rows.
 *
 * Called straight from `pay4payment_uninstall()` (the Freemius
 * `after_uninstall` hook), so every constraint that entry point documents
 * applies here and shapes the class:
 *
 * - **Static, container-free.** There is no component graph at uninstall,
 *   and building one would make "delete plugin" depend on every constructor
 *   staying side-effect free — and on the scoped deps, which are not loaded
 *   when WooCommerce is inactive.
 * - **Never names a `src/Pro/` class**, because this is shared code and that
 *   folder is stripped from the free build. Rows a Pro component owns are
 *   named by their `Settings::OPTION_*` constant, which the owner aliases
 *   back (`Pro\FeeWatchdog::OPTION_STATE`, `Pro\ManagedFeeProduct::OPTION_PRODUCT_ID`).
 * - **WooCommerce may be gone.** Anything reaching for it checks first; the
 *   option rows still have to go either way.
 *
 * The option NAMES stay in `Settings` (the storage layer declares them, their
 * owners alias them). Only the deleting lives here — the reason the class
 * exists at all is that `Settings` had grown a second responsibility,
 * WooCommerce product lifecycle, on top of option storage (#164).
 */
final class Uninstall {

	/**
	 * Remove everything the plugin ever wrote, its v2 predecessor's leftovers
	 * included (#149) — a delete should not seed the next install with stale
	 * rows, and one of them used to have teeth: the migration marker made a
	 * reinstall of the same version skip the migration entirely (#142).
	 *
	 * One thing is deliberately spared: the `pay4pay_*` keys inside each
	 * gateway's own `woocommerce_{id}_settings` option. The migration is
	 * non-destructive by design so a rollback to 2.x keeps working
	 * (PRD.md, readme.txt), and those keys are the 2.x configuration itself.
	 *
	 * @return void
	 */
	public static function run(): void {
		delete_option( Settings::OPTION_GENERAL );
		delete_option( Settings::OPTION_WATCHDOG );
		delete_option( Settings::OPTION_VERSION );
		delete_option( Settings::OPTION_MIGRATED );
		delete_option( Settings::OPTION_OPTIN_ASK );
		delete_option( Settings::OPTION_LAST_PREMIUM );
		delete_option( Settings::OPTION_NEEDS_REVIEW );
		delete_option( Settings::OPTION_LEGACY_FOOTER_RATED );

		self::trash_managed_fee_product();
		self::delete_options_by_pattern();
	}

	/**
	 * The two sets of rows that can only be found by name pattern: v3's
	 * per-method options (keyed by gateway id) and v2's per-field ones (keyed
	 * by gateway id AND field name). Neither can be listed, so both are
	 * discovered with a `LIKE` over `option_name`.
	 *
	 * No caching: this runs once, during uninstall, and any cache it warmed
	 * would be thrown away with the site's options moments later.
	 *
	 * @return void
	 */
	private static function delete_options_by_pattern(): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( Settings::OPTION_PREFIX_METHOD ) . '%',
			$wpdb->esc_like( 'woocommerce_' ) . '%' . $wpdb->esc_like( Settings::OPTION_LEGACY_FIELD_INFIX ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$option_names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
					$pattern
				)
			);

			// get_col() returns the bare option names. The previous
			// get_results() handed delete_option() a row OBJECT, which it
			// silently ignored — every pay4pay_payment_method_* option
			// survived uninstall. A non-string here can only come from a
			// broken query result; skip it rather than pass it on.
			foreach ( $option_names as $option_name ) {
				if ( is_string( $option_name ) ) {
					delete_option( $option_name );
				}
			}
		}
	}

	/**
	 * Trash the managed "Payment fee" product and forget its id.
	 *
	 * Created by `Pro\ManagedFeeProduct` for the off-checkout product mode and
	 * hidden from the catalogue, so leaving it behind leaves an invisible
	 * product no one can explain (#149). Only the one product the plugin
	 * created is touched — the id comes from the option, never a search.
	 *
	 * **Trashed, not deleted** (decided 2026-08-26 with #149). Product mode
	 * puts this product on real order lines (`Fees\OrderFeeLines::add_product_line()`
	 * → `$order->add_product()`), so every past order in that mode carries its
	 * id. Line totals are snapshotted as item meta and survive either way, but
	 * a hard delete silently breaks every live lookup behind them —
	 * `$item->get_product()` returns false, "Order Again" drops the fee line,
	 * Analytics rows dangle. Trashing takes it out of the catalogue, which is
	 * what the leftover was, and leaves the order history whole. It is also
	 * what WooCommerce does to its own created pages, and even that only
	 * behind the opt-in `WC_REMOVE_ALL_DATA` — a "delete plugin" click is not
	 * consent to rewrite order history.
	 *
	 * Through WooCommerce when it is loaded, so its own bookkeeping runs;
	 * uninstall can run with WooCommerce inactive (the option rows still have
	 * to go), and then `wp_trash_post()` is all there is.
	 *
	 * @return void
	 */
	private static function trash_managed_fee_product(): void {
		$product_id = Cast::to_int( get_option( Settings::OPTION_FEE_PRODUCT, 0 ) );

		if ( $product_id > 0 ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( $product instanceof \WC_Product ) {
				// `false` = trash rather than force-delete.
				$product->delete( false );
			} elseif ( function_exists( 'wp_trash_post' ) ) {
				wp_trash_post( $product_id );
			}
		}

		delete_option( Settings::OPTION_FEE_PRODUCT );
	}
}

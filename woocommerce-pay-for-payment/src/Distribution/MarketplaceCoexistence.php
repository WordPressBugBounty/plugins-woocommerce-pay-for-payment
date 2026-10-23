<?php

namespace Pay4Payment\Distribution;

/**
 * The marketplace build's answer to the coexistence guard every build of
 * this plugin opens its main file with (docs/agents/distribution.md).
 *
 * Every build's main file begins `if ( function_exists( 'pay4payment_fs' ) )
 * { pay4payment_fs()->set_basename( …, __FILE__ ); } else { <the plugin> }`.
 * On the Freemius builds `pay4payment_fs()` is the SDK, and the SDK's
 * `set_basename()` registers the newly loaded copy's activation hook so
 * that activating it deactivates the copy already running — the last
 * activated build wins. The marketplace build ships no SDK, so
 * `distribution/marketplace.php` defines `pay4payment_fs()` to return this
 * object instead: the same method, the same semantics, nothing else. Without
 * it a Freemius copy loaded after this one would run its body and PHP
 * would fatal on "cannot redeclare" on every request.
 *
 * Deactivating *this* copy from the other's activation hook works because
 * WordPress re-reads `active_plugins` after the hook; deactivating the
 * other copy from there would not — it is not in the option yet.
 */
final class MarketplaceCoexistence {

	/**
	 * @var string Absolute path of this build's main plugin file.
	 */
	private $own_file;

	/**
	 * @param string $own_file Absolute path of this build's main plugin file.
	 */
	public function __construct( string $own_file ) {
		$this->own_file = $own_file;
	}

	/**
	 * Called by the guard of another build of this plugin that WordPress
	 * loaded after this one.
	 *
	 * @param bool   $is_premium Whether the caller is a premium build (the
	 *                           SDK's first parameter; unused here — both
	 *                           builds are handled the same way).
	 * @param string $file       The caller's main plugin file.
	 * @return void
	 */
	public function set_basename( bool $is_premium, string $file ): void {
		unset( $is_premium );

		if ( ! function_exists( 'register_activation_hook' ) ) {
			return;
		}

		register_activation_hook( $file, [ $this, 'deactivate_self' ] );
	}

	/**
	 * Activation hook of the other copy: step aside for it.
	 *
	 * @return void
	 */
	public function deactivate_self(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( $this->own_file ) );
	}

	/**
	 * Drop the uninstall callback another build of this plugin left in
	 * `uninstall_plugins`, the way the SDK's own `unregister_uninstall_hook()`
	 * does when its other build takes over.
	 *
	 * Only the Freemius builds register one: the SDK's static uninstall hook.
	 * Deleting such a copy while this one is active, WordPress includes its
	 * main file, whose guard finds this build's `pay4payment_fs()` and loads
	 * nothing, so the callback names a class that never loads and "Delete"
	 * ends in a fatal. With this copy active the callback would have nothing
	 * to do anyway: the SDK skips its cleanup while the other build is
	 * active, and the settings belong to this copy now.
	 *
	 * @return void
	 */
	public function release_sdk_uninstall_hooks(): void {
		$callbacks = get_option( 'uninstall_plugins' );
		if ( ! is_array( $callbacks ) ) {
			return;
		}

		$own     = plugin_basename( $this->own_file );
		$changed = false;
		foreach ( array_keys( $callbacks ) as $basename ) {
			if ( $basename !== $own && self::is_build_basename( (string) $basename ) ) {
				unset( $callbacks[ $basename ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'uninstall_plugins', $callbacks );
		}
	}

	/**
	 * Every build shares the main file's name and differs only in its folder
	 * (`woocommerce-pay-for-payment`, `woocommerce-pay-for-payment-pro`,
	 * `pay-for-payment`, or whatever a site renamed it to). The marketplace
	 * `uninstall.php` repeats this match inline: it runs before any class
	 * may be loaded.
	 *
	 * @param string $basename A plugin basename.
	 * @return bool
	 */
	private static function is_build_basename( string $basename ): bool {
		return 1 === preg_match( '#^[^/]+/woocommerce-payforpayment\.php$#', $basename );
	}
}

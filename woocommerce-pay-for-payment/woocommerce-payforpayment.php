<?php
/**
 * Plugin Name:          Pay for Payment for WooCommerce – Payment Gateway Fees & Discounts
 * Description:          Charge a fee or give a discount on any WooCommerce payment method. Fixed or percentage, tax aware, with min and max caps.
 * Requires at least:    6.2
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * Version:              3.0.3
 * Author:               Karolína Vyskočilová
 * Author URI:           https://kybernaut.cz
 * Plugin URI:           https://payforpayment.com/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       	 woocommerce-pay-for-payment
 * Domain Path: 	     /languages
 * WC requires at least: 7.7.0
 * WC tested up to:      11.2.1
 *
  */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Pay4PaymentDeps\DI\Container;
use Pay4PaymentDeps\DI\DependencyException;
use Pay4PaymentDeps\DI\NotFoundException;
use Pay4Payment\ContainerFactory;
use Pay4Payment\Plugin;
use Pay4Payment\Uninstall;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Free ↔ Pro coexistence — Freemius' documented pattern for products with a
 * free and a premium build. When another build is already loaded (a merchant
 * activating Pro while the free build is still active, or the other way round),
 * `pay4payment_fs` exists already: hand it this file's basename and load
 * nothing else — the SDK deactivates the other build as part of the activation.
 * The whole plugin body has to sit in the `else` branch: PHP binds top-level
 * function declarations at compile time, so an early `return` would not stop
 * "cannot redeclare" fatals. The `true` marks this file as the premium build;
 * the generated free build must carry `false` here (docs/RELEASE.md §3b).
 *
 * This is the one SDK call the main file makes, and it is the same in every
 * build: the WooCommerce.com marketplace build, which ships no SDK, defines
 * `pay4payment_fs()` as a shim with this one method (`distribution/marketplace.php`),
 * so a Freemius copy loaded after it makes this very call and steps aside the
 * same way (docs/agents/distribution.md).
 */
if ( function_exists( 'pay4payment_fs' ) ) {
	pay4payment_fs()->set_basename( false, __FILE__ );
} else {

	/**
	 * Version of the plugin.
	 */
	define( 'PAY4PAYMENT_VERSION', '3.0.3' );

	/**
	 * Minimum required PHP version.
	 */
	define( 'PAY4PAYMENT_MIN_PHP_VERSION', '8.0' );

	/**
	 * Minimum required WooCommerce version.
	 */
	define( 'PAY4PAYMENT_MIN_WC_VERSION', '7.7.0' );


	/**
	 * Load plugin textdomain.
	 *
	 * @since 3.0.0
	 */
	function pay4payment_load_textdomain() {
		load_plugin_textdomain( 'woocommerce-pay-for-payment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
	add_action( 'init', 'pay4payment_load_textdomain' );


	/**
	 * Queue a requirement notice for the current request — the one notice
	 * mechanism for every guard in this file (site and network admin).
	 *
	 * Plain `admin_notices` rather than the SDK's FS_Admin_Notices on purpose:
	 * the first two guards run before anything is loaded, the Freemius SDK
	 * included (the Composer autoloader's platform check would fatal on
	 * PHP < 8.0 before a friendlier notice could render), and a requirement
	 * notice is not a licensing concern anyway.
	 *
	 * @param callable $message Builds the notice text when it renders — after
	 *                          `init`, so translations are loaded by then; links
	 *                          (`<a href>`) are allowed.
	 * @return void
	 */
	function pay4payment_admin_error_notice( callable $message ): void {
		$render = static function () use ( $message ) {
			echo '<div class="notice notice-error"><p><strong>Pay for Payment for WooCommerce:</strong> ';
			echo wp_kses( $message(), array( 'a' => array( 'href' => array() ) ) );
			echo '</p></div>';
		};
		add_action( 'admin_notices', $render );
		add_action( 'network_admin_notices', $render );
	}

	/**
	 * Requirements that must hold before anything is loaded: the PHP floor, and
	 * both autoloaders being present — Composer's `vendor/` (which also carries
	 * the Freemius SDK) and the scoped `vendor/deps/`. Notice-only, no deactivation.
	 *
	 * Every path must be __DIR__-anchored. A relative path is resolved against
	 * the PHP include_path (and only then against the calling file's directory),
	 * so on a host with an unusual include_path — or when the plugin file is
	 * loaded through a symlink — it resolves elsewhere and activation fatals
	 * before these notices ever render.
	 */
	if ( version_compare( PHP_VERSION, PAY4PAYMENT_MIN_PHP_VERSION, '<' ) ) {
		pay4payment_admin_error_notice( static function () {
			return sprintf(
				/* translators: 1: Required PHP version number, 2: Current PHP version number, 3: URL of PHP update help page */
				__( 'The plugin requires PHP version %1$s or higher. This site is running PHP version %2$s. <a href="%3$s">Learn about updating PHP</a>.', 'woocommerce-pay-for-payment' ),
				PAY4PAYMENT_MIN_PHP_VERSION,
				PHP_VERSION,
				'https://wordpress.org/support/update-php/'
			);
		} );
		return;
	}

	/**
	 * Where the scoped libraries live.
	 *
	 * They moved from `deps/` to `vendor/deps/` in 3.0.0-rc2, so that
	 * wordpress.org's Plugin Check stops linting vendored php-di as if this
	 * plugin had written it. An update that leaves the previous tree in place
	 * can end up with only the old path present, and refusing to boot over a
	 * folder name — when the autoloader sitting there is byte-identical and
	 * carries the same `Pay4PaymentDeps` prefix — would turn a cosmetic
	 * difference into a dead plugin and a support ticket.
	 */
	$pay4payment_deps_dir = __DIR__ . '/vendor/deps';
	if ( ! file_exists( $pay4payment_deps_dir . '/scoper-autoload.php' )
		&& file_exists( __DIR__ . '/deps/scoper-autoload.php' ) ) {
		$pay4payment_deps_dir = __DIR__ . '/deps';
	}

	/**
	 * Name what is actually missing.
	 *
	 * "The plugin files are corrupted" is true and useless: it is the same
	 * sentence whether Composer never ran, an update half-applied, or a
	 * security plugin quarantined one file. A merchant cannot act on it and
	 * neither can support, so the path goes in the notice.
	 */
	/*
	 * The distribution channel's bootstrap (required below). One variable, so
	 * the missing-file check and the require cannot name different files —
	 * the marketplace build rewrites this line and nothing else.
	 */
	$pay4payment_distribution = __DIR__ . '/distribution/freemius.php';

	$pay4payment_required = array(
		__DIR__ . '/vendor/autoload.php',
		$pay4payment_distribution,
		$pay4payment_deps_dir . '/scoper-autoload.php',
		$pay4payment_deps_dir . '/autoload.php',
	);
	$pay4payment_missing  = array();

	foreach ( $pay4payment_required as $pay4payment_file ) {
		if ( ! file_exists( $pay4payment_file ) ) {
			$pay4payment_missing[] = str_replace( __DIR__ . '/', '', $pay4payment_file );
		}
	}

	if ( $pay4payment_missing ) {
		pay4payment_admin_error_notice( static function () use ( $pay4payment_missing ) {
			return sprintf(
				/* translators: %s: comma-separated list of missing file paths. */
				_n(
					'Pay for Payment is missing a file it needs to run: %s. Re-installing the plugin restores it.',
					'Pay for Payment is missing files it needs to run: %s. Re-installing the plugin restores them.',
					count( $pay4payment_missing ),
					'woocommerce-pay-for-payment'
				),
				implode( ', ', $pay4payment_missing )
			);
		} );
		return;
	}

	/**
	 * Composer autoloader: the plugin classes and, on the Freemius builds, the
	 * SDK (`freemius/wordpress-sdk` registers its `start.php` as a Composer
	 * autoload file, so the SDK must not be required by hand as well — Composer
	 * includes autoload files with a plain `require`, and the SDK would load
	 * twice). The marketplace build strips that autoload entry with the SDK.
	 */
	require_once __DIR__ . '/vendor/autoload.php';


	/**
	 * The distribution channel — everything channel-specific in one file
	 * (docs/agents/distribution.md). The Freemius builds ship
	 * `distribution/freemius.php` (the SDK accessor and init, the uninstall
	 * hook, the free↔Pro build switch); the WooCommerce.com marketplace build
	 * ships `distribution/marketplace.php` instead — its build script points
	 * `$pay4payment_distribution` there and strips the SDK. Nothing else in
	 * this file, and nothing under `src/` outside `src/Freemius/`, may call
	 * the SDK: a call that survives into the SDK-less build fails silently,
	 * as free.
	 */
	require_once $pay4payment_distribution;


	/**
	 * Check if required plugin is active
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	function pay4payment_plugin_is_active( $plugin ) {
		if ( is_multisite() ) {
			$plugins = get_site_option( 'active_sitewide_plugins' );
			if ( isset( $plugins[ $plugin ] ) ) {
				return true;
			}
		}

		if ( in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method called on plugin uninstall — from the SDK's `after_uninstall`
	 * on the Freemius builds (wired in `distribution/freemius.php`; the
	 * marketplace build runs the same cleanup from its root `uninstall.php`).
	 *
	 * Calls the cleanup directly instead of going through the container:
	 * building the whole component graph for it would make "delete plugin"
	 * depend on every constructor staying side-effect free — and on the scoped
	 * deps, which are not loaded when WooCommerce is inactive. That is also why
	 * `Uninstall` reaches WooCommerce's product API itself to trash the managed
	 * fee product (#149) rather than asking the component that owns it: there
	 * is no component here to ask.
	 *
	 * @return void
	 */
	function pay4payment_uninstall(): void {
		Uninstall::run();
	}


	/**
	 * WooCommerce must be active for everything else. If not, show an admin
	 * notice and stop here (deliberately no `deactivate_plugins()`).
	 */
	if ( ! pay4payment_plugin_is_active( 'woocommerce/woocommerce.php' ) ) {
		pay4payment_admin_error_notice( static function () {
			return __( 'WooCommerce is not active, so no payment fees are being charged. Install and activate it — your fee settings are kept.', 'woocommerce-pay-for-payment' );
		} );
		return;
	}

	/**
	 * Scoped dependencies (php-di and friends, prefixed `Pay4PaymentDeps` by
	 * wpify/scoper). __DIR__-anchored for the same reason as above.
	 */
	require_once $pay4payment_deps_dir . '/scoper-autoload.php';
	require_once $pay4payment_deps_dir . '/autoload.php';


	/**
	 * Creates and returns DI container.
	 * If DI container is already created, returns it.
	 *
	 * Which build this is — the free seam bindings (`Bindings::SEAMS`) or
	 * the Pro overlay (`Pro\Bindings`) — is the distribution file's answer
	 * (`pay4payment_bindings()`, docs/agents/freemius.md); the wiring itself
	 * is `ContainerFactory`.
	 *
	 * @return Container
	 * @throws Exception
	 */
	function pay4payment_container(): Container {
		static $container;

		if ( empty( $container ) ) {
			[ $seams, $components ] = pay4payment_bindings();

			$container = ContainerFactory::build( __FILE__, $seams, $components );
		}

		return $container;
	}

	/**
	 * Returns the main plugin class from DI container.
	 *
	 * @return Plugin
	 * @throws DependencyException
	 * @throws NotFoundException
	 */
	function pay4payment(): Plugin {
		return pay4payment_container()->get( Plugin::class );
	}

	/**
	 * Check if the installed WooCommerce version is compatible with the plugin.
	 *
	 * Can only be checked after WooCommerce is loaded (`plugins_loaded`).
	 *
	 * @return bool
	 */
	function pay4payment_check_wc_version(): bool {
		return ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, PAY4PAYMENT_MIN_WC_VERSION, '>=' );
	}

	/**
	 * Queue an admin notice about an unsupported WooCommerce version.
	 *
	 * @return void
	 */
	function pay4payment_wc_version_notice(): void {
		pay4payment_admin_error_notice( static function () {
			return sprintf(
				/* translators: %s: Required WooCommerce version number */
				__( 'The plugin requires WooCommerce version %s or higher. Please update WooCommerce!', 'woocommerce-pay-for-payment' ),
				PAY4PAYMENT_MIN_WC_VERSION
			);
		} );
	}

	/**
	 * Init
	 * @return void
	 * @throws DependencyException
	 * @throws NotFoundException
	 */
	function pay4payment_init(): void {
		if ( ! pay4payment_check_wc_version() ) {
			pay4payment_wc_version_notice();
			return;
		}
		pay4payment()->init();
	}

	/**
	 * Method called on plugin activation.
	 *
	 * @param $network_wide Plugin Activated network wide.
	 *
	 * @return void
	 * @throws DependencyException
	 * @throws NotFoundException
	 */
	function pay4payment_activate( $network_wide ): void {
		if ( ! pay4payment_check_wc_version() ) {
			pay4payment_wc_version_notice();
			return;
		}
		pay4payment()->activate( $network_wide );
	}

	/**
	 * Method called on plugin deactivation.
	 *
	 * @param $network_wide Plugin Deactivated network wide.
	 *
	 * @return void
	 * @throws DependencyException
	 * @throws NotFoundException
	 */
	function pay4payment_deactivate( $network_wide ): void {
		pay4payment()->deactivate( $network_wide );
	}

	/**
	 * Init plugin.
	 */
	add_action( 'plugins_loaded', 'pay4payment_init' );

	register_activation_hook( __FILE__, 'pay4payment_activate' );
	register_deactivation_hook( __FILE__, 'pay4payment_deactivate' );

	// Declare compatibility with Custom Order Tables and Cart/Checkout Blocks.
	add_action( 'before_woocommerce_init', function () {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__ );
		}
	} );

}

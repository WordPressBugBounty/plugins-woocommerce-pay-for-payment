<?php

/**
 * Freemius bootstrap — the distribution file the Freemius builds ship
 * (docs/agents/distribution.md).
 *
 * Everything channel-specific the main plugin file used to do inline lives
 * here: the SDK accessor and init, the plugin icon, the uninstall hook, the
 * channel `Premium` asks, and the free↔Pro build switch. The main file
 * `require`s this right after the Composer autoloader; the marketplace
 * build swaps this file for `distribution/marketplace.php` (the build
 * script rewrites that one `require_once`) and ships no SDK at all.
 *
 * Two things about this file are the Freemius deployment's business, so
 * their shape must not change:
 *
 * - `fs_dynamic_init()`: the deployment flips `'is_premium' => true` to
 *   `false` and drops the `wp_org_gatekeeper` line in the free build.
 * - `if ( pay4payment_fs()->is__premium_only() ) { … }` in
 *   `pay4payment_bindings()`: the one block the deployment strips from the
 *   free build — bare SDK call, no `&&`, no `else` (docs/agents/freemius.md;
 *   `FreeBuildContractTest` pins it).
 *
 * The coexistence guard (`set_basename( true, __FILE__ )`) stays in the
 * main file: the SDK needs the main file's path, and the deployment
 * rewrites its `true` to `false` there.
 *
 * @package Pay4Payment
 */
use Pay4Payment\Bindings;
use Pay4Payment\Freemius\FreemiusDistribution;
use Pay4Payment\Premium;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE `function_exists` CALL IN THE MAIN FILE TO PROPERLY WORK.
if ( !function_exists( 'pay4payment_fs' ) ) {
    /**
     * Helper function for easy SDK access.
     *
     * @return Freemius
     */
    function pay4payment_fs() {
        global $pay4payment_fs;
        if ( !isset( $pay4payment_fs ) ) {
            // Activate multisite network integration.
            if ( !defined( 'WP_FS__PRODUCT_13223_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_13223_MULTISITE', true );
            }
            // Include Freemius SDK.
            // SDK is auto-loaded through Composer (vendor/autoload.php, required by the main file).
            $pay4payment_fs = fs_dynamic_init( array(
                'id'               => '13223',
                'slug'             => 'woocommerce-pay-for-payment',
                'premium_slug'     => 'woocommerce-pay-for-payment-pro',
                'type'             => 'plugin',
                'public_key'       => 'pk_0bd2869a910392b81c6b8ae6ffeeb',
                'is_premium'       => false,
                'premium_suffix'   => 'PRO',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'trial'            => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu'             => array(
                    'first-path' => 'admin.php?page=wc-settings&tab=pay4payment',
                    'contact'    => false,
                    'support'    => false,
                ),
                'is_live'          => true,
            ) );
        }
        return $pay4payment_fs;
    }

    // Init Freemius.
    pay4payment_fs();
    /*
     * The icon on Freemius' own screens — opt-in, account, pricing, the
     * deactivation feedback modal (#230).
     *
     * Without this the SDK goes looking for one: it falls back to the
     * wp.org API, and for the Pro build — which is not on wp.org under
     * its premium slug — to a placeholder. Shipping the file settles it
     * for both builds and works offline. It is the same PNG the settings
     * header uses (`assets/scss/settings.scss`), which is why
     * `assets/images` is in the zip's `files` list.
     */
    pay4payment_fs()->add_filter( 'plugin_icon', function () {
        return dirname( __DIR__ ) . '/assets/images/icon-256x256.png';
    } );
    // Signal that SDK was initiated.
    do_action( 'pay4payment_fs_loaded' );
}
/*
 * The channel `Premium` asks — the only object in the plugin that talks to
 * the SDK, constructed here and nowhere else.
 */
Premium::set_distribution( new FreemiusDistribution() );
/**
 * Hooked through the Freemius SDK rather than `register_uninstall_hook()`:
 * WordPress stores one uninstall callback per plugin, and the SDK registers
 * its own on deactivation (and removes it again on activation — a stored
 * callback makes WordPress include the main file on delete, which fatals on
 * "cannot redeclare" when the free and the Pro build are both installed).
 * Freemius fires `after_uninstall` once its own handling is done, and
 * `pay4payment_uninstall()` (main file) runs the cleanup.
 *
 * Wired before the WooCommerce guard in the main file: WordPress loads the
 * plugin once more when it is deleted, and WooCommerce may be inactive by
 * then — the options still have to go. Known gap, the SDK's documented
 * behaviour: a WP-CLI deactivation without `--user` never stores the SDK
 * handler, so a later delete cleans nothing.
 */
pay4payment_fs()->add_action( 'after_uninstall', 'pay4payment_uninstall' );
/**
 * The seam bindings and components this build wires the container from
 * (`ContainerFactory::build()`, called by `pay4payment_container()` in the
 * main file).
 *
 * This is where the build is chosen: the free seam bindings
 * (`Bindings::SEAMS`), or the Pro overlay (`Pro\Bindings`) inside the one
 * `if` block the Freemius deployment preprocessor removes from the
 * generated free build — together with `src/Pro/`, listed as
 * `@fs_premium_only` in the plugin header.
 *
 * @return array{0: array<class-string, class-string>, 1: array<int, class-string>} Seams, then components.
 */
function pay4payment_bindings() : array {
    $seams = Bindings::SEAMS;
    // The build's OWN components only — none in the free build.
    // `ContainerFactory::build()` prepends the shared
    // `Bindings::COMPONENTS` itself, so naming that list here would
    // set every shared component up twice (ContainerFactoryTest).
    $components = [];
    return [$seams, $components];
}

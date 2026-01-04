<?php

declare(strict_types=1);

/**
 * Plugin Name: CamooPay for e-Commerce - Mobile Money Gateway
 * Requires Plugins: woocommerce
 * Plugin URI: https://github.com/camoo/camoo-woocommerce-gateway
 * Description: Receive Mobile Money payments on your store using CamooPay for WooCommerce.
 * Version: 1.0.9
 * Tested up to: 6.9
 * Author: Camoo Sarl
 * Author URI: https://profiles.wordpress.org/camoo/
 * Developer: Camoo Sarl
 * Text Domain: camoo-pay-for-ecommerce
 * Domain Path: /includes/languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Camoo\Pay\WooCommerce;

defined('ABSPATH') || exit;

if (version_compare(PHP_VERSION, '8.1', '<')) {

    add_action('admin_notices', static function(): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html('CamooPay for e-Commerce requires PHP 8.1 or higher.')
            . '</p></div>';
    });

    return;
}

require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/admin/PluginAdmin.php';
/**
 * Delay plugin boot until plugins_loaded
 */
add_action('plugins_loaded', static function () {
    if (!class_exists('\WooCommerce')) {
        add_action('admin_notices', static function(): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('WooCommerce must be active to use CamooPay.', 'camoo-pay-for-ecommerce')
                . '</p></div>';
        });

        return;
    }

    // Defer real plugin startup
    add_action('init', static function(): void {
        $plugin = new Plugin(
            __FILE__,
            'WC_CamooPay_Gateway',
            'Gateway',
            'CamooPay for e-commerce payment gateway',
            '1.0.9'
        );

        $plugin->register();
        $plugin->onInit();
    });
}, 0);

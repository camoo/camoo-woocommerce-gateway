<?php

/**
 * Plugin Name: CamooPay for e-Commerce - Mobile Money Gateway
 * Requires Plugins: woocommerce
 * Plugin URI: https://github.com/camoo/camoo-woocommerce-gateway
 * Description: Receive Mobile Money payments on your store using CamooPay for WooCommerce.
 * Version: 1.0
 * Tested up to: 6.7
 * Author: Camoo Sarl
 * Author URI: https://profiles.wordpress.org/camoo/
 * Developer: Camoo Sarl
 * Text Domain: camoo-pay-for-ecommerce
 * Domain Path: /includes/languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 8.0
 * WC tested up to: 9.6.1
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace Camoo\Pay\WooCommerce;

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/admin/PluginAdmin.php';

(new Plugin(
    __FILE__,
    'WC_CamooPay_Gateway',
    'Gateway',
    sprintf(
        '%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
        __('CamooPay for e-commerce payment gateway', 'camoo-pay-for-ecommerce'),
        'https://camoo.cm/#camoo-pay',
        __('Do you have any questions or requests?', 'camoo-pay-for-ecommerce'),
        'https://github.com/camoo/camoo-pay-for-ecommerce',
        __('Do you like our plugin and can recommend to others.', 'camoo-pay-for-ecommerce')
    ),
    '1.0'
)
    )->register();

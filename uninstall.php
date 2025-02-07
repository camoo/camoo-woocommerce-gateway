<?php

declare(strict_types=1);

/**
 * Uninstalling CamooPay for eCommerce - Mobile Money Gateway, deletes tables, and options.
 *
 * @version 1.0
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Delete plugin options
delete_option('wc_camoo_pay_db_version');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE `option_name` LIKE 'woocommerce_wc_camoo_pay%';");

// Remove the gateway from the WooCommerce payment gateways list
add_filter('woocommerce_payment_gateways', 'remove_camoo_pay_gateway');

/**
 * Remove the CamooPay gateway from the WooCommerce payment gateways list.
 *
 * @param string[]|null $gateways
 *
 * @return string[]
 */
function remove_camoo_pay_gateway(?array $gateways): array
{
    if (empty($gateways)) {
        return [];
    }
    foreach ($gateways as $key => $gateway) {
        if ('WC_CamooPay_Gateway' === $gateway) {
            unset($gateways[$key]); // Remove the gateway
        }
    }

    return $gateways;
}

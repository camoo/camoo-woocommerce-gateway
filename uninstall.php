<?php

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

delete_option('wc_camoo_pay_db_version');

$wpdb->query("DELETE FROM {$wpdb->options} WHERE `option_name` LIKE 'woocommerce_wc_camoo_pay%';");

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_camoo_pay_payments");

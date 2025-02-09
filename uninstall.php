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

// Delete plugin options
remove_camoo_pay_plugin_options();
delete_camoo_pay_media_by_attachments();
// Remove the gateway from the WooCommerce payment gateways list
add_filter('woocommerce_payment_gateways', 'remove_camoo_pay_gateway');

/**
 * Delete plugin-related options from WordPress.
 */
function remove_camoo_pay_plugin_options(): void
{
    global $wpdb;

    // Delete the plugin-specific options
    delete_option('wc_camoo_pay_db_version');

    // Delete all options starting with 'woocommerce_wc_camoo_pay'
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE `option_name` LIKE 'woocommerce_wc_camoo_pay%';");
}
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

function delete_camoo_pay_media_by_attachments(): void
{
    $media_options = [
        'wc_camoo_pay_db_online_momo_image',
        'wc_camoo_pay_db_icon',
    ];

    // Loop through the media options to delete attachments
    foreach ($media_options as $option_key) {
        $attachment_id = get_option($option_key);
        delete_option($option_key);

        if ($attachment_id) {
            // Ensure the attachment_id is numeric and valid
            if (!is_numeric($attachment_id)) {
                continue;
            }

            // Check if the attachment exists and is a valid attachment post type
            $attachment = get_post($attachment_id);
            if (!$attachment || 'attachment' !== $attachment->post_type) {
                continue;
            }

            // Delete the attachment and the associated file from the server
            wp_delete_attachment($attachment_id, true);
        }
    }
}

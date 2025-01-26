<?php

declare(strict_types=1);

namespace Camoo\Pay\WooCommerce;

defined('ABSPATH') || exit;

class Install
{
    public const PLUGIN_MAIN_FILE = 'camoo-pay-for-ecommerce/camoo-pay-for-ecommerce.php';

    public function __construct()
    {
        add_action('wpmu_new_blog', [$this, 'add_table_on_create_blog'], 10, 1);
    }

    /**
     * Creating plugin tables
     */
    public static function install($network_wide): void
    {

        if (!is_admin()) {
            return;
        }
        self::upgrade();
    }

    /**
     * Creating Table for New Blog in WordPress
     */
    public function add_table_on_create_blog($blogId): void
    {
        if (!is_plugin_active_for_network(self::PLUGIN_MAIN_FILE)) {
            return;
        }

        switch_to_blog($blogId);

        restore_current_blog();
    }

    /** Upgrade plugin requirements if needed */
    public static function upgrade(): void
    {
        $installedVersion = get_option('wc_camoo_pay_db_version');

        if (empty($installedVersion) || version_compare($installedVersion, Plugin::WC_CAMOO_PAY_DB_VERSION, '<')) {
            update_option('wc_camoo_pay_db_version', Plugin::WC_CAMOO_PAY_DB_VERSION);
        }
    }
}

(new Install());

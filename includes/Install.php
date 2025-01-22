<?php

declare(strict_types=1);

namespace Camoo\Pay\WooCommerce;

defined('ABSPATH') || exit;

class Install
{
    public const PLUGIN_MAIN_FILE = 'camoo-pay-for-woocommerce/camoo-pay-for-woocommerce.php';

    public function __construct()
    {
        add_action('wpmu_new_blog', [$this, 'add_table_on_create_blog'], 10, 1);
        add_filter('wpmu_drop_tables', [$this, 'remove_table_on_delete_blog']);
    }

    /**
     * Creating plugin tables
     */
    public static function install($network_wide): void
    {
        self::create_table($network_wide);

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

        self::table_sql();
        restore_current_blog();
    }

    /**
     * Remove Table On Delete Blog Wordpress
     */
    public function remove_table_on_delete_blog($tables): array
    {
        global $wpdb;
        $tbl = 'wc_camoo_pay_payments';
        $tables[] = $wpdb->tb_prefix . $tbl;
        delete_option('wc_camoo_pay_db_version');
        delete_option('woocommerce_' . Plugin::WC_CAMOO_PAY_GATEWAY_ID . '_settings');

        return $tables;
    }

    /** Upgrade plugin requirements if needed */
    public static function upgrade(): void
    {
        $installedVersion = get_option('wc_camoo_pay_db_version');

        if ($installedVersion !== Plugin::WC_CAMOO_PAY_DB_VERSION) {
            update_option('wc_camoo_pay_db_version', Plugin::WC_CAMOO_PAY_DB_VERSION);
        }
    }

    protected static function create_table($network_wide): void
    {
        global $wpdb;

        if (is_multisite() && $network_wide) {
            $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);

                self::table_sql();

                restore_current_blog();
            }
        } else {
            self::table_sql();
        }
    }

    protected static function table_sql(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $wpdb->prefix . 'wc_camoo_pay_payments';
        if ($wpdb->get_var("show tables like '{$table_name}'") !== $table_name) {
            $create_camoo_pay_payments = ("CREATE TABLE IF NOT EXISTS {$table_name}(
            id int(10) NOT NULL auto_increment,
            wc_order_id bigint unsigned NOT NULL,
            merchant_reference_id varchar(128) NOT NULL DEFAULT '',
            order_transaction_id varchar(128) NOT NULL DEFAULT '',
            status                varchar(50)            DEFAULT NULL,
            status_date           datetime      NOT NULL DEFAULT '2025-01-20 00:00:00',
            created_at            timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            remote_ip             varbinary(64) NOT NULL DEFAULT '0.0.0.0',
            fee                   decimal(10,2) NOT NULL DEFAULT '0.00',
            PRIMARY KEY(ID)) CHARSET=utf8");
            add_option('wc_camoo_pay_db_version', Plugin::WC_CAMOO_PAY_DB_VERSION);
            dbDelta($create_camoo_pay_payments);
        }
    }
}

(new Install());

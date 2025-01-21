<?php

/**
 * PluginAdmin
 *
 * @author Camoo
 */

namespace Camoo\Pay\WooCommerce\Admin;

use Camoo\Pay\WooCommerce\Plugin;
use Camoo\Payment\Api\PaymentApi;
use Camoo\Payment\Http\Client;
use WC_Order;
use WC_Order_Refund;

defined('ABSPATH') || exit;

if (!class_exists(PluginAdmin::class)) {
    class PluginAdmin
    {
        protected static $instance = null;

        protected string $mainMenuId;

        protected string $author;

        protected bool $isRegistered;

        public function __construct()
        {
            $this->mainMenuId = 'wc-camoo-pay';
            $this->author = 'wordpress@camoo.sarl';
            $this->isRegistered = false;
        }

        public static function instance(): ?self
        {
            if (!isset(self::$instance)) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function register(): void
        {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            if ($this->isRegistered) {
                return;
            }

            $this->isRegistered = true;

            add_filter('manage_edit-shop_order_columns', [__CLASS__, 'extend_order_view_for_camoo_pay'], 10);
            add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'get_extended_order_value'], 2);
            add_filter('woocommerce_admin_order_actions', [__CLASS__, 'add_custom_order_status_actions_button'], 100, 2);
            add_action('wp_ajax_wc_camoo_pay_mark_order_status', [__CLASS__, 'checkRemotePaymentStatus']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_camoo_pay_css_scripts']);
        }

        public static function enqueue_admin_camoo_pay_css_scripts(): void
        {
            wp_enqueue_style(
                'admin_camoo_pay_style',
                plugins_url('/includes/assets/css/admin-style.css', dirname(__DIR__)),
                [],
                Plugin::WC_CAMOO_PAY_DB_VERSION
            );
        }

        public static function checkRemotePaymentStatus(): bool
        {
            if (current_user_can('edit_shop_orders') &&
                check_admin_referer('woocommerce_camoo_pay_check_status') &&
                isset($_GET['status'], $_GET['order_id'])) {
                $status = sanitize_text_field(wp_unslash($_GET['status']));
                /** @var bool|WC_Order|WC_Order_Refund $order */
                $order = wc_get_order(absint(wp_unslash($_GET['order_id'])));
                if ($status === 'check' && !empty($order) && $order->has_status(['pending', 'on-hold', 'processing'])) {
                    WC()->payment_gateways();
                    $settings = get_option('woocommerce_' . Plugin::WC_CAMOO_PAY_GATEWAY_ID . '_settings');
                    $consumerKey = sanitize_text_field($settings['camoo_pay_key']);
                    $consumerSecret = sanitize_text_field($settings['camoo_pay_secret']);
                    // $testMode = sanitize_text_field($settings['test_mode']) === 'yes';
                    $client = Client::create($consumerKey, $consumerSecret);

                    $paymentApi = new PaymentApi($client);
                    $paymentData = self::getPaymentByWcOrderId($order->get_id());
                    if ($paymentData) {
                        $verify = $paymentApi->verify($paymentData->order_transaction_id);
                        Plugin::processWebhookStatus(
                            $order,
                            $verify->status,
                            $paymentData->merchant_reference_id,
                            $verify
                        );
                    }
                }
            }

            return wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('edit.php?post_type=shop_order'));
        }

        public static function add_custom_order_status_actions_button(array $actions, $order): array
        {
            if ($order->get_payment_method() !== Plugin::WC_CAMOO_PAY_GATEWAY_ID) {
                return $actions;
            }

            if (!$order->has_status(['pending', 'on-hold', 'processing'])) {
                return $actions;
            }

            $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
            $actions['check'] = [
                'url' => wp_nonce_url(
                    admin_url('admin-ajax.php?action=wc_camoo_pay_mark_order_status&status=check&order_id=' .
                        absint(wp_unslash($order_id))),
                    'woocommerce_camoo_pay_check_status'
                ),
                'name' => __('Check status', 'camoo-pay-for-woocommerce'),
                'title' => __('Check remote order status', 'camoo-pay-for-woocommerce'),
                'action' => 'check',
            ];

            return $actions;
        }

        public function onAdminMenu(): void
        {
            add_menu_page(
                'CamooPay for e-commerce',
                'CamooPay for e-commerce',
                'manage_options',
                $this->mainMenuId,
                [&$this, 'display'],
                '',
                26
            );

            add_submenu_page(
                $this->mainMenuId,
                'About',
                'About',
                'manage_options',
                $this->mainMenuId
            );
        }

        /** Display the admin page */
        public function display(): void
        {
            echo 'CamooPay for e-commerce';
        }

        public static function extend_order_view_for_camoo_pay($columns): array
        {
            $new_columns = (is_array($columns)) ? $columns : [];
            unset($new_columns['wc_actions']);

            $new_columns['camoo_pay_merchant_reference_id'] = __('CamooPay Reference ID', 'camoo-pay-for-woocommerce');
            $new_columns['camoo_pay_order_transaction_id'] = __('CamooPay Transaction ID', 'camoo-pay-for-woocommerce');

            $new_columns['wc_actions'] = $columns['wc_actions'];

            return $new_columns;
        }

        public static function get_extended_order_value(string $column): void
        {
            global $post;
            $orderId = $post->ID;

            if ($column === 'camoo_pay_merchant_reference_id') {
                $paymentData = self::getPaymentByWcOrderId($orderId);
                echo esc_html($paymentData->merchant_reference_id ?? '');
            }

            if ($column === 'camoo_pay_order_transaction_id') {
                $paymentData = self::getPaymentByWcOrderId($orderId);

                echo esc_html($paymentData->order_transaction_id ?? '');
            }
        }

        protected static function getPaymentByWcOrderId(int $wcOrderId)
        {
            global $wpdb;

            $db_prepare = $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}wc_camoo_pay_payments` WHERE `wc_order_id` = %s",
                absint(wp_unslash($wcOrderId))
            );
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return $payment;
        }
    }
}

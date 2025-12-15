<?php

/**
 * PluginAdmin
 *
 * @author Camoo
 */

namespace Camoo\Pay\WooCommerce\Admin;

use Camoo\Pay\WooCommerce\Admin\Enum\MetaKeysEnum;
use Camoo\Pay\WooCommerce\Logger\Logger;
use Camoo\Pay\WooCommerce\Media;
use Camoo\Pay\WooCommerce\Plugin;
use Camoo\Payment\Api\PaymentApi;
use Camoo\Payment\Http\Client;
use Throwable;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

defined('ABSPATH') || exit;

if (!class_exists(PluginAdmin::class)) {
    class PluginAdmin
    {
        /** @var string[] */
        private const PENDING_STATUS_LIST = ['pending', 'on-hold', 'processing'];

        protected static ?self $instance = null;

        protected string $mainMenuId;

        protected string $author;

        protected bool $isRegistered;

        private static ?Logger $logger = null;

        public function __construct()
        {
            $this->mainMenuId = 'wc-camoo-pay';
            $this->author = 'wordpress@camoo.sarl';
            $this->isRegistered = false;
        }

        public static function instance(): ?self
        {
            if (self::$instance === null) {
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

            add_filter(
                'manage_woocommerce_page_wc-orders_columns',
                [__CLASS__, 'extend_order_view_for_camoo_pay'],
                10
            );
            add_action(
                'manage_woocommerce_page_wc-orders_custom_column',
                [__CLASS__, 'get_extended_order_value'],
                25,
                2
            );
            add_filter(
                'woocommerce_admin_order_actions',
                [__CLASS__, 'add_camoo_pay_custom_order_status_actions_button'],
                50,
                2
            );
            add_action('wp_ajax_wc_camoo_pay_mark_order_status', [__CLASS__, 'verifyCamooPayStatus']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_camoo_pay_css_scripts']);

            add_action(
                'woocommerce_admin_order_data_after_order_details',
                [__CLASS__, 'display_camoo_pay_fee_in_order_details'],
                10,
                1
            );

            // Upload icons into media library on first admin hit
            add_action('admin_init', static fn () => (new Media())->upload_image_to_media_library());
        }

        public static function display_camoo_pay_fee_in_order_details($order): void
        {
            if (!$order instanceof WC_Order) {
                return;
            }

            $camooPayFee = $order->get_meta(MetaKeysEnum::PAYMENT_FEE->value, true);

            if ($camooPayFee !== '' && $camooPayFee !== null) {
                echo '<p class="form-field form-field-wide">
                    <label for="wc_camoo_pay_fee">
                        <strong>' .
                    esc_html__('CamooPay Fee', 'camoo-pay-for-ecommerce') . ':
                        </strong> ' .
                    esc_html(self::camoo_pay_fee_format((float)$camooPayFee)) .
                    '</label></p>';
            }

            $mobileMoneyNumber = $order->get_meta(MetaKeysEnum::BUYER_MOBILE_MONEY_NUMBER->value, true);
            if ($mobileMoneyNumber !== '' && $mobileMoneyNumber !== null) {
                echo '<p class="form-field form-field-wide">
                    <label for="wc_camoo_pay_mobile_money_number">
                        <strong>' .
                    esc_html__('Buyer Mobile money number', 'camoo-pay-for-ecommerce') . ':
                        </strong> ' .
                    esc_html($mobileMoneyNumber) .
                    '</label></p>';
            }
        }

        public static function verifyCamooPayStatus(): void
        {
            if (
                !current_user_can('edit_shop_orders') ||
                !check_admin_referer('woocommerce_camoo_pay_check_status') ||
                !isset($_GET['status'], $_GET['order_id'])
            ) {
                wp_safe_redirect(self::getRedirectUrl());
                exit;
            }

            $orderId = sanitize_text_field(wp_unslash($_GET['order_id']));

            $status = sanitize_text_field(wp_unslash($_GET['status']));
            $orderId = absint($orderId);

            if ($status !== 'check' || !$orderId) {
                wp_safe_redirect(self::getRedirectUrl());
                exit;
            }

            /** @var WC_Order|WC_Order_Refund|false $order */
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order || !$order->has_status(self::PENDING_STATUS_LIST)) {
                wp_safe_redirect(self::getRedirectUrl());
                exit;
            }

            WC()->payment_gateways();
            $settings = get_option('woocommerce_' . Plugin::WC_CAMOO_PAY_GATEWAY_ID . '_settings', []);

            $consumerKey = sanitize_text_field($settings['camoo_pay_key'] ?? '');
            $consumerSecret = sanitize_text_field($settings['camoo_pay_secret'] ?? '');

            if ($consumerKey === '' || $consumerSecret === '') {
                wc_add_wp_error_notices(new WP_Error('camoo_pay_missing_credentials', __('Missing API credentials', 'camoo-pay-for-ecommerce')));
                wp_safe_redirect(self::getRedirectUrl());
                exit;
            }

            $client = Client::create($consumerKey, $consumerSecret);
            $paymentApi = new PaymentApi($client);
            $ptn = $order->get_meta(MetaKeysEnum::CAMOO_PAYMENT_TRANSACTION_ID->value);

            if ($ptn) {
                try {
                    $verify = $paymentApi->verify((string)$ptn);
                } catch (Throwable $e) {
                    self::getLogger()->error(__FILE__, __LINE__, $e->getMessage());
                    $verify = null;
                    wc_add_wp_error_notices(
                        new WP_Error('camoo_pay_verify_error', __('Error while verifying payment status', 'camoo-pay-for-ecommerce'))
                    );
                }

                if ($verify !== null) {
                    $merchantTransactionId = $order->get_meta(
                        MetaKeysEnum::PAYMENT_MERCHANT_TRANSACTION_ID->value
                    );

                    Plugin::processWebhookStatus(
                        $order,
                        $verify->status,
                        (string)$merchantTransactionId,
                        $verify
                    );
                }
            }

            wp_safe_redirect(self::getRedirectUrl());
            exit;
        }

        private static function getRedirectUrl(): string
        {
            return wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
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

        public static function add_camoo_pay_custom_order_status_actions_button(array $actions, $order): array
        {
            if (!$order instanceof WC_Order) {
                return $actions;
            }

            if ($order->get_payment_method() !== Plugin::WC_CAMOO_PAY_GATEWAY_ID) {
                self::getLogger()->debug(
                    __FILE__,
                    __LINE__,
                    'Not using CamooPay gateway for order ' . wp_unslash((string)$order->get_id())
                );

                return $actions;
            }

            if (!$order->has_status(self::PENDING_STATUS_LIST)) {
                self::getLogger()->debug(
                    __FILE__,
                    __LINE__,
                    'Order ' . wp_unslash((string)$order->get_id()) . ' does not have a pending status'
                );

                return $actions;
            }

            $orderId = $order->get_id();
            $actions['check'] = [
                'url' => wp_nonce_url(
                    admin_url(
                        'admin-ajax.php?action=wc_camoo_pay_mark_order_status&status=check&order_id='
                        . absint(wp_unslash((string)$orderId))
                    ),
                    'woocommerce_camoo_pay_check_status'
                ),
                'name' => __('Check status', 'camoo-pay-for-ecommerce'),
                'title' => __('Check remote order status', 'camoo-pay-for-ecommerce'),
                'action' => 'check',
            ];

            self::getLogger()->debug(
                __FILE__,
                __LINE__,
                'Check Status Button Added for Order ID ' . wp_unslash((string)$order->get_id())
            );

            return $actions;
        }

        /** Display the admin page */
        public function display(): void
        {
            echo 'CamooPay for e-commerce';
        }

        public static function extend_order_view_for_camoo_pay($columns): array
        {
            self::getLogger()->debug(__FILE__, __LINE__, 'Extending order view for CamooPay');

            $newColumns = is_array($columns) ? $columns : [];
            self::getLogger()->debug(__FILE__, __LINE__, wp_json_encode($newColumns));

            if (!isset($columns['wc_actions'])) {
                return $newColumns;
            }

            // Keep the original actions column at the end
            $wcActions = $columns['wc_actions'];
            unset($newColumns['wc_actions']);

            $newColumns['camoo_pay_merchant_reference_id'] = __('CamooPay Reference ID', 'camoo-pay-for-ecommerce');
            $newColumns['camoo_pay_order_transaction_id'] = __('CamooPay Transaction ID', 'camoo-pay-for-ecommerce');
            $newColumns['camoo_pay_fee'] = __('CamooPay Fee', 'camoo-pay-for-ecommerce');

            $newColumns['wc_actions'] = $wcActions;

            return $newColumns;
        }

        public static function get_extended_order_value(string $column, $order): void
        {
            if (!$order instanceof WC_Order) {
                return;
            }

            if ($column === 'camoo_pay_merchant_reference_id') {
                $merchantTransactionId = $order->get_meta(
                    MetaKeysEnum::PAYMENT_MERCHANT_TRANSACTION_ID->value,
                    true
                );
                echo esc_html($merchantTransactionId ?? '');
            }

            if ($column === 'camoo_pay_order_transaction_id') {
                $ptn = $order->get_meta(MetaKeysEnum::CAMOO_PAYMENT_TRANSACTION_ID->value, true);
                echo esc_html($ptn ?? '');
            }

            if ($column === 'camoo_pay_fee') {
                $camooPayFee = $order->get_meta(MetaKeysEnum::PAYMENT_FEE->value, true);
                if ($camooPayFee === '' || $camooPayFee === null) {
                    echo esc_html('N/A');

                    return;
                }

                echo esc_html(self::camoo_pay_fee_format((float)$camooPayFee));
            }
        }

        private static function getLogger(): Logger
        {
            if (self::$logger === null) {
                self::$logger = new Logger(Plugin::WC_CAMOO_PAY_GATEWAY_ID, WP_DEBUG);
            }

            return self::$logger;
        }

        private static function camoo_pay_fee_format(float $amount): string
        {
            return sprintf(
                '%s %s',
                number_format($amount, 0, ',', ' '),
                'FCFA'
            );
        }
    }
}

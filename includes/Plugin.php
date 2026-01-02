<?php

/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Pay\WooCommerce;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Camoo\Pay\WooCommerce\Admin\Enum\MetaKeysEnum;
use Camoo\Pay\WooCommerce\Admin\PluginAdmin;
use Camoo\Payment\Api\PaymentApi;
use Camoo\Payment\Enum\Status;
use Camoo\Payment\Http\Client;
use Camoo\Payment\Models\Payment;
use Throwable;
use WC_Geolocation;
use WC_Order;
use WC_Order_Query;
use WC_Order_Refund;
use WP_REST_Request;

defined('ABSPATH') || exit;
if (!class_exists(Plugin::class)) {
    class Plugin
    {
        public const WC_CAMOO_PAY_DB_VERSION = '1.0.8';

        public const DEFAULT_TITLE = 'CamooPay';

        public const WC_CAMOO_PAY_GATEWAY_ID = 'wc_camoo_pay';

        private const DEFAULT_COUNTRY_CODE = '237';

        protected $id;

        protected $mainMenuId;

        protected $adapterName;

        protected $title;

        protected $description;

        protected $optionKey;

        protected $settings;

        protected $adapterFile;

        protected $pluginPath;

        protected $version;

        private static ?Logger\Logger $logger = null;

        public function __construct($pluginPath, $adapterName, $adapterFile, $description = '', $version = null)
        {
            $this->id = basename($pluginPath, '.php');

            $this->pluginPath = $pluginPath;
            $this->description = $description;
            $this->adapterName = $adapterName;
            $this->adapterFile = $adapterFile;
            $this->version = $version;
            $this->optionKey = '';
            $this->settings = [
                'live' => '1',
                'accountId' => '',
                'apiKey' => '',
                'notifyForStatus' => [],
                'completeOrderForStatuses' => [],
            ];

            $this->mainMenuId = 'admin.php';
            $this->title = 'CamooPay for e-commerce - Payment Gateway for WooCommerce';
        }

        public function register(): void
        {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once __DIR__ . '/Install.php';
            require_once __DIR__ . '/admin/Enum/MetaKeysEnum.php';
            require_once __DIR__ . '/Logger/Logger.php';
            require_once __DIR__ . '/admin/Enum/MediaEnum.php';
            require_once __DIR__ . '/Media.php';

            if (!is_plugin_active('woocommerce/woocommerce.php')) {

                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>'
                        . esc_html__('WooCommerce is not enabled. Please enable WooCommerce to use CamooPay for WooCommerce.', 'camoo-pay-for-ecommerce')
                        . '</p></div>';
                });

                return;
            }

            register_activation_hook($this->pluginPath, [Install::class, 'install']);

            add_filter(
                'plugin_action_links_' . plugin_basename($this->pluginPath),
                [$this, 'onPluginActionLinks'],
                1,
                1
            );

            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_camoo_pay_css_scripts']);
            register_deactivation_hook($this->pluginPath, [$this, 'route_status_plugin_deactivate']);
            add_action('before_woocommerce_init', [__CLASS__, 'camoo_pay_hpos_compatibility']);
            add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_extensions']);
            add_action(
                'woocommerce_store_api_checkout_update_order_from_request',
                [$this, 'manage_api_order_from_request'],
                10,
                2
            );

            if (is_admin()) {
                PluginAdmin::instance()->register();
            }
        }

        public function manage_api_order_from_request(WC_Order $order, WP_REST_Request $request): void
        {
            $payment_data = $request->get_param('payment_data') ?? [];

            foreach ($payment_data as $item) {
                if (
                    isset($item['key'], $item['value']) &&
                    $item['key'] === 'camoo_pay_phone_number'
                ) {
                    $order->update_meta_data(
                        MetaKeysEnum::BUYER_MOBILE_MONEY_NUMBER->value,
                        sanitize_text_field($item['value'])
                    );
                }
            }
        }

        public function register_store_api_extensions(): void
        {
            if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
                return;
            }

            woocommerce_store_api_register_endpoint_data(
                [
                    'endpoint' => CheckoutSchema::IDENTIFIER,
                    'namespace' => 'camoo_pay',
                    'schema_callback' => static fn (): array => [
                        'phone_number' => [
                            'description' => __('Mobile Money phone number', 'camoo-pay-for-ecommerce'),
                            'type' => 'string',
                            'required' => true,
                        ],
                    ],

                ]
            );
        }

        public static function camoo_pay_hpos_compatibility(): void
        {

            if (!class_exists(FeaturesUtil::class)) {
                return;
            }

            FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                plugin_dir_path(__DIR__) . 'camoo-pay-for-ecommerce.php',
            );

        }

        public function route_status_plugin_deactivate(): void
        {
            flush_rewrite_rules();
        }

        public static function enqueue_block_camoo_pay_css_scripts(): void
        {
            wp_enqueue_style(
                'camoo_pay_style',
                plugins_url('/assets/css/style.css', __FILE__),
                [],
                self::WC_CAMOO_PAY_DB_VERSION
            );

            if (
                !function_exists('is_checkout') ||
                !is_checkout() ||
                !function_exists('WC')
            ) {
                return;
            }

            wp_register_script(
                'camoo-pay-blocks',
                plugins_url('/assets/js/index.js', __FILE__),
                ['wc-blocks-registry', 'wp-element', 'wp-i18n'],
                self::WC_CAMOO_PAY_DB_VERSION,
                true
            );

            wp_enqueue_script('camoo-pay-blocks');
        }

        public function onAddGatewayClass($gateways)
        {
            $gateways[] = WC_CamooPay_Gateway::class;

            return $gateways;
        }

        public function onInit(): void
        {
            $this->loadGatewayClass();
            self::$logger->initLogger();

            $this->title = __('CamooPay for e-commerce - Payment Gateway for WooCommerce', 'camoo-pay-for-ecommerce');
            $this->description = sprintf(
                '%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
                __('CamooPay for e-commerce payment gateway', 'camoo-pay-for-ecommerce'),
                'https://www.camoo.cm/#camoo-pay',
                __('Do you have any questions or requests?', 'camoo-pay-for-ecommerce'),
                'https://github.com/camoo/camoo-pay-for-ecommerce',
                __('Do you like our plugin and can recommend to others.', 'camoo-pay-for-ecommerce')
            );

            /**
             * The hook camoo_pay_order_status_changed can be used during order status change.
             *
             * Example usage:
             *
             *     // The action callback function.
             *     Function example_on_change_status_callback( $order, $status) {
             *         // (maybe) do something for the $order and $status info depending on your logic.
             *     }
             *
             *     add_action('camoo_pay_order_status_changed', 'example_on_change_status_callback', 10, 2 );
             *
             *     /*
             *      * Trigger the actions by calling the 'example_on_change_status_callback()' function
             *      * that's hooked onto `camoo_pay_order_status_changed`.
             *
             *      * @param WC_Order $order The WooCommerce order object.
             *      * @param Status $status The new status of the order.
             *      * @param string|null $merchantReferenceId The merchant reference ID associated with the order (if available).
             *      * @param Payment|null $payment The payment object associated with the order (if available).
             *
             *      * @return void
             *
             * @since 1.0.8
             */
            add_action('camoo_pay_order_status_changed', [$this, 'onCamooPayStatusChanged'], 10, 4);

            add_action('rest_api_init', [$this, 'notification_route']);
            add_filter('woocommerce_payment_gateways', [$this, 'onAddGatewayClass']);
        }

        public function notification_route(): void
        {
            register_rest_route(
                'wc-camoo-pay',
                '/notification',
                [
                    'methods' => 'GET',
                    'callback' => [new WC_CamooPay_Gateway(), 'onNotification'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'status' => [
                            'required' => true,
                            'validate_callback' => function ($param) {
                                return in_array($param, array_map(fn ($status) => strtolower($status->value), Status::cases()));
                            },
                        ],
                        'trx' => [
                            'required' => true,
                            'validate_callback' => 'wp_is_uuid',
                        ],
                        'status_time' => [
                            'required' => false,
                            'validate_callback' => 'is_string',
                        ],
                        'payment_id' => [
                            'required' => false,
                            'validate_callback' => 'is_string',
                        ],
                    ],
                ],
            );

            flush_rewrite_rules();
        }

        public function onPluginActionLinks($links)
        {
            $link = sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_camoo_pay'),
                __('Settings', 'camoo-pay-for-ecommerce')
            );
            array_unshift($links, $link);

            return $links;
        }

        public function loadGatewayClass(): void
        {
            if (class_exists('\\Camoo\\Pay\\WooCommerce\\' . $this->adapterName)) {
                return;
            }

            include_once dirname(__DIR__) . '/vendor/autoload.php';

            include_once dirname(__DIR__) . '/includes/Gateway.php';
            self::$logger = new Logger\Logger(self::WC_CAMOO_PAY_GATEWAY_ID, WP_DEBUG);
        }

        public static function get_webhook_url($endpoint): string
        {
            if (get_option('permalink_structure')) {
                return trailingslashit(get_home_url()) . 'wp-json/wc-camoo-pay/' . sanitize_text_field($endpoint);
            }

            return add_query_arg(
                'rest_route',
                '/wc-camoo-pay/' . sanitize_text_field($endpoint),
                trailingslashit(get_home_url())
            );
        }

        public static function getPaymentHistoryByReferenceId(string $merchantReferenceId): array|null|object
        {
            if (!wp_is_uuid(sanitize_text_field($merchantReferenceId))) {
                self::$logger?->debug(__FILE__, __LINE__, 'Invalid merchant reference ID: ' .
                    esc_html($merchantReferenceId));

                return null;
            }

            // Create a query to find orders by the custom meta-key
            $args = [
                'limit' => 1,
                'meta_query' => [
                    [
                        'key' => MetaKeysEnum::PAYMENT_MERCHANT_TRANSACTION_ID->value,
                        'value' => sanitize_text_field($merchantReferenceId),
                        'compare' => '=',
                    ],
                ],
            ];

            // Run the query
            $order_query = new WC_Order_Query($args);
            try {
                $orders = $order_query->get_orders();
            } catch (Throwable) {
                self::$logger?->debug(
                    __FILE__,
                    __LINE__,
                    'Error while querying orders for merchant reference ID: ' . esc_html($merchantReferenceId)
                );

                $orders = null;
            }

            // Since the merchantReferenceId is unique, we expect only one result
            if (!empty($orders)) {
                // Get the first order (the only one)
                return $orders[0];
            }

            self::$logger?->debug(__FILE__, __LINE__, 'No order found for merchant reference ID: ' .
                esc_html($merchantReferenceId));

            return null;  // Return null if no matching order is found
        }

        public static function getLanguageKey(): string
        {
            $local = sanitize_text_field(get_locale());
            if (empty($local)) {
                return 'fr';
            }

            $localExploded = explode('_', $local);

            $lang = $localExploded[0];

            return in_array($lang, ['fr', 'en']) ? $lang : 'en';
        }

        public static function processWebhookStatus(
            mixed $order,
            string $status,
            string $merchantReferenceId,
            ?Payment $payment = null
        ): void {

            $current = $order->get_meta(MetaKeysEnum::PAYMENT_ORDER_STATUS->value);

            if ($current === sanitize_title($status)) {
                return;
            }

            $enumStatus = Status::from(strtoupper($status));
            match ($enumStatus) {
                Status::IN_PROGRESS, Status::CREATED, Status::INITIALISED, Status::PENDING, Status::UNDERINVESTIGATION => do_action(
                    'camoo_pay_order_status_changed',
                    $order,
                    $enumStatus,
                    $merchantReferenceId
                ),
                Status::CONFIRMED, Status::SUCCESS => do_action(
                    'camoo_pay_order_status_changed',
                    $order,
                    Status::CONFIRMED,
                    $merchantReferenceId,
                    $payment
                ),
                Status::CANCELED => do_action(
                    'camoo_pay_order_status_changed',
                    $order,
                    Status::CANCELED,
                    $merchantReferenceId
                ),
                Status::FAILED, Status::ERRORED => do_action(
                    'camoo_pay_order_status_changed',
                    $order,
                    Status::FAILED,
                    $merchantReferenceId
                )
            };

        }

        public static function cleanUpPhone(string $number): string
        {
            /** @var string $phone */
            $phone = wc_clean(wp_unslash($number));

            return preg_replace('/\D/', '', $phone);
        }

        public static function anonymizePhoneNumber(string $number, ?string $phoneCountryCode = null): string
        {
            $phoneCountryCode = $phoneCountryCode ?: self::DEFAULT_COUNTRY_CODE;
            $cleanedNumber = self::cleanUpPhone($number);
            $pattern = '/^(\+' . $phoneCountryCode . '|' . $phoneCountryCode . '|00' . $phoneCountryCode . ')?/';
            $number = preg_replace($pattern, '', $cleanedNumber);

            return substr($number, 0, 1) . str_repeat('*', strlen($number) - 3) . substr($number, -2);
        }

        public function onCamooPayStatusChanged(
            WC_Order $order,
            Status $status,
            ?string $merchantReferenceId = null,
            ?Payment $payment = null,
        ): void {
            if ($order->get_payment_method() !== self::WC_CAMOO_PAY_GATEWAY_ID) {
                return;
            }

            self::$logger?->info(
                __FILE__,
                __LINE__,
                sprintf(
                    'CamooPay order %d transitioned to %s',
                    $order->get_id(),
                    $status->value
                )
            );

            match ($status) {
                Status::FAILED => self::processWebhookFailed($order, $merchantReferenceId),
                Status::CONFIRMED => self::processWebhookConfirmed($order, $merchantReferenceId, $payment),
                Status::CANCELED => self::processWebhookCanceled($order, $merchantReferenceId),
                default => self::processWebhookProgress($order, $merchantReferenceId, $status),
            };
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookConfirmed(
            mixed $order,
            string $merchantReferenceId,
            ?Payment $payment = null
        ): void {
            $order->update_status('completed');
            wc_reduce_stock_levels($order->get_id());

            $consumerKey = sanitize_text_field(get_option('camoo_pay_key') ?? '');
            $consumerSecret = sanitize_text_field(get_option('camoo_pay_secret') ?? '');
            $client = Client::create($consumerKey, $consumerSecret);
            $paymentApi = new PaymentApi($client);

            $ptn = $order->get_meta(MetaKeysEnum::PAYMENT_MERCHANT_TRANSACTION_ID->value);

            try {
                $verifyPayment = $payment ?? $paymentApi->verify($ptn);
            } catch (Throwable) {
                $order->add_order_note(
                    __('CamooPay payment cannot be confirmed', 'camoo-pay-for-ecommerce'),
                    true
                );
            }

            $fees = $verifyPayment?->fees ?? null;
            $order->add_order_note(__('CamooPay payment completed', 'camoo-pay-for-ecommerce'), true);
            self::applyStatusChange(Status::CONFIRMED, $order, $merchantReferenceId, $fees);
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookProgress(
            mixed $order,
            string $merchantReferenceId,
            Status $realStatus
        ): void {
            $currentStatus = $order->get_status();
            if ($currentStatus === 'completed') {
                return;
            }
            $order->update_status('pending');
            self::applyStatusChange($realStatus, $order, $merchantReferenceId);
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookCanceled(mixed $order, string $merchantReferenceId): void
        {
            $order->update_status('cancelled');
            $order->add_order_note(__('CamooPay payment cancelled', 'camoo-pay-for-ecommerce'), true);
            self::applyStatusChange(Status::CANCELED, $order, $merchantReferenceId);
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookFailed(mixed $order, string $merchantReferenceId): void
        {
            $order->update_status('failed');
            $order->add_order_note(__('CamooPay payment failed', 'camoo-pay-for-ecommerce'), true);
            self::applyStatusChange(Status::FAILED, $order, $merchantReferenceId);
        }

        private static function applyStatusChange(
            Status $status,
            mixed $order,
            ?string $merchantReferenceId = null,
            ?float $fees = null,
        ): void {

            /** @var bool|WC_Order|WC_Order_Refund $order */
            if (empty($order)) {
                self::$logger?->debug(__FILE__, __LINE__, 'No order found for merchant reference ID: ' .
                    esc_html($merchantReferenceId ?? ''));

                return;
            }

            $remoteIp = WC_Geolocation::get_ip_address();

            $order->update_meta_data(MetaKeysEnum::PAYMENT_ORDER_STATUS->value, sanitize_title($status->value));
            $order->update_meta_data(MetaKeysEnum::PAYMENT_NOTIFIED_AT->value, current_time('mysql'));
            if ($fees) {
                $order->update_meta_data(MetaKeysEnum::PAYMENT_FEE->value, $fees);
            }
            if ($remoteIp) {
                $order->update_meta_data(MetaKeysEnum::PAYMENT_CONFIRM_ADR->value, sanitize_text_field($remoteIp));
            }
            $order->save();

            /**
             * Executes the hook camoo_pay_after_status_change where ever it's defined.
             *
             * Example usage:
             *
             *     // The action callback function.
             *     Function example_callback( $id, $shopType) {
             *         // (maybe) do something with the args.
             *     }
             *
             *     add_action('camoo_pay_after_status_change', 'example_callback', 10, 2 );
             *
             *     /*
             *      * Trigger the actions by calling the 'example_callback()' function
             *      * that's hooked onto `camoo_pay_after_status_change`.
             *
             *      * - $id is either the WooCommerce orderId
             *      * - $shopType is the shop invoked actually the hook
             *
             * @since 1.0
             */
            do_action('camoo_pay_after_status_change', sanitize_text_field($order->get_id()), 'wc');
        }
    }
}

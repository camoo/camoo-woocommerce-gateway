<?php

namespace Camoo\Pay\WooCommerce;

use Camoo\Pay\WooCommerce\Admin\Enum\MetaKeysEnum;
use Camoo\Payment\Api\AccountApi;
use Camoo\Payment\Api\PaymentApi;
use Camoo\Payment\Enum\Status;
use Camoo\Payment\Http\Client;
use Camoo\Payment\Models\Account;
use Camoo\Payment\Models\Payment;
use Exception;
use Throwable;

use function wc_add_notice;

use WC_Geolocation;
use WC_Order;
use WC_Payment_Gateway;
use WP_REST_Response;

defined('ABSPATH') || exit;

class WC_CamooPay_Gateway extends WC_Payment_Gateway
{
    private string $consumerKey;

    private string $consumerSecret;

    private ?string $instructions;

    private bool $testMode;

    private Logger\Logger $logger;

    public function __construct()
    {
        $this->icon = null;
        $this->has_fields = true;
        $this->id = Plugin::WC_CAMOO_PAY_GATEWAY_ID;

        $this->init_settings();
        $this->init_form_fields();

        $this->title = esc_html($this->get_option('title'));
        $this->description = esc_html($this->get_option('description'));
        $this->method_title = esc_html($this->get_option('method_title'));
        $this->method_description = esc_html($this->get_option('description'));
        $this->enabled = sanitize_text_field($this->get_option('enabled'));
        $this->testMode = 'yes' === sanitize_text_field($this->get_option('test_mode'));
        $this->instructions = esc_html($this->get_option('instructions'));

        $this->consumerKey = sanitize_text_field($this->get_option('camoo_pay_key') ?? '');
        $this->consumerSecret = sanitize_text_field($this->get_option('camoo_pay_secret') ?? '');
        $this->registerHooks();

        $this->logger = new Logger\Logger($this->id, WP_DEBUG || $this->testMode);
    }

    public function init_form_fields()
    {
        $wc_camoo_pay_settings = [
            'enabled' => [
                'title' => esc_html__('Enable/Disable', 'camoo-pay-for-ecommerce'),
                'label' => esc_html__('Enable CamooPay for e-commerce Payment', 'camoo-pay-for-ecommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => esc_html__('Title', 'camoo-pay-for-ecommerce'),
                'type' => 'text',
                'description' => esc_html__('This controls the title which the user sees during checkout.', 'camoo-pay-for-ecommerce'),
                'default' => esc_html__('CamooPay for e-commerce Payment.', 'camoo-pay-for-ecommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => esc_html__('Description', 'camoo-pay-for-ecommerce'),
                'type' => 'textarea',
                'description' => esc_html__(
                    'This controls the description which the user sees during checkout.',
                    'camoo-pay-for-ecommerce'
                ),
                'default' => esc_html__(
                    'Accept payments via Mobile Money with CamooPay, the e-commerce payment gateway.',
                    'camoo-pay-for-ecommerce'
                ),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => esc_html__('Instructions', 'camoo-pay-for-ecommerce'),
                'type' => 'textarea',
                'description' => esc_html__('Instructions that will be added to the thank you page and emails.', 'camoo-pay-for-ecommerce'),
                'default' => esc_html__('Secured Payment with CamooPay for e-commerce.', 'camoo-pay-for-ecommerce'),
                'desc_tip' => true,
            ],
            'camoo_pay_currency' => [
                'title' => esc_html__('Currency', 'camoo-pay-for-ecommerce'),
                'label' => esc_html__('CamooPay Currency', 'camoo-pay-for-ecommerce'),
                'type' => 'select',
                'description' => esc_html__('Define the currency to place your payments', 'camoo-pay-for-ecommerce'),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', 'camoo-pay-for-ecommerce')],
                'desc_tip' => true,
            ],
            'api_details' => [
                'title' => esc_html__('API credentials', 'camoo-pay-for-ecommerce'),
                'type' => 'title',
                'description' => wp_kses(
                    esc_attr__(
                        'Enter your CamooPay for e-commerce API credentials to process Payments via CamooPay for e-commerce. Learn how to access your ',
                        'camoo-pay-for-ecommerce'
                    ) .
                    '<a href="' . esc_url('https://camoo.cm/faq/') . '" target="_blank" rel="noopener noreferrer">' .
                    esc_attr__('CamooPay for e-commerce API Credentials', 'camoo-pay-for-ecommerce') . '</a>',
                    [
                        'a' => [
                            'href' => true,
                            'target' => true,
                            'rel' => true,
                        ],
                    ]
                ),
            ],
            'camoo_pay_key' => [
                'title' => esc_html__('Consumer Key', 'camoo-pay-for-ecommerce'),
                'type' => 'text',
                'description' => esc_html__('Get your API Consumer Key from CamooPay for e-commerce.', 'camoo-pay-for-ecommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'camoo_pay_secret' => [
                'title' => esc_html__('Consumer Secret', 'camoo-pay-for-ecommerce'),
                'type' => 'password',
                'description' => esc_html__('Get your API Consumer Secret from CamooPay for e-commerce.', 'camoo-pay-for-ecommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
        ];
        $this->form_fields = apply_filters('wc_camoo_pay_settings', $wc_camoo_pay_settings);
    }

    public function validate_fields()
    {
        if ('yes' === $this->enabled) {
            $phone = sanitize_text_field(wp_unslash($_POST['camoo_pay_phone_number'] ?? ''));
            if (empty($phone)) {
                wc_add_notice(
                    esc_html__('Mobile Money number is required.', 'camoo-pay-for-ecommerce'),
                    'error'
                );

                return false;
            }

            if (!preg_match('/^\d{9}$/', $phone)) {
                wc_add_notice(esc_html__(
                    'Invalid Mobile Money number format. Please enter a valid 9-digit phone number.',
                    'camoo-pay-for-ecommerce'
                ), 'error');

                return false;
            }
        }

        return true;
    }

    /** Display the phone number field in the payment form. */
    public function payment_fields(): void
    {
        if ('yes' === $this->enabled) {
            // Display the phone number field only if CamooPay is selected
            echo '<div class="form-row form-row-wide validate-required validate-phone" style="margin-bottom: 20px;">
                <label for="camoo_pay_phone_number" style="font-size: 14px; font-weight: bold;">' .
                esc_html__('Mobile Money number', 'camoo-pay-for-ecommerce') .
                ' <span class="required" style="color: red;">*</span></label>
                <span class="woocommerce-input-wrapper" style="width: 100%; display: flex; justify-content: center;">
                <input type="number" class="input-text" name="camoo_pay_phone_number" id="camoo_pay_phone_number"
                autocomplete="tel"
                aria-required="true"
                autocapitalize="off"
                maxlength="9"
                placeholder="' . esc_html__('Enter your Mobile Money number', 'camoo-pay-for-ecommerce') . '" 
                style="width: 100%; padding: 10px; font-size: 14px; border-radius: 5px; border: 2px solid #ddd; background-color: #f9f9f9; margin-top: 8px;"/>
                </span>
              </div>';

            $icon_momo = plugin_dir_url(__FILE__) . 'assets/images/online_momo.png';
            // Add the image to emphasize the Mobile Money payment option, with tooltip
            echo '<div class="camoo-pay-image" style="text-align:center; margin-top:20px; margin-bottom: 20px;">
                <img src="' . esc_url($icon_momo) . '" alt="Mobile Money Payment" title="' . esc_attr__('Pay with Cameroon Orange or MTN Mobile Money', 'camoo-pay-for-ecommerce') . '" 
                style="max-width:80%; height:auto; border: 3px solid #50575e; border-radius: 15px; padding:5px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);" />
              </div>';
        }
    }

    public function process_admin_options(): bool
    {
        $postData = $this->get_post_data();
        $consumerKey = sanitize_text_field($postData['woocommerce_wc_camoo_pay_camoo_pay_key']);
        $consumerSecret = sanitize_text_field($postData['woocommerce_wc_camoo_pay_camoo_pay_secret']);
        $client = Client::create(trim($consumerKey), trim($consumerSecret));
        $accountApi = new AccountApi($client);
        $account = null;
        try {
            $account = $accountApi->get();
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());
            $this->add_error(__('Invalid API credentials', 'camoo-pay-for-ecommerce'));
        }

        $saved = false;
        if ($account instanceof Account) {
            $saved = parent::process_admin_options();
            $this->logger->info(
                __FILE__,
                __LINE__,
                __('CamooPay for WooCommerce setup successfully', 'camoo-pay-for-ecommerce')
            );

        } else {
            $this->logger->error(
                __FILE__,
                __LINE__,
                __(
                    'CamooPay for WooCommerce could not be setup. Please check your API credentials.',
                    'camoo-pay-for-ecommerce'
                )
            );
        }

        return $saved;
    }

    public function process_payment($order_id)
    {
        try {
            $wcOrder = $this->getWcOrder($order_id);
            $merchantReferenceId = wp_generate_uuid4();

            // Handle a guest checkout scenario (unlogged-in user)
            if (!is_user_logged_in()) {
                // For unlogged-in users, retrieve details from the payment form
                $postData = $this->get_post_data();
                $firstName = sanitize_text_field($postData['billing_first_name'] ?? '');
                $lastName = sanitize_text_field($postData['billing_last_name'] ?? '');
                $email = sanitize_email($postData['billing_email'] ?? '');
                $phoneNumber = sanitize_text_field($postData['billing_phone'] ?? '');

                // Update the WooCommerce order with guest details
                $wcOrder->set_billing_first_name($firstName);
                $wcOrder->set_billing_last_name($lastName);
                $wcOrder->set_billing_email($email);
                $wcOrder->set_billing_phone($phoneNumber);
            }

            // Prepare order data
            $orderData = $this->prepareOrderData($wcOrder, $merchantReferenceId);

            $orderData['shopping_cart_details'] = wp_json_encode($orderData['shopping_cart_details']);
            $payment = $this->placeOrder($orderData);
            $this->handleOrderResponse($wcOrder, $payment);

            $status = Status::tryFrom(strtoupper($payment->status));
            $fallbackStatus = $payment === null ? Status::FAILED : Status::IN_PROGRESS;
            $status = $status ?? $fallbackStatus;

            $wcOrder->update_meta_data(MetaKeysEnum::PAYMENT_MERCHANT_TRANSACTION_ID->value, sanitize_text_field($merchantReferenceId));
            $wcOrder->update_meta_data(MetaKeysEnum::PAYMENT_ORDER_STATUS->value, sanitize_text_field($status->value));

            // Add the notice if the payment is successful
            if ($payment !== null) {
                wc_add_notice(
                    __('Thank you for your order. Please check your phone for payment instructions.', 'camoo-pay-for-ecommerce'),
                    'notice'
                );
                $wcOrder->update_meta_data(MetaKeysEnum::CAMOO_PAYMENT_TRANSACTION_ID->value, $payment->id);
            }
            $wcOrder->save();

            // Return to site
            $returnUrl = get_permalink(wc_get_page_id('shop')) . '?trx=' . $merchantReferenceId . '&status=' . strtolower($status->value);

            return [
                'result' => $payment === null ? 'failure' : 'success',
                'redirect' => $returnUrl,
            ];
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());
            wc_add_notice($exception->getMessage(), 'error');

            return [];
        }
    }

    public function onNotification(): WP_REST_Response
    {
        $merchantReferenceId = sanitize_text_field(filter_input(INPUT_GET, 'trx'));

        if (empty($merchantReferenceId) || !wp_is_uuid(sanitize_text_field($merchantReferenceId))) {
            $this->logger->error(__FILE__, __LINE__, 'Invalid trx parameter: ' . $merchantReferenceId);

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request - Invalid trx parameter',
            ], 400);
        }

        $order = Plugin::getPaymentHistoryByReferenceId($merchantReferenceId);

        $orderId = (int)($order?->get_id() ?? 0);
        if (empty($orderId)) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Order Id not found');

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'CamooPay Bad notification Request',
            ], 400);
        }

        $status = filter_input(INPUT_GET, 'status');

        if (empty($status) || !in_array(
            sanitize_text_field($status),
            array_map(fn ($status) => strtolower($status->value), Status::cases())
        )) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Invalide status ' . $status);

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request - Invalid status parameter',
            ], 400);
        }

        $oldStatus = $order->get_status();
        Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);

        $this->logger->info(
            __FILE__,
            __LINE__,
            'onNotification:: status ' . $status . ' updates successfully'
        );

        return new WP_REST_Response([
            'status' => 'OK',
            'message' => sprintf('CamooPay Status Updated From %s To %s', $oldStatus, $order->get_status()),
        ], 200);
    }

    public function get_icon()
    {
        // Define the icon image path
        $icon_url = plugin_dir_url(__FILE__) . 'assets/images/camoo-pay.png';

        $icon_html = '<img width="144" height="40" src="' . esc_url($icon_url) . '" alt="' .
            esc_attr__('CamooPay for e-commerce acceptance mark', 'camoo-pay-for-ecommerce') . '"
              style="max-width:80%; height:auto; 
                     border: 3px solid #73d8fd00; border-radius: 10px;"
             />';

        // Apply filters for compatibility with WooCommerce
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }

    /**
     * By default, the gateway does not support refunds at the moment.
     */
    public function can_refund_order($order): bool
    {
        return false;
    }

    private function registerHooks(): void
    {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // ADD refund hook
    }

    private function getWcOrder(int $orderId): WC_Order
    {
        return wc_get_order(absint(wp_unslash($orderId)));
    }

    /**
     * @param array<string, mixed> $orderData
     *
     * @throws Exception
     */
    private function placeOrder(array $orderData): ?Payment
    {
        $client = Client::create($this->consumerKey, $this->consumerSecret);
        $paymentApi = new PaymentApi($client);

        try {
            $payment = $paymentApi->cashout($orderData);
        } catch (Exception $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());

            return null;
        }

        return $payment;
    }

    /**
     * Normalize the amount for XAF (FCFA BEAC) to ensure it's a valid multiple of 25,
     * but only adjust amounts that are not already divisible by 5.
     *
     * @param float $amount The amount to be normalized.
     *
     * @return float The normalized amount.
     */
    private function normalizeXafAmount(float $amount): float
    {
        // Check if the amount is divisible by 5
        if ($amount % 5 !== 0) {
            // Round the amount to the nearest multiple of 25
            return round($amount / 25) * 25;
        }

        // Return the amount as is if it's already divisible by 5
        return $amount;
    }

    private function cleanUpPhone(string $rawData): string
    {
        /** @var string $phone */
        $phone = wc_clean(wp_unslash($rawData));

        return preg_replace('/\D/', '', $phone);
    }

    /** @return array<string, mixed> */
    private function prepareOrderData(WC_Order $wcOrder, string $merchantReferenceId): array
    {
        $order_data = $wcOrder->get_data();
        $post_data = $this->get_post_data();
        $phoneNumber = $this->cleanUpPhone($post_data['camoo_pay_phone_number']);
        $orderData = [
            'external_reference' => $merchantReferenceId,
            'phone_number' => $phoneNumber,
            'amount' => $this->normalizeXafAmount((float)$order_data['total']),
            'notification_url' => Plugin::get_webhook_url('notification'),
            'shopping_cart_details' => [
                'user_browser_data' => [
                    'platform' => 'WooCommerce/' . wc()->version,
                    'ip' => WC_Geolocation::get_ip_address(),
                    'user_agent' => wc_get_user_agent(),
                    'php_version' => PHP_VERSION,
                ],
                'email' => $order_data['billing']['email'],
                'customerName' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
                'description' => __('Payment from', 'camoo-pay-for-ecommerce') . ' ' . get_bloginfo('name'),
                'currency' => sanitize_text_field($this->get_option('camoo_pay_currency')),
                'langKey' => Plugin::getLanguageKey(),
                'items' => [],
            ],
        ];

        foreach ($wcOrder->get_items() as $item) {
            $product = $item->get_product();
            $orderData['shopping_cart_details']['items'][] = [
                'itemId' => $item->get_id(),
                'particulars' => $item->get_name(),
                'unitCost' => (float)$product->get_price(),
                'subTotal' => (float)$item->get_subtotal(),
                'quantity' => $item->get_quantity(),
            ];
        }

        return $orderData;
    }

    private function handleOrderResponse(WC_Order $wcOrder, ?Payment $payment = null): void
    {
        if (null === $payment) {
            return;
        }
        $wcOrder->update_status('on-hold', __('Awaiting CamooPay payment confirmation', 'camoo-pay-for-ecommerce'));
        WC()->cart->empty_cart();

        $wcOrder->add_order_note(
            __('Your order is under process. Thank you!', 'camoo-pay-for-ecommerce'),
            true
        );
    }
}

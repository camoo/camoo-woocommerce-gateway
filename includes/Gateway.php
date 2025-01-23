<?php

namespace Camoo\Pay\WooCommerce;

use Camoo\Payment\Api\AccountApi;
use Camoo\Payment\Api\PaymentApi;
use Camoo\Payment\Enum\Status;
use Camoo\Payment\Http\Client;
use Camoo\Payment\Models\Account;
use Camoo\Payment\Models\Payment;
use Exception;
use Throwable;

use function wc_add_notice;

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

    /** @var Logger\Logger $logger */
    private $logger;

    public function __construct()
    {
        $this->id = Plugin::WC_CAMOO_PAY_GATEWAY_ID;
        $this->icon = null;
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = esc_html($this->get_option('title'));
        $this->method_title = esc_html($this->get_option('method_title'));
        $this->method_description = esc_html($this->get_option('description'));
        $this->enabled = sanitize_text_field($this->get_option('enabled'));
        $this->testMode = 'yes' === sanitize_text_field($this->get_option('test_mode'));
        $this->description = esc_html($this->get_option('description'));
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
                'title' => __('Enable/Disable', 'camoo-pay-for-ecommerce'),
                'label' => __('Enable CamooPay for e-commerce Payment', 'camoo-pay-for-ecommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('CamooPay for e-commerce Payment.', 'camoo-pay-for-ecommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __(
                    'This controls the description which the user sees during checkout.',
                    'woocommerce'
                ),
                'default' => __(
                    'Pay with your mobile phone via CamooPay for e-commerce payment gateway.',
                    'camoo-pay-for-ecommerce'
                ),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                'default' => __('Secured Payment with CamooPay for e-commerce', 'camoo-pay-for-ecommerce'),
                'desc_tip' => true,
            ],
            'test_mode' => [
                'title' => __('Test mode', 'camoo-pay-for-ecommerce'),
                'label' => __('Enable Test Mode', 'camoo-pay-for-ecommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'camoo-pay-for-ecommerce'),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'camoo_pay_currency' => [
                'title' => __('Currency', 'woocommerce'),
                'label' => __('CamooPay Currency', 'camoo-pay-for-ecommerce'),
                'type' => 'select',
                'description' => __('Define the currency to place your payments', 'camoo-pay-for-ecommerce'),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', 'camoo-pay-for-ecommerce')],
                'desc_tip' => true,
            ],
            'api_details' => [
                'title' => __('API credentials', 'woocommerce'),
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
                'title' => __('Consumer Key', 'camoo-pay-for-ecommerce'),
                'type' => 'text',
                'description' => __('Get your API Consumer Key from CamooPay for e-commerce.', 'camoo-pay-for-ecommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'camoo_pay_secret' => [
                'title' => __('Consumer Secret', 'camoo-pay-for-ecommerce'),
                'type' => 'password',
                'description' => __('Get your API Consumer Secret from CamooPay for e-commerce.', 'camoo-pay-for-ecommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
        ];
        $this->form_fields = apply_filters('wc_camoo_pay_settings', $wc_camoo_pay_settings);
    }

    public function validate_fields()
    {
        if (empty($_POST['billing_first_name'])) {
            wc_add_notice(esc_html__('First name is required!', 'camoo-pay-for-ecommerce'), 'error');

            return false;
        }

        if ('yes' === $this->enabled) {
            if (empty($_POST['camoo_pay_phone_number'])) {
                wc_add_notice(esc_html__('Mobile Money number is required.', 'camoo-pay-for-ecommerce'), 'error');

                return false;
            }

            if (!preg_match('/^\d{9}$/', sanitize_text_field($_POST['camoo_pay_phone_number']))) {
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
            echo '<div class="form-row form-row-wide validate-required validate-phone">
                    <label for="camoo_pay_phone_number">' . esc_html__('Mobile Money number', 'camoo-pay-for-ecommerce') .
                ' <span class="required">*</span></label>
                    <span class="woocommerce-input-wrapper">
                    <input type="number" class="input-text" name="camoo_pay_phone_number" id="camoo_pay_phone_number"
                    autocomplete="tel"
                    aria-required="true"
                     autocapitalize="off"
                     maxlength="9"
                    placeholder="' . esc_html__('Enter your Mobile Money number', 'camoo-pay-for-ecommerce') . '" />
                    </span>
                  </div>';
        }
    }

    public function process_admin_options(): bool
    {

        $postData = $this->get_post_data();
        $consumerKey = sanitize_text_field($postData['woocommerce_wc_camoo_pay_camoo_pay_key']);
        $consumerSecret = sanitize_text_field($postData['woocommerce_wc_camoo_pay_camoo_pay_secret']);
        $client = Client::create($consumerKey, $consumerSecret);
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
                __('CamooPay for wooCommerce setup successfully', 'camoo-pay-for-ecommerce')
            );

        } else {
            $this->logger->error(
                __FILE__,
                __LINE__,
                __('CamooPay for wooCommerce could not be setup', 'camoo-pay-for-ecommerce')
            );
        }

        return $saved;
    }

    public function process_payment($order_id)
    {
        try {
            $wcOrder = $this->getWcOrder($order_id);
            $merchantReferenceId = wp_generate_uuid4();
            $orderData = $this->prepareOrderData($wcOrder, $merchantReferenceId);

            $orderData['shopping_cart_details'] = wp_json_encode($orderData['shopping_cart_details']);
            $payment = $this->placeOrder($orderData);
            $this->handleOrderResponse($wcOrder, $merchantReferenceId, $payment);

            $status = Status::tryFrom(strtoupper($payment->status));

            $fallbackStatus = $payment === null ? Status::FAILED : Status::IN_PROGRESS;
            $status = $status ?? $fallbackStatus;

            // Only add the notice if the payment is successful
            if ($payment !== null) {
                wc_add_notice(
                    __(
                        'Veuillez suivre les instructions Mobile Money pour completer votre payment',
                        'camoo-pay-for-ecommerce'
                    ),
                    'notice'
                );
            }

            // Return to site
            $returnUrl = get_permalink(wc_get_page_id('shop')) . '?trx=' . $merchantReferenceId . '&status='
                . strtolower($status->value);

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

        $paymentHistory = Plugin::getPaymentHistoryByReferenceId($merchantReferenceId);

        $orderId = (int)$paymentHistory->wc_order_id;
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

        $order = wc_get_order($orderId);
        $oldStatus = '';
        if ($order) {
            $oldStatus = $order->get_status();
            Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);
        }

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
            esc_attr__('CamooPay for e-commerce acceptance mark', 'camoo-pay-for-ecommerce') . '" />';

        // Apply filters for compatibility with WooCommerce
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }

    protected function logCamooPayPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wc_camoo_pay_payments',
            [
                'wc_order_id' => absint(wp_unslash($orderId)),
                'order_transaction_id' => sanitize_text_field($orderTransactionId),
                'merchant_reference_id' => sanitize_text_field($merchantReferenceId),
            ]
        );
    }

    private function registerHooks(): void
    {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    private function getWcOrder(int $orderId): WC_Order
    {
        return wc_get_order(absint(wp_unslash($orderId)));
    }

    /** @throws Exception */
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
     * Normalize the amount for XAF (FCFA BEAC) to ensure it's a valid multiple of 25.
     *
     * @param float $amount The amount to be normalized.
     *
     * @return float The normalized amount.
     */
    private function normalizeXafAmount(float $amount): float
    {
        // Round the amount to the nearest multiple of 25
        return round($amount / 25) * 25;
    }

    private function prepareOrderData(WC_Order $wcOrder, string $merchantReferenceId): array
    {
        $order_data = $wcOrder->get_data();
        $post_data = $this->get_post_data();
        $phoneNumber = sanitize_text_field($post_data['camoo_pay_phone_number']);
        $orderData = [
            'external_reference' => $merchantReferenceId,
            'phone_number' => $phoneNumber,
            'amount' => $this->normalizeXafAmount((float)$order_data['total']),
            'notification_url' => Plugin::get_webhook_url('notification'),
            'shopping_cart_details' => [
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

    private function handleOrderResponse(WC_Order $wcOrder, string $merchantReferenceId, ?Payment $payment = null): void
    {
        if (null === $payment) {
            return;
        }
        $wcOrder->update_status('on-hold', __('Awaiting CamooPay payment confirmation', 'camoo-pay-for-ecommerce'));
        WC()->cart->empty_cart();
        $this->logCamooPayPayment(
            $wcOrder->get_id(),
            sanitize_text_field($merchantReferenceId),
            sanitize_text_field($payment->id)
        );

        $wcOrder->add_order_note(
            __('Your order is under process. Thank you!', 'camoo-pay-for-ecommerce'),
            true
        );
    }
}

<?php

declare(strict_types=1);

namespace Camoo\Pay\WooCommerce\Admin\Enum;

enum MetaKeysEnum: string
{
    case CAMOO_PAYMENT_TRANSACTION_ID = '_camoo_pay_transaction_id';
    case PAYMENT_ORDER_STATUS = '_camoo_pay_merchant_order_status';
    case PAYMENT_FEE = '_camoo_pay_merchant_fee';
    case PAYMENT_NOTIFIED_AT = '_camoo_pay_merchant_notified_at';
    case PAYMENT_MERCHANT_TRANSACTION_ID = '_camoo_pay_merchant_transaction_id';
    case PAYMENT_BUYER_IP = '_camoo_pay_buyer_ip';
}

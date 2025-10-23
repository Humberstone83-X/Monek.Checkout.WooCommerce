<?php
/**
 * Builds the payload for server-completed payments using the Monek Checkout SDK.
 *
 * @package Monek
 */
class MCWC_ServerCompletionPayloadBuilder
{
    /**
     * Build the payload that will be sent to the Checkout API when completing a payment.
     *
     * @param WC_Order $order The WooCommerce order being paid for.
     * @param string   $merchant_id The merchant identifier configured for the gateway.
     * @param string   $country_code ISO numeric country code associated with the merchant account.
     * @param string   $payment_token The token returned by the Checkout SDK.
     * @param string   $description Purchase description to attach to the payment.
     * @param array    $context Additional context returned by the Checkout SDK (optional).
     *
     * @return array
     */
    public function build(
        WC_Order $order,
        string $merchant_id,
        string $country_code,
        string $payment_token,
        string $description,
        array $context = []
    ): array {
        $amount_minor = MCWC_TransactionHelper::mcwc_convert_decimal_to_flat($order->get_total());
        $currency_code = MCWC_TransactionHelper::mcwc_get_iso4217_currency_code();

        $payload = [
            'merchantId' => $merchant_id,
            'paymentReference' => (string) $order->get_id(),
            'amount' => [
                'minor' => $amount_minor,
                'currencyCode' => $currency_code,
                'countryCode' => $country_code,
            ],
            'description' => $description,
            'paymentToken' => $payment_token,
            'idempotencyKey' => $this->get_idempotency_key($order),
            'metadata' => [
                'platform' => 'woocommerce',
                'pluginVersion' => mcwc_get_monek_plugin_version(),
                'orderKey' => $order->get_order_key(),
            ],
        ];

        $payload['cardholder'] = $this->build_cardholder($order);
        $shipping = $this->build_shipping($order);
        if (!empty($shipping)) {
            $payload['shipping'] = $shipping;
        }

        $basket = $this->build_basket($order);
        if (!empty($basket)) {
            $payload['basket'] = $basket;
        }

        if (!empty($context)) {
            $payload['context'] = $context;
        }

        return $payload;
    }

    /**
     * Generate a deterministic idempotency key for the order.
     */
    private function get_idempotency_key(WC_Order $order): string
    {
        $key = get_post_meta($order->get_id(), 'monek_idempotency_key', true);
        if (empty($key)) {
            $key = uniqid('monek_' . $order->get_id() . '_', true);
            update_post_meta($order->get_id(), 'monek_idempotency_key', $key);
        }

        return (string) $key;
    }

    /**
     * Build cardholder data structure from the order billing details.
     */
    private function build_cardholder(WC_Order $order): array
    {
        return [
            'name' => trim($order->get_formatted_billing_full_name()),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'billingAddress' => [
                'line1' => $order->get_billing_address_1(),
                'line2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
        ];
    }

    /**
     * Build shipping data structure from the order shipping details.
     */
    private function build_shipping(WC_Order $order): array
    {
        if (!$order->get_shipping_first_name() && !$order->get_shipping_last_name()) {
            return [];
        }

        return [
            'name' => trim($order->get_formatted_shipping_full_name()),
            'company' => $order->get_shipping_company(),
            'line1' => $order->get_shipping_address_1(),
            'line2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ];
    }

    /**
     * Build a basket representation mirroring the previous hosted checkout payload.
     */
    private function build_basket(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $line_total = $item->get_total();
            $line_tax = $item->get_total_tax();
            $quantity = $item->get_quantity();
            $unit_price = $quantity > 0 ? ($line_total / $quantity) : 0;

            $items[] = [
                'sku' => $product ? $product->get_sku() : '',
                'description' => MCWC_TransactionHelper::mcwc_trim_description($item->get_name()),
                'quantity' => $quantity,
                'unitPrice' => round((float) $unit_price, 2),
                'total' => round((float) ($line_total + $line_tax), 2),
                'taxAmount' => round((float) $line_tax, 2),
            ];
        }

        $delivery = [];
        if (($order->get_shipping_total() ?? 0) > 0) {
            $delivery = [
                'description' => $order->get_shipping_method(),
                'amount' => round((float) ($order->get_shipping_total() + $order->get_shipping_tax()), 2),
            ];
        }

        $discounts = [];
        if (($order->get_discount_total() ?? 0) > 0) {
            $discounts[] = [
                'description' => __('Discount', 'monek-checkout'),
                'amount' => round((float) $order->get_discount_total(), 2),
            ];
        }

        $basket = [
            'items' => $items,
        ];

        if (!empty($discounts)) {
            $basket['discounts'] = $discounts;
        }

        if (!empty($delivery)) {
            $basket['delivery'] = $delivery;
        }

        return $basket;
    }
}

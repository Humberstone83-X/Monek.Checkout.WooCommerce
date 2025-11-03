<?php

namespace Monek\Checkout\Application\Checkout;

use Automattic\WooCommerce\Blocks\Payments\PaymentContext;
use Monek\Checkout\Domain\Checkout\CheckoutRequest;

class CheckoutRequestFactory
{
    public function createFromPaymentContext(PaymentContext $context): CheckoutRequest
    {
        $paymentData = $this->sanitisePaymentData($context->payment_data ?? []);

        $gatewayId = isset($context->payment_method) ? (string) $context->payment_method : '';
        $mode = isset($paymentData['monek_mode']) ? (string) $paymentData['monek_mode'] : '';
        $token = isset($paymentData['monek_token']) ? (string) $paymentData['monek_token'] : '';
        $sessionIdentifier = isset($paymentData['monek_session']) ? (string) $paymentData['monek_session'] : '';
        $expiry = isset($paymentData['monek_expiry']) ? (string) $paymentData['monek_expiry'] : '';
        $paymentReference = isset($paymentData['monek_reference']) ? (string) $paymentData['monek_reference'] : '';

        return new CheckoutRequest(
            $gatewayId,
            $mode,
            $token,
            $sessionIdentifier,
            $expiry,
            $paymentReference
        );
    }

    private function sanitisePaymentData($rawPaymentData): array
    {
        if (! is_array($rawPaymentData)) {
            return [];
        }

        $sanitised = [];
        foreach ($rawPaymentData as $key => $value) {
            $sanitisedKey = sanitize_text_field((string) $key);
            $sanitised[$sanitisedKey] = is_string($value) ? wc_clean(wp_unslash($value)) : $value;
        }

        return $sanitised;
    }
}

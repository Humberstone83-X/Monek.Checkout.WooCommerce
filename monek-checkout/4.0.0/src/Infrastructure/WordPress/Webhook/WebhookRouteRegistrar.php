<?php

namespace Monek\Checkout\Infrastructure\WordPress\Webhook;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class WebhookRouteRegistrar
{
    public function register(): void
    {
        $this->log('rest_api_init fired');

        register_rest_route(
            'monek/v1',
            '/webhook',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleWebhook'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handleWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $paymentReference = $this->extractPaymentReference($body);
        if ($paymentReference === '') {
            $this->log('webhook: missing paymentReference', ['body' => $body], 'warning');
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_reference'], 400);
        }

        $orderId = $this->locateOrderByPaymentReference($paymentReference);
        if (! $orderId) {
            $this->log('webhook: order not found', ['reference' => $paymentReference], 'warning');
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'order_not_found',
                'reference' => $paymentReference,
            ], 404);
        }

        $order = wc_get_order($orderId);
        if (! $order) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'order_load_failed',
                'order_id' => $orderId,
            ], 500);
        }

        $order->update_meta_data('_monek_last_webhook', wp_json_encode($body));
        $targetStatus = 'payment-confirmed';

        if ($order->get_status() !== $targetStatus) {
            $order->update_status(
                $targetStatus,
                sprintf('Webhook set status to %s (ref: %s).', $targetStatus, $paymentReference)
            );
        } else {
            $order->add_order_note(sprintf('Webhook ping received (already %s). Ref: %s', $targetStatus, $paymentReference));
        }

        $order->save();

        $this->log('webhook: status updated', [
            'reference' => $paymentReference,
            'order_id' => $orderId,
            'status' => $order->get_status(),
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'reference' => $paymentReference,
            'order_id' => $orderId,
            'status' => $order->get_status(),
        ], 200);
    }

    private function extractPaymentReference(array $body): string
    {
        if (isset($body['paymentReference'])) {
            return (string) $body['paymentReference'];
        }

        if (isset($body['Data']['PaymentReference'])) {
            return (string) $body['Data']['PaymentReference'];
        }

        if (isset($body['reference'])) {
            return (string) $body['reference'];
        }

        return '';
    }

    private function locateOrderByPaymentReference(string $paymentReference): ?int
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => '_monek_payment_reference',
            'meta_value' => $paymentReference,
            'orderby' => 'date',
            'order' => 'DESC',
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
        ]);

        if (! is_array($orders) || $orders === []) {
            return null;
        }

        return (int) $orders[0];
    }

    private function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, '[monek] ' . $message, ['source' => 'monek-webhook'] + $context);
            return;
        }

        error_log('[monek] ' . $message . ($context ? ' ' . wp_json_encode($context) : ''));
    }
}

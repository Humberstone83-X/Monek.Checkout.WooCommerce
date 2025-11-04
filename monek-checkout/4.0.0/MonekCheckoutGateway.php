<?php

namespace Monek\Checkout\Infrastructure\WordPress\Gateway;

use Automattic\WooCommerce\Blocks\Payments\PaymentContext;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Monek\Checkout\Application\Checkout\CheckoutRequestFactory;
use Monek\Checkout\Application\Checkout\CurrencyFormatter;
use Monek\Checkout\Application\Checkout\ExpressCheckoutHandler;
use Monek\Checkout\Application\Checkout\PaymentPayloadBuilder;
use Monek\Checkout\Application\Checkout\PaymentProcessor;
use Monek\Checkout\Application\Checkout\StandardCheckoutHandler;
use Monek\Checkout\Application\Checkout\StoreContext;
use Monek\Checkout\Infrastructure\Logging\Logger;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

class MonekCheckoutGateway extends \WC_Payment_Gateway
{
    private Logger $logger;
    private CheckoutRequestFactory $checkoutRequestFactory;
    private ExpressCheckoutHandler $expressCheckoutHandler;
    private StandardCheckoutHandler $standardCheckoutHandler;
    private CurrencyFormatter $currencyFormatter;
    private StoreContext $storeContext;

    public function __construct()
    {
        $this->id = 'monek-checkout';
        $this->method_title = __('Monek Checkout', 'monek-checkout');
        $this->method_description = __('Accept payments using the embedded Monek checkout experience.', 'monek-checkout');
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->publishable_key = $this->get_option('publishable_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->show_express = $this->get_option('show_express', 'yes');
        $this->debug_mode = $this->get_option('debug', 'no');
        $this->svix_signing_secret = $this->get_option('svix_signing_secret');

        $this->logger = new Logger();
        $this->currencyFormatter = new CurrencyFormatter();
        $this->storeContext = new StoreContext();
        $paymentPayloadBuilder = new PaymentPayloadBuilder($this->currencyFormatter, $this->storeContext);
        $paymentProcessor = new PaymentProcessor(
            (string) $this->publishable_key,
            (string) $this->secret_key,
            $paymentPayloadBuilder,
            $this->logger
        );

        $this->checkoutRequestFactory = new CheckoutRequestFactory();
        $this->expressCheckoutHandler = new ExpressCheckoutHandler($this->logger);
        $this->standardCheckoutHandler = new StandardCheckoutHandler($paymentProcessor, $this->logger);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'blocks_process_payment'], 10, 2);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'monek-checkout'),
                'label' => __('Enable Monek Checkout', 'monek-checkout'),
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'monek-checkout'),
                'type' => 'text',
                'description' => __('Title shown to customers during checkout.', 'monek-checkout'),
                'default' => __('Monek Checkout', 'monek-checkout'),
            ],
            'description' => [
                'title' => __('Description', 'monek-checkout'),
                'type' => 'textarea',
                'description' => __('Optional message shown alongside the payment form.', 'monek-checkout'),
                'default' => __('Secure payment powered by Monek.', 'monek-checkout'),
            ],
            'publishable_key' => [
                'title' => __('Publishable key', 'monek-checkout'),
                'type' => 'text',
                'description' => __('Your public key used to initialise the embedded checkout.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'secret_key' => [
                'title' => __('Secret key', 'monek-checkout'),
                'type' => 'password',
                'description' => __('Server key used for completing payments from your server.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'svix_signing_secret' => [
                'title' => __('Svix signing secret', 'monek-checkout'),
                'type' => 'text',
                'description' => __('Paste the signing secret for your svix endpoint.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'show_express' => [
                'title' => __('Express checkout', 'monek-checkout'),
                'type' => 'checkbox',
                'label' => __('Display express wallets (Apple Pay, etc.) above the card form when available.', 'monek-checkout'),
                'default' => 'yes',
            ],
            'debug' => [
                'title' => __('Debug logging', 'monek-checkout'),
                'type' => 'checkbox',
                'label' => __('Enable verbose logging in the browser console.', 'monek-checkout'),
                'default' => 'no',
            ],
        ];
    }

    public function is_available(): bool
    {
        if ('yes' !== $this->get_option('enabled', 'yes')) {
            return false;
        }

        if (empty($this->publishable_key)) {
            return false;
        }

        return parent::is_available();
    }

    public function enqueue_scripts(): void
    {
        if (! is_checkout() || is_order_received_page()) {
            return;
        }

        if (! $this->is_available()) {
            return;
        }

        $blockDependencies = $this->collectBlockDependencies();
        $this->registerSdkScript();
        $this->registerCheckoutScript($blockDependencies);
        $this->registerStyles();

        wp_enqueue_script('monek-embedded-checkout');
        wp_enqueue_style('monek-embedded-checkout');

        if ('yes' === $this->debug_mode) {
            wp_add_inline_script(
                'monek-embedded-checkout',
                'console.log("[monek] deps present:", ' . wp_json_encode(array_values($blockDependencies)) . ');',
                'after'
            );
        }
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        echo '<div id="monek-checkout-wrapper" class="monek-checkout-wrapper" data-loading="true">';

        if ('yes' === $this->show_express) {
            echo '<div id="monek-express-container" class="monek-sdk-surface" aria-live="polite"></div>';
        }

        echo '<div id="monek-checkout-container" class="monek-sdk-surface" aria-live="polite"></div>';
        echo '<div id="monek-checkout-messages" class="monek-checkout-messages" role="alert" aria-live="polite"></div>';
        echo '</div>';
    }

    public function blocks_process_payment($context, $result): void
    {
        try {
            $this->logger->debug('Entered blocks_process_payment');

            if (! $context instanceof PaymentContext || ! $result instanceof PaymentResult) {
                $this->logger->warning('Invalid context/result types received', [
                    'context_type' => is_object($context) ? get_class($context) : gettype($context),
                    'result_type' => is_object($result) ? get_class($result) : gettype($result),
                ]);
                return;
            }

            $this->logRequestSnapshot($context);

            $checkoutRequest = $this->checkoutRequestFactory->createFromPaymentContext($context);
            $isTargetGateway = $checkoutRequest->isForGateway($this->id) || $checkoutRequest->getMode() === 'express';
            if (! $isTargetGateway) {
                $this->logger->debug('Not handling gateway', [
                    'requested_gateway' => $checkoutRequest->getGatewayId(),
                    'mode' => $checkoutRequest->getMode(),
                ]);
                return;
            }

            $order = $this->resolveOrderFromContext($context);
            if (! $order) {
                $this->logger->error('Unable to resolve order from payment context');
                throw new \Exception(__('Unable to load order for payment.', 'monek-checkout'));
            }

            if ($checkoutRequest->isExpress()) {
                $this->expressCheckoutHandler->handle($checkoutRequest, $order, $result);
                return;
            }

            $this->standardCheckoutHandler->handle($checkoutRequest, $order, $result);
        } catch (\Throwable $exception) {
            $this->logger->error('Exception during blocks_process_payment', [
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function get_initial_amount_minor(): int
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return 0;
        }

        $totals = WC()->cart->get_totals();
        $total = isset($totals['total']) ? (float) $totals['total'] : 0.0;

        return $this->currencyFormatter->toMinorUnits($total, get_woocommerce_currency());
    }

    public function get_currency_numeric_code(string $currency): string
    {
        return $this->currencyFormatter->getNumericCurrencyCode($currency);
    }

    public function get_store_country_numeric_code(): string
    {
        return $this->storeContext->getNumericCountryCode();
    }

    private function collectBlockDependencies(): array
    {
        $dependencies = ['wp-data'];
        $maybeHandles = [
            'wc-blocks-checkout',
            'wc-blocks-registry',
            'wc-blocks',
            'wc-blocks-data-store',
        ];

        foreach ($maybeHandles as $handle) {
            if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
                $dependencies[] = $handle;
            }
        }

        return array_values(array_unique($dependencies));
    }

    private function registerSdkScript(): void
    {
        $sdkHandle = 'monek-checkout-sdk';
        if (wp_script_is($sdkHandle, 'registered')) {
            return;
        }

        wp_register_script(
            $sdkHandle,
            'https://checkout-js.monek.com/monek-checkout.iife.js',
            [],
            null,
            true
        );
    }

    private function registerCheckoutScript(array $blockDependencies): void
    {
        $scriptHandle = 'monek-embedded-checkout';
        if (wp_script_is($scriptHandle, 'registered')) {
            return;
        }

        $scriptPath = MONEK_PLUGIN_DIR . 'assets/js/monek-embedded-checkout.js';
        $scriptUrl = MONEK_PLUGIN_URL . 'assets/js/monek-embedded-checkout.js';
        $scriptVersion = file_exists($scriptPath) ? filemtime($scriptPath) : monek_get_plugin_version();

        $dependencies = array_merge(['jquery', 'monek-checkout-sdk'], $blockDependencies);

        wp_register_script(
            $scriptHandle,
            $scriptUrl,
            array_unique($dependencies),
            $scriptVersion,
            true
        );

        $settings = [
            'gatewayId' => $this->id,
            'publishableKey' => $this->publishable_key,
            'showExpress' => ('yes' === $this->show_express),
            'currency' => get_woocommerce_currency(),
            'currencyNumeric' => $this->currencyFormatter->getNumericCurrencyCode(get_woocommerce_currency()),
            'currencyDecimals' => wc_get_price_decimals(),
            'countryNumeric' => $this->storeContext->getNumericCountryCode(),
            'orderDescription' => get_bloginfo('name'),
            'initialAmountMinor' => $this->get_initial_amount_minor(),
            'debug' => ('yes' === $this->debug_mode),
            'strings' => [
                'token_error' => __('There was a problem preparing your payment. Please try again.', 'monek-checkout'),
            ],
        ];

        wp_localize_script($scriptHandle, 'monekCheckoutConfig', $settings);
    }

    private function registerStyles(): void
    {
        $styleHandle = 'monek-embedded-checkout';
        if (wp_style_is($styleHandle, 'registered')) {
            return;
        }

        $stylePath = MONEK_PLUGIN_DIR . 'assets/css/monek-checkout.css';
        $styleUrl = MONEK_PLUGIN_URL . 'assets/css/monek-checkout.css';
        $styleVersion = file_exists($stylePath) ? filemtime($stylePath) : monek_get_plugin_version();

        wp_register_style(
            $styleHandle,
            $styleUrl,
            [],
            $styleVersion
        );
    }

    private function logRequestSnapshot(PaymentContext $context): void
    {
        $rawBody = @file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        $orderId = null;
        if ($context->order instanceof WC_Order) {
            $orderId = $context->order->get_id();
        }

        $this->logger->debug('Request snapshot', [
            'payment_method' => $context->payment_method ?? null,
            'order_id' => $orderId,
            'headers' => $headers,
            'raw_body' => $rawBody,
        ]);
    }

    private function resolveOrderFromContext(PaymentContext $context): ?WC_Order
    {
        if ($context->order instanceof WC_Order) {
            return $context->order;
        }

        return null;
    }
}

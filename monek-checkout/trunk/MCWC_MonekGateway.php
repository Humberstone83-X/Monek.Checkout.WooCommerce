<?php

if (! defined('ABSPATH')) {
    exit;
}

class MCWC_MonekGateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'monek-checkout';
        $this->method_title       = __('Monek Checkout', 'monek-checkout');
        $this->method_description = __('Accept payments using the embedded Monek checkout experience.', 'monek-checkout');
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        $this->publishable_key = $this->get_option('publishable_key');
        $this->secret_key      = $this->get_option('secret_key');
        $this->show_express    = $this->get_option('show_express', 'yes');
        $this->debug_mode      = $this->get_option('debug', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'monek-checkout'),
                'label'   => __('Enable Monek Checkout', 'monek-checkout'),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __('Title', 'monek-checkout'),
                'type'        => 'text',
                'description' => __('Title shown to customers during checkout.', 'monek-checkout'),
                'default'     => __('Monek Checkout', 'monek-checkout'),
            ],
            'description' => [
                'title'       => __('Description', 'monek-checkout'),
                'type'        => 'textarea',
                'description' => __('Optional message shown alongside the payment form.', 'monek-checkout'),
                'default'     => __('Secure payment powered by Monek.', 'monek-checkout'),
            ],
            'publishable_key' => [
                'title'       => __('Publishable key', 'monek-checkout'),
                'type'        => 'text',
                'description' => __('Your public key used to initialise the embedded checkout.', 'monek-checkout'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __('Secret key', 'monek-checkout'),
                'type'        => 'password',
                'description' => __('Server key used for completing payments from your server.', 'monek-checkout'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'show_express' => [
                'title'       => __('Express checkout', 'monek-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Display express wallets (Apple Pay, etc.) above the card form when available.', 'monek-checkout'),
                'default'     => 'yes',
            ],
            'debug' => [
                'title'       => __('Debug logging', 'monek-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Enable verbose logging in the browser console.', 'monek-checkout'),
                'default'     => 'no',
            ],
        ];
    }

    public function is_available(): bool {
        if ('yes' !== $this->get_option('enabled', 'yes')) {
            return false;
        }

        if (empty($this->publishable_key)) {
            return false;
        }

        return parent::is_available();
    }

    public function enqueue_scripts(): void {
        if (! is_checkout() || is_order_received_page()) {
            return;
        }

        if (! $this->is_available()) {
            return;
        }

        $sdk_handle = 'monek-checkout-sdk';
        if (! wp_script_is($sdk_handle, 'registered')) {
            wp_register_script($sdk_handle, 'https://checkout-js.monek.com/monek-checkout.iife.js', [], null, true);
        }

        $script_handle = 'mcwc-embedded-checkout';
        $script_path   = MCWC_PLUGIN_DIR . 'assets/js/mcwc-embedded-checkout.js';
        $script_url    = MCWC_PLUGIN_URL . 'assets/js/mcwc-embedded-checkout.js';

        wp_register_script(
            $script_handle,
            $script_url,
            ['jquery', $sdk_handle],
            file_exists($script_path) ? filemtime($script_path) : mcwc_get_monek_plugin_version(),
            true
        );

        $style_handle = 'mcwc-embedded-checkout';
        $style_path   = MCWC_PLUGIN_DIR . 'assets/css/mcwc-checkout.css';
        $style_url    = MCWC_PLUGIN_URL . 'assets/css/mcwc-checkout.css';

        wp_register_style(
            $style_handle,
            $style_url,
            [],
            file_exists($style_path) ? filemtime($style_path) : mcwc_get_monek_plugin_version()
        );

        $settings = [
            'gatewayId'          => $this->id,
            'publishableKey'     => $this->publishable_key,
            'showExpress'        => ('yes' === $this->show_express),
            'currency'           => get_woocommerce_currency(),
            'currencyNumeric'    => $this->get_currency_numeric_code(get_woocommerce_currency()),
            'currencyDecimals'   => wc_get_price_decimals(),
            'countryNumeric'     => $this->get_store_country_numeric_code(),
            'orderDescription'   => get_bloginfo('name'),
            'initialAmountMinor' => $this->get_initial_amount_minor(),
            'debug'              => ('yes' === $this->debug_mode),
            'strings'            => [
                'token_error' => __('There was a problem preparing your payment. Please try again.', 'monek-checkout'),
            ],
        ];

        wp_localize_script($script_handle, 'mcwcCheckoutConfig', $settings);

        wp_enqueue_script($script_handle);
        wp_enqueue_style($style_handle);
    }

    public function payment_fields(): void {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        echo '<div id="mcwc-checkout-wrapper" class="mcwc-checkout-wrapper" data-loading="true">';
        echo '<div id="mcwc-express-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '<div id="mcwc-checkout-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '<div id="mcwc-checkout-messages" class="mcwc-checkout-messages" role="alert" aria-live="polite"></div>';
        echo '<input type="hidden" name="monek_payment_token" id="monek_payment_token" />';
        echo '<input type="hidden" name="monek_checkout_context" id="monek_checkout_context" />';
        echo '<input type="hidden" name="monek_checkout_session_id" id="monek_checkout_session_id" />';
        echo '</div>';
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            wc_add_notice(__('Unable to create the order at this time. Please try again.', 'monek-checkout'), 'error');
            return ['result' => 'fail'];
        }

        $token = isset($_POST['monek_payment_token']) ? wc_clean(wp_unslash($_POST['monek_payment_token'])) : '';
        if (! $token) {
            wc_add_notice(__('We could not prepare your payment method. Please try again.', 'monek-checkout'), 'error');
            return ['result' => 'fail'];
        }

        $session_id = isset($_POST['monek_checkout_session_id']) ? wc_clean(wp_unslash($_POST['monek_checkout_session_id'])) : '';
        $context     = [];

        if (isset($_POST['monek_checkout_context'])) {
            $raw = wp_unslash($_POST['monek_checkout_context']);
            if (is_string($raw) && '' !== $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $context = $decoded;
                } else {
                    $context = ['raw' => $raw];
                }
            }
        }

        $order->update_meta_data('_mcwc_payment_token', $token);

        if ($session_id) {
            $order->update_meta_data('_mcwc_session_id', $session_id);
        }

        if (! empty($context)) {
            $order->update_meta_data('_mcwc_payment_context', $context);
        }

        $order->add_order_note(__('Monek checkout token captured. Awaiting server-side payment completion.', 'monek-checkout'));
        $order->update_status('on-hold', __('Awaiting payment confirmation from Monek.', 'monek-checkout'));
        $order->save();

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        do_action('mcwc_checkout_token_captured', $order_id, $token, $context, $session_id);

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    protected function get_initial_amount_minor(): int {
        if (! function_exists('WC') || ! WC()->cart) {
            return 0;
        }

        $totals = WC()->cart->get_totals();
        $total  = isset($totals['total']) ? (float) $totals['total'] : 0.0;

        return (int) round($total * pow(10, wc_get_price_decimals()));
    }

    protected function get_currency_numeric_code(string $currency): string {
        $map = [
            'GBP' => '826',
            'USD' => '840',
            'EUR' => '978',
            'AUD' => '036',
            'CAD' => '124',
            'NZD' => '554',
            'SEK' => '752',
            'NOK' => '578',
            'DKK' => '208',
            'CHF' => '756',
        ];

        $map = apply_filters('mcwc_currency_numeric_codes', $map, $currency);

        return $map[$currency] ?? '826';
    }

    protected function get_store_country_numeric_code(): string {
        $base_country = wc_get_base_location()['country'] ?? 'GB';

        $map = [
            'GB' => '826',
            'US' => '840',
            'IE' => '372',
            'AU' => '036',
            'NZ' => '554',
            'CA' => '124',
            'FR' => '250',
            'DE' => '276',
        ];

        $map = apply_filters('mcwc_country_numeric_codes', $map, $base_country);

        return $map[$base_country] ?? '826';
    }
}

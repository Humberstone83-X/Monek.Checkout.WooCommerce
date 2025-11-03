<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
    exit;
}

final class MCWC_Monek_Blocks extends AbstractPaymentMethodType {

    protected $name = 'monek-checkout';

    public function initialize() {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active(): bool {
        if (empty($this->settings)) {
            return false;
        }

        if (! isset($this->settings['enabled']) || 'yes' !== $this->settings['enabled']) {
            return false;
        }

        if (empty($this->settings['publishable_key'])) {
            return false;
        }

        return true;
    }

    public function get_payment_method_script_handles(): array {
        $sdk_handle = 'monek-checkout-sdk';
        if (! wp_script_is($sdk_handle, 'registered')) {
            wp_register_script($sdk_handle, 'https://checkout-js.monek.com/monek-checkout.iife.js', [], null, true);
        }

        $checkout_handle = 'mcwc-embedded-checkout';
        if (! wp_script_is($checkout_handle, 'registered')) {
            $checkout_path = MCWC_PLUGIN_DIR . 'assets/js/mcwc-embedded-checkout.js';
            $checkout_url  = MCWC_PLUGIN_URL . 'assets/js/mcwc-embedded-checkout.js';
            $checkout_ver  = file_exists($checkout_path) ? filemtime($checkout_path) : mcwc_get_monek_plugin_version();

            wp_register_script(
                $checkout_handle,
                $checkout_url,
                ['jquery', $sdk_handle],
                $checkout_ver,
                true
            );
        }

        $style_handle = 'mcwc-embedded-checkout';
        if (! wp_style_is($style_handle, 'registered')) {
            $style_path = MCWC_PLUGIN_DIR . 'assets/css/mcwc-checkout.css';
            $style_url  = MCWC_PLUGIN_URL . 'assets/css/mcwc-checkout.css';
            $style_ver  = file_exists($style_path) ? filemtime($style_path) : mcwc_get_monek_plugin_version();

            wp_register_style(
                $style_handle,
                $style_url,
                [],
                $style_ver
            );
        }

        if (! wp_script_is('mcwc-blocks-checkout', 'registered')) {
            $blocks_path = MCWC_PLUGIN_DIR . 'assets/js/mcwc-blocks-checkout.js';
            $blocks_url  = MCWC_PLUGIN_URL . 'assets/js/mcwc-blocks-checkout.js';
            $blocks_ver  = file_exists($blocks_path) ? filemtime($blocks_path) : mcwc_get_monek_plugin_version();

            wp_register_script(
                'mcwc-blocks-checkout',
                $blocks_url,
                ['wc-blocks-registry', 'wc-settings', 'wp-element', $checkout_handle],
                $blocks_ver,
                true
            );
        }

        return [$checkout_handle, 'mcwc-blocks-checkout'];
    }

    public function get_payment_method_script_handles_for_admin(): array {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data(): array {
        $gateways = WC() && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway  = $gateways[ $this->name ] ?? null;

        // Pull gateway settings you already have (so the client doesn’t depend on wp_localize_script anymore)
        $publishable = $gateway ? ( $gateway->get_option( 'publishable_key' ) ?: '' ) : '';
        $show_express = $gateway ? ( $gateway->get_option( 'show_express', 'yes' ) === 'yes' ) : true;
        $debug        = $gateway ? ( $gateway->get_option( 'debug', 'no' ) === 'yes' ) : false;

        return [
            'title'        => $gateway ? $gateway->get_title() : __( 'Monek Checkout', 'monek-checkout' ),
            'description'  => $gateway ? $gateway->get_description() : __( 'Secure payment powered by Monek.', 'monek-checkout' ),
            // Keep it SIMPLE: features array directly
            'supports'     => [ 'products' ],
            'errorMessage' => __( 'We were unable to prepare your payment. Please try again.', 'monek-checkout' ),

            // Provide everything the client needs without wp_localize_script
            'gatewayId'          => $this->name,
            'publishableKey'     => $publishable,
            'showExpress'        => $show_express,
            'currency'           => get_woocommerce_currency(),
            'currencyNumeric'    => '826', // or compute like in your gateway
            'currencyDecimals'   => wc_get_price_decimals(),
            'countryNumeric'     => '826', // or compute like in your gateway
            'orderDescription'   => get_bloginfo( 'name' ),
            'initialAmountMinor' => 0,
            'debug'              => $debug,
            'strings'            => [
                'token_error' => __( 'There was a problem preparing your payment. Please try again.', 'monek-checkout' ),
            ],
        ];
    }
}

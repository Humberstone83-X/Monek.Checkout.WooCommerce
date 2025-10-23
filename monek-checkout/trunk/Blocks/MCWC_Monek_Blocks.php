<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MCWC_Monek_Blocks extends AbstractPaymentMethodType {

	protected $name = 'monek-checkout';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
	}

	public function is_active(): bool {
		$enabled = $this->settings['enabled'] ?? 'no';
		if ( 'yes' !== $enabled ) return false;

		if ( 'yes' === ( $this->settings['consignment_mode'] ?? 'no' ) ) return true;

		return ! empty( $this->settings['merchant_id'] );
	}


        public function get_payment_method_script_handles(): array {
                if ( ! wp_script_is( 'mcwc-embedded-checkout', 'registered' ) ) {
                        wp_register_script(
                                'mcwc-embedded-checkout',
                                plugins_url( 'assets/js/monek-embedded-checkout.js', dirname(__FILE__, 2) . '/.' ),
                                [ 'jquery' ],
                                mcwc_get_monek_plugin_version(),
                                true
                        );
                }

                if ( ! wp_style_is( 'mcwc-embedded-checkout', 'registered' ) ) {
                        wp_register_style(
                                'mcwc-embedded-checkout',
                                plugins_url( 'assets/css/monek-embedded-checkout.css', dirname(__FILE__, 2) . '/.' ),
                                [],
                                mcwc_get_monek_plugin_version()
                        );
                }

                wp_register_script(
                        'mcwc-monek-blocks',
                        plugins_url( 'assets/js/monek-blocks-checkout.js', dirname(__FILE__, 2) . '/.' ),
                        [ 'wc-blocks-registry', 'wc-blocks-checkout', 'wp-element', 'wp-i18n', 'mcwc-embedded-checkout' ],
                        defined('WC_VERSION') ? WC_VERSION : '1.0.0',
                        true
                );

                return [ 'mcwc-embedded-checkout', 'mcwc-monek-blocks' ];
        }

	public function get_payment_method_script_handles_for_admin(): array {
		return $this->get_payment_method_script_handles();
	}

	public function get_payment_method_data(): array {
        $gateway = \WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null;

        return [
            'title'       => $gateway ? $gateway->get_title() : __( 'Credit/Debit Card', 'monek-checkout' ),
            'description' => $gateway ? $gateway->get_description() : __( 'Pay securely with Monek.', 'monek-checkout' ),
            'supports'    => [ 'features' => [ 'products' ] ],
        ];
    }
}

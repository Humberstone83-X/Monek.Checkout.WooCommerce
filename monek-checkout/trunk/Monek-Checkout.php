<?php
/**
 * Plugin Name: Monek Checkout
 * Description: Embedded checkout experience for WooCommerce powered by Monek.
 * Author: Monek Ltd
 * Author URI: https://www.monek.com
 * Version: 5.0.0
 * Text Domain: monek-checkout
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('MCWC_PLUGIN_FILE')) {
    define('MCWC_PLUGIN_FILE', __FILE__);
}

if (! defined('MCWC_PLUGIN_DIR')) {
    define('MCWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('MCWC_PLUGIN_URL')) {
    define('MCWC_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (! function_exists('mcwc_get_monek_plugin_version')) {
    function mcwc_get_monek_plugin_version(): string {
        $data = get_file_data(MCWC_PLUGIN_FILE, [
            'Version' => 'Version',
        ]);

        return isset($data['Version']) ? $data['Version'] : '1.0.0';
    }
}

if (! function_exists('mcwc_register_gateway')) {
    function mcwc_register_gateway(array $gateways): array {
        $gateways[] = 'MCWC_MonekGateway';
        return $gateways;
    }
}

if (! function_exists('mcwc_bootstrap_gateway')) {
    function mcwc_bootstrap_gateway(): void {
        if (! class_exists('WC_Payment_Gateway')) {
            return;
        }

        require_once MCWC_PLUGIN_DIR . 'MCWC_MonekGateway.php';

        add_filter('woocommerce_payment_gateways', 'mcwc_register_gateway');

        if (function_exists('WC') && class_exists('\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
            require_once MCWC_PLUGIN_DIR . 'Blocks/MCWC_Monek_Blocks.php';
            add_action('woocommerce_blocks_payment_method_type_registration', function (PaymentMethodRegistry $registry) {
                $registry->register(new MCWC_Monek_Blocks());
            });
        }
    }
}

add_action('plugins_loaded', 'mcwc_bootstrap_gateway', 11);

if (! function_exists('mcwc_plugin_action_links')) {
    function mcwc_plugin_action_links(array $links): array {
        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=monek-checkout');
        $settingsLink = '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings', 'monek-checkout') . '</a>';
        array_unshift($links, $settingsLink);
        return $links;
    }
}

add_filter('plugin_action_links_' . plugin_basename(MCWC_PLUGIN_FILE), 'mcwc_plugin_action_links');

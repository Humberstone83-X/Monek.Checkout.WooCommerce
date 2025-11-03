<?php
/**
 * Plugin Name: Monek Checkout
 * Description: Embedded checkout experience for WooCommerce powered by Monek.
 * Author: Monek Ltd
 * Author URI: https://www.monek.com
 * Version: 4.0.0
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

add_action( 'before_woocommerce_init', function () {
  if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
      'cart_checkout_blocks',
      __FILE__,
      true
    );
  }
} );

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

// --- Register "Payment Confirmed" status
add_action('init', function () {
    register_post_status('wc-payment-confirmed', [
        'label'                     => _x('Payment Confirmed', 'Order status', 'monek-checkout'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Payment Confirmed <span class="count">(%s)</span>', 'Payment Confirmed <span class="count">(%s)</span>')
    ]);
});

// Show it in the dropdowns
add_filter('wc_order_statuses', function ($statuses) {
    $new = [];
    foreach ($statuses as $k => $v) {
        $new[$k] = $v;
        if ('wc-processing' === $k) {
            // Insert after "Processing" (place wherever you prefer)
            $new['wc-payment-confirmed'] = _x('Payment Confirmed', 'Order status', 'monek-checkout');
        }
    }
    return $new;
});

add_action('admin_head', function () {
    ?>
    <style>
      /* Match Woo processing (green) */
      .order-status.status-payment-confirmed {
        background: #c6e1c6;          /* same as processing */
        color: #5b841b;               /* same as processing */
      }
      .order-status.status-payment-confirmed:before {
        color: #5b841b;               /* icon color */
      }
    </style>
    <?php
});

/**
 * Plugin Name: MCWC Webhook Test
 * Description: Minimal REST route to verify /wp-json/mcwc/v1/webhook works.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Log when REST is booting (helps confirm the MU plugin is actually loaded)
add_action('rest_api_init', function () {
    if ( function_exists('wc_get_logger') ) {
        wc_get_logger()->info('[mcwc] rest_api_init fired', ['source' => 'mcwc-webhook']);
    } else {
        error_log('[mcwc] rest_api_init fired');
    }

    register_rest_route(
    'mcwc/v1',
    '/webhook',
    [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => function( WP_REST_Request $req ) : WP_REST_Response {
            $logger = function_exists('wc_get_logger') ? wc_get_logger() : null;

            // Read JSON
            $body = $req->get_json_params();
            if ( ! is_array( $body ) ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_json' ], 400 );
            }

            // Extract paymentReference from common places
            $ref = '';
            if ( isset($body['paymentReference']) )                $ref = (string) $body['paymentReference'];
            elseif ( isset($body['Data']['PaymentReference']) )    $ref = (string) $body['Data']['PaymentReference'];
            elseif ( isset($body['reference']) )                   $ref = (string) $body['reference'];

            if ( $ref === '' ) {
                $logger?->warning('[mcwc] webhook: missing paymentReference', ['source'=>'mcwc-webhook','body'=>$body]);
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_reference' ], 400 );
            }

            // Find the order by meta
            $orders = function_exists('wc_get_orders') ? wc_get_orders([
                'limit'      => 1,
                'return'     => 'ids',
                'meta_key'   => '_mcwc_payment_reference',
                'meta_value' => $ref,
                'orderby'    => 'date',
                'order'      => 'DESC',
                'type'       => 'shop_order',
                'status'     => array_keys( wc_get_order_statuses() ),
            ]) : [];

            $order_id = $orders[0] ?? null;
            if ( ! $order_id ) {
                $logger?->warning('[mcwc] webhook: order not found', ['source'=>'mcwc-webhook','reference'=>$ref]);
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'order_not_found', 'reference' => $ref ], 404 );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_REST_Response( [ 'ok' => false, 'error' => 'order_load_failed', 'order_id' => $order_id ], 500 );
            }

            // (Optional) store raw webhook for audit/debug
            $order->update_meta_data( '_mcwc_last_webhook', wp_json_encode( $body ) );

            // Transition to your custom status (slug WITHOUT "wc-")
            $target_status = 'payment-confirmed';

            // Only update if needed (idempotent)
            if ( $order->get_status() !== $target_status ) {
                $order->update_status(
                    $target_status,
                    sprintf( 'Webhook set status to %s (ref: %s).', $target_status, $ref )
                );
            } else {
                // Still add a note so you can see repeated pings
                $order->add_order_note( sprintf( 'Webhook ping received (already %s). Ref: %s', $target_status, $ref ) );
            }

            $order->save();

            $logger?->info('[mcwc] webhook: status updated', [
                'source'    => 'mcwc-webhook',
                'reference' => $ref,
                'order_id'  => $order_id,
                'status'    => $order->get_status(),
            ]);

            return new WP_REST_Response([
                'ok'        => true,
                'reference' => $ref,
                'order_id'  => $order_id,
                'status'    => $order->get_status(),
            ], 200 );
        },
        'permission_callback' => '__return_true', // see security note below
    ]
);

});


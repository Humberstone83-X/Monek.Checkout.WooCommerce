<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MCWC_MonekGateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'monek-checkout';
        $this->method_title       = __( 'Monek Checkout', 'monek-checkout' );
        $this->method_description = __( 'Accept payments using the embedded Monek checkout experience.', 'monek-checkout' );
        $this->has_fields         = false;              // Blocks does not use classic fields
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title           = $this->get_option( 'title' );
        $this->description     = $this->get_option( 'description' );
        $this->publishable_key = $this->get_option( 'publishable_key' );
        $this->secret_key      = $this->get_option( 'secret_key' );
        $this->show_express    = $this->get_option( 'show_express', 'yes' );
        $this->debug_mode      = $this->get_option( 'debug', 'no' );
        $this->svix_signing_secret = $this->get_option( 'svix_signing_secret' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // 🔹 Blocks (Store API) payment handler.
        add_action(
            'woocommerce_rest_checkout_process_payment_with_context',
            [ $this, 'blocks_process_payment' ],
            10,
            2
        );

    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'monek-checkout' ),
                'label'   => __( 'Enable Monek Checkout', 'monek-checkout' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Title', 'monek-checkout' ),
                'type'        => 'text',
                'description' => __( 'Title shown to customers during checkout.', 'monek-checkout' ),
                'default'     => __( 'Monek Checkout', 'monek-checkout' ),
            ],
            'description' => [
                'title'       => __( 'Description', 'monek-checkout' ),
                'type'        => 'textarea',
                'description' => __( 'Optional message shown alongside the payment form.', 'monek-checkout' ),
                'default'     => __( 'Secure payment powered by Monek.', 'monek-checkout' ),
            ],
            'publishable_key' => [
                'title'       => __( 'Publishable key', 'monek-checkout' ),
                'type'        => 'text',
                'description' => __( 'Your public key used to initialise the embedded checkout.', 'monek-checkout' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __( 'Secret key', 'monek-checkout' ),
                'type'        => 'password',
                'description' => __( 'Server key used for completing payments from your server.', 'monek-checkout' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'svix_signing_secret' => [
                'title'       => __( 'Svix signing secret', 'monek-checkout' ),
                'type'        => 'text',
                'description' => __( 'Paste the signing secret for your svix endpoint.', 'monek-checkout' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'show_express' => [
                'title'       => __( 'Express checkout', 'monek-checkout' ),
                'type'        => 'checkbox',
                'label'       => __( 'Display express wallets (Apple Pay, etc.) above the card form when available.', 'monek-checkout' ),
                'default'     => 'yes',
            ],
            'debug' => [
                'title'       => __( 'Debug logging', 'monek-checkout' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable verbose logging in the browser console.', 'monek-checkout' ),
                'default'     => 'no',
            ],
        ];
    }

    public function is_available(): bool {
        if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) {
            return false;
        }
        if ( empty( $this->publishable_key ) ) {
            return false;
        }
        return parent::is_available();
    }

    public function enqueue_scripts(): void {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }
        if ( ! $this->is_available() ) {
            return;
        }

        // --- Blocks deps (keep very lean; no classic checkout) ---
        $blocks_deps   = [ 'wp-data' ];
        $maybe_handles = [
            'wc-blocks-checkout',
            'wc-blocks-registry',
            'wc-blocks',
            'wc-blocks-data-store',
        ];
        foreach ( $maybe_handles as $h ) {
            if ( wp_script_is( $h, 'registered' ) || wp_script_is( $h, 'enqueued' ) ) {
                $blocks_deps[] = $h;
            }
        }

        // --- Monek SDK ---
        $sdk_handle = 'monek-checkout-sdk';
        if ( ! wp_script_is( $sdk_handle, 'registered' ) ) {
            wp_register_script(
                $sdk_handle,
                'https://checkout-js.monek.com/monek-checkout.iife.js',
                [],
                null,
                true
            );
        }

        // --- Your app script (Blocks-only version) ---
        $script_handle = 'mcwc-embedded-checkout';
        $script_path   = MCWC_PLUGIN_DIR . 'assets/js/mcwc-embedded-checkout.js';
        $script_url    = MCWC_PLUGIN_URL . 'assets/js/mcwc-embedded-checkout.js';

        $deps = array_merge( [ 'jquery', $sdk_handle ], array_unique( $blocks_deps ) );

        wp_register_script(
            $script_handle,
            $script_url,
            $deps,
            file_exists( $script_path ) ? filemtime( $script_path ) : mcwc_get_monek_plugin_version(),
            true
        );

        // --- Styles ---
        $style_handle = 'mcwc-embedded-checkout';
        $style_path   = MCWC_PLUGIN_DIR . 'assets/css/mcwc-checkout.css';
        $style_url    = MCWC_PLUGIN_URL . 'assets/css/mcwc-checkout.css';

        wp_register_style(
            $style_handle,
            $style_url,
            [],
            file_exists( $style_path ) ? filemtime( $style_path ) : mcwc_get_monek_plugin_version()
        );

        // --- Settings passed to JS ---
        $settings = [
            'gatewayId'          => $this->id,
            'publishableKey'     => $this->publishable_key,
            'showExpress'        => ( 'yes' === $this->show_express ),
            'currency'           => get_woocommerce_currency(),
            'currencyNumeric'    => $this->get_currency_numeric_code( get_woocommerce_currency() ),
            'currencyDecimals'   => wc_get_price_decimals(),
            'countryNumeric'     => $this->get_store_country_numeric_code(),
            'orderDescription'   => get_bloginfo( 'name' ),
            'initialAmountMinor' => $this->get_initial_amount_minor(),
            'debug'              => ( 'yes' === $this->debug_mode ),
            'strings'            => [
                'token_error' => __( 'There was a problem preparing your payment. Please try again.', 'monek-checkout' ),
            ],
        ];

        wp_localize_script( $script_handle, 'mcwcCheckoutConfig', $settings );

        wp_enqueue_script( $script_handle );
        wp_enqueue_style( $style_handle );

        if ( 'yes' === $this->debug_mode ) {
            $present = array_values( array_unique( $blocks_deps ) );
            wp_add_inline_script(
                $script_handle,
                'console.log("[mcwc] deps present:", ' . wp_json_encode( $present ) . ' );',
                'after'
            );
        }
    }

    // Blocks doesn’t render this, but leaving it is harmless; can be removed if you prefer.
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wpautop( wptexturize( $this->description ) );
        }
        echo '<div id="mcwc-checkout-wrapper" class="mcwc-checkout-wrapper" data-loading="true">';
        echo '<div id="mcwc-express-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '<div id="mcwc-checkout-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '<div id="mcwc-checkout-messages" class="mcwc-checkout-messages" role="alert" aria-live="polite"></div>';
        echo '</div>';
    }

  // Add this helper anywhere in the class (e.g., after __construct()).
protected function log( string $message, array $context = [], string $level = 'debug' ): void {
    if ( ! function_exists( 'wc_get_logger' ) ) {
        // Fallback to PHP error_log if Woo logger not present
        error_log( '[mcwc] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
        return;
    }

    // Normalise non-scalar context (avoid "Array to string conversion")
    $safe = [];
    foreach ( $context as $k => $v ) {
        if ( is_scalar( $v ) || $v === null ) {
            $safe[ $k ] = $v;
        } else {
            $safe[ $k ] = wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
    }

    // Use a stable "source" so it groups into one daily log file
    wc_get_logger()->log( $level, $message, [ 'source' => 'mcwc' ] + $safe );
}

/**
 * Store API / Blocks payment handler (REPLACE your existing method with this one)
 */
public function blocks_process_payment( $context, $result ) {
    try {
        $this->log('entered blocks_process_payment');

        // Type-safety guards
        if (
            ! ( $context instanceof \Automattic\WooCommerce\Blocks\Payments\PaymentContext ) ||
            ! ( $result  instanceof \Automattic\WooCommerce\Blocks\Payments\PaymentResult )
        ) {
            $this->log('invalid context/result types', [
                'context_type' => is_object($context) ? get_class($context) : gettype($context),
                'result_type'  => is_object($result)  ? get_class($result)  : gettype($result),
            ], 'warning');
            return;
        }

        // Basic request snapshot (best-effort)
        $raw_body = @file_get_contents('php://input');
        $headers  = function_exists('getallheaders') ? getallheaders() : [];
        $this->log('request snapshot', [
            'payment_method' => $context->payment_method ?? null,
            'order_id'       => method_exists($context->order ?? null, 'get_id') ? $context->order->get_id() : null,
            'headers'        => $headers,
            'raw_body'       => $raw_body,
        ]);

        $method_id = $context->payment_method ?? null;

        $payment_data = is_array( $context->payment_data ?? null ) ? $context->payment_data : [];
        $mode       = isset( $payment_data['mcwc_mode'] )      ? wc_clean( wp_unslash( $payment_data['mcwc_mode'] ) )      : '';

        // Only handle our methods
        if ( $method_id !== $this->id && $method_id !== "{$this->id}-express" && $mode !== 'express' ) {
            $this->log('not our gateway, skipping', [ 'method_id' => $method_id ]);
            return;
        }

        // Collect/clean payment data
        $token      = isset( $payment_data['mcwc_token'] )     ? wc_clean( wp_unslash( $payment_data['mcwc_token'] ) )     : '';
        $session    = isset( $payment_data['mcwc_session'] )   ? wc_clean( wp_unslash( $payment_data['mcwc_session'] ) )   : '';
        $expiry     = isset( $payment_data['mcwc_expiry'] )    ? wc_clean( wp_unslash( $payment_data['mcwc_expiry'] ) )    : '';
        $reference  = isset( $payment_data['mcwc_reference'] ) ? wc_clean( wp_unslash( $payment_data['mcwc_reference'] ) ) : '';

        $keys = array_keys( $payment_data );
        $this->log('parsed payment_data', [
            'method_id'  => $method_id,
            'mode'       => $mode,
            'keys'       => $keys,
            'has_ref'    => $reference !== '' ? 'yes' : 'no',
            'has_token'  => $token     !== '' ? 'yes' : 'no',
            'has_sess'   => $session   !== '' ? 'yes' : 'no',
            'has_expiry' => $expiry    !== '' ? 'yes' : 'no',
        ]);

        $is_express = ( $method_id === "{$this->id}-express" ) || ( $mode === 'express' );

        // EXPRESS BRANCH
        if ( $is_express ) {
            $this->log('branch: express', [ 'method_id' => $method_id, 'mode' => $mode, 'reference' => $reference ]);

            if ( empty( $reference ) ) {
                $this->log('express missing reference', [ 'payment_data' => $payment_data ], 'error' );
                throw new \Exception( __( 'Missing payment reference.', 'monek-checkout' ) );
            }

            $order = $context->order;
            if ( ! $order ) {
                $this->log('no order in context (express)', [], 'error' );
                throw new \Exception( __( 'Unable to load order for payment.', 'monek-checkout' ) );
            }

            $order->update_meta_data( '_mcwc_session', $session );
            $order->update_meta_data( '_mcwc_payment_reference', $reference );
            $order->add_order_note( sprintf( 'Express payment reference set: %s', $reference ) );
            $order->payment_complete();
            $order->save();

            $this->log('express success → redirecting', [
                'order_id' => $order->get_id(),
                'redirect' => $order->get_checkout_order_received_url(),
            ]);

            $result->set_status( 'success' );
            $result->set_redirect_url( $order->get_checkout_order_received_url() );
            return;
        }

        // NORMAL CARD BRANCH
        if ( empty( $token ) || empty( $session ) || empty( $expiry ) || empty( $reference ) ) {
            $this->log('normal branch missing data', [
                'token' => $token ? 'y' : 'n',
                'session' => $session ? 'y' : 'n',
                'expiry' => $expiry ? 'y' : 'n',
                'reference' => $reference ? 'y' : 'n',
                'payment_data' => $payment_data,
            ], 'error' );
            throw new \Exception( __( 'Missing payment data. Please try again.', 'monek-checkout' ) );
        }

        $order = $context->order;
        if ( ! $order ) {
            $this->log('no order in context (normal)', [], 'error' );
            throw new \Exception( __( 'Unable to load order for payment.', 'monek-checkout' ) );
        }

        $this->log('calling Monek /payment', [
            'order_id'  => $order->get_id(),
            'minor'     => (int) round( (float) $order->get_total() * pow( 10, wc_get_price_decimals() ) ),
            'currency'  => $order->get_currency(),
            'reference' => $reference,
        ]);

        $out = $this->call_monek_pay_api( $order, $token, $session, $expiry, $reference );

        $this->log('Monek response', [
            'success'    => $out['success'] ? 'yes' : 'no',
            'message'    => $out['message'],
            'auth_code'  => $out['auth_code'],
            'error_code' => $out['error_code'],
            'raw'        => $out['raw'],
        ], $out['success'] ? 'info' : 'error' );

        if ( ! $out['success'] ) {
            $msg = $out['message'] ?: __( 'Payment failed. Please try again.', 'monek-checkout' );
            throw new \Exception( $msg );
        }

        if ( $out['auth_code'] ) {
            $order->add_order_note( 'Monek auth code: ' . $out['auth_code'] );
        }

        $order->update_meta_data( '_mcwc_token', $token );
        $order->update_meta_data( '_mcwc_session', $session );
        $order->update_meta_data( '_mcwc_result', $out['raw'] ? wp_json_encode( $out['raw'] ) : '' );
        $order->update_meta_data( '_mcwc_payment_reference', $reference );
        $order->payment_complete();
        $order->save();

        $this->log('normal success → redirecting', [
            'order_id' => $order->get_id(),
            'redirect' => $order->get_checkout_order_received_url(),
        ]);

        $result->set_status( 'success' );
        $result->set_redirect_url( $order->get_checkout_order_received_url() );
    } catch ( \Throwable $e ) {
        // Any thrown error will be surfaced to the UI by Woo Blocks
        $this->log('exception in blocks_process_payment', [
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ], 'error');

        // Re-throw so Blocks shows it to the shopper
        throw $e;
    }
}


    protected function get_initial_amount_minor(): int {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }
        $totals = WC()->cart->get_totals();
        $total  = isset( $totals['total'] ) ? (float) $totals['total'] : 0.0;

        return (int) round( $total * pow( 10, wc_get_price_decimals() ) );
    }

    protected function get_currency_numeric_code( string $currency ): string {
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

        $map = apply_filters( 'mcwc_currency_numeric_codes', $map, $currency );
        return $map[ $currency ] ?? '826';
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

        $map = apply_filters( 'mcwc_country_numeric_codes', $map, $base_country );
        return $map[ $base_country ] ?? '826';
    }

    /**
 * Build the JSON payload for Monek /payment (matches your TS buildPaymentRequest).
 */
protected function build_monek_payment_payload( WC_Order $order, string $token, string $session, string $expiry, string $reference ): array {
    $currency         = $order->get_currency();                                   // e.g. "GBP"
    $currency_numeric = $this->get_currency_numeric_code( $currency );            // e.g. "826"
    $minor_unit       = wc_get_price_decimals();                                   // e.g. 2
    $minor_amount     = (int) round( (float) $order->get_total() * pow( 10, $minor_unit ) );

    $billing_first = (string) $order->get_billing_first_name();
    $billing_last  = (string) $order->get_billing_last_name();
    $billing_name  = trim( $billing_first . ' ' . $billing_last );

    $expiry_month = substr( $expiry, 0, 2 );
    $expiry_year  = substr( $expiry, 3, 2 );

    $payload = [
        'sessionId'     => $session,
        'tokenId'       => $token,

        // Keep aligned with your SDK settings
        'settlementType'=> 'Auto',
        'cardEntry'     => 'ECommerce',
        'intent'        => 'Purchase',
        'order'         => 'Checkout',

        'currencyCode'  => $currency_numeric,
        'minorAmount'   => $minor_amount,

        'countryCode'   => $this->get_store_country_numeric_code(),


        'card' => [ 'expiryMonth' => $expiry_month, 'expiryYear' => $expiry_year ],

        'cardHolder'    => array_filter([
            'name'             => $billing_name,
            'emailAddress'     => $order->get_billing_email(),
            'phoneNumber'      => $order->get_billing_phone(),
            'billingStreet1'   => $order->get_billing_address_1(),
            'billingStreet2'   => $order->get_billing_address_2(),
            'billingCity'      => $order->get_billing_city(),
            'billingPostcode'  => $order->get_billing_postcode(),
        ], static fn( $v ) => $v !== null && $v !== '' ),

        'storeCardDetails' => false,

        // Idempotency: stable per attempt (order + attempt)
        'idempotencyToken' => 'wc-' . $order->get_id() . '-' . wp_generate_uuid4(),

        'source'        => 'EmbeddedCheckout',
        'url'           => home_url(),
        'basketDescription' => sprintf( __( 'Order %s', 'monek-checkout' ), $order->get_order_number() ),
        'paymentReference'  => $reference,
        // If you have these in settings/SDK, add them here:
        // 'validityId' => '...', 'channel' => '...'
    ];

    return $payload;
}

/**
 * Call Monek /payment and return a normalized result.
 *
 * @return array{success:bool,message:string|null,auth_code:string|null,error_code:string|null,raw:array|null}
 */
protected function call_monek_pay_api( WC_Order $order, string $token, string $session, string $expiry, string $reference ): array {
    $endpoint = 'https://api.monek.com/embedded-checkout/payment';

    $body = $this->build_monek_payment_payload( $order, $token, $session, $expiry, $reference );

    $args = [
        'method'      => 'POST',
        'timeout'     => 20,
        'headers'     => [
            'Content-Type' => 'application/json',
            // Client sends publishable; server should use secret:
            'X-Api-Key'    => (string) $this->publishable_key,
            'X-Secret-Key' => (string) $this->secret_key,
        ],
        'body'        => wp_json_encode( $body ),
        'data_format' => 'body',
    ];

    $resp = wp_remote_post( $endpoint, $args );

    if ( is_wp_error( $resp ) ) {
        return [
            'success'    => false,
            'message'    => 'Payment request failed to send',
            'auth_code'  => null,
            'error_code' => $resp->get_error_code(),
            'raw'        => null,
        ];
    }

    $status = (int) wp_remote_retrieve_response_code( $resp );
    $raw    = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

    if ( $status < 200 || $status >= 300 ) {
        return [
            'success'    => false,
            'message'    => sprintf( 'payment failed (%d)', $status ),
            'auth_code'  => null,
            'error_code' => isset( $raw['ErrorCode'] ) ? (string) $raw['ErrorCode'] : null,
            'raw'        => is_array( $raw ) ? $raw : null,
        ];
    }

    // Map like your TS mapPaymentResponse()
    $result    = $raw['Result']    ?? $raw['result']    ?? null;
    $message   = $raw['Message']   ?? $raw['message']   ?? null;
    $auth_code = $raw['AuthCode']  ?? $raw['authCode']  ?? null;
    $err_code  = $raw['ErrorCode'] ?? $raw['errorCode'] ?? null;

    // Normalise like your normalisePayment()
    $success_buckets = [ 'Success' ]; // 00

    $is_success = in_array( (string) $result, $success_buckets, true );

    return [
        'success'    => $is_success,
        'message'    => $message ? (string) $message : null,
        'auth_code'  => $auth_code ? (string) $auth_code : null,
        'error_code' => $err_code ? (string) $err_code : null,
        'raw'        => is_array( $raw ) ? $raw : null,
    ];
}
}

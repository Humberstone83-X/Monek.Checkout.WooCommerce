<?php

/**
 * Class MonekGateway - provides the main functionality of the Monek payment gateway 
 *
 * #[AllowDynamicProperties] - allows dynamic properties to be set on the class as we are not allowed to define the following variables
 * @property string $title
 * @property string $description
 * 
 * @package Monek
 */

#[AllowDynamicProperties]
class MCWC_MonekGateway extends WC_Payment_Gateway
{
    public const ELITE_URL = 'https://elite.monek.com/Secure/';
    private const GATEWAY_ID = 'monek-checkout';
    public const STAGING_URL = 'https://staging.monek.com/Secure/';
    private const CHECKOUT_JS_URL_LIVE = 'https://checkout-js.monek.com/monek-checkout.iife.js';
    private const CHECKOUT_JS_URL_TEST = 'https://dev-checkout-js.monek.com/monek-checkout.iife.js';
    private const CHECKOUT_API_BASE_LIVE = 'https://checkout-api.monek.com/v1/';
    private const CHECKOUT_API_BASE_TEST = 'https://dev-checkout-api.monek.com/v1/';

    public string $basket_summary;
    public bool $is_consignment_mode_active;
    public string $country_dropdown;
    private bool $is_test_mode_active;
    public string $merchant_id;
    private bool $show_google_pay;
    private bool $disable_basket;
    private string $publishable_key = '';
    private string $secret_key = '';
    private ?MCWC_ServerCompletionClient $server_completion_client = null;
    private MCWC_ServerCompletionPayloadBuilder $server_payload_builder;
    private array $store_api_payment_data = [];

    public function __construct()
    {
        $this->mcwc_setup_properties();
        $this->mcwc_init_form_fields();
        $this->init_settings();
        $this->mcwc_get_settings();

        add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
        add_action('wp_enqueue_scripts', [$this, 'mcwc_enqueue_checkout_assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'mcwc_require_token_during_validation'], 10, 2);
        add_filter('woocommerce_store_api_checkout_payment_method_data', [$this, 'mcwc_capture_store_api_payment_data'], 10, 3);

        $this->server_payload_builder = new MCWC_ServerCompletionPayloadBuilder();
        $this->mcwc_bootstrap_server_completion_client();

        $callback_controller = new MCWC_CallbackController($this->is_test_mode_active);
        $callback_controller->mcwc_register_routes();

        if ($this->is_consignment_mode_active) {
            MCWC_ProductConsignmentInitializer::init();
        }
    }

    /**
     * Validate that all basket items have the same Monek ID and return the product Monek ID
     * 
     * @param WC_Order $order
     * @return string
     * @throws Exception
     */
    private function mcwc_get_consignment_merchant_id(WC_Order $order): string
    {
        if(MCWC_ConsignmentCart::mcwc_check_order_for_matching_merchants($order->get_id()) !== 1) {
            wc_add_notice('Invalid Monek ID: Order items have different merchant IDs.', 'error');
            $order->add_order_note(__('Invalid Monek ID: Order items have different merchant IDs.', 'monek-checkout'));
            throw new Exception('Order items have different merchant IDs.');
        }
        else {
            $order_items = $order->get_items();
            return MCWC_ConsignmentCart::mcwc_get_merchant_id_by_product_tags_from_pairs(reset($order_items)->get_product()->get_id())[0];
        }
    }

    /**
     * Get the merchant ID for the Monek payment gateway 
     *
     * @param WC_Order $order
     * @return string
     */
    private function mcwc_get_merchant_id(WC_Order $order): string
    {
        if ($this->is_consignment_mode_active) {
            return $this->mcwc_get_consignment_merchant_id($order);
        } else {
            return $this->get_option('merchant_id');
        }
    }

    /**
     * Get the settings for the Monek payment gateway 
     *
     * @return void
     */
    private function mcwc_get_settings(): void
    {
        $this->title = __('Credit/Debit Card', 'monek-checkout');
        $this->description = __('Pay securely with Monek.', 'monek-checkout');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->is_test_mode_active = isset($this->settings['test_mode']) && $this->settings['test_mode'] == 'yes';
        $this->show_google_pay = isset($this->settings['google_pay']) && $this->settings['google_pay'] == 'yes';
        $this->is_consignment_mode_active = isset($this->settings['consignment_mode']) && $this->settings['consignment_mode'] == 'yes';
        $this->country_dropdown = $this->get_option('country_dropdown');
        $this->basket_summary = $this->get_option('basket_summary');
        $this->disable_basket = isset($this->settings['basket_disable']) && $this->settings['basket_disable'] == 'yes';

        $this->publishable_key = $this->is_test_mode_active
            ? trim($this->get_option('test_publishable_key'))
            : trim($this->get_option('live_publishable_key'));

        $this->secret_key = $this->is_test_mode_active
            ? trim($this->get_option('test_secret_key'))
            : trim($this->get_option('live_secret_key'));
    }

    /**
     * Initialise the server completion HTTP client if credentials are available.
     */
    private function mcwc_bootstrap_server_completion_client(): void
    {
        if (empty($this->secret_key)) {
            $this->server_completion_client = null;
            return;
        }

        $api_base = $this->mcwc_get_checkout_api_base_url();
        $this->server_completion_client = new MCWC_ServerCompletionClient($this->secret_key, $api_base);
    }

    /**
     * Capture Store API payment data for use during validation and processing.
     *
     * @param array|mixed            $data           Payment data provided by the Store API request.
     * @param string                 $payment_method The selected payment method id.
     * @param \WP_REST_Request|null $request        The incoming REST request, when available.
     *
     * @return array|mixed
     */
    public function mcwc_capture_store_api_payment_data($data, $payment_method, $request)
    {
        if ($payment_method !== $this->id) {
            return $data;
        }

        $this->store_api_payment_data = is_array($data) ? $data : [];

        return $data;
    }

    /**
     * Retrieve a payment field from either the classic POST globals or Store API payload.
     */
    private function mcwc_get_request_payment_field(string $key, string $mode = 'text'): string
    {
        $value = null;

        if (isset($_POST[$key])) {
            $value = $_POST[$key];
        } elseif (isset($this->store_api_payment_data[$key])) {
            $value = $this->store_api_payment_data[$key];
        }

        if (null === $value) {
            return '';
        }

        if (is_array($value)) {
            $value = wp_json_encode($value);
        }

        $value = wp_unslash((string) $value);

        if ('json' === $mode) {
            return $value;
        }

        return sanitize_text_field($value);
    }

    /**
     * Determine the Checkout API base URL for the current environment.
     */
    private function mcwc_get_checkout_api_base_url(): string
    {
        $default = $this->is_test_mode_active ? self::CHECKOUT_API_BASE_TEST : self::CHECKOUT_API_BASE_LIVE;

        /**
         * Filter the Checkout API base URL used by the plugin.
         *
         * @since 4.0.0
         *
         * @param string               $default_url Default API base.
         * @param bool                 $is_test     Whether the gateway operates in test mode.
         * @param MCWC_MonekGateway    $gateway     Gateway instance.
         */
        $filtered = apply_filters('mcwc_checkout_api_base_url', $default, $this->is_test_mode_active, $this);

        return trailingslashit($filtered);
    }

    /**
     * Determine the URL of the Checkout SDK for the current environment.
     */
    private function mcwc_get_checkout_js_url(): string
    {
        $default = $this->is_test_mode_active ? self::CHECKOUT_JS_URL_TEST : self::CHECKOUT_JS_URL_LIVE;

        /**
         * Filter the Checkout SDK URL used to load the embedded iframe.
         *
         * @since 4.0.0
         *
         * @param string            $default_url Default SDK URL.
         * @param bool              $is_test     Whether test mode is active.
         * @param MCWC_MonekGateway $gateway     Gateway instance.
         */
        return (string) apply_filters('mcwc_checkout_js_url', $default, $this->is_test_mode_active, $this);
    }

   /**
     * Initialise the form fields for the Monek payment gateway 
     *
     * @return void
     */
    public function mcwc_init_form_fields(): void
    {
        $country_codes = include 'Model/MCWC_CountryCodes.php';
        $consignment_mode = $this->get_option('consignment_mode');

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enabled', 'monek-checkout'),
                'type' => 'checkbox',
                'label' => __('Enable this payment gateway', 'monek-checkout'),
                'default' => 'no'
            ],

            'end_of_section_1' => ['type' => 'title'],

            // Monek ID Settings Section
            'monek_id_section_title' => [
                'title' => __('Monek ID Settings', 'monek-checkout'),
                'type' => 'title',
                'description' => __('Configure the Monek ID.', 'monek-checkout'),
            ],
            'merchant_id' => [
                'title' => __('Monek ID', 'monek-checkout'),
                'type' => 'number',
                'description' => __("Your Monek ID, a unique code that connects your business with Monek. This ID helps streamline transactions and communication between your account and Monek's systems.", 'monek-checkout'),
                'default' => '',
                'desc_tip' => true
            ],
            'consignment_mode' => [
                'title' => __('Enable Consignment Sales', 'monek-checkout'),
                'type' => 'checkbox',
                'label' => isset($consignment_mode) && $consignment_mode == 'yes'
                    ? sprintf(
                        __('Monek ID per product. <a href="%s">Configure Consignment IDs</a>.', 'monek-checkout'),
                        admin_url('admin.php?page=wc-settings&tab=products&section=monek_consigment_ids')
                    )
                    : __('Monek ID per product.', 'monek-checkout'),
                'default' => 'no',
                'description' => __('If enabled, the Monek ID field will be hidden and the Monek ID will need to be configured per product.', 'monek-checkout'),
                'desc_tip' => true
            ],


            'end_of_section_2' => ['type' => 'title'],

            // General Settings Section
            'general_section_title' => [
                'title' => __('General Settings', 'monek-checkout'),
                'type' => 'title',
                'description' => __('Configure the basic settings for the Monek payment gateway. ', 'monek-checkout'),
            ],
            'test_mode' => [
                'title' => __('Trial Features', 'monek-checkout'),
                'type' => 'checkbox',
                'default' => 'no',
                'label' => __('Enable trial features', 'monek-checkout'),
                'description' => __('Enable this option to access trial features. Trial features provide early access to new functionalities and enhancements that are currently in testing.', 'monek-checkout'),
                'desc_tip' => true
            ],
            'google_pay' => [
                'title' => __('Enable GooglePay', 'monek-checkout'),
                'type' => 'checkbox',
                'default' => 'no',
                'label' => __('Merchants must adhere to the <a href="https://payments.developers.google.com/terms/aup" target="_blank">Google Pay APIs Acceptable Use Policy</a> and accept the terms defined in the <a href="https://payments.developers.google.com/terms/sellertos" target="_blank">Google Pay API Terms of Service</a>.', 'monek-checkout'),
                'description' => __('Enable this option to provide access to GooglePay as a payment option. ', 'monek-checkout'),
                'desc_tip' => true
            ],
            'test_publishable_key' => [
                'title' => __('Test publishable key', 'monek-checkout'),
                'type' => 'text',
                'description' => __('Public key provided by Monek for use with the Checkout SDK in test mode.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'test_secret_key' => [
                'title' => __('Test secret key', 'monek-checkout'),
                'type' => 'password',
                'description' => __('Secret key used to authenticate server-completed payments in test mode.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'live_publishable_key' => [
                'title' => __('Live publishable key', 'monek-checkout'),
                'type' => 'text',
                'description' => __('Public key provided by Monek for the Checkout SDK in live mode.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'live_secret_key' => [
                'title' => __('Live secret key', 'monek-checkout'),
                'type' => 'password',
                'description' => __('Secret key used to authenticate server-completed payments in live mode.', 'monek-checkout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'country_dropdown' => [
                'title' => __('Country', 'monek-checkout'),
                'type' => 'select',
                'options' => $country_codes,
                'default' => '826', // Set default to United Kingdom
                'description' => __('Set your location', 'monek-checkout'),
                'id' => 'country_dropdown_field',
                'desc_tip' => true
            ],
            'basket_summary' => [
                'title' => __('Basket Summary', 'monek-checkout'),
                'type' => 'text',
                'description' => __('This section allows you to customise the basket summary that is required as a purchase summary by PayPal.', 'monek-checkout'),
                'default' => 'Goods',
                'desc_tip' => true
            ],
            'basket_disable' => [
                'title' => __('Disable Basket Breakdown', 'monek-checkout'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Enable this option if you use custom plugins that are incompatible with the hosted checkout page basket', 'monek-checkout'),
                'desc_tip' => true
            ],
        ];
    }

    /**
     * Output the container that hosts the embedded checkout component.
     */
    public function payment_fields(): void
    {
        echo '<div id="mcwc-checkout-wrapper" class="mcwc-checkout-wrapper">';
        echo '<div id="mcwc-checkout-messages" class="mcwc-checkout-messages" role="alert" aria-live="polite"></div>';
        echo '<div id="mcwc-express-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '<div id="mcwc-checkout-container" class="mcwc-sdk-surface" aria-live="polite"></div>';
        echo '</div>';
        echo '<input type="hidden" name="monek_payment_token" id="monek_payment_token" value="" />';
        echo '<input type="hidden" name="monek_checkout_context" id="monek_checkout_context" value="" />';
        echo '<input type="hidden" name="monek_checkout_session_id" id="monek_checkout_session_id" value="" />';
    }

    /**
     * Enqueue the assets required to display the embedded checkout UI.
     */
    public function mcwc_enqueue_checkout_assets(): void
    {
        if ((!is_checkout() && !is_checkout_pay_page()) || $this->enabled !== 'yes') {
            return;
        }

        if (empty($this->publishable_key)) {
            return;
        }

        $sdk_handle = 'mcwc-checkout-sdk';
        if (!wp_script_is($sdk_handle, 'registered')) {
            wp_register_script($sdk_handle, $this->mcwc_get_checkout_js_url(), [], null, true);
        }

        $script_handle = 'mcwc-embedded-checkout';
        wp_register_script(
            $script_handle,
            plugins_url('assets/js/monek-embedded-checkout.js', __FILE__),
            ['jquery', $sdk_handle],
            mcwc_get_monek_plugin_version(),
            true
        );

        $initial_amount_minor = 0;
        if (WC()->cart instanceof WC_Cart) {
            $totals = WC()->cart->get_totals();
            $initial_total = isset($totals['total']) ? (float) $totals['total'] : 0.0;
            $initial_amount_minor = MCWC_TransactionHelper::mcwc_convert_decimal_to_flat($initial_total);
        }

        $settings = [
            'publishableKey' => $this->publishable_key,
            'countryCode' => $this->country_dropdown ?: '826',
            'currencyNumeric' => MCWC_TransactionHelper::mcwc_get_iso4217_currency_code(),
            'currencyDecimals' => wc_get_price_decimals(),
            'basketSummary' => $this->basket_summary ?: __('Goods', 'monek-checkout'),
            'testMode' => $this->is_test_mode_active,
            'showExpress' => $this->show_google_pay,
            'initialAmountMinor' => $initial_amount_minor,
            'gatewayId' => $this->id,
            'strings' => [
                'initialising' => __('Initialising secure card fields…', 'monek-checkout'),
                'tokenError' => __('We were unable to secure your card details. Please try again.', 'monek-checkout'),
            ],
        ];

        wp_localize_script($script_handle, 'mcwcCheckoutSettings', $settings);

        wp_enqueue_script($sdk_handle);
        wp_enqueue_script($script_handle);

        $style_handle = 'mcwc-embedded-checkout';
        if (!wp_style_is($style_handle, 'registered')) {
            wp_register_style(
                $style_handle,
                plugins_url('assets/css/monek-embedded-checkout.css', __FILE__),
                [],
                mcwc_get_monek_plugin_version()
            );
        }
        wp_enqueue_style($style_handle);
    }

    /**
     * Ensure a token has been supplied before WooCommerce processes the order.
     *
     * @param array           $data   Checkout data.
     * @param WP_Error|object $errors Validation errors object.
     */
    public function mcwc_require_token_during_validation($data, $errors): void
    {
        $selected_method = $data['payment_method'] ?? '';
        if ($selected_method !== $this->id) {
            return;
        }

        $token = $this->mcwc_get_request_payment_field('monek_payment_token');
        if (empty($token)) {
            $errors->add('monek_checkout_missing_token', __('Please enter your card details before placing the order.', 'monek-checkout'));
        }
    }

    /**
     * Process the payment for the Monek payment gateway 
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Unable to load the order for payment.', 'monek-checkout'), 'error');
            return [];
        }

        if (null === $this->server_completion_client) {
            wc_add_notice(__('The Monek gateway is missing API credentials. Please contact the store owner.', 'monek-checkout'), 'error');
            return [];
        }

        $token = $this->mcwc_get_request_payment_field('monek_payment_token');

        if (empty($token)) {
            wc_add_notice(__('Please enter your card details before placing the order.', 'monek-checkout'), 'error');
            return [];
        }

        $context = [];
        $raw_context = $this->mcwc_get_request_payment_field('monek_checkout_context', 'json');
        if (!empty($raw_context)) {
            $decoded = json_decode($raw_context, true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        $session_id = $this->mcwc_get_request_payment_field('monek_checkout_session_id');

        if (!empty($session_id) && (!isset($context['sessionId']) || empty($context['sessionId']))) {
            $context['sessionId'] = $session_id;
        }

        try {
            $merchant_id = $this->mcwc_get_merchant_id($order);
        } catch (Exception $exception) {
            wc_add_notice($exception->getMessage(), 'error');
            return [];
        }

        $payload = $this->server_payload_builder->build(
            $order,
            $merchant_id,
            $this->get_option('country_dropdown'),
            $token,
            $this->basket_summary ?: __('Goods', 'monek-checkout'),
            $session_id,
            $context
        );

        $response = $this->server_completion_client->complete_payment($payload);

        if (is_wp_error($response)) {
            $this->mcwc_handle_payment_error($order, $response->get_error_message());
            return [];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($status_code >= 200 && $status_code < 300) {
            $transaction_id = '';
            if (is_array($body)) {
                $transaction_id = $body['payment']['id'] ?? ($body['id'] ?? '');
            }

            if (!empty($transaction_id)) {
                $order->set_transaction_id($transaction_id);
            }

            $order->add_order_note(__('Payment authorised via embedded checkout.', 'monek-checkout'));
            $order->payment_complete();
            if (WC()->cart instanceof WC_Cart) {
                WC()->cart->empty_cart();
            }

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            ];
        }

        $message = '';
        if (is_array($body)) {
            $message = $body['error']['message'] ?? $body['message'] ?? '';
        }

        if (empty($message)) {
            $message = wp_remote_retrieve_response_message($response);
        }

        if (empty($message)) {
            $message = __('Payment could not be completed. Please try again.', 'monek-checkout');
        }

        $this->mcwc_handle_payment_error($order, $message);

        return [];
    }

    /**
     * Handle a declined or failed payment response.
     */
    private function mcwc_handle_payment_error(WC_Order $order, string $message): void
    {
        $clean_message = wp_strip_all_tags($message);
        wc_add_notice($clean_message, 'error');
        $order->add_order_note(sprintf(__('Payment failed: %s', 'monek-checkout'), $clean_message));
    }

    /**
     * Setup the properties for the Monek payment gateway 
     *
     * @return void
     */
    protected function mcwc_setup_properties(): void
    {
        $this->id = self::GATEWAY_ID;
        $this->icon = plugins_url('img/Monek-Logo100x12.png', __FILE__);
        $this->has_fields = true;
        $this->method_title = __('Monek', 'monek-checkout');
        $this->method_description = __('Pay securely with Monek using your credit/debit card.', 'monek-checkout');
    }

}

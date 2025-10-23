<?php
/**
 * Handles completing payments directly with the Monek Checkout API when using server completion.
 *
 * @package Monek
 */
class MCWC_ServerCompletionClient
{
    private string $secret_key;
    private string $api_base_url;

    /**
     * @param string $secret_key   Secret key used to authorise requests against the Checkout API.
     * @param string $api_base_url Base URL for the Checkout API.
     */
    public function __construct(string $secret_key, string $api_base_url)
    {
        $this->secret_key = $secret_key;
        $this->api_base_url = $api_base_url;
    }

    /**
     * Submit the payment payload to the Checkout API.
     *
     * @param array $payload
     * @return array|WP_Error
     */
    public function complete_payment(array $payload)
    {
        $endpoint = trailingslashit($this->api_base_url) . 'payments';

        return wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => $this->build_headers(),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ]);
    }

    /**
     * Construct the headers for the request.
     */
    private function build_headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->secret_key,
        ];
    }
}

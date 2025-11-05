<?php

namespace Monek\Checkout\Infrastructure\WordPress\Admin;

use SplFileObject;
use Throwable;
use WP_Error;

class ToolsPage
{
    private const PAGE_SLUG = 'monek-tools';
    private const LOG_LINE_LIMIT = 20;

    public function register(): void
    {
        if (! function_exists('add_management_page')) {
            return;
        }

        add_management_page(
            __('Monek diagnostics', 'monek-checkout'),
            __('Monek', 'monek-checkout'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'monek-checkout'));
        }

        $webhookStatus = $this->evaluateWebhook();
        $credentialStatus = $this->evaluateCredentials();
        $logs = $this->collectLogs();

        $webhookEndpoint = function_exists('rest_url') ? rest_url('monek/v1/webhook') : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Monek diagnostics', 'monek-checkout'); ?></h1>
            <p><?php esc_html_e('Use this page to review recent activity, verify webhook connectivity and confirm your credentials are set correctly.', 'monek-checkout'); ?></p>

            <h2><?php esc_html_e('Webhook heartbeat', 'monek-checkout'); ?></h2>
            <?php $this->renderNotice($webhookStatus); ?>
            <?php if ($webhookEndpoint !== '') : ?>
                <p><strong><?php esc_html_e('Endpoint URL:', 'monek-checkout'); ?></strong> <code><?php echo esc_html($webhookEndpoint); ?></code></p>
            <?php endif; ?>
            <?php if ($webhookStatus['details'] !== '') : ?>
                <p><code><?php echo esc_html($webhookStatus['details']); ?></code></p>
            <?php endif; ?>

            <h2><?php esc_html_e('Credential validation', 'monek-checkout'); ?></h2>
            <?php $this->renderNotice($credentialStatus); ?>
            <?php if ($credentialStatus['details'] !== '') : ?>
                <p><code><?php echo esc_html($credentialStatus['details']); ?></code></p>
            <?php endif; ?>

            <h2><?php esc_html_e('Recent logs', 'monek-checkout'); ?></h2>
            <?php if ($logs === []) : ?>
                <p><?php esc_html_e('No log files were found for the Monek gateway yet.', 'monek-checkout'); ?></p>
            <?php else : ?>
                <?php foreach ($logs as $log ) : ?>
                    <h3><?php echo esc_html($log['label']); ?></h3>
                    <?php if ($log['path'] !== null) : ?>
                        <p><code><?php echo esc_html($log['path']); ?></code></p>
                    <?php endif; ?>
                    <?php if ($log['entries'] === []) : ?>
                        <p><?php esc_html_e('The log file exists but no entries could be read.', 'monek-checkout'); ?></p>
                    <?php else : ?>
                        <pre><?php echo esc_html(implode("\n", $log['entries'])); ?></pre>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderNotice(array $result): void
    {
        $status = $result['status'] ?? 'warning';
        $message = $result['message'] ?? '';

        $class = 'notice';
        switch ($status) {
            case 'success':
                $class .= ' notice-success';
                break;
            case 'error':
                $class .= ' notice-error';
                break;
            default:
                $class .= ' notice-warning';
        }
        ?>
        <div class="<?php echo esc_attr($class); ?>"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    /**
     * @return array{status:string,message:string,details:string}
     */
    private function evaluateWebhook(): array
    {
        if (! function_exists('wp_remote_post') || ! function_exists('rest_url')) {
            return [
                'status' => 'warning',
                'message' => __('WordPress REST API functions are unavailable.', 'monek-checkout'),
                'details' => '',
            ];
        }

        $endpoint = rest_url('monek/v1/webhook');
        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['diagnostics' => true]),
            ]
        );

        if ($response instanceof WP_Error) {
            return [
                'status' => 'error',
                'message' => __('Failed to contact the webhook endpoint.', 'monek-checkout'),
                'details' => $this->truncateDetails($response->get_error_message()),
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($statusCode === 401) {
            return [
                'status' => 'success',
                'message' => __('The webhook endpoint responded as expected (signature required).', 'monek-checkout'),
                'details' => sprintf(__('Response: HTTP %d', 'monek-checkout'), $statusCode),
            ];
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'status' => 'success',
                'message' => __('The webhook endpoint is reachable.', 'monek-checkout'),
                'details' => sprintf(__('Response: HTTP %1$d %2$s', 'monek-checkout'), $statusCode, $this->truncateDetails($body)),
            ];
        }

        return [
            'status' => 'warning',
            'message' => __('The webhook endpoint responded with an unexpected status code.', 'monek-checkout'),
            'details' => sprintf(__('Response: HTTP %1$d %2$s', 'monek-checkout'), $statusCode, $this->truncateDetails($body)),
        ];
    }

    /**
     * @return array{status:string,message:string,details:string}
     */
    private function evaluateCredentials(): array
    {
        if (! function_exists('get_option')) {
            return [
                'status' => 'warning',
                'message' => __('Unable to read gateway settings from the database.', 'monek-checkout'),
                'details' => '',
            ];
        }

        $settings = get_option('woocommerce_monek-checkout_settings', []);
        $publishableKey = '';
        $secretKey = '';

        if (is_array($settings)) {
            $publishableKey = isset($settings['publishable_key']) ? trim((string) $settings['publishable_key']) : '';
            $secretKey = isset($settings['secret_key']) ? trim((string) $settings['secret_key']) : '';
        }

        if ($publishableKey === '' || $secretKey === '') {
            return [
                'status' => 'warning',
                'message' => __('Add your publishable and secret keys on the payment settings screen to run the validation test.', 'monek-checkout'),
                'details' => '',
            ];
        }

        if (! function_exists('wp_remote_post')) {
            return [
                'status' => 'warning',
                'message' => __('WordPress HTTP functions are unavailable.', 'monek-checkout'),
                'details' => '',
            ];
        }

        $response = wp_remote_post(
            'https://api.monek.com/embedded-checkout/payment',
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $publishableKey,
                    'X-Secret-Key' => $secretKey,
                ],
                'body' => wp_json_encode(['diagnostics' => true]),
            ]
        );

        if ($response instanceof WP_Error) {
            return [
                'status' => 'error',
                'message' => __('Failed to reach the Monek API.', 'monek-checkout'),
                'details' => $this->truncateDetails($response->get_error_message()),
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $details = sprintf(__('Response: HTTP %1$d %2$s', 'monek-checkout'), $statusCode, $this->truncateDetails($body));

        if ($statusCode === 401 || $statusCode === 403) {
            return [
                'status' => 'error',
                'message' => __('The Monek API rejected the provided credentials.', 'monek-checkout'),
                'details' => $details,
            ];
        }

        if ($statusCode >= 200 && $statusCode < 500) {
            return [
                'status' => 'success',
                'message' => __('The Monek API is reachable with the configured credentials.', 'monek-checkout'),
                'details' => $details,
            ];
        }

        return [
            'status' => 'warning',
            'message' => __('Received an unexpected response from the Monek API.', 'monek-checkout'),
            'details' => $details,
        ];
    }

    /**
     * @return array<int,array{label:string,path:?string,entries:array<int,string>}> 
     */
    private function collectLogs(): array
    {
        if (! function_exists('wc_get_log_dir')) {
            return [];
        }

        $logs = [];
        $sources = [
            'monek' => __('Checkout activity', 'monek-checkout'),
            'monek-webhook' => __('Webhook activity', 'monek-checkout'),
        ];

        foreach ($sources as $handle => $label) {
            $path = $this->resolveLatestLogFile($handle);
            $logs[] = [
                'label' => $label,
                'path' => $path,
                'entries' => $path ? $this->readLogTail($path, self::LOG_LINE_LIMIT) : [],
            ];
        }

        return $logs;
    }

    private function resolveLatestLogFile(string $handle): ?string
    {
        $directory = wc_get_log_dir();
        $safeHandle = $this->sanitiseHandle($handle);
        $pattern = trailingslashit($directory) . $safeHandle . '-*.log';
        $files = glob($pattern);

        if (! is_array($files) || $files === []) {
            return null;
        }

        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        foreach ($files as $file) {
            if (is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function readLogTail(string $path, int $limit): array
    {
        try {
            $file = new SplFileObject($path, 'r');
        } catch (Throwable $exception) {
            return [];
        }

        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        $lines = [];
        for ($lineNumber = $lastLine; $lineNumber >= 0 && count($lines) < $limit; $lineNumber--) {
            $file->seek($lineNumber);
            $line = rtrim((string) $file->current(), "\r\n");
            array_unshift($lines, $line);
        }

        return $lines;
    }

    private function sanitiseHandle(string $handle): string
    {
        $sanitised = preg_replace('/[^a-z0-9\-]/i', '', $handle);

        return $sanitised !== null && $sanitised !== '' ? strtolower($sanitised) : 'monek';
    }

    private function truncateDetails(string $value): string
    {
        $trimmed = trim(wp_strip_all_tags($value));
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > 400) {
            return mb_substr($trimmed, 0, 400) . '…';
        }

        return $trimmed;
    }
}

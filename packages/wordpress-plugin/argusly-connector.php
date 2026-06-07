<?php
/**
 * Plugin Name: Argusly Connector
 * Plugin URI: https://argusly.com
 * Description: First-party connector for registering WordPress sites with Argusly and receiving content sync events.
 * Version: 0.1.0-dev
 * Author: Argusly
 * Author URI: https://argusly.com
 * Text Domain: argusly-connector
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ARGUSLY_CONNECTOR_VERSION', '0.1.0-dev');
define('ARGUSLY_CONNECTOR_OPTION_GROUP', 'argusly_connector');
define('ARGUSLY_CONNECTOR_API_URL_OPTION', 'argusly_connector_api_url');
define('ARGUSLY_CONNECTOR_TOKEN_OPTION', 'argusly_connector_token');

final class Argusly_Connector
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_argusly_connector_health_check', [self::class, 'handleHealthCheck']);
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
    }

    public static function registerAdminMenu(): void
    {
        add_options_page(
            __('Argusly Connector', 'argusly-connector'),
            __('Argusly Connector', 'argusly-connector'),
            'manage_options',
            'argusly-connector',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(ARGUSLY_CONNECTOR_OPTION_GROUP, ARGUSLY_CONNECTOR_API_URL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://api.argusly.com',
        ]);

        register_setting(ARGUSLY_CONNECTOR_OPTION_GROUP, ARGUSLY_CONNECTOR_TOKEN_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeToken'],
            'default' => '',
        ]);
    }

    public static function sanitizeToken(string $value): string
    {
        return trim(sanitize_text_field($value));
    }

    public static function renderSettingsPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $healthStatus = get_transient('argusly_connector_health_status');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Argusly Connector', 'argusly-connector'); ?></h1>

            <?php if (is_array($healthStatus)) : ?>
                <div class="notice notice-<?php echo esc_attr($healthStatus['type'] ?? 'info'); ?> is-dismissible">
                    <p><?php echo esc_html($healthStatus['message'] ?? ''); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(ARGUSLY_CONNECTOR_OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(ARGUSLY_CONNECTOR_API_URL_OPTION); ?>">
                                <?php esc_html_e('Argusly API URL', 'argusly-connector'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                id="<?php echo esc_attr(ARGUSLY_CONNECTOR_API_URL_OPTION); ?>"
                                name="<?php echo esc_attr(ARGUSLY_CONNECTOR_API_URL_OPTION); ?>"
                                type="url"
                                class="regular-text"
                                value="<?php echo esc_attr((string) get_option(ARGUSLY_CONNECTOR_API_URL_OPTION, 'https://api.argusly.com')); ?>"
                                placeholder="https://api.argusly.com"
                            >
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(ARGUSLY_CONNECTOR_TOKEN_OPTION); ?>">
                                <?php esc_html_e('Argusly token', 'argusly-connector'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                id="<?php echo esc_attr(ARGUSLY_CONNECTOR_TOKEN_OPTION); ?>"
                                name="<?php echo esc_attr(ARGUSLY_CONNECTOR_TOKEN_OPTION); ?>"
                                type="password"
                                class="regular-text"
                                value="<?php echo esc_attr((string) get_option(ARGUSLY_CONNECTOR_TOKEN_OPTION, '')); ?>"
                                autocomplete="new-password"
                            >
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save connector settings', 'argusly-connector')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('argusly_connector_health_check'); ?>
                <input type="hidden" name="action" value="argusly_connector_health_check">
                <?php submit_button(__('Run health check', 'argusly-connector'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public static function handleHealthCheck(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the Argusly connector.', 'argusly-connector'));
        }

        check_admin_referer('argusly_connector_health_check');

        $response = self::apiRequest('/api/v1/connectors/heartbeat', [
            'site_url' => home_url(),
            'connector' => [
                'name' => 'argusly-wordpress-connector',
                'version' => ARGUSLY_CONNECTOR_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ],
        ]);

        if (is_wp_error($response)) {
            set_transient('argusly_connector_health_status', [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Argusly health check failed: %s', 'argusly-connector'),
                    $response->get_error_message()
                ),
            ], 60);
        } else {
            set_transient('argusly_connector_health_status', [
                'type' => 'success',
                'message' => __('Argusly health check completed.', 'argusly-connector'),
            ], 60);
        }

        wp_safe_redirect(admin_url('options-general.php?page=argusly-connector'));
        exit;
    }

    public static function registerRestRoutes(): void
    {
        register_rest_route('argusly/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'handleRemoteHealth'],
            'permission_callback' => [self::class, 'authorizeWebhook'],
        ]);

        register_rest_route('argusly/v1', '/posts', [
            'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
            'callback' => [self::class, 'handlePostsPlaceholder'],
            'permission_callback' => [self::class, 'authorizeWebhook'],
        ]);

        register_rest_route('argusly/v1', '/webhooks/(?P<event>[a-z0-9-_]+)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handleWebhook'],
            'permission_callback' => [self::class, 'authorizeWebhook'],
        ]);

        register_rest_route('argusly/v1', '/content/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handleContentSync'],
            'permission_callback' => [self::class, 'authorizeWebhook'],
        ]);
    }

    public static function authorizeWebhook(WP_REST_Request $request): bool
    {
        $configuredToken = (string) get_option(ARGUSLY_CONNECTOR_TOKEN_OPTION, '');
        $providedToken = self::bearerToken($request);

        return $configuredToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    public static function handleRemoteHealth(WP_REST_Request $request): WP_REST_Response
    {
        // TODO(argusly): Review remote health payload once the platform callback contract is finalized.
        return new WP_REST_Response([
            'status' => 'ok',
            'connector' => 'argusly-wordpress-connector',
            'version' => ARGUSLY_CONNECTOR_VERSION,
            'site_url' => home_url(),
        ], 200);
    }

    public static function handlePostsPlaceholder(WP_REST_Request $request): WP_REST_Response
    {
        // TODO(argusly): Map Argusly post payloads to WordPress posts, taxonomies, media, and SEO metadata.
        return new WP_REST_Response([
            'status' => 'accepted',
            'method' => $request->get_method(),
        ], $request->get_method() === 'GET' ? 200 : 202);
    }

    public static function handleWebhook(WP_REST_Request $request): WP_REST_Response
    {
        // TODO(argusly): Review webhook event contracts once the platform webhook API is finalized.
        return new WP_REST_Response([
            'status' => 'accepted',
            'event' => (string) $request['event'],
        ], 202);
    }

    public static function handleContentSync(WP_REST_Request $request): WP_REST_Response
    {
        // TODO(argusly): Map canonical Argusly content payloads to WordPress posts, taxonomies, media, and SEO metadata.
        return new WP_REST_Response([
            'status' => 'accepted',
            'received_keys' => array_keys((array) $request->get_json_params()),
        ], 202);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function apiRequest(string $path, array $payload): array|WP_Error
    {
        $baseUrl = rtrim((string) get_option(ARGUSLY_CONNECTOR_API_URL_OPTION, 'https://api.argusly.com'), '/');
        $token = (string) get_option(ARGUSLY_CONNECTOR_TOKEN_OPTION, '');

        if ($token === '') {
            return new WP_Error('argusly_missing_token', __('Set an Argusly token before running connector actions.', 'argusly-connector'));
        }

        $response = wp_remote_post($baseUrl . '/' . ltrim($path, '/'), [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            return new WP_Error(
                'argusly_api_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Argusly API returned HTTP %d.', 'argusly-connector'),
                    $status
                )
            );
        }

        return [
            'status' => $status,
            'body' => json_decode((string) wp_remote_retrieve_body($response), true),
        ];
    }

    private static function bearerToken(WP_REST_Request $request): string
    {
        $authorization = (string) $request->get_header('authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }
}

Argusly_Connector::boot();

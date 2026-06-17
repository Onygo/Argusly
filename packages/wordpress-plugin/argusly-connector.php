<?php
/**
 * Plugin Name: Argusly Connector
 * Plugin URI: https://argusly.com
 * Description: First-party connector for publishing Argusly content into WordPress.
 * Version: 1.0.0
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

define('ARGUSLY_CONNECTOR_VERSION', '1.0.0');
define('ARGUSLY_CONNECTOR_OPTION_GROUP', 'argusly_connector');
define('ARGUSLY_CONNECTOR_API_URL_OPTION', 'argusly_connector_api_url');
define('ARGUSLY_CONNECTOR_TOKEN_OPTION', 'argusly_connector_token');

final class Argusly_Connector
{
    private const REST_NAMESPACE = 'argusly/v1';

    /** @var array<int, string> */
    private const META_KEYS = [
        'argusly_draft_id',
        'argusly_content_id',
        'argusly_brief_id',
        'argusly_publication_id',
        'argusly_origin',
        'argusly_language',
        'argusly_locale',
        'argusly_destination_id',
        'argusly_is_translation',
        'argusly_source_draft_id',
        'argusly_external_key',
        'argusly_origin_type',
        'argusly_source',
        'argusly_generation_mode',
        'argusly_automation_id',
        'argusly_automation_run_id',
        'argusly_family_id',
        'argusly_translation_source_content_id',
        'argusly_translation_source_locale',
        'argusly_is_source_locale',
        'argusly_publish_url_key',
        'argusly_canonical_url_key',
        '_argusly_content_id',
        '_argusly_locale',
        '_argusly_destination_id',
        '_argusly_seo_title',
        '_argusly_meta_description',
        '_argusly_canonical_url',
        '_argusly_primary_keyword',
        '_argusly_og_image',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('init', [self::class, 'registerPostMeta']);
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
            'sanitize_callback' => [self::class, 'sanitizeApiUrl'],
            'default' => 'https://api.argusly.com',
        ]);

        register_setting(ARGUSLY_CONNECTOR_OPTION_GROUP, ARGUSLY_CONNECTOR_TOKEN_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeToken'],
            'default' => '',
        ]);
    }

    public static function registerPostMeta(): void
    {
        foreach (self::META_KEYS as $key) {
            register_meta('post', $key, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public static function sanitizeApiUrl(string $value): string
    {
        $url = trim($value);

        return $url !== '' ? esc_url_raw($url) : 'https://api.argusly.com';
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
                                <?php esc_html_e('Argusly site token', 'argusly-connector'); ?>
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

        $response = self::apiRequest('/api/v1/connectors/heartbeat', self::heartbeatPayload());

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
        register_rest_route(self::REST_NAMESPACE, '/ping', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'handleRemoteHealth'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'handleRemoteHealth'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/heartbeat', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'handleRemoteHealth'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/posts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'createPost'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/posts/lookup', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'lookupPost'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)', [
            'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
            'callback' => [self::class, 'handlePostById'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/posts/(?P<id>[\d]+)/featured-image', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'setFeaturedImage'],
            'permission_callback' => [self::class, 'authorizeRequest'],
        ]);
    }

    public static function authorizeRequest(WP_REST_Request $request): bool|WP_Error
    {
        $configuredToken = (string) get_option(ARGUSLY_CONNECTOR_TOKEN_OPTION, '');
        $providedToken = self::bearerToken($request);

        if ($configuredToken !== '' && $providedToken !== '' && hash_equals($configuredToken, $providedToken)) {
            return true;
        }

        return new WP_Error(
            'argusly_unauthorized',
            __('Invalid Argusly connector token.', 'argusly-connector'),
            ['status' => 401]
        );
    }

    public static function handleRemoteHealth(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'status' => 'ok',
            'platform' => 'wp',
            'connector_version' => ARGUSLY_CONNECTOR_VERSION,
            'site_url' => home_url(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ], 200);
    }

    public static function createPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = self::jsonPayload($request);
        $postarr = self::postArrayFromPayload($payload);

        $postId = wp_insert_post($postarr, true);
        if (is_wp_error($postId)) {
            return self::errorResponse($postId, 422);
        }

        self::storeArguslyMeta((int) $postId, $payload);

        return new WP_REST_Response(self::postResponse((int) $postId), 201);
    }

    public static function handlePostById(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($request->get_method() === 'GET') {
            return self::getPost($request);
        }

        return self::updatePost($request);
    }

    public static function updatePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request['id'];
        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            return self::notFoundResponse();
        }

        $payload = self::jsonPayload($request);
        $postarr = self::postArrayFromPayload($payload);
        $postarr['ID'] = $postId;

        $updated = wp_update_post($postarr, true);
        if (is_wp_error($updated)) {
            return self::errorResponse($updated, 422);
        }

        self::storeArguslyMeta($postId, $payload);

        return new WP_REST_Response(self::postResponse($postId), 200);
    }

    public static function getPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);
        if (! $post instanceof WP_Post) {
            return self::notFoundResponse();
        }

        return new WP_REST_Response(self::postResponse((int) $post->ID), 200);
    }

    public static function lookupPost(WP_REST_Request $request): WP_REST_Response
    {
        $criteria = self::lookupCriteria($request);
        if ($criteria === []) {
            return new WP_REST_Response(['exists' => false, 'error' => 'No lookup criteria supplied.'], 200);
        }

        $query = new WP_Query([
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array_map(
                static fn (string $key, string $value): array => [
                    'key' => $key,
                    'value' => $value,
                    'compare' => '=',
                ],
                array_keys($criteria),
                array_values($criteria)
            ),
        ]);

        $postId = (int) ($query->posts[0] ?? 0);
        if ($postId <= 0) {
            return new WP_REST_Response(['exists' => false, 'error' => 'Post not found.'], 200);
        }

        return new WP_REST_Response(self::postResponse($postId), 200);
    }

    public static function setFeaturedImage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request['id'];
        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            return self::notFoundResponse();
        }

        $payload = self::jsonPayload($request);
        $imageUrl = esc_url_raw((string) ($payload['featured_image_url'] ?? $payload['image_url'] ?? ''));
        if ($imageUrl === '') {
            return new WP_Error('argusly_missing_featured_image_url', __('A featured image URL is required.', 'argusly-connector'), ['status' => 422]);
        }

        $attachmentId = self::sideloadImage($imageUrl, $postId, (string) ($payload['featured_image_attribution'] ?? ''));
        if (is_wp_error($attachmentId)) {
            return self::errorResponse($attachmentId, 422);
        }

        set_post_thumbnail($postId, (int) $attachmentId);

        return new WP_REST_Response([
            'ok' => true,
            'post_id' => (string) $postId,
            'wp_post_id' => (string) $postId,
            'attachment_id' => (string) $attachmentId,
            'featured_image_id' => (string) $attachmentId,
            'featured_image_url' => wp_get_attachment_url((int) $attachmentId),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private static function heartbeatPayload(): array
    {
        return [
            'platform' => 'wp',
            'connector_version' => ARGUSLY_CONNECTOR_VERSION,
            'framework_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'site_url' => home_url(),
            'app_url' => home_url(),
            'capabilities' => [
                'create_content' => true,
                'update_content' => true,
                'publish_content' => true,
                'schedule_content' => true,
                'read_publication_status' => true,
                'preview_content' => true,
                'featured_image' => true,
            ],
            'plugins' => self::activePluginSlugs(),
            'active_plugins' => self::activePluginSlugs(),
            'environment' => wp_get_environment_type(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function activePluginSlugs(): array
    {
        $plugins = get_option('active_plugins', []);

        return array_values(array_map('sanitize_text_field', is_array($plugins) ? $plugins : []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonPayload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function postArrayFromPayload(array $payload): array
    {
        $status = sanitize_key((string) ($payload['status'] ?? 'draft'));
        if (! in_array($status, ['draft', 'publish', 'pending', 'private', 'future'], true)) {
            $status = 'draft';
        }

        $postType = sanitize_key((string) ($payload['post_type'] ?? 'post'));
        if ($postType === '' || ! post_type_exists($postType)) {
            $postType = 'post';
        }

        $postarr = [
            'post_type' => $postType,
            'post_status' => $status,
            'post_title' => sanitize_text_field((string) ($payload['title'] ?? '')),
            'post_content' => wp_kses_post((string) ($payload['content_html'] ?? $payload['content'] ?? $payload['rendered_html'] ?? '')),
            'post_excerpt' => sanitize_textarea_field((string) ($payload['excerpt'] ?? '')),
        ];

        $slug = sanitize_title((string) ($payload['slug'] ?? ''));
        if ($slug !== '') {
            $postarr['post_name'] = $slug;
        }

        return $postarr;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function storeArguslyMeta(int $postId, array $payload): void
    {
        $meta = [];
        foreach (['meta_input', 'wp_post_meta', 'meta'] as $source) {
            if (is_array($payload[$source] ?? null)) {
                $meta = array_replace($meta, $payload[$source]);
            }
        }

        $meta = array_replace($meta, array_filter([
            '_argusly_seo_title' => $payload['seo_title'] ?? $payload['meta_title'] ?? null,
            '_argusly_meta_description' => $payload['seo_meta_description'] ?? $payload['meta_description'] ?? null,
            '_argusly_canonical_url' => $payload['seo_canonical'] ?? $payload['canonical_url'] ?? null,
            '_argusly_primary_keyword' => $payload['primary_keyword'] ?? null,
            '_argusly_og_image' => $payload['seo_og_image'] ?? $payload['og_image'] ?? $payload['og_image_url'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));

        foreach (self::META_KEYS as $key) {
            if (! array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (is_scalar($value) || $value === null) {
                update_post_meta($postId, $key, sanitize_text_field((string) $value));
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function lookupCriteria(WP_REST_Request $request): array
    {
        $criteria = [];

        $metaKey = sanitize_key((string) $request->get_param('meta_key'));
        $metaValue = sanitize_text_field((string) $request->get_param('meta_value'));
        if ($metaKey !== '' && $metaValue !== '' && in_array($metaKey, self::META_KEYS, true)) {
            $criteria[$metaKey] = $metaValue;
        }

        foreach (self::META_KEYS as $key) {
            $value = sanitize_text_field((string) $request->get_param($key));
            if ($value !== '') {
                $criteria[$key] = $value;
            }
        }

        return $criteria;
    }

    /**
     * @return array<string, mixed>
     */
    private static function postResponse(int $postId): array
    {
        $post = get_post($postId);

        return [
            'exists' => $post instanceof WP_Post,
            'post_id' => (string) $postId,
            'wp_post_id' => (string) $postId,
            'id' => (string) $postId,
            'status' => $post instanceof WP_Post ? (string) $post->post_status : '',
            'post_status' => $post instanceof WP_Post ? (string) $post->post_status : '',
            'post_type' => $post instanceof WP_Post ? (string) $post->post_type : '',
            'link' => get_permalink($postId) ?: '',
            'url' => get_permalink($postId) ?: '',
            'published_url' => get_permalink($postId) ?: '',
            'modified' => $post instanceof WP_Post ? (string) $post->post_modified : '',
            'modified_gmt' => $post instanceof WP_Post ? (string) $post->post_modified_gmt : '',
            'argusly_content_id' => (string) get_post_meta($postId, 'argusly_content_id', true),
            'argusly_draft_id' => (string) get_post_meta($postId, 'argusly_draft_id', true),
            'argusly_publication_id' => (string) get_post_meta($postId, 'argusly_publication_id', true),
            'argusly_locale' => (string) get_post_meta($postId, 'argusly_locale', true),
        ];
    }

    private static function notFoundResponse(): WP_Error
    {
        return new WP_Error('argusly_post_not_found', __('Post not found.', 'argusly-connector'), ['status' => 404]);
    }

    private static function errorResponse(WP_Error $error, int $status): WP_Error
    {
        return new WP_Error($error->get_error_code(), $error->get_error_message(), ['status' => $status]);
    }

    private static function sideloadImage(string $imageUrl, int $postId, string $description): int|WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        return media_sideload_image($imageUrl, $postId, sanitize_text_field($description), 'id');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function apiRequest(string $path, array $payload): array|WP_Error
    {
        $baseUrl = rtrim((string) get_option(ARGUSLY_CONNECTOR_API_URL_OPTION, 'https://api.argusly.com'), '/');
        $token = (string) get_option(ARGUSLY_CONNECTOR_TOKEN_OPTION, '');

        if ($token === '') {
            return new WP_Error('argusly_missing_token', __('Set an Argusly site token before running connector actions.', 'argusly-connector'));
        }

        $response = wp_remote_post($baseUrl . '/' . ltrim($path, '/'), [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'X-Argusly-API-Key' => $token,
                'X-Argusly-Site' => home_url(),
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

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return [
            'status' => $status,
            'body' => is_array($body) ? $body : [],
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

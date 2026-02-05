<?php

/**
 * AI Provider Router
 *
 * Multi-provider AI routing for ABW-AI chat interface.
 * Supports OpenAI, Anthropic, and custom providers.
 *
 * @package ABW_AI
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class ABW_AI_Router
 *
 * Routes AI requests to configured providers (OpenAI, Anthropic, custom)
 * and manages tool execution for the chat interface.
 */
class ABW_AI_Router
{
    /**
     * Supported AI providers
     */
    const PROVIDER_OPENAI    = 'openai';
    const PROVIDER_ANTHROPIC = 'anthropic';
    const PROVIDER_CUSTOM    = 'custom';

    /**
     * Default models per provider
     */
    const DEFAULT_MODELS = [
        'openai'    => 'gpt-4-turbo-preview',
        'anthropic' => 'claude-3-5-sonnet-20241022',
    ];

    /**
     * API endpoints
     */
    const API_ENDPOINTS = [
        'openai'    => 'https://api.openai.com/v1/chat/completions',
        'anthropic' => 'https://api.anthropic.com/v1/messages',
    ];

    /**
     * Get the active AI provider
     *
     * @return string
     */
    public static function get_provider(): string
    {
        return get_option('abw_ai_provider', self::PROVIDER_OPENAI);
    }

    /**
     * Get API key for a provider
     *
     * @param string $provider Provider name
     * @return string
     */
    public static function get_api_key(string $provider): string
    {
        $key = '';

        switch ($provider) {
            case self::PROVIDER_OPENAI:
                $key = get_option('abw_openai_api_key', '');
                break;
            case self::PROVIDER_ANTHROPIC:
                $key = get_option('abw_anthropic_api_key', '');
                break;
            case self::PROVIDER_CUSTOM:
                $key = get_option('abw_custom_api_key', '');
                break;
        }

        // Decrypt if stored encrypted
        if (! empty($key) && function_exists('sodium_crypto_secretbox_open')) {
            $key = self::maybe_decrypt($key);
        }

        return $key;
    }

    /**
     * Get model for a provider
     *
     * @param string $provider Provider name
     * @return string
     */
    public static function get_model(string $provider): string
    {
        $model = get_option("abw_{$provider}_model", '');
        return ! empty($model) ? $model : (self::DEFAULT_MODELS[$provider] ?? '');
    }

    /**
     * Send a chat completion request
     *
     * @param array $messages Chat messages
     * @param array $tools    Available tools for function calling
     * @param array $options  Additional options
     * @return array|WP_Error
     */
    public static function chat(array $messages, array $tools = [], array $options = [])
    {
        $provider = $options['provider'] ?? self::get_provider();
        $api_key  = self::get_api_key($provider);

        if (empty($api_key)) {
            return new WP_Error('no_api_key', sprintf(
                __('No API key configured for %s. Please add your API key in ABW-AI settings.', 'abw-ai'),
                $provider
            ));
        }

        switch ($provider) {
            case self::PROVIDER_OPENAI:
                return self::chat_openai($messages, $tools, $options, $api_key);

            case self::PROVIDER_ANTHROPIC:
                return self::chat_anthropic($messages, $tools, $options, $api_key);

            case self::PROVIDER_CUSTOM:
                return self::chat_custom($messages, $tools, $options, $api_key);

            default:
                return new WP_Error('invalid_provider', __('Invalid AI provider specified.', 'abw-ai'));
        }
    }

    /**
     * Send chat request to OpenAI
     *
     * @param array  $messages Chat messages
     * @param array  $tools    Available tools
     * @param array  $options  Options
     * @param string $api_key  API key
     * @return array|WP_Error
     */
    private static function chat_openai(array $messages, array $tools, array $options, string $api_key)
    {
        $model = $options['model'] ?? self::get_model(self::PROVIDER_OPENAI);

        $body = [
            'model'    => $model,
            'messages' => self::format_messages_openai($messages),
        ];

        // Add tools if provided
        if (! empty($tools)) {
            $body['tools'] = self::format_tools_openai($tools);
            $body['tool_choice'] = 'auto';
        }

        // Add optional parameters
        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        $response = wp_remote_post(self::API_ENDPOINTS['openai'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => ABW_Background_Jobs::get_safe_timeout(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = $body['error']['message'] ?? 'Unknown OpenAI API error';
            return new WP_Error('openai_error', $error_message, ['status' => $status_code]);
        }

        return self::parse_openai_response($body);
    }

    /**
     * Send chat request to Anthropic
     *
     * @param array  $messages Chat messages
     * @param array  $tools    Available tools
     * @param array  $options  Options
     * @param string $api_key  API key
     * @return array|WP_Error
     */
    private static function chat_anthropic(array $messages, array $tools, array $options, string $api_key)
    {
        $model = $options['model'] ?? self::get_model(self::PROVIDER_ANTHROPIC);

        // Extract system message if present
        $system_message = '';
        $filtered_messages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system_message = $msg['content'];
            } else {
                $filtered_messages[] = $msg;
            }
        }

        $body = [
            'model'      => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages'   => self::format_messages_anthropic($filtered_messages),
        ];

        if (! empty($system_message)) {
            $body['system'] = $system_message;
        }

        // Add tools if provided
        if (! empty($tools)) {
            $body['tools'] = self::format_tools_anthropic($tools);
        }

        $response = wp_remote_post(self::API_ENDPOINTS['anthropic'], [
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => ABW_Background_Jobs::get_safe_timeout(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = $body['error']['message'] ?? 'Unknown Anthropic API error';
            return new WP_Error('anthropic_error', $error_message, ['status' => $status_code]);
        }

        return self::parse_anthropic_response($body);
    }

    /**
     * Send chat request to custom provider
     *
     * @param array  $messages Chat messages
     * @param array  $tools    Available tools
     * @param array  $options  Options
     * @param string $api_key  API key
     * @return array|WP_Error
     */
    private static function chat_custom(array $messages, array $tools, array $options, string $api_key)
    {
        $endpoint = get_option('abw_custom_endpoint', '');

        if (empty($endpoint)) {
            return new WP_Error('no_endpoint', __('Custom AI endpoint not configured.', 'abw-ai'));
        }

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Custom API key not configured.', 'abw-ai'));
        }

        // Custom providers should implement OpenAI-compatible API
        $body = [
            'model'    => get_option('abw_custom_model', 'gpt-4'),
            'messages' => self::format_messages_openai($messages),
        ];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        if (! empty($tools)) {
            $body['tools'] = self::format_tools_openai($tools);
            $body['tool_choice'] = 'auto';
        }

        // Build headers - Minimax uses standard Bearer token auth
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => ABW_Background_Jobs::get_safe_timeout(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $body = json_decode($response_body, true);

        if ($status_code !== 200) {
            // Try to extract error message from response
            $error_message = 'Custom API error';
            $error_details = [];

            if (is_array($body)) {
                // Standard OpenAI format
                if (isset($body['error']['message'])) {
                    $error_message = $body['error']['message'];
                    $error_details = $body['error'];
                } elseif (isset($body['error']['code'])) {
                    $error_message = $body['error']['code'] . ': ' . ($body['error']['message'] ?? 'Unknown error');
                    $error_details = $body['error'];
                } elseif (isset($body['message'])) {
                    $error_message = $body['message'];
                } elseif (isset($body['error']) && is_string($body['error'])) {
                    $error_message = $body['error'];
                } elseif (isset($body['base_resp'])) {
                    // Minimax format: base_resp.status_msg
                    $error_message = $body['base_resp']['status_msg'] ?? 'Unknown error';
                    if (isset($body['base_resp']['status_code']) && $body['base_resp']['status_code'] !== 0) {
                        $error_message .= ' (Code: ' . $body['base_resp']['status_code'] . ')';
                    }
                    $error_details = $body['base_resp'];
                }
            } elseif (! empty($response_body)) {
                // If not JSON, show raw response (truncated)
                $error_message = substr($response_body, 0, 200);
            }

            // Special handling for 404 errors - likely wrong endpoint
            if ($status_code === 404) {
                $error_message .= '. ' . __('Check that your endpoint URL includes the full path (e.g., /v1/chat/completions).', 'abw-ai');
            }

            // Log for debugging (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ABW-AI Custom Provider Error: ' . print_r([
                    'endpoint' => $endpoint,
                    'status' => $status_code,
                    'response' => $response_body,
                    'parsed_body' => $body,
                ], true));
            }

            return new WP_Error(
                'custom_error',
                sprintf(__('Custom API error (HTTP %d): %s', 'abw-ai'), $status_code, $error_message),
                [
                    'status' => $status_code,
                    'response' => $response_body,
                    'details' => $error_details,
                ]
            );
        }

        return self::parse_openai_response($body);
    }

    /**
     * Format messages for OpenAI API
     *
     * @param array $messages Raw messages
     * @return array
     */
    private static function format_messages_openai(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $formatted[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $formatted;
    }

    /**
     * Format messages for Anthropic API
     *
     * @param array $messages Raw messages
     * @return array
     */
    private static function format_messages_anthropic(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            // Convert 'assistant' tool results to expected format
            $formatted[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $formatted;
    }

    /**
     * Format tools for OpenAI API
     *
     * @param array $tools Raw tools definition
     * @return array
     */
    private static function format_tools_openai(array $tools): array
    {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'],
                    'parameters'  => $tool['parameters'] ?? [
                        'type'       => 'object',
                        'properties' => [],
                    ],
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Format tools for Anthropic API
     *
     * @param array $tools Raw tools definition
     * @return array
     */
    private static function format_tools_anthropic(array $tools): array
    {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'name'         => $tool['name'],
                'description'  => $tool['description'],
                'input_schema' => $tool['parameters'] ?? [
                    'type'       => 'object',
                    'properties' => [],
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Parse OpenAI response
     *
     * @param array $response API response
     * @return array
     */
    private static function parse_openai_response(array $response): array
    {
        $choice  = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $result = [
            'content'      => $message['content'] ?? '',
            'role'         => 'assistant',
            'tool_calls'   => [],
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
            'usage'        => $response['usage'] ?? [],
        ];

        // Handle tool calls
        if (! empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $result['tool_calls'][] = [
                    'id'       => $call['id'],
                    'name'     => $call['function']['name'],
                    'arguments' => json_decode($call['function']['arguments'], true) ?? [],
                ];
            }
        }

        return $result;
    }

    /**
     * Parse Anthropic response
     *
     * @param array $response API response
     * @return array
     */
    private static function parse_anthropic_response(array $response): array
    {
        $content = '';
        $tool_calls = [];

        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $tool_calls[] = [
                    'id'        => $block['id'],
                    'name'      => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        return [
            'content'       => $content,
            'role'          => 'assistant',
            'tool_calls'    => $tool_calls,
            'finish_reason' => $response['stop_reason'] ?? 'end_turn',
            'usage'         => [
                'input_tokens'  => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    /**
     * Execute a tool and return result
     *
     * @param string $tool_name Tool name
     * @param array  $arguments Tool arguments
     * @return array|WP_Error
     */
    public static function execute_tool(string $tool_name, array $arguments)
    {
        // Map tool names to internal REST API endpoints or ability callbacks
        $tool_mapping = [
            // Posts
            'list_posts'         => ['ABW_Abilities_Registration', 'execute_list_posts'],
            'get_post'           => ['ABW_Abilities_Registration', 'execute_get_post'],
            'create_post'        => ['ABW_Abilities_Registration', 'execute_create_post'],
            'update_post'        => ['ABW_Abilities_Registration', 'execute_update_post'],
            'delete_post'        => ['ABW_Abilities_Registration', 'execute_delete_post'],
            // Comments
            'list_comments'      => ['ABW_Abilities_Registration', 'execute_list_comments'],
            'moderate_comment'   => ['ABW_Abilities_Registration', 'execute_moderate_comment'],
            // Plugins
            'list_plugins'       => ['ABW_Abilities_Registration', 'execute_list_plugins'],
            'activate_plugin'    => ['ABW_Abilities_Registration', 'execute_activate_plugin'],
            'deactivate_plugin'  => ['ABW_Abilities_Registration', 'execute_deactivate_plugin'],
            // Themes
            'list_themes'        => ['ABW_Abilities_Registration', 'execute_list_themes'],
            'activate_theme'     => ['ABW_Abilities_Registration', 'execute_activate_theme'],
            // Site Info
            'get_site_info'      => ['ABW_Abilities_Registration', 'execute_get_site_info'],
            'update_site_identity' => ['ABW_Abilities_Registration', 'execute_update_site_identity'],
            // Users
            'list_users'         => ['ABW_Abilities_Registration', 'execute_list_users'],
            'get_user'           => ['ABW_Abilities_Registration', 'execute_get_user'],
            'create_user'        => ['ABW_Abilities_Registration', 'execute_create_user'],
            'update_user'        => ['ABW_Abilities_Registration', 'execute_update_user'],
            'delete_user'        => ['ABW_Abilities_Registration', 'execute_delete_user'],
            // Media
            'list_media'         => ['ABW_Abilities_Registration', 'execute_list_media'],
            'upload_media'       => ['ABW_Abilities_Registration', 'execute_upload_media'],
            'update_media'       => ['ABW_Abilities_Registration', 'execute_update_media'],
            'delete_media'       => ['ABW_Abilities_Registration', 'execute_delete_media'],
            // Taxonomies
            'list_taxonomies'    => ['ABW_Abilities_Registration', 'execute_list_taxonomies'],
            'list_terms'         => ['ABW_Abilities_Registration', 'execute_list_terms'],
            'create_term'        => ['ABW_Abilities_Registration', 'execute_create_term'],
            'update_term'        => ['ABW_Abilities_Registration', 'execute_update_term'],
            'delete_term'        => ['ABW_Abilities_Registration', 'execute_delete_term'],
            // Menus
            'list_menus'         => ['ABW_Abilities_Registration', 'execute_list_menus'],
            'get_menu'           => ['ABW_Abilities_Registration', 'execute_get_menu'],
            'create_menu'        => ['ABW_Abilities_Registration', 'execute_create_menu'],
            'add_menu_item'      => ['ABW_Abilities_Registration', 'execute_add_menu_item'],
            'update_menu_item'   => ['ABW_Abilities_Registration', 'execute_update_menu_item'],
            'delete_menu_item'   => ['ABW_Abilities_Registration', 'execute_delete_menu_item'],
            'assign_menu_location' => ['ABW_Abilities_Registration', 'execute_assign_menu_location'],
            // Options
            'get_option'          => ['ABW_Abilities_Registration', 'execute_get_option'],
            'update_option'      => ['ABW_Abilities_Registration', 'execute_update_option'],
            'get_reading_settings' => ['ABW_Abilities_Registration', 'execute_get_reading_settings'],
            'update_reading_settings' => ['ABW_Abilities_Registration', 'execute_update_reading_settings'],
            // Search & Analytics
            'search_site'         => ['ABW_Abilities_Registration', 'execute_search_site'],
            'get_post_stats'     => ['ABW_Abilities_Registration', 'execute_get_post_stats'],
            'get_popular_content' => ['ABW_Abilities_Registration', 'execute_get_popular_content'],
            'get_recent_activity' => ['ABW_Abilities_Registration', 'execute_get_recent_activity'],
            // Bulk Operations
            'bulk_update_posts'   => ['ABW_Abilities_Registration', 'execute_bulk_update_posts'],
            'bulk_delete_posts'   => ['ABW_Abilities_Registration', 'execute_bulk_delete_posts'],
            'find_replace_content' => ['ABW_Abilities_Registration', 'execute_find_replace_content'],
            'bulk_moderate_comments' => ['ABW_Abilities_Registration', 'execute_bulk_moderate_comments'],
            // Site Health
            'get_site_health'     => ['ABW_Abilities_Registration', 'execute_get_site_health'],
            'check_plugin_updates' => ['ABW_Abilities_Registration', 'execute_check_plugin_updates'],
            'check_theme_updates' => ['ABW_Abilities_Registration', 'execute_check_theme_updates'],
            // Block Themes
            'list_block_patterns' => ['ABW_Abilities_Registration', 'execute_list_block_patterns'],
            'list_template_parts' => ['ABW_Abilities_Registration', 'execute_list_template_parts'],
            // Elementor
            'list_elementor_pages' => ['ABW_Abilities_Registration', 'execute_list_elementor_pages'],
            'get_elementor_page'   => ['ABW_Abilities_Registration', 'execute_get_elementor_page'],
            'update_elementor_page' => ['ABW_Abilities_Registration', 'execute_update_elementor_page'],
            'list_elementor_templates' => ['ABW_Abilities_Registration', 'execute_list_elementor_templates'],
            // WooCommerce (conditional)
            'list_products'       => ['ABW_Abilities_Registration', 'execute_list_products'],
            'get_product'         => ['ABW_Abilities_Registration', 'execute_get_product'],
            'update_product'      => ['ABW_Abilities_Registration', 'execute_update_product'],
            'list_orders'         => ['ABW_Abilities_Registration', 'execute_list_orders'],
            'get_order'           => ['ABW_Abilities_Registration', 'execute_get_order'],
            'update_order_status' => ['ABW_Abilities_Registration', 'execute_update_order_status'],
            // AI Tools
            'generate_post_content' => ['ABW_AI_Tools', 'generate_post_content'],
            'improve_content'    => ['ABW_AI_Tools', 'improve_content'],
            'generate_seo_meta'  => ['ABW_AI_Tools', 'generate_seo_meta'],
            'translate_content'  => ['ABW_AI_Tools', 'translate_content'],
            'generate_css'       => ['ABW_AI_Tools', 'generate_css'],
            'suggest_color_scheme' => ['ABW_AI_Tools', 'suggest_color_scheme'],
            'generate_image_alt' => ['ABW_AI_Tools', 'generate_image_alt'],
            'generate_faq'       => ['ABW_AI_Tools', 'generate_faq'],
            'generate_schema_markup' => ['ABW_AI_Tools', 'generate_schema_markup'],
            'summarize_content'  => ['ABW_AI_Tools', 'summarize_content'],
            'generate_social_posts' => ['ABW_AI_Tools', 'generate_social_posts'],
            'analyze_content_sentiment' => ['ABW_AI_Tools', 'analyze_content_sentiment'],
            'detect_content_language' => ['ABW_AI_Tools', 'detect_content_language'],
            'check_content_accessibility' => ['ABW_AI_Tools', 'check_content_accessibility'],
        ];

        if (! isset($tool_mapping[$tool_name])) {
            return new WP_Error('unknown_tool', sprintf(__('Unknown tool: %s', 'abw-ai'), $tool_name));
        }

        $callback = $tool_mapping[$tool_name];

        if (is_callable($callback)) {
            return call_user_func($callback, $arguments);
        }

        return new WP_Error('tool_not_callable', sprintf(__('Tool %s is not callable.', 'abw-ai'), $tool_name));
    }

    /**
     * Get available tools for AI
     *
     * @return array
     */
    public static function get_available_tools(): array
    {
        $tools = [
            // Posts
            [
                'name'        => 'list_posts',
                'description' => 'List WordPress posts with optional filtering',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => ['type' => 'string', 'description' => 'Post type (post, page)'],
                        'per_page'  => ['type' => 'integer', 'description' => 'Number per page'],
                        'status'    => ['type' => 'string', 'description' => 'Post status filter'],
                    ],
                ],
            ],
            [
                'name'        => 'get_post',
                'description' => 'Get a single WordPress post by ID',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Post ID'],
                    ],
                ],
            ],
            [
                'name'        => 'create_post',
                'description' => 'Create a new WordPress post',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['title'],
                    'properties' => [
                        'title'   => ['type' => 'string', 'description' => 'Post title'],
                        'content' => ['type' => 'string', 'description' => 'Post content (HTML)'],
                        'status'  => ['type' => 'string', 'description' => 'Post status (draft, publish)'],
                        'type'    => ['type' => 'string', 'description' => 'Post type'],
                    ],
                ],
            ],
            [
                'name'        => 'update_post',
                'description' => 'Update an existing WordPress post',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'      => ['type' => 'integer', 'description' => 'Post ID'],
                        'title'   => ['type' => 'string', 'description' => 'New title'],
                        'content' => ['type' => 'string', 'description' => 'New content'],
                        'status'  => ['type' => 'string', 'description' => 'New status'],
                    ],
                ],
            ],
            [
                'name'        => 'delete_post',
                'description' => 'Delete a WordPress post',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'    => ['type' => 'integer', 'description' => 'Post ID'],
                        'force' => ['type' => 'boolean', 'description' => 'Permanently delete (skip trash)'],
                    ],
                ],
            ],
            // Comments
            [
                'name'        => 'list_comments',
                'description' => 'List WordPress comments with filtering',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Comment status filter'],
                        'per_page' => ['type' => 'integer', 'description' => 'Number per page'],
                    ],
                ],
            ],
            [
                'name'        => 'moderate_comment',
                'description' => 'Moderate a comment (approve, hold, spam, trash)',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id', 'status'],
                    'properties' => [
                        'id'     => ['type' => 'integer', 'description' => 'Comment ID'],
                        'status' => ['type' => 'string', 'description' => 'New status (approve, hold, spam, trash)'],
                    ],
                ],
            ],
            // Users
            [
                'name'        => 'list_users',
                'description' => 'List WordPress users with optional filtering by role or search',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'role'     => ['type' => 'string', 'description' => 'Filter by user role'],
                        'per_page' => ['type' => 'integer', 'description' => 'Users per page', 'default' => 20],
                        'page'     => ['type' => 'integer', 'description' => 'Page number', 'default' => 1],
                        'search'   => ['type' => 'string', 'description' => 'Search by username or email'],
                    ],
                ],
            ],
            [
                'name'        => 'get_user',
                'description' => 'Get user details by ID',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'User ID'],
                    ],
                ],
            ],
            [
                'name'        => 'create_user',
                'description' => 'Create a new WordPress user. Password is auto-generated if not provided.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['username', 'email'],
                    'properties' => [
                        'username'     => ['type' => 'string', 'description' => 'Username for the new user'],
                        'email'        => ['type' => 'string', 'description' => 'Email address'],
                        'password'     => ['type' => 'string', 'description' => 'Password (auto-generated if not provided)'],
                        'display_name' => ['type' => 'string', 'description' => 'Display name'],
                        'role'         => ['type' => 'string', 'description' => 'User role (defaults to subscriber)'],
                    ],
                ],
            ],
            [
                'name'        => 'update_user',
                'description' => 'Update user profile and role',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'           => ['type' => 'integer', 'description' => 'User ID'],
                        'email'        => ['type' => 'string', 'description' => 'New email'],
                        'display_name' => ['type' => 'string', 'description' => 'New display name'],
                        'role'         => ['type' => 'string', 'description' => 'New role'],
                    ],
                ],
            ],
            [
                'name'        => 'delete_user',
                'description' => 'Delete a user and optionally reassign content',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'        => ['type' => 'integer', 'description' => 'User ID'],
                        'reassign'  => ['type' => 'integer', 'description' => 'User ID to reassign content to'],
                    ],
                ],
            ],
            // Media
            [
                'name'        => 'list_media',
                'description' => 'List media files',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => ['type' => 'integer', 'description' => 'Number per page'],
                        'mime_type' => ['type' => 'string', 'description' => 'Filter by MIME type'],
                    ],
                ],
            ],
            [
                'name'        => 'upload_media',
                'description' => 'Upload media from URL or base64 data',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['url'],
                    'properties' => [
                        'url'         => ['type' => 'string', 'description' => 'Media URL to download and upload'],
                        'title'       => ['type' => 'string', 'description' => 'Media title'],
                        'alt_text'    => ['type' => 'string', 'description' => 'Alt text'],
                        'caption'     => ['type' => 'string', 'description' => 'Caption'],
                        'description' => ['type' => 'string', 'description' => 'Description'],
                    ],
                ],
            ],
            [
                'name'        => 'update_media',
                'description' => 'Update media metadata',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'          => ['type' => 'integer', 'description' => 'Media ID'],
                        'title'       => ['type' => 'string', 'description' => 'New title'],
                        'alt_text'    => ['type' => 'string', 'description' => 'New alt text'],
                        'caption'     => ['type' => 'string', 'description' => 'New caption'],
                        'description' => ['type' => 'string', 'description' => 'New description'],
                    ],
                ],
            ],
            [
                'name'        => 'delete_media',
                'description' => 'Delete a media file',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Media ID'],
                    ],
                ],
            ],
            // Plugins
            [
                'name'        => 'list_plugins',
                'description' => 'List installed WordPress plugins',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Filter: active, inactive, all'],
                    ],
                ],
            ],
            [
                'name'        => 'activate_plugin',
                'description' => 'Activate a WordPress plugin',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['plugin'],
                    'properties' => [
                        'plugin' => ['type' => 'string', 'description' => 'Plugin file path'],
                    ],
                ],
            ],
            [
                'name'        => 'deactivate_plugin',
                'description' => 'Deactivate a WordPress plugin',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['plugin'],
                    'properties' => [
                        'plugin' => ['type' => 'string', 'description' => 'Plugin file path'],
                    ],
                ],
            ],
            // Themes
            [
                'name'        => 'list_themes',
                'description' => 'List installed WordPress themes',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'activate_theme',
                'description' => 'Activate a WordPress theme',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['stylesheet'],
                    'properties' => [
                        'stylesheet' => ['type' => 'string', 'description' => 'Theme directory name'],
                    ],
                ],
            ],
            // Site Info
            [
                'name'        => 'get_site_info',
                'description' => 'Get WordPress site information',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'update_site_identity',
                'description' => 'Update site title and tagline',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'    => ['type' => 'string', 'description' => 'Site title'],
                        'tagline'  => ['type' => 'string', 'description' => 'Site tagline'],
                    ],
                ],
            ],
        ];

        // Add Elementor tools if available
        if (class_exists('\Elementor\Plugin')) {
            $tools[] = [
                'name'        => 'list_elementor_pages',
                'description' => 'List pages built with Elementor',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => ['type' => 'integer', 'description' => 'Number per page'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'get_elementor_page',
                'description' => 'Get Elementor page data',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Page ID'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'update_elementor_page',
                'description' => 'Update an Elementor page layout',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'       => ['type' => 'integer', 'description' => 'Page ID'],
                        'elements' => ['type' => 'array', 'description' => 'Elementor elements data'],
                        'settings' => ['type' => 'object', 'description' => 'Page settings'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'list_elementor_templates',
                'description' => 'List Elementor templates',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'Template type filter'],
                    ],
                ],
            ];
        }

        // Add WooCommerce tools if available
        if (class_exists('WooCommerce')) {
            $tools[] = [
                'name'        => 'list_products',
                'description' => 'List WooCommerce products',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => ['type' => 'integer', 'description' => 'Products per page'],
                        'status'   => ['type' => 'string', 'description' => 'Product status'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'get_product',
                'description' => 'Get WooCommerce product details',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Product ID'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'update_product',
                'description' => 'Update WooCommerce product',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id'          => ['type' => 'integer', 'description' => 'Product ID'],
                        'name'        => ['type' => 'string', 'description' => 'Product name'],
                        'description' => ['type' => 'string', 'description' => 'Product description'],
                        'price'       => ['type' => 'number', 'description' => 'Product price'],
                        'stock_status' => ['type' => 'string', 'description' => 'Stock status'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'list_orders',
                'description' => 'List WooCommerce orders',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => ['type' => 'integer', 'description' => 'Orders per page'],
                        'status'   => ['type' => 'string', 'description' => 'Order status'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'get_order',
                'description' => 'Get WooCommerce order details',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Order ID'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'update_order_status',
                'description' => 'Update WooCommerce order status',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['id', 'status'],
                    'properties' => [
                        'id'     => ['type' => 'integer', 'description' => 'Order ID'],
                        'status' => ['type' => 'string', 'description' => 'New order status'],
                    ],
                ],
            ];
        }

        // Add AI tools
        $ai_tools = ABW_AI_Tools::get_tools_list();
        $tools = array_merge($tools, $ai_tools);

        return $tools;
    }

    /**
     * Get system prompt for AI
     *
     * @return string
     */
    public static function get_system_prompt(): string
    {
        $site_name = get_bloginfo('name');
        $site_url  = home_url();
        $wp_version = get_bloginfo('version');
        $theme_name = wp_get_theme()->get('Name');

        $has_elementor = class_exists('\Elementor\Plugin') ? 'Yes' : 'No';

        return <<<PROMPT
You are ABW-AI, an Advanced Butler for WordPress. You are a helpful AI assistant that helps users manage their WordPress website.

Current WordPress Site:
- Site Name: {$site_name}
- URL: {$site_url}
- WordPress Version: {$wp_version}
- Active Theme: {$theme_name}
- Elementor Active: {$has_elementor}

Your capabilities:
- POSTS & PAGES: Create, edit, update, delete, and list posts and pages with filtering
- USERS: Create, update, delete, list, and get user details. You can create users with username, email, password (auto-generated if not provided), display name, and role
- MEDIA: Upload, update, delete, and list media files. Upload from URLs or base64 data
- COMMENTS: List and moderate comments (approve, hold, spam, trash)
- PLUGINS: List, activate, and deactivate plugins
- THEMES: List and activate themes
- TAXONOMIES: List taxonomies, create/update/delete terms (categories, tags, custom taxonomies)
- MENUS: Create menus, add/update/delete menu items, assign menu locations
- SITE OPTIONS: Get and update WordPress options (safely whitelisted options only)
- SEARCH & ANALYTICS: Search site content, get post statistics, popular content, recent activity
- BULK OPERATIONS: Bulk update/delete posts, bulk moderate comments, find and replace content
- SITE HEALTH: Get site health status, check for plugin/theme updates
- BLOCK THEMES: List block patterns and template parts (for block themes)
- ELEMENTOR: List and update Elementor pages and templates (if Elementor is active)
- WOOCOMMERCE: List products/orders, get product/order details, update product/order status (if WooCommerce is active)

IMPORTANT - User Creation:
- You CAN create users using the create_user tool
- Required: username and email
- Optional: password (auto-generated if not provided), display_name, role (defaults to 'subscriber')
- Example: To create a user, use create_user with username and email parameters

Guidelines:
1. Be helpful and proactive in suggesting solutions
2. Ask clarifying questions when needed
3. Explain what you're doing before taking actions
4. Do NOT add a "Summary", "Quick Summary", or "TL;DR" section unless the user explicitly asks for a summary
5. Handle errors gracefully and suggest alternatives
6. When creating users, always use the create_user tool - it is available and functional

Always prioritize the user's intent and provide clear, actionable responses.
PROMPT;
    }

    /**
     * Maybe decrypt an encrypted value
     *
     * @param string $value Potentially encrypted value
     * @return string
     */
    private static function maybe_decrypt(string $value): string
    {
        // Check if value looks encrypted (base64 with specific prefix)
        if (strpos($value, 'abw_enc_') !== 0) {
            return $value;
        }

        // Get encryption key
        $key = self::get_encryption_key();
        if (empty($key)) {
            return $value;
        }

        try {
            $encrypted = base64_decode(substr($value, 8));
            $nonce     = substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($encrypted, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

            if ($decrypted === false) {
                return $value;
            }

            return $decrypted;
        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * Encrypt a value for storage
     *
     * @param string $value Value to encrypt
     * @return string
     */
    public static function encrypt(string $value): string
    {
        if (! function_exists('sodium_crypto_secretbox')) {
            return $value;
        }

        $key = self::get_encryption_key();
        if (empty($key)) {
            return $value;
        }

        try {
            $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);

            return 'abw_enc_' . base64_encode($nonce . $ciphertext);
        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * Get or generate encryption key
     *
     * @return string
     */
    private static function get_encryption_key(): string
    {
        $key = get_option('abw_encryption_key', '');

        if (empty($key)) {
            if (function_exists('sodium_crypto_secretbox_keygen')) {
                $key = base64_encode(sodium_crypto_secretbox_keygen());
                update_option('abw_encryption_key', $key);
            } else {
                return '';
            }
        }

        return base64_decode($key);
    }

    /**
     * Track token usage
     *
     * @param string $provider Provider name
     * @param array  $usage    Usage data
     */
    public static function track_usage(string $provider, array $usage): void
    {
        $today = gmdate('Y-m-d');
        $usage_key = 'abw_usage_' . $today;

        $daily_usage = get_option($usage_key, []);

        if (! isset($daily_usage[$provider])) {
            $daily_usage[$provider] = [
                'requests'      => 0,
                'input_tokens'  => 0,
                'output_tokens' => 0,
            ];
        }

        $daily_usage[$provider]['requests']++;
        $daily_usage[$provider]['input_tokens']  += $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0;
        $daily_usage[$provider]['output_tokens'] += $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0;

        update_option($usage_key, $daily_usage);
    }

    /**
     * Get usage statistics
     *
     * @param int $days Number of days to retrieve
     * @return array
     */
    public static function get_usage_stats(int $days = 30): array
    {
        $stats = [];

        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', strtotime("-{$i} days"));
            $usage_key = 'abw_usage_' . $date;
            $daily = get_option($usage_key, []);

            if (! empty($daily)) {
                $stats[$date] = $daily;
            }
        }

        return $stats;
    }
}

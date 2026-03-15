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
            return self::format_provider_transport_error(self::PROVIDER_OPENAI, $response);
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
            return self::format_provider_transport_error(self::PROVIDER_ANTHROPIC, $response);
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
            return self::format_provider_transport_error(self::PROVIDER_CUSTOM, $response);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $body = json_decode($response_body, true);

        if ($status_code !== 200) {
            // Try to extract error message from response
            $error_message = '';
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
            }

            $user_friendly_error = self::format_custom_provider_error($status_code, $error_message, $response_body);

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
                $user_friendly_error,
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
     * Turn raw custom-provider failures into user-friendly messages.
     *
     * @param int    $status_code   HTTP status from the provider.
     * @param string $error_message Best parsed provider message, if any.
     * @param string $response_body Raw provider response body.
     * @return string
     */
    private static function format_custom_provider_error(int $status_code, string $error_message, string $response_body): string
    {
        $message = trim(wp_strip_all_tags($error_message));
        $response_excerpt = trim(wp_strip_all_tags((string) $response_body));
        $looks_like_json = '' !== $response_excerpt && (
            '{' === $response_excerpt[0] ||
            '[' === $response_excerpt[0]
        );

        if ($status_code === 404) {
            return __('The custom AI endpoint could not be found. Check that the endpoint URL includes the full API path, such as /v1/chat/completions.', 'abw-ai');
        }

        if ($status_code >= 500) {
            if ($message !== '' && ! $looks_like_json) {
                return sprintf(
                    __('The custom AI provider returned a server error: %s', 'abw-ai'),
                    $message
                );
            }

            return __('The custom AI provider returned a server error and did not send a usable message. Please try again in a moment. If this keeps happening, check the provider status, model name, and endpoint configuration.', 'abw-ai');
        }

        if ($message !== '' && ! $looks_like_json) {
            return sprintf(
                __('The custom AI provider rejected the request: %s', 'abw-ai'),
                $message
            );
        }

        return sprintf(
            __('The custom AI provider request failed with HTTP %d. Please review your endpoint, headers, and model settings.', 'abw-ai'),
            $status_code
        );
    }

    /**
     * Convert low-level transport/network failures into user-friendly provider errors.
     *
     * @param string   $provider Provider key.
     * @param WP_Error $error    Transport error from wp_remote_post().
     * @return WP_Error
     */
    private static function format_provider_transport_error(string $provider, WP_Error $error): WP_Error
    {
        $raw_message = trim((string) $error->get_error_message());
        $provider_label = self::PROVIDER_CUSTOM === $provider
            ? __('custom AI provider', 'abw-ai')
            : sprintf(__('%s provider', 'abw-ai'), ucfirst($provider));

        $friendly_message = sprintf(
            __('Could not reach the %s. Please try again and verify your API settings, endpoint, and network connection.', 'abw-ai'),
            $provider_label
        );

        if (
            false !== stripos($raw_message, 'cURL error 28') ||
            false !== stripos($raw_message, 'timed out') ||
            false !== stripos($raw_message, 'timeout')
        ) {
            $friendly_message = sprintf(
                __('The %s took too long to respond. Please try again in a moment. If this keeps happening, check the model, endpoint, and server timeout settings.', 'abw-ai'),
                $provider_label
            );
        }

        return new WP_Error(
            $error->get_error_code() ?: 'provider_transport_error',
            $friendly_message,
            [
                'provider'    => $provider,
                'raw_message' => $raw_message,
                'original'    => $error,
            ]
        );
    }

    /**
     * Format messages for OpenAI API
     *
     * Supports multi-turn tool calling: assistant with tool_calls, tool role messages.
     *
     * @param array $messages Raw messages
     * @return array
     */
    private static function format_messages_openai(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'tool') {
                $formatted[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $msg['tool_call_id'],
                    'content'      => is_array($msg['content']) ? wp_json_encode($msg['content']) : (string) $msg['content'],
                ];
                continue;
            }

            if ($role === 'assistant' && ! empty($msg['tool_calls'])) {
                $formatted[] = [
                    'role'       => 'assistant',
                    'content'    => $msg['content'] ?? null,
                    'tool_calls' => $msg['tool_calls'],
                ];
                continue;
            }

            $formatted[] = [
                'role'    => $role,
                'content' => is_array($msg['content']) ? wp_json_encode($msg['content']) : (string) ($msg['content'] ?? ''),
            ];
        }

        return $formatted;
    }

    /**
     * Format messages for Anthropic API
     *
     * Supports multi-turn tool calling: assistant with content blocks (tool_use),
     * user with content blocks (tool_result). Consecutive tool messages are
     * collapsed into one user message with multiple tool_result blocks.
     *
     * @param array $messages Raw messages
     * @return array
     */
    private static function format_messages_anthropic(array $messages): array
    {
        $formatted = [];
        $tool_results_buf = [];

        foreach ($messages as $msg) {
            $role    = $msg['role'];
            $content = $msg['content'];

            if ($role === 'tool') {
                $tool_results_buf[] = [
                    'type'         => 'tool_result',
                    'tool_use_id'  => $msg['tool_call_id'],
                    'content'      => is_array($content) ? wp_json_encode($content) : (string) $content,
                    'is_error'     => $msg['is_error'] ?? false,
                ];
                continue;
            }

            if (! empty($tool_results_buf)) {
                $formatted[] = [
                    'role'    => 'user',
                    'content' => $tool_results_buf,
                ];
                $tool_results_buf = [];
            }

            if ($role === 'assistant' && isset($msg['tool_calls']) && ! empty($msg['tool_calls'])) {
                $blocks = [];
                if (! empty($msg['content'])) {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => is_string($msg['content']) ? $msg['content'] : wp_json_encode($msg['content']),
                    ];
                }
                foreach ($msg['tool_calls'] as $call) {
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => $call['id'],
                        'name'  => $call['name'],
                        'input' => $call['arguments'] ?? [],
                    ];
                }
                $formatted[] = [
                    'role'    => 'assistant',
                    'content' => $blocks,
                ];
                continue;
            }

            $formatted[] = [
                'role'    => $role,
                'content' => is_array($content) ? $content : (string) ($content ?? ''),
            ];
        }

        if (! empty($tool_results_buf)) {
            $formatted[] = [
                'role'    => 'user',
                'content' => $tool_results_buf,
            ];
        }

        return $formatted;
    }

    /**
     * Build messages to append after tool execution (for multi-turn agentic loop).
     *
     * Returns the assistant message + tool result messages in provider-agnostic format.
     * Caller appends these to the messages array before the next AI call.
     *
     * @param array $assistant_response Response from parse_openai_response or parse_anthropic_response.
     * @param array $tool_results       Array of ['id' => tool_call_id, 'name' => string, 'result' => mixed].
     * @return array Messages to append (assistant + tool results).
     */
    public static function build_tool_result_messages(array $assistant_response, array $tool_results): array
    {
        $to_append = [];

        $content  = $assistant_response['content'] ?? '';
        $tool_calls = $assistant_response['tool_calls'] ?? [];

        $to_append[] = [
            'role'       => 'assistant',
            'content'    => $content,
            'tool_calls' => $tool_calls,
        ];

        foreach ($tool_results as $tr) {
            $id     = $tr['id'] ?? '';
            $result = $tr['result'] ?? $tr;
            $is_error = isset($tr['is_error']) && $tr['is_error'];

            if (is_object($result) && is_a($result, 'WP_Error')) {
                $result  = $result->get_error_message();
                $is_error = true;
            } elseif (is_array($result) || is_object($result)) {
                $result = wp_json_encode($result);
            } else {
                $result = (string) $result;
            }

            $to_append[] = [
                'role'         => 'tool',
                'tool_call_id' => $id,
                'content'     => $result,
                'is_error'     => $is_error,
            ];
        }

        return $to_append;
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
    public static function execute_tool(string $tool_name, array $arguments, array $context = [])
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
            'update_plugin'      => ['ABW_Abilities_Registration', 'execute_update_plugin'],
            // Themes
            'list_themes'        => ['ABW_Abilities_Registration', 'execute_list_themes'],
            'activate_theme'     => ['ABW_Abilities_Registration', 'execute_activate_theme'],
            'update_theme'       => ['ABW_Abilities_Registration', 'execute_update_theme'],
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
            // Block Editor Tools (return block_actions for frontend execution)
            'insert_editor_blocks'    => [__CLASS__, 'execute_insert_editor_blocks'],
            'replace_editor_content'  => [__CLASS__, 'execute_replace_editor_content'],
            'update_editor_block'     => [__CLASS__, 'execute_update_editor_block'],
            'remove_editor_blocks'    => [__CLASS__, 'execute_remove_editor_blocks'],
            'save_current_post'       => [__CLASS__, 'execute_save_current_post'],
            'update_post_details'     => [__CLASS__, 'execute_update_post_details'],
            // Brand Voice
            'train_brand_voice'      => ['ABW_Brand_Voice', 'train_brand_voice'],
            'get_brand_voice'        => ['ABW_Brand_Voice', 'get_brand_voice'],
            // AI Workflows
            'create_workflow'        => ['ABW_AI_Workflows', 'create_workflow'],
            'list_workflows'         => ['ABW_AI_Workflows', 'list_workflows'],
            'toggle_workflow'        => ['ABW_AI_Workflows', 'toggle_workflow'],
            'run_workflow'           => ['ABW_AI_Workflows', 'run_workflow'],
            // Database & Performance
            'get_database_stats'     => ['ABW_Abilities_Registration', 'execute_get_database_stats'],
            'cleanup_database'       => ['ABW_Abilities_Registration', 'execute_cleanup_database'],
            'optimize_database_tables' => ['ABW_Abilities_Registration', 'execute_optimize_database_tables'],
            'list_cron_jobs'         => ['ABW_Abilities_Registration', 'execute_list_cron_jobs'],
            'delete_cron_job'        => ['ABW_Abilities_Registration', 'execute_delete_cron_job'],
            'list_transients'        => ['ABW_Abilities_Registration', 'execute_list_transients'],
            'flush_transients'       => ['ABW_Abilities_Registration', 'execute_flush_transients'],
            'get_autoload_report'    => ['ABW_Abilities_Registration', 'execute_get_autoload_report'],
            'get_performance_report' => ['ABW_Abilities_Registration', 'execute_get_performance_report'],
            // Security
            'scan_file_integrity'    => ['ABW_Security_Tools', 'scan_file_integrity'],
            'list_failed_logins'     => ['ABW_Security_Tools', 'list_failed_logins'],
            'check_file_permissions' => ['ABW_Security_Tools', 'check_file_permissions'],
            'get_security_report'    => ['ABW_Security_Tools', 'get_security_report'],
            'list_admin_users'       => ['ABW_Security_Tools', 'list_admin_users'],
            'check_ssl_status'       => ['ABW_Security_Tools', 'check_ssl_status'],
            // New AI Content Tools
            'generate_product_description' => ['ABW_AI_Tools', 'generate_product_description'],
            'rewrite_for_tone'       => ['ABW_AI_Tools', 'rewrite_for_tone'],
            'generate_outline'       => ['ABW_AI_Tools', 'generate_outline'],
            'expand_from_outline'    => ['ABW_AI_Tools', 'expand_from_outline'],
            'generate_excerpt_ai'    => ['ABW_AI_Tools', 'generate_excerpt_ai'],
            'generate_table_of_contents' => ['ABW_AI_Tools', 'generate_table_of_contents'],
            // New SEO Tools
            'analyze_seo_score'      => ['ABW_AI_Tools', 'analyze_seo_score'],
            'suggest_internal_links' => ['ABW_AI_Tools', 'suggest_internal_links'],
            'generate_slug'          => ['ABW_AI_Tools', 'generate_slug'],
            'check_broken_links'     => ['ABW_AI_Tools', 'check_broken_links'],

            // WooCommerce Deep
            'bulk_update_products'   => ['ABW_Abilities_Registration', 'execute_bulk_update_products'],
            'get_sales_report'       => ['ABW_Abilities_Registration', 'execute_get_sales_report'],
            'get_customer_stats'     => ['ABW_Abilities_Registration', 'execute_get_customer_stats'],
            'create_coupon'          => ['ABW_Abilities_Registration', 'execute_create_coupon'],
            'list_coupons'           => ['ABW_Abilities_Registration', 'execute_list_coupons'],
            'analyze_product_performance' => ['ABW_Abilities_Registration', 'execute_analyze_product_performance'],
            // i18n Tools
            'detect_and_translate_post' => ['ABW_AI_Tools', 'detect_and_translate_post'],
            'bulk_translate_posts'   => ['ABW_AI_Tools', 'bulk_translate_posts'],
            'manage_translations'    => ['ABW_AI_Tools', 'manage_translations'],
            // Analytics & Reporting
            'get_content_calendar'   => ['ABW_AI_Tools', 'get_content_calendar'],
            'get_publishing_stats'   => ['ABW_AI_Tools', 'get_publishing_stats'],
            'get_comment_stats'      => ['ABW_AI_Tools', 'get_comment_stats'],
            'generate_site_report'   => ['ABW_AI_Tools', 'generate_site_report'],
            'get_daily_brief'        => ['ABW_AI_Tools', 'get_daily_brief'],
            'get_site_opportunities' => ['ABW_AI_Tools', 'get_site_opportunities'],
        ];

        if (! isset($tool_mapping[$tool_name])) {
            return new WP_Error('unknown_tool', sprintf(__('Unknown tool: %s', 'abw-ai'), $tool_name));
        }

        $permission_check = self::authorize_tool_execution($tool_name);
        if (is_wp_error($permission_check)) {
            return $permission_check;
        }

        if (self::tool_requires_confirmation($tool_name, $arguments, $context)) {
            return [
                '__abw_requires_confirmation' => self::build_confirmation_request($tool_name, $arguments),
            ];
        }

        $callback = $tool_mapping[$tool_name];

        if (is_callable($callback)) {
            return call_user_func($callback, $arguments);
        }

        return new WP_Error('tool_not_callable', sprintf(__('Tool %s is not callable.', 'abw-ai'), $tool_name));
    }

    /**
     * Enforce capability checks for chat, workflow, and background execution.
     *
     * @param string $tool_name Tool name.
     * @return true|WP_Error
     */
    private static function authorize_tool_execution(string $tool_name)
    {
        $requirements = self::get_tool_capability_requirements($tool_name);
        if (empty($requirements)) {
            return true;
        }

        $caps = is_array($requirements['caps']) ? $requirements['caps'] : [ $requirements['caps'] ];
        foreach ($caps as $cap) {
            if ($cap && current_user_can($cap)) {
                return true;
            }
        }

        $label = self::humanize_tool_name($tool_name);
        $message = $requirements['message']
            ?? sprintf(
                __('You do not have permission to run %s.', 'abw-ai'),
                strtolower($label)
            );

        return new WP_Error('forbidden_tool', $message, [ 'status' => 403 ]);
    }

    /**
     * Get capability requirements for a tool.
     *
     * @param string $tool_name Tool name.
     * @return array<string, mixed>
     */
    private static function get_tool_capability_requirements(string $tool_name): array
    {
        $manage_options_tools = [
            'create_menu',
            'add_menu_item',
            'update_menu_item',
            'delete_menu_item',
            'assign_menu_location',
            'get_option',
            'update_option',
            'get_reading_settings',
            'update_reading_settings',
            'update_site_identity',
            'create_workflow',
            'list_workflows',
            'toggle_workflow',
            'run_workflow',
            'get_database_stats',
            'cleanup_database',
            'optimize_database_tables',
            'list_cron_jobs',
            'delete_cron_job',
            'list_transients',
            'flush_transients',
            'get_autoload_report',
            'get_performance_report',
            'scan_file_integrity',
            'list_failed_logins',
            'check_file_permissions',
            'get_security_report',
            'list_admin_users',
            'check_ssl_status',
        ];

        $edit_posts_tools = [
            'create_post',
            'update_post',
            'bulk_update_posts',
            'find_replace_content',
            'generate_post_content',
            'improve_content',
            'generate_seo_meta',
            'translate_content',
            'generate_css',
            'suggest_color_scheme',
            'generate_image_alt',
            'generate_faq',
            'generate_schema_markup',
            'summarize_content',
            'generate_social_posts',
            'analyze_content_sentiment',
            'detect_content_language',
            'check_content_accessibility',
            'insert_editor_blocks',
            'replace_editor_content',
            'update_editor_block',
            'remove_editor_blocks',
            'save_current_post',
            'update_post_details',
            'train_brand_voice',
            'get_brand_voice',
            'generate_product_description',
            'rewrite_for_tone',
            'generate_outline',
            'expand_from_outline',
            'generate_excerpt_ai',
            'generate_table_of_contents',
            'analyze_seo_score',
            'suggest_internal_links',
            'generate_slug',
            'check_broken_links',
            'detect_and_translate_post',
            'bulk_translate_posts',
            'manage_translations',
            'get_content_calendar',
            'get_publishing_stats',
            'get_comment_stats',
            'generate_site_report',
            'get_daily_brief',
            'get_site_opportunities',
        ];

        $read_tools = [
            'list_posts',
            'get_post',
            'list_taxonomies',
            'list_terms',
            'list_block_patterns',
            'list_template_parts',
            'search_site',
            'get_post_stats',
            'get_popular_content',
            'get_recent_activity',
            'get_site_info',
            'get_site_health',
            'check_plugin_updates',
            'check_theme_updates',
            'get_daily_brief',
            'get_site_opportunities',
        ];

        if (in_array($tool_name, $read_tools, true)) {
            return [
                'caps'    => 'manage_options',
                'message' => __('You need administrator access to run this tool.', 'abw-ai'),
            ];
        }

        if (in_array($tool_name, $edit_posts_tools, true)) {
            return [
                'caps'    => 'manage_options',
                'message' => __('You need administrator access to run this tool.', 'abw-ai'),
            ];
        }

        if (in_array($tool_name, $manage_options_tools, true)) {
            return [
                'caps'    => 'manage_options',
                'message' => __('You need administrator access to run this site-management tool.', 'abw-ai'),
            ];
        }

        switch ($tool_name) {
            case 'delete_post':
            case 'bulk_delete_posts':
                return [
                    'caps'    => 'delete_posts',
                    'message' => __('You need permission to delete posts before running this tool.', 'abw-ai'),
                ];

            case 'list_comments':
            case 'moderate_comment':
            case 'bulk_moderate_comments':
                return [
                    'caps'    => 'moderate_comments',
                    'message' => __('You need permission to moderate comments before running this tool.', 'abw-ai'),
                ];

            case 'list_users':
            case 'get_user':
            case 'update_user':
                return [
                    'caps'    => 'edit_users',
                    'message' => __('You need permission to manage users before running this tool.', 'abw-ai'),
                ];

            case 'create_user':
                return [
                    'caps'    => 'create_users',
                    'message' => __('You need permission to create users before running this tool.', 'abw-ai'),
                ];

            case 'delete_user':
                return [
                    'caps'    => 'delete_users',
                    'message' => __('You need permission to delete users before running this tool.', 'abw-ai'),
                ];

            case 'list_media':
            case 'upload_media':
            case 'update_media':
            case 'delete_media':
                return [
                    'caps'    => 'upload_files',
                    'message' => __('You need permission to manage media before running this tool.', 'abw-ai'),
                ];

            case 'create_term':
            case 'update_term':
            case 'delete_term':
                return [
                    'caps'    => 'manage_categories',
                    'message' => __('You need permission to manage categories and terms before running this tool.', 'abw-ai'),
                ];

            case 'list_menus':
            case 'get_menu':
                return [
                    'caps'    => 'edit_theme_options',
                    'message' => __('You need permission to manage navigation before running this tool.', 'abw-ai'),
                ];

            case 'list_plugins':
            case 'activate_plugin':
            case 'deactivate_plugin':
                return [
                    'caps'    => 'activate_plugins',
                    'message' => __('You need permission to manage plugins before running this tool.', 'abw-ai'),
                ];

            case 'update_plugin':
                return [
                    'caps'    => 'update_plugins',
                    'message' => __('You need permission to update plugins before running this tool.', 'abw-ai'),
                ];

            case 'list_themes':
            case 'activate_theme':
                return [
                    'caps'    => 'switch_themes',
                    'message' => __('You need permission to manage themes before running this tool.', 'abw-ai'),
                ];

            case 'update_theme':
                return [
                    'caps'    => 'update_themes',
                    'message' => __('You need permission to update themes before running this tool.', 'abw-ai'),
                ];

            case 'list_products':
            case 'get_product':
            case 'update_product':
            case 'bulk_update_products':
            case 'analyze_product_performance':
            case 'list_orders':
            case 'get_order':
            case 'update_order_status':
            case 'get_sales_report':
            case 'get_customer_stats':
            case 'create_coupon':
            case 'list_coupons':
                return [
                    'caps'    => [ 'manage_woocommerce', 'manage_options' ],
                    'message' => __('You need WooCommerce management access to run this tool.', 'abw-ai'),
                ];

            default:
                return [
                    'caps'    => 'manage_options',
                    'message' => __('You need administrator access to run this tool.', 'abw-ai'),
                ];
        }
    }

    /**
     * Determine whether a tool must be explicitly confirmed.
     *
     * @param string $tool_name Tool name.
     * @param array  $arguments Tool arguments.
     * @param array  $context   Execution context.
     * @return bool
     */
    private static function tool_requires_confirmation(string $tool_name, array $arguments, array $context): bool
    {
        if (! empty($context['confirmed'])) {
            return false;
        }

        $always_confirm = [
            'create_user',
            'delete_user',
            'delete_post',
            'bulk_delete_posts',
            'delete_media',
            'delete_term',
            'delete_menu_item',
            'activate_theme',
            'update_plugin',
            'update_theme',
            'update_option',
            'update_reading_settings',
            'update_site_identity',
            'cleanup_database',
            'optimize_database_tables',
            'delete_cron_job',
            'flush_transients',
            'create_coupon',
        ];

        if (in_array($tool_name, $always_confirm, true)) {
            return true;
        }

        if ('moderate_comment' === $tool_name) {
            $action = strtolower((string) ($arguments['status'] ?? $arguments['action'] ?? ''));
            return in_array($action, [ 'spam', 'trash', 'delete' ], true);
        }

        if ('update_user' === $tool_name) {
            return isset($arguments['role']) && '' !== trim((string) $arguments['role']);
        }

        if ('bulk_update_posts' === $tool_name) {
            return ! empty($arguments['post_ids']) && count((array) $arguments['post_ids']) > 10;
        }

        if ('find_replace_content' === $tool_name) {
            return ! empty($arguments['search']) && array_key_exists('replace', $arguments);
        }

        return false;
    }

    /**
     * Build a user-facing confirmation request.
     *
     * @param string $tool_name Tool name.
     * @param array  $arguments Tool arguments.
     * @return array<string, mixed>
     */
    private static function build_confirmation_request(string $tool_name, array $arguments): array
    {
        $label = self::humanize_tool_name($tool_name);

        return [
            'tool'          => $tool_name,
            'arguments'     => $arguments,
            'title'         => sprintf(__('Confirm %s', 'abw-ai'), $label),
            'message'       => sprintf(
                __('ABW-AI is ready to run %s. Review the details below and confirm before anything changes.', 'abw-ai'),
                strtolower($label)
            ),
            'details'       => self::summarize_tool_arguments($tool_name, $arguments),
            'confirm_label' => __('Confirm action', 'abw-ai'),
            'cancel_label'  => __('Cancel', 'abw-ai'),
        ];
    }

    /**
     * Summarize tool arguments for approval UI.
     *
     * @param string $tool_name Tool name.
     * @param array  $arguments Tool arguments.
     * @return array<int, string>
     */
    private static function summarize_tool_arguments(string $tool_name, array $arguments): array
    {
        $details = [];

        switch ($tool_name) {
            case 'create_user':
                if (! empty($arguments['username'])) {
                    $details[] = sprintf(__('Username: %s', 'abw-ai'), (string) $arguments['username']);
                }
                if (! empty($arguments['email'])) {
                    $details[] = sprintf(__('Email: %s', 'abw-ai'), (string) $arguments['email']);
                }
                if (! empty($arguments['role'])) {
                    $details[] = sprintf(__('Role: %s', 'abw-ai'), (string) $arguments['role']);
                }
                break;

            case 'delete_user':
            case 'delete_post':
            case 'delete_media':
            case 'delete_term':
            case 'delete_menu_item':
                if (isset($arguments['id'])) {
                    $details[] = sprintf(__('Target ID: %s', 'abw-ai'), (string) $arguments['id']);
                }
                break;

            case 'bulk_delete_posts':
            case 'bulk_update_posts':
                if (! empty($arguments['post_ids'])) {
                    $ids = array_map('intval', (array) $arguments['post_ids']);
                    $details[] = sprintf(__('Post count: %d', 'abw-ai'), count($ids));
                    $details[] = sprintf(__('Post IDs: %s', 'abw-ai'), implode(', ', array_slice($ids, 0, 15)));
                }
                break;

            case 'update_plugin':
            case 'activate_plugin':
            case 'deactivate_plugin':
                if (! empty($arguments['plugin'])) {
                    $details[] = sprintf(__('Plugin: %s', 'abw-ai'), (string) $arguments['plugin']);
                }
                break;

            case 'activate_theme':
            case 'update_theme':
                if (! empty($arguments['stylesheet'])) {
                    $details[] = sprintf(__('Theme: %s', 'abw-ai'), (string) $arguments['stylesheet']);
                }
                break;

            case 'update_option':
                if (! empty($arguments['option_name'])) {
                    $details[] = sprintf(__('Option: %s', 'abw-ai'), (string) $arguments['option_name']);
                }
                break;

            case 'update_site_identity':
                if (isset($arguments['title'])) {
                    $details[] = sprintf(__('Site title: %s', 'abw-ai'), (string) $arguments['title']);
                }
                if (isset($arguments['tagline'])) {
                    $details[] = sprintf(__('Tagline: %s', 'abw-ai'), (string) $arguments['tagline']);
                }
                break;

            case 'update_reading_settings':
                foreach ([ 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page' ] as $field) {
                    if (isset($arguments[$field])) {
                        $details[] = sprintf('%s: %s', $field, is_scalar($arguments[$field]) ? (string) $arguments[$field] : wp_json_encode($arguments[$field]));
                    }
                }
                break;

            default:
                foreach ($arguments as $key => $value) {
                    if (is_array($value)) {
                        $encoded = wp_json_encode($value);
                        $details[] = sprintf('%s: %s', $key, strlen((string) $encoded) > 120 ? substr((string) $encoded, 0, 117) . '...' : $encoded);
                    } elseif (is_scalar($value) && '' !== trim((string) $value)) {
                        $string_value = (string) $value;
                        $details[] = sprintf('%s: %s', $key, strlen($string_value) > 120 ? substr($string_value, 0, 117) . '...' : $string_value);
                    }
                }
                break;
        }

        if (empty($details)) {
            $details[] = __('No extra details were provided for this action.', 'abw-ai');
        }

        return $details;
    }

    /**
     * Convert a tool slug to a human-readable label.
     *
     * @param string $tool_name Tool name.
     * @return string
     */
    private static function humanize_tool_name(string $tool_name): string
    {
        return ucwords(str_replace('_', ' ', $tool_name));
    }

    // =========================================================================
    // Block Editor Tool Handlers
    //
    // These return structured data with a __block_actions key. The chat
    // handler detects this key and passes the actions to the frontend JS
    // which executes them via wp.data.dispatch.
    // =========================================================================

    /**
     * Insert blocks into the editor.
     *
     * @param array $args { html: string, position?: string }
     * @return array Block action payload.
     */
    public static function execute_insert_editor_blocks(array $args): array
    {
        $html     = $args['html'] ?? '';
        $position = $args['position'] ?? 'end';

        if (empty($html)) {
            return ['success' => false, 'message' => 'No HTML content provided.'];
        }

        return [
            'success'        => true,
            'message'        => 'Blocks will be inserted.',
            '__block_actions' => [
                [
                    'action'   => 'insert_blocks',
                    'html'     => $html,
                    'position' => $position,
                ],
            ],
        ];
    }

    /**
     * Replace all editor content.
     *
     * @param array $args { html: string }
     * @return array Block action payload.
     */
    public static function execute_replace_editor_content(array $args): array
    {
        $html = $args['html'] ?? '';

        if (empty($html)) {
            return ['success' => false, 'message' => 'No HTML content provided.'];
        }

        return [
            'success'        => true,
            'message'        => 'Editor content will be replaced.',
            '__block_actions' => [
                [
                    'action' => 'replace_all',
                    'html'   => $html,
                ],
            ],
        ];
    }

    /**
     * Update a specific editor block.
     *
     * @param array $args { block_index: int, attributes: object }
     * @return array Block action payload.
     */
    public static function execute_update_editor_block(array $args): array
    {
        $block_index = $args['block_index'] ?? null;
        $attributes  = $args['attributes'] ?? [];

        if ($block_index === null) {
            return ['success' => false, 'message' => 'Block index is required.'];
        }

        return [
            'success'        => true,
            'message'        => sprintf('Block [%d] will be updated.', $block_index),
            '__block_actions' => [
                [
                    'action'      => 'update_block',
                    'block_index' => (int) $block_index,
                    'attributes'  => $attributes,
                ],
            ],
        ];
    }

    /**
     * Remove blocks from the editor.
     *
     * @param array $args { block_indices: array }
     * @return array Block action payload.
     */
    public static function execute_remove_editor_blocks(array $args): array
    {
        $indices = $args['block_indices'] ?? [];

        if (empty($indices)) {
            return ['success' => false, 'message' => 'No block indices provided.'];
        }

        return [
            'success'        => true,
            'message'        => sprintf('Blocks [%s] will be removed.', implode(', ', $indices)),
            '__block_actions' => [
                [
                    'action'        => 'remove_blocks',
                    'block_indices' => array_map('intval', $indices),
                ],
            ],
        ];
    }

    /**
     * Save the current post.
     *
     * @param array $args Unused.
     * @return array Block action payload.
     */
    public static function execute_save_current_post(array $args = []): array
    {
        return [
            'success'        => true,
            'message'        => 'Post will be saved.',
            '__block_actions' => [
                [
                    'action' => 'save_post',
                ],
            ],
        ];
    }

    /**
     * Update post details (title, status, excerpt).
     *
     * @param array $args { title?: string, status?: string, excerpt?: string }
     * @return array Block action payload.
     */
    public static function execute_update_post_details(array $args): array
    {
        $action = ['action' => 'update_post_meta'];

        if (isset($args['title'])) {
            $action['title'] = $args['title'];
        }
        if (isset($args['status'])) {
            $action['status'] = $args['status'];
        }
        if (isset($args['excerpt'])) {
            $action['excerpt'] = $args['excerpt'];
        }

        return [
            'success'        => true,
            'message'        => 'Post details will be updated.',
            '__block_actions' => [$action],
        ];
    }

    /**
     * Check whether a tool name is a block editor tool.
     *
     * @param string $tool_name Tool name.
     * @return bool
     */
    public static function is_block_editor_tool(string $tool_name): bool
    {
        return in_array($tool_name, [
            'insert_editor_blocks',
            'replace_editor_content',
            'update_editor_block',
            'remove_editor_blocks',
            'save_current_post',
            'update_post_details',
        ], true);
    }

    /**
     * Get available tools for AI
     *
     * @return array
     */
    public static function get_available_tools(string $agent_mode = 'general'): array
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
                        'id'           => ['type' => 'integer', 'description' => 'Post ID. Use the exact ID returned by previous tools. If no reliable ID is known, pass 0 and provide lookup_title.'],
                        'title'        => ['type' => 'string', 'description' => 'New title'],
                        'content'      => ['type' => 'string', 'description' => 'New content'],
                        'status'       => ['type' => 'string', 'description' => 'New status'],
                        'lookup_title' => ['type' => 'string', 'description' => 'Optional exact current post title for safe server-side resolution when no reliable ID is known'],
                        'post_type'    => ['type' => 'string', 'description' => 'Optional post type filter for lookup_title'],
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
            [
                'name'        => 'update_plugin',
                'description' => 'Update a WordPress plugin to the latest available version',
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
            [
                'name'        => 'update_theme',
                'description' => 'Update a WordPress theme to the latest available version',
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
                'description' => 'Get general WordPress site information (name, URL, version, theme). Not for update checks.',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'get_site_health',
                'description' => 'Get WordPress Site Health test results and statuses',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'check_plugin_updates',
                'description' => 'Check which installed plugins have updates available (name, current version, new version)',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'check_theme_updates',
                'description' => 'Check which installed themes have updates available (name, current version, new version)',
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
            $tools[] = [
                'name'        => 'bulk_update_products',
                'description' => 'Bulk update multiple WooCommerce products (price, stock, status)',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['updates'],
                    'properties' => [
                        'updates' => ['type' => 'array', 'description' => 'Array of product updates, each with id and fields to update', 'items' => ['type' => 'object']],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'get_sales_report',
                'description' => 'Get WooCommerce sales report with revenue, order count, and top products',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'period'    => ['type' => 'string', 'description' => 'Report period: today, week, month, year', 'default' => 'month'],
                        'date_from' => ['type' => 'string', 'description' => 'Start date (Y-m-d)'],
                        'date_to'   => ['type' => 'string', 'description' => 'End date (Y-m-d)'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'get_customer_stats',
                'description' => 'Get WooCommerce customer statistics including total customers, repeat rate, and average order value',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
            ];
            $tools[] = [
                'name'        => 'create_coupon',
                'description' => 'Create a WooCommerce coupon code',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['code', 'amount'],
                    'properties' => [
                        'code'          => ['type' => 'string', 'description' => 'Coupon code'],
                        'discount_type' => ['type' => 'string', 'description' => 'Type: percent, fixed_cart, fixed_product', 'default' => 'percent'],
                        'amount'        => ['type' => 'number', 'description' => 'Discount amount'],
                        'expiry_date'   => ['type' => 'string', 'description' => 'Expiry date (Y-m-d)'],
                        'usage_limit'   => ['type' => 'integer', 'description' => 'Maximum usage count'],
                        'minimum_amount' => ['type' => 'number', 'description' => 'Minimum order amount'],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'list_coupons',
                'description' => 'List WooCommerce coupons',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => ['type' => 'integer', 'description' => 'Coupons per page', 'default' => 20],
                    ],
                ],
            ];
            $tools[] = [
                'name'        => 'analyze_product_performance',
                'description' => 'Analyze sales performance of a specific WooCommerce product',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['product_id'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'Product ID to analyze'],
                        'period'     => ['type' => 'string', 'description' => 'Analysis period: week, month, year', 'default' => 'month'],
                    ],
                ],
            ];
        }

        // Block Editor tools (only included when editor context is present)
        $tools[] = [
            'name'        => 'insert_editor_blocks',
            'description' => 'Insert HTML content as blocks into the block editor at a specific position. The HTML will be automatically converted to WordPress blocks. Use standard HTML (h2, p, ul, ol, img, table, blockquote, pre/code, etc.).',
            'parameters'  => [
                'type'       => 'object',
                'required'   => ['html'],
                'properties' => [
                    'html'     => ['type' => 'string', 'description' => 'HTML content to insert (h1-h6, p, ul, ol, img, table, blockquote, pre, etc.)'],
                    'position' => ['type' => 'string', 'description' => 'Where to insert: "start", "end" (default), or "after:N" where N is block index'],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'replace_editor_content',
            'description' => 'Replace ALL content in the block editor with new HTML. Use this to completely rewrite the post content.',
            'parameters'  => [
                'type'       => 'object',
                'required'   => ['html'],
                'properties' => [
                    'html' => ['type' => 'string', 'description' => 'Complete HTML content to replace all editor blocks'],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'update_editor_block',
            'description' => 'Update the content attribute of a specific block by its index. Reference blocks by their [N] index from the editor context.',
            'parameters'  => [
                'type'       => 'object',
                'required'   => ['block_index', 'attributes'],
                'properties' => [
                    'block_index' => ['type' => 'integer', 'description' => 'Block index from the editor context (e.g., 0, 1, 2...)'],
                    'attributes'  => ['type' => 'object', 'description' => 'Block attributes to update (e.g., {"content": "new text"})'],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'remove_editor_blocks',
            'description' => 'Remove blocks from the editor by their indices.',
            'parameters'  => [
                'type'       => 'object',
                'required'   => ['block_indices'],
                'properties' => [
                    'block_indices' => ['type' => 'array', 'description' => 'Array of block indices to remove', 'items' => ['type' => 'integer']],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'save_current_post',
            'description' => 'Save the current post in the block editor. Call this after making changes if the user wants to save.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => new \stdClass(),
            ],
        ];
        $tools[] = [
            'name'        => 'update_post_details',
            'description' => 'Update post metadata like title, status, or excerpt in the block editor.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'title'   => ['type' => 'string', 'description' => 'New post title'],
                    'status'  => ['type' => 'string', 'description' => 'Post status: draft, publish, pending, private'],
                    'excerpt' => ['type' => 'string', 'description' => 'Post excerpt'],
                ],
            ],
        ];

        // Add AI tools
        $ai_tools = ABW_AI_Tools::get_tools_list();
        $tools = array_merge($tools, $ai_tools);

        // Add Brand Voice tools
        $brand_voice_tools = ABW_Brand_Voice::get_tools_list();
        $tools = array_merge($tools, $brand_voice_tools);

        // Add Workflow tools
        $workflow_tools = ABW_AI_Workflows::get_tools_list();
        $tools = array_merge($tools, $workflow_tools);

        // Add Security tools
        $security_tools = ABW_Security_Tools::get_tools_list();
        $tools = array_merge($tools, $security_tools);

        // Add Database & Performance tools
        $tools[] = [
            'name'        => 'get_database_stats',
            'description' => 'Get database statistics including table sizes, row counts, and total database size',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'cleanup_database',
            'description' => 'Clean up the WordPress database by removing revisions, auto-drafts, trashed posts, spam comments, and expired transients',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Types to clean: revisions, auto_drafts, trashed_posts, spam_comments, trashed_comments, expired_transients. Defaults to all.'],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'optimize_database_tables',
            'description' => 'Run OPTIMIZE TABLE on all WordPress database tables to reclaim space and improve performance',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'list_cron_jobs',
            'description' => 'List all scheduled WordPress cron events with their hooks, schedules, and next run times',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'delete_cron_job',
            'description' => 'Delete a scheduled WordPress cron event by its hook name',
            'parameters'  => [
                'type'       => 'object',
                'required'   => ['hook'],
                'properties' => [
                    'hook'      => ['type' => 'string', 'description' => 'Cron hook name to delete'],
                    'timestamp' => ['type' => 'integer', 'description' => 'Specific timestamp to unschedule (optional)'],
                ],
            ],
        ];
        $tools[] = [
            'name'        => 'list_transients',
            'description' => 'List transient counts (total, expired, active) in the WordPress database',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'flush_transients',
            'description' => 'Delete all expired transients from the WordPress database',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'get_autoload_report',
            'description' => 'Report the largest autoloaded options in wp_options, which impact every page load',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];
        $tools[] = [
            'name'        => 'get_performance_report',
            'description' => 'Get a comprehensive WordPress performance report including PHP info, plugin count, database stats, and cron jobs',
            'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
        ];

        return self::filter_tools_for_agent_mode($tools, $agent_mode);
    }

    /**
     * Return the available agent packages for the chat UI.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_agent_packages(): array
    {
        return [
            'general' => [
                'label'        => __('General', 'abw-ai'),
                'description'  => __('Use the full ABW-AI toolset across content, operations, and maintenance.', 'abw-ai'),
                'instructions' => 'Use the full toolset and pick the best tools for the user request.',
                'tools'        => [],
            ],
            'copilot' => [
                'label'        => __('Copilot', 'abw-ai'),
                'description'  => __('Act like a site-wide advisor that can brief, diagnose, and recommend next actions across the whole WordPress admin.', 'abw-ai'),
                'instructions' => 'Start with broad situational awareness. Prefer daily briefs, opportunity scans, and site-wide reports before drilling into specific actions.',
                'tools'        => [ 'get_daily_brief', 'get_site_opportunities', 'generate_site_report', 'get_post_stats', 'get_popular_content', 'get_recent_activity', 'get_content_calendar', 'get_publishing_stats', 'get_comment_stats', 'get_site_health', 'get_performance_report', 'get_security_report', 'check_plugin_updates', 'check_theme_updates', 'list_workflows', 'run_workflow', 'search_site', 'list_posts', 'get_post' ],
            ],
            'siteops' => [
                'label'        => __('SiteOps', 'abw-ai'),
                'description'  => __('Focus on plugins, themes, options, updates, and performance operations.', 'abw-ai'),
                'instructions' => 'Prioritize site operations, diagnostics, updates, and settings changes.',
                'tools'        => [ 'list_plugins', 'activate_plugin', 'deactivate_plugin', 'update_plugin', 'list_themes', 'activate_theme', 'update_theme', 'get_site_info', 'get_site_health', 'check_plugin_updates', 'check_theme_updates', 'update_site_identity', 'get_option', 'update_option', 'get_reading_settings', 'update_reading_settings', 'list_menus', 'get_menu', 'create_menu', 'add_menu_item', 'update_menu_item', 'delete_menu_item', 'assign_menu_location', 'get_database_stats', 'cleanup_database', 'optimize_database_tables', 'list_cron_jobs', 'delete_cron_job', 'list_transients', 'flush_transients', 'get_autoload_report', 'get_performance_report' ],
            ],
            'content' => [
                'label'        => __('Content', 'abw-ai'),
                'description'  => __('Focus on writing, rewriting, SEO, translation, and post/page management.', 'abw-ai'),
                'instructions' => 'Prioritize content production, post management, SEO, translation, and media preparation.',
                'tools'        => [ 'list_posts', 'get_post', 'create_post', 'update_post', 'delete_post', 'bulk_update_posts', 'bulk_delete_posts', 'find_replace_content', 'list_media', 'upload_media', 'update_media', 'delete_media', 'generate_post_content', 'improve_content', 'generate_seo_meta', 'translate_content', 'generate_image_alt', 'generate_faq', 'generate_schema_markup', 'summarize_content', 'generate_social_posts', 'analyze_content_sentiment', 'detect_content_language', 'check_content_accessibility', 'generate_product_description', 'rewrite_for_tone', 'generate_outline', 'expand_from_outline', 'generate_excerpt_ai', 'generate_table_of_contents', 'analyze_seo_score', 'suggest_internal_links', 'generate_slug', 'check_broken_links', 'detect_and_translate_post', 'bulk_translate_posts', 'manage_translations' ],
            ],
            'editor' => [
                'label'        => __('Editor/Page', 'abw-ai'),
                'description'  => __('Focus on Gutenberg page-building workflows.', 'abw-ai'),
                'instructions' => 'Prioritize block editor and page-building tasks. Use editor-specific tools before general post tools when available.',
                'tools'        => [ 'list_posts', 'get_post', 'update_post', 'list_media', 'upload_media', 'update_media', 'insert_editor_blocks', 'replace_editor_content', 'update_editor_block', 'remove_editor_blocks', 'save_current_post', 'update_post_details', 'generate_post_content', 'improve_content', 'generate_css', 'suggest_color_scheme', 'generate_outline', 'expand_from_outline', 'generate_table_of_contents' ],
            ],
            'workflow' => [
                'label'        => __('Workflow', 'abw-ai'),
                'description'  => __('Focus on building, running, and reviewing repeatable automations.', 'abw-ai'),
                'instructions' => 'Prioritize automation design, trigger selection, reusable variables, and step-by-step workflow execution.',
                'tools'        => [ 'list_workflows', 'create_workflow', 'toggle_workflow', 'run_workflow', 'search_site', 'get_recent_activity', 'get_site_health', 'get_site_info', 'list_posts', 'get_post', 'list_comments', 'list_orders', 'get_order', 'get_content_calendar', 'generate_site_report' ],
            ],
            'commerce' => [
                'label'        => __('Commerce', 'abw-ai'),
                'description'  => __('Focus on WooCommerce catalog, order, and sales operations.', 'abw-ai'),
                'instructions' => 'Prioritize WooCommerce products, orders, coupons, sales reporting, and catalog content.',
                'tools'        => [ 'list_products', 'get_product', 'update_product', 'bulk_update_products', 'list_orders', 'get_order', 'update_order_status', 'get_sales_report', 'get_customer_stats', 'create_coupon', 'list_coupons', 'analyze_product_performance', 'generate_product_description' ],
            ],
            'security' => [
                'label'        => __('Security', 'abw-ai'),
                'description'  => __('Focus on security, integrity checks, maintenance, and recovery guidance.', 'abw-ai'),
                'instructions' => 'Prioritize security posture, integrity scans, admin audits, SSL, and maintenance follow-up actions.',
                'tools'        => [ 'get_site_health', 'check_plugin_updates', 'check_theme_updates', 'scan_file_integrity', 'list_failed_logins', 'check_file_permissions', 'get_security_report', 'list_admin_users', 'check_ssl_status', 'list_plugins', 'update_plugin', 'list_themes', 'update_theme' ],
            ],
            'brand' => [
                'label'        => __('Brand/Voice', 'abw-ai'),
                'description'  => __('Focus on tone, consistency, brand voice training, and editorial polish.', 'abw-ai'),
                'instructions' => 'Prioritize brand consistency, voice training, rewriting, and editorial guidance.',
                'tools'        => [ 'list_posts', 'get_post', 'train_brand_voice', 'get_brand_voice', 'improve_content', 'rewrite_for_tone', 'generate_seo_meta', 'generate_outline', 'expand_from_outline', 'generate_social_posts' ],
            ],
        ];
    }

    /**
     * Filter tool definitions for the selected agent mode.
     *
     * @param array  $tools      Full tool list.
     * @param string $agent_mode Selected agent mode.
     * @return array
     */
    private static function filter_tools_for_agent_mode(array $tools, string $agent_mode): array
    {
        $agent_mode = self::normalize_agent_mode($agent_mode);
        $packages   = self::get_agent_packages();
        $allowed    = $packages[$agent_mode]['tools'] ?? [];

        if (empty($allowed)) {
            return $tools;
        }

        return array_values(array_filter($tools, static function ($tool) use ($allowed) {
            return in_array($tool['name'] ?? '', $allowed, true);
        }));
    }

    /**
     * Normalize an agent mode to a known package key.
     *
     * @param string $agent_mode Requested mode.
     * @return string
     */
    private static function normalize_agent_mode(string $agent_mode): string
    {
        $packages = self::get_agent_packages();
        return isset($packages[$agent_mode]) ? $agent_mode : 'general';
    }

    /**
     * Get system prompt for AI
     *
     * @param string $editor_context Optional serialized block editor context.
     * @return string
     */
    public static function get_system_prompt(string $editor_context = '', string $agent_mode = 'general'): string
    {
        $site_name = get_bloginfo('name');
        $site_url  = home_url();
        $wp_version = get_bloginfo('version');
        $theme_name = wp_get_theme()->get('Name');
        $agent_mode = self::normalize_agent_mode($agent_mode);
        $agent_package = self::get_agent_packages()[$agent_mode];

        $prompt = <<<PROMPT
You are ABW-AI, an Advanced Butler for WordPress. You are a helpful AI assistant that helps users manage their WordPress website.

Current WordPress Site:
- Site Name: {$site_name}
- URL: {$site_url}
- WordPress Version: {$wp_version}
- Active Theme: {$theme_name}

Active Agent Package:
- Mode: {$agent_package['label']}
- Focus: {$agent_package['description']}
- Instructions: {$agent_package['instructions']}

Your capabilities:
- POSTS & PAGES: Create, edit, update, delete, and list posts and pages with filtering
- USERS: Create, update, delete, list, and get user details. You can create users with username, email, password (auto-generated if not provided), display name, and role
- MEDIA: Upload, update, delete, and list media files. Upload from URLs or base64 data
- COMMENTS: List and moderate comments (approve, hold, spam, trash)
- PLUGINS: List, activate, deactivate, check updates, and update plugins
- THEMES: List, activate, check updates, and update themes
- TAXONOMIES: List taxonomies, create/update/delete terms (categories, tags, custom taxonomies)
- MENUS: Create menus, add/update/delete menu items, assign menu locations
- SITE OPTIONS: Get and update WordPress options (safely whitelisted options only)
- SEARCH & ANALYTICS: Search site content, get post statistics, popular content, recent activity
- BULK OPERATIONS: Bulk update/delete posts, bulk moderate comments, find and replace content
- SITE HEALTH: Get site health status, check for plugin/theme updates
- BLOCK THEMES: List block patterns and template parts (for block themes)
- WOOCOMMERCE: List/update products and orders, sales reports, customer stats, coupons, product performance analysis (if WooCommerce is active)
- AI CONTENT: Generate posts, product descriptions, outlines, excerpts, TOC, rewrite for tone, expand outlines into full content
- BRAND VOICE: Train brand voice from existing posts, apply consistent writing style
- SEO SUITE: Analyze SEO score, suggest internal links, generate slugs, check broken links, generate schema markup, generate SEO meta
- IMAGE ALT TEXT: Generate descriptive alt text for images using AI
- DATABASE & PERFORMANCE: Database stats, cleanup (revisions/drafts/trash/spam/transients), optimize tables, cron management, autoload audit, performance report
- SECURITY: File integrity scan, file permissions audit, SSL status, admin user audit, security report with scoring
- WORKFLOWS: Create automated AI workflows with scheduled or event-based triggers, list/toggle/run workflows
- TRANSLATION: Detect and translate posts, bulk translate, WPML/Polylang integration
- ANALYTICS: Content calendar, publishing stats, comment stats, comprehensive site report generation
- COPILOT BRIEFS: Generate daily briefs and site-wide opportunity scans grounded in live site data

IMPORTANT - User Creation:
- You CAN create users using the create_user tool
- Required: username and email
- Optional: password (auto-generated if not provided), display_name, role (defaults to 'subscriber')
- Example: To create a user, use create_user with username and email parameters

Execution policy:
1. Identify intent first:
   - INFORMATIONAL: User asks to view, inspect, explain, or report. Use read/list tools and return findings.
   - ACTION: User asks to create, update, delete, publish, moderate, optimize, or configure. You MUST execute the action tools, not just describe data.
2. Complete the full task end-to-end. If a request needs multiple steps (for example list -> filter -> delete), continue calling tools until done.
3. Do not stop after a discovery/list step when the user asked for an action.
4. High-impact changes such as deletes, user management, theme/plugin updates, and settings changes require an approval step before execution. Gather what you need, explain the impact, and let the confirmation flow handle the final approval.
5. Never return an empty response. Always explain what happened, including counts and outcomes.
6. Handle tool errors gracefully and propose the next best action.

ReACT loop for multi-step tasks:
- THINK: Determine required steps and required data.
- ACT: Call the best next tool for the current step.
- OBSERVE: Validate tool output (success/failure, counts, pagination, remaining work).
- REPEAT: Continue tool calls until the requested outcome is actually completed.
- RESPOND: Give a concise completion report that states exactly what changed.

Tool-calling discipline:
- For "find then modify/delete" requests, gather IDs first, then call the action tool in the same workflow.
- Never invent IDs. Reuse IDs exactly as returned by tool results.
- If no reliable post ID is known for `update_post`, first call `list_posts`/`get_post`, or use `lookup_title` for exact server-side title resolution.
- For bulk actions, handle pagination when needed so "all" really means all matching items.
- Prefer bulk tools for multi-item actions (`bulk_delete_posts`, `bulk_update_posts`, `bulk_moderate_comments`) when available.
- When creating users, always use the `create_user` tool. It is available and functional.
- For site-wide advice such as "what should I work on?" or "give me a brief", prefer `get_daily_brief` or `get_site_opportunities` before free-form analysis.
- Apply durable user preferences and goals from persistent memory when they are relevant.

High-priority runbook:
- Request: "delete/remove/empty all posts from trash"
  1) Call `list_posts` with `status: "trash"` (paginate if needed).
  2) Collect all returned post IDs.
  3) Prepare `bulk_delete_posts` with `post_ids` and `force: true`; the system will ask the user to confirm before deletion.
  4) After approval, report deleted/total counts.
- Request: "check plugin and theme updates"
  1) Call `check_plugin_updates`.
  2) Call `check_theme_updates`.
  3) Report update counts and list items with current -> new version.
  4) Do NOT use `get_site_info` for this task.
- Request: "update plugin and/or theme"
  1) Call `check_plugin_updates` and/or `check_theme_updates` to discover targets.
  2) If the user asked for specific items, prepare `update_plugin` / `update_theme` only for those targets.
  3) If the user asked to update all available items, prepare `update_plugin` / `update_theme` for each available update.
  4) Let the approval flow confirm the updates, then report which items were updated and any failures.

Response style:
- Be helpful, direct, and action-oriented.
- Explain brief intent before sensitive changes.
- Do NOT add a "Summary", "Quick Summary", or "TL;DR" section unless explicitly requested.
- Always prioritize user intent and provide clear, actionable responses.
PROMPT;

        // Append block editor instructions when editor context is provided.
        if (! empty($editor_context)) {
            $prompt .= <<<EDITOR


--- BLOCK EDITOR MODE ---

You are currently helping the user edit a post in the WordPress Block Editor (Gutenberg).
You can see the full block structure below and can directly manipulate blocks in the editor.

BLOCK EDITOR TOOLS:
- insert_editor_blocks: Insert new content (HTML) at a position (start, end, or after block index N). The HTML is automatically converted to WordPress blocks (headings, paragraphs, lists, images, tables, quotes, code blocks, etc.).
- replace_editor_content: Replace ALL editor content with new HTML. Use this to completely rewrite the post.
- update_editor_block: Update a specific block's attributes by its index [N].
- remove_editor_blocks: Remove blocks by their indices.
- save_current_post: Save the post after making changes.
- update_post_details: Update post title, status (draft/publish/pending/private), or excerpt.

CURRENT EDITOR STATE:
{$editor_context}

IMPORTANT RULES FOR BLOCK EDITOR:
- Reference blocks by their index [N] shown in the editor state above.
- When generating content, output standard HTML (h1-h6, p, ul, ol, li, img, table, blockquote, pre, code, figure, etc.). The frontend automatically converts HTML into proper WordPress blocks.
- For headings, use <h2>, <h3>, etc. For lists, use <ul>/<ol> with <li>. For images, use <img> with src and alt.
- Always explain what changes you are making before executing the tools.
- Use insert_editor_blocks to ADD content. Use replace_editor_content to REWRITE everything.
- If the user asks to save, call save_current_post after making changes.
- You can chain multiple tools in one response (e.g., update_post_details to set title + insert_editor_blocks to add content + save_current_post to save).
- Do NOT use the create_post or update_post tools when in the block editor. Use the editor-specific tools instead since they update the editor directly and the user can see changes live.
EDITOR;
        }

        return $prompt;
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

<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable Squiz.Classes.ValidClassName.NotPascalCase
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/**
 * WordPress Abilities API Registration
 *
 * Registers ABW-AI abilities with WordPress core Abilities API
 * for MCP Adapter integration.
 *
 * @package ABW_AI
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class ABW_Abilities_Registration
 *
 * Registers all ABW-AI capabilities as WordPress Abilities
 * for native MCP integration via the MCP Adapter plugin.
 */
class ABW_Abilities_Registration
{
    /**
     * Initialize abilities registration
     */
    public static function init()
    {
        // Only register if Abilities API is available (WordPress 6.9+)
        if (!function_exists('wp_register_ability')) {
            return;
        }

        add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities']);
    }

    /**
     * Register all ABW-AI abilities with WordPress
     */
    public static function register_abilities()
    {
        // Register ability category
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('abw-ai', [
                'label'       => __('ABW-AI Butler', 'abw-ai'),
                'description' => __('Advanced AI management abilities for your site', 'abw-ai'),
            ]);
        }

        // =====================
        // Posts Abilities
        // =====================
        wp_register_ability('abw-ai/list-posts', [
            'label'       => __('List Posts', 'abw-ai'),
            'description' => __('Retrieve WordPress posts with filtering options', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type to retrieve',
                        'default'     => 'post',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'description' => 'Number of posts per page',
                        'default'     => 10,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ],
                    'page' => [
                        'type'        => 'integer',
                        'description' => 'Page number',
                        'default'     => 1,
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Post status filter',
                        'enum'        => ['publish', 'draft', 'pending', 'private', 'any'],
                        'default'     => 'publish',
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Search query',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_posts'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/get-post', [
            'label'       => __('Get Post', 'abw-ai'),
            'description' => __('Retrieve a single post by ID', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_post'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/create-post', [
            'label'       => __('Create Post', 'abw-ai'),
            'description' => __('Create a new WordPress post', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Post title',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Post content (HTML)',
                    ],
                    'excerpt' => [
                        'type'        => 'string',
                        'description' => 'Post excerpt',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Post status',
                        'enum'        => ['publish', 'draft', 'pending', 'private'],
                        'default'     => 'draft',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'Post type',
                        'default'     => 'post',
                    ],
                    'categories' => [
                        'type'        => 'array',
                        'description' => 'Category IDs',
                        'items'       => ['type' => 'integer'],
                    ],
                    'tags' => [
                        'type'        => 'array',
                        'description' => 'Tag IDs or names',
                        'items'       => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_create_post'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/update-post', [
            'label'       => __('Update Post', 'abw-ai'),
            'description' => __('Update an existing WordPress post', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to update',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'New post title',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'New post content',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'New post status',
                        'enum'        => ['publish', 'draft', 'pending', 'private', 'trash'],
                    ],
                    'lookup_title' => [
                        'type'        => 'string',
                        'description' => 'Optional exact current post title to resolve the target safely when no reliable ID is known',
                    ],
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Optional post type filter when resolving by lookup_title',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_post'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/delete-post', [
            'label'       => __('Delete Post', 'abw-ai'),
            'description' => __('Delete a WordPress post', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to delete',
                    ],
                    'force' => [
                        'type'        => 'boolean',
                        'description' => 'Skip trash and permanently delete',
                        'default'     => false,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_delete_post'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // Comments Abilities
        // =====================
        wp_register_ability('abw-ai/list-comments', [
            'label'       => __('List Comments', 'abw-ai'),
            'description' => __('Retrieve WordPress comments with filtering', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Filter by post ID',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Comment status',
                        'enum'        => ['approve', 'hold', 'spam', 'trash', 'all'],
                        'default'     => 'all',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'description' => 'Comments per page',
                        'default'     => 20,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_comments'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/moderate-comment', [
            'label'       => __('Moderate Comment', 'abw-ai'),
            'description' => __('Approve, spam, or trash a comment', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id', 'action'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Comment ID',
                    ],
                    'action' => [
                        'type'        => 'string',
                        'description' => 'Moderation action',
                        'enum'        => ['approve', 'unapprove', 'spam', 'trash', 'delete'],
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_moderate_comment'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // Plugin Abilities
        // =====================
        wp_register_ability('abw-ai/list-plugins', [
            'label'       => __('List Plugins', 'abw-ai'),
            'description' => __('List all installed WordPress plugins', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Filter by status',
                        'enum'        => ['active', 'inactive', 'all'],
                        'default'     => 'all',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_plugins'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/activate-plugin', [
            'label'       => __('Activate Plugin', 'abw-ai'),
            'description' => __('Activate a WordPress plugin', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['plugin'],
                'properties' => [
                    'plugin' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php")',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_activate_plugin'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/deactivate-plugin', [
            'label'       => __('Deactivate Plugin', 'abw-ai'),
            'description' => __('Deactivate a WordPress plugin', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['plugin'],
                'properties' => [
                    'plugin' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_deactivate_plugin'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/update-plugin', [
            'label'       => __('Update Plugin', 'abw-ai'),
            'description' => __('Update a WordPress plugin to the latest available version', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['plugin'],
                'properties' => [
                    'plugin' => [
                        'type'        => 'string',
                        'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php")',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_plugin'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Theme Abilities
        // =====================
        wp_register_ability('abw-ai/list-themes', [
            'label'       => __('List Themes', 'abw-ai'),
            'description' => __('List all installed WordPress themes', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_themes'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/activate-theme', [
            'label'       => __('Activate Theme', 'abw-ai'),
            'description' => __('Activate a WordPress theme', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['stylesheet'],
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Theme stylesheet (directory name)',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_activate_theme'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/update-theme', [
            'label'       => __('Update Theme', 'abw-ai'),
            'description' => __('Update a WordPress theme to the latest available version', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['stylesheet'],
                'properties' => [
                    'stylesheet' => [
                        'type'        => 'string',
                        'description' => 'Theme stylesheet (directory name)',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_theme'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Site Settings Abilities
        // =====================
        wp_register_ability('abw-ai/get-site-info', [
            'label'       => __('Get Site Info', 'abw-ai'),
            'description' => __('Get WordPress site information', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_site_info'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/update-site-identity', [
            'label'       => __('Update Site Identity', 'abw-ai'),
            'description' => __('Update site title and tagline', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Site title',
                    ],
                    'tagline' => [
                        'type'        => 'string',
                        'description' => 'Site tagline/description',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_site_identity'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Menu Abilities
        // =====================
        wp_register_ability('abw-ai/list-menus', [
            'label'       => __('List Menus', 'abw-ai'),
            'description' => __('List all WordPress navigation menus', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_menus'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/get-menu', [
            'label'       => __('Get Menu', 'abw-ai'),
            'description' => __('Get menu with all items', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Menu ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_menu'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        // =====================
        // Media Abilities
        // =====================
        wp_register_ability('abw-ai/list-media', [
            'label'       => __('List Media', 'abw-ai'),
            'description' => __('List media library items', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'mime_type' => [
                        'type'        => 'string',
                        'description' => 'Filter by MIME type (e.g., "image")',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'default'     => 20,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_media'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/upload-media', [
            'label'       => __('Upload Media', 'abw-ai'),
            'description' => __('Upload media from URL or base64', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['url'],
                'properties' => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'Media URL to download and upload',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Media title',
                    ],
                    'alt_text' => [
                        'type'        => 'string',
                        'description' => 'Alt text for image',
                    ],
                    'caption' => [
                        'type'        => 'string',
                        'description' => 'Media caption',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_upload_media'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/update-media', [
            'label'       => __('Update Media', 'abw-ai'),
            'description' => __('Update media metadata (title, alt text, caption)', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Media attachment ID',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'New title',
                    ],
                    'alt_text' => [
                        'type'        => 'string',
                        'description' => 'New alt text',
                    ],
                    'caption' => [
                        'type'        => 'string',
                        'description' => 'New caption',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_media'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/delete-media', [
            'label'       => __('Delete Media', 'abw-ai'),
            'description' => __('Delete a media item', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Media attachment ID',
                    ],
                    'force' => [
                        'type'        => 'boolean',
                        'description' => 'Skip trash and permanently delete',
                        'default'     => false,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_delete_media'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/generate-image-alt', [
            'label'       => __('Generate Image Alt Text', 'abw-ai'),
            'description' => __('AI-generate alt text for images', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'image_url' => [
                        'type'        => 'string',
                        'description' => 'Image URL',
                    ],
                    'image_description' => [
                        'type'        => 'string',
                        'description' => 'Image description',
                    ],
                ],
            ],
            'execute_callback'   => ['ABW_AI_Tools', 'generate_image_alt'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // User Abilities
        // =====================
        wp_register_ability('abw-ai/list-users', [
            'label'       => __('List Users', 'abw-ai'),
            'description' => __('List WordPress users with filtering', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'role' => [
                        'type'        => 'string',
                        'description' => 'Filter by role',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'description' => 'Users per page',
                        'default'     => 20,
                    ],
                    'page' => [
                        'type'        => 'integer',
                        'description' => 'Page number',
                        'default'     => 1,
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Search by username or email',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_users'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/get-user', [
            'label'       => __('Get User', 'abw-ai'),
            'description' => __('Get user details by ID', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_user'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/create-user', [
            'label'       => __('Create User', 'abw-ai'),
            'description' => __('Create a new WordPress user', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['username', 'email'],
                'properties' => [
                    'username' => [
                        'type'        => 'string',
                        'description' => 'Username',
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'Email address',
                    ],
                    'password' => [
                        'type'        => 'string',
                        'description' => 'Password (auto-generated if not provided)',
                    ],
                    'display_name' => [
                        'type'        => 'string',
                        'description' => 'Display name',
                    ],
                    'role' => [
                        'type'        => 'string',
                        'description' => 'User role',
                        'default'     => 'subscriber',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_create_user'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/update-user', [
            'label'       => __('Update User', 'abw-ai'),
            'description' => __('Update user profile and role', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID',
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'New email',
                    ],
                    'display_name' => [
                        'type'        => 'string',
                        'description' => 'New display name',
                    ],
                    'role' => [
                        'type'        => 'string',
                        'description' => 'New role',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_user'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/delete-user', [
            'label'       => __('Delete User', 'abw-ai'),
            'description' => __('Delete a user and optionally reassign content', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'User ID to delete',
                    ],
                    'reassign' => [
                        'type'        => 'integer',
                        'description' => 'User ID to reassign content to (optional)',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_delete_user'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Taxonomy Abilities
        // =====================
        wp_register_ability('abw-ai/list-taxonomies', [
            'label'       => __('List Taxonomies', 'abw-ai'),
            'description' => __('List all registered taxonomies', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_taxonomies'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/list-terms', [
            'label'       => __('List Terms', 'abw-ai'),
            'description' => __('List terms in a taxonomy', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['taxonomy'],
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy name (e.g., category, post_tag)',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'default'     => 50,
                    ],
                    'hide_empty' => [
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_terms'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/create-term', [
            'label'       => __('Create Term', 'abw-ai'),
            'description' => __('Create a new taxonomy term', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['taxonomy', 'name'],
                'properties' => [
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy name',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Term name',
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'Term slug',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Term description',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'Parent term ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_create_term'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/update-term', [
            'label'       => __('Update Term', 'abw-ai'),
            'description' => __('Update a taxonomy term', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id', 'taxonomy'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Term ID',
                    ],
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy name',
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'New term name',
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'description' => 'New term slug',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'New description',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'New parent term ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_term'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/delete-term', [
            'label'       => __('Delete Term', 'abw-ai'),
            'description' => __('Delete a taxonomy term', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id', 'taxonomy'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Term ID',
                    ],
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy name',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_delete_term'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // Menu Write Abilities
        // =====================
        wp_register_ability('abw-ai/create-menu', [
            'label'       => __('Create Menu', 'abw-ai'),
            'description' => __('Create a new navigation menu', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['name'],
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Menu name',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_create_menu'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/add-menu-item', [
            'label'       => __('Add Menu Item', 'abw-ai'),
            'description' => __('Add an item to a menu', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['menu_id', 'title'],
                'properties' => [
                    'menu_id' => [
                        'type'        => 'integer',
                        'description' => 'Menu ID',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Menu item title',
                    ],
                    'url' => [
                        'type'        => 'string',
                        'description' => 'URL for custom link',
                    ],
                    'object_id' => [
                        'type'        => 'integer',
                        'description' => 'Post/page ID for post/page item',
                    ],
                    'object' => [
                        'type'        => 'string',
                        'description' => 'Object type (post, page, category, etc.)',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'Parent menu item ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_add_menu_item'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/update-menu-item', [
            'label'       => __('Update Menu Item', 'abw-ai'),
            'description' => __('Update a menu item', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Menu item ID',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'New title',
                    ],
                    'url' => [
                        'type'        => 'string',
                        'description' => 'New URL',
                    ],
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'New parent item ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_menu_item'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/delete-menu-item', [
            'label'       => __('Delete Menu Item', 'abw-ai'),
            'description' => __('Delete a menu item', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => [
                        'type'        => 'integer',
                        'description' => 'Menu item ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_delete_menu_item'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/assign-menu-location', [
            'label'       => __('Assign Menu Location', 'abw-ai'),
            'description' => __('Assign a menu to a theme location', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['menu_id', 'location'],
                'properties' => [
                    'menu_id' => [
                        'type'        => 'integer',
                        'description' => 'Menu ID',
                    ],
                    'location' => [
                        'type'        => 'string',
                        'description' => 'Theme location slug',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_assign_menu_location'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // Site Options Abilities
        // =====================
        wp_register_ability('abw-ai/get-option', [
            'label'       => __('Get Option', 'abw-ai'),
            'description' => __('Get a WordPress option value', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['option'],
                'properties' => [
                    'option' => [
                        'type'        => 'string',
                        'description' => 'Option name',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_option'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/update-option', [
            'label'       => __('Update Option', 'abw-ai'),
            'description' => __('Update a WordPress option (whitelisted only)', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['option', 'value'],
                'properties' => [
                    'option' => [
                        'type'        => 'string',
                        'description' => 'Option name',
                    ],
                    'value' => [
                        'description' => 'Option value',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_option'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/get-reading-settings', [
            'label'       => __('Get Reading Settings', 'abw-ai'),
            'description' => __('Get WordPress reading settings', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_reading_settings'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/update-reading-settings', [
            'label'       => __('Update Reading Settings', 'abw-ai'),
            'description' => __('Update WordPress reading settings', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'posts_per_page' => [
                        'type'        => 'integer',
                        'description' => 'Posts per page',
                    ],
                    'show_on_front' => [
                        'type'        => 'string',
                        'enum'        => ['posts', 'page'],
                        'description' => 'Show on front',
                    ],
                    'page_on_front' => [
                        'type'        => 'integer',
                        'description' => 'Front page ID',
                    ],
                    'page_for_posts' => [
                        'type'        => 'integer',
                        'description' => 'Posts page ID',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_update_reading_settings'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Search & Analytics Abilities
        // =====================
        wp_register_ability('abw-ai/search-site', [
            'label'       => __('Search Site', 'abw-ai'),
            'description' => __('Full-text search across content types', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['query'],
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Search query',
                    ],
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type filter',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'default'     => 10,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_search_site'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/get-post-stats', [
            'label'       => __('Get Post Stats', 'abw-ai'),
            'description' => __('Get post count statistics by type and status', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_post_stats'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/get-popular-content', [
            'label'       => __('Get Popular Content', 'abw-ai'),
            'description' => __('Get most viewed or commented posts', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['comments', 'views'],
                        'default'     => 'comments',
                        'description' => 'Sort by comments or views',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'default'     => 10,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_popular_content'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/get-recent-activity', [
            'label'       => __('Get Recent Activity', 'abw-ai'),
            'description' => __('Get recent posts, comments, and edits', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'per_page' => [
                        'type'        => 'integer',
                        'default'     => 10,
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_recent_activity'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        // =====================
        // Bulk Operations Abilities
        // =====================
        wp_register_ability('abw-ai/bulk-update-posts', [
            'label'       => __('Bulk Update Posts', 'abw-ai'),
            'description' => __('Update multiple posts at once', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_ids'],
                'properties' => [
                    'post_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Array of post IDs',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'New status',
                    ],
                    'author' => [
                        'type'        => 'integer',
                        'description' => 'New author ID',
                    ],
                    'categories' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Category IDs to add',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_bulk_update_posts'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/bulk-delete-posts', [
            'label'       => __('Bulk Delete Posts', 'abw-ai'),
            'description' => __('Delete multiple posts', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['post_ids'],
                'properties' => [
                    'post_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Array of post IDs',
                    ],
                    'force' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Skip trash and permanently delete',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_bulk_delete_posts'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/find-replace-content', [
            'label'       => __('Find and Replace Content', 'abw-ai'),
            'description' => __('Find and replace text across posts', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['find', 'replace'],
                'properties' => [
                    'find' => [
                        'type'        => 'string',
                        'description' => 'Text to find',
                    ],
                    'replace' => [
                        'type'        => 'string',
                        'description' => 'Replacement text',
                    ],
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type filter',
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Preview changes without saving',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_find_replace_content'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        wp_register_ability('abw-ai/bulk-moderate-comments', [
            'label'       => __('Bulk Moderate Comments', 'abw-ai'),
            'description' => __('Moderate multiple comments at once', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['comment_ids', 'action'],
                'properties' => [
                    'comment_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Array of comment IDs',
                    ],
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['approve', 'unapprove', 'spam', 'trash', 'delete'],
                        'description' => 'Moderation action',
                    ],
                ],
            ],
            'execute_callback'   => [__CLASS__, 'execute_bulk_moderate_comments'],
            'permission_callback' => [__CLASS__, 'check_write_permission'],
        ]);

        // =====================
        // Site Health Abilities
        // =====================
        wp_register_ability('abw-ai/get-site-health', [
            'label'       => __('Get Site Health', 'abw-ai'),
            'description' => __('Get WordPress Site Health status', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_get_site_health'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/check-plugin-updates', [
            'label'       => __('Check Plugin Updates', 'abw-ai'),
            'description' => __('List plugins that need updates', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_check_plugin_updates'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        wp_register_ability('abw-ai/check-theme-updates', [
            'label'       => __('Check Theme Updates', 'abw-ai'),
            'description' => __('List themes that need updates', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_check_theme_updates'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // =====================
        // Block/Widget Management Abilities
        // =====================
        wp_register_ability('abw-ai/list-block-patterns', [
            'label'       => __('List Block Patterns', 'abw-ai'),
            'description' => __('List available block patterns', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_block_patterns'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        wp_register_ability('abw-ai/list-template-parts', [
            'label'       => __('List Template Parts', 'abw-ai'),
            'description' => __('List template parts (headers, footers, etc.)', 'abw-ai'),
            'category'    => 'abw-ai',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [],
            ],
            'execute_callback'   => [__CLASS__, 'execute_list_template_parts'],
            'permission_callback' => [__CLASS__, 'check_read_permission'],
        ]);

        // =====================
        // WooCommerce Abilities (conditional)
        // =====================
        if (class_exists('WooCommerce')) {
            wp_register_ability('abw-ai/list-products', [
                'label'       => __('List Products', 'abw-ai'),
                'description' => __('List WooCommerce products', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => [
                            'type'        => 'integer',
                            'default'     => 20,
                        ],
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['publish', 'draft', 'pending', 'private'],
                            'default'     => 'publish',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_list_products'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/get-product', [
                'label'       => __('Get Product', 'abw-ai'),
                'description' => __('Get WooCommerce product details', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Product ID',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_get_product'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/update-product', [
                'label'       => __('Update Product', 'abw-ai'),
                'description' => __('Update WooCommerce product', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Product ID',
                        ],
                        'price' => [
                            'type'        => 'string',
                            'description' => 'Product price',
                        ],
                        'stock_status' => [
                            'type'        => 'string',
                            'enum'        => ['instock', 'outofstock', 'onbackorder'],
                            'description' => 'Stock status',
                        ],
                        'stock_quantity' => [
                            'type'        => 'integer',
                            'description' => 'Stock quantity',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_update_product'],
                'permission_callback' => [__CLASS__, 'check_write_permission'],
            ]);

            wp_register_ability('abw-ai/list-orders', [
                'label'       => __('List Orders', 'abw-ai'),
                'description' => __('List WooCommerce orders', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => [
                            'type'        => 'integer',
                            'default'     => 20,
                        ],
                        'status' => [
                            'type'        => 'string',
                            'description' => 'Order status filter',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_list_orders'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/get-order', [
                'label'       => __('Get Order', 'abw-ai'),
                'description' => __('Get WooCommerce order details', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Order ID',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_get_order'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/update-order-status', [
                'label'       => __('Update Order Status', 'abw-ai'),
                'description' => __('Update WooCommerce order status', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id', 'status'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Order ID',
                        ],
                        'status' => [
                            'type'        => 'string',
                            'description' => 'New order status',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_update_order_status'],
                'permission_callback' => [__CLASS__, 'check_write_permission'],
            ]);
        }
    }

    // ===================
    // Permission Callbacks
    // ===================

    /**
     * Check read permission
     */
    public static function check_read_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Check write permission
     */
    public static function check_write_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Check admin permission
     */
    public static function check_admin_permission()
    {
        return current_user_can('manage_options');
    }

    // ===================
    // Execute Callbacks
    // ===================

    /**
     * Execute list posts ability
     */
    public static function execute_list_posts($input)
    {
        $args = [
            'post_type'      => $input['post_type'] ?? 'post',
            'posts_per_page' => $input['per_page'] ?? 10,
            'paged'          => $input['page'] ?? 1,
            'post_status'    => $input['status'] ?? 'publish',
        ];

        if (! empty($input['search'])) {
            $args['s'] = $input['search'];
        }

        $query = new WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'type'     => $post->post_type,
                'date'     => $post->post_date,
                'modified' => $post->post_modified,
            ];
        }

        return [
            'posts' => $posts,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ];
    }

    /**
     * Execute get post ability
     */
    public static function execute_get_post($input)
    {
        $post = get_post($input['id']);

        if (! $post) {
            return new WP_Error('not_found', 'Post not found');
        }

        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'content'        => $post->post_content,
            'excerpt'        => $post->post_excerpt,
            'status'         => $post->post_status,
            'type'           => $post->post_type,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author'         => $post->post_author,
            'featured_image' => get_post_thumbnail_id($post->ID),
        ];
    }

    /**
     * Execute create post ability
     */
    public static function execute_create_post($input)
    {
        $post_data = [
            'post_title'   => sanitize_text_field($input['title']),
            'post_content' => wp_kses_post($input['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($input['excerpt'] ?? ''),
            'post_status'  => $input['status'] ?? 'draft',
            'post_type'    => $input['type'] ?? 'post',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if (! empty($input['categories'])) {
            wp_set_post_categories($post_id, $input['categories']);
        }

        if (! empty($input['tags'])) {
            wp_set_post_tags($post_id, $input['tags']);
        }

        return [
            'id'       => $post_id,
            'success'  => true,
            'title'    => get_the_title($post_id),
            'status'   => get_post_status($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
        ];
    }

    /**
     * Execute update post ability
     */
    public static function execute_update_post($input)
    {
        $resolved = self::resolve_post_for_update($input);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $post_id   = (int) $resolved['id'];
        $post_data = ['ID' => $post_id];

        if (isset($input['title'])) {
            $post_data['post_title'] = sanitize_text_field($input['title']);
        }
        if (isset($input['content'])) {
            $post_data['post_content'] = wp_kses_post($input['content']);
        }
        if (isset($input['status'])) {
            $post_data['post_status'] = sanitize_key($input['status']);
        }

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'id'            => $post_id,
            'success'       => true,
            'title'         => get_the_title($post_id),
            'status'        => get_post_status($post_id),
            'edit_url'      => get_edit_post_link($post_id, 'raw'),
            'resolved_from' => $resolved['resolved_from'],
        ];
    }

    /**
     * Resolve a target post for update operations.
     *
     * @param array $input Update input.
     * @return array|WP_Error
     */
    private static function resolve_post_for_update($input)
    {
        $post_id = absint($input['id'] ?? 0);
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                return [
                    'id'            => $post_id,
                    'resolved_from' => 'id',
                ];
            }
        }

        $lookup_title = sanitize_text_field($input['lookup_title'] ?? '');
        if ('' === $lookup_title) {
            return new WP_Error(
                'invalid_post_id',
                __('Invalid post ID. Use list_posts/get_post first or provide lookup_title for exact title matching.', 'abw-ai')
            );
        }

        $post_type = sanitize_key($input['post_type'] ?? '');
        $args = [
            'post_status'      => 'any',
            'posts_per_page'   => 2,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'title'            => $lookup_title,
            'suppress_filters' => false,
            'fields'           => 'ids',
        ];

        if ('' !== $post_type) {
            $args['post_type'] = $post_type;
        } else {
            $args['post_type'] = 'any';
        }

        $matches   = get_posts($args);
        $match_cnt = is_array($matches) ? count($matches) : 0;

        if (1 !== $match_cnt) {
            if ($match_cnt > 1) {
                return new WP_Error(
                    'ambiguous_post',
                    __('Multiple posts matched lookup_title. Use the exact post ID from list_posts instead.', 'abw-ai')
                );
            }

            return new WP_Error(
                'post_not_found',
                __('No post matched lookup_title. Use list_posts first to confirm the exact title and ID.', 'abw-ai')
            );
        }

        return [
            'id'            => (int) $matches[0],
            'resolved_from' => 'lookup_title',
        ];
    }

    /**
     * Execute delete post ability
     */
    public static function execute_delete_post($input)
    {
        $force  = $input['force'] ?? false;
        $result = wp_delete_post($input['id'], $force);

        return [
            'success' => (bool) $result,
            'deleted' => (bool) $result,
        ];
    }

    /**
     * Execute list comments ability
     */
    public static function execute_list_comments($input)
    {
        $args = [
            'number' => $input['per_page'] ?? 20,
            'status' => $input['status'] ?? 'all',
        ];

        if (! empty($input['post_id'])) {
            $args['post_id'] = $input['post_id'];
        }

        $comments = get_comments($args);
        $result   = [];

        foreach ($comments as $comment) {
            $result[] = [
                'id'      => $comment->comment_ID,
                'post_id' => $comment->comment_post_ID,
                'author'  => $comment->comment_author,
                'content' => $comment->comment_content,
                'date'    => $comment->comment_date,
                'status'  => wp_get_comment_status($comment),
            ];
        }

        return ['comments' => $result];
    }

    /**
     * Execute moderate comment ability
     */
    public static function execute_moderate_comment($input)
    {
        $id     = $input['id'];
        $action = $input['action'];

        switch ($action) {
            case 'approve':
                $result = wp_set_comment_status($id, 'approve');
                break;
            case 'unapprove':
                $result = wp_set_comment_status($id, 'hold');
                break;
            case 'spam':
                $result = wp_spam_comment($id);
                break;
            case 'trash':
                $result = wp_trash_comment($id);
                break;
            case 'delete':
                $result = wp_delete_comment($id, true);
                break;
            default:
                return new WP_Error('invalid_action', 'Invalid moderation action');
        }

        return [
            'id'      => $id,
            'action'  => $action,
            'success' => (bool) $result,
        ];
    }

    /**
     * Execute list plugins ability
     */
    public static function execute_list_plugins($input)
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $status_filter  = $input['status'] ?? 'all';

        $plugins = [];
        foreach ($all_plugins as $file => $data) {
            $is_active = in_array($file, $active_plugins, true);

            if ($status_filter === 'active' && ! $is_active) {
                continue;
            }
            if ($status_filter === 'inactive' && $is_active) {
                continue;
            }

            $plugins[] = [
                'file'      => $file,
                'name'      => $data['Name'],
                'version'   => $data['Version'],
                'is_active' => $is_active,
            ];
        }

        return ['plugins' => $plugins];
    }

    /**
     * Execute activate plugin ability
     */
    public static function execute_activate_plugin($input)
    {
        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($input['plugin']);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'plugin'  => $input['plugin'],
            'success' => true,
        ];
    }

    /**
     * Execute deactivate plugin ability
     */
    public static function execute_deactivate_plugin($input)
    {
        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($input['plugin']);

        return [
            'plugin'  => $input['plugin'],
            'success' => true,
        ];
    }

    /**
     * Execute update plugin ability
     */
    public static function execute_update_plugin($input)
    {
        if (empty($input['plugin'])) {
            return new WP_Error('missing_plugin', __('Plugin file path is required.', 'abw-ai'));
        }

        if (! current_user_can('update_plugins')) {
            return new WP_Error('forbidden', __('You do not have permission to update plugins.', 'abw-ai'));
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $plugin_file = sanitize_text_field($input['plugin']);
        $all_plugins = get_plugins();

        if (! isset($all_plugins[$plugin_file])) {
            return new WP_Error('not_found', __('Plugin not found.', 'abw-ai'));
        }

        wp_update_plugins();
        $updates = get_plugin_updates();

        if (! isset($updates[$plugin_file])) {
            return [
                'plugin'       => $plugin_file,
                'name'         => $all_plugins[$plugin_file]['Name'] ?? $plugin_file,
                'success'      => true,
                'updated'      => false,
                'from_version' => $all_plugins[$plugin_file]['Version'] ?? '',
                'to_version'   => $all_plugins[$plugin_file]['Version'] ?? '',
                'message'      => __('Plugin is already up to date.', 'abw-ai'),
            ];
        }

        $plugin_update = $updates[$plugin_file];
        $from_version  = $plugin_update->Version ?? ($all_plugins[$plugin_file]['Version'] ?? '');
        $to_version    = $plugin_update->update->new_version ?? '';

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        if (false === $result) {
            $skin_errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
            if (is_wp_error($skin_errors) && $skin_errors->has_errors()) {
                return new WP_Error('plugin_update_failed', $skin_errors->get_error_message());
            }
            return new WP_Error('plugin_update_failed', __('Plugin update failed.', 'abw-ai'));
        }

        wp_clean_plugins_cache(true);

        return [
            'plugin'       => $plugin_file,
            'name'         => $plugin_update->Name ?? ($all_plugins[$plugin_file]['Name'] ?? $plugin_file),
            'success'      => true,
            'updated'      => true,
            'from_version' => $from_version,
            'to_version'   => $to_version,
            'message'      => __('Plugin updated successfully.', 'abw-ai'),
        ];
    }

    /**
     * Execute list themes ability
     */
    public static function execute_list_themes($input)
    {
        $all_themes    = wp_get_themes();
        $current_theme = wp_get_theme();

        $themes = [];
        foreach ($all_themes as $stylesheet => $theme) {
            $themes[] = [
                'stylesheet' => $stylesheet,
                'name'       => $theme->get('Name'),
                'version'    => $theme->get('Version'),
                'is_active'  => $stylesheet === $current_theme->get_stylesheet(),
            ];
        }

        return ['themes' => $themes];
    }

    /**
     * Execute activate theme ability
     */
    public static function execute_activate_theme($input)
    {
        $theme = wp_get_theme($input['stylesheet']);

        if (! $theme->exists()) {
            return new WP_Error('not_found', 'Theme not found');
        }

        switch_theme($input['stylesheet']);

        return [
            'theme'   => $input['stylesheet'],
            'success' => true,
        ];
    }

    /**
     * Execute update theme ability
     */
    public static function execute_update_theme($input)
    {
        if (empty($input['stylesheet'])) {
            return new WP_Error('missing_stylesheet', __('Theme stylesheet is required.', 'abw-ai'));
        }

        if (! current_user_can('update_themes')) {
            return new WP_Error('forbidden', __('You do not have permission to update themes.', 'abw-ai'));
        }

        if (! function_exists('get_theme_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if (! class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $stylesheet = sanitize_text_field($input['stylesheet']);
        $theme      = wp_get_theme($stylesheet);

        if (! $theme->exists()) {
            return new WP_Error('not_found', __('Theme not found.', 'abw-ai'));
        }

        wp_update_themes();
        $updates = get_theme_updates();

        if (! isset($updates[$stylesheet])) {
            return [
                'stylesheet'   => $stylesheet,
                'name'         => $theme->get('Name'),
                'success'      => true,
                'updated'      => false,
                'from_version' => $theme->get('Version'),
                'to_version'   => $theme->get('Version'),
                'message'      => __('Theme is already up to date.', 'abw-ai'),
            ];
        }

        $theme_update = $updates[$stylesheet];
        $from_version = $theme_update->get('Version') ?: $theme->get('Version');
        $to_version   = $theme_update->update['new_version'] ?? '';

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result   = $upgrader->upgrade($stylesheet);

        if (is_wp_error($result)) {
            return $result;
        }

        if (false === $result) {
            $skin_errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
            if (is_wp_error($skin_errors) && $skin_errors->has_errors()) {
                return new WP_Error('theme_update_failed', $skin_errors->get_error_message());
            }
            return new WP_Error('theme_update_failed', __('Theme update failed.', 'abw-ai'));
        }

        wp_clean_themes_cache(true);

        return [
            'stylesheet'   => $stylesheet,
            'name'         => $theme_update->get('Name') ?: $theme->get('Name'),
            'success'      => true,
            'updated'      => true,
            'from_version' => $from_version,
            'to_version'   => $to_version,
            'message'      => __('Theme updated successfully.', 'abw-ai'),
        ];
    }

    /**
     * Execute get site info ability
     */
    public static function execute_get_site_info($input)
    {
        return [
            'name'        => get_bloginfo('name'),
            'tagline'     => get_bloginfo('description'),
            'url'         => home_url(),
            'admin_url'   => admin_url(),
            'version'     => get_bloginfo('version'),
            'language'    => get_locale(),
            'timezone'    => wp_timezone_string(),
            'theme'       => wp_get_theme()->get('Name'),
        ];
    }

    /**
     * Execute update site identity ability
     */
    public static function execute_update_site_identity($input)
    {
        if (isset($input['title'])) {
            update_option('blogname', sanitize_text_field($input['title']));
        }
        if (isset($input['tagline'])) {
            update_option('blogdescription', sanitize_text_field($input['tagline']));
        }

        return [
            'success' => true,
            'title'   => get_bloginfo('name'),
            'tagline' => get_bloginfo('description'),
        ];
    }

    /**
     * Execute list menus ability
     */
    public static function execute_list_menus($input)
    {
        $menus  = wp_get_nav_menus();
        $result = [];

        foreach ($menus as $menu) {
            $result[] = [
                'id'    => $menu->term_id,
                'name'  => $menu->name,
                'slug'  => $menu->slug,
                'count' => $menu->count,
            ];
        }

        return ['menus' => $result];
    }

    /**
     * Execute get menu ability
     */
    public static function execute_get_menu($input)
    {
        $menu = wp_get_nav_menu_object($input['id']);

        if (! $menu) {
            return new WP_Error('not_found', 'Menu not found');
        }

        $items      = wp_get_nav_menu_items($input['id']);
        $menu_items = [];

        foreach ($items as $item) {
            $menu_items[] = [
                'id'     => $item->ID,
                'title'  => $item->title,
                'url'    => $item->url,
                'parent' => $item->menu_item_parent,
                'order'  => $item->menu_order,
            ];
        }

        return [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'items' => $menu_items,
        ];
    }

    /**
     * Execute list media ability
     */
    public static function execute_list_media($input)
    {
        $args = [
            'post_type'      => 'attachment',
            'posts_per_page' => $input['per_page'] ?? 20,
            'post_mime_type' => $input['mime_type'] ?? '',
        ];

        $query = new WP_Query($args);
        $media = [];

        foreach ($query->posts as $attachment) {
            $media[] = [
                'id'        => $attachment->ID,
                'title'     => $attachment->post_title,
                'url'       => wp_get_attachment_url($attachment->ID),
                'mime_type' => $attachment->post_mime_type,
            ];
        }

        return [
            'media' => $media,
            'total' => $query->found_posts,
        ];
    }

    /**
     * Execute upload media ability
     */
    public static function execute_upload_media($input)
    {
        $url = $input['url'] ?? '';
        if (empty($url)) {
            return new WP_Error('missing_url', __('Media URL is required.', 'abw-ai'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $file_array = [
            'name'     => basename($url),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return $id;
        }

        // Update metadata
        if (! empty($input['title'])) {
            wp_update_post([
                'ID'         => $id,
                'post_title' => sanitize_text_field($input['title']),
            ]);
        }

        if (! empty($input['alt_text'])) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
        }

        if (! empty($input['caption'])) {
            wp_update_post([
                'ID'       => $id,
                'post_excerpt' => sanitize_textarea_field($input['caption']),
            ]);
        }

        return [
            'id'      => $id,
            'url'     => wp_get_attachment_url($id),
            'success' => true,
        ];
    }

    /**
     * Execute update media ability
     */
    public static function execute_update_media($input)
    {
        $attachment = get_post($input['id']);
        if (! $attachment || 'attachment' !== $attachment->post_type) {
            return new WP_Error('not_found', __('Media not found.', 'abw-ai'));
        }

        $update_data = ['ID' => $input['id']];

        if (isset($input['title'])) {
            $update_data['post_title'] = sanitize_text_field($input['title']);
        }

        if (isset($input['caption'])) {
            $update_data['post_excerpt'] = sanitize_textarea_field($input['caption']);
        }

        wp_update_post($update_data);

        if (isset($input['alt_text'])) {
            update_post_meta($input['id'], '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
        }

        return [
            'id'      => $input['id'],
            'success' => true,
        ];
    }

    /**
     * Execute delete media ability
     */
    public static function execute_delete_media($input)
    {
        $force = $input['force'] ?? false;
        $result = wp_delete_attachment($input['id'], $force);

        return [
            'success' => (bool) $result,
            'deleted' => (bool) $result,
        ];
    }

    /**
     * Execute list users ability
     */
    public static function execute_list_users($input)
    {
        $args = [
            'number' => $input['per_page'] ?? 20,
            'paged'  => $input['page'] ?? 1,
        ];

        if (! empty($input['role'])) {
            $args['role'] = $input['role'];
        }

        if (! empty($input['search'])) {
            $args['search'] = '*' . $input['search'] . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $user_query = new WP_User_Query($args);
        $users = [];

        foreach ($user_query->get_results() as $user) {
            $users[] = [
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ];
        }

        return [
            'users' => $users,
            'total' => $user_query->get_total(),
        ];
    }

    /**
     * Execute get user ability
     */
    public static function execute_get_user($input)
    {
        $user = get_user_by('ID', $input['id']);
        if (! $user) {
            return new WP_Error('not_found', __('User not found.', 'abw-ai'));
        }

        return [
            'id'           => $user->ID,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
        ];
    }

    /**
     * Execute create user ability
     */
    public static function execute_create_user($input)
    {
        $user_data = [
            'user_login' => sanitize_user($input['username']),
            'user_email' => sanitize_email($input['email']),
            'role'       => $input['role'] ?? 'subscriber',
        ];

        if (! empty($input['password'])) {
            $user_data['user_pass'] = $input['password'];
        } else {
            $user_data['user_pass'] = wp_generate_password();
        }

        if (! empty($input['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($input['display_name']);
        }

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return [
            'id'      => $user_id,
            'success' => true,
        ];
    }

    /**
     * Execute update user ability
     */
    public static function execute_update_user($input)
    {
        $user = get_user_by('ID', $input['id']);
        if (! $user) {
            return new WP_Error('not_found', __('User not found.', 'abw-ai'));
        }

        $user_data = ['ID' => $input['id']];

        if (isset($input['email'])) {
            $user_data['user_email'] = sanitize_email($input['email']);
        }

        if (isset($input['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($input['display_name']);
        }

        $result = wp_update_user($user_data);

        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($input['role'])) {
            $user->set_role($input['role']);
        }

        return [
            'id'      => $input['id'],
            'success' => true,
        ];
    }

    /**
     * Execute delete user ability
     */
    public static function execute_delete_user($input)
    {
        $reassign = $input['reassign'] ?? null;
        $result = wp_delete_user($input['id'], $reassign);

        return [
            'success' => (bool) $result,
            'deleted' => (bool) $result,
        ];
    }

    /**
     * Execute list taxonomies ability
     */
    public static function execute_list_taxonomies($input)
    {
        $taxonomies = get_taxonomies([], 'objects');
        $result = [];

        foreach ($taxonomies as $taxonomy) {
            $result[] = [
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->label,
                'object_type'  => $taxonomy->object_type,
                'public'        => $taxonomy->public,
                'hierarchical' => $taxonomy->hierarchical,
            ];
        }

        return ['taxonomies' => $result];
    }

    /**
     * Execute list terms ability
     */
    public static function execute_list_terms($input)
    {
        $args = [
            'taxonomy'   => $input['taxonomy'],
            'hide_empty' => $input['hide_empty'] ?? false,
            'number'     => $input['per_page'] ?? 50,
        ];

        $terms = get_terms($args);
        $result = [];

        if (! is_wp_error($terms)) {
            foreach ($terms as $term) {
                $result[] = [
                    'id'          => $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'parent'      => $term->parent,
                    'count'       => $term->count,
                ];
            }
        }

        return ['terms' => $result];
    }

    /**
     * Execute create term ability
     */
    public static function execute_create_term($input)
    {
        $term_data = [
            'description' => $input['description'] ?? '',
        ];

        if (! empty($input['slug'])) {
            $term_data['slug'] = sanitize_title($input['slug']);
        }

        if (! empty($input['parent'])) {
            $term_data['parent'] = absint($input['parent']);
        }

        $result = wp_insert_term($input['name'], $input['taxonomy'], $term_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'id'      => $result['term_id'],
            'success' => true,
        ];
    }

    /**
     * Execute update term ability
     */
    public static function execute_update_term($input)
    {
        $term_data = [];

        if (isset($input['name'])) {
            $term_data['name'] = sanitize_text_field($input['name']);
        }

        if (isset($input['slug'])) {
            $term_data['slug'] = sanitize_title($input['slug']);
        }

        if (isset($input['description'])) {
            $term_data['description'] = sanitize_textarea_field($input['description']);
        }

        if (isset($input['parent'])) {
            $term_data['parent'] = absint($input['parent']);
        }

        $result = wp_update_term($input['id'], $input['taxonomy'], $term_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'id'      => $input['id'],
            'success' => true,
        ];
    }

    /**
     * Execute delete term ability
     */
    public static function execute_delete_term($input)
    {
        $result = wp_delete_term($input['id'], $input['taxonomy']);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'deleted' => true,
        ];
    }

    /**
     * Execute create menu ability
     */
    public static function execute_create_menu($input)
    {
        $menu_id = wp_create_nav_menu(sanitize_text_field($input['name']));

        if (is_wp_error($menu_id)) {
            return $menu_id;
        }

        return [
            'id'      => $menu_id,
            'success' => true,
        ];
    }

    /**
     * Execute add menu item ability
     */
    public static function execute_add_menu_item($input)
    {
        $menu_item_data = [
            'menu-item-title'  => sanitize_text_field($input['title']),
            'menu-item-status' => 'publish',
        ];

        if (! empty($input['url'])) {
            $menu_item_data['menu-item-type'] = 'custom';
            $menu_item_data['menu-item-url'] = esc_url_raw($input['url']);
        } elseif (! empty($input['object_id']) && ! empty($input['object'])) {
            $menu_item_data['menu-item-type'] = 'post_type';
            $menu_item_data['menu-item-object'] = $input['object'];
            $menu_item_data['menu-item-object-id'] = absint($input['object_id']);
        } else {
            return new WP_Error('invalid_input', __('Either URL or object_id+object must be provided.', 'abw-ai'));
        }

        if (! empty($input['parent'])) {
            $menu_item_data['menu-item-parent-id'] = absint($input['parent']);
        }

        $item_id = wp_update_nav_menu_item($input['menu_id'], 0, $menu_item_data);

        if (is_wp_error($item_id)) {
            return $item_id;
        }

        return [
            'id'      => $item_id,
            'success' => true,
        ];
    }

    /**
     * Execute update menu item ability
     */
    public static function execute_update_menu_item($input)
    {
        $menu_item_data = [];

        if (isset($input['title'])) {
            $menu_item_data['menu-item-title'] = sanitize_text_field($input['title']);
        }

        if (isset($input['url'])) {
            $menu_item_data['menu-item-url'] = esc_url_raw($input['url']);
        }

        if (isset($input['parent'])) {
            $menu_item_data['menu-item-parent-id'] = absint($input['parent']);
        }

        $item = wp_setup_nav_menu_item(get_post($input['id']));
        if (! $item) {
            return new WP_Error('not_found', __('Menu item not found.', 'abw-ai'));
        }

        $menu_id = wp_get_post_terms($input['id'], 'nav_menu', ['fields' => 'ids']);
        if (empty($menu_id)) {
            return new WP_Error('no_menu', __('Menu item not associated with a menu.', 'abw-ai'));
        }

        $menu_item_data['menu-item-db-id'] = $input['id'];
        $result = wp_update_nav_menu_item($menu_id[0], $input['id'], $menu_item_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'id'      => $input['id'],
            'success' => true,
        ];
    }

    /**
     * Execute delete menu item ability
     */
    public static function execute_delete_menu_item($input)
    {
        $result = wp_delete_post($input['id'], true);

        return [
            'success' => (bool) $result,
            'deleted' => (bool) $result,
        ];
    }

    /**
     * Execute assign menu location ability
     */
    public static function execute_assign_menu_location($input)
    {
        $locations = get_theme_mod('nav_menu_locations', []);
        $locations[$input['location']] = absint($input['menu_id']);
        set_theme_mod('nav_menu_locations', $locations);

        return [
            'success' => true,
        ];
    }

    /**
     * Execute get option ability
     */
    public static function execute_get_option($input)
    {
        $whitelist = self::get_safe_option_whitelist();
        $option    = sanitize_key($input['option'] ?? '');

        if (! in_array($option, $whitelist, true)) {
            return new WP_Error('not_allowed', __('This option is not allowed to be read via API.', 'abw-ai'));
        }

        $value = get_option($option);

        return [
            'option' => $option,
            'value'  => $value,
        ];
    }

    /**
     * Execute update option ability
     */
    public static function execute_update_option($input)
    {
        $whitelist = self::get_safe_option_whitelist();
        $option    = sanitize_key($input['option'] ?? '');

        if (! in_array($option, $whitelist, true)) {
            return new WP_Error('not_allowed', __('This option is not allowed to be updated via API.', 'abw-ai'));
        }

        $result = update_option($option, $input['value']);

        return [
            'success' => $result,
        ];
    }

    /**
     * Get the list of safe options that may be read or updated through tools.
     *
     * @return array<int, string>
     */
    private static function get_safe_option_whitelist()
    {
        return [
            'blogname',
            'blogdescription',
            'admin_email',
            'users_can_register',
            'default_role',
            'timezone_string',
            'date_format',
            'time_format',
            'start_of_week',
            'posts_per_page',
            'default_ping_status',
            'default_comment_status',
            'abw_ai_provider',
            'abw_chat_enabled',
            'abw_custom_endpoint',
            'abw_custom_model',
            'abw_rate_limit',
            'abw_sidebar_default_state',
            'abw_sidebar_default_width',
            'abw_debug_log',
        ];
    }

    /**
     * Execute get reading settings ability
     */
    public static function execute_get_reading_settings($input)
    {
        return [
            'posts_per_page'  => get_option('posts_per_page'),
            'show_on_front'   => get_option('show_on_front'),
            'page_on_front'   => get_option('page_on_front'),
            'page_for_posts'  => get_option('page_for_posts'),
        ];
    }

    /**
     * Execute update reading settings ability
     */
    public static function execute_update_reading_settings($input)
    {
        if (isset($input['posts_per_page'])) {
            update_option('posts_per_page', absint($input['posts_per_page']));
        }

        if (isset($input['show_on_front'])) {
            update_option('show_on_front', $input['show_on_front']);
        }

        if (isset($input['page_on_front'])) {
            update_option('page_on_front', absint($input['page_on_front']));
        }

        if (isset($input['page_for_posts'])) {
            update_option('page_for_posts', absint($input['page_for_posts']));
        }

        return [
            'success' => true,
        ];
    }

    /**
     * Execute search site ability
     */
    public static function execute_search_site($input)
    {
        $args = [
            's'              => $input['query'],
            'posts_per_page' => $input['per_page'] ?? 10,
        ];

        if (! empty($input['post_type'])) {
            $args['post_type'] = $input['post_type'];
        }

        $query = new WP_Query($args);
        $results = [];

        foreach ($query->posts as $post) {
            $results[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'type'    => $post->post_type,
                'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 30),
            ];
        }

        return [
            'results' => $results,
            'total'   => $query->found_posts,
        ];
    }

    /**
     * Execute get post stats ability
     */
    public static function execute_get_post_stats($input)
    {
        $stats = [];
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);
            $stats[$post_type] = [
                'publish' => $counts->publish ?? 0,
                'draft'   => $counts->draft ?? 0,
                'pending' => $counts->pending ?? 0,
                'total'   => array_sum((array) $counts),
            ];
        }

        return ['stats' => $stats];
    }

    /**
     * Execute get popular content ability
     */
    public static function execute_get_popular_content($input)
    {
        $type = $input['type'] ?? 'comments';
        $per_page = $input['per_page'] ?? 10;

        if ('comments' === $type) {
            $args = [
                'post_type'      => 'post',
                'posts_per_page' => $per_page,
                'orderby'        => 'comment_count',
                'order'          => 'DESC',
            ];
        } else {
            // For views, we'd need a plugin like WP-PostViews
            // For now, fallback to date
            $args = [
                'post_type'      => 'post',
                'posts_per_page' => $per_page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];
        }

        $query = new WP_Query($args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'comment_count' => $post->comment_count,
            ];
        }

        return [
            'posts' => $posts,
            'total' => $query->found_posts,
        ];
    }

    /**
     * Execute get recent activity ability
     */
    public static function execute_get_recent_activity($input)
    {
        $per_page = $input['per_page'] ?? 10;
        $activity = [];

        // Recent posts
        $recent_posts = get_posts([
            'posts_per_page' => $per_page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        foreach ($recent_posts as $post) {
            $activity[] = [
                'type'      => 'post',
                'id'        => $post->ID,
                'title'     => $post->post_title,
                'timestamp' => $post->post_modified,
            ];
        }

        // Recent comments
        $recent_comments = get_comments([
            'number' => 5,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ]);

        foreach ($recent_comments as $comment) {
            $activity[] = [
                'type'      => 'comment',
                'id'        => $comment->comment_ID,
                'post_id'   => $comment->comment_post_ID,
                'author'    => $comment->comment_author,
                'timestamp' => $comment->comment_date,
            ];
        }

        usort($activity, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return [
            'activity' => array_slice($activity, 0, $per_page),
        ];
    }

    /**
     * Execute bulk update posts ability
     */
    public static function execute_bulk_update_posts($input)
    {
        $post_ids = $input['post_ids'] ?? [];
        $updated = 0;
        $errors = [];

        foreach ($post_ids as $post_id) {
            $post_data = ['ID' => $post_id];

            if (isset($input['status'])) {
                $post_data['post_status'] = $input['status'];
            }

            if (isset($input['author'])) {
                $post_data['post_author'] = absint($input['author']);
            }

            $result = wp_update_post($post_data, true);

            if (is_wp_error($result)) {
                $errors[] = ['post_id' => $post_id, 'error' => $result->get_error_message()];
            } else {
                $updated++;

                if (! empty($input['categories'])) {
                    wp_set_post_categories($post_id, $input['categories']);
                }
            }
        }

        return [
            'updated' => $updated,
            'total'   => count($post_ids),
            'errors'  => $errors,
        ];
    }

    /**
     * Execute bulk delete posts ability
     */
    public static function execute_bulk_delete_posts($input)
    {
        $post_ids = $input['post_ids'] ?? [];
        $force = $input['force'] ?? false;
        $deleted = 0;

        foreach ($post_ids as $post_id) {
            $result = wp_delete_post($post_id, $force);
            if ($result) {
                $deleted++;
            }
        }

        return [
            'deleted' => $deleted,
            'total'   => count($post_ids),
        ];
    }

    /**
     * Execute find replace content ability
     */
    public static function execute_find_replace_content($input)
    {
        $find = $input['find'];
        $replace = $input['replace'];
        $dry_run = $input['dry_run'] ?? false;

        $args = [
            'post_type'      => $input['post_type'] ?? 'any',
            'posts_per_page' => -1,
            'post_status'   => 'any',
        ];

        $query = new WP_Query($args);
        $matches = [];
        $updated = 0;

        foreach ($query->posts as $post) {
            if (strpos($post->post_content, $find) !== false) {
                $matches[] = [
                    'id'    => $post->ID,
                    'title' => $post->post_title,
                ];

                if (! $dry_run) {
                    $new_content = str_replace($find, $replace, $post->post_content);
                    wp_update_post([
                        'ID'           => $post->ID,
                        'post_content' => $new_content,
                    ]);
                    $updated++;
                }
            }
        }

        return [
            'matches' => $matches,
            'updated' => $updated,
            'dry_run' => $dry_run,
        ];
    }

    /**
     * Execute bulk moderate comments ability
     */
    public static function execute_bulk_moderate_comments($input)
    {
        $comment_ids = $input['comment_ids'] ?? [];
        $action = $input['action'];
        $processed = 0;
        $errors = [];

        foreach ($comment_ids as $comment_id) {
            switch ($action) {
                case 'approve':
                    $result = wp_set_comment_status($comment_id, 'approve');
                    break;
                case 'unapprove':
                    $result = wp_set_comment_status($comment_id, 'hold');
                    break;
                case 'spam':
                    $result = wp_spam_comment($comment_id);
                    break;
                case 'trash':
                    $result = wp_trash_comment($comment_id);
                    break;
                case 'delete':
                    $result = wp_delete_comment($comment_id, true);
                    break;
                default:
                    $errors[] = ['comment_id' => $comment_id, 'error' => 'Invalid action'];
                    continue 2;
            }

            if (is_wp_error($result)) {
                $errors[] = ['comment_id' => $comment_id, 'error' => $result->get_error_message()];
            } else {
                $processed++;
            }
        }

        return [
            'processed' => $processed,
            'total'     => count($comment_ids),
            'errors'    => $errors,
        ];
    }

    /**
     * Execute get site health ability
     */
    public static function execute_get_site_health($input)
    {
        if (! class_exists('WP_Site_Health')) {
            return new WP_Error('not_available', __('Site Health is not available.', 'abw-ai'));
        }

        $site_health = WP_Site_Health::get_instance();
        $tests = $site_health->get_tests();

        $results = [];
        foreach ($tests['direct'] as $test) {
            $result = call_user_func($test['test']);
            $results[] = [
                'label' => $result['label'],
                'status' => $result['status'],
                'badge' => $result['badge'] ?? [],
            ];
        }

        return [
            'results' => $results,
        ];
    }

    /**
     * Execute check plugin updates ability
     */
    public static function execute_check_plugin_updates($input)
    {
        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updates = get_plugin_updates();
        $plugins = [];

        foreach ($updates as $file => $plugin) {
            $plugins[] = [
                'file'    => $file,
                'name'    => $plugin->Name,
                'version' => $plugin->Version,
                'new_version' => $plugin->update->new_version ?? '',
            ];
        }

        return ['plugins' => $plugins];
    }

    /**
     * Execute check theme updates ability
     */
    public static function execute_check_theme_updates($input)
    {
        if (! function_exists('get_theme_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updates = get_theme_updates();
        $themes = [];

        foreach ($updates as $stylesheet => $theme) {
            $themes[] = [
                'stylesheet' => $stylesheet,
                'name'      => $theme->get('Name'),
                'version'   => $theme->get('Version'),
                'new_version' => $theme->update['new_version'] ?? '',
            ];
        }

        return ['themes' => $themes];
    }

    /**
     * Execute list block patterns ability
     */
    public static function execute_list_block_patterns($input)
    {
        if (! class_exists('WP_Block_Patterns_Registry')) {
            return ['patterns' => []];
        }

        $registry = WP_Block_Patterns_Registry::get_instance();
        $patterns = $registry->get_all_registered();
        $result = [];

        foreach ($patterns as $pattern) {
            $result[] = [
                'name'        => $pattern['name'] ?? '',
                'title'       => $pattern['title'] ?? '',
                'description' => $pattern['description'] ?? '',
                'categories' => $pattern['categories'] ?? [],
            ];
        }

        return ['patterns' => $result];
    }

    /**
     * Execute list template parts ability
     */
    public static function execute_list_template_parts($input)
    {
        $template_parts = get_block_templates(['post_type' => 'wp_template_part']);
        $result = [];

        foreach ($template_parts as $part) {
            $result[] = [
                'id'          => $part->id,
                'title'       => $part->title,
                'slug'        => $part->slug,
                'theme'       => $part->theme,
                'area'        => $part->area ?? '',
            ];
        }

        return ['template_parts' => $result];
    }

    /**
     * Execute list products ability (WooCommerce)
     */
    public static function execute_list_products($input)
    {
        if (! function_exists('wc_get_products')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $args = [
            'limit'  => $input['per_page'] ?? 20,
            'status' => $input['status'] ?? 'publish',
        ];

        $products = wc_get_products($args);
        $result = [];

        foreach ($products as $product) {
            $result[] = [
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $product->get_price(),
                'stock_status' => $product->get_stock_status(),
            ];
        }

        return ['products' => $result];
    }

    /**
     * Execute get product ability (WooCommerce)
     */
    public static function execute_get_product($input)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $product = wc_get_product($input['id']);
        if (! $product) {
            return new WP_Error('not_found', __('Product not found.', 'abw-ai'));
        }

        return [
            'id'           => $product->get_id(),
            'name'         => $product->get_name(),
            'price'        => $product->get_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'sku'          => $product->get_sku(),
        ];
    }

    /**
     * Execute update product ability (WooCommerce)
     */
    public static function execute_update_product($input)
    {
        if (! function_exists('wc_get_product')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $product = wc_get_product($input['id']);
        if (! $product) {
            return new WP_Error('not_found', __('Product not found.', 'abw-ai'));
        }

        if (isset($input['price'])) {
            $product->set_price($input['price']);
        }

        if (isset($input['stock_status'])) {
            $product->set_stock_status($input['stock_status']);
        }

        if (isset($input['stock_quantity'])) {
            $product->set_stock_quantity($input['stock_quantity']);
        }

        $product->save();

        return [
            'id'      => $input['id'],
            'success' => true,
        ];
    }

    /**
     * Execute list orders ability (WooCommerce)
     */
    public static function execute_list_orders($input)
    {
        if (! function_exists('wc_get_orders')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $args = [
            'limit'  => $input['per_page'] ?? 20,
        ];

        if (! empty($input['status'])) {
            $args['status'] = $input['status'];
        }

        $orders = wc_get_orders($args);
        $result = [];

        foreach ($orders as $order) {
            $result[] = [
                'id'     => $order->get_id(),
                'status' => $order->get_status(),
                'total'  => $order->get_total(),
                'date'   => $order->get_date_created()->date('Y-m-d H:i:s'),
            ];
        }

        return ['orders' => $result];
    }

    /**
     * Execute get order ability (WooCommerce)
     */
    public static function execute_get_order($input)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $order = wc_get_order($input['id']);
        if (! $order) {
            return new WP_Error('not_found', __('Order not found.', 'abw-ai'));
        }

        return [
            'id'     => $order->get_id(),
            'status' => $order->get_status(),
            'total'  => $order->get_total(),
            'date'   => $order->get_date_created()->date('Y-m-d H:i:s'),
            'items'  => count($order->get_items()),
        ];
    }

    /**
     * Execute update order status ability (WooCommerce)
     */
    public static function execute_update_order_status($input)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce is not active.', 'abw-ai'));
        }

        $order = wc_get_order($input['id']);
        if (! $order) {
            return new WP_Error('not_found', __('Order not found.', 'abw-ai'));
        }

        $order->update_status($input['status']);

        return [
            'id'      => $input['id'],
            'status'  => $input['status'],
            'success' => true,
        ];
    }

    /**
     * Execute get database stats ability.
     *
     * Returns table sizes, row counts, and total database size.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error Database statistics or error.
     */
    public static function execute_get_database_stats(array $input)
    {
        global $wpdb;

        $database_name = preg_replace('/[^A-Za-z0-9_]/', '', DB_NAME);

        if (empty($database_name)) {
            return new WP_Error('db_error', __('Failed to determine the database name.', 'abw-ai'));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $tables = $wpdb->get_results("SHOW TABLE STATUS FROM `{$database_name}`", ARRAY_A);

        if ($tables === null || $tables === false) {
            return new WP_Error('db_error', __('Failed to retrieve database table status.', 'abw-ai'));
        }

        $total_size  = 0;
        $table_data  = [];

        foreach ($tables as $table) {
            $size       = ($table['Data_length'] + $table['Index_length']);
            $total_size += $size;

            $table_data[] = [
                'name'       => $table['Name'],
                'rows'       => (int) $table['Rows'],
                'size'       => size_format($size, 2),
                'size_bytes' => $size,
                'engine'     => $table['Engine'],
            ];
        }

        usort($table_data, fn($a, $b) => $b['size_bytes'] - $a['size_bytes']);

        return [
            'total_size'   => size_format($total_size, 2),
            'total_tables' => count($tables),
            'tables'       => array_slice($table_data, 0, 30),
        ];
    }

    /**
     * Execute cleanup database ability.
     *
     * Removes revisions, auto-drafts, trashed posts/comments, spam comments,
     * expired transients, and orphaned postmeta.
     *
     * @param array $input {
     *     Optional input parameters.
     *
     *     @type array $types Cleanup types to run. Defaults to all.
     * }
     * @return array|WP_Error Counts of deleted items per type or error.
     */
    public static function execute_cleanup_database(array $input)
    {
        global $wpdb;

        $all_types = [
            'revisions',
            'auto_drafts',
            'trashed_posts',
            'spam_comments',
            'trashed_comments',
            'expired_transients',
        ];

        $types   = $input['types'] ?? $all_types;
        $types   = array_intersect($types, $all_types);
        $deleted = [];

        foreach ($types as $type) {
            switch ($type) {
                case 'revisions':
                    $deleted['revisions'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"
                    );
                    break;

                case 'auto_drafts':
                    $deleted['auto_drafts'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
                    );
                    break;

                case 'trashed_posts':
                    $deleted['trashed_posts'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'"
                    );
                    break;

                case 'spam_comments':
                    $deleted['spam_comments'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
                    );
                    break;

                case 'trashed_comments':
                    $deleted['trashed_comments'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
                    );
                    break;

                case 'expired_transients':
                    $deleted['expired_transients'] = (int) $wpdb->query(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
                    );
                    break;
            }
        }

        $deleted['orphaned_postmeta'] = (int) $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
        );

        return [
            'success' => true,
            'deleted' => $deleted,
        ];
    }

    /**
     * Execute optimize database tables ability.
     *
     * Runs OPTIMIZE TABLE on all WordPress-prefixed tables.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error List of optimized tables and their status or error.
     */
    public static function execute_optimize_database_tables(array $input)
    {
        global $wpdb;

        $tables = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
                DB_NAME,
                $wpdb->esc_like($wpdb->prefix) . '%'
            )
        );

        if (empty($tables)) {
            return new WP_Error('no_tables', __('No WordPress tables found to optimize.', 'abw-ai'));
        }

        $results = [];

        foreach ($tables as $table) {
            $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
            if ('' === $safe_table) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $status     = $wpdb->get_results("OPTIMIZE TABLE `{$safe_table}`", ARRAY_A);

            $results[] = [
                'table'   => $table,
                'status'  => $status[0]['Msg_text'] ?? 'unknown',
            ];
        }

        return [
            'success'         => true,
            'tables_optimized' => count($results),
            'results'         => $results,
        ];
    }

    /**
     * Execute list cron jobs ability.
     *
     * Returns all scheduled WordPress cron events with their hooks,
     * next run time, schedule, and arguments.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error List of cron events or error.
     */
    public static function execute_list_cron_jobs(array $input)
    {
        $crons = _get_cron_array();

        if (empty($crons)) {
            return ['cron_jobs' => [], 'total' => 0];
        }

        $events = [];

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $schedules) {
                foreach ($schedules as $key => $event) {
                    $events[] = [
                        'hook'      => $hook,
                        'next_run'  => wp_date('Y-m-d H:i:s', $timestamp),
                        'timestamp' => (int) $timestamp,
                        'schedule'  => $event['schedule'] ?: 'single',
                        'interval'  => $event['interval'] ?? null,
                        'args'      => $event['args'],
                    ];
                }
            }
        }

        usort($events, fn($a, $b) => $a['timestamp'] - $b['timestamp']);

        return [
            'cron_jobs' => $events,
            'total'     => count($events),
        ];
    }

    /**
     * Execute delete cron job ability.
     *
     * Removes a scheduled cron event by hook name and optional timestamp.
     *
     * @param array $input {
     *     Input parameters.
     *
     *     @type string $hook      Required. The cron hook name.
     *     @type int    $timestamp Optional. Specific event timestamp to remove.
     * }
     * @return array|WP_Error Success status or error.
     */
    public static function execute_delete_cron_job(array $input)
    {
        if (empty($input['hook'])) {
            return new WP_Error('missing_hook', __('The hook parameter is required.', 'abw-ai'));
        }

        $hook = sanitize_text_field($input['hook']);

        if (! empty($input['timestamp'])) {
            $timestamp = (int) $input['timestamp'];
            $result    = wp_unschedule_event($timestamp, $hook);

            if ($result === false) {
                return new WP_Error('unschedule_failed', __('Failed to unschedule the cron event.', 'abw-ai'));
            }

            return [
                'success'   => true,
                'hook'      => $hook,
                'timestamp' => $timestamp,
                'message'   => __('Single cron event unscheduled.', 'abw-ai'),
            ];
        }

        $count = wp_clear_scheduled_hook($hook);

        if ($count === false) {
            return new WP_Error('clear_failed', __('Failed to clear scheduled hook.', 'abw-ai'));
        }

        return [
            'success'       => true,
            'hook'          => $hook,
            'events_removed' => $count,
            'message'       => sprintf(
                /* translators: 1: number of scheduled events, 2: cron hook name */
                __('Cleared %1$d scheduled event(s) for hook "%2$s".', 'abw-ai'),
                $count,
                $hook
            ),
        ];
    }

    /**
     * Execute list transients ability.
     *
     * Returns counts of total, expired, and active transients.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error Transient counts or error.
     */
    public static function execute_list_transients(array $input)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $expired = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} o1 "
            . "JOIN {$wpdb->options} o2 ON o2.option_name = CONCAT('_transient_timeout_', SUBSTRING(o1.option_name, 12)) "
            . "WHERE o1.option_name LIKE '_transient_%' "
            . "AND o1.option_name NOT LIKE '_transient_timeout_%' "
            . "AND o2.option_value < UNIX_TIMESTAMP()"
        );

        return [
            'total'   => $total,
            'expired' => $expired,
            'active'  => $total - $expired,
        ];
    }

    /**
     * Execute flush transients ability.
     *
     * Deletes all expired transients and their timeout counterparts.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error Count of deleted transients or error.
     */
    public static function execute_flush_transients(array $input)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $expired_timeouts = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
        );

        $deleted = 0;

        foreach ($expired_timeouts as $timeout_name) {
            $transient_name = str_replace('_transient_timeout_', '_transient_', $timeout_name);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $transient_name
            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_name
            ));

            $deleted++;
        }

        return [
            'success'          => true,
            'transients_flushed' => $deleted,
        ];
    }

    /**
     * Execute get autoload report ability.
     *
     * Returns the largest autoloaded options and total autoload size.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error Autoload report or error.
     */
    public static function execute_get_autoload_report(array $input)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY LENGTH(option_value) DESC LIMIT 30",
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        return [
            'total_autoload_size'  => size_format($total, 2),
            'total_autoload_bytes' => $total,
            'largest_options'      => array_map(
                fn($r) => [
                    'name'       => $r['option_name'],
                    'size'       => size_format((int) $r['size'], 2),
                    'size_bytes' => (int) $r['size'],
                ],
                $results
            ),
        ];
    }

    /**
     * Execute get performance report ability.
     *
     * Returns a comprehensive WordPress performance summary including
     * active plugins, autoloaded data size, cron job count, and PHP settings.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error Performance report or error.
     */
    public static function execute_get_performance_report(array $input)
    {
        global $wpdb;

        $active_plugins = get_option('active_plugins', []);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $autoload_size = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        $crons      = _get_cron_array();
        $cron_count = 0;
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                foreach ($hooks as $hook => $schedules) {
                    $cron_count += count($schedules);
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_transients = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $revision_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");

        return [
            'php_version'          => PHP_VERSION,
            'memory_limit'         => ini_get('memory_limit'),
            'max_execution_time'   => (int) ini_get('max_execution_time'),
            'upload_max_filesize'  => ini_get('upload_max_filesize'),
            'post_max_size'        => ini_get('post_max_size'),
            'wordpress_version'    => get_bloginfo('version'),
            'active_plugins'       => count($active_plugins),
            'active_plugin_list'   => $active_plugins,
            'autoload_size'        => size_format($autoload_size, 2),
            'autoload_size_bytes'  => $autoload_size,
            'cron_jobs'            => $cron_count,
            'transients'           => $total_transients,
            'published_posts'      => $post_count,
            'revisions'            => $revision_count,
            'is_multisite'         => is_multisite(),
            'wp_debug'             => defined('WP_DEBUG') && WP_DEBUG,
            'wp_cache'             => defined('WP_CACHE') && WP_CACHE,
        ];
    }

    /**
     * Bulk update multiple WooCommerce products.
     *
     * @param array $input {
     *     @type array $updates Array of objects with 'id' and fields to update.
     * }
     * @return array|WP_Error
     */
    public static function execute_bulk_update_products(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        if (empty($input['updates']) || !is_array($input['updates'])) {
            return new WP_Error('missing_updates', __('The updates parameter is required and must be an array.', 'abw-ai'));
        }

        $updated = 0;
        $failed  = 0;
        $results = [];

        foreach ($input['updates'] as $update) {
            if (empty($update['id'])) {
                $failed++;
                $results[] = ['id' => 0, 'success' => false, 'error' => 'Missing product ID'];
                continue;
            }

            $product = wc_get_product((int) $update['id']);
            if (!$product) {
                $failed++;
                $results[] = ['id' => (int) $update['id'], 'success' => false, 'error' => 'Product not found'];
                continue;
            }

            if (isset($update['regular_price'])) {
                $product->set_regular_price($update['regular_price']);
            }
            if (isset($update['sale_price'])) {
                $product->set_sale_price($update['sale_price']);
            }
            if (isset($update['stock_quantity'])) {
                $product->set_stock_quantity((int) $update['stock_quantity']);
                $product->set_manage_stock(true);
            }
            if (isset($update['stock_status'])) {
                $product->set_stock_status(sanitize_text_field($update['stock_status']));
            }
            if (isset($update['status'])) {
                $product->set_status(sanitize_text_field($update['status']));
            }

            $product->save();
            $updated++;
            $results[] = ['id' => $product->get_id(), 'success' => true, 'name' => $product->get_name()];
        }

        return [
            'updated' => $updated,
            'failed'  => $failed,
            'results' => $results,
        ];
    }

    /**
     * Get a WooCommerce sales report.
     *
     * @param array $input {
     *     @type string $period    Report period: today, week, month, year.
     *     @type string $date_from Start date (Y-m-d).
     *     @type string $date_to   End date (Y-m-d).
     * }
     * @return array|WP_Error
     */
    public static function execute_get_sales_report(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        $period = sanitize_text_field($input['period'] ?? 'month');

        if (!empty($input['date_from']) && !empty($input['date_to'])) {
            $date_from = sanitize_text_field($input['date_from']);
            $date_to   = sanitize_text_field($input['date_to']);
        } else {
            $now = current_time('timestamp');
            switch ($period) {
                case 'today':
                    $date_from = gmdate('Y-m-d', $now);
                    $date_to   = gmdate('Y-m-d', $now);
                    break;
                case 'week':
                    $date_from = gmdate('Y-m-d', strtotime('-7 days', $now));
                    $date_to   = gmdate('Y-m-d', $now);
                    break;
                case 'year':
                    $date_from = gmdate('Y-01-01', $now);
                    $date_to   = gmdate('Y-m-d', $now);
                    break;
                case 'month':
                default:
                    $date_from = gmdate('Y-m-01', $now);
                    $date_to   = gmdate('Y-m-d', $now);
                    break;
            }
        }

        $orders = wc_get_orders([
            'status'       => ['wc-completed', 'wc-processing'],
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit'        => -1,
        ]);

        $total_revenue   = 0;
        $product_revenue = [];

        foreach ($orders as $order) {
            $total_revenue += (float) $order->get_total();

            foreach ($order->get_items() as $item) {
                $pid  = $item->get_product_id();
                $name = $item->get_name();
                if (!isset($product_revenue[$pid])) {
                    $product_revenue[$pid] = ['name' => $name, 'revenue' => 0, 'quantity' => 0];
                }
                $product_revenue[$pid]['revenue']  += (float) $item->get_total();
                $product_revenue[$pid]['quantity'] += $item->get_quantity();
            }
        }

        usort($product_revenue, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $top_products = array_slice($product_revenue, 0, 5);

        $order_count = count($orders);

        return [
            'period'              => $period,
            'date_from'           => $date_from,
            'date_to'             => $date_to,
            'total_revenue'       => round($total_revenue, 2),
            'total_orders'        => $order_count,
            'average_order_value' => $order_count > 0 ? round($total_revenue / $order_count, 2) : 0,
            'top_products'        => $top_products,
            'currency'            => get_woocommerce_currency(),
        ];
    }

    /**
     * Get WooCommerce customer statistics.
     *
     * @param array $input Ability input (unused).
     * @return array|WP_Error
     */
    public static function execute_get_customer_stats(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        $customer_query = new \WP_User_Query([
            'role'   => 'customer',
            'fields' => 'ID',
        ]);
        $total_customers = (int) $customer_query->get_total();

        $repeat_customers = 0;
        $customer_ids     = $customer_query->get_results();

        foreach ($customer_ids as $cid) {
            $order_count = wc_get_customer_order_count((int) $cid);
            if ($order_count > 1) {
                $repeat_customers++;
            }
        }

        $repeat_rate = $total_customers > 0 ? round(($repeat_customers / $total_customers) * 100, 2) : 0;

        global $wpdb;
        $avg_order = (float) $wpdb->get_var(
            "SELECT AVG(meta_value) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_order_total'
             AND p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')"
        );

        $month_start = gmdate('Y-m-01 00:00:00');
        $new_this_month = (int) (new \WP_User_Query([
            'role'       => 'customer',
            'date_query' => [['after' => $month_start]],
            'fields'     => 'ID',
            'count_total' => true,
        ]))->get_total();

        return [
            'total_customers'       => $total_customers,
            'repeat_rate'           => $repeat_rate,
            'average_order_value'   => round($avg_order, 2),
            'new_customers_this_month' => $new_this_month,
        ];
    }

    /**
     * Create a WooCommerce coupon.
     *
     * @param array $input {
     *     @type string $code          Coupon code (required).
     *     @type string $discount_type Discount type: percent, fixed_cart, fixed_product.
     *     @type float  $amount        Discount amount (required).
     *     @type string $expiry_date   Expiry date (Y-m-d).
     *     @type int    $usage_limit   Maximum usage count.
     *     @type float  $minimum_amount Minimum order amount.
     *     @type float  $maximum_amount Maximum order amount.
     *     @type bool   $individual_use Whether coupon can be combined.
     *     @type array  $product_ids   Applicable product IDs.
     * }
     * @return array|WP_Error
     */
    public static function execute_create_coupon(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        if (empty($input['code'])) {
            return new WP_Error('missing_code', __('The code parameter is required.', 'abw-ai'));
        }
        if (!isset($input['amount'])) {
            return new WP_Error('missing_amount', __('The amount parameter is required.', 'abw-ai'));
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code(sanitize_text_field($input['code']));
        $coupon->set_discount_type(sanitize_text_field($input['discount_type'] ?? 'percent'));
        $coupon->set_amount((float) $input['amount']);

        if (!empty($input['expiry_date'])) {
            $coupon->set_date_expires(sanitize_text_field($input['expiry_date']));
        }
        if (isset($input['usage_limit'])) {
            $coupon->set_usage_limit((int) $input['usage_limit']);
        }
        if (isset($input['minimum_amount'])) {
            $coupon->set_minimum_amount((float) $input['minimum_amount']);
        }
        if (isset($input['maximum_amount'])) {
            $coupon->set_maximum_amount((float) $input['maximum_amount']);
        }
        if (isset($input['individual_use'])) {
            $coupon->set_individual_use((bool) $input['individual_use']);
        }
        if (!empty($input['product_ids']) && is_array($input['product_ids'])) {
            $coupon->set_product_ids(array_map('intval', $input['product_ids']));
        }

        $coupon->save();

        return [
            'id'            => $coupon->get_id(),
            'code'          => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount'        => $coupon->get_amount(),
            'expiry_date'   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
        ];
    }

    /**
     * List WooCommerce coupons.
     *
     * @param array $input {
     *     @type int    $per_page Coupons per page (default 20).
     *     @type string $status   Post status filter.
     * }
     * @return array|WP_Error
     */
    public static function execute_list_coupons(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        $per_page = (int) ($input['per_page'] ?? 20);
        $args     = [
            'post_type'      => 'shop_coupon',
            'posts_per_page' => min($per_page, 100),
            'post_status'    => !empty($input['status']) ? sanitize_text_field($input['status']) : 'publish',
        ];

        $posts   = get_posts($args);
        $coupons = [];

        foreach ($posts as $post) {
            $coupon    = new \WC_Coupon($post->ID);
            $coupons[] = [
                'id'            => $coupon->get_id(),
                'code'          => $coupon->get_code(),
                'discount_type' => $coupon->get_discount_type(),
                'amount'        => $coupon->get_amount(),
                'usage_count'   => $coupon->get_usage_count(),
                'usage_limit'   => $coupon->get_usage_limit(),
                'expiry_date'   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
            ];
        }

        return $coupons;
    }

    /**
     * Analyze sales performance of a specific WooCommerce product.
     *
     * @param array $input {
     *     @type int    $product_id Product ID (required).
     *     @type string $period     Analysis period: week, month, year.
     * }
     * @return array|WP_Error
     */
    public static function execute_analyze_product_performance(array $input)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', __('WooCommerce is not active.', 'abw-ai'));
        }

        if (empty($input['product_id'])) {
            return new WP_Error('missing_product_id', __('The product_id parameter is required.', 'abw-ai'));
        }

        $product_id = (int) $input['product_id'];
        $product    = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found.', 'abw-ai'));
        }

        $period = sanitize_text_field($input['period'] ?? 'month');
        $now    = current_time('timestamp');

        switch ($period) {
            case 'week':
                $date_from = gmdate('Y-m-d', strtotime('-7 days', $now));
                break;
            case 'year':
                $date_from = gmdate('Y-01-01', $now);
                break;
            case 'month':
            default:
                $date_from = gmdate('Y-m-01', $now);
                break;
        }

        $date_to = gmdate('Y-m-d', $now);

        $orders = wc_get_orders([
            'status'       => ['wc-completed', 'wc-processing'],
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit'        => -1,
        ]);

        $units_sold    = 0;
        $revenue       = 0;
        $orders_with   = 0;

        foreach ($orders as $order) {
            $found = false;
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() === $product_id || $item->get_variation_id() === $product_id) {
                    $units_sold += $item->get_quantity();
                    $revenue    += (float) $item->get_total();
                    $found       = true;
                }
            }
            if ($found) {
                $orders_with++;
            }
        }

        return [
            'product_id'   => $product_id,
            'product_name' => $product->get_name(),
            'units_sold'   => $units_sold,
            'revenue'      => round($revenue, 2),
            'orders'       => $orders_with,
            'period'       => $period,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'currency'     => get_woocommerce_currency(),
        ];
    }
}

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
                'description' => __('Advanced WordPress AI management abilities', 'abw-ai'),
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
        // Elementor Abilities (if Elementor is active)
        // =====================
        if (class_exists('\Elementor\Plugin')) {
            wp_register_ability('abw-ai/list-elementor-pages', [
                'label'       => __('List Elementor Pages', 'abw-ai'),
                'description' => __('List pages built with Elementor', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => [
                            'type'        => 'integer',
                            'description' => 'Pages per page',
                            'default'     => 20,
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_list_elementor_pages'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/get-elementor-page', [
                'label'       => __('Get Elementor Page', 'abw-ai'),
                'description' => __('Get Elementor page data including elements', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Page ID',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_get_elementor_page'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);

            wp_register_ability('abw-ai/update-elementor-page', [
                'label'       => __('Update Elementor Page', 'abw-ai'),
                'description' => __('Update Elementor page content and settings', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => 'Page ID to update',
                        ],
                        'elements' => [
                            'type'        => 'array',
                            'description' => 'Elementor elements data',
                        ],
                        'settings' => [
                            'type'        => 'object',
                            'description' => 'Page settings',
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_update_elementor_page'],
                'permission_callback' => [__CLASS__, 'check_write_permission'],
            ]);

            wp_register_ability('abw-ai/list-elementor-templates', [
                'label'       => __('List Elementor Templates', 'abw-ai'),
                'description' => __('List saved Elementor templates', 'abw-ai'),
                'category'    => 'abw-ai',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'per_page' => [
                            'type'        => 'integer',
                            'default'     => 20,
                        ],
                    ],
                ],
                'execute_callback'   => [__CLASS__, 'execute_list_elementor_templates'],
                'permission_callback' => [__CLASS__, 'check_read_permission'],
            ]);
        }

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
        return current_user_can('read');
    }

    /**
     * Check write permission
     */
    public static function check_write_permission()
    {
        return current_user_can('edit_posts');
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
            'id'      => $post_id,
            'success' => true,
        ];
    }

    /**
     * Execute update post ability
     */
    public static function execute_update_post($input)
    {
        $post_data = ['ID' => $input['id']];

        if (isset($input['title'])) {
            $post_data['post_title'] = sanitize_text_field($input['title']);
        }
        if (isset($input['content'])) {
            $post_data['post_content'] = wp_kses_post($input['content']);
        }
        if (isset($input['status'])) {
            $post_data['post_status'] = $input['status'];
        }

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'id'      => $input['id'],
            'success' => true,
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
     * Execute list Elementor pages ability
     */
    public static function execute_list_elementor_pages($input)
    {
        $args = [
            'post_type'      => 'page',
            'posts_per_page' => $input['per_page'] ?? 20,
            'meta_query'     => [
                [
                    'key'   => '_elementor_edit_mode',
                    'value' => 'builder',
                ],
            ],
        ];

        $query = new WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $pages[] = [
                'id'     => $post->ID,
                'title'  => $post->post_title,
                'slug'   => $post->post_name,
                'status' => $post->post_status,
            ];
        }

        return [
            'pages' => $pages,
            'total' => $query->found_posts,
        ];
    }

    /**
     * Execute get Elementor page ability
     */
    public static function execute_get_elementor_page($input)
    {
        $page = get_post($input['id']);

        if (! $page) {
            return new WP_Error('not_found', 'Page not found');
        }

        $elementor_data = get_post_meta($input['id'], '_elementor_data', true);

        return [
            'id'             => $page->ID,
            'title'          => $page->post_title,
            'status'         => $page->post_status,
            'elementor_data' => $elementor_data ? json_decode($elementor_data, true) : null,
        ];
    }

    /**
     * Execute update Elementor page ability
     */
    public static function execute_update_elementor_page($input)
    {
        $document = \Elementor\Plugin::$instance->documents->get($input['id'], false);

        if (! $document) {
            return new WP_Error('not_found', 'Document not found');
        }

        if (! $document->is_built_with_elementor()) {
            $document->set_is_built_with_elementor(true);
        }

        $save_data = [];

        if (isset($input['elements'])) {
            $save_data['elements'] = $input['elements'];
        }

        if (isset($input['settings'])) {
            $save_data['settings'] = $input['settings'];
        }

        $saved = $document->save($save_data);

        return [
            'id'      => $input['id'],
            'success' => (bool) $saved,
        ];
    }

    /**
     * Execute list Elementor templates ability
     */
    public static function execute_list_elementor_templates($input)
    {
        $args = [
            'post_type'      => 'elementor_library',
            'posts_per_page' => $input['per_page'] ?? 20,
        ];

        $query     = new WP_Query($args);
        $templates = [];

        foreach ($query->posts as $post) {
            $templates[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'type'  => get_post_meta($post->ID, '_elementor_template_type', true),
            ];
        }

        return [
            'templates' => $templates,
            'total'     => $query->found_posts,
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
            @unlink($tmp);
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
        $value = get_option($input['option']);

        return [
            'option' => $input['option'],
            'value'  => $value,
        ];
    }

    /**
     * Execute update option ability
     */
    public static function execute_update_option($input)
    {
        // Whitelist of safe options
        $whitelist = [
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
        ];

        if (! in_array($input['option'], $whitelist, true)) {
            return new WP_Error('not_allowed', __('This option is not allowed to be updated via API.', 'abw-ai'));
        }

        $result = update_option($input['option'], $input['value']);

        return [
            'success' => $result,
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
                'excerpt' => wp_trim_words(strip_tags($post->post_content), 30),
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
}

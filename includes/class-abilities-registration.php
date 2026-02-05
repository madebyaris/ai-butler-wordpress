<?php
/**
 * WordPress Abilities API Registration
 *
 * Registers ABW-AI abilities with WordPress core Abilities API
 * for MCP Adapter integration.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Abilities_Registration
 *
 * Registers all ABW-AI capabilities as WordPress Abilities
 * for native MCP integration via the MCP Adapter plugin.
 */
class ABW_Abilities_Registration {

	/**
	 * Initialize abilities registration
	 */
	public static function init() {
		// Only register if Abilities API is available (WordPress 6.9+)
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register all ABW-AI abilities with WordPress
	 */
	public static function register_abilities() {
		// Register ability category
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'abw-ai', [
				'label'       => __( 'ABW-AI Butler', 'abw-ai' ),
				'description' => __( 'Advanced WordPress AI management abilities', 'abw-ai' ),
			] );
		}

		// =====================
		// Posts Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-posts', [
			'label'       => __( 'List Posts', 'abw-ai' ),
			'description' => __( 'Retrieve WordPress posts with filtering options', 'abw-ai' ),
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
						'enum'        => [ 'publish', 'draft', 'pending', 'private', 'any' ],
						'default'     => 'publish',
					],
					'search' => [
						'type'        => 'string',
						'description' => 'Search query',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_list_posts' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		wp_register_ability( 'abw-ai/get-post', [
			'label'       => __( 'Get Post', 'abw-ai' ),
			'description' => __( 'Retrieve a single post by ID', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id' ],
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'Post ID',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_get_post' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		wp_register_ability( 'abw-ai/create-post', [
			'label'       => __( 'Create Post', 'abw-ai' ),
			'description' => __( 'Create a new WordPress post', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'title' ],
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
						'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
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
						'items'       => [ 'type' => 'integer' ],
					],
					'tags' => [
						'type'        => 'array',
						'description' => 'Tag IDs or names',
						'items'       => [ 'type' => 'string' ],
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_create_post' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		wp_register_ability( 'abw-ai/update-post', [
			'label'       => __( 'Update Post', 'abw-ai' ),
			'description' => __( 'Update an existing WordPress post', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id' ],
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
						'enum'        => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_update_post' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		wp_register_ability( 'abw-ai/delete-post', [
			'label'       => __( 'Delete Post', 'abw-ai' ),
			'description' => __( 'Delete a WordPress post', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id' ],
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
			'execute_callback'   => [ __CLASS__, 'execute_delete_post' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		// =====================
		// Comments Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-comments', [
			'label'       => __( 'List Comments', 'abw-ai' ),
			'description' => __( 'Retrieve WordPress comments with filtering', 'abw-ai' ),
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
						'enum'        => [ 'approve', 'hold', 'spam', 'trash', 'all' ],
						'default'     => 'all',
					],
					'per_page' => [
						'type'        => 'integer',
						'description' => 'Comments per page',
						'default'     => 20,
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_list_comments' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		wp_register_ability( 'abw-ai/moderate-comment', [
			'label'       => __( 'Moderate Comment', 'abw-ai' ),
			'description' => __( 'Approve, spam, or trash a comment', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id', 'action' ],
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'Comment ID',
					],
					'action' => [
						'type'        => 'string',
						'description' => 'Moderation action',
						'enum'        => [ 'approve', 'unapprove', 'spam', 'trash', 'delete' ],
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_moderate_comment' ],
			'permission_callback' => [ __CLASS__, 'check_write_permission' ],
		] );

		// =====================
		// Plugin Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-plugins', [
			'label'       => __( 'List Plugins', 'abw-ai' ),
			'description' => __( 'List all installed WordPress plugins', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'status' => [
						'type'        => 'string',
						'description' => 'Filter by status',
						'enum'        => [ 'active', 'inactive', 'all' ],
						'default'     => 'all',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_list_plugins' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		wp_register_ability( 'abw-ai/activate-plugin', [
			'label'       => __( 'Activate Plugin', 'abw-ai' ),
			'description' => __( 'Activate a WordPress plugin', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'plugin' ],
				'properties' => [
					'plugin' => [
						'type'        => 'string',
						'description' => 'Plugin file path (e.g., "plugin-folder/plugin-file.php")',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_activate_plugin' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		wp_register_ability( 'abw-ai/deactivate-plugin', [
			'label'       => __( 'Deactivate Plugin', 'abw-ai' ),
			'description' => __( 'Deactivate a WordPress plugin', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'plugin' ],
				'properties' => [
					'plugin' => [
						'type'        => 'string',
						'description' => 'Plugin file path',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_deactivate_plugin' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		// =====================
		// Theme Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-themes', [
			'label'       => __( 'List Themes', 'abw-ai' ),
			'description' => __( 'List all installed WordPress themes', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [],
			],
			'execute_callback'   => [ __CLASS__, 'execute_list_themes' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		wp_register_ability( 'abw-ai/activate-theme', [
			'label'       => __( 'Activate Theme', 'abw-ai' ),
			'description' => __( 'Activate a WordPress theme', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'stylesheet' ],
				'properties' => [
					'stylesheet' => [
						'type'        => 'string',
						'description' => 'Theme stylesheet (directory name)',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_activate_theme' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		// =====================
		// Site Settings Abilities
		// =====================
		wp_register_ability( 'abw-ai/get-site-info', [
			'label'       => __( 'Get Site Info', 'abw-ai' ),
			'description' => __( 'Get WordPress site information', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [],
			],
			'execute_callback'   => [ __CLASS__, 'execute_get_site_info' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		wp_register_ability( 'abw-ai/update-site-identity', [
			'label'       => __( 'Update Site Identity', 'abw-ai' ),
			'description' => __( 'Update site title and tagline', 'abw-ai' ),
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
			'execute_callback'   => [ __CLASS__, 'execute_update_site_identity' ],
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
		] );

		// =====================
		// Elementor Abilities (if Elementor is active)
		// =====================
		if ( class_exists( '\Elementor\Plugin' ) ) {
			wp_register_ability( 'abw-ai/list-elementor-pages', [
				'label'       => __( 'List Elementor Pages', 'abw-ai' ),
				'description' => __( 'List pages built with Elementor', 'abw-ai' ),
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
				'execute_callback'   => [ __CLASS__, 'execute_list_elementor_pages' ],
				'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			] );

			wp_register_ability( 'abw-ai/get-elementor-page', [
				'label'       => __( 'Get Elementor Page', 'abw-ai' ),
				'description' => __( 'Get Elementor page data including elements', 'abw-ai' ),
				'category'    => 'abw-ai',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id' ],
					'properties' => [
						'id' => [
							'type'        => 'integer',
							'description' => 'Page ID',
						],
					],
				],
				'execute_callback'   => [ __CLASS__, 'execute_get_elementor_page' ],
				'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			] );

			wp_register_ability( 'abw-ai/update-elementor-page', [
				'label'       => __( 'Update Elementor Page', 'abw-ai' ),
				'description' => __( 'Update Elementor page content and settings', 'abw-ai' ),
				'category'    => 'abw-ai',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'id' ],
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
				'execute_callback'   => [ __CLASS__, 'execute_update_elementor_page' ],
				'permission_callback' => [ __CLASS__, 'check_write_permission' ],
			] );

			wp_register_ability( 'abw-ai/list-elementor-templates', [
				'label'       => __( 'List Elementor Templates', 'abw-ai' ),
				'description' => __( 'List saved Elementor templates', 'abw-ai' ),
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
				'execute_callback'   => [ __CLASS__, 'execute_list_elementor_templates' ],
				'permission_callback' => [ __CLASS__, 'check_read_permission' ],
			] );
		}

		// =====================
		// Menu Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-menus', [
			'label'       => __( 'List Menus', 'abw-ai' ),
			'description' => __( 'List all WordPress navigation menus', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [],
			],
			'execute_callback'   => [ __CLASS__, 'execute_list_menus' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		wp_register_ability( 'abw-ai/get-menu', [
			'label'       => __( 'Get Menu', 'abw-ai' ),
			'description' => __( 'Get menu with all items', 'abw-ai' ),
			'category'    => 'abw-ai',
			'input_schema' => [
				'type'       => 'object',
				'required'   => [ 'id' ],
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'Menu ID',
					],
				],
			],
			'execute_callback'   => [ __CLASS__, 'execute_get_menu' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );

		// =====================
		// Media Abilities
		// =====================
		wp_register_ability( 'abw-ai/list-media', [
			'label'       => __( 'List Media', 'abw-ai' ),
			'description' => __( 'List media library items', 'abw-ai' ),
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
			'execute_callback'   => [ __CLASS__, 'execute_list_media' ],
			'permission_callback' => [ __CLASS__, 'check_read_permission' ],
		] );
	}

	// ===================
	// Permission Callbacks
	// ===================

	/**
	 * Check read permission
	 */
	public static function check_read_permission() {
		return current_user_can( 'read' );
	}

	/**
	 * Check write permission
	 */
	public static function check_write_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check admin permission
	 */
	public static function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	// ===================
	// Execute Callbacks
	// ===================

	/**
	 * Execute list posts ability
	 */
	public static function execute_list_posts( $input ) {
		$args = [
			'post_type'      => $input['post_type'] ?? 'post',
			'posts_per_page' => $input['per_page'] ?? 10,
			'paged'          => $input['page'] ?? 1,
			'post_status'    => $input['status'] ?? 'publish',
		];

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}

		$query = new WP_Query( $args );
		$posts = [];

		foreach ( $query->posts as $post ) {
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
	public static function execute_get_post( $input ) {
		$post = get_post( $input['id'] );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found' );
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
			'featured_image' => get_post_thumbnail_id( $post->ID ),
		];
	}

	/**
	 * Execute create post ability
	 */
	public static function execute_create_post( $input ) {
		$post_data = [
			'post_title'   => sanitize_text_field( $input['title'] ),
			'post_content' => wp_kses_post( $input['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $input['excerpt'] ?? '' ),
			'post_status'  => $input['status'] ?? 'draft',
			'post_type'    => $input['type'] ?? 'post',
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $input['categories'] ) ) {
			wp_set_post_categories( $post_id, $input['categories'] );
		}

		if ( ! empty( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, $input['tags'] );
		}

		return [
			'id'      => $post_id,
			'success' => true,
		];
	}

	/**
	 * Execute update post ability
	 */
	public static function execute_update_post( $input ) {
		$post_data = [ 'ID' => $input['id'] ];

		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $input['content'] );
		}
		if ( isset( $input['status'] ) ) {
			$post_data['post_status'] = $input['status'];
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
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
	public static function execute_delete_post( $input ) {
		$force  = $input['force'] ?? false;
		$result = wp_delete_post( $input['id'], $force );

		return [
			'success' => (bool) $result,
			'deleted' => (bool) $result,
		];
	}

	/**
	 * Execute list comments ability
	 */
	public static function execute_list_comments( $input ) {
		$args = [
			'number' => $input['per_page'] ?? 20,
			'status' => $input['status'] ?? 'all',
		];

		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = $input['post_id'];
		}

		$comments = get_comments( $args );
		$result   = [];

		foreach ( $comments as $comment ) {
			$result[] = [
				'id'      => $comment->comment_ID,
				'post_id' => $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date,
				'status'  => wp_get_comment_status( $comment ),
			];
		}

		return [ 'comments' => $result ];
	}

	/**
	 * Execute moderate comment ability
	 */
	public static function execute_moderate_comment( $input ) {
		$id     = $input['id'];
		$action = $input['action'];

		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $id, 'approve' );
				break;
			case 'unapprove':
				$result = wp_set_comment_status( $id, 'hold' );
				break;
			case 'spam':
				$result = wp_spam_comment( $id );
				break;
			case 'trash':
				$result = wp_trash_comment( $id );
				break;
			case 'delete':
				$result = wp_delete_comment( $id, true );
				break;
			default:
				return new WP_Error( 'invalid_action', 'Invalid moderation action' );
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
	public static function execute_list_plugins( $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );
		$status_filter  = $input['status'] ?? 'all';

		$plugins = [];
		foreach ( $all_plugins as $file => $data ) {
			$is_active = in_array( $file, $active_plugins, true );

			if ( $status_filter === 'active' && ! $is_active ) {
				continue;
			}
			if ( $status_filter === 'inactive' && $is_active ) {
				continue;
			}

			$plugins[] = [
				'file'      => $file,
				'name'      => $data['Name'],
				'version'   => $data['Version'],
				'is_active' => $is_active,
			];
		}

		return [ 'plugins' => $plugins ];
	}

	/**
	 * Execute activate plugin ability
	 */
	public static function execute_activate_plugin( $input ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $input['plugin'] );

		if ( is_wp_error( $result ) ) {
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
	public static function execute_deactivate_plugin( $input ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $input['plugin'] );

		return [
			'plugin'  => $input['plugin'],
			'success' => true,
		];
	}

	/**
	 * Execute list themes ability
	 */
	public static function execute_list_themes( $input ) {
		$all_themes    = wp_get_themes();
		$current_theme = wp_get_theme();

		$themes = [];
		foreach ( $all_themes as $stylesheet => $theme ) {
			$themes[] = [
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'is_active'  => $stylesheet === $current_theme->get_stylesheet(),
			];
		}

		return [ 'themes' => $themes ];
	}

	/**
	 * Execute activate theme ability
	 */
	public static function execute_activate_theme( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );

		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', 'Theme not found' );
		}

		switch_theme( $input['stylesheet'] );

		return [
			'theme'   => $input['stylesheet'],
			'success' => true,
		];
	}

	/**
	 * Execute get site info ability
	 */
	public static function execute_get_site_info( $input ) {
		return [
			'name'        => get_bloginfo( 'name' ),
			'tagline'     => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_url'   => admin_url(),
			'version'     => get_bloginfo( 'version' ),
			'language'    => get_locale(),
			'timezone'    => wp_timezone_string(),
			'theme'       => wp_get_theme()->get( 'Name' ),
		];
	}

	/**
	 * Execute update site identity ability
	 */
	public static function execute_update_site_identity( $input ) {
		if ( isset( $input['title'] ) ) {
			update_option( 'blogname', sanitize_text_field( $input['title'] ) );
		}
		if ( isset( $input['tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $input['tagline'] ) );
		}

		return [
			'success' => true,
			'title'   => get_bloginfo( 'name' ),
			'tagline' => get_bloginfo( 'description' ),
		];
	}

	/**
	 * Execute list Elementor pages ability
	 */
	public static function execute_list_elementor_pages( $input ) {
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

		$query = new WP_Query( $args );
		$pages = [];

		foreach ( $query->posts as $post ) {
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
	public static function execute_get_elementor_page( $input ) {
		$page = get_post( $input['id'] );

		if ( ! $page ) {
			return new WP_Error( 'not_found', 'Page not found' );
		}

		$elementor_data = get_post_meta( $input['id'], '_elementor_data', true );

		return [
			'id'             => $page->ID,
			'title'          => $page->post_title,
			'status'         => $page->post_status,
			'elementor_data' => $elementor_data ? json_decode( $elementor_data, true ) : null,
		];
	}

	/**
	 * Execute update Elementor page ability
	 */
	public static function execute_update_elementor_page( $input ) {
		$document = \Elementor\Plugin::$instance->documents->get( $input['id'], false );

		if ( ! $document ) {
			return new WP_Error( 'not_found', 'Document not found' );
		}

		if ( ! $document->is_built_with_elementor() ) {
			$document->set_is_built_with_elementor( true );
		}

		$save_data = [];

		if ( isset( $input['elements'] ) ) {
			$save_data['elements'] = $input['elements'];
		}

		if ( isset( $input['settings'] ) ) {
			$save_data['settings'] = $input['settings'];
		}

		$saved = $document->save( $save_data );

		return [
			'id'      => $input['id'],
			'success' => (bool) $saved,
		];
	}

	/**
	 * Execute list Elementor templates ability
	 */
	public static function execute_list_elementor_templates( $input ) {
		$args = [
			'post_type'      => 'elementor_library',
			'posts_per_page' => $input['per_page'] ?? 20,
		];

		$query     = new WP_Query( $args );
		$templates = [];

		foreach ( $query->posts as $post ) {
			$templates[] = [
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
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
	public static function execute_list_menus( $input ) {
		$menus  = wp_get_nav_menus();
		$result = [];

		foreach ( $menus as $menu ) {
			$result[] = [
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => $menu->count,
			];
		}

		return [ 'menus' => $result ];
	}

	/**
	 * Execute get menu ability
	 */
	public static function execute_get_menu( $input ) {
		$menu = wp_get_nav_menu_object( $input['id'] );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', 'Menu not found' );
		}

		$items      = wp_get_nav_menu_items( $input['id'] );
		$menu_items = [];

		foreach ( $items as $item ) {
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
	public static function execute_list_media( $input ) {
		$args = [
			'post_type'      => 'attachment',
			'posts_per_page' => $input['per_page'] ?? 20,
			'post_mime_type' => $input['mime_type'] ?? '',
		];

		$query = new WP_Query( $args );
		$media = [];

		foreach ( $query->posts as $attachment ) {
			$media[] = [
				'id'        => $attachment->ID,
				'title'     => $attachment->post_title,
				'url'       => wp_get_attachment_url( $attachment->ID ),
				'mime_type' => $attachment->post_mime_type,
			];
		}

		return [
			'media' => $media,
			'total' => $query->found_posts,
		];
	}
}

<?php
/**
 * Elementor REST API Integration
 *
 * @package ABW_AI_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Elementor_REST_API
 *
 * Provides Elementor-specific REST endpoints (when Elementor is active)
 */
class ABW_Elementor_REST_API {

	const NAMESPACE = 'abw/v1';

	/**
	 * Initialize Elementor REST routes
	 */
	public static function init() {
		if ( ! self::is_elementor_active() ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Check if Elementor is active
	 *
	 * @return bool
	 */
	private static function is_elementor_active() {
		return class_exists( '\Elementor\Plugin' ) && defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Register Elementor REST routes
	 */
	public static function register_routes() {
		// Templates endpoints
		register_rest_route( self::NAMESPACE, '/elementor/templates', [
			[
				'methods' => 'GET',
				'callback' => [ __CLASS__, 'list_templates' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
			[
				'methods' => 'POST',
				'callback' => [ __CLASS__, 'create_template' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/elementor/templates/(?P<id>\d+)', [
			[
				'methods' => 'GET',
				'callback' => [ __CLASS__, 'get_template' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
			[
				'methods' => 'POST',
				'callback' => [ __CLASS__, 'update_template' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
			[
				'methods' => 'DELETE',
				'callback' => [ __CLASS__, 'delete_template' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
		] );

		// Kit endpoints
		register_rest_route( self::NAMESPACE, '/elementor/kit', [
			[
				'methods' => 'GET',
				'callback' => [ __CLASS__, 'get_kit' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
			[
				'methods' => 'POST',
				'callback' => [ __CLASS__, 'update_kit' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
		] );

		// Pages/Documents endpoints
		register_rest_route( self::NAMESPACE, '/elementor/pages', [
			[
				'methods' => 'GET',
				'callback' => [ __CLASS__, 'list_pages' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/elementor/pages/(?P<id>\d+)', [
			[
				'methods' => 'GET',
				'callback' => [ __CLASS__, 'get_page' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
			[
				'methods' => 'POST',
				'callback' => [ __CLASS__, 'update_page' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			],
		] );
	}

	/**
	 * Check permission
	 *
	 * @param WP_REST_Request $request Request object
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request ) {
		// Use same permission check as main REST API
		return ABW_REST_API::check_permission( $request );
	}

	/**
	 * List Elementor templates
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_templates( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$args = [
			'post_type' => 'elementor_library',
			'posts_per_page' => $request->get_param( 'per_page' ) ?: 20,
			'paged' => $request->get_param( 'page' ) ?: 1,
		];

		$query = new WP_Query( $args );
		$templates = [];

		foreach ( $query->posts as $post ) {
			$templates[] = [
				'id' => $post->ID,
				'title' => $post->post_title,
				'slug' => $post->post_name,
				'type' => get_post_meta( $post->ID, '_elementor_template_type', true ),
			];
		}

		return rest_ensure_response( [
			'templates' => $templates,
			'total' => $query->found_posts,
		] );
	}

	/**
	 * Get Elementor template
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_template( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$template_id = (int) $request->get_param( 'id' );
		$template = get_post( $template_id );

		if ( ! $template || $template->post_type !== 'elementor_library' ) {
			return new WP_Error( 'template_not_found', 'Template not found.', [ 'status' => 404 ] );
		}

		$elementor_data = get_post_meta( $template_id, '_elementor_data', true );

		return rest_ensure_response( [
			'id' => $template->ID,
			'title' => $template->post_title,
			'slug' => $template->post_name,
			'type' => get_post_meta( $template_id, '_elementor_template_type', true ),
			'data' => $elementor_data ? json_decode( $elementor_data, true ) : null,
		] );
	}

	/**
	 * Create Elementor template
	 *
	 * Uses Elementor's Documents_Manager to properly create templates.
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_template( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$data = $request->get_json_params();

		// Determine template type (default to 'section' if not specified)
		$template_type = sanitize_text_field( $data['type'] ?? 'section' );

		// Prepare post data
		$post_data = [
			'post_title' => sanitize_text_field( $data['title'] ?? 'New Template' ),
			'post_status' => 'publish',
		];

		// Prepare meta data
		$meta_data = [];

		// Create document using Elementor's Documents_Manager
		// This properly initializes all required meta keys
		$document = \Elementor\Plugin::$instance->documents->create( $template_type, $post_data, $meta_data );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$template_id = $document->get_main_id();

		// Save Elementor data if provided
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$save_data = [
				'elements' => $data['data'],
			];

			// Handle settings if provided
			if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
				$save_data['settings'] = $data['settings'];
			}

			$document->save( $save_data );
		}

		return rest_ensure_response( [
			'id' => $template_id,
			'success' => true,
			'message' => 'Elementor template created successfully.',
		] );
	}

	/**
	 * Update Elementor template
	 *
	 * Uses Elementor's Document API to properly save data with cache clearing.
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_template( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$template_id = (int) $request->get_param( 'id' );
		$template = get_post( $template_id );

		if ( ! $template || $template->post_type !== 'elementor_library' ) {
			return new WP_Error( 'template_not_found', 'Template not found.', [ 'status' => 404 ] );
		}

		$data = $request->get_json_params();

		// Update post title if provided
		if ( isset( $data['title'] ) ) {
			wp_update_post( [
				'ID' => $template_id,
				'post_title' => sanitize_text_field( $data['title'] ),
			] );
		}

		// Get document instance
		$document = \Elementor\Plugin::$instance->documents->get( $template_id, false );

		if ( ! $document ) {
			return new WP_Error( 'document_not_found', 'Could not retrieve document.', [ 'status' => 404 ] );
		}

		// Ensure template is marked as built with Elementor
		if ( ! $document->is_built_with_elementor() ) {
			$document->set_is_built_with_elementor( true );
		}

		// Prepare save data
		$save_data = [];

		// Handle template data if provided
		if ( isset( $data['data'] ) ) {
			// Ensure it's an array
			$elements = is_array( $data['data'] ) ? $data['data'] : [];
			$save_data['elements'] = $elements;
		}

		// Handle settings if provided
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$save_data['settings'] = $data['settings'];
		}

		// Check if user has permission to edit
		if ( ! $document->is_editable_by_current_user() ) {
			return new WP_Error( 'permission_denied', 'You do not have permission to edit this template.', [ 'status' => 403 ] );
		}

		// Save using Elementor's Document API
		// This properly handles cache clearing, hooks, and data sanitization
		if ( ! empty( $save_data ) ) {
			$saved = $document->save( $save_data );

			if ( ! $saved ) {
				return new WP_Error( 'save_failed', 'Failed to save Elementor data.', [ 'status' => 500 ] );
			}
		}

		return rest_ensure_response( [
			'id' => $template_id,
			'success' => true,
			'message' => 'Elementor template updated successfully.',
		] );
	}

	/**
	 * Delete Elementor template
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_template( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$template_id = (int) $request->get_param( 'id' );
		$result = wp_delete_post( $template_id, true );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', 'Failed to delete template.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'deleted' => true,
		] );
	}

	/**
	 * Get Elementor kit settings
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_kit( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
		if ( ! $kit_id ) {
			return new WP_Error( 'kit_not_found', 'No active kit found.', [ 'status' => 404 ] );
		}

		$kit = \Elementor\Plugin::$instance->documents->get( $kit_id );
		$settings = $kit->get_settings();

		return rest_ensure_response( [
			'id' => $kit_id,
			'settings' => $settings,
		] );
	}

	/**
	 * Update Elementor kit settings
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_kit( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$data = $request->get_json_params();
		$kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();

		if ( ! $kit_id ) {
			return new WP_Error( 'kit_not_found', 'No active kit found.', [ 'status' => 404 ] );
		}

		$kit = \Elementor\Plugin::$instance->documents->get( $kit_id );

		// Update settings if provided
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			foreach ( $data['settings'] as $key => $value ) {
				$kit->update_settings( [ $key => $value ] );
			}
		}

		return rest_ensure_response( [
			'id' => $kit_id,
			'success' => true,
		] );
	}

	/**
	 * List Elementor-edited pages
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_pages( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$args = [
			'post_type' => 'page',
			'posts_per_page' => $request->get_param( 'per_page' ) ?: 20,
			'paged' => $request->get_param( 'page' ) ?: 1,
			'meta_query' => [
				[
					'key' => '_elementor_edit_mode',
					'value' => 'builder',
				],
			],
		];

		$query = new WP_Query( $args );
		$pages = [];

		foreach ( $query->posts as $post ) {
			$pages[] = [
				'id' => $post->ID,
				'title' => $post->post_title,
				'slug' => $post->post_name,
				'status' => $post->post_status,
			];
		}

		return rest_ensure_response( [
			'pages' => $pages,
			'total' => $query->found_posts,
		] );
	}

	/**
	 * Get Elementor page
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_page( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$page_id = (int) $request->get_param( 'id' );
		$page = get_post( $page_id );

		if ( ! $page || $page->post_type !== 'page' ) {
			return new WP_Error( 'page_not_found', 'Page not found.', [ 'status' => 404 ] );
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		$is_elementor = get_post_meta( $page_id, '_elementor_edit_mode', true ) === 'builder';

		return rest_ensure_response( [
			'id' => $page->ID,
			'title' => $page->post_title,
			'slug' => $page->post_name,
			'status' => $page->post_status,
			'is_elementor' => $is_elementor,
			'elementor_data' => $is_elementor && $elementor_data ? json_decode( $elementor_data, true ) : null,
		] );
	}

	/**
	 * Update Elementor page
	 *
	 * Uses Elementor's Document API to properly save data with cache clearing.
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_page( $request ) {
		if ( ! self::is_elementor_active() ) {
			return new WP_Error( 'elementor_not_active', 'Elementor is not active.', [ 'status' => 404 ] );
		}

		$page_id = (int) $request->get_param( 'id' );
		$page = get_post( $page_id );

		if ( ! $page || $page->post_type !== 'page' ) {
			return new WP_Error( 'page_not_found', 'Page not found.', [ 'status' => 404 ] );
		}

		$data = $request->get_json_params();

		// Get document instance
		$document = \Elementor\Plugin::$instance->documents->get( $page_id, false );

		if ( ! $document ) {
			return new WP_Error( 'document_not_found', 'Could not retrieve document.', [ 'status' => 404 ] );
		}

		// Ensure page is marked as built with Elementor
		if ( ! $document->is_built_with_elementor() ) {
			$document->set_is_built_with_elementor( true );
		}

		// Prepare save data
		$save_data = [];

		// Handle elements data
		if ( isset( $data['elementor_data'] ) ) {
			// Ensure it's an array
			$elements = is_array( $data['elementor_data'] ) ? $data['elementor_data'] : [];
			$save_data['elements'] = $elements;
		}

		// Handle page settings if provided
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$save_data['settings'] = $data['settings'];
		}

		// Check if user has permission to edit
		if ( ! $document->is_editable_by_current_user() ) {
			return new WP_Error( 'permission_denied', 'You do not have permission to edit this page.', [ 'status' => 403 ] );
		}

		// Save using Elementor's Document API
		// This properly handles cache clearing, hooks, and data sanitization
		$saved = $document->save( $save_data );

		if ( ! $saved ) {
			return new WP_Error( 'save_failed', 'Failed to save Elementor data.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'id' => $page_id,
			'success' => true,
			'message' => 'Elementor page updated successfully.',
		] );
	}
}


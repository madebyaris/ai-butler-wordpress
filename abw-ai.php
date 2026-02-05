<?php
/**
 * Plugin Name: ABW-AI - Advanced Butler WordPress AI
 * Plugin URI: https://github.com/madebyaris/abw-ai
 * Description: Advanced AI assistant for WordPress with MCP support, Elementor integration, and multi-provider AI chat interface
 * Version: 1.0.0
 * Author: ABW-AI Contributors
 * Author URI: https://github.com/madebyaris/abw-ai
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: abw-ai
 * Requires at least: 6.9
 * Requires PHP: 7.4
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABW_VERSION', '1.0.0' );
define( 'ABW_PATH', plugin_dir_path( __FILE__ ) );
define( 'ABW_URL', plugin_dir_url( __FILE__ ) );
define( 'ABW_INCLUDES', ABW_PATH . 'includes/' );

// Load core files
require_once ABW_INCLUDES . 'class-admin.php';
require_once ABW_INCLUDES . 'class-elementor-rest-api.php';
require_once ABW_INCLUDES . 'class-abilities-registration.php';
require_once ABW_INCLUDES . 'class-ai-router.php';
require_once ABW_INCLUDES . 'class-ai-tools.php';
require_once ABW_INCLUDES . 'class-chat-interface.php';

/**
 * Initialize plugin
 */
function abw_ai_init() {
	ABW_Admin::init();
	ABW_Elementor_REST_API::init();
	ABW_Abilities_Registration::init();
	ABW_Chat_Interface::init();
}
add_action( 'plugins_loaded', 'abw_ai_init' );

/**
 * Activation hook
 */
function abw_ai_activate() {
	// Add capability to administrators
	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		$admin_role->add_cap( 'use_abw' );
	}
	
	// Migrate from old AYU capability if exists
	$users = get_users( [ 'role' => 'administrator' ] );
	foreach ( $users as $user ) {
		if ( $user->has_cap( 'use_ayu' ) ) {
			$user->add_cap( 'use_abw' );
		}
	}
}
register_activation_hook( __FILE__, 'abw_ai_activate' );

/**
 * Uninstall hook
 */
function abw_ai_uninstall() {
	// Cleanup options
	delete_option( 'abw_rate_limit' );
	delete_option( 'abw_cors_origins' );
	delete_option( 'abw_ai_provider' );
	delete_option( 'abw_openai_api_key' );
	delete_option( 'abw_anthropic_api_key' );
	delete_option( 'abw_audit_logs' );
}
register_uninstall_hook( __FILE__, 'abw_ai_uninstall' );

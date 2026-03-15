<?php
/**
 * Plugin Name: ABW-AI - Advanced Butler WP AI
 * Plugin URI: https://github.com/madebyaris/ai-butler-wordpress
 * Description: Advanced AI assistant for WordPress with MCP support, Gutenberg integration, and a multi-provider AI chat interface
 * Version: 1.0.0
 * Author: ABW-AI Contributors
 * Author URI: https://madebyaris.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
require_once ABW_INCLUDES . 'class-debug-log.php';
require_once ABW_INCLUDES . 'class-abilities-registration.php';
require_once ABW_INCLUDES . 'class-background-jobs.php';
require_once ABW_INCLUDES . 'class-ai-router.php';
require_once ABW_INCLUDES . 'class-ai-tools.php';
require_once ABW_INCLUDES . 'class-brand-voice.php';
require_once ABW_INCLUDES . 'class-ai-workflows.php';
require_once ABW_INCLUDES . 'class-security-tools.php';
require_once ABW_INCLUDES . 'class-chat-interface.php';

// Register custom cron schedules early (before init).
add_filter( 'cron_schedules', [ 'ABW_Background_Jobs', 'add_cron_schedules' ] );

/**
 * Initialize plugin
 */
function abw_ai_init() {
	ABW_Admin::init();
	ABW_Abilities_Registration::init();
	ABW_Background_Jobs::init();
	ABW_Chat_Interface::init();
	ABW_AI_Workflows::init();
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

	// Create background jobs table.
	ABW_Background_Jobs::create_table();

	// Create debug log directory so it's ready when debug mode is enabled.
	ABW_Debug_Log::create_log_dir();
}
register_activation_hook( __FILE__, 'abw_ai_activate' );

/**
 * Deactivation hook
 */
function abw_ai_deactivate() {
	// Clear background jobs cron events.
	ABW_Background_Jobs::deactivate();
}
register_deactivation_hook( __FILE__, 'abw_ai_deactivate' );

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

	// Drop background jobs table and clean up.
	ABW_Background_Jobs::drop_table();
}
register_uninstall_hook( __FILE__, 'abw_ai_uninstall' );

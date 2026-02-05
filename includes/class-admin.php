<?php
/**
 * Admin Interface
 *
 * @package ABW_AI_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Admin
 *
 * Handles admin UI for token management and settings
 */
class ABW_Admin {

	/**
	 * Initialize admin
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Add admin menu
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'ABW-AI', 'abw-ai' ),
			__( 'ABW-AI', 'abw-ai' ),
			'use_abw',
			'ayu-ai',
			[ __CLASS__, 'render_main_page' ],
			'dashicons-admin-generic',
			30
		);

		add_submenu_page(
			'ayu-ai',
			__( 'Settings', 'abw-ai' ),
			__( 'Settings', 'abw-ai' ),
			'manage_options',
			'ayu-ai-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue admin scripts
	 */
	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'ayu-ai' ) === false ) {
			return;
		}

		wp_enqueue_style( 'ayu-admin', ABW_URL . 'assets/admin.css', [], ABW_VERSION );
		wp_enqueue_script( 'ayu-admin', ABW_URL . 'assets/admin.js', [ 'jquery' ], ABW_VERSION, true );
		wp_localize_script( 'ayu-admin', 'ayuAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ayu-admin' ),
		] );
	}

	/**
	 * Render main page
	 */
	public static function render_main_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ABW-AI Elementor', 'abw-ai' ); ?></h1>
			<div class="ayu-welcome">
				<h2><?php esc_html_e( 'Welcome to ABW-AI', 'abw-ai' ); ?></h2>
				<p><?php esc_html_e( 'ABW-AI is an open-source AI assistant for WordPress that works with MCP-compatible clients like Cursor.', 'abw-ai' ); ?></p>
				<h3><?php esc_html_e( 'Quick Start', 'abw-ai' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Go to Tokens page to create a Personal Access Token', 'abw-ai' ); ?></li>
					<li><?php esc_html_e( 'Configure your MCP client (Cursor) with the token', 'abw-ai' ); ?></li>
					<li><?php esc_html_e( 'Start using ABW-AI tools in Cursor', 'abw-ai' ); ?></li>
				</ol>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ayu-ai-tokens' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Create Token', 'abw-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ayu-ai-settings' ) ); ?>" class="button">
						<?php esc_html_e( 'Settings', 'abw-ai' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page() {
		// Handle form submission
		if ( isset( $_POST['abw_save_settings'] ) && check_admin_referer( 'abw_settings', 'abw_settings_nonce' ) ) {
			// General settings
			update_option( 'abw_rate_limit', isset( $_POST['abw_rate_limit'] ) ? (int) $_POST['abw_rate_limit'] : 100 );
			update_option( 'abw_cors_origins', isset( $_POST['abw_cors_origins'] ) ? sanitize_textarea_field( $_POST['abw_cors_origins'] ) : '' );
			
			// AI settings
			update_option( 'abw_ai_provider', isset( $_POST['abw_ai_provider'] ) ? sanitize_text_field( $_POST['abw_ai_provider'] ) : 'openai' );
			update_option( 'abw_chat_enabled', isset( $_POST['abw_chat_enabled'] ) ? true : false );
			
			// API Keys (encrypt if ABW_AI_Router is available)
			if ( ! empty( $_POST['abw_openai_api_key'] ) ) {
				$key = sanitize_text_field( $_POST['abw_openai_api_key'] );
				if ( class_exists( 'ABW_AI_Router' ) && method_exists( 'ABW_AI_Router', 'encrypt' ) ) {
					$key = ABW_AI_Router::encrypt( $key );
				}
				update_option( 'abw_openai_api_key', $key );
			}
			if ( ! empty( $_POST['abw_anthropic_api_key'] ) ) {
				$key = sanitize_text_field( $_POST['abw_anthropic_api_key'] );
				if ( class_exists( 'ABW_AI_Router' ) && method_exists( 'ABW_AI_Router', 'encrypt' ) ) {
					$key = ABW_AI_Router::encrypt( $key );
				}
				update_option( 'abw_anthropic_api_key', $key );
			}
			
			// Custom provider
			update_option( 'abw_custom_endpoint', isset( $_POST['abw_custom_endpoint'] ) ? esc_url_raw( $_POST['abw_custom_endpoint'] ) : '' );
			update_option( 'abw_custom_model', isset( $_POST['abw_custom_model'] ) ? sanitize_text_field( $_POST['abw_custom_model'] ) : '' );
			if ( ! empty( $_POST['abw_custom_api_key'] ) ) {
				$key = sanitize_text_field( $_POST['abw_custom_api_key'] );
				if ( class_exists( 'ABW_AI_Router' ) && method_exists( 'ABW_AI_Router', 'encrypt' ) ) {
					$key = ABW_AI_Router::encrypt( $key );
				}
				update_option( 'abw_custom_api_key', $key );
			}
			
			// Sidebar settings
			update_option( 'abw_sidebar_default_state', isset( $_POST['abw_sidebar_default_state'] ) ? sanitize_text_field( $_POST['abw_sidebar_default_state'] ) : 'closed' );
			update_option( 'abw_sidebar_default_width', isset( $_POST['abw_sidebar_default_width'] ) ? absint( $_POST['abw_sidebar_default_width'] ) : 370 );
			
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'abw-ai' ) . '</p></div>';
		}

		$current_provider = get_option( 'abw_ai_provider', 'openai' );
		$chat_enabled = get_option( 'abw_chat_enabled', true );
		$has_openai_key = ! empty( get_option( 'abw_openai_api_key', '' ) );
		$has_anthropic_key = ! empty( get_option( 'abw_anthropic_api_key', '' ) );
		$has_custom_key = ! empty( get_option( 'abw_custom_api_key', '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ABW-AI Settings', 'abw-ai' ); ?></h1>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'abw_settings', 'abw_settings_nonce' ); ?>
				
				<h2><?php esc_html_e( 'AI Chat Settings', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_chat_enabled"><?php esc_html_e( 'Enable Chat Widget', 'abw-ai' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="abw_chat_enabled" name="abw_chat_enabled" value="1" <?php checked( $chat_enabled ); ?> />
								<?php esc_html_e( 'Show floating chat widget in WordPress admin', 'abw-ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="abw_ai_provider"><?php esc_html_e( 'AI Provider', 'abw-ai' ); ?></label></th>
						<td>
							<select id="abw_ai_provider" name="abw_ai_provider">
								<option value="openai" <?php selected( $current_provider, 'openai' ); ?>>OpenAI (GPT-4)</option>
								<option value="anthropic" <?php selected( $current_provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
								<option value="custom" <?php selected( $current_provider, 'custom' ); ?>>Custom Provider</option>
							</select>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'OpenAI Configuration', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'abw-ai' ); ?></label></th>
						<td>
							<input type="password" id="abw_openai_api_key" name="abw_openai_api_key" class="regular-text" placeholder="<?php echo $has_openai_key ? '••••••••••••••••' : 'sk-...'; ?>" />
							<p class="description">
								<?php if ( $has_openai_key ) : ?>
									<span style="color: green;">✓ <?php esc_html_e( 'API key is configured', 'abw-ai' ); ?></span>
								<?php else : ?>
									<?php esc_html_e( 'Get your API key from', 'abw-ai' ); ?> <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Anthropic Configuration', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'abw-ai' ); ?></label></th>
						<td>
							<input type="password" id="abw_anthropic_api_key" name="abw_anthropic_api_key" class="regular-text" placeholder="<?php echo $has_anthropic_key ? '••••••••••••••••' : 'sk-ant-...'; ?>" />
							<p class="description">
								<?php if ( $has_anthropic_key ) : ?>
									<span style="color: green;">✓ <?php esc_html_e( 'API key is configured', 'abw-ai' ); ?></span>
								<?php else : ?>
									<?php esc_html_e( 'Get your API key from', 'abw-ai' ); ?> <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Custom Provider (Optional)', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_custom_api_key"><?php esc_html_e( 'API Key', 'abw-ai' ); ?></label></th>
						<td>
							<input type="password" id="abw_custom_api_key" name="abw_custom_api_key" class="regular-text" placeholder="<?php echo $has_custom_key ? '••••••••••••••••' : ''; ?>" />
							<p class="description">
								<?php if ( $has_custom_key ) : ?>
									<span style="color: green;">✓ <?php esc_html_e( 'API key is configured', 'abw-ai' ); ?></span>
								<?php else : ?>
									<?php esc_html_e( 'Enter your custom provider API key', 'abw-ai' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_custom_endpoint"><?php esc_html_e( 'API Endpoint', 'abw-ai' ); ?></label></th>
						<td>
							<input type="url" id="abw_custom_endpoint" name="abw_custom_endpoint" class="regular-text" value="<?php echo esc_attr( get_option( 'abw_custom_endpoint', '' ) ); ?>" placeholder="https://api.example.com/v1/chat/completions" />
							<p class="description">
								<?php esc_html_e( 'Full OpenAI-compatible API endpoint URL (e.g., https://api.minimax.io/v1/chat/completions)', 'abw-ai' ); ?>
								<br>
								<strong><?php esc_html_e( 'Note:', 'abw-ai' ); ?></strong> <?php esc_html_e( 'Include the full path including /chat/completions', 'abw-ai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_custom_model"><?php esc_html_e( 'Model Name', 'abw-ai' ); ?></label></th>
						<td>
							<input type="text" id="abw_custom_model" name="abw_custom_model" class="regular-text" value="<?php echo esc_attr( get_option( 'abw_custom_model', '' ) ); ?>" placeholder="gpt-4" />
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Sidebar Settings', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_sidebar_default_state"><?php esc_html_e( 'Default Sidebar State', 'abw-ai' ); ?></label></th>
						<td>
							<select id="abw_sidebar_default_state" name="abw_sidebar_default_state">
								<option value="closed" <?php selected( get_option( 'abw_sidebar_default_state', 'closed' ), 'closed' ); ?>><?php esc_html_e( 'Closed', 'abw-ai' ); ?></option>
								<option value="open" <?php selected( get_option( 'abw_sidebar_default_state', 'closed' ), 'open' ); ?>><?php esc_html_e( 'Open', 'abw-ai' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Whether the sidebar should be open or closed by default when users first see it.', 'abw-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_sidebar_default_width"><?php esc_html_e( 'Default Sidebar Width', 'abw-ai' ); ?></label></th>
						<td>
							<input type="number" id="abw_sidebar_default_width" name="abw_sidebar_default_width" value="<?php echo esc_attr( get_option( 'abw_sidebar_default_width', 370 ) ); ?>" min="310" max="550" step="10" />
							<span class="description">px</span>
							<p class="description"><?php esc_html_e( 'Default width of the sidebar in pixels. Users can resize it individually.', 'abw-ai' ); ?></p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'MCP API Settings', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_rate_limit"><?php esc_html_e( 'Rate Limit (requests/minute)', 'abw-ai' ); ?></label></th>
						<td>
							<input type="number" id="abw_rate_limit" name="abw_rate_limit" value="<?php echo esc_attr( get_option( 'abw_rate_limit', 100 ) ); ?>" min="1" max="1000" />
							<p class="description"><?php esc_html_e( 'Maximum requests per minute per token/IP address.', 'abw-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_cors_origins"><?php esc_html_e( 'Allowed CORS Origins', 'abw-ai' ); ?></label></th>
						<td>
							<textarea id="abw_cors_origins" name="abw_cors_origins" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'abw_cors_origins', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One origin per line (e.g., https://example.com). Leave empty for same-origin only.', 'abw-ai' ); ?></p>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Settings', 'abw-ai' ), 'primary', 'abw_save_settings' ); ?>
			</form>
		</div>
		<?php
	}
}


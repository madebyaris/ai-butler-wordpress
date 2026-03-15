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
		add_action( 'wp_ajax_abw_test_ai_connection', [ __CLASS__, 'ajax_test_ai_connection' ] );
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
			'abw-ai-settings',
			[ __CLASS__, 'render_settings_page' ]
		);

		// Background Jobs page with pending count badge.
		$pending_count = 0;
		if ( class_exists( 'ABW_Background_Jobs' ) ) {
			$counts = ABW_Background_Jobs::get_job_counts();
			$pending_count = $counts[ ABW_Background_Jobs::STATUS_PENDING ]
				+ $counts[ ABW_Background_Jobs::STATUS_PROCESSING ];
		}
		$badge = $pending_count > 0
			? ' <span class="awaiting-mod">' . $pending_count . '</span>'
			: '';

		add_submenu_page(
			'ayu-ai',
			__( 'Background Jobs', 'abw-ai' ),
			__( 'Background Jobs', 'abw-ai' ) . $badge,
			'manage_options',
			'abw-ai-jobs',
			[ __CLASS__, 'render_jobs_page' ]
		);

		add_submenu_page(
			'ayu-ai',
			__( 'Debug Log', 'abw-ai' ),
			__( 'Debug Log', 'abw-ai' ),
			'manage_options',
			'abw-ai-debug',
			[ __CLASS__, 'render_debug_page' ]
		);
	}

	/**
	 * Enqueue admin scripts
	 */
	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'ayu-ai' ) === false && strpos( $hook, 'abw-ai' ) === false ) {
			return;
		}

		wp_enqueue_style( 'abw-admin', ABW_URL . 'assets/admin.css', [], ABW_VERSION );
		wp_enqueue_script( 'abw-admin', ABW_URL . 'assets/admin.js', [ 'jquery' ], ABW_VERSION, true );

		// Additional localized data for the background jobs page using the abw-admin nonce.
		wp_localize_script( 'abw-admin', 'abwAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'abw-admin' ),
			'i18n'    => [
				'retry_confirm'  => __( 'Are you sure you want to retry this job?', 'abw-ai' ),
				'cancel_confirm' => __( 'Are you sure you want to cancel this job?', 'abw-ai' ),
				'retried'        => __( 'Job queued for retry.', 'abw-ai' ),
				'cancelled'      => __( 'Job cancelled.', 'abw-ai' ),
				'error'          => __( 'An error occurred. Please try again.', 'abw-ai' ),
				'testing'        => __( 'Testing...', 'abw-ai' ),
				'testing_message' => __( 'Checking saved provider settings...', 'abw-ai' ),
			],
		] );
	}

	/**
	 * Render main page
	 */
	public static function render_main_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ABW-AI', 'abw-ai' ); ?></h1>
			<div class="abw-welcome">
				<h2><?php esc_html_e( 'WordPress operations assistant', 'abw-ai' ); ?></h2>
				<p><?php esc_html_e( 'ABW-AI lives inside wp-admin and the block editor so you can manage content, run site operations, and launch longer background tasks without leaving WordPress.', 'abw-ai' ); ?></p>
				<div class="abw-admin-card-grid">
					<div class="abw-admin-card">
						<h3><?php esc_html_e( '1. Configure a provider', 'abw-ai' ); ?></h3>
						<p><?php esc_html_e( 'Add your OpenAI, Anthropic, or OpenAI-compatible API credentials and test the connection from Settings.', 'abw-ai' ); ?></p>
					</div>
					<div class="abw-admin-card">
						<h3><?php esc_html_e( '2. Start in chat', 'abw-ai' ); ?></h3>
						<p><?php esc_html_e( 'Use the floating admin chat for site-wide tasks, or open the Gutenberg sidebar to edit a post with block-aware AI actions.', 'abw-ai' ); ?></p>
					</div>
					<div class="abw-admin-card">
						<h3><?php esc_html_e( '3. Review long jobs', 'abw-ai' ); ?></h3>
						<p><?php esc_html_e( 'Content generation and other long-running requests are queued automatically. Track them from the Background Jobs screen.', 'abw-ai' ); ?></p>
					</div>
				</div>
				<p class="abw-admin-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-settings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Open Settings', 'abw-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-jobs' ) ); ?>" class="button">
						<?php esc_html_e( 'View Background Jobs', 'abw-ai' ); ?>
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
			update_option( 'abw_debug_log', isset( $_POST['abw_debug_log'] ) );

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
			<div class="abw-welcome">
				<h2><?php esc_html_e( 'Quick start', 'abw-ai' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Choose your provider and save your API credentials.', 'abw-ai' ); ?></li>
					<li><?php esc_html_e( 'Use the Test connection button to verify the saved provider can answer requests.', 'abw-ai' ); ?></li>
					<li><?php esc_html_e( 'Open the ABW-AI chat from the admin bar or the Gutenberg sidebar to start working.', 'abw-ai' ); ?></li>
				</ol>
			</div>
			
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
						<th><label for="abw_debug_log"><?php esc_html_e( 'Debug Log', 'abw-ai' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="abw_debug_log" name="abw_debug_log" value="1" <?php checked( get_option( 'abw_debug_log', false ) ); ?> />
								<?php esc_html_e( 'Log chat requests, AI calls, and tool execution to wp-content/abw-ai-logs/ for debugging', 'abw-ai' ); ?>
							</label>
							<p class="description">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-debug' ) ); ?>"><?php esc_html_e( 'View Debug Log', 'abw-ai' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_ai_provider"><?php esc_html_e( 'AI Provider', 'abw-ai' ); ?></label></th>
						<td>
							<select id="abw_ai_provider" name="abw_ai_provider">
								<option value="openai" <?php selected( $current_provider, 'openai' ); ?>>OpenAI</option>
								<option value="anthropic" <?php selected( $current_provider, 'anthropic' ); ?>>Anthropic</option>
								<option value="custom" <?php selected( $current_provider, 'custom' ); ?>><?php esc_html_e( 'OpenAI-compatible', 'abw-ai' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'ABW-AI currently supports OpenAI, Anthropic, and any OpenAI-compatible endpoint such as MiniMax.', 'abw-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Connection Check', 'abw-ai' ); ?></th>
						<td>
							<button type="button" class="button" id="abw-test-connection"><?php esc_html_e( 'Test connection', 'abw-ai' ); ?></button>
							<span id="abw-test-connection-status" class="abw-connection-status" aria-live="polite"></span>
							<p class="description"><?php esc_html_e( 'Uses the currently saved provider settings. Save changes first if you edited credentials on this page.', 'abw-ai' ); ?></p>
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
				
				<h2><?php esc_html_e( 'Advanced API Settings', 'abw-ai' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="abw_rate_limit"><?php esc_html_e( 'Rate Limit (requests/minute)', 'abw-ai' ); ?></label></th>
						<td>
							<input type="number" id="abw_rate_limit" name="abw_rate_limit" value="<?php echo esc_attr( get_option( 'abw_rate_limit', 100 ) ); ?>" min="1" max="1000" />
							<p class="description"><?php esc_html_e( 'Reserved for external integrations and future API surfaces.', 'abw-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="abw_cors_origins"><?php esc_html_e( 'Allowed CORS Origins', 'abw-ai' ); ?></label></th>
						<td>
							<textarea id="abw_cors_origins" name="abw_cors_origins" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'abw_cors_origins', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One origin per line for any external clients you allow to call future API endpoints. Leave empty for same-origin only.', 'abw-ai' ); ?></p>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Settings', 'abw-ai' ), 'primary', 'abw_save_settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Background Jobs admin page.
	 */
	public static function render_jobs_page() {
		if ( ! class_exists( 'ABW_Background_Jobs' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Background Jobs', 'abw-ai' ) . '</h1>';
			echo '<p>' . esc_html__( 'Background Jobs system is not available.', 'abw-ai' ) . '</p></div>';
			return;
		}

		$counts = ABW_Background_Jobs::get_job_counts();
		$jobs   = ABW_Background_Jobs::get_recent_jobs( 50 );
		$total  = array_sum( $counts );

		$status_labels = [
			ABW_Background_Jobs::STATUS_PENDING    => __( 'Pending', 'abw-ai' ),
			ABW_Background_Jobs::STATUS_PROCESSING => __( 'Processing', 'abw-ai' ),
			ABW_Background_Jobs::STATUS_COMPLETED  => __( 'Completed', 'abw-ai' ),
			ABW_Background_Jobs::STATUS_FAILED     => __( 'Failed', 'abw-ai' ),
			ABW_Background_Jobs::STATUS_CANCELLED  => __( 'Cancelled', 'abw-ai' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Background Jobs', 'abw-ai' ); ?></h1>

			<div class="abw-jobs-summary">
				<div class="abw-jobs-stat abw-jobs-stat-total">
					<span class="abw-jobs-stat-number"><?php echo esc_html( $total ); ?></span>
					<span class="abw-jobs-stat-label"><?php esc_html_e( 'Total', 'abw-ai' ); ?></span>
				</div>
				<div class="abw-jobs-stat abw-jobs-stat-pending">
					<span class="abw-jobs-stat-number"><?php echo esc_html( $counts[ ABW_Background_Jobs::STATUS_PENDING ] ); ?></span>
					<span class="abw-jobs-stat-label"><?php esc_html_e( 'Pending', 'abw-ai' ); ?></span>
				</div>
				<div class="abw-jobs-stat abw-jobs-stat-processing">
					<span class="abw-jobs-stat-number"><?php echo esc_html( $counts[ ABW_Background_Jobs::STATUS_PROCESSING ] ); ?></span>
					<span class="abw-jobs-stat-label"><?php esc_html_e( 'Processing', 'abw-ai' ); ?></span>
				</div>
				<div class="abw-jobs-stat abw-jobs-stat-completed">
					<span class="abw-jobs-stat-number"><?php echo esc_html( $counts[ ABW_Background_Jobs::STATUS_COMPLETED ] ); ?></span>
					<span class="abw-jobs-stat-label"><?php esc_html_e( 'Completed', 'abw-ai' ); ?></span>
				</div>
				<div class="abw-jobs-stat abw-jobs-stat-failed">
					<span class="abw-jobs-stat-number"><?php echo esc_html( $counts[ ABW_Background_Jobs::STATUS_FAILED ] ); ?></span>
					<span class="abw-jobs-stat-label"><?php esc_html_e( 'Failed', 'abw-ai' ); ?></span>
				</div>
			</div>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="abw-empty-state">
					<div class="abw-empty-icon">
						<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
							<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
							<rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
							<path d="M9 14l2 2 4-4"></path>
						</svg>
					</div>
					<h2><?php esc_html_e( 'No Background Jobs Yet', 'abw-ai' ); ?></h2>
					<p><?php esc_html_e( 'When you ask the AI to create content, generate posts, or perform other long-running tasks, they will appear here.', 'abw-ai' ); ?></p>
				</div>
			<?php else : ?>
				<div class="abw-jobs-container" id="abw-jobs-table-container">
					<table class="abw-jobs-table">
						<thead>
							<tr>
								<th class="abw-col-id"><?php esc_html_e( 'ID', 'abw-ai' ); ?></th>
								<th class="abw-col-type"><?php esc_html_e( 'Job Type', 'abw-ai' ); ?></th>
								<th class="abw-col-user"><?php esc_html_e( 'User', 'abw-ai' ); ?></th>
								<th class="abw-col-status"><?php esc_html_e( 'Status', 'abw-ai' ); ?></th>
								<th class="abw-col-attempts"><?php esc_html_e( 'Attempts', 'abw-ai' ); ?></th>
								<th class="abw-col-time"><?php esc_html_e( 'Created', 'abw-ai' ); ?></th>
								<th class="abw-col-actions"><?php esc_html_e( 'Actions', 'abw-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $jobs as $job ) :
								$user_data = get_userdata( (int) $job->user_id );
								$user_name = $user_data ? $user_data->display_name : __( 'Unknown', 'abw-ai' );
								$status_class = 'abw-job-status-' . esc_attr( $job->status );
								?>
								<tr class="<?php echo esc_attr( $status_class ); ?>" data-job-id="<?php echo esc_attr( $job->id ); ?>">
									<td class="abw-col-id">#<?php echo esc_html( $job->id ); ?></td>
									<td class="abw-col-type">
										<span class="abw-tool-name"><?php echo esc_html( $job->job_type ); ?></span>
									</td>
									<td class="abw-col-user"><?php echo esc_html( $user_name ); ?></td>
									<td class="abw-col-status">
										<span class="abw-job-badge abw-job-badge-<?php echo esc_attr( $job->status ); ?>">
											<?php echo esc_html( $status_labels[ $job->status ] ?? $job->status ); ?>
										</span>
										<?php if ( ! empty( $job->error_message ) ) : ?>
											<span class="abw-job-error-hint" title="<?php echo esc_attr( $job->error_message ); ?>">&#9432;</span>
										<?php endif; ?>
									</td>
									<td class="abw-col-attempts">
										<?php echo esc_html( $job->attempts . '/' . $job->max_attempts ); ?>
									</td>
									<td class="abw-col-time">
										<span class="abw-time-primary"><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $job->created_at ) ) ); ?></span>
										<?php if ( $job->completed_at ) : ?>
											<span class="abw-time-secondary"><?php echo esc_html( human_time_diff( strtotime( $job->created_at ), strtotime( $job->completed_at ) ) ); ?></span>
										<?php endif; ?>
									</td>
									<td class="abw-col-actions">
										<?php if ( ABW_Background_Jobs::STATUS_FAILED === $job->status ) : ?>
											<button class="button button-small abw-retry-job" data-job-id="<?php echo esc_attr( $job->id ); ?>">
												<?php esc_html_e( 'Retry', 'abw-ai' ); ?>
											</button>
										<?php endif; ?>
										<?php if ( ABW_Background_Jobs::STATUS_PENDING === $job->status ) : ?>
											<button class="button button-small abw-cancel-job" data-job-id="<?php echo esc_attr( $job->id ); ?>">
												<?php esc_html_e( 'Cancel', 'abw-ai' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="abw-jobs-footer">
				<p class="abw-jobs-auto-refresh">
					<label>
						<input type="checkbox" id="abw-jobs-auto-refresh" checked />
						<?php esc_html_e( 'Auto-refresh every 5 seconds', 'abw-ai' ); ?>
					</label>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: PHP max execution time */
						esc_html__( 'Server max execution time: %s seconds', 'abw-ai' ),
						esc_html( (string) ini_get( 'max_execution_time' ) )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Debug Log page
	 */
	public static function render_debug_page() {
		$enabled = ABW_Debug_Log::is_enabled();
		$log_dir = ABW_Debug_Log::get_log_dir();
		$files   = ABW_Debug_Log::list_log_files();

		// Handle view/download.
		$view_file = isset( $_GET['view'] ) ? sanitize_file_name( wp_unslash( $_GET['view'] ) ) : '';
		if ( $view_file && current_user_can( 'manage_options' ) ) {
			$content = ABW_Debug_Log::get_log_content( $view_file );
			if ( is_wp_error( $content ) ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $content->get_error_message() ) . '</p></div></div>';
				return;
			}
			if ( isset( $_GET['download'] ) ) {
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . esc_attr( $view_file ) . '"' );
				header( 'Content-Length: ' . strlen( $content ) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $content;
				exit;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Debug Log:', 'abw-ai' ); ?> <?php echo esc_html( $view_file ); ?></h1>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-debug' ) ); ?>" class="button"><?php esc_html_e( '&larr; Back to Logs', 'abw-ai' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-debug&view=' . rawurlencode( $view_file ) . '&download=1' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Download', 'abw-ai' ); ?></a>
				</p>
				<pre style="background:#1e293b;color:#e2e8f0;padding:16px;overflow:auto;max-height:70vh;font-size:12px;line-height:1.5;"><?php echo esc_html( $content ); ?></pre>
			</div>
			<?php
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Debug Log', 'abw-ai' ); ?></h1>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'Debug logging is disabled. Enable it in', 'abw-ai' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-settings' ) ); ?>"><?php esc_html_e( 'ABW-AI Settings', 'abw-ai' ); ?></a>
						<?php esc_html_e( 'to record chat requests, AI calls, and tool execution.', 'abw-ai' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: Log directory path */
					esc_html__( 'Logs are saved to %s', 'abw-ai' ),
					'<code>' . esc_html( $log_dir ) . '</code>'
				);
				?>
			</p>

			<?php if ( empty( $files ) ) : ?>
				<p><?php esc_html_e( 'No log files yet. Send a chat message with debug mode enabled to create logs.', 'abw-ai' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'File', 'abw-ai' ); ?></th>
							<th><?php esc_html_e( 'Size', 'abw-ai' ); ?></th>
							<th><?php esc_html_e( 'Modified', 'abw-ai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'abw-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $f ) : ?>
							<tr>
								<td><code><?php echo esc_html( $f['name'] ); ?></code></td>
								<td><?php echo esc_html( $f['size'] ); ?></td>
								<td><?php echo esc_html( $f['date'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-debug&view=' . rawurlencode( $f['name'] ) ) ); ?>"><?php esc_html_e( 'View', 'abw-ai' ); ?></a>
									|
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=abw-ai-debug&view=' . rawurlencode( $f['name'] ) . '&download=1' ) ); ?>"><?php esc_html_e( 'Download', 'abw-ai' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Test the saved AI connection from the settings page.
	 */
	public static function ajax_test_ai_connection() {
		check_ajax_referer( 'abw-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$provider = ABW_AI_Router::get_provider();
		$response = ABW_AI_Router::chat(
			[
				[
					'role'    => 'user',
					'content' => 'Reply with the single word OK.',
				],
			],
			[],
			[
				'provider'   => $provider,
				'max_tokens' => 32,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ], 400 );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: 1: provider name, 2: model name */
					__( 'Connected successfully to %1$s using %2$s.', 'abw-ai' ),
					esc_html( ucfirst( $provider ) ),
					esc_html( ABW_AI_Router::get_model( $provider ) )
				),
			]
		);
	}
}


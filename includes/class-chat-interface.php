<?php
/**
 * Chat Interface Controller
 *
 * Handles the admin chat widget UI and AJAX endpoints for ABW-AI.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Chat_Interface
 *
 * Manages the floating chat widget in WordPress admin,
 * similar to Elementor's Angie AI assistant.
 */
class ABW_Chat_Interface {

	/**
	 * Initialize chat interface
	 */
	public static function init(): void {
		// Check if user has capability and chat is enabled
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		// Admin bar toggle (works everywhere admin bar shows)
		add_action( 'admin_bar_menu', [ __CLASS__, 'add_toggle_to_admin_bar' ], 999 );
		
		// Elementor editor fallback (admin_bar_menu doesn't fire there)
		add_action( 'elementor/editor/init', function () {
			add_action( 'wp_footer', [ __CLASS__, 'add_toggle_to_admin_bar' ] );
		} );

		// Sidebar HTML injection (admin + frontend + Elementor)
		add_action( 'in_admin_header', [ __CLASS__, 'inject_sidebar_html' ] );
		add_action( 'wp_head', [ __CLASS__, 'inject_sidebar_html' ] );
		add_action( 'elementor/editor/init', function () {
			add_action( 'wp_footer', [ __CLASS__, 'inject_sidebar_html' ] );
		} );

		// Enqueue assets
		add_action( 'admin_head', [ __CLASS__, 'enqueue_sidebar_css' ] );
		add_action( 'wp_head', [ __CLASS__, 'enqueue_sidebar_css' ] );
		add_action( 'elementor/editor/init', function () {
			add_action( 'wp_footer', [ __CLASS__, 'enqueue_sidebar_css' ] );
		} );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Block editor sidebar (PluginSidebar via React)
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );

		// AJAX handlers
		add_action( 'wp_ajax_abw_chat_message', [ __CLASS__, 'handle_chat_message' ] );
		add_action( 'wp_ajax_abw_agent_poll', [ __CLASS__, 'handle_agent_poll' ] );
		add_action( 'wp_ajax_abw_chat_history', [ __CLASS__, 'get_chat_history' ] );
		add_action( 'wp_ajax_abw_clear_chat', [ __CLASS__, 'clear_chat_history' ] );
	}

	/**
	 * Add toggle button to WordPress admin bar
	 *
	 * @param \WP_Admin_Bar|null $wp_admin_bar WordPress admin bar object (null when called from Elementor).
	 */
	public static function add_toggle_to_admin_bar( $wp_admin_bar ): void {
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() && ! $wp_admin_bar ) {
			return;
		}

		if ( $wp_admin_bar ) {
			// Standard WordPress admin bar
			$wp_admin_bar->add_node( [
				'id'    => 'abw-sidebar-toggle',
				'title' => '<span class="ab-icon dashicons dashicons-format-chat"></span> <span class="ab-label">' . esc_html__( 'ABW-AI', 'abw-ai' ) . '</span>',
				'href'  => '#',
				'meta'  => [
					'class' => 'abw-sidebar-toggle-item',
					'title' => __( 'Toggle ABW-AI Sidebar', 'abw-ai' ),
				],
			] );
		} else {
			// Elementor editor fallback - inject minimal element JavaScript needs
			echo '<div id="wp-admin-bar-abw-sidebar-toggle" class="abw-sidebar-toggle-item" style="display: none;">
				<a href="#" class="ab-item" title="' . esc_attr__( 'Toggle ABW-AI Sidebar', 'abw-ai' ) . '">
					<span class="ab-icon dashicons dashicons-format-chat"></span> <span class="ab-label">' . esc_html__( 'ABW-AI', 'abw-ai' ) . '</span>
				</a>
			</div>';
		}
	}

	/**
	 * Enqueue sidebar CSS
	 */
	public static function enqueue_sidebar_css(): void {
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		// Skip on block editor pages.
		if ( self::is_block_editor_screen() ) {
			return;
		}

		wp_enqueue_style(
			'abw-sidebar',
			ABW_URL . 'assets/sidebar.css',
			[],
			ABW_VERSION
		);
	}

	/**
	 * Enqueue chat widget assets
	 */
	public static function enqueue_assets(): void {
		// Check if user has capability
		if ( ! current_user_can( 'use_abw' ) ) {
			return;
		}

		// Check if chat is enabled
		if ( ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		// Skip on block editor pages - the native PluginSidebar handles its own styles.
		if ( self::is_block_editor_screen() ) {
			return;
		}

		wp_enqueue_style(
			'abw-chat-widget',
			ABW_URL . 'assets/chat-widget.css',
			[],
			ABW_VERSION
		);

		wp_enqueue_script(
			'abw-chat-widget',
			ABW_URL . 'assets/js/chat-widget.js',
			[ 'jquery' ],
			ABW_VERSION,
			true
		);

		// Tool results are useful for debugging, but noisy for normal usage.
		// Default: off. Enable explicitly via URL param (?abw_debug_tools=1) for admins.
		$debug_tool_results = false;
		if ( current_user_can( 'manage_options' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$debug_tool_results = isset( $_GET['abw_debug_tools'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['abw_debug_tools'] ) );
		}

		wp_localize_script( 'abw-chat-widget', 'abwChat', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'abw-chat' ),
			'userId'       => get_current_user_id(),
			'userName'     => wp_get_current_user()->display_name,
			'siteName'     => get_bloginfo( 'name' ),
			'currentPage'  => self::get_current_page_context(),
			'debugToolResults' => $debug_tool_results,
			'adminJobsUrl' => admin_url( 'admin.php?page=abw-ai-jobs' ),
			'i18n'         => [
				'title'       => __( 'ABW-AI Butler', 'abw-ai' ),
				'placeholder' => __( 'Ask me anything about your WordPress site...', 'abw-ai' ),
				'send'        => __( 'Send', 'abw-ai' ),
				'thinking'    => __( 'Thinking...', 'abw-ai' ),
				'error'       => __( 'An error occurred. Please try again.', 'abw-ai' ),
				'noApiKey'    => __( 'Please configure your AI API key in ABW-AI settings.', 'abw-ai' ),
				'clear'       => __( 'Clear chat', 'abw-ai' ),
				'minimize'    => __( 'Minimize', 'abw-ai' ),
				'close'       => __( 'Close', 'abw-ai' ),
			],
		] );
	}

	/**
	 * Enqueue block editor sidebar assets (React PluginSidebar).
	 *
	 * Fires on `enqueue_block_editor_assets` so the script only loads
	 * inside the Gutenberg editor.
	 */
	public static function enqueue_block_editor_assets(): void {
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		$asset_file = ABW_PATH . 'build/editor-sidebar.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return; // Build not yet run.
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'abw-editor-sidebar',
			ABW_URL . 'build/editor-sidebar.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'abw-editor-sidebar',
			ABW_URL . 'build/editor-sidebar.css',
			[],
			$asset['version']
		);

		// Provide chat config to the editor sidebar script.
		global $post;
		wp_localize_script( 'abw-editor-sidebar', 'abwEditorChat', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'abw-chat' ),
			'userId'   => get_current_user_id(),
			'userName' => wp_get_current_user()->display_name,
			'siteName' => get_bloginfo( 'name' ),
			'provider' => ucfirst( ABW_AI_Router::get_provider() ),
			'postId'   => $post ? $post->ID : 0,
			'postType' => $post ? $post->post_type : 'post',
		] );
	}

	/**
	 * Get current page context for AI
	 *
	 * @return array
	 */
	private static function get_current_page_context(): array {
		global $pagenow, $post;

		$context = [
			'page'      => $pagenow,
			'screen'    => '',
			'post_id'   => 0,
			'post_type' => '',
		];

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$context['screen'] = $screen->id;
				$context['post_type'] = $screen->post_type ?? '';
			}
		}

		if ( $post ) {
			$context['post_id'] = $post->ID;
		}

		return $context;
	}

	/**
	 * Check if the current screen is the block editor.
	 *
	 * When the block editor is active, we use the native PluginSidebar
	 * instead of the CSS-injected sidebar.
	 *
	 * @return bool
	 */
	private static function is_block_editor_screen(): bool {
		global $pagenow;

		// Only applies in admin context.
		if ( ! is_admin() ) {
			return false;
		}

		// Block editor loads on post.php and post-new.php.
		if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
			return false;
		}

		// Check if the block editor JS was actually built (build dir exists).
		if ( ! file_exists( ABW_PATH . 'build/editor-sidebar.asset.php' ) ) {
			return false; // Fallback to regular sidebar if build is missing.
		}

		// Check if the block editor is enabled for this post type.
		if ( function_exists( 'use_block_editor_for_post_type' ) ) {
			$post_type = '';
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->post_type ) ) {
					$post_type = $screen->post_type;
				}
			}
			if ( empty( $post_type ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
			}
			return use_block_editor_for_post_type( $post_type );
		}

		return true;
	}

	/**
	 * Inject sidebar HTML container (Angie-style)
	 */
	public static function inject_sidebar_html(): void {
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
			return;
		}

		// Skip on block editor pages - the native PluginSidebar is used there.
		if ( self::is_block_editor_screen() ) {
			return;
		}

		$is_rtl = is_rtl();
		$dir_attr = $is_rtl ? 'dir="rtl"' : 'dir="ltr"';

		$default_state = get_option( 'abw_sidebar_default_state', 'closed' );
		$default_width = get_option( 'abw_sidebar_default_width', 370 );
		$is_open = 'open' === $default_state;
		$hidden = $is_open ? 'false' : 'true';

		$has_api_key = ! empty( ABW_AI_Router::get_api_key( ABW_AI_Router::get_provider() ) );

		$html = "
		<!-- ABW-AI Sidebar -->
		<div id='abw-body-top-padding'></div>
		<script>
			// Apply initial state to prevent flash - no transition on load
			(function() {
				const SIDE_MENU_WIDTH = 40;
				const MIN_WIDTH = 310 + SIDE_MENU_WIDTH;
				const MAX_WIDTH = 550 + SIDE_MENU_WIDTH;
				const DEFAULT_WIDTH = " . ( $default_width + 40 ) . ";

				var defaultState = '" . esc_js( $default_state ) . "';
				var savedState = null;
				var savedWidth = DEFAULT_WIDTH;
				
				// Check localStorage for saved state and width
				try {
					savedState = localStorage.getItem('abw_sidebar_state');
					var widthStr = localStorage.getItem('abw_sidebar_width');
					if (widthStr) {
						var width = parseInt(widthStr, 10);
						if (width >= MIN_WIDTH && width <= MAX_WIDTH) {
							savedWidth = width;
						}
					}
				} catch (e) {
					// localStorage not available
				}
				
				document.documentElement.style.setProperty('--abw-sidebar-width', savedWidth + 'px');
				
				const isIframe = window.self !== window.top;
				
				var shouldBeOpen = (savedState || defaultState) === 'open' && !isIframe;

				function applyAbwClasses() {
					const topPadding = document.getElementById('abw-body-top-padding');
					if (topPadding && document.body) {
						document.body.insertBefore(topPadding, document.body.firstChild);
					}

					if (shouldBeOpen && document.body) {
						document.documentElement.classList.add('abw-sidebar-active');
						document.body.classList.add('abw-sidebar-active');
					}
				}

				// Apply immediately if DOM is ready, otherwise wait
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', applyAbwClasses);
				} else {
					applyAbwClasses();
				}
			})();
		</script>

		<div id='abw-wrapper'></div>
		<div 
			id='abw-sidebar-container'
			role='complementary'
			aria-label='ABW-AI Butler'
			aria-hidden='{$hidden}'
			tabindex='-1'
			{$dir_attr}
			data-has-key='" . ( $has_api_key ? '1' : '0' ) . "'>
			
			<!-- Loading state -->
			<div id='abw-sidebar-loading' aria-live='polite' class='abw-sr-only'>
			</div>
			
			<!-- Sidebar Content -->
			<div id='abw-sidebar-content'>
				<!-- Header -->
				<div class='abw-chat-header'>
					<div class='abw-chat-header-title'>
						<span class='abw-chat-logo'><svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'/></svg></span>
						<span>" . esc_html__( 'ABW-AI Butler', 'abw-ai' ) . "</span>
					</div>
					<div class='abw-chat-header-actions'>
						<button class='abw-chat-action' id='abw-clear-chat' title='" . esc_attr__( 'Clear chat', 'abw-ai' ) . "'>
							<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'>
								<polyline points='3 6 5 6 21 6'></polyline>
								<path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'></path>
							</svg>
						</button>
					</div>
				</div>

				<!-- Messages Container -->
				<div class='abw-chat-messages' id='abw-chat-messages'>
					" . ( ! $has_api_key ? "
						<div class='abw-chat-setup-notice'>
							<p>" . esc_html__( 'Welcome to ABW-AI! To get started, please configure your AI provider API key.', 'abw-ai' ) . "</p>
							<a href='" . esc_url( admin_url( 'admin.php?page=abw-ai-settings' ) ) . "' class='button button-primary'>
								" . esc_html__( 'Configure API Key', 'abw-ai' ) . "
							</a>
						</div>
					" : "
						<div class='abw-chat-welcome'>
							<p>" . esc_html__( 'Hi! I\'m your Advanced Butler for WordPress. How can I help you today?', 'abw-ai' ) . "</p>
							<div class='abw-chat-suggestions'>
								<button class='abw-suggestion' data-prompt='" . esc_attr__( 'List all my posts', 'abw-ai' ) . "'>" . esc_html__( 'List all posts', 'abw-ai' ) . "</button>
								<button class='abw-suggestion' data-prompt='" . esc_attr__( 'Create a new blog post about', 'abw-ai' ) . "'>" . esc_html__( 'Create a post', 'abw-ai' ) . "</button>
								<button class='abw-suggestion' data-prompt='" . esc_attr__( 'Show me site health info', 'abw-ai' ) . "'>" . esc_html__( 'Site health', 'abw-ai' ) . "</button>
								<button class='abw-suggestion' data-prompt='" . esc_attr__( 'List installed plugins', 'abw-ai' ) . "'>" . esc_html__( 'List plugins', 'abw-ai' ) . "</button>
							</div>
						</div>
					" ) . "
				</div>

				<!-- Input Area -->
				<div class='abw-chat-input-area'>
					<textarea 
						id='abw-chat-input' 
						class='abw-chat-input' 
						placeholder='" . esc_attr__( 'Ask me anything...', 'abw-ai' ) . "'
						rows='1'
						" . ( ! $has_api_key ? 'disabled' : '' ) . "
					></textarea>
					<button 
						id='abw-chat-send' 
						class='abw-chat-send'
						" . ( ! $has_api_key ? 'disabled' : '' ) . "
					>
						<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'>
							<line x1='22' y1='2' x2='11' y2='13'></line>
							<polygon points='22 2 15 22 11 13 2 9 22 2'></polygon>
						</svg>
					</button>
				</div>

				<!-- Footer -->
				<div class='abw-chat-footer'>
					<span class='abw-chat-provider'>
						" . esc_html( ucfirst( ABW_AI_Router::get_provider() ) ) . "
					</span>
				</div>
			</div>
		</div>
		";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Max iterations for agentic ReACT loop.
	 */
	const AGENTIC_MAX_ITERATIONS = 5;

	/**
	 * Transient TTL for agentic sessions (10 minutes).
	 */
	const AGENTIC_SESSION_TTL = 600;

	/**
	 * Handle chat message AJAX request
	 */
	public static function handle_chat_message(): void {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$message        = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$context        = isset( $_POST['context'] ) ? json_decode( stripslashes( $_POST['context'] ), true ) : [];
		$editor_context = isset( $_POST['editor_context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['editor_context'] ) ) : '';

		if ( empty( $message ) ) {
			wp_send_json_error( [ 'message' => __( 'Message cannot be empty.', 'abw-ai' ) ] );
		}

		ABW_Debug_Log::log( 'chat_request', [ 'message' => $message, 'context' => $context, 'has_editor_context' => ! empty( $editor_context ) ], 'Chat message received' );

		$is_block_editor = ! empty( $editor_context );
		$user_id        = get_current_user_id();
		$history        = self::get_user_history( $user_id );

		$system_prompt = $is_block_editor
			? ABW_AI_Router::get_system_prompt( $editor_context )
			: ABW_AI_Router::get_system_prompt();

		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
		];

		$recent_history = array_slice( $history, -10 );
		foreach ( $recent_history as $entry ) {
			$messages[] = [
				'role'    => $entry['role'],
				'content' => $entry['content'],
			];
		}

		$user_message = $message;
		if ( ! empty( $context ) ) {
			$user_message .= "\n\n[Context: Current page: " . ( $context['screen'] ?? 'unknown' ) . "]";
		}
		$messages[] = [ 'role' => 'user', 'content' => $user_message ];

		$tools = ABW_AI_Router::get_available_tools();
		$provider = ABW_AI_Router::get_provider();

		// Run first iteration.
		$result = self::run_agentic_iteration( $messages, $tools, $provider, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$response         = $result['response'];
		$new_messages      = $result['messages'];
		$steps             = $result['steps'];
		$block_actions     = $result['block_actions'];
		$background_jobs   = $result['background_jobs'];
		$iteration         = $result['iteration'];
		$has_more_tools    = ! empty( $response['tool_calls'] );
		$at_iteration_limit = $iteration >= self::AGENTIC_MAX_ITERATIONS;

		if ( ! $has_more_tools || $at_iteration_limit ) {
			// Done in one or more rounds - return final response.
			$final_response = self::strip_unsolicited_summary( $response['content'], $message );
			$all_tool_results = $result['all_tool_results'] ?? [];
			if ( ( $final_response === '' || ! trim( $final_response ) ) && ! empty( $all_tool_results ) ) {
				$final_response = self::format_tool_results_for_response( $all_tool_results );
			}
			self::save_to_history( $user_id, 'user', $message );
			self::save_to_history( $user_id, 'assistant', $final_response );
			if ( isset( $response['usage'] ) ) {
				ABW_AI_Router::track_usage( $provider, $response['usage'] );
			}

			ABW_Debug_Log::log( 'chat_done', [ 'iteration' => $iteration, 'steps_count' => count( $steps ), 'response_preview' => substr( $final_response, 0, 200 ) ], 'Chat completed in first request' );

			$payload = [
				'status'       => 'done',
				'response'     => $final_response,
				'steps'        => $steps,
				'tool_results' => $result['all_tool_results'] ?? [],
			];
			if ( ! empty( $block_actions ) ) {
				$payload['block_actions'] = $block_actions;
			}
			if ( ! empty( $background_jobs ) ) {
				$payload['background_job'] = $background_jobs[0];
			}
			wp_send_json_success( $payload );
		}

		// Create session for polling.
		$session_id = 'abw_' . wp_generate_password( 32, false );
		$session = [
			'status'           => 'thinking',
			'messages'         => $new_messages,
			'tools'            => $tools,
			'iteration'        => $iteration,
			'max_iterations'   => self::AGENTIC_MAX_ITERATIONS,
			'steps'            => $steps,
			'block_actions'    => $block_actions,
			'final_response'   => null,
			'user_id'          => $user_id,
			'message'          => $message,
			'editor_context'   => $editor_context,
			'provider'         => $provider,
			'background_jobs'  => $background_jobs,
			'all_tool_results' => $result['all_tool_results'] ?? [],
		];
		set_transient( 'abw_agent_' . $session_id, $session, self::AGENTIC_SESSION_TTL );
		self::save_to_history( $user_id, 'user', $message );

		ABW_Debug_Log::log( 'chat_thinking', [ 'session_id' => $session_id, 'iteration' => $iteration, 'steps_count' => count( $steps ), 'tool_calls_count' => count( $response['tool_calls'] ?? [] ) ], 'Session created, polling started' );

		wp_send_json_success( [
			'session_id' => $session_id,
			'status'     => 'thinking',
			'steps'      => $steps,
		] );
	}

	/**
	 * Handle agentic polling AJAX request
	 */
	public static function handle_agent_poll(): void {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( empty( $session_id ) || strpos( $session_id, 'abw_' ) !== 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid session.', 'abw-ai' ) ] );
		}

		ABW_Debug_Log::log( 'agent_poll', [ 'session_id' => $session_id ], 'Agent poll request' );

		$session = get_transient( 'abw_agent_' . $session_id );
		if ( ! is_array( $session ) ) {
			wp_send_json_error( [ 'message' => __( 'Session expired.', 'abw-ai' ) ] );
		}

		if ( (int) ( $session['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		if ( ( $session['status'] ?? '' ) !== 'thinking' ) {
			ABW_Debug_Log::log( 'agent_poll_cached', [ 'session_id' => $session_id, 'status' => $session['status'] ?? '' ], 'Returning cached result (already done)' );
			wp_send_json_success( [
				'status'   => $session['status'],
				'response' => $session['final_response'] ?? '',
				'steps'    => $session['steps'] ?? [],
				'block_actions' => $session['block_actions'] ?? [],
				'background_job' => $session['background_jobs'][0] ?? null,
			] );
			return;
		}

		$messages  = $session['messages'];
		$tools     = $session['tools'];
		$provider  = $session['provider'];
		$user_id   = (int) $session['user_id'];

		$result = self::run_agentic_iteration( $messages, $tools, $provider, $user_id );

		if ( is_wp_error( $result ) ) {
			$session['status'] = 'error';
			$session['final_response'] = $result->get_error_message();
			set_transient( 'abw_agent_' . $session_id, $session, self::AGENTIC_SESSION_TTL );
			ABW_Debug_Log::log( 'agent_error', [ 'session_id' => $session_id, 'error' => $result->get_error_message() ], 'Agent iteration failed' );
			wp_send_json_success( [
				'status'   => 'error',
				'response' => $result->get_error_message(),
				'steps'    => $session['steps'],
			] );
			return;
		}

		$session['messages']         = $result['messages'];
		$session['iteration']         = ( $session['iteration'] ?? 1 ) + 1;
		$session['steps']            = array_merge( $session['steps'] ?? [], $result['steps'] );
		$session['block_actions']      = array_merge( $session['block_actions'] ?? [], $result['block_actions'] );
		$session['background_jobs']   = array_merge( $session['background_jobs'] ?? [], $result['background_jobs'] );
		$session['all_tool_results']   = array_merge( $session['all_tool_results'] ?? [], $result['all_tool_results'] ?? [] );

		$response          = $result['response'];
		$has_more_tools     = ! empty( $response['tool_calls'] );
		$at_iteration_limit = $result['iteration'] >= self::AGENTIC_MAX_ITERATIONS;

		if ( ! $has_more_tools || $at_iteration_limit ) {
			$session['status'] = 'done';
			$final = self::strip_unsolicited_summary( $response['content'], $session['message'] ?? '' );
			$all_tool_results = $session['all_tool_results'] ?? [];
			if ( ( $final === '' || ! trim( $final ) ) && ! empty( $all_tool_results ) ) {
				$final = self::format_tool_results_for_response( $all_tool_results );
			}
			$session['final_response'] = $final;
			self::save_to_history( $user_id, 'assistant', $final );
			if ( isset( $response['usage'] ) ) {
				ABW_AI_Router::track_usage( $provider, $response['usage'] );
			}
			delete_transient( 'abw_agent_' . $session_id );
			ABW_Debug_Log::log( 'agent_done', [ 'session_id' => $session_id, 'iteration' => $session['iteration'], 'steps_count' => count( $session['steps'] ?? [] ), 'response_preview' => substr( $final, 0, 200 ) ], 'Agent completed via poll' );
			$payload = [
				'status'       => 'done',
				'response'     => $final,
				'steps'        => $session['steps'],
				'tool_results' => $session['all_tool_results'] ?? [],
			];
			if ( ! empty( $session['block_actions'] ) ) {
				$payload['block_actions'] = $session['block_actions'];
			}
			if ( ! empty( $session['background_jobs'] ) ) {
				$payload['background_job'] = $session['background_jobs'][0];
			}
			wp_send_json_success( $payload );
			return;
		}

		set_transient( 'abw_agent_' . $session_id, $session, self::AGENTIC_SESSION_TTL );

		ABW_Debug_Log::log( 'agent_thinking', [ 'session_id' => $session_id, 'iteration' => $session['iteration'], 'tool_calls_count' => count( $response['tool_calls'] ?? [] ) ], 'Agent continues thinking' );

		wp_send_json_success( [
			'session_id' => $session_id,
			'status'     => 'thinking',
			'steps'      => $session['steps'],
		] );
	}

	/**
	 * Run one agentic iteration: AI call + tool execution.
	 *
	 * @param array  $messages Messages for AI.
	 * @param array  $tools    Available tools.
	 * @param string $provider AI provider.
	 * @param int    $user_id  User ID.
	 * @return array|WP_Error Keys: response, messages, steps, block_actions, background_jobs, iteration, all_tool_results.
	 */
	private static function run_agentic_iteration( array $messages, array $tools, string $provider, int $user_id ) {
		ABW_Debug_Log::log( 'ai_iteration_start', [ 'provider' => $provider, 'messages_count' => count( $messages ), 'tools_count' => count( $tools ) ], 'Running AI iteration' );

		$options  = [ 'provider' => $provider ];
		$response = ABW_AI_Router::chat( $messages, $tools, $options );

		if ( is_wp_error( $response ) ) {
			ABW_Debug_Log::log( 'ai_error', [ 'error' => $response->get_error_message() ], 'AI call failed' );
			return $response;
		}

		ABW_Debug_Log::log( 'ai_response', [
			'content_preview' => substr( (string) ( $response['content'] ?? '' ), 0, 300 ),
			'tool_calls_count' => count( $response['tool_calls'] ?? [] ),
			'tool_call_names'  => array_column( $response['tool_calls'] ?? [], 'name' ),
		], 'AI response received' );

		$steps           = [];
		$block_actions    = [];
		$background_jobs  = [];
		$all_tool_results = [];
		$iteration        = 1;

		if ( ! empty( $response['content'] ) ) {
			$steps[] = [ 'type' => 'thinking', 'content' => trim( $response['content'] ) ];
		}

		if ( empty( $response['tool_calls'] ) ) {
			return [
				'response'          => $response,
				'messages'          => $messages,
				'steps'             => $steps,
				'block_actions'     => [],
				'background_jobs'   => [],
				'iteration'         => 1,
				'all_tool_results'  => [],
			];
		}

		$tool_results_for_ai = [];
		$has_background = self::has_background_tool_call( $response['tool_calls'] );
		$queue_background = $has_background && class_exists( 'ABW_Background_Jobs' );

		if ( $queue_background ) {
			ABW_Debug_Log::log( 'tool_queue_background', [ 'tool_names' => array_column( $response['tool_calls'], 'name' ) ], 'Queuing long-running tool(s) as background job' );
			$job_result = self::queue_background_job( $user_id, $messages[ array_key_last( $messages ) ]['content'] ?? '', $messages, $tools, $response );
			if ( ! is_wp_error( $job_result ) ) {
				$bg_result = [
					'status'    => 'queued',
					'message'   => __( 'Task queued for background processing.', 'abw-ai' ),
					'job_token' => $job_result['job_token'],
					'job_type'  => $job_result['job_type'],
				];
				$background_jobs[] = $job_result;
				foreach ( $response['tool_calls'] as $tc ) {
					$steps[] = [ 'type' => 'tool_call', 'name' => $tc['name'], 'args' => $tc['arguments'] ?? [] ];
					$steps[] = [ 'type' => 'tool_result', 'name' => $tc['name'], 'result' => $bg_result ];
					$tool_results_for_ai[] = [
						'id'       => $tc['id'],
						'name'     => $tc['name'],
						'result'   => $bg_result,
						'is_error' => false,
					];
					$all_tool_results[] = [ 'tool' => $tc['name'], 'result' => $bg_result ];
				}
				$to_append   = ABW_AI_Router::build_tool_result_messages( $response, $tool_results_for_ai );
				$new_messages = array_merge( $messages, $to_append );
				return [
					'response'         => $response,
					'messages'         => $new_messages,
					'steps'            => $steps,
					'block_actions'    => [],
					'background_jobs'   => $background_jobs,
					'iteration'         => 1,
					'all_tool_results' => $all_tool_results,
				];
			}
		}

		foreach ( $response['tool_calls'] as $tool_call ) {
			$steps[] = [ 'type' => 'tool_call', 'name' => $tool_call['name'], 'args' => $tool_call['arguments'] ?? [] ];
			ABW_Debug_Log::log( 'tool_call', [ 'name' => $tool_call['name'], 'arguments' => $tool_call['arguments'] ?? [] ], 'Executing tool' );

			$result = ABW_AI_Router::execute_tool( $tool_call['name'], $tool_call['arguments'] ?? [] );

			$block_actions_part = [];
			if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['__block_actions'] ) ) {
				$block_actions_part = $result['__block_actions'];
				unset( $result['__block_actions'] );
			}

			$block_actions = array_merge( $block_actions, $block_actions_part );
			$resolved = is_wp_error( $result ) ? $result->get_error_message() : $result;
			$result_preview = is_string( $resolved ) ? substr( $resolved, 0, 300 ) : substr( wp_json_encode( $resolved ), 0, 300 );
			ABW_Debug_Log::log( 'tool_result', [ 'name' => $tool_call['name'], 'is_error' => is_wp_error( $result ), 'result_preview' => $result_preview ], 'Tool execution completed' );
			$steps[] = [ 'type' => 'tool_result', 'name' => $tool_call['name'], 'result' => $resolved ];
			$tool_results_for_ai[] = [
				'id'       => $tool_call['id'],
				'name'     => $tool_call['name'],
				'result'   => $resolved,
				'is_error' => is_wp_error( $result ),
			];
			$all_tool_results[] = [ 'tool' => $tool_call['name'], 'result' => $resolved ];
		}

		$auto_empty_trash_result = self::maybe_auto_empty_trash_posts( $messages, $response['tool_calls'], $all_tool_results );
		if ( $auto_empty_trash_result['did_auto_delete'] ) {
			$steps[] = [
				'type' => 'tool_call',
				'name' => 'bulk_delete_posts',
				'args' => [
					'post_ids' => $auto_empty_trash_result['post_ids'],
					'force'    => true,
				],
			];
			$steps[] = [
				'type'   => 'tool_result',
				'name'   => 'bulk_delete_posts',
				'result' => $auto_empty_trash_result['result'],
			];

			$all_tool_results[] = [
				'tool'   => 'bulk_delete_posts',
				'result' => $auto_empty_trash_result['result'],
			];

			ABW_Debug_Log::log(
				'tool_call_auto_followup',
				[
					'name'      => 'bulk_delete_posts',
					'post_count' => count( $auto_empty_trash_result['post_ids'] ),
				],
				'Auto-followup tool execution for empty trash request'
			);

			$response['tool_calls'] = [];
			$response['content']    = '';

			return [
				'response'          => $response,
				'messages'          => $messages,
				'steps'             => $steps,
				'block_actions'     => $block_actions,
				'background_jobs'   => [],
				'iteration'         => 1,
				'all_tool_results'  => $all_tool_results,
			];
		}

		$to_append = ABW_AI_Router::build_tool_result_messages( $response, $tool_results_for_ai );
		$new_messages = array_merge( $messages, $to_append );

		// Nudge the model to continue acting on the tool results.
		$new_messages[] = [
			'role'    => 'user',
			'content' => 'Tool results received. Now continue: if the original task requires more actions (e.g. deleting, updating, creating), call the appropriate tool(s) now. If the task is fully complete, respond with your final answer to the user.',
		];

		return [
			'response'          => $response,
			'messages'          => $new_messages,
			'steps'             => $steps,
			'block_actions'     => $block_actions,
			'background_jobs'   => [],
			'iteration'         => 1,
			'all_tool_results'  => $all_tool_results,
		];
	}

	/**
	 * Check if any tool call in the response is a long-running background operation.
	 *
	 * @param array $tool_calls Array of tool calls from AI response.
	 * @return bool True if at least one tool is a background job type.
	 */
	private static function has_background_tool_call( array $tool_calls ): bool {
		foreach ( $tool_calls as $tool_call ) {
			if ( ABW_Background_Jobs::is_background_job_type( $tool_call['name'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Queue a long-running AI operation as a background job.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $message  Original user message.
	 * @param array  $messages Full message history for re-execution.
	 * @param array  $tools    Available tools.
	 * @param array  $response Initial AI response with tool calls.
	 * @return array|WP_Error  Job info array or WP_Error on failure.
	 */
	private static function queue_background_job( int $user_id, string $message, array $messages, array $tools, array $response ) {
		// Determine the primary job type from the tool calls.
		$job_type = 'generate_post_content'; // Default.
		foreach ( $response['tool_calls'] as $tool_call ) {
			if ( ABW_Background_Jobs::is_background_job_type( $tool_call['name'] ) ) {
				$job_type = $tool_call['name'];
				break;
			}
		}

		// Store all data needed to re-execute the job.
		$input_data = [
			'messages'   => $messages,
			'tools'      => $tools,
			'user_message' => $message,
		];

		$job = ABW_Background_Jobs::create_job( $user_id, $job_type, $input_data );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Dispatch for async processing.
		ABW_Background_Jobs::dispatch_async( $job['job_id'] );

		return [
			'job_token' => $job['job_token'],
			'job_type'  => $job_type,
		];
	}

	/**
	 * Format tool results as a human-readable response when the AI returns empty content.
	 *
	 * @param array $tool_results Array of [ 'tool' => string, 'result' => mixed ].
	 * @return string Formatted markdown, or empty if nothing to show.
	 */
	private static function format_tool_results_for_response( array $tool_results ): string {
		if ( empty( $tool_results ) ) {
			return '';
		}

		$lines = [];
		foreach ( $tool_results as $entry ) {
			$tool   = $entry['tool'] ?? '';
			$result = $entry['result'] ?? null;

			if ( is_wp_error( $result ) ) {
				continue;
			}

			if ( is_string( $result ) && ( $result[0] ?? '' ) === '{' ) {
				$decoded = json_decode( $result, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$result = $decoded;
				}
			}

			if ( ! is_array( $result ) ) {
				if ( is_string( $result ) && $result !== '' ) {
					$lines[] = $result;
				}
				continue;
			}

			$action_formatted = self::format_action_tool_result( $tool, $result );
			if ( $action_formatted !== '' ) {
				$lines[] = $action_formatted;
				continue;
			}

			$formatted = self::format_list_tool_result( $tool, $result );
			if ( $formatted !== '' ) {
				$lines[] = $formatted;
			}
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Return human-readable messages for non-list action results.
	 */
	private static function format_action_tool_result( string $tool, array $result ): string {
		switch ( $tool ) {
			case 'bulk_delete_posts':
				$deleted = (int) ( $result['deleted'] ?? 0 );
				$total   = (int) ( $result['total'] ?? $deleted );
				/* translators: 1: deleted count, 2: total count */
				return sprintf( __( 'Deleted %1$d of %2$d post(s) from trash.', 'abw-ai' ), $deleted, $total );
			case 'check_plugin_updates':
				if ( isset( $result['plugins'] ) && is_array( $result['plugins'] ) && count( $result['plugins'] ) === 0 ) {
					return __( 'No plugin updates available.', 'abw-ai' );
				}
				return '';
			case 'check_theme_updates':
				if ( isset( $result['themes'] ) && is_array( $result['themes'] ) && count( $result['themes'] ) === 0 ) {
					return __( 'No theme updates available.', 'abw-ai' );
				}
				return '';
			case 'update_plugin':
				if ( isset( $result['success'] ) && ! $result['success'] ) {
					return '';
				}
				$plugin_name = (string) ( $result['name'] ?? $result['plugin'] ?? __( 'Plugin', 'abw-ai' ) );
				$from        = (string) ( $result['from_version'] ?? '' );
				$to          = (string) ( $result['to_version'] ?? '' );
				$updated     = ! empty( $result['updated'] );
				if ( $updated ) {
					/* translators: 1: plugin name, 2: old version, 3: new version */
					return sprintf( __( 'Updated plugin %1$s from v%2$s to v%3$s.', 'abw-ai' ), $plugin_name, $from, $to );
				}
				/* translators: %s: plugin name */
				return sprintf( __( 'Plugin %s is already up to date.', 'abw-ai' ), $plugin_name );
			case 'update_theme':
				if ( isset( $result['success'] ) && ! $result['success'] ) {
					return '';
				}
				$theme_name = (string) ( $result['name'] ?? $result['stylesheet'] ?? __( 'Theme', 'abw-ai' ) );
				$from       = (string) ( $result['from_version'] ?? '' );
				$to         = (string) ( $result['to_version'] ?? '' );
				$updated    = ! empty( $result['updated'] );
				if ( $updated ) {
					/* translators: 1: theme name, 2: old version, 3: new version */
					return sprintf( __( 'Updated theme %1$s from v%2$s to v%3$s.', 'abw-ai' ), $theme_name, $from, $to );
				}
				/* translators: %s: theme name */
				return sprintf( __( 'Theme %s is already up to date.', 'abw-ai' ), $theme_name );
		}

		return '';
	}

	/**
	 * Auto-followup: if user clearly asks to empty trash and model only lists trash posts,
	 * execute bulk deletion so the action actually completes.
	 *
	 * @param array $messages          Message history.
	 * @param array $tool_calls        Current tool calls from model.
	 * @param array $all_tool_results  Tool results collected in this iteration.
	 * @return array {
	 *     @type bool  $did_auto_delete Whether auto deletion ran.
	 *     @type array $post_ids        Deleted post IDs.
	 *     @type array $result          bulk_delete_posts tool result.
	 * }
	 */
	private static function maybe_auto_empty_trash_posts( array $messages, array $tool_calls, array $all_tool_results ): array {
		$default = [
			'did_auto_delete' => false,
			'post_ids'        => [],
			'result'          => [],
		];

		if ( ! self::message_requests_empty_trash( $messages ) ) {
			return $default;
		}

		if ( ! self::tool_calls_only_list_trashed_posts( $tool_calls ) ) {
			return $default;
		}

		$post_ids = [];
		$pages    = 1;
		$base_args = self::get_first_trash_list_args( $tool_calls );
		foreach ( $all_tool_results as $entry ) {
			if ( ( $entry['tool'] ?? '' ) !== 'list_posts' || ! is_array( $entry['result'] ?? null ) ) {
				continue;
			}
			if ( isset( $entry['result']['pages'] ) ) {
				$pages = max( 1, (int) $entry['result']['pages'] );
			}
			$post_ids = array_merge( $post_ids, self::extract_post_ids_from_list_result( $entry['result'] ) );
		}

		if ( ! empty( $base_args ) && $pages > 1 ) {
			for ( $page = 2; $page <= $pages; $page++ ) {
				$page_args         = $base_args;
				$page_args['page'] = $page;

				$page_result = ABW_AI_Router::execute_tool( 'list_posts', $page_args );
				if ( is_wp_error( $page_result ) || ! is_array( $page_result ) ) {
					break;
				}

				$post_ids = array_merge( $post_ids, self::extract_post_ids_from_list_result( $page_result ) );
			}
		}

		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return [
				'did_auto_delete' => true,
				'post_ids'        => [],
				'result'          => [
					'deleted'       => 0,
					'total'         => 0,
					'auto_followup' => true,
				],
			];
		}

		$delete_result = ABW_AI_Router::execute_tool(
			'bulk_delete_posts',
			[
				'post_ids' => $post_ids,
				'force'    => true,
			]
		);

		if ( is_wp_error( $delete_result ) ) {
			return [
				'did_auto_delete' => false,
				'post_ids'        => [],
				'result'          => [],
			];
		}

		if ( is_array( $delete_result ) ) {
			$delete_result['auto_followup'] = true;
		}

		return [
			'did_auto_delete' => true,
			'post_ids'        => $post_ids,
			'result'          => is_array( $delete_result ) ? $delete_result : [ 'deleted' => 0, 'total' => count( $post_ids ), 'auto_followup' => true ],
		];
	}

	/**
	 * Check if the latest user prompt requests deleting all trash posts.
	 */
	private static function message_requests_empty_trash( array $messages ): bool {
		$user_message = '';
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( ( $messages[ $i ]['role'] ?? '' ) !== 'user' ) {
				continue;
			}

			$content = (string) ( $messages[ $i ]['content'] ?? '' );
			if ( strpos( $content, 'Tool results received. Now continue:' ) === 0 ) {
				continue;
			}

			$user_message = $content;
			break;
		}

		if ( $user_message === '' ) {
			return false;
		}

		$user_message = preg_replace( '/\s*\[Context:.*$/s', '', $user_message );
		$user_message = strtolower( wp_strip_all_tags( (string) $user_message ) );

		$wants_delete = preg_match( '/\b(delete|remove|clear|empty|purge)\b/', $user_message );
		$mentions_trash = preg_match( '/\b(trash|trashed|bin)\b/', $user_message );
		$mentions_all = preg_match( '/\b(all|every|everything)\b/', $user_message );
		$mentions_posts = preg_match( '/\b(post|posts)\b/', $user_message );

		return (bool) ( $wants_delete && $mentions_trash && ( $mentions_all || $mentions_posts ) );
	}

	/**
	 * True when model requested list_posts with trash status and no action tool.
	 */
	private static function tool_calls_only_list_trashed_posts( array $tool_calls ): bool {
		if ( empty( $tool_calls ) ) {
			return false;
		}

		$has_trash_list = false;
		foreach ( $tool_calls as $tool_call ) {
			$name = $tool_call['name'] ?? '';
			$args = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : [];

			if ( $name === 'list_posts' ) {
				$status = strtolower( (string) ( $args['status'] ?? '' ) );
				if ( $status === 'trash' ) {
					$has_trash_list = true;
					continue;
				}
			}

			return false;
		}

		return $has_trash_list;
	}

	/**
	 * Get normalized arguments from the first list_posts(status=trash) tool call.
	 */
	private static function get_first_trash_list_args( array $tool_calls ): array {
		foreach ( $tool_calls as $tool_call ) {
			$name = $tool_call['name'] ?? '';
			$args = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : [];

			if ( $name !== 'list_posts' ) {
				continue;
			}

			if ( strtolower( (string) ( $args['status'] ?? '' ) ) !== 'trash' ) {
				continue;
			}

			$args['status'] = 'trash';
			return $args;
		}

		return [];
	}

	/**
	 * Extract post IDs from list_posts tool result.
	 */
	private static function extract_post_ids_from_list_result( array $result ): array {
		$posts = $result['posts'] ?? [];
		if ( ! is_array( $posts ) ) {
			return [];
		}

		$post_ids = [];
		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) || ! isset( $post['id'] ) ) {
				continue;
			}
			$post_ids[] = (int) $post['id'];
		}

		return $post_ids;
	}

	/**
	 * Tool-specific table definitions: data key, title, columns (result_key => label),
	 * and optional value transformers.
	 */
	private static function get_tool_table_config(): array {
		return [
			'list_plugins' => [
				'key'     => 'plugins',
				'title'   => __( 'Plugins', 'abw-ai' ),
				'columns' => [
					'name'      => __( 'Plugin', 'abw-ai' ),
					'version'   => __( 'Version', 'abw-ai' ),
					'is_active' => __( 'Status', 'abw-ai' ),
				],
				'transforms' => [
					'version'   => fn( $v ) => $v ? "v{$v}" : '—',
					'is_active' => fn( $v ) => $v ? __( 'Active', 'abw-ai' ) : __( 'Disabled', 'abw-ai' ),
				],
			],
			'list_themes' => [
				'key'     => 'themes',
				'title'   => __( 'Themes', 'abw-ai' ),
				'columns' => [
					'name'      => __( 'Theme', 'abw-ai' ),
					'version'   => __( 'Version', 'abw-ai' ),
					'is_active' => __( 'Status', 'abw-ai' ),
				],
				'transforms' => [
					'version'   => fn( $v ) => $v ? "v{$v}" : '—',
					'is_active' => fn( $v ) => $v ? __( 'Active', 'abw-ai' ) : __( 'Inactive', 'abw-ai' ),
				],
			],
			'check_plugin_updates' => [
				'key'     => 'plugins',
				'title'   => __( 'Plugin Updates', 'abw-ai' ),
				'columns' => [
					'name'        => __( 'Plugin', 'abw-ai' ),
					'version'     => __( 'Current', 'abw-ai' ),
					'new_version' => __( 'Available', 'abw-ai' ),
				],
				'transforms' => [
					'version'     => fn( $v ) => $v ? "v{$v}" : '—',
					'new_version' => fn( $v ) => $v ? "v{$v}" : '—',
				],
			],
			'check_theme_updates' => [
				'key'     => 'themes',
				'title'   => __( 'Theme Updates', 'abw-ai' ),
				'columns' => [
					'name'        => __( 'Theme', 'abw-ai' ),
					'version'     => __( 'Current', 'abw-ai' ),
					'new_version' => __( 'Available', 'abw-ai' ),
				],
				'transforms' => [
					'version'     => fn( $v ) => $v ? "v{$v}" : '—',
					'new_version' => fn( $v ) => $v ? "v{$v}" : '—',
				],
			],
			'list_posts' => [
				'key'     => 'posts',
				'title'   => __( 'Posts', 'abw-ai' ),
				'columns' => [
					'title'  => __( 'Title', 'abw-ai' ),
					'status' => __( 'Status', 'abw-ai' ),
					'type'   => __( 'Type', 'abw-ai' ),
					'date'   => __( 'Date', 'abw-ai' ),
				],
				'transforms' => [
					'title'  => fn( $v ) => $v !== '' ? $v : '_Untitled_',
					'date'   => fn( $v ) => $v ? wp_date( 'M j, Y', strtotime( $v ) ) : '—',
				],
				'total_key' => 'total',
			],
			'list_comments' => [
				'key'     => 'comments',
				'title'   => __( 'Comments', 'abw-ai' ),
				'columns' => [
					'author'  => __( 'Author', 'abw-ai' ),
					'content' => __( 'Comment', 'abw-ai' ),
					'status'  => __( 'Status', 'abw-ai' ),
					'date'    => __( 'Date', 'abw-ai' ),
				],
				'transforms' => [
					'content' => fn( $v ) => mb_strlen( $v ) > 60 ? mb_substr( $v, 0, 60 ) . '…' : $v,
					'date'    => fn( $v ) => $v ? wp_date( 'M j, Y', strtotime( $v ) ) : '—',
				],
			],
			'list_users' => [
				'key'     => 'users',
				'title'   => __( 'Users', 'abw-ai' ),
				'columns' => [
					'display_name' => __( 'Name', 'abw-ai' ),
					'username'     => __( 'Username', 'abw-ai' ),
					'email'        => __( 'Email', 'abw-ai' ),
					'roles'        => __( 'Role', 'abw-ai' ),
				],
				'transforms' => [
					'roles' => fn( $v ) => is_array( $v ) ? implode( ', ', $v ) : (string) $v,
				],
				'total_key' => 'total',
			],
			'list_media' => [
				'key'     => 'media',
				'title'   => __( 'Media', 'abw-ai' ),
				'columns' => [
					'title'     => __( 'Title', 'abw-ai' ),
					'mime_type' => __( 'Type', 'abw-ai' ),
					'url'       => __( 'URL', 'abw-ai' ),
				],
				'transforms' => [
					'title' => fn( $v ) => $v !== '' ? $v : '_Untitled_',
					'url'   => fn( $v ) => $v ? '[link](' . $v . ')' : '—',
				],
				'total_key' => 'total',
			],
			'list_menus' => [
				'key'     => 'menus',
				'title'   => __( 'Menus', 'abw-ai' ),
				'columns' => [
					'name'  => __( 'Menu', 'abw-ai' ),
					'count' => __( 'Items', 'abw-ai' ),
				],
			],
			'list_products' => [
				'key'     => 'products',
				'title'   => __( 'Products', 'abw-ai' ),
				'columns' => [
					'name'         => __( 'Product', 'abw-ai' ),
					'price'        => __( 'Price', 'abw-ai' ),
					'stock_status' => __( 'Stock', 'abw-ai' ),
				],
				'transforms' => [
					'stock_status' => fn( $v ) => $v === 'instock' ? __( 'In Stock', 'abw-ai' ) : ucfirst( str_replace( '_', ' ', (string) $v ) ),
				],
			],
			'list_orders' => [
				'key'     => 'orders',
				'title'   => __( 'Orders', 'abw-ai' ),
				'columns' => [
					'id'     => __( 'Order', 'abw-ai' ),
					'status' => __( 'Status', 'abw-ai' ),
					'total'  => __( 'Total', 'abw-ai' ),
					'date'   => __( 'Date', 'abw-ai' ),
				],
				'transforms' => [
					'id'     => fn( $v ) => '#' . $v,
					'status' => fn( $v ) => ucfirst( str_replace( [ 'wc-', '-', '_' ], [ '', ' ', ' ' ], (string) $v ) ),
					'date'   => fn( $v ) => $v ? wp_date( 'M j, Y', strtotime( $v ) ) : '—',
				],
			],
		];
	}

	/**
	 * Format a single list-tool result into a Markdown table.
	 *
	 * @param string $tool   Tool name.
	 * @param array  $result Tool result data.
	 * @return string Markdown table, or empty string.
	 */
	private static function format_list_tool_result( string $tool, array $result ): string {
		$configs = self::get_tool_table_config();

		if ( isset( $configs[ $tool ] ) ) {
			return self::build_table_from_config( $configs[ $tool ], $result );
		}

		return self::build_table_auto( $tool, $result );
	}

	/**
	 * Build a Markdown table from a known tool config.
	 */
	private static function build_table_from_config( array $config, array $result ): string {
		$key        = $config['key'];
		$items      = $result[ $key ] ?? [];
		$transforms = $config['transforms'] ?? [];
		$max_rows   = 30;

		if ( empty( $items ) || ! is_array( $items ) ) {
			return '';
		}

		$lines   = [];
		$lines[] = '**' . $config['title'] . '**';
		$lines[] = '';

		$headers = array_values( $config['columns'] );
		$keys    = array_keys( $config['columns'] );

		$lines[] = '| ' . implode( ' | ', $headers ) . ' |';
		$lines[] = '| ' . implode( ' | ', array_fill( 0, count( $headers ), '---' ) ) . ' |';

		$shown = array_slice( $items, 0, $max_rows );
		foreach ( $shown as $item ) {
			$cells = [];
			foreach ( $keys as $k ) {
				$val = $item[ $k ] ?? '';
				if ( isset( $transforms[ $k ] ) ) {
					$val = ( $transforms[ $k ] )( $val );
				}
				$cells[] = self::escape_table_cell( (string) $val );
			}
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
		}

		$total_count = count( $items );
		$total_key   = $config['total_key'] ?? '';
		$total_val   = $total_key && isset( $result[ $total_key ] ) ? (int) $result[ $total_key ] : $total_count;

		if ( $total_val > $max_rows ) {
			$lines[] = '';
			/* translators: %1$d: shown count, %2$d: total count */
			$lines[] = sprintf( __( '_Showing %1$d of %2$d_', 'abw-ai' ), min( $max_rows, $total_count ), $total_val );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Auto-detect arrays of objects in an unknown tool result and render as a table.
	 */
	private static function build_table_auto( string $tool, array $result ): string {
		$items     = null;
		$items_key = '';

		foreach ( $result as $k => $v ) {
			if ( is_array( $v ) && ! empty( $v ) && isset( $v[0] ) && is_array( $v[0] ) ) {
				$items     = $v;
				$items_key = $k;
				break;
			}
		}

		if ( ! $items ) {
			$json = wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( strlen( $json ) > 2000 ) {
				$json = substr( $json, 0, 2000 ) . "\n...";
			}
			return '**' . self::humanize_tool_name( $tool ) . "**\n\n```\n" . $json . "\n```";
		}

		$title   = self::humanize_tool_name( $tool );
		$sample  = $items[0];
		$all_keys = array_keys( $sample );

		$skip = [ 'file', 'slug', 'modified' ];
		$keys = array_filter( $all_keys, fn( $k ) => ! in_array( $k, $skip, true ) );
		if ( count( $keys ) > 5 ) {
			$keys = array_slice( $keys, 0, 5 );
		}
		$keys = array_values( $keys );

		$headers = array_map( fn( $k ) => ucfirst( str_replace( '_', ' ', $k ) ), $keys );

		$lines   = [];
		$lines[] = '**' . $title . '**';
		$lines[] = '';
		$lines[] = '| ' . implode( ' | ', $headers ) . ' |';
		$lines[] = '| ' . implode( ' | ', array_fill( 0, count( $headers ), '---' ) ) . ' |';

		$max_rows = 30;
		foreach ( array_slice( $items, 0, $max_rows ) as $item ) {
			$cells = [];
			foreach ( $keys as $k ) {
				$val = $item[ $k ] ?? '';
				if ( is_array( $val ) ) {
					$val = implode( ', ', $val );
				} elseif ( is_bool( $val ) ) {
					$val = $val ? 'Yes' : 'No';
				}
				$val = (string) $val;
				if ( mb_strlen( $val ) > 60 ) {
					$val = mb_substr( $val, 0, 60 ) . '…';
				}
				$cells[] = self::escape_table_cell( $val );
			}
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
		}

		if ( count( $items ) > $max_rows ) {
			$lines[] = '';
			$lines[] = sprintf( __( '_Showing %1$d of %2$d_', 'abw-ai' ), $max_rows, count( $items ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Escape a value for use inside a Markdown table cell.
	 */
	private static function escape_table_cell( string $val ): string {
		return str_replace( [ '|', "\n", "\r" ], [ '\\|', ' ', '' ], $val ?: '—' );
	}

	/**
	 * Turn a snake_case tool name into a human-readable title.
	 */
	private static function humanize_tool_name( string $tool ): string {
		return ucwords( str_replace( '_', ' ', $tool ) );
	}

	/**
	 * Remove auto-generated "Summary"/"Quick Summary"/"TL;DR" sections unless the user requested them.
	 *
	 * @param string $response     Assistant response.
	 * @param string $user_message User message.
	 * @return string
	 */
	private static function strip_unsolicited_summary( string $response, string $user_message ): string {
		if ( preg_match( '/\b(summary|summarize|tl;dr|recap)\b/i', $user_message ) ) {
			return $response;
		}

		$parts = preg_split( "/\n{2,}/", (string) $response );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return $response;
		}

		$out = [];
		$count = count( $parts );

		for ( $i = 0; $i < $count; $i++ ) {
			$part_trimmed = trim( (string) $parts[ $i ] );
			if ( $part_trimmed === '' ) {
				continue;
			}

			$is_summary_heading = (bool) preg_match( '/^#{1,6}\s*(quick\s+summary|summary|tl;dr)\b[:\s]*$/i', $part_trimmed );
			$is_summary_paragraph = (bool) preg_match( '/^(quick\s+summary|summary|tl;dr)\s*:/i', $part_trimmed );

			if ( $is_summary_heading || $is_summary_paragraph ) {
				// If the summary is a heading, it's commonly followed by a bullet list paragraph. Drop that too.
				if ( $is_summary_heading && $i + 1 < $count ) {
					$next_trimmed = trim( (string) $parts[ $i + 1 ] );
					if ( self::is_markdown_list_block( $next_trimmed ) ) {
						$i++;
					}
				}
				continue;
			}

			$out[] = (string) $parts[ $i ];
		}

		$rebuilt = implode( "\n\n", $out );
		return $rebuilt !== '' ? $rebuilt : $response;
	}

	/**
	 * Determine if a paragraph is exclusively a markdown list block (unordered or ordered).
	 *
	 * @param string $text Paragraph text.
	 * @return bool
	 */
	private static function is_markdown_list_block( string $text ): bool {
		$lines = preg_split( "/\n/", $text );
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return false;
		}

		$has_list_item = false;

		foreach ( $lines as $line ) {
			$line_trimmed = trim( (string) $line );
			if ( $line_trimmed === '' ) {
				continue;
			}

			if ( preg_match( '/^[-*]\s+/', $line_trimmed ) || preg_match( '/^\d+\.\s+/', $line_trimmed ) ) {
				$has_list_item = true;
				continue;
			}

			// Non-list content found.
			return false;
		}

		return $has_list_item;
	}

	/**
	 * Get chat history AJAX handler
	 */
	public static function get_chat_history(): void {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$user_id = get_current_user_id();
		$history = self::get_user_history( $user_id );

		wp_send_json_success( [ 'history' => $history ] );
	}

	/**
	 * Clear chat history AJAX handler
	 */
	public static function clear_chat_history(): void {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'abw_chat_history' );

		wp_send_json_success( [ 'message' => __( 'Chat history cleared.', 'abw-ai' ) ] );
	}

	/**
	 * Get user's chat history
	 *
	 * @param int $user_id User ID
	 * @return array
	 */
	private static function get_user_history( int $user_id ): array {
		$history = get_user_meta( $user_id, 'abw_chat_history', true );
		return is_array( $history ) ? $history : [];
	}

	/**
	 * Save message to history
	 *
	 * @param int    $user_id User ID
	 * @param string $role    Message role (user/assistant)
	 * @param string $content Message content
	 */
	private static function save_to_history( int $user_id, string $role, string $content ): void {
		$history = self::get_user_history( $user_id );

		$history[] = [
			'role'      => $role,
			'content'   => $content,
			'timestamp' => time(),
		];

		// Keep only last 100 messages
		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100 );
		}

		update_user_meta( $user_id, 'abw_chat_history', $history );
	}
}

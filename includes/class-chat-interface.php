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

		// AJAX handlers
		add_action( 'wp_ajax_abw_chat_message', [ __CLASS__, 'handle_chat_message' ] );
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
				'title' => '<span class="ab-icon">🤖</span> <span class="ab-label">' . esc_html__( 'ABW-AI', 'abw-ai' ) . '</span>',
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
					<span class="ab-icon">🤖</span> <span class="ab-label">' . esc_html__( 'ABW-AI', 'abw-ai' ) . '</span>
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
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'abw-chat' ),
			'userId'      => get_current_user_id(),
			'userName'    => wp_get_current_user()->display_name,
			'siteName'    => get_bloginfo( 'name' ),
			'currentPage' => self::get_current_page_context(),
			'debugToolResults' => $debug_tool_results,
			'i18n'        => [
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
	 * Inject sidebar HTML container (Angie-style)
	 */
	public static function inject_sidebar_html(): void {
		if ( ! current_user_can( 'use_abw' ) || ! get_option( 'abw_chat_enabled', true ) ) {
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
						<span class='abw-chat-logo'>🤖</span>
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
	 * Handle chat message AJAX request
	 */
	public static function handle_chat_message(): void {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$context = isset( $_POST['context'] ) ? json_decode( stripslashes( $_POST['context'] ), true ) : [];

		if ( empty( $message ) ) {
			wp_send_json_error( [ 'message' => __( 'Message cannot be empty.', 'abw-ai' ) ] );
		}

		// Get conversation history
		$user_id = get_current_user_id();
		$history = self::get_user_history( $user_id );

		// Build messages array
		$messages = [
			[
				'role'    => 'system',
				'content' => ABW_AI_Router::get_system_prompt(),
			],
		];

		// Add history (last 10 messages for context)
		$recent_history = array_slice( $history, -10 );
		foreach ( $recent_history as $entry ) {
			$messages[] = [
				'role'    => $entry['role'],
				'content' => $entry['content'],
			];
		}

		// Add current message with context
		$user_message = $message;
		if ( ! empty( $context ) ) {
			$user_message .= "\n\n[Context: Current page: " . ( $context['screen'] ?? 'unknown' ) . "]";
		}

		$messages[] = [
			'role'    => 'user',
			'content' => $user_message,
		];

		// Get available tools
		$tools = ABW_AI_Router::get_available_tools();

		// Call AI
		$response = ABW_AI_Router::chat( $messages, $tools );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		// Handle tool calls if any
		$final_response = $response['content'];
		$tool_results = [];

		if ( ! empty( $response['tool_calls'] ) ) {
			foreach ( $response['tool_calls'] as $tool_call ) {
				$result = ABW_AI_Router::execute_tool( $tool_call['name'], $tool_call['arguments'] );
				$tool_results[] = [
					'tool'   => $tool_call['name'],
					'result' => is_wp_error( $result ) ? $result->get_error_message() : $result,
				];
			}

			// Get final response with tool results
			$messages[] = [
				'role'    => 'assistant',
				'content' => $response['content'],
			];

			$messages[] = [
				'role'    => 'user',
				'content' => 'Tool results: ' . wp_json_encode( $tool_results ),
			];

			$final_ai_response = ABW_AI_Router::chat( $messages, [] );
			
			if ( ! is_wp_error( $final_ai_response ) ) {
				$final_response = $final_ai_response['content'];
			}
		}

		// Strip unsolicited summaries unless the user explicitly asked for one.
		$final_response = self::strip_unsolicited_summary( $final_response, $message );

		// Save to history
		self::save_to_history( $user_id, 'user', $message );
		self::save_to_history( $user_id, 'assistant', $final_response );

		// Track usage
		if ( isset( $response['usage'] ) ) {
			ABW_AI_Router::track_usage( ABW_AI_Router::get_provider(), $response['usage'] );
		}

		wp_send_json_success( [
			'response'     => $final_response,
			'tool_results' => $tool_results,
		] );
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

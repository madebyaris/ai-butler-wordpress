<?php
/**
 * Background Jobs Manager
 *
 * Manages background processing of long-running AI operations with
 * multi-layer async dispatch, atomic job locking, dynamic timeout
 * detection, and user context restoration.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Background_Jobs
 *
 * Provides a robust job queue system that works across all WordPress
 * hosting environments (shared, managed, VPS) with cascading fallback
 * strategies for async processing.
 */
class ABW_Background_Jobs {

	/**
	 * Database table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'abw_background_jobs';

	/**
	 * DB schema version for migrations.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Job status constants.
	 */
	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_FAILED     = 'failed';
	const STATUS_CANCELLED  = 'cancelled';

	/**
	 * Cron hook names.
	 */
	const CRON_PROCESS_HOOK = 'abw_process_pending_jobs';
	const CRON_CLEANUP_HOOK = 'abw_cleanup_old_jobs';
	const CRON_STUCK_HOOK   = 'abw_detect_stuck_jobs';

	/**
	 * Job types that should run in background (long-running operations).
	 *
	 * @var array
	 */
	const BACKGROUND_JOB_TYPES = [
		'generate_post_content',
		'create_post',
		'update_post',
		'improve_content',
		'translate_content',
		'generate_css',
		'update_elementor_page',
		'generate_seo_meta',
		'generate_faq',
		'generate_schema_markup',
		'summarize_content',
		'generate_social_posts',
		'analyze_content_sentiment',
		'check_content_accessibility',
		'generate_image_alt',
	];

	/**
	 * Initialize background jobs system.
	 */
	public static function init() {
		// Register cron hooks.
		add_action( self::CRON_PROCESS_HOOK, [ __CLASS__, 'cron_process_pending' ] );
		add_action( self::CRON_CLEANUP_HOOK, [ __CLASS__, 'cleanup_old_jobs' ] );
		add_action( self::CRON_STUCK_HOOK, [ __CLASS__, 'detect_stuck_jobs' ] );

		// Single job processing hook (for spawn_cron dispatched jobs).
		add_action( 'abw_process_single_job', [ __CLASS__, 'cron_process_single_job' ] );

		// AJAX endpoints.
		add_action( 'wp_ajax_abw_check_job_status', [ __CLASS__, 'ajax_check_job_status' ] );
		add_action( 'wp_ajax_abw_retry_job', [ __CLASS__, 'ajax_retry_job' ] );
		add_action( 'wp_ajax_abw_cancel_job', [ __CLASS__, 'ajax_cancel_job' ] );
		add_action( 'wp_ajax_abw_admin_job_list', [ __CLASS__, 'ajax_admin_job_list' ] );

		// Schedule recurring cron events if not already scheduled.
		self::schedule_cron_events();
	}

	/**
	 * Schedule cron events for job processing and cleanup.
	 */
	private static function schedule_cron_events() {
		if ( ! wp_next_scheduled( self::CRON_PROCESS_HOOK ) ) {
			wp_schedule_event( time(), 'every_minute', self::CRON_PROCESS_HOOK );
		}

		if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_CLEANUP_HOOK );
		}

		if ( ! wp_next_scheduled( self::CRON_STUCK_HOOK ) ) {
			wp_schedule_event( time(), 'every_five_minutes', self::CRON_STUCK_HOOK );
		}
	}

	/**
	 * Register custom cron schedules.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = [
				'interval' => 60,
				'display'  => __( 'Every Minute', 'abw-ai' ),
			];
		}

		if ( ! isset( $schedules['every_five_minutes'] ) ) {
			$schedules['every_five_minutes'] = [
				'interval' => 300,
				'display'  => __( 'Every Five Minutes', 'abw-ai' ),
			];
		}

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Database
	// -------------------------------------------------------------------------

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the background jobs database table.
	 *
	 * Called on plugin activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			job_type VARCHAR(100) NOT NULL,
			job_token VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			input_data LONGTEXT,
			result_data LONGTEXT,
			error_message TEXT,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
			created_at DATETIME NOT NULL,
			started_at DATETIME DEFAULT NULL,
			timeout_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_token (job_token),
			KEY user_id (user_id),
			KEY status (status),
			KEY status_timeout (status, timeout_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'abw_bg_jobs_db_version', self::DB_VERSION );
	}

	/**
	 * Drop the background jobs table.
	 *
	 * Called on plugin uninstall.
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		delete_option( 'abw_bg_jobs_db_version' );
	}

	// -------------------------------------------------------------------------
	// Dynamic Timeout Detection
	// -------------------------------------------------------------------------

	/**
	 * Get the safe timeout for HTTP requests based on server configuration.
	 *
	 * Detects max_execution_time and reserves buffer for bookkeeping.
	 *
	 * @return int Safe timeout in seconds.
	 */
	public static function get_safe_timeout() {
		$max_exec = (int) ini_get( 'max_execution_time' );

		if ( 0 === $max_exec ) {
			// Unlimited execution time (CLI or VPS).
			return 120;
		}

		// Reserve 5 seconds for job status updates and cleanup.
		return max( $max_exec - 5, 10 );
	}

	// -------------------------------------------------------------------------
	// Job CRUD
	// -------------------------------------------------------------------------

	/**
	 * Check if a tool name is a long-running operation.
	 *
	 * @param string $tool_name The tool name to check.
	 * @return bool
	 */
	public static function is_background_job_type( $tool_name ) {
		return in_array( $tool_name, self::BACKGROUND_JOB_TYPES, true );
	}

	/**
	 * Create a new background job.
	 *
	 * @param int    $user_id   The user ID who initiated the job.
	 * @param string $job_type  The job type (tool name).
	 * @param array  $data      Input data for the job.
	 * @return array|WP_Error   Array with 'job_id' and 'job_token', or WP_Error.
	 */
	public static function create_job( $user_id, $job_type, $data ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$job_token  = wp_generate_password( 64, false );

		$result = $wpdb->insert(
			$table_name,
			[
				'user_id'    => $user_id,
				'job_type'   => $job_type,
				'job_token'  => $job_token,
				'status'     => self::STATUS_PENDING,
				'input_data' => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			return new WP_Error(
				'job_create_failed',
				__( 'Failed to create background job.', 'abw-ai' )
			);
		}

		$job_id = (int) $wpdb->insert_id;

		return [
			'job_id'    => $job_id,
			'job_token' => $job_token,
		];
	}

	/**
	 * Get a job by its ID.
	 *
	 * @param int $job_id Job ID.
	 * @return object|null Job object or null.
	 */
	public static function get_job( $job_id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $job_id ) );
	}

	/**
	 * Get a job by its token.
	 *
	 * @param string $job_token Job token.
	 * @return object|null Job object or null.
	 */
	public static function get_job_by_token( $job_token ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE job_token = %s", $job_token ) );
	}

	/**
	 * Atomically lock a job for processing.
	 *
	 * Uses UPDATE with WHERE status=pending to prevent race conditions.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True if locked successfully, false if already claimed.
	 */
	public static function lock_job( $job_id ) {
		global $wpdb;

		$table_name   = self::get_table_name();
		$safe_timeout = self::get_safe_timeout();
		$now          = current_time( 'mysql' );
		$timeout_at   = gmdate( 'Y-m-d H:i:s', time() + $safe_timeout );

		// Atomic lock + increment attempts in a single query.
		// Uses WHERE status = 'pending' to prevent race conditions.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$locked = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table_name} SET status = %s, started_at = %s, timeout_at = %s, attempts = attempts + 1 WHERE id = %d AND status = %s",
			self::STATUS_PROCESSING,
			$now,
			$timeout_at,
			$job_id,
			self::STATUS_PENDING
		) );

		return $locked > 0;
	}

	/**
	 * Mark a job as completed.
	 *
	 * @param int   $job_id      Job ID.
	 * @param mixed $result_data The result data.
	 */
	public static function complete_job( $job_id, $result_data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$wpdb->update(
			$table_name,
			[
				'status'       => self::STATUS_COMPLETED,
				'result_data'  => wp_json_encode( $result_data ),
				'completed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark a job as failed.
	 *
	 * @param int    $job_id        Job ID.
	 * @param string $error_message Error message.
	 */
	public static function fail_job( $job_id, $error_message ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$wpdb->update(
			$table_name,
			[
				'status'        => self::STATUS_FAILED,
				'error_message' => $error_message,
				'completed_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True if cancelled, false if not.
	 */
	public static function cancel_job( $job_id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$updated = $wpdb->update(
			$table_name,
			[
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql' ),
			],
			[
				'id'     => $job_id,
				'status' => self::STATUS_PENDING,
			],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);

		return $updated > 0;
	}

	/**
	 * Reset a failed job back to pending for retry.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True if reset, false if not.
	 */
	public static function retry_job( $job_id ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Only retry if the job is failed and hasn't exceeded max attempts.
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return false;
		}

		if ( self::STATUS_FAILED !== $job->status ) {
			return false;
		}

		if ( $job->attempts >= $job->max_attempts ) {
			// Reset attempts to allow retry.
			$wpdb->update(
				$table_name,
				[
					'status'        => self::STATUS_PENDING,
					'attempts'      => 0,
					'error_message' => null,
					'started_at'    => null,
					'timeout_at'    => null,
					'completed_at'  => null,
				],
				[ 'id' => $job_id ],
				[ '%s', '%d', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->update(
				$table_name,
				[
					'status'        => self::STATUS_PENDING,
					'error_message' => null,
					'started_at'    => null,
					'timeout_at'    => null,
					'completed_at'  => null,
				],
				[ 'id' => $job_id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Job Processing
	// -------------------------------------------------------------------------

	/**
	 * Process a single job.
	 *
	 * Restores user context, executes the tool, and updates job status.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public static function process_job( $job_id ) {
		$job = self::get_job( $job_id );

		if ( ! $job ) {
			return false;
		}

		// Only process pending jobs.
		if ( self::STATUS_PENDING !== $job->status ) {
			return false;
		}

		// Check max attempts.
		if ( $job->attempts >= $job->max_attempts ) {
			self::fail_job( $job_id, __( 'Maximum retry attempts exceeded.', 'abw-ai' ) );
			return false;
		}

		// Atomic lock.
		if ( ! self::lock_job( $job_id ) ) {
			return false; // Another process claimed it.
		}

		// Restore user context for permission checks.
		wp_set_current_user( (int) $job->user_id );

		$input_data = json_decode( $job->input_data, true );
		if ( ! is_array( $input_data ) ) {
			$input_data = [];
		}

		$tool_name  = $job->job_type;
		$arguments  = $input_data['arguments'] ?? [];
		$messages   = $input_data['messages'] ?? [];
		$tools_list = $input_data['tools'] ?? [];

		try {
			// If this is an AI chat operation, we need to replay the chat + tool execution.
			if ( ! empty( $messages ) ) {
				$result = self::process_chat_job( $job_id, $messages, $tools_list, $input_data );
			} else {
				// Direct tool execution.
				$result = ABW_AI_Router::execute_tool(
					$tool_name,
					$arguments,
					[
						'source'    => 'background_job',
						'confirmed' => true,
						'user_id'   => (int) $job->user_id,
					]
				);
			}

			if ( is_wp_error( $result ) ) {
				self::fail_job( $job_id, $result->get_error_message() );
				return false;
			}

			self::complete_job( $job_id, $result );
			return true;
		} catch ( \Exception $e ) {
			self::fail_job( $job_id, $e->getMessage() );
			return false;
		} catch ( \Error $e ) {
			self::fail_job( $job_id, $e->getMessage() );
			return false;
		}
	}

	/**
	 * Process a chat-based background job.
	 *
	 * Re-sends the message to the AI, executes tool calls, and returns
	 * the final AI response.
	 *
	 * @param int   $job_id   Job ID.
	 * @param array $messages Chat messages.
	 * @param array $tools    Available tools.
	 * @param array $input    Full input data.
	 * @return array|WP_Error Result array or error.
	 */
	private static function process_chat_job( $job_id, $messages, $tools, $input ) {
		$provider = $input['provider'] ?? ABW_AI_Router::get_provider();
		$user_id  = get_current_user_id();

		$result = ABW_Chat_Interface::run_agentic_until_complete(
			$messages,
			$tools,
			$provider,
			$user_id,
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$final_response = $result['response']['content'] ?? '';
		if ( '' === trim( $final_response ) ) {
			$final_response = 'Task completed.';
		}

		if ( ! empty( $result['confirmation'] ) ) {
			return new WP_Error(
				'background_confirmation_required',
				__( 'This background task now requires manual approval before it can continue.', 'abw-ai' )
			);
		}

		return [
			'response'      => $final_response,
			'tool_results'  => $result['all_tool_results'] ?? [],
			'steps'         => $result['steps'] ?? [],
		];
	}

	/**
	 * Find and process the oldest pending job.
	 *
	 * @return bool True if a job was processed, false if no pending jobs.
	 */
	public static function try_process_next() {
		global $wpdb;

		$table_name = self::get_table_name();

		// Find the oldest pending job.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE status = %s ORDER BY created_at ASC LIMIT 1",
			self::STATUS_PENDING
		) );

		if ( ! $job ) {
			return false;
		}

		return self::process_job( (int) $job->id );
	}

	// -------------------------------------------------------------------------
	// Multi-Layer Async Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a job for async processing.
	 *
	 * Tries Layer 1 (fastcgi_finish_request), then Layer 2 (spawn_cron).
	 * Layer 3 (frontend polling) is handled automatically by ajax_check_job_status.
	 *
	 * @param int $job_id The job ID to dispatch.
	 */
	public static function dispatch_async( $job_id ) {
		// Layer 1: Try inline processing via fastcgi_finish_request.
		if ( self::try_fastcgi_dispatch( $job_id ) ) {
			return;
		}

		// Layer 2: Schedule via WP-Cron and spawn immediately.
		self::dispatch_via_cron( $job_id );
	}

	/**
	 * Layer 1: Attempt inline processing using fastcgi_finish_request.
	 *
	 * If PHP-FPM is available, sends the response to the client immediately,
	 * then continues processing in the same PHP process. This preserves the
	 * user session and avoids loopback issues.
	 *
	 * @param int $job_id The job ID.
	 * @return bool True if dispatch succeeded (processing will happen after response).
	 */
	private static function try_fastcgi_dispatch( $job_id ) {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			return false;
		}

		// Register a shutdown function to process the job after the response is sent.
		// We store the job_id and use the shutdown to process after fastcgi_finish_request.
		add_action( 'shutdown', function () use ( $job_id ) {
			// The response has already been sent via fastcgi_finish_request
			// (called by WordPress in the AJAX handler path).
			// We now have the remaining execution time to process the job.
			self::process_job( $job_id );
		}, 100 ); // Late priority to ensure response is fully sent.

		return true;
	}

	/**
	 * Layer 2: Dispatch job via WP-Cron.
	 *
	 * Schedules a single cron event and immediately spawns cron to trigger it.
	 * This uses WordPress's own loopback mechanism.
	 *
	 * @param int $job_id The job ID.
	 */
	private static function dispatch_via_cron( $job_id ) {
		// Schedule immediate single event.
		wp_schedule_single_event( time(), 'abw_process_single_job', [ $job_id ] );

		// Trigger cron spawn immediately (non-blocking).
		spawn_cron();
	}

	// -------------------------------------------------------------------------
	// Cron Handlers
	// -------------------------------------------------------------------------

	/**
	 * Cron handler: Process the next pending job.
	 *
	 * Only processes one job per cron run to avoid timeouts.
	 */
	public static function cron_process_pending() {
		self::try_process_next();
	}

	/**
	 * Cron handler: Process a specific job by ID.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function cron_process_single_job( $job_id ) {
		self::process_job( (int) $job_id );
	}

	/**
	 * Cron handler: Detect and reset stuck jobs.
	 *
	 * Jobs in 'processing' status with timeout_at in the past are considered stuck.
	 */
	public static function detect_stuck_jobs() {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql' );

		// Find stuck jobs (processing but past their timeout).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stuck_jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, attempts, max_attempts FROM {$table_name} WHERE status = %s AND timeout_at IS NOT NULL AND timeout_at < %s",
			self::STATUS_PROCESSING,
			$now
		) );

		if ( empty( $stuck_jobs ) ) {
			return;
		}

		foreach ( $stuck_jobs as $job ) {
			if ( (int) $job->attempts >= (int) $job->max_attempts ) {
				// Exceeded max attempts - mark as permanently failed.
				self::fail_job( (int) $job->id, __( 'Job timed out and exceeded maximum retry attempts.', 'abw-ai' ) );
			} else {
				// Reset to pending for retry.
				$wpdb->update(
					$table_name,
					[
						'status'     => self::STATUS_PENDING,
						'started_at' => null,
						'timeout_at' => null,
					],
					[ 'id' => (int) $job->id ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
			}
		}
	}

	/**
	 * Cron handler: Clean up old completed and failed jobs.
	 */
	public static function cleanup_old_jobs() {
		global $wpdb;

		$table_name = self::get_table_name();

		// Delete completed jobs older than 7 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE status = %s AND completed_at < DATE_SUB(%s, INTERVAL 7 DAY)",
			self::STATUS_COMPLETED,
			current_time( 'mysql' )
		) );

		// Delete failed jobs older than 30 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE status = %s AND completed_at < DATE_SUB(%s, INTERVAL 30 DAY)",
			self::STATUS_FAILED,
			current_time( 'mysql' )
		) );

		// Delete cancelled jobs older than 7 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE status = %s AND completed_at < DATE_SUB(%s, INTERVAL 7 DAY)",
			self::STATUS_CANCELLED,
			current_time( 'mysql' )
		) );
	}

	// -------------------------------------------------------------------------
	// Deactivation Cleanup
	// -------------------------------------------------------------------------

	/**
	 * Clean up cron events on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_PROCESS_HOOK );
		wp_clear_scheduled_hook( self::CRON_CLEANUP_HOOK );
		wp_clear_scheduled_hook( self::CRON_STUCK_HOOK );
	}

	// -------------------------------------------------------------------------
	// AJAX Endpoints
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Check job status.
	 *
	 * Layer 3 fallback: If job is still pending, attempts inline processing.
	 */
	public static function ajax_check_job_status() {
		check_ajax_referer( 'abw-chat', 'nonce' );

		if ( ! current_user_can( 'use_abw' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$job_token = isset( $_POST['job_token'] ) ? sanitize_text_field( wp_unslash( $_POST['job_token'] ) ) : '';

		if ( empty( $job_token ) ) {
			wp_send_json_error( [ 'message' => __( 'Job token is required.', 'abw-ai' ) ] );
		}

		$job = self::get_job_by_token( $job_token );

		if ( ! $job ) {
			wp_send_json_error( [ 'message' => __( 'Job not found.', 'abw-ai' ) ] );
		}

		// Verify the job belongs to the current user.
		if ( (int) $job->user_id !== get_current_user_id() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		// Layer 3 Fallback: If job is still pending, try processing it inline.
		if ( self::STATUS_PENDING === $job->status ) {
			self::process_job( (int) $job->id );
			// Refresh job data after processing attempt.
			$job = self::get_job_by_token( $job_token );
		}

		$response = [
			'status'    => $job->status,
			'job_type'  => $job->job_type,
			'created_at' => $job->created_at,
			'attempts'  => (int) $job->attempts,
		];

		if ( self::STATUS_COMPLETED === $job->status ) {
			$response['result'] = json_decode( $job->result_data, true );
		}

		if ( self::STATUS_FAILED === $job->status ) {
			$response['error'] = $job->error_message;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Retry a failed job.
	 */
	public static function ajax_retry_job() {
		check_ajax_referer( 'abw-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		if ( ! $job_id ) {
			wp_send_json_error( [ 'message' => __( 'Job ID is required.', 'abw-ai' ) ] );
		}

		$retried = self::retry_job( $job_id );

		if ( $retried ) {
			// Dispatch async for immediate processing.
			self::dispatch_async( $job_id );
			wp_send_json_success( [ 'message' => __( 'Job has been queued for retry.', 'abw-ai' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Cannot retry this job.', 'abw-ai' ) ] );
		}
	}

	/**
	 * AJAX: Cancel a pending job.
	 */
	public static function ajax_cancel_job() {
		check_ajax_referer( 'abw-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		if ( ! $job_id ) {
			wp_send_json_error( [ 'message' => __( 'Job ID is required.', 'abw-ai' ) ] );
		}

		$cancelled = self::cancel_job( $job_id );

		if ( $cancelled ) {
			wp_send_json_success( [ 'message' => __( 'Job has been cancelled.', 'abw-ai' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Cannot cancel this job. It may already be processing.', 'abw-ai' ) ] );
		}
	}

	/**
	 * AJAX: Get paginated job list for admin page.
	 */
	public static function ajax_admin_job_list() {
		check_ajax_referer( 'abw-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'abw-ai' ) ], 403 );
		}

		global $wpdb;

		$table_name = self::get_table_name();
		$page       = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$per_page   = 20;
		$offset     = ( $page - 1 ) * $per_page;

		// Get total count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		// Get jobs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, job_type, status, error_message, attempts, max_attempts, created_at, started_at, completed_at FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		// Enrich with user display names.
		$formatted_jobs = [];
		foreach ( $jobs as $job ) {
			$user = get_userdata( (int) $job->user_id );
			$formatted_jobs[] = [
				'id'           => (int) $job->id,
				'user_name'    => $user ? $user->display_name : __( 'Unknown', 'abw-ai' ),
				'job_type'     => $job->job_type,
				'status'       => $job->status,
				'error_message' => $job->error_message,
				'attempts'     => (int) $job->attempts,
				'max_attempts' => (int) $job->max_attempts,
				'created_at'   => $job->created_at,
				'started_at'   => $job->started_at,
				'completed_at' => $job->completed_at,
			];
		}

		wp_send_json_success( [
			'jobs'       => $formatted_jobs,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		] );
	}

	// -------------------------------------------------------------------------
	// Admin Page
	// -------------------------------------------------------------------------

	/**
	 * Get job counts by status for the admin page.
	 *
	 * @return array Associative array of status => count.
	 */
	public static function get_job_counts() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table_name} GROUP BY status" );

		$counts = [
			self::STATUS_PENDING    => 0,
			self::STATUS_PROCESSING => 0,
			self::STATUS_COMPLETED  => 0,
			self::STATUS_FAILED     => 0,
			self::STATUS_CANCELLED  => 0,
		];

		if ( $results ) {
			foreach ( $results as $row ) {
				$counts[ $row->status ] = (int) $row->count;
			}
		}

		return $counts;
	}

	/**
	 * Get recent jobs for the admin page.
	 *
	 * @param int $limit Number of jobs to return.
	 * @return array
	 */
	public static function get_recent_jobs( $limit = 50 ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
			$limit
		) );
	}
}

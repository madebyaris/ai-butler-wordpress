<?php
/**
 * Debug Log for ABW-AI
 *
 * Writes agentic flow, tool calls, and AI interactions to wp-content/abw-ai-logs/
 * for debugging. Enable via ABW-AI > Settings > Debug Log.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Debug_Log
 */
class ABW_Debug_Log {

	/**
	 * Log directory name (inside wp-content).
	 */
	const LOG_DIR = 'abw-ai-logs';

	/**
	 * Max size per log file in bytes (5MB).
	 */
	const MAX_FILE_SIZE = 5242880;

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( 'abw_debug_log', false );
	}

	/**
	 * Get the log directory path.
	 *
	 * @return string
	 */
	public static function get_log_dir(): string {
		return WP_CONTENT_DIR . '/' . self::LOG_DIR . '/';
	}

	/**
	 * Create log directory on plugin activation (does not require debug to be enabled).
	 *
	 * @return bool True if directory is ready.
	 */
	public static function create_log_dir(): bool {
		$dir = self::get_log_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		return is_dir( $dir ) && is_writable( $dir );
	}

	/**
	 * Ensure log directory exists and is writable.
	 *
	 * @return bool True if directory is ready.
	 */
	public static function ensure_log_dir(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		return self::create_log_dir();
	}

	/**
	 * Get today's log file path.
	 *
	 * @return string
	 */
	public static function get_today_log_file(): string {
		return self::get_log_dir() . 'abw-' . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $event   Event name (e.g. 'chat_request', 'ai_call', 'tool_call').
	 * @param array  $data    Structured data to log (will be JSON-encoded).
	 * @param string $message Optional plain text message.
	 */
	public static function log( string $event, array $data = [], string $message = '' ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! self::ensure_log_dir() ) {
			return;
		}

		$file = self::get_today_log_file();

		// Rotate if file is too large.
		if ( file_exists( $file ) && filesize( $file ) >= self::MAX_FILE_SIZE ) {
			$rotated = $file . '.' . gmdate( 'His' ) . '.old';
			rename( $file, $rotated );
		}

		$entry = [
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'event'   => $event,
			'user_id' => get_current_user_id(),
			'data'    => self::sanitize_for_log( $data ),
		];
		if ( $message !== '' ) {
			$entry['message'] = $message;
		}

		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Sanitize data for logging (remove secrets, truncate huge strings).
	 *
	 * @param mixed $data Raw data.
	 * @return mixed Sanitized data.
	 */
	private static function sanitize_for_log( $data ) {
		if ( ! is_array( $data ) ) {
			if ( is_string( $data ) && strlen( $data ) > 2000 ) {
				return substr( $data, 0, 2000 ) . '...[truncated]';
			}
			return $data;
		}

		$out = [];
		$skip_keys = [ 'api_key', 'password', 'nonce', 'secret' ];
		$truncate_keys = [ 'content', 'editor_context', 'system_prompt', 'html' ];

		foreach ( $data as $k => $v ) {
			$key_lower = strtolower( (string) $k );
			foreach ( $skip_keys as $skip ) {
				if ( strpos( $key_lower, $skip ) !== false ) {
					$v = '[REDACTED]';
					break;
				}
			}
			foreach ( $truncate_keys as $trunc ) {
				if ( strpos( $key_lower, $trunc ) !== false && is_string( $v ) && strlen( $v ) > 500 ) {
					$v = substr( $v, 0, 500 ) . '...[truncated]';
					break;
				}
			}
			$out[ $k ] = is_array( $v ) ? self::sanitize_for_log( $v ) : $v;
		}
		return $out;
	}

	/**
	 * List available log files for admin review.
	 *
	 * @return array List of log file info (name, size, date).
	 */
	public static function list_log_files(): array {
		$dir = self::get_log_dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = [];
		$list = glob( $dir . 'abw-*.log*' );
		if ( ! is_array( $list ) ) {
			return [];
		}

		foreach ( $list as $path ) {
			$name = basename( $path );
			$files[] = [
				'name' => $name,
				'size' => size_format( filesize( $path ) ),
				'date' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
			];
		}

		usort( $files, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		} );

		return $files;
	}

	/**
	 * Get log file contents (for download/view). Only allowed in admin.
	 *
	 * @param string $filename Log filename (basename only).
	 * @return string|WP_Error File contents or error.
	 */
	public static function get_log_content( string $filename ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'abw-ai' ) );
		}

		$filename = basename( $filename );
		if ( ! preg_match( '/^abw-\d{4}-\d{2}-\d{2}\.log(\.[a-zA-Z0-9.]+)?$/', $filename ) ) {
			return new WP_Error( 'invalid', __( 'Invalid log file.', 'abw-ai' ) );
		}

		$path = self::get_log_dir() . $filename;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'not_found', __( 'Log file not found.', 'abw-ai' ) );
		}

		return file_get_contents( $path );
	}
}

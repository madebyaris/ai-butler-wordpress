<?php
/**
 * Security & Monitoring Tools for ABW-AI
 *
 * Provides file integrity scanning, permission auditing, SSL checks,
 * admin-user listing, and failed-login tracking as AI-callable tools.
 *
 * @package ABW_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ABW_Security_Tools
 */
class ABW_Security_Tools {

	/**
	 * Scan WordPress core file integrity against official checksums.
	 *
	 * @param array $input Unused — no parameters required.
	 * @return array|WP_Error
	 */
	public static function scan_file_integrity( array $input ) {
		global $wp_version;

		$locale  = get_locale();
		$api_url = sprintf(
			'https://api.wordpress.org/core/checksums/1.0/?version=%s&locale=%s',
			$wp_version,
			$locale
		);

		$response = wp_remote_get( $api_url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', __( 'Could not reach WordPress.org checksums API.', 'abw-ai' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['checksums'] ) || ! is_array( $body['checksums'] ) ) {
			return new WP_Error( 'checksum_error', __( 'Invalid checksum data from API.', 'abw-ai' ) );
		}

		$checksums      = $body['checksums'];
		$modified_files = [];
		$missing_files  = [];
		$checked        = 0;

		foreach ( $checksums as $file => $expected_md5 ) {
			$full_path = ABSPATH . $file;
			$checked++;

			if ( ! file_exists( $full_path ) ) {
				$missing_files[] = $file;
				continue;
			}

			$actual_md5 = md5_file( $full_path );

			if ( $actual_md5 !== $expected_md5 ) {
				$modified_files[] = [
					'path'   => $file,
					'status' => 'modified',
				];
			}
		}

		$extra_files  = [];
		$core_dirs    = [ 'wp-admin/', 'wp-includes/' ];
		foreach ( $core_dirs as $dir ) {
			$dir_path = ABSPATH . $dir;
			if ( ! is_dir( $dir_path ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					$relative = str_replace( ABSPATH, '', $item->getPathname() );
					if ( ! isset( $checksums[ $relative ] ) ) {
						$extra_files[] = $relative;
					}
				}
			}
		}

		$has_issues = ! empty( $modified_files ) || ! empty( $missing_files );

		return [
			'total_files_checked' => $checked,
			'modified_files'      => $modified_files,
			'missing_files'       => $missing_files,
			'extra_files'         => array_slice( $extra_files, 0, 50 ),
			'status'              => $has_issues ? 'modified' : 'clean',
		];
	}

	/**
	 * List recent failed login attempts.
	 *
	 * @param array $input {
	 *     @type int $limit Max entries to return (default 20).
	 * }
	 * @return array|WP_Error
	 */
	public static function list_failed_logins( array $input ) {
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 20;
		$limit = min( max( $limit, 1 ), 100 );

		global $wpdb;

		// Try Wordfence table first.
		$wf_table = $wpdb->base_prefix . 'wfLogins';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wf_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wf_table ) );

		if ( $wf_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT IP AS ip, username, ctime AS time
					 FROM {$wf_table}
					 WHERE fail = 1
					 ORDER BY ctime DESC
					 LIMIT %d",
					$limit
				),
				ARRAY_A
			);

			if ( $rows ) {
				foreach ( $rows as &$row ) {
					$row['ip']   = long2ip( (int) $row['ip'] );
					$row['time'] = gmdate( 'Y-m-d H:i:s', (int) $row['time'] );
				}
				unset( $row );
				return $rows;
			}
		}

		// Try Limit Login Attempts Reloaded.
		$llar_log = get_option( 'limit_login_logged' );
		if ( is_array( $llar_log ) && ! empty( $llar_log ) ) {
			$entries = [];
			foreach ( $llar_log as $ip => $data ) {
				if ( is_array( $data ) ) {
					foreach ( $data as $username => $info ) {
						$entries[] = [
							'ip'       => $ip,
							'username' => $username,
							'time'     => isset( $info['date'] ) ? $info['date'] : 'unknown',
						];
					}
				}
			}
			usort( $entries, function ( $a, $b ) {
				return strcmp( $b['time'], $a['time'] );
			} );
			return array_slice( $entries, 0, $limit );
		}

		// Fall back to our own tracked option.
		$own_log = get_option( 'abw_failed_logins', [] );
		if ( ! is_array( $own_log ) ) {
			$own_log = [];
		}

		$own_log = array_reverse( $own_log );
		return array_slice( $own_log, 0, $limit );
	}

	/**
	 * Check permissions on critical WordPress files and directories.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function check_file_permissions( array $input ) {
		$targets = [
			'wp-config.php'       => [
				'path'        => ABSPATH . 'wp-config.php',
				'recommended' => '0440',
				'max_octal'   => 0440,
				'is_dir'      => false,
			],
			'.htaccess'           => [
				'path'        => ABSPATH . '.htaccess',
				'recommended' => '0644',
				'max_octal'   => 0644,
				'is_dir'      => false,
			],
			'wp-content/'         => [
				'path'        => WP_CONTENT_DIR,
				'recommended' => '0755',
				'max_octal'   => 0755,
				'is_dir'      => true,
			],
			'wp-content/uploads/' => [
				'path'        => WP_CONTENT_DIR . '/uploads',
				'recommended' => '0755',
				'max_octal'   => 0755,
				'is_dir'      => true,
			],
			'wp-content/plugins/' => [
				'path'        => WP_PLUGIN_DIR,
				'recommended' => '0755',
				'max_octal'   => 0755,
				'is_dir'      => true,
			],
			'wp-content/themes/'  => [
				'path'        => get_theme_root(),
				'recommended' => '0755',
				'max_octal'   => 0755,
				'is_dir'      => true,
			],
		];

		$results = [];

		foreach ( $targets as $label => $meta ) {
			$path = $meta['path'];

			if ( ! file_exists( $path ) ) {
				$results[] = [
					'file'        => $label,
					'permissions' => 'N/A',
					'recommended' => $meta['recommended'],
					'status'      => 'warning',
				];
				continue;
			}

			$perms       = fileperms( $path );
			$octal       = substr( decoct( $perms ), -4 );
			$octal_int   = intval( $octal, 8 );
			$max_allowed = $meta['max_octal'];

			if ( $label === 'wp-config.php' && ( $perms & 0004 ) ) {
				$status = 'critical';
			} elseif ( $meta['is_dir'] && $octal_int === 0777 ) {
				$status = 'critical';
			} elseif ( $octal_int > $max_allowed ) {
				$status = 'warning';
			} else {
				$status = 'ok';
			}

			$results[] = [
				'file'        => $label,
				'permissions' => $octal,
				'recommended' => $meta['recommended'],
				'status'      => $status,
			];
		}

		return $results;
	}

	/**
	 * Generate a comprehensive security report with a 0-100 score.
	 *
	 * @param array $input Unused.
	 * @return array|WP_Error
	 */
	public static function get_security_report( array $input ) {
		$score    = 100;
		$findings = [
			'critical' => [],
			'warning'  => [],
			'info'     => [],
			'good'     => [],
		];

		// --- SSL ---
		$ssl_active = is_ssl();
		if ( $ssl_active ) {
			$findings['good'][] = 'SSL is active.';
		} else {
			$findings['critical'][] = 'SSL is NOT active — site is served over plain HTTP.';
			$score -= 20;
		}

		// --- Debug mode ---
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$findings['warning'][] = 'WP_DEBUG is enabled on this site.';
			$score -= 5;
		} else {
			$findings['good'][] = 'WP_DEBUG is disabled.';
		}

		// --- File editing ---
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$findings['good'][] = 'In-dashboard file editing is disabled.';
		} else {
			$findings['warning'][] = 'In-dashboard file editing is enabled (DISALLOW_FILE_EDIT is not set).';
			$score -= 5;
		}

		// --- WordPress version ---
		global $wp_version;
		$update_check = get_site_transient( 'update_core' );
		$is_latest    = true;
		if ( ! empty( $update_check->updates ) ) {
			foreach ( $update_check->updates as $update ) {
				if ( 'upgrade' === $update->response && version_compare( $update->current, $wp_version, '>' ) ) {
					$is_latest = false;
					break;
				}
			}
		}
		if ( $is_latest ) {
			$findings['good'][] = sprintf( 'WordPress %s is up to date.', $wp_version );
		} else {
			$findings['warning'][] = sprintf( 'WordPress %s is outdated — update available.', $wp_version );
			$score -= 10;
		}

		// --- Admin users ---
		$admin_users   = get_users( [ 'role' => 'administrator', 'fields' => [ 'ID', 'user_login' ] ] );
		$admin_count   = count( $admin_users );
		$weak_names    = [];
		$bad_usernames = [ 'admin', 'administrator', 'root', 'test' ];

		foreach ( $admin_users as $user ) {
			if ( in_array( strtolower( $user->user_login ), $bad_usernames, true ) ) {
				$weak_names[] = $user->user_login;
			}
		}

		if ( $admin_count > 3 ) {
			$findings['warning'][] = sprintf( '%d administrator accounts found — consider reducing.', $admin_count );
			$score -= 5;
		} else {
			$findings['info'][] = sprintf( '%d administrator account(s).', $admin_count );
		}

		if ( ! empty( $weak_names ) ) {
			$findings['critical'][] = sprintf(
				'Weak admin username(s) detected: %s',
				implode( ', ', $weak_names )
			);
			$score -= 15;
		}

		// --- File permissions ---
		$perm_results = self::check_file_permissions( [] );
		$perm_issues  = 0;
		foreach ( $perm_results as $perm ) {
			if ( 'critical' === $perm['status'] ) {
				$findings['critical'][] = sprintf( '%s has insecure permissions (%s).', $perm['file'], $perm['permissions'] );
				$score -= 10;
				$perm_issues++;
			} elseif ( 'warning' === $perm['status'] ) {
				$findings['warning'][] = sprintf( '%s permissions (%s) are looser than recommended (%s).', $perm['file'], $perm['permissions'], $perm['recommended'] );
				$score -= 3;
				$perm_issues++;
			}
		}
		if ( 0 === $perm_issues ) {
			$findings['good'][] = 'All critical file permissions are correct.';
		}

		// --- File integrity (lightweight summary) ---
		$integrity = self::scan_file_integrity( [] );
		if ( is_wp_error( $integrity ) ) {
			$findings['info'][] = 'Could not verify core file integrity: ' . $integrity->get_error_message();
		} elseif ( 'modified' === $integrity['status'] ) {
			$mod_count = count( $integrity['modified_files'] );
			$miss_count = count( $integrity['missing_files'] );
			$findings['warning'][] = sprintf( 'Core file integrity: %d modified, %d missing.', $mod_count, $miss_count );
			$score -= min( 15, ( $mod_count + $miss_count ) * 2 );
		} else {
			$findings['good'][] = 'Core file integrity check passed.';
		}

		$score = max( 0, min( 100, $score ) );

		return [
			'score'            => $score,
			'grade'            => self::score_to_grade( $score ),
			'findings'         => $findings,
			'file_permissions' => $perm_results,
			'ssl_active'       => $ssl_active,
			'wp_version'       => $wp_version,
			'admin_count'      => $admin_count,
		];
	}

	/**
	 * List all administrator users.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function list_admin_users( array $input ) {
		$users  = get_users( [ 'role' => 'administrator' ] );
		$result = [];

		foreach ( $users as $user ) {
			$last_login = get_user_meta( $user->ID, 'abw_last_login', true );

			$result[] = [
				'id'              => $user->ID,
				'username'        => $user->user_login,
				'email'           => $user->user_email,
				'display_name'    => $user->display_name,
				'last_login'      => $last_login ? $last_login : null,
				'registered_date' => $user->user_registered,
			];
		}

		return $result;
	}

	/**
	 * Check SSL status and certificate details.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function check_ssl_status( array $input ) {
		$ssl_active = is_ssl();
		$result     = [
			'ssl_active'     => $ssl_active,
			'issuer'         => null,
			'valid_from'     => null,
			'valid_to'       => null,
			'days_remaining' => null,
			'status'         => $ssl_active ? 'active' : 'inactive',
		];

		if ( ! $ssl_active ) {
			$result['status'] = 'inactive';
			return $result;
		}

		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$context = stream_context_create( [
			'ssl' => [
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
			],
		] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client
		$client = @stream_socket_client(
			'ssl://' . $host . ':443',
			$errno,
			$errstr,
			10,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( ! $client ) {
			$result['status'] = 'ssl_active_cert_unknown';
			return $result;
		}

		$params = stream_context_get_params( $client );
		fclose( $client );

		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			$result['status'] = 'ssl_active_cert_unknown';
			return $result;
		}

		$cert_info = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );

		if ( ! $cert_info ) {
			$result['status'] = 'ssl_active_cert_parse_error';
			return $result;
		}

		$valid_from = isset( $cert_info['validFrom_time_t'] ) ? (int) $cert_info['validFrom_time_t'] : 0;
		$valid_to   = isset( $cert_info['validTo_time_t'] ) ? (int) $cert_info['validTo_time_t'] : 0;
		$now        = time();

		$issuer_parts = [];
		if ( ! empty( $cert_info['issuer']['O'] ) ) {
			$issuer_parts[] = $cert_info['issuer']['O'];
		}
		if ( ! empty( $cert_info['issuer']['CN'] ) ) {
			$issuer_parts[] = $cert_info['issuer']['CN'];
		}

		$days_remaining = $valid_to > 0 ? (int) floor( ( $valid_to - $now ) / 86400 ) : null;

		$status = 'active';
		if ( $days_remaining !== null && $days_remaining < 0 ) {
			$status = 'expired';
		} elseif ( $days_remaining !== null && $days_remaining < 14 ) {
			$status = 'expiring_soon';
		}

		$result['issuer']         = implode( ' — ', $issuer_parts ) ?: 'Unknown';
		$result['valid_from']     = $valid_from ? gmdate( 'Y-m-d H:i:s', $valid_from ) : null;
		$result['valid_to']       = $valid_to ? gmdate( 'Y-m-d H:i:s', $valid_to ) : null;
		$result['days_remaining'] = $days_remaining;
		$result['status']         = $status;

		return $result;
	}

	/**
	 * Return tool schemas for all security tools.
	 *
	 * @return array
	 */
	public static function get_tools_list(): array {
		return [
			[
				'name'        => 'scan_file_integrity',
				'description' => 'Scan WordPress core files against official checksums to detect modifications, missing, or extra files.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'list_failed_logins',
				'description' => 'List recent failed login attempts with IP, username, and timestamp.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'description' => 'Maximum number of entries to return (default 20, max 100).',
						],
					],
				],
			],
			[
				'name'        => 'check_file_permissions',
				'description' => 'Audit file permissions on critical WordPress files and directories (wp-config.php, .htaccess, wp-content, etc.).',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'get_security_report',
				'description' => 'Generate a comprehensive security report with a 0-100 score covering SSL, debug mode, file editing, WP version, admin users, file permissions, and core integrity.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'list_admin_users',
				'description' => 'List all WordPress administrator users with their details and last login time.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'check_ssl_status',
				'description' => 'Check SSL status and retrieve certificate details including issuer, validity dates, and days remaining.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
		];
	}

	/**
	 * Convert a numeric score to a letter grade.
	 *
	 * @param int $score 0-100.
	 * @return string
	 */
	private static function score_to_grade( int $score ): string {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 65 ) {
			return 'C';
		}
		if ( $score >= 50 ) {
			return 'D';
		}
		return 'F';
	}
}

<?php
/**
 * Local path scanning for vip-go-ci.
 *
 * Scans a local folder for PHP lint and PHPCS issues
 * without requiring GitHub credentials or a git repository.
 *
 * @package Automattic/vip-go-ci
 */

declare(strict_types=1);

/**
 * Scan a local folder for PHP lint, PHPCS, and SVG issues.
 * Outputs results as JSON to stdout and optionally to a file via --output.
 *
 * @param array $options Options array.
 *
 * @return int Exit status: VIPGOCI_EXIT_CODE_ISSUES if errors found, VIPGOCI_EXIT_NORMAL otherwise.
 */
function vipgoci_local_path_scan( array $options ): int {
	$local_path = rtrim( $options['local-path'], '/' );

	vipgoci_log(
		'Starting local path scan',
		array(
			'local-path'  => $local_path,
			'lint'        => $options['lint'],
			'phpcs'       => $options['phpcs'],
			'svg-checks'  => $options['svg-checks'],
		)
	);

	if ( ! is_dir( $local_path ) || ! is_readable( $local_path ) ) {
		vipgoci_sysexit(
			'--local-path is not a readable directory',
			array( 'local-path' => $local_path ),
			VIPGOCI_EXIT_USAGE_ERROR
		);
	}

	$results = array(
		'issues' => array(),
		'stats'  => array(
			'error'   => 0,
			'warning' => 0,
		),
	);

	/*
	 * PHP lint scan.
	 */
	if ( true === $options['lint'] ) {
		$lint_files = vipgoci_scandir_git_repo(
			$local_path,
			true,
			array(
				'file_extensions' => $options['lint-file-extensions'],
				'skip_folders'    => $options['lint-skip-folders'],
			)
		);

		vipgoci_log(
			'PHP lint scanning local files',
			array( 'files_count' => count( $lint_files ) )
		);

		foreach ( $lint_files as $filename ) {
			$file_contents = @file_get_contents( $local_path . '/' . $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( false === $file_contents ) {
				continue;
			}

			$temp_file = vipgoci_save_temp_file( 'vipgoci-local-lint-', null, $file_contents );

			$current_file_intermediary_results = array();

			foreach ( $options['lint-php-versions'] as $php_version ) {
				$raw = vipgoci_lint_do_scan_file(
					$options['lint-php-version-paths'][ $php_version ],
					$temp_file
				);

				if ( null !== $raw ) {
					$issues = vipgoci_lint_parse_results( $filename, $temp_file, $raw );

					vipgoci_lint_scan_multiple_files_process_intermediate_results(
						$current_file_intermediary_results,
						$php_version,
						$issues
					);
				}
			}

			$file_issues = vipgoci_lint_scan_multiple_files_merge_results_by_php_version(
				$current_file_intermediary_results
			);

			unlink( $temp_file );

			foreach ( $file_issues as $line => $line_issues ) {
				foreach ( $line_issues as $issue ) {
					$results['issues'][ $filename ][] = array(
						'type'     => 'lint',
						'line'     => (int) $line,
						'message'  => $issue['message'],
						'level'    => $issue['level'],
						'severity' => (int) $issue['severity'],
					);

					if ( 'ERROR' === $issue['level'] ) {
						$results['stats']['error']++;
					} else {
						$results['stats']['warning']++;
					}
				}
			}
		}
	}

	/*
	 * PHPCS scan.
	 */
	if ( true === $options['phpcs'] ) {
		$phpcs_files = vipgoci_scandir_git_repo(
			$local_path,
			true,
			array(
				'file_extensions' => $options['phpcs-file-extensions'],
				'skip_folders'    => $options['phpcs-skip-folders'],
			)
		);

		vipgoci_log(
			'PHPCS scanning local files',
			array( 'files_count' => count( $phpcs_files ) )
		);

		foreach ( $phpcs_files as $filename ) {
			$file_contents = @file_get_contents( $local_path . '/' . $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( false === $file_contents ) {
				continue;
			}

			/*
			 * Place the file in a temp directory using its real basename so
			 * PHPCS filename-based sniffs (e.g. WordPress.Files.FileName) see
			 * the correct name rather than the random temp file name.
			 */
			$temp_dir  = rtrim( sys_get_temp_dir(), '/' ) . '/vipgoci-local-' . uniqid( '', true );
			mkdir( $temp_dir, 0700 );
			$temp_file = $temp_dir . '/' . basename( $filename );
			file_put_contents( $temp_file, $file_contents );

			$file_issues_str = vipgoci_phpcs_do_scan(
				$temp_file,
				$options['phpcs-path'],
				$options['phpcs-php-path'],
				$options['phpcs-standard'],
				$options['phpcs-sniffs-exclude'],
				$options['phpcs-severity'],
				$options['phpcs-runtime-set']
			);

			unlink( $temp_file );
			rmdir( $temp_dir );

			if ( null === $file_issues_str ) {
				continue;
			}

			$decoded = json_decode( rtrim( $file_issues_str, "\n" ), true );

			if ( null === $decoded || ! isset( $decoded['files'] ) ) {
				continue;
			}

			foreach ( $decoded['files'] as $phpcs_file_data ) {
				foreach ( $phpcs_file_data['messages'] ?? array() as $msg ) {
					$results['issues'][ $filename ][] = array(
						'type'     => 'phpcs',
						'line'     => (int) $msg['line'],
						'column'   => (int) $msg['column'],
						'message'  => $msg['message'],
						'source'   => $msg['source'],
						'level'    => $msg['type'],
						'severity' => (int) $msg['severity'],
						'fixable'  => (bool) $msg['fixable'],
					);

					if ( 'ERROR' === $msg['type'] ) {
						$results['stats']['error']++;
					} else {
						$results['stats']['warning']++;
					}
				}
			}
		}
	}

	/*
	 * SVG scan.
	 */
	if ( true === $options['svg-checks'] ) {
		$svg_files = vipgoci_scandir_git_repo(
			$local_path,
			true,
			array(
				'file_extensions' => $options['svg-file-extensions'],
				'skip_folders'    => array(),
			)
		);

		vipgoci_log(
			'SVG scanning local files',
			array( 'files_count' => count( $svg_files ) )
		);

		$disallowed_tokens = array( '<?php', '<?=' );

		foreach ( $svg_files as $filename ) {
			$file_contents = @file_get_contents( $local_path . '/' . $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( false === $file_contents ) {
				continue;
			}

			$temp_file = vipgoci_save_temp_file( 'vipgoci-local-svg-', 'svg', $file_contents );

			/*
			 * Initialize results structure that both the external scanner
			 * and the token scanner write into.
			 */
			$svg_results = array(
				'totals' => array( 'errors' => 0, 'warnings' => 0, 'fixable' => 0 ),
				'files'  => array(
					$temp_file => array( 'errors' => 0, 'messages' => array() ),
				),
			);

			/*
			 * Run the external SVG sanitizer if a scanner path is configured.
			 */
			if ( ! empty( $options['svg-scanner-path'] ) ) {
				$scanner_raw = vipgoci_svg_do_scan_with_scanner(
					$options['svg-scanner-path'],
					$options['svg-php-path'],
					$temp_file
				);

				if ( null !== $scanner_raw ) {
					$scanner_decoded = json_decode( $scanner_raw, true );

					if ( null !== $scanner_decoded ) {
						$svg_results = $scanner_decoded;

						if ( ! isset( $svg_results['files'][ $temp_file ] ) ) {
							$svg_results['files'][ $temp_file ] = array( 'errors' => 0, 'messages' => array() );
						}
					}
				}
			}

			/*
			 * Always scan for forbidden PHP tags regardless of external scanner.
			 */
			vipgoci_svg_look_for_specific_tokens( $disallowed_tokens, $temp_file, $svg_results );

			unlink( $temp_file );

			/*
			 * Normalize messages and collect into results.
			 */
			$messages = $svg_results['files'][ $temp_file ]['messages'] ?? array();

			foreach ( $messages as $msg ) {
				$results['issues'][ $filename ][] = array(
					'type'     => 'svg',
					'line'     => (int) ( $msg['line'] ?? 0 ),
					'column'   => (int) ( $msg['column'] ?? 0 ),
					'message'  => $msg['message'],
					'source'   => $msg['source'] ?? 'VipgociInternal.SVG.DisallowedTags',
					'level'    => 'ERROR',
					'severity' => 5,
					'fixable'  => false,
				);

				$results['stats']['error']++;
			}
		}
	}

	$json_output = json_encode( $results, JSON_PRETTY_PRINT ) . PHP_EOL;

	echo $json_output;

	if ( ! empty( $options['output'] ) ) {
		file_put_contents( $options['output'], $json_output );
	}

	vipgoci_log(
		'Local path scan complete',
		array(
			'errors'   => $results['stats']['error'],
			'warnings' => $results['stats']['warning'],
		)
	);

	return $results['stats']['error'] > 0
		? VIPGOCI_EXIT_CODE_ISSUES
		: VIPGOCI_EXIT_NORMAL;
}

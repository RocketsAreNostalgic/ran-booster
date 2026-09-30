<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Native local filesystem operations are required to verify inode, lock and atomic-replacement semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions

use ParseError;
use Throwable;

/**
 * Adds Booster's fixed encrypted-sidecar directory to a known wp-config.php.
 *
 * This class deliberately does not discover the config path or the sidecar
 * location. Callers must supply both after completing the separate location
 * discovery and filesystem probes.
 */
class WpConfigSecretsPathWriter {

	private const DIRECTORY_CONSTANT_NAME        = 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR';
	private const UNSUPPORTED_FILE_CONSTANT_NAME = 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE';
	private const OWNED_MARKER                   = '/* RAN Booster encrypted secrets storage. */';
	private const MARKER                         = "/* That's all, stop editing! Happy publishing. */";
	private const MAX_BYTES                      = 1048576;
	private const LOCK_SUFFIX                    = '.ran-booster.lock';
	private const TEMP_PREFIX                    = '.ran-booster-wp-config-';

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	public function write( string $configPath, string $sidecarPath ): WpConfigPathWriteResult {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$this->edit( $configPath, $sidecarPath, false, null );

		return new WpConfigPathWriteResult();
	}

	/**
	 * Atomically retarget the exact definition previously inserted by this writer.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function retargetOwnedDefinition(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		string $configPath,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		string $currentSidecarPath,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		string $replacementSidecarPath
	): WpConfigPathWriteResult|false {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		if ( $currentSidecarPath === $replacementSidecarPath ) {
			$this->fail( 'sidecar_path_unchanged', 'The encrypted secrets path is already configured.' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		if ( ! $this->edit( $configPath, $currentSidecarPath, false, $replacementSidecarPath ) ) {
			return false;
		}

		return new WpConfigPathWriteResult();
	}

	/**
	 * Removes only the exact definition block previously inserted by this writer.
	 *
	 * @return bool Whether the owned definition block was removed.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	public function removeOwnedDefinition( string $configPath, string $sidecarPath ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		return $this->edit( $configPath, $sidecarPath, true, null );
	}

	/**
	 * Verify that the exact owned definition can be removed without changing it.
	 *
	 * @return bool Whether the exact owned definition is present.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	public function assertOwnedDefinitionRemovable( string $configPath, string $sidecarPath ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		if ( ! $this->hasOwnedDefinition( $configPath, $sidecarPath ) ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$directory = dirname( $configPath );
		if ( ! is_dir( $directory ) || is_link( $directory ) || ! is_writable( $directory ) ) {
			$this->fail( 'config_directory_invalid', 'The WordPress configuration directory is not safe and writable.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$this->inspect_config_path( $configPath );

		return true;
	}

	/**
	 * Report whether the exact directory definition was inserted by this writer.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	public function hasOwnedDefinition( string $configPath, string $sidecarPath ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$this->assert_absolute_safe_path( $configPath, 'config_path_invalid' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$this->assert_absolute_safe_path( $sidecarPath, 'sidecar_path_invalid' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		if ( $configPath === $sidecarPath ) {
			$this->fail( 'sidecar_path_invalid', 'The encrypted secrets path is not safe for automatic configuration.' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$preflight = $this->inspect_config_path( $configPath, false );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$candidate = $this->build_removal_candidate( $preflight['contents'], $sidecarPath );
		if ( null === $candidate ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		$this->validate_removal_candidate( $candidate, $sidecarPath );

		return true;
	}

	private function edit(
		string $config_path,
		string $sidecar_path,
		bool $remove,
		?string $replacement_sidecar_path
	): bool {
		$this->assert_absolute_safe_path( $config_path, 'config_path_invalid' );
		$this->assert_absolute_safe_path( $sidecar_path, 'sidecar_path_invalid' );
		if ( null !== $replacement_sidecar_path ) {
			$this->assert_absolute_safe_path( $replacement_sidecar_path, 'sidecar_path_invalid' );
		}
		if ( $config_path === $sidecar_path ) {
			$this->fail( 'sidecar_path_invalid', 'The encrypted secrets path is not safe for automatic configuration.' );
		}
		if ( null !== $replacement_sidecar_path
			&& ( $config_path === $replacement_sidecar_path || $sidecar_path === $replacement_sidecar_path )
		) {
			$this->fail( 'sidecar_path_invalid', 'The replacement encrypted secrets path is not safe for automatic configuration.' );
		}

		$preflight = null;
		if ( $remove || null !== $replacement_sidecar_path ) {
			$preflight = $this->inspect_config_path( $config_path, false );
			$candidate = $remove
				? $this->build_removal_candidate( $preflight['contents'], $sidecar_path )
				: $this->build_retarget_candidate( $preflight['contents'], $sidecar_path, (string) $replacement_sidecar_path );
			if ( null === $candidate ) {
				return false;
			}
		}

		$directory = dirname( $config_path );
		if ( ! is_dir( $directory ) || is_link( $directory ) || ! is_writable( $directory ) ) {
			$this->fail( 'config_directory_invalid', 'The WordPress configuration directory is not safe and writable.' );
		}

		$writable_preflight = $this->inspect_config_path( $config_path );
		if ( null !== $preflight && ! $this->same_snapshot( $preflight, $writable_preflight ) ) {
			$this->fail( 'config_changed', 'The WordPress configuration changed before it could be edited.' );
		}
		$preflight = $writable_preflight;
		$lock_path = $config_path . self::LOCK_SUFFIX;
		$lock      = $this->openLock( $lock_path );

		try {
			$this->assert_lock_handle( $lock, $lock_path );
			if ( ! $this->changePermissions( $lock_path, 0600 ) ) {
				$this->fail( 'lock_permissions_failed', 'Could not secure the WordPress configuration edit lock.' );
			}
			$this->assert_lock_handle( $lock, $lock_path );
			$lock_stat = fstat( $lock );
			if ( false === $lock_stat || 0600 !== ( $lock_stat['mode'] & 0777 ) ) {
				$this->fail( 'lock_permissions_failed', 'Could not secure the WordPress configuration edit lock.' );
			}
			if ( ! $this->acquireLock( $lock ) ) {
				$this->fail( 'lock_failed', 'Could not lock the WordPress configuration for editing.' );
			}

			$locked = $this->inspect_config_path( $config_path );
			if ( ! $this->same_snapshot( $preflight, $locked ) ) {
				$this->fail( 'config_changed', 'The WordPress configuration changed before it could be edited.' );
			}

			$original      = $locked['contents'];
				$candidate = $remove
					? $this->build_removal_candidate( $original, $sidecar_path )
					: ( null === $replacement_sidecar_path
						? $this->build_candidate( $original, $sidecar_path )
						: $this->build_retarget_candidate( $original, $sidecar_path, $replacement_sidecar_path ) );
			if ( null === $candidate ) {
				return false;
			}
			if ( $remove ) {
				$this->validate_removal_candidate( $candidate, $sidecar_path );
			} elseif ( null !== $replacement_sidecar_path ) {
				$this->validate_retarget_candidate( $candidate, $sidecar_path, $replacement_sidecar_path );
			} else {
				$this->validate_candidate( $candidate );
			}

			$this->replace_config( $config_path, $candidate, $locked );
		} finally {
			$this->releaseLock( $lock );
			fclose( $lock );
		}

		return true;
	}

	/**
	 * @return array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int}
	 */
	private function inspect_config_path( string $path, bool $require_writable = true ): array {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) || ( $require_writable && ! is_writable( $path ) ) ) {
			$this->fail( 'config_file_invalid', 'The WordPress configuration is not a writable regular file.' );
		}

		$path_stat = lstat( $path );
		if ( false === $path_stat
			|| 0100000 !== ( $path_stat['mode'] & 0170000 )
			|| 1 !== $path_stat['nlink']
		) {
			$this->fail( 'config_file_invalid', 'The WordPress configuration is not a private single-link file.' );
		}
		if ( 0 !== ( $path_stat['mode'] & 0022 ) ) {
			$this->fail( 'config_permissions_unsafe', 'The WordPress configuration is group- or world-writable.' );
		}
		if ( $path_stat['size'] < 1 || $path_stat['size'] > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The WordPress configuration has an unsupported size.' );
		}
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $path_stat['uid'] ) {
			$this->fail( 'config_owner_invalid', 'The WordPress configuration is not owned by the current process owner.' );
		}

		$handle = $this->openConfigForRead( $path );
		try {
			$handle_stat = fstat( $handle );
			if ( false === $handle_stat || ! $this->same_identity( $path_stat, $handle_stat ) ) {
				$this->fail( 'config_file_invalid', 'The WordPress configuration changed while it was opened.' );
			}

			$contents = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( false === $contents || strlen( $contents ) !== $handle_stat['size'] ) {
				$this->fail( 'config_read_failed', 'Could not read the complete WordPress configuration.' );
			}
		} finally {
			fclose( $handle );
		}

		clearstatcache( true, $path );
		$after = lstat( $path );
		if ( false === $after || ! $this->same_metadata( $path_stat, $after ) ) {
			$this->fail( 'config_changed', 'The WordPress configuration changed while it was read.' );
		}

		return array(
			'contents' => $contents,
			'dev'      => $after['dev'],
			'ino'      => $after['ino'],
			'mode'     => $after['mode'],
			'uid'      => $after['uid'],
			'gid'      => $after['gid'],
			'nlink'    => $after['nlink'],
			'size'     => $after['size'],
			'mtime'    => $after['mtime'],
			'ctime'    => $after['ctime'],
		);
	}

	private function build_candidate( string $original, string $sidecar_path ): string {
		$line_ending = $this->line_ending( $original );
		$tokens      = $this->tokens( $original );
		$offset      = 0;
		$marker_at   = array();

		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token )
				&& T_COMMENT === $token[0]
				&& self::MARKER === trim( $text )
			) {
				$line_start = strrpos( substr( $original, 0, $offset ), "\n" );
				$line_start = false === $line_start ? 0 : $line_start + 1;
				$prefix     = substr( $original, $line_start, $offset - $line_start );
				if ( '' !== trim( $prefix, " \t\r" ) ) {
					$this->fail( 'marker_invalid', 'The WordPress stop-editing marker is not on a supported line.' );
				}
				$marker_at[] = $line_start;
			}
			$offset += strlen( $text );
		}

		if ( 1 !== count( $marker_at ) ) {
			$this->fail( 'marker_invalid', 'The WordPress configuration must contain one standard stop-editing marker.' );
		}
		if ( $this->contains_literal_constant_definition( $tokens ) ) {
			$this->fail( 'constant_exists', 'The encrypted secrets path constant is already defined.' );
		}

		$definition  = self::OWNED_MARKER . $line_ending;
		$definition .= "define( '" . self::DIRECTORY_CONSTANT_NAME . "', " . $this->export_php_string( dirname( $sidecar_path ) ) . ' );' . $line_ending;
		$definition .= $line_ending;

		return substr( $original, 0, $marker_at[0] ) . $definition . substr( $original, $marker_at[0] );
	}

	private function build_removal_candidate( string $original, string $sidecar_path ): ?string {
		$tokens  = $this->tokens( $original );
		$offset  = 0;
		$matches = array();

		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token )
				&& T_COMMENT === $token[0]
				&& self::OWNED_MARKER === $text
			) {
				foreach ( array( "\n", "\r\n" ) as $line_ending ) {
					$block = $this->owned_definition_block( $sidecar_path, $line_ending );
					if ( substr( $original, $offset, strlen( $block ) ) === $block ) {
						$matches[] = array( $offset, strlen( $block ) );
					}
				}
			}
			$offset += strlen( $text );
		}

		if ( array() === $matches ) {
			return null;
		}
		if ( 1 !== count( $matches ) ) {
			$this->fail( 'owned_definition_ambiguous', 'The automatic encrypted secrets definition is ambiguous.' );
		}

		return substr( $original, 0, $matches[0][0] )
			. substr( $original, $matches[0][0] + $matches[0][1] );
	}

	private function owned_definition_block( string $sidecar_path, string $line_ending ): string {
		return self::OWNED_MARKER . $line_ending
			. "define( '" . self::DIRECTORY_CONSTANT_NAME . "', " . $this->export_php_string( dirname( $sidecar_path ) ) . ' );' . $line_ending
			. $line_ending;
	}

	private function build_retarget_candidate(
		string $original,
		string $current_sidecar_path,
		string $replacement_sidecar_path
	): ?string {
		if ( null === $this->build_removal_candidate( $original, $current_sidecar_path ) ) {
			return null;
		}

		$line_ending = $this->line_ending( $original );
		$current     = $this->owned_definition_block( $current_sidecar_path, $line_ending );
		$replacement = $this->owned_definition_block( $replacement_sidecar_path, $line_ending );
		$count       = 0;
		$candidate   = str_replace( $current, $replacement, $original, $count );

		return 1 === $count ? $candidate : null;
	}

	private function validate_removal_candidate( string $candidate, string $sidecar_path ): void {
		if ( strlen( $candidate ) > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The edited WordPress configuration would exceed the size limit.' );
		}
		if ( null !== $this->build_removal_candidate( $candidate, $sidecar_path ) ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration retained the automatic definition.' );
		}
	}

	private function validate_retarget_candidate(
		string $candidate,
		string $current_sidecar_path,
		string $replacement_sidecar_path
	): void {
		$this->validate_candidate( $candidate );
		if ( null !== $this->build_removal_candidate( $candidate, $current_sidecar_path )
			|| null === $this->build_removal_candidate( $candidate, $replacement_sidecar_path )
		) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration did not contain the expected replacement definition.' );
		}
	}

	private function validate_candidate( string $candidate ): void {
		if ( strlen( $candidate ) > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The edited WordPress configuration would exceed the size limit.' );
		}

		$tokens = $this->tokens( $candidate );
		if ( ! $this->contains_literal_constant_definition( $tokens ) ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration did not contain the expected definition.' );
		}

		$definitions = 0;
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
				continue;
			}
			$name = $this->literal_define_name( $tokens, $index );
			if ( self::DIRECTORY_CONSTANT_NAME === $name ) {
				++$definitions;
			}
			if ( self::UNSUPPORTED_FILE_CONSTANT_NAME === $name ) {
				$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration retained a legacy encrypted secrets definition.' );
			}
		}
		if ( 1 !== $definitions ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration is ambiguous.' );
		}
	}

	/**
	 * @param array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int} $original
	 */
	private function replace_config( string $path, string $candidate, array $original ): void {
		$temporary = '';
		$handle    = null;
		$replaced  = null;

		try {
			list( $temporary, $handle ) = $this->createTemporary( dirname( $path ) );
			$this->assert_temporary_handle( $handle, $temporary );
			if ( ! $this->changePermissions( $temporary, 0600 ) ) {
				$this->fail( 'temporary_permissions_failed', 'Could not secure the temporary WordPress configuration.' );
			}
			if ( ! $this->preserve_ownership( $temporary, $original['uid'], $original['gid'] ) ) {
				$this->fail( 'temporary_ownership_failed', 'Could not preserve WordPress configuration ownership.' );
			}
			$this->assert_temporary_handle( $handle, $temporary );

			$this->write_all( $handle, $candidate );
			if ( ! $this->flushHandle( $handle ) ) {
				$this->fail( 'temporary_flush_failed', 'Could not flush the edited WordPress configuration.' );
			}
			if ( ! $this->syncHandle( $handle ) ) {
				$this->fail( 'temporary_sync_failed', 'Could not synchronize the edited WordPress configuration.' );
			}

			if ( $candidate !== $this->readBack( $temporary ) ) {
				$this->fail( 'temporary_readback_failed', 'The edited WordPress configuration failed its read-back check.' );
			}
			if ( ! $this->changePermissions( $temporary, $original['mode'] & 0777 ) || ! $this->syncHandle( $handle ) ) {
				$this->fail( 'temporary_permissions_failed', 'Could not preserve WordPress configuration permissions.' );
			}

			$temporary_stat = fstat( $handle );
			if ( false === $temporary_stat
				|| 0100000 !== ( $temporary_stat['mode'] & 0170000 )
				|| 1 !== $temporary_stat['nlink']
				|| ( $original['mode'] & 0777 ) !== ( $temporary_stat['mode'] & 0777 )
				|| $original['uid'] !== $temporary_stat['uid']
				|| $original['gid'] !== $temporary_stat['gid']
			) {
				$this->fail( 'temporary_metadata_invalid', 'The edited WordPress configuration metadata could not be verified.' );
			}
			fclose( $handle );
			$handle = null;

			$this->beforeFinalConfigCheck( $path );
			$current = $this->inspect_config_path( $path );
			if ( ! $this->same_snapshot( $original, $current ) ) {
				$this->fail( 'config_changed', 'The WordPress configuration changed while it was being edited.' );
			}

			if ( ! $this->replacePath( $temporary, $path ) ) {
				$this->fail( 'replace_failed', 'Could not atomically replace the WordPress configuration.' );
			}
			$temporary = '';
			$replaced  = $temporary_stat;

			$installed = $this->readInstalled( $path );
			if ( $candidate !== $installed['contents']
				|| ( $original['mode'] & 0777 ) !== ( $installed['mode'] & 0777 )
				|| $original['uid'] !== $installed['uid']
				|| $original['gid'] !== $installed['gid']
				|| ! $this->same_identity( $replaced, $installed )
			) {
				$this->attempt_rollback( $path, $original, $replaced );
				$this->fail( 'replacement_readback_failed', 'The installed WordPress configuration failed verification.' );
			}
			$this->invalidateOpcodeCache( $path );
		} catch ( WpConfigPathWriteException $exception ) {
			if ( null !== $replaced ) {
				$this->attempt_rollback( $path, $original, $replaced );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is propagated, not rendered.
			throw $exception;
		} catch ( Throwable $exception ) {
			if ( null !== $replaced ) {
				$this->attempt_rollback( $path, $original, $replaced );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is retained, not rendered.
			throw new WpConfigPathWriteException(
				'filesystem_failure',
				'The WordPress configuration could not be updated safely.',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The chained filesystem exception is retained for rollback diagnostics, not rendered.
				$exception
			);
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
				unlink( $temporary );
			}
		}
	}

	/**
	 * @param array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int} $original
	 * @param array<string, int> $replaced
	 */
	private function attempt_rollback( string $path, array $original, array $replaced ): void {
		try {
			$current = lstat( $path );
			if ( false === $current || ! $this->same_identity( $replaced, $current ) || 1 !== $current['nlink'] ) {
				return;
			}

			list( $temporary, $handle ) = $this->createTemporary( dirname( $path ) );
			try {
				$this->assert_temporary_handle( $handle, $temporary );
				if ( ! $this->changePermissions( $temporary, 0600 )
					|| ! $this->preserve_ownership( $temporary, $original['uid'], $original['gid'] )
				) {
					return;
				}
				$this->write_all( $handle, $original['contents'] );
				if ( ! $this->flushHandle( $handle ) || ! $this->syncHandle( $handle ) ) {
					return;
				}
				if ( $original['contents'] !== $this->readBack( $temporary ) ) {
					return;
				}
				if ( ! $this->changePermissions( $temporary, $original['mode'] & 0777 ) || ! $this->syncHandle( $handle ) ) {
					return;
				}
				fclose( $handle );
				$handle = null;
				if ( $this->replacePath( $temporary, $path ) ) {
					$temporary = '';
				}
			} finally {
				if ( is_resource( $handle ) ) {
					fclose( $handle );
				}
				if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
					unlink( $temporary );
				}
			}
		} catch ( Throwable ) {
			// The original bytes remain in memory only; rollback is deliberately best-effort.
			return;
		}
	}

	/**
	 * @return list<array{0: int, 1: string, 2: int}|string>
	 */
	private function tokens( string $contents ): array {
		try {
			/** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
			$tokens = token_get_all( $contents, TOKEN_PARSE );
		} catch ( ParseError $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The parse exception is retained, not rendered.
			throw new WpConfigPathWriteException(
				'config_parse_failed',
				'The WordPress configuration does not parse as supported PHP.',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The chained filesystem exception is retained for rollback diagnostics, not rendered.
				$exception
			);
		}

		return $tokens;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function contains_literal_constant_definition( array $tokens ): bool {
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_CONST === $token[0] ) {
				$constant_at = $index + 1;
				$this->skip_whitespace( $tokens, $constant_at );
				$name = $tokens[ $constant_at ] ?? null;
				if ( is_array( $name )
					&& T_STRING === $name[0]
					&& in_array( $name[1], array( self::DIRECTORY_CONSTANT_NAME, self::UNSUPPORTED_FILE_CONSTANT_NAME ), true )
				) {
					return true;
				}
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
				continue;
			}
			if ( in_array(
				$this->literal_define_name( $tokens, $index ),
				array( self::DIRECTORY_CONSTANT_NAME, self::UNSUPPORTED_FILE_CONSTANT_NAME ),
				true
			) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function literal_define_name( array $tokens, int $define_at ): ?string {
		$index = $define_at + 1;
		$this->skip_whitespace( $tokens, $index );
		if ( '(' !== ( $tokens[ $index ] ?? null ) ) {
			return null;
		}
		++$index;
		$this->skip_whitespace( $tokens, $index );
		$literal = $tokens[ $index ] ?? null;
		if ( ! is_array( $literal ) || T_CONSTANT_ENCAPSED_STRING !== $literal[0] ) {
			return null;
		}

		$value = $this->decode_php_string_literal( $literal[1] );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function skip_whitespace( array $tokens, int &$index ): void {
		while ( isset( $tokens[ $index ] )
			&& is_array( $tokens[ $index ] )
			&& T_WHITESPACE === $tokens[ $index ][0]
		) {
			++$index;
		}
	}

	private function decode_php_string_literal( string $literal ): ?string {
		if ( strlen( $literal ) < 2 ) {
			return null;
		}
		$quote = $literal[0];
		if ( "'" === $quote ) {
			return str_replace(
				array( "\\'", '\\\\' ),
				array( "'", '\\' ),
				substr( $literal, 1, -1 )
			);
		}
		if ( '"' === $quote ) {
			return stripcslashes( substr( $literal, 1, -1 ) );
		}

		return null;
	}

	private function export_php_string( string $value ): string {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
	}

	private function line_ending( string $contents ): string {
		$without_cr_lf = str_replace( "\r\n", '', $contents );
		if ( str_contains( $without_cr_lf, "\r" ) ) {
			$this->fail( 'line_endings_unsupported', 'The WordPress configuration uses unsupported line endings.' );
		}
		if ( str_contains( $contents, "\r\n" ) && str_contains( $without_cr_lf, "\n" ) ) {
			$this->fail( 'line_endings_unsupported', 'The WordPress configuration uses mixed line endings.' );
		}

		return str_contains( $contents, "\r\n" ) ? "\r\n" : "\n";
	}

	private function assert_absolute_safe_path( string $path, string $reason ): void {
		if ( '' === $path
			|| '/' !== $path[0]
			|| str_contains( $path, "\0" )
			|| preg_match( '/[\\x00-\\x1F\\x7F]/', $path )
			|| str_contains( $path, '//' )
			|| str_ends_with( $path, '/' )
			|| preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path )
		) {
			$this->fail( $reason, 'The supplied path is not an absolute safe POSIX file path.' );
		}
	}

	/**
	 * @param array<string, int|string> $first
	 * @param array<string, int|string> $second
	 */
	private function same_snapshot( array $first, array $second ): bool {
		return $first === $second;
	}

	/**
	 * @param array<string, int> $first
	 * @param array<string, int> $second
	 */
	private function same_identity( array $first, array $second ): bool {
		return $first['dev'] === $second['dev']
			&& $first['ino'] === $second['ino']
			&& 0100000 === ( $second['mode'] & 0170000 )
			&& 1 === $second['nlink'];
	}

	/**
	 * @param array<string, int> $first
	 * @param array<string, int> $second
	 */
	private function same_metadata( array $first, array $second ): bool {
		foreach ( array( 'dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime' ) as $key ) {
			if ( $first[ $key ] !== $second[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param resource $lock
	 */
	private function assert_lock_handle( mixed $lock, string $path ): void {
		$path_stat = lstat( $path );
		$lock_stat = fstat( $lock );
		if ( false === $path_stat
			|| false === $lock_stat
			|| ! $this->same_identity( $path_stat, $lock_stat )
			|| ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $path_stat['uid'] )
		) {
			$this->fail( 'lock_invalid', 'The WordPress configuration edit lock is not safe.' );
		}
	}

	/**
	 * @param resource $handle
	 */
	private function write_all( mixed $handle, string $contents ): void {
		$offset = 0;
		$length = strlen( $contents );
		while ( $offset < $length ) {
			$written = $this->writeHandle( $handle, substr( $contents, $offset ) );
			if ( false === $written || 0 === $written ) {
				$this->fail( 'temporary_write_failed', 'Could not write the complete edited WordPress configuration.' );
			}
			$offset += $written;
		}
	}

	/**
	 * @param resource $handle
	 */
	private function assert_temporary_handle( mixed $handle, string $path ): void {
		$path_stat   = lstat( $path );
		$handle_stat = fstat( $handle );
		if ( false === $path_stat || false === $handle_stat || ! $this->same_identity( $path_stat, $handle_stat ) ) {
			$this->fail( 'temporary_file_invalid', 'The temporary WordPress configuration is not safe.' );
		}
	}

	private function preserve_ownership( string $path, int $uid, int $gid ): bool {
		$stat = lstat( $path );
		if ( false === $stat ) {
			return false;
		}
		if ( $stat['uid'] !== $uid && ! $this->changeOwner( $path, $uid ) ) {
			return false;
		}
		if ( $stat['gid'] !== $gid && ! $this->changeGroup( $path, $gid ) ) {
			return false;
		}

		clearstatcache( true, $path );
		$stat = lstat( $path );

		return false !== $stat && $stat['uid'] === $uid && $stat['gid'] === $gid;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	protected function beforeFinalConfigCheck( string $configPath ): void {
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	protected function invalidateOpcodeCache( string $configPath ): void {
		if ( function_exists( 'opcache_invalidate' ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			opcache_invalidate( $configPath, true );
		}
	}

	/**
	 * @return resource
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function openConfigForRead( string $path ): mixed {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			$this->fail( 'config_read_failed', 'Could not open the WordPress configuration.' );
		}

		return $handle;
	}

	/**
	 * @return resource
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function openLock( string $path ): mixed {
		if ( is_link( $path ) ) {
			$this->fail( 'lock_invalid', 'The WordPress configuration edit lock is not safe.' );
		}
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			$this->fail( 'lock_open_failed', 'Could not open the WordPress configuration edit lock.' );
		}

		return $handle;
	}

	/**
	 * @param resource $lock
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function acquireLock( mixed $lock ): bool {
		return flock( $lock, LOCK_EX );
	}

	/**
	 * @param resource $lock
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function releaseLock( mixed $lock ): void {
		flock( $lock, LOCK_UN );
	}

	/**
	 * @return array{0: string, 1: resource}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function createTemporary( string $directory ): array {
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$path   = $directory . '/' . self::TEMP_PREFIX . bin2hex( random_bytes( 12 ) ) . '.php';
			$handle = fopen( $path, 'x+b' );
			if ( false !== $handle ) {
				return array( $path, $handle );
			}
		}

		$this->fail( 'temporary_create_failed', 'Could not create a private temporary WordPress configuration.' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function changePermissions( string $path, int $mode ): bool {
		return chmod( $path, $mode );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function changeOwner( string $path, int $uid ): bool {
		return chown( $path, $uid );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function changeGroup( string $path, int $gid ): bool {
		return chgrp( $path, $gid );
	}

	/**
	 * @param resource $handle
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function writeHandle( mixed $handle, string $contents ): int|false {
		return fwrite( $handle, $contents );
	}

	/**
	 * @param resource $handle
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function flushHandle( mixed $handle ): bool {
		return fflush( $handle );
	}

	/**
	 * @param resource $handle
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function syncHandle( mixed $handle ): bool {
		return ! function_exists( 'fsync' ) || fsync( $handle );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function readBack( string $path ): string|false {
		return file_get_contents( $path );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function replacePath( string $source, string $destination ): bool {
		return rename( $source, $destination );
	}

	/**
	 * @return array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int}
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	protected function readInstalled( string $path ): array {
		$snapshot = $this->inspect_config_path( $path );
		$this->tokens( $snapshot['contents'] );

		return $snapshot;
	}

	private function fail( string $reason, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These values form an exception, not output.
		throw new WpConfigPathWriteException( $reason, $message );
	}
}

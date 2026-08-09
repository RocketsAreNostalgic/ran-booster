<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Native local filesystem operations are required to verify inode, lock and atomic-replacement semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped

use ParseError;
use Throwable;

/**
 * Adds Booster's fixed encrypted-sidecar definition to a known wp-config.php.
 *
 * This class deliberately does not discover the config path or the sidecar
 * location. Callers must supply both after completing the separate location
 * discovery and filesystem probes.
 */
class WpConfigSecretsPathWriter {

	private const CONSTANT_NAME = 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE';
	private const OWNED_MARKER  = '/* RAN Booster encrypted secrets storage. */';
	private const MARKER        = "/* That's all, stop editing! Happy publishing. */";
	private const MAX_BYTES     = 1048576;
	private const LOCK_SUFFIX   = '.ran-booster.lock';
	private const TEMP_PREFIX   = '.ran-booster-wp-config-';

	public function write( string $configPath, string $sidecarPath ): WpConfigPathWriteResult {
		$this->edit( $configPath, $sidecarPath, false, null );

		return new WpConfigPathWriteResult();
	}

	/**
	 * Atomically retarget the exact definition previously inserted by this writer.
	 */
	public function retargetOwnedDefinition(
		string $configPath,
		string $currentSidecarPath,
		string $replacementSidecarPath
	): WpConfigPathWriteResult|false {
		if ( $currentSidecarPath === $replacementSidecarPath ) {
			$this->fail( 'sidecar_path_unchanged', 'The encrypted secrets path is already configured.' );
		}

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
	public function removeOwnedDefinition( string $configPath, string $sidecarPath ): bool {
		return $this->edit( $configPath, $sidecarPath, true, null );
	}

	/**
	 * Verify that the exact owned definition can be removed without changing it.
	 *
	 * @return bool Whether the exact owned definition is present.
	 */
	public function assertOwnedDefinitionRemovable( string $configPath, string $sidecarPath ): bool {
		$this->assertAbsoluteSafePath( $configPath, 'config_path_invalid' );
		$this->assertAbsoluteSafePath( $sidecarPath, 'sidecar_path_invalid' );
		if ( $configPath === $sidecarPath ) {
			$this->fail( 'sidecar_path_invalid', 'The encrypted secrets path is not safe for automatic configuration.' );
		}

		$directory = dirname( $configPath );
		if ( ! is_dir( $directory ) || is_link( $directory ) || ! is_writable( $directory ) ) {
			$this->fail( 'config_directory_invalid', 'The WordPress configuration directory is not safe and writable.' );
		}

		$preflight = $this->inspectConfigPath( $configPath );
		$candidate = $this->buildRemovalCandidate( $preflight['contents'], $sidecarPath );
		if ( null === $candidate ) {
			return false;
		}

		$this->validateRemovalCandidate( $candidate, $sidecarPath );

		return true;
	}

	private function edit(
		string $configPath,
		string $sidecarPath,
		bool $remove,
		?string $replacementSidecarPath
	): bool {
		$this->assertAbsoluteSafePath( $configPath, 'config_path_invalid' );
		$this->assertAbsoluteSafePath( $sidecarPath, 'sidecar_path_invalid' );
		if ( null !== $replacementSidecarPath ) {
			$this->assertAbsoluteSafePath( $replacementSidecarPath, 'sidecar_path_invalid' );
		}
		if ( $configPath === $sidecarPath ) {
			$this->fail( 'sidecar_path_invalid', 'The encrypted secrets path is not safe for automatic configuration.' );
		}
		if ( null !== $replacementSidecarPath
			&& ( $configPath === $replacementSidecarPath || $sidecarPath === $replacementSidecarPath )
		) {
			$this->fail( 'sidecar_path_invalid', 'The replacement encrypted secrets path is not safe for automatic configuration.' );
		}

		$preflight = null;
		if ( $remove || null !== $replacementSidecarPath ) {
			$preflight = $this->inspectConfigPath( $configPath, false );
			$candidate = $remove
				? $this->buildRemovalCandidate( $preflight['contents'], $sidecarPath )
				: $this->buildRetargetCandidate( $preflight['contents'], $sidecarPath, (string) $replacementSidecarPath );
			if ( null === $candidate ) {
				return false;
			}
		}

		$directory = dirname( $configPath );
		if ( ! is_dir( $directory ) || is_link( $directory ) || ! is_writable( $directory ) ) {
			$this->fail( 'config_directory_invalid', 'The WordPress configuration directory is not safe and writable.' );
		}

		$writablePreflight = $this->inspectConfigPath( $configPath );
		if ( null !== $preflight && ! $this->sameSnapshot( $preflight, $writablePreflight ) ) {
			$this->fail( 'config_changed', 'The WordPress configuration changed before it could be edited.' );
		}
		$preflight = $writablePreflight;
		$lockPath  = $configPath . self::LOCK_SUFFIX;
		$lock      = $this->openLock( $lockPath );

		try {
			$this->assertLockHandle( $lock, $lockPath );
			if ( ! $this->changePermissions( $lockPath, 0600 ) ) {
				$this->fail( 'lock_permissions_failed', 'Could not secure the WordPress configuration edit lock.' );
			}
			$this->assertLockHandle( $lock, $lockPath );
			$lockStat = fstat( $lock );
			if ( false === $lockStat || 0600 !== ( $lockStat['mode'] & 0777 ) ) {
				$this->fail( 'lock_permissions_failed', 'Could not secure the WordPress configuration edit lock.' );
			}
			if ( ! $this->acquireLock( $lock ) ) {
				$this->fail( 'lock_failed', 'Could not lock the WordPress configuration for editing.' );
			}

			$locked = $this->inspectConfigPath( $configPath );
			if ( ! $this->sameSnapshot( $preflight, $locked ) ) {
				$this->fail( 'config_changed', 'The WordPress configuration changed before it could be edited.' );
			}

			$original      = $locked['contents'];
				$candidate = $remove
					? $this->buildRemovalCandidate( $original, $sidecarPath )
					: ( null === $replacementSidecarPath
						? $this->buildCandidate( $original, $sidecarPath )
						: $this->buildRetargetCandidate( $original, $sidecarPath, $replacementSidecarPath ) );
			if ( null === $candidate ) {
				return false;
			}
			if ( $remove ) {
				$this->validateRemovalCandidate( $candidate, $sidecarPath );
			} elseif ( null !== $replacementSidecarPath ) {
				$this->validateRetargetCandidate( $candidate, $sidecarPath, $replacementSidecarPath );
			} else {
				$this->validateCandidate( $candidate );
			}

			$this->replaceConfig( $configPath, $candidate, $locked );
		} finally {
			$this->releaseLock( $lock );
			fclose( $lock );
		}

		return true;
	}

	/**
	 * @return array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int}
	 */
	private function inspectConfigPath( string $path, bool $requireWritable = true ): array {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) || ( $requireWritable && ! is_writable( $path ) ) ) {
			$this->fail( 'config_file_invalid', 'The WordPress configuration is not a writable regular file.' );
		}

		$pathStat = lstat( $path );
		if ( false === $pathStat
			|| 0100000 !== ( $pathStat['mode'] & 0170000 )
			|| 1 !== $pathStat['nlink']
		) {
			$this->fail( 'config_file_invalid', 'The WordPress configuration is not a private single-link file.' );
		}
		if ( 0 !== ( $pathStat['mode'] & 0022 ) ) {
			$this->fail( 'config_permissions_unsafe', 'The WordPress configuration is group- or world-writable.' );
		}
		if ( $pathStat['size'] < 1 || $pathStat['size'] > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The WordPress configuration has an unsupported size.' );
		}
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $pathStat['uid'] ) {
			$this->fail( 'config_owner_invalid', 'The WordPress configuration is not owned by the current process owner.' );
		}

		$handle = $this->openConfigForRead( $path );
		try {
			$handleStat = fstat( $handle );
			if ( false === $handleStat || ! $this->sameIdentity( $pathStat, $handleStat ) ) {
				$this->fail( 'config_file_invalid', 'The WordPress configuration changed while it was opened.' );
			}

			$contents = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( false === $contents || strlen( $contents ) !== $handleStat['size'] ) {
				$this->fail( 'config_read_failed', 'Could not read the complete WordPress configuration.' );
			}
		} finally {
			fclose( $handle );
		}

		clearstatcache( true, $path );
		$after = lstat( $path );
		if ( false === $after || ! $this->sameMetadata( $pathStat, $after ) ) {
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

	private function buildCandidate( string $original, string $sidecarPath ): string {
		$lineEnding = $this->lineEnding( $original );
		$tokens     = $this->tokens( $original );
		$offset     = 0;
		$markerAt   = array();

		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token )
				&& T_COMMENT === $token[0]
				&& self::MARKER === trim( $text )
			) {
				$lineStart = strrpos( substr( $original, 0, $offset ), "\n" );
				$lineStart = false === $lineStart ? 0 : $lineStart + 1;
				$prefix    = substr( $original, $lineStart, $offset - $lineStart );
				if ( '' !== trim( $prefix, " \t\r" ) ) {
					$this->fail( 'marker_invalid', 'The WordPress stop-editing marker is not on a supported line.' );
				}
				$markerAt[] = $lineStart;
			}
			$offset += strlen( $text );
		}

		if ( 1 !== count( $markerAt ) ) {
			$this->fail( 'marker_invalid', 'The WordPress configuration must contain one standard stop-editing marker.' );
		}
		if ( $this->containsLiteralConstantDefinition( $tokens ) ) {
			$this->fail( 'constant_exists', 'The encrypted secrets path constant is already defined.' );
		}

		$definition  = self::OWNED_MARKER . $lineEnding;
		$definition .= "define( '" . self::CONSTANT_NAME . "', " . $this->exportPhpString( $sidecarPath ) . ' );' . $lineEnding;
		$definition .= $lineEnding;

		return substr( $original, 0, $markerAt[0] ) . $definition . substr( $original, $markerAt[0] );
	}

	private function buildRemovalCandidate( string $original, string $sidecarPath ): ?string {
		$tokens  = $this->tokens( $original );
		$offset  = 0;
		$matches = array();

		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token )
				&& T_COMMENT === $token[0]
				&& self::OWNED_MARKER === $text
			) {
				foreach ( array( "\n", "\r\n" ) as $lineEnding ) {
					$block = $this->ownedDefinitionBlock( $sidecarPath, $lineEnding );
					if ( $block === substr( $original, $offset, strlen( $block ) ) ) {
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

	private function ownedDefinitionBlock( string $sidecarPath, string $lineEnding ): string {
		return self::OWNED_MARKER . $lineEnding
			. "define( '" . self::CONSTANT_NAME . "', " . $this->exportPhpString( $sidecarPath ) . ' );' . $lineEnding
			. $lineEnding;
	}

	private function buildRetargetCandidate(
		string $original,
		string $currentSidecarPath,
		string $replacementSidecarPath
	): ?string {
		if ( null === $this->buildRemovalCandidate( $original, $currentSidecarPath ) ) {
			return null;
		}

		$lineEnding  = $this->lineEnding( $original );
		$current     = $this->ownedDefinitionBlock( $currentSidecarPath, $lineEnding );
		$replacement = $this->ownedDefinitionBlock( $replacementSidecarPath, $lineEnding );
		$count       = 0;
		$candidate   = str_replace( $current, $replacement, $original, $count );

		return 1 === $count ? $candidate : null;
	}

	private function validateRemovalCandidate( string $candidate, string $sidecarPath ): void {
		if ( strlen( $candidate ) > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The edited WordPress configuration would exceed the size limit.' );
		}
		if ( null !== $this->buildRemovalCandidate( $candidate, $sidecarPath ) ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration retained the automatic definition.' );
		}
	}

	private function validateRetargetCandidate(
		string $candidate,
		string $currentSidecarPath,
		string $replacementSidecarPath
	): void {
		$this->validateCandidate( $candidate );
		if ( null !== $this->buildRemovalCandidate( $candidate, $currentSidecarPath )
			|| null === $this->buildRemovalCandidate( $candidate, $replacementSidecarPath )
		) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration did not contain the expected replacement definition.' );
		}
	}

	private function validateCandidate( string $candidate ): void {
		if ( strlen( $candidate ) > self::MAX_BYTES ) {
			$this->fail( 'config_size_unsupported', 'The edited WordPress configuration would exceed the size limit.' );
		}

		$tokens = $this->tokens( $candidate );
		if ( ! $this->containsLiteralConstantDefinition( $tokens ) ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration did not contain the expected definition.' );
		}

		$definitions = 0;
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
				continue;
			}
			$name = $this->literalDefineName( $tokens, $index );
			if ( self::CONSTANT_NAME === $name ) {
				++$definitions;
			}
		}
		if ( 1 !== $definitions ) {
			$this->fail( 'candidate_parse_failed', 'The edited WordPress configuration is ambiguous.' );
		}
	}

	/**
	 * @param array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int} $original
	 */
	private function replaceConfig( string $path, string $candidate, array $original ): void {
		$temporary = '';
		$handle    = null;
		$replaced  = null;

		try {
			list( $temporary, $handle ) = $this->createTemporary( dirname( $path ) );
			$this->assertTemporaryHandle( $handle, $temporary );
			if ( ! $this->changePermissions( $temporary, 0600 ) ) {
				$this->fail( 'temporary_permissions_failed', 'Could not secure the temporary WordPress configuration.' );
			}
			if ( ! $this->preserveOwnership( $temporary, $original['uid'], $original['gid'] ) ) {
				$this->fail( 'temporary_ownership_failed', 'Could not preserve WordPress configuration ownership.' );
			}
			$this->assertTemporaryHandle( $handle, $temporary );

			$this->writeAll( $handle, $candidate );
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

			$temporaryStat = fstat( $handle );
			if ( false === $temporaryStat
				|| 0100000 !== ( $temporaryStat['mode'] & 0170000 )
				|| 1 !== $temporaryStat['nlink']
				|| ( $original['mode'] & 0777 ) !== ( $temporaryStat['mode'] & 0777 )
				|| $original['uid'] !== $temporaryStat['uid']
				|| $original['gid'] !== $temporaryStat['gid']
			) {
				$this->fail( 'temporary_metadata_invalid', 'The edited WordPress configuration metadata could not be verified.' );
			}
			fclose( $handle );
			$handle = null;

			$this->beforeFinalConfigCheck( $path );
			$current = $this->inspectConfigPath( $path );
			if ( ! $this->sameSnapshot( $original, $current ) ) {
				$this->fail( 'config_changed', 'The WordPress configuration changed while it was being edited.' );
			}

			if ( ! $this->replacePath( $temporary, $path ) ) {
				$this->fail( 'replace_failed', 'Could not atomically replace the WordPress configuration.' );
			}
			$temporary = '';
			$replaced  = $temporaryStat;

			$installed = $this->readInstalled( $path );
			if ( $candidate !== $installed['contents']
				|| ( $original['mode'] & 0777 ) !== ( $installed['mode'] & 0777 )
				|| $original['uid'] !== $installed['uid']
				|| $original['gid'] !== $installed['gid']
				|| ! $this->sameIdentity( $replaced, $installed )
			) {
				$this->attemptRollback( $path, $original, $replaced );
				$this->fail( 'replacement_readback_failed', 'The installed WordPress configuration failed verification.' );
			}
		} catch ( WpConfigPathWriteException $exception ) {
			if ( null !== $replaced ) {
				$this->attemptRollback( $path, $original, $replaced );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception is propagated, not rendered.
			throw $exception;
		} catch ( Throwable $exception ) {
			if ( null !== $replaced ) {
				$this->attemptRollback( $path, $original, $replaced );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is retained, not rendered.
			throw new WpConfigPathWriteException(
				'filesystem_failure',
				'The WordPress configuration could not be updated safely.',
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
	private function attemptRollback( string $path, array $original, array $replaced ): void {
		try {
			$current = lstat( $path );
			if ( false === $current || ! $this->sameIdentity( $replaced, $current ) || 1 !== $current['nlink'] ) {
				return;
			}

			list( $temporary, $handle ) = $this->createTemporary( dirname( $path ) );
			try {
				$this->assertTemporaryHandle( $handle, $temporary );
				if ( ! $this->changePermissions( $temporary, 0600 )
					|| ! $this->preserveOwnership( $temporary, $original['uid'], $original['gid'] )
				) {
					return;
				}
				$this->writeAll( $handle, $original['contents'] );
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
				$exception
			);
		}

		return $tokens;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function containsLiteralConstantDefinition( array $tokens ): bool {
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_CONST === $token[0] ) {
				$constantAt = $index + 1;
				$this->skipWhitespace( $tokens, $constantAt );
				$name = $tokens[ $constantAt ] ?? null;
				if ( is_array( $name ) && T_STRING === $name[0] && self::CONSTANT_NAME === $name[1] ) {
					return true;
				}
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
				continue;
			}
			if ( self::CONSTANT_NAME === $this->literalDefineName( $tokens, $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function literalDefineName( array $tokens, int $defineAt ): ?string {
		$index = $defineAt + 1;
		$this->skipWhitespace( $tokens, $index );
		if ( '(' !== ( $tokens[ $index ] ?? null ) ) {
			return null;
		}
		++$index;
		$this->skipWhitespace( $tokens, $index );
		$literal = $tokens[ $index ] ?? null;
		if ( ! is_array( $literal ) || T_CONSTANT_ENCAPSED_STRING !== $literal[0] ) {
			return null;
		}

		$value = $this->decodePhpStringLiteral( $literal[1] );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private function skipWhitespace( array $tokens, int &$index ): void {
		while ( isset( $tokens[ $index ] )
			&& is_array( $tokens[ $index ] )
			&& T_WHITESPACE === $tokens[ $index ][0]
		) {
			++$index;
		}
	}

	private function decodePhpStringLiteral( string $literal ): ?string {
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

	private function exportPhpString( string $value ): string {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
	}

	private function lineEnding( string $contents ): string {
		$withoutCrLf = str_replace( "\r\n", '', $contents );
		if ( str_contains( $withoutCrLf, "\r" ) ) {
			$this->fail( 'line_endings_unsupported', 'The WordPress configuration uses unsupported line endings.' );
		}
		if ( str_contains( $contents, "\r\n" ) && str_contains( $withoutCrLf, "\n" ) ) {
			$this->fail( 'line_endings_unsupported', 'The WordPress configuration uses mixed line endings.' );
		}

		return str_contains( $contents, "\r\n" ) ? "\r\n" : "\n";
	}

	private function assertAbsoluteSafePath( string $path, string $reason ): void {
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
	private function sameSnapshot( array $first, array $second ): bool {
		return $first === $second;
	}

	/**
	 * @param array<string, int> $first
	 * @param array<string, int> $second
	 */
	private function sameIdentity( array $first, array $second ): bool {
		return $first['dev'] === $second['dev']
			&& $first['ino'] === $second['ino']
			&& 0100000 === ( $second['mode'] & 0170000 )
			&& 1 === $second['nlink'];
	}

	/**
	 * @param array<string, int> $first
	 * @param array<string, int> $second
	 */
	private function sameMetadata( array $first, array $second ): bool {
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
	private function assertLockHandle( mixed $lock, string $path ): void {
		$pathStat = lstat( $path );
		$lockStat = fstat( $lock );
		if ( false === $pathStat
			|| false === $lockStat
			|| ! $this->sameIdentity( $pathStat, $lockStat )
			|| ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $pathStat['uid'] )
		) {
			$this->fail( 'lock_invalid', 'The WordPress configuration edit lock is not safe.' );
		}
	}

	/**
	 * @param resource $handle
	 */
	private function writeAll( mixed $handle, string $contents ): void {
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
	private function assertTemporaryHandle( mixed $handle, string $path ): void {
		$pathStat   = lstat( $path );
		$handleStat = fstat( $handle );
		if ( false === $pathStat || false === $handleStat || ! $this->sameIdentity( $pathStat, $handleStat ) ) {
			$this->fail( 'temporary_file_invalid', 'The temporary WordPress configuration is not safe.' );
		}
	}

	private function preserveOwnership( string $path, int $uid, int $gid ): bool {
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

	protected function beforeFinalConfigCheck( string $configPath ): void {
	}

	/**
	 * @return resource
	 */
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
	protected function acquireLock( mixed $lock ): bool {
		return flock( $lock, LOCK_EX );
	}

	/**
	 * @param resource $lock
	 */
	protected function releaseLock( mixed $lock ): void {
		flock( $lock, LOCK_UN );
	}

	/**
	 * @return array{0: string, 1: resource}
	 */
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

	protected function changePermissions( string $path, int $mode ): bool {
		return chmod( $path, $mode );
	}

	protected function changeOwner( string $path, int $uid ): bool {
		return chown( $path, $uid );
	}

	protected function changeGroup( string $path, int $gid ): bool {
		return chgrp( $path, $gid );
	}

	/**
	 * @param resource $handle
	 */
	protected function writeHandle( mixed $handle, string $contents ): int|false {
		return fwrite( $handle, $contents );
	}

	/**
	 * @param resource $handle
	 */
	protected function flushHandle( mixed $handle ): bool {
		return fflush( $handle );
	}

	/**
	 * @param resource $handle
	 */
	protected function syncHandle( mixed $handle ): bool {
		return ! function_exists( 'fsync' ) || fsync( $handle );
	}

	protected function readBack( string $path ): string|false {
		return file_get_contents( $path );
	}

	protected function replacePath( string $source, string $destination ): bool {
		return rename( $source, $destination );
	}

	/**
	 * @return array{contents: string, dev: int, ino: int, mode: int, uid: int, gid: int, nlink: int, size: int, mtime: int, ctime: int}
	 */
	protected function readInstalled( string $path ): array {
		$snapshot = $this->inspectConfigPath( $path );
		$this->tokens( $snapshot['contents'] );

		return $snapshot;
	}

	private function fail( string $reason, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These values form an exception, not output.
		throw new WpConfigPathWriteException( $reason, $message );
	}
}

<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Candidate discovery must inspect native paths rather than a WordPress transport.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Suggests one site-specific secrets path outside known public/VCS boundaries.
 */
final class PrivateLocationCandidateResolver {

	private string $temporaryRoot;

	public function __construct( ?string $temporaryRoot = null ) {
		$resolvedTemporary   = realpath( $temporaryRoot ?? sys_get_temp_dir() );
		$this->temporaryRoot = false === $resolvedTemporary ? sys_get_temp_dir() : $resolvedTemporary;
	}

	public function resolve(
		string $wordpressRoot,
		string $contentDir,
		string $pluginDir,
		?string $documentRoot = null,
		?array &$discarded = null
	): ?string {
		$discarded  = array();
		$boundaries = $this->unsafeBoundaries( $wordpressRoot, $contentDir, $pluginDir, $documentRoot );
		if ( null === $boundaries ) {
			return null;
		}

		$privateBase = $this->automaticPrivateBase( $boundaries );
		if ( null === $privateBase ) {
			return null;
		}

		$fingerprint = substr(
			hash( 'sha256', implode( "\0", array_map( array( $this, 'canonicalDirectory' ), array( $wordpressRoot, $contentDir, $pluginDir ) ) ) ),
			0,
			16
		);

		$candidate = $privateBase . '/.ran-booster/' . $fingerprint . '/secrets.json';
		$failure   = $this->configuredPathFailure( $candidate, $boundaries, $privateBase );
		if ( null !== $failure ) {
			$discarded[] = array(
				'directory' => dirname( $candidate ),
				'code'      => $failure['code'],
				'reason'    => $failure['message'],
				'component' => $failure['component'],
			);

			return null;
		}

		return $candidate;
	}

	/**
	 * Validate an operator-configured location without creating or modifying it.
	 */
	public function validateConfigured(
		string $candidate,
		string $wordpressRoot,
		string $contentDir,
		string $pluginDir,
		?string $documentRoot = null
	): bool {
		$boundaries = $this->unsafeBoundaries( $wordpressRoot, $contentDir, $pluginDir, $documentRoot );
		if ( null === $boundaries ) {
			return false;
		}

		$privateBase = $this->automaticPrivateBase( $boundaries );
		$anchor      = null !== $privateBase && $this->contains( $privateBase, $candidate )
			? $privateBase
			: null;

		return null === $this->configuredPathFailure( $candidate, $boundaries, $anchor );
	}

	/**
	 * @param list<string> $boundaries
	 * @return array{code:string,message:string,component:string|null}|null
	 */
	private function configuredPathFailure( string $candidate, array $boundaries, ?string $privateBase ): ?array {
		if ( ! $this->validAbsoluteFilePath( $candidate ) || 'secrets.json' !== basename( $candidate ) ) {
			return $this->failure( 'invalid_candidate_path', 'The candidate is not a valid absolute secrets.json path.' );
		}
		if ( $this->isTemporaryDirectory( $candidate ) ) {
			return $this->failure( 'temporary_storage', 'The candidate is inside the operating system temporary directory.' );
		}
		foreach ( $boundaries as $boundary ) {
			if ( $this->contains( $boundary, $candidate ) ) {
				return $this->failure( 'inside_unsafe_boundary', 'The candidate is inside a public web or version-control directory.', $boundary );
			}
		}
		if ( null !== $privateBase && ( ! is_dir( $privateBase ) || is_link( $privateBase ) ) ) {
			return $this->failure( 'private_anchor_unavailable', 'The private account directory is missing, is not a directory or is a symbolic link.', $privateBase );
		}

		$parts   = explode( '/', ltrim( $candidate, '/' ) );
		$current = '';
		foreach ( $parts as $index => $part ) {
			$current .= '/' . $part;
			if ( ! file_exists( $current ) && ! is_link( $current ) ) {
				continue;
			}

			$stat = lstat( $current );
			if ( false === $stat || is_link( $current ) ) {
				return $this->failure( 'symlink_or_unreadable_component', 'A path component is a symbolic link or could not be inspected.', $current );
			}
			$isTarget = $index === array_key_last( $parts );
			$fileType = $stat['mode'] & 0170000;
			if ( $isTarget ) {
				if ( 0100000 !== $fileType ) {
					return $this->failure( 'storage_file_not_regular', 'The existing storage target is not a regular file.', $current );
				}
				if ( 1 !== $stat['nlink'] ) {
					return $this->failure( 'storage_file_hard_linked', 'The existing storage target has more than one hard link.', $current );
				}
				continue;
			}
			if ( 0040000 !== $fileType ) {
				return $this->failure( 'path_component_not_directory', 'A path component is not a directory.', $current );
			}

			$abovePrivateBase = null !== $privateBase && ! $this->contains( $privateBase, $current );
			if ( $abovePrivateBase ) {
				if ( 0 !== ( $stat['mode'] & 0002 ) ) {
					return $this->failure( 'world_writable_host_ancestor', 'A host directory is writable by every local user, so the private account path could be replaced.', $current );
				}
				if ( 0 !== ( $stat['mode'] & 0020 ) && ! $this->trustedHostGroupBoundary( $current, $stat ) ) {
					return $this->failure( 'php_accessible_group_writable_ancestor', 'A group-writable host directory is owned by, writable by or grouped with the PHP process, so the private account path could be replaced.', $current );
				}
				continue;
			}

			if ( 0 !== ( $stat['mode'] & 0022 ) ) {
				return $this->failure( 'broad_private_path_permissions', 'A private path component is writable by its group or by other users.', $current );
			}
			if ( $current === $privateBase
				&& ( ! is_writable( $current ) || ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $stat['uid'] ) )
			) {
				return $this->failure( 'private_anchor_not_owned', 'The private account directory is not writable and owned by the PHP process user.', $current );
			}
		}

		return null;
	}

	/** @param array<string|int,int> $stat */
	private function trustedHostGroupBoundary( string $path, array $stat ): bool {
		// A group outside PHP's identity is treated as the host control plane.
		if ( ! function_exists( 'posix_geteuid' )
			|| ! function_exists( 'posix_getegid' )
			|| ! function_exists( 'posix_getgroups' )
		) {
			return false;
		}

		$effectiveUser  = posix_geteuid();
		$effectiveGroup = posix_getegid();
		$processGroups  = posix_getgroups();
		clearstatcache( true, $path );

		return $effectiveUser !== $stat['uid']
			&& is_array( $processGroups )
			&& $effectiveGroup !== $stat['gid']
			&& ! in_array( $stat['gid'], $processGroups, true )
			&& ! is_writable( $path );
	}

	/** @return array{code:string,message:string,component:string|null} */
	private function failure( string $code, string $message, ?string $component = null ): array {
		return array(
			'code'      => $code,
			'message'   => $message,
			'component' => $component,
		);
	}

	/** @param list<string> $boundaries */
	private function automaticPrivateBase( array $boundaries ): ?string {
		usort( $boundaries, static fn ( string $left, string $right ): int => strlen( $left ) <=> strlen( $right ) );
		$outermost = $boundaries[0] ?? null;
		if ( null === $outermost ) {
			return null;
		}
		foreach ( $boundaries as $boundary ) {
			if ( ! $this->contains( $outermost, $boundary ) ) {
				return null;
			}
		}

		$privateBase = dirname( $outermost );

		return '/' === $privateBase || $this->isTemporaryDirectory( $privateBase )
			? null
			: $privateBase;
	}

	/**
	 * @return list<string>|null
	 */
	private function unsafeBoundaries(
		string $wordpressRoot,
		string $contentDir,
		string $pluginDir,
		?string $documentRoot
	): ?array {
		$boundaries = array();
		foreach ( array_filter( array( $wordpressRoot, $contentDir, $pluginDir, $documentRoot ) ) as $path ) {
			$canonical = $this->canonicalDirectory( $path );
			if ( null === $canonical ) {
				return null;
			}
			$boundaries[] = $canonical;

			for ( $ancestor = $canonical; '/' !== $ancestor; $ancestor = dirname( $ancestor ) ) {
				foreach ( array( '.git', '.hg', '.svn' ) as $marker ) {
					if ( file_exists( $ancestor . '/' . $marker ) || is_link( $ancestor . '/' . $marker ) ) {
						$boundaries[] = $ancestor;
						break;
					}
				}
			}
		}

		return count( $boundaries ) < 3
			? null
			: array_values( array_unique( $boundaries ) );
	}

	private function canonicalDirectory( string $path ): ?string {
		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return null;
		}

		$normalized = '/' === $path ? '/' : rtrim( $path, '/' );
		$canonical  = realpath( $normalized );
		if ( false === $canonical || $canonical !== $normalized || ! is_dir( $canonical ) || is_link( $canonical ) ) {
			return null;
		}

		return $canonical;
	}

	private function contains( string $directory, string $path ): bool {
		return $directory === $path || str_starts_with( $path, rtrim( $directory, '/' ) . '/' );
	}

	private function validAbsoluteFilePath( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_ends_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, "\r" )
			&& ! str_contains( $path, "\n" )
			&& ! str_contains( $path, '//' )
			&& 0 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path );
	}

	private function isTemporaryDirectory( string $path ): bool {
		return $this->contains( $this->temporaryRoot, $path );
	}
}

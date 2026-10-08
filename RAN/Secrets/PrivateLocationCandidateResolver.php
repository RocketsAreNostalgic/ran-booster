<?php

declare(strict_types=1);

namespace RAN\Secrets;

// Candidate discovery must inspect native paths rather than a WordPress transport.

/**
 * Suggests one site-specific secrets path outside known public/VCS boundaries.
 */
final class PrivateLocationCandidateResolver {

	private string $temporary_root;

	public function __construct( ?string $temporary_root = null ) {
		$resolved_temporary   = realpath( $temporary_root ?? sys_get_temp_dir() );
		$this->temporary_root = false === $resolved_temporary ? sys_get_temp_dir() : $resolved_temporary;
	}

	/**
	 * @param array<array-key, mixed>|null $discarded Previous contents are discarded before candidate discovery.
	 * @param-out list<array{directory:string,code:string,reason:string,component:string|null}> $discarded
	 */
	public function resolve(
		string $wordpress_root,
		string $content_dir,
		string $plugin_dir,
		?string $document_root = null,
		?array &$discarded = null
	): ?string {
		$discarded  = array();
		$boundaries = $this->unsafe_boundaries( $wordpress_root, $content_dir, $plugin_dir, $document_root );
		if ( null === $boundaries ) {
			return null;
		}

		$private_base = $this->automatic_private_base( $boundaries );
		if ( null === $private_base ) {
			return null;
		}

		$fingerprint = substr(
			hash( 'sha256', implode( "\0", array_map( array( $this, 'canonical_directory' ), array( $wordpress_root, $content_dir, $plugin_dir ) ) ) ),
			0,
			16
		);

		$candidate = $private_base . '/.ran-booster/' . $fingerprint . '/secrets.json';
		$failure   = $this->configured_path_failure( $candidate, $boundaries, $private_base );
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
	public function validate_configured(
		string $candidate,
		string $wordpress_root,
		string $content_dir,
		string $plugin_dir,
		?string $document_root = null
	): bool {
		$boundaries = $this->unsafe_boundaries( $wordpress_root, $content_dir, $plugin_dir, $document_root );
		if ( null === $boundaries ) {
			return false;
		}

		$private_base = $this->automatic_private_base( $boundaries );
		$anchor       = null !== $private_base && $this->contains( $private_base, $candidate )
			? $private_base
			: null;

		return null === $this->configured_path_failure( $candidate, $boundaries, $anchor );
	}

	/**
	 * @param list<string> $boundaries
	 * @return array{code:string,message:string,component:string|null}|null
	 */
	private function configured_path_failure( string $candidate, array $boundaries, ?string $private_base ): ?array {
		if ( ! $this->valid_absolute_file_path( $candidate ) || 'secrets.json' !== basename( $candidate ) ) {
			return $this->failure( 'invalid_candidate_path', 'The candidate is not a valid absolute secrets.json path.' );
		}
		if ( $this->is_temporary_directory( $candidate ) ) {
			return $this->failure( 'temporary_storage', 'The candidate is inside the operating system temporary directory.' );
		}
		foreach ( $boundaries as $boundary ) {
			if ( $this->contains( $boundary, $candidate ) ) {
				return $this->failure( 'inside_unsafe_boundary', 'The candidate is inside a public web or version-control directory.', $boundary );
			}
		}
		if ( null !== $private_base && ( ! is_dir( $private_base ) || is_link( $private_base ) ) ) {
			return $this->failure( 'private_anchor_unavailable', 'The private account directory is missing, is not a directory or is a symbolic link.', $private_base );
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
			$is_target = array_key_last( $parts ) === $index;
			$file_type = $stat['mode'] & 0170000;
			if ( $is_target ) {
				if ( 0100000 !== $file_type ) {
					return $this->failure( 'storage_file_not_regular', 'The existing storage target is not a regular file.', $current );
				}
				if ( 1 !== $stat['nlink'] ) {
					return $this->failure( 'storage_file_hard_linked', 'The existing storage target has more than one hard link.', $current );
				}
				continue;
			}
			if ( 0040000 !== $file_type ) {
				return $this->failure( 'path_component_not_directory', 'A path component is not a directory.', $current );
			}

			$above_private_base = null !== $private_base && ! $this->contains( $private_base, $current );
			if ( $above_private_base ) {
				if ( 0 !== ( $stat['mode'] & 0002 ) ) {
					return $this->failure( 'world_writable_host_ancestor', 'A host directory is writable by every local user, so the private account path could be replaced.', $current );
				}
				if ( 0 !== ( $stat['mode'] & 0020 ) && ! $this->trusted_host_group_boundary( $current, $stat ) ) {
					return $this->failure( 'php_accessible_group_writable_ancestor', 'A group-writable host directory is owned by, writable by or grouped with the PHP process, so the private account path could be replaced.', $current );
				}
				continue;
			}

			if ( 0 !== ( $stat['mode'] & 0022 ) ) {
				return $this->failure( 'broad_private_path_permissions', 'A private path component is writable by its group or by other users.', $current );
			}
			if ( $current === $private_base
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The trust boundary depends on writability by the actual PHP process, including host-group ancestor checks.
				&& ( ! is_writable( $current ) || ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== $stat['uid'] ) )
			) {
				return $this->failure( 'private_anchor_not_owned', 'The private account directory is not writable and owned by the PHP process user.', $current );
			}
		}

		return null;
	}

	/** @param array<string|int,int> $stat */
	private function trusted_host_group_boundary( string $path, array $stat ): bool {
		// A group outside PHP's identity is treated as the host control plane.
		if ( ! function_exists( 'posix_geteuid' )
			|| ! function_exists( 'posix_getegid' )
			|| ! function_exists( 'posix_getgroups' )
		) {
			return false;
		}

		$effective_user  = posix_geteuid();
		$effective_group = posix_getegid();
		$process_groups  = posix_getgroups();
		clearstatcache( true, $path );

		return $effective_user !== $stat['uid']
			&& is_array( $process_groups )
			&& $effective_group !== $stat['gid']
			&& ! in_array( $stat['gid'], $process_groups, true )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The trust boundary depends on writability by the actual PHP process, including host-group ancestor checks.
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
	private function automatic_private_base( array $boundaries ): ?string {
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

		$private_base = dirname( $outermost );

		return '/' === $private_base || $this->is_temporary_directory( $private_base )
			? null
			: $private_base;
	}

	/**
	 * @return list<string>|null
	 */
	private function unsafe_boundaries(
		string $wordpress_root,
		string $content_dir,
		string $plugin_dir,
		?string $document_root
	): ?array {
		$boundaries = array();
		foreach ( array_filter( array( $wordpress_root, $content_dir, $plugin_dir, $document_root ) ) as $path ) {
			$canonical = $this->canonical_directory( $path );
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

	private function canonical_directory( string $path ): ?string {
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

	private function valid_absolute_file_path( string $path ): bool {
		return str_starts_with( $path, '/' )
			&& ! str_ends_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! str_contains( $path, "\r" )
			&& ! str_contains( $path, "\n" )
			&& ! str_contains( $path, '//' )
			&& 0 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path );
	}

	private function is_temporary_directory( string $path ): bool {
		return $this->contains( $this->temporary_root, $path );
	}
}

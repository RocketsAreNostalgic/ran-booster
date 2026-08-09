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
		?string $documentRoot = null
	): ?string {
		$boundaries = $this->unsafeBoundaries( $wordpressRoot, $contentDir, $pluginDir, $documentRoot );
		if ( null === $boundaries ) {
			return null;
		}

		usort( $boundaries, static fn ( string $left, string $right ): int => strlen( $left ) <=> strlen( $right ) );
		$outermost = $boundaries[0];
		foreach ( $boundaries as $boundary ) {
			if ( ! $this->contains( $outermost, $boundary ) ) {
				return null;
			}
		}

		$privateBase = dirname( $outermost );
		if ( '/' === $privateBase
			|| ! is_dir( $privateBase )
			|| is_link( $privateBase )
			|| ! is_writable( $privateBase )
			|| $this->isTemporaryDirectory( $privateBase )
		) {
			return null;
		}

		$fingerprint = substr(
			hash( 'sha256', implode( "\0", array_map( array( $this, 'canonicalDirectory' ), array( $wordpressRoot, $contentDir, $pluginDir ) ) ) ),
			0,
			16
		);

		$candidate = $privateBase . '/.ran-booster/' . $fingerprint . '/secrets.json';
		foreach ( $boundaries as $boundary ) {
			if ( $this->contains( $boundary, $candidate ) ) {
				return null;
			}
		}

		return $this->validateConfigured( $candidate, $wordpressRoot, $contentDir, $pluginDir, $documentRoot )
			? $candidate
			: null;
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
		if ( ! $this->validAbsoluteFilePath( $candidate ) || 'secrets.json' !== basename( $candidate ) ) {
			return false;
		}

		$boundaries = $this->unsafeBoundaries( $wordpressRoot, $contentDir, $pluginDir, $documentRoot );
		if ( null === $boundaries || $this->isTemporaryDirectory( $candidate ) ) {
			return false;
		}
		foreach ( $boundaries as $boundary ) {
			if ( $this->contains( $boundary, $candidate ) ) {
				return false;
			}
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
				return false;
			}
			$isTarget = $index === array_key_last( $parts );
			$fileType = $stat['mode'] & 0170000;
			if ( $isTarget ) {
				if ( 0100000 !== $fileType || 1 !== $stat['nlink'] ) {
					return false;
				}
			} elseif ( 0040000 !== $fileType || 0 !== ( $stat['mode'] & 0022 ) ) {
				return false;
			}
		}

		return true;
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

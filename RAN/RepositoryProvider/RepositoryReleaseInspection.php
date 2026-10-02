<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Path-free evidence from inspection of one exact release archive. */
final readonly class RepositoryReleaseInspection {
	public function __construct(
		public string $provider_release_id,
		public string $tag,
		public string $version,
		public string $provider_commit_id,
		public string $package_root,
		public string $main_file,
		public string $fingerprint
	) {
		if ( ! $this->bounded_opaque_value( $provider_release_id, 191 )
			|| ! $this->bounded_opaque_value( $tag, 100 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! $this->bounded_opaque_value( $provider_commit_id, 191 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $package_root )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $main_file )
			|| ! $this->bounded_opaque_value( $fingerprint, 191 ) ) {
			throw new InvalidArgumentException( 'The repository release inspection is invalid.' );
		}
	}

	private function bounded_opaque_value( string $value, int $maximum_bytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximum_bytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}

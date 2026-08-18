<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Path-free evidence from inspection of one exact release archive. */
final readonly class RepositoryReleaseInspection {
	public function __construct(
		public string $providerReleaseId,
		public string $tag,
		public string $version,
		public string $providerCommitId,
		public string $packageRoot,
		public string $mainFile,
		public string $fingerprint
	) {
		if ( ! $this->boundedOpaqueValue( $providerReleaseId, 191 )
			|| ! $this->boundedOpaqueValue( $tag, 100 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version )
			|| ! $this->boundedOpaqueValue( $providerCommitId, 191 )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $packageRoot )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $mainFile )
			|| ! $this->boundedOpaqueValue( $fingerprint, 191 ) ) {
			throw new InvalidArgumentException( 'The repository release inspection is invalid.' );
		}
	}

	private function boundedOpaqueValue( string $value, int $maximumBytes ): bool {
		return '' !== $value
			&& strlen( $value ) <= $maximumBytes
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}

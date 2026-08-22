<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RuntimeException;

final class ProspectiveReleaseFacadeDouble implements ProspectiveReleaseFacade {
	/** @var list<list<mixed>> */
	public array $calls = array();

	/** @var list<string> */
	public array $supportedProviders = array( 'gh' );

	/** @var array<string, ProspectiveReleaseResult> */
	public array $results = array();

	public string $nonceFailure = '';

	public function nonceAction( string $operation, string $type ): string {
		if ( 'throw' === $this->nonceFailure ) {
			throw new RuntimeException( 'nonce-failure' );
		}
		if ( 'empty' === $this->nonceFailure ) {
			return '';
		}

		return 'prospective-release-' . $operation . '-' . $type;
	}

	public function supportedProviderCodes( string $type ): array {
		unset( $type );

		return $this->supportedProviders;
	}

	public function listCandidates( string $type, array $repositoryRequest, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'list_candidates', $type, $repositoryRequest, $channel, $nonce );

		return $this->result( 'list_candidates' );
	}

	public function inspect( string $type, array $repositoryRequest, string $releaseId, string $tag, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'inspect', $type, $repositoryRequest, $releaseId, $tag, $channel, $nonce );

		return $this->result( 'inspect' );
	}

	public function install( string $type, array $repositoryRequest, string $releaseId, string $tag, string $expectedFingerprint, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'install', $type, $repositoryRequest, $releaseId, $tag, $expectedFingerprint, $channel, $nonce );

		return $this->result( 'install' );
	}

	private function result( string $operation ): ProspectiveReleaseResult {
		return $this->results[ $operation ] ?? ProspectiveReleaseResult::failure( 'operation_failed' );
	}
}

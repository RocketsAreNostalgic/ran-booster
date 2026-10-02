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
	public array $supported_providers = array( 'gh' );

	/** @var array<string, ProspectiveReleaseResult> */
	public array $results = array();

	public string $nonce_failure = '';

	public function nonce_action( string $operation, string $type ): string {
		if ( 'throw' === $this->nonce_failure ) {
			throw new RuntimeException( 'nonce-failure' );
		}
		if ( 'empty' === $this->nonce_failure ) {
			return '';
		}

		return 'prospective-release-' . $operation . '-' . $type;
	}

	public function supported_provider_codes( string $type ): array {
		unset( $type );

		return $this->supported_providers;
	}

	public function list_candidates( string $type, array $repository_request, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'list_candidates', $type, $repository_request, $channel, $nonce );

		return $this->result( 'list_candidates' );
	}

	public function inspect( string $type, array $repository_request, string $release_id, string $tag, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'inspect', $type, $repository_request, $release_id, $tag, $channel, $nonce );

		return $this->result( 'inspect' );
	}

	public function install( string $type, array $repository_request, string $release_id, string $tag, string $expected_fingerprint, string $channel, string $nonce ): ProspectiveReleaseResult {
		$this->calls[] = array( 'install', $type, $repository_request, $release_id, $tag, $expected_fingerprint, $channel, $nonce );

		return $this->result( 'install' );
	}

	private function result( string $operation ): ProspectiveReleaseResult {
		return $this->results[ $operation ] ?? ProspectiveReleaseResult::failure( 'operation_failed' );
	}
}

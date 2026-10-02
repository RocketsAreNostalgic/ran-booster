<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;

/** @internal Keeps the Core-admin browser out of the published add-on facade. */
final class NativeManagedReleaseBrowser implements ManagedReleaseBrowser {
	public function __construct( private readonly NativeReleaseTrackingFacade $releases ) {}
	public function list_candidates( string $type, string $identifier, int $revision, string $channel, string $nonce ): ?RepositoryReleaseCandidateList {
		return $this->releases->list_candidates( $type, $identifier, $revision, $channel, $nonce );
	}
	public function inspect_candidate( string $type, string $identifier, int $revision, string $release_id, string $tag, string $channel, string $nonce ): ?ReleaseTrackingPreflight {
		return $this->releases->inspect_candidate( $type, $identifier, $revision, $release_id, $tag, $channel, $nonce );
	}
}

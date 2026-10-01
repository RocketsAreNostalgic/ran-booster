<?php

declare(strict_types=1);

namespace Tests\WordPress;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;

final class RuntimeReleaseProvider implements RepositoryProvider, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseNativeTargets {
	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private \Closure $list;
	private \Closure $inspect;
	private \Closure $targetFactory;

	public function __construct(
		private string $code = 'gh',
		private string $baseUrl = 'https://github.com/',
		?callable $list = null,
		?callable $inspect = null,
		?callable $targetFactory = null,
		private bool $collision = false
	) {
		$this->list          = null === $list
			? static fn ( string $type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList => new RepositoryReleaseCandidateList(
				array( new RepositoryReleaseCandidate( '101', 'v2.0.0', '2.0.0', false, '2026-08-17T12:00:00Z', array( 'example.zip' ) ) )
			)
			: \Closure::fromCallable( $list );
		$this->inspect       = null === $inspect
			? static fn ( string $type, RepositoryReference $repository, string $releaseId, string $tag, string $channel ): RepositoryReleaseInspection => new RepositoryReleaseInspection(
				$releaseId,
				$tag,
				'2.0.0',
				str_repeat( 'a', 40 ),
				'plugin' === $type ? 'example' : 'example-theme',
				'plugin' === $type ? 'example.php' : 'style.css',
				'v1:' . str_repeat( 'b', 64 )
			)
			: \Closure::fromCallable( $inspect );
		$this->targetFactory = null === $targetFactory
			? static fn ( mixed ...$options ): RepositoryReleaseNativeTarget => new RuntimeUpdaterFacade( $options )
			: \Closure::fromCallable( $targetFactory );
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Release fixture', $this->baseUrl, 'Owner' );
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		return $this->baseUrl . $repository->locator;
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return '' === $tag ? '' : $this->expected_update_uri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
	}

	public function list_release_candidates( string $packageType, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		return ( $this->list )( $packageType, $repository, $channel );
	}

	public function inspect_release(
		string $packageType,
		RepositoryReference $repository,
		string $providerReleaseId,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		return ( $this->inspect )( $packageType, $repository, $providerReleaseId, $tag, $channel );
	}

	public function has_registered_native_target( string $packageType, string $installedIdentifier ): bool {
		unset( $packageType, $installedIdentifier );

		return $this->collision;
	}

	public function create_native_target(
		string $packageType,
		RepositoryReference $repository,
		string $metadataFile,
		string $packageRoot,
		string $installedIdentifier,
		string $channel,
		string $deploymentPolicy
	): RepositoryReleaseNativeTarget {
		$options = array(
			'targetType'        => $packageType,
			'repository'        => $repository,
			'pluginFile'        => $metadataFile,
			'pluginSlug'        => $packageRoot,
			'installedIdentity' => $installedIdentifier,
			'channel'           => $channel,
			'autoUpdatePolicy'  => $deploymentPolicy,
		);
		if ( 'theme' === $packageType ) {
			$options['stylesheet'] = $installedIdentifier;
		}
		$target = ( $this->targetFactory )( ...$options );
		if ( ! $target instanceof RepositoryReleaseNativeTarget ) {
			throw new \RuntimeException( 'The fixture native target is invalid.' );
		}

		return $target;
	}
}

<?php

declare(strict_types=1);

namespace Tests\WordPress;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;

final class RuntimeReleaseProvider implements RepositoryProvider, RepositoryReleaseMetadata, RepositoryReleaseNativeTargets {
	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private \Closure $targetFactory;

	public function __construct(
		private string $code = 'gh',
		private string $baseUrl = 'https://github.com/',
		?callable $targetFactory = null,
		private bool $collision = false
	) {
		$this->targetFactory = null === $targetFactory
			? static fn (): RepositoryReleaseNativeTarget => new RuntimeUpdaterFacade()
			: \Closure::fromCallable( $targetFactory );
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Release fixture', $this->baseUrl, 'Owner' );
	}

	public function expectedUpdateUri( RepositoryReference $repository ): string {
		return $this->baseUrl . $repository->locator;
	}

	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
		return '' === $tag ? '' : $this->expectedUpdateUri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
	}

	public function hasRegisteredNativeTarget( string $packageType, string $installedIdentifier ): bool {
		unset( $packageType, $installedIdentifier );

		return $this->collision;
	}

	public function createNativeTarget(
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

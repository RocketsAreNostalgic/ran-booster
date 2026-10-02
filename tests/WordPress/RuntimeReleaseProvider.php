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
	private \Closure $target_factory;

	public function __construct(
		private string $code = 'gh',
		private string $base_url = 'https://github.com/',
		?callable $list = null,
		?callable $inspect = null,
		?callable $target_factory = null,
		private bool $collision = false
	) {
		$this->list           = null === $list
			? static fn ( string $type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList => new RepositoryReleaseCandidateList(
				array( new RepositoryReleaseCandidate( '101', 'v2.0.0', '2.0.0', false, '2026-08-17T12:00:00Z', array( 'example.zip' ) ) )
			)
			: \Closure::fromCallable( $list );
		$this->inspect        = null === $inspect
			? static fn ( string $type, RepositoryReference $repository, string $release_id, string $tag, string $channel ): RepositoryReleaseInspection => new RepositoryReleaseInspection(
				$release_id,
				$tag,
				'2.0.0',
				str_repeat( 'a', 40 ),
				'plugin' === $type ? 'example' : 'example-theme',
				'plugin' === $type ? 'example.php' : 'style.css',
				'v1:' . str_repeat( 'b', 64 )
			)
			: \Closure::fromCallable( $inspect );
		$this->target_factory = null === $target_factory
			? static fn ( mixed ...$options ): RepositoryReleaseNativeTarget => new RuntimeUpdaterFacade( $options )
			: \Closure::fromCallable( $target_factory );
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Release fixture', $this->base_url, 'Owner' );
	}

	public function expected_update_uri( RepositoryReference $repository ): string {
		return $this->base_url . $repository->locator;
	}

	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return '' === $tag ? '' : $this->expected_update_uri( $repository ) . '/releases/tag/' . rawurlencode( $tag );
	}

	public function list_release_candidates( string $package_type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		return ( $this->list )( $package_type, $repository, $channel );
	}

	public function inspect_release(
		string $package_type,
		RepositoryReference $repository,
		string $provider_release_id,
		string $tag,
		string $channel
	): RepositoryReleaseInspection {
		return ( $this->inspect )( $package_type, $repository, $provider_release_id, $tag, $channel );
	}

	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
		unset( $package_type, $installed_identifier );

		return $this->collision;
	}

	public function create_native_target(
		string $package_type,
		RepositoryReference $repository,
		string $metadata_file,
		string $package_root,
		string $installed_identifier,
		string $channel,
		string $deployment_policy
	): RepositoryReleaseNativeTarget {
		$options = array(
			'targetType'        => $package_type,
			'repository'        => $repository,
			'plugin_file'       => $metadata_file,
			'pluginSlug'        => $package_root,
			'installedIdentity' => $installed_identifier,
			'channel'           => $channel,
			'autoUpdatePolicy'  => $deployment_policy,
		);
		if ( 'theme' === $package_type ) {
			$options['stylesheet'] = $installed_identifier;
		}
		$target = ( $this->target_factory )( ...$options );
		if ( ! $target instanceof RepositoryReleaseNativeTarget ) {
			throw new \RuntimeException( 'The fixture native target is invalid.' );
		}

		return $target;
	}
}

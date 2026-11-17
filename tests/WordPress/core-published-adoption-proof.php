<?php

declare(strict_types=1);

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;

// Exercise the installed picker callbacks with the adopted fixture's real provider.
return static function ( CoreContainer $container, string $type, string $identifier, int $revision, array $repository, callable $assert ): void {
	$controls    = $container->make( ReleaseManagementControls::class );
	$prospective = $container->make( ProspectiveReleaseFacade::class );
	$managed     = $container->make( ReleaseTrackingFacade::class );
	$registry    = $container->make( ProviderRegistry::class );
	$listing     = $registry->require_capability( 'gh', RepositoryReleaseCandidateListing::class );
	$reference   = new RepositoryReference( $repository['repository'], 'plugin' === $type ? '951001' : '951002', false, null );
	$release_url = 'https://api.github.com/repos/' . $repository['repository'] . '/releases';
	$wire        = new class() {
		public string $mode = '';
		/** @var list<array{string,string}> */
		public array $reads = array();

		public function start( string $mode ): void {
			$this->mode  = $mode;
			$this->reads = array();
		}
	};
	$observed    = array();
	$http        = static function ( mixed $response, array $args, string $url ) use ( $wire, $release_url ): mixed {
		if ( ! is_array( $response ) || ! str_starts_with( $url, $release_url ) ) {
			return $response;
		}
		$wire->reads[] = array( $args['method'], $url );
		$mode          = $wire->mode;
		if ( 'digest' === $mode ) {
			if ( in_array( $url, array( $release_url . '/42', $release_url . '/tags/v2.0.0' ), true ) ) {
				$release = json_decode( $response['body'], true, 16, JSON_THROW_ON_ERROR );
				unset( $release['assets'][0]['digest'] );
				$response['body'] = wp_json_encode( $release );
			}
			return $response;
		}
		if ( ! in_array( $url, array( $release_url . '?per_page=20&page=1', $release_url . '?per_page=20&page=2' ), true ) ) {
			throw new RuntimeException( 'Unexpected GitHub listing endpoint.' );
		}
		if ( 'rate' === $mode || 'unavailable' === $mode ) {
			$response['response']['code'] = 'rate' === $mode ? 429 : 503;
			$response['headers']          = array( 'retry-after' => '120' );
			$response['body']             = '{}';
			return $response;
		}
		$release = json_decode( $response['body'], true, 16, JSON_THROW_ON_ERROR )[0];
		$page    = array();
		$offset  = str_ends_with( $url, 'page=1' ) ? 0 : 20;
		for ( $index = 0; $index < 20; ++$index ) {
			$draft             = $release;
			$draft['draft']    = true;
			$draft['id']       = 100 + $offset + $index;
			$draft['tag_name'] = 'v1.0.' . ( $offset + $index );
			$page[]            = $draft;
		}
		if ( 'nonempty' === $mode && 0 === $offset ) {
			$page[0] = $release;
		}
		$response['body'] = wp_json_encode( $page );
		return $response;
	};
	$first_page  = array( 'GET', $release_url . '?per_page=20&page=1' );
	$second_page = array( 'GET', $release_url . '?per_page=20&page=2' );
	add_filter( 'pre_http_request', $http, PHP_INT_MIN + 1, 3 );
	try {
		$wire->start( 'rate' );
		try {
			$listing->list_release_candidates( $type, $reference, 'stable' );
			throw new RuntimeException( 'The installed provider lost the listing failure.' );
		} catch ( RepositoryReleaseReadUnavailable $failure ) {
			$assert( 'rate_limited' === $failure->failure?->reason && 120 === $failure->failure->retry_after && null === $failure->failure->http_status, 'The listing carrier lost the upstream retry delay or invented an HTTP status.' );
		}
		$assert( array( $first_page ) === $wire->reads, 'The carrier proof did not make the expected listing request.' );
		foreach ( array( 'managed', 'prospective' ) as $picker ) {
			foreach ( array( 'rate', 'unavailable', 'empty', 'nonempty', 'digest' ) as $mode ) {
				$wire->start( $mode );
				$operation = 'digest' === $mode ? ( 'managed' === $picker ? 'inspect_candidate' : 'inspect' ) : 'list_candidates';
				$request   = array(
					'expected_type'            => $type,
					'expected_identifier'      => $identifier,
					'expected_source_revision' => $revision,
					'release_channel'          => 'stable',
					'ran_booster'              => $repository,
					'release_id'               => '42',
					'release_tag'              => 'v2.0.0',
				);
				if ( 'managed' === $picker ) {
					$request['_wpnonce'] = wp_create_nonce( $managed->nonce_action( $operation, $type, $identifier, $revision, 'stable' ) );
					$outcome             = $controls->process_managed_browser_request( $operation, $request );
				} else {
					$request['_wpnonce'] = wp_create_nonce( $prospective->nonce_action( $operation, $type, null ) );
					$outcome             = $controls->process_prospective_request( $operation, $request );
				}
				$data                         = $outcome['data'];
				$observed[ $picker ][ $mode ] = $outcome;
				if ( 'digest' === $mode ) {
					$assert( in_array( array( 'GET', $release_url . '/42' ), $wire->reads, true ), 'The digest refusal did not inspect the exact release.' );
					$assert( ( 'managed' === $picker ? 'unable_to_check' : 'release_invalid' ) === $outcome['code'] && ! $outcome['successful'] && array( 'rejection_reason' => 'digest_missing' ) === $data, 'The picker lost the missing-digest refusal.' );
					continue;
				}
				if ( 'rate' === $mode || 'unavailable' === $mode ) {
					$assert( array( $first_page ) === $wire->reads && 'unable_to_check' === $outcome['code'] && ! $outcome['successful'], 'The failed picker listing did not use the expected provider route.' );
					if ( 'rate' === $mode ) {
						$hint = $data['lookup_hint'] ?? array();
						$assert( 'A dedicated lookup credential helps avoid rate limits.' === ( $hint['text'] ?? null ) && 'Choose a lookup credential' === ( $hint['link'] ?? null ) && str_ends_with( $hint['url'] ?? '', '#ran-booster-public-lookup-profile-region' ) && ! isset( $data['rate_limited_provider'] ), 'The anonymous rate-limit hint was lost.' );
					} else {
						$assert( array() === $data, 'A service failure inherited the rate-limit hint.' );
					}
					continue;
				}
				$nonempty = 'nonempty' === $mode;
				$assert( array( $first_page, $second_page ) === $wire->reads, 'The listing failed to search exactly two pages.' );
				$assert( ( $nonempty ? 'release_candidates_available' : 'no_releases' ) === $outcome['code'] && ( $nonempty || 'managed' === $picker ) === $outcome['successful'], 'Wrong picker outcome for the bounded listing.' );
				$assert( true === ( $data['search_exhausted'] ?? null ) && ! isset( $data['lookup_hint'] ) && ( $nonempty ? array( '42' ) : array() ) === array_column( $data['candidates'], 'release_id' ), 'The picker lost bounded search or candidate identity.' );
				$drops = array();
				for ( $index = 0; $index < 8; ++$index ) {
					$drops[] = array(
						'reason' => 'release_draft',
						'tag'    => 'v1.0.' . ( $index + ( $nonempty ? 1 : 0 ) ),
					);
				}
				$assert(
					array(
						'scanned_count' => 40,
						'dropped_count' => $nonempty ? 39 : 40,
						'drops'         => $drops,
						'truncated'     => true,
					) === $data['diagnostics'],
					'The picker lost or broadened the identity-free listing report.'
				);
			}
		}
	} finally {
		remove_filter( 'pre_http_request', $http, PHP_INT_MIN + 1 );
	}
	// The deterministic callback responses can be replayed against the packaged picker loaders.
	WP_CLI::debug( (string) wp_json_encode( $observed ), 'published-adoption' ); // @phpstan-ignore class.notFound (WP-CLI owns debug output in the disposable installed proof.)
};

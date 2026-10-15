<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Storage\GitHubSelectionStore;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

/** Actual same-connection provider selection for deployment and webhook fixtures. */
trait QueuedSelectionFixture {
	private function queued_selection( \RAN\Tests\Deployment\AttemptRepositoryDatabase $db, ?ProviderRegistry $registry = null, bool $signing = true ): GitHubProviderSelection {
		$queries                     = $db->queries;
		$db->selection->active_bytes = 'a:1:{i:0;s:9:"index.php";}';
		$declaration                 = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'index.php',
				'implementation_id'      => 'queued-test',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => $signing ? 'github-hmac-sha256-v1' : null,
				'facets'                 => array(),
			)
		);
		$store                       = new GitHubSelectionStore( $db, 'wp_options' );
		$store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $store, dirname( __DIR__ ) . '/WordPress/fixtures/ran-booster-managed-active', $declaration );
		$provider  = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
		$registry ??= new ProviderRegistry( array( $provider ) );
		$selection->bind_registry( $registry );
		$selection->complete_collection();
		$registry->seal();
		$db->queries = $queries;
		return $selection;
	}
}

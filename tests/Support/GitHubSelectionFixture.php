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
use RAN\Tests\RepositoryProvider\SelectionContextDatabase;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

/** Real selection/CAS authority shared by native lifecycle tests. */
trait GitHubSelectionFixture {
	private ?GitHubSelectionStore $native_selection_store = null;
	private ?GitHubProviderSelection $native_selection    = null;

	private function github_selection(): GitHubProviderSelection {
		if ( null !== $this->native_selection ) {
			return $this->native_selection;
		}
		$db                           = new SelectionContextDatabase();
		$db->active_bytes             = 'a:1:{i:0;s:9:"index.php";}';
		$declaration                  = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'index.php',
				'implementation_id'      => 'native-test',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$this->native_selection_store = new GitHubSelectionStore( $db, 'wp_options' );
		$this->native_selection_store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $this->native_selection_store, dirname( __DIR__ ) . '/WordPress/fixtures/ran-booster-managed-active', $declaration );
		$provider  = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createMock( ProviderDiagnostics::class ) );
		$registry = new ProviderRegistry( array( $provider ) );
		$selection->bind_registry( $registry );
		$selection->complete_collection();
		$registry->seal();
		$this->native_selection = $selection;
		return $selection;
	}
}

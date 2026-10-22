<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\RepositoryProvider\SelectionContextDatabase;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

/** Existing unit composition only; host guard/credential wiring is tested separately. */
final class GitHubSelectionTestFixture {
	public static function bind( ProviderRegistry $registry ): GitHubProviderSelection {
		$db               = new SelectionContextDatabase();
		$db->active_bytes = 'a:1:{i:0;s:19:"example/example.php";}';
		$declaration      = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'example/example.php',
				'implementation_id'      => 'unit-fixture',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array( 'github-classic-pat-v1', 'github-fine-grained-pat-v1' ),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$store            = new GitHubSelectionStore( $db, 'wp_options' );
		$store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $store, dirname( __DIR__ ) . '/fixtures/wordpress/wp-content/plugins', $declaration );
		$selection->bind_registry( $registry );
		$selection->complete_collection();
		$registry->seal();
		return $selection;
	}
}

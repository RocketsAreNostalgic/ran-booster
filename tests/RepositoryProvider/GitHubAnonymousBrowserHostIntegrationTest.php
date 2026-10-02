<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\RepositoryBrowser;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;

final class GitHubAnonymousBrowserHostIntegrationTest extends TestCase {

	public function test_anonymous_public_lookup_remains_available_when_encrypted_secrets_runtime_is_unavailable(): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":987654321,"full_name":"RocketsAreNostalgic/ran-booster","private":false,"default_branch":"main"}',
			)
		);
		$secrets = new SecretsFile(
			constants: array(),
			provider_policies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$repository = ( new RepositoryBrowser( $secrets->credentials_for( 'gh' ) ) )->repository( 'rocketsarenostalgic/ran-booster' );

		self::assertFalse( $repository->private );
		self::assertNull( $repository->credential_id );
		$request = \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests()[0];
		self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
	}
}

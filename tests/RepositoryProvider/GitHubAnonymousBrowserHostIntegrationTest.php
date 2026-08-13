<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once dirname( __DIR__ ) . '/Booster/GitHub/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\RepositoryBrowser;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;

final class GitHubAnonymousBrowserHostIntegrationTest extends TestCase {

	public function testAnonymousPublicLookupRemainsAvailableWhenEncryptedSecretsRuntimeIsUnavailable(): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"id":987654321,"full_name":"RocketsAreNostalgic/ran-booster","private":false,"default_branch":"main"}',
			)
		);
		$secrets = new SecretsFile(
			constants: array(),
			providerPolicies: new ProviderSecretPolicyCatalog(),
			availability: new SecretsRuntimeAvailability( false, false )
		);

		$repository = ( new RepositoryBrowser( $secrets->credentialsFor( 'gh' ) ) )->repository( 'rocketsarenostalgic/ran-booster' );

		self::assertFalse( $repository->private );
		self::assertNull( $repository->credentialId );
		$request = \RAN\Booster\GitHub\repository_resolver_http_requests()[0];
		self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
	}
}

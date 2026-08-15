<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;

final class GitHubModuleHostBoundaryTest extends TestCase {

	private const EXPLICIT_CORE_HOST_INTEGRATIONS = array(
		'tests/Admin/Support/ExpiryReminderProvider.php',
		'tests/Logging/GitHubDiagnosticsLoggingTest.php',
		'tests/Portability/BlueprintRepositoryVerifierTest.php',
		'tests/Portability/EncryptedStoreBlueprintIntegrationTest.php',
		'tests/Portability/TemporaryCredentialProvider.php',
		'tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php',
		'tests/RepositoryProvider/GitHubAnonymousBrowserHostIntegrationTest.php',
		'tests/RepositoryProvider/GitHubArchiveHostIntegrationTest.php',
		'tests/RepositoryProvider/GitHubCredentialPolicyHostIntegrationTest.php',
		'tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php',
		'tests/Webhook/SignedWebhookVerifierTest.php',
		'tests/Webhook/WebhookProcessorTest.php',
		'tests/WordPress/github-provider-installed-readback.php',
	);

	public function testCoreReferencesOnlyTheNamedGitHubCompositionSeam(): void {
		$references = array();
		$root       = dirname( __DIR__, 2 ) . '/RAN';
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/RAN/Booster/GitHub/' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local architecture boundary under test.
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			if ( str_contains( $source, 'RAN\Booster\GitHub\\' ) ) {
				$relative     = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() );
				$references[] = $relative;
				if ( 'RAN/BoosterServiceProvider.php' === $relative ) {
					self::assertStringContainsString( 'use RAN\Booster\GitHub\GitHubProvider;', $source );
					self::assertStringContainsString( 'use RAN\Booster\GitHub\WebhookManagement\GitHubWebhookManagement;', $source );
				} elseif ( 'RAN/Uninstall/LocalDataRemover.php' === $relative ) {
					self::assertStringContainsString( 'use RAN\Booster\GitHub\WebhookManagement\GitHubWebhookManagement;', $source );
					self::assertStringContainsString( 'use RAN\Booster\GitHub\WebhookManagement\Installation\WordPressInstallationStore;', $source );
				} else {
					self::assertContains( $relative, array( 'RAN/Dashboard.php', 'RAN/Admin/ProviderRepositoryRowsNormalizer.php' ) );
					self::assertStringContainsString( 'use RAN\Booster\GitHub\WebhookManagement\GitHubWebhookManagement;', $source );
				}
			}
		}

		sort( $references );
		self::assertSame(
			array(
				'RAN/Admin/ProviderRepositoryRowsNormalizer.php',
				'RAN/BoosterServiceProvider.php',
				'RAN/Dashboard.php',
				'RAN/Uninstall/LocalDataRemover.php',
			),
			$references
		);
	}

	public function testCoreTestsReferenceGitHubConcretesOnlyThroughExplicitHostIntegrationOwners(): void {
		$root       = dirname( __DIR__, 2 );
		$references = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests' ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || __FILE__ === $file->getPathname() || str_contains( $file->getPathname(), '/tests/Booster/GitHub/' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local test-ownership boundary under test.
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			if ( str_contains( $source, 'RAN\\Booster\\GitHub\\' ) ) {
				$references[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		sort( $references );
		$expected = self::EXPLICIT_CORE_HOST_INTEGRATIONS;
		sort( $expected );
		self::assertSame( $expected, $references );
	}
}

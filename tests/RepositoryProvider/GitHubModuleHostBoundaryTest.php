<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;

final class GitHubModuleHostBoundaryTest extends TestCase {

	private const EXPLICIT_CORE_HOST_INTEGRATIONS = array(
		'tests/Runtime/Support/GitHubWorkflowAssistanceWordPressFunctions.php',
		'tests/Admin/Support/ExpiryReminderProvider.php',
		'tests/Logging/GitHubDiagnosticsLoggingTest.php',
		'tests/Portability/BlueprintRepositoryVerifierTest.php',
		'tests/Portability/EncryptedStoreBlueprintIntegrationTest.php',
		'tests/Portability/TemporaryCredentialProvider.php',
		'tests/Integration/phase-4.4-core-disposable-harness.php',
		'tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php',
		'tests/RepositoryProvider/GitHubAnonymousBrowserHostIntegrationTest.php',
		'tests/RepositoryProvider/GitHubArchiveHostIntegrationTest.php',
		'tests/RepositoryProvider/GitHubCredentialPolicyHostIntegrationTest.php',
		'tests/RepositoryProvider/Support/ShippedSecretPolicyCatalog.php',
		'tests/Runtime/ReleaseManagementCutoverBootstrapTest.php',
		'tests/Webhook/SignedWebhookVerifierTest.php',
		'tests/Webhook/WebhookProcessorTest.php',
		'tests/WordPress/ManagedReleaseRuntimeTest.php',
		'tests/WordPress/github-provider-installed-readback.php',
		'tests/WordPress/github-release-updater-bootstrap-smoke.php',
		'tests/WordPress/native-lifecycle-installed-smoke.php',
	);

	public function testCoreReferencesOnlyTheNamedGitHubCompositionSeam(): void {
		$allowed    = array(
			'RAN/BoosterServiceProvider.php' => 'use RAN\BoosterGitHubProvider\V1\GitHubProvider;',
		);
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
			if ( str_contains( $source, 'RAN\BoosterGitHubProvider\V1\\' ) ) {
				$relative     = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() );
				$references[] = $relative;
				self::assertArrayHasKey( $relative, $allowed );
				self::assertStringContainsString( $allowed[ $relative ], $source );
			}
		}

		sort( $references );
		$expected = array_keys( $allowed );
		sort( $expected );
		self::assertSame( $expected, $references );
	}

	public function testNeutralReleaseAndWebhookOwnersHaveNoGitHubBranchOrGenericDispatcherSurface(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = array(
			$root . '/RAN/Dashboard.php',
			$root . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php',
			$root . '/RAN/Admin/Component/RepositoryDetailRenderer.php',
			$root . '/RAN/WordPress/ManagedReleaseConfiguration.php',
			$root . '/RAN/WordPress/ManagedReleaseStore.php',
			$root . '/RAN/WordPress/ManagedReleaseTargetRegistrar.php',
		);
		foreach (
			array(
				$root . '/RAN/AddOn/ReleaseTracking',
				$root . '/RAN/Admin/ReleaseManagement',
				$root . '/RAN/Admin/WebhookManagement',
			) as $directory
		) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile()
					&& 'php' === $file->getExtension()
				) {
					$paths[] = $file->getPathname();
				}
			}
		}

		foreach ( $paths as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local architecture boundary under test.
			$source = file_get_contents( $path );
			self::assertIsString( $source );
			self::assertStringNotContainsString( 'RAN\\BoosterGitHubProvider\\V1', $source, $path );
			self::assertStringNotContainsString( 'GitHubProvider', $source, $path );
			self::assertDoesNotMatchRegularExpression( "/(?:===|!==)\\s*['\"]gh['\"]|['\"]gh['\"]\\s*(?:===|!==)/", $source, $path );
			self::assertStringNotContainsString( 'dispatchCapability', $source, $path );
			self::assertStringNotContainsString( 'executeCapability', $source, $path );
			self::assertStringNotContainsString( 'capabilityDescriptors', $source, $path );
		}
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
			if ( str_contains( $source, 'RAN\\BoosterGitHubProvider\\V1\\' ) ) {
				$references[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		sort( $references );
		$expected = self::EXPLICIT_CORE_HOST_INTEGRATIONS;
		sort( $expected );
		self::assertSame( $expected, $references );
	}

	public function testReleasedProviderIsTheOnlyBundledGitHubImplementation(): void {
		$root = dirname( __DIR__, 2 );
		self::assertDirectoryDoesNotExist( $root . '/RAN/Booster/GitHub' );
		self::assertTrue( class_exists( \RAN\BoosterGitHubProvider\V1\GitHubProvider::class ) );

		$source = ( new \ReflectionClass( \RAN\BoosterGitHubProvider\V1\GitHubProvider::class ) )->getFileName();
		self::assertIsString( $source );
		self::assertSame(
			realpath( $root . '/vendor/ran/booster-github-provider/src/GitHubProvider.php' ),
			realpath( $source )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static host/package ownership boundary under test.
		$bootstrap = file_get_contents( $root . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertStringContainsString( '$ran_booster_container->make( RepositoryWebhookManagementControls::class )->register();', $bootstrap );
		self::assertStringNotContainsString( 'legacyAssistedHooksAddOnIsActive', $bootstrap );
		self::assertStringNotContainsString( 'registerLegacyAssistedHooksAddOnNotice', $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION', $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_BUNDLED_GITHUB_WEBHOOK_MANAGEMENT_VERSION', $bootstrap );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;

final class ModuleDependencyBoundaryTest extends TestCase {

	private const FORBIDDEN_CORE_NAMESPACES = array(
		'RAN\\Admin\\',
		'RAN\\Deployment\\',
		'RAN\\Internal\\',
		'RAN\\Logging\\',
		'RAN\\Secrets\\',
		'RAN\\Storage\\',
		'RAN\\WordPress\\',
	);

	private const ALLOWED_CORE_IMPORTS = array(
		'RAN\RepositoryProvider\Admin\CredentialFieldMetadata',
		'RAN\RepositoryProvider\Admin\CredentialKindMetadata',
		'RAN\RepositoryProvider\Admin\ProviderAdminMetadata',
		'RAN\RepositoryProvider\Admin\ProviderNavigationPlacement',
		'RAN\RepositoryProvider\Admin\ProviderSetupMetadata',
		'RAN\RepositoryProvider\Admin\ProviderWebhookAssistanceMetadata',
		'RAN\RepositoryProvider\Admin\WebhookScopeMetadata',
		'RAN\RepositoryProvider\ArchiveRequest',
		'RAN\RepositoryProvider\AuthenticatedPreparedArchive',
		'RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader',
		'RAN\RepositoryProvider\CredentialExpiryReport',
		'RAN\RepositoryProvider\CredentialValidationResult',
		'RAN\RepositoryProvider\CredentialValidator',
		'RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser',
		'RAN\RepositoryProvider\GitReferenceSyntax',
		'RAN\RepositoryProvider\InvalidCredentialInput',
		'RAN\RepositoryProvider\InvalidWebhookInput',
		'RAN\RepositoryProvider\PreparedArchive',
		'RAN\RepositoryProvider\ProviderCode',
		'RAN\RepositoryProvider\ProviderCredentialPolicy',
		'RAN\RepositoryProvider\ProviderCredentialPolicySupplier',
		'RAN\RepositoryProvider\ProviderCredentialStore',
		'RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded',
		'RAN\RepositoryProvider\ProviderDiagnosticRequest',
		'RAN\RepositoryProvider\ProviderDiagnosticResult',
		'RAN\RepositoryProvider\ProviderDiagnostics',
		'RAN\RepositoryProvider\ProviderMetadata',
		'RAN\RepositoryProvider\ProviderWebhookPolicy',
		'RAN\RepositoryProvider\ProviderWebhookProfileReader',
		'RAN\RepositoryProvider\PublicRepositoryBrowseMetadata',
		'RAN\RepositoryProvider\PushEvent',
		'RAN\RepositoryProvider\RepositoryBrowseMode',
		'RAN\RepositoryProvider\RepositoryBrowseRequest',
		'RAN\RepositoryProvider\RepositoryBrowseResult',
		'RAN\RepositoryProvider\RepositoryDescriptor',
		'RAN\RepositoryProvider\RepositoryLookupRequest',
		'RAN\RepositoryProvider\RepositoryProvider',
		'RAN\RepositoryProvider\RepositoryReference',
		'RAN\RepositoryProvider\RepositoryWebhookFitness',
		'RAN\RepositoryProvider\RepositoryWebhookFitnessResult',
		'RAN\RepositoryProvider\RepositoryWebhookManagement',
		'RAN\RepositoryProvider\RepositoryWebhookOperationResult',
		'RAN\RepositoryProvider\RepositoryWebhookSettingsLink',
		'RAN\RepositoryProvider\SignedWebhookVerification',
		'RAN\RepositoryProvider\StaleDeployment',
		'RAN\RepositoryProvider\SubmittedCredentialValidator',
		'RAN\RepositoryProvider\WebhookEnvelope',
		'RAN\RepositoryProvider\WebhookNormalizer',
		'RAN\RepositoryProvider\WebhookRejected',
		'RAN\RepositoryProvider\WebhookRequest',
	);

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

	public function testModuleImportsOnlyTheExplicitProviderApiAllowlist(): void {
		$imports = array();
		foreach ( $this->moduleFiles() as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local architecture boundary under test.
			$source = file_get_contents( $path );
			self::assertIsString( $source );
			foreach ( self::FORBIDDEN_CORE_NAMESPACES as $namespace ) {
				self::assertStringNotContainsString( $namespace, $source, $path );
			}
			preg_match_all( '/^use\s+(RAN\\\\[^;]+);/m', $source, $matches );
			foreach ( $matches[1] as $import ) {
				$imports[] = preg_replace( '/\s+as\s+.+$/i', '', $import );
			}
		}

		$imports = array_values( array_unique( $imports ) );
		sort( $imports );
		$allowed = self::ALLOWED_CORE_IMPORTS;
		sort( $allowed );
		self::assertSame( $allowed, $imports );
	}

	public function testCoreReferencesOnlyTheNamedGitHubCompositionSeam(): void {
		$references = array();
		$root       = dirname( __DIR__, 3 ) . '/RAN';
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/RAN/Booster/GitHub/' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local architecture boundary under test.
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			if ( str_contains( $source, 'RAN\Booster\GitHub\\' ) ) {
				$references[] = str_replace( dirname( __DIR__, 3 ) . '/', '', $file->getPathname() );
				self::assertStringContainsString( 'use RAN\Booster\GitHub\GitHubProvider;', $source );
				self::assertSame( 1, substr_count( $source, 'RAN\Booster\GitHub\\' ) );
			}
		}

		self::assertSame( array( 'RAN/BoosterServiceProvider.php' ), $references );
	}

	public function testCoreTestsReferenceGitHubConcretesOnlyThroughExplicitHostIntegrationOwners(): void {
		$root       = dirname( __DIR__, 3 );
		$references = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests' ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/tests/Booster/GitHub/' ) ) {
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

	/** @return list<string> */
	private function moduleFiles(): array {
		$files = glob( dirname( __DIR__, 3 ) . '/RAN/Booster/GitHub/*.php' );
		self::assertIsArray( $files );
		sort( $files );

		return $files;
	}
}

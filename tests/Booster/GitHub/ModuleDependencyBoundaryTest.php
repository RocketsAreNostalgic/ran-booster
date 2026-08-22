<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;

final class ModuleDependencyBoundaryTest extends TestCase {

	private const FORBIDDEN_CORE_NAMESPACES = array(
		'RAN\\Admin\\',
		'RAN\\Internal\\',
		'RAN\\Logging\\',
		'RAN\\Secrets\\',
		'RAN\\Storage\\',
		'RAN\\WordPress\\',
	);

	private const ALLOWED_CORE_IMPORTS = array(
		'RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade',
		'RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus',
		'RAN\Deployment\PreparedArtifact',
		'RAN\RepositoryProvider\Admin\CredentialFieldMetadata',
		'RAN\RepositoryProvider\Admin\CredentialKindMetadata',
		'RAN\RepositoryProvider\Admin\ProviderAdminMetadata',
		'RAN\RepositoryProvider\Admin\ProviderNavigationPlacement',
		'RAN\RepositoryProvider\Admin\ProviderSetupMetadata',
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
		'RAN\RepositoryProvider\RepositoryReleaseAcquirer',
		'RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected',
		'RAN\RepositoryProvider\RepositoryReleaseArtifact',
		'RAN\RepositoryProvider\RepositoryReleaseCandidate',
		'RAN\RepositoryProvider\RepositoryReleaseCandidateList',
		'RAN\RepositoryProvider\RepositoryReleaseCandidateListing',
		'RAN\RepositoryProvider\RepositoryReleaseInspection',
		'RAN\RepositoryProvider\RepositoryReleaseInspectionRejected',
		'RAN\RepositoryProvider\RepositoryReleaseInspector',
		'RAN\RepositoryProvider\RepositoryReleaseMetadata',
		'RAN\RepositoryProvider\RepositoryReleaseNativeTarget',
		'RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus',
		'RAN\RepositoryProvider\RepositoryReleaseNativeTargets',
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
		'RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact',
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

	/** @return list<string> */
	private function moduleFiles(): array {
		$module        = dirname( __DIR__, 3 ) . '/RAN/Booster/GitHub';
		$rootFiles     = glob( $module . '/*.php' );
		$workflowFiles = glob( $module . '/ReleaseDeployments/WorkflowAssistance/*.php' );
		self::assertIsArray( $rootFiles );
		self::assertIsArray( $workflowFiles );
		$files = array_merge( $rootFiles, $workflowFiles );
		sort( $files );

		return $files;
	}
}

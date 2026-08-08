<?php

declare(strict_types=1);

namespace Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PortabilityController;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageOperationService;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintExportPackageFailure;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\Portability\UnsupportedBlueprintPackages;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use ReflectionClass;
use RuntimeException;
use Tests\Portability\TemporaryCredentialProvider;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PortabilityControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$_FILES = array();
		$_POST  = array();
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
		unset( $GLOBALS['ran_booster_repository_admin_capabilities'] );
		unset( $GLOBALS['ran_booster_repository_admin_uploaded_files'] );
	}

	protected function tearDown(): void {
		$_FILES = array();
		$_POST  = array();
		unset( $GLOBALS['ran_booster_repository_admin_allowed'] );
		unset( $GLOBALS['ran_booster_repository_admin_capabilities'] );
		unset( $GLOBALS['ran_booster_repository_admin_uploaded_files'] );

		parent::tearDown();
	}

	public function testPreviewRejectsUnauthorisedRequestsBeforeReadingAnUpload(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = $this->controller()->handlePreview();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
	}

	public function testPreviewRejectsMissingUploadsWithoutUsingPortabilityServices(): void {
		$result = $this->controller()->handlePreview();

		self::assertFalse( $result['success'] );
		self::assertSame( 400, $result['status'] );
		self::assertSame( 'Choose a valid Transporter Blueprint ZIP to review.', $result['data']['message'] );
	}

	public function testApplyRejectsUnauthorisedRequestsBeforeReadingAnUpload(): void {
		$GLOBALS['ran_booster_repository_admin_allowed'] = false;

		$result = $this->controller()->handleApply();

		self::assertFalse( $result['success'] );
		self::assertSame( 403, $result['status'] );
	}

	public function testApplyRejectsMissingUploadAndRowWithoutUsingPortabilityServices(): void {
		$result = $this->controller()->handleApply();

		self::assertFalse( $result['success'] );
		self::assertSame( 400, $result['status'] );
		self::assertSame( 'Choose the same Transporter Blueprint and package row to apply.', $result['data']['message'] );
	}

	public function testPreviewAcceptsARealUploadedBlueprintArchive(): void {
		$file = $this->blueprintArchive( new PackageBlueprint( array( $this->blueprintPackage() ) ) );
		$this->setUploadedBlueprint( $file );

		try {
			$result = $this->previewController( new PortabilityReadinessSpySecretsFile( true ) )->handlePreview();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertStringContainsString( 'data-portability-row="0"', $result['data']['html'] );
		self::assertStringContainsString( 'data-portability-action="install"', $result['data']['html'] );
	}

	public function testApplyRecomputesAndSkipsAStaleSubmittedAction(): void {
		$file                   = $this->blueprintArchive( new PackageBlueprint( array( $this->blueprintPackage() ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'adopt';
		$this->setUploadedBlueprint( $file );

		try {
			$result = $this->previewController( new PortabilityReadinessSpySecretsFile( true ) )->handleApply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertStringContainsString( 'changed since review', $result['data']['message'] );
	}

	public function testApplyRowBoundMatchesTheBlueprintPackageBound(): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'requestedRow' );

		$_POST['row'] = '127';
		self::assertSame( 127, $method->invoke( $this->controller() ) );

		$_POST['row'] = '128';
		self::assertNull( $method->invoke( $this->controller() ) );
	}

	public function testCredentialDecisionParserAcceptsOnlyTheClosedOrdinalShape(): void {
		$blueprint                     = $this->credentialBlueprint();
		$_POST['credential_decisions'] = array(
			'0' => array(
				'action'    => 'target',
				'target_id' => 'saved-profile',
			),
		);
		$method                        = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'credentialDecisions' );

		self::assertSame(
			array(
				0 => array(
					'action'    => BlueprintCredentialAction::TARGET,
					'target_id' => 'saved-profile',
				),
			),
			$method->invoke( $this->controller(), $blueprint )
		);
	}

	#[DataProvider( 'invalidCredentialDecisions' )]
	public function testCredentialDecisionParserRejectsAmbiguousOrMalformedInput( mixed $input ): void {
		$_POST['credential_decisions'] = $input;
		$method                        = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'credentialDecisions' );

		$this->expectException( InvalidArgumentException::class );
		$method->invoke( $this->controller(), $this->credentialBlueprint() );
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidCredentialDecisions(): iterable {
		yield 'scalar root' => array( 'import' );
		yield 'unknown ordinal' => array( array( 1 => array( 'action' => 'import' ) ) );
		yield 'non-canonical ordinal' => array( array( '00' => array( 'action' => 'import' ) ) );
		yield 'unknown action' => array( array( 0 => array( 'action' => 'automatic' ) ) );
		yield 'extra key' => array(
			array(
				0 => array(
					'action'   => 'import',
					'fallback' => 'target',
				),
			),
		);
		yield 'target missing id' => array( array( 0 => array( 'action' => 'target' ) ) );
		yield 'import with target id' => array(
			array(
				0 => array(
					'action'    => 'import',
					'target_id' => 'saved-profile',
				),
			),
		);
		yield 'constant target' => array(
			array(
				0 => array(
					'action'    => 'target',
					'target_id' => SecretsFile::CONSTANT_PROFILE,
				),
			),
		);
	}

	public function testMissingCredentialDecisionLeavesApplyUnchangedWithoutStorageOrProviderWork(): void {
		$secrets                = new PortabilityReadinessSpySecretsFile( false );
		$file                   = $this->blueprintArchive( $this->credentialBlueprint(), 'correct-horse-battery-staple' );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'install';
		$_POST['password']      = 'correct-horse-battery-staple';
		$this->setUploadedBlueprint( $file );

		try {
			$result = $this->previewController( $secrets )->handleApply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertSame( 'none', $result['data']['credential_state'] );
		self::assertSame( 0, $secrets->readinessChecks );
	}

	public function testExportParsesTheExactBoundedCredentialSelection(): void {
		$expected             = array(
			'gh' => array( 'classic-profile', 'fine_grained-profile' ),
			'bb' => array( 'bitbucket-profile' ),
		);
		$_POST['credentials'] = $expected;

		self::assertSame( $expected, $this->selectedCredentials() );
		unset( $_POST['credentials'] );
		self::assertSame( array(), $this->selectedCredentials() );
	}

	#[DataProvider( 'invalidCredentialSelections' )]
	public function testExportRejectsMalformedCredentialSelections( mixed $selection ): void {
		$_POST['credentials'] = $selection;
		$this->expectException( InvalidArgumentException::class );
		$this->selectedCredentials();
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidCredentialSelections(): iterable {
		yield 'scalar root' => array( 'gh' );
		yield 'invalid provider' => array( array( 'GitHub' => array( 'valid-profile' ) ) );
		yield 'scalar provider selection' => array( array( 'gh' => 'valid-profile' ) );
		yield 'empty provider selection' => array( array( 'gh' => array() ) );
		yield 'associative provider selection' => array( array( 'gh' => array( 'profile' => 'valid-profile' ) ) );
		yield 'constant profile' => array( array( 'gh' => array( SecretsFile::CONSTANT_PROFILE ) ) );
		yield 'malformed profile' => array( array( 'gh' => array( 'invalid profile' ) ) );
		yield 'duplicate profile' => array( array( 'gh' => array( 'valid-profile', 'valid-profile' ) ) );
		yield 'over bound' => array( array( 'gh' => array_map( static fn ( int $index ): string => 'profile_' . $index, range( 0, PackageBlueprint::MAX_CREDENTIALS ) ) ) );
	}

	public function testApplyFailureKeepsTheSafeStorageFailureMessage(): void {
		try {
			PackageMutationResult::conflict(
				PackageStorageOperation::INSERT,
				'ran_booster_storage_adoption_conflict',
				'Booster found existing package management data. No package changes were made.'
			)->requireSuccess();
			self::fail( 'A failed storage mutation must throw.' );
		} catch ( PackageStorageFailure $failure ) {
			$result = $this->applyFailure( $failure );
		}

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Booster found existing package management data. No package changes were made.', $result['message'] );
	}

	public function testApplyFailureKeepsTheActionableDatabaseRequirement(): void {
		$result = $this->applyFailure( PackageStorageFailure::unsupportedDatabase() );

		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( 'database requirements', $result['message'] );
	}

	public function testApplyFailureDoesNotExposeUnexpectedExceptionText(): void {
		$result = $this->applyFailure( new RuntimeException( 'Sensitive provider failure detail.' ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'Booster could not apply this package. Review the Transporter Blueprint again and check repository access.', $result['message'] );
	}

	public function testLocalStoreApplyFailureHasItsOwnCategoryAndMessage(): void {
		$result = $this->applyFailure( LocalSecretStoreUnavailable::forPortability() );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'local_secret_store_unavailable', $result['category'] );
		self::assertStringContainsString( 'encrypted credential storage is unavailable', $result['message'] );
		self::assertStringNotContainsString( 'repository access', $result['message'] );
	}

	public function testPackageOnlyPreviewDoesNotPreflightEncryptedStorage(): void {
		$secrets   = new PortabilityReadinessSpySecretsFile( false );
		$blueprint = new PackageBlueprint( array( $this->blueprintPackage() ) );
		$file      = $this->blueprintArchive( $blueprint );

		try {
			$html = $this->previewController( $secrets )->previewFile( $file );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertSame( 0, $secrets->readinessChecks );
		self::assertStringContainsString( 'Ready to migrate; all checks passed.', $html );
	}

	public function testCredentialPreviewReportsUnavailableTargetStorageWithoutPersistence(): void {
		$secrets   = new PortabilityReadinessSpySecretsFile( false );
		$package   = $this->blueprintPackage();
		$blueprint = new PackageBlueprint(
			array( $package ),
			array(
				new BlueprintCredential(
					'gh',
					'Imported credential',
					'classic',
					array(),
					'sentinel-portability-token',
					array(
						array(
							'type'       => 'plugin',
							'identifier' => $package->identifier,
						),
					)
				),
			)
		);
		$file      = $this->blueprintArchive( $blueprint, 'correct-horse-battery-staple' );

		try {
			$html = $this->previewController( $secrets )->previewFile( $file, 'correct-horse-battery-staple' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertSame( 0, $secrets->readinessChecks );
		self::assertStringContainsString( 'Repository credentials', $html );
		self::assertStringContainsString( 'name="credential_decisions[0][action]" value="import"', $html );
		self::assertStringNotContainsString( 'value="import" data-portability-credential-action aria-describedby="ran-booster-portability-credential-description-0" checked', $html );
		self::assertStringNotContainsString( 'sentinel-portability-token', $html );
	}

	public function testApplyRecognisesAManagedPackageWithoutMutation(): void {
		$package   = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$blueprint = new PackageBlueprint( array( $package ) );
		$item      = new BlueprintPlanItem( $package, TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'applyItem' );

		$result = $method->invoke( $this->application(), $blueprint, $item, null, null, null, null, false, false );

		self::assertSame(
			array(
				'status'           => 'unchanged',
				'message'          => 'This package is already managed.',
				'credential_state' => 'none',
			),
			$result
		);
	}

	public function testAdoptRequiresExplicitApprovalBeforeMutation(): void {
		$file                   = $this->blueprintArchive( new PackageBlueprint( array( $this->blueprintPackage() ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'adopt';
		$this->setUploadedBlueprint( $file );

		try {
			$result = $this->previewController( new PortabilityReadinessSpySecretsFile( true ), true )->handleApply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'skipped', $result['data']['status'] );
		self::assertStringContainsString( 'not selected for adoption', $result['data']['message'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testPackageTypeCapabilityIsRequiredBeforeMutation( string $type, string $identifier ): void {
		$file                   = $this->blueprintArchive( new PackageBlueprint( array( $this->blueprintPackage( $type, $identifier ) ) ) );
		$_POST['row']           = '0';
		$_POST['review_action'] = 'install';
		$GLOBALS['ran_booster_repository_admin_capabilities'] = array(
			'manage_options'  => true,
			'install_plugins' => 'theme' === $type,
			'install_themes'  => 'plugin' === $type,
		);
		$this->setUploadedBlueprint( $file );

		try {
			$result = $this->previewController( new PortabilityReadinessSpySecretsFile( true ) )->handleApply();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test temporary file is outside WordPress media handling.
			unlink( $file );
		}

		self::assertTrue( $result['success'] );
		self::assertSame( 'failed', $result['data']['status'] );
		self::assertStringContainsString( 'permission to apply this package type', $result['data']['message'] );
	}

	/** @return iterable<string, array{string, string}> */
	public static function packageTypeProvider(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function testPublicRepositoryInputKeepsItsCredentialAssociation(): void {
		$package = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$item    = new BlueprintPlanItem( $package, TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		$method  = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'operationInput' );

		$input = $method->invoke( $this->application(), $item, 'imported-pat', false );

		self::assertSame( 'imported-pat', $input['credential_id'] );
		self::assertSame( '0', $input['private'] );
		self::assertSame( DeploymentPolicy::DISABLED->value, $input['deployment_policy'] );
		self::assertSame( '1', $method->invoke( $this->application(), $item, 'imported-pat', true )['private'] );
	}

	public function testBlueprintSuccessRequiresAnExactDisabledReadBack(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assertDisabledResult' );

		$method->invoke( $this->application(), array( 'package' => $this->managedPlugin( DeploymentPolicy::DISABLED ) ), $blueprint, null, false );
		$method->invoke(
			$this->application(),
			array( 'package' => $this->managedPlugin( DeploymentPolicy::DISABLED, private: true, credentialId: 'target-profile' ) ),
			$blueprint,
			'target-profile',
			true
		);
		$this->addToAssertionCount( 1 );
	}

	public function testExactTargetPredicateSupportsOnlyVerifiedManagedRetry(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'targetVerified' );

		self::assertTrue( $method->invoke( $this->application(), $this->managedPlugin( DeploymentPolicy::DISABLED ), $blueprint, null, false ) );
		self::assertFalse( $method->invoke( $this->application(), $this->managedPlugin( DeploymentPolicy::MANUAL ), $blueprint, null, false ) );
		self::assertFalse( $method->invoke( $this->application(), $this->managedPlugin( DeploymentPolicy::DISABLED, source: PackageSource::RELEASE_ASSET ), $blueprint, null, false ) );
	}

	public function testBlueprintSuccessRejectsAManualReadBack(): void {
		$blueprint = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method    = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assertDisabledResult' );

		$this->expectException( RuntimeException::class );
		$method->invoke( $this->application(), array( 'package' => $this->managedPlugin( DeploymentPolicy::MANUAL ) ), $blueprint, null, false );
	}

	public function testBlueprintSuccessRejectsAnyMismatchedManagementField(): void {
		$blueprint  = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$method     = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'assertDisabledResult' );
		$mismatches = array(
			'provider'     => $this->managedPlugin( DeploymentPolicy::DISABLED, private: true, credentialId: 'target-profile', provider: 'gitlab' ),
			'type'         => $this->managedThemeWithPluginIdentifier(),
			'source'       => $this->managedPlugin( DeploymentPolicy::DISABLED, private: true, credentialId: 'target-profile', source: PackageSource::RELEASE_ASSET ),
			'locator'      => $this->managedPlugin( DeploymentPolicy::DISABLED, locator: 'owner/other', private: true, credentialId: 'target-profile' ),
			'stable id'    => $this->managedPlugin( DeploymentPolicy::DISABLED, providerRepositoryId: 'other-id', private: true, credentialId: 'target-profile' ),
			'branch'       => $this->managedPlugin( DeploymentPolicy::DISABLED, branch: 'develop', private: true, credentialId: 'target-profile' ),
			'credential'   => $this->managedPlugin( DeploymentPolicy::DISABLED, private: true, credentialId: 'other-profile' ),
			'privacy'      => $this->managedPlugin( DeploymentPolicy::DISABLED, credentialId: 'target-profile' ),
			'subdirectory' => $this->managedPlugin( DeploymentPolicy::DISABLED, private: true, credentialId: 'target-profile', subdirectory: 'plugin' ),
		);

		foreach ( $mismatches as $label => $package ) {
			try {
				$method->invoke( $this->application(), array( 'package' => $package ), $blueprint, 'target-profile', true );
				self::fail( $label . ' mismatch must fail exact target verification.' );
			} catch ( \ReflectionException $failure ) {
				throw $failure;
			} catch ( \Throwable $failure ) {
				self::assertInstanceOf( RuntimeException::class, $failure, $label );
			}
		}
	}

	#[DataProvider( 'exportPasswordErrorProvider' )]
	public function testProtectedExportPasswordValidationMatchesTheClientContract(
		bool $includeCredentials,
		?string $password,
		?string $confirmation,
		?string $expected
	): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'exportPasswordError' );

		self::assertSame( $expected, $method->invoke( $this->controller(), $includeCredentials, $password, $confirmation ) );
	}

	public function testReleaseManagedExportFailureNamesEveryAffectedPackageAndItsLimitation(): void {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'exportValidationFailureMessage' );

		self::assertSame(
			'Transporter Blueprint export cannot include: Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster; Theme “Example Theme” manages its own updates and cannot also be managed by Booster. Deselect those packages and try again.',
			$method->invoke(
				$this->controller(),
				new UnsupportedBlueprintPackages(
					array(
						new BlueprintExportPackageFailure( 'plugin', 'RAN GitHub Updater Dummy', BlueprintExportPackageFailure::PUBLISHED_RELEASES ),
						new BlueprintExportPackageFailure( 'theme', 'Example Theme', BlueprintExportPackageFailure::PUBLISHED_RELEASES ),
					)
				)
			)
		);
	}

	public function testInlineExportFailureReturnsTheSpecificMessageAsJson(): void {
		$_POST['response_format'] = 'json';
		$method                   = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'exportFailure' );

		self::assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster.' ),
				'status'  => 400,
			),
			$method->invoke( $this->controller(), 'Plugin “RAN GitHub Updater Dummy” manages its own updates and cannot also be managed by Booster.', 400 )
		);
	}

	/** @return iterable<string, array{bool, ?string, ?string, ?string}> */
	public static function exportPasswordErrorProvider(): iterable {
		yield 'unprotected' => array( false, null, null, null );
		yield 'missing' => array( true, null, null, 'Choose a Transporter Blueprint password before exporting credentials.' );
		yield 'mismatch' => array( true, 'correct-horse-battery-staple', 'different-password-value', 'The Transporter Blueprint passwords do not match. Nothing was exported.' );
		yield 'matching' => array( true, 'correct-horse-battery-staple', 'correct-horse-battery-staple', null );
	}

	public function testInstallSuccessExplainsTheDisabledDeploymentGate(): void {
		$result = $this->deploymentResult(
			array( 'status' => 'succeeded' )
		);

		self::assertSame( 'installed', $result['status'] );
		self::assertStringContainsString( 'deployment disabled', $result['message'] );
		self::assertStringContainsString( 'Re-enable deployment deliberately', $result['message'] );
	}

	public function testInstallFailureUsesTheSpecificDeploymentOutcomeAndReference(): void {
		$result = $this->deploymentResult(
			array(
				'status'         => 'failed',
				'outcome_code'   => DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE,
				'correlation_id' => str_repeat( 'a', 32 ),
			)
		);

		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( 'configured archive download limit', $result['message'] );
		self::assertStringContainsString( 'Reference: ' . str_repeat( 'a', 32 ), $result['message'] );
		self::assertStringNotContainsString( 'check repository access', $result['message'] );
	}

	public function testInstallFailureKeepsTheSafeOutcomeIndependentOfPackageType(): void {
		$result = $this->deploymentResult(
			array(
				'status'       => 'failed',
				'outcome_code' => DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE,
			)
		);

		self::assertStringContainsString( 'configured archive download limit', $result['message'] );
	}

	public function testInstallFailureRejectsUnsafeOutcomeEvidenceAndMalformedReferences(): void {
		$result = $this->deploymentResult(
			array(
				'status'         => 'failed',
				'outcome_code'   => 'Authorization: Bearer secret-canary',
				'correlation_id' => 'secret-canary',
			)
		);

		self::assertSame( 'Booster recorded an unavailable deployment outcome.', $result['message'] );
		self::assertStringNotContainsString( 'secret-canary', $result['message'] );
	}

	public function testItReadsOnlyBoundedPluginAndThemeSelections(): void {
		$_POST['packages'] = array(
			'plugin' => array( 'example/example.php' ),
			'theme'  => array( 'example-theme' ),
		);

		self::assertSame(
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
				array(
					'type'       => 'theme',
					'identifier' => 'example-theme',
				),
			),
			$this->selectedPackages()
		);
	}

	/** @param mixed $input */
	#[DataProvider( 'invalidPackageSelections' )]
	public function testItRejectsMalformedPackageSelections( mixed $input ): void {
		$_POST['packages'] = $input;

		$this->expectException( InvalidArgumentException::class );
		$this->selectedPackages();
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidPackageSelections(): iterable {
		yield 'not an array' => array( 'example/example.php' );
		yield 'empty' => array( array() );
		yield 'unknown group' => array( array( 'vendor' => array( 'example/example.php' ) ) );
		yield 'non-list group' => array( array( 'plugin' => array( 'chosen' => 'example/example.php' ) ) );
		yield 'non-string identity' => array( array( 'plugin' => array( 7 ) ) );
		yield 'duplicate identity' => array( array( 'plugin' => array( 'example/example.php', 'example/example.php' ) ) );
		yield 'too many' => array(
			array(
				'plugin' => array_map(
					static fn( int $index ): string => 'example-' . $index . '/example.php',
					range( 0, PackageBlueprint::MAX_PACKAGES )
				),
			),
		);
	}

	private function controller(): PortabilityController {
		return ( new ReflectionClass( PortabilityController::class ) )->newInstanceWithoutConstructor();
	}

	private function previewController( SecretsFile $secrets, bool $installed = false, string $type = 'plugin' ): PortabilityController {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'isInstalled' )->willReturn( $installed && 'plugin' === $type );
		$plugins->method( 'hasManagementRecord' )->willReturn( false );
		$themes->method( 'isInstalled' )->willReturn( $installed && 'theme' === $type );
		$themes->method( 'hasManagementRecord' )->willReturn( false );
		$catalog  = new ProviderSecretPolicyCatalog();
		$provider = new TemporaryCredentialProvider( $secrets->credentialsFor( 'gh' ), 0, 'repository-id' );
		$registry = new ProviderRegistry( array( $provider ), $catalog );

		$application = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets ),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);

		return new PortabilityController(
			( new ReflectionClass( ManagedPackageBlueprintExporter::class ) )->newInstanceWithoutConstructor(),
			new BlueprintArchive(),
			$application,
			( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function blueprintArchive( PackageBlueprint $blueprint, ?string $password = null ): string {
		$file = tempnam( sys_get_temp_dir(), 'ran-booster-portability-controller-' );
		self::assertIsString( $file );
		( new BlueprintArchive() )->writeTo( $file, $blueprint, $password );

		return $file;
	}

	private function blueprintPackage( string $type = 'plugin', ?string $identifier = null ): BlueprintPackage {
		return new BlueprintPackage(
			$type,
			$identifier ?? ( 'plugin' === $type ? 'example/example.php' : 'example-theme' ),
			'Example',
			'gh',
			'repository-id',
			'owner/repository',
			'main',
			null
		);
	}

	private function credentialBlueprint(): PackageBlueprint {
		$package = $this->blueprintPackage();

		return new PackageBlueprint(
			array( $package ),
			array(
				new BlueprintCredential(
					'gh',
					'Imported credential',
					'classic',
					array(),
					'sentinel-portability-token',
					array(
						array(
							'type'       => $package->type,
							'identifier' => $package->identifier,
						),
					)
				),
			)
		);
	}

	private function managedPlugin(
		DeploymentPolicy $policy,
		string $locator = 'owner/example',
		string $providerRepositoryId = 'repository-id',
		string $branch = 'main',
		bool $private = false,
		?string $credentialId = null,
		?string $subdirectory = null,
		string $provider = 'gh',
		PackageSource $source = PackageSource::BRANCH
	): Plugin {
		$plugin = Plugin::fromWpArray(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugin->setRepository( new ManagedRepository( $provider, $locator, $providerRepositoryId, $branch, $private, $credentialId ) );
		$plugin->setDeploymentPolicy( $policy );
		$plugin->setSubdirectory( $subdirectory );
		$plugin->setSource( $source, 1 );

		return $plugin;
	}

	private function managedThemeWithPluginIdentifier(): Theme {
		$theme = new class() extends Theme {
			public function __construct() {
				$this->stylesheet = 'example/example.php';
				$this->name       = 'Example';
			}
		};
		$theme->setRepository( new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'main', true, 'target-profile' ) );
		$theme->setDeploymentPolicy( DeploymentPolicy::DISABLED );

		return $theme;
	}

	/** @return list<array{type:string,identifier:string}> */
	private function selectedPackages(): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'selectedPackages' );

		return $method->invoke( $this->controller() );
	}

	/** @return array<string, list<string>> */
	private function selectedCredentials(): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'selectedCredentials' );

		return $method->invoke( $this->controller() );
	}

	/** @return array{status:string,message:string,category?:string} */
	private function applyFailure( \Throwable $failure ): array {
		$method = ( new ReflectionClass( PortabilityController::class ) )->getMethod( 'applyFailure' );

		return $method->invoke( $this->controller(), $failure );
	}

	/** @param array<string, mixed> $result @return array{status:string,message:string} */
	private function deploymentResult( array $result ): array {
		$method = ( new ReflectionClass( PortabilityApplicationService::class ) )->getMethod( 'deploymentResult' );

		return $method->invoke( $this->application(), $result );
	}

	private function application(): PortabilityApplicationService {
		return ( new ReflectionClass( PortabilityApplicationService::class ) )->newInstanceWithoutConstructor();
	}

	private function setUploadedBlueprint( string $file ): void {
		$GLOBALS['ran_booster_repository_admin_uploaded_files'] = array( $file );
		$_FILES['blueprint']                                    = array(
			'error'    => UPLOAD_ERR_OK,
			'tmp_name' => $file,
			'name'     => 'ran-booster-blueprint.zip',
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused readiness spy belongs with controller behavior tests.
final class PortabilityReadinessSpySecretsFile extends SecretsFile {
	public int $readinessChecks = 0;

	public function __construct( private readonly bool $ready ) {
		parent::__construct( null, array(), new ProviderSecretPolicyCatalog() );
	}

	public function assertManagedStorageReady(): void {
		++$this->readinessChecks;
		if ( ! $this->ready ) {
			throw new RuntimeException( 'Test target storage is unavailable.' );
		}
	}
}

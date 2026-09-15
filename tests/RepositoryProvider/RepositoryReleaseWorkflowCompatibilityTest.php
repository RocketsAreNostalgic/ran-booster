<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use ReflectionClass;
use ReflectionNamedType;

final class RepositoryReleaseWorkflowCompatibilityTest extends TestCase {
	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function testApiTwoAwareProviderRemainsLoadableOnOlderApiTenHost(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );
		$autoloaders = spl_autoload_functions();

		try {
			foreach ( $autoloaders as $autoloader ) {
				spl_autoload_unregister( $autoloader );
			}

			require dirname( __DIR__ ) . '/fixtures/provider-workflow-v2-feature-detection/provider.php';
		} finally {
			foreach ( $autoloaders as $autoloader ) {
				spl_autoload_register( $autoloader );
			}
		}

		self::assertTrue( class_exists( 'RANBoosterWorkflowV2FeatureDetectionProvider', false ) );
		self::assertFalse( class_exists( 'RANBoosterWorkflowV2FeatureDetectionProviderV2', false ) );
	}

	public function testApiOneContractRemainsLoadCompatible(): void {
		$provider = new class() implements RepositoryReleaseWorkflowManagement {
			public function workflowStatus( ReleaseTrackingStatus $status ): RepositoryReleaseWorkflowStatus {
				throw new LogicException();
			}

			public function workflowPreview(
				ReleaseTrackingStatus $status,
				string $key
			): ?RepositoryReleaseWorkflowPreview {
				throw new LogicException();
			}

			public function workflowInspect(
				ReleaseTrackingStatus $status,
				string $channel,
				ReleaseTrackingPreflight $preflight,
				?string $credentialId
			): RepositoryReleaseWorkflowResult {
				throw new LogicException();
			}

			public function workflowSetup(
				ReleaseTrackingStatus $status,
				string $key,
				string $confirmation,
				ReleaseTrackingPreflight $preflight,
				?string $credentialId
			): RepositoryReleaseWorkflowResult {
				throw new LogicException();
			}

			public function workflowOutcome(
				ReleaseTrackingStatus $status,
				?string $credentialId
			): RepositoryReleaseWorkflowResult {
				throw new LogicException();
			}

			public function workflowInspectUpdate(
				ReleaseTrackingStatus $status,
				?string $credentialId
			): RepositoryReleaseWorkflowResult {
				throw new LogicException();
			}

			public function workflowSetupUpdate(
				ReleaseTrackingStatus $status,
				string $key,
				string $confirmation,
				?string $credentialId
			): RepositoryReleaseWorkflowResult {
				throw new LogicException();
			}
		};

		self::assertInstanceOf( RepositoryReleaseWorkflowManagement::class, $provider );
		self::assertNotInstanceOf( RepositoryReleaseWorkflowManagementV2::class, $provider );
		self::assertSame( 1, $provider::RELEASE_WORKFLOW_API_VERSION );
	}

	public function testApiTwoIsASeparateProviderNeutralFacet(): void {
		self::assertFalse(
			is_subclass_of( RepositoryReleaseWorkflowManagementV2::class, RepositoryReleaseWorkflowManagement::class )
		);
		self::assertSame( 1, RepositoryReleaseWorkflowManagement::RELEASE_WORKFLOW_API_VERSION );
		self::assertSame( 2, RepositoryReleaseWorkflowManagementV2::RELEASE_WORKFLOW_API_VERSION );

		$reflection  = new ReflectionClass( RepositoryReleaseWorkflowManagementV2::class );
		$statusType  = $reflection->getMethod( 'workflowStatus' )->getParameters()[0]->getType();
		$inspectType = $reflection->getMethod( 'workflowInspect' )->getParameters()[2]->getType();

		self::assertInstanceOf( ReflectionNamedType::class, $statusType );
		self::assertInstanceOf( ReflectionNamedType::class, $inspectType );
		self::assertSame( RepositoryReleaseWorkflowTarget::class, $statusType->getName() );
		self::assertSame( RepositoryReleaseWorkflowPreflight::class, $inspectType->getName() );
	}
}

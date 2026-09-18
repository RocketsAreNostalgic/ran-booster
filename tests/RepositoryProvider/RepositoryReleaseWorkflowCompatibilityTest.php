<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
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

	public function testApiTwoIsProviderNeutralFacet(): void {
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

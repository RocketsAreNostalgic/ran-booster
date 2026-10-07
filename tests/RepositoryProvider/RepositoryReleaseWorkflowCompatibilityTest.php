<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use ReflectionClass;
use ReflectionNamedType;

final class RepositoryReleaseWorkflowCompatibilityTest extends TestCase {
	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_api_three_aware_provider_remains_loadable_on_older_api_ten_host(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 10 );
		$autoloaders = spl_autoload_functions();

		try {
			foreach ( $autoloaders as $autoloader ) {
				spl_autoload_unregister( $autoloader );
			}

			require dirname( __DIR__ ) . '/fixtures/provider-workflow-v3-feature-detection/provider.php';
		} finally {
			foreach ( $autoloaders as $autoloader ) {
				spl_autoload_register( $autoloader );
			}
		}

		self::assertTrue( class_exists( 'RAN_Booster_WorkflowV3FeatureDetectionProvider', false ) );
		self::assertFalse( class_exists( 'RAN_Booster_WorkflowV3FeatureDetectionProviderV3', false ) );
	}

	public function test_api_three_is_provider_neutral_facet(): void {
		self::assertSame( 3, RepositoryReleaseWorkflowManagementV3::RELEASE_WORKFLOW_API_VERSION );

		$reflection = new ReflectionClass( RepositoryReleaseWorkflowManagementV3::class );
		self::assertFalse( $reflection->hasMethod( 'workflowInspectUpdate' ) );
		self::assertFalse( $reflection->hasMethod( 'workflowSetupUpdate' ) );
		self::assertFalse( interface_exists( 'RAN\\RepositoryProvider\\RepositoryReleaseWorkflowManagementV2' ) );
		$status_type  = $reflection->getMethod( 'workflow_status' )->getParameters()[0]->getType();
		$inspect_type = $reflection->getMethod( 'workflow_inspect' )->getParameters()[2]->getType();

		self::assertInstanceOf( ReflectionNamedType::class, $status_type );
		self::assertInstanceOf( ReflectionNamedType::class, $inspect_type );
		self::assertSame( RepositoryReleaseWorkflowTarget::class, $status_type->getName() );
		self::assertSame( RepositoryReleaseWorkflowPreflight::class, $inspect_type->getName() );
	}
}

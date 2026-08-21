<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/ProspectiveReleaseUpdaterFixtures.php';

// The focused facade spy belongs with the bootstrap contract it observes.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\WordPress\GitHubReleaseUpdaterBootstrap;
use TypeError;

#[CoversClass( GitHubReleaseUpdaterBootstrap::class )]
final class GitHubReleaseUpdaterBootstrapTest extends TestCase {

	public function testCoreAndUpdaterProspectiveApiGenerationsAreIndependent(): void {
		self::assertSame( 6, ProspectiveReleaseFacade::API_VERSION );
		self::assertSame( 4, GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION );
	}

	/** @return list<array{string, string}> */
	public static function channelProvider(): array {
		return array(
			array( '0.1.0-alpha.10', 'prerelease' ),
			array( '1.0.0', 'stable' ),
		);
	}

	#[DataProvider( 'channelProvider' )]
	public function testRegistersTheExactBoosterConfigurationOnce(
		string $pluginVersion,
		string $expectedChannel
	): void {
		$pluginFile = '/wordpress/wp-content/plugins/custom-booster/ran-booster.php';
		$calls      = array();
		$facade     = new GitHubReleaseUpdaterFacadeSpy();
		$factory    = static function ( mixed ...$options ) use ( &$calls, $facade ): object {
			$calls[] = $options;

			return $facade;
		};

		$result = GitHubReleaseUpdaterBootstrap::register( $pluginFile, $pluginVersion, $factory );

		self::assertSame( $facade, $result );
		self::assertSame( 1, $facade->registrations );
		self::assertSame(
			array(
				array(
					'pluginFile'           => $pluginFile,
					'repository'           => 'RocketsAreNostalgic/ran-booster',
					'providerRepositoryId' => '1319710173',
					'pluginSlug'           => 'ran-booster',
					'channel'              => $expectedChannel,
					'accessToken'          => null,
					'autoUpdatePolicy'     => 'forced-off',
					'cacheDuration'        => 21_600,
					'failureCacheDuration' => 900,
					'nativeDiscovery'      => true,
				),
			),
			$calls
		);
	}

	public function testRuntimeOnlyCoreTargetStillRegistersWithNativeDiscoveryDisabled(): void {
		$calls   = array();
		$facade  = new GitHubReleaseUpdaterFacadeSpy();
		$factory = static function ( mixed ...$options ) use ( &$calls, $facade ): object {
			$calls[] = $options;

			return $facade;
		};

		$result = GitHubReleaseUpdaterBootstrap::register(
			'/wordpress/wp-content/plugins/ran-booster/ran-booster.php',
			'1.0.0',
			$factory,
			false
		);

		self::assertSame( $facade, $result );
		self::assertSame( 'disabled', $calls[0]['autoUpdatePolicy'] );
		self::assertFalse( $calls[0]['nativeDiscovery'] );
		self::assertSame( 1, $facade->registrations );
	}

	public function testRejectsAnInvalidFactoryArgument(): void {
		$this->expectException( TypeError::class );

		GitHubReleaseUpdaterBootstrap::register(
			'/wordpress/wp-content/plugins/ran-booster/ran-booster.php',
			'1.0.0',
			'invalid-factory'
		);
	}

	public function testRejectsANonObjectFactoryResult(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster release updater target is incompatible.' );

		GitHubReleaseUpdaterBootstrap::register(
			'/wordpress/wp-content/plugins/ran-booster/ran-booster.php',
			'1.0.0',
			static fn ( mixed ...$options ): string => 'invalid-facade'
		);
	}

	public function testRejectsAFacadeWithoutARegisterMethod(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'RAN Booster release updater target is incompatible.' );

		GitHubReleaseUpdaterBootstrap::register(
			'/wordpress/wp-content/plugins/ran-booster/ran-booster.php',
			'1.0.0',
			static fn ( mixed ...$options ): object => new \stdClass()
		);
	}

	public function testReportsProspectiveApiForTheSelectedRuntime(): void {
		$facade              = new GitHubReleaseUpdaterFacadeSpy();
		$facade->diagnostics = array(
			'selection_fixed'  => true,
			'selected_version' => '1.5.0-beta.1',
			'state'            => 'active',
		);

		self::assertSame( 4, GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $facade ) );
	}

	public function testReportsProspectiveApiWhenTheSelectedRuntimeTargetIsTemporarilyUnavailable(): void {
		$facade              = new GitHubReleaseUpdaterFacadeSpy();
		$facade->diagnostics = array(
			'selection_fixed'  => true,
			'selected_version' => '1.5.0-beta.1',
			'state'            => 'unavailable',
			'code'             => 'github_updater_github_http_error',
		);

		self::assertSame( 4, GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $facade ) );
	}

	public function testDoesNotAdvertiseProspectiveApiBeforeSelectionIsFixed(): void {
		$facade              = new GitHubReleaseUpdaterFacadeSpy();
		$facade->diagnostics = array(
			'selection_fixed'  => false,
			'selected_version' => '1.5.0-beta.1',
			'state'            => 'active',
		);

		self::assertNull( GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $facade ) );
	}

	public function testDoesNotAdvertiseProspectiveApiWhenNoRuntimeWasSelected(): void {
		$facade              = new GitHubReleaseUpdaterFacadeSpy();
		$facade->diagnostics = array(
			'selection_fixed'  => true,
			'selected_version' => null,
			'state'            => 'inactive',
			'code'             => 'runtime_load_failed',
		);

		self::assertNull( GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $facade ) );
	}
}

final class GitHubReleaseUpdaterFacadeSpy {

	public int $registrations = 0;
	/** @var array<string, mixed> */
	public array $diagnostics = array(
		'registered'       => true,
		'selection_fixed'  => true,
		'selected_version' => 'test-runtime',
		'state'            => 'idle',
	);

	public function register(): void {
		++$this->registrations;
	}

	public function diagnostics(): array {
		return $this->diagnostics;
	}

	public function refresh(): bool {
		return true;
	}
}

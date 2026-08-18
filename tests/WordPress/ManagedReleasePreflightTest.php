<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/../Support/ProspectiveReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Secrets\SecretsFile;
use RAN\WordPress\ManagedReleasePreflight;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveDiscoveryFixture;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;

final class ManagedReleasePreflightTest extends TestCase {

	protected function setUp(): void {
		ReleaseCandidatePreflight::reset();
	}

	public function testProspectiveDiscoveryRetainsItsBoundedLegacyShape(): void {
		ReleaseCandidatePreflight::$discovery = new ProspectiveDiscoveryFixture( 101, 'v1.2.3', '1.2.3' );

		$result = $this->preflight()->discoverProspective(
			'plugin',
			$this->repository(),
			'stable'
		);

		self::assertSame(
			array(
				'release_id' => 101,
				'tag'        => 'v1.2.3',
				'version'    => '1.2.3',
				'channel'    => 'stable',
			),
			$result
		);
		self::assertSame( 1, ReleaseCandidatePreflight::$discoverCalls );
		self::assertSame( 'owner/example', ReleaseCandidatePreflight::$target['repository'] );
		self::assertNull( ReleaseCandidatePreflight::$target['accessToken'] );
	}

	public function testProspectiveDiscoveryRejectsNonGitHubTargetsBeforeRemoteWork(): void {
		$repository             = $this->repository();
		$repository['provider'] = 'bb';

		$result = $this->preflight()->discoverProspective( 'theme', $repository, 'stable' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_release_preflight_unavailable', $result->get_error_code() );
		self::assertSame( 0, ReleaseCandidatePreflight::$discoverCalls );
	}

	private function preflight(): ManagedReleasePreflight {
		return new ManagedReleasePreflight(
			new SecretsFile( sys_get_temp_dir() . '/ran-booster-managed-preflight-test.php', array() )
		);
	}

	/** @return array<string, string> */
	private function repository(): array {
		return array(
			'provider'               => 'gh',
			'repository'             => 'owner/example',
			'provider_repository_id' => '101',
			'credential_id'          => '',
			'private'                => '0',
		);
	}
}

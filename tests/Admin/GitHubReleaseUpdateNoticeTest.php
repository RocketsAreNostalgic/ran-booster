<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\GitHubReleaseUpdateNotice;

final class GitHubReleaseUpdateNoticeTest extends TestCase {

	public function testSuppressesTheExactCorePluginNotice(): void {
		self::assertNull(
			GitHubReleaseUpdateNotice::filter(
				array(
					'message'     => 'Raw diagnostic.',
					'remediation' => 'Raw remediation.',
				),
				array(
					'type'       => 'plugin',
					'package'    => 'ran-booster/ran-booster.php',
					'repository' => 'RocketsAreNostalgic/ran-booster',
					'code'       => 'github_updater_github_http_error',
				)
			)
		);
	}

	public function testLeavesEveryNonExactCoreTupleUntouched(): void {
		$notice = array(
			'message'     => 'Managed package failed.',
			'remediation' => 'Review the package.',
		);

		self::assertSame(
			$notice,
			GitHubReleaseUpdateNotice::filter(
				$notice,
				array(
					'type'       => 'plugin',
					'package'    => 'ran-booster/other-package.php',
					'repository' => 'RocketsAreNostalgic/ran-booster',
				)
			)
		);
		self::assertSame(
			$notice,
			GitHubReleaseUpdateNotice::filter(
				$notice,
				array(
					'type'       => 'theme',
					'package'    => 'ran-booster/ran-booster.php',
					'repository' => 'RocketsAreNostalgic/ran-booster',
				)
			)
		);
		self::assertSame(
			$notice,
			GitHubReleaseUpdateNotice::filter(
				$notice,
				array(
					'type'       => 'plugin',
					'package'    => 'ran-booster/ran-booster.php',
					'repository' => 'RocketsAreNostalgic/example-plugin',
				)
			)
		);
		self::assertSame(
			$notice,
			GitHubReleaseUpdateNotice::filter(
				$notice,
				array(
					'type'       => 'theme',
					'package'    => 'ran-booster/ran-booster.php',
					'repository' => 'RocketsAreNostalgic/ran-booster',
				)
			)
		);
		self::assertSame( $notice, GitHubReleaseUpdateNotice::filter( $notice, array() ) );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support;

use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

/** Provider-contract fixtures for the bounded GitHub workflow suite. */
final class WorkflowProviderFixtures {
	public static function target( string $type = 'plugin', string $identifier = 'example-plugin/example-plugin.php', int $sourceRevision = 3 ): RepositoryReleaseWorkflowTarget {
		$root = 'theme' === $type ? 'example-theme' : 'example-plugin';

		return new RepositoryReleaseWorkflowTarget(
			$type,
			$identifier,
			$sourceRevision,
			'101',
			$root,
			'1.2.3',
			'https://github.com/owner/example-plugin'
		);
	}

	public static function preflight( string $code = RepositoryReleaseWorkflowPreflight::RELEASE_UNAVAILABLE, string $reasonCode = '' ): RepositoryReleaseWorkflowPreflight {
		return new RepositoryReleaseWorkflowPreflight( $code, $reasonCode );
	}
}

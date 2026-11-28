<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/**
 * Retired by #1253. Nothing constructs it; it remains only as the type that
 * DeploymentAttemptRepository still names, and is deleted with that reference.
 */
final class GitHubProviderSelection {
	public function uses_connection( object $connection ): bool {
		unset( $connection );
		return false;
	}

	public function operation_allowed(): bool {
		return true;
	}
}

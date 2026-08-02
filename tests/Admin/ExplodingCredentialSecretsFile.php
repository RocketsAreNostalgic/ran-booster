<?php

declare(strict_types=1);

namespace Tests\Admin;

use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;
use RuntimeException;

final class ExplodingCredentialSecretsFile extends SecretsFile {

	public function __construct(
		?string $path,
		?array $constants,
		private string $canary
	) {
		parent::__construct( $path, $constants );
	}

	public function saveCredential(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		?string $secret
	): string {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The canary verifies Dispatcher redacts unexpected storage failures.
		throw new RuntimeException( 'Storage failed after receiving ' . $this->canary . '.' );
	}
}

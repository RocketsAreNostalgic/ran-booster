<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

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

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of save_credential retains the production method contract; these inputs do not affect this controlled result.
	public function save_credential(
		ProviderCode|string $provider,
		?string $id,
		array $metadata,
		?string $secret
	): string {
		// The canary verifies Dispatcher redacts unexpected storage failures.
		throw new RuntimeException( 'Storage failed after receiving ' . $this->canary . '.' );
	}
}

<?php

declare(strict_types=1);

namespace Tests\Secrets;

use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;

final class SecretsFileTestFactory {

	/**
	 * @param array<string, mixed> $constants
	 */
	public static function create(
		?string $path,
		array $constants = array(),
		?ProviderSecretPolicyCatalog $policies = null
	): SecretsFile {
		return new SecretsFile(
			$path,
			$constants,
			$policies,
			self::keyStore( $path ),
			new EncryptedSecretsEnvelopeCodec()
		);
	}

	public static function keyStore( ?string $path ): SiteKeyStore {
		return new InMemorySiteKeyStore( $path ?? 'unconfigured' );
	}
}

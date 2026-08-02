<?php

declare(strict_types=1);

namespace Tests\Uninstall;

use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Uninstall\LocalDataRemover;
use RuntimeException;

final class TestableLocalDataRemover extends LocalDataRemover {

	public function __construct(
		SecretsFile $secrets,
		TemporaryDebugCapture $debugCapture,
		WpConfigSecretsPathWriter $configWriter,
		object $database,
		private readonly ?string $configPath
	) {
		parent::__construct( $secrets, $debugCapture, $configWriter, database: $database );
	}

	protected function loadedWpConfigPath(): string {
		if ( null === $this->configPath ) {
			throw new RuntimeException( 'No loaded configuration fixture.' );
		}

		return $this->configPath;
	}
}

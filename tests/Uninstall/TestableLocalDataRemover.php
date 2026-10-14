<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Uninstall\LocalDataRemover;
use RuntimeException;

final class TestableLocalDataRemover extends LocalDataRemover {

	public function __construct(
		SecretsFile $secrets,
		TemporaryDebugCapture $debug_capture,
		WpConfigSecretsPathWriter $config_writer,
		object $database,
		private readonly ?string $config_path,
		private readonly ?string $automatic_path = null
	) {
		parent::__construct( $secrets, $debug_capture, $config_writer, database: $database );
	}

	protected function automatic_sidecar_path(): ?string {
		return $this->automatic_path ?? parent::automatic_sidecar_path();
	}

	protected function loaded_wp_config_path(): string {
		if ( null === $this->config_path ) {
			throw new RuntimeException( 'No loaded configuration fixture.' );
		}

		return $this->config_path;
	}
}

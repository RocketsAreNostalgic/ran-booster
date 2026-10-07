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
		private readonly ?string $config_path
	) {
		parent::__construct( $secrets, $debug_capture, $config_writer, database: $database );
	}

	protected function loaded_wp_config_path(): string {
		if ( null === $this->config_path ) {
			throw new RuntimeException( 'No loaded configuration fixture.' );
		}

		return $this->config_path;
	}
}

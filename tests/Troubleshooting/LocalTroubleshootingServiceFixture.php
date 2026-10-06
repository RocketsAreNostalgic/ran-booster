<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Troubleshooting\LocalTroubleshootingService;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Fixture simulates native exclusive-file races and failures.

final class LocalTroubleshootingServiceFixture extends LocalTroubleshootingService {

	public bool $multisite                  = false;
	public bool $file_modifications_allowed = true;
	public string $php_version              = '8.2.0';
	public string $wordpress_version        = '7.0.1';
	public string $filesystem_method        = 'direct';
	public string $temporary_directory;
	public string $plugin_directory;
	public string $theme_directory;
	public bool $fail_permission                 = false;
	public ?string $fail_promotion               = null;
	public ?string $fail_cleanup                 = null;
	public ?string $race_on_promotion            = null;
	public ?string $race_destination             = null;
	public ?string $substitute_before_permission = null;
	public ?string $replace_directory_on_open    = null;
	public int $filesystem_reads                 = 0;
	public int $marker_opens                     = 0;
	public int $deployment_snapshot_reads        = 0;
	public int $worker_inspection_reads          = 0;
	public bool $use_deployment_dependency       = false;
	public bool $use_worker_dependency           = false;
	/** @var array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	public ?array $retention_configuration = array(
		'valid'        => true,
		'maximum_rows' => 200,
		'source'       => 'default',
	);
	/** @var array{queued: int, running: int, needs_attention: int, earliest_queued_at: string|null, latest_terminal_at: string|null}|null */
	public ?array $deployment_snapshot = array(
		'queued'             => 0,
		'running'            => 0,
		'needs_attention'    => 0,
		'earliest_queued_at' => null,
		'latest_terminal_at' => null,
	);
	/** @var array{status: 'scheduled'|'missing'|'unavailable', scheduled_at: int|null}|null */
	public ?array $worker_inspection = array(
		'status'       => 'missing',
		'scheduled_at' => null,
	);
	/** @var list<string> */
	public array $opened_paths = array();
	/** @var list<int> */
	public array $permissions_before_promotion = array();
	private int $suffix_counter                = 1;

	public function __construct(
		SecretsFile $secrets,
		string $temporary_directory,
		string $plugin_directory,
		string $theme_directory,
		?DeploymentAttemptRepository $deployment_attempts = null,
		?WordPressWorkerWakeup $worker_wakeup = null,
		?Database $database = null
	) {
		parent::__construct( $secrets, $deployment_attempts, $worker_wakeup, $database );
		$this->temporary_directory = $temporary_directory;
		$this->plugin_directory    = $plugin_directory;
		$this->theme_directory     = $theme_directory;
	}

	public function next_suffix(): string {
		return str_pad( (string) $this->suffix_counter, 32, '0', STR_PAD_LEFT );
	}

	protected function is_multisite(): bool {
		return $this->multisite;
	}

	protected function php_version(): string {
		return $this->php_version;
	}

	protected function wordpress_version(): string {
		return $this->wordpress_version;
	}

	protected function filesystem_modification_allowed(): bool {
		++$this->filesystem_reads;

		return $this->file_modifications_allowed;
	}

	protected function filesystem_method(): string {
		++$this->filesystem_reads;

		return $this->filesystem_method;
	}

	protected function temporary_directory(): string {
		++$this->filesystem_reads;

		return $this->temporary_directory;
	}

	protected function plugin_directory(): string {
		++$this->filesystem_reads;

		return $this->plugin_directory;
	}

	protected function theme_directory(): string {
		++$this->filesystem_reads;

		return $this->theme_directory;
	}

	protected function random_suffix(): string {
		$suffix = $this->next_suffix();
		++$this->suffix_counter;

		return $suffix;
	}

	protected function deployment_snapshot(): ?array {
		++$this->deployment_snapshot_reads;
		if ( $this->use_deployment_dependency ) {
			return parent::deployment_snapshot();
		}

		return $this->deployment_snapshot;
	}

	protected function retention_configuration(): ?array {
		return $this->retention_configuration;
	}

	protected function worker_inspection(): ?array {
		++$this->worker_inspection_reads;
		if ( $this->use_worker_dependency ) {
			return parent::worker_inspection();
		}

		return $this->worker_inspection;
	}

	protected function open_exclusive( string $path ): mixed {
		++$this->marker_opens;
		$this->opened_paths[] = $path;

		if ( null !== $this->replace_directory_on_open
			&& str_starts_with( $path, $this->replace_directory_on_open . DIRECTORY_SEPARATOR )
		) {
			$original = $this->replace_directory_on_open . '-original';
			rename( $this->replace_directory_on_open, $original );
			mkdir( $this->replace_directory_on_open, 0700 );
			$this->replace_directory_on_open = null;
		}

		$handle = parent::open_exclusive( $path );
		if ( is_resource( $handle ) && null !== $this->substitute_before_permission ) {
			unlink( $path );
			symlink( $this->substitute_before_permission, $path );
		}

		return $handle;
	}

	protected function creation_mask(): int {
		return $this->fail_permission ? 0 : parent::creation_mask();
	}

	protected function promote_marker( string $source, string $destination ): bool {
		if ( null !== $this->fail_promotion && str_starts_with( $source, $this->fail_promotion ) ) {
			return false;
		}

		if ( null !== $this->race_on_promotion && str_starts_with( $source, $this->race_on_promotion ) ) {
			unlink( $source );
			file_put_contents( $source, 'attacker replacement canary' );
		}
		if ( null !== $this->race_destination && str_starts_with( $destination, $this->race_destination ) ) {
			file_put_contents( $destination, 'destination race canary' );
		}

		clearstatcache( true, $source );
		$this->permissions_before_promotion[] = fileperms( $source ) & 0777;

		return parent::promote_marker( $source, $destination );
	}

	protected function remove_marker( string $path ): bool {
		if ( null !== $this->fail_cleanup && str_starts_with( $path, $this->fail_cleanup ) ) {
			return false;
		}

		return parent::remove_marker( $path );
	}
}

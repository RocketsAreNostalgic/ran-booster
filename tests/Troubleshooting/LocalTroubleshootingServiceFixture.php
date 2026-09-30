<?php

declare(strict_types=1);

namespace Tests\Troubleshooting;

use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Troubleshooting\LocalTroubleshootingService;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Fixture simulates native exclusive-file races and failures.

final class LocalTroubleshootingServiceFixture extends LocalTroubleshootingService {

	public bool $multisite                = false;
	public bool $fileModificationsAllowed = true;
	public string $phpVersion             = '8.2.0';
	public string $wordpressVersion       = '7.0.1';
	public string $filesystemMethod       = 'direct';
	public string $temporaryDirectory;
	public string $pluginDirectory;
	public string $themeDirectory;
	public bool $failPermission                = false;
	public ?string $failPromotion              = null;
	public ?string $failCleanup                = null;
	public ?string $raceOnPromotion            = null;
	public ?string $raceDestination            = null;
	public ?string $substituteBeforePermission = null;
	public ?string $replaceDirectoryOnOpen     = null;
	public int $filesystemReads                = 0;
	public int $markerOpens                    = 0;
	public int $deploymentSnapshotReads        = 0;
	public int $workerInspectionReads          = 0;
	public bool $useDeploymentDependency       = false;
	public bool $useWorkerDependency           = false;
	/** @var array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	public ?array $retentionConfiguration = array(
		'valid'        => true,
		'maximum_rows' => 200,
		'source'       => 'default',
	);
	/** @var array{queued: int, running: int, needs_attention: int, earliest_queued_at: string|null, latest_terminal_at: string|null}|null */
	public ?array $deploymentSnapshot = array(
		'queued'             => 0,
		'running'            => 0,
		'needs_attention'    => 0,
		'earliest_queued_at' => null,
		'latest_terminal_at' => null,
	);
	/** @var array{status: 'scheduled'|'missing'|'unavailable', scheduled_at: int|null}|null */
	public ?array $workerInspection = array(
		'status'       => 'missing',
		'scheduled_at' => null,
	);
	/** @var list<string> */
	public array $openedPaths = array();
	/** @var list<int> */
	public array $permissionsBeforePromotion = array();
	private int $suffixCounter               = 1;

	public function __construct(
		SecretsFile $secrets,
		string $temporaryDirectory,
		string $pluginDirectory,
		string $themeDirectory,
		?DeploymentAttemptRepository $deploymentAttempts = null,
		?WordPressWorkerWakeup $workerWakeup = null,
		?Database $database = null
	) {
		parent::__construct( $secrets, $deploymentAttempts, $workerWakeup, $database );
		$this->temporaryDirectory = $temporaryDirectory;
		$this->pluginDirectory    = $pluginDirectory;
		$this->themeDirectory     = $themeDirectory;
	}

	public function nextSuffix(): string {
		return str_pad( (string) $this->suffixCounter, 32, '0', STR_PAD_LEFT );
	}

	protected function is_multisite(): bool {
		return $this->multisite;
	}

	protected function php_version(): string {
		return $this->phpVersion;
	}

	protected function wordpress_version(): string {
		return $this->wordpressVersion;
	}

	protected function filesystem_modification_allowed(): bool {
		++$this->filesystemReads;

		return $this->fileModificationsAllowed;
	}

	protected function filesystem_method(): ?string {
		++$this->filesystemReads;

		return $this->filesystemMethod;
	}

	protected function temporary_directory(): string {
		++$this->filesystemReads;

		return $this->temporaryDirectory;
	}

	protected function plugin_directory(): string {
		++$this->filesystemReads;

		return $this->pluginDirectory;
	}

	protected function theme_directory(): string {
		++$this->filesystemReads;

		return $this->themeDirectory;
	}

	protected function random_suffix(): string {
		$suffix = $this->nextSuffix();
		++$this->suffixCounter;

		return $suffix;
	}

	protected function deployment_snapshot(): ?array {
		++$this->deploymentSnapshotReads;
		if ( $this->useDeploymentDependency ) {
			return parent::deployment_snapshot();
		}

		return $this->deploymentSnapshot;
	}

	protected function retention_configuration(): ?array {
		return $this->retentionConfiguration;
	}

	protected function worker_inspection(): ?array {
		++$this->workerInspectionReads;
		if ( $this->useWorkerDependency ) {
			return parent::worker_inspection();
		}

		return $this->workerInspection;
	}

	protected function open_exclusive( string $path ): mixed {
		++$this->markerOpens;
		$this->openedPaths[] = $path;

		if ( null !== $this->replaceDirectoryOnOpen
			&& str_starts_with( $path, $this->replaceDirectoryOnOpen . DIRECTORY_SEPARATOR )
		) {
			$original = $this->replaceDirectoryOnOpen . '-original';
			rename( $this->replaceDirectoryOnOpen, $original );
			mkdir( $this->replaceDirectoryOnOpen, 0700 );
			$this->replaceDirectoryOnOpen = null;
		}

		$handle = parent::open_exclusive( $path );
		if ( is_resource( $handle ) && null !== $this->substituteBeforePermission ) {
			unlink( $path );
			symlink( $this->substituteBeforePermission, $path );
		}

		return $handle;
	}

	protected function creation_mask(): int {
		return $this->failPermission ? 0 : parent::creation_mask();
	}

	protected function promote_marker( string $source, string $destination ): bool {
		if ( null !== $this->failPromotion && str_starts_with( $source, $this->failPromotion ) ) {
			return false;
		}

		if ( null !== $this->raceOnPromotion && str_starts_with( $source, $this->raceOnPromotion ) ) {
			unlink( $source );
			file_put_contents( $source, 'attacker replacement canary' );
		}
		if ( null !== $this->raceDestination && str_starts_with( $destination, $this->raceDestination ) ) {
			file_put_contents( $destination, 'destination race canary' );
		}

		clearstatcache( true, $source );
		$this->permissionsBeforePromotion[] = fileperms( $source ) & 0777;

		return parent::promote_marker( $source, $destination );
	}

	protected function remove_marker( string $path ): bool {
		if ( null !== $this->failCleanup && str_starts_with( $path, $this->failCleanup ) ) {
			return false;
		}

		return parent::remove_marker( $path );
	}
}

<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\PackageMutationGuard;
use RAN\PackageSource;
use RAN\Storage\Database;
use RuntimeException;

/**
 * Narrow persistence seam for configuration reads and source CAS transitions.
 */
class ManagedReleaseStore {

	/** @var \Closure(): string */
	private \Closure $clock;

	/** @param callable(): string|null $clock */
	public function __construct(
		private ?object $database = null,
		private ?Database $lifecycle = null,
		?callable $clock = null
	) {
		if ( null === $this->database ) {
			global $wpdb;
			$this->database = $wpdb;
		}
		$this->lifecycle = $this->lifecycle ?? new Database( $this->database );
		$this->clock     = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: \Closure::fromCallable( $clock );
	}

	public function configuration( string $type, string $identifier ): ?ManagedReleaseConfiguration {
		$row   = $this->row( $type, $identifier );
		$value = $row->release_configuration ?? null;
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'The managed release configuration is unavailable.' );
		}

		return ManagedReleaseConfiguration::fromJson( $value );
	}

	public function transition(
		string $type,
		string $identifier,
		PackageSource $expectedSource,
		int $expectedRevision,
		PackageSource $newSource,
		?ManagedReleaseConfiguration $configuration,
		int $userId
	): bool {
		PackageMutationGuard::assertPackageMutationAllowed();

		$this->assertIdentity( $type, $identifier );
		if ( $expectedRevision < 1 || PHP_INT_MAX === $expectedRevision || $userId < 0 || $expectedSource === $newSource ) {
			return false;
		}
		if ( ( PackageSource::RELEASE_ASSET === $newSource ) !== ( null !== $configuration ) ) {
			return false;
		}

		$before = $this->row( $type, $identifier );
		if ( $expectedSource->value !== ( $before->source ?? null )
			|| $expectedRevision !== (int) ( $before->source_revision ?? 0 ) ) {
			return false;
		}
		$policy     = is_string( $before->deployment_policy ?? null ) ? $before->deployment_policy : '';
		$nextPolicy = DeploymentPolicy::AUTOMATIC->value === $policy
			? DeploymentPolicy::MANUAL->value
			: $policy;
		$data       = array(
			'source'                => $newSource->value,
			'source_revision'       => $expectedRevision + 1,
			'source_previous'       => $expectedSource->value,
			'source_changed_at'     => ( $this->clock )(),
			'source_changed_by'     => $userId > 0 ? $userId : null,
			'deployment_policy'     => $nextPolicy,
			'release_configuration' => $configuration?->toJson(),
		);
		$where      = array(
			'type'              => self::typeId( $type ),
			'package'           => $identifier,
			'source'            => $expectedSource->value,
			'source_revision'   => $expectedRevision,
			'deployment_policy' => $policy,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact source-transition CAS boundary.
		if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
			return false;
		}

		$after = $this->row( $type, $identifier );

		return $newSource->value === ( $after->source ?? null )
			&& $expectedRevision + 1 === (int) ( $after->source_revision ?? 0 )
			&& $expectedSource->value === ( $after->source_previous ?? null )
			&& $data['release_configuration'] === ( $after->release_configuration ?? null )
			&& $nextPolicy === ( $after->deployment_policy ?? null );
	}

	/**
	 * Change only the release channel behind the exact release-source revision.
	 *
	 * Package identity remains unchanged. Automatic deployment is reset to
	 * Manual.
	 *
	 * @param 'stable'|'prerelease' $channel
	 */
	public function changeChannel(
		string $type,
		string $identifier,
		int $expectedRevision,
		string $channel,
		int $userId
	): bool {
		PackageMutationGuard::assertPackageMutationAllowed();

		$this->assertIdentity( $type, $identifier );
		if ( $expectedRevision < 1
			|| PHP_INT_MAX === $expectedRevision
			|| $userId < 0
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return false;
		}

		$before = $this->row( $type, $identifier );
		if ( PackageSource::RELEASE_ASSET->value !== ( $before->source ?? null )
			|| $expectedRevision !== (int) ( $before->source_revision ?? 0 )
			|| ! is_string( $before->release_configuration ?? null ) ) {
			return false;
		}
		$current = ManagedReleaseConfiguration::fromJson( $before->release_configuration );
		if ( $channel === $current->channel() ) {
			return false;
		}
		$next = new ManagedReleaseConfiguration(
			$current->packageRoot(),
			$current->metadataFile(),
			$channel
		);

		$policy     = is_string( $before->deployment_policy ?? null ) ? $before->deployment_policy : '';
		$nextPolicy = DeploymentPolicy::AUTOMATIC->value === $policy
			? DeploymentPolicy::MANUAL->value
			: $policy;
		$data       = array(
			'source_revision'       => $expectedRevision + 1,
			'source_changed_at'     => ( $this->clock )(),
			'source_changed_by'     => $userId > 0 ? $userId : null,
			'deployment_policy'     => $nextPolicy,
			'release_configuration' => $next->toJson(),
		);
		$where      = array(
			'type'                  => self::typeId( $type ),
			'package'               => $identifier,
			'source'                => PackageSource::RELEASE_ASSET->value,
			'source_revision'       => $expectedRevision,
			'deployment_policy'     => $policy,
			'release_configuration' => $current->toJson(),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact same-source configuration CAS boundary.
		if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
			return false;
		}

		$after = $this->row( $type, $identifier );

		return PackageSource::RELEASE_ASSET->value === ( $after->source ?? null )
			&& $expectedRevision + 1 === (int) ( $after->source_revision ?? 0 )
			&& $next->toJson() === ( $after->release_configuration ?? null )
			&& $nextPolicy === ( $after->deployment_policy ?? null );
	}

	private function row( string $type, string $identifier ): object {
		$this->assertIdentity( $type, $identifier );
		$this->lifecycle?->requireReady();
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE type = %d AND package = %s LIMIT 2',
			ran_booster_table_name(),
			self::typeId( $type ),
			$identifier
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared immediately above.
		$rows  = $this->database->get_results( $query );
		$error = property_exists( $this->database, 'last_error' ) ? trim( (string) $this->database->last_error ) : '';
		if ( '' !== $error || ! is_array( $rows ) || 1 !== count( $rows ) || ! is_object( $rows[0] ) ) {
			throw new RuntimeException( 'The managed release package row is unavailable.' );
		}

		return $rows[0];
	}

	private function assertIdentity( string $type, string $identifier ): void {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $identifier
			|| strlen( $identifier ) > 255
			|| str_contains( $identifier, "\0" ) ) {
			throw new RuntimeException( 'The managed release package identity is invalid.' );
		}
	}

	private static function typeId( string $type ): int {
		return 'plugin' === $type ? 1 : 2;
	}
}

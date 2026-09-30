<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\PackageMutationGuard;
use RAN\PackageSubdirectory;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\Storage\RepositorySourceGuard;
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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		PackageSource $expectedSource,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		int $expectedRevision,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		PackageSource $newSource,
		?ManagedReleaseConfiguration $configuration,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		int $userId
	): bool {
		PackageMutationGuard::assert_package_mutation_allowed();

		$this->assert_identity( $type, $identifier );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $expectedRevision < 1 || PHP_INT_MAX === $expectedRevision || $userId < 0 || $expectedSource === $newSource ) {
			return false;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( ( PackageSource::RELEASE_ASSET === $newSource ) !== ( null !== $configuration ) ) {
			return false;
		}

		if ( false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $this->database->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			$before = $this->row( $type, $identifier, true );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			if ( PackageSource::RELEASE_ASSET === $newSource ) {
				$this->assert_release_subdirectory( $before );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			if ( $expectedSource->value !== ( $before->source ?? null )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				|| $expectedRevision !== (int) ( $before->source_revision ?? 0 )
				|| ! is_string( $before->provider ?? null )
				|| ! is_string( $before->provider_repository_id ?? null ) ) {
				$wpdb = $this->database;
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$assessment = ( new RepositorySourceGuard( $this->database, $this->lifecycle ) )->assess(
				$before->provider,
				$before->provider_repository_id,
				self::type_id( $type ),
				$identifier,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				$newSource,
				true
			);
			if ( ! $assessment['allowed'] ) {
				$this->database->query( 'ROLLBACK' );
				if ( 'repository_source_unavailable' === $assessment['code'] ) {
					throw new ManagedReleaseRepositorySourceUnavailable( 'The repository source relationship is unavailable.' );
				}

				return false;
			}
			$policy      = is_string( $before->deployment_policy ?? null ) ? $before->deployment_policy : '';
			$next_policy = DeploymentPolicy::AUTOMATIC->value === $policy
			? DeploymentPolicy::MANUAL->value
			: $policy;
			$data        = array(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source'                => $newSource->value,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_revision'       => $expectedRevision + 1,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_previous'       => $expectedSource->value,
				'source_changed_at'     => ( $this->clock )(),
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_changed_by'     => $userId > 0 ? $userId : null,
				'deployment_policy'     => $next_policy,
				'release_configuration' => $configuration?->toJson(),
			);
			$where       = array(
				'type'              => self::type_id( $type ),
				'package'           => $identifier,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source'            => $expectedSource->value,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_revision'   => $expectedRevision,
				'deployment_policy' => $policy,
			);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact source-transition CAS boundary.
			if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}

			$after = $this->row( $type, $identifier, true );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			$verified = $newSource->value === ( $after->source ?? null )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			&& $expectedRevision + 1 === (int) ( $after->source_revision ?? 0 )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			&& $expectedSource->value === ( $after->source_previous ?? null )
			&& $data['release_configuration'] === ( $after->release_configuration ?? null )
				&& $next_policy === ( $after->deployment_policy ?? null );
			if ( ! $verified || false === $this->database->query( 'COMMIT' ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}

			return true;
		} catch ( \Throwable $exception ) {
			$this->database->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Change only the release channel behind the exact release-source revision.
	 *
	 * Package identity remains unchanged. Automatic deployment is reset to
	 * Manual.
	 *
	 * @param 'stable'|'prerelease' $channel
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function changeChannel(
		string $type,
		string $identifier,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		int $expectedRevision,
		string $channel,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		int $userId
	): bool {
		PackageMutationGuard::assert_package_mutation_allowed();

		$this->assert_identity( $type, $identifier );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		if ( $expectedRevision < 1
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| PHP_INT_MAX === $expectedRevision
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| $userId < 0
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return false;
		}

		if ( false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $this->database->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			$before     = $this->row( $type, $identifier, true );
			$assessment = ! is_string( $before->provider ?? null ) || ! is_string( $before->provider_repository_id ?? null )
				? array(
					'allowed' => false,
					'code'    => 'repository_source_unavailable',
				)
				: ( new RepositorySourceGuard( $this->database, $this->lifecycle ) )->assess(
					$before->provider,
					$before->provider_repository_id,
					self::type_id( $type ),
					$identifier,
					PackageSource::RELEASE_ASSET,
					true
				);
			if ( ! $assessment['allowed'] ) {
				$this->database->query( 'ROLLBACK' );
				if ( 'repository_source_unavailable' === $assessment['code'] ) {
					throw new ManagedReleaseRepositorySourceUnavailable( 'The repository source relationship is unavailable.' );
				}

				return false;
			}
			$this->assert_release_subdirectory( $before );
			if ( PackageSource::RELEASE_ASSET->value !== ( $before->source ?? null )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			|| $expectedRevision !== (int) ( $before->source_revision ?? 0 )
			|| ! is_string( $before->release_configuration ?? null ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}
			$current = ManagedReleaseConfiguration::fromJson( $before->release_configuration );
			if ( $channel === $current->channel() ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}
			$next = new ManagedReleaseConfiguration(
				$current->packageRoot(),
				$current->metadataFile(),
				$channel
			);

			$policy      = is_string( $before->deployment_policy ?? null ) ? $before->deployment_policy : '';
			$next_policy = DeploymentPolicy::AUTOMATIC->value === $policy
			? DeploymentPolicy::MANUAL->value
			: $policy;
			$data        = array(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_revision'       => $expectedRevision + 1,
				'source_changed_at'     => ( $this->clock )(),
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_changed_by'     => $userId > 0 ? $userId : null,
				'deployment_policy'     => $next_policy,
				'release_configuration' => $next->toJson(),
			);
			$where       = array(
				'type'                  => self::type_id( $type ),
				'package'               => $identifier,
				'source'                => PackageSource::RELEASE_ASSET->value,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
				'source_revision'       => $expectedRevision,
				'deployment_policy'     => $policy,
				'release_configuration' => $current->toJson(),
			);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact same-source configuration CAS boundary.
			if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}

			$after = $this->row( $type, $identifier, true );

			$verified = PackageSource::RELEASE_ASSET->value === ( $after->source ?? null )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			&& $expectedRevision + 1 === (int) ( $after->source_revision ?? 0 )
			&& $next->toJson() === ( $after->release_configuration ?? null )
			&& $next_policy === ( $after->deployment_policy ?? null );
			if ( ! $verified || false === $this->database->query( 'COMMIT' ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}
			return true;
		} catch ( \Throwable $exception ) {
			$this->database->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	private function row( string $type, string $identifier, bool $lock = false ): object {
		$this->assert_identity( $type, $identifier );
		$this->lifecycle?->requireReady();
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE type = %d AND package = %s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ),
			ran_booster_table_name(),
			self::type_id( $type ),
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

	private function assert_identity( string $type, string $identifier ): void {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| '' === $identifier
			|| strlen( $identifier ) > 255
			|| str_contains( $identifier, "\0" ) ) {
			throw new RuntimeException( 'The managed release package identity is invalid.' );
		}
	}

	private function assert_release_subdirectory( object $row ): void {
		try {
			$subdirectory = PackageSubdirectory::normalize( $row->subdirectory ?? null );
		} catch ( \InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The prior exception remains internal to the typed storage failure.
			throw new RuntimeException( 'The managed release package subdirectory is invalid.', 0, $exception );
		}
		if ( null !== $subdirectory ) {
			throw new ManagedReleaseSubdirectoryNotSupported( 'The managed release package subdirectory is not supported.' );
		}
	}

	private static function type_id( string $type ): int {
		return 'plugin' === $type ? 1 : 2;
	}
}

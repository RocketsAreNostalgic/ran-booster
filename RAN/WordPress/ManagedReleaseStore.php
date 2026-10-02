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

		return ManagedReleaseConfiguration::from_json( $value );
	}

	public function transition(
		string $type,
		string $identifier,
		PackageSource $expected_source,
		int $expected_revision,
		PackageSource $new_source,
		?ManagedReleaseConfiguration $configuration,
		int $user_id
	): bool {
		PackageMutationGuard::assert_package_mutation_allowed();

		$this->assert_identity( $type, $identifier );
		if ( $expected_revision < 1 || PHP_INT_MAX === $expected_revision || $user_id < 0 || $expected_source === $new_source ) {
			return false;
		}
		if ( ( PackageSource::RELEASE_ASSET === $new_source ) !== ( null !== $configuration ) ) {
			return false;
		}

		if ( false === $this->database->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' )
			|| false === $this->database->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			$before = $this->row( $type, $identifier, true );
			if ( PackageSource::RELEASE_ASSET === $new_source ) {
				$this->assert_release_subdirectory( $before );
			}
			if ( ( $before->source ?? null ) !== $expected_source->value
				|| (int) ( $before->source_revision ?? 0 ) !== $expected_revision
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
				$new_source,
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
				'source'                => $new_source->value,
				'source_revision'       => $expected_revision + 1,
				'source_previous'       => $expected_source->value,
				'source_changed_at'     => ( $this->clock )(),
				'source_changed_by'     => $user_id > 0 ? $user_id : null,
				'deployment_policy'     => $next_policy,
				'release_configuration' => $configuration?->to_json(),
			);
			$where       = array(
				'type'              => self::type_id( $type ),
				'package'           => $identifier,
				'source'            => $expected_source->value,
				'source_revision'   => $expected_revision,
				'deployment_policy' => $policy,
			);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact source-transition CAS boundary.
			if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}

			$after = $this->row( $type, $identifier, true );

			$verified = ( $after->source ?? null ) === $new_source->value
			&& (int) ( $after->source_revision ?? 0 ) === $expected_revision + 1
			&& ( $after->source_previous ?? null ) === $expected_source->value
			&& ( $after->release_configuration ?? null ) === $data['release_configuration']
				&& ( $after->deployment_policy ?? null ) === $next_policy;
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
	public function change_channel(
		string $type,
		string $identifier,
		int $expected_revision,
		string $channel,
		int $user_id
	): bool {
		PackageMutationGuard::assert_package_mutation_allowed();

		$this->assert_identity( $type, $identifier );
		if ( $expected_revision < 1
			|| PHP_INT_MAX === $expected_revision
			|| $user_id < 0
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
			|| (int) ( $before->source_revision ?? 0 ) !== $expected_revision
			|| ! is_string( $before->release_configuration ?? null ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}
			$current = ManagedReleaseConfiguration::from_json( $before->release_configuration );
			if ( $channel === $current->channel() ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}
			$next = new ManagedReleaseConfiguration(
				$current->package_root(),
				$current->metadata_file(),
				$channel
			);

			$policy      = is_string( $before->deployment_policy ?? null ) ? $before->deployment_policy : '';
			$next_policy = DeploymentPolicy::AUTOMATIC->value === $policy
			? DeploymentPolicy::MANUAL->value
			: $policy;
			$data        = array(
				'source_revision'       => $expected_revision + 1,
				'source_changed_at'     => ( $this->clock )(),
				'source_changed_by'     => $user_id > 0 ? $user_id : null,
				'deployment_policy'     => $next_policy,
				'release_configuration' => $next->to_json(),
			);
			$where       = array(
				'type'                  => self::type_id( $type ),
				'package'               => $identifier,
				'source'                => PackageSource::RELEASE_ASSET->value,
				'source_revision'       => $expected_revision,
				'deployment_policy'     => $policy,
				'release_configuration' => $current->to_json(),
			);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This is the exact same-source configuration CAS boundary.
			if ( 1 !== $this->database->update( ran_booster_table_name(), $data, $where ) ) {
				$this->database->query( 'ROLLBACK' );
				return false;
			}

			$after = $this->row( $type, $identifier, true );

			$verified = PackageSource::RELEASE_ASSET->value === ( $after->source ?? null )
			&& (int) ( $after->source_revision ?? 0 ) === $expected_revision + 1
			&& $next->to_json() === ( $after->release_configuration ?? null )
			&& ( $after->deployment_policy ?? null ) === $next_policy;
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
		$this->lifecycle?->require_ready();
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

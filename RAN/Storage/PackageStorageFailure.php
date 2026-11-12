<?php

declare(strict_types=1);

namespace RAN\Storage;

use RuntimeException;

final class PackageStorageFailure extends RuntimeException {

	private function __construct(
		private readonly PackageStorageOperation $operation,
		private readonly string $diagnostic_id,
		string $message,
		private readonly bool $recovery_required = false
	) {
		parent::__construct( $message );
	}

	private const QUERY_FAILED               = 'ran_booster_storage_query_failed';
	private const WRITE_FAILED               = 'ran_booster_storage_write_failed';
	private const TRANSACTION_UNAVAILABLE    = 'ran_booster_storage_transaction_unavailable';
	private const DATABASE_UNSUPPORTED       = 'ran_booster_storage_database_unsupported';
	private const DUPLICATE_PACKAGE          = 'ran_booster_storage_duplicate_package';
	private const INVALID_PROVIDER_IDENTITY  = 'ran_booster_storage_invalid_provider_identity';
	private const REPOSITORY_SOURCE_CONFLICT = 'ran_booster_repository_source_conflict';
	private const VERIFICATION_FAILED        = 'ran_booster_storage_verification_failed';

	public static function query_failed(): self {
		return new self( PackageStorageOperation::QUERY, self::QUERY_FAILED, self::message( self::QUERY_FAILED ) );
	}

	public static function write_failed(): self {
		return new self( PackageStorageOperation::UPDATE, self::WRITE_FAILED, self::message( self::WRITE_FAILED ) );
	}

	public static function transaction_unavailable(): self {
		return new self( PackageStorageOperation::UPDATE, self::TRANSACTION_UNAVAILABLE, self::message( self::TRANSACTION_UNAVAILABLE ) );
	}

	public static function unsupported_database( PackageStorageOperation $operation = PackageStorageOperation::QUERY ): self {
		return new self( $operation, self::DATABASE_UNSUPPORTED, self::message( self::DATABASE_UNSUPPORTED ) );
	}

	public static function duplicate_package_rows(): self {
		return new self( PackageStorageOperation::QUERY, self::DUPLICATE_PACKAGE, self::message( self::DUPLICATE_PACKAGE ) );
	}

	public static function invalid_provider_identity(): self {
		return new self( PackageStorageOperation::QUERY, self::INVALID_PROVIDER_IDENTITY, self::message( self::INVALID_PROVIDER_IDENTITY ) );
	}

	public static function repository_source_conflict( ?string $release_owner = null ): self {
		return new self( PackageStorageOperation::QUERY, self::REPOSITORY_SOURCE_CONFLICT, self::message( self::REPOSITORY_SOURCE_CONFLICT, $release_owner ) );
	}

	public static function from_mutation_result( PackageMutationResult $result ): self {
		return new self(
			$result->get_operation(),
			$result->get_diagnostic_id(),
			$result->get_message(),
			$result->is_recovery_required()
		);
	}

	public static function after_write_could_not_be_verified( PackageStorageOperation $operation ): self {
		return new self( $operation, self::VERIFICATION_FAILED, self::message( self::VERIFICATION_FAILED ), true );
	}

	/**
	 * Resolve the message when the failure is built.
	 *
	 * Package storage is read while the plugin file loads, before the textdomain is available,
	 * and WordPress logs a doing-it-wrong notice for any translation requested that early. Until
	 * `init` has run, the stable diagnostic ID stands in as the message; it suits the log, and
	 * every failure that reaches an administrator is built later, during a request they make.
	 */
	private static function message( string $diagnostic_id, ?string $release_owner = null ): string {
		if ( function_exists( 'did_action' ) && 0 === did_action( 'init' ) ) {
			return $diagnostic_id;
		}

		return match ( $diagnostic_id ) {
			self::QUERY_FAILED => __( 'Booster could not read its package management data. No package changes were made.', 'ran-booster' ),
			self::WRITE_FAILED => __( 'Booster could not save its package management data. No package changes were made.', 'ran-booster' ),
			self::TRANSACTION_UNAVAILABLE => __( 'Booster could not safely begin its package-management transaction. No package changes were made.', 'ran-booster' ),
			self::DATABASE_UNSUPPORTED => __( 'Booster package storage is paused because this site does not meet the database requirements. No package changes were made.', 'ran-booster' ),
			self::DUPLICATE_PACKAGE => __( 'Booster found conflicting package management records. No package changes were made.', 'ran-booster' ),
			self::INVALID_PROVIDER_IDENTITY => __( 'Booster could not read a managed package because its Git provider identity is invalid.', 'ran-booster' ),
			self::REPOSITORY_SOURCE_CONFLICT => null === $release_owner
				? __( 'This repository is shared by managed packages. Releases require a repository used by only one managed package. Review the repository’s package settings before changing source.', 'ran-booster' )
				: sprintf(
					/* translators: %s: identifier of the existing Release package. */
					__( 'This repository already supplies releases to %s. Additional packages cannot use it. To use this repository for multiple Branch packages, switch that package to Branch first.', 'ran-booster' ),
					$release_owner
				),
			self::VERIFICATION_FAILED => __( 'Booster could not verify package management data after a database change. The saved state may have changed; review it before retrying.', 'ran-booster' ),
			default => $diagnostic_id,
		};
	}

	public function get_diagnostic_id(): string {
		return $this->diagnostic_id;
	}

	public function get_operation(): PackageStorageOperation {
		return $this->operation;
	}

	public function is_recovery_required(): bool {
		return $this->recovery_required;
	}

	public function is_database_unsupported(): bool {
		return 'ran_booster_storage_database_unsupported' === $this->diagnostic_id;
	}
}

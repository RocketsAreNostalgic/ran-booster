<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;
use RAN\AddOn\Logging\LoggingFacade;

/**
 * The small, capability-checked context handed to a rendered add-on tab.
 */
final readonly class AdminAddOnContext {

	private function __construct(
		private string $tabKey,
		private string $boosterUrl,
		private string $scope,
		private int $coreApiVersion,
		private int $addOnApiVersion,
		/** @var array<string, object> */
		private array $facades,
		private LoggingFacade $logger
	) {
	}

	public static function forCurrentAdministrator(
		string $tabKey,
		string $boosterUrl,
		string $scope,
		int $coreApiVersion,
		int $addOnApiVersion,
		LoggingFacade $logger,
		array $facades = array()
	): self {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new LogicException( 'Add-on tabs require the Booster administrator capability.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress bootstrap is available for this small API value object.
		$urlParts = parse_url( $boosterUrl );
		if ( ! is_array( $urlParts )
			|| ! isset( $urlParts['scheme'], $urlParts['host'] )
			|| ! in_array( strtolower( $urlParts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $urlParts['user'], $urlParts['pass'], $urlParts['fragment'] ) ) {
			throw new LogicException( 'Add-on tabs require a canonical Booster URL.' );
		}

		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) {
			throw new LogicException( 'Add-on tabs require a known administration scope.' );
		}

		if ( $coreApiVersion < 1 || $addOnApiVersion < 1 ) {
			throw new LogicException( 'Add-on API versions must be positive integers.' );
		}

		return new self(
			$tabKey,
			$boosterUrl,
			$scope,
			$coreApiVersion,
			$addOnApiVersion,
			$facades,
			$logger
		);
	}

	public function tabKey(): string {
		return $this->tabKey;
	}

	public function boosterUrl(): string {
		return $this->boosterUrl;
	}

	public function scope(): string {
		return $this->scope;
	}

	public function coreApiVersion(): int {
		return $this->coreApiVersion;
	}

	public function addOnApiVersion(): int {
		return $this->addOnApiVersion;
	}

	public function logger(): LoggingFacade {
		return $this->logger;
	}

	public function facade( string $name ): ?object {
		return $this->facades[ $name ] ?? null;
	}

	/**
	 * Render the canonical managed-repository table.
	 *
	 * @param list<array<string, mixed>> $rows Display-safe repository rows.
	 */
	public function renderRepositoryTable( string $labelledBy, array $rows ): void {
		( new Component\RepositoryTableRenderer() )->render( $labelledBy, $rows );
	}
}

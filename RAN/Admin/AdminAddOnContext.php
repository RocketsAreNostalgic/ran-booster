<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;

/**
 * The small, capability-checked context handed to a rendered add-on tab.
 */
final readonly class AdminAddOnContext {

	private function __construct(
		private string $tab_key,
		private string $booster_url,
		private string $scope,
		private int $core_api_version,
		private int $add_on_api_version,
		/** @var array<string, object> */
		private array $facades
	) {
	}

	/** @param array<string, object> $facades */
	public static function for_current_administrator(
		string $tab_key,
		string $booster_url,
		string $scope,
		int $core_api_version,
		int $add_on_api_version,
		array $facades = array()
	): self {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new LogicException( 'Add-on tabs require the Booster administrator capability.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress bootstrap is available for this small API value object.
		$url_parts = parse_url( $booster_url );
		if ( ! is_array( $url_parts )
			|| ! isset( $url_parts['scheme'], $url_parts['host'] )
			|| ! in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $url_parts['user'], $url_parts['pass'], $url_parts['fragment'] ) ) {
			throw new LogicException( 'Add-on tabs require a canonical Booster URL.' );
		}

		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) {
			throw new LogicException( 'Add-on tabs require a known administration scope.' );
		}

		if ( $core_api_version < 1 || $add_on_api_version < 1 ) {
			throw new LogicException( 'Add-on API versions must be positive integers.' );
		}

		return new self(
			$tab_key,
			$booster_url,
			$scope,
			$core_api_version,
			$add_on_api_version,
			$facades
		);
	}

	public function tab_key(): string {
		return $this->tab_key;
	}

	public function booster_url(): string {
		return $this->booster_url;
	}

	public function scope(): string {
		return $this->scope;
	}

	public function core_api_version(): int {
		return $this->core_api_version;
	}

	public function add_on_api_version(): int {
		return $this->add_on_api_version;
	}

	public function facade( string $name ): ?object {
		return $this->facades[ $name ] ?? null;
	}

	/**
	 * Render the canonical managed-repository table.
	 *
	 * @param list<array<string, mixed>> $rows Display-safe repository rows.
	 */
	public function render_repository_table( string $labelled_by, array $rows ): void {
		( new Component\RepositoryTableRenderer() )->render( $labelled_by, $rows );
	}
}

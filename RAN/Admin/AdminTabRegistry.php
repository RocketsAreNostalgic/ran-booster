<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderRegistry;

/**
 * Builds the complete allowlist of provider and fixed Booster admin tabs.
 */
final class AdminTabRegistry {

	/** @var array<string, AdminTab> */
	private array $tabs = array();

	private string $default_key;

	public function __construct( ProviderRegistry $providers, ?\RAN\RepositoryProvider\GitHubProviderSelection $github_selection = null ) {
		$this->add( AdminTab::page( 'overview', 'Overview', 'onboarding.php' ) );

		$metadata_rows = $providers->administration_metadata();
		if ( null !== $github_selection && ! in_array( 'gh', array_map( static fn ( \RAN\RepositoryProvider\ProviderMetadata $metadata ): string => $metadata->code->value, $metadata_rows ), true ) ) {
			$this->add( AdminTab::provider( new \RAN\RepositoryProvider\ProviderMetadata( \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) ) );
		}

		foreach ( $metadata_rows as $metadata ) {
			$this->add( AdminTab::provider( $metadata ) );
		}

		$this->add( AdminTab::page( 'portability', 'Transporter', 'portability.php' ) );
		$this->add( AdminTab::page( 'documentation', 'Documentation', 'documentation.php' ) );
		$this->add( AdminTab::page( 'troubleshooting', 'Troubleshooting', 'troubleshooting.php' ) );

		$this->default_key = 'overview';
	}

	/** @return list<AdminTab> */
	public function all(): array {
		return array_values( $this->tabs );
	}

	public function resolve( mixed $requested_key ): AdminTab {
		if ( ! is_string( $requested_key ) ) {
			return $this->tabs[ $this->default_key ];
		}

		$requested_key = strtolower( trim( $requested_key ) );

		return $this->tabs[ $requested_key ] ?? $this->tabs[ $this->default_key ];
	}

	private function add( AdminTab $tab ): void {
		if ( isset( $this->tabs[ $tab->get_key() ] ) ) {
			throw new InvalidArgumentException( 'Admin tab keys must be unique.' );
		}

		$this->tabs[ $tab->get_key() ] = $tab;
	}
}

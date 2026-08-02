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

	private string $defaultKey;

	public function __construct( ProviderRegistry $providers ) {
		$this->add( AdminTab::page( 'overview', 'Overview', 'onboarding.php' ) );

		foreach ( $providers->administrationMetadata() as $metadata ) {
			$this->add( AdminTab::provider( $metadata ) );
		}

		$this->add( AdminTab::page( 'portability', 'Transporter', 'portability.php' ) );
		$this->add( AdminTab::page( 'documentation', 'Documentation', 'documentation.php' ) );
		$this->add( AdminTab::page( 'troubleshooting', 'Troubleshooting', 'troubleshooting.php' ) );

		$this->defaultKey = 'overview';
	}

	/** @return list<AdminTab> */
	public function all(): array {
		return array_values( $this->tabs );
	}

	public function resolve( mixed $requestedKey ): AdminTab {
		if ( ! is_string( $requestedKey ) ) {
			return $this->tabs[ $this->defaultKey ];
		}

		$requestedKey = strtolower( trim( $requestedKey ) );

		return $this->tabs[ $requestedKey ] ?? $this->tabs[ $this->defaultKey ];
	}

	public function getDefault(): AdminTab {
		return $this->tabs[ $this->defaultKey ];
	}

	private function add( AdminTab $tab ): void {
		if ( isset( $this->tabs[ $tab->getKey() ] ) ) {
			throw new InvalidArgumentException( 'Admin tab keys must be unique.' );
		}

		$this->tabs[ $tab->getKey() ] = $tab;
	}
}

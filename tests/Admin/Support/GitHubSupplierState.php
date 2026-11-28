<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\Support;

use RAN\RepositoryProvider\ProviderRegistry;

/** Puts a registry into a GitHub supplier state without plugin attribution or a bundled factory. */
final class GitHubSupplierState {
	/**
	 * @param list<string> $claimants Plugin directory names that claimed `gh`.
	 */
	public static function apply( ProviderRegistry $registry, string $supplier, array $claimants = array() ): ProviderRegistry {
		( new \ReflectionProperty( ProviderRegistry::class, 'github_supplier' ) )->setValue( $registry, $supplier );
		( new \ReflectionProperty( ProviderRegistry::class, 'github_claims' ) )->setValue(
			$registry,
			array_map(
				static fn ( string $plugin ): array => array(
					'plugin'  => $plugin,
					'factory' => null,
				),
				$claimants
			)
		);
		return $registry;
	}
}

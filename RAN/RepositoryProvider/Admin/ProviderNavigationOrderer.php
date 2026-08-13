<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use RAN\RepositoryProvider\ProviderMetadata;

final class ProviderNavigationOrderer {

	/** @param iterable<ProviderMetadata> $metadata @return list<ProviderMetadata> */
	public function orderMetadata( iterable $metadata ): array {
		$ordered = array();
		foreach ( $metadata as $item ) {
			$ordered[] = $item;
		}
		usort(
			$ordered,
			function ( ProviderMetadata $left, ProviderMetadata $right ): int {
				$groups    = array(
					ProviderNavigationPlacement::GIT_HOST => 0,
					ProviderNavigationPlacement::OTHER_PROVIDER => 1,
				);
				$placement = $this->placement( $left );
				$other     = $this->placement( $right );
				$result    = $groups[ $placement->group ] <=> $groups[ $other->group ];
				if ( 0 === $result ) {
					$result = $placement->slot <=> $other->slot;
				}
				if ( 0 === $result ) {
					$result = strcmp( $left->code->value, $right->code->value );
				}

				return $result;
			}
		);
		return $ordered;
	}

	private function placement( ProviderMetadata $metadata ): ProviderNavigationPlacement {
		if ( null !== $metadata->admin?->navigation ) {
			return $metadata->admin->navigation;
		}

		return new ProviderNavigationPlacement(
			ProviderNavigationPlacement::OTHER_PROVIDER,
			10000
		);
	}
}

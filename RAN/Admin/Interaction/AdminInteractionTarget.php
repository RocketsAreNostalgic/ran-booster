<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

/**
 * Core-owned administration regions that add-ons may refresh.
 */
enum AdminInteractionTarget: string {

	case PROVIDER_REPOSITORIES = 'provider_repositories';

	case TRANSPORTER_MIGRATION_SOURCE = 'transporter_migration_source';

	public function key( ?string $instance = null ): string {
		return match ( $this ) {
			self::PROVIDER_REPOSITORIES        => $this->value,
			self::TRANSPORTER_MIGRATION_SOURCE => $this->value . '_' . $this->migrationInstance( $instance ),
		};
	}

	public function selector( ?string $instance = null ): string {
		return match ( $this ) {
			self::PROVIDER_REPOSITORIES        => '#ran-booster-provider-task-panel',
			self::TRANSPORTER_MIGRATION_SOURCE => '#ran-booster-transporter-migration-source-' . $this->migrationInstance( $instance ),
		};
	}

	public function elementId( ?string $instance = null ): string {
		return substr( $this->selector( $instance ), 1 );
	}

	private function migrationInstance( ?string $instance ): string {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', (string) $instance ) ) {
			throw new \InvalidArgumentException( 'Transporter migration targets require a Core-derived row instance.' );
		}

		return $instance;
	}
}

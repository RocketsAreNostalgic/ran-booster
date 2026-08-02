<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Package;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

/**
 * Keep an unavailable package provider from being replaced through a forged edit request.
 */
final readonly class PackageEditProviderGuard {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ProviderRegistry $providers
	) {
	}

	/** @param array<string, mixed> $request */
	public function assertStoredProviderAvailable( string $action, array $request ): void {
		$package = match ( $action ) {
			'edit-plugin' => $this->plugin( $request ),
			'edit-theme'  => $this->theme( $request ),
			default       => throw new InvalidArgumentException( 'The package edit action is unsupported.' ),
		};

		$this->providers->get( $package->getProviderCode() ?? '' );
	}

	/** @param array<string, mixed> $request */
	private function plugin( array $request ): Package {
		$file = $request['file'] ?? null;

		if ( ! is_string( $file ) || '' === trim( $file ) ) {
			throw new InvalidArgumentException( 'The managed plugin identifier is required.' );
		}

		return $this->plugins->boosterPluginFromFile( $file );
	}

	/** @param array<string, mixed> $request */
	private function theme( array $request ): Package {
		$stylesheet = $request['stylesheet'] ?? null;

		if ( ! is_string( $stylesheet ) || '' === trim( $stylesheet ) ) {
			throw new InvalidArgumentException( 'The managed theme identifier is required.' );
		}

		return $this->themes->boosterThemeFromStylesheet( $stylesheet );
	}
}

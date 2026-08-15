<?php

declare(strict_types=1);

$ranBoosterRoot = dirname( __DIR__, 3 );

spl_autoload_register(
	static function ( string $class ) use ( $ranBoosterRoot ): void {
		$prefixes = array(
			'RAN\\Booster\\GitHub\\'          => $ranBoosterRoot . '/RAN/Booster/GitHub/',
			'RAN\\RepositoryProvider\\'       => $ranBoosterRoot . '/RAN/RepositoryProvider/',
			'RAN\\AddOn\\WebhookAssistance\\' => $ranBoosterRoot . '/RAN/AddOn/WebhookAssistance/',
			'RAN\\Admin\\Interaction\\'       => $ranBoosterRoot . '/RAN/Admin/Interaction/',
			'Tests\\Booster\\GitHub\\'        => __DIR__ . '/',
		);

		foreach ( $prefixes as $prefix => $directory ) {
			if ( ! str_starts_with( $class, $prefix ) ) {
				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $directory . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}

			return;
		}

		if ( 'RAN\\Provider\\ProviderCapability' === $class ) {
			require $ranBoosterRoot . '/RAN/Provider/ProviderCapability.php';
			return;
		}
		if ( 'RAN\\PackageSubdirectory' === $class ) {
			// RepositoryDescriptor owns package-slug validation through this
			// transitive Core value helper; the GitHub module does not import it.
			require $ranBoosterRoot . '/RAN/PackageSubdirectory.php';
			return;
		}

		if ( str_starts_with( $class, 'RAN\\' ) || str_starts_with( $class, 'Tests\\' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception text is not rendered.
			throw new LogicException( 'The bounded GitHub module suite attempted to load an unrelated Core or test class: ' . $class );
		}
	},
	true,
	true
);

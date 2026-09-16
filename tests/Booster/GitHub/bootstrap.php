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
			'RAN\\UpdaterSupport\\V1\\'       => $ranBoosterRoot . '/vendor/ran/updater-support/src/',
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
			// RepositoryDescriptor remains a host contract and reaches the Core
			// wrapper transitively; GitHub implementation code no longer imports it.
			require $ranBoosterRoot . '/RAN/PackageSubdirectory.php';
			return;
		}
		if ( 'RAN\\PackageArtifactLimit' === $class ) {
			require $ranBoosterRoot . '/RAN/PackageArtifactLimit.php';
			return;
		}
		if ( 'RAN\\Deployment\\ReleaseArtifactCustodian' === $class ) {
			require $ranBoosterRoot . '/RAN/Deployment/ReleaseArtifactCustodian.php';
			return;
		}
		if ( 'RAN\\Deployment\\ReleaseArtifactCleanupFailure' === $class ) {
			require $ranBoosterRoot . '/RAN/Deployment/ReleaseArtifactCleanupFailure.php';
			return;
		}
		if ( 'RAN\\Deployment\\PreparedArtifact' === $class ) {
			// Exact reviewed one-shot custody handoff from the provider module to Core.
			require $ranBoosterRoot . '/RAN/Deployment/PreparedArtifact.php';
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

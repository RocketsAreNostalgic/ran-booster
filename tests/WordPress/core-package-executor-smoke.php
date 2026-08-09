<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.
// phpcs:disable

$root = getenv( 'RAN_BOOSTER_EXECUTOR_ROOT' );
if ( ! is_string( $root ) || '' === $root ) {
	throw new RuntimeException( 'The executor source root is unavailable.' );
}

$executorSource = array(
	RAN\PackageSubdirectory::class                    => '/RAN/PackageSubdirectory.php',
	RAN\Deployment\PreparedArtifact::class             => '/RAN/Deployment/PreparedArtifact.php',
	RAN\Runtime\RuntimeSupport::class                   => '/RAN/Runtime/RuntimeSupport.php',
	RAN\WordPress\CorePackageExecutionFailure::class   => '/RAN/WordPress/CorePackageExecutionFailure.php',
	RAN\WordPress\CorePackageExecutionResult::class    => '/RAN/WordPress/CorePackageExecutionResult.php',
	RAN\WordPress\CorePackageExecutor::class           => '/RAN/WordPress/CorePackageExecutor.php',
);
foreach ( $executorSource as $class => $relativePath ) {
	$sourcePath = $root . $relativePath;
	if ( class_exists( $class, false ) ) {
		$loadedPath = ( new ReflectionClass( $class ) )->getFileName();
		$sourceHash = hash_file( 'sha256', $sourcePath );
		$loadedHash = is_string( $loadedPath ) ? hash_file( 'sha256', $loadedPath ) : false;
		if ( ! is_string( $sourceHash ) || ! is_string( $loadedHash ) || ! hash_equals( $sourceHash, $loadedHash ) ) {
			throw new RuntimeException( 'The loaded executor source does not match the checkout under test.' );
		}
		continue;
	}
	require_once $sourcePath;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

if ( wp_doing_cron() || PHP_VERSION_ID < 80200 || version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) {
	throw new RuntimeException( 'The core executor smoke test requires WordPress 7, PHP 8.2 and non-cron request semantics.' );
}

final class RanBoosterCorePackageExecutorSmoke {
	private array $artifacts = array();
	private array $plugins = array();
	private array $themes = array();
	private string $originalStylesheet;

	public function __construct( private readonly string $runId ) {
		$this->originalStylesheet = (string) get_option( 'stylesheet' );
	}

	public function run(): void {
		$this->assertMaintenanceAbsent();
		$this->exercisePlugins();
		$this->exerciseThemes();
		$this->exerciseInstallHookIsolation();
		$this->exerciseBoundedFailures();
		$this->assertMaintenanceAbsent();
	}

	public function cleanup(): void {
		if ( $this->originalStylesheet !== (string) get_option( 'stylesheet' ) ) {
			switch_theme( $this->originalStylesheet );
		}
		foreach ( array_reverse( $this->plugins ) as $identifier ) {
			if ( is_plugin_active( $identifier ) ) {
				deactivate_plugins( $identifier, true );
			}
			if ( is_dir( WP_PLUGIN_DIR . '/' . dirname( $identifier ) ) ) {
				delete_plugins( array( $identifier ) );
			}
		}
		foreach ( array_reverse( $this->themes ) as $stylesheet ) {
			if ( is_dir( get_theme_root( $stylesheet ) . '/' . $stylesheet ) ) {
				delete_theme( $stylesheet );
			}
		}
		foreach ( $this->artifacts as $artifact ) {
			$artifact->cleanup();
		}
	}

	private function exercisePlugins(): void {
		$slug       = $this->slug( 'plugin' );
		$identifier = $slug . '/' . $slug . '.php';
		$executor   = new RAN\WordPress\CorePackageExecutor();

		$install = $this->artifact( 'plugin', $slug, '1.0.0', 'plugin-install', 'packages/' . $slug );
		$this->assertSuccess( $this->withHookRestorationCheck( static fn () => $executor->installPlugin( $install, $slug, 'packages/' . $slug ) ) );
		$this->plugins[] = $identifier;
		$this->assertPlugin( $identifier, '1.0.0', 'plugin-install', false );

		$inactive = $this->artifact( 'plugin', $slug, '2.0.0', 'plugin-inactive' );
		$this->assertScopedUpdate(
			fn () => $this->withMaintenanceObservation(
				'plugin',
				$identifier,
				false,
				static fn () => $executor->updatePlugin( $inactive, $slug, null, $identifier )
			),
			WP_PLUGIN_DIR
		);
		$this->assertPlugin( $identifier, '2.0.0', 'plugin-inactive', false );

		$result = activate_plugin( $identifier, '', false, true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'The disposable plugin could not be activated.' );
		}
		$sameVersion = $this->artifact( 'plugin', $slug, '2.0.0', 'plugin-same-version', null, null, true );
		$this->assertSuccess(
			$this->withMaintenanceObservation(
				'plugin',
				$identifier,
				true,
				fn () => $this->withScrapeResponse( false, static fn () => $executor->updatePlugin( $sameVersion, $slug, null, $identifier ) )
			)
		);
		$this->assertPlugin( $identifier, '2.0.0', 'plugin-same-version', true );

		$downgrade = $this->artifact( 'plugin', $slug, '1.5.0', 'plugin-downgrade' );
		$this->assertSuccess( $this->withScrapeResponse( false, static fn () => $executor->updatePlugin( $downgrade, $slug, null, $identifier ) ) );
		$this->assertPlugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		$sourceVeto = static function ( mixed $source, mixed $remote, mixed $upgrader, array $extra ) use ( $identifier ): mixed {
			unset( $remote, $upgrader );
			return $identifier === ( $extra['plugin'] ?? null )
				? new WP_Error( 'disposable_source_veto' )
				: $source;
		};
		add_filter( 'upgrader_source_selection', $sourceVeto, 5, 4 );
		try {
			$failed = $executor->updatePlugin(
				$this->artifact( 'plugin', $slug, '1.6.0', 'plugin-source-veto', null, null, true ),
				$slug,
				null,
				$identifier
			);
		} finally {
			remove_filter( 'upgrader_source_selection', $sourceVeto, 5 );
		}
		$this->assertFailure(
			$failed,
			RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_UNCERTAIN,
			RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_RESTORED
		);
		$this->assertPlugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		$blocked = static fn (): bool => false;
		add_filter( 'auto_update_plugin', $blocked, 100, 2 );
		try {
			$refused = $executor->updatePlugin(
				$this->artifact( 'plugin', $slug, '1.6.0', 'plugin-policy-refused' ),
				$slug,
				null,
				$identifier
			);
		} finally {
			remove_filter( 'auto_update_plugin', $blocked, 100 );
		}
		$this->assertFailure( $refused, RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_REFUSED );
		$this->assertPlugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		$fatal = $this->artifact( 'plugin', $slug, '4.0.0', 'plugin-fatal' );
		$restored = $this->withScrapeResponse( true, static fn () => $executor->updatePlugin( $fatal, $slug, null, $identifier ) );
		$this->assertFailure( $restored, RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_RESTORED );
		$this->assertPlugin( $identifier, '1.5.0', 'plugin-downgrade', true );
	}

	private function exerciseThemes(): void {
		$slug          = $this->slug( 'theme' );
		$parentSlug    = $this->slug( 'parent-theme' );
		$childSlug     = $this->slug( 'child-theme' );
		$missingParent = $this->slug( 'missing-parent' );
		$executor      = new RAN\WordPress\CorePackageExecutor();

		$missingRequests = 0;
		$blockRequest    = static function ( mixed $response ) use ( &$missingRequests ): WP_Error {
			++$missingRequests;
			return new WP_Error( 'unexpected_request' );
		};
		$before = $this->hookFingerprint();
		add_filter( 'pre_http_request', $blockRequest, 100, 3 );
		try {
			$missing = $executor->installTheme(
				$this->artifact( 'theme', $childSlug, '1.0.0', 'missing-child', null, $missingParent ),
				$childSlug,
				null
			);
		} finally {
			remove_filter( 'pre_http_request', $blockRequest, 100 );
		}
		$this->assertFailure( $missing, RAN\WordPress\CorePackageExecutionFailure::INVALID_REQUEST );
			if ( 0 !== $missingRequests || file_exists( get_theme_root() . '/' . $childSlug ) || $this->hasAddedHooks( $before, $this->hookFingerprint() ) ) {
			throw new RuntimeException( 'A child theme with a missing parent reached mutation or a secondary request.' );
		}

		$parent = $this->artifact( 'theme', $parentSlug, '1.0.0', 'parent-theme' );
		$this->assertSuccess( $this->withHookRestorationCheck( static fn () => $executor->installTheme( $parent, $parentSlug, null ) ) );
		$this->themes[] = $parentSlug;
		$child = $this->artifact( 'theme', $childSlug, '1.0.0', 'child-theme', null, $parentSlug );
		$this->assertSuccess( $this->withHookRestorationCheck( static fn () => $executor->installTheme( $child, $childSlug, null ) ) );
		$this->themes[] = $childSlug;
		if ( $parentSlug !== (string) wp_get_theme( $childSlug )->get( 'Template' ) ) {
			throw new RuntimeException( 'The installed child theme did not retain its installed parent.' );
		}

		$themeInstall = $this->artifact( 'theme', $slug, '1.0.0', 'theme-install' );
		$this->assertSuccess( $this->withHookRestorationCheck( static fn () => $executor->installTheme( $themeInstall, $slug, null ) ) );
		$this->themes[] = $slug;
		$this->assertTheme( $slug, '1.0.0', 'theme-install', false );

		$this->assertSuccess(
			$this->withMaintenanceObservation(
				'theme',
				$slug,
				false,
				fn () => $executor->updateTheme( $this->artifact( 'theme', $slug, '2.0.0', 'theme-inactive' ), $slug, null, $slug )
			)
		);
		$this->assertTheme( $slug, '2.0.0', 'theme-inactive', false );
		switch_theme( $slug );
		$this->assertSuccess(
			$this->withMaintenanceObservation(
				'theme',
				$slug,
				true,
				fn () => $executor->updateTheme( $this->artifact( 'theme', $slug, '3.0.0', 'theme-active', null, null, true ), $slug, null, $slug )
			)
		);
		$this->assertTheme( $slug, '3.0.0', 'theme-active', true );
	}

	private function exerciseBoundedFailures(): void {
		$slug       = $this->slug( 'failure' );
		$identifier = $slug . '/' . $slug . '.php';
		$artifact   = $this->artifact( 'plugin', $slug, '1.0.0', 'failure-fixture' );
		$secret     = 'Authorization: Bearer never-retain-this';
		$cases      = array(
			array( false, RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_REFUSED ),
			array( new WP_Error( 'provider_secret', $secret ), RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_FAILED ),
			array( new RuntimeException( $secret ), RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_UNCERTAIN ),
		);

		foreach ( $cases as $case ) {
			list( $value, $expected ) = $case;
			$sourceIsolationObserved = false;
			$executor = new RAN\WordPress\CorePackageExecutor(
				static function ( string $action, string $type, string $path, ?object $offer ) use ( $value, &$sourceIsolationObserved ): mixed {
					$unrelated = apply_filters(
						'upgrader_source_selection',
						'/unrelated-source',
						'/unrelated-remote',
						new stdClass(),
						array( 'type' => $type, 'action' => $action, $type => 'other/other.php' )
					);
					$nested = apply_filters(
						'upgrader_source_selection',
						'/nested-source',
						'/nested-remote',
						new stdClass(),
						array( 'type' => $type, 'action' => 'install' )
					);
					$consumed = apply_filters(
						'upgrader_pre_download',
						false,
						$path,
						new stdClass(),
						array( 'type' => $type, 'action' => $action, $type => $offer->{$type} )
					);
					$sourceIsolationObserved = '/unrelated-source' === $unrelated && '/nested-source' === $nested && $path === $consumed;
					if ( $value instanceof Throwable ) {
						throw $value;
					}
					return $value;
				}
			);
			$before = $this->hookFingerprint();
			$result = $executor->updatePlugin( $artifact, $slug, null, $identifier );
			$this->assertFailure( $result, $expected );
			if ( ! $sourceIsolationObserved || $this->hasAddedHooks( $before, $this->hookFingerprint() ) || str_contains( serialize( $result ), $secret ) ) {
				throw new RuntimeException( 'A bounded executor result retained sensitive failure data.' );
			}
		}
	}

	private function exerciseInstallHookIsolation(): void {
		$slug = $this->slug( 'install-hook' );
		$artifact = $this->artifact( 'plugin', $slug, '1.0.0', 'install-hook' );
		$observed = false;
		$executor = new RAN\WordPress\CorePackageExecutor(
			static function ( string $action, string $type, string $path ) use ( &$observed ): bool {
				$exact = apply_filters(
					'upgrader_pre_download',
					false,
					$path,
					new stdClass(),
					array( 'type' => $type, 'action' => $action )
				);
				$unrelated = apply_filters(
					'upgrader_pre_download',
					'unrelated-reply',
					$path,
					new stdClass(),
					array( 'type' => $type, 'action' => 'update', $type => 'other/other.php' )
				);
				$nestedSource = apply_filters(
					'upgrader_source_selection',
					'/nested-install-source',
					'/nested-install-remote',
					new stdClass(),
					array( 'type' => $type, 'action' => 'update', $type => 'other/other.php' )
				);
				$observed = $path === $exact && 'unrelated-reply' === $unrelated && '/nested-install-source' === $nestedSource;
				do_action( 'upgrader_process_complete', new stdClass(), array( 'type' => $type, 'action' => $action ) );

				return true;
			}
		);
		$this->assertSuccess( $this->withHookRestorationCheck( static fn () => $executor->installPlugin( $artifact, $slug, null ) ) );
		if ( ! $observed ) {
			throw new RuntimeException( 'Install hooks were not isolated to the exact immutable package operation.' );
		}

		$vetoSlug     = $this->slug( 'install-veto' );
		$vetoArtifact = $this->artifact( 'plugin', $vetoSlug, '1.0.0', 'install-veto' );
		$vetoError     = new WP_Error( 'prior_download_veto', 'Blocked by an earlier download policy.' );
		$veto          = static fn () => $vetoError;
		$preserved     = false;
		$observer      = static function ( mixed $reply ) use ( $vetoError, &$preserved ): mixed {
			$preserved = $vetoError === $reply;

			return $reply;
		};
		add_filter( 'upgrader_pre_download', $veto, 5, 4 );
		add_filter( 'upgrader_pre_download', $observer, 20, 4 );
		$before = $this->hookFingerprint();
		try {
			$vetoed = ( new RAN\WordPress\CorePackageExecutor() )->installPlugin( $vetoArtifact, $vetoSlug, null );
		} finally {
			remove_filter( 'upgrader_pre_download', $veto, 5 );
			remove_filter( 'upgrader_pre_download', $observer, 20 );
		}
		if ( $vetoed->isSuccessful()
			|| ! $preserved
			|| file_exists( WP_PLUGIN_DIR . '/' . $vetoSlug )
			|| $this->hasAddedHooks( $before, $this->hookFingerprint() )
		) {
			throw new RuntimeException( 'The executor bypassed a prior download veto or leaked its scoped hooks.' );
		}
	}

	private function assertScopedUpdate( callable $operation, string $expectedContext ): void {
		$before = $this->hookFingerprint();
		$observedTarget = null;
		$observedOther = null;
		$observer = static function ( bool $checkout, string $context ) use ( &$observedTarget, $expectedContext ): bool {
			if ( realpath( $context ) === realpath( $expectedContext ) ) {
				$observedTarget = $checkout;
			}
			return $checkout;
		};
		$preUpdate = static function () use ( &$observedOther ): void {
			$observedOther = apply_filters( 'automatic_updates_is_vcs_checkout', true, ABSPATH );
		};
		add_filter( 'automatic_updates_is_vcs_checkout', $observer, 20, 2 );
		add_action( 'pre_auto_update', $preUpdate, 20, 3 );
		try {
			$this->assertSuccess( $operation() );
		} finally {
			remove_filter( 'automatic_updates_is_vcs_checkout', $observer, 20 );
			remove_action( 'pre_auto_update', $preUpdate, 20 );
		}
		if ( false !== $observedTarget || true !== $observedOther || $this->hasAddedHooks( $before, $this->hookFingerprint() ) ) {
			throw new RuntimeException( 'The VCS exception or scoped hook cleanup exceeded the selected package operation.' );
		}
	}

	private function withHookRestorationCheck( callable $operation ): mixed {
		$before = $this->hookFingerprint();
		$result = $operation();
		$after = $this->hookFingerprint();
		if ( $this->hasAddedHooks( $before, $after ) ) {
			throw new RuntimeException( 'The executor did not remove its exact scoped WordPress hooks.' );
		}

		return $result;
	}

	private function withMaintenanceObservation( string $type, string $identifier, bool $expectedActive, callable $operation ): mixed {
		$active = 'plugin' === $type
			? is_plugin_active( $identifier )
			: get_stylesheet() === $identifier;
		if ( $expectedActive !== $active ) {
			throw new RuntimeException( 'The maintenance-mode proof package has an unexpected activation state.' );
		}

		$observations = array();
		$observer = static function ( mixed $response, mixed $destination, mixed $remoteDestination, array $extra ) use ( $type, $identifier, &$observations ): mixed {
			unset( $destination, $remoteDestination );
			if ( 'update' !== ( $extra['action'] ?? null )
				|| $type !== ( $extra['type'] ?? null )
				|| $identifier !== ( $extra[ $type ] ?? null )
			) {
				return $response;
			}

			$path = ABSPATH . '.maintenance';
			clearstatcache( true, $path );
			$contents = is_file( $path ) && ! is_link( $path ) ? file_get_contents( $path ) : false;
			$match = array();
			$valid = is_string( $contents )
				&& 1 === preg_match( '/\A<\?php \$upgrading = ([0-9]+); \?>\z/D', $contents, $match )
				&& (int) $match[1] <= time()
				&& (int) $match[1] > time() - ( 10 * MINUTE_IN_SECONDS );
			$observations[] = $valid;

			return $response;
		};
		add_filter( 'upgrader_clear_destination', $observer, 100, 4 );
		try {
			$result = $operation();
		} finally {
			remove_filter( 'upgrader_clear_destination', $observer, 100 );
		}

		$this->assertMaintenanceAbsent();
		if ( array( true ) !== $observations ) {
			throw new RuntimeException( 'WordPress maintenance mode was not active at the package mutation boundary.' );
		}

		return $result;
	}

	private function artifact(
		string $kind,
		string $slug,
		string $version,
		string $marker,
		?string $subdirectory = null,
		?string $parent = null,
		bool $alignedRoot = false
	): RAN\Deployment\PreparedArtifact {
		$path = wp_tempnam( 'ran-booster-executor-' . $this->runId . '.zip' );
		$zip = new ZipArchive();
		if ( ! is_string( $path ) || true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'A disposable package archive could not be created.' );
		}
		$root = $alignedRoot ? $slug : 'repository-' . $marker;
		$directory = $root . ( null === $subdirectory ? '' : '/' . $subdirectory );
		if ( 'plugin' === $kind ) {
			$zip->addFromString( $directory . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: Executor {$slug}\nVersion: {$version}\nRequires PHP: 8.2\n*/\n" );
		} else {
			$template = null === $parent ? '' : "Template: {$parent}\n";
			$zip->addFromString( $directory . '/style.css', "/*\nTheme Name: Executor {$slug}\nVersion: {$version}\n{$template}Requires PHP: 8.2\n*/\n" );
			$zip->addFromString( $directory . '/index.php', "<?php\n" );
		}
		$zip->addFromString( $directory . '/ran-booster-executor.txt', $marker . "\n" );
		$zip->close();
		chmod( $path, 0600 );
		$identity = RAN\Deployment\PreparedArtifact::regularFileIdentity( $path );
		if ( null === $identity ) {
			throw new RuntimeException( 'The disposable artifact identity is unavailable.' );
		}
		$artifact = new RAN\Deployment\PreparedArtifact( $path, str_repeat( 'a', 40 ), $version, hash_file( 'sha256', $path ), $identity['device'], $identity['inode'], $identity['size'], $identity['permissions'], $identity['links'] );
		$this->artifacts[] = $artifact;
		return $artifact;
	}

	private function withScrapeResponse( bool $fatal, callable $operation ): mixed {
		$scrape = $this->scrapeResponse( $fatal );
		add_filter( 'pre_http_request', $scrape, 10, 3 );
		try {
			return $operation();
		} finally {
			remove_filter( 'pre_http_request', $scrape, 10 );
		}
	}

	private function scrapeResponse( bool $fatal ): Closure {
		return static function ( mixed $preempt, array $arguments, string $url ) use ( $fatal ): mixed {
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) {
				return $preempt;
			}
			parse_str( $query, $parameters );
			$key = $parameters['wp_scrape_key'] ?? null;
			if ( ! is_string( $key ) ) {
				return $preempt;
			}
			return array( 'headers' => array(), 'body' => '###### wp_scraping_result_start:' . $key . ' ######' . ( $fatal ? '{"type":1}' : '{}' ) . '###### wp_scraping_result_end:' . $key . ' ######', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};
	}

	private function assertPlugin( string $identifier, string $version, string $marker, bool $active ): void {
		wp_clean_plugins_cache( false );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false );
		if ( $version !== ( $data['Version'] ?? null ) || $active !== is_plugin_active( $identifier ) || $marker . "\n" !== file_get_contents( WP_PLUGIN_DIR . '/' . dirname( $identifier ) . '/ran-booster-executor.txt' ) ) {
			throw new RuntimeException( 'The installed plugin did not match the exact prepared package.' );
		}
	}

	private function assertTheme( string $stylesheet, string $version, string $marker, bool $active ): void {
		wp_clean_themes_cache();
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() || $version !== (string) $theme->get( 'Version' ) || $active !== ( $stylesheet === (string) get_option( 'stylesheet' ) ) || $marker . "\n" !== file_get_contents( get_theme_root( $stylesheet ) . '/' . $stylesheet . '/ran-booster-executor.txt' ) ) {
			throw new RuntimeException( 'The installed theme did not match the exact prepared package.' );
		}
	}

	private function assertSuccess( RAN\WordPress\CorePackageExecutionResult $result ): void {
		if ( ! $result->isSuccessful() ) {
			throw new RuntimeException( 'WordPress core did not complete the disposable package operation: ' . $result->getFailure()->value );
		}
	}

	private function assertFailure(
		RAN\WordPress\CorePackageExecutionResult $result,
		RAN\WordPress\CorePackageExecutionFailure $failure,
		RAN\WordPress\CorePackageExecutionFailure ...$alternativeFailures
	): void {
		if ( ! in_array( $result->getFailure(), array( $failure, ...$alternativeFailures ), true ) ) {
			$actual = $result->getFailure();
			throw new RuntimeException( 'The executor did not return the expected bounded failure: ' . ( null === $actual ? 'success' : $actual->value ) );
		}
	}

	private function assertMaintenanceAbsent(): void {
		$path = ABSPATH . '.maintenance';
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'WordPress left maintenance mode enabled.' );
		}
	}

	private function hookFingerprint(): array {
		global $wp_filter;
		$fingerprint = array();
		foreach ( array( 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_clear_destination', 'automatic_updates_is_vcs_checkout', 'wp_doing_cron', 'upgrader_process_complete' ) as $hook ) {
			$fingerprint[ $hook ] = array();
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( $callbacks ) as $callbackId ) {
					$fingerprint[ $hook ][] = $priority . ':' . $callbackId;
				}
			}
		}
		return $fingerprint;
	}

	private function hasAddedHooks( array $before, array $after ): bool {
		foreach ( $after as $hook => $callbacks ) {
			if ( array() !== array_diff( $callbacks, $before[ $hook ] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	private function slug( string $role ): string {
		return 'ran-booster-executor-' . $role . '-' . $this->runId;
	}
}

$smoke = new RanBoosterCorePackageExecutorSmoke( bin2hex( random_bytes( 6 ) ) );
$failure = null;
try {
	$smoke->run();
} catch ( Throwable $caught ) {
	$failure = $caught;
}
try {
	$smoke->cleanup();
} catch ( Throwable $cleanupFailure ) {
	$failure = $cleanupFailure;
}
if ( null !== $failure ) {
	throw $failure;
}
WP_CLI::success( 'Core package executor smoke test passed.' );

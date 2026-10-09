<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

$ran_booster_root = getenv( 'RAN_BOOSTER_EXECUTOR_ROOT' );
if ( ! is_string( $ran_booster_root ) || '' === $ran_booster_root ) {
	throw new RuntimeException( 'The executor source root is unavailable.' );
}

$ran_booster_executor_source = array(
	RAN\PackageSubdirectory::class                   => '/RAN/PackageSubdirectory.php',
	RAN\Deployment\PreparedArtifact::class           => '/RAN/Deployment/PreparedArtifact.php',
	RAN\Runtime\RuntimeSupport::class                => '/RAN/Runtime/RuntimeSupport.php',
	RAN\WordPress\CorePackageExecutionFailure::class => '/RAN/WordPress/CorePackageExecutionFailure.php',
	RAN\WordPress\CorePackageExecutionResult::class  => '/RAN/WordPress/CorePackageExecutionResult.php',
	RAN\WordPress\CorePackageExecutor::class         => '/RAN/WordPress/CorePackageExecutor.php',
);
foreach ( $ran_booster_executor_source as $ran_booster_class => $ran_booster_relative_path ) {
	$ran_booster_source_path = $ran_booster_root . $ran_booster_relative_path;
	if ( class_exists( $ran_booster_class, false ) ) {
		$ran_booster_loaded_path = ( new ReflectionClass( $ran_booster_class ) )->getFileName();
		$ran_booster_source_hash = hash_file( 'sha256', $ran_booster_source_path );
		$ran_booster_loaded_hash = is_string( $ran_booster_loaded_path ) ? hash_file( 'sha256', $ran_booster_loaded_path ) : false;
		if ( ! is_string( $ran_booster_source_hash ) || ! is_string( $ran_booster_loaded_hash ) || ! hash_equals( $ran_booster_source_hash, $ran_booster_loaded_hash ) ) {
			throw new RuntimeException( 'The loaded executor source does not match the checkout under test.' );
		}
		continue;
	}
	require_once $ran_booster_source_path;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

if ( wp_doing_cron() || PHP_VERSION_ID < 80200 || version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) { // @phpstan-ignore smaller.alwaysFalse (Installed proof must refuse unsupported host PHP before mutating its disposable site; analyzer PHP range cannot prove the external host.)
	throw new RuntimeException( 'The core executor smoke test requires WordPress 7, PHP 8.2 and non-cron request semantics.' );
}

final class RAN_Booster_CorePackageExecutorSmoke {
	/** @var list<\RAN\Deployment\PreparedArtifact> */
	private array $artifacts = array();
	/** @var list<string> */
	private array $plugins = array();
	/** @var list<string> */
	private array $themes = array();
	private string $original_stylesheet;

	public function __construct( private readonly string $run_id ) {
		$this->original_stylesheet = (string) get_option( 'stylesheet' );
	}

	public function run(): void {
		$this->assert_maintenance_absent();
		$this->exercise_plugins();
		$this->exercise_themes();
		$this->exercise_install_hook_isolation();
		$this->exercise_bounded_failures();
		$this->assert_maintenance_absent();
	}

	public function cleanup(): void {
		if ( (string) get_option( 'stylesheet' ) !== $this->original_stylesheet ) {
			switch_theme( $this->original_stylesheet );
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

	private function exercise_plugins(): void {
		$slug       = $this->slug( 'plugin' );
		$identifier = $slug . '/' . $slug . '.php';
		$executor   = new RAN\WordPress\CorePackageExecutor();

		$install = $this->artifact( 'plugin', $slug, '1.0.0', 'plugin-install', 'packages/' . $slug );
		$this->assert_success( $this->with_hook_restoration_check( static fn () => $executor->install_plugin( $install, $slug, 'packages/' . $slug ) ) );
		$this->plugins[] = $identifier;
		$this->assert_plugin( $identifier, '1.0.0', 'plugin-install', false );

		$inactive = $this->artifact( 'plugin', $slug, '2.0.0', 'plugin-inactive' );
		$this->assert_scoped_single_plugin_update(
			$identifier,
			fn () => $this->assert_scoped_update(
				fn () => $this->with_maintenance_observation(
					'plugin',
					$identifier,
					false,
					static fn () => $executor->update_plugin( $inactive, $slug, null, $identifier )
				),
				WP_PLUGIN_DIR
			)
		);
		$this->assert_plugin( $identifier, '2.0.0', 'plugin-inactive', false );

		$result = activate_plugin( $identifier, '', false, true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'The disposable plugin could not be activated.' );
		}
		$same_version = $this->artifact( 'plugin', $slug, '2.0.0', 'plugin-same-version', null, null, true );
		$this->assert_success(
			$this->with_maintenance_observation(
				'plugin',
				$identifier,
				true,
				fn () => $this->with_scrape_response( false, static fn () => $executor->update_plugin( $same_version, $slug, null, $identifier ) )
			)
		);
		$this->assert_plugin( $identifier, '2.0.0', 'plugin-same-version', true );

		$downgrade = $this->artifact( 'plugin', $slug, '1.5.0', 'plugin-downgrade' );
		$this->assert_success( $this->with_scrape_response( false, static fn () => $executor->update_plugin( $downgrade, $slug, null, $identifier ) ) );
		$this->assert_plugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		// Production processes one deployment per request, while this smoke performs
		// several. Complete WordPress's deferred request-shutdown backup cleanup so
		// a later failure cannot restore a stale package from an earlier operation.
		$backup_deleted = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->delete_temp_backup(
			array(
				array(
					'dir'  => 'plugins',
					'slug' => $slug,
					'src'  => WP_PLUGIN_DIR,
				),
			)
		);
		if ( false === $backup_deleted || is_wp_error( $backup_deleted ) ) {
			throw new RuntimeException( 'WordPress could not clean the request-scoped temporary backup.' );
		}

		$source_veto = static function ( mixed $source, mixed $remote, mixed $upgrader, array $extra ) use ( $identifier ): mixed {
			unset( $remote, $upgrader );
			return ( $extra['plugin'] ?? null ) === $identifier
				? new WP_Error( 'disposable_source_veto' )
				: $source;
		};
		add_filter( 'upgrader_source_selection', $source_veto, 5, 4 );
		try {
			$failed = $executor->update_plugin(
				$this->artifact( 'plugin', $slug, '1.6.0', 'plugin-source-veto', null, null, true ),
				$slug,
				null,
				$identifier
			);
		} finally {
			remove_filter( 'upgrader_source_selection', $source_veto, 5 );
		}
		$this->assert_failure(
			$failed,
			RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_UNCERTAIN,
			RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_RESTORED
		);
		$this->assert_plugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		$blocked = static fn (): bool => false;
		add_filter( 'auto_update_plugin', $blocked, 100, 2 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		try {
			$refused = $executor->update_plugin(
				$this->artifact( 'plugin', $slug, '1.6.0', 'plugin-policy-refused' ),
				$slug,
				null,
				$identifier
			);
		} finally {
			remove_filter( 'auto_update_plugin', $blocked, 100 );
		}
		$this->assert_failure( $refused, RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_REFUSED );
		$this->assert_plugin( $identifier, '1.5.0', 'plugin-downgrade', true );

		$fatal    = $this->artifact( 'plugin', $slug, '4.0.0', 'plugin-fatal' );
		$restored = $this->with_scrape_response( true, static fn () => $executor->update_plugin( $fatal, $slug, null, $identifier ) );
		$this->assert_failure( $restored, RAN\WordPress\CorePackageExecutionFailure::WORDPRESS_RESTORED );
		$this->assert_plugin( $identifier, '1.5.0', 'plugin-downgrade', true );
	}

	private function exercise_themes(): void {
		$slug           = $this->slug( 'theme' );
		$parent_slug    = $this->slug( 'parent-theme' );
		$child_slug     = $this->slug( 'child-theme' );
		$missing_parent = $this->slug( 'missing-parent' );
		$executor       = new RAN\WordPress\CorePackageExecutor();

		$missing_requests = 0;
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
		$block_request = static function ( mixed $response ) use ( &$missing_requests ): WP_Error {
			++$missing_requests;
			return new WP_Error( 'unexpected_request' );
		};
		$before        = $this->hook_fingerprint();
		add_filter( 'pre_http_request', $block_request, 100, 3 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		try {
			$missing = $executor->install_theme(
				$this->artifact( 'theme', $child_slug, '1.0.0', 'missing-child', null, $missing_parent ),
				$child_slug,
				null
			);
		} finally {
			remove_filter( 'pre_http_request', $block_request, 100 );
		}
		$this->assert_failure( $missing, RAN\WordPress\CorePackageExecutionFailure::INVALID_REQUEST );
		if ( 0 !== $missing_requests || file_exists( get_theme_root() . '/' . $child_slug ) || $this->has_added_hooks( $before, $this->hook_fingerprint() ) ) {
			throw new RuntimeException( 'A child theme with a missing parent reached mutation or a secondary request.' );
		}

		$parent_artifact = $this->artifact( 'theme', $parent_slug, '1.0.0', 'parent-theme' );
		$this->assert_success( $this->with_hook_restoration_check( static fn () => $executor->install_theme( $parent_artifact, $parent_slug, null ) ) );
		$this->themes[] = $parent_slug;
		$child          = $this->artifact( 'theme', $child_slug, '1.0.0', 'child-theme', null, $parent_slug );
		$this->assert_success( $this->with_hook_restoration_check( static fn () => $executor->install_theme( $child, $child_slug, null ) ) );
		$this->themes[] = $child_slug;
		if ( (string) wp_get_theme( $child_slug )->get( 'Template' ) !== $parent_slug ) {
			throw new RuntimeException( 'The installed child theme did not retain its installed parent.' );
		}

		$theme_install = $this->artifact( 'theme', $slug, '1.0.0', 'theme-install' );
		$this->assert_success( $this->with_hook_restoration_check( static fn () => $executor->install_theme( $theme_install, $slug, null ) ) );
		$this->themes[] = $slug;
		$this->assert_theme( $slug, '1.0.0', 'theme-install', false );

		$this->assert_success(
			$this->with_maintenance_observation(
				'theme',
				$slug,
				false,
				fn () => $executor->update_theme( $this->artifact( 'theme', $slug, '2.0.0', 'theme-inactive' ), $slug, null, $slug )
			)
		);
		$this->assert_theme( $slug, '2.0.0', 'theme-inactive', false );
		switch_theme( $slug );
		$this->assert_success(
			$this->with_maintenance_observation(
				'theme',
				$slug,
				true,
				fn () => $executor->update_theme( $this->artifact( 'theme', $slug, '3.0.0', 'theme-active', null, null, true ), $slug, null, $slug )
			)
		);
		$this->assert_theme( $slug, '3.0.0', 'theme-active', true );
	}

	private function exercise_bounded_failures(): void {
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
			list( $value, $expected )  = $case;
			$source_isolation_observed = false;
			$executor                  = new RAN\WordPress\CorePackageExecutor(
				static function ( string $action, string $type, string $path, ?object $offer ) use ( $value, &$source_isolation_observed ): mixed {
					$unrelated = apply_filters(
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
						'upgrader_source_selection',
						'/unrelated-source',
						'/unrelated-remote',
						new stdClass(),
						array(
							'type'   => $type,
							'action' => $action,
							$type    => 'other/other.php',
						)
					);
					$nested = apply_filters(
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
						'upgrader_source_selection',
						'/nested-source',
						'/nested-remote',
						new stdClass(),
						array(
							'type'   => $type,
							'action' => 'install',
						)
					);
					$consumed = apply_filters(
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
						'upgrader_pre_download',
						false,
						$path,
						new stdClass(),
						array(
							'type'   => $type,
							'action' => $action,
							$type    => $offer->{$type},
						)
					);
					$source_isolation_observed = '/unrelated-source' === $unrelated && '/nested-source' === $nested && $path === $consumed;
					if ( $value instanceof Throwable ) {
						throw $value;
					}
					return $value;
				}
			);
			$before                    = $this->hook_fingerprint();
			$result                    = $executor->update_plugin( $artifact, $slug, null, $identifier );
			$this->assert_failure( $result, $expected );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The proof scans serialized result bytes for a leaked secret; it never unserializes them.
			if ( ! $source_isolation_observed || $this->has_added_hooks( $before, $this->hook_fingerprint() ) || str_contains( serialize( $result ), $secret ) ) {
				throw new RuntimeException( 'A bounded executor result retained sensitive failure data.' );
			}
		}
	}

	private function exercise_install_hook_isolation(): void {
		$slug     = $this->slug( 'install-hook' );
		$artifact = $this->artifact( 'plugin', $slug, '1.0.0', 'install-hook' );
		$observed = false;
		$executor = new RAN\WordPress\CorePackageExecutor(
			static function ( string $action, string $type, string $path ) use ( &$observed ): bool {
				$exact = apply_filters(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
					'upgrader_pre_download',
					false,
					$path,
					new stdClass(),
					array(
						'type'   => $type,
						'action' => $action,
					)
				);
				$unrelated = apply_filters(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
					'upgrader_pre_download',
					'unrelated-reply',
					$path,
					new stdClass(),
					array(
						'type'   => $type,
						'action' => 'update',
						$type    => 'other/other.php',
					)
				);
				$nested_source = apply_filters(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
					'upgrader_source_selection',
					'/nested-install-source',
					'/nested-install-remote',
					new stdClass(),
					array(
						'type'   => $type,
						'action' => 'update',
						$type    => 'other/other.php',
					)
				);
				$observed = $path === $exact && 'unrelated-reply' === $unrelated && '/nested-install-source' === $nested_source;
				do_action(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
					'upgrader_process_complete',
					new stdClass(),
					array(
						'type'   => $type,
						'action' => $action,
					)
				);

				return true;
			}
		);
		$this->assert_success( $this->with_hook_restoration_check( static fn () => $executor->install_plugin( $artifact, $slug, null ) ) );
		if ( ! $observed ) {
			throw new RuntimeException( 'Install hooks were not isolated to the exact immutable package operation.' );
		}

		$veto_slug     = $this->slug( 'install-veto' );
		$veto_artifact = $this->artifact( 'plugin', $veto_slug, '1.0.0', 'install-veto' );
		$veto_error    = new WP_Error( 'prior_download_veto', 'Blocked by an earlier download policy.' );
		$veto          = static fn () => $veto_error;
		$preserved     = false;
		$observer      = static function ( mixed $reply ) use ( $veto_error, &$preserved ): mixed {
			$preserved = $veto_error === $reply;

			return $reply;
		};
		add_filter( 'upgrader_pre_download', $veto, 5, 4 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		add_filter( 'upgrader_pre_download', $observer, 20, 4 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		$before = $this->hook_fingerprint();
		try {
			$vetoed = ( new RAN\WordPress\CorePackageExecutor() )->install_plugin( $veto_artifact, $veto_slug, null );
		} finally {
			remove_filter( 'upgrader_pre_download', $veto, 5 );
			remove_filter( 'upgrader_pre_download', $observer, 20 );
		}
		if ( $vetoed->is_successful()
			|| ! $preserved
			|| file_exists( WP_PLUGIN_DIR . '/' . $veto_slug )
			|| $this->has_added_hooks( $before, $this->hook_fingerprint() )
		) {
			throw new RuntimeException( 'The executor bypassed a prior download veto or leaked its scoped hooks.' );
		}
	}

	private function assert_scoped_single_plugin_update( string $identifier, callable $operation ): void {
		$core_auto_update_priority = has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		$completions               = 0;
		$cron_states               = array();
		$core_runner_suppressed    = false;
		$observer                  = static function ( object $upgrader, array $extra ) use ( $identifier, &$completions, &$cron_states ): void {
			unset( $upgrader );
			if ( 'plugin' === ( $extra['type'] ?? null )
				&& 'update' === ( $extra['action'] ?? null )
				&& ( $extra['plugin'] ?? null ) === $identifier
			) {
				++$completions;
				$cron_states[] = wp_doing_cron();
			}
		};
		$pre_update                = static function ( string $type, object $item ) use ( $identifier, &$core_runner_suppressed ): void {
			if ( 'plugin' === $type && ( $item->plugin ?? null ) === $identifier ) {
				$core_runner_suppressed = wp_doing_cron()
					&& false === has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
			}
		};
		add_action( 'upgrader_process_complete', $observer, 101, 2 );
		add_action( 'pre_auto_update', $pre_update, 101, 3 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		try {
			$operation();
		} finally {
			remove_action( 'upgrader_process_complete', $observer, 101 );
			remove_action( 'pre_auto_update', $pre_update, 101 );
		}
		if ( false === $core_auto_update_priority
			|| has_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ) !== $core_auto_update_priority
			|| ! $core_runner_suppressed
			|| 1 !== $completions
			|| array( true ) !== $cron_states
		) {
			throw new RuntimeException( 'The selected plugin update did not complete exactly once inside the scoped updater context.' );
		}
	}

	private function assert_scoped_update( callable $operation, string $expected_context ): void {
		$before          = $this->hook_fingerprint();
		$observed_target = null;
		$observed_other  = null;
		$observer        = static function ( bool $checkout, string $context ) use ( &$observed_target, $expected_context ): bool {
			if ( realpath( $context ) === realpath( $expected_context ) ) {
				$observed_target = $checkout;
			}
			return $checkout;
		};
		$pre_update      = static function () use ( &$observed_other ): void {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
			$observed_other = apply_filters( 'automatic_updates_is_vcs_checkout', true, ABSPATH );
		};
		add_filter( 'automatic_updates_is_vcs_checkout', $observer, 20, 2 );
		add_action( 'pre_auto_update', $pre_update, 20, 3 ); // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		try {
			$this->assert_success( $operation() );
		} finally {
			remove_filter( 'automatic_updates_is_vcs_checkout', $observer, 20 );
			remove_action( 'pre_auto_update', $pre_update, 20 );
		}
		if ( false !== $observed_target || true !== $observed_other || $this->has_added_hooks( $before, $this->hook_fingerprint() ) ) {
			throw new RuntimeException( 'The VCS exception or scoped hook cleanup exceeded the selected package operation.' );
		}
	}

	private function with_hook_restoration_check( callable $operation ): mixed {
		$before = $this->hook_fingerprint();
		$result = $operation();
		$after  = $this->hook_fingerprint();
		if ( $this->has_added_hooks( $before, $after ) ) {
			throw new RuntimeException( 'The executor did not remove its exact scoped WordPress hooks.' );
		}

		return $result;
	}

	private function with_maintenance_observation( string $type, string $identifier, bool $expected_active, callable $operation ): mixed {
		$active = 'plugin' === $type
			? is_plugin_active( $identifier )
			: get_stylesheet() === $identifier;
		if ( $expected_active !== $active ) {
			throw new RuntimeException( 'The maintenance-mode proof package has an unexpected activation state.' );
		}

		$observations = array();
		$observer     = static function ( mixed $response, mixed $destination, mixed $remote_destination, array $extra ) use ( $type, $identifier, &$observations ): mixed {
			unset( $destination, $remote_destination );
			if ( 'update' !== ( $extra['action'] ?? null )
				|| ( $extra['type'] ?? null ) !== $type
				|| ( $extra[ $type ] ?? null ) !== $identifier
			) {
				return $response;
			}

			$path = ABSPATH . '.maintenance';
			clearstatcache( true, $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			$contents       = is_file( $path ) && ! is_link( $path ) ? file_get_contents( $path ) : false;
			$match          = array();
			$valid          = is_string( $contents )
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

		$this->assert_maintenance_absent();
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
		?string $parent_slug = null,
		bool $aligned_root = false
	): RAN\Deployment\PreparedArtifact {
		$path = wp_tempnam( 'ran-booster-executor-' . $this->run_id . '.zip' );
		$zip  = new ZipArchive();
		if ( ! is_string( $path ) || true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'A disposable package archive could not be created.' );
		}
		$root      = $aligned_root ? $slug : 'repository-' . $marker;
		$directory = $root . ( null === $subdirectory ? '' : '/' . $subdirectory );
		if ( 'plugin' === $kind ) {
			$zip->addFromString( $directory . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: Executor {$slug}\nVersion: {$version}\nRequires PHP: 8.2\n*/\n" );
		} else {
			$template = null === $parent_slug ? '' : "Template: {$parent_slug}\n";
			$zip->addFromString( $directory . '/style.css', "/*\nTheme Name: Executor {$slug}\nVersion: {$version}\n{$template}Requires PHP: 8.2\n*/\n" );
			$zip->addFromString( $directory . '/index.php', "<?php\n" );
		}
		$zip->addFromString( $directory . '/ran-booster-executor.txt', $marker . "\n" );
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		chmod( $path, 0600 );
		$identity = RAN\Deployment\PreparedArtifact::regular_file_identity( $path );
		if ( null === $identity ) {
			throw new RuntimeException( 'The disposable artifact identity is unavailable.' );
		}
		$digest = hash_file( 'sha256', $path );
		if ( false === $digest ) {
			throw new RuntimeException( 'The disposable artifact digest is unavailable.' );
		}
		$artifact          = new RAN\Deployment\PreparedArtifact( $path, str_repeat( 'a', 40 ), $version, $digest, $identity['device'], $identity['inode'], $identity['size'], $identity['permissions'], $identity['links'] );
		$this->artifacts[] = $artifact;
		return $artifact;
	}

	private function with_scrape_response( bool $fatal, callable $operation ): mixed {
		$scrape = $this->scrape_response( $fatal );
		add_filter( 'pre_http_request', $scrape, 10, 3 );
		try {
			return $operation();
		} finally {
			remove_filter( 'pre_http_request', $scrape, 10 );
		}
	}

	private function scrape_response( bool $fatal ): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
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
			return array(
				'headers'  => array(),
				'body'     => '###### wp_scraping_result_start:' . $key . ' ######' . ( $fatal ? '{"type":1}' : '{}' ) . '###### wp_scraping_result_end:' . $key . ' ######',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	private function assert_plugin( string $identifier, string $version, string $marker, bool $active ): void {
		wp_clean_plugins_cache( false );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false );
		if ( ( $data['Version'] ?? null ) !== $version || is_plugin_active( $identifier ) !== $active || file_get_contents( WP_PLUGIN_DIR . '/' . dirname( $identifier ) . '/ran-booster-executor.txt' ) !== $marker . "\n" ) {
			throw new RuntimeException( 'The installed plugin did not match the exact prepared package.' );
		}
	}

	private function assert_theme( string $stylesheet, string $version, string $marker, bool $active ): void {
		wp_clean_themes_cache();
		$theme = wp_get_theme( $stylesheet );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		if ( ! $theme->exists() || (string) $theme->get( 'Version' ) !== $version || ( (string) get_option( 'stylesheet' ) === $stylesheet ) !== $active || file_get_contents( get_theme_root( $stylesheet ) . '/' . $stylesheet . '/ran-booster-executor.txt' ) !== $marker . "\n" ) {
			throw new RuntimeException( 'The installed theme did not match the exact prepared package.' );
		}
	}

	private function assert_success( RAN\WordPress\CorePackageExecutionResult $result ): void {
		if ( ! $result->is_successful() ) {
			// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
			throw new RuntimeException( 'WordPress core did not complete the disposable package operation: ' . $result->get_failure()->value );
		}
	}

	private function assert_failure(
		RAN\WordPress\CorePackageExecutionResult $result,
		RAN\WordPress\CorePackageExecutionFailure $failure,
		RAN\WordPress\CorePackageExecutionFailure ...$alternative_failures
	): void {
		if ( ! in_array( $result->get_failure(), array( $failure, ...$alternative_failures ), true ) ) {
			$actual = $result->get_failure();
			// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
			throw new RuntimeException( 'The executor did not return the expected bounded failure: ' . ( null === $actual ? 'success' : $actual->value ) );
		}
	}

	private function assert_maintenance_absent(): void {
		$path = ABSPATH . '.maintenance';
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'WordPress left maintenance mode enabled.' );
		}
	}

	/** @return array<string, list<string>> */
	private function hook_fingerprint(): array {
		global $wp_filter;
		$fingerprint = array();
		foreach ( array( 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_clear_destination', 'automatic_updates_is_vcs_checkout', 'wp_doing_cron', 'upgrader_process_complete' ) as $hook ) {
			$fingerprint[ $hook ] = array();
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( $callbacks ) as $callback_id ) {
					$fingerprint[ $hook ][] = $priority . ':' . $callback_id;
				}
			}
		}
		return $fingerprint;
	}

	/**
	 * @param array<string, list<string>> $before
	 * @param array<string, list<string>> $after
	 */
	private function has_added_hooks( array $before, array $after ): bool {
		foreach ( $after as $hook => $callbacks ) {
			if ( array() !== array_diff( $callbacks, $before[ $hook ] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	private function slug( string $role ): string {
		return 'ran-booster-executor-' . $role . '-' . $this->run_id;
	}
}

$ran_booster_smoke   = new RAN_Booster_CorePackageExecutorSmoke( bin2hex( random_bytes( 6 ) ) );
$ran_booster_failure = null;
try {
	$ran_booster_smoke->run();
} catch ( Throwable $caught ) {
	$ran_booster_failure = $caught;
}
try {
	$ran_booster_smoke->cleanup();
} catch ( Throwable $cleanup_failure ) {
	$ran_booster_failure = $cleanup_failure;
}
if ( null !== $ran_booster_failure ) {
	throw $ran_booster_failure;
}
WP_CLI::success( 'Core package executor smoke test passed.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)

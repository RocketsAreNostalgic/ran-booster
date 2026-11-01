<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\CoreSelfUpdateNativeTarget;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use RAN\WordPress\ReleaseUpdaterBootstrap;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CoreSelfUpdateAdmissionTest extends TestCase {

	private static string $source_root;
	private ?string $installed_file = null;

	private static function load_shims(): void {
		$root = getenv( 'RAN_BOOSTER_QUEUED_ADMISSION_ROOT' );
		$root = is_string( $root ) && '' !== $root ? $root : dirname( __DIR__, 2 );
		if ( ! is_dir( $root ) || ! is_file( $root . '/ran-booster.php' ) ) {
			throw new \RuntimeException( 'The queued-admission test source root is unavailable.' );
		}
		self::$source_root = $root;
		if ( defined( 'WP_PLUGIN_DIR' ) && str_starts_with( WP_PLUGIN_DIR, sys_get_temp_dir() . '/ran-booster-queued-admission-' ) ) {
			$GLOBALS['ran_booster_queued_admission_fixture_root'] = WP_PLUGIN_DIR;
		} elseif ( ! is_string( $GLOBALS['ran_booster_queued_admission_fixture_root'] ?? null ) ) {
			$random = bin2hex( random_bytes( 16 ) );
			$GLOBALS['ran_booster_queued_admission_fixture_root'] = sys_get_temp_dir() . '/ran-booster-queued-admission-' . $random;
		}

		require_once __DIR__ . '/CoreSelfUpdateAdmissionWordPressFunctions.php';
		require_once self::$source_root . '/tests/Support/WPError.php';
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Each separately spawned PHPUnit process must load its own isolated WordPress fixture after discovery.
	protected function setUp(): void {
		self::load_shims();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle hook.
	protected function tearDown(): void {
		if ( is_string( $this->installed_file ) && is_file( $this->installed_file ) ) {
			unlink( $this->installed_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test owns this exact temporary copied source file.
			rmdir( dirname( $this->installed_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test owns the exact otherwise-empty temporary plugin directory.
			rmdir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test owns the exact otherwise-empty temporary plugin root.
		}
	}

	#[RunInSeparateProcess]
	public function test_queued_target_that_fails_at_the_real_activation_boundary_is_fenced(): void {
		$target = $this->queued_target();
		$this->install_actual_fallback( $target );
		$this->assert_fenced( $this->core_identifier() );

		global $wp_version;
		$wp_version = 'not-a-wordpress-version'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Real selected runtime must reject this controlled lifecycle environment.
		$this->invoke_lifecycle();

		self::assertFalse( $target->status()->active );
		self::assertSame( 'github_updater_runtime_environment_invalid', $target->status()->failure_code );
		$this->assert_fenced( $this->core_identifier() );
	}

	#[RunInSeparateProcess]
	public function test_active_target_preserves_foreign_values_then_fences_after_live_broker_loss(): void {
		$target = $this->queued_target();
		$this->install_actual_fallback( $target );

		global $wp_version;
		$wp_version = '7.0.0'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Real selected runtime activation fixture meets the copied Core header requirement.
		$this->invoke_lifecycle();
		self::assertTrue( $target->status()->active );
		$this->assert_passes_through( $this->core_identifier() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the public runtime losing its request-local broker ownership after activation.
		$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
		self::assertFalse( $target->status()->active );
		$this->assert_fenced( $this->core_identifier() );
	}

	private function queued_target(): CoreSelfUpdateNativeTarget {
		global $wp_version;
		$wp_version = '7.0.0'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The public registrar samples WordPress at its later lifecycle callback.
		$registrar  = ReleaseUpdaterBootstrap::register();
		$updater    = ( new ManagedReleaseUpdaterRegistrar( $registrar ) )->plugin(
			'github',
			$this->installed_core_file(),
			'RocketsAreNostalgic/ran-booster',
			'1319710173',
			'prerelease',
			'manual'
		);
		$target     = new CoreSelfUpdateNativeTarget( $updater );

		self::assertTrue( $target->register() );
		self::assertFalse( $target->status()->active );

		return $target;
	}

	private function invoke_lifecycle(): void {
		$callbacks = $GLOBALS['ran_booster_queued_admission_hooks']['after_setup_theme'] ?? array();
		self::assertCount( 1, $callbacks );
		$callbacks[0]['callback']();
	}

	private function install_actual_fallback( CoreSelfUpdateNativeTarget $target ): void {
		$source = file_get_contents( $this->source_file() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Extract exactly the production fallback block from the bounded source input.
		self::assertIsString( $source );
		$offset = strpos( $source, "if ( function_exists( 'add_filter' ) ) {" );
		self::assertIsInt( $offset, 'The candidate must provide unconditional Core admission filters.' );
		$block = $this->balanced_block( $source, $offset );

		$core_release_target = $target;
		eval( $block ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test executes the exact bounded production fallback block rather than reimplementing it.
	}

	private function assert_fenced( string $identifier ): void {
		$offer    = (object) array(
			'response' => array(
				$identifier               => (object) array( 'plugin' => $identifier ),
				'unrelated/unrelated.php' => (object) array( 'plugin' => 'unrelated/unrelated.php' ),
			),
		);
		$filtered = $this->fallback_filter( 'site_transient_update_plugins', $offer );
		self::assertSame( $offer, $filtered );
		self::assertArrayNotHasKey( $identifier, $filtered->response );
		self::assertArrayHasKey( 'unrelated/unrelated.php', $filtered->response );

		$sentinel = new \stdClass();
		foreach ( array( array( 'plugin' => $identifier ), array( 'plugins' => array( $identifier, 'unrelated/unrelated.php' ) ) ) as $target ) {
			$extra    = array_merge(
				array(
					'action' => 'update',
					'type'   => 'plugin',
				),
				$target
			);
			$download = $this->fallback_filter( 'upgrader_pre_download', $sentinel, 'https://example.test/core.zip', new \stdClass(), $extra );
			$install  = $this->fallback_filter( 'upgrader_pre_install', $sentinel, $extra );
			self::assertInstanceOf( \WP_Error::class, $download );
			self::assertInstanceOf( \WP_Error::class, $install );
			self::assertSame( 'ran_booster_native_update_core_registration_unavailable', $download->get_error_code() );
			self::assertSame( 'ran_booster_native_update_core_registration_unavailable', $install->get_error_code() );
		}
		foreach ( array(
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'unrelated/unrelated.php',
			),
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'unrelated-theme',
			),
			array(
				'action' => 'install-plugin',
				'type'   => 'plugin',
				'plugin' => $identifier,
			),
		) as $extra ) {
			self::assertSame( $sentinel, $this->fallback_filter( 'upgrader_pre_download', $sentinel, 'https://example.test/unrelated.zip', new \stdClass(), $extra ) );
			self::assertSame( $sentinel, $this->fallback_filter( 'upgrader_pre_install', $sentinel, $extra ) );
		}
	}

	private function assert_passes_through( string $identifier ): void {
		$offer = (object) array(
			'response' => array(
				$identifier => (object) array( 'plugin' => $identifier ),
			),
		);
		self::assertSame( $offer, $this->fallback_filter( 'site_transient_update_plugins', $offer ) );
		$sentinel = new \stdClass();
		$extra    = array(
			'action' => 'update',
			'type'   => 'plugin',
			'plugin' => $identifier,
		);
		self::assertSame( $sentinel, $this->fallback_filter( 'upgrader_pre_download', $sentinel, 'https://example.test/core.zip', new \stdClass(), $extra ) );
		self::assertSame( $sentinel, $this->fallback_filter( 'upgrader_pre_install', $sentinel, $extra ) );
	}

	private function source_file(): string {
		$source = getenv( 'RAN_BOOSTER_QUEUED_ADMISSION_SOURCE' );
		$source = is_string( $source ) && '' !== $source ? $source : self::$source_root . '/ran-booster.php';
		self::assertFileExists( $source );

		return $source;
	}

	private function installed_core_file(): string {
		if ( is_string( $this->installed_file ) ) {
			return $this->installed_file;
		}
		$directory = WP_PLUGIN_DIR . '/ran-booster';
		self::assertTrue( is_dir( $directory ) || mkdir( $directory, 0700, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only the bounded private test plugin fixture.
		$file = $directory . '/ran-booster.php';
		self::assertTrue( copy( $this->source_file(), $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy -- Real updater requires a regular installed plugin file in the owned temporary fixture.
		self::assertTrue( chmod( $file, 0600 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture keeps its copied plugin source private.
		$this->installed_file = $file;

		return $file;
	}

	private function core_identifier(): string {
		return 'ran-booster/ran-booster.php';
	}

	private function fallback_filter( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$callbacks = $GLOBALS['ran_booster_queued_admission_hooks'][ $hook ] ?? array();
		$priority  = 'site_transient_update_plugins' === $hook ? PHP_INT_MAX : PHP_INT_MIN;
		$callbacks = array_values( array_filter( $callbacks, static fn ( array $entry ): bool => $priority === $entry['priority'] ) );
		self::assertCount( 1, $callbacks, 'The actual Core fallback must register one callback at its fail-closed priority.' );
		$entry = $callbacks[0];
		$value = $entry['callback']( $value, ...array_slice( $arguments, 0, $entry['accepted_args'] - 1 ) );

		return $value;
	}

	private function balanced_block( string $source, int $offset ): string {
		$tokens = token_get_all( '<?php ' . substr( $source, $offset ) );
		$block  = '';
		$depth  = 0;
		$opened = false;
		foreach ( $tokens as $token ) {
			$text   = is_array( $token ) ? $token[1] : $token;
			$block .= $text;
			if ( '{' === $text ) {
				$opened = true;
				++$depth;
			} elseif ( $opened && '}' === $text && 0 === --$depth ) {
				return ltrim( substr( $block, strlen( '<?php ' ) ) );
			}
		}

		self::fail( 'The candidate Core admission block is unbalanced.' );
	}
}

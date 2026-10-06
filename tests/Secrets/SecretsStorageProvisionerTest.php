<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

require_once __DIR__ . '/SecretsStorageWordPressFunctions.php';

// Native local filesystem behavior is part of this focused composition test.

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Secrets\PosixFilesystemProbe;
use RAN\Secrets\PrivateLocationCandidateResolver;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageProvisioner;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Secrets\WpConfigPathWriteResult;
use RAN\Secrets\WpConfigSecretsPathWriter;

#[CoversClass( SecretsStorageProvisioner::class )]
#[CoversClass( SecretsStorageProvisioningResult::class )]
final class SecretsStorageProvisionerTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_wordpress_directory_constants_remain_unusable(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'ABSPATH', 123 );
		define( 'WP_CONTENT_DIR', false );

		$reflection  = new \ReflectionClass( SecretsStorageProvisioner::class );
		$provisioner = $reflection->newInstanceWithoutConstructor();

		self::assertSame( '', $reflection->getMethod( 'wordpress_root' )->invoke( $provisioner ) );
		self::assertSame( '', $reflection->getMethod( 'content_directory' )->invoke( $provisioner ) );
	}

	private string $root;
	private string $wordpress_root;
	private string $config_path;
	private string $candidate;
	private string $temporary_boundary;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_secrets_test_translations'] = array();
		$suffix                   = bin2hex( random_bytes( 8 ) );
		$this->root               = sys_get_temp_dir() . '/ran-booster-provisioner-' . $suffix;
		$this->temporary_boundary = sys_get_temp_dir() . '/ran-booster-temporary-boundary-' . $suffix;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->root, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->temporary_boundary, 0700 ) );
		$canonical_root = realpath( $this->root );
		self::assertIsString( $canonical_root );
		$this->root           = $canonical_root;
		$this->wordpress_root = $this->root . '/wordpress';
		$this->config_path    = $this->wordpress_root . '/wp-config.php';
		$this->candidate      = $this->root . '/private/.ran-booster/0123456789abcdef/secrets.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->wordpress_root . '/wp-content/plugins/ran-booster', 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->root . '/private', 0700 ) );
		self::assertNotFalse(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
			file_put_contents(
				$this->config_path,
				"<?php\n\ndefine( 'DB_NAME', 'example' );\n\n/* That's all, stop editing! Happy publishing. */\n"
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->config_path, 0600 ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_secrets_test_translations'] );
		$this->remove_tree( $this->root );
		$this->remove_tree( $this->temporary_boundary );
	}

	public function test_read_only_status_suggests_setup_without_running_the_probe_or_writer(): void {
		$provisioner = $this->provisioner();
		$result      = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::SETUP_AVAILABLE, $result->status() );
		self::assertSame( 'setup_available', $result->code() );
		self::assertSame( $this->candidate, $result->candidate_path() );
		self::assertTrue( $result->can_provision_automatically() );
		self::assertFalse( $provisioner->probe_called );
		self::assertFalse( $provisioner->writer_called );
		self::assertDirectoryDoesNotExist( dirname( $this->candidate ) );
	}

	public function test_localizes_factory_status_and_pending_messages_without_changing_codes_or_paths(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			'Booster can create secure encrypted secrets storage.' => 'Stockage sécurisé prêt.',
			'WordPress must reload before the encrypted secrets path can be trusted.' => 'WordPress doit recharger.',
			'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.' => 'Stockage chiffré incomplet.',
		);
		$provisioner = $this->provisioner();
		$status      = $provisioner->status();
		$pending     = $provisioner->provision();
		$attention   = SecretsStorageProvisioningResult::storage_needs_attention(
			$this->candidate,
			SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
		);

		self::assertSame( 'Stockage sécurisé prêt.', $status->message() );
		self::assertSame( 'setup_available', $status->code() );
		self::assertSame( $this->candidate, $status->candidate_path() );
		self::assertSame( 'WordPress doit recharger.', $pending->message() );
		self::assertSame( 'pending_verification', $pending->code() );
		self::assertSame( $this->candidate, $pending->candidate_path() );
		self::assertSame( 'Stockage chiffré incomplet.', $attention->message() );
	}

	public function test_localizes_configured_storage_item_diagnostics_without_changing_codes_modes_or_paths(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			"Configured secrets storage item\004directory" => 'répertoire',
			"Configured secrets storage item\004file"      => 'fichier',
			"Configured secrets storage item\004lock file" => 'fichier verrou',
			'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.' => 'Le secret %1$s utilise le mode %2$04o ; le mode %3$04o est requis.',
		);

		$directory = $this->root . '/translated-directory';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0755 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $directory . '/secrets.json';
		$directory_result        = $provisioner->status();

		self::assertSame( 'storage_directory_unusable', $directory_result->code() );
		self::assertSame( $provisioner->configured, $directory_result->candidate_path() );
		self::assertStringContainsString( 'répertoire', $directory_result->message() );
		self::assertStringContainsString( '0755', $directory_result->message() );
		self::assertStringContainsString( '0700', $directory_result->message() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $directory, 0700 ) );
		$file = $provisioner->configured;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $file, 0644 ) );
		$file_result = $provisioner->status();

		self::assertSame( 'storage_file_unusable', $file_result->code() );
		self::assertSame( $file, $file_result->candidate_path() );
		self::assertStringContainsString( 'fichier', $file_result->message() );
		self::assertStringContainsString( '0644', $file_result->message() );
		self::assertStringContainsString( '0600', $file_result->message() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $file, 0600 ) );
		$lock = $file . '.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $lock, '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $lock, 0644 ) );
		$lock_result = $provisioner->status();

		self::assertSame( 'storage_lock_unusable', $lock_result->code() );
		self::assertSame( $file, $lock_result->candidate_path() );
		self::assertStringContainsString( 'fichier verrou', $lock_result->message() );
		self::assertStringContainsString( '0644', $lock_result->message() );
		self::assertStringContainsString( '0600', $lock_result->message() );
	}

	public function test_localizes_mode_ownership_readability_and_writability_issues(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			"Configured secrets storage item\004file" => 'fichier',
			'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.' => 'Mode : %1$s, %2$04o au lieu de %3$04o.',
			'The configured secrets %s is not owned by the PHP process user.' => 'Propriétaire PHP incorrect : %s.',
			'The configured secrets %s is not readable by PHP.' => 'PHP ne peut pas lire : %s.',
			'The configured secrets %s is not writable by PHP.' => 'PHP ne peut pas écrire : %s.',
		);
		$method = new \ReflectionMethod( SecretsStorageProvisioner::class, 'access_issues' );
		$issues = $method->invoke(
			$this->provisioner(),
			$this->root . '/does-not-exist',
			array(
				'mode' => 0644,
				'uid'  => -1,
			),
			0600,
			'file'
		);

		self::assertSame(
			array(
				'Mode : fichier, 0644 au lieu de 0600.',
				'Propriétaire PHP incorrect : fichier.',
				'PHP ne peut pas lire : fichier.',
				'PHP ne peut pas écrire : fichier.',
			),
			$issues
		);

		$fallback_issues = $method->invoke(
			$this->provisioner(),
			$this->root . '/does-not-exist',
			array(
				'mode' => 0644,
				'uid'  => -1,
			),
			0600,
			'unexpected item'
		);

		self::assertSame( 'Mode : unexpected item, 0644 au lieu de 0600.', $fallback_issues[0] );
	}

	public function test_localizes_managed_storage_diagnostic_branches_without_changing_match_routing(): void {
		$directory = $this->root . '/translated-managed-diagnostics';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$provisioner                 = $this->provisioner();
		$provisioner->configured     = $directory . '/secrets.json';
		$provisioner->health_failure = true;
		$generic                     = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
		$cases                       = array(
			array( 'storage_key_missing', 'Fixture storage failure.', 'storage_key_missing' ),
			array( 'storage_file_missing', 'Fixture storage failure.', 'storage_file_missing' ),
			array( 'storage_orphan_lock', 'Fixture storage failure.', 'storage_orphan_lock' ),
			array( 'storage_lock_missing', 'Fixture storage failure.', 'storage_lock_missing' ),
			array( 'unexpected_reason', 'Fixture storage failure.', 'unexpected_reason' ),
			array( $generic, 'The encrypted Booster secrets store is incomplete.', 'storage_incomplete' ),
			array( $generic, 'The encrypted Booster secrets store is incomplete because its lock is missing.', 'storage_incomplete' ),
			array( $generic, 'The encrypted Booster secrets store is missing its lock.', 'storage_incomplete' ),
			array( $generic, 'The encrypted Booster secrets document could not be authenticated.', 'storage_authentication_failed' ),
			array( $generic, 'The encrypted Booster secrets payload is invalid.', 'storage_document_invalid' ),
			array( $generic, 'The encrypted Booster secrets payload is not canonical.', 'storage_document_invalid' ),
			array( $generic, 'The Booster site key is unavailable.', 'storage_key_unavailable' ),
			array( $generic, 'The encrypted Booster secrets file is not readable.', 'storage_file_unusable' ),
			array( $generic, 'The encrypted Booster secrets file is not a secure bounded file.', 'storage_file_unusable' ),
			array( $generic, 'The encrypted Booster secrets file could not be read safely.', 'storage_file_unusable' ),
			array( $generic, 'Refusing to use an invalid encrypted Booster secrets lock.', 'storage_lock_unusable' ),
			array( $generic, 'Could not open the encrypted Booster secrets lock.', 'storage_lock_unusable' ),
			array( $generic, 'Could not inspect the encrypted Booster secrets lock.', 'storage_lock_unusable' ),
			array( $generic, 'Could not secure the encrypted Booster secrets lock.', 'storage_lock_unusable' ),
			array( $generic, 'Could not lock the encrypted Booster secrets store.', 'storage_lock_unusable' ),
			array( $generic, 'An unclassified fixture failure.', 'storage_unavailable' ),
		);

		foreach ( $cases as $case ) {
			$reason                              = $case[0];
			$message                             = $case[1];
			$code                                = $case[2];
			$provisioner->health_failure_reason  = $reason;
			$provisioner->health_failure_message = $message;
			$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
				$this->managed_diagnostic_message_for_code( $code ) => 'Diagnostic traduit : ' . $code,
			);

			$result = $provisioner->status();

			self::assertSame( $code, $result->code(), $message );
			self::assertSame( $provisioner->configured, $result->candidate_path(), $message );
			self::assertSame( 'Diagnostic traduit : ' . $code, $result->message(), $message );
		}
	}

	public function test_unavailable_location_retains_bounded_discarded_candidate_diagnostics(): void {
		$provisioner                       = $this->provisioner();
		$provisioner->resolver_fails       = true;
		$provisioner->discarded_candidates = array(
			array(
				'directory' => $this->root . '/account/.ran-booster/0123456789abcdef',
				'code'      => 'php_accessible_group_writable_ancestor',
				'reason'    => 'The host ancestor is writable by the PHP group.',
				'component' => $this->root,
			),
		);

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'location_unavailable', $result->code() );
		self::assertSame( $provisioner->discarded_candidates, $result->discarded_candidates() );
		self::assertFalse( $provisioner->probe_called );
		self::assertFalse( $provisioner->writer_called );
	}

	public function test_provision_probes_then_writes_and_remains_pending_until_afresh_configuration_check(): void {
		$provisioner = $this->provisioner();

		$pending = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::PENDING_VERIFICATION, $pending->status() );
		self::assertTrue( $pending->requires_next_request_verification() );
		self::assertTrue( $provisioner->probe_called );
		self::assertTrue( $provisioner->writer_called );
		self::assertDirectoryExists( dirname( $this->candidate ) );
		self::assertFileDoesNotExist( $this->candidate );
		self::assertStringContainsString(
			"define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->candidate ) . "' );",
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
			(string) file_get_contents( $this->config_path )
		);
		self::assertStringNotContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
			(string) file_get_contents( $this->config_path )
		);

		$provisioner->configured = $this->candidate;
		$configured              = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $configured->status() );
		self::assertTrue( $configured->has_configured_path() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC, $configured->path_source() );
	}

	public function test_valid_manual_override_is_handled_before_automatic_suggestion_resolution(): void {
		$manual = $this->root . '/manual';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $manual . '/secrets.json';

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $result->status() );
		self::assertSame( $manual . '/secrets.json', $result->candidate_path() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL, $result->path_source() );
		self::assertTrue( $provisioner->resolver_called );
		self::assertFalse( $provisioner->probe_called );
		self::assertFalse( $provisioner->writer_called );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_directory_constant_appends_the_managed_filename(): void {
		$wp_config_directory = $this->root . '/public';
		$directory           = dirname( $wp_config_directory ) . '/operator-private';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $directory );

		$provisioner                             = $this->provisioner();
		$provisioner->read_runtime_configuration = true;
		$result                                  = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $result->status() );
		self::assertSame( $directory . '/secrets.json', $result->candidate_path() );
		self::assertSame( $directory . '/secrets.json', ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_legacy_file_constant_blocks_otherwise_valid_directory_configuration(): void {
		$wp_config_directory = $this->root . '/public';
		$directory           = dirname( $wp_config_directory ) . '/operator-private';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $directory );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $directory . '/legacy-secrets.json' );

		$provisioner                             = $this->provisioner();
		$provisioner->read_runtime_configuration = true;
		$result                                  = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidate_path() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_legacy_file_constant_is_rejected_even_when_its_path_is_otherwise_valid(): void {
		$wp_config_directory = $this->root . '/public';
		$directory           = dirname( $wp_config_directory ) . '/operator-file';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $directory . '/secrets.json' );

		$provisioner                             = $this->provisioner();
		$provisioner->read_runtime_configuration = true;
		$result                                  = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidate_path() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_raw_relative_directory_constant_is_rejected_by_both_consumers(): void {
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', 'relative/private' );

		$provisioner                             = $this->provisioner();
		$provisioner->read_runtime_configuration = true;
		$result                                  = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidate_path() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_raw_relative_file_constant_is_rejected_by_both_consumers(): void {
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', 'relative/private/secrets.json' );

		$provisioner                             = $this->provisioner();
		$provisioner->read_runtime_configuration = true;
		$result                                  = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidate_path() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	public function test_configured_path_reports_authenticated_and_broken_storage_truthfully(): void {
		$manual = $this->root . '/manual-health';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $manual . '/secrets.json';
		$provisioner->healthy    = true;

		self::assertSame(
			SecretsStorageProvisioningResult::STORAGE_HEALTHY,
			$provisioner->status()->status()
		);

		$provisioner->health_failure = true;
		$result                      = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $result->status() );
		self::assertSame( 'storage_unavailable', $result->code() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function test_configured_path_explains_missing_and_insecure_directory(): void {
		$directory               = $this->root . '/manual-attention';
		$provisioner             = $this->provisioner();
		$provisioner->configured = $directory . '/secrets.json';

		$result = $provisioner->status();
		self::assertSame( 'storage_directory_unavailable', $result->code() );
		self::assertStringContainsString( 'execute/traverse', $result->message() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0755 ) );
		$result = $provisioner->status();
		self::assertSame( 'storage_directory_unusable', $result->code() );
		self::assertStringContainsString( '0755', $result->message() );
		self::assertStringContainsString( '0700', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function test_configured_path_explains_unsafe_file_permissions(): void {
		$directory = $this->root . '/manual-file-attention';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$file = $directory . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $file, 0644 ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $file;
		$result                  = $provisioner->status();

		self::assertSame( 'storage_file_unusable', $result->code() );
		self::assertStringContainsString( '0644', $result->message() );
		self::assertStringContainsString( '0600', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function test_configured_path_explains_missing_and_unsafe_lock_file(): void {
		$directory = $this->root . '/manual-lock-attention';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$file = $directory . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $file, 0600 ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $file;
		$result                  = $provisioner->status();
		self::assertSame( 'storage_lock_missing', $result->code() );
		self::assertStringContainsString( 'matching lock file is missing', $result->message() );

		$lock = $file . '.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $lock, '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $lock, 0644 ) );
		$result = $provisioner->status();
		self::assertSame( 'storage_lock_unusable', $result->code() );
		self::assertStringContainsString( '0644', $result->message() );
		self::assertStringContainsString( '0600', $result->message() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $lock, 0600 ) );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $provisioner->status()->status() );
	}

	public function test_configured_path_distinguishes_incomplete_and_authentication_failures(): void {
		$directory = $this->root . '/manual-managed-attention';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$provisioner                 = $this->provisioner();
		$provisioner->configured     = $directory . '/secrets.json';
		$provisioner->health_failure = true;

		$provisioner->health_failure_message = 'The encrypted Booster secrets store is incomplete.';
		self::assertSame( 'storage_incomplete', $provisioner->status()->code() );

		$provisioner->health_failure_reason = 'storage_key_missing';
		$result                             = $provisioner->status();
		self::assertSame( 'storage_key_missing', $result->code() );
		self::assertStringContainsString( 'database encryption key is missing', $result->message() );
		self::assertStringContainsString( 'will not delete unauthenticated ciphertext', $result->message() );

		$provisioner->health_failure_reason  = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
		$provisioner->health_failure_message = 'The encrypted Booster secrets document could not be authenticated.';
		$result                              = $provisioner->status();
		self::assertSame( 'storage_authentication_failed', $result->code() );
		self::assertStringContainsString( 'same backup', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function test_manual_path_does_not_require_automatic_filesystem_eligibility(): void {
		$manual = $this->root . '/manual-platform';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner                 = $this->provisioner();
		$provisioner->configured     = $manual . '/secrets.json';
		$provisioner->local_platform = false;

		self::assertSame(
			SecretsStorageProvisioningResult::PATH_CONFIGURED,
			$provisioner->status()->status()
		);
	}

	public function test_manual_override_inside_word_press_is_rejected_with_privileged_correction_path(): void {
		$provisioner             = $this->provisioner();
		$provisioner->configured = $this->wordpress_root . '/secrets.json';

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $result->status() );
		self::assertSame( 'configured_path_unsafe', $result->code() );
		self::assertSame( $this->wordpress_root . '/secrets.json', $result->candidate_path() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL, $result->path_source() );
		self::assertStringContainsString( 'outside the public web root', $result->message() );
		self::assertFalse( $provisioner->resolver_called );
	}

	public function test_unsafe_booster_owned_directory_definition_is_attributed_to_automatic_setup(): void {
		$configured = $this->wordpress_root . '/private/secrets.json';
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $configured );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $configured;

		$result = $provisioner->status();

		self::assertSame( 'configured_path_unsafe', $result->code() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC, $result->path_source() );
		self::assertFalse( $provisioner->resolver_called );
	}

	public function test_manual_override_rejects_symlinked_components_and_unsafe_existing_target(): void {
		$actual = $this->root . '/manual-actual';
		$link   = $this->root . '/manual-link';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $actual, 0700 ) );
		self::assertTrue( symlink( $actual, $link ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $link . '/secrets.json';
		self::assertSame( 'configured_path_unsafe', $provisioner->status()->code() );

		$provisioner->configured = $actual . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $provisioner->configured, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $provisioner->configured, 0644 ) );
		self::assertSame( 'storage_file_unusable', $provisioner->status()->code() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $provisioner->configured, 0600 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $provisioner->configured . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $provisioner->configured . '.lock', 0600 ) );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $provisioner->status()->status() );
	}

	public function test_only_one_actually_included_core_configuration_candidate_is_accepted(): void {
		$provisioner           = $this->provisioner();
		$other                 = $this->root . '/other/wp-config.php';
		$provisioner->included = array( $this->config_path, $other );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $other ), 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $other, "<?php\n" ) );

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'wp_config_unavailable', $result->code() );
		self::assertFalse( $provisioner->probe_called );
	}

	public function test_parent_configuration_uses_the_core_fallback_rule(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		self::assertTrue( unlink( $this->config_path ) );
		$parent_config = $this->root . '/wp-config.php';
		self::assertNotFalse(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
			file_put_contents(
				$parent_config,
				"<?php\n\n/* That's all, stop editing! Happy publishing. */\n"
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $parent_config, 0600 ) );
		$provisioner           = $this->provisioner();
		$provisioner->included = array( $parent_config );

		self::assertSame(
			SecretsStorageProvisioningResult::SETUP_AVAILABLE,
			$provisioner->status()->status()
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->root . '/wp-settings.php', "<?php\n" ) );
		self::assertSame( 'wp_config_unavailable', $provisioner->status()->code() );
	}

	public function test_unsupported_environment_stops_before_location_or_filesystem_work(): void {
		$provisioner            = $this->provisioner();
		$provisioner->multisite = true;

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::UNSUPPORTED, $result->status() );
		self::assertSame( 'multisite_unsupported', $result->code() );
		self::assertFalse( $provisioner->resolver_called );
		self::assertFalse( $provisioner->probe_called );
		self::assertFalse( $provisioner->writer_called );
	}

	public function test_probe_failure_returns_astable_code_without_calling_the_writer_or_leaking_the_path(): void {
		$provisioner              = $this->provisioner();
		$provisioner->probe_fails = true;

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'filesystem_probe_failed', $result->code() );
		self::assertFalse( $provisioner->writer_called );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function test_candidate_is_revalidated_after_probe_before_wp_config_mutation(): void {
		$provisioner                     = $this->provisioner();
		$provisioner->unsafe_after_probe = true;

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'candidate_path_unsafe', $result->code() );
		self::assertTrue( $provisioner->probe_called );
		self::assertFalse( $provisioner->writer_called );
		self::assertStringNotContainsString( $this->root, $result->message() );
		self::assertStringNotContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
			(string) file_get_contents( $this->config_path )
		);
	}

	#[DataProvider( 'wp_config_write_failure_cases' )]
	public function test_writer_failure_is_reduced_to_its_stable_code_and_translated_display_message(
		string $reason,
		string $source_message
	): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'][ $source_message ] = 'Translated writer failure: ' . $reason;
		$provisioner                         = $this->provisioner();
		$provisioner->writer_failure_code    = $reason;
		$provisioner->writer_failure_message = 'Raw writer canary: /private/leaked/wp-config.php';

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( $reason, $result->code() );
		self::assertSame( 'Translated writer failure: ' . $reason, $result->message() );
		self::assertStringNotContainsString( 'Raw writer canary', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	/** @return iterable<string, array{string, string}> */
	public static function wp_config_write_failure_cases(): iterable {
		$messages = array(
			'sidecar_path_unchanged'       => 'The encrypted secrets path is already configured.',
			'config_directory_invalid'     => 'The WordPress configuration directory is not safe and writable.',
			'sidecar_path_invalid'         => 'The encrypted secrets path is not safe for automatic configuration.',
			'config_path_invalid'          => 'The supplied WordPress configuration path is not an absolute safe POSIX file path.',
			'config_changed'               => 'The WordPress configuration changed before it could be edited.',
			'lock_permissions_failed'      => 'Could not secure the WordPress configuration edit lock.',
			'lock_failed'                  => 'Could not lock the WordPress configuration for editing.',
			'config_file_invalid'          => 'The WordPress configuration does not pass Booster\'s writable private regular-file checks.',
			'config_permissions_unsafe'    => 'The WordPress configuration is group- or world-writable.',
			'config_size_unsupported'      => 'The WordPress configuration has an unsupported size.',
			'config_owner_invalid'         => 'The WordPress configuration is not owned by the current process owner.',
			'config_read_failed'           => 'Could not read the complete WordPress configuration.',
			'marker_invalid'               => 'The WordPress configuration must contain one standard stop-editing marker.',
			'constant_exists'              => 'The encrypted secrets path constant is already defined.',
			'owned_definition_ambiguous'   => 'The automatic encrypted secrets definition is ambiguous.',
			'candidate_parse_failed'       => 'The edited WordPress configuration did not pass the expected definition check.',
			'temporary_permissions_failed' => 'Could not secure the temporary WordPress configuration.',
			'temporary_ownership_failed'   => 'Could not preserve WordPress configuration ownership.',
			'temporary_flush_failed'       => 'Could not flush the edited WordPress configuration.',
			'temporary_sync_failed'        => 'Could not synchronize the edited WordPress configuration.',
			'temporary_readback_failed'    => 'The edited WordPress configuration failed its read-back check.',
			'temporary_metadata_invalid'   => 'The edited WordPress configuration metadata could not be verified.',
			'replace_failed'               => 'Could not atomically replace the WordPress configuration.',
			'replacement_readback_failed'  => 'The installed WordPress configuration failed verification.',
			'filesystem_failure'           => 'The WordPress configuration could not be updated safely.',
			'config_parse_failed'          => 'The WordPress configuration does not parse as supported PHP.',
			'line_endings_unsupported'     => 'The WordPress configuration uses unsupported line endings.',
			'lock_invalid'                 => 'The WordPress configuration edit lock is not safe.',
			'temporary_write_failed'       => 'Could not write the complete edited WordPress configuration.',
			'temporary_file_invalid'       => 'The temporary WordPress configuration is not safe.',
			'lock_open_failed'             => 'Could not open the WordPress configuration edit lock.',
			'temporary_create_failed'      => 'Could not create a private temporary WordPress configuration.',
			'future_writer_failure'        => 'The WordPress configuration could not be updated safely.',
		);
		foreach ( $messages as $reason => $message ) {
			yield $reason => array( $reason, $message );
		}
	}

	public function test_unique_authenticated_provider_fit_sibling_can_be_adopted_by_opaque_revision(): void {
		$old = $this->recovery_store( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                        = $this->provisioner();
		$provisioner->configured            = $this->candidate;
		$provisioner->health_failure        = true;
		$provisioner->health_failure_reason = 'storage_file_missing';
		$status                             = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $status->status() );
		$recovery = $provisioner->recovery_state( $status );
		self::assertIsArray( $recovery );
		self::assertSame( 'available', $recovery['state'] );
		self::assertSame( $old, $recovery['candidate_path'] );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', (string) $recovery['token'] );

		$result = $provisioner->adopt_recovery( (string) $recovery['token'] );
		self::assertTrue( $result->requires_next_request_verification() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local fixture/source bytes for the boundary assertion without WordPress filesystem indirection.
		$config = (string) file_get_contents( $this->config_path );
		self::assertStringContainsString( dirname( $old ), $config );
		self::assertStringNotContainsString( dirname( $this->candidate ), $config );
		self::assertSame( array( $old, $old ), $provisioner->authenticated_candidates );
	}

	public function test_recovery_writer_failure_uses_the_same_translated_display_boundary(): void {
		$old = $this->recovery_store( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster']['The WordPress configuration changed before it could be edited.'] = 'Configuration changed translation.';
		$provisioner                         = $this->provisioner();
		$provisioner->configured             = $this->candidate;
		$provisioner->health_failure         = true;
		$provisioner->health_failure_reason  = 'storage_file_missing';
		$provisioner->writer_failure_code    = 'config_changed';
		$provisioner->writer_failure_message = 'Raw recovery writer canary: /private/leaked/wp-config.php';
		$status                              = $provisioner->status();
		$recovery                            = $provisioner->recovery_state( $status );
		self::assertIsArray( $recovery );

		$result = $provisioner->adopt_recovery( (string) $recovery['token'] );

		self::assertSame( 'config_changed', $result->code() );
		self::assertSame( 'Configuration changed translation.', $result->message() );
		self::assertStringNotContainsString( 'Raw recovery writer canary', $result->message() );
		self::assertSame( $this->candidate, $result->candidate_path() );
		self::assertSame( array( $old, $old ), $provisioner->authenticated_candidates );
	}

	public function test_existing_managed_lock_does_not_hide_an_authenticated_sibling(): void {
		$old = $this->recovery_store( 'abcdef0123456789' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                        = $this->provisioner();
		$provisioner->configured            = $this->candidate;
		$provisioner->health_failure        = true;
		$provisioner->health_failure_reason = 'storage_file_missing';

		$recovery = $provisioner->recovery_state( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'available', $recovery['state'] );
		self::assertSame( $old, $recovery['candidate_path'] );
	}

	public function test_explicit_reset_is_offered_only_for_the_orphaned_key_state_and_requires_typed_confirmation(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			'Booster found a database encryption key without its matching encrypted file. Restore the matching file if possible, or explicitly reset this empty credential store.' => 'Clé de stockage orpheline.',
			'Incomplete credential storage was reset. Booster will initialize fresh encrypted storage when you next save or import a credential.' => 'Stockage réinitialisé.',
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                           = $this->provisioner();
		$provisioner->configured               = $this->candidate;
		$provisioner->health_failure           = true;
		$provisioner->health_failure_reason    = 'storage_file_missing';
		$provisioner->orphaned_reset_available = true;
		$status                                = $provisioner->status();

		$offer = $provisioner->recovery_state( $status );
		self::assertIsArray( $offer );
		self::assertSame( 'reset_available', $offer['state'] );
		self::assertSame( 'Clé de stockage orpheline.', $offer['message'] );
		self::assertSame( SecretsStorageProvisioner::RESET_CONFIRMATION, $offer['confirmation'] );

		$invalid = $provisioner->reset_orphaned_storage( 'reset storage' );
		self::assertSame( 'storage_reset_request_invalid', $invalid->code() );
		self::assertSame( array(), $provisioner->reset_candidates );

		$reset = $provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $reset->status() );
		self::assertSame( 'storage_reset', $reset->code() );
		self::assertSame( 'Stockage réinitialisé.', $reset->message() );
		self::assertSame( array( $this->candidate ), $provisioner->reset_candidates );

		$replay = $provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $replay->code() );
		self::assertSame( array( $this->candidate ), $provisioner->reset_candidates );
	}

	public function test_explicit_reset_also_handles_secure_orphaned_ciphertext_without_its_database_key(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->candidate, 'encrypted-canary' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->candidate, 0600 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                                      = $this->provisioner();
		$provisioner->configured                          = $this->candidate;
		$provisioner->health_failure                      = true;
		$provisioner->health_failure_reason               = 'storage_key_missing';
		$provisioner->orphaned_ciphertext_reset_available = true;

		$offer = $provisioner->recovery_state( $provisioner->status() );
		self::assertIsArray( $offer );
		self::assertSame( 'reset_available', $offer['state'] );
		self::assertStringContainsString( 'without its matching database key', $offer['message'] );

		$reset = $provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset', $reset->code() );
		self::assertSame( array( $this->candidate ), $provisioner->reset_ciphertext_candidates );
		self::assertSame( array(), $provisioner->reset_candidates );

		$replay = $provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $replay->code() );
	}

	public function test_missing_database_key_cannot_reset_while_prior_storage_material_needs_review(): void {
		$this->recovery_store( 'abcdef0123456789' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->candidate, 'encrypted-canary' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->candidate, 0600 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                                      = $this->provisioner();
		$provisioner->configured                          = $this->candidate;
		$provisioner->health_failure                      = true;
		$provisioner->health_failure_reason               = 'storage_key_missing';
		$provisioner->orphaned_ciphertext_reset_available = true;

		$offer = $provisioner->recovery_state( $provisioner->status() );

		self::assertIsArray( $offer );
		self::assertSame( 'blocked', $offer['state'] );
		self::assertNull( $offer['confirmation'] );
		self::assertStringContainsString( 'no database key is available to authenticate it', $offer['message'] );
		self::assertSame(
			'storage_reset_state_changed',
			$provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION )->code()
		);
		self::assertSame( array(), $provisioner->reset_ciphertext_candidates );
	}

	public function test_authenticated_sibling_recovery_takes_priority_over_reset(): void {
		$this->recovery_store( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                           = $this->provisioner();
		$provisioner->configured               = $this->candidate;
		$provisioner->health_failure           = true;
		$provisioner->health_failure_reason    = 'storage_file_missing';
		$provisioner->orphaned_reset_available = true;

		$offer = $provisioner->recovery_state( $provisioner->status() );

		self::assertIsArray( $offer );
		self::assertSame( 'available', $offer['state'] );
		$result = $provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $result->code() );
		self::assertSame( array(), $provisioner->reset_candidates );
	}

	public function test_authenticated_unsafe_sibling_is_reported_but_cannot_be_adopted(): void {
		$this->recovery_store( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner               = $this->provisioner();
		$provisioner->configured   = $this->candidate;
		$provisioner->force_unsafe = true;

		$recovery = $provisioner->recovery_state( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'blocked', $recovery['state'] );
		self::assertNull( $recovery['candidate_path'] );
		self::assertNull( $recovery['token'] );
		self::assertStringContainsString( 'does not pass', $recovery['message'] );
	}

	public function test_ambiguous_or_unauthenticated_sibling_does_not_produce_an_adoption_token(): void {
		$this->recovery_store( 'abcdef0123456789' );
		$this->recovery_store( 'fedcba9876543210' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $this->candidate;

		$ambiguous = $provisioner->recovery_state( $provisioner->status() );
		self::assertIsArray( $ambiguous );
		self::assertSame( 'ambiguous', $ambiguous['state'] );
		self::assertNull( $ambiguous['token'] );

		$provisioner->recovery_authentication_fails = true;
		$blocked                                    = $provisioner->recovery_state( $provisioner->status() );
		self::assertIsArray( $blocked );
		self::assertSame( 'blocked', $blocked['state'] );
		self::assertStringContainsString( 'could not inspect completely', $blocked['message'] );
	}

	public function test_malformed_or_over_limit_sibling_scan_blocks_reset(): void {
		$malformed = $this->recovery_store( 'abcdef0123456789' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		self::assertTrue( unlink( $malformed . '.lock' ) );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                           = $this->provisioner();
		$provisioner->configured               = $this->candidate;
		$provisioner->health_failure           = true;
		$provisioner->health_failure_reason    = 'storage_file_missing';
		$provisioner->orphaned_reset_available = true;

		$blocked = $provisioner->recovery_state( $provisioner->status() );
		self::assertIsArray( $blocked );
		self::assertSame( 'blocked', $blocked['state'] );
		self::assertNull( $blocked['confirmation'] );
		self::assertSame(
			'storage_reset_state_changed',
			$provisioner->reset_orphaned_storage( SecretsStorageProvisioner::RESET_CONFIRMATION )->code()
		);
		self::assertSame( array(), $provisioner->reset_candidates );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
		self::assertTrue( unlink( $malformed ) );
		for ( $index = 0; $index <= 64; ++$index ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
			self::assertTrue( mkdir( dirname( dirname( $this->candidate ) ) . '/' . sprintf( '%016x', $index ), 0700 ) );
		}
		$overflow = $provisioner->recovery_state( $provisioner->status() );
		self::assertIsArray( $overflow );
		self::assertSame( 'blocked', $overflow['state'] );
		self::assertNull( $overflow['confirmation'] );
	}

	public function test_authenticated_provider_unfit_sibling_is_visible_but_not_adoptable(): void {
		$this->recovery_store( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->candidate );
		$provisioner                           = $this->provisioner();
		$provisioner->configured               = $this->candidate;
		$provisioner->recovery_credentials_fit = false;

		$recovery = $provisioner->recovery_state( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'blocked', $recovery['state'] );
		self::assertNull( $recovery['token'] );
		self::assertStringContainsString( 'do not pass their current provider policy', $recovery['message'] );
	}

	private function managed_diagnostic_message_for_code( string $code ): string {
		return array(
			'storage_key_missing'           => 'secrets.json and secrets.json.lock exist, but the matching database encryption key is missing. Restore the file and database key from the same backup; Booster will not delete unauthenticated ciphertext.',
			'storage_file_missing'          => 'The database encryption key exists, but secrets.json is missing. Restore the matching encrypted file from the same backup before using or uninstalling Booster.',
			'storage_orphan_lock'           => 'Only secrets.json.lock remains; no secrets file or database encryption key was found.',
			'storage_lock_missing'          => 'Managed secrets material exists, but secrets.json.lock is missing. Restore the matching storage set from one backup.',
			'unexpected_reason'             => 'Booster could not safely use the encrypted secrets store.',
			'storage_incomplete'            => 'The secrets file, lock file and database key are incomplete. Restore the matching set from one backup or reset empty storage.',
			'storage_authentication_failed' => 'The secrets file could not be authenticated with this site\'s database key. Restore both from the same backup.',
			'storage_document_invalid'      => 'The secrets file authenticated but its encrypted document is invalid.',
			'storage_key_unavailable'       => 'Booster could not read the database-held encryption key. Restore the database and encrypted files from the same backup.',
			'storage_file_unusable'         => 'The secrets file could not be read safely. Verify its ownership, mode 0600 and that it is a non-empty Booster-managed file.',
			'storage_lock_unusable'         => 'The secrets lock file could not be used safely. Verify its ownership and mode 0600.',
			'storage_unavailable'           => 'Booster could not classify the storage failure. Verify PHP owns the directories, secrets.json and secrets.json.lock; directories require mode 0700 and both files require mode 0600.',
		)[ $code ];
	}

	private function provisioner(): TestSecretsStorageProvisioner {
		$provisioner                 = new TestSecretsStorageProvisioner( $this->temporary_boundary );
		$provisioner->root           = $this->wordpress_root;
		$provisioner->candidate      = $this->candidate;
		$provisioner->included       = array( $this->config_path );
		$provisioner->configured     = null;
		$provisioner->sodium         = true;
		$provisioner->multisite      = false;
		$provisioner->local_platform = true;

		return $provisioner;
	}

	private function recovery_store( string $fingerprint ): string {
		$path = dirname( dirname( $this->candidate ) ) . '/' . $fingerprint . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( dirname( $path ), 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $path, '{}' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $path, 0600 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $path . '.lock', '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $path . '.lock', 0600 ) );

		return $path;
	}

	private function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_tree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
		rmdir( $path );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class TestSecretsStorageProvisioner extends SecretsStorageProvisioner {

	public string $root      = '';
	public string $candidate = '';
	/** @var list<string> */
	public array $included                           = array();
	public string|false|null $configured             = null;
	public bool $sodium                              = true;
	public bool $multisite                           = false;
	public bool $local_platform                      = true;
	public bool $resolver_called                     = false;
	public bool $resolver_fails                      = false;
	public bool $probe_called                        = false;
	public bool $writer_called                       = false;
	public bool $probe_fails                         = false;
	public ?string $writer_failure_code              = null;
	public string $writer_failure_message            = 'Raw writer failure.';
	public bool $healthy                             = false;
	public bool $health_failure                      = false;
	public string $health_failure_message            = 'Fixture storage failure.';
	public string $health_failure_reason             = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
	public bool $read_runtime_configuration          = false;
	public bool $force_unsafe                        = false;
	public bool $unsafe_after_probe                  = false;
	public bool $recovery_authentication_fails       = false;
	public bool $recovery_credentials_fit            = true;
	public bool $orphaned_reset_available            = false;
	public bool $orphaned_ciphertext_reset_available = false;
	/** @var list<string> */
	public array $authenticated_candidates = array();
	/** @var list<string> */
	public array $reset_candidates = array();
	/** @var list<string> */
	public array $reset_ciphertext_candidates = array();
	/** @var list<array{directory:string,code:string,reason:string,component:string|null}> */
	public array $discarded_candidates = array();

	public function __construct( string $temporary_boundary ) {
		parent::__construct(
			new PrivateLocationCandidateResolver( $temporary_boundary ),
			new PosixFilesystemProbe(),
			new WpConfigSecretsPathWriter()
		);
	}

	protected function resolve_candidate( ?array &$discarded = null ): ?string {
		$this->resolver_called = true;
		$discarded             = $this->discarded_candidates;

		return $this->resolver_fails ? null : $this->candidate;
	}

	protected function probe_candidate( string $candidate ): bool {
		$this->probe_called = true;

		return ! $this->probe_fails && parent::probe_candidate( $candidate );
	}

	protected function validate_configured_candidate( string $candidate ): bool {
		return ! $this->force_unsafe
			&& ! ( $this->unsafe_after_probe && $this->probe_called )
			&& parent::validate_configured_candidate( $candidate );
	}

	protected function recovery_credentials_fit( string $candidate ): bool {
		$this->authenticated_candidates[] = $candidate;
		if ( $this->recovery_authentication_fails ) {
			throw new \RuntimeException( 'Provider credential fitness failed.' );
		}

		return $this->recovery_credentials_fit;
	}

	protected function current_ciphertext_is_absent( string $current ): bool {
		return ! file_exists( $current ) && ! is_link( $current );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of orphaned_key_reset_available retains the production method contract; these inputs do not affect this controlled result.
	protected function orphaned_key_reset_available( string $current ): bool {
		return $this->orphaned_reset_available;
	}

	protected function reset_orphaned_key( string $current ): void {
		$this->reset_candidates[]       = $current;
		$this->orphaned_reset_available = false;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of orphaned_ciphertext_reset_available retains the production method contract; these inputs do not affect this controlled result.
	protected function orphaned_ciphertext_reset_available( string $current ): bool {
		return $this->orphaned_ciphertext_reset_available;
	}

	protected function reset_orphaned_ciphertext( string $current ): void {
		$this->reset_ciphertext_candidates[]       = $current;
		$this->orphaned_ciphertext_reset_available = false;
	}

	protected function write_configuration( string $config, string $candidate ): WpConfigPathWriteResult {
		$this->writer_called = true;
		if ( null !== $this->writer_failure_code ) {
			throw new \RAN\Secrets\WpConfigPathWriteException(
				// Test seam throws a stable fixture code, never rendered output.
				$this->writer_failure_code,
				// Test seam throws raw fixture text to prove it is not rendered.
				$this->writer_failure_message
			);
		}

		return parent::write_configuration( $config, $candidate );
	}

	protected function retarget_configuration(
		string $config,
		string $current,
		string $replacement
	): WpConfigPathWriteResult|false {
		$this->writer_called = true;
		if ( null !== $this->writer_failure_code ) {
			throw new \RAN\Secrets\WpConfigPathWriteException(
				// Test seam throws a stable fixture code, never rendered output.
				$this->writer_failure_code,
				// Test seam throws raw fixture text to prove it is not rendered.
				$this->writer_failure_message
			);
		}

		return parent::retarget_configuration( $config, $current, $replacement );
	}

	protected function wordpress_root(): string {
		return $this->root;
	}

	protected function content_directory(): string {
		return $this->root . '/wp-content';
	}

	protected function plugin_directory(): string {
		return $this->root . '/wp-content/plugins/ran-booster';
	}

	protected function document_root(): string {
		return $this->root;
	}

	protected function included_files(): array {
		return $this->included;
	}

	protected function configured_path(): string|false|null {
		return $this->read_runtime_configuration ? parent::configured_path() : $this->configured;
	}

	protected function is_multisite_installation(): bool {
		return $this->multisite;
	}

	protected function sodium_available(): bool {
		return $this->sodium;
	}

	protected function supported_local_platform(): bool {
		return $this->local_platform;
	}

	protected function managed_storage_healthy(): bool {
		if ( $this->health_failure ) {
			// Test-only fixed fixture message.
			throw new \RAN\Secrets\SecretsStorageUnavailable( $this->health_failure_message, $this->health_failure_reason );
		}

		return $this->healthy;
	}
}
